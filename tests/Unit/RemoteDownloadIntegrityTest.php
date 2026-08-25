<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\RemoteDownloadIntegrity;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database-import.max_upload_mb', 1);
});

test('it rejects a response that ends before its advertised content length', function () {
    $path = tempnam(sys_get_temp_dir(), 'dbi-remote-');
    file_put_contents($path, 'partial database dump');

    try {
        expect(fn () => (new RemoteDownloadIntegrity)->assertComplete(
            $path,
            filesize($path),
            filesize($path) + 100,
            null,
            null,
        ))->toThrow(RuntimeException::class, 'expected');
    } finally {
        @unlink($path);
    }
});

test('it rejects a file whose stored size differs from the streamed byte count', function () {
    $path = tempnam(sys_get_temp_dir(), 'dbi-remote-');
    file_put_contents($path, 'database dump');

    try {
        expect(fn () => (new RemoteDownloadIntegrity)->assertComplete(
            $path,
            filesize($path) + 1,
            null,
            null,
            null,
        ))->toThrow(RuntimeException::class, 'incomplete on disk');
    } finally {
        @unlink($path);
    }
});

test('it verifies advertised content checksums', function () {
    $path = tempnam(sys_get_temp_dir(), 'dbi-remote-');
    file_put_contents($path, '-- MySQL dump');
    $size = filesize($path);

    try {
        (new RemoteDownloadIntegrity)->assertComplete(
            $path,
            $size,
            $size,
            base64_encode(hash_file('md5', $path, true)),
            base64_encode(hash_file('sha256', $path, true)),
        );
        expect(true)->toBeTrue();
    } finally {
        @unlink($path);
    }
});

test('it rejects an advertised file larger than the configured compressed limit', function () {
    expect(fn () => (new RemoteDownloadIntegrity)->assertWithinLimit((1024 * 1024) + 1))
        ->toThrow(RuntimeException::class, 'size limit');
});
