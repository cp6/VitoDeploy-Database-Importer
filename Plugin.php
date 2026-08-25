<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServerFeature;
use App\Plugins\RegisterServerFeatureAction;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Database\SchemaManager;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Http\Controllers\DatabaseImportController;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\ServerFeatures\OpenImporter;
use Illuminate\Support\Facades\Route;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Database Importer';

    protected string $description = 'Upload or securely download and import SQL databases into VitoDeploy.';

    public function boot(): void
    {
        $defaults = require __DIR__.'/config/database-import.php';
        config(['database-import' => array_replace_recursive($defaults, config('database-import', []))]);

        RegisterViews::make('vito-database-import')
            ->path(__DIR__.'/resources/views')
            ->register();

        $this->registerRoutes();
        $this->registerServerFeature();
    }

    public function install(): void
    {
        app(SchemaManager::class)->ensureInstalled();
    }

    public function enable(): void
    {
        app(SchemaManager::class)->ensureInstalled();
    }

    public function uninstall(): void
    {
        app(SchemaManager::class)->uninstall();
    }

    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth', 'has-project'])
            ->prefix('database-importer')
            ->name('database-importer.')
            ->group(function (): void {
                Route::get('/assets/importer.css', [DatabaseImportController::class, 'styles'])->name('styles');
                Route::get('/', [DatabaseImportController::class, 'index'])->name('index');
                Route::post('/uploads', [DatabaseImportController::class, 'upload'])->name('uploads.store');
                Route::post('/runs/{run}/preview', [DatabaseImportController::class, 'preview'])->name('runs.preview');
                Route::post('/runs/{run}/start', [DatabaseImportController::class, 'start'])->name('runs.start');
                Route::get('/runs/{run}', [DatabaseImportController::class, 'show'])->name('runs.show');
                Route::post('/runs/{run}/retry', [DatabaseImportController::class, 'retry'])->name('runs.retry');
                Route::post('/runs/{run}/cancel', [DatabaseImportController::class, 'cancel'])->name('runs.cancel');
                Route::delete('/runs/{run}', [DatabaseImportController::class, 'destroy'])->name('runs.destroy');
            });

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    private function registerServerFeature(): void
    {
        RegisterServerFeature::make('database-importer')
            ->label('Database Importer')
            ->description('Upload or download an SQL dump and import it into a database on this server')
            ->register();

        RegisterServerFeatureAction::make('database-importer', 'open')
            ->label('Open Importer')
            ->handler(OpenImporter::class)
            ->register();
    }
}
