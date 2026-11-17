<?php
/**
 * Snapshot, stream-import and session recovery.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_SQL {
    /**
     * @param string $path SQL file.
     * @return array{siteurl:string,home:string,prefix:string,old_path:string,tables:int}
     */
    public static function detect($path) {
        $found = array(
            'siteurl'  => '',
            'home'     => '',
            'prefix'   => '',
            'old_path' => '',
            'tables'   => 0,
        );
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return $found;
        }
        $carry = '';
        $bytes = 0;
        while (!feof($fh) && $bytes < 80 * 1024 * 1024) {
            $chunk = fread($fh, 1024 * 1024);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            $data   = $carry . $chunk;
            if ($found['siteurl'] === '' && preg_match('/,\s*\'siteurl\'\s*,\s*\'([^\']+)\'/', $data, $m)) {
                $found['siteurl'] = rgm_trim_url(str_replace('\\/', '/', $m[1]));
            }
            if ($found['home'] === '' && preg_match('/,\s*\'home\'\s*,\s*\'([^\']+)\'/', $data, $m)) {
                $found['home'] = rgm_trim_url(str_replace('\\/', '/', $m[1]));
            }
            if ($found['prefix'] === '' && preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `([A-Za-z0-9]+_)options`/i', $data, $m)) {
                $found['prefix'] = $m[1];
            }
            if ($found['old_path'] === '' && preg_match('#((?:/home|/var/www|/var/www/vhosts)/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*)/wp-content/#', $data, $m)) {
                $found['old_path'] = $m[1];
            }
            $found['tables'] += preg_match_all('/CREATE TABLE /i', $data);
            $carry = substr($data, -400);
            if ($found['siteurl'] !== '' && $found['home'] !== '' && $found['prefix'] !== '' && $found['old_path'] !== '' && $bytes > 4 * 1024 * 1024) {
                break;
            }
        }
        fclose($fh);
        return $found;
    }

    /**
     * @param string $meta_path meta.json path.
     * @return array<string,mixed>
     */
    public static function read_meta($meta_path) {
        if (!is_file($meta_path)) {
            return array();
        }
        $raw  = (string) file_get_contents($meta_path);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : array();
    }

    /**
     * @param array<string,mixed> $state  State.
     * @param int                 $budget Seconds.
     * @return array<string,mixed>
     */
    public static function snapshot_slice($state, $budget = 12) {
        global $wpdb;
        try {
            $start = microtime(true);
            $file  = RGM_Store::work() . '/snapshot-before.sql';
            $snap  = $state['snapshot'];
            if (empty($snap['tables'])) {
                $like   = $wpdb->esc_like($wpdb->prefix) . '%';
                $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
                $snap['tables'] = is_array($tables) ? array_values($tables) : array();
                $fh = fopen($file, 'wb');
                if (!$fh) {
                    throw new RuntimeException('Cannot write the safety snapshot.');
                }
                fwrite($fh, "-- Research GR Migrator safety snapshot\n");
                fwrite($fh, '-- Created: ' . gmdate('c') . "\n\n");
                fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
                fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
                fclose($fh);
                RGM_Store::log('Writing a safety snapshot of the current database (' . count($snap['tables']) . ' tables).');
            }
            $fh = fopen($file, 'ab');
            if (!$fh) {
                throw new RuntimeException('Cannot append to the safety snapshot.');
            }
            while ($snap['table_index'] < count($snap['tables']) && (microtime(true) - $start) < $budget) {
                $table = $snap['tables'][$snap['table_index']];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                    $snap['table_index']++;
                    $snap['row_offset'] = 0;
                    continue;
                }
                if ((int) $snap['row_offset'] === 0) {
                    $create = $wpdb->get_row('SHOW CREATE TABLE `' . $table . '`', ARRAY_N);
                    if (!$create || empty($create[1])) {
                        $snap['table_index']++;
                        continue;
                    }
                    fwrite($fh, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
                    fwrite($fh, $create[1] . ";\n");
                }
                $rows = $wpdb->get_results('SELECT * FROM `' . $table . '` LIMIT 200 OFFSET ' . (int) $snap['row_offset'], ARRAY_A);
                if (!$rows) {
                    fwrite($fh, "\n");
                    $snap['table_index']++;
                    $snap['row_offset'] = 0;
                    continue;
                }
                foreach ($rows as $row) {
                    $cols = array();
                    $vals = array();
                    foreach ($row as $col => $value) {
                        $cols[] = '`' . $col . '`';
                        if ($value === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = "'" . esc_sql((string) $value) . "'";
                        }
                    }
                    fwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                }
                $snap['row_offset'] += count($rows);
                if (count($rows) < 200) {
                    fwrite($fh, "\n");
                    $snap['table_index']++;
                    $snap['row_offset'] = 0;
                }
            }
            fclose($fh);
            $snap['done']      = $snap['table_index'] >= count($snap['tables']);
            $state['snapshot'] = $snap;
            $state['sql_snapshot'] = $file;
            if ($snap['done']) {
                RGM_Store::log('Safety snapshot saved (' . rgm_human_bytes((int) filesize($file)) . ').');
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
     * @param array<string,mixed> $state  State.
     * @param int                 $budget Seconds.
     * @return array<string,mixed>
     */
    public static function import_slice($state, $budget = 12) {
        global $wpdb;
        $path = isset($state['sql_file']) ? (string) $state['sql_file'] : '';
        if (!is_file($path)) {
            $state['phase'] = 'error';
            $state['error'] = 'SQL dump not found.';
            RGM_Store::log('ERROR: SQL dump not found at the expected path.');
            return $state;
        }

        $wpdb->suppress_errors(true);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $wpdb->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        $wpdb->query('SET NAMES utf8mb4');

        $start  = microtime(true);
        $fh     = fopen($path, 'rb');
        if (!$fh) {
            $state['phase'] = 'error';
            $state['error'] = 'Cannot read the SQL dump.';
            return $state;
        }
        $offset = (int) $state['import']['offset'];
        if ($offset > 0) {
            fseek($fh, $offset);
        }
        $stream = new RGM_SQL_Stream($fh, $offset);

        $old = (string) $state['old_prefix'];
        $new = (string) $state['new_prefix'];
        if ($old === '') {
            $old = $new;
        }

        if ((int) $state['import']['statements'] === 0) {
            RGM_Store::log('Importing database. Table prefix ' . $old . ' → ' . $new . '.');
        }

        while ((microtime(true) - $start) < $budget) {
            $stmt = $stream->next_statement();
            if ($stmt === null) {
                $state['import']['done']   = true;
                $state['import']['offset'] = $stream->file_pos();
                fclose($fh);
                RGM_Store::log('Database import finished. Statements: ' . (int) $state['import']['statements'] . ', errors: ' . (int) $state['import']['errors'] . '.');
                return $state;
            }
            $prepared = self::prepare_statement($stmt, $old, $new);
            if ($prepared === '') {
                continue;
            }
            $parts = self::split_insert($prepared, 500000);
            foreach ($parts as $part) {
                $ok = $wpdb->query($part);
                if ($ok === false && $wpdb->last_error) {
                    $retry = preg_replace('/utf8mb4_unicode_520_ci/i', 'utf8mb4_unicode_ci', $part);
                    $retry = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', (string) $retry);
                    $ok    = $wpdb->query((string) $retry);
                }
                if ($ok === false && $wpdb->last_error) {
                    $state['import']['errors']++;
                    $preview = substr(preg_replace('/\s+/', ' ', $part), 0, 220);
                    RGM_Store::log('SQL error: ' . $wpdb->last_error . ' | ' . $preview);
                    if ((int) $state['import']['errors'] > 40) {
                        fclose($fh);
                        $state['phase'] = 'error';
                        $state['error'] = 'Too many SQL errors. Import stopped. Use Rollback if the staging site no longer loads.';
                        $state['import']['offset'] = $stream->file_pos();
                        return $state;
                    }
                } else {
                    $state['import']['statements']++;
                }
            }
            $state['import']['current'] = substr(preg_replace('/\s+/', ' ', $prepared), 0, 90);
        }

        $state['import']['offset'] = $stream->file_pos();
        fclose($fh);
        return $state;
    }

    /**
     * Keep the current administrator logged in after the database swap.
     *
     * @param array<string,mixed> $state State.
     * @return void
     */
    public static function restore_access($state) {
        global $wpdb;

        $recovery_path = RGM_Store::work() . '/recovery.json';
        if (!is_file($recovery_path)) {
            RGM_Store::log('WARNING: recovery file missing. You may need the old site password to log in.');
            return;
        }
        $recovery = json_decode((string) file_get_contents($recovery_path), true);
        if (!is_array($recovery) || empty($recovery['user_login']) || empty($recovery['user_pass'])) {
            RGM_Store::log('WARNING: recovery file is unreadable.');
            return;
        }

        $login = (string) $recovery['user_login'];
        $user  = $wpdb->get_row($wpdb->prepare("SELECT ID, user_login FROM {$wpdb->users} WHERE user_login = %s", $login));
        if ($user) {
            $user_id = (int) $user->ID;
            $wpdb->update(
                $wpdb->users,
                array(
                    'user_pass'    => (string) $recovery['user_pass'],
                    'user_email'   => (string) $recovery['user_email'],
                    'display_name' => (string) $recovery['display_name'],
                ),
                array('ID' => $user_id)
            );
        } else {
            $wpdb->insert(
                $wpdb->users,
                array(
                    'user_login'      => $login,
                    'user_pass'       => (string) $recovery['user_pass'],
                    'user_nicename'   => (string) $recovery['user_nicename'],
                    'user_email'      => (string) $recovery['user_email'],
                    'user_registered' => current_time('mysql'),
                    'display_name'    => (string) $recovery['display_name'],
                )
            );
            $user_id = (int) $wpdb->insert_id;
        }

        $saved = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins'));
        if (is_string($saved) && $saved !== '') {
            update_option('rgm_saved_active_plugins', maybe_unserialize($saved), false);
        }

        if ($state['old_prefix'] !== '' && $state['old_prefix'] !== $state['new_prefix']) {
            self::rewrite_prefix_keys((string) $state['old_prefix'], (string) $state['new_prefix']);
        }

        if ($user_id > 0) {
            $wp_user = new WP_User($user_id);
            $wp_user->set_role('administrator');
            if (!empty($recovery['token'])) {
                $verifier = hash('sha256', (string) $recovery['token']);
                $sessions = array(
                    $verifier => array(
                        'expiration' => (int) $recovery['expiration'],
                        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                        'ua'         => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                        'login'      => time(),
                    ),
                );
                update_user_meta($user_id, 'session_tokens', $sessions);
            }
            RGM_Store::log('Kept administrator "' . $login . '" able to log in with the current password.');
        }

        $ours = array(RGM_BASENAME);
        update_option('active_plugins', $ours);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('active_plugins', 'options');

        $target = rgm_trim_url((string) $state['target_url']);
        if ($target !== '') {
            update_option('siteurl', $target);
            update_option('home', $target);
        }

        RGM_Files::install_guard();
        RGM_Store::log('Safe mode is on. Only Research GR Migrator will load until you exit safe mode.');
    }

    /**
     * @param string $old Old prefix.
     * @param string $new New prefix.
     * @return void
     */
    public static function rewrite_prefix_keys($old, $new) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s",
            $old,
            $new,
            $wpdb->esc_like($old) . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
            $old,
            $new,
            $wpdb->esc_like($old) . '%'
        ));
        RGM_Store::log('Rewrote usermeta and option names from prefix ' . $old . ' to ' . $new . '.');
    }

    /**
     * @param string $sql        Statement.
     * @param string $old_prefix Old prefix.
     * @param string $new_prefix New prefix.
     * @return string
     */
    private static function prepare_statement($sql, $old_prefix, $new_prefix) {
        $bare = self::strip_leading_comments($sql);
        if ($bare === '') {
            return '';
        }
        if (preg_match('/^(LOCK TABLES|UNLOCK TABLES)\b/i', $bare)) {
            return '';
        }
        if (preg_match('/^(CREATE DATABASE|DROP DATABASE|USE )\b/i', $bare)) {
            return '';
        }
        if (preg_match('/^DELIMITER\b/i', $bare)) {
            return '';
        }
        $sql = preg_replace('/DEFINER\s*=\s*(`[^`]*`@`[^`]*`|\'[^\']*\'@\'[^\']*\'|[^\s]+)/i', '', $sql);
        $sql = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', (string) $sql);
        if ($old_prefix !== '' && $old_prefix !== $new_prefix) {
            $sql = str_replace('`' . $old_prefix, '`' . $new_prefix, (string) $sql);
        }
        return trim((string) $sql);
    }

    /**
     * @param string $sql SQL.
     * @return string
     */
    private static function strip_leading_comments($sql) {
        $sql = ltrim($sql);
        while ($sql !== '') {
            if (rgm_starts_with($sql, '--') || rgm_starts_with($sql, '#')) {
                $nl = strpos($sql, "\n");
                if ($nl === false) {
                    return '';
                }
                $sql = ltrim(substr($sql, $nl + 1));
                continue;
            }
            if (rgm_starts_with($sql, '/*') && !rgm_starts_with($sql, '/*!')) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return '';
                }
                $sql = ltrim(substr($sql, $end + 2));
                continue;
            }
            break;
        }
        return trim($sql);
    }

    /**
     * @param string $sql      Statement.
     * @param int    $max_bytes Max chunk size.
     * @return array<int,string>
     */
    public static function split_insert($sql, $max_bytes) {
        $trim = ltrim($sql);
        if (strlen($trim) <= $max_bytes) {
            return array($trim);
        }
        if (!preg_match('/^(INSERT\s+(?:IGNORE\s+)?INTO\s+.+?\s+VALUES\s*)/is', $trim, $m)) {
            return array($trim);
        }
        $prefix = $m[1];
        $values = rtrim(substr($trim, strlen($prefix)));
        if (substr($values, -1) === ';') {
            $values = substr($values, 0, -1);
        }
        $tuples = self::split_tuples($values);
        if (count($tuples) < 2) {
            return array($trim);
        }
        $chunks  = array();
        $current = array();
        $size    = strlen($prefix) + 1;
        foreach ($tuples as $tuple) {
            $len = strlen($tuple) + 1;
            if ($current && ($size + $len) > $max_bytes) {
                $chunks[] = rtrim($prefix) . ' ' . implode(',', $current);
                $current  = array();
                $size     = strlen($prefix) + 1;
            }
            $current[] = $tuple;
            $size     += $len;
        }
        if ($current) {
            $chunks[] = rtrim($prefix) . ' ' . implode(',', $current);
        }
        return $chunks;
    }

    /**
     * @param string $values VALUES payload.
     * @return array<int,string>
     */
    private static function split_tuples($values) {
        $tuples = array();
        $len    = strlen($values);
        $start  = 0;
        $depth  = 0;
        $in     = false;
        $quote  = '';
        $esc    = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $values[$i];
            if ($in) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($c === '\\') {
                    $esc = true;
                    continue;
                }
                if ($c === $quote) {
                    if ($i + 1 < $len && $values[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $in = false;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $in    = true;
                $quote = $c;
                continue;
            }
            if ($c === '(') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }
            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = substr($values, $start, $i - $start + 1);
                }
            }
        }
        return $tuples;
    }
}

/**
 * Incremental SQL statement reader.
 */
class RGM_SQL_Stream {
    /**
     * @var resource
     */
    private $fh;

    /**
     * @var string
     */
    private $buf = '';

    /**
     * @var int
     */
    private $buf_file_pos = 0;

    /**
     * @var int
     */
    private $consumed = 0;

    /**
     * @var bool
     */
    private $eof = false;

    /**
     * @param resource $fh     File handle.
     * @param int      $offset Starting offset.
     */
    public function __construct($fh, $offset) {
        $this->fh            = $fh;
        $this->buf_file_pos  = (int) $offset;
    }

    /**
     * @return int
     */
    public function file_pos() {
        return $this->buf_file_pos + $this->consumed;
    }

    /**
     * @return string|null
     */
    public function next_statement() {
        while (true) {
            $stmt = $this->extract();
            if ($stmt !== null) {
                return $stmt;
            }
            if ($this->eof) {
                $rest = trim(substr($this->buf, $this->consumed));
                $this->consumed = strlen($this->buf);
                return $rest === '' ? null : $rest;
            }
            $chunk = fread($this->fh, 262144);
            if ($chunk === '' || $chunk === false) {
                $this->eof = true;
                continue;
            }
            if ($this->consumed > 0) {
                $this->buf           = substr($this->buf, $this->consumed);
                $this->buf_file_pos += $this->consumed;
                $this->consumed      = 0;
            }
            $this->buf .= $chunk;
        }
    }

    /**
     * @return string|null
     */
    private function extract() {
        $len   = strlen($this->buf);
        $in    = false;
        $quote = '';
        $esc   = false;
        for ($i = $this->consumed; $i < $len; $i++) {
            $c = $this->buf[$i];
            if ($in) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($c === '\\') {
                    $esc = true;
                    continue;
                }
                if ($c === $quote) {
                    if ($i + 1 < $len && $this->buf[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $in = false;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $in    = true;
                $quote = $c;
                continue;
            }
            if ($c === ';') {
                $stmt           = substr($this->buf, $this->consumed, $i - $this->consumed);
                $this->consumed = $i + 1;
                return $stmt;
            }
        }
        return null;
    }
}
