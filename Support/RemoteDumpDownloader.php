<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

use RuntimeException;

class RemoteDumpDownloader
{
    public function __construct(private readonly RemoteDownloadIntegrity $integrity) {}

    public function validate(string $url): void
    {
        $this->safeEndpoint($url);
    }

    /** @param null|callable(int,?int):void $progress @return array{name:string,size:int,final_url:string} */
    public function download(string $url, string $destination, ?callable $progress = null): array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL support is required to download a database dump from a URL.');
        }

        $currentUrl = $url;
        $redirects = 0;
        $maximumRedirects = (int) config('database-import.remote_download_max_redirects', 5);

        while (true) {
            $endpoint = $this->safeEndpoint($currentUrl);
            $result = $this->request($currentUrl, $endpoint, $destination, $progress);

            if (in_array($result['status'], [301, 302, 303, 307, 308], true)) {
                if ($result['location'] === null || $redirects++ >= $maximumRedirects) {
                    throw new RuntimeException('The remote download exceeded the allowed redirect limit.');
                }
                $currentUrl = $this->redirectUrl($currentUrl, $result['location']);

                continue;
            }

            if ($result['status'] !== 200) {
                throw new RuntimeException('The remote server returned HTTP '.$result['status'].' instead of the database dump.');
            }
            if ($result['content_range'] !== null) {
                throw new RuntimeException('The remote server returned only part of the database dump.');
            }
            if ($result['content_encoding'] !== null && strtolower($result['content_encoding']) !== 'identity') {
                throw new RuntimeException('The remote server encoded the response unexpectedly; provide a direct, identity-encoded download URL.');
            }

            $this->integrity->assertComplete(
                $destination,
                $result['received'],
                $result['content_length'],
                $result['content_md5'],
                $result['sha256'],
            );

            return [
                'name' => $this->downloadName($currentUrl, $result['content_disposition']),
                'size' => $result['received'],
                'final_url' => $currentUrl,
            ];
        }
    }

    /** @param array{host:string,port:int,ip:string} $endpoint @param null|callable(int,?int):void $progress */
    private function request(string $url, array $endpoint, string $destination, ?callable $progress): array
    {
        $output = fopen($destination, 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Unable to create the staged remote download.');
        }

        $headers = [];
        $received = 0;
        $tooLarge = false;
        $ch = curl_init();
        if ($ch === false) {
            fclose($output);
            throw new RuntimeException('Unable to initialize the remote download.');
        }

        $resolveIp = str_contains($endpoint['ip'], ':') ? '['.$endpoint['ip'].']' : $endpoint['ip'];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ((bool) config('database-import.remote_download_require_https', true) ? 0 : CURLPROTO_HTTP),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) config('database-import.remote_download_connect_timeout_seconds', 15),
            CURLOPT_TIMEOUT => (int) config('database-import.remote_download_timeout_seconds', 7200),
            CURLOPT_LOW_SPEED_LIMIT => (int) config('database-import.remote_download_minimum_bytes_per_second', 1024),
            CURLOPT_LOW_SPEED_TIME => (int) config('database-import.remote_download_stall_seconds', 60),
            CURLOPT_RESOLVE => [$endpoint['host'].':'.$endpoint['port'].':'.$resolveIp],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream, application/sql, application/gzip, application/zip', 'Accept-Encoding: identity'],
            CURLOPT_USERAGENT => 'VitoDeploy-Database-Importer/1.0',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($output, &$headers, &$received, &$tooLarge, $progress): int {
                $length = strlen($chunk);
                if ($received + $length > $this->integrity->maximumBytes()) {
                    $tooLarge = true;

                    return 0;
                }
                $written = fwrite($output, $chunk);
                if ($written === false || $written !== $length) {
                    return 0;
                }
                $received += $written;
                if ($progress !== null) {
                    $progress($received, isset($headers['content-length']) ? (int) $headers['content-length'] : null);
                }

                return $written;
            },
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers, &$tooLarge): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = [];

                    return $length;
                }
                $separator = strpos($line, ':');
                if ($separator === false) {
                    return $length;
                }
                $name = strtolower(trim(substr($line, 0, $separator)));
                $value = trim(substr($line, $separator + 1));
                $headers[$name] = $value;
                if ($name === 'content-length' && ctype_digit($value)) {
                    try {
                        $this->integrity->assertWithinLimit((int) $value);
                    } catch (RuntimeException) {
                        $tooLarge = true;

                        return 0;
                    }
                }

                return $length;
            },
        ]);

        try {
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
        } finally {
            fflush($output);
            fclose($output);
            curl_close($ch);
        }

        if ($tooLarge) {
            throw new RuntimeException('The remote file exceeds the configured upload size limit.');
        }
        if ($ok === false) {
            throw new RuntimeException('The remote download failed or ended early: '.$error);
        }

        $digest = $headers['digest'] ?? null;
        $sha256 = null;
        if (is_string($digest) && preg_match('/(?:^|,)\s*sha-256=([^,\s]+)\s*(?:,|$)/i', $digest, $match)) {
            $sha256 = trim($match[1], '"');
        }

        return [
            'status' => $status,
            'received' => $received,
            'location' => $headers['location'] ?? null,
            'content_length' => isset($headers['content-length']) && ctype_digit($headers['content-length']) ? (int) $headers['content-length'] : null,
            'content_disposition' => $headers['content-disposition'] ?? null,
            'content_encoding' => $headers['content-encoding'] ?? null,
            'content_range' => $headers['content-range'] ?? null,
            'content_md5' => isset($headers['content-md5']) ? trim($headers['content-md5'], " \t\n\r\0\x0B\"") : null,
            'sha256' => $sha256,
        ];
    }

    /** @return array{host:string,port:int,ip:string} */
    private function safeEndpoint(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Enter a valid direct URL for the database dump.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $requiredScheme = (bool) config('database-import.remote_download_require_https', true) ? 'https' : null;
        if (($requiredScheme !== null && $scheme !== $requiredScheme) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('The remote database URL must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Remote URLs containing embedded credentials are not allowed.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowedPorts = array_map('intval', (array) config('database-import.remote_download_allowed_ports', [443]));
        if ($host === '' || ! in_array($port, $allowedPorts, true)) {
            throw new RuntimeException('The remote database URL uses a host or port that is not allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolvePublicAddresses($host);
        foreach ($addresses as $address) {
            if ($this->isPublicIp($address)) {
                return ['host' => $host, 'port' => $port, 'ip' => $address];
            }
        }

        throw new RuntimeException('The remote database URL must resolve only to a public internet address.');
    }

    /** @return list<string> */
    private function resolvePublicAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new RuntimeException('The remote database host could not be resolved.');
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        if ($addresses === [] || count(array_filter($addresses, fn (string $ip): bool => ! $this->isPublicIp($ip))) > 0) {
            throw new RuntimeException('The remote database URL must not resolve to a private or reserved address.');
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $blockedNetworks = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
            '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7',
            'fe80::/10', 'ff00::/8',
        ];

        return count(array_filter($blockedNetworks, fn (string $network): bool => $this->ipInNetwork($ip, $network))) === 0;
    }

    private function ipInNetwork(string $ip, string $network): bool
    {
        [$networkIp, $prefix] = explode('/', $network, 2);
        $address = inet_pton($ip);
        $base = inet_pton($networkIp);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }

        $bits = (int) $prefix;
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($base, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($base[$wholeBytes]) & $mask);
    }

    private function redirectUrl(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }
        $authority = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }
        $path = (string) ($base['path'] ?? '/');
        if (str_starts_with($location, '?')) {
            return $authority.$path.$location;
        }

        return $authority.rtrim(str_replace('\\', '/', dirname($path)), '/').'/'.$location;
    }

    private function downloadName(string $url, ?string $contentDisposition): string
    {
        $name = null;
        if ($contentDisposition !== null && preg_match("/filename\\*=UTF-8''([^;]+)/i", $contentDisposition, $match)) {
            $name = rawurldecode(trim($match[1], " \\t\\n\\r\\0\\x0B\\\"'"));
        } elseif ($contentDisposition !== null && preg_match('/filename="?([^";]+)"?/i', $contentDisposition, $match)) {
            $name = trim($match[1]);
        }
        $name ??= rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('The direct URL must identify an .sql, .sql.gz, or .zip file.');
        }

        return substr($name, 0, 255);
    }
}
