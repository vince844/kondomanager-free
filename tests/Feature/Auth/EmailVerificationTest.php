<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();
    
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);

    Event::fake();

    // Firmato sul percorso, come il link della mail (`CustomVerifyEmailNotification`): la rotta controlla
    // quella firma (`signed:relative`).
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
        absolute: false,
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(\App\Helpers\RedirectHelper::userHomeRoute() . '?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    // Firmato sul percorso come il link della mail: la firma vale, e a fermare la verifica è l'hash.
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
        absolute: false,
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});