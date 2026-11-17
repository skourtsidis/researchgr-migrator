<?php
/**
 * Small shared helpers. PHP 7.4 compatible.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $haystack Haystack.
 * @param string $needle   Needle.
 * @return bool
 */
function rgm_starts_with($haystack, $needle) {
    if ($needle === '') {
        return true;
    }
    return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
}

/**
 * @param string $haystack Haystack.
 * @param string $needle   Needle.
 * @return bool
 */
function rgm_ends_with($haystack, $needle) {
    $haystack = (string) $haystack;
    $needle   = (string) $needle;
    if ($needle === '') {
        return true;
    }
    if (strlen($needle) > strlen($haystack)) {
        return false;
    }
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * @param string $haystack Haystack.
 * @param string $needle   Needle.
 * @return bool
 */
function rgm_contains($haystack, $needle) {
    return strpos((string) $haystack, (string) $needle) !== false;
}

/**
 * @param int|float $bytes Bytes.
 * @return string
 */
function rgm_human_bytes($bytes) {
    $bytes = (float) $bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i     = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $precision = $i === 0 ? 0 : 1;
    return number_format_i18n($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Join a relative path onto a root and reject traversal.
 *
 * @param string $root Absolute root.
 * @param string $rel  Relative path.
 * @return string|false
 */
function rgm_safe_join($root, $rel) {
    $rel = str_replace('\\', '/', (string) $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, "\0") !== false) {
        return false;
    }
    $parts = array();
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return false;
        }
        $parts[] = $part;
    }
    if (!$parts) {
        return false;
    }
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    $path = $root . '/' . implode('/', $parts);
    if (strpos($path, $root . '/') !== 0) {
        return false;
    }
    return $path;
}

/**
 * @param string $name File or directory name.
 * @return string
 */
function rgm_sanitize_filename($name) {
    $name = basename(str_replace('\\', '/', (string) $name));
    $name = preg_replace('/[^A-Za-z0-9._+-]/', '_', (string) $name);
    return trim((string) $name, '._');
}

/**
 * @return bool
 */
function rgm_shell_available() {
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return false;
    }
    $suhosin = array_map('trim', explode(',', (string) ini_get('suhosin.executor.func.blacklist')));
    if (in_array('exec', $suhosin, true)) {
        return false;
    }
    return true;
}

/**
 * @param string $url URL.
 * @return string
 */
function rgm_trim_url($url) {
    $url = trim((string) $url);
    $url = rtrim($url, '/');
    return $url;
}

/**
 * @param string $url URL.
 * @return string
 */
function rgm_toggle_www($url) {
    $parts = wp_parse_url($url);
    if (empty($parts['host'])) {
        return $url;
    }
    $host = $parts['host'];
    if (rgm_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    } else {
        $host = 'www.' . $host;
    }
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $path   = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    return $scheme . '://' . $host . $path;
}

/**
 * A bundled theme that can keep wp-admin alive in safe mode.
 *
 * @return string
 */
function rgm_fallback_theme() {
    $candidates = array(
        'twentytwentyfive',
        'twentytwentyfour',
        'twentytwentythree',
        'twentytwentytwo',
        'twentytwentyone',
        'twentytwenty',
    );
    foreach ($candidates as $slug) {
        if (is_dir(WP_CONTENT_DIR . '/themes/' . $slug)) {
            return $slug;
        }
    }
    $themes = wp_get_themes();
    if ($themes) {
        $first = array_key_first($themes);
        if ($first) {
            return (string) $first;
        }
    }
    return 'twentytwentyfour';
}

/**
 * @param string $value Raw SQL string value.
 * @return string
 */
function rgm_unescape_sql_string($value) {
    $value = str_replace(array('\\\\', '\\\'', '\\"', '\\0', '\\n', '\\r', '\\t'), array('\\', "'", '"', "\0", "\n", "\r", "\t"), $value);
    return $value;
}
