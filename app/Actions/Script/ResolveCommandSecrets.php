<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use InvalidArgumentException;

/**
 * The secrets a command's script declares in config/scripts.php, worked out
 * at the moment the agent fetches it and handed over with its parameters.
 * This is the only place they are put together: they are never written to
 * the command, its parameters, the audit log or any log line.
 *
 * Backup secrets (repository name and password) are the command's own device's, or, for a script that
 * declares a SourceDevice parameter, the device that parameter names. Either
 * must be a Windows PC with backups enabled; anything else gets blanks, which
 * the scripts report as not set up. The master password goes only to a
 * script that declares it (backup-files), and only while that PC's master
 * key is pending: once it is stamped as added, it is sent blank. Ad-hoc commands and user scripts never
 * declare secrets, and a device that is not Windows never receives any.
 */
final class ResolveCommandSecrets
{
    /**
     * @return array<string, string> Keyed by secret name
     */
    public function __invoke(DeviceCommand $command): array
    {
        $names = $command->script?->secretNames() ?? [];

        if ($names === [] || $command->device->platform() !== ScriptPlatform::Windows) {
            return [];
        }

        $source = $this->credentialSource($command);

        return collect($names)
            ->mapWithKeys(fn (string $name): array => [$name => $this->value($name, $source)])
            ->all();
    }

    private function credentialSource(DeviceCommand $command): ?Device
    {
        $declaresSource = $command->script->parameters->contains('name', 'SourceDevice');
        $sourceId = $declaresSource ? ($command->parameters['SourceDevice'] ?? null) : null;
        $source = $sourceId === null ? $command->device : Device::query()->find((int) $sourceId);

        return $source?->canBackUp() ? $source : null;
    }

    private function value(string $name, ?Device $source): string
    {
        return match ($name) {
            'RestUrl' => rtrim((string) config('backup.rest_url'), '/'),
            'RestCaCert' => (string) config('backup.ca_cert'),
            'RepositoryName' => (string) $source?->backup_repository_name,
            'ResticPassword' => (string) $source?->backup_repository_password,
            'ResticVersion' => config('backup.restic.version'),
            'ResticDownloadUrl' => config('backup.restic.download_url'),
            'ResticSha256' => config('backup.restic.sha256'),
            'ResticExeSha256' => config('backup.restic.exe_sha256'),
            'MasterPassword' => $source?->isBackupMasterKeyPending ? (string) config('backup.master_password') : '',
            default => throw new InvalidArgumentException("No value is known for the script secret '{$name}'."),
        };
    }
}
