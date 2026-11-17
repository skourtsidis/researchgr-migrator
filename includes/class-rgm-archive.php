<?php
/**
 * Pure-PHP reader for WP Toolkit tar.gz backups.
 *
 * Archive layout produced by cPanel / FastComet WP Toolkit:
 *   files/...WordPress tree...
 *   files/.htaccess.wpt-backup
 *   meta.json
 *   sqldump.sql
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Archive {
    const BLOCK = 512;

    /**
     * @param array<string,mixed> $state  State.
     * @param int                 $budget Seconds.
     * @return array<string,mixed>
     */
    public static function extract_slice($state, $budget = 12) {
        try {
            return self::extract_inner($state, $budget);
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
     * @throws RuntimeException On a corrupt archive or unwritable path.
     */
    private static function extract_inner($state, $budget) {
        $archive = isset($state['source_path']) ? (string) $state['source_path'] : '';
        if (!is_file($archive)) {
            throw new RuntimeException('Archive not found. Upload the .tar.gz again or choose a file already in the inbox.');
        }
        if (!function_exists('gzopen')) {
            throw new RuntimeException('PHP zlib (gzopen) is not available on this host.');
        }

        $start = microtime(true);
        $fh    = @gzopen($archive, 'rb');
        if (!$fh) {
            throw new RuntimeException('Could not open the .tar.gz archive.');
        }

        $offset = (int) $state['extract']['offset'];
        if ($offset > 0 && @gzseek($fh, $offset) !== 0) {
            gzclose($fh);
            throw new RuntimeException('Could not resume the archive at offset ' . $offset . '. Use an already-extracted folder instead.');
        }

        $partial   = isset($state['extract']['partial']) && is_array($state['extract']['partial']) ? $state['extract']['partial'] : null;
        $long_name = isset($state['extract']['long_name']) ? $state['extract']['long_name'] : null;
        $next_pax  = isset($state['extract']['next_pax']) && is_array($state['extract']['next_pax']) ? $state['extract']['next_pax'] : null;

        if ($partial) {
            $consumed = self::consume($fh, $partial, $start, $budget);
            $state['extract']['bytes'] += (int) $consumed['wrote'];
            $state['extract']['partial'] = $consumed['partial'];
            $state['extract']['offset']  = (int) gztell($fh);
            if ($consumed['partial']) {
                $state['extract']['current'] = (string) $partial['name'];
                gzclose($fh);
                return $state;
            }
            if (empty($partial['skip'])) {
                $state['extract']['files']++;
            } else {
                $state['extract']['skipped']++;
            }
        }

        while ((microtime(true) - $start) < $budget) {
            $block = self::read_block($fh);
            if ($block === null) {
                $state['extract']['done']    = true;
                $state['extract']['current'] = '';
                $state['extract']['offset']  = (int) gztell($fh);
                gzclose($fh);
                RGM_Store::log('Archive extraction finished. Files written: ' . (int) $state['extract']['files'] . ', skipped: ' . (int) $state['extract']['skipped'] . '.');
                return $state;
            }
            if ($block === '') {
                $state['extract']['done']    = true;
                $state['extract']['current'] = '';
                $state['extract']['offset']  = (int) gztell($fh);
                gzclose($fh);
                RGM_Store::log('Archive extraction finished. Files written: ' . (int) $state['extract']['files'] . ', skipped: ' . (int) $state['extract']['skipped'] . '.');
                return $state;
            }
            if ($block === false) {
                gzclose($fh);
                throw new RuntimeException('Truncated tar header. The archive may be incomplete.');
            }
            if (!self::checksum_ok($block)) {
                gzclose($fh);
                throw new RuntimeException('Tar checksum failed. This does not look like a valid WP Toolkit backup.');
            }

            $parsed = self::parse_header($block);
            $name   = $parsed['name'];
            $size   = $parsed['size'];
            $type   = $parsed['type'];

            if ($type === 'L') {
                $long_name = self::read_payload_string($fh, $size);
                continue;
            }
            if ($type === 'K') {
                self::read_payload_string($fh, $size);
                continue;
            }
            if ($type === 'x' || $type === 'g') {
                $body = self::read_payload_string($fh, $size);
                if ($type === 'x') {
                    $next_pax = self::parse_pax($body);
                }
                continue;
            }
            if ($type === 'S') {
                gzclose($fh);
                throw new RuntimeException('Sparse tar entries are not supported. Extract the archive on your computer and upload the folder.');
            }

            if (is_string($long_name) && $long_name !== '') {
                $name      = $long_name;
                $long_name = null;
            }
            if (is_array($next_pax)) {
                if (!empty($next_pax['path'])) {
                    $name = (string) $next_pax['path'];
                }
                if (isset($next_pax['size']) && $next_pax['size'] !== '') {
                    $size = (int) $next_pax['size'];
                }
                $next_pax = null;
            }

            $name = self::normalize_name($name);
            if ($name === '') {
                if ($size > 0) {
                    self::discard_now($fh, $size);
                }
                continue;
            }

            if ($type === '5') {
                if ($size > 0) {
                    self::discard_now($fh, $size);
                }
                continue;
            }
            if ($type === '2' || $type === '1') {
                RGM_Store::log('Skipped link: ' . $name);
                if ($size > 0) {
                    self::discard_now($fh, $size);
                }
                $state['extract']['skipped']++;
                continue;
            }

            $decision = self::decide($name, $state);
            $pad      = (int) ((self::BLOCK - ($size % self::BLOCK)) % self::BLOCK);
            $partial  = array(
                'name'      => $name,
                'dest'      => empty($decision['skip']) ? $decision['dest'] : '',
                'skip'      => !empty($decision['skip']),
                'remaining' => (int) $size,
                'pad'       => $pad,
                'append'    => false,
                'held'      => isset($decision['held']) ? $decision['held'] : '',
            );

            if (!$partial['skip']) {
                self::ensure_parent($partial['dest']);
                if (!empty($decision['held'])) {
                    self::remember_held($state, $decision['held'], $name);
                }
            }

            $consumed = self::consume($fh, $partial, $start, $budget);
            $state['extract']['bytes'] += (int) $consumed['wrote'];
            $state['extract']['current'] = $name;
            if ($consumed['partial']) {
                $state['extract']['partial']   = $consumed['partial'];
                $state['extract']['offset']    = (int) gztell($fh);
                $state['extract']['long_name'] = $long_name;
                $state['extract']['next_pax']  = $next_pax;
                gzclose($fh);
                return $state;
            }
            if ($partial['skip']) {
                $state['extract']['skipped']++;
            } else {
                $state['extract']['files']++;
                if ($name === 'sqldump.sql' || $name === 'meta.json' || rgm_ends_with($name, '/sqldump.sql')) {
                    RGM_Store::log('Extracted ' . $name);
                }
            }
            if (((int) $state['extract']['files'] % 250) === 0 && (int) $state['extract']['files'] > 0) {
                RGM_Store::log('Extracted ' . (int) $state['extract']['files'] . ' files so far. Current: ' . $name);
            }
        }

        $state['extract']['offset']    = (int) gztell($fh);
        $state['extract']['long_name'] = $long_name;
        $state['extract']['next_pax']  = $next_pax;
        $state['extract']['partial']   = null;
        gzclose($fh);
        return $state;
    }

    /**
     * @param resource            $fh      Gzip handle.
     * @param array<string,mixed> $partial Partial entry.
     * @param float               $start   Start time.
     * @param int                 $budget  Budget.
     * @return array{partial:?array,wrote:int}
     * @throws RuntimeException When the archive ends early or a file cannot be written.
     */
    private static function consume($fh, $partial, $start, $budget) {
        $wrote = 0;
        $out   = null;
        if (empty($partial['skip'])) {
            $mode = !empty($partial['append']) ? 'ab' : 'wb';
            $out  = @fopen((string) $partial['dest'], $mode);
            if (!$out) {
                throw new RuntimeException('Cannot write ' . $partial['dest'] . '. Check directory permissions.');
            }
            $partial['append'] = true;
        }
        try {
            while ((int) $partial['remaining'] > 0 && (microtime(true) - $start) < $budget) {
                $chunk_size = (int) min(262144, (int) $partial['remaining']);
                $data       = self::read_exact($fh, $chunk_size);
                if ($data === null) {
                    throw new RuntimeException('Unexpected end of archive while reading ' . $partial['name']);
                }
                if ($out) {
                    fwrite($out, $data);
                    $wrote += strlen($data);
                }
                $partial['remaining'] = (int) $partial['remaining'] - strlen($data);
            }
            if ((int) $partial['remaining'] > 0) {
                if ($out) {
                    fclose($out);
                    $out = null;
                }
                return array('partial' => $partial, 'wrote' => $wrote);
            }
            $pad = (int) $partial['pad'];
            if ($pad > 0) {
                $junk = self::read_exact($fh, $pad);
                if ($junk === null) {
                    throw new RuntimeException('Unexpected end of archive after ' . $partial['name']);
                }
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }
        if (empty($partial['skip'])) {
            @chmod((string) $partial['dest'], 0644);
        }
        return array('partial' => null, 'wrote' => $wrote);
    }

    /**
     * @param string              $name  Archive entry name.
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    private static function decide($name, $state) {
        $options = isset($state['options']) && is_array($state['options']) ? $state['options'] : array();
        $work    = RGM_Store::work();

        if ($name === 'sqldump.sql' || rgm_ends_with($name, '/sqldump.sql')) {
            return array('dest' => $work . '/sqldump.sql');
        }
        if ($name === 'meta.json' || rgm_ends_with($name, '/meta.json')) {
            return array('dest' => $work . '/meta.json');
        }

        $inner = $name;
        if (rgm_starts_with($name, 'files/')) {
            $inner = substr($name, 6);
        }

        if ($inner === '.htaccess.wpt-backup' || $inner === '.htaccess') {
            return array('dest' => $work . '/htaccess.original');
        }
        if ($inner === 'wp-config.php') {
            return array('dest' => $work . '/wp-config.original.php');
        }
        if (!rgm_starts_with($inner, 'wp-content/')) {
            return array('skip' => true);
        }

        $rel = substr($inner, strlen('wp-content/'));
        if ($rel === '' || strpos($rel, "\0") !== false || rgm_contains($rel, '../')) {
            return array('skip' => true);
        }

        if (self::rel_skipped($rel, $options)) {
            return array('skip' => true);
        }

        $held = '';
        if (rgm_starts_with($rel, 'mu-plugins/') && empty($options['copy_mu_plugins'])) {
            $held = 'mu-plugins';
            $dest = rgm_safe_join($work . '/held/mu-plugins', substr($rel, strlen('mu-plugins/')));
        } elseif (self::is_dropin($rel) && !empty($options['skip_dropins'])) {
            $held = 'dropins';
            $dest = rgm_safe_join($work . '/held/dropins', $rel);
        } else {
            $dest = rgm_safe_join(WP_CONTENT_DIR, $rel);
        }
        if (!$dest) {
            return array('skip' => true);
        }
        if (rgm_starts_with($rel, 'plugins/researchgr-migrator/')) {
            return array('skip' => true);
        }
        return array('dest' => $dest, 'held' => $held);
    }

    /**
     * @param string              $rel     Path relative to wp-content.
     * @param array<string,mixed> $options Options.
     * @return bool
     */
    private static function rel_skipped($rel, $options) {
        $prefixes = array(
            'cache/',
            'et-cache/',
            'litespeed/',
            'wflogs/',
            'upgrade/',
            'upgrade-temp-backup/',
            'uploads/researchgr-migrator/',
        );
        if (!empty($options['skip_updraft'])) {
            $prefixes[] = 'updraft/';
            $prefixes[] = 'ai1wm-backups/';
            $prefixes[] = 'wpvividbackups/';
            $prefixes[] = 'backups-dup-lite/';
            $prefixes[] = 'uploads/backupbuddy_backups/';
            $prefixes[] = 'uploads/wp-clone/';
            $prefixes[] = 'uploads/backup-guard/';
        }
        foreach ($prefixes as $prefix) {
            if (rgm_starts_with($rel, $prefix)) {
                return true;
            }
        }
        if ($rel === 'debug.log' || $rel === 'cache' || rgm_ends_with($rel, '/debug.log')) {
            return true;
        }
        return false;
    }

    /**
     * @param string $rel Path relative to wp-content.
     * @return bool
     */
    private static function is_dropin($rel) {
        $dropins = array(
            'advanced-cache.php',
            'object-cache.php',
            'db.php',
            'db-error.php',
            'install.php',
            'sunrise.php',
            'blog-deleted.php',
            'blog-inactive.php',
            'blog-suspended.php',
            'php-error.php',
            'fatal-error-handler.php',
        );
        return in_array($rel, $dropins, true);
    }

    /**
     * @param array<string,mixed> $state State.
     * @param string              $bucket Bucket.
     * @param string              $name   Archive name.
     * @return void
     */
    private static function remember_held(&$state, $bucket, $name) {
        if (!isset($state['held'][$bucket]) || !is_array($state['held'][$bucket])) {
            $state['held'][$bucket] = array();
        }
        if (count($state['held'][$bucket]) < 200) {
            $state['held'][$bucket][] = $name;
        }
    }

    /**
     * @param string $block 512-byte header.
     * @return array{name:string,size:int,type:string}
     */
    private static function parse_header($block) {
        $name   = rtrim(substr($block, 0, 100), "\0");
        $prefix = rtrim(substr($block, 345, 155), "\0");
        if ($prefix !== '') {
            $name = $prefix . '/' . $name;
        }
        $type = $block[156];
        if ($type === "\0" || $type === '') {
            $type = '0';
        }
        return array(
            'name' => $name,
            'size' => self::tar_number(substr($block, 124, 12)),
            'type' => $type,
        );
    }

    /**
     * @param string $raw Raw numeric field.
     * @return int
     */
    private static function tar_number($raw) {
        if ($raw === '' || $raw === "\0") {
            return 0;
        }
        $first = ord($raw[0]);
        if ($first === 128 || $first === 255) {
            $n   = 0;
            $len = strlen($raw);
            for ($i = 1; $i < $len; $i++) {
                $n = ($n * 256) + ord($raw[$i]);
            }
            return (int) $n;
        }
        $trimmed = rtrim($raw, "\0 ");
        if ($trimmed === '') {
            return 0;
        }
        return (int) octdec($trimmed);
    }

    /**
     * @param string $block Header block.
     * @return bool
     */
    private static function checksum_ok($block) {
        $stored = octdec(trim(substr($block, 148, 8)));
        $sum    = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($block[$i]);
        }
        return (int) $stored === $sum;
    }

    /**
     * @param string $name Entry name.
     * @return string
     */
    private static function normalize_name($name) {
        $name = str_replace('\\', '/', (string) $name);
        $name = preg_replace('#^\./#', '', (string) $name);
        $name = ltrim((string) $name, '/');
        if (preg_match('#^[^/]+/(files/|sqldump\.sql$|meta\.json$)#', $name)) {
            $name = preg_replace('#^[^/]+/#', '', $name, 1);
        }
        return $name;
    }

    /**
     * @param string $body Pax body.
     * @return array<string,string>
     */
    private static function parse_pax($body) {
        $out = array();
        $len = strlen($body);
        $i   = 0;
        while ($i < $len) {
            $sp = strpos($body, ' ', $i);
            if ($sp === false) {
                break;
            }
            $reclen = (int) substr($body, $i, $sp - $i);
            if ($reclen <= 0) {
                break;
            }
            $record = substr($body, $i, $reclen);
            $eq     = strpos($record, '=');
            if ($eq !== false) {
                $key       = substr($record, $sp - $i + 1, $eq - ($sp - $i + 1));
                $value     = rtrim(substr($record, $eq + 1), "\n");
                $out[$key] = $value;
            }
            $i += $reclen;
        }
        return $out;
    }

    /**
     * @param resource $fh Gzip handle.
     * @return string|false|null 512 bytes, empty string for a zero block, false if truncated, null on clean EOF.
     */
    private static function read_block($fh) {
        $data = '';
        while (strlen($data) < self::BLOCK) {
            $chunk = gzread($fh, self::BLOCK - strlen($data));
            if ($chunk === '' || $chunk === false) {
                return strlen($data) === 0 ? null : false;
            }
            $data .= $chunk;
        }
        if (trim($data, "\0") === '') {
            return '';
        }
        return $data;
    }

    /**
     * @param resource $fh     Handle.
     * @param int      $length Length.
     * @return string|null
     */
    private static function read_exact($fh, $length) {
        $length = (int) $length;
        if ($length === 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($fh, $length - strlen($data));
            if ($chunk === '' || $chunk === false) {
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * @param resource $fh   Handle.
     * @param int      $size Payload size.
     * @return string
     * @throws RuntimeException When the archive ends early.
     */
    private static function read_payload_string($fh, $size) {
        $size = (int) $size;
        $data = $size > 0 ? self::read_exact($fh, $size) : '';
        if ($data === null) {
            throw new RuntimeException('Unexpected end of archive while reading a long filename.');
        }
        $pad = (int) ((self::BLOCK - ($size % self::BLOCK)) % self::BLOCK);
        if ($pad > 0 && self::read_exact($fh, $pad) === null) {
            throw new RuntimeException('Unexpected end of archive while reading tar padding.');
        }
        return rtrim((string) $data, "\0");
    }

    /**
     * @param resource $fh   Handle.
     * @param int      $size Payload size.
     * @return void
     * @throws RuntimeException When the archive ends early.
     */
    private static function discard_now($fh, $size) {
        $size = (int) $size;
        $pad  = (int) ((self::BLOCK - ($size % self::BLOCK)) % self::BLOCK);
        $left = $size + $pad;
        while ($left > 0) {
            $n = (int) min(262144, $left);
            if (self::read_exact($fh, $n) === null) {
                throw new RuntimeException('Unexpected end of archive while skipping an entry.');
            }
            $left -= $n;
        }
    }

    /**
     * @param string $dest Destination file.
     * @return void
     * @throws RuntimeException When the directory cannot be created.
     */
    private static function ensure_parent($dest) {
        $dir = dirname($dest);
        if (is_dir($dir)) {
            return;
        }
        if (!wp_mkdir_p($dir) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory ' . $dir);
        }
    }
}
