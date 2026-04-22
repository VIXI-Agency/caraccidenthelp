<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

use VIXI\CahSplit\Admin\Admin;
use VIXI\CahSplit\Repositories\TestsRepository;
use VIXI\CahSplit\Repositories\VariantsRepository;

/** @var array<int,array<string,mixed>> $tests */
/** @var VariantsRepository $variants */

$newUrl   = admin_url('admin.php?page=' . Admin::TESTS_SLUG . '&action=new');
$userErr  = get_transient('cah_split_error_' . get_current_user_id());
if ($userErr) {
    delete_transient('cah_split_error_' . get_current_user_id());
}
?>
<div class="wrap cah-split-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Tests', 'cah-split'); ?></h1>
    <a href="<?php echo esc_url($newUrl); ?>" class="page-title-action"><?php esc_html_e('Add New', 'cah-split'); ?></a>
    <hr class="wp-header-end" />

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test saved.', 'cah-split'); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test deleted.', 'cah-split'); ?></p></div>
    <?php endif; ?>
    <?php if ($userErr) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html((string) $userErr); ?></p></div>
    <?php endif; ?>

    <?php if (empty($tests)) : ?>
        <div class="cah-empty">
            <p><?php esc_html_e('No tests yet. Create your first one to start routing traffic to variants.', 'cah-split'); ?></p>
            <a href="<?php echo esc_url($newUrl); ?>" class="button button-primary"><?php esc_html_e('Add your first test', 'cah-split'); ?></a>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Name', 'cah-split'); ?></th>
                    <th scope="col"><?php esc_html_e('Trigger path', 'cah-split'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'cah-split'); ?></th>
                    <th scope="col"><?php esc_html_e('Variants', 'cah-split'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'cah-split'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $test) :
                    $testId    = (int) $test['id'];
                    $testVars  = $variants->forTest($testId);
                    $editUrl   = admin_url('admin.php?page=' . Admin::TESTS_SLUG . '&action=edit&test_id=' . $testId);
                    $deleteUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=cah_split_delete_test&test_id=' . $testId),
                        'cah_split_delete_test'
                    );
                    $cloneUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=cah_split_clone_test&test_id=' . $testId),
                        'cah_split_clone_test'
                    );
                    $toggleTo = $test['status'] === TestsRepository::STATUS_ACTIVE
                        ? TestsRepository::STATUS_PAUSED
                        : TestsRepository::STATUS_ACTIVE;
                    $toggleUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=cah_split_toggle_status&test_id=' . $testId . '&status=' . $toggleTo),
                        'cah_split_toggle_status'
                    );
                    $toggleLabel = $toggleTo === TestsRepository::STATUS_ACTIVE
                        ? __('Activate', 'cah-split')
                        : __('Pause', 'cah-split');
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html((string) $test['name']); ?></a></strong>
                            <div class="row-actions">
                                <span><?php echo esc_html((string) $test['slug']); ?></span>
                            </div>
                        </td>
                        <td><code><?php echo esc_html((string) $test['trigger_path']); ?></code></td>
                        <td>
                            <span class="cah-status cah-status-<?php echo esc_attr((string) $test['status']); ?>">
                                <?php echo esc_html((string) $test['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo count($testVars); ?>
                            <?php if (!empty($testVars)) : ?>
                                <small>
                                    (<?php echo esc_html(implode('/', array_map(
                                        static fn(array $v): string => (string) $v['weight'],
                                        $testVars
                                    ))); ?>)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edit', 'cah-split'); ?></a> |
                            <a href="<?php echo esc_url($toggleUrl); ?>"><?php echo esc_html($toggleLabel); ?></a> |
                            <a href="<?php echo esc_url($cloneUrl); ?>"><?php esc_html_e('Clone', 'cah-split'); ?></a> |
                            <a href="<?php echo esc_url($deleteUrl); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this test and its variants?', 'cah-split')); ?>');" class="cah-danger"><?php esc_html_e('Delete', 'cah-split'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
