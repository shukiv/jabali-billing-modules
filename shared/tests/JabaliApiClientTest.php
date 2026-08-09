<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/JabaliApiClient.php';

/**
 * Locks the PHP signing implementation to reference vectors generated with
 * the server runbook's openssl recipe (plans/automation-api-tokens-runbook.md
 * in jabali2):
 *
 *   BODY_HASH=$(printf "%s" "$BODY" | openssl dgst -sha256 -hex | awk '{print $2}')
 *   TO_SIGN=$(printf "%s\n%s\n%s\n%s" "$METHOD" "$PATH_Q" "$TS" "$BODY_HASH")
 *   SIG=$(printf "%s" "$TO_SIGN" | openssl dgst -sha256 -hmac "$SECRET" -hex)
 */
final class JabaliApiClientTest extends TestCase
{
    private const SECRET = '9d30e1edeb89fac42974a73fda1238de394d073785ac4959aabbccddeeff0011';

    public function testSignMatchesOpensslVectorGetNoBody(): void
    {
        $sig = JabaliApiClient::sign(
            self::SECRET,
            'GET',
            '/api/v1/automation/status?verbose=1',
            1754600000,
            ''
        );
        $this->assertSame(
            'e53ec40bee51eee5c271db00c8c4936e78e96996edf8edb3384af068e2effe6b',
            $sig
        );
    }

    public function testSignMatchesOpensslVectorPostJsonBody(): void
    {
        $sig = JabaliApiClient::sign(
            self::SECRET,
            'POST',
            '/api/v1/automation/users',
            1754600001,
            '{"email":"a@b.c","password":"hunter2hunter2"}'
        );
        $this->assertSame(
            '6f51269ffa846c4a195b250a8a5d47e8238b52a6438498a64a7dce1a28a35466',
            $sig
        );
    }

    public function testSignEmptyBodyUsesSha256OfEmptyString(): void
    {
        // hex(sha256("")) = e3b0c442... — the runbook's documented base case.
        $withEmpty = JabaliApiClient::sign(self::SECRET, 'GET', '/x', 1, '');
        $manual = hash_hmac(
            'sha256',
            "GET\n/x\n1\n" . hash('sha256', ''),
            self::SECRET
        );
        $this->assertSame($manual, $withEmpty);
    }

    public function testSignQueryStringChangesSignature(): void
    {
        $a = JabaliApiClient::sign(self::SECRET, 'GET', '/api/v1/automation/domains?page=1', 1754600000, '');
        $b = JabaliApiClient::sign(self::SECRET, 'GET', '/api/v1/automation/domains?page=2', 1754600000, '');
        $this->assertNotSame($a, $b, 'PATH includes the query string; signatures must differ');
    }

    public function testSignMethodIsUppercased(): void
    {
        $a = JabaliApiClient::sign(self::SECRET, 'get', '/x', 1, '');
        $b = JabaliApiClient::sign(self::SECRET, 'GET', '/x', 1, '');
        $this->assertSame($a, $b);
    }

    public function testConstructorRejectsHttpScheme(): void
    {
        $this->expectException(JabaliApiException::class);
        new JabaliApiClient('http://panel.example.com', 'kid', 'secret');
    }

    public function testConstructorRejectsMissingToken(): void
    {
        $this->expectException(JabaliApiException::class);
        new JabaliApiClient('panel.example.com', '', '');
    }

    public function testConstructorDefaultsPortTo8443(): void
    {
        $c = new JabaliApiClient('panel.example.com', 'kid', 'secret');
        $r = new ReflectionProperty(JabaliApiClient::class, 'baseUrl');
        $r->setAccessible(true);
        $this->assertSame('https://panel.example.com:8443', $r->getValue($c));
    }

    public function testConstructorHonorsExplicitPortAndScheme(): void
    {
        $c = new JabaliApiClient('https://panel.example.com:443', 'kid', 'secret');
        $r = new ReflectionProperty(JabaliApiClient::class, 'baseUrl');
        $r->setAccessible(true);
        $this->assertSame('https://panel.example.com:443', $r->getValue($c));
    }

    public function testNormalizeCapabilitiesLiveShape(): void
    {
        // Verbatim capability shape from a live panel
        $resp = json_decode(
            '{"actions":[{"action":"services.restart","method":"POST","path":"/automation/services/:name/restart","scope":"write:services","async":false},'
            . '{"action":"users.disable","method":"POST","path":"/automation/users/:id/disable","scope":"write:users","async":false},'
            . '{"action":"backups.create","method":"POST","path":"/automation/backups","scope":"write:backups","async":true}],"ok":true}',
            true
        );
        $this->assertSame(
            ['services.restart', 'users.disable', 'backups.create'],
            JabaliApiClient::normalizeCapabilities($resp)
        );
    }

    public function testNormalizeCapabilitiesForwardCompatShapes(): void
    {
        $this->assertSame(['a.b', 'c.d'], JabaliApiClient::normalizeCapabilities(['actions' => ['a.b', 'c.d']]));
        $this->assertSame(['a.b'], JabaliApiClient::normalizeCapabilities(['data' => [['action' => 'a.b']]]));
        $this->assertSame(['a.b'], JabaliApiClient::normalizeCapabilities(['a.b']));
        $this->assertSame([], JabaliApiClient::normalizeCapabilities([]));
        $this->assertSame([], JabaliApiClient::normalizeCapabilities(['ok' => true]));
    }

    public function testExceptionTaxonomy(): void
    {
        $e = new JabaliApiException('m', 429, 'rate_limited', true);
        $this->assertTrue($e->isRetryable());
        $this->assertFalse($e->isClockSkew());
        $this->assertFalse($e->isUnsupported());

        $skew = new JabaliApiException('m', 401, 'clock_skew', false);
        $this->assertTrue($skew->isClockSkew());

        $unsupported = new JabaliApiException('m', 404, 'unsupported', false);
        $this->assertTrue($unsupported->isUnsupported());
    }

    /** @return JabaliApiException via the private transport error mapper */
    private function mapError(int $status, array $body, array $headers, int $sentTs, bool $idempotent): JabaliApiException
    {
        $c = new JabaliApiClient('panel.example.com', 'kid', 'secret');
        $m = new ReflectionMethod(JabaliApiClient::class, 'mapError');
        $m->setAccessible(true);
        return $m->invoke($c, $status, $body, $headers, $sentTs, $idempotent);
    }

    public function testReplay401OnIdempotentRequestIsRetryable(): void
    {
        // The panel's HMAC middleware emits {"error":"replay detected"} when a
        // valid signature was already seen (Redis SETNX gate). Two identical
        // signed GETs within one second self-collide; retry is safe.
        $ts = time();
        $e = $this->mapError(
            401,
            ['error' => 'replay detected'],
            ['date' => gmdate('D, d M Y H:i:s', $ts) . ' GMT'],
            $ts,
            true
        );
        $this->assertSame('replay_detected', $e->getErrorCode());
        $this->assertTrue($e->isRetryable());
        $this->assertSame(401, $e->getHttpStatus());
    }

    public function testReplay401OnNonIdempotentRequestIsNotRetryable(): void
    {
        $ts = time();
        $e = $this->mapError(
            401,
            ['error' => 'replay detected'],
            ['date' => gmdate('D, d M Y H:i:s', $ts) . ' GMT'],
            $ts,
            false
        );
        $this->assertFalse($e->isRetryable());
        $this->assertSame('replay detected', $e->getErrorCode());
    }

    public function testSkewCheckTakesPrecedenceOverReplayBody(): void
    {
        // A replay-401 with a far-off Date header is a clock problem, not a
        // replay — the skew diagnostic must win.
        $ts = time();
        $e = $this->mapError(
            401,
            ['error' => 'replay detected'],
            ['date' => gmdate('D, d M Y H:i:s', $ts + 3600) . ' GMT'],
            $ts,
            true
        );
        $this->assertSame('clock_skew', $e->getErrorCode());
        $this->assertFalse($e->isRetryable());
    }
}
