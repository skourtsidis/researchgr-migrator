<?php
/**
 * Plugin Name: Research GR Migrator
 * Plugin URI: https://github.com/kourtsidis/researchgr-migrator
 * Description: Restores a FastComet / cPanel WP Toolkit backup (tar.gz with files/, sqldump.sql and meta.json) onto this WordPress site, then prepares the research.gr cutover.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Research GR
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: researchgr-migrator
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RGM_VERSION', '1.0.0');
define('RGM_FILE', __FILE__);
define('RGM_DIR', plugin_dir_path(__FILE__));
define('RGM_URL', plugin_dir_url(__FILE__));
define('RGM_BASENAME', plugin_basename(__FILE__));

require_once RGM_DIR . 'includes/helpers.php';
require_once RGM_DIR . 'includes/class-rgm-store.php';
require_once RGM_DIR . 'includes/class-rgm-archive.php';
require_once RGM_DIR . 'includes/class-rgm-sql.php';
require_once RGM_DIR . 'includes/class-rgm-replacer.php';
require_once RGM_DIR . 'includes/class-rgm-files.php';
require_once RGM_DIR . 'includes/class-rgm-runner.php';
require_once RGM_DIR . 'includes/class-rgm-admin.php';

register_activation_hook(RGM_FILE, array('RGM_Admin', 'activate'));
register_deactivation_hook(RGM_FILE, array('RGM_Admin', 'deactivate'));

add_action('plugins_loaded', static function () {
    RGM_Admin::instance();
});
