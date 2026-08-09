<?php
/**
 * en_us language for the Jabali Panel module.
 */
// Basics
$lang['Jabali.name'] = 'Jabali Panel';
$lang['Jabali.description'] = 'Provisions hosting accounts on Jabali Panel, the modern hosting control panel for WordPress and PHP hosting.';
$lang['Jabali.module_row'] = 'Server';
$lang['Jabali.module_row_plural'] = 'Servers';
$lang['Jabali.module_group'] = 'Server Group';
$lang['Jabali.tab_client_actions'] = 'Actions';

// Module management
$lang['Jabali.add_module_row'] = 'Add Server';
$lang['Jabali.add_module_group'] = 'Add Server Group';
$lang['Jabali.manage.module_rows_title'] = 'Servers';
$lang['Jabali.manage.module_rows_heading.name'] = 'Server Label';
$lang['Jabali.manage.module_rows_heading.host_name'] = 'Hostname';
$lang['Jabali.manage.module_rows_heading.options'] = 'Options';
$lang['Jabali.manage.module_rows.edit'] = 'Edit';
$lang['Jabali.manage.module_rows.delete'] = 'Delete';
$lang['Jabali.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this server?';
$lang['Jabali.servers.no_results'] = 'There are no servers.';

$lang['Jabali.order_options.roundrobin'] = 'Evenly Distribute Among Servers';
$lang['Jabali.order_options.first'] = 'First Non-full Server';

// Row meta
$lang['Jabali.row_meta.server_name'] = 'Server Label';
$lang['Jabali.row_meta.host_name'] = 'Panel Hostname';
$lang['Jabali.row_meta.port'] = 'Panel Port';
$lang['Jabali.row_meta.default_port'] = '8443';
$lang['Jabali.row_meta.token_id'] = 'Automation Token ID';
$lang['Jabali.row_meta.token_secret'] = 'Automation Token Secret';
$lang['Jabali.row_meta.account_limit'] = 'Account Limit';
$lang['Jabali.row_meta.notes'] = 'Notes';

// Add/edit row
$lang['Jabali.add_row.box_title'] = 'Add Jabali Server';
$lang['Jabali.add_row.basic_title'] = 'Basic Settings';
$lang['Jabali.add_row.add_btn'] = 'Add Server';
$lang['Jabali.edit_row.box_title'] = 'Edit Jabali Server';
$lang['Jabali.edit_row.basic_title'] = 'Basic Settings';
$lang['Jabali.edit_row.edit_btn'] = 'Update Server';

// Package fields
$lang['Jabali.package_fields.package'] = 'Jabali Package';
$lang['Jabali.package_fields.package_manual'] = '-- set below manually --';
$lang['Jabali.package_fields.package_override'] = 'Package ID (manual override)';

// Service fields
$lang['Jabali.service_field.domain'] = 'Domain';
$lang['Jabali.service_field.username'] = 'Username';
$lang['Jabali.service_field.password'] = 'Password';
$lang['Jabali.service_field.email'] = 'Email Address';
$lang['Jabali.service_field.text_generate_password'] = 'Generate Password';

// Service info
$lang['Jabali.service_info.username'] = 'Username';
$lang['Jabali.service_info.domain'] = 'Domain';
$lang['Jabali.service_info.panel_url'] = 'Panel URL';
$lang['Jabali.service_info.login'] = 'Log in to Jabali Panel';

// Client actions tab
$lang['Jabali.tab_client_actions.change_password'] = 'Change Password';
$lang['Jabali.tab_client_actions.field_password'] = 'New Password';
$lang['Jabali.tab_client_actions.field_password_submit'] = 'Update Password';
$lang['Jabali.tab_client_actions.login'] = 'Log in to Jabali Panel';
$lang['Jabali.tab_client_actions.login_submit'] = 'Open Control Panel';

// Errors
$lang['Jabali.!error.server_name.empty'] = 'Please enter a server label.';
$lang['Jabali.!error.host_name.format'] = 'Please enter a valid panel hostname.';
$lang['Jabali.!error.port.format'] = 'Please enter a valid port number.';
$lang['Jabali.!error.token_id.empty'] = 'Please enter the automation token ID.';
$lang['Jabali.!error.token_secret.valid_connection'] = 'Could not connect to the Jabali panel with these credentials. Check hostname, port, token ID and secret — and that both server clocks are NTP-synced.';
$lang['Jabali.!error.module_row.missing'] = 'No Jabali server is assigned to this package.';
$lang['Jabali.!error.jabali_domain.format'] = 'Please enter a valid domain name.';
$lang['Jabali.!error.jabali_username.format'] = 'Usernames must be 3-32 characters, start with a letter, and contain only lowercase letters and numbers.';
$lang['Jabali.!error.jabali_password.length'] = 'The password must be at least 10 characters long.';
$lang['Jabali.!error.jabali_email.format'] = 'Please enter a valid email address.';
$lang['Jabali.!error.meta[package_id].format'] = 'The Jabali package ID must be a 26-character ULID.';
$lang['Jabali.!error.change_package.missing'] = 'The new package has no Jabali package ID configured.';
$lang['Jabali.!error.api.no_user_id'] = 'The panel accepted the request but returned no user ID. Verify the panel version.';
$lang['Jabali.!error.api.no_login_url'] = 'The panel did not return a login URL.';
$lang['Jabali.!error.api.client_generic'] = 'We are temporarily unable to reach the control panel. Please try again later or contact support.';
$lang['Jabali.!error.api.user_not_found'] = 'Cannot map this service to a Jabali user. Set the jabali_user_id service field manually.';
$lang['Jabali.!error.capability.create'] = 'The connected Jabali Panel does not support account creation via the automation API yet. Upgrade Jabali Panel.';
$lang['Jabali.!error.capability.delete'] = 'The connected Jabali Panel does not support account termination via the automation API yet. Upgrade Jabali Panel.';
$lang['Jabali.!error.capability.password'] = 'The connected Jabali Panel does not support password changes via the automation API yet. Upgrade Jabali Panel.';
$lang['Jabali.!error.capability.package'] = 'The connected Jabali Panel does not support package changes via the automation API yet. Upgrade Jabali Panel.';
