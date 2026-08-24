<?php

use App\Vito\Plugins\Cp6\VitoDeployDatabaseImporter\Support\SecretRedactor;

test('it removes common credential forms from import errors', function () {
    $message = 'password=hunter2 token: abc123 mysql://root:secret@localhost/db --password=another';
    $redacted = (new SecretRedactor)->redact($message);

    expect($redacted)
        ->not->toContain('hunter2')
        ->not->toContain('abc123')
        ->not->toContain('root:secret')
        ->not->toContain('another')
        ->toContain('[REDACTED]');
});
