<?php

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Audit rows older than this many days are pruned by the hourly
    | model:prune run. The audit log channel keeps its files as long.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    */

    'per_page' => 25,

    /*
    |--------------------------------------------------------------------------
    | Details Length
    |--------------------------------------------------------------------------
    |
    | The audit page cuts each row's change summary to this many characters
    | so a long ad-hoc command does not swallow the table.
    |
    */

    'details_max_length' => 160,

    /*
    |--------------------------------------------------------------------------
    | Audited Attributes
    |--------------------------------------------------------------------------
    |
    | The attributes worth auditing on each Auditable model. An update that
    | touches none of them is not recorded, which is what keeps metric posts
    | (last_seen, last_ip, agent_version, mac_addresses) out of the log.
    | Attributes a model does not have yet are skipped.
    |
    */

    'attributes' => [
        Script::class => ['name', 'description', 'category', 'platform', 'script_type', 'script_content', 'is_system', 'timeout_seconds', 'requires_admin', 'parameters'],
        ScheduledTask::class => ['name', 'action', 'script_id', 'cron_expression', 'target_type', 'target_id', 'is_active', 'parameters'],
        Device::class => ['hostname', 'status', 'device_group_id', 'api_key_hash', 'backup_repository_name', 'backup_repository_password'],
        DeviceCommand::class => ['device_id', 'script_id', 'scheduled_task_id', 'script_type', 'timeout_seconds', 'parameters', 'status'],
        AlertRule::class => ['name', 'metric', 'operator', 'threshold', 'duration_minutes', 'severity', 'is_active'],
        Alert::class => ['status'],
        User::class => ['name', 'email', 'password'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret Attributes
    |--------------------------------------------------------------------------
    |
    | Never written to the audit log. Changes to these are recorded by name
    | only, so the log says the password changed but never what to.
    |
    */

    'secret_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_key_hash',
        'pending_api_key',
        'backup_repository_password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Hashed Attributes
    |--------------------------------------------------------------------------
    |
    | Too large to copy into every audit row, so the log keeps a sha256 of
    | the before and after values under "<attribute>_sha256".
    |
    */

    'hashed_attributes' => [
        'script_content',
    ],

];
