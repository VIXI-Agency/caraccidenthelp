<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

/** @var \VIXI\CahSplit\Settings $settings */

$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
?>
<div class="wrap cah-split-wrap">
    <h1><?php esc_html_e('Split Tester — Settings', 'cah-split'); ?></h1>

    <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved.', 'cah-split'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cah_split_save_settings" />
        <?php wp_nonce_field('cah_split_save_settings', 'cah_split_nonce'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="make_webhook_url"><?php esc_html_e('Make.com webhook URL', 'cah-split'); ?></label>
                    </th>
                    <td>
                        <input
                            name="make_webhook_url"
                            id="make_webhook_url"
                            type="url"
                            class="regular-text code"
                            value="<?php echo esc_attr($settings->makeWebhookUrl()); ?>"
                            required
                        />
                        <p class="description">
                            <?php esc_html_e('Leads are forwarded here server-side after being saved in WordPress.', 'cah-split'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cookie_ttl_days"><?php esc_html_e('Variant cookie TTL (days)', 'cah-split'); ?></label>
                    </th>
                    <td>
                        <input
                            name="cookie_ttl_days"
                            id="cookie_ttl_days"
                            type="number"
                            min="1"
                            max="3650"
                            step="1"
                            value="<?php echo esc_attr((string) $settings->cookieTtlDays()); ?>"
                            class="small-text"
                        />
                        <p class="description">
                            <?php esc_html_e('How long a visitor stays stuck to the variant they were bucketed into.', 'cah-split'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Drop tables on uninstall', 'cah-split'); ?></th>
                    <td>
                        <label for="drop_tables_on_uninstall">
                            <input
                                name="drop_tables_on_uninstall"
                                id="drop_tables_on_uninstall"
                                type="checkbox"
                                value="1"
                                <?php checked($settings->dropTablesOnUninstall()); ?>
                            />
                            <?php esc_html_e('Permanently delete tests, variants, pageviews, and leads tables when the plugin is uninstalled.', 'cah-split'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Default off. Leaving this off preserves lead data even if the plugin is removed.', 'cah-split'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'cah-split')); ?>
    </form>
</div>
