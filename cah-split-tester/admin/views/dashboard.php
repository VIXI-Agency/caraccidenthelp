<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cah-split-wrap">
    <h1><?php esc_html_e('Split Tester — Dashboard', 'cah-split'); ?></h1>

    <div class="notice notice-info">
        <p><?php esc_html_e('Dashboard stats, charts, and UTM breakdowns arrive in Phase 4.', 'cah-split'); ?></p>
    </div>

    <div class="cah-card-grid">
        <div class="cah-card">
            <h2><?php esc_html_e('Phase 1 scope', 'cah-split'); ?></h2>
            <p><?php esc_html_e('Plugin bootstrap, four custom tables, admin menu, and a working Settings page.', 'cah-split'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cah-split-settings')); ?>">
                    <?php esc_html_e('Open Settings', 'cah-split'); ?>
                </a>
            </p>
        </div>
        <div class="cah-card">
            <h2><?php esc_html_e('What\'s next', 'cah-split'); ?></h2>
            <p><?php esc_html_e('Phase 2 — router, weighted variant selection, plugin-rendered variant route, and the current landing page migrated as v1.', 'cah-split'); ?></p>
        </div>
    </div>
</div>
