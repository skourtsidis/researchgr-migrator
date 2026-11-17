<?php
/**
 * Admin screen and AJAX controller.
 *
 * @package ResearchGRMigrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class RGM_Admin {
    /**
     * @var self|null
     */
    private static $instance = null;

    /**
     * @return self
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return void
     */
    public static function activate() {
        RGM_Store::ensure();
    }

    /**
     * @return void
     */
    public static function deactivate() {
        RGM_Store::unlock('run');
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_notices', array($this, 'notice'));
        add_action('wp_ajax_rgm_step', array($this, 'ajax_step'));
        add_action('wp_ajax_rgm_upload', array($this, 'ajax_upload'));
    }

    /**
     * @return void
     */
    public function menu() {
        add_menu_page(
            'Research GR Migrator',
            'RG Migrator',
            'manage_options',
            'researchgr-migrator',
            array($this, 'render'),
            'dashicons-migrate',
            3
        );
    }

    /**
     * @param string $hook Hook.
     * @return void
     */
    public function assets($hook) {
        if ($hook !== 'toplevel_page_researchgr-migrator') {
            return;
        }
        wp_enqueue_style('rgm-admin', RGM_URL . 'assets/admin.css', array(), RGM_VERSION);
        wp_enqueue_script('rgm-admin', RGM_URL . 'assets/admin.js', array(), RGM_VERSION, true);
        wp_localize_script('rgm-admin', 'RGM', array(
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rgm_nonce'),
            'home'  => rgm_trim_url(home_url()),
            'view'  => home_url('/'),
        ));
    }

    /**
     * @return void
     */
    public function notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'toplevel_page_researchgr-migrator') {
            return;
        }
        if (!RGM_Files::safe_mode_on()) {
            return;
        }
        $url = admin_url('admin.php?page=researchgr-migrator');
        echo '<div class="notice notice-warning"><p><strong>Research GR Migrator</strong> is still in safe mode. Other plugins and the original theme are held back until you exit safe mode. <a href="' . esc_url($url) . '">Open the migrator</a>.</p></div>';
    }

    /**
     * @return void
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to migrate this site.', 'researchgr-migrator'));
        }
        ?>
        <div class="rgm-app" id="rgm-app">
            <header class="rgm-top">
                <div>
                    <p class="rgm-kicker">FastComet WP Toolkit → Strato</p>
                    <h1>Research GR Migrator</h1>
                    <p class="rgm-lede">Restores <code>research.gr__….tar.gz</code> — the archive with <code>files/</code>, <code>sqldump.sql</code> and <code>meta.json</code> — onto this WordPress install without touching its <code>wp-config.php</code>.</p>
                </div>
                <ol class="rgm-route" aria-label="Route">
                    <li><span>From</span><strong>research.gr</strong><em>FastComet</em></li>
                    <li><span>Stage</span><strong id="rgm-stage-url">this site</strong><em>Strato</em></li>
                    <li><span>Live</span><strong>research.gr</strong><em>after DNS</em></li>
                </ol>
            </header>

            <div class="rgm-layout">
                <aside class="rgm-rail" aria-label="Phases">
                    <ol id="rgm-phases">
                        <li data-phase="source">1 · Backup</li>
                        <li data-phase="extract">2 · Files</li>
                        <li data-phase="confirm_db">3 · Confirm</li>
                        <li data-phase="import">4 · Database</li>
                        <li data-phase="replace">5 · URLs</li>
                        <li data-phase="done">6 · Check</li>
                        <li data-phase="golive">7 · Go live</li>
                    </ol>
                    <div class="rgm-rail-note" id="rgm-safe-pill">Safe mode: checking…</div>
                </aside>

                <main class="rgm-main">
                    <section class="rgm-panel" id="panel-boot">
                        <p class="rgm-kicker">Preflight</p>
                        <div id="rgm-preflight" class="rgm-stats"></div>
                    </section>

                    <section class="rgm-panel" id="panel-source">
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Step 1</p>
                            <h2>Choose the WP Toolkit backup</h2>
                        </div>
                        <div class="rgm-split">
                            <div>
                                <h3>Browser upload</h3>
                                <p>Works around Strato’s upload limit by sending the archive in 1 MB pieces. For a very large <code>uploads</code> folder, FTP is more reliable.</p>
                                <label class="rgm-drop" id="rgm-drop">
                                    <input type="file" id="rgm-file" accept=".gz,.tgz,application/gzip" />
                                    <span>Drop the .tar.gz here, or browse</span>
                                </label>
                                <div class="rgm-progress" id="rgm-upload-progress" hidden>
                                    <span id="rgm-upload-label">Uploading…</span>
                                    <div class="rgm-bar"><i id="rgm-upload-bar"></i></div>
                                </div>
                            </div>
                            <div>
                                <h3>Already on the server</h3>
                                <p>Upload by FTP/SFTP into the inbox below. You can also extract the archive on your computer and upload the folder that contains <code>files/</code>, <code>sqldump.sql</code> and <code>meta.json</code>.</p>
                                <div class="rgm-path">
                                    <code id="rgm-inbox-path">…</code>
                                    <button type="button" class="rgm-textbtn" data-copy="#rgm-inbox-path">Copy</button>
                                </div>
                                <ul class="rgm-sources" id="rgm-sources"></ul>
                                <button type="button" class="rgm-textbtn" id="rgm-refresh">Refresh list</button>
                            </div>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-plan">
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Step 2</p>
                            <h2>Plan the staging restore</h2>
                        </div>
                        <label class="rgm-field">
                            <span>Target URL on this host</span>
                            <input type="url" id="rgm-target" spellcheck="false" />
                        </label>
                        <div class="rgm-checks">
                            <label><input type="checkbox" id="opt-skip-cache" checked /> Skip caches (cache, et-cache, litespeed, upgrade)</label>
                            <label><input type="checkbox" id="opt-skip-updraft" checked /> Skip backup folders (updraft, wpvivid, ai1wm)</label>
                            <label><input type="checkbox" id="opt-skip-dropins" checked /> Hold drop-ins (object-cache.php, advanced-cache.php, db.php)</label>
                            <label><input type="checkbox" id="opt-mu" /> Also copy mu-plugins now (off is safer)</label>
                            <label><input type="checkbox" id="opt-search" checked /> Discourage search engines until go-live</label>
                            <label><input type="checkbox" id="opt-paths" checked /> Replace old server filesystem paths</label>
                            <label><input type="checkbox" id="opt-guids" /> Also replace post GUIDs (usually leave off)</label>
                        </div>
                        <p class="rgm-warn">This replaces the database of <strong>this</strong> WordPress install. A safety snapshot is written first. <code>wp-config.php</code> on Strato is kept, so the FastComet database password is never imported.</p>
                        <div class="rgm-actions">
                            <button type="button" class="rgm-btn" id="rgm-start" disabled>Start restore</button>
                            <span id="rgm-selected" class="rgm-muted">No backup selected.</span>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-run" hidden>
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker" id="rgm-run-kicker">Running</p>
                            <h2 id="rgm-run-title">Working</h2>
                        </div>
                        <div class="rgm-meters" id="rgm-meters"></div>
                        <div class="rgm-actions">
                            <button type="button" class="rgm-btn" id="rgm-resume">Resume</button>
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-pause">Pause after this step</button>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-confirm" hidden>
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Stop and read this</p>
                            <h2>About to replace the database</h2>
                        </div>
                        <div id="rgm-inspect" class="rgm-stats"></div>
                        <div class="rgm-grid2">
                            <label class="rgm-field"><span>Source site URL</span><input type="url" id="rgm-source-url" spellcheck="false" /></label>
                            <label class="rgm-field"><span>Source home URL</span><input type="url" id="rgm-home-url" spellcheck="false" /></label>
                        </div>
                        <label class="rgm-field"><span>Replace those URLs with</span><input type="url" id="rgm-target-confirm" spellcheck="false" /></label>
                        <p>Type <strong>RESTORE</strong> to replace this site’s tables with the FastComet dump. Your current login is preserved.</p>
                        <div class="rgm-actions">
                            <input type="text" id="rgm-confirm-word" class="rgm-word" autocomplete="off" placeholder="RESTORE" />
                            <button type="button" class="rgm-btn rgm-btn-danger" id="rgm-confirm">Replace database</button>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-done" hidden>
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Staging copy</p>
                            <h2>Restore report</h2>
                        </div>
                        <div id="rgm-report" class="rgm-stats"></div>
                        <div id="rgm-health"></div>
                        <div class="rgm-actions">
                            <a class="rgm-btn" id="rgm-view" href="#" target="_blank" rel="noopener">View site</a>
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-flush">Flush permalinks</button>
                            <button type="button" class="rgm-btn" id="rgm-safe-off">Exit safe mode</button>
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-held">Install held mu-plugins</button>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-golive">
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Cutover</p>
                            <h2>Point the copy at research.gr</h2>
                        </div>
                        <ol class="rgm-steps">
                            <li>Test every important page on the staging URL first. Leave FastComet running until this host answers for research.gr.</li>
                            <li>In Strato, add <strong>research.gr</strong> as an external domain and set its directory to this folder: <code id="rgm-abspath"></code></li>
                            <li>If <code>@research.gr</code> mail must keep working, write down MX, SPF and DKIM at ip.gr <em>before</em> changing nameservers. Changing nameservers moves mail too. Safer option: leave DNS at ip.gr and change only the A record to the Strato IP.</li>
                            <li>Run the replacement below in the same window of time as the DNS change. Until DNS arrives here, a replaced site will load CSS from the old host.</li>
                            <li>At ip.gr: Control panel → Domains → gear under Nameservers. Use the nameservers shown in Strato, commonly <code>ns1.stratoserver.net</code> and <code>ns2.stratoserver.net</code>. .gr updates usually land within 3–6 hours.</li>
                            <li>After it answers, turn search engines back on, then delete this plugin.</li>
                        </ol>
                        <label class="rgm-field"><span>Production URL</span><input type="url" id="rgm-live-url" value="https://research.gr" spellcheck="false" /></label>
                        <div class="rgm-checks">
                            <label><input type="radio" name="rgm-base" value="root" checked /> Document root will be this folder (RewriteBase /)</label>
                            <label><input type="radio" name="rgm-base" value="subdir" /> Keep the current subdirectory</label>
                            <label><input type="checkbox" id="rgm-live-redirect" checked /> Redirect kourtsidis.com/researchgr to research.gr</label>
                            <label><input type="checkbox" id="rgm-live-search" /> Allow search engines after the swap</label>
                        </div>
                        <div class="rgm-actions">
                            <input type="text" id="rgm-golive-word" class="rgm-word" autocomplete="off" placeholder="GOLIVE" />
                            <button type="button" class="rgm-btn rgm-btn-danger" id="rgm-golive">Run go-live replace</button>
                        </div>
                    </section>

                    <section class="rgm-panel" id="panel-danger">
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Recovery</p>
                            <h2>If something looks wrong</h2>
                        </div>
                        <p id="rgm-error" class="rgm-error" hidden></p>
                        <div class="rgm-actions">
                            <input type="text" id="rgm-rollback-word" class="rgm-word" autocomplete="off" placeholder="ROLLBACK" />
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-rollback">Roll back snapshot</button>
                            <input type="text" id="rgm-reset-word" class="rgm-word" autocomplete="off" placeholder="RESET" />
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-reset">Reset job</button>
                            <input type="text" id="rgm-delete-word" class="rgm-word" autocomplete="off" placeholder="DELETE" />
                            <button type="button" class="rgm-btn rgm-btn-ghost" id="rgm-cleanup">Delete working files</button>
                        </div>
                        <p class="rgm-muted">Rollback re-imports the snapshot taken before the dump. Reset only clears the job pointer — it does not undo a database import. Delete removes the SQL dump, snapshot and uploaded archive from the private folder.</p>
                    </section>

                    <section class="rgm-panel">
                        <div class="rgm-panel-head">
                            <p class="rgm-kicker">Log</p>
                            <h2>What the migrator did</h2>
                        </div>
                        <pre id="rgm-log" class="rgm-log">Waiting for the first action.</pre>
                    </section>
                </main>
            </div>
            <div class="rgm-toast" id="rgm-toast" hidden></div>
        </div>
        <?php
    }

    /**
     * @return void
     */
    private function guard() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are no longer logged in as an administrator.'), 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $nonce_ok = $nonce !== '' && wp_verify_nonce($nonce, 'rgm_nonce');
        if (!$nonce_ok && !$this->job_cookie_ok()) {
            wp_send_json_error(array('message' => 'Security check failed. Reload the page and try again.'), 403);
        }
    }

    /**
     * @return bool
     */
    private function job_cookie_ok() {
        $path = RGM_Store::work() . '/job.secret';
        if (!is_file($path) || empty($_COOKIE['rgm_job'])) {
            return false;
        }
        $secret = (string) file_get_contents($path);
        $cookie = (string) wp_unslash($_COOKIE['rgm_job']);
        return $secret !== '' && hash_equals($secret, $cookie);
    }

    /**
     * @return void
     */
    private function issue_job_cookie() {
        try {
            $secret = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $secret = md5(uniqid('rgm', true));
        }
        file_put_contents(RGM_Store::work() . '/job.secret', $secret);
        @chmod(RGM_Store::work() . '/job.secret', 0600);
        setcookie('rgm_job', $secret, array(
            'expires'  => time() + 2 * DAY_IN_SECONDS,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ));
        $_COOKIE['rgm_job'] = $secret;
    }

    /**
     * @param array<string,mixed> $extra Extra payload.
     * @return void
     */
    private function ok($extra = array()) {
        wp_send_json_success(array_merge(array(
            'nonce' => wp_create_nonce('rgm_nonce'),
            'state' => RGM_Store::public_state(RGM_Store::state()),
            'log'   => RGM_Store::tail(),
        ), $extra));
    }

    /**
     * @return void
     */
    public function ajax_step() {
        $this->guard();
        RGM_Runner::relax_limits();
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';

        if ($task === 'boot') {
            $this->ok(array(
                'preflight' => $this->preflight(),
                'sources'   => RGM_Files::list_sources(),
                'health'    => RGM_Files::plugin_health(),
            ));
        }

        if ($task === 'list') {
            $this->ok(array('sources' => RGM_Files::list_sources()));
        }

        if ($task === 'start') {
            $source = isset($_POST['source_id']) ? sanitize_text_field(wp_unslash($_POST['source_id'])) : '';
            $target = isset($_POST['target_url']) ? esc_url_raw(wp_unslash($_POST['target_url'])) : '';
            $options = array(
                'skip_cache'        => !empty($_POST['skip_cache']) && $_POST['skip_cache'] !== '0',
                'skip_updraft'      => !empty($_POST['skip_updraft']) && $_POST['skip_updraft'] !== '0',
                'skip_dropins'      => !empty($_POST['skip_dropins']) && $_POST['skip_dropins'] !== '0',
                'copy_mu_plugins'   => !empty($_POST['copy_mu_plugins']) && $_POST['copy_mu_plugins'] !== '0',
                'discourage_search' => !empty($_POST['discourage_search']) && $_POST['discourage_search'] !== '0',
                'replace_guids'     => !empty($_POST['replace_guids']) && $_POST['replace_guids'] !== '0',
                'replace_paths'     => !empty($_POST['replace_paths']) && $_POST['replace_paths'] !== '0',
            );
            $this->issue_job_cookie();
            $result = RGM_Runner::begin($source, $target, $options);
            if (empty($result['ok'])) {
                wp_send_json_error(array('message' => $result['message']), 400);
            }
            $this->ok();
        }

        if ($task === 'run') {
            if (!RGM_Store::lock('run')) {
                $this->ok(array('busy' => true));
            }
            $state = RGM_Store::state();
            if (!in_array($state['phase'], array('extract', 'copy', 'snapshot', 'import', 'replace', 'finalize', 'golive'), true)) {
                RGM_Store::unlock('run');
                $this->ok();
            }
            $state = RGM_Runner::step($state);
            RGM_Store::save($state);
            RGM_Store::unlock('run');
            $this->ok();
        }

        if ($task === 'confirm') {
            $this->confirm_db();
        }

        if ($task === 'rollback') {
            $this->rollback();
        }

        if ($task === 'reset') {
            $this->require_word('RESET');
            RGM_Store::reset();
            RGM_Store::log('Job pointer reset. Copied files and the database were not reverted.');
            $this->ok();
        }

        if ($task === 'cleanup') {
            $this->require_word('DELETE');
            $this->cleanup_files();
            $this->ok();
        }

        if ($task === 'health') {
            $this->ok(array('health' => RGM_Files::plugin_health()));
        }

        if ($task === 'safe_off') {
            RGM_Files::exit_safe_mode();
            $this->ok(array('health' => RGM_Files::plugin_health()));
        }

        if ($task === 'held') {
            $n = $this->install_held();
            RGM_Store::log('Installed ' . $n . ' held mu-plugin files.');
            $this->ok(array('installed' => $n));
        }

        if ($task === 'permalinks') {
            delete_option('rewrite_rules');
            flush_rewrite_rules(true);
            RGM_Store::log('Permalinks flushed.');
            $this->ok();
        }

        if ($task === 'golive') {
            $this->start_golive();
        }

        wp_send_json_error(array('message' => 'Unknown task.'), 400);
    }

    /**
     * @return void
     */
    public function ajax_upload() {
        $this->guard();
        RGM_Store::ensure();
        if (empty($_FILES['chunk']['tmp_name']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            wp_send_json_error(array('message' => 'No upload chunk arrived. Lower the chunk size or use FTP.'), 400);
        }
        $filename = isset($_POST['filename']) ? rgm_sanitize_filename(wp_unslash($_POST['filename'])) : '';
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $filename)) {
            wp_send_json_error(array('message' => 'Only a .tar.gz or .tgz WP Toolkit backup can be uploaded here.'), 400);
        }
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $total  = isset($_POST['total']) ? (int) $_POST['total'] : 0;
        if ($offset < 0 || $total < 1) {
            wp_send_json_error(array('message' => 'Invalid upload size.'), 400);
        }
        $part    = RGM_Store::inbox() . '/' . $filename . '.part';
        $current = is_file($part) ? (int) filesize($part) : 0;
        if ($offset === 0 && is_file($part)) {
            unlink($part);
            $current = 0;
        }
        if ($offset !== $current) {
            wp_send_json_error(array(
                'message' => 'Upload out of sequence. Expected byte ' . $current . '.',
                'expected'=> $current,
            ), 409);
        }
        $in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
        $out = fopen($part, $offset === 0 ? 'wb' : 'ab');
        if (!$in || !$out) {
            wp_send_json_error(array('message' => 'Could not write the upload. Check disk space and directory permissions.'), 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $size = (int) filesize($part);
        $done = $size >= $total;
        if ($done) {
            $final = RGM_Store::inbox() . '/' . $filename;
            if (is_file($final)) {
                unlink($final);
            }
            rename($part, $final);
            RGM_Store::log('Uploaded ' . $filename . ' (' . rgm_human_bytes($size) . ').');
        }
        wp_send_json_success(array(
            'received' => $size,
            'done'     => $done,
            'name'     => $filename,
            'nonce'    => wp_create_nonce('rgm_nonce'),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight() {
        global $wpdb;
        RGM_Store::ensure();
        $free = @disk_free_space(ABSPATH);
        $constants = array();
        $config = ABSPATH . 'wp-config.php';
        if (is_readable($config)) {
            $raw = (string) file_get_contents($config);
            foreach (array('WP_HOME', 'WP_SITEURL') as $const) {
                if (preg_match("/define\\(\\s*['\"]" . $const . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/", $raw, $m)) {
                    $constants[$const] = $m[1];
                }
            }
        }
        return array(
            'php'        => PHP_VERSION,
            'wp'         => get_bloginfo('version'),
            'memory'     => ini_get('memory_limit'),
            'upload'     => ini_get('upload_max_filesize'),
            'post_max'   => ini_get('post_max_size'),
            'time'       => ini_get('max_execution_time'),
            'zlib'       => function_exists('gzopen'),
            'prefix'     => $wpdb->prefix,
            'home'       => rgm_trim_url(home_url()),
            'abspath'    => ABSPATH,
            'inbox'      => RGM_Store::inbox(),
            'disk_free'  => $free === false ? null : (int) $free,
            'writable'   => is_writable(WP_CONTENT_DIR) && is_writable(ABSPATH),
            'constants'  => $constants,
            'safe'       => RGM_Files::safe_mode_on(),
        );
    }

    /**
     * @return void
     */
    private function confirm_db() {
        $this->require_word('RESTORE');
        $state = RGM_Store::state();
        if ($state['phase'] !== 'confirm_db') {
            wp_send_json_error(array('message' => 'The job is not waiting for confirmation.'), 400);
        }
        $source = isset($_POST['source_url']) ? rgm_trim_url(esc_url_raw(wp_unslash($_POST['source_url']))) : '';
        $home   = isset($_POST['home_url']) ? rgm_trim_url(esc_url_raw(wp_unslash($_POST['home_url']))) : '';
        $target = isset($_POST['target_url']) ? rgm_trim_url(esc_url_raw(wp_unslash($_POST['target_url']))) : '';
        if (!preg_match('#^https?://#i', $source) || !preg_match('#^https?://#i', $target)) {
            wp_send_json_error(array('message' => 'Source and target URLs must both be full http(s) URLs.'), 400);
        }
        if ($state['sql_file'] === '' || !is_file($state['sql_file'])) {
            $guess = RGM_Store::work() . '/sqldump.sql';
            if (!is_file($guess)) {
                wp_send_json_error(array('message' => 'sqldump.sql is missing from the working folder.'), 400);
            }
            $state['sql_file'] = $guess;
        }
        $state['source_url'] = $source;
        $state['home_url']   = $home !== '' ? $home : $source;
        $state['target_url'] = $target;
        $state['phase']      = 'snapshot';
        $state['is_rollback']= false;
        $state['snapshot']   = array('offset' => 0, 'tables' => array(), 'table_index' => 0, 'row_offset' => 0, 'done' => false);
        RGM_Store::save($state);
        RGM_Store::log('Confirmed database replace. ' . $source . ' → ' . $target);
        $this->ok();
    }

    /**
     * @return void
     */
    private function rollback() {
        $this->require_word('ROLLBACK');
        $snap = RGM_Store::work() . '/snapshot-before.sql';
        if (!is_file($snap)) {
            wp_send_json_error(array('message' => 'No safety snapshot was found.'), 400);
        }
        global $wpdb;
        $state = RGM_Store::state();
        $state['sql_file']    = $snap;
        $state['old_prefix']  = $wpdb->prefix;
        $state['new_prefix']  = $wpdb->prefix;
        $state['is_rollback'] = true;
        $state['phase']       = 'import';
        $state['import']      = array('offset' => 0, 'statements' => 0, 'errors' => 0, 'done' => false, 'current' => '');
        RGM_Store::save($state);
        RGM_Store::log('Rollback started from the pre-restore snapshot.');
        $this->ok();
    }

    /**
     * @return void
     */
    private function start_golive() {
        $this->require_word('GOLIVE');
        $url = isset($_POST['target_url']) ? rgm_trim_url(esc_url_raw(wp_unslash($_POST['target_url']))) : '';
        if (!preg_match('#^https?://#i', $url)) {
            wp_send_json_error(array('message' => 'Enter a full production URL.'), 400);
        }
        $state = RGM_Store::state();
        if (in_array($state['phase'], array('extract', 'copy', 'snapshot', 'import', 'replace', 'finalize', 'golive'), true)) {
            wp_send_json_error(array('message' => 'A restore is still running. Finish or reset it first.'), 400);
        }
        $state['source_url'] = rgm_trim_url((string) get_option('siteurl'));
        $state['home_url']   = rgm_trim_url((string) get_option('home'));
        $state['target_url'] = $url;
        $state['old_path']   = '';
        $state['options']['replace_paths'] = false;
        $state['options']['replace_guids'] = false;
        $state['replace'] = array(
            'table_index' => 0,
            'last_pk'     => 0,
            'offset'      => 0,
            'changed'     => 0,
            'scanned'     => 0,
            'done'        => false,
            'current'     => '',
            'tables'      => array(),
        );
        $state['golive'] = array(
            'htaccess' => (isset($_POST['htaccess']) && $_POST['htaccess'] === 'subdir') ? 'subdir' : 'root',
            'redirect' => !empty($_POST['redirect']) && $_POST['redirect'] !== '0',
            'search'   => !empty($_POST['search']) && $_POST['search'] !== '0',
        );
        $state['phase'] = 'golive';
        RGM_Store::save($state);
        $this->issue_job_cookie();
        RGM_Store::log('Go-live started. ' . $state['source_url'] . ' → ' . $url);
        $this->ok();
    }

    /**
     * @param string $word Required word.
     * @return void
     */
    private function require_word($word) {
        $given = isset($_POST['confirm']) ? strtoupper(trim((string) wp_unslash($_POST['confirm']))) : '';
        if ($given !== $word) {
            wp_send_json_error(array('message' => 'Type ' . $word . ' to continue.'), 400);
        }
    }

    /**
     * @return int
     */
    private function install_held() {
        $src  = RGM_Store::work() . '/held/mu-plugins';
        $dest = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($src)) {
            return 0;
        }
        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }
        $n = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $base = rtrim(str_replace('\\', '/', $src), '/');
            $rel  = substr($full, strlen($base) + 1);
            if ($rel === 'rgm-guard.php' || $rel === 'rgm-staging-redirect.php') {
                continue;
            }
            $target = rgm_safe_join($dest, $rel);
            if (!$target) {
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent)) {
                wp_mkdir_p($parent);
            }
            if (@copy($full, $target)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return void
     */
    private function cleanup_files() {
        $targets = array(
            RGM_Store::work() . '/sqldump.sql',
            RGM_Store::work() . '/snapshot-before.sql',
            RGM_Store::work() . '/recovery.json',
            RGM_Store::work() . '/wp-config.original.php',
            RGM_Store::work() . '/meta.json',
            RGM_Store::work() . '/manifest.txt',
        );
        foreach ($targets as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $inbox = RGM_Store::inbox();
        $entries = @scandir($inbox);
        if (is_array($entries)) {
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || $name === 'index.php' || $name === '.htaccess') {
                    continue;
                }
                $path = $inbox . '/' . $name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        RGM_Store::log('Working SQL files and inbox archives deleted.');
    }
}
