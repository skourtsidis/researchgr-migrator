# Research GR Migrator

WordPress plugin that restores a **FastComet / cPanel WP Toolkit** backup onto another WordPress install, then prepares the `research.gr` cutover.

The admin screen is in English. This file is bilingual so the GitHub steps are usable either way.

The archive this plugin expects looks like the one downloaded from WP Toolkit:

```text
research.gr__2026-11-17T18_15_29+0000.tar.gz
├── files/                      WordPress tree
│   ├── .htaccess.wpt-backup    the old .htaccess, renamed by WP Toolkit
│   ├── wp-config.php           old host credentials — never copied over
│   ├── wp-admin/               skipped
│   ├── wp-includes/            skipped
│   └── wp-content/             themes, plugins, uploads, mu-plugins
├── meta.json                   usually {"dbPrefix":"wp_","login":null,"password":null}
└── sqldump.sql                 database dump
```

It does **not** read `.wpress`, Duplicator or Updraft packages.

## What it does

1. Accepts the `.tar.gz` in 1 MB browser chunks, or a file/folder already uploaded by FTP.
2. Extracts `wp-content` with a pure-PHP tar reader (no `exec`, no Phar). Core files and the old `wp-config.php` are skipped.
3. Holds `object-cache.php`, `advanced-cache.php` and `db.php` so a Redis drop-in cannot blank the Strato site.
4. Writes a safety snapshot of the current database.
5. Streams `sqldump.sql`, rewriting the table prefix to the prefix in this `wp-config.php`.
6. Keeps the administrator who started the restore logged in.
7. Turns on safe mode (only this plugin, fallback theme) so a fatal plugin cannot lock you out.
8. Rewrites `https://research.gr` to the staging URL, including serialized PHP and `https:\/\/` JSON.
9. Writes a subdirectory `.htaccess` for `kourtsidis.com/researchgr`.
10. Later, **Go live** rewrites that staging URL to `https://research.gr` and can redirect the temporary host.

Do not commit the `.tar.gz` or `sqldump.sql` to GitHub. They contain the live database.

---

## GitHub: create the plugin zip

The repository root **is** the plugin. Upload the contents of the `researchgr-migrator` folder, not a parent folder and not `index.html`.

### 1. Create an empty repository

1. Open [github.com/new](https://github.com/new).
2. Repository name: `researchgr-migrator`.
3. Private is fine. Public is fine. There are no secrets in this code.
4. Do **not** add a README, .gitignore or license. This folder already has them.
5. Create repository.

### 2. Push the files

From the `researchgr-migrator` directory, after [installing Git](https://git-scm.com/downloads):

```bash
git init
git add .
git commit -m "Add Research GR Migrator"
git branch -M main
git remote add origin https://github.com/YOUR_USER/researchgr-migrator.git
git push -u origin main
```

Replace `YOUR_USER`. GitHub will ask you to sign in. GitHub Desktop can do the same thing if you would rather not use the terminal: File → Add local repository → Publish repository.

The web uploader flattens folders. Do not use “Upload files” unless you are sure the `includes/` and `assets/` directories survived.

### 3. Let GitHub build the zip

This repo contains `.github/workflows/build-zip.yml`. It builds a WordPress-ready zip:

```text
researchgr-migrator.zip
└── researchgr-migrator/
    └── researchgr-migrator.php
```

**Either tag a release** (the zip is attached to the release):

```bash
git tag v1.0.0
git push origin v1.0.0
```

Then open **Releases**. The file is `researchgr-migrator-1.0.0.zip`.

**Or run the workflow by hand:**

1. Repository → **Actions**.
2. If GitHub asks, enable Actions for the repository.
3. **Build plugin zip** → **Run workflow** → **Run workflow**.
4. Open the finished run → **Artifacts** → `researchgr-migrator`.

Install that zip. Do not install GitHub’s green “Code → Download ZIP” unless you have no other choice: that folder is named `researchgr-migrator-main`, which still works, but the Action zip is the clean one.

### 4. Install it on the staging site

1. Open `https://kourtsidis.com/researchgr/wp-admin`.
2. Plugins → Add New → Upload Plugin → choose the zip → Install → Activate.
3. Open **RG Migrator** in the left menu.

---

## Staging restore

1. Put `research.gr__2026-11-17T18_15_29+0000.tar.gz` in the inbox path shown on the screen (FTP), or upload it in the browser.
   - Large sites: extract the archive on your computer (7-Zip / `tar -xzf`) and upload the folder that contains `files/`, `sqldump.sql` and `meta.json`.
2. Target URL defaults to the current site, `https://kourtsidis.com/researchgr`.
3. Start restore. Wait until it asks you to confirm the database.
4. Check the detected source URL. It should be `https://research.gr` (or `https://www.research.gr`).
5. Type `RESTORE`.
6. When the report appears, open the site in a private window. Permalinks: if a page 404s, click **Flush permalinks**, then Settings → Permalinks → Save.
7. **Exit safe mode** only after wp-admin still loads. That reactivates the backed-up plugins.
8. Leave this plugin active until DNS has moved. Then delete it, and delete the private working files (type `DELETE`).

`wp-config.php` on Strato is not overwritten. If `WP_HOME` or `WP_SITEURL` is hardcoded there, the database replace will not change the visible URL. Remove those two lines first.

## Go live

Test on the staging URL before this. Keep FastComet online until `research.gr` answers from Strato.

1. In Strato, add `research.gr` as an external domain. Point its directory at the folder that contains this WordPress (`ABSPATH` is shown in the plugin).
2. If `@research.gr` mail must survive, copy MX, SPF and DKIM from the current DNS **before** changing nameservers. Changing nameservers moves mail as well. The safer cutover is often to leave DNS at ip.gr and change only the A record to the Strato server IP.
3. In the plugin, type `GOLIVE` and run the replacement to `https://research.gr`. Do this in the same window of time as the DNS change. After the replacement, the staging URL’s assets point at `research.gr`.
4. At [ip.gr](https://www.ip.gr): control panel → Domains → the gear under Nameservers. Use the nameservers shown inside Strato. They are often `ns1.stratoserver.net` and `ns2.stratoserver.net`. Confirm the pair in the Strato panel; do not guess if the panel shows different hosts.
5. `.gr` registry updates are usually visible within 3–6 hours, sometimes up to 24–48 hours worldwide.
6. When the site answers, allow search engines again, remove the redirect mu-plugin if you no longer want it, and delete this plugin.

## Rollback

Type `ROLLBACK` on the migrator screen. That re-imports the snapshot taken before `sqldump.sql`. It cannot help if the snapshot was deleted.

---

## Οδηγίες στα ελληνικά

### Τι είναι το αρχείο

Το `research.gr__2026-11-17T18_15_29+0000.tar.gz` είναι αντίγραφο του **WP Toolkit** της FastComet, όχι αντίγραφο του All-in-One ή του Updraft. Μέσα έχει `files/`, `sqldump.sql` (περίπου 9 MB στη λήψη που φαίνεται) και `meta.json`. Το `.htaccess` είναι αποθηκευμένο ως `files/.htaccess.wpt-backup`.

### Ανέβασμα στο GitHub και δημιουργία zip

1. Φτιάξε άδειο repository με όνομα `researchgr-migrator`. Μην προσθέσεις README από το GitHub.
2. Ανέβασε **τα περιεχόμενα** του φακέλου `researchgr-migrator` (το `researchgr-migrator.php` πρέπει να είναι στη ρίζα του repo).
3. Μην ανεβάσεις το `.tar.gz` του site. Έχει τη βάση και προσωπικά δεδομένα.
4. Το αρχείο `.github/workflows/build-zip.yml` φτιάχνει το zip.
5. Είτε `git tag v1.0.0 && git push origin v1.0.0` και κατεβάζεις το zip από το Release, είτε Actions → Build plugin zip → Run workflow → Artifacts.
6. Στο `kourtsidis.com/researchgr`: Πρόσθετα → Νέο → Μεταφόρτωση → ενεργοποίηση → μενού **RG Migrator**.

### Σειρά εργασιών

1. Δοκίμασε το restore στη διεύθυνση `https://kourtsidis.com/researchgr`.
2. Μην αλλάξεις nameservers πριν το Strato έχει το domain και πριν πατήσεις Go live.
3. Κράτα τη FastComet ανοιχτή μέχρι το `research.gr` να απαντά από τη Strato.
4. Αν έχεις email στο `@research.gr`, μην αλλάξεις nameservers στα τυφλά. Σημείωσε MX/SPF/DKIM. Συχνά είναι ασφαλέστερο να μείνουν τα DNS στην ip.gr και να αλλάξει μόνο η εγγραφή A στην IP της Strato.
5. Αν αλλάξεις nameservers, πάρε τα ακριβή ονόματα από το panel της Strato. Συχνά είναι `ns1.stratoserver.net` και `ns2.stratoserver.net`.
6. Στην ip.gr: Πίνακας ελέγχου → Domains → γρανάζι στα Nameservers. Η ζώνη `.gr` ενημερώνεται συνήθως σε 3–6 ώρες.
7. Στο τέλος διέγραψε το plugin.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
