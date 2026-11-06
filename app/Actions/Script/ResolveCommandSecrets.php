<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\StaffAccount;
use App\Models\ZebraTemplate;
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
 * key is pending: once it is stamped as added, it is sent blank. The disk
 * usage scan gets the space-hog folders it must keep from config/disk_usage.php,
 * and the delete and restore scripts the protected paths from config/devices.php
 * as JSON, so the PC refuses them too. backup-restore-profile gets the profile
 * folders it restores from config/backup.php as JSON. The USMT scripts get
 * config/usmt.php's ADK URL, folder, XML list (as JSON) and log level. install-zebra gets the ZDesigner driver
 * pin and match patterns from config/zebra.php and the Zebra template's
 * settings. sync-local-user gets the staff temp password from config/staff.php,
 * which it sets only when its ResetPassword parameter says so or the user is
 * new to the PC, and only while the staff account its StaffAccountId names
 * still exists under the same username; anything else gets a blank, which
 * the script refuses, so a deleted account is never recreated. set-local-password
 * gets the password chosen on the Staff page, taken once from command_secrets
 * (which deletes it) under the same account checks. Ad-hoc commands and user scripts never
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
            ->mapWithKeys(fn (string $name): array => [$name => $this->value($name, $source, $command)])
            ->all();
    }

    private function staffTempPassword(DeviceCommand $command): string
    {
        $account = StaffAccount::query()->find((int) ($command->parameters['StaffAccountId'] ?? 0));

        return $account !== null && $account->username === ($command->parameters['Username'] ?? null) ? (string) config('staff.temp_password') : '';
    }

    /**
     * Taken from command_secrets, which deletes it, so it reaches the agent once.
     * It is still taken (and so gone) when the account was deleted or renamed,
     * but then handed over blank.
     */
    private function staffPassword(DeviceCommand $command): string
    {
        $password = $command->takeSecret('StaffPassword');

        return $this->staffTempPassword($command) === '' ? '' : $password;
    }

    private function credentialSource(DeviceCommand $command): ?Device
    {
        $declaresSource = $command->script->parameters->contains('name', 'SourceDevice');
        $sourceId = $declaresSource ? ($command->parameters['SourceDevice'] ?? null) : null;
        $source = $sourceId === null ? $command->device : Device::query()->find((int) $sourceId);

        return $source?->canBackUp() ? $source : null;
    }

    private function value(string $name, ?Device $source, DeviceCommand $command): string
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
            'ZebraDriverUrl' => (string) config('zebra.driver.url'),
            'ZebraDriverSha256' => (string) config('zebra.driver.sha256'),
            'ZebraDriverInf' => (string) config('zebra.driver.inf'),
            'ZebraDriverName' => (string) config('zebra.driver.name'),
            'ZebraDriverPrefix' => (string) config('zebra.driver.prefix'),
            'ZebraMatch' => implode('|', config('zebra.match')),
            'ZebraPnpWaitSeconds' => (string) config('zebra.pnp_wait_seconds'),
            'ZebraTemplate' => (string) ZebraTemplate::current()?->settings,
            'ZebraTemplateDriver' => (string) ZebraTemplate::current()?->driver_name,
            'ZebraTemplateMaxChars' => (string) config('zebra.template_max_chars'),
            'DiskUsageKeep' => collect(config('disk_usage.culprits'))->pluck('glob')->unique()->implode(config('disk_usage.keep_separator')),
            'StaffTempPassword' => $this->staffTempPassword($command),
            'StaffPassword' => $this->staffPassword($command),
            'RestoreProfileFolders' => json_encode(config('backup.restore_profile.folders'), JSON_THROW_ON_ERROR),
            'UsmtAdkUrl' => (string) config('usmt.adk_download_url'),
            'UsmtFolder' => (string) config('usmt.folder'),
            'UsmtXmlFiles' => json_encode(config('usmt.xml_files'), JSON_THROW_ON_ERROR),
            'UsmtVerbosity' => (string) config('usmt.log_verbosity'),
            'DeletePathProtected' => json_encode(config('devices.delete_path.protected'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            default => throw new InvalidArgumentException("No value is known for the script secret '{$name}'."),
        };
    }
}
