<?php

declare(strict_types=1);

use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets a signed-in user do everything with staff accounts', function (string $ability, bool $needsAccount): void {
    $user = User::factory()->create();
    $subject = $needsAccount ? StaffAccount::factory()->create() : StaffAccount::class;

    expect($user->can($ability, $subject))->toBeTrue();
})->with([
    'viewAny' => ['viewAny', false],
    'view' => ['view', true],
    'create' => ['create', false],
    'update' => ['update', true],
    'delete' => ['delete', true],
]);

it('lets a signed-in user lock staff screens', function (): void {
    expect(Gate::forUser(User::factory()->create())->allows('lock-staff-screens'))->toBeTrue();
});

it('no longer defines a gate for revealing staff passwords', function (): void {
    expect(Gate::has('reveal-staff-passwords'))->toBeFalse();
});

it('keeps guests off the Staff page', function (): void {
    $this->get(route('staff.index'))->assertRedirect();
});
