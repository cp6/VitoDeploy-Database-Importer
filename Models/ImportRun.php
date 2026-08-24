<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property ?int $server_id
 * @property ?int $database_id
 * @property ?int $database_user_id
 * @property string $status
 * @property int $progress
 * @property ?string $current_step
 * @property string $original_name
 * @property string $stored_path
 * @property int $file_size
 * @property ?int $extracted_size
 * @property string $archive_type
 * @property ?string $detected_engine
 * @property ?array<string, mixed> $selection
 * @property ?array<string, mixed> $result
 * @property ?array<int, array{at:string,message:string}> $log
 * @property ?string $error
 * @property int $attempts
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ImportRun extends Model
{
    protected $table = 'database_import_runs';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'project_id' => 'integer',
        'server_id' => 'integer',
        'database_id' => 'integer',
        'database_user_id' => 'integer',
        'progress' => 'integer',
        'file_size' => 'integer',
        'extracted_size' => 'integer',
        'attempts' => 'integer',
        'stored_path' => 'encrypted',
        'selection' => 'encrypted:array',
        'result' => 'encrypted:array',
        'log' => 'encrypted:array',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['stored_path', 'selection'];

    public function publicStatus(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'progress' => $this->progress,
            'current_step' => $this->current_step,
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'extracted_size' => $this->extracted_size,
            'archive_type' => $this->archive_type,
            'detected_engine' => $this->detected_engine,
            'attempts' => $this->attempts,
            'result' => $this->result ?? [],
            'log' => $this->log ?? [],
            'error' => $this->error,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
