<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs\DownloadImportFileJob;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models\ImportRun;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Plugin;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\ServerFeatures\OpenImporter;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\ArchiveInspector;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDumpDownloader;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SqlEngineDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs($this->user);
    $plugin = new Plugin;
    $plugin->install();
    $plugin->boot();
});

test('authenticated users can open the importer', function () {
    $this->get(route('database-importer.index'))
        ->assertOk()
        ->assertSee('Import a database')
        ->assertSee('Provide database dump')
        ->assertSee('Download a URL');
});

test('a direct download URL is validated and queued without passing the dump through the browser request', function () {
    Queue::fake();
    $downloads = Mockery::mock(RemoteDumpDownloader::class);
    $downloads->shouldReceive('validate')
        ->once()
        ->with('https://downloads.example/database.sql.gz');
    app()->instance(RemoteDumpDownloader::class, $downloads);

    $response = $this->postJson(route('database-importer.uploads.store'), [
        'url' => 'https://downloads.example/database.sql.gz',
    ]);

    $response->assertAccepted()->assertJsonPath('status', 'downloading');
    $run = ImportRun::query()->findOrFail($response->json('id'));
    expect(data_get($run->selection, 'download_url'))->toBe('https://downloads.example/database.sql.gz');
    Queue::assertPushed(DownloadImportFileJob::class, fn (DownloadImportFileJob $job) => $job->runId === $run->id);
});

test('direct download URLs cannot target private services', function () {
    $this->postJson(route('database-importer.uploads.store'), [
        'url' => 'https://127.0.0.1/database.sql.gz',
    ])->assertUnprocessable()->assertJsonPath('message', 'The remote database URL must resolve only to a public internet address.');
});

test('a completed remote download is inspected and promoted to a staged upload', function () {
    Storage::fake('local');
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->currentProject->id,
        'status' => 'downloading',
        'progress' => 0,
        'current_step' => 'Remote download queued',
        'original_name' => 'Remote database dump',
        'stored_path' => 'vito-database-imports/test/source.download',
        'file_size' => 0,
        'archive_type' => 'pending',
        'selection' => ['download_url' => 'https://downloads.example/database.sql'],
        'expires_at' => now()->addDay(),
    ]);
    $downloads = Mockery::mock(RemoteDumpDownloader::class);
    $downloads->shouldReceive('download')->once()->andReturnUsing(function ($url, $destination, $progress): array {
        $contents = "-- MySQL dump\n/*!40101 SET character_set_client = utf8 */;\nENGINE=InnoDB;\n";
        file_put_contents($destination, $contents);
        $progress(strlen($contents), strlen($contents));

        return ['name' => 'database.sql', 'size' => strlen($contents), 'final_url' => $url];
    });

    (new DownloadImportFileJob($run->id))->handle(
        $downloads,
        new ArchiveInspector(new SqlEngineDetector),
    );

    $run->refresh();
    expect($run->status)->toBe('uploaded')
        ->and($run->original_name)->toBe('database.sql')
        ->and($run->detected_engine)->toBe('mysql')
        ->and($run->selection)->toBeNull();
    Storage::disk('local')->assertExists('vito-database-imports/test/source.sql');
});

test('the plugin serves its compiled stylesheet', function () {
    $this->get(route('database-importer.styles'))
        ->assertOk()
        ->assertHeader('content-type', 'text/css; charset=UTF-8');

    expect(filesize(dirname(__DIR__, 2).'/resources/dist/importer.css'))->toBeGreaterThan(1000);
});

test('the server feature action is active', function () {
    expect((new OpenImporter($this->server))->active())->toBeTrue();
});

test('the server feature primary action opens the importer for that server', function () {
    $url = route('database-importer.index', ['server' => $this->server->id]);

    $this->withHeader('X-Inertia', 'true')
        ->post(route('server-features.action', [
            'server' => $this->server,
            'feature' => 'database-importer',
            'action' => 'open',
        ]))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);
});

test('unauthenticated users cannot open importer routes', function () {
    auth()->logout();
    $this->get(route('database-importer.index'))->assertRedirect();
});
