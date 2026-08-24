<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\ArchiveInspector;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SqlEngineDetector;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database-import.max_extracted_mb', 4);
    config()->set('database-import.max_zip_entries', 5);
    config()->set('database-import.max_zip_ratio', 200);
});

test('it inspects a gzip dump without loading it into memory', function () {
    $path = tempnam(sys_get_temp_dir(), 'dbi-gz-');
    $gzip = gzopen($path, 'wb6');
    gzwrite($gzip, "-- PostgreSQL database dump\nSET statement_timeout = 0;\nSET search_path = public;\n");
    gzclose($gzip);

    try {
        $result = (new ArchiveInspector(new SqlEngineDetector))->inspect($path, 'backup.sql.gz');
        expect($result)->archive_type->toBe('gzip')->detected_engine->toBe('postgresql');
    } finally {
        @unlink($path);
    }
});

test('it rejects zip traversal paths', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('PHP zip is not installed.');
    }
    $path = tempnam(sys_get_temp_dir(), 'dbi-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../backup.sql', "-- MySQL dump\n/*!40101 SET character_set_client = utf8 */;\nENGINE=InnoDB");
    $zip->close();

    try {
        expect(fn () => (new ArchiveInspector(new SqlEngineDetector))->inspect($path, 'backup.zip'))
            ->toThrow(RuntimeException::class, 'unsafe path');
    } finally {
        @unlink($path);
    }
});
