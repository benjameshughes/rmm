<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditAction: string
{
    case Login = 'auth.login';
    case LoginFromNewDevice = 'auth.login_new_device';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case PasswordReset = 'auth.password_reset';
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorConfirmed = 'auth.two_factor_confirmed';
    case TwoFactorDisabled = 'auth.two_factor_disabled';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserPasswordChanged = 'user.password_changed';
    case UserDeleted = 'user.deleted';
    case ScriptCreated = 'script.created';
    case ScriptUpdated = 'script.updated';
    case ScriptContentChanged = 'script.content_changed';
    case ScriptDeleted = 'script.deleted';
    case ScheduledTaskCreated = 'scheduled_task.created';
    case ScheduledTaskUpdated = 'scheduled_task.updated';
    case ScheduledTaskDeleted = 'scheduled_task.deleted';
    case DeviceEnrolled = 'device.enrolled';
    case DeviceApproved = 'device.approved';
    case DeviceUpdated = 'device.updated';
    case DeviceEnrolmentReset = 'device.enrolment_reset';
    case DeviceDeleted = 'device.deleted';
    case DeviceWakeRequested = 'device.wake_requested';
    case PathDeleted = 'device.path_deleted';
    case PathQuarantined = 'device.path_quarantined';
    case QuarantinePurged = 'device.quarantine_purged';
    case QuarantineRestored = 'device.quarantine_restored';
    case CommandQueued = 'command.queued';
    case CommandCancelled = 'command.cancelled';
    case AlertRuleCreated = 'alert_rule.created';
    case AlertRuleUpdated = 'alert_rule.updated';
    case AlertRuleDeleted = 'alert_rule.deleted';
    case AlertAcknowledged = 'alert.acknowledged';
    case AlertResolved = 'alert.resolved';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::LoginFromNewDevice => 'Signed in from a new device',
            self::LoginFailed => 'Failed sign-in',
            self::Logout => 'Signed out',
            self::PasswordReset => 'Password reset',
            self::TwoFactorEnabled => 'Two-factor enabled',
            self::TwoFactorConfirmed => 'Two-factor confirmed',
            self::TwoFactorDisabled => 'Two-factor disabled',
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserPasswordChanged => 'Password changed',
            self::UserDeleted => 'User deleted',
            self::ScriptCreated => 'Script created',
            self::ScriptUpdated => 'Script updated',
            self::ScriptContentChanged => 'Script content changed',
            self::ScriptDeleted => 'Script deleted',
            self::ScheduledTaskCreated => 'Schedule created',
            self::ScheduledTaskUpdated => 'Schedule updated',
            self::ScheduledTaskDeleted => 'Schedule deleted',
            self::DeviceEnrolled => 'Device enrolled',
            self::DeviceApproved => 'Device approved',
            self::DeviceUpdated => 'Device updated',
            self::DeviceEnrolmentReset => 'Enrolment reset',
            self::DeviceDeleted => 'Device deleted',
            self::DeviceWakeRequested => 'Wake requested',
            self::PathDeleted => 'Path deleted',
            self::PathQuarantined => 'Path quarantined',
            self::QuarantinePurged => 'Quarantine purged',
            self::QuarantineRestored => 'Quarantine restored',
            self::CommandQueued => 'Command queued',
            self::CommandCancelled => 'Command cancelled',
            self::AlertRuleCreated => 'Alert rule created',
            self::AlertRuleUpdated => 'Alert rule updated',
            self::AlertRuleDeleted => 'Alert rule deleted',
            self::AlertAcknowledged => 'Alert acknowledged',
            self::AlertResolved => 'Alert resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LoginFailed, self::TwoFactorDisabled, self::UserDeleted, self::ScriptDeleted, self::DeviceDeleted, self::PathDeleted, self::QuarantinePurged => 'red',
            self::LoginFromNewDevice, self::PasswordReset, self::UserPasswordChanged, self::ScriptContentChanged, self::DeviceEnrolmentReset, self::PathQuarantined => 'amber',
            self::Login, self::TwoFactorEnabled, self::TwoFactorConfirmed, self::DeviceApproved, self::AlertResolved, self::QuarantineRestored => 'green',
            self::CommandQueued, self::DeviceWakeRequested => 'blue',
            default => 'zinc',
        };
    }

    /**
     * Security-worthy actions ring every auditor's bell; null keeps an action to the audit log only.
     */
    public function notificationLevel(): ?NotificationLevel
    {
        return match ($this) {
            self::LoginFromNewDevice, self::LoginFailed, self::ScriptContentChanged, self::UserCreated => NotificationLevel::Warning,
            self::ScheduledTaskCreated, self::ScheduledTaskUpdated => NotificationLevel::Info,
            default => null,
        };
    }

    public function isSecurityEvent(): bool
    {
        return $this->notificationLevel() !== null;
    }

    /**
     * Alerts are acknowledged by people but also resolve themselves; only the people are worth auditing.
     */
    public function requiresActor(): bool
    {
        return in_array($this, [self::AlertAcknowledged, self::AlertResolved], true);
    }

    public function isSignIn(): bool
    {
        return in_array($this, [self::Login, self::LoginFromNewDevice], true);
    }

    /**
     * Who to show when no user did it: agents enrol themselves, failed sign-ins are strangers until proven otherwise.
     */
    public function unattributedActorName(): string
    {
        return match ($this) {
            self::DeviceEnrolled => 'Agent',
            self::LoginFailed => 'Unknown',
            default => 'System',
        };
    }
}
