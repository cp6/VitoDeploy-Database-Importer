<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Jobs;

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models\ImportRun;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\ArchiveInspector;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDumpDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DownloadImportFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('ssh');
    }

    public function handle(RemoteDumpDownloader $downloads, ArchiveInspector $archives): void
    {
        $run = ImportRun::query()->find($this->runId);
        if (! $run || $run->status !== 'downloading') {
            return;
        }

        $disk = Storage::disk(config('database-import.disk', 'local'));
        $directory = dirname($run->stored_path);
        $temporaryPath = $run->stored_path;
        $lastProgress = -1;
        $lastProgressAt = 0.0;

        try {
            $url = (string) data_get($run->selection, 'download_url', '');
            if ($url === '') {
                throw new RuntimeException('The queued remote download URL is unavailable.');
            }
            $disk->makeDirectory($directory);
            $download = $downloads->download(
                $url,
                $disk->path($temporaryPath),
                function (int $received, ?int $total) use ($run, &$lastProgress, &$lastProgressAt): void {
                    $progress = $total !== null && $total > 0
                        ? min(90, max(1, (int) floor(($received / $total) * 90)))
                        : min(90, max(1, (int) floor($received / (16 * 1024 * 1024))));
                    $now = microtime(true);
                    if ($progress > $lastProgress && ($now - $lastProgressAt >= 1.0 || $progress >= 90)) {
                        $run->update([
                            'progress' => $progress,
                            'current_step' => 'Downloading remote dump ('.$this->bytes($received).')',
                        ]);
                        $lastProgress = $progress;
                        $lastProgressAt = $now;
                    }
                },
            );

            $extension = $this->acceptedExtension($download['name']);
            $storedPath = $directory.'/source.'.$extension;
            if (! $disk->move($temporaryPath, $storedPath)) {
                throw new RuntimeException('The downloaded file could not be staged.');
            }

            $run->update(['progress' => 92, 'current_step' => 'Inspecting downloaded archive']);
            $inspection = $archives->inspect($disk->path($storedPath), $download['name']);
            $free = @disk_free_space(dirname($disk->path($storedPath)));
            $headroom = 256 * 1024 * 1024;
            if (is_float($free) && $free < ($download['size'] * 2) + $headroom) {
                throw new RuntimeException('The Vito host does not have enough free disk space to stage this download.');
            }

            $run->update([
                'status' => 'uploaded',
                'progress' => 0,
                'current_step' => 'Remote download inspected',
                'original_name' => $download['name'],
                'stored_path' => $storedPath,
                'file_size' => $download['size'],
                'extracted_size' => $inspection['extracted_size'],
                'archive_type' => $inspection['archive_type'],
                'detected_engine' => $inspection['detected_engine'],
                'selection' => null,
                'error' => null,
                'log' => [['at' => now()->toIso8601String(), 'message' => 'Remote download completed, its size was verified, and archive safety checks passed.']],
            ]);
        } catch (Throwable $e) {
            $disk->deleteDirectory($directory);
            $run->update([
                'status' => 'download_failed',
                'current_step' => 'Remote download failed',
                'selection' => null,
                'error' => $e->getMessage(),
                'log' => [['at' => now()->toIso8601String(), 'message' => 'Remote download failed before import: '.$e->getMessage()]],
            ]);
        }
    }

    private function acceptedExtension(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_ends_with($name, '.sql.gz') => 'sql.gz',
            str_ends_with($name, '.sql') => 'sql',
            str_ends_with($name, '.zip') => 'zip',
            default => throw new RuntimeException('The remote URL must download an .sql, .sql.gz, or .zip file.'),
        };
    }

    private function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $value;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
