<?php

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
    // Ensure permission exists in DB because Pest refresh database
    SpatiePermission::firstOrCreate(['name' => Permission::APPROVE_COMMENTS_SEGNALAZIONI->value]);

    $adminNotificato = User::factory()->create();
    $adminNotificato->givePermissionTo(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value);
    
    NotificationPreference::create([
        'user_id' => $adminNotificato->id,
        'type' => NotificationType::COMMENT_UNDER_MODERATION->value,
        'enabled' => true,
    ]);

    $adminIgnorato = User::factory()->create();
    $adminIgnorato->givePermissionTo(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value);
    // No preference -> non deve ricevere la mail

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
});
