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

it('asks search engines not to index, follow or cache any response', function () {
    $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

it('disallows every path in robots.txt', function () {
    expect(file_get_contents(public_path('robots.txt')))->toBe("User-agent: *\nDisallow: /\n");
});
