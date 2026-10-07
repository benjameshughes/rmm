<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ignored Printers
    |--------------------------------------------------------------------------
    |
    | Windows agents post every print queue on the PC. Queues whose name
    | matches one of these patterns (Str::is wildcards, any case) are
    | software printers that never jam, so they are dropped on arrival.
    |
    */

    'ignored_names' => [
        'Microsoft Print to PDF',
        'Microsoft XPS Document Writer*',
        '*OneNote*',
        'Fax*',
        'Send To *',
    ],

    /*
    |--------------------------------------------------------------------------
    | Problems
    |--------------------------------------------------------------------------
    |
    | A queue is a problem when Windows flags the printer or one of its jobs
    | with an error, when its oldest job has waited at least
    | oldest_job_minutes (measured against when the agent took the
    | snapshot), or when it holds more than max_jobs jobs. An idle queue
    | often reports no status even with the printer switched off (MS KB
    | 160129), so the job rules catch what the status flags miss.
    |
    | Only PCs that are plainly online are judged. Printers are not judged
    | at all once the agent has not reported them for stale_after_minutes,
    | so a stopped reporter never leaves an old problem on show.
    |
    */

    'problem' => [
        'oldest_job_minutes' => 5,
        'max_jobs' => 10,
    ],

    'stale_after_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Payload Limits
    |--------------------------------------------------------------------------
    */

    'max_printers' => 100,

    'max_jobs_per_printer' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Printers Tab
    |--------------------------------------------------------------------------
    |
    | A flooded queue can hold hundreds of jobs; the tab lists the first
    | jobs_shown of them and counts the rest.
    |
    */

    'jobs_shown' => 50,

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | A printer problem raises one alert per printer once it has lasted
    | after_minutes on a plainly online PC; a stopped print spooler raises
    | its own. Both come from built-in rules that can be switched off under
    | Alert Rules.
    |
    */

    'alerts' => [
        'after_minutes' => 5,
        'printer_problem' => [
            'rule_name' => 'Printer problem',
            'severity' => 'critical',
        ],
        'spooler_down' => [
            'rule_name' => 'Print spooler down',
            'severity' => 'critical',
        ],
    ],

];
