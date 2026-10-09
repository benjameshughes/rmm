<?php

declare(strict_types=1);

it('runs mariadb sessions at read committed so concurrent agent writes do not fail', function (): void {
    expect(config('database.connections.mariadb.isolation_level'))->toBe('READ COMMITTED');
});
