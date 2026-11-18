<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Commento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Models\Condominio;
use App\Models\NotificationPreference;
use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Events\Commenti\CommentoCreato;
use App\Listeners\Commenti\InviaNotificheCommento;
use App\Notifications\NuovoCommentoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
});

it('sends moderation notification to admins with enabled preferences', function () {
    // Coda 558 e decisione 282.1: l'avviso «in attesa» va a chi può approvare, cioè a chi ha il pannello,
    // «Approva commenti segnalazioni» e apre la scheda. Non serve un'anagrafica nel palazzo: chi amministra
    // di norma non ne ha una, ed è il caso di questo test. Il solo permesso, senza il pannello, non basta.
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $adminNotificato = User::factory()->create();
    $adminNotificato->assignRole(\App\Enums\Role::AMMINISTRATORE->value);
    
    NotificationPreference::create([
        'user_id' => $adminNotificato->id,
        'type' => NotificationType::COMMENT_UNDER_MODERATION->value,
        'enabled' => true,
    ]);

    $adminIgnorato = User::factory()->create();
    $adminIgnorato->assignRole(\App\Enums\Role::AMMINISTRATORE->value);
    // No preference -> non deve ricevere la mail

    // Il permesso di approvare senza il pannello: non approva, e non riceve l'avviso.
    $soloPermesso = User::factory()->create();
    $soloPermesso->givePermissionTo(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value);
    NotificationPreference::create([
        'user_id' => $soloPermesso->id,
        'type' => NotificationType::COMMENT_UNDER_MODERATION->value,
        'enabled' => true,
    ]);

    $condominio = Condominio::factory()->create();
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $condominio->id]);
    
    $commento = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'stato' => 'in_attesa',
        'condominio_id' => $condominio->id
    ]);

    $evento = new CommentoCreato($commento);
    $listener = app(InviaNotificheCommento::class);
    $listener->handle($evento);

    Notification::assertSentTo($adminNotificato, CommentoInAttesaNotification::class);
    Notification::assertNotSentTo($adminIgnorato, CommentoInAttesaNotification::class);
    Notification::assertNotSentTo($soloPermesso, CommentoInAttesaNotification::class);
});
