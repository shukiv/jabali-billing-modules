<?php
/**
 * Jabali Panel — product page form elements.
 *
 * WiseCP renders this file on the Add/Edit Product screen once this module is
 * selected for the product's server. Without it WiseCP has nothing to draw and
 * the product shows no package selector at all, leaving the operator no way to
 * map a product to a Jabali package.
 *
 * Form elements must be named module_data[<field>]; WiseCP persists them to the
 * product's module_data column. "plan" is WiseCP's own key for the selection,
 * and Jabali.php reads it (falling back to the legacy free-text "package_id").
 *
 * Available: $module (this module), $product (product row), $LANG.
 */

$product = isset($product) && $product ? $product : [];

$module_data = [];
if (isset($product['module_data']) && $product['module_data']) {
    $module_data = is_array($product['module_data'])
        ? $product['module_data']
        : Utility::jdecode($product['module_data'], true);
}
if (!is_array($module_data)) {
    $module_data = [];
}

// WiseCP nests these under create_account for some modules; accept both shapes.
$create_account = isset($module_data['create_account']) && is_array($module_data['create_account'])
    ? $module_data['create_account']
    : $module_data;

$selected_plan = '';
foreach (['plan', 'package_id'] as $key) {
    if (!empty($create_account[$key])) {
        $selected_plan = (string)$create_account[$key];
        break;
    }
}

$plans = $module->getPlans();
?>
<div class="form-group">
    <label for="module_data_plan">Jabali Package</label>

    <?php if ($plans === false || !is_array($plans) || !$plans): ?>

        <select name="module_data[plan]" id="module_data_plan">
            <option value="<?php echo htmlspecialchars($selected_plan, ENT_QUOTES); ?>">
                <?php echo $selected_plan !== '' ? htmlspecialchars($selected_plan, ENT_QUOTES) : 'None'; ?>
            </option>
        </select>
        <p class="description" style="color:#e23e3d;">
            Could not read the package list from Jabali Panel. Check the server's
            hostname, port and automation token (the token needs a read scope),
            then reopen this page.
            <?php
            $err = isset($module->error) ? trim((string)$module->error) : '';
            if ($err !== '') {
                echo '<br />' . htmlspecialchars($err, ENT_QUOTES);
            }
            ?>
        </p>

    <?php else: ?>

        <select name="module_data[plan]" id="module_data_plan">
            <option value="">None</option>
            <?php foreach ($plans as $plan_id => $plan): ?>
                <?php
                $plan_id = (string)$plan_id;
                $label   = is_array($plan) && !empty($plan['name'])
                    ? (string)$plan['name']
                    : $plan_id;

                // Surface the quotas so near-identical package names stay distinguishable.
                $bits = [];
                if (is_array($plan)) {
                    if (isset($plan['disk_quota_mb']) && $plan['disk_quota_mb'] !== '') {
                        $bits[] = 'disk ' . (int)$plan['disk_quota_mb'] . ' MB';
                    }
                    if (isset($plan['bandwidth_quota_mb']) && $plan['bandwidth_quota_mb'] !== '') {
                        $bits[] = 'bandwidth ' . (int)$plan['bandwidth_quota_mb'] . ' MB';
                    }
                }
                if ($bits) {
                    $label .= ' (' . implode(', ', $bits) . ')';
                }
                ?>
                <option value="<?php echo htmlspecialchars($plan_id, ENT_QUOTES); ?>"<?php
                    echo $plan_id === $selected_plan ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            The Jabali package applied when the account is created, and on
            upgrade/downgrade.
        </p>

    <?php endif; ?>
</div>
