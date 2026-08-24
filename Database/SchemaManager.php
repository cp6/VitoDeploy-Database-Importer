<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaManager
{
    public function ensureInstalled(): void
    {
        if (Schema::hasTable('database_import_runs')) {
            return;
        }

        Schema::create('database_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->unsignedBigInteger('database_id')->nullable()->index();
            $table->unsignedBigInteger('database_user_id')->nullable()->index();
            $table->string('status')->default('uploaded')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('current_step')->nullable();
            $table->string('original_name');
            $table->longText('stored_path');
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('extracted_size')->nullable();
            $table->string('archive_type', 16);
            $table->string('detected_engine', 32)->nullable();
            $table->longText('selection')->nullable();
            $table->longText('result')->nullable();
            $table->longText('log')->nullable();
            $table->longText('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function uninstall(): void
    {
        if ((bool) config('database-import.drop_tables_on_uninstall', false)) {
            Schema::dropIfExists('database_import_runs');
        }
    }
}
