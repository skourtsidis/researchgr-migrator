<?php
/**
 * Serialized-data-safe search and replace.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Replacer {
    /**
     * @param array<string,mixed> $state State.
     * @return array<int,array{0:string,1:string}>
     */
    public static function pairs_from_state($state) {
        $pairs  = array();
        $target = rgm_trim_url((string) $state['target_url']);
        $sources = array();
        foreach (array('source_url', 'home_url') as $key) {
            if (!empty($state[$key])) {
                $sources[] = rgm_trim_url((string) $state[$key]);
            }
        }
        if (!empty($state['source_url'])) {
            $sources[] = rgm_toggle_www(rgm_trim_url((string) $state['source_url']));
        }
        $expanded = array();
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            $expanded[] = $source;
            $expanded[] = preg_replace('#^https://#', 'http://', $source);
            $expanded[] = preg_replace('#^http://#', 'https://', $source);
        }
        $expanded = array_unique(array_filter($expanded));
        foreach ($expanded as $from) {
            if ($from === $target) {
                continue;
            }
            self::add_pair($pairs, $from, $target);
            $from_rel = preg_replace('#^https?:#', '', $from);
            $to_rel   = preg_replace('#^https?:#', '', $target);
            if ($from_rel && $to_rel && $from_rel !== $to_rel) {
                self::add_pair($pairs, $from_rel, $to_rel);
            }
        }
        if (!empty($state['options']['replace_paths']) && !empty($state['old_path'])) {
            $old_path = rtrim((string) $state['old_path'], '/');
            $new_path = rtrim(ABSPATH, '/');
            if ($old_path !== '' && $old_path !== $new_path) {
                self::add_pair($pairs, $old_path, $new_path);
                self::add_pair($pairs, $old_path . '/', $new_path . '/');
            }
        }
        usort($pairs, static function ($a, $b) {
            return strlen($b[0]) <=> strlen($a[0]);
        });
        $unique = array();
        $seen   = array();
        foreach ($pairs as $pair) {
            if ($pair[0] === '' || $pair[0] === $pair[1] || isset($seen[$pair[0]])) {
                continue;
            }
            $seen[$pair[0]] = true;
            $unique[]       = $pair;
        }
        return $unique;
    }

    /**
     * @param array<int,array{0:string,1:string}> $pairs Pairs.
     * @param string                              $from  From.
     * @param string                              $to    To.
     * @return void
     */
    private static function add_pair(&$pairs, $from, $to) {
        $pairs[] = array($from, $to);
        $escaped_from = str_replace('/', chr(92) . '/', $from);
        $escaped_to   = str_replace('/', chr(92) . '/', $to);
        $pairs[] = array($escaped_from, $escaped_to);
        $pairs[] = array(str_replace('/', '%2F', $from), str_replace('/', '%2F', $to));
        $pairs[] = array(rawurlencode($from), rawurlencode($to));
    }

    /**
     * @param array<string,mixed> $state  State.
     * @param int                 $budget Seconds.
     * @return array<string,mixed>
     */
    public static function slice($state, $budget = 12) {
        global $wpdb;
        $start = microtime(true);
        $pairs = self::pairs_from_state($state);
        if (!$pairs) {
            $state['replace']['done'] = true;
            RGM_Store::log('No URL differences to replace.');
            return $state;
        }
        if (empty($state['replace']['tables']) || !is_array($state['replace']['tables'])) {
            $like   = $wpdb->esc_like($wpdb->prefix) . '%';
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            $state['replace']['tables'] = is_array($tables) ? array_values($tables) : array();
            RGM_Store::log('Search-replace across ' . count($state['replace']['tables']) . ' tables.');
        }
        $tables = $state['replace']['tables'];
        $skip_guid = empty($state['options']['replace_guids']);

        while ((int) $state['replace']['table_index'] < count($tables) && (microtime(true) - $start) < $budget) {
            $table = $tables[(int) $state['replace']['table_index']];
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                $state['replace']['table_index']++;
                $state['replace']['last_pk'] = 0;
                $state['replace']['offset']  = 0;
                continue;
            }
            $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . $table . '`', ARRAY_A);
            if (!$columns) {
                $state['replace']['table_index']++;
                continue;
            }
            $pks  = array();
            $text = array();
            foreach ($columns as $column) {
                $field = (string) $column['Field'];
                if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                    continue;
                }
                if (isset($column['Key']) && $column['Key'] === 'PRI') {
                    $pks[] = $field;
                }
                $type = strtolower((string) $column['Type']);
                if (preg_match('/char|text|json|enum|set/', $type)) {
                    if ($skip_guid && $field === 'guid') {
                        continue;
                    }
                    $text[] = $field;
                }
            }
            if (!$text || !$pks) {
                if (!$pks && $text) {
                    RGM_Store::log('Skipped ' . $table . ' during search-replace (no primary key).');
                }
                $state['replace']['table_index']++;
                $state['replace']['last_pk'] = 0;
                $state['replace']['offset']  = 0;
                continue;
            }
            $pk = $pks[0];
            $state['replace']['current'] = $table;
            $select_cols = array();
            foreach (array_unique(array_merge($text, $pks)) as $col) {
                $select_cols[] = '`' . $col . '`';
            }
            $likes  = array();
            $params = array();
            foreach (array_slice(self::needles($pairs), 0, 4) as $needle) {
                foreach ($text as $col) {
                    $likes[]  = '`' . $col . '` LIKE %s';
                    $params[] = '%' . $wpdb->esc_like($needle) . '%';
                }
            }
            $where_like = $likes ? '(' . implode(' OR ', $likes) . ')' : '1=1';
            $sql = 'SELECT ' . implode(',', $select_cols) . ' FROM `' . $table . '` WHERE `' . $pk . '` > %s AND ' . $where_like . ' ORDER BY `' . $pk . '` LIMIT 60';
            $query_params = array_merge(array((string) $state['replace']['last_pk']), $params);
            $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $query_params));
            $rows = $wpdb->get_results($prepared, ARRAY_A);

            if (!is_array($rows) || !$rows) {
                $state['replace']['table_index']++;
                $state['replace']['last_pk'] = 0;
                $state['replace']['offset']  = 0;
                continue;
            }

            foreach ($rows as $row) {
                $state['replace']['scanned']++;
                $changed = array();
                foreach ($text as $col) {
                    if (!isset($row[$col]) || !is_string($row[$col]) || $row[$col] === '') {
                        continue;
                    }
                    if (!self::might_match($row[$col], $pairs)) {
                        continue;
                    }
                    $new = self::replace_recursive($row[$col], $pairs);
                    if (is_string($new) && $new !== $row[$col]) {
                        $changed[$col] = $new;
                    }
                }
                if ($changed) {
                    $where = array();
                    foreach ($pks as $pk_col) {
                        $where[$pk_col] = $row[$pk_col];
                    }
                    $wpdb->update($table, $changed, $where);
                    $state['replace']['changed']++;
                }
                $state['replace']['last_pk'] = $row[$pk];
            }
        }

        if ((int) $state['replace']['table_index'] >= count($tables)) {
            $state['replace']['done'] = true;
            $target = rgm_trim_url((string) $state['target_url']);
            if ($target !== '') {
                update_option('siteurl', $target);
                update_option('home', $target);
            }
            RGM_Store::log('Search-replace finished. Rows updated: ' . (int) $state['replace']['changed'] . '.');
        }
        return $state;
    }

    /**
     * @param array<int,array{0:string,1:string}> $pairs Pairs.
     * @return array<int,string>
     */
    private static function needles($pairs) {
        $needles = array();
        foreach ($pairs as $pair) {
            if (strlen($pair[0]) < 8) {
                continue;
            }
            if (rgm_contains($pair[0], 'research.gr') || rgm_contains($pair[0], 'http') || rgm_starts_with($pair[0], '/') || rgm_starts_with($pair[0], 'http')) {
                $needles[] = $pair[0];
            }
        }
        if (!$needles && $pairs) {
            $needles[] = $pairs[0][0];
        }
        return array_slice(array_unique($needles), 0, 8);
    }

    /**
     * @param string                              $value Value.
     * @param array<int,array{0:string,1:string}> $pairs Pairs.
     * @return bool
     */
    private static function might_match($value, $pairs) {
        foreach ($pairs as $pair) {
            if ($pair[0] !== '' && strpos($value, $pair[0]) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed                               $data       Value.
     * @param array<int,array{0:string,1:string}> $pairs      Pairs.
     * @param bool                                $serialised Re-serialize on the way out.
     * @return mixed
     */
    public static function replace_recursive($data, $pairs, $serialised = false) {
        if (is_string($data)) {
            if (is_serialized($data)) {
                $un = @unserialize($data, array('allowed_classes' => true));
                if ($un !== false || $data === 'b:0;') {
                    $data = self::replace_recursive($un, $pairs, true);
                    return $data;
                }
            }
            $data = self::replace_string($data, $pairs);
        } elseif (is_array($data)) {
            $tmp = array();
            foreach ($data as $key => $value) {
                $new_key       = self::replace_recursive($key, $pairs, false);
                $tmp[$new_key] = self::replace_recursive($value, $pairs, false);
            }
            $data = $tmp;
        } elseif (is_object($data)) {
            $props = get_object_vars($data);
            foreach ($props as $key => $value) {
                $data->$key = self::replace_recursive($value, $pairs, false);
            }
        }
        if ($serialised) {
            return serialize($data);
        }
        return $data;
    }

    /**
     * @param string                              $value Value.
     * @param array<int,array{0:string,1:string}> $pairs Pairs.
     * @return string
     */
    private static function replace_string($value, $pairs) {
        foreach ($pairs as $pair) {
            if ($pair[0] !== '' && strpos($value, $pair[0]) !== false) {
                $value = str_replace($pair[0], $pair[1], $value);
            }
        }
        return $value;
    }
}
