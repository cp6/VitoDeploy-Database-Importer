<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDownloadIntegrity;
use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDumpDownloader;
use Tests\TestCase;

uses(TestCase::class);

test('it requires HTTPS direct download URLs', function () {
    $downloader = new RemoteDumpDownloader(new RemoteDownloadIntegrity);

    expect(fn () => $downloader->validate('http://example.com/database.sql.gz'))
        ->toThrow(RuntimeException::class, 'must use HTTPS');
});

test('it rejects direct URLs containing credentials', function () {
    $downloader = new RemoteDumpDownloader(new RemoteDownloadIntegrity);

    expect(fn () => $downloader->validate('https://user:secret@example.com/database.sql.gz'))
        ->toThrow(RuntimeException::class, 'embedded credentials');
});

test('it rejects loopback and private address targets', function (string $url) {
    $downloader = new RemoteDumpDownloader(new RemoteDownloadIntegrity);

    expect(fn () => $downloader->validate($url))
        ->toThrow(RuntimeException::class, 'public internet address');
})->with([
    'loopback' => 'https://127.0.0.1/database.sql.gz',
    'private network' => 'https://10.0.0.1/database.sql.gz',
    'carrier-grade NAT' => 'https://100.64.0.1/database.sql.gz',
    'benchmark network' => 'https://198.18.0.1/database.sql.gz',
    'documentation network' => 'https://203.0.113.1/database.sql.gz',
]);
