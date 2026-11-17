(function () {
  var app = document.getElementById('rgm-app');
  if (!app || typeof RGM === 'undefined') {
    return;
  }

  var nonce = RGM.nonce;
  var state = null;
  var sources = [];
  var health = null;
  var preflight = null;
  var selected = '';
  var pumping = false;
  var pauseAfter = false;

  function $(id) { return document.getElementById(id); }
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function bytes(n) {
    n = Number(n) || 0;
    var u = ['B', 'KB', 'MB', 'GB'];
    var i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : String(n)) + ' ' + u[i];
  }
  function toast(message, kind) {
    var el = $('rgm-toast');
    el.hidden = false;
    el.className = 'rgm-toast' + (kind ? ' ' + kind : '');
    el.textContent = message;
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { el.hidden = true; }, 7000);
  }
  function flag(id) {
    var el = $(id);
    return el && el.checked ? '1' : '0';
  }

  function api(task, fields) {
    var body = new FormData();
    body.append('action', 'rgm_step');
    body.append('nonce', nonce);
    body.append('task', task);
    Object.keys(fields || {}).forEach(function (key) {
      if (fields[key] !== undefined && fields[key] !== null) {
        body.append(key, fields[key]);
      }
    });
    return fetch(RGM.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (res) {
        return res.text().then(function (text) {
          var json = null;
          try { json = JSON.parse(text); } catch (e) { json = null; }
          if (!json) {
            throw new Error('Empty or non-JSON response. The host may have timed out. Click Resume.');
          }
          if (json.data && json.data.nonce) nonce = json.data.nonce;
          if (!json.success) {
            throw new Error((json.data && json.data.message) || 'Request failed');
          }
          return json.data;
        });
      });
  }

  function apply(data) {
    if (data.state) state = data.state;
    if (data.sources) sources = data.sources;
    if (data.health) health = data.health;
    if (data.preflight) preflight = data.preflight;
    if (typeof data.log === 'string') {
      var log = $('rgm-log');
      log.textContent = data.log || 'No log entries yet.';
      log.scrollTop = log.scrollHeight;
    }
    render();
  }

  function stat(label, value, warn) {
    return '<div class="rgm-stat' + (warn ? ' warn' : '') + '"><span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>';
  }

  function renderPreflight() {
    if (!preflight) return;
    $('rgm-stage-url').textContent = preflight.home || RGM.home;
    $('rgm-inbox-path').textContent = preflight.inbox || '';
    $('rgm-abspath').textContent = preflight.abspath || '';
    if (!$('rgm-target').value) $('rgm-target').value = preflight.home || RGM.home;
    var html = '';
    html += stat('PHP', preflight.php);
    html += stat('WordPress', preflight.wp);
    html += stat('Memory', preflight.memory);
    html += stat('Max upload', preflight.upload);
    html += stat('Time limit', (preflight.time || '0') + 's');
    html += stat('zlib', preflight.zlib ? 'available' : 'missing', !preflight.zlib);
    html += stat('Disk free', preflight.disk_free == null ? 'unknown' : bytes(preflight.disk_free));
    html += stat('Table prefix', preflight.prefix);
    html += stat('Writable', preflight.writable ? 'yes' : 'check permissions', !preflight.writable);
    if (preflight.constants && Object.keys(preflight.constants).length) {
      html += stat('wp-config URLs', Object.keys(preflight.constants).join(', '), true);
    }
    $('rgm-preflight').innerHTML = html;
    $('rgm-safe-pill').textContent = preflight.safe || (health && health.safe)
      ? 'Safe mode is ON. Only this plugin loads.'
      : 'Safe mode is off.';
  }

  function renderSources() {
    var list = $('rgm-sources');
    if (!sources.length) {
      list.innerHTML = '<li class="rgm-muted">Nothing in the inbox yet.</li>';
      return;
    }
    list.innerHTML = sources.map(function (item) {
      var meta = item.type === 'archive' ? 'Archive · ' + bytes(item.size) : 'Extracted folder · SQL ' + bytes(item.size);
      return '<li><button type="button" data-id="' + esc(item.id) + '" class="' + (selected === item.id ? 'is-on' : '') + '">' +
        esc(item.name) + '<small>' + esc(meta) + '</small></button></li>';
    }).join('');
    Array.prototype.forEach.call(list.querySelectorAll('button'), function (btn) {
      btn.addEventListener('click', function () {
        selected = btn.getAttribute('data-id');
        renderSources();
        $('rgm-selected').textContent = 'Selected: ' + selected;
        $('rgm-start').disabled = false;
      });
    });
  }

  function phaseRank(phase) {
    var order = ['idle', 'extract', 'copy', 'confirm_db', 'snapshot', 'import', 'replace', 'finalize', 'done', 'golive', 'error'];
    var i = order.indexOf(phase || 'idle');
    return i < 0 ? 0 : i;
  }

  function renderPhases() {
    var phase = state && state.phase ? state.phase : 'idle';
    var map = {
      source: phase === 'idle' || phase === 'error',
      extract: phase === 'extract' || phase === 'copy' || phase === 'snapshot',
      confirm_db: phase === 'confirm_db',
      import: phase === 'import',
      replace: phase === 'replace' || phase === 'finalize',
      done: phase === 'done',
      golive: phase === 'golive'
    };
    Array.prototype.forEach.call(document.querySelectorAll('#rgm-phases li'), function (li) {
      var key = li.getAttribute('data-phase');
      li.classList.toggle('is-on', !!map[key]);
      var past = false;
      if (key === 'source' && phase !== 'idle') past = true;
      if (key === 'extract' && ['confirm_db', 'import', 'replace', 'finalize', 'done', 'golive'].indexOf(phase) !== -1) past = true;
      if (key === 'confirm_db' && ['snapshot', 'import', 'replace', 'finalize', 'done', 'golive'].indexOf(phase) !== -1) past = true;
      if (key === 'import' && ['replace', 'finalize', 'done', 'golive'].indexOf(phase) !== -1) past = true;
      if (key === 'replace' && ['done', 'golive'].indexOf(phase) !== -1 && phase !== 'golive') past = phase === 'done';
      li.classList.toggle('is-done', past && !map[key]);
    });
  }

  function show(id, on) {
    var el = $(id);
    if (el) el.hidden = !on;
  }

  function renderRun() {
    var phase = state.phase;
    var titles = {
      extract: ['Reading the archive', 'Extracting wp-content, skipping core, caches and the old wp-config.php'],
      copy: ['Copying files', 'Copying the extracted wp-content into this install'],
      snapshot: ['Safety snapshot', 'Exporting the current Strato database before it is replaced'],
      import: ['Importing sqldump.sql', 'Streaming the FastComet database into this install'],
      replace: ['Rewriting URLs', 'Serialized-safe search and replace'],
      finalize: ['Finishing', 'Permalinks, transients and staging .htaccess'],
      golive: ['Go-live replace', 'Rewriting the staging URL to the production domain']
    };
    var pair = titles[phase] || ['Working', phase];
    $('rgm-run-title').textContent = pair[0];
    $('rgm-run-kicker').textContent = pair[1];
    var rows = [];
    if (phase === 'extract') {
      rows.push(['Files written', state.extract.files]);
      rows.push(['Skipped', state.extract.skipped]);
      rows.push(['Bytes', bytes(state.extract.bytes)]);
      rows.push(['Current', state.extract.current || '…']);
    } else if (phase === 'copy') {
      rows.push(['Copied', state.copy.copied + ' / ' + state.copy.total]);
      rows.push(['Current', state.copy.current || '…']);
    } else if (phase === 'snapshot') {
      var tables = (state.snapshot.tables || []).length;
      rows.push(['Tables', state.snapshot.table_index + ' / ' + tables]);
    } else if (phase === 'import') {
      rows.push(['Statements', state.import.statements]);
      rows.push(['Errors', state.import.errors]);
      rows.push(['Current', state.import.current || '…']);
    } else if (phase === 'replace' || phase === 'golive') {
      rows.push(['Rows updated', state.replace.changed]);
      rows.push(['Scanned', state.replace.scanned]);
      rows.push(['Table', state.replace.current || '…']);
    }
    $('rgm-meters').innerHTML = rows.map(function (row) {
      return '<div class="rgm-meter"><span>' + esc(row[0]) + '</span><strong>' + esc(row[1]) + '</strong></div>';
    }).join('');
  }

  function renderConfirm() {
    $('rgm-source-url').value = state.source_url || '';
    $('rgm-home-url').value = state.home_url || state.source_url || '';
    $('rgm-target-confirm').value = state.target_url || ($('rgm-target').value || RGM.home);
    var html = '';
    html += stat('Detected URL', state.source_url || 'not found — type it', !state.source_url);
    html += stat('Prefix', (state.old_prefix || '?') + ' → ' + (state.new_prefix || '?'));
    html += stat('SQL', bytes(state.sql_bytes || 0));
    html += stat('Tables seen', state.sql_tables || '—');
    html += stat('Old path', state.old_path || 'not detected');
    if (state.meta && state.meta.dbPrefix) html += stat('meta.json', state.meta.dbPrefix);
    $('rgm-inspect').innerHTML = html;
  }

  function renderDone() {
    var report = state.report || {};
    var html = '';
    html += stat('siteurl', report.siteurl || '');
    html += stat('home', report.home || '');
    html += stat('Files', report.files || 0);
    html += stat('Rows rewritten', report.replaced || 0);
    html += stat('SQL errors', report.sql_errors || 0, (report.sql_errors || 0) > 0);
    html += stat('Theme in database', report.theme || '—');
    $('rgm-report').innerHTML = html;
    $('rgm-view').href = RGM.view;
    var h = '';
    if (health) {
      h += '<p><strong>' + (health.safe ? 'Safe mode is on.' : 'Safe mode is off.') + '</strong> ';
      h += health.theme_ok ? 'Theme folder exists.' : 'Theme folder is missing — stay in safe mode.';
      h += '</p>';
      if (health.missing && health.missing.length) {
        h += '<p>Missing plugin files, left inactive:</p><ul class="rgm-health-list">' +
          health.missing.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>';
      }
      if (health.present && health.present.length) {
        h += '<p>' + health.present.length + ' backed-up plugins are present and will load when you exit safe mode.</p>';
      }
    }
    if (state.held && state.held['mu-plugins'] && state.held['mu-plugins'].length) {
      h += '<p>Held mu-plugins: ' + state.held['mu-plugins'].length + '. Install them only after the admin loads cleanly.</p>';
    }
    $('rgm-health').innerHTML = h;
  }

  function render() {
    var phase = state && state.phase ? state.phase : 'idle';
    renderPreflight();
    renderSources();
    renderPhases();
    var busy = ['extract', 'copy', 'snapshot', 'import', 'replace', 'finalize', 'golive'].indexOf(phase) !== -1;
    show('panel-source', phase === 'idle' || phase === 'error');
    show('panel-plan', phase === 'idle' || phase === 'error');
    show('panel-run', busy);
    show('panel-confirm', phase === 'confirm_db');
    show('panel-done', phase === 'done' || phase === 'error');
    if (busy) renderRun();
    if (phase === 'confirm_db') renderConfirm();
    if (phase === 'done' || phase === 'error') renderDone();
    var err = $('rgm-error');
    if (state && state.error && phase === 'error') {
      err.hidden = false;
      err.textContent = state.error;
    } else {
      err.hidden = true;
    }
    $('rgm-start').disabled = !selected || busy;
  }

  function pump() {
    if (pumping) return;
    pumping = true;
    pauseAfter = false;
    var loop = function () {
      return api('run').then(function (data) {
        apply(data);
        var phase = state && state.phase;
        if (pauseAfter) return;
        if (data.busy) {
          return new Promise(function (resolve) { setTimeout(resolve, 800); }).then(loop);
        }
        if (['extract', 'copy', 'snapshot', 'import', 'replace', 'finalize', 'golive'].indexOf(phase) !== -1) {
          return loop();
        }
      });
    };
    loop().catch(function (err) {
      toast(err.message, 'bad');
    }).then(function () {
      pumping = false;
    });
  }

  function uploadFile(file) {
    var chunkSize = 1024 * 1024;
    var offset = 0;
    var box = $('rgm-upload-progress');
    var bar = $('rgm-upload-bar');
    var label = $('rgm-upload-label');
    box.hidden = false;
    var send = function () {
      var end = Math.min(offset + chunkSize, file.size);
      var body = new FormData();
      body.append('action', 'rgm_upload');
      body.append('nonce', nonce);
      body.append('filename', file.name);
      body.append('offset', String(offset));
      body.append('total', String(file.size));
      body.append('chunk', file.slice(offset, end), file.name);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', RGM.ajax, true);
      xhr.withCredentials = true;
      xhr.upload.onprogress = function (ev) {
        if (!ev.lengthComputable) return;
        var pct = Math.round(((offset + ev.loaded) / file.size) * 100);
        bar.style.width = pct + '%';
        label.textContent = 'Uploading ' + file.name + ' · ' + pct + '%';
      };
      xhr.onload = function () {
        var json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
        if (!json || !json.success) {
          var expected = json && json.data && json.data.expected;
          if (xhr.status === 409 && typeof expected === 'number' && expected !== offset) {
            offset = expected;
            send();
            return;
          }
          toast((json && json.data && json.data.message) || 'Upload failed', 'bad');
          return;
        }
        if (json.data.nonce) nonce = json.data.nonce;
        offset = json.data.received;
        bar.style.width = Math.round((offset / file.size) * 100) + '%';
        if (json.data.done) {
          label.textContent = 'Uploaded ' + json.data.name;
          toast('Archive is in the inbox.');
          selected = json.data.name;
          api('list').then(apply).catch(function (err) { toast(err.message, 'bad'); });
          return;
        }
        send();
      };
      xhr.onerror = function () { toast('Network error during upload.', 'bad'); };
      xhr.send(body);
    };
    send();
  }

  $('rgm-file').addEventListener('change', function () {
    if (this.files && this.files[0]) uploadFile(this.files[0]);
  });
  var drop = $('rgm-drop');
  ['dragover', 'dragenter'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-hot'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-hot'); });
  });
  drop.addEventListener('drop', function (e) {
    var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) uploadFile(file);
  });

  $('rgm-refresh').addEventListener('click', function () {
    api('list').then(apply).catch(function (err) { toast(err.message, 'bad'); });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var target = document.querySelector(btn.getAttribute('data-copy'));
    if (!target) return;
    var text = target.textContent || '';
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () { toast('Copied.'); });
    }
  });

  $('rgm-start').addEventListener('click', function () {
    if (!selected) return;
    api('start', {
      source_id: selected,
      target_url: $('rgm-target').value,
      skip_cache: flag('opt-skip-cache'),
      skip_updraft: flag('opt-skip-updraft'),
      skip_dropins: flag('opt-skip-dropins'),
      copy_mu_plugins: flag('opt-mu'),
      discourage_search: flag('opt-search'),
      replace_paths: flag('opt-paths'),
      replace_guids: flag('opt-guids')
    }).then(function (data) {
      apply(data);
      pump();
    }).catch(function (err) { toast(err.message, 'bad'); });
  });

  $('rgm-resume').addEventListener('click', pump);
  $('rgm-pause').addEventListener('click', function () {
    pauseAfter = true;
    toast('Will pause after this step.');
  });

  $('rgm-confirm').addEventListener('click', function () {
    api('confirm', {
      confirm: $('rgm-confirm-word').value,
      source_url: $('rgm-source-url').value,
      home_url: $('rgm-home-url').value,
      target_url: $('rgm-target-confirm').value
    }).then(function (data) {
      apply(data);
      pump();
    }).catch(function (err) { toast(err.message, 'bad'); });
  });

  $('rgm-rollback').addEventListener('click', function () {
    api('rollback', { confirm: $('rgm-rollback-word').value }).then(function (data) {
      apply(data);
      pump();
    }).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-reset').addEventListener('click', function () {
    api('reset', { confirm: $('rgm-reset-word').value }).then(apply).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-cleanup').addEventListener('click', function () {
    api('cleanup', { confirm: $('rgm-delete-word').value }).then(function (data) {
      apply(data);
      toast('Working files deleted.');
    }).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-flush').addEventListener('click', function () {
    api('permalinks').then(function (data) { apply(data); toast('Permalinks flushed.'); }).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-safe-off').addEventListener('click', function () {
    api('safe_off').then(function (data) { apply(data); toast('Safe mode off. Reload if the screen looks odd.'); }).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-held').addEventListener('click', function () {
    api('held').then(function (data) { apply(data); toast('Held mu-plugins installed.'); }).catch(function (err) { toast(err.message, 'bad'); });
  });
  $('rgm-golive').addEventListener('click', function () {
    var base = document.querySelector('input[name="rgm-base"]:checked');
    api('golive', {
      confirm: $('rgm-golive-word').value,
      target_url: $('rgm-live-url').value,
      htaccess: base ? base.value : 'root',
      redirect: $('rgm-live-redirect').checked ? '1' : '0',
      search: $('rgm-live-search').checked ? '1' : '0'
    }).then(function (data) {
      apply(data);
      pump();
    }).catch(function (err) { toast(err.message, 'bad'); });
  });

  api('boot').then(apply).catch(function (err) {
    $('rgm-log').textContent = err.message;
    toast(err.message, 'bad');
  });
})();
