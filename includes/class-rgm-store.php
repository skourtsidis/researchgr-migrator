<?php
/**
 * Private working directories, job state and the log.
 *
 * Sensitive files live in an unguessable directory, preferably beside
 * the web root, and are also blocked with .htaccess.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Store {
    /**
     * @return string
     */
    public static function token() {
        $token = get_option('rgm_storage_token');
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Exception $e) {
                $token = md5(uniqid('rgm', true) . wp_rand());
            }
            update_option('rgm_storage_token', $token, false);
        }
        return $token;
    }

    /**
     * Directory that must not be web-reachable.
     *
     * @return string
     */
    public static function private_root() {
        $name   = 'rgm-private-' . self::token();
        $parent = dirname(ABSPATH);
        $prefer = $parent . '/' . $name;
        if (self::dir_is_usable($parent) && self::dir_is_usable(dirname($prefer))) {
            $test = $parent . '/.rgm-write-test-' . self::token();
            if (@file_put_contents($test, 'ok') !== false) {
                @unlink($test);
                return $prefer;
            }
        }
        return WP_CONTENT_DIR . '/' . $name;
    }

    /**
     * Public-ish inbox. The tar.gz is large; uploads land here.
     * Access is denied by .htaccess. The directory name is not secret,
     * so the archive should be deleted after a successful restore.
     *
     * @return string
     */
    public static function inbox() {
        return self::private_root() . '/inbox';
    }

    /**
     * @return string
     */
    public static function work() {
        return self::private_root() . '/work';
    }

    /**
     * @return string
     */
    public static function state_path() {
        return self::work() . '/state.json';
    }

    /**
     * @return string
     */
    public static function log_path() {
        return self::work() . '/restore.log';
    }

    /**
     * @param string $dir Directory.
     * @return bool
     */
    private static function dir_is_usable($dir) {
        return is_string($dir) && $dir !== '' && $dir !== '/' && (is_dir($dir) || @mkdir($dir, 0755, true));
    }

    /**
     * @return void
     */
    public static function ensure() {
        $dirs = array(self::private_root(), self::inbox(), self::work(), self::work() . '/held');
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            self::protect($dir);
        }
        $index = self::private_root() . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * @param string $dir Directory.
     * @return void
     */
    public static function protect($dir) {
        $htaccess = $dir . '/.htaccess';
        $rules    = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, $rules);
        }
        $index = $dir . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function blank_state() {
        global $wpdb;
        return array(
            'version'    => RGM_VERSION,
            'phase'      => 'idle',
            'mode'       => '',
            'source_id'  => '',
            'source_path'=> '',
            'sql_file'   => '',
            'source_url' => '',
            'home_url'   => '',
            'target_url' => rgm_trim_url(home_url()),
            'old_prefix' => '',
            'new_prefix' => $wpdb->prefix,
            'old_path'   => '',
            'error'      => '',
            'db_replaced'=> false,
            'is_rollback'=> false,
            'created'    => time(),
            'updated'    => time(),
            'options'    => array(
                'skip_cache'          => true,
                'skip_updraft'        => true,
                'skip_dropins'        => true,
                'copy_mu_plugins'     => false,
                'discourage_search'   => true,
                'replace_guids'       => false,
                'replace_paths'       => true,
            ),
            'snapshot'   => array('offset' => 0, 'tables' => array(), 'table_index' => 0, 'row_offset' => 0, 'done' => false),
            'extract'    => array('offset' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => 0, 'done' => false, 'current' => '', 'partial' => null, 'long_name' => null),
            'copy'       => array('index' => 0, 'copied' => 0, 'total' => 0, 'done' => false, 'current' => ''),
            'import'     => array('offset' => 0, 'statements' => 0, 'errors' => 0, 'done' => false, 'current' => ''),
            'replace'    => array('table_index' => 0, 'last_pk' => 0, 'offset' => 0, 'changed' => 0, 'scanned' => 0, 'done' => false, 'current' => ''),
            'held'       => array('mu-plugins' => array(), 'dropins' => array()),
            'report'     => array(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function state() {
        self::ensure();
        $path = self::state_path();
        if (!is_file($path)) {
            return self::blank_state();
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return self::blank_state();
        }
        return array_replace_recursive(self::blank_state(), $data);
    }

    /**
     * @param array<string,mixed> $state State.
     * @return void
     */
    public static function save($state) {
        self::ensure();
        $state['updated'] = time();
        $json = wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $path = self::state_path();
        $tmp  = $path . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    /**
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    public static function public_state($state) {
        unset($state['source_path']);
        if (isset($state['extract']['partial']['dest'])) {
            unset($state['extract']['partial']['dest']);
        }
        return $state;
    }

    /**
     * @param string $message Message.
     * @return void
     */
    public static function log($message) {
        self::ensure();
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . "\n";
        file_put_contents(self::log_path(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param int $lines Lines.
     * @return string
     */
    public static function tail($lines = 80) {
        $path = self::log_path();
        if (!is_file($path)) {
            return '';
        }
        $size = filesize($path);
        if ($size === false) {
            return '';
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        $read = (int) min($size, 64000);
        if ($read > 0) {
            fseek($fh, -$read, SEEK_END);
        }
        $data = (string) fread($fh, $read);
        fclose($fh);
        $all = preg_split("/\r\n|\n|\r/", trim($data));
        if (!is_array($all)) {
            return '';
        }
        $all = array_slice($all, -abs((int) $lines));
        return implode("\n", $all);
    }

    /**
     * @param string $name Lock name.
     * @return bool
     */
    public static function lock($name = 'run') {
        self::ensure();
        $path = self::work() . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.lock';
        if (is_file($path) && (time() - (int) filemtime($path)) < 25) {
            return false;
        }
        file_put_contents($path, (string) time());
        return true;
    }

    /**
     * @param string $name Lock name.
     * @return void
     */
    public static function unlock($name = 'run') {
        $path = self::work() . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.lock';
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return void
     */
    public static function reset() {
        self::ensure();
        $state = self::state_path();
        if (is_file($state)) {
            unlink($state);
        }
        self::unlock('run');
    }
}
