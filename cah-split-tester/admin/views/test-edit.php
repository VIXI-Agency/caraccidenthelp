<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Repositories\TestsRepository;

/** @var array<string,mixed>|null $test */
/** @var array<int,array<string,mixed>> $variants */

$isEdit      = is_array($test);
$name        = $isEdit ? (string) $test['name'] : '';
$slug        = $isEdit ? (string) $test['slug'] : '';
$triggerPath = $isEdit ? (string) $test['trigger_path'] : '';
$status      = $isEdit ? (string) $test['status'] : TestsRepository::STATUS_DRAFT;
$id          = $isEdit ? (int) $test['id'] : 0;
$userErr     = get_transient('cah_split_error_' . get_current_user_id());
if ($userErr) {
    delete_transient('cah_split_error_' . get_current_user_id());
}
if (empty($variants)) {
    $variants = [
        ['name' => '', 'slug' => '', 'url' => '', 'html_file' => '', 'weight' => 50, 'sort_order' => 0],
        ['name' => '', 'slug' => '', 'url' => '', 'html_file' => '', 'weight' => 50, 'sort_order' => 1],
    ];
}
?>
<div class="wrap cah-split-wrap">
    <h1 class="wp-heading-inline">
        <?php echo $isEdit
            ? esc_html__('Edit test', 'cah-split')
            : esc_html__('Add test', 'cah-split'); ?>
    </h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=' . Admin::TESTS_SLUG)); ?>" class="page-title-action">
        <?php esc_html_e('Back to tests', 'cah-split'); ?>
    </a>
    <hr class="wp-header-end" />

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test saved.', 'cah-split'); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['cloned'])) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Test cloned. Review variants and activate when ready.', 'cah-split'); ?></p></div>
    <?php endif; ?>
    <?php if ($userErr) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html((string) $userErr); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cah-test-form">
        <input type="hidden" name="action" value="cah_split_save_test" />
        <input type="hidden" name="test_id" value="<?php echo esc_attr((string) $id); ?>" />
        <?php wp_nonce_field('cah_split_save_test', 'cah_split_nonce'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="cah-name"><?php esc_html_e('Name', 'cah-split'); ?></label></th>
                    <td>
                        <input type="text" id="cah-name" name="name" value="<?php echo esc_attr($name); ?>" class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cah-slug"><?php esc_html_e('Slug', 'cah-split'); ?></label></th>
                    <td>
                        <input type="text" id="cah-slug" name="slug" value="<?php echo esc_attr($slug); ?>" class="regular-text code" />
                        <p class="description"><?php esc_html_e('Used in plugin-hosted variant URLs. Auto-generated from the name if left empty.', 'cah-split'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cah-trigger"><?php esc_html_e('Trigger path', 'cah-split'); ?></label></th>
                    <td>
                        <input type="text" id="cah-trigger" name="trigger_path" value="<?php echo esc_attr($triggerPath); ?>" class="regular-text code" placeholder="/car-accident2" required />
                        <p class="description"><?php esc_html_e('Path (no domain) where the router intercepts. Visitors hitting this path are bucketed to a variant.', 'cah-split'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cah-status"><?php esc_html_e('Status', 'cah-split'); ?></label></th>
                    <td>
                        <select id="cah-status" name="status">
                            <?php foreach ([
                                TestsRepository::STATUS_DRAFT    => __('Draft', 'cah-split'),
                                TestsRepository::STATUS_ACTIVE   => __('Active', 'cah-split'),
                                TestsRepository::STATUS_PAUSED   => __('Paused', 'cah-split'),
                                TestsRepository::STATUS_ARCHIVED => __('Archived', 'cah-split'),
                            ] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Only one active test per trigger path. Draft and paused tests do not receive traffic.', 'cah-split'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Variants', 'cah-split'); ?></h2>
        <p class="description">
            <?php esc_html_e('Weights must sum to 100. For plugin-hosted variants, drop the HTML file into the plugin\'s variants/ directory and enter its filename.', 'cah-split'); ?>
        </p>

        <table class="widefat cah-variants-table" id="cah-variants-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'cah-split'); ?></th>
                    <th><?php esc_html_e('Slug', 'cah-split'); ?></th>
                    <th><?php esc_html_e('HTML file', 'cah-split'); ?></th>
                    <th><?php esc_html_e('External URL', 'cah-split'); ?></th>
                    <th style="width: 80px;"><?php esc_html_e('Weight', 'cah-split'); ?></th>
                    <th style="width: 60px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_values($variants) as $i => $v) : ?>
                    <tr class="cah-variant-row">
                        <td><input type="text" name="variants[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr((string) ($v['name'] ?? '')); ?>" class="regular-text" /></td>
                        <td><input type="text" name="variants[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr((string) ($v['slug'] ?? '')); ?>" class="code" /></td>
                        <td><input type="text" name="variants[<?php echo (int) $i; ?>][html_file]" value="<?php echo esc_attr((string) ($v['html_file'] ?? '')); ?>" placeholder="v1.html" class="code" /></td>
                        <td><input type="url" name="variants[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr((string) ($v['url'] ?? '')); ?>" placeholder="<?php esc_attr_e('leave empty if using HTML file', 'cah-split'); ?>" /></td>
                        <td><input type="number" min="0" max="100" step="1" name="variants[<?php echo (int) $i; ?>][weight]" value="<?php echo esc_attr((string) ($v['weight'] ?? 0)); ?>" class="small-text cah-weight" /></td>
                        <td><button type="button" class="button-link-delete cah-remove-variant"><?php esc_html_e('Remove', 'cah-split'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4"><button type="button" class="button" id="cah-add-variant"><?php esc_html_e('+ Add variant', 'cah-split'); ?></button></td>
                    <td><strong id="cah-weight-sum">0</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <p id="cah-weight-error" class="cah-weight-error" style="display:none;">
            <?php esc_html_e('Weights must sum to 100.', 'cah-split'); ?>
        </p>

        <?php submit_button($isEdit ? __('Save test', 'cah-split') : __('Create test', 'cah-split')); ?>
    </form>
</div>
