<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\ServerFeatures;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;

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
        // Vito's server-feature controller always returns back after invoking
        // an action and does not use the handler's return value. Interrupt the
        // response with Inertia's location response so the modal's primary
        // button navigates just like the direct link in the form.
        throw new HttpResponseException(Inertia::location(
            route('database-importer.index', ['server' => $this->server->id]),
        ));
    }
}
