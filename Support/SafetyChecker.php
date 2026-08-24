<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

use App\Models\Database;
use App\Models\Server;
use App\Enums\DatabaseStatus;
use Throwable;

class SafetyChecker
{
    /** @return array{checks:array<int,array<string,mixed>>,compatible:bool,destination_engine:?string,database_empty:bool,remote_free_bytes:?int} */
    public function check(Server $server, ?Database $database, string $databaseName, string $sourceEngine, int $extractedSize, bool $backup): array
    {
        $service = $server->database();
        $destinationEngine = $service?->name;
        $checks = [];

        $checks[] = $this->row('server', $server->isReady(), 'Destination server is ready', $server->name, true);
        $checks[] = $this->row('service', $service !== null, 'Database service is installed', $destinationEngine ?: 'not installed', true);
        if ($database !== null) {
            $checks[] = $this->row('database', $database->status === DatabaseStatus::READY, 'Destination database is ready', $database->status->getText(), true);
        }

        $engineCompatible = $this->enginesCompatible($sourceEngine, $destinationEngine);
        $checks[] = $this->row('engine', $engineCompatible, 'Database engines are compatible', ($sourceEngine ?: 'unknown').' → '.($destinationEngine ?: 'none'), true);

        $databaseEmpty = true;
        if ($database !== null && $service !== null) {
            try {
                $databaseEmpty = $this->databaseIsEmpty($server, $databaseName, $destinationEngine);
                $checks[] = $this->row('empty', $databaseEmpty, $databaseEmpty ? 'Destination database is empty' : 'Destination database contains objects', $databaseName, false);
            } catch (Throwable $e) {
                $databaseEmpty = false;
                $checks[] = $this->row('empty', false, 'Could not inspect destination contents', $e->getMessage(), true);
            }
        } else {
            $checks[] = $this->row('empty', true, 'New database will start empty', $databaseName, false);
        }

        $remoteFree = null;
        try {
            $remoteFree = $this->remoteFreeBytes($server);
            $headroom = (int) config('database-import.minimum_remote_headroom_mb', 512) * 1024 * 1024;
            $required = $extractedSize + $headroom + ($backup && ! $databaseEmpty ? $extractedSize : 0);
            $enough = $remoteFree >= $required;
            $checks[] = $this->row('disk', $enough, 'Destination has enough free disk space', $this->bytes($remoteFree).' free · '.$this->bytes($required).' required', true);
        } catch (Throwable $e) {
            $checks[] = $this->row('disk', false, 'Could not check destination disk space', $e->getMessage(), true);
        }

        return [
            'checks' => $checks,
            'compatible' => collect($checks)->where('blocking', true)->every(fn (array $check) => $check['status'] === 'matched'),
            'destination_engine' => $destinationEngine,
            'database_empty' => $databaseEmpty,
            'remote_free_bytes' => $remoteFree,
        ];
    }

    public function databaseIsEmpty(Server $server, string $database, ?string $engine = null): bool
    {
        $engine ??= $server->database()?->name;
        if ($engine === 'postgresql') {
            $query = "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')";
            $command = 'sudo -u postgres psql -Atqc '.escapeshellarg($query).' '.escapeshellarg($database);
        } else {
            $query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '".$database."'";
            $command = 'sudo DEBIAN_FRONTEND=noninteractive mysql -N -B -u root -e '.escapeshellarg($query);
        }

        return (int) trim($server->ssh()->exec($command, 'database-import-check-empty')) === 0;
    }

    private function remoteFreeBytes(Server $server): int
    {
        $output = trim($server->ssh()->exec("df -Pk /home | awk 'NR==2 {print \$4 * 1024}'", 'database-import-check-disk'));
        if (! is_numeric($output)) {
            throw new \RuntimeException('Unexpected disk-space response.');
        }

        return (int) $output;
    }

    private function enginesCompatible(string $source, ?string $destination): bool
    {
        if ($source === '' || $source === 'unknown' || $destination === null) {
            return false;
        }
        $mysqlFamily = ['mysql', 'mariadb'];

        return $source === $destination || (in_array($source, $mysqlFamily, true) && in_array($destination, $mysqlFamily, true));
    }

    /** @return array<string,mixed> */
    private function row(string $key, bool $matched, string $label, string $value, bool $blocking): array
    {
        return ['key' => $key, 'status' => $matched ? 'matched' : 'blocked', 'label' => $label, 'value' => $value, 'blocking' => $blocking];
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 1).' GB';
        }

        return number_format($bytes / (1024 ** 2)).' MB';
    }
}
