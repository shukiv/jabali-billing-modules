<?php
/**
 * Jabali Panel provisioning module for WHMCS.
 *
 * Drop-in location on the WHMCS host: modules/servers/jabali/
 *
 * Server configuration mapping (Setup → Products/Services → Servers):
 *   Hostname          → Jabali panel hostname (panel.example.com)
 *   Username          → Automation token ID (the HMAC "kid")
 *   Password          → Automation token secret (shown once at mint time)
 *   Secure            → ignored; the module always uses HTTPS
 *   Port              → panel port (default 8443)
 *
 * Token scopes required (mint at /jabali-admin/automation):
 *   read:status, read:users, write:users            — baseline
 *   delete:users                                    — terminate support
 *
 * The module feature-detects panel capabilities via
 * GET /api/v1/automation/capabilities and returns a clear error for
 * operations the connected panel does not support yet
 * (see docs/API-CONTRACT.md in the jabali-integration repository).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/JabaliApiClient.php';

use WHMCS\Database\Capsule;

/**
 * Module metadata.
 *
 * @return array
 */
function jabali_MetaData()
{
    return [
        'DisplayName' => 'Jabali Panel',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8443',
        'DefaultSSLPort' => '8443',
        'ServiceSingleSignOnLabel' => 'Log in to Jabali Panel',
        'AdminSingleSignOnLabel' => 'Log in to Jabali Panel',
    ];
}

/**
 * Product configuration options.
 *
 * @return array
 */
function jabali_ConfigOptions()
{
    return [
        'Package' => [
            'Type' => 'dropdown',
            'loader' => 'jabali_PackageLoader',
            'SimpleMode' => true,
            'Description' => 'Jabali hosting package assigned at account creation',
        ],
        'Package ID (override)' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => 'Optional: explicit Jabali package ULID; takes precedence over the dropdown (use when the panel does not expose the package list yet)',
        ],
    ];
}

/**
 * Dropdown loader for the Package config option. Called by WHMCS with
 * server context when an admin edits the product's Module Settings tab.
 *
 * @param array $params
 * @return array key => label
 * @throws Exception when the panel is unreachable (WHMCS displays it)
 */
function jabali_PackageLoader(array $params)
{
    $client = jabali_getClient($params);
    if (!$client->hasCapability('packages.read')) {
        return ['' => '-- panel has no package-list endpoint; use the Package ID override field --'];
    }
    $out = [];
    foreach ($client->listPackages() as $pkg) {
        $id = (string)($pkg['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[$id] = (string)($pkg['name'] ?? $id);
    }
    return $out !== [] ? $out : ['' => '-- no packages defined on the panel --'];
}

/**
 * Provision a new account.
 *
 * @param array $params
 * @return string 'success' or error message
 */
function jabali_CreateAccount(array $params)
{
    try {
        $client = jabali_getClient($params);
        jabali_requireCapability($client, 'users.create', 'account creation');

        $email = $params['clientsdetails']['email'] ?? '';
        $username = jabali_serviceUsername($params);
        $password = $params['password'] ?? '';
        if ($password === '') {
            return 'WHMCS did not supply a service password';
        }
        if (strlen($password) < 10) {
            // Panel enforces min-10 (POST /users binding). Fail actionably
            // rather than bouncing off a 422.
            return 'Jabali requires passwords of at least 10 characters — raise the generated password length in WHMCS security settings';
        }

        $packageId = jabali_packageId($params);
        $domain = $params['domain'] ?? '';

        $resp = $client->createUser($email, $password, $username, $packageId, $domain);
        $userId = (string)($resp['user_id'] ?? '');
        if ($userId === '') {
            return 'Panel did not return a user_id — check the panel version against docs/API-CONTRACT.md';
        }

        jabali_storeUserId($params, $userId);
        jabali_logCall(__FUNCTION__, $params, ['request' => 'createUser'], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * @param array $params
 * @return string
 */
function jabali_SuspendAccount(array $params)
{
    try {
        $client = jabali_getClient($params);
        $resp = $client->disableUser(jabali_userId($params, $client));
        jabali_logCall(__FUNCTION__, $params, [], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * @param array $params
 * @return string
 */
function jabali_UnsuspendAccount(array $params)
{
    try {
        $client = jabali_getClient($params);
        $resp = $client->enableUser(jabali_userId($params, $client));
        jabali_logCall(__FUNCTION__, $params, [], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * @param array $params
 * @return string
 */
function jabali_TerminateAccount(array $params)
{
    try {
        $client = jabali_getClient($params);
        jabali_requireCapability($client, 'users.delete', 'account termination');
        $resp = $client->deleteUser(jabali_userId($params, $client), true, false);
        jabali_logCall(__FUNCTION__, $params, [], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * @param array $params
 * @return string
 */
function jabali_ChangePassword(array $params)
{
    try {
        $client = jabali_getClient($params);
        jabali_requireCapability($client, 'users.password', 'password changes');
        $password = $params['password'] ?? '';
        if (strlen($password) < 10) {
            return 'Jabali requires passwords of at least 10 characters';
        }
        $resp = $client->setUserPassword(jabali_userId($params, $client), $password);
        jabali_logCall(__FUNCTION__, $params, [], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Upgrade/downgrade: reassign the Jabali package.
 *
 * @param array $params
 * @return string
 */
function jabali_ChangePackage(array $params)
{
    try {
        $client = jabali_getClient($params);
        jabali_requireCapability($client, 'users.package', 'package changes');
        $packageId = jabali_packageId($params);
        if ($packageId === null) {
            return 'No Jabali package configured on the target product (Module Settings tab)';
        }
        $resp = $client->setUserPackage(jabali_userId($params, $client), $packageId);
        jabali_logCall(__FUNCTION__, $params, [], $resp);
        return 'success';
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Admin server "Test Connection".
 *
 * @param array $params
 * @return array
 */
function jabali_TestConnection(array $params)
{
    try {
        $client = jabali_getClient($params);
        $client->status();
        $caps = $client->capabilities();
        $note = $caps === []
            ? 'Connected (read API). No write capabilities advertised — older panel or token lacks write scopes.'
            : 'Connected. Capabilities: ' . implode(', ', $caps);
        jabali_logCall(__FUNCTION__, $params, [], $note);
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Client-area single sign-on into the Jabali user panel.
 *
 * @param array $params
 * @return array
 */
function jabali_ServiceSingleSignOn(array $params)
{
    try {
        $client = jabali_getClient($params);
        jabali_requireCapability($client, 'users.login_token', 'panel single sign-on');
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $resp = $client->mintLoginToken(jabali_userId($params, $client), $clientIp);
        $url = (string)($resp['url'] ?? '');
        if ($url === '') {
            return ['success' => false, 'errorMsg' => 'Panel did not return a login URL'];
        }
        jabali_logCall(__FUNCTION__, $params, [], ['url' => '(minted)', 'expires_in' => $resp['expires_in'] ?? null]);
        return ['success' => true, 'redirectTo' => $url];
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/**
 * Admin-area single sign-on (same mechanism; lands on the user's panel).
 *
 * @param array $params
 * @return array
 */
function jabali_AdminSingleSignOn(array $params)
{
    return jabali_ServiceSingleSignOn($params);
}

/**
 * Admin Services tab summary fields.
 *
 * @param array $params
 * @return array
 */
function jabali_AdminServicesTabFields(array $params)
{
    $fields = [];
    try {
        $client = jabali_getClient($params);
        $userId = jabali_userId($params, $client);
        $fields['Jabali User ID'] = htmlspecialchars($userId, ENT_QUOTES);

        $row = $client->findUserById($userId);
        if ($row !== null) {
            $fields['Panel Email'] = htmlspecialchars((string)($row['email'] ?? ''), ENT_QUOTES);
            $fields['Panel Package ID'] = htmlspecialchars((string)($row['package_id'] ?? ''), ENT_QUOTES);
        }

        if ($client->hasCapability('usage.read')) {
            $usage = $client->getUserUsage($userId);
            $fields['Disk (MB used / quota)'] = (int)($usage['disk_used_mb'] ?? 0) . ' / ' . (int)($usage['disk_quota_mb'] ?? 0);
            $fields['Bandwidth (MB used / quota)'] = (int)($usage['bw_used_mb'] ?? 0) . ' / ' . (int)($usage['bw_quota_mb'] ?? 0);
        }
    } catch (Exception $e) {
        $fields['Jabali'] = 'Lookup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    }
    return $fields;
}

/**
 * Daily per-server usage sync (WHMCS cron).
 *
 * @param array $params
 * @return void
 */
function jabali_UsageUpdate(array $params)
{
    try {
        $client = jabali_getClient($params);
        if (!$client->hasCapability('usage.read')) {
            jabali_logCall(__FUNCTION__, $params, [], 'Skipped: panel does not advertise usage.read');
            return;
        }

        $resp = $client->get(JabaliApiClient::API_PREFIX . '/usage');
        $rows = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
        $byUsername = [];
        foreach ($rows as $row) {
            if (!empty($row['username'])) {
                $byUsername[strtolower((string)$row['username'])] = $row;
            }
        }

        $services = Capsule::table('tblhosting')
            ->where('server', $params['serverid'])
            ->get(['id', 'username']);
        foreach ($services as $svc) {
            $key = strtolower((string)$svc->username);
            if (!isset($byUsername[$key])) {
                continue;
            }
            $u = $byUsername[$key];
            Capsule::table('tblhosting')->where('id', $svc->id)->update([
                'diskusage' => (int)($u['disk_used_mb'] ?? 0),
                'disklimit' => (int)($u['disk_quota_mb'] ?? 0),
                'bwusage' => (int)($u['bw_used_mb'] ?? 0),
                'bwlimit' => (int)($u['bw_quota_mb'] ?? 0),
                'lastupdate' => Capsule::raw('now()'),
            ]);
        }
        jabali_logCall(__FUNCTION__, $params, [], 'Updated usage for ' . count($rows) . ' panel rows');
    } catch (Exception $e) {
        jabali_logCall(__FUNCTION__, $params, [], $e->getMessage());
    }
}

/**
 * Client area output.
 *
 * @param array $params
 * @return array
 */
function jabali_ClientArea(array $params)
{
    $panelUrl = 'https://' . ($params['serverhostname'] ?: $params['serverip']) . ':' . jabali_port($params) . '/jabali-panel';
    return [
        'tabOverviewReplacementTemplate' => 'templates/overview.tpl',
        'templateVariables' => [
            'panelUrl' => $panelUrl,
            'username' => jabali_serviceUsername($params),
        ],
    ];
}

// ---------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------

/**
 * Build the shared API client from WHMCS server params.
 *
 * @param array $params
 * @return JabaliApiClient
 */
function jabali_getClient(array $params)
{
    $host = $params['serverhostname'] ?: ($params['serverip'] ?? '');
    return new JabaliApiClient(
        $host . ':' . jabali_port($params),
        $params['serverusername'] ?? '',
        $params['serverpassword'] ?? '',
        [
            'logger' => static function ($msg) {
                logActivity($msg);
            },
        ]
    );
}

/**
 * @param array $params
 * @return int
 */
function jabali_port(array $params)
{
    $port = isset($params['serverport']) ? (int)$params['serverport'] : 0;
    return $port > 0 ? $port : 8443;
}

/**
 * @param JabaliApiClient $client
 * @param string          $capability
 * @param string          $label
 * @return void
 * @throws JabaliApiException
 */
function jabali_requireCapability(JabaliApiClient $client, $capability, $label)
{
    if (!$client->hasCapability($capability)) {
        throw new JabaliApiException(
            'The connected Jabali Panel does not support ' . $label . ' via the automation API yet'
            . ' (missing capability "' . $capability . '").'
            . ' Upgrade Jabali Panel and/or re-mint the automation token with the required scopes.',
            0,
            'unsupported'
        );
    }
}

/**
 * The service username WHMCS generated (or the domain-derived fallback).
 *
 * @param array $params
 * @return string
 */
function jabali_serviceUsername(array $params)
{
    $username = trim((string)($params['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }
    // Same fallback shape WHMCS itself uses: first 8 alnum chars of domain.
    $base = preg_replace('/[^a-z0-9]/', '', strtolower((string)($params['domain'] ?? '')));
    return substr($base !== '' ? $base : 'jabali', 0, 8);
}

/**
 * Resolve the Jabali user ULID for this service: stored custom field first,
 * then panel lookup by username/email.
 *
 * @param array           $params
 * @param JabaliApiClient $client
 * @return string
 * @throws JabaliApiException when no mapping can be resolved
 */
function jabali_userId(array $params, JabaliApiClient $client)
{
    $stored = jabali_readStoredUserId($params);
    if ($stored !== '') {
        return $stored;
    }
    $row = $client->findUser(jabali_serviceUsername($params));
    if ($row === null && !empty($params['clientsdetails']['email'])) {
        $row = $client->findUser($params['clientsdetails']['email']);
    }
    if ($row === null || empty($row['id'])) {
        throw new JabaliApiException(
            'Cannot map this service to a Jabali user: no stored user ID and no panel user matches username "'
            . jabali_serviceUsername($params) . '". Set the "jabali_user_id" custom field manually.',
            0,
            'not_found'
        );
    }
    jabali_storeUserId($params, (string)$row['id']);
    return (string)$row['id'];
}

/**
 * Custom-field storage for the Jabali user ULID. Uses a product custom
 * field named "jabali_user_id" when it exists (recommended; see README),
 * creating the value row on demand.
 *
 * @param array $params
 * @return string '' when absent
 */
function jabali_readStoredUserId(array $params)
{
    if (!empty($params['customfields']) && is_array($params['customfields'])) {
        foreach ($params['customfields'] as $name => $value) {
            if (strcasecmp(trim((string)$name), 'jabali_user_id') === 0 && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
    }
    return '';
}

/**
 * Persist the ULID into the "jabali_user_id" custom field if the product
 * defines one. Silent no-op otherwise (resolution falls back to lookup).
 *
 * @param array  $params
 * @param string $userId
 * @return void
 */
function jabali_storeUserId(array $params, $userId)
{
    try {
        $fieldId = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $params['pid'] ?? 0)
            ->whereRaw("SUBSTRING_INDEX(fieldname, '|', 1) = ?", ['jabali_user_id'])
            ->value('id');
        if ($fieldId === null) {
            return;
        }
        $existing = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $params['serviceid'])
            ->exists();
        if ($existing) {
            Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $params['serviceid'])
                ->update(['value' => $userId]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => $fieldId,
                'relid' => $params['serviceid'],
                'value' => $userId,
            ]);
        }
    } catch (Exception $e) {
        logActivity('jabali: could not persist jabali_user_id custom field: ' . $e->getMessage());
    }
}

/**
 * Product package resolution: explicit override field wins, then dropdown.
 *
 * @param array $params
 * @return string|null
 */
function jabali_packageId(array $params)
{
    $override = trim((string)($params['configoption2'] ?? ''));
    if ($override !== '') {
        return $override;
    }
    $dropdown = trim((string)($params['configoption1'] ?? ''));
    return $dropdown !== '' ? $dropdown : null;
}

/**
 * logModuleCall wrapper that scrubs the token secret.
 *
 * @param string $action
 * @param array  $params
 * @param mixed  $request
 * @param mixed  $response
 * @return void
 */
function jabali_logCall($action, array $params, $request, $response)
{
    if (!function_exists('logModuleCall')) {
        return;
    }
    logModuleCall(
        'jabali',
        $action,
        $request ?: ['serviceid' => $params['serviceid'] ?? null, 'domain' => $params['domain'] ?? null],
        $response,
        '',
        [
            $params['serverpassword'] ?? '',
            $params['password'] ?? '',
            $params['servicepassword'] ?? '',
        ]
    );
}
