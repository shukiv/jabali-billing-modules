<?php
/**
 * SSO redemption helper: the piece sso_smoke.php cannot
 * cover — proving a minted login-token URL actually logs the TARGET user in
 * when opened in a clean browser session.
 *
 * Two modes:
 *
 *   php sso_redeem.php <host[:port]> <kid> <secret> mint
 *       Creates a throwaway user, mints a login-token URL for it, prints
 *       JSON: {user_id, username, email, url}. The URL is SINGLE-USE — do
 *       NOT curl it. Open it exactly once, in a browser context with no
 *       existing panel session (a fresh Playwright context qualifies).
 *
 *   php sso_redeem.php <host[:port]> <kid> <secret> cleanup <user_id>
 *       Deletes the throwaway user.
 *
 * Token scopes: read:*, write:users, delete:users (cleanup only).
 *
 * After `mint`, verify in the clean browser that the session belongs to the
 * printed username/email (panel recovery/settings page shows the account),
 * then run `cleanup`.
 */

require __DIR__ . '/../../shared/src/JabaliApiClient.php';

[$self, $host, $kid, $secret, $mode, $arg] = array_pad(array_slice($argv, 0, 6), 6, null);
if (!$host || !$kid || !$secret || !in_array($mode, ['mint', 'cleanup'], true)) {
    fwrite(STDERR, "usage: sso_redeem.php <host[:port]> <token-id> <token-secret> mint|cleanup [user_id]\n");
    exit(2);
}

$c = new JabaliApiClient($host, $kid, $secret);

if ($mode === 'cleanup') {
    if (!$arg) {
        fwrite(STDERR, "cleanup needs the user_id printed by mint\n");
        exit(2);
    }
    $c->deleteUser($arg, true, false);
    echo "deleted $arg\n";
    exit(0);
}

// mint
if (!$c->hasCapability('users.login_token')) {
    fwrite(STDERR, "panel does not advertise users.login_token — needs a panel with the login-token endpoint\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(3));
$username = "jabred$suffix";
$email = "jab-redeem-$suffix@example.com";

$r = $c->createUser($email, 'Rdm-' . bin2hex(random_bytes(6)), $username);
$userId = (string)($r['user_id'] ?? '');
if ($userId === '') {
    fwrite(STDERR, "create failed: " . json_encode($r) . "\n");
    exit(1);
}

// No client_ip: the redeeming browser is not the billing server, and the
// panel does not IP-bind Kratos codes anyway.
$t = $c->mintLoginToken($userId, null, 300);
$url = (string)($t['url'] ?? '');
if ($url === '') {
    fwrite(STDERR, "mint failed: " . json_encode($t) . "\n");
    exit(1);
}

echo json_encode([
    'user_id' => $userId,
    'username' => $username,
    'email' => $email,
    'url' => $url,
    'expires_in' => $t['expires_in'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
