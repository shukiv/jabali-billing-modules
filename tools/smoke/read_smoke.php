<?php
/**
 * Read-only live smoke test for the Jabali Automation API through the shared
 * JabaliApiClient. Safe to run against any panel with a read-scoped token —
 * touches nothing. Optionally exercises a reversible disable/enable when a
 * throwaway non-admin user id is passed as the 4th argument.
 *
 * Usage:
 *   php read_smoke.php <host[:port]> <token-id> <token-secret> [reversible-user-id]
 *
 * Token scopes: read:status, read:users (+ write:users for the optional
 * disable/enable check).
 */

require __DIR__ . '/../../shared/src/JabaliApiClient.php';

[$self, $host, $kid, $secret] = array_pad(array_slice($argv, 0, 4), 4, null);
$reversibleUserId = $argv[4] ?? '';
if (!$host || !$kid || !$secret) {
    fwrite(STDERR, "usage: read_smoke.php <host[:port]> <token-id> <token-secret> [reversible-user-id]\n");
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
        echo "FAIL  $label — " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

check('GET /status (read:status)', function () use ($c) {
    $s = $c->status();
    return 'keys: ' . implode(',', array_slice(array_keys($s), 0, 6));
});

check('GET /capabilities', function () use ($c) {
    $caps = $c->capabilities();
    return $caps === [] ? '(none advertised — older panel or read-only token)' : implode(', ', $caps);
});

// NOTE: each list read below uses a DISTINCT query string on purpose. Two
// byte-identical signed GETs within the same wall-clock second produce the
// same signature and the panel replay-blocks the second one (401) — the
// nonce gate can't tell a legitimate rapid-repeat from a captured replay.
// Distinct paths → distinct signatures → no self-collision. (See the client
// gotcha in docs/HANDOFF.md §9.)
check('GET /users (read:users)', function () use ($c) {
    $r = $c->get(JabaliApiClient::API_PREFIX . '/users', ['limit' => 200]);
    $users = $r['data'] ?? [];
    $first = $users[0] ?? null;
    return count($users) . ' users; row keys: ' . ($first ? implode(',', array_keys($first)) : 'n/a');
});

check('findUser() nonexistent → null (plain-path list read)', function () use ($c) {
    if ($c->findUser('no-such-user-xyz-12345') !== null) {
        throw new Exception('expected null');
    }
    return null;
});

check('query-string signing (GET /users?page=1)', function () use ($c) {
    $r = $c->get(JabaliApiClient::API_PREFIX . '/users', ['page' => 1]);
    return 'total=' . ($r['total'] ?? '?');
});

check('scope_denied surfaces cleanly (read:mail if not granted)', function () use ($c) {
    try {
        $c->get(JabaliApiClient::API_PREFIX . '/mail/summary');
        return 'read:mail granted — no denial to assert';
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 403) {
            throw new Exception('expected 403, got ' . $e->getHttpStatus());
        }
        return 'code=' . $e->getErrorCode();
    }
});

check('bad secret → clean 401 (not clock skew)', function () use ($host, $kid) {
    $bad = new JabaliApiClient($host, $kid, str_repeat('0', 64));
    try {
        $bad->status();
        throw new Exception('expected 401');
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 401 || $e->isClockSkew()) {
            throw new Exception('expected non-skew 401, got ' . $e->getHttpStatus());
        }
        return 'clean 401';
    }
});

if ($reversibleUserId !== '') {
    check("disable + enable $reversibleUserId (write:users, reversible)", function () use ($c, $reversibleUserId) {
        $d = $c->disableUser($reversibleUserId);
        $e = $c->enableUser($reversibleUserId);
        return 'disable ok=' . var_export($d['ok'] ?? null, true) . ' enable ok=' . var_export($e['ok'] ?? null, true);
    });
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURES\n";
exit($fails === 0 ? 0 : 1);
