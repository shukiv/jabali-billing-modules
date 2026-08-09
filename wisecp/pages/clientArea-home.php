<div class="hosting-panel-info">
    <h3>Jabali Panel Hosting</h3>
    <table class="config-table">
        <tr>
            <td><strong>Username</strong></td>
            <td><?php echo htmlspecialchars($username ?? '', ENT_QUOTES); ?></td>
        </tr>
        <tr>
            <td><strong>Domain</strong></td>
            <td><?php echo htmlspecialchars($domain ?? '', ENT_QUOTES); ?></td>
        </tr>
        <tr>
            <td><strong>Control Panel</strong></td>
            <td>
                <a href="<?php echo htmlspecialchars($panel_url ?? '', ENT_QUOTES); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($panel_url ?? '', ENT_QUOTES); ?>
                </a>
            </td>
        </tr>
    </table>
</div>
