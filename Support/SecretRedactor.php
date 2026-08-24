<?php

namespace App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support;

use Illuminate\Support\Str;

class SecretRedactor
{
    public function redact(string $message): string
    {
        $message = preg_replace(
            '/(?i)(password|passwd|pwd|secret|token)(\s*[=:]\s*)([^\s,;]+)/',
            '$1$2[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/(?i)(mysql|postgres(?:ql)?):\/\/[^\s@]+@/', '$1://[REDACTED]@', $message) ?? $message;
        $message = preg_replace('/(?i)(-p|--password=)([^\s]+)/', '$1[REDACTED]', $message) ?? $message;

        return Str::limit(trim($message), 4000);
    }
}
