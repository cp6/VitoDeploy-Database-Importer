<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SqlEngineDetector;

test('it detects mysql and mariadb dumps', function () {
    $detector = new SqlEngineDetector;

    expect($detector->detect("-- MySQL dump\n/*!40101 SET character_set_client = utf8 */;\nCREATE TABLE x (id int) ENGINE=InnoDB;"))->toBe('mysql')
        ->and($detector->detect("-- MariaDB dump\nLOCK TABLES `users` WRITE;\nCREATE TABLE users (id int) ENGINE=InnoDB;"))->toBe('mariadb');
});

test('it detects postgresql dumps', function () {
    $sample = "-- PostgreSQL database dump\nSET statement_timeout = 0;\nSET search_path = public, pg_catalog;\nCOPY public.users (id) FROM stdin;";

    expect((new SqlEngineDetector)->detect($sample))->toBe('postgresql');
});

test('it leaves generic sql for explicit confirmation', function () {
    expect((new SqlEngineDetector)->detect('CREATE TABLE users (id INTEGER);'))->toBeNull();
});
