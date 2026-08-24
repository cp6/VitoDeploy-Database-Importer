<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs;

use App\Actions\Database\CreateDatabase;
use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\LinkUser;
use App\Enums\DatabaseUserPermission;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Services\Database\Database as DatabaseHandler;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models\ImportRun;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\ArchiveInspector;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SafetyChecker;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SecretRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunDatabaseImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('ssh');
    }

    public function handle(ArchiveInspector $archives, SafetyChecker $safety, SecretRedactor $redactor): void
    {
        $run = ImportRun::query()->find($this->runId);
        if (! $run || in_array($run->status, ['complete', 'cancelled', 'expired'], true)) {
            return;
        }

        $started = microtime(true);
        $disk = Storage::disk(config('database-import.disk', 'local'));
        $normalized = null;
        $server = null;
        $remotePath = null;

        try {
            $run->update(['status' => 'running', 'attempts' => $run->attempts + 1, 'error' => null]);
            $this->step($run, 5, 'Validating staged upload');
            if (! $disk->exists($run->stored_path)) {
                throw new RuntimeException('The staged upload is no longer available. Upload the dump again.');
            }

            $source = $disk->path($run->stored_path);
            $normalized = dirname($source).DIRECTORY_SEPARATOR.'normalized-'.$run->id.'.sql.gz';
            $archives->normalizeToGzip($source, $run->archive_type, $normalized);
            $this->guardCancelled($run);
            $this->step($run, 20, 'Preparing destination database');

            $selection = $run->selection ?? [];
            $server = Server::query()->with(['services', 'databases', 'databaseUsers'])->findOrFail($run->server_id);
            $database = $this->resolveDatabase($server, (array) ($selection['database'] ?? []), $run);
            $run->update(['database_id' => $database->id]);

            $review = $safety->check(
                $server,
                $database,
                $database->name,
                (string) ($selection['source_engine'] ?? ''),
                (int) $run->extracted_size,
                (bool) ($selection['backup_before_overwrite'] ?? false),
            );
            if (! $review['compatible']) {
                throw new RuntimeException('A blocking compatibility or disk-space check changed after review. Generate a new preview.');
            }

            $backupPath = null;
            if (! $review['database_empty']) {
                $isRetryingPartialImport = $run->attempts > 1
                    && data_get($selection, 'database.mode') === 'create';
                if (($selection['policy'] ?? 'empty_only') !== 'overwrite' && ! $isRetryingPartialImport) {
                    throw new RuntimeException('The destination is no longer empty. Import was stopped before making changes.');
                }
                if (($selection['backup_before_overwrite'] ?? false) && ! $isRetryingPartialImport) {
                    $this->step($run, 30, 'Backing up destination before overwrite');
                    $backupPath = $this->backupDatabase($server, $database);
                }
                $this->guardCancelled($run);
                $this->step($run, 40, 'Clearing destination database');
                $this->clearDatabase($server, $database);
            }

            $this->guardCancelled($run);
            $this->step($run, 55, 'Uploading normalized SQL archive');
            $remoteDirectory = '/home/'.$server->getSshUser().'/.vito-database-importer';
            $remotePath = $remoteDirectory.'/run-'.$run->id.'.sql.gz';
            // Keep this compatible with Vito releases whose SSH::upload() method
            // predates the named $permission argument. The private directory
            // protects the upload while chmod applies the final file mode.
            $server->ssh()->exec(
                'mkdir -p '.escapeshellarg($remoteDirectory).' && chmod 700 '.escapeshellarg($remoteDirectory),
                'database-import-prepare',
            );
            $server->ssh()->upload($normalized, $remotePath, $server->getSshUser(), 'database-import-upload');
            $server->ssh()->exec('chmod 600 '.escapeshellarg($remotePath), 'database-import-permissions');

            $this->guardCancelled($run);
            $this->step($run, 70, 'Importing database with '.$server->database()->name);
            $server->ssh()->exec(
                view('ssh.services.database.'.$server->database()->name.'.restore', [
                    'database' => $database->name,
                    'path' => $remotePath,
                ]),
                'database-import-restore',
            );
            $remotePath = null; // Vito's restore template removes the archive after a successful import.

            $this->guardCancelled($run);
            $this->step($run, 92, 'Linking database user');
            $databaseUser = $this->resolveDatabaseUser($server, $database, (array) ($selection['user'] ?? []), $run);
            $run->update(['database_user_id' => $databaseUser->id]);

            $result = [
                'database_id' => $database->id,
                'database' => $database->name,
                'database_user_id' => $databaseUser->id,
                'database_user' => $databaseUser->username,
                'server' => $server->name,
                'engine' => $server->database()->name,
                'backup_path' => $backupPath,
                'duration_seconds' => round(microtime(true) - $started, 1),
            ];
            $this->appendLog($run, 'Import completed successfully.');
            $run->update([
                'status' => 'complete',
                'progress' => 100,
                'current_step' => 'Import complete',
                'result' => $result,
                'error' => null,
                'expires_at' => null,
            ]);
            $disk->deleteDirectory(dirname($run->stored_path));
        } catch (Throwable $e) {
            $run->refresh();
            if ($run->status !== 'cancelled') {
                $message = $redactor->redact($e->getMessage());
                $this->appendLog($run, 'Import failed: '.$message);
                $expires = now()->addHours((int) config('database-import.failed_file_retention_hours', 24));
                $run->update([
                    'status' => 'failed',
                    'current_step' => 'Import failed',
                    'error' => $message,
                    'expires_at' => $expires,
                ]);
                CleanupImportFileJob::dispatch($run->id)->delay($expires);
            }
        } finally {
            if (is_string($normalized) && is_file($normalized)) {
                @unlink($normalized);
            }
            if ($server && $remotePath) {
                try {
                    $server->ssh()->exec('rm -f -- '.escapeshellarg($remotePath), 'database-import-cleanup');
                } catch (Throwable) {
                    // The delayed cleanup retains the local source; remote cleanup is best effort.
                }
            }
        }
    }

    private function resolveDatabase(Server $server, array $selection, ImportRun $run): Database
    {
        if (($selection['mode'] ?? '') === 'existing') {
            return $server->databases()->findOrFail((int) ($selection['id'] ?? 0));
        }

        if ($run->database_id) {
            $created = $server->databases()->find($run->database_id);
            if ($created) {
                return $created;
            }
        }

        [$charset, $collation] = $this->databaseDefaults($server);

        return app(CreateDatabase::class)->create($server, [
            'name' => (string) ($selection['name'] ?? ''),
            'charset' => $charset,
            'collation' => $collation,
        ]);
    }

    private function resolveDatabaseUser(Server $server, Database $database, array $selection, ImportRun $run): DatabaseUser
    {
        if (($selection['mode'] ?? '') === 'existing') {
            $user = $server->databaseUsers()->findOrFail((int) ($selection['id'] ?? 0));
            $databases = array_values(array_unique([...($user->databases ?? []), $database->name]));

            return app(LinkUser::class)->link($user, ['databases' => $databases]);
        }


        if ($run->attempts > 1) {
            $created = $server->databaseUsers()->where('username', (string) ($selection['username'] ?? ''))->first();
            if ($created) {
                $databases = array_values(array_unique([...($created->databases ?? []), $database->name]));

                return app(LinkUser::class)->link($created, ['databases' => $databases]);
            }
        }

        return app(CreateDatabaseUser::class)->create($server, [
            'username' => (string) ($selection['username'] ?? ''),
            'password' => Str::password(32),
            'permission' => DatabaseUserPermission::ADMIN->value,
        ], [$database->name]);
    }

    private function backupDatabase(Server $server, Database $database): string
    {
        $directory = '/home/'.$server->getSshUser().'/.vito-database-importer/backups';
        $path = $directory.'/'.$database->name.'-before-import-'.now()->format('Ymd-His').'.sql.gz';
        $server->ssh()->exec('mkdir -p '.escapeshellarg($directory).' && chmod 700 '.escapeshellarg($directory), 'database-import-backup-prepare');
        $server->ssh()->exec(
            view('ssh.services.database.'.$server->database()->name.'.backup', [
                'database' => $database->name,
                'path' => $path,
            ]),
            'database-import-safety-backup',
        );

        return $path;
    }

    private function clearDatabase(Server $server, Database $database): void
    {
        $handler = $this->databaseHandler($server);
        $handler->delete($database->name);
        $handler->create($database->name, $database->charset, $database->collation);
    }

    private function databaseHandler(Server $server): DatabaseHandler
    {
        $handler = $server->database()?->handler();
        if (! $handler instanceof DatabaseHandler) {
            throw new RuntimeException('The destination database service handler is unavailable.');
        }

        return $handler;
    }

    /** @return array{string,string} */
    private function databaseDefaults(Server $server): array
    {
        $service = $server->database();
        $charset = (string) data_get($service?->type_data, 'defaultCharset', '');
        if ($charset === '') {
            $charset = (string) array_key_first((array) data_get($service?->type_data, 'charsets', []));
        }
        $collation = (string) data_get($service?->type_data, 'charsets.'.$charset.'.default', '');
        if ($charset === '' || $collation === '') {
            throw new RuntimeException('Vito has no charset/collation metadata for this database service. Sync the service first.');
        }

        return [$charset, $collation];
    }

    private function step(ImportRun $run, int $progress, string $message): void
    {
        $run->update(['progress' => $progress, 'current_step' => $message]);
        $this->appendLog($run, $message);
    }

    private function appendLog(ImportRun $run, string $message): void
    {
        $log = $run->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'message' => $message];
        $run->update(['log' => array_slice($log, -100)]);
    }

    private function guardCancelled(ImportRun $run): void
    {
        $run->refresh();
        if ($run->status === 'cancelled') {
            throw new RuntimeException('Import cancelled.');
        }
    }
}
