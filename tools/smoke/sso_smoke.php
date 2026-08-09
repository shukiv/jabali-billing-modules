<?php
/**
 * Login-token live smoke: mints a one-time panel login link for a
 * throwaway user, confirms the returned URL is on the panel host and live,
 * and that admin targets are refused. Cleans up the throwaway user IF the
 * token carries delete:users (otherwise prints the id to delete via CLI).
 *
 * Usage:
 *   php sso_smoke.php <host[:port]> <token-id> <token-secret>
 *
 * Token scopes: read:*, write:users (+ delete:users for auto-cleanup).
 *
 * NOTE: this verifies LINK MINTING + liveness, not end-user redemption. The
 * link is a single-use Kratos recovery URL; redeeming it (browser lands
 * logged-in on the panel recovery/settings page) is stock Kratos behavior,
 * unchanged by this milestone. To verify redemption, open the URL in a
 * CLEAN/incognito browser with no existing panel session.
 */

require __DIR__ . '/../../shared/src/JabaliApiClient.php';

[$self, $host, $kid, $secret] = array_pad(array_slice($argv, 0, 4), 4, null);
if (!$host || !$kid || !$secret) {
    fwrite(STDERR, "usage: sso_smoke.php <host[:port]> <token-id> <token-secret>\n");
    exit(2);
}

$c = new JabaliApiClient($host, $kid, $secret);
$fails = 0;
function check($label, $fn)
{
    global $fails;
    try {
        $out = $fn();
        echo "PASS  $label" . ($out !== null ? " — $out" : '') . "\n";
    } catch (Exception $e) {
        $fails++;
        echo "FAIL  $label — " . $e->getMessage() . "\n";
    }
}

$suffix = bin2hex(random_bytes(3));
$username = "jabsso$suffix";
$userId = '';

check('capabilities advertise users.login_token', function () use ($c) {
    if (!$c->hasCapability('users.login_token')) {
        throw new Exception('missing: ' . implode(',', $c->capabilities()));
    }
    return null;
});

check('create throwaway user', function () use ($c, $username, $suffix, &$userId) {
    $r = $c->createUser("jab-sso-$suffix@example.com", 'Ss0-' . bin2hex(random_bytes(6)), $username);
    $userId = $r['user_id'] ?? '';
    if ($userId === '') {
        throw new Exception(json_encode($r));
    }
    return $userId;
});

$loginUrl = '';
check('mint login token (client_ip passed, ttl 120)', function () use ($c, &$userId, &$loginUrl) {
    $r = $c->mintLoginToken($userId, '203.0.113.7', 120);
    $loginUrl = (string)($r['url'] ?? '');
    if ($loginUrl === '' || ($r['expires_in'] ?? 0) !== 120) {
        throw new Exception(json_encode($r));
    }
    // Client-side panel-host validation already ran inside mintLoginToken().
    return substr($loginUrl, 0, 72) . '…';
});

check('recovery URL is live (panel serves the flow)', function () use (&$loginUrl) {
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 10, CURLOPT_COOKIEFILE => '',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($code !== 200) {
        throw new Exception("HTTP $code at $finalUrl");
    }
    return '200 at ' . parse_url($finalUrl, PHP_URL_PATH);
});

check('admin target refused (409)', function () use ($c) {
    $admins = array_values(array_filter($c->listUsers(), fn($u) => $u['is_admin']));
    if ($admins === []) {
        return 'no admin visible — skipped';
    }
    try {
        $c->mintLoginToken($admins[0]['id']);
        throw new Exception('expected 409');
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 409) {
            throw new Exception('got ' . $e->getHttpStatus());
        }
    }
    return null;
});

check('cleanup throwaway user', function () use ($c, &$userId) {
    try {
        $c->deleteUser($userId, true, false);
        return 'deleted';
    } catch (JabaliApiException $e) {
        if ($e->getErrorCode() === 'scope_denied' || $e->getHttpStatus() === 403) {
            return "token lacks delete:users — remove manually: jabali user delete $userId --force";
        }
        throw $e;
    }
});

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURES\n";
exit($fails === 0 ? 0 : 1);
