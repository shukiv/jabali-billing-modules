<?php
/**
 * Jabali Panel Automation API client.
 *
 * Canonical copy lives in shared/src/ of the jabali-integration repository.
 * The copies under whmcs/lib/, blesta/apis/lib/ and wisecp/lib/ are synced
 * from here by shared/sync.sh — edit the canonical copy only.
 *
 * Implements the Jabali Automation API auth contract with the
 * Write-endpoint semantics:
 *
 *   Authorization: Jabali-HMAC kid=<id>, ts=<unix>, sig=<hex>
 *   sig = hex(HMAC_SHA256(secret, METHOD."\n".PATH."\n".ts."\n".hex(sha256(BODY))))
 *
 * Load-bearing client rules (see docs/API-CONTRACT.md §1):
 *  - PATH in the signature includes the query string; we sign exactly the
 *    bytes sent on the wire.
 *  - The server replay-blocks identical signatures, so every retry re-signs
 *    with a fresh timestamp.
 *  - Read endpoints return {data, total}; write endpoints return
 *    {ok, operation_id?, status, message} and errors {ok:false, error, message}.
 *
 * Requires PHP 7.4+ with curl and json extensions. No composer dependencies.
 */

if (!class_exists('JabaliApiException', false)) {
    require_once __DIR__ . '/JabaliApiException.php';
}

class JabaliApiClient
{
    public const VERSION = '0.1.0';

    /** Automation API prefix, joined to the panel base URL. */
    public const API_PREFIX = '/api/v1/automation';

    /** Max clock drift (seconds) before a 401 is reported as clock skew. */
    private const SKEW_REPORT_THRESHOLD = 240;

    /** @var string e.g. "https://panel.example.com:8443" (no trailing slash) */
    private $baseUrl;

    /** @var string automation token id (the HMAC "kid") */
    private $tokenId;

    /** @var string automation token shared secret (64 hex chars) */
    private $tokenSecret;

    /** @var int request timeout in seconds */
    private $timeout;

    /** @var int connect timeout in seconds */
    private $connectTimeout;

    /** @var string|null optional CA bundle path for self-managed trust */
    private $caBundle;

    /** @var int max automatic retries for retryable failures */
    private $maxRetries;

    /** @var array|null capabilities cache for this instance */
    private $capabilities = null;

    /** @var callable|null optional logger: fn(string $msg): void — MUST NOT receive secrets */
    private $logger;

    /**
     * @param string $host        Panel hostname or URL. "panel.example.com",
     *                            "panel.example.com:8443" and
     *                            "https://panel.example.com:8443" all accepted.
     * @param string $tokenId     Automation token id (kid).
     * @param string $tokenSecret Automation token secret.
     * @param array  $options     timeout(int=15), connect_timeout(int=7),
     *                            ca_bundle(?string), max_retries(int=2),
     *                            logger(?callable).
     */
    public function __construct($host, $tokenId, $tokenSecret, array $options = [])
    {
        $host = trim((string)$host);
        if ($host === '') {
            throw new JabaliApiException('Jabali panel hostname is required', 0, 'config');
        }
        if (strpos($host, '://') === false) {
            $host = 'https://' . $host;
        }
        $parts = parse_url($host);
        if ($parts === false || empty($parts['host'])) {
            throw new JabaliApiException('Invalid Jabali panel hostname: ' . $host, 0, 'config');
        }
        $scheme = $parts['scheme'] ?? 'https';
        if ($scheme !== 'https') {
            // The automation API carries credentials; never allow plaintext.
            throw new JabaliApiException('Jabali panel URL must use https', 0, 'config');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 8443;
        $this->baseUrl = $scheme . '://' . $parts['host'] . ':' . $port;

        $this->tokenId = trim((string)$tokenId);
        $this->tokenSecret = trim((string)$tokenSecret);
        if ($this->tokenId === '' || $this->tokenSecret === '') {
            throw new JabaliApiException('Jabali automation token id + secret are required', 0, 'config');
        }

        $this->timeout = isset($options['timeout']) ? max(1, (int)$options['timeout']) : 15;
        $this->connectTimeout = isset($options['connect_timeout']) ? max(1, (int)$options['connect_timeout']) : 7;
        $this->caBundle = isset($options['ca_bundle']) && $options['ca_bundle'] !== '' ? (string)$options['ca_bundle'] : null;
        $this->maxRetries = isset($options['max_retries']) ? max(0, (int)$options['max_retries']) : 2;
        $this->logger = isset($options['logger']) && is_callable($options['logger']) ? $options['logger'] : null;
    }

    // ------------------------------------------------------------------
    // Signature (kept static + pure so tests can lock it to reference
    // vectors generated with openssl — see shared/tests/).
    // ------------------------------------------------------------------

    /**
     * Compute the request signature.
     *
     * @param string $secret        Token secret (raw string, as minted).
     * @param string $method        Upper-case HTTP method.
     * @param string $pathWithQuery Path INCLUDING query string, e.g.
     *                              "/api/v1/automation/status?verbose=1".
     * @param int    $ts            Unix timestamp (seconds).
     * @param string $body          Raw request body ("" for none).
     * @return string hex signature
     */
    public static function sign($secret, $method, $pathWithQuery, $ts, $body)
    {
        $toSign = strtoupper($method) . "\n"
            . $pathWithQuery . "\n"
            . $ts . "\n"
            . hash('sha256', (string)$body);
        return hash_hmac('sha256', $toSign, $secret);
    }

    // ------------------------------------------------------------------
    // Generic verbs
    // ------------------------------------------------------------------

    /** @return array decoded JSON */
    public function get($path, array $query = [])
    {
        return $this->request('GET', $this->withQuery($path, $query), null, true);
    }

    /**
     * @param bool $idempotent true when the endpoint is idempotent by target
     *                         state (suspend/enable style) — enables retries.
     * @return array decoded JSON
     */
    public function post($path, ?array $body = null, $idempotent = false)
    {
        return $this->request('POST', $path, $body, $idempotent);
    }

    /** @return array decoded JSON */
    public function put($path, ?array $body = null)
    {
        return $this->request('PUT', $path, $body, true);
    }

    /** @return array decoded JSON */
    public function delete($path, ?array $body = null)
    {
        return $this->request('DELETE', $path, $body, true);
    }

    // ------------------------------------------------------------------
    // Capability detection (docs/API-CONTRACT.md §4)
    // ------------------------------------------------------------------

    /**
     * Mounted write actions advertised by the panel. Cached per instance.
     * Returns [] when the capabilities endpoint itself is unavailable
     * (older panel) — callers treat absence as "not supported".
     *
     * @return string[]
     */
    public function capabilities()
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        try {
            $resp = $this->get(self::API_PREFIX . '/capabilities');
            $this->capabilities = self::normalizeCapabilities($resp);
        } catch (JabaliApiException $e) {
            if ($e->getHttpStatus() === 404 || $e->getErrorCode() === 'scope_denied') {
                $this->capabilities = [];
            } else {
                throw $e;
            }
        }
        return $this->capabilities;
    }

    /** @return bool */
    public function hasCapability($action)
    {
        return in_array($action, $this->capabilities(), true);
    }

    /**
     * Extract action names from a capabilities response.
     *
     * Live capability shape:
     *   {actions:[{action, method, path, scope, async}, ...], ok:true}
     * Bare-string entries and {data:[...]} / bare-array shapes are accepted
     * for forward compatibility.
     *
     * @param array $resp decoded capabilities JSON
     * @return string[] action names, e.g. ["users.disable", "users.enable"]
     */
    public static function normalizeCapabilities(array $resp)
    {
        $list = [];
        foreach (['actions', 'data'] as $k) {
            if (isset($resp[$k]) && is_array($resp[$k])) {
                $list = $resp[$k];
                break;
            }
        }
        if ($list === [] && $resp !== [] && array_values($resp) === $resp) {
            $list = $resp;
        }
        $names = [];
        foreach ($list as $entry) {
            if (is_array($entry)) {
                if (isset($entry['action']) && is_string($entry['action'])) {
                    $names[] = $entry['action'];
                }
            } elseif (is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }
        return $names;
    }

    // ------------------------------------------------------------------
    // Domain helpers — thin wrappers over the endpoints in
    // docs/API-CONTRACT.md §2/§3. PROPOSED endpoints degrade with a clear
    // JabaliApiException('unsupported') when the panel 404s.
    // ------------------------------------------------------------------

    /** Connection test: GET /status (read:status). @return array */
    public function status()
    {
        return $this->get(self::API_PREFIX . '/status');
    }

    /** @return array[] rows {id, email, username, package_id, is_admin} */
    public function listUsers()
    {
        $resp = $this->get(self::API_PREFIX . '/users');
        return isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
    }

    /**
     * Resolve one user row by username or email. Username matches win
     * (usernames are the panel's unique identity); an email match is only
     * returned when it is UNambiguous — the panel allows several accounts
     * to share one email (one billing client, many services), so a
     * multi-hit email lookup returns null rather than guessing.
     *
     * Persist the user id at create time and prefer findUserById().
     *
     * @return array|null
     */
    public function findUser($usernameOrEmail)
    {
        $needle = strtolower(trim((string)$usernameOrEmail));
        if ($needle === '') {
            return null;
        }
        $emailMatches = [];
        foreach ($this->listUsers() as $row) {
            if (strtolower((string)($row['username'] ?? '')) === $needle) {
                return $row; // unique — safe to return immediately
            }
            if (strtolower((string)($row['email'] ?? '')) === $needle) {
                $emailMatches[] = $row;
            }
        }
        return count($emailMatches) === 1 ? $emailMatches[0] : null;
    }

    /** @return array|null */
    public function findUserById($userId)
    {
        foreach ($this->listUsers() as $row) {
            if ((string)($row['id'] ?? '') === (string)$userId) {
                return $row;
            }
        }
        return null;
    }

    /** PROPOSED endpoint. @return array write envelope incl. user_id */
    public function createUser($email, $password, $username = null, $packageId = null, $domain = null)
    {
        $body = ['email' => $email, 'password' => $password];
        if ($username !== null && $username !== '') {
            $body['username'] = $username;
        }
        if ($packageId !== null && $packageId !== '') {
            $body['package_id'] = $packageId;
        }
        if ($domain !== null && $domain !== '') {
            $body['domain'] = $domain;
        }
        return $this->post(self::API_PREFIX . '/users', $body, false);
    }

    /** EXISTS (write:users). Idempotent by target state. @return array */
    public function disableUser($userId)
    {
        return $this->post(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/disable', null, true);
    }

    /** EXISTS (write:users). Idempotent by target state. @return array */
    public function enableUser($userId)
    {
        return $this->post(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/enable', null, true);
    }

    /** PROPOSED endpoint (delete:users). @return array */
    public function deleteUser($userId, $deleteDomains = true, $dryRun = false)
    {
        $body = ['confirm' => true, 'delete_domains' => (bool)$deleteDomains];
        if ($dryRun) {
            $body['dry_run'] = true;
        }
        return $this->delete(self::API_PREFIX . '/users/' . rawurlencode($userId), $body);
    }

    /** PROPOSED endpoint. @return array */
    public function setUserPassword($userId, $password)
    {
        return $this->post(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/password', ['password' => $password], false);
    }

    /** PROPOSED endpoint. @return array */
    public function setUserPackage($userId, $packageId)
    {
        return $this->put(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/package', ['package_id' => $packageId]);
    }

    /**
     * PROPOSED endpoint. Mints a one-time panel login URL for the end user.
     *
     * @param string|null $clientIp end-user browser IP for token IP-binding
     * @return array {ok, url, expires_in}
     */
    public function mintLoginToken($userId, $clientIp = null, $ttlSeconds = null)
    {
        $body = [];
        if ($clientIp !== null && $clientIp !== '') {
            $body['client_ip'] = $clientIp;
        }
        if ($ttlSeconds !== null) {
            $body['ttl_s'] = (int)$ttlSeconds;
        }
        $resp = $this->post(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/login-token', $body ?: null, false);

        // Defense-in-depth: the modules redirect the end-user's browser to
        // this URL, so refuse anything that is not https on the configured
        // panel host — even though the panel is operator-trusted.
        $url = isset($resp['url']) ? (string)$resp['url'] : '';
        if ($url !== '') {
            $parts = parse_url($url);
            $expectedHost = (string)parse_url($this->baseUrl, PHP_URL_HOST);
            if (($parts['scheme'] ?? '') !== 'https'
                || strcasecmp((string)($parts['host'] ?? ''), $expectedHost) !== 0) {
                throw new JabaliApiException(
                    'Panel returned a login URL outside the configured panel host — refusing to redirect',
                    0,
                    'error'
                );
            }
        }
        return $resp;
    }

    /** PROPOSED endpoint. @return array {disk_used_mb, disk_quota_mb, bw_used_mb, bw_quota_mb, ...} */
    public function getUserUsage($userId)
    {
        $resp = $this->get(self::API_PREFIX . '/users/' . rawurlencode($userId) . '/usage');
        return isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
    }

    /** PROPOSED endpoint. @return array[] package rows */
    public function listPackages()
    {
        $resp = $this->get(self::API_PREFIX . '/packages');
        return isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /** @return string path with encoded query appended */
    private function withQuery($path, array $query)
    {
        if ($query === []) {
            return $path;
        }
        return $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
    }

    /**
     * Perform one signed request with retry policy.
     *
     * Retries (fresh signature each attempt — the server replay-blocks
     * identical sigs) only when the failure is retryable AND the request is
     * idempotent: 429, 5xx, transport errors, and replay-401s. Never on other
     * 4xx. A replay-401 on an idempotent request means two byte-identical
     * signed requests landed within one second (ts has 1s resolution);
     * retrying once the unix second rolls over produces a fresh signature
     * the replay gate has never seen.
     *
     * @param string     $method        upper-case verb
     * @param string     $pathWithQuery path incl. query
     * @param array|null $body          JSON-encodable body or null
     * @param bool       $idempotent    whether retries are safe
     * @return array decoded JSON response
     * @throws JabaliApiException
     */
    private function request($method, $pathWithQuery, ?array $body, $idempotent)
    {
        $rawBody = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($rawBody === false) {
            throw new JabaliApiException('Failed to JSON-encode request body', 0, 'config');
        }

        $attempts = $idempotent ? $this->maxRetries + 1 : 1;
        $lastEx = null;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep((int)(250000 * (2 ** ($i - 1)))); // 250ms, 500ms
            }
            if ($lastEx !== null && $lastEx->getErrorCode() === 'replay_detected') {
                // ts has 1-second resolution: a retry signed in the SAME
                // second reproduces the identical signature and replays
                // again. Wait out the second so the next attempt's
                // signature is one the replay gate has never seen.
                $sec = time();
                while (time() === $sec) {
                    usleep(20000);
                }
            }
            try {
                return $this->requestOnce($method, $pathWithQuery, $rawBody, $idempotent);
            } catch (JabaliApiException $e) {
                $lastEx = $e;
                if (!$e->isRetryable()) {
                    throw $e;
                }
                $this->log(sprintf(
                    'jabali-api: retryable failure (%s %s attempt %d/%d): %s',
                    $method,
                    $pathWithQuery,
                    $i + 1,
                    $attempts,
                    $e->getMessage()
                ));
            }
        }
        throw $lastEx;
    }

    /**
     * @param string $rawBody exact bytes to sign AND send — never
     *                        re-serialized between the two.
     * @param bool   $idempotent whether a retry is safe (passed to mapError
     *                           so a replay-401 is only marked retryable for
     *                           idempotent requests).
     * @return array
     * @throws JabaliApiException
     */
    private function requestOnce($method, $pathWithQuery, $rawBody, $idempotent)
    {
        $ts = time();
        $sig = self::sign($this->tokenSecret, $method, $pathWithQuery, $ts, $rawBody);
        $auth = sprintf('Jabali-HMAC kid=%s, ts=%d, sig=%s', $this->tokenId, $ts, $sig);

        $headers = [
            'Authorization: ' . $auth,
            'Accept: application/json',
            'User-Agent: jabali-integration-php/' . self::VERSION,
        ];
        if ($rawBody !== '') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->baseUrl . $pathWithQuery);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($rawBody !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }
        if ($this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new JabaliApiException(
                'Cannot reach Jabali panel at ' . $this->baseUrl . ': ' . $error,
                0,
                'network',
                true
            );
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw $this->mapError($status, $decoded, $respHeaders, $ts, $idempotent);
    }

    /** @return JabaliApiException */
    private function mapError($status, array $body, array $headers, $sentTs, $idempotent = false)
    {
        // Write envelope: {ok:false, error, message}; read/middleware: {error}.
        $code = isset($body['error']) ? (string)$body['error'] : '';
        $message = isset($body['message']) && $body['message'] !== ''
            ? (string)$body['message']
            : ($code !== '' ? $code : 'HTTP ' . $status);

        $retryable = $status === 429 || $status >= 500;

        if ($status === 401) {
            // Distinguish clock skew from bad credentials: compare the ts we
            // signed with against the server's Date header.
            $serverDate = isset($headers['date']) ? strtotime($headers['date']) : false;
            if ($serverDate !== false && abs($serverDate - $sentTs) > self::SKEW_REPORT_THRESHOLD) {
                return new JabaliApiException(
                    sprintf(
                        'Jabali panel rejected the request signature: clock skew of %ds detected between this server and the panel. Sync both clocks with NTP.',
                        abs($serverDate - $sentTs)
                    ),
                    $status,
                    'clock_skew',
                    false
                );
            }
            // The panel emits {"error":"replay detected"} when the signature
            // was valid but already seen (Redis SETNX gate). On an idempotent
            // request that is a self-collision — two byte-identical signed
            // requests within one second — so it is safe to retry with a
            // fresh ts (request() waits out the second first). On a
            // non-idempotent write it stays non-retryable.
            if ($code === 'replay detected' && $idempotent) {
                return new JabaliApiException(
                    'Jabali panel replay gate rejected an identical signature (two identical requests within one second); retrying with a fresh signature.',
                    $status,
                    'replay_detected',
                    true
                );
            }
            return new JabaliApiException(
                'Jabali panel rejected the automation token (401). Check token ID + secret, and that the token is not revoked or expired.',
                $status,
                $code !== '' ? $code : 'unauthorized',
                false
            );
        }
        if ($status === 403) {
            return new JabaliApiException(
                'Automation token lacks the required scope (403' . ($code !== '' ? ': ' . $code : '') . '). Re-mint the token with the scopes listed in the module documentation.',
                $status,
                $code !== '' ? $code : 'scope_denied',
                false
            );
        }
        if ($status === 404) {
            return new JabaliApiException(
                'The Jabali panel does not provide this endpoint yet (404). Update Jabali Panel to a version that supports it. (' . $message . ')',
                $status,
                $code !== '' ? $code : 'unsupported',
                false
            );
        }
        if ($status === 429) {
            return new JabaliApiException(
                'Jabali automation API rate limit hit (429); the request will be retried.',
                $status,
                'rate_limited',
                true
            );
        }

        return new JabaliApiException(
            'Jabali panel error (HTTP ' . $status . '): ' . $message,
            $status,
            $code !== '' ? $code : ($retryable ? 'internal' : 'error'),
            $retryable
        );
    }

    private function log($message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
