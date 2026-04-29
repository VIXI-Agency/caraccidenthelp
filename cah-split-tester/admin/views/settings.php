<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

/** @var \VIXI\CahSplit\Settings $settings */

$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
$pruned  = isset($_GET['pruned']) ? (int) $_GET['pruned'] : null;
$userErr = get_transient('cah_split_error_' . get_current_user_id());
if ($userErr) {
    delete_transient('cah_split_error_' . get_current_user_id());
}
?>
<div class="wrap cah-split-wrap">
    <h1><?php esc_html_e('Split Tester — Settings', 'cah-split'); ?></h1>

    <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved.', 'cah-split'); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($pruned !== null) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(esc_html(_n('%s pageview deleted.', '%s pageviews deleted.', $pruned, 'cah-split')), number_format_i18n($pruned)); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($userErr) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html((string) $userErr); ?></p></div>
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
                    <th scope="row">
                        <label for="dashboard_timezone"><?php esc_html_e('Dashboard timezone', 'cah-split'); ?></label>
                    </th>
                    <td>
                        <select name="dashboard_timezone" id="dashboard_timezone">
                            <?php
                            $currentTz = $settings->dashboardTimezoneRaw();
                            foreach (\VIXI\CahSplit\Settings::DASHBOARD_TZ_CHOICES as $tzKey => $tzLabel) :
                                ?>
                                <option value="<?php echo esc_attr((string) $tzKey); ?>" <?php selected($currentTz, $tzKey); ?>>
                                    <?php echo esc_html((string) $tzLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php
                            $resolved = $settings->dashboardTimezone();
                            $nowLocal = (new \DateTimeImmutable('now', $resolved))->format('Y-m-d H:i T');
                            printf(
                                /* translators: %s: current local time in selected zone */
                                esc_html__('Used to interpret all date filters and to bucket the per-day chart. Leads are stored in UTC and converted on read — changing this never moves data, only how it’s displayed. Currently resolved to: %s', 'cah-split'),
                                '<strong>' . esc_html($nowLocal) . '</strong>'
                            );
                            ?>
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

    <hr />

    <h2><?php esc_html_e('Maintenance tools', 'cah-split'); ?></h2>
    <p class="description">
        <?php esc_html_e('Manual tools only — nothing here runs on a schedule. Leads are never deleted; only pageviews are eligible for pruning.', 'cah-split'); ?>
    </p>

    <h3><?php esc_html_e('Prune old pageviews', 'cah-split'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete pageviews older than the chosen date? This cannot be undone.', 'cah-split')); ?>');">
        <input type="hidden" name="action" value="cah_split_prune_pageviews" />
        <?php wp_nonce_field('cah_split_prune_pageviews', 'cah_split_nonce'); ?>
        <p>
            <label for="before_date"><?php esc_html_e('Delete pageviews before:', 'cah-split'); ?></label>
            <input type="date" id="before_date" name="before_date" required />
            <button type="submit" class="button button-secondary cah-danger-btn"><?php esc_html_e('Prune', 'cah-split'); ?></button>
        </p>
    </form>

    <h3><?php esc_html_e('Retry failed Make.com forwards', 'cah-split'); ?></h3>
    <p>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cah_split_retry_make'), 'cah_split_retry_make')); ?>" class="button">
            <?php esc_html_e('Retry now', 'cah-split'); ?>
        </a>
        <span class="description"><?php esc_html_e('Runs the same retry logic that cron performs hourly.', 'cah-split'); ?></span>
    </p>
</div>
