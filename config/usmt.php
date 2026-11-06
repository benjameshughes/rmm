<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | User State Migration Tool
    |--------------------------------------------------------------------------
    |
    | Migrate User moves one user's profile (documents, desktop, app
    | settings) into a staff account with Microsoft's USMT, either from an
    | old PC through its restic repository or on the same PC. It sits beside
    | the restic profile restore and never replaces it.
    |
    | USMT ships only inside the Windows ADK, so install-usmt downloads
    | adksetup.exe from adk_download_url, checks it is signed by Microsoft,
    | installs just the User State Migration Tool feature and copies its
    | amd64 folder into C:\ProgramData\RMM\{folder}, next to restic and
    | locked the same way. Bump the URL to move to a newer ADK.
    |
    | xml_files are the migration rules passed to scanstate and loadstate as
    | /i:, from the USMT folder. log_verbosity is USMT's /v: level (0 to 13;
    | 5 is verbose and status output).
    |
    | These values reach the USMT scripts only when the agent fetches the
    | command (see `secrets` in config/scripts.php).
    |
    */

    'adk_download_url' => 'https://go.microsoft.com/fwlink/?linkid=2289980',

    'folder' => 'usmt',

    'xml_files' => ['MigDocs.xml', 'MigApp.xml'],

    'log_verbosity' => 5,

];
