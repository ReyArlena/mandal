<?php

use App\Models\User;

it('shows the login page', function () {
    $this->get('/login')->assertOk();
});

it('redirects guests from the dashboard to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('does not offer a registration page', function () {
    $this->get('/register')->assertNotFound();
});

it('sends a user without multi-factor authentication to the set-up page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect('/multi-factor-authentication/set-up');
});
