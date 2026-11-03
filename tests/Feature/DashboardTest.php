<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get('/admin/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    // La dashboard chiede alle policy dei quattro elenchi se mandare i loro blocchi (decisione 137), e le policy
    // guardano permessi che su ogni installazione esistono: il test li crea come l'installazione.
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    /** @var \App\Models\User $user */
    $user = User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($user);

    $response = $this->get('/admin/dashboard');
    $response->assertStatus(200);
});