<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Server
    |--------------------------------------------------------------------------
    |
    | PCs back their user profiles up with restic to rest-server on scarif,
    | run as `rest-server --append-only --no-auth` over TLS, with the
    | firewall letting only the office VLAN reach it. There are no per-PC
    | logins: each PC's repository at {rest_url}/{repository name}/ is
    | encrypted with its own repository password, and append-only means a PC
    | can add snapshots but never delete or rewrite them. Anything on the
    | VLAN can still write to the server, so give its ZFS dataset a quota.
    |
    | Enable backups on a PC's Backups tab names its repository after its
    | lowercase hostname and generates its password, both kept for good; its
    | first backup creates the repository.
    |
    | The repository passwords are stored encrypted with APP_KEY and exist
    | nowhere else. Keep APP_KEY in Bitwarden: if the RMM is lost without it,
    | no backup on scarif can be decrypted.
    |
    | ca_cert is an optional PEM certificate for a self-signed server; PCs
    | write it to disk and pass it to restic as --cacert.
    |
    | These values, the restic pin below and each PC's repository name and
    | password reach the backup scripts only when the agent fetches the
    | command (see `secrets` in config/scripts.php). They are never stored
    | with the command.
    |
    */

    'rest_url' => env('BACKUP_REST_URL'),

    'ca_cert' => env('BACKUP_CA_CERT'),

    /*
    |--------------------------------------------------------------------------
    | Master Key
    |--------------------------------------------------------------------------
    |
    | One extra password added as a second key to every PC's repository, so
    | the admin host can forget, prune and check them all with a single
    | password, and so the backups can still be opened if the RMM (and with
    | it every PC's own password) is lost. It lives only in the RMM's .env
    | and in Bitwarden. Null when unset, and then nothing is added.
    |
    | It reaches backup-files only while a PC's master key is still pending:
    | after a good backup the script adds it with `restic key add` unless it
    | already opens the repository, and the RMM stamps the PC
    | (backup_master_key_added_at) from the result. A stamped PC is never
    | sent it again, and no other script ever is.
    |
    */

    'master_password' => env('BACKUP_MASTER_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Generated Repository Password
    |--------------------------------------------------------------------------
    |
    | Length of each PC's generated repository password, letters and digits
    | only, so it needs no escaping in an environment variable.
    |
    */

    'generated_password_length' => 48,

    /*
    |--------------------------------------------------------------------------
    | Restic Release
    |--------------------------------------------------------------------------
    |
    | The one restic build every PC runs, from the GitHub release. The zip is
    | checked against `sha256`, as published in the release's SHA256SUMS,
    | before restic.exe is taken out of it and installed to
    | C:\ProgramData\RMM\restic. `exe_sha256` is the hash of that restic.exe
    | (unzip and sha256sum it), checked before every run, so a binary that
    | changed on disk is replaced instead of run as SYSTEM. Bump all four
    | together.
    |
    */

    'restic' => [
        'version' => '0.19.1',
        'download_url' => 'https://github.com/restic/restic/releases/download/v0.19.1/restic_0.19.1_windows_amd64.zip',
        'sha256' => 'da948ad707ed690426473aaba2046cd61f8f90f6f0e7dab6be0d5796531de67d',
        'exe_sha256' => 'b0dd1fd21eea5d8fe1325f55f7118213c21f36de8a261e04c0624a5ab9fd7830',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | PCs cannot delete anything from an append-only repository, so retention
    | runs on scarif itself against the repository folders, never on a PC or
    | through the append-only server:
    |
    |   restic forget --keep-within-daily 7d --keep-within-weekly 1m \
    |       --keep-within-monthly 1y --prune
    |
    | The --keep-within-* rules count back from the newest snapshot's time,
    | not from how many snapshots exist, so a compromised PC flooding its
    | repository with fake snapshots cannot push real ones out of retention.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | A PC with backups enabled is overdue once its last good backup (or,
    | before its first, the moment backups were enabled) is older than
    | stale_after_hours. An overdue PC, or one whose last run failed, raises
    | the built-in alert below, checked hourly and after every run.
    |
    */

    'stale_after_hours' => 26,

    'alert' => [
        'rule_name' => 'Backup overdue or failed',
        'severity' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups Tab
    |--------------------------------------------------------------------------
    |
    | Runs shown in a PC's history, and the most file errors kept per run.
    |
    */

    'history_shown' => 20,

    'errors_kept' => 20,

    'error_max_length' => 500,

    /*
    |--------------------------------------------------------------------------
    | Server Backups
    |--------------------------------------------------------------------------
    |
    | Linux servers back themselves up with their own scripts (restic to
    | scarif). Each script writes a JSON status file, and the agent sends
    | every one with its metrics report under `backups`, the same entries
    | every minute until a file changes. The RMM only watches: nothing here
    | ever runs a command on a server.
    |
    | max_jobs and max_snapshots cap what one report may hold; anything past
    | them is ignored. A job whose status file has not been reported for
    | forget_missing_after_hours shows as "status file missing" (it is kept,
    | never deleted, and can be forgotten from its Backups tab).
    |
    | Health, worst first: missing, then unreadable (the agent could not
    | parse the status file), then failed (the last run's exit code was not
    | 0), then overdue (the newest snapshot is older than
    | stale_after_minutes; jobs run hourly, so 150 allows one missed run),
    | then shrunk: the newest snapshot processed less than shrink_threshold
    | times the median of the shrink_baseline_snapshots before it that
    | processed anything. Zero-byte snapshots are left out of that median,
    | so a dump that keeps failing (mysqldump dies, restic backs up 0 bytes
    | every hour) stays flagged instead of becoming the new normal.
    |
    | Any of those raises the built-in alert below, one per job, checked as
    | reports arrive and hourly. It resolves once the job is healthy.
    |
    */

    'servers' => [
        'max_jobs' => 32,
        'max_snapshots' => 200,
        'forget_missing_after_hours' => 72,
        'stale_after_minutes' => 150,
        'shrink_threshold' => 0.5,
        'shrink_baseline_snapshots' => 5,

        'alert' => [
            'rule_name' => 'Server backup overdue, failed or shrunk',
            'severity' => 'warning',
        ],

        /*
        | The Backups tab charts each job's snapshots over chart_days, grouped
        | by the database into about chart_points buckets, and lists them
        | snapshots_per_page at a time.
        */

        'chart_days' => 30,
        'chart_points' => 150,
        'snapshots_per_page' => 10,
    ],

];
