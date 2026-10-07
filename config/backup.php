<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Server
    |--------------------------------------------------------------------------
    |
    | PCs back their user profiles up with restic to rest-server on scarif,
    | run with --append-only --private-repos over TLS. Each PC has its own
    | basic-auth user (its lowercase hostname) and repository at
    | {rest_url}/{username}/, both set up by hand on scarif before the PC's
    | credentials are entered on its Backups tab. A PC can add snapshots but
    | never delete or rewrite them.
    |
    | ca_cert is an optional PEM certificate for a self-signed server; PCs
    | write it to disk and pass it to restic as --cacert.
    |
    | These values, the restic pin below and each PC's credentials reach the
    | backup scripts only when the agent fetches the command (see `secrets`
    | in config/scripts.php). They are never stored with the command.
    |
    */

    'rest_url' => env('BACKUP_REST_URL'),

    'ca_cert' => env('BACKUP_CA_CERT'),

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
    | runs on Ben's admin host with full access to scarif, never on a PC:
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
    | A PC with credentials is overdue once its last good backup (or, before
    | its first, the moment its credentials were set) is older than
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
