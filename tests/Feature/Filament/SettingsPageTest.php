<?php

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\SourceCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves the public URL without a trailing slash', function () {
    livewire(Settings::class)
        ->callAction('publicUrl', data: ['public_url' => 'https://panel.example.com/'])
        ->assertHasNoActionErrors()
        ->assertNotified('Public URL saved');

    expect(Setting::publicUrl())->toBe('https://panel.example.com');
});

it('requires https for the public URL', function () {
    livewire(Settings::class)
        ->callAction('publicUrl', data: ['public_url' => 'http://panel.example.com'])
        ->assertHasActionErrors(['public_url']);
});

it('connects GitHub after checking the token', function () {
    Http::fake(['api.github.com/user' => Http::response(['login' => 'acme'], 200, ['github-authentication-token-expiration' => '2027-03-01 00:00:00 UTC'])]);

    livewire(Settings::class)
        ->callAction('addGitHub', data: ['name' => 'Laptop', 'token' => 'github_pat_'.str_repeat('a', 30)])
        ->assertHasNoActionErrors()
        ->assertNotified('GitHub connected as acme');

    $credential = SourceCredential::query()->sole();

    expect($credential->account)->toBe('acme')
        ->and($credential->expires_at?->toDateString())->toBe('2027-03-01')
        ->and($credential->token)->toBe('github_pat_'.str_repeat('a', 30))
        ->and(DB::table('source_credentials')->value('token'))->not->toContain('github_pat_');
});

it('does not store a token GitHub rejects', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    livewire(Settings::class)
        ->callAction('addGitHub', data: ['name' => 'Bad', 'token' => 'github_pat_'.str_repeat('b', 30)])
        ->assertNotified('GitHub rejected the token');

    expect(SourceCredential::query()->count())->toBe(0);
});

it('lists and removes tokens', function () {
    $credential = SourceCredential::factory()->create(['name' => 'Laptop', 'account' => 'acme']);

    livewire(Settings::class)
        ->assertSee('Laptop')
        ->assertSee('acme')
        ->assertActionHidden('addGitHub')
        ->callAction('removeCredential', arguments: ['id' => $credential->id])
        ->assertNotified('Token removed');

    expect(SourceCredential::query()->count())->toBe(0);
});
