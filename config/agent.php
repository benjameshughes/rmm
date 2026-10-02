<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Latest Release
    |--------------------------------------------------------------------------
    |
    | `agent:check-version` asks GitHub for the newest agent release every
    | hour and caches its version forever under `latest_version_cache_key`,
    | so pages and metrics posts never call GitHub themselves. A failed check
    | keeps the previously cached version.
    |
    */

    'releases_url' => 'https://api.github.com/repos/benjameshughes/rmm/releases/latest',

    'latest_version_cache_key' => 'agent.latest_version',

    /*
    | The built-in script that installs a new agent. Its command finishes when
    | the device reports a new version, since the update stops the agent that
    | ran it before it can post a result.
    */

    'update_script_slug' => 'update-agent',

    'release_check_timeout_seconds' => 10,

    /*
    |--------------------------------------------------------------------------
    | Script Parameters
    |--------------------------------------------------------------------------
    |
    | Older agents ignore the `parameters` field and would run a parameterised
    | script with none set, so such scripts are only queued on agents at or
    | above this version.
    |
    */

    'parameters_min_version' => '0.6.2',

    /*
    |--------------------------------------------------------------------------
    | Outdated Agent Alert
    |--------------------------------------------------------------------------
    |
    | The built-in alert rule created the first time an outdated agent is
    | checked. Switch it off under Alert Rules to stop raising these alerts.
    |
    */

    'outdated_alert' => [
        'rule_name' => 'Agent outdated',
        'severity' => 'warning',
    ],

];
