<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

class SqlEngineDetector
{
    public function detect(string $sample): ?string
    {
        $sample = strtolower($sample);
        $postgres = 0;
        $mysql = 0;

        foreach (['postgresql database dump', 'set statement_timeout', 'set search_path', 'pg_catalog.', 'copy public.', 'set default_table_access_method'] as $needle) {
            $postgres += str_contains($sample, $needle) ? 1 : 0;
        }
        foreach (['mysql dump', 'mariadb dump', 'engine=innodb', 'lock tables', '/*!40', 'character_set_client'] as $needle) {
            $mysql += str_contains($sample, $needle) ? 1 : 0;
        }

        if ($postgres >= 2 && $postgres > $mysql) {
            return 'postgresql';
        }
        if ($mysql >= 2 && $mysql > $postgres) {
            return str_contains($sample, 'mariadb dump') ? 'mariadb' : 'mysql';
        }

        return null;
    }
}
