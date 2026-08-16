<?php
/**
 * Jabali Panel hosting module for WiseCP.
 *
 * Drop-in location on the WiseCP host: coremio/modules/Servers/Jabali/
 *
 * Server definition mapping (WiseCP admin → Servers):
 *   Name / IP     → Jabali panel hostname
 *   Username      → Automation token ID (the HMAC "kid")
 *   Password      → Automation token secret
 *   Port          → panel port (default 8443, always HTTPS)
 *
 * Product module settings must carry the Jabali package ULID
 * (config option "package_id") — it is applied at create and on
 * upgrade/downgrade.
 *
 * Built against the official WiseCP sample-hosting-panel-module contract:
 * https://github.com/wisecp/sample-hosting-panel-module
 */

class Jabali_Module extends ServerModule
{
    /** @var JabaliApiClient|null */
    private $api;

    /** @var array */
    private $server_info = [];

    public function __construct($server = [], $options = [])
    {
        $this->_name = __CLASS__;
        $this->force_setup = false;
        parent::__construct($server, $options);
    }

    protected function define_server_info($server = [])
    {
        if (!class_exists('JabaliApiClient')) {
            include __DIR__ . DS . 'lib' . DS . 'JabaliApiClient.php';
        }

        $this->server_info = $server;

        $host = isset($server['name']) && $server['name'] !== '' ? $server['name'] : ($server['ip'] ?? '');
        $port = isset($server['port']) && (int)$server['port'] > 0 ? (int)$server['port'] : 8443;
        $secret = isset($server['password']) && $server['password'] !== ''
            ? $server['password']
            : ($server['access_hash'] ?? '');

        try {
            $this->api = new JabaliApiClient(
                $host . ':' . $port,
                $server['username'] ?? '',
                $secret
            );
        } catch (Exception $e) {
            $this->api = null;
            $this->error = $e->getMessage();
        }
    }

    public function testConnect()
    {
        if (!$this->api) {
            $this->error = $this->error ?: 'Jabali API client not configured';
            return false;
        }
        try {
            $this->api->status();
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    /**
     * Product-level module settings.
     */
    public function config_options($data = [])
    {
        return [
            'package_id' => [
                'wrap_width' => 100,
                'name' => 'Jabali Package ID',
                'description' => 'The 26-character package ULID from Jabali Panel (Admin → Packages). Applied at account creation and on upgrade/downgrade.',
                'type' => 'text',
                'value' => isset($data['package_id']) ? $data['package_id'] : '',
                'placeholder' => '01J0000000000000000000000',
            ],
        ];
    }

    /**
     * Packages offered by this Jabali server, for the product page's
     * "Service Plan" selector.
     *
     * Every bundled WiseCP hosting module (cPanel, DirectAdmin, Plesk, …)
     * exposes this method; WiseCP calls it to populate the dropdown and
     * stores the chosen key in the product's module_data as "plan".
     * Without it WiseCP renders no selector at all, leaving operators with
     * no way to map a product to a package.
     *
     * Keyed by package ULID so the stored value is what the API expects.
     */
    public function getPlans()
    {
        if (!$this->requireApi()) {
            return false;
        }

        try {
            $packages = $this->api->listPackages();
        } catch (Exception $e) {
            $this->error = 'Could not read packages from Jabali Panel: ' . $e->getMessage();
            return false;
        }

        $plans = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $id = trim((string)($package['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $plans[$id] = $package;
        }

        // false (not []) tells WiseCP the list is unavailable rather than empty.
        return $plans ?: false;
    }

    public function generate_username($domain = '', $half_mixed = false)
    {
        // Keep in lockstep with the Blesta module's generateUsername()
        $exp = explode('.', (string)$domain);
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $exp[0] ?? ''));
        $base = ltrim($base, '0123456789');
        if (strlen($base) < 3) {
            $base .= substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 3);
        }
        return substr($base, 0, 16);
    }

    /**
     * Provision the account on the panel.
     *
     * @param string $domain
     * @param array $order_options
     * @return array|false creation info persisted by WiseCP
     */
    public function create($domain = '', array $order_options = [])
    {
        if (!$this->requireApi() || !$this->requireCapability('users.create', 'account creation')) {
            return false;
        }

        $options = $order_options ?: ($this->order['options'] ?? []);

        $username = !empty($options['username']) ? $options['username'] : $this->generate_username($domain);
        $username = str_replace('-', '', strtolower($username));
        $password = !empty($options['password']) ? $options['password'] : $this->generatePassword();
        // WiseCP's ServerModule base populates $this->user from the order owner
        // (verified against the cPanel/CyberPanel/KeyHelp modules). An explicit
        // order-option "email" may override it (e.g. admin-created services).
        $email = !empty($this->user['email'])
            ? (string)$this->user['email']
            : (string)($options['email'] ?? '');
        $package_id = $this->resolvePackageId($options);

        try {
            $resp = $this->api->createUser($email, $password, $username, $package_id, $domain);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }

        $user_id = isset($resp['user_id']) ? (string)$resp['user_id'] : '';
        if ($user_id === '') {
            $this->error = 'The panel accepted the request but returned no user ID. Verify the panel version.';
            return false;
        }

        $host = $this->server_info['ip'] ?? '';
        if (!empty($this->server_info['name'])) {
            $host = $this->server_info['name'];
        }

        return [
            'username' => $username,
            'password' => $password,
            'user_id' => $user_id,
            'domain' => $domain,
            'ftp_info' => [
                'ip' => $this->server_info['ip'] ?? '',
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'port' => 22, // Jabali provides SFTP, not plain FTP
            ],
        ];
    }

    public function suspend()
    {
        if (!$this->requireApi()) {
            return false;
        }
        try {
            $this->api->disableUser($this->userId());
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    public function unsuspend()
    {
        if (!$this->requireApi()) {
            return false;
        }
        try {
            $this->api->enableUser($this->userId());
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    public function terminate()
    {
        if (!$this->requireApi() || !$this->requireCapability('users.delete', 'account termination')) {
            return false;
        }
        try {
            $this->api->deleteUser($this->userId(), true, false);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    public function change_password($password = '')
    {
        if (!$this->requireApi() || !$this->requireCapability('users.password', 'password changes')) {
            return false;
        }
        if (strlen((string)$password) < 10) {
            $this->error = 'Jabali requires passwords of at least 10 characters.';
            return false;
        }
        try {
            $this->api->setUserPassword($this->userId(), $password);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    public function apply_updowngrade($orderopt = [], $product = [])
    {
        if (!$this->requireApi() || !$this->requireCapability('users.package', 'package changes')) {
            return false;
        }

        $package_id = '';
        foreach (['plan', 'package_id'] as $key) {
            if (!empty($product['module_data'][$key])) {
                $package_id = trim((string)$product['module_data'][$key]);
                break;
            }
        }
        if ($package_id === '') {
            $this->error = 'The target product has no Jabali Package ID configured (product module settings).';
            return false;
        }

        try {
            $this->api->setUserPackage($this->userId(), $package_id);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    public function getDisk()
    {
        $usage = $this->fetchUsage();
        if ($usage === false) {
            return false;
        }

        $limit = (int)($usage['disk_quota_mb'] ?? 0);
        $used = (int)($usage['disk_used_mb'] ?? 0);

        $percent = ($limit > 0 && $used > 0) ? min(100, round($used / $limit * 100)) : 0;

        return [
            'limit' => $limit,
            'used' => $used,
            'used-percent' => $percent,
            'format-limit' => $limit ? $limit . ' MB' : '∞',
            'format-used' => $used ? $used . ' MB' : '0 MB',
        ];
    }

    public function getBandwidth()
    {
        $usage = $this->fetchUsage();
        if ($usage === false) {
            return false;
        }

        // WiseCP displays bandwidth in GB (per the official sample)
        $limit = round((int)($usage['bw_quota_mb'] ?? 0) / 1024, 2);
        $used = round((int)($usage['bw_used_mb'] ?? 0) / 1024, 2);

        $percent = ($limit > 0 && $used > 0) ? min(100, round($used / $limit * 100)) : 0;

        return [
            'limit' => $limit,
            'used' => $used,
            'used-percent' => $percent,
            'format-limit' => $limit ? $limit . ' GB' : '∞',
            'format-used' => $used ? $used . ' GB' : '0 GB',
        ];
    }

    public function clientArea()
    {
        $content = $this->clientArea_buttons_output();
        $_page = $this->page;
        if (!$_page) {
            $_page = 'home';
        }

        $content .= $this->get_page('clientArea-' . $_page, [
            'username' => $this->creationInfo('username'),
            'domain' => $this->creationInfo('domain'),
            'panel_url' => $this->panelUrl(),
        ]);
        return $content;
    }

    public function clientArea_buttons()
    {
        $buttons = [];

        if ($this->page && $this->page != 'home') {
            $buttons['home'] = [
                'text' => $this->lang['turn-back'] ?? 'Back',
                'type' => 'page-loader',
            ];
            return $buttons;
        }

        $sso = false;
        if ($this->api) {
            try {
                $sso = $this->api->hasCapability('users.login_token');
            } catch (Exception $e) {
                // Panel unreachable — fall through to the plain link
            }
        }

        if ($sso) {
            $buttons['SingleSignOn'] = [
                'text' => 'Log in to Jabali Panel',
                'type' => 'function',
                'target_blank' => true,
            ];
        } else {
            $buttons['panel-link'] = [
                'text' => 'Open Jabali Panel',
                'type' => 'link',
                'url' => $this->panelUrl(),
                'target_blank' => true,
            ];
        }

        return $buttons;
    }

    public function use_clientArea_SingleSignOn()
    {
        if (!$this->requireApi() || !$this->requireCapability('users.login_token', 'panel single sign-on')) {
            echo 'An error has occurred, unable to access: ' . htmlspecialchars((string)$this->error, ENT_QUOTES, 'UTF-8');
            return false;
        }
        try {
            $resp = $this->api->mintLoginToken($this->userId(), $_SERVER['REMOTE_ADDR'] ?? null);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            // Keep panel-internal detail out of the customer-facing page
            echo 'An error has occurred, unable to access the panel right now. Please try again later or contact support.';
            return false;
        }

        $url = isset($resp['url']) ? (string)$resp['url'] : '';
        if ($url === '') {
            echo 'The panel did not return a login URL.';
            return false;
        }

        Utility::redirect($url);
        echo 'Redirecting...';
        return true;
    }

    public function adminArea_buttons()
    {
        $buttons = [];
        $buttons['SingleSignOn'] = [
            'text' => 'Log in to Jabali Panel',
            'type' => 'function',
            'target_blank' => true,
        ];
        return $buttons;
    }

    public function use_adminArea_SingleSignOn()
    {
        return $this->use_clientArea_SingleSignOn();
    }

    public function adminArea_service_fields()
    {
        $c_info = $this->options['creation_info'] ?? [];

        return [
            'username' => [
                'wrap_width' => 100,
                'name' => 'Panel Username',
                'type' => 'text',
                'value' => $c_info['username'] ?? '',
            ],
            'user_id' => [
                'wrap_width' => 100,
                'name' => 'Jabali User ID',
                'description' => 'The 26-character ULID of the panel user this service maps to.',
                'type' => 'text',
                'value' => $c_info['user_id'] ?? '',
            ],
        ];
    }

    public function save_adminArea_service_fields($data = [])
    {
        $n_c_info = $data['new']['creation_info'] ?? [];

        if (($n_c_info['user_id'] ?? '') !== '' && !preg_match('/^[0-9A-Z]{26}$/i', $n_c_info['user_id'])) {
            $this->error = 'The Jabali User ID must be a 26-character ULID.';
            return false;
        }

        return [
            'creation_info' => $n_c_info,
            'config' => $data['new']['config'] ?? [],
            'ftp_info' => $data['new']['ftp_info'] ?? [],
            'options' => $data['new']['options'] ?? [],
        ];
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /** @return bool */
    private function requireApi()
    {
        if (!$this->api) {
            $this->error = $this->error ?: 'Jabali API client not configured — check the server definition.';
            return false;
        }
        return true;
    }

    /** @return bool */
    private function requireCapability($capability, $label)
    {
        try {
            if (!$this->api->hasCapability($capability)) {
                $this->error = 'The connected Jabali Panel does not support ' . $label
                    . ' via the automation API yet (missing capability "' . $capability . '").'
                    . ' Upgrade Jabali Panel and/or re-mint the automation token with the required scopes.';
                return false;
            }
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
        return true;
    }

    /**
     * Resolve the Jabali package ULID for this order. The product's module
     * settings (config_options "package_id" → module_data) are the canonical
     * source; order options may carry an override. Returns null when absent —
     * the panel then creates the user without a package assignment.
     *
     * @param array $options
     * @return string|null
     */
    private function resolvePackageId(array $options)
    {
        // "plan" is WiseCP's own key for the product's Service Plan selector
        // (see getPlans()); "package_id" is the legacy free-text field kept
        // working for installs configured before the selector existed.
        $candidates = [
            $options['plan'] ?? null,
            $options['package_id'] ?? null,
            $options['module_data']['plan'] ?? null,
            $options['module_data']['package_id'] ?? null,
            $this->order['options']['plan'] ?? null,
            $this->order['options']['package_id'] ?? null,
            $this->order['options']['module_data']['plan'] ?? null,
            $this->order['options']['module_data']['package_id'] ?? null,
            $this->product['module_data']['plan'] ?? null,
            $this->product['module_data']['package_id'] ?? null,
            $this->config['plan'] ?? null,
            $this->config['package_id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param string $key
     * @return string
     */
    private function creationInfo($key)
    {
        $sources = [
            $this->options['creation_info'] ?? null,
            $this->order['options']['creation_info'] ?? null,
        ];
        foreach ($sources as $info) {
            if (is_array($info) && isset($info[$key]) && $info[$key] !== '') {
                return (string)$info[$key];
            }
        }
        return '';
    }

    /**
     * Resolve the Jabali user ULID: creation_info first, then panel lookup.
     *
     * @return string
     * @throws Exception when no mapping resolves
     */
    private function userId()
    {
        $stored = $this->creationInfo('user_id');
        if ($stored !== '') {
            return $stored;
        }

        $username = $this->creationInfo('username');
        $row = $username !== '' ? $this->api->findUser($username) : null;
        if ($row === null) {
            $email = (string)($this->user['email'] ?? '');
            $row = $email !== '' ? $this->api->findUser($email) : null;
        }
        if ($row === null || empty($row['id'])) {
            throw new Exception(
                'Cannot map this service to a Jabali user: no stored user ID and no panel user matches. '
                . 'Set the "Jabali User ID" service field in the admin area.'
            );
        }
        return (string)$row['id'];
    }

    /** @return array|false */
    private function fetchUsage()
    {
        if (!$this->requireApi()) {
            return false;
        }
        try {
            if (!$this->api->hasCapability('usage.read')) {
                // Panel predates the usage endpoint — report empty rather than error
                return ['disk_quota_mb' => 0, 'disk_used_mb' => 0, 'bw_quota_mb' => 0, 'bw_used_mb' => 0];
            }
            return $this->api->getUserUsage($this->userId());
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->saveLog(__FUNCTION__, $e);
            return false;
        }
    }

    /** @return string */
    private function panelUrl()
    {
        $host = $this->server_info['name'] ?? ($this->server_info['ip'] ?? '');
        $port = isset($this->server_info['port']) && (int)$this->server_info['port'] > 0
            ? (int)$this->server_info['port']
            : 8443;
        return 'https://' . $host . ':' . $port . '/jabali-panel';
    }

    /** @return string */
    private function generatePassword()
    {
        $pools = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            '!@#$%^&*',
        ];
        $password = '';
        foreach ($pools as $pool) {
            $password .= $pool[random_int(0, strlen($pool) - 1)];
        }
        $all = implode('', $pools);
        for ($i = strlen($password); $i < 16; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        return str_shuffle($password);
    }

    /**
     * @param string $function
     * @param Exception $e
     */
    private function saveLog($function, Exception $e)
    {
        // No stack trace: frame arguments can capture password prefixes when
        // zend.exception_ignore_args is off. Class + message is enough.
        self::save_log(
            'Servers',
            $this->_name,
            $function,
            ['order_id' => $this->order['id'] ?? null],
            $e->getMessage(),
            'Exception: ' . get_class($e)
        );
    }
}
