<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Agent\FetchLatestAgentVersion;
use App\Actions\Agent\SyncAgentOutdatedAlert;
use App\Enums\DeviceStatus;
use App\Models\AlertRule;
use App\Models\Device;
use Illuminate\Console\Command;

final class CheckAgentVersion extends Command
{
    protected $signature = 'agent:check-version';

    protected $description = 'Fetch the latest agent release and raise or resolve outdated-agent alerts';

    public function handle(FetchLatestAgentVersion $fetchLatestVersion, SyncAgentOutdatedAlert $syncAlert): int
    {
        $latestVersion = $fetchLatestVersion();

        if ($latestVersion === null) {
            $this->components->error('Could not read the latest agent release. The previously cached version is kept.');

            return self::FAILURE;
        }

        $rule = AlertRule::agentOutdated();

        $devices = Device::query()
            ->where('status', DeviceStatus::Active)
            ->whereNotNull('agent_version')
            ->with('unresolvedAlerts')
            ->get()
            ->each(fn (Device $device) => $syncAlert($device, $latestVersion, $rule));

        $outdatedCount = $devices->filter(fn (Device $device): bool => $device->isAgentOutdated($latestVersion))->count();

        $this->components->info("Latest agent is {$latestVersion}. {$outdatedCount} of {$devices->count()} devices are behind.");

        return self::SUCCESS;
    }
}
