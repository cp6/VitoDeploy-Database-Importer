<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Plugin;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\ServerFeatures\OpenImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertSee('Upload database dump');
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

test('unauthenticated users cannot open importer routes', function () {
    auth()->logout();
    $this->get(route('database-importer.index'))->assertRedirect();
});
