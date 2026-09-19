<?php

use Database\Seeders\AdminUserSeeder;

it('signs in the seeded administrator and asks them to set up multi-factor authentication', function () {
    $this->seed(AdminUserSeeder::class);

    visit('/login')
        ->fill('email', AdminUserSeeder::EMAIL)
        ->fill('password', AdminUserSeeder::PASSWORD)
        ->press('Sign in')
        ->assertSee('Set up multi-factor authentication')
        ->assertNoJavaScriptErrors();
});

it('rejects a wrong password', function () {
    $this->seed(AdminUserSeeder::class);

    visit('/login')
        ->fill('email', AdminUserSeeder::EMAIL)
        ->fill('password', 'not-the-password')
        ->press('Sign in')
        ->assertSee('These credentials do not match our records')
        ->assertPathIs('/login');
});
