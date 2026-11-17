<?php
/**
 * File copy, .htaccess, safe-mode guard and inbox listing.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Files {
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function list_sources() {
        RGM_Store::ensure();
        $inbox = RGM_Store::inbox();
        $items = array();
        if (is_file($inbox . '/sqldump.sql')) {
            $items[] = array(
                'type' => 'folder',
                'id'   => '.',
                'name' => 'inbox root (already extracted)',
                'size' => is_file($inbox . '/sqldump.sql') ? filesize($inbox . '/sqldump.sql') : 0,
            );
        }
        $entries = @scandir($inbox);
        if (!is_array($entries)) {
            return $items;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === 'index.php' || $name === '.htaccess') {
                continue;
            }
            $path = $inbox . '/' . $name;
            if (is_file($path) && preg_match('/\.(tar\.gz|tgz)$/i', $name)) {
                $items[] = array(
                    'type' => 'archive',
                    'id'   => $name,
                    'name' => $name,
                    'size' => filesize($path),
                );
                continue;
            }
            if (is_dir($path) && (is_file($path . '/sqldump.sql') || is_dir($path . '/files'))) {
                $items[] = array(
                    'type' => 'folder',
                    'id'   => $name,
                    'name' => $name,
                    'size' => is_file($path . '/sqldump.sql') ? filesize($path . '/sqldump.sql') : 0,
                );
            }
        }
        return $items;
    }

    /**
     * @param string $id Source id from list_sources().
     * @return string|false
     */
    public static function resolve_source($id) {
        $inbox = RGM_Store::inbox();
        if ($id === '.') {
            return $inbox;
        }
        $id = basename(str_replace('\\', '/', (string) $id));
        if ($id === '' || $id === '.' || $id === '..') {
            return false;
        }
        $path = $inbox . '/' . $id;
        if (!file_exists($path)) {
            return false;
        }
        $real_inbox = realpath($inbox);
        $real_path  = realpath($path);
        if (!$real_inbox || !$real_path || strpos($real_path, $real_inbox) !== 0) {
            return false;
        }
        return $real_path;
    }

    /**
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    public static function inspect($state) {
        $sql = RGM_Store::work() . '/sqldump.sql';
        if (!is_file($sql) && !empty($state['source_path']) && is_dir($state['source_path'])) {
            $candidate = rtrim($state['source_path'], '/') . '/sqldump.sql';
            if (is_file($candidate)) {
                $sql = $candidate;
            }
        }
        if (!is_file($sql)) {
            $state['phase'] = 'error';
            $state['error'] = 'sqldump.sql was not found in this backup. It must be a WP Toolkit archive (files/, meta.json, sqldump.sql).';
            RGM_Store::log('ERROR: ' . $state['error']);
            return $state;
        }
        $state['sql_file'] = $sql;
        $detected          = RGM_SQL::detect($sql);
        $meta_path         = RGM_Store::work() . '/meta.json';
        if (!is_file($meta_path) && !empty($state['source_path']) && is_dir($state['source_path'])) {
            $alt = rtrim($state['source_path'], '/') . '/meta.json';
            if (is_file($alt)) {
                $meta_path = $alt;
            }
        }
        $meta = RGM_SQL::read_meta($meta_path);
        if ($detected['prefix'] === '' && !empty($meta['dbPrefix'])) {
            $detected['prefix'] = (string) $meta['dbPrefix'];
        }
        if (!empty($meta['siteUrl']) && $detected['siteurl'] === '') {
            $detected['siteurl'] = rgm_trim_url((string) $meta['siteUrl']);
        }
        $state['source_url'] = $detected['siteurl'];
        $state['home_url']   = $detected['home'] !== '' ? $detected['home'] : $detected['siteurl'];
        $state['old_prefix'] = $detected['prefix'];
        $state['old_path']   = $detected['old_path'];
        $state['meta']       = $meta;
        $state['sql_tables'] = $detected['tables'];
        $state['sql_bytes']  = filesize($sql);

        $config = RGM_Store::work() . '/wp-config.original.php';
        if (is_file($config)) {
            $raw = (string) file_get_contents($config);
            if ($state['old_prefix'] === '' && preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
                $state['old_prefix'] = $m[1];
            }
            if (preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']*)'/", $raw, $m)) {
                $state['old_db_name'] = $m[1];
            }
        }

        RGM_Store::log('Backup inspected. Source URL: ' . ($state['source_url'] !== '' ? $state['source_url'] : '(not detected)') . '. Prefix: ' . ($state['old_prefix'] !== '' ? $state['old_prefix'] : '(unknown)') . '.');
        if ($state['old_path'] !== '') {
            RGM_Store::log('Old server path detected: ' . $state['old_path']);
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state  State.
     * @param int                 $budget Seconds.
     * @return array<string,mixed>
     */
    public static function copy_slice($state, $budget = 12) {
        try {
            $start  = microtime(true);
            $source = rtrim((string) $state['source_path'], '/');
            $root   = $source . '/files/wp-content';
            if (!is_dir($root)) {
                $root = $source . '/wp-content';
            }
            if (!is_dir($root)) {
                throw new RuntimeException('Could not find files/wp-content in the extracted backup.');
            }
            $manifest = RGM_Store::work() . '/manifest.txt';
            if (!is_file($manifest)) {
                $count = self::build_manifest($root, $manifest, $state);
                $state['copy']['total'] = $count;
                RGM_Store::log('File manifest ready: ' . $count . ' files to copy.');
                self::stage_special_files($source);
            }
            $fh = fopen($manifest, 'rb');
            if (!$fh) {
                throw new RuntimeException('Cannot read the file manifest.');
            }
            $index = 0;
            while ($index < (int) $state['copy']['index'] && !feof($fh)) {
                fgets($fh);
                $index++;
            }
            while (!feof($fh) && (microtime(true) - $start) < $budget) {
                $rel = fgets($fh);
                if ($rel === false) {
                    break;
                }
                $rel = trim($rel);
                if ($rel === '') {
                    $state['copy']['index']++;
                    continue;
                }
                $src  = rgm_safe_join($root, $rel);
                $dest = rgm_safe_join(WP_CONTENT_DIR, $rel);
                if (!$src || !$dest || !is_file($src)) {
                    $state['copy']['index']++;
                    continue;
                }
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    wp_mkdir_p($parent);
                }
                if (!@copy($src, $dest)) {
                    RGM_Store::log('Could not copy ' . $rel);
                } else {
                    $state['copy']['copied']++;
                }
                $state['copy']['current'] = $rel;
                $state['copy']['index']++;
            }
            $done = feof($fh);
            fclose($fh);
            if ($done) {
                $state['copy']['done'] = true;
                RGM_Store::log('Copied ' . (int) $state['copy']['copied'] . ' files into wp-content.');
            }
            return $state;
        } catch (Exception $e) {
            $state['phase'] = 'error';
            $state['error'] = $e->getMessage();
            RGM_Store::log('ERROR: ' . $e->getMessage());
            return $state;
        }
    }

    /**
     * @param string              $root     Source wp-content.
     * @param string              $manifest Manifest path.
     * @param array<string,mixed> $state    State.
     * @return int
     */
    private static function build_manifest($root, $manifest, &$state) {
        $options = isset($state['options']) && is_array($state['options']) ? $state['options'] : array();
        $fh      = fopen($manifest, 'wb');
        $count   = 0;
        if (!$fh) {
            throw new RuntimeException('Cannot write the file manifest.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $base = rtrim(str_replace('\\', '/', $root), '/');
            if (strpos($full, $base . '/') !== 0) {
                continue;
            }
            $rel = substr($full, strlen($base) + 1);
            if (self::skip_rel($rel, $options)) {
                continue;
            }
            if (rgm_starts_with($rel, 'mu-plugins/') && empty($options['copy_mu_plugins'])) {
                $held = rgm_safe_join(RGM_Store::work() . '/held/mu-plugins', substr($rel, strlen('mu-plugins/')));
                if ($held) {
                    $parent = dirname($held);
                    if (!is_dir($parent)) {
                        wp_mkdir_p($parent);
                    }
                    @copy($full, $held);
                    if (count($state['held']['mu-plugins']) < 200) {
                        $state['held']['mu-plugins'][] = $rel;
                    }
                }
                continue;
            }
            if (self::is_dropin($rel) && !empty($options['skip_dropins'])) {
                $held = rgm_safe_join(RGM_Store::work() . '/held/dropins', $rel);
                if ($held) {
                    $parent = dirname($held);
                    if (!is_dir($parent)) {
                        wp_mkdir_p($parent);
                    }
                    @copy($full, $held);
                    $state['held']['dropins'][] = $rel;
                }
                continue;
            }
            fwrite($fh, $rel . "\n");
            $count++;
        }
        fclose($fh);
        return $count;
    }

    /**
     * @param string $source Extracted backup root.
     * @return void
     */
    private static function stage_special_files($source) {
        $map = array(
            '/sqldump.sql'                 => '/sqldump.sql',
            '/meta.json'                   => '/meta.json',
            '/files/.htaccess.wpt-backup'  => '/htaccess.original',
            '/files/.htaccess'             => '/htaccess.original',
            '/files/wp-config.php'         => '/wp-config.original.php',
        );
        foreach ($map as $from => $to) {
            $src = rtrim($source, '/') . $from;
            if (is_file($src)) {
                @copy($src, RGM_Store::work() . $to);
            }
        }
    }

    /**
     * @param string              $rel     Relative path.
     * @param array<string,mixed> $options Options.
     * @return bool
     */
    private static function skip_rel($rel, $options) {
        $prefixes = array(
            'cache/',
            'et-cache/',
            'litespeed/',
            'wflogs/',
            'upgrade/',
            'upgrade-temp-backup/',
        );
        if (!empty($options['skip_updraft'])) {
            $prefixes[] = 'updraft/';
            $prefixes[] = 'ai1wm-backups/';
            $prefixes[] = 'wpvividbackups/';
            $prefixes[] = 'backups-dup-lite/';
            $prefixes[] = 'uploads/backupbuddy_backups/';
        }
        foreach ($prefixes as $prefix) {
            if (rgm_starts_with($rel, $prefix)) {
                return true;
            }
        }
        if (rgm_starts_with($rel, 'plugins/researchgr-migrator/')) {
            return true;
        }
        return $rel === 'debug.log';
    }

    /**
     * @param string $rel Relative path.
     * @return bool
     */
    private static function is_dropin($rel) {
        return in_array($rel, array(
            'advanced-cache.php',
            'object-cache.php',
            'db.php',
            'db-error.php',
            'install.php',
            'sunrise.php',
        ), true);
    }

    /**
     * @param string $base_path URL path such as /researchgr or empty.
     * @return bool
     */
    public static function write_htaccess($base_path) {
        $base_path = trim((string) $base_path);
        if ($base_path === '/' || $base_path === '.') {
            $base_path = '';
        }
        $base_path = '/' . trim($base_path, '/');
        if ($base_path === '/') {
            $base_path = '';
        }
        $rewrite_base = $base_path === '' ? '/' : $base_path . '/';
        $index_rule   = $base_path === '' ? '/index.php' : $base_path . '/index.php';
        $rules        = "# BEGIN WordPress\n";
        $rules       .= "# Generated by Research GR Migrator on " . gmdate('Y-m-d H:i:s') . " UTC\n";
        $rules       .= "<IfModule mod_rewrite.c>\n";
        $rules       .= "RewriteEngine On\n";
        $rules       .= "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
        $rules       .= 'RewriteBase ' . $rewrite_base . "\n";
        $rules       .= "RewriteRule ^index\\.php$ - [L]\n";
        $rules       .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
        $rules       .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
        $rules       .= 'RewriteRule . ' . $index_rule . " [L]\n";
        $rules       .= "</IfModule>\n";
        $rules       .= "# END WordPress\n";

        $target = ABSPATH . '.htaccess';
        if (is_file($target)) {
            @copy($target, RGM_Store::work() . '/htaccess.before-' . gmdate('Ymd-His'));
        }
        $ok = file_put_contents($target, $rules);
        if ($ok === false) {
            RGM_Store::log('WARNING: could not write .htaccess. Permalinks may 404 until it is writable.');
            return false;
        }
        RGM_Store::log('Wrote .htaccess with RewriteBase ' . $rewrite_base);
        return true;
    }

    /**
     * @param array<string,mixed> $state State.
     * @return void
     */
    public static function finalize($state) {
        $path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        self::write_htaccess($path);

        delete_option('rewrite_rules');
        flush_rewrite_rules(true);

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

        $upload_path = get_option('upload_path');
        if (is_string($upload_path) && $upload_path !== '' && (rgm_starts_with($upload_path, '/home/') || rgm_starts_with($upload_path, '/var/'))) {
            update_option('upload_path', '');
            RGM_Store::log('Cleared an absolute upload_path left over from the old server.');
        }

        if (!empty($state['options']['discourage_search'])) {
            update_option('blog_public', '0');
            RGM_Store::log('Search engines are discouraged while this copy is on the staging URL.');
        }

        $report = array(
            'siteurl'   => get_option('siteurl'),
            'home'      => get_option('home'),
            'theme'     => get_option('template'),
            'files'     => isset($state['mode']) && $state['mode'] === 'folder' ? (int) $state['copy']['copied'] : (int) $state['extract']['files'],
            'replaced'  => (int) $state['replace']['changed'],
            'sql_errors'=> (int) $state['import']['errors'],
            'prefix'    => $state['new_prefix'],
            'old_prefix'=> $state['old_prefix'],
        );
        $state_report = $report;
        RGM_Store::log('Restore finished. siteurl=' . $report['siteurl'] . ' home=' . $report['home']);
        $saved = RGM_Store::state();
        $saved['report'] = $state_report;
        $saved['phase']  = 'done';
        RGM_Store::save($saved);
    }

    /**
     * @return void
     */
    public static function install_guard() {
        $dir = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $theme = rgm_fallback_theme();
        $flag  = array(
            'on'     => true,
            'plugin' => RGM_BASENAME,
            'theme'  => $theme,
            'since'  => time(),
        );
        $flag_dir = WP_CONTENT_DIR . '/uploads/researchgr-migrator';
        if (!is_dir($flag_dir)) {
            wp_mkdir_p($flag_dir);
        }
        file_put_contents($flag_dir . '/safe-mode.flag', wp_json_encode($flag));
        $guard = $dir . '/rgm-guard.php';
        $php   = <<<'PHP'
<?php
/**
 * Emergency safe mode installed by Research GR Migrator.
 * Delete this file after the migration is finished.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rgm_guard_flag')) {
    /**
     * @return array<string,mixed>|null
     */
    function rgm_guard_flag() {
        $path = WP_CONTENT_DIR . '/uploads/researchgr-migrator/safe-mode.flag';
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : array('on' => true);
    }
}

add_filter('option_active_plugins', static function ($plugins) {
    $flag = rgm_guard_flag();
    if (!$flag) {
        return $plugins;
    }
    $ours = !empty($flag['plugin']) ? (string) $flag['plugin'] : 'researchgr-migrator/researchgr-migrator.php';
    return array($ours);
});

add_filter('pre_option_template', static function ($pre) {
    $flag = rgm_guard_flag();
    if ($flag && !empty($flag['theme'])) {
        return (string) $flag['theme'];
    }
    return $pre;
});

add_filter('pre_option_stylesheet', static function ($pre) {
    $flag = rgm_guard_flag();
    if ($flag && !empty($flag['theme'])) {
        return (string) $flag['theme'];
    }
    return $pre;
});
PHP;
        file_put_contents($guard, $php);
        if (!is_file($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * @return bool
     */
    public static function safe_mode_on() {
        return is_file(WP_CONTENT_DIR . '/uploads/researchgr-migrator/safe-mode.flag');
    }

    /**
     * @return array<string,mixed>
     */
    public static function plugin_health() {
        $saved = get_option('rgm_saved_active_plugins', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $present = array();
        $missing = array();
        foreach ($saved as $plugin) {
            if (!is_string($plugin) || $plugin === RGM_BASENAME) {
                continue;
            }
            if (is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
                $present[] = $plugin;
            } else {
                $missing[] = $plugin;
            }
        }
        global $wpdb;
        $theme = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'template' LIMIT 1");
        return array(
            'present' => $present,
            'missing' => $missing,
            'theme'   => $theme,
            'theme_ok'=> $theme ? is_dir(WP_CONTENT_DIR . '/themes/' . $theme) : false,
            'safe'    => self::safe_mode_on(),
        );
    }

    /**
     * @return void
     */
    public static function exit_safe_mode() {
        $health = self::plugin_health();
        $active = $health['present'];
        if (!in_array(RGM_BASENAME, $active, true)) {
            $active[] = RGM_BASENAME;
        }
        update_option('active_plugins', $active);
        $flag = WP_CONTENT_DIR . '/uploads/researchgr-migrator/safe-mode.flag';
        if (is_file($flag)) {
            unlink($flag);
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('active_plugins', 'options');
        RGM_Store::log('Safe mode off. Reactivated ' . count($health['present']) . ' plugins from the backup.');
    }

    /**
     * @param string $target Production URL.
     * @return void
     */
    public static function write_staging_redirect($target) {
        $dir = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $target = rgm_trim_url($target);
        $path   = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        $path   = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '';
        }
        $php = "<?php\n";
        $php .= "/**\n * Sends the temporary staging host to the production domain.\n * Installed by Research GR Migrator. Delete after cutover.\n */\n\n";
        $php .= "if (!defined('ABSPATH')) {\n    exit;\n}\n\n";
        $php .= "add_action('template_redirect', static function () {\n";
        $php .= "    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {\n        return;\n    }\n";
        $php .= "    \$host = isset(\$_SERVER['HTTP_HOST']) ? strtolower((string) \$_SERVER['HTTP_HOST']) : '';\n";
        $php .= "    if (strpos(\$host, 'kourtsidis.com') === false) {\n        return;\n    }\n";
        $php .= "    \$uri = isset(\$_SERVER['REQUEST_URI']) ? (string) \$_SERVER['REQUEST_URI'] : '/';\n";
        $php .= '    $prefix = ' . var_export($path, true) . ";\n";
        $php .= "    if (\$prefix !== '' && strpos(\$uri, \$prefix) === 0) {\n        \$uri = substr(\$uri, strlen(\$prefix));\n        if (\$uri === '' || \$uri[0] !== '/') {\n            \$uri = '/' . ltrim(\$uri, '/');\n        }\n    }\n";
        $php .= '    wp_redirect(' . var_export($target, true) . " . \$uri, 301);\n";
        $php .= "    exit;\n";
        $php .= "}, 0);\n";
        file_put_contents($dir . '/rgm-staging-redirect.php', $php);
        RGM_Store::log('Installed a redirect from the staging host to ' . $target . '.');
    }
}
