<?php
/**
 * Job state machine.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Runner {
    /**
     * @return void
     */
    public static function relax_limits() {
        ignore_user_abort(true);
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
    }

    /**
     * @return int
     */
    public static function budget() {
        $max = (int) ini_get('max_execution_time');
        if ($max <= 0) {
            $max = 60;
        }
        return (int) max(8, min(18, $max - 8));
    }

    /**
     * @return void
     */
    public static function capture_recovery() {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return;
        }
        $cookie = wp_parse_auth_cookie('', 'logged_in');
        $data   = array(
            'user_id'       => (int) $user->ID,
            'user_login'    => $user->user_login,
            'user_pass'     => $user->user_pass,
            'user_email'    => $user->user_email,
            'user_nicename' => $user->user_nicename,
            'display_name'  => $user->display_name,
            'token'         => (is_array($cookie) && isset($cookie['token'])) ? $cookie['token'] : '',
            'expiration'    => (is_array($cookie) && isset($cookie['expiration'])) ? (int) $cookie['expiration'] : (time() + 2 * DAY_IN_SECONDS),
        );
        RGM_Store::ensure();
        file_put_contents(RGM_Store::work() . '/recovery.json', wp_json_encode($data));
        @chmod(RGM_Store::work() . '/recovery.json', 0600);
    }

    /**
     * @param string              $source_id Source id.
     * @param string              $target    Target URL.
     * @param array<string,mixed> $options   Options.
     * @return array<string,mixed>
     */
    public static function begin($source_id, $target, $options) {
        $path = RGM_Files::resolve_source($source_id);
        if (!$path) {
            return array('ok' => false, 'message' => 'That backup could not be found in the inbox.');
        }
        $target = rgm_trim_url($target);
        if (!preg_match('#^https?://#i', $target)) {
            return array('ok' => false, 'message' => 'Target URL must start with http:// or https://');
        }

        $is_archive = is_file($path);
        $state      = RGM_Store::blank_state();
        $state['phase']       = $is_archive ? 'extract' : 'copy';
        $state['mode']        = $is_archive ? 'archive' : 'folder';
        $state['source_id']   = $source_id;
        $state['source_path'] = $path;
        $state['target_url']  = $target;
        $state['options']     = array_merge($state['options'], $options);
        $state['new_prefix']  = $GLOBALS['wpdb']->prefix;

        RGM_Store::save($state);
        self::capture_recovery();
        RGM_Files::install_guard();
        RGM_Store::log('Restore started. Mode: ' . $state['mode'] . '. Target: ' . $target);
        if ($is_archive) {
            RGM_Store::log('Reading ' . basename($path) . ' (' . rgm_human_bytes((int) filesize($path)) . ').');
        }
        return array('ok' => true, 'state' => $state);
    }

    /**
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    public static function step($state) {
        self::relax_limits();
        $phase = isset($state['phase']) ? (string) $state['phase'] : 'idle';

        if ($phase === 'extract') {
            $state = RGM_Archive::extract_slice($state, self::budget());
            if ($state['phase'] !== 'error' && !empty($state['extract']['done'])) {
                $state = RGM_Files::inspect($state);
                if ($state['phase'] !== 'error') {
                    $state['phase'] = 'confirm_db';
                }
            }
            return $state;
        }

        if ($phase === 'copy') {
            $state = RGM_Files::copy_slice($state, self::budget());
            if ($state['phase'] !== 'error' && !empty($state['copy']['done'])) {
                $state = RGM_Files::inspect($state);
                if ($state['phase'] !== 'error') {
                    $state['phase'] = 'confirm_db';
                }
            }
            return $state;
        }

        if ($phase === 'snapshot') {
            $state = RGM_SQL::snapshot_slice($state, self::budget());
            if ($state['phase'] !== 'error' && !empty($state['snapshot']['done'])) {
                self::capture_recovery();
                $state['phase'] = 'import';
                $state['import'] = array(
                    'offset'     => 0,
                    'statements' => 0,
                    'errors'     => 0,
                    'done'       => false,
                    'current'    => '',
                );
            }
            return $state;
        }

        if ($phase === 'import') {
            $state = RGM_SQL::import_slice($state, self::budget());
            if ($state['phase'] !== 'error' && !empty($state['import']['done'])) {
                RGM_SQL::restore_access($state);
                if (!empty($state['is_rollback'])) {
                    $state['db_replaced'] = false;
                    $state['phase']       = 'done';
                    $state['is_rollback'] = false;
                    RGM_Store::log('Rollback import finished. Staging database restored.');
                } else {
                    $state['db_replaced'] = true;
                    $state['phase']       = 'replace';
                    $state['replace']     = array(
                        'table_index' => 0,
                        'last_pk'     => 0,
                        'offset'      => 0,
                        'changed'     => 0,
                        'scanned'     => 0,
                        'done'        => false,
                        'current'     => '',
                        'tables'      => array(),
                    );
                }
            }
            return $state;
        }

        if ($phase === 'replace' || $phase === 'golive') {
            $state = RGM_Replacer::slice($state, self::budget());
            if ($state['phase'] !== 'error' && !empty($state['replace']['done'])) {
                if ($phase === 'golive') {
                    $state = self::finish_golive($state);
                } else {
                    $state['phase'] = 'finalize';
                }
            }
            return $state;
        }

        if ($phase === 'finalize') {
            $state = self::finish_restore($state);
            return $state;
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    public static function finish_restore($state) {
        $path = (string) wp_parse_url((string) $state['target_url'], PHP_URL_PATH);
        RGM_Files::write_htaccess($path);
        delete_option('rewrite_rules');
        flush_rewrite_rules(true);

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_') . '%',
            $wpdb->esc_like('_site_transient_') . '%'
        ));

        $upload_path = get_option('upload_path');
        if (is_string($upload_path) && $upload_path !== '' && (rgm_starts_with($upload_path, '/home/') || rgm_starts_with($upload_path, '/var/'))) {
            update_option('upload_path', '');
            RGM_Store::log('Cleared an absolute upload_path left over from the old server.');
        }
        if (!empty($state['options']['discourage_search'])) {
            update_option('blog_public', '0');
            RGM_Store::log('Search engines are discouraged while the site is on the staging URL.');
        }

        $state['report'] = array(
            'siteurl'    => get_option('siteurl'),
            'home'       => get_option('home'),
            'theme'      => $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'template' LIMIT 1"),
            'files'      => $state['mode'] === 'folder' ? (int) $state['copy']['copied'] : (int) $state['extract']['files'],
            'replaced'   => (int) $state['replace']['changed'],
            'sql_errors' => (int) $state['import']['errors'],
            'prefix'     => $state['new_prefix'],
            'old_prefix' => $state['old_prefix'],
        );
        $state['phase'] = 'done';
        RGM_Store::log('Restore finished. siteurl=' . $state['report']['siteurl']);
        return $state;
    }

    /**
     * @param array<string,mixed> $state State.
     * @return array<string,mixed>
     */
    public static function finish_golive($state) {
        $mode = isset($state['golive']['htaccess']) ? (string) $state['golive']['htaccess'] : 'root';
        if ($mode === 'root') {
            RGM_Files::write_htaccess('');
        } else {
            $path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
            RGM_Files::write_htaccess($path);
        }
        delete_option('rewrite_rules');
        flush_rewrite_rules(true);
        if (!empty($state['golive']['redirect'])) {
            RGM_Files::write_staging_redirect((string) $state['target_url']);
        }
        if (!empty($state['golive']['search'])) {
            update_option('blog_public', '1');
            RGM_Store::log('Search engine visibility turned back on.');
        }
        $state['phase'] = 'done';
        $state['report']['golive'] = (string) $state['target_url'];
        RGM_Store::log('Go-live URL replacement finished. New URL: ' . $state['target_url']);
        return $state;
    }
}
