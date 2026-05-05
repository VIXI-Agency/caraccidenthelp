<?php
/**
 * Plugin Name:       CAH Split Tester
 * Plugin URI:        https://github.com/VIXI-Agency/caraccidenthelp
 * Description:       Generic A/B/N split testing for caraccidenthelp.net. WordPress is the source of truth for leads; Make.com is forwarded server-side.
 * Version:           1.0.18
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            VIXI Agency
 * License:           Proprietary
 * Text Domain:       cah-split
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace VIXI\CahSplit;

if (!defined('ABSPATH')) {
    exit;
}

define('CAH_SPLIT_VERSION', '1.0.18');
define('CAH_SPLIT_PLUGIN_FILE', __FILE__);
define('CAH_SPLIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CAH_SPLIT_PLUGIN_URL', plugin_dir_url(__FILE__));

$cahSplitVendor = CAH_SPLIT_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($cahSplitVendor)) {
    require_once $cahSplitVendor;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'VIXI\\CahSplit\\Admin\\' => CAH_SPLIT_PLUGIN_DIR . 'admin/',
            'VIXI\\CahSplit\\'        => CAH_SPLIT_PLUGIN_DIR . 'includes/',
        ];
        foreach ($prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($path)) {
                require_once $path;
            }
            return;
        }
    });
}
unset($cahSplitVendor);

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
