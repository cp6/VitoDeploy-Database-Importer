<?php

return [
    'disk' => 'local',
    'max_upload_mb' => 2048,
    'max_extracted_mb' => 8192,
    'max_zip_entries' => 20,
    'max_zip_ratio' => 200,
    'remote_download_connect_timeout_seconds' => 15,
    'remote_download_timeout_seconds' => 7200,
    'remote_download_max_redirects' => 5,
    'remote_download_stall_seconds' => 60,
    'remote_download_minimum_bytes_per_second' => 1024,
    'remote_download_require_https' => true,
    'remote_download_allowed_ports' => [80, 443],
    'minimum_remote_headroom_mb' => 512,
    'failed_file_retention_hours' => 24,
    'drop_tables_on_uninstall' => false,
];
