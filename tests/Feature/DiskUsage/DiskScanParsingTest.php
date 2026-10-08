<?php

declare(strict_types=1);

use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanNode;
use App\Enums\DiskNodeFlag;

it('reads the totals, volume, tree, files, extensions and errors of an rmm.du/1 scan', function (): void {
    $scan = DiskScan::fromArray(diskScanFixture());

    expect($scan)
        ->root->toBe('C:\\')
        ->agentVersion->toBe('0.9.0')
        ->durationMs->toBe(81234)
        ->filesystem->toBe('NTFS')
        ->volumeTotal->toBe(256 * 1024 ** 3)
        ->errorCount->toBe(2)
        ->and($scan->startedAt->toIso8601ZuluString())->toBe('2026-10-08T05:00:00Z')
        ->and($scan->isDriveRoot())->toBeTrue()
        ->and($scan->tree->nodes())->toHaveCount(19)
        ->and($scan->topFiles)->toHaveCount(4)
        ->and($scan->extensions->first()->extension)->toBe('.ost')
        ->and($scan->errorSample[0])->toBe(['C:\\Windows\\CSC', 5]);
});

it('builds paths, children and index paths from parent indexes', function (): void {
    $tree = DiskScan::fromArray(diskScanFixture())->tree;

    expect($tree->path(11))->toBe('C:\\Users\\anna\\AppData\\Local\\Microsoft\\Outlook')
        ->and($tree->pathFromDrive(11))->toBe('Users\\anna\\AppData\\Local\\Microsoft\\Outlook')
        ->and($tree->indexPath(11))->toBe('1.6.8.9.10.11')
        ->and($tree->resolve('1.6.8'))->toBe(8)
        ->and($tree->resolve('1.99.8'))->toBe(1)
        ->and($tree->resolve('garbage'))->toBe(0)
        ->and($tree->indexOfPath('c:\\USERS\\anna\\downloads'))->toBe(7)
        ->and($tree->children(0)->map(fn (DiskScanNode $node): string => $node->name)->all())
        ->toBe(['Users', 'Windows', 'Program Files', '$Recycle.Bin', 'Documents and Settings', '*']);
});

it('reads node flags as badges', function (): void {
    $tree = DiskScan::fromArray(diskScanFixture())->tree;

    expect($tree->node(5)->badges())->toBe([DiskNodeFlag::Other])
        ->and($tree->node(5)->displayName())->toBe('(other)')
        ->and($tree->node(18)->badges())->toBe([DiskNodeFlag::LinkNotFollowed])
        ->and($tree->node(17)->badges())->toBe([DiskNodeFlag::AccessDenied])
        ->and($tree->node(16)->badges())->toBe([DiskNodeFlag::Cloud])
        ->and($tree->node(7)->badges())->toBe([])
        ->and($tree->node(7)->has(DiskNodeFlag::Kept))->toBeTrue()
        ->and(DiskNodeFlag::fromMask(4 | 16))->toBe([DiskNodeFlag::AccessDenied, DiskNodeFlag::Kept]);
});

it('works out unaccounted space from the volume when the agent does not', function (): void {
    $scan = DiskScan::fromArray(diskScanFixture());
    $used = 156 * 1024 ** 3;

    expect($scan->usedBytes())->toBe($used)
        ->and($scan->unaccountedBytes())->toBe($used - $scan->allocated)
        ->and(DiskScan::fromArray(diskScanFixture(['totals.unaccounted' => 123]))->unaccountedBytes())->toBe(123)
        ->and(DiskScan::fromArray(diskScanFixture(['root' => 'C:\\Users', 'nodes.0.1' => 'C:\\Users']))->unaccountedBytes())->toBeNull();
});

it('rejects an unknown schema version', function (mixed $schema): void {
    expect(DiskScan::fromArray(diskScanFixture(['schema' => $schema])))->toBeNull();
})->with(['rmm.du/2', 'rmm.du', null, 1]);

it('rejects a folder tree that does not hang together', function (array $overrides): void {
    expect(DiskScan::fromArray(diskScanFixture($overrides)))->toBeNull();
})->with([
    'no nodes' => [['nodes' => []]],
    'nodes not a list' => [['nodes' => ['a' => [-1, 'C:\\', 0, 0, 0, 0, 0]]]],
    'root with a parent' => [['nodes.0.0' => 0]],
    'parent after child' => [['nodes.1.0' => 5]],
    'self parent' => [['nodes.1.0' => 1]],
    'short row' => [['nodes.2' => [0, 'Windows', 1]]],
    'string size' => [['nodes.2.2' => '30']],
    'negative size' => [['nodes.2.2' => -1]],
    'empty name' => [['nodes.2.1' => '']],
    'missing root' => [['root' => null]],
]);

it('drops malformed file and extension rows but keeps the scan', function (): void {
    $scan = DiskScan::fromArray(diskScanFixture([
        'top_files' => [[0, 'pagefile.sys', 10, 10, 'not a date', 0], 'junk', [0, 5]],
        'extensions' => [['.ost', 1, 1], ['.bad', 'x', 1]],
        'errors' => 'junk',
    ]));

    expect($scan->topFiles)->toHaveCount(1)
        ->and($scan->topFiles->first()->modifiedAt)->toBeNull()
        ->and($scan->extensions)->toHaveCount(1)
        ->and($scan->errorCount)->toBe(0);
});
