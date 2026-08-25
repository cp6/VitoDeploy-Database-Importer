<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Database\SchemaManager;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs\CleanupImportFileJob;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs\DownloadImportFileJob;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs\RunDatabaseImportJob;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models\ImportRun;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\ArchiveInspector;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDumpDownloader;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SafetyChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseImportController extends Controller
{
    public function styles(): BinaryFileResponse
    {
        return response()->file(dirname(__DIR__, 2).'/resources/dist/importer.css', [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function index(Request $request): View
    {
        app(SchemaManager::class)->ensureInstalled();
        $this->cleanExpired($request);

        $project = $request->user()->currentProject;
        Gate::forUser($request->user())->authorize('view', $project);

        $servers = $project->servers()
            ->with(['services', 'databases', 'databaseUsers'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server) => $server->database() !== null)
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status->getText(),
                'ready' => $server->isReady(),
                'engine' => $server->database()?->name,
                'version' => $server->database()?->installed_version ?: $server->database()?->version,
                'databases' => $server->databases->map(fn (Database $database) => [
                    'id' => $database->id,
                    'name' => $database->name,
                    'status' => $database->status->getText(),
                ])->values(),
                'users' => $server->databaseUsers->map(fn (DatabaseUser $user) => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'databases' => $user->databases ?? [],
                ])->values(),
            ])->values();

        $frontendConfig = [
            'servers' => $servers,
            'selectedServer' => (int) $request->query('server', 0),
            'limits' => [
                'uploadMb' => (int) config('database-import.max_upload_mb', 2048),
                'extractedMb' => (int) config('database-import.max_extracted_mb', 8192),
            ],
            'urls' => [
                'uploads' => route('database-importer.uploads.store'),
                'runBase' => url('/database-importer/runs'),
            ],
        ];

        return view()->file(dirname(__DIR__, 2).'/resources/views/importer.blade.php', [
            'servers' => $servers,
            'selectedServer' => (int) $request->query('server', 0),
            'maxUploadMb' => (int) config('database-import.max_upload_mb', 2048),
            'maxExtractedMb' => (int) config('database-import.max_extracted_mb', 8192),
            'frontendConfig' => $frontendConfig,
        ]);
    }

    public function upload(Request $request, ArchiveInspector $archives, RemoteDumpDownloader $downloads): JsonResponse
    {
        app(SchemaManager::class)->ensureInstalled();
        $validated = $request->validate([
            'file' => ['nullable', 'required_without:url', 'prohibited_with:url', 'file', 'max:'.((int) config('database-import.max_upload_mb', 2048) * 1024)],
            'url' => ['nullable', 'required_without:file', 'prohibited_with:file', 'string', 'max:4096'],
        ]);
        if (isset($validated['url'])) {
            return $this->queueRemoteDownload($request, $downloads, (string) $validated['url']);
        }

        $file = $validated['file'];
        $originalName = basename((string) $file->getClientOriginalName());
        $extension = $this->acceptedExtension($originalName);

        try {
            $inspection = $archives->inspect($file->getRealPath(), $originalName);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $disk = Storage::disk(config('database-import.disk', 'local'));
        $directory = 'vito-database-imports/'.$request->user()->id.'/'.Str::uuid();
        $storedPath = $file->storeAs($directory, 'source.'.$extension, config('database-import.disk', 'local'));
        if (! is_string($storedPath)) {
            return response()->json(['message' => 'The upload could not be staged.'], 500);
        }

        try {
            $free = @disk_free_space(dirname($disk->path($storedPath)));
            $headroom = 256 * 1024 * 1024;
            if (is_float($free) && $free < ((int) $file->getSize() * 2) + $headroom) {
                throw new \RuntimeException('The Vito host does not have enough free disk space to stage this upload.');
            }

            $run = ImportRun::query()->create([
                'user_id' => $request->user()->id,
                'project_id' => $request->user()->currentProject->id,
                'status' => 'uploaded',
                'progress' => 0,
                'current_step' => 'Upload inspected',
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'file_size' => (int) $file->getSize(),
                'extracted_size' => $inspection['extracted_size'],
                'archive_type' => $inspection['archive_type'],
                'detected_engine' => $inspection['detected_engine'],
                'log' => [['at' => now()->toIso8601String(), 'message' => 'Upload completed and passed archive safety checks.']],
                'expires_at' => now()->addHours((int) config('database-import.failed_file_retention_hours', 24)),
            ]);
            CleanupImportFileJob::dispatch($run->id)->delay($run->expires_at);

            return response()->json($run->publicStatus(), 201);
        } catch (Throwable $e) {
            $disk->deleteDirectory(dirname($storedPath));

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function queueRemoteDownload(Request $request, RemoteDumpDownloader $downloads, string $url): JsonResponse
    {
        try {
            $downloads->validate($url);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $disk = Storage::disk(config('database-import.disk', 'local'));
        $directory = 'vito-database-imports/'.$request->user()->id.'/'.Str::uuid();
        $temporaryPath = $directory.'/source.download';
        try {
            $run = ImportRun::query()->create([
                'user_id' => $request->user()->id,
                'project_id' => $request->user()->currentProject->id,
                'status' => 'downloading',
                'progress' => 0,
                'current_step' => 'Remote download queued',
                'original_name' => 'Remote database dump',
                'stored_path' => $temporaryPath,
                'file_size' => 0,
                'extracted_size' => null,
                'archive_type' => 'pending',
                'detected_engine' => null,
                'selection' => ['download_url' => $url],
                'log' => [['at' => now()->toIso8601String(), 'message' => 'Remote database download queued.']],
                'expires_at' => now()->addHours((int) config('database-import.failed_file_retention_hours', 24)),
            ]);
            DownloadImportFileJob::dispatch($run->id);
            CleanupImportFileJob::dispatch($run->id)->delay($run->expires_at);

            return response()->json($run->publicStatus(), 202);
        } catch (Throwable $e) {
            $disk->deleteDirectory($directory);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function preview(Request $request, ImportRun $run, SafetyChecker $safety): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if (! in_array($run->status, ['uploaded', 'reviewed', 'failed'], true)) {
            return response()->json(['message' => 'This upload can no longer be reconfigured.'], 422);
        }

        $validated = $this->validateSelection($request);
        $server = $this->serverForProject($request, (int) $validated['server_id']);
        [$database, $databaseSelection] = $this->databaseSelection($request, $server, $validated);
        $userSelection = $this->userSelection($request, $server, $validated);

        $review = $safety->check(
            $server,
            $database,
            $databaseSelection['name'],
            $validated['source_engine'],
            (int) $run->extracted_size,
            (bool) $validated['backup_before_overwrite'],
        );

        $selection = [
            'source_engine' => $validated['source_engine'],
            'database' => $databaseSelection,
            'user' => $userSelection,
            'policy' => $validated['policy'],
            'backup_before_overwrite' => (bool) $validated['backup_before_overwrite'],
            'review' => [
                'database_empty' => $review['database_empty'],
                'destination_engine' => $review['destination_engine'],
            ],
        ];
        $run->update([
            'server_id' => $server->id,
            'database_id' => $database?->id,
            'database_user_id' => $userSelection['mode'] === 'existing' ? $userSelection['id'] : null,
            'selection' => $selection,
            'status' => 'reviewed',
            'current_step' => 'Ready for confirmation',
            'error' => null,
        ]);

        return response()->json([
            'run' => $run->fresh()->publicStatus(),
            'checks' => $review['checks'],
            'compatible' => $review['compatible'],
            'database_empty' => $review['database_empty'],
            'database_name' => $databaseSelection['name'],
            'destination_engine' => $review['destination_engine'],
        ]);
    }

    public function start(Request $request, ImportRun $run, SafetyChecker $safety): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if ($run->status !== 'reviewed' || ! is_array($run->selection)) {
            return response()->json(['message' => 'Review the import plan before starting.'], 422);
        }
        $validated = $request->validate(['confirmation' => ['nullable', 'string', 'max:255']]);
        $server = $this->serverForProject($request, (int) $run->server_id);
        $databaseSelection = (array) $run->selection['database'];
        $this->authorizeStoredSelection($request, $server, $run->selection);
        $database = ($databaseSelection['mode'] ?? '') === 'existing'
            ? $server->databases()->findOrFail((int) $databaseSelection['id'])
            : null;
        $review = $safety->check(
            $server,
            $database,
            (string) $databaseSelection['name'],
            (string) $run->selection['source_engine'],
            (int) $run->extracted_size,
            (bool) $run->selection['backup_before_overwrite'],
        );
        if (! $review['compatible']) {
            return response()->json(['message' => 'A blocking safety check failed. Review the import plan again.'], 422);
        }
        if (! $review['database_empty']) {
            if ($run->selection['policy'] !== 'overwrite') {
                return response()->json(['message' => 'The destination database is not empty. Choose overwrite and review again.'], 422);
            }
            if (! hash_equals((string) $databaseSelection['name'], (string) ($validated['confirmation'] ?? ''))) {
                return response()->json(['message' => 'Type the destination database name exactly to confirm the overwrite.'], 422);
            }
        }

        $run->update(['status' => 'pending', 'progress' => 0, 'current_step' => 'Queued', 'error' => null]);
        RunDatabaseImportJob::dispatch($run->id);

        return response()->json($run->fresh()->publicStatus(), 202);
    }

    public function show(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        return response()->json($run->fresh()->publicStatus());
    }

    public function retry(Request $request, ImportRun $run, SafetyChecker $safety): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if ($run->status !== 'failed') {
            return response()->json(['message' => 'Only failed imports can be retried.'], 422);
        }
        if (! Storage::disk(config('database-import.disk', 'local'))->exists($run->stored_path)) {
            return response()->json(['message' => 'The staged upload expired. Upload the SQL dump again.'], 422);
        }

        $selection = $run->selection ?? [];
        $server = $this->serverForProject($request, (int) $run->server_id);
        $this->authorizeStoredSelection($request, $server, $selection);
        if (data_get($selection, 'database.mode') === 'existing' && data_get($selection, 'policy') === 'empty_only') {
            $database = $server->databases()->findOrFail((int) data_get($selection, 'database.id'));
            if (! $safety->databaseIsEmpty($server, $database->name)) {
                return response()->json([
                    'message' => 'The failed attempt left data in the destination. Review the destination and confirm an overwrite before retrying.',
                    'needs_review' => true,
                ], 422);
            }
        }

        $run->update(['status' => 'pending', 'progress' => 0, 'current_step' => 'Queued for retry', 'error' => null, 'expires_at' => null]);
        RunDatabaseImportJob::dispatch($run->id);

        return response()->json($run->fresh()->publicStatus(), 202);
    }

    public function cancel(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if (! in_array($run->status, ['pending', 'running'], true)) {
            return response()->json(['message' => 'This import can no longer be cancelled.'], 422);
        }
        $expires = now()->addHour();
        $run->update(['status' => 'cancelled', 'current_step' => 'Cancellation requested', 'expires_at' => $expires]);
        CleanupImportFileJob::dispatch($run->id)->delay($expires);

        return response()->json($run->fresh()->publicStatus());
    }

    public function destroy(Request $request, ImportRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        if (in_array($run->status, ['downloading', 'pending', 'running'], true)) {
            return response()->json(['message' => 'Wait for the active download or import before removing it.'], 422);
        }
        Storage::disk(config('database-import.disk', 'local'))->deleteDirectory(dirname($run->stored_path));
        $run->delete();

        return response()->json([], 204);
    }

    /** @return array<string,mixed> */
    private function validateSelection(Request $request): array
    {
        return $request->validate([
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'source_engine' => ['required', Rule::in(['mysql', 'mariadb', 'postgresql'])],
            'database_mode' => ['required', Rule::in(['existing', 'create'])],
            'database_id' => ['nullable', 'integer'],
            'database_name' => ['nullable', 'alpha_dash', 'max:64'],
            'user_mode' => ['required', Rule::in(['existing', 'create'])],
            'database_user_id' => ['nullable', 'integer'],
            'database_username' => ['nullable', 'alpha_dash', 'max:64'],
            'policy' => ['required', Rule::in(['empty_only', 'overwrite'])],
            'backup_before_overwrite' => ['required', 'boolean'],
        ]);
    }

    /** @return array{?Database,array<string,mixed>} */
    private function databaseSelection(Request $request, Server $server, array $validated): array
    {
        if ($validated['database_mode'] === 'existing') {
            $database = $server->databases()->findOrFail((int) ($validated['database_id'] ?? 0));
            Gate::forUser($request->user())->authorize('update', $database);

            return [$database, ['mode' => 'existing', 'id' => $database->id, 'name' => $database->name]];
        }
        Gate::forUser($request->user())->authorize('create', [Database::class, $server]);
        $name = (string) ($validated['database_name'] ?? '');
        abort_if($name === '', 422, 'Enter a name for the new database.');
        abort_if($server->databases()->where('name', $name)->exists(), 422, 'A database with this name already exists.');

        return [null, ['mode' => 'create', 'id' => null, 'name' => $name]];
    }

    /** @return array<string,mixed> */
    private function userSelection(Request $request, Server $server, array $validated): array
    {
        if ($validated['user_mode'] === 'existing') {
            $user = $server->databaseUsers()->findOrFail((int) ($validated['database_user_id'] ?? 0));
            Gate::forUser($request->user())->authorize('update', $user);
            abort_unless($user->status->getText() === 'ready', 422, 'The selected database user is not ready.');

            return ['mode' => 'existing', 'id' => $user->id, 'username' => $user->username];
        }
        Gate::forUser($request->user())->authorize('create', [DatabaseUser::class, $server]);
        $username = (string) ($validated['database_username'] ?? '');
        abort_if($username === '', 422, 'Enter a name for the new database user.');
        abort_if($server->databaseUsers()->where('username', $username)->exists(), 422, 'A database user with this name already exists.');

        return ['mode' => 'create', 'id' => null, 'username' => $username];
    }

    private function serverForProject(Request $request, int $serverId): Server
    {
        $server = Server::query()->with(['services', 'databases', 'databaseUsers'])
            ->where('project_id', $request->user()->currentProject->id)
            ->findOrFail($serverId);
        Gate::forUser($request->user())->authorize('viewAny', [Database::class, $server]);

        return $server;
    }

    private function authorizeRun(Request $request, ImportRun $run): void
    {
        abort_unless($run->user_id === $request->user()->id || $request->user()->is_admin, 403);
        abort_unless($run->project_id === $request->user()->currentProject->id, 403);
    }

    /** @param array<string,mixed> $selection */
    private function authorizeStoredSelection(Request $request, Server $server, array $selection): void
    {
        if (data_get($selection, 'database.mode') === 'existing') {
            $database = $server->databases()->findOrFail((int) data_get($selection, 'database.id'));
            Gate::forUser($request->user())->authorize('update', $database);
        } else {
            Gate::forUser($request->user())->authorize('create', [Database::class, $server]);
        }

        if (data_get($selection, 'user.mode') === 'existing') {
            $databaseUser = $server->databaseUsers()->findOrFail((int) data_get($selection, 'user.id'));
            Gate::forUser($request->user())->authorize('update', $databaseUser);
        } else {
            Gate::forUser($request->user())->authorize('create', [DatabaseUser::class, $server]);
        }
    }

    private function cleanExpired(Request $request): void
    {
        ImportRun::query()
            ->where('user_id', $request->user()->id)
            ->where('project_id', $request->user()->currentProject->id)
            ->whereNotIn('status', ['pending', 'running', 'complete'])
            ->where('expires_at', '<=', now())
            ->limit(20)
            ->get()
            ->each(fn (ImportRun $run) => CleanupImportFileJob::dispatch($run->id));
    }

    private function acceptedExtension(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_ends_with($name, '.sql.gz') => 'sql.gz',
            str_ends_with($name, '.sql') => 'sql',
            str_ends_with($name, '.zip') => 'zip',
            default => abort(422, 'Choose an .sql, .sql.gz, or .zip file.'),
        };
    }
}
