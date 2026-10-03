<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class RecordModelChange
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private Guard $guard,
    ) {}

    /**
     * Audit an Auditable model's created, updated or deleted event. Secrets are
     * recorded by name only and large attributes as a sha256, so the audit log
     * never becomes a second copy of anything sensitive.
     *
     * @param  'created'|'updated'|'deleted'  $event
     */
    public function __invoke(Model $model, string $event): void
    {
        $action = $this->action($model, $event);

        if ($action === null || ($action->requiresActor() && $this->guard->guest())) {
            return;
        }

        ($this->recordAuditEvent)($action, $model, [
            'label' => $this->label($model),
            ...($event === 'updated' ? $this->changes($model) : ['attributes' => $this->snapshot($model)]),
            ...($action === AuditAction::CommandQueued ? $this->commandDetails($model) : []),
        ]);
    }

    /**
     * @param  'created'|'updated'|'deleted'  $event
     */
    private function action(Model $model, string $event): ?AuditAction
    {
        return match (true) {
            $model instanceof Script => match ($event) {
                'created' => AuditAction::ScriptCreated,
                'updated' => $model->wasChanged('script_content') ? AuditAction::ScriptContentChanged : AuditAction::ScriptUpdated,
                'deleted' => AuditAction::ScriptDeleted,
            },
            $model instanceof ScheduledTask => match ($event) {
                'created' => AuditAction::ScheduledTaskCreated,
                'updated' => AuditAction::ScheduledTaskUpdated,
                'deleted' => AuditAction::ScheduledTaskDeleted,
            },
            $model instanceof Device => match ($event) {
                'created' => AuditAction::DeviceEnrolled,
                'updated' => $this->deviceUpdateAction($model),
                'deleted' => AuditAction::DeviceDeleted,
            },
            $model instanceof DeviceCommand => match (true) {
                $event === 'created' => AuditAction::CommandQueued,
                $event === 'updated' && $model->wasChanged('status') && $model->status === CommandStatus::Cancelled => AuditAction::CommandCancelled,
                default => null,
            },
            $model instanceof AlertRule => match ($event) {
                'created' => AuditAction::AlertRuleCreated,
                'updated' => AuditAction::AlertRuleUpdated,
                'deleted' => AuditAction::AlertRuleDeleted,
            },
            $model instanceof Alert => $event === 'updated' ? $model->status->auditAction() : null,
            $model instanceof User => match ($event) {
                'created' => AuditAction::UserCreated,
                'updated' => $model->wasChanged('password') ? AuditAction::UserPasswordChanged : AuditAction::UserUpdated,
                'deleted' => AuditAction::UserDeleted,
            },
            default => null,
        };
    }

    /**
     * Approval issues a key hash and a reset clears it; anything else is a plain edit.
     */
    private function deviceUpdateAction(Device $device): AuditAction
    {
        return match (true) {
            $device->wasChanged('api_key_hash') && $device->api_key_hash === null => AuditAction::DeviceEnrolmentReset,
            $device->wasChanged('api_key_hash') && $device->status === DeviceStatus::Active => AuditAction::DeviceApproved,
            default => AuditAction::DeviceUpdated,
        };
    }

    private function label(Model $model): string
    {
        return match (true) {
            $model instanceof Device => $model->hostname,
            $model instanceof User => $model->email,
            $model instanceof DeviceCommand => "{$model->displayName()} on {$model->device?->hostname}",
            $model instanceof Alert => "{$model->conditionLabel()} on {$model->device?->hostname}",
            default => (string) $model->getAttribute('name'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        return $this->presentableAttributes($model, collect($model->auditedAttributes()))
            ->mapWithKeys(fn (string $attribute): array => $this->present($attribute, $model->getAttribute($attribute)))
            ->all();
    }

    /**
     * @return array{changes?: array<string, array{from: mixed, to: mixed}>, secrets_changed?: array<int, string>}
     */
    private function changes(Model $model): array
    {
        $changed = collect($model->getChanges())->keys()->intersect($model->auditedAttributes())->values();
        $secrets = $changed->intersect(config('audit.secret_attributes'))->values();

        $changes = $this->presentableAttributes($model, $changed)
            ->mapWithKeys(function (string $attribute) use ($model): array {
                $from = $this->present($attribute, $model->getOriginal($attribute));
                $to = $this->present($attribute, $model->getAttribute($attribute));

                return [key($to) => ['from' => current($from), 'to' => current($to)]];
            });

        return array_filter([
            'changes' => $changes->all(),
            'secrets_changed' => $secrets->all(),
        ]);
    }

    /**
     * The script behind a queued command is already audited, so a scripted command
     * carries the script's name; an ad-hoc command has no script, so its full text
     * is the only record of what ran.
     *
     * @return array{script_name?: string, command?: string}
     */
    private function commandDetails(DeviceCommand $command): array
    {
        return $command->script_id === null
            ? ['command' => $command->script_content]
            : ['script_name' => $command->script?->name];
    }

    /**
     * @param  Collection<int, string>  $attributes
     * @return Collection<int, string>
     */
    private function presentableAttributes(Model $model, Collection $attributes): Collection
    {
        return $attributes
            ->filter(fn (string $attribute): bool => array_key_exists($attribute, $model->getAttributes()))
            ->reject(fn (string $attribute): bool => in_array($attribute, config('audit.secret_attributes'), true))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $attribute, mixed $value): array
    {
        if (in_array($attribute, config('audit.hashed_attributes'), true)) {
            return ["{$attribute}_sha256" => $value === null ? null : hash('sha256', (string) $value)];
        }

        return [$attribute => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Arrayable => $value->toArray(),
            default => $value,
        }];
    }
}
