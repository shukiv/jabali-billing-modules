<?php
/**
 * Full account-lifecycle live smoke for the billing endpoints,
 * driven through the shared JabaliApiClient. Creates a throwaway user and
 * exercises the whole lifecycle, cleaning up after itself.
 *
 * DESTRUCTIVE on the throwaway user only. Run against a TEST panel.
 *
 * Usage:
 *   php lifecycle_smoke.php <host[:port]> <token-id> <token-secret>
 *
 * Token scopes: read:*, write:users, delete:users.
 *
 * Verifies: capabilities, create (201), idempotent exists-retry (200),
 * same-email/new-username second account (201, email is NOT unique recent panel versions),
 * username-collision/different-email conflict (409), username lookup filter +
 * `suspended` row field, password change, package 404 + assign, per-user +
 * bulk usage, delete dry-run, confirm-required (422), delete, gone.
 */

require __DIR__ . '/../../shared/src/JabaliApiClient.php';

[$self, $host, $kid, $secret] = array_pad(array_slice($argv, 0, 4), 4, null);
if (!$host || !$kid || !$secret) {
    fwrite(STDERR, "usage: lifecycle_smoke.php <host[:port]> <token-id> <token-secret>\n");
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
$email = "jab-smoke-$suffix@example.com";
$username = "jabsmoke$suffix";
$password = 'Sm0ke-' . bin2hex(random_bytes(6)); // 18 chars, satisfies min-10
$userId = '';

check('capabilities advertise billing actions', function () use ($c) {
    $caps = $c->capabilities();
    foreach (['users.create', 'users.delete', 'users.password', 'users.package', 'usage.read', 'packages.read'] as $want) {
        if (!in_array($want, $caps, true)) {
            throw new Exception("missing $want in: " . implode(',', $caps));
        }
    }
    return implode(', ', $caps);
});

check('create user (201 created + user_id)', function () use ($c, $email, $password, $username, &$userId) {
    $r = $c->createUser($email, $password, $username);
    if (($r['status'] ?? '') !== 'created' || empty($r['user_id'])) {
        throw new Exception(json_encode($r));
    }
    $userId = $r['user_id'];
    return $userId;
});

check('identical create retry → 200 exists, same user_id', function () use ($c, $email, $password, $username, &$userId) {
    $r = $c->createUser($email, $password, $username);
    if (($r['status'] ?? '') !== 'exists' || ($r['user_id'] ?? '') !== $userId) {
        throw new Exception(json_encode($r));
    }
    return null;
});

check('same email + new username → 201 second account (email not unique), cleaned up', function () use ($c, $email, $password, $suffix) {
    $r = $c->createUser($email, $password, "jabsmoke2$suffix");
    if (($r['status'] ?? '') !== 'created' || empty($r['user_id'])) {
        throw new Exception(json_encode($r));
    }
    $c->deleteUser($r['user_id'], true, false);
    return null;
});

check('username collision + different email → 409 conflict', function () use ($c, $password, $username) {
    try {
        $c->createUser('someone-else@example.com', $password, $username);
        throw new Exception('expected 409');
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 409) {
            throw new Exception('got ' . $e->getHttpStatus() . ': ' . $e->getMessage());
        }
    }
    return null;
});

check('lookup filter ?username= + suspended row field', function () use ($c, $username, &$userId) {
    $row = $c->findUser($username);
    if ($row === null || $row['id'] !== $userId) {
        throw new Exception('lookup mismatch: ' . json_encode($row));
    }
    if (!array_key_exists('suspended', $row)) {
        throw new Exception('suspended field missing from row shape');
    }
    return null;
});

check('set password', function () use ($c, &$userId) {
    $r = $c->setUserPassword($userId, 'N3w-' . bin2hex(random_bytes(6)));
    if (($r['ok'] ?? false) !== true) {
        throw new Exception(json_encode($r));
    }
    return null;
});

check('set unknown package → 404 not_found', function () use ($c, &$userId) {
    try {
        $c->setUserPackage($userId, '01GHOSTGHOSTGHOSTGHOSTGH00');
        throw new Exception('expected 404');
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 404) {
            throw new Exception('got ' . $e->getHttpStatus());
        }
    }
    return null;
});

check('set real package (first from GET /packages)', function () use ($c, &$userId) {
    $pkgs = $c->listPackages();
    if ($pkgs === []) {
        return 'no packages on box — skipped assignment';
    }
    $r = $c->setUserPackage($userId, $pkgs[0]['id']);
    if (($r['ok'] ?? false) !== true) {
        throw new Exception(json_encode($r));
    }
    return 'assigned ' . $pkgs[0]['name'];
});

check('per-user usage endpoint', function () use ($c, &$userId) {
    $u = $c->getUserUsage($userId);
    foreach (['disk_used_mb', 'disk_quota_mb', 'bw_used_mb', 'bw_quota_mb'] as $k) {
        if (!array_key_exists($k, $u)) {
            throw new Exception("missing $k: " . json_encode($u));
        }
    }
    return 'disk ' . $u['disk_used_mb'] . '/' . $u['disk_quota_mb'] . 'MB bw ' . $u['bw_used_mb'] . '/' . $u['bw_quota_mb'] . 'MB';
});

check('bulk usage endpoint includes user', function () use ($c, &$userId) {
    $resp = $c->get(JabaliApiClient::API_PREFIX . '/usage');
    foreach (($resp['data'] ?? []) as $row) {
        if (($row['user_id'] ?? '') === $userId) {
            return 'rows=' . ($resp['total'] ?? '?');
        }
    }
    throw new Exception('smoke user missing from bulk rows');
});

check('delete dry_run (no changes, preview counts)', function () use ($c, &$userId) {
    $r = $c->deleteUser($userId, true, true);
    if (($r['status'] ?? '') !== 'dry_run' || !isset($r['preview'])) {
        throw new Exception(json_encode($r));
    }
    return json_encode($r['preview']);
});

check('delete without confirm → 422', function () use ($c, &$userId) {
    try {
        $c->delete(JabaliApiClient::API_PREFIX . '/users/' . $userId, ['confirm' => false]);
        throw new Exception('expected 422');
    } catch (JabaliApiException $e) {
        if ($e->getHttpStatus() !== 422) {
            throw new Exception('got ' . $e->getHttpStatus());
        }
    }
    return null;
});

check('delete with confirm', function () use ($c, &$userId) {
    $r = $c->deleteUser($userId, true, false);
    if (($r['ok'] ?? false) !== true) {
        throw new Exception(json_encode($r));
    }
    return null;
});

check('user gone after delete', function () use ($c, $username) {
    if ($c->findUser($username) !== null) {
        throw new Exception('user still present');
    }
    return null;
});

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURES\n";
exit($fails === 0 ? 0 : 1);
