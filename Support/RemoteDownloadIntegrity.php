<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

use RuntimeException;

class RemoteDownloadIntegrity
{
    public function assertWithinLimit(int $bytes): void
    {
        if ($bytes > $this->maximumBytes()) {
            throw new RuntimeException('The remote file exceeds the configured upload size limit.');
        }
    }

    public function maximumBytes(): int
    {
        return (int) config('database-import.max_upload_mb', 2048) * 1024 * 1024;
    }

    public function assertComplete(
        string $path,
        int $receivedBytes,
        ?int $declaredBytes,
        ?string $contentMd5,
        ?string $sha256,
    ): void {
        clearstatcache(true, $path);
        $storedBytes = filesize($path);
        if ($storedBytes === false || $receivedBytes < 1 || (int) $storedBytes !== $receivedBytes) {
            throw new RuntimeException('The remote download was incomplete on disk.');
        }
        $this->assertWithinLimit($receivedBytes);

        if ($declaredBytes !== null && $declaredBytes !== $receivedBytes) {
            throw new RuntimeException(sprintf(
                'The remote download was incomplete: expected %d bytes but received %d.',
                $declaredBytes,
                $receivedBytes,
            ));
        }

        if ($contentMd5 !== null) {
            $actual = base64_encode((string) hash_file('md5', $path, true));
            if (! hash_equals($contentMd5, $actual)) {
                throw new RuntimeException('The remote file failed its Content-MD5 integrity check.');
            }
        }

        if ($sha256 !== null) {
            $actual = base64_encode((string) hash_file('sha256', $path, true));
            if (! hash_equals($sha256, $actual)) {
                throw new RuntimeException('The remote file failed its SHA-256 integrity check.');
            }
        }
    }
}
