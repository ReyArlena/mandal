<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

it('creates the initial administrator account', function () {
    $this->seed(AdminUserSeeder::class);

    $user = User::query()->where('email', AdminUserSeeder::EMAIL)->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check(AdminUserSeeder::PASSWORD, $user->password))->toBeTrue();
});

it('does not duplicate the administrator when seeded twice', function () {
    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', AdminUserSeeder::EMAIL)->count())->toBe(1);
});
