<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\ServerFeatures;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;

class OpenImporter extends Action
{
    public function name(): string
    {
        return 'Open Database Importer';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('open_importer')
                ->alert()
                ->label('Database Importer')
                ->description('Upload an .sql, .sql.gz, or .zip dump and import it safely. ')
                ->link('Open importer', route('database-importer.index', ['server' => $this->server->id])),
        ]);
    }

    public function handle(Request $request): void
    {
        // This feature action only links to the importer.
    }
}
