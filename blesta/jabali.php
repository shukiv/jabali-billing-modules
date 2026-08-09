<?php
/**
 * Jabali Panel module for Blesta.
 *
 * Drop-in location on the Blesta host: components/modules/jabali/
 *
 * Talks to the Jabali Automation API (HMAC-signed) via
 * the shared JabaliApiClient (vendored under apis/lib/, synced from the
 * jabali-integration repository's shared/src/).
 *
 * Module row (server) meta:
 *   server_name, host_name, port, token_id, token_secret, account_limit, notes
 *
 * Service fields:
 *   jabali_domain, jabali_username, jabali_password (encrypted),
 *   jabali_email, jabali_user_id
 *
 * @package blesta.components.modules.jabali
 * @license MIT
 */
class Jabali extends Module
{
    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load configuration required by this module
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('jabali', null, dirname(__FILE__) . DS . 'language' . DS);

        require_once dirname(__FILE__) . DS . 'apis' . DS . 'lib' . DS . 'JabaliApiClient.php';
    }

    /**
     * Returns all tabs to display to a client when managing a service
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title
     */
    public function getClientTabs($package)
    {
        return [
            'tabClientActions' => Language::_('Jabali.tab_client_actions', true)
        ];
    }

    /**
     * Returns an array of available service delegation order methods.
     *
     * @return array An array of order methods in key/value pairs
     */
    public function getGroupOrderOptions()
    {
        return [
            'roundrobin' => Language::_('Jabali.order_options.roundrobin', true),
            'first' => Language::_('Jabali.order_options.first', true)
        ];
    }

    /**
     * Returns all fields used when adding/editing a package.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        // Resolve the module row to query the panel package list
        $module_row = null;
        if (isset($vars->module_group) && $vars->module_group == '') {
            if (isset($vars->module_row) && $vars->module_row > 0) {
                $module_row = $this->getModuleRow($vars->module_row);
            } else {
                $rows = $this->getModuleRows();
                if (isset($rows[0])) {
                    $module_row = $rows[0];
                }
                unset($rows);
            }
        } else {
            $rows = $this->getModuleRows($vars->module_group ?? null);
            if (isset($rows[0])) {
                $module_row = $rows[0];
            }
            unset($rows);
        }

        // Jabali package dropdown (from the panel when supported)
        $packages = ['' => Language::_('Jabali.package_fields.package_manual', true)];
        if ($module_row) {
            try {
                $client = $this->getApiClient($module_row);
                if ($client->hasCapability('packages.read')) {
                    foreach ($client->listPackages() as $pkg) {
                        if (!empty($pkg['id'])) {
                            $packages[(string)$pkg['id']] = (string)($pkg['name'] ?? $pkg['id']);
                        }
                    }
                }
            } catch (Exception $e) {
                // Panel unreachable or capability missing — manual entry still works
            }
        }

        $package = $fields->label(Language::_('Jabali.package_fields.package', true), 'jabali_package');
        $package->attach(
            $fields->fieldSelect(
                'meta[package_id]',
                $packages,
                ($vars->meta['package_id'] ?? null),
                ['id' => 'jabali_package']
            )
        );
        $fields->setField($package);

        // Manual package ULID override (used when the dropdown is empty)
        $package_override = $fields->label(
            Language::_('Jabali.package_fields.package_override', true),
            'jabali_package_override'
        );
        $package_override->attach(
            $fields->fieldText(
                'meta[package_id_override]',
                ($vars->meta['package_id_override'] ?? null),
                ['id' => 'jabali_package_override']
            )
        );
        $fields->setField($package_override);

        return $fields;
    }

    /**
     * Returns the rendered view of the manage module page
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data
     * @return string HTML content
     */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page
     *
     * @param array $vars An array of post data
     * @return string HTML content
     */
    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data
     * @return string HTML content
     */
    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = $module_row->meta;
        }

        $this->view->set('vars', (object)$vars);
        return $this->view->fetch();
    }

    /**
     * Adds the module row. Sets Input errors on failure.
     *
     * @param array $vars An array of module info to add
     * @return array Meta fields
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = ['server_name', 'host_name', 'port', 'token_id', 'token_secret',
            'account_limit', 'notes'];
        $encrypted_fields = ['token_id', 'token_secret'];

        if (empty($vars['port'])) {
            $vars['port'] = '8443';
        }

        $this->Input->setRules($this->getRowRules($vars));

        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }
            return $meta;
        }
    }

    /**
     * Edits the module row. Sets Input errors on failure.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array Meta fields
     */
    public function editModuleRow($module_row, array &$vars)
    {
        // Keep the stored secret when the operator leaves the field blank
        if (empty($vars['token_secret']) && isset($module_row->meta->token_secret)) {
            $vars['token_secret'] = $module_row->meta->token_secret;
        }
        return $this->addModuleRow($vars);
    }

    /**
     * Returns all fields to display to an admin attempting to add a service
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object
     */
    public function getAdminAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $domain = $fields->label(Language::_('Jabali.service_field.domain', true), 'jabali_domain');
        $domain->attach(
            $fields->fieldText(
                'jabali_domain',
                ($vars->jabali_domain ?? null),
                ['id' => 'jabali_domain']
            )
        );
        $fields->setField($domain);

        $username = $fields->label(Language::_('Jabali.service_field.username', true), 'jabali_username');
        $username->attach(
            $fields->fieldText(
                'jabali_username',
                ($vars->jabali_username ?? null),
                ['id' => 'jabali_username']
            )
        );
        $fields->setField($username);

        $password = $fields->label(Language::_('Jabali.service_field.password', true), 'jabali_password');
        $password->attach(
            $fields->fieldPassword(
                'jabali_password',
                [
                    'class' => 'jabali_password',
                    'id' => 'jabali_password',
                    'value' => ($vars->jabali_password ?? null)
                ]
            )
        );
        $fields->setField($password);

        $email = $fields->label(Language::_('Jabali.service_field.email', true), 'jabali_email');
        $email->attach(
            $fields->fieldText(
                'jabali_email',
                ($vars->jabali_email ?? null),
                ['id' => 'jabali_email']
            )
        );
        $fields->setField($email);

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $password = $fields->label(Language::_('Jabali.service_field.password', true), 'jabali_password');
        $password->attach(
            $fields->fieldPassword(
                'jabali_password',
                [
                    'class' => 'jabali_password',
                    'id' => 'jabali_password',
                    'value' => ($vars->jabali_password ?? null)
                ]
            )
        );
        $fields->setField($password);

        return $fields;
    }

    /**
     * Returns all fields to display to a client attempting to add a service
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object
     */
    public function getClientAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $domain = $fields->label(Language::_('Jabali.service_field.domain', true), 'jabali_domain');
        $domain->attach(
            $fields->fieldText(
                'jabali_domain',
                ($vars->jabali_domain ?? ($vars->domain ?? null)),
                ['id' => 'jabali_domain']
            )
        );
        $fields->setField($domain);

        return $fields;
    }

    /**
     * Validates input data when attempting to add a package. Sets Input errors
     * on failure.
     *
     * @param array $vars An array of key/value pairs used to add the package
     * @return array Meta fields
     */
    public function addPackage(array $vars = null)
    {
        $this->Input->setRules($this->getPackageRules($vars));

        $meta = [];
        if ($this->Input->validates($vars)) {
            // The override beats the dropdown (mirrors the WHMCS module)
            if (!empty($vars['meta']['package_id_override'])) {
                $vars['meta']['package_id'] = trim($vars['meta']['package_id_override']);
            }
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }
        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package. Sets Input errors
     * on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of key/value pairs used to edit the package
     * @return array Meta fields
     */
    public function editPackage($package, array $vars = null)
    {
        return $this->addPackage($vars);
    }

    /**
     * Adds the service on the Jabali panel. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @param string $status The status of the service being added
     * @return array Service fields
     */
    public function addService(
        $package,
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors([
                'module_row' => ['missing' => Language::_('Jabali.!error.module_row.missing', true)]
            ]);
            return;
        }

        $domain = strtolower(trim($vars['jabali_domain'] ?? ''));
        $username = strtolower(trim($vars['jabali_username'] ?? ''));
        if ($username === '') {
            $username = $this->generateUsername($domain);
        }
        $password = $vars['jabali_password'] ?? '';
        if ($password === '') {
            $password = $this->generatePassword();
        }
        $email = trim($vars['jabali_email'] ?? '');
        if ($email === '' && isset($vars['client_id'])) {
            Loader::loadModels($this, ['Clients']);
            $client = $this->Clients->get($vars['client_id']);
            $email = $client->email ?? '';
        }

        $vars['jabali_domain'] = $domain;
        $vars['jabali_username'] = $username;

        $this->validateService($package, $vars + ['jabali_password' => $password, 'jabali_email' => $email]);
        if ($this->Input->errors()) {
            return;
        }

        $user_id = '';

        // Only provision on the panel if 'use_module' is true
        if (isset($vars['use_module']) && $vars['use_module'] == 'true') {
            $client = $this->getApiClient($row);

            try {
                $this->requireCapability($client, 'users.create', 'Jabali.!error.capability.create');

                $masked = ['email' => $email, 'username' => $username, 'password' => '***',
                    'package_id' => $package->meta->package_id ?? '', 'domain' => $domain];
                $this->log($row->meta->host_name . '|users.create', serialize($masked), 'input', true);

                $resp = $client->createUser(
                    $email,
                    $password,
                    $username,
                    ($package->meta->package_id ?? '') !== '' ? $package->meta->package_id : null,
                    $domain
                );
                $user_id = (string)($resp['user_id'] ?? '');
                $this->log($row->meta->host_name . '|users.create', serialize($resp), 'output', $user_id !== '');

                if ($user_id === '') {
                    $this->Input->setErrors([
                        'api' => ['response' => Language::_('Jabali.!error.api.no_user_id', true)]
                    ]);
                    return;
                }
            } catch (Exception $e) {
                $this->log($row->meta->host_name . '|users.create', $e->getMessage(), 'output', false);
                $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
                return;
            }
        }

        // Return service fields
        return [
            [
                'key' => 'jabali_domain',
                'value' => $domain,
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_username',
                'value' => $username,
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_password',
                'value' => $password,
                'encrypted' => 1
            ],
            [
                'key' => 'jabali_email',
                'value' => $email,
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_user_id',
                'value' => $user_id,
                'encrypted' => 0
            ]
        ];
    }

    /**
     * Edits the service. A changed password is pushed to the panel.
     * Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @return array Service fields
     */
    public function editService(
        $package,
        $service,
        array $vars = [],
        $parent_package = null,
        $parent_service = null
    ) {
        $row = $this->getModuleRow();
        $service_fields = isset($service->fields) ? $this->serviceFieldsToObject($service->fields) : new stdClass();

        $new_password = $vars['jabali_password'] ?? '';
        $password_changed = $new_password !== '' && $new_password !== ($service_fields->jabali_password ?? '');

        $this->validateServiceEdit($service, $vars);
        if ($this->Input->errors()) {
            return;
        }

        if ($password_changed && isset($vars['use_module']) && $vars['use_module'] == 'true') {
            $client = $this->getApiClient($row);
            try {
                $this->requireCapability($client, 'users.password', 'Jabali.!error.capability.password');
                $user_id = $this->resolveUserId($client, $service_fields);

                $this->log($row->meta->host_name . '|users.password', serialize(['user_id' => $user_id, 'password' => '***']), 'input', true);
                $resp = $client->setUserPassword($user_id, $new_password);
                $this->log($row->meta->host_name . '|users.password', serialize($resp), 'output', true);
            } catch (Exception $e) {
                $this->log($row->meta->host_name . '|users.password', $e->getMessage(), 'output', false);
                $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
                return;
            }
        }

        // Return service fields
        return [
            [
                'key' => 'jabali_domain',
                'value' => $service_fields->jabali_domain ?? '',
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_username',
                'value' => $service_fields->jabali_username ?? '',
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_password',
                'value' => $password_changed ? $new_password : ($service_fields->jabali_password ?? ''),
                'encrypted' => 1
            ],
            [
                'key' => 'jabali_email',
                'value' => $service_fields->jabali_email ?? '',
                'encrypted' => 0
            ],
            [
                'key' => 'jabali_user_id',
                'value' => $service_fields->jabali_user_id ?? '',
                'encrypted' => 0
            ]
        ];
    }

    /**
     * Suspends the service (disables the Jabali user). Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @return mixed null
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {
            $client = $this->getApiClient($row);
            $service_fields = $this->serviceFieldsToObject($service->fields);
            try {
                $user_id = $this->resolveUserId($client, $service_fields);
                $this->log($row->meta->host_name . '|users.disable', serialize(['user_id' => $user_id]), 'input', true);
                $resp = $client->disableUser($user_id);
                $this->log($row->meta->host_name . '|users.disable', serialize($resp), 'output', true);
            } catch (Exception $e) {
                $this->log($row->meta->host_name . '|users.disable', $e->getMessage(), 'output', false);
                $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
            }
        }
        return null;
    }

    /**
     * Unsuspends the service (re-enables the Jabali user). Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @return mixed null
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {
            $client = $this->getApiClient($row);
            $service_fields = $this->serviceFieldsToObject($service->fields);
            try {
                $user_id = $this->resolveUserId($client, $service_fields);
                $this->log($row->meta->host_name . '|users.enable', serialize(['user_id' => $user_id]), 'input', true);
                $resp = $client->enableUser($user_id);
                $this->log($row->meta->host_name . '|users.enable', serialize($resp), 'output', true);
            } catch (Exception $e) {
                $this->log($row->meta->host_name . '|users.enable', $e->getMessage(), 'output', false);
                $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
            }
        }
        return null;
    }

    /**
     * Cancels the service (deletes the Jabali user). Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @return mixed null
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {
            $client = $this->getApiClient($row);
            $service_fields = $this->serviceFieldsToObject($service->fields);
            try {
                $this->requireCapability($client, 'users.delete', 'Jabali.!error.capability.delete');
                $user_id = $this->resolveUserId($client, $service_fields);
                $this->log($row->meta->host_name . '|users.delete', serialize(['user_id' => $user_id]), 'input', true);
                $resp = $client->deleteUser($user_id, true, false);
                $this->log($row->meta->host_name . '|users.delete', serialize($resp), 'output', true);
            } catch (Exception $e) {
                $this->log($row->meta->host_name . '|users.delete', $e->getMessage(), 'output', false);
                $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
            }
        }
        return null;
    }

    /**
     * Updates the package for the service on the panel. Sets Input errors on failure.
     *
     * @param stdClass $package_from A stdClass object representing the current package
     * @param stdClass $package_to A stdClass object representing the new package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package
     * @param stdClass $parent_service
     * @return mixed null
     */
    public function changeServicePackage(
        $package_from,
        $package_to,
        $service,
        $parent_package = null,
        $parent_service = null
    ) {
        if (($row = $this->getModuleRow())) {
            // Only request a package change if it has changed
            if (($package_from->meta->package_id ?? '') != ($package_to->meta->package_id ?? '')) {
                $client = $this->getApiClient($row);
                $service_fields = $this->serviceFieldsToObject($service->fields);
                try {
                    $this->requireCapability($client, 'users.package', 'Jabali.!error.capability.package');
                    if (($package_to->meta->package_id ?? '') === '') {
                        $this->Input->setErrors([
                            'change_package' => ['missing' => Language::_('Jabali.!error.change_package.missing', true)]
                        ]);
                        return;
                    }
                    $user_id = $this->resolveUserId($client, $service_fields);
                    $this->log(
                        $row->meta->host_name . '|users.package',
                        serialize(['user_id' => $user_id, 'package_id' => $package_to->meta->package_id]),
                        'input',
                        true
                    );
                    $resp = $client->setUserPackage($user_id, $package_to->meta->package_id);
                    $this->log($row->meta->host_name . '|users.package', serialize($resp), 'output', true);
                } catch (Exception $e) {
                    $this->log($row->meta->host_name . '|users.package', $e->getMessage(), 'output', false);
                    $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);
                    return;
                }
            }
        }
        return null;
    }

    /**
     * Validates input data when attempting to add a service. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info
     * @return bool True if the service validates, false otherwise
     */
    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars));
        return $this->Input->validates($vars);
    }

    /**
     * Validates input data when attempting to edit a service. Sets Input errors on failure.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param array $vars An array of user supplied info
     * @return bool True if the service validates, false otherwise
     */
    public function validateServiceEdit($service, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, true));
        return $this->Input->validates($vars);
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the admin interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content
     */
    public function getAdminServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        $this->view = new View('admin_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->serviceFieldsToObject($service->fields));

        return $this->view->fetch();
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the client interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content
     */
    public function getClientServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        $this->view = new View('client_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->serviceFieldsToObject($service->fields));

        return $this->view->fetch();
    }

    /**
     * Client Actions tab: password reset + one-click panel login.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The contents of this tab
     */
    public function tabClientActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_actions', 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow();
        $service_fields = $this->serviceFieldsToObject($service->fields);

        $sso_supported = false;
        if ($row) {
            try {
                $client = $this->getApiClient($row);
                $sso_supported = $client->hasCapability('users.login_token');
            } catch (Exception $e) {
                // Panel unreachable — hide the SSO button
            }
        }

        if (!empty($post)) {
            if (isset($post['action']) && $post['action'] == 'panel_login' && $sso_supported) {
                // Mint a one-time login URL bound to the visitor's IP
                try {
                    $client = $this->getApiClient($row);
                    $user_id = $this->resolveUserId($client, $service_fields);
                    $resp = $client->mintLoginToken($user_id, $_SERVER['REMOTE_ADDR'] ?? null);
                    $url = (string)($resp['url'] ?? '');
                    if ($url !== '') {
                        header('Location: ' . $url);
                        exit;
                    }
                    $this->Input->setErrors(['api' => ['response' => Language::_('Jabali.!error.api.no_login_url', true)]]);
                } catch (Exception $e) {
                    // Client-facing tab: keep panel-internal detail out of the message
                    $this->Input->setErrors(['api' => ['response' => Language::_('Jabali.!error.api.client_generic', true)]]);
                }
            } else {
                // Password reset via the standard service edit flow
                Loader::loadModels($this, ['Services']);
                $data = [
                    'jabali_password' => ($post['jabali_password'] ?? null),
                ];
                $this->Services->edit($service->id, $data);

                if ($this->Services->errors()) {
                    $this->Input->setErrors($this->Services->errors());
                }

                $vars = (object)$post;
            }
        }

        $panel_url = $row
            ? 'https://' . $row->meta->host_name . ':' . ($row->meta->port ?? '8443') . '/jabali-panel'
            : '';

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('sso_supported', $sso_supported);
        $this->view->set('panel_url', $panel_url);
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'jabali' . DS);
        return $this->view->fetch();
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * Builds the shared API client for a module row.
     *
     * @param stdClass $module_row
     * @return JabaliApiClient
     */
    private function getApiClient($module_row)
    {
        return new JabaliApiClient(
            $module_row->meta->host_name . ':' . ($module_row->meta->port ?? '8443'),
            $module_row->meta->token_id ?? '',
            $module_row->meta->token_secret ?? ''
        );
    }

    /**
     * Throws when the panel does not advertise a capability.
     *
     * @param JabaliApiClient $client
     * @param string $capability
     * @param string $lang_key
     * @throws JabaliApiException
     */
    private function requireCapability($client, $capability, $lang_key)
    {
        if (!$client->hasCapability($capability)) {
            throw new JabaliApiException(Language::_($lang_key, true), 0, 'unsupported');
        }
    }

    /**
     * Resolves the Jabali user ULID from stored service fields, falling back
     * to a username/email lookup on the panel.
     *
     * @param JabaliApiClient $client
     * @param stdClass $service_fields
     * @return string
     * @throws JabaliApiException
     */
    private function resolveUserId($client, $service_fields)
    {
        $stored = trim((string)($service_fields->jabali_user_id ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $row = $client->findUser($service_fields->jabali_username ?? '');
        if ($row === null) {
            $row = $client->findUser($service_fields->jabali_email ?? '');
        }
        if ($row === null || empty($row['id'])) {
            throw new JabaliApiException(Language::_('Jabali.!error.api.user_not_found', true), 0, 'not_found');
        }
        return (string)$row['id'];
    }

    /**
     * Generates a username from the given domain.
     *
     * @param string $domain
     * @return string
     */
    private function generateUsername($domain)
    {
        $username = ltrim(preg_replace('/[^a-z0-9]/i', '', strtolower($domain)), '0123456789');
        if (strlen($username) < 3) {
            $username .= substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 3);
        }
        return substr($username, 0, 16);
    }

    /**
     * Generates a password satisfying Jabali's min-10 rule.
     *
     * @return string
     */
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
     * Service validation rules.
     *
     * @param array $vars
     * @param bool $edit
     * @return array
     */
    private function getServiceRules(array $vars = null, $edit = false)
    {
        $rules = [
            'jabali_domain' => [
                'format' => [
                    'rule' => [[$this, 'validateHostName']],
                    'message' => Language::_('Jabali.!error.jabali_domain.format', true)
                ]
            ],
            'jabali_username' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['matches', '/^[a-z][a-z0-9]{2,31}$/'],
                    'message' => Language::_('Jabali.!error.jabali_username.format', true)
                ]
            ],
            'jabali_password' => [
                'length' => [
                    'if_set' => true,
                    'rule' => ['minLength', 10],
                    'message' => Language::_('Jabali.!error.jabali_password.length', true)
                ]
            ],
            'jabali_email' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['isEmail', false],
                    'message' => Language::_('Jabali.!error.jabali_email.format', true)
                ]
            ]
        ];

        if ($edit) {
            // On edit only the password may change
            unset($rules['jabali_domain'], $rules['jabali_username'], $rules['jabali_email']);
        }

        return $rules;
    }

    /**
     * Validates a host name.
     *
     * @param string $host_name
     * @return bool
     */
    public function validateHostName($host_name)
    {
        $validator = new \Blesta\Core\Util\Validate\Server();
        return $validator->isDomain($host_name);
    }

    /**
     * Module row validation rules.
     *
     * @param array $vars
     * @return array
     */
    private function getRowRules(&$vars)
    {
        return [
            'server_name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Jabali.!error.server_name.empty', true)
                ]
            ],
            'host_name' => [
                'format' => [
                    'rule' => [[$this, 'validateHostName']],
                    'message' => Language::_('Jabali.!error.host_name.format', true)
                ]
            ],
            'port' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]{1,5}$/'],
                    'message' => Language::_('Jabali.!error.port.format', true)
                ]
            ],
            'token_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Jabali.!error.token_id.empty', true)
                ]
            ],
            'token_secret' => [
                'valid_connection' => [
                    'rule' => [
                        function ($secret) use (&$vars) {
                            try {
                                $client = new JabaliApiClient(
                                    ($vars['host_name'] ?? '') . ':' . ($vars['port'] ?? '8443'),
                                    $vars['token_id'] ?? '',
                                    $secret
                                );
                                $client->status();
                                return true;
                            } catch (Exception $e) {
                                return false;
                            }
                        }
                    ],
                    'message' => Language::_('Jabali.!error.token_secret.valid_connection', true)
                ]
            ]
        ];
    }

    /**
     * Package validation rules.
     *
     * @param array $vars
     * @return array
     */
    private function getPackageRules($vars)
    {
        return [
            'meta[package_id]' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['matches', '/^[0-9A-Z]{0,26}$/i'],
                    'message' => Language::_('Jabali.!error.meta[package_id].format', true)
                ]
            ],
            'meta[package_id_override]' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['matches', '/^[0-9A-Z]{0,26}$/i'],
                    'message' => Language::_('Jabali.!error.meta[package_id].format', true)
                ]
            ]
        ];
    }
}
