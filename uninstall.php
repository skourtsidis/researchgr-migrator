<?php
/**
 * Cleanup on uninstall.
 *
 * @package ResearchGRMigrator
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$token = get_option('rgm_storage_token');
delete_option('rgm_storage_token');
delete_option('rgm_saved_active_plugins');
delete_option('rgm_notice_dismissed');

$guard = WP_CONTENT_DIR . '/mu-plugins/rgm-guard.php';
if (is_file($guard)) {
    unlink($guard);
}
$redirect = WP_CONTENT_DIR . '/mu-plugins/rgm-staging-redirect.php';
if (is_file($redirect)) {
    unlink($redirect);
}
$flag = WP_CONTENT_DIR . '/uploads/researchgr-migrator/safe-mode.flag';
if (is_file($flag)) {
    unlink($flag);
}

$candidates = array(
    WP_CONTENT_DIR . '/uploads/researchgr-migrator',
);
if ($token) {
    $candidates[] = WP_CONTENT_DIR . '/rgm-private-' . $token;
}
$parent = dirname(ABSPATH);
if ($token && is_dir($parent)) {
    $candidates[] = $parent . '/rgm-private-' . $token;
}

/**
 * @param string $dir Directory.
 * @return void
 */
function rgm_uninstall_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rgm_uninstall_rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

foreach ($candidates as $dir) {
    rgm_uninstall_rrmdir($dir);
}
