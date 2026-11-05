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

it('lets a signed-in user reveal staff passwords and lock staff screens', function (string $gate): void {
    expect(Gate::forUser(User::factory()->create())->allows($gate))->toBeTrue();
})->with(['reveal-staff-passwords', 'lock-staff-screens']);

it('keeps guests off the Staff page', function (): void {
    $this->get(route('staff.index'))->assertRedirect();
});
