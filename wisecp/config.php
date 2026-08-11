<?php
/**
 * Jabali Panel — WiseCP module metadata.
 * Flat structure per the official sample-hosting-panel-module config.php:
 * https://github.com/wisecp/sample-hosting-panel-module
 */

return [
    'type' => 'hosting',

    // Server connection UI
    'access-hash' => false,
    // true → WiseCP shows a "Check Connection" button and calls testConnect()
    // on save, so bad host/token is rejected instead of silently accepted.
    'server-info-checker' => true,
    'server-info-port' => true,
    // Panel web UI is HTTPS-only on 8443
    'server-info-not-secure-port' => 8443,
    'server-info-secure-port' => 8443,

    'supported' => [
        'disk-bandwidth-usage',
        'change-password',
    ],
];
