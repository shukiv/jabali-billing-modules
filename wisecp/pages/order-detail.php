<?php
/**
 * Jabali Panel — admin service detail output.
 *
 * WiseCP renders this file inside the "Hosting Management" tab of a service in
 * the admin area. It is also the only thing that invokes adminArea_service_fields():
 * WiseCP does not call that hook on its own, the module's own order-detail page
 * does (see WiseCP's SampleHostingCP). Without this file our service fields --
 * Panel Username and Jabali User ID -- never appear, which leaves an operator no
 * way to inspect or repair the link between a WiseCP service and its Jabali user.
 *
 * Available: $module (this module), $order (the service row), $LANG.
 */

$LANG    = $module->lang;
$options = isset($order['options']) && is_array($order['options']) ? $order['options'] : [];

$creation_info = isset($options['creation_info']) && is_array($options['creation_info'])
    ? $options['creation_info']
    : [];
$config = isset($options['config']) && is_array($options['config'])
    ? $options['config']
    : [];

$buttons = method_exists($module, 'adminArea_buttons_output')
    ? $module->adminArea_buttons_output()
    : '';

// create() stores the provisioned account under options.config; creation_info is
// the fallback for services linked by hand.
$linked_user = '';
foreach ([$config, $creation_info] as $src) {
    if (!empty($src['user_id'])) {
        $linked_user = (string)$src['user_id'];
        break;
    }
}
?>

<?php if ($buttons): ?>
    <div class="formcon"><?php echo $buttons; ?></div>
    <div class="clear"></div>
<?php endif; ?>

<div class="clear"></div>

<div class="formcon">
    <div class="yuzde30">Jabali Link Status</div>
    <div class="yuzde70">
        <?php if ($linked_user !== ''): ?>
            <span style="color:#2e7d32;">Linked to panel user
                <strong><?php echo htmlspecialchars($linked_user, ENT_QUOTES); ?></strong></span>
        <?php else: ?>
            <span style="color:#e23e3d;">Not linked to a Jabali panel user.</span>
            <span class="kinfo">
                Panel single sign-on and usage reporting need the panel user's ID.
                Services provisioned by this module store it automatically; for a
                service created before the module was installed, paste the user's
                26-character ID below and save, or use Reinstall to provision it.
            </span>
        <?php endif; ?>
    </div>
</div>

<?php
    // Renders Panel Username / Jabali User ID and posts them back as
    // creation_info[...], which save_adminArea_service_fields() validates.
    if (method_exists($module, 'adminArea_service_fields')
        && $service_fields = $module->adminArea_service_fields()) {
        $module->config_options_output($service_fields, 'creation_info');
    }
?>
