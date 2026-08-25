<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs;

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CleanupImportFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $run = ImportRun::query()->find($this->runId);
        if (! $run || in_array($run->status, ['downloading', 'pending', 'running'], true)) {
            return;
        }
        if ($run->expires_at && $run->expires_at->isFuture()) {
            static::dispatch($run->id)->delay($run->expires_at);

            return;
        }

        Storage::disk(config('database-import.disk', 'local'))->deleteDirectory(dirname($run->stored_path));
        if (! in_array($run->status, ['complete', 'cancelled'], true)) {
            $run->update(['status' => 'expired', 'current_step' => 'Staged upload expired']);
        }
    }
}
