<?php

use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\Resources\Administrators\Pages\ManageAdministrators;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->me = User::factory()->create(['name' => 'Rey', 'email' => 'rey@example.com']);
    $this->actingAs($this->me);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->password = 'correct-horse-battery-staple';
});

it('lists administrators and marks your own account', function () {
    $other = User::factory()->create(['name' => 'Sam', 'email' => 'sam@example.com']);

    livewire(ManageAdministrators::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->me, $other])
        ->assertSee('sam@example.com')
        ->assertSee('You');
});

it('sits below General in the settings sidebar under the Settings heading', function () {
    livewire(General::class)->assertSeeInOrder(['General', 'Administrators', 'Source providers']);

    expect(livewire(ManageAdministrators::class)->instance()->getHeading())->toBe('Settings');
});

it('adds an administrator with a hashed password', function () {
    livewire(ManageAdministrators::class)
        ->callAction('create', data: [
            'name' => 'Sam',
            'email' => 'sam@example.com',
            'password' => $this->password,
            'passwordConfirmation' => $this->password,
        ])
        ->assertHasNoActionErrors();

    $administrator = User::query()->where('email', 'sam@example.com')->sole();

    expect($administrator->name)->toBe('Sam')
        ->and($administrator->password)->not->toBe($this->password)
        ->and(Hash::check($this->password, $administrator->password))->toBeTrue();
});

it('refuses an address already in use, a missing password and a mismatched confirmation', function () {
    livewire(ManageAdministrators::class)
        ->callAction('create', data: ['name' => 'Sam', 'email' => 'rey@example.com', 'password' => $this->password, 'passwordConfirmation' => $this->password])
        ->assertHasActionErrors(['email' => 'unique']);

    livewire(ManageAdministrators::class)
        ->callAction('create', data: ['name' => 'Sam', 'email' => 'sam@example.com'])
        ->assertHasActionErrors(['password' => 'required']);

    livewire(ManageAdministrators::class)
        ->callAction('create', data: ['name' => 'Sam', 'email' => 'sam@example.com', 'password' => $this->password, 'passwordConfirmation' => 'something-else'])
        ->assertHasActionErrors(['password' => 'same']);

    expect(User::query()->count())->toBe(1);
});

it('keeps the password when an edit leaves it empty', function () {
    $other = User::factory()->create(['email' => 'sam@example.com']);
    $hash = $other->password;

    livewire(ManageAdministrators::class)
        ->callTableAction('edit', $other, data: ['name' => 'Samantha', 'email' => 'samantha@example.com', 'password' => ''])
        ->assertHasNoTableActionErrors();

    $other->refresh();

    expect($other->name)->toBe('Samantha')
        ->and($other->email)->toBe('samantha@example.com')
        ->and($other->password)->toBe($hash);
});

it('changes another administrator\'s password without touching your session', function () {
    $other = User::factory()->create();
    session()->put('password_hash_web', $this->me->password);

    livewire(ManageAdministrators::class)
        ->callTableAction('edit', $other, data: ['name' => $other->name, 'email' => $other->email, 'password' => $this->password, 'passwordConfirmation' => $this->password])
        ->assertHasNoTableActionErrors();

    expect(Hash::check($this->password, $other->fresh()->password))->toBeTrue()
        ->and(session('password_hash_web'))->toBe($this->me->password);
});

it('keeps your session signed in when you change your own password', function () {
    session()->put('password_hash_web', $this->me->password);

    livewire(ManageAdministrators::class)
        ->callTableAction('edit', $this->me, data: ['name' => 'Rey', 'email' => 'rey@example.com', 'password' => $this->password, 'passwordConfirmation' => $this->password])
        ->assertHasNoTableActionErrors();

    $hash = $this->me->fresh()->password;

    expect(Hash::check($this->password, $hash))->toBeTrue()
        ->and(session('password_hash_web'))->toBe($hash)
        ->and(Filament::auth()->user()->getAuthPassword())->toBe($hash);
});

it('does not let you delete your own account', function () {
    $other = User::factory()->create();

    livewire(ManageAdministrators::class)
        ->assertTableActionHidden('delete', $this->me)
        ->assertTableActionVisible('delete', $other);

    expect($this->me->can('delete', $this->me))->toBeFalse()
        ->and($this->me->can('delete', $other))->toBeTrue();
});

it('deletes an administrator with their notifications and keeps their tasks', function () {
    $other = User::factory()->create();
    $other->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => []]);
    $this->me->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => []]);
    $task = Task::factory()->create(['user_id' => $other->getKey()]);

    livewire(ManageAdministrators::class)
        ->callTableAction('delete', $other);

    expect(User::query()->whereKey($other->getKey())->exists())->toBeFalse()
        ->and($other->notifications()->count())->toBe(0)
        ->and($this->me->notifications()->count())->toBe(1)
        ->and($task->fresh()->user_id)->toBeNull();
});
