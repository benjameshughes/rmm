<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Events\DeviceWakeRequested;

final class RecordDeviceWake
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    public function handle(DeviceWakeRequested $event): void
    {
        ($this->recordAuditEvent)(AuditAction::DeviceWakeRequested, $event->device, [
            'label' => $event->device->hostname,
            'mac_addresses' => $event->device->mac_addresses,
        ]);
    }
}
