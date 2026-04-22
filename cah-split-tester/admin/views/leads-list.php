<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;

/** @var array<int,array<string,mixed>> $leads */
/** @var int $totalLeads */
/** @var int $page */
/** @var int $perPage */
/** @var array<string,mixed> $filters */
/** @var array<int,array<string,mixed>> $allTests */
/** @var array<int,array<string,mixed>> $allVariants */

$totalPages = $perPage > 0 ? (int) ceil($totalLeads / $perPage) : 1;

$exportUrl = wp_nonce_url(
    add_query_arg(
        array_merge(
            ['action' => 'cah_split_export_leads'],
            array_filter($filters, static fn($v): bool => $v !== null && $v !== '')
        ),
        admin_url('admin-post.php')
    ),
    'cah_split_export_leads'
);

function cah_format_dt(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return esc_html($value);
    }
    return esc_html(date_i18n('Y-m-d H:i', $ts));
}
?>
<div class="wrap cah-split-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Leads', 'cah-split'); ?></h1>
    <a href="<?php echo esc_url($exportUrl); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'cah-split'); ?></a>
    <hr class="wp-header-end" />

    <form method="get" class="cah-filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(Admin::LEADS_SLUG); ?>" />

        <label><?php esc_html_e('Test', 'cah-split'); ?>
            <select name="test_id">
                <option value=""><?php esc_html_e('All tests', 'cah-split'); ?></option>
                <?php foreach ($allTests as $t) : ?>
                    <option value="<?php echo esc_attr((string) $t['id']); ?>" <?php selected((int) ($filters['test_id'] ?? 0), (int) $t['id']); ?>>
                        <?php echo esc_html((string) $t['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label><?php esc_html_e('Variant', 'cah-split'); ?>
            <select name="variant_id">
                <option value=""><?php esc_html_e('All variants', 'cah-split'); ?></option>
                <?php foreach ($allVariants as $v) : ?>
                    <option value="<?php echo esc_attr((string) $v['id']); ?>" <?php selected((int) ($filters['variant_id'] ?? 0), (int) $v['id']); ?>>
                        <?php echo esc_html((string) $v['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label><?php esc_html_e('Stage', 'cah-split'); ?>
            <select name="lead_stage">
                <option value=""><?php esc_html_e('Any', 'cah-split'); ?></option>
                <option value="qualified" <?php selected((string) ($filters['lead_stage'] ?? ''), 'qualified'); ?>><?php esc_html_e('Qualified', 'cah-split'); ?></option>
                <option value="disqualified" <?php selected((string) ($filters['lead_stage'] ?? ''), 'disqualified'); ?>><?php esc_html_e('Disqualified', 'cah-split'); ?></option>
                <option value="unknown" <?php selected((string) ($filters['lead_stage'] ?? ''), 'unknown'); ?>><?php esc_html_e('Unknown', 'cah-split'); ?></option>
            </select>
        </label>

        <label><?php esc_html_e('From', 'cah-split'); ?>
            <input type="date" name="from" value="<?php echo esc_attr(substr((string) ($filters['from'] ?? ''), 0, 10)); ?>" />
        </label>

        <label><?php esc_html_e('To', 'cah-split'); ?>
            <input type="date" name="to" value="<?php echo esc_attr(substr((string) ($filters['to'] ?? ''), 0, 10)); ?>" />
        </label>

        <label><?php esc_html_e('UTM source', 'cah-split'); ?>
            <input type="text" name="utm_source" value="<?php echo esc_attr((string) ($filters['utm_source'] ?? '')); ?>" />
        </label>

        <label><?php esc_html_e('State', 'cah-split'); ?>
            <input type="text" name="state" value="<?php echo esc_attr((string) ($filters['state'] ?? '')); ?>" />
        </label>

        <label><?php esc_html_e('Email', 'cah-split'); ?>
            <input type="text" name="email" value="<?php echo esc_attr((string) ($filters['email'] ?? '')); ?>" />
        </label>

        <label><?php esc_html_e('Phone', 'cah-split'); ?>
            <input type="text" name="phone" value="<?php echo esc_attr((string) ($filters['phone'] ?? '')); ?>" />
        </label>

        <button class="button button-primary"><?php esc_html_e('Filter', 'cah-split'); ?></button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . Admin::LEADS_SLUG)); ?>" class="button"><?php esc_html_e('Reset', 'cah-split'); ?></a>
    </form>

    <p class="cah-summary">
        <?php printf(esc_html__('%s leads match filters.', 'cah-split'), number_format_i18n($totalLeads)); ?>
    </p>

    <?php if (empty($leads)) : ?>
        <div class="cah-empty">
            <p><?php esc_html_e('No leads match the current filters.', 'cah-split'); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped cah-leads-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Name', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Contact', 'cah-split'); ?></th>
                    <th><?php esc_html_e('State', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Service', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Stage', 'cah-split'); ?></th>
                    <th><?php esc_html_e('UTM source', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Make', 'cah-split'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead) : ?>
                    <tr>
                        <td><?php echo cah_format_dt((string) ($lead['created_at'] ?? '')); ?></td>
                        <td><?php echo esc_html(trim(((string) ($lead['first_name'] ?? '')) . ' ' . ((string) ($lead['last_name'] ?? '')))); ?></td>
                        <td>
                            <div><?php echo esc_html((string) ($lead['email'] ?? '')); ?></div>
                            <div class="description"><?php echo esc_html((string) ($lead['phone'] ?? '')); ?></div>
                        </td>
                        <td><?php echo esc_html((string) ($lead['state'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($lead['service_type'] ?? '')); ?></td>
                        <td><span class="cah-status cah-status-<?php echo esc_attr((string) ($lead['lead_stage'] ?? 'unknown')); ?>"><?php echo esc_html((string) ($lead['lead_stage'] ?? 'unknown')); ?></span></td>
                        <td><?php echo esc_html((string) ($lead['utm_source'] ?? '')); ?></td>
                        <td><span class="cah-make cah-make-<?php echo esc_attr((string) ($lead['make_status'] ?? '')); ?>"><?php echo esc_html((string) ($lead['make_status'] ?? '')); ?></span></td>
                        <td><button type="button" class="button-link cah-expand" data-lead="<?php echo esc_attr((string) $lead['id']); ?>"><?php esc_html_e('Details', 'cah-split'); ?></button></td>
                    </tr>
                    <tr class="cah-lead-detail" id="cah-lead-detail-<?php echo esc_attr((string) $lead['id']); ?>" hidden>
                        <td colspan="9">
                            <pre><?php echo esc_html(
                                wp_json_encode(
                                    json_decode((string) ($lead['raw_payload'] ?? 'null'), true),
                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                                ) ?: (string) ($lead['raw_payload'] ?? '')
                            ); ?></pre>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1) :
            $base = add_query_arg(
                array_filter(array_merge(['page' => Admin::LEADS_SLUG], $filters), static fn($v): bool => $v !== null && $v !== ''),
                admin_url('admin.php')
            );
            ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', $base),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $totalPages,
                        'prev_text' => __('&laquo;', 'cah-split'),
                        'next_text' => __('&raquo;', 'cah-split'),
                    ]); ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.classList || !e.target.classList.contains('cah-expand')) { return; }
        var id = e.target.getAttribute('data-lead');
        var row = document.getElementById('cah-lead-detail-' + id);
        if (!row) { return; }
        if (row.hasAttribute('hidden')) {
            row.removeAttribute('hidden');
            e.target.textContent = '<?php echo esc_js(__('Hide', 'cah-split')); ?>';
        } else {
            row.setAttribute('hidden', 'hidden');
            e.target.textContent = '<?php echo esc_js(__('Details', 'cah-split')); ?>';
        }
    });
})();
</script>
