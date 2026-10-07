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

];
