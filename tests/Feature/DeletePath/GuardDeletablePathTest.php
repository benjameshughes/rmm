<?php

declare(strict_types=1);

use App\Actions\DeletePath\GuardDeletablePath;
use App\Exceptions\PathCannotBeDeleted;

it('refuses protected paths and path tricks', function (string $path): void {
    expect(app(GuardDeletablePath::class)->refusal($path))->toBeInstanceOf(PathCannotBeDeleted::class)
        ->and(fn () => app(GuardDeletablePath::class)($path))->toThrow(PathCannotBeDeleted::class);
})->with([
    'blank' => '',
    'whitespace' => '   ',
    'drive root' => 'C:\\',
    'other drive root' => 'D:\\',
    'drive without slash' => 'C:',
    'drive relative' => 'C:Windows',
    'relative' => 'Windows\\System32',
    'unix style relative' => '../etc',
    'unc' => '\\\\server\\share\\folder',
    'unc forward slashes' => '//server/share/folder',
    'device path' => '\\\\?\\C:\\Windows',
    'wildcard star' => 'C:\\Users\\sophie\\*',
    'wildcard question' => 'C:\\Temp\\file?.txt',
    'percent variable' => 'C:\\%WINDIR%\\System32',
    'env variable' => '$env:SystemRoot\\System32',
    'braced env variable' => 'C:\\${env:windir}',
    'dot dot' => 'C:\\Temp\\..\\Windows',
    'single dot' => 'C:\\Temp\\.\\x',
    'trailing dot' => 'C:\\Windows.',
    'trailing space' => 'C:\\Windows \\System32',
    'short name' => 'C:\\PROGRA~1',
    'alternate data stream' => 'C:\\Temp\\file.txt:hidden',
    'pipe' => 'C:\\Temp\\a|b',
    'windows' => 'C:\\Windows',
    'windows lower case' => 'c:\\windows',
    'inside windows' => 'C:\\Windows\\System32\\drivers',
    'windows forward slashes' => 'C:/Windows/Temp',
    'windows on another drive' => 'D:\\Windows',
    'program files' => 'C:\\Program Files',
    'inside program files' => 'C:\\Program Files\\Veeam',
    'program files x86' => 'C:\\Program Files (x86)',
    'inside program files x86' => 'C:\\Program Files (x86)\\Google\\Chrome',
    'programdata microsoft' => 'C:\\ProgramData\\Microsoft',
    'inside programdata microsoft' => 'C:\\ProgramData\\Microsoft\\Windows\\WER',
    'programdata itself' => 'C:\\ProgramData',
    'system volume information' => 'C:\\System Volume Information',
    'recycle bin root' => 'C:\\$Recycle.Bin',
    'recovery' => 'C:\\Recovery',
    'boot' => 'C:\\Boot',
    'pagefile' => 'C:\\pagefile.sys',
    'hiberfil' => 'C:\\hiberfil.sys',
    'swapfile' => 'C:\\swapfile.sys',
    'dumpstack' => 'C:\\DumpStack.log.tmp',
    'pagefile on another drive' => 'D:\\pagefile.sys',
    'users' => 'C:\\Users',
    'a profile root' => 'C:\\Users\\sophie',
    'a profile root trailing slash' => 'C:\\Users\\sophie\\',
    'default profile' => 'C:\\Users\\Default',
    'public profile' => 'C:\\Users\\Public',
    'documents and settings junction' => 'C:\\Documents and Settings',
    'agent data' => 'C:\\ProgramData\\BenJH RMM',
    'agent log' => 'C:\\ProgramData\\BenJH RMM\\agent.log',
    'rmm folder' => 'C:\\ProgramData\\RMM',
    'restic' => 'C:\\ProgramData\\RMM\\restic',
    'quarantine root' => 'C:\\ProgramData\\RMM\\Quarantine',
    'inside quarantine' => 'C:\\ProgramData\\RMM\\Quarantine\\20261009T101500Z-0a1b2c3d',
]);

it('allows ordinary files and folders, normalised', function (string $path, string $normalised): void {
    expect(app(GuardDeletablePath::class)->refusal($path))->toBeNull()
        ->and(app(GuardDeletablePath::class)($path))->toBe($normalised);
})->with([
    'the orphaned veeam cache' => ['C:\\Veeam Backup Cache', 'C:\\Veeam Backup Cache'],
    'an iso in downloads' => ['C:\\Users\\sophie\\Downloads\\big.iso', 'C:\\Users\\sophie\\Downloads\\big.iso'],
    'lower case drive and forward slashes' => ['c:/Users/sophie/Downloads/', 'C:\\Users\\sophie\\Downloads'],
    'doubled backslashes' => ['C:\\\\Temp\\\\old', 'C:\\Temp\\old'],
    'a recycle bin inside' => ['C:\\$Recycle.Bin\\S-1-5-21-111-222-333-1001', 'C:\\$Recycle.Bin\\S-1-5-21-111-222-333-1001'],
    'appdata temp' => ['C:\\Users\\anna\\AppData\\Local\\Temp', 'C:\\Users\\anna\\AppData\\Local\\Temp'],
    'a data drive folder' => ['D:\\Backups\\2019', 'D:\\Backups\\2019'],
    'programdata vendor folder' => ['C:\\ProgramData\\Veeam\\Backup', 'C:\\ProgramData\\Veeam\\Backup'],
    'a folder named like windows' => ['C:\\Windows.old', 'C:\\Windows.old'],
    'a file with a percent' => ['C:\\Temp\\100% done.txt', 'C:\\Temp\\100% done.txt'],
]);

it('says why a protected path is refused', function (): void {
    expect(app(GuardDeletablePath::class)->refusal('c:\\windows\\system32')->getMessage())
        ->toBe('C:\\windows\\system32 is protected: Windows, installed programs, user profile roots and the RMM\'s own folders are never deleted from the RMM.')
        ->and(app(GuardDeletablePath::class)->refusal('C:\\Windows\\..\\x')->getStatusCode())->toBe(422);
});
