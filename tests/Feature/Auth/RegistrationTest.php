<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration screen can be rendered', function () {
    app(\App\Settings\GeneralSettings::class)->user_frontend_registration = true;
    app(\App\Settings\GeneralSettings::class)->save();
    
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    \Illuminate\Support\Facades\Notification::fake();
    app(\App\Settings\GeneralSettings::class)->user_frontend_registration = true;
    app(\App\Settings\GeneralSettings::class)->save();
    
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \App\Enums\Role::UTENTE->value, 'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \App\Enums\Role::AMMINISTRATORE->value, 'guard_name' => 'web']);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    if (!\Illuminate\Support\Facades\Auth::check()) {
        dump($response->exception ? $response->exception->getMessage() : 'No exception');
    }

    $this->assertAuthenticated();
    $response->assertRedirect(route('verification.notice', absolute: false));
});