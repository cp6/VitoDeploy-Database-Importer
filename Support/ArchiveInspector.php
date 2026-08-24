<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

use RuntimeException;
use ZipArchive;

class ArchiveInspector
{
    private const SAMPLE_BYTES = 2_097_152;

    public function __construct(private readonly SqlEngineDetector $detector)
    {
    }

    /** @return array{archive_type:string,extracted_size:int,detected_engine:?string} */
    public function inspect(string $path, string $originalName): array
    {
        $type = $this->archiveType($originalName);
        [$size, $sample] = match ($type) {
            'sql' => $this->inspectSql($path),
            'gzip' => $this->inspectGzip($path),
            'zip' => $this->inspectZip($path),
            default => throw new RuntimeException('Unsupported archive type.'),
        };

        $this->assertExtractedSize($size);

        return [
            'archive_type' => $type,
            'extracted_size' => $size,
            'detected_engine' => $this->detector->detect($sample),
        ];
    }

    public function normalizeToGzip(string $source, string $type, string $destination): void
    {
        if ($type === 'gzip') {
            $input = fopen($source, 'rb');
            $output = fopen($destination, 'wb');
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('Unable to prepare the uploaded archive.');
            }
            try {
                stream_copy_to_stream($input, $output);
            } finally {
                fclose($input);
                fclose($output);
            }

            return;
        }

        $output = gzopen($destination, 'wb6');
        if ($output === false) {
            throw new RuntimeException('Unable to create the normalized import archive.');
        }

        try {
            if ($type === 'sql') {
                $input = fopen($source, 'rb');
                if (! is_resource($input)) {
                    throw new RuntimeException('Unable to read the uploaded SQL file.');
                }
                try {
                    $this->copyToGzip($input, $output);
                } finally {
                    fclose($input);
                }
            } elseif ($type === 'zip') {
                $zip = $this->openZip($source);
                try {
                    $entry = $this->zipSqlEntry($zip);
                    $input = $zip->getStream($entry);
                    if (! is_resource($input)) {
                        throw new RuntimeException('Unable to read the SQL file inside the ZIP archive.');
                    }
                    try {
                        $this->copyToGzip($input, $output);
                    } finally {
                        fclose($input);
                    }
                } finally {
                    $zip->close();
                }
            } else {
                throw new RuntimeException('Unsupported archive type.');
            }
        } finally {
            gzclose($output);
        }
    }

    private function archiveType(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_ends_with($name, '.sql.gz') => 'gzip',
            str_ends_with($name, '.sql') => 'sql',
            str_ends_with($name, '.zip') => 'zip',
            default => throw new RuntimeException('Choose an .sql, .sql.gz, or .zip file.'),
        };
    }

    /** @return array{int,string} */
    private function inspectSql(string $path): array
    {
        $size = filesize($path);
        $input = fopen($path, 'rb');
        if ($size === false || ! is_resource($input)) {
            throw new RuntimeException('Unable to read the uploaded SQL file.');
        }
        try {
            $sample = fread($input, self::SAMPLE_BYTES);
        } finally {
            fclose($input);
        }

        return [(int) $size, is_string($sample) ? $sample : ''];
    }

    /** @return array{int,string} */
    private function inspectGzip(string $path): array
    {
        $input = gzopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('The gzip archive cannot be opened.');
        }

        $size = 0;
        $sample = '';
        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 1_048_576);
                if ($chunk === false) {
                    throw new RuntimeException('The gzip archive is corrupt.');
                }
                $size += strlen($chunk);
                $this->assertExtractedSize($size);
                if (strlen($sample) < self::SAMPLE_BYTES) {
                    $sample .= substr($chunk, 0, self::SAMPLE_BYTES - strlen($sample));
                }
            }
        } finally {
            gzclose($input);
        }

        return [$size, $sample];
    }

    /** @return array{int,string} */
    private function inspectZip(string $path): array
    {
        $zip = $this->openZip($path);
        try {
            if ($zip->numFiles > (int) config('database-import.max_zip_entries', 20)) {
                throw new RuntimeException('The ZIP archive contains too many entries.');
            }

            $entry = $this->zipSqlEntry($zip);
            $stat = $zip->statName($entry);
            if (! is_array($stat)) {
                throw new RuntimeException('Unable to inspect the SQL entry in the ZIP archive.');
            }

            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            if ($size / $compressed > (int) config('database-import.max_zip_ratio', 200)) {
                throw new RuntimeException('The ZIP compression ratio is unsafe.');
            }
            $this->assertExtractedSize($size);

            $input = $zip->getStream($entry);
            if (! is_resource($input)) {
                throw new RuntimeException('Unable to read the SQL entry in the ZIP archive.');
            }
            try {
                $sample = stream_get_contents($input, self::SAMPLE_BYTES);
            } finally {
                fclose($input);
            }

            return [$size, is_string($sample) ? $sample : ''];
        } finally {
            $zip->close();
        }
    }

    private function openZip(string $path): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP support is required to import .zip files.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The ZIP archive is corrupt or unsupported.');
        }

        return $zip;
    }

    private function zipSqlEntry(ZipArchive $zip): string
    {
        $matches = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) ($stat['name'] ?? '');
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
                throw new RuntimeException('The ZIP archive contains an unsafe path.');
            }
            $mode = ((int) ($stat['external_attributes'] ?? 0) >> 16) & 0xF000;
            if ($mode === 0xA000) {
                throw new RuntimeException('ZIP symbolic links are not allowed.');
            }
            if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('Encrypted ZIP entries are not supported.');
            }
            if (str_ends_with(strtolower($name), '.sql')) {
                $matches[] = $name;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('A ZIP upload must contain exactly one .sql file.');
        }

        return $matches[0];
    }

    private function assertExtractedSize(int $size): void
    {
        $limit = (int) config('database-import.max_extracted_mb', 8192) * 1024 * 1024;
        if ($size < 1) {
            throw new RuntimeException('The uploaded SQL file is empty.');
        }
        if ($size > $limit) {
            throw new RuntimeException('The extracted SQL file exceeds the configured size limit.');
        }
    }

    /** @param resource $input @param resource $output */
    private function copyToGzip($input, $output): void
    {
        while (! feof($input)) {
            $chunk = fread($input, 1_048_576);
            if ($chunk === false || ($chunk !== '' && gzwrite($output, $chunk) === false)) {
                throw new RuntimeException('The SQL archive could not be normalized.');
            }
        }
    }
}
