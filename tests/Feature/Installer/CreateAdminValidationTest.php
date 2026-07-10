<?php

use App\Livewire\Installer\CreateAdmin;
use App\Models\User;
use Livewire\Livewire;

it('does not create the admin user when passwords do not match', function () {
    $before = User::count();

    Livewire::test(CreateAdmin::class)
        ->set('name', 'Mario Rossi')
        ->set('email', 'admin.mismatch@esempio.it')
        ->set('password', 'password123')
        ->set('password_confirmation', 'differente456')
        ->call('completeStep')
        ->assertHasErrors(['password']);

    expect(User::count())->toBe($before);
    expect(User::where('email', 'admin.mismatch@esempio.it')->exists())->toBeFalse();
});

it('creates the admin user when passwords match', function () {
    $before = User::count();

    Livewire::test(CreateAdmin::class)
        ->set('name', 'Mario Rossi')
        ->set('email', 'admin.match@esempio.it')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('completeStep')
        ->assertHasNoErrors();

    expect(User::count())->toBe($before + 1);
});

it('shows the mismatch error as soon as the confirmation field is filled', function () {
    Livewire::test(CreateAdmin::class)
        ->set('password', 'password123')
        ->set('password_confirmation', 'differente456')
        ->assertHasErrors(['password']);
});

it('clears the mismatch error when the confirmation is corrected', function () {
    Livewire::test(CreateAdmin::class)
        ->set('password', 'password123')
        ->set('password_confirmation', 'differente456')
        ->assertHasErrors(['password'])
        ->set('password_confirmation', 'password123')
        ->assertHasNoErrors(['password']);
});

it('does not show a premature mismatch error while the confirmation is still empty', function () {
    Livewire::test(CreateAdmin::class)
        ->set('password', 'password123')
        ->assertHasNoErrors(['password']);
});

it('still validates the password length while the confirmation is empty', function () {
    Livewire::test(CreateAdmin::class)
        ->set('password', 'corta')
        ->assertHasErrors(['password']);
});
