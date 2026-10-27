<?php

/**
 * # La mail «il tuo commento è stato rimosso» esce nella lingua dell'installazione
 *
 * Quando l'amministratore nasconde un commento (`admin.commenti.modera`), l'autore che ha acceso
 * l'avviso «commento eliminato» riceve `CommentoEliminatoNotification`, che compone oggetto e riga
 * con le chiavi `notifications.deleted_ticket_comment.*` nella lingua dell'installazione
 * (`LocalizedNotification`). Una chiave scritta in modo diverso in un file di lingua non si trova, e
 * il traduttore ripiega sulla lingua di riserva (`en`): la mail esce in inglese a chi usa l'italiano.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\CommentoEliminatoNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

test('la mail «il tuo commento è stato rimosso» esce in italiano su un\'installazione in italiano', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $condominio = Condominio::factory()->create(['nome' => "Residenza l'Ulivo"]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);
    $mario = User::factory()->create(['email_verified_at' => now(), 'name' => 'Mario Rossi']);
    $mario->assignRole(Role::UTENTE->value);
    $mario->givePermissionTo(Permission::COMMENT_SEGNALAZIONI->value);
    Anagrafica::factory()->create(['user_id' => $mario->id, 'nome' => 'Mario Rossi'])->condomini()->attach($condominio->id);
    DB::table('notification_preferences')->insert([
        'user_id' => $mario->id, 'type' => NotificationType::COMMENT_DELETED->value, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $segnalazione = Segnalazione::create([
        'subject' => "Il cancello dell'ingresso «B»", 'description' => "Non chiude\nda due giorni", 'priority' => 'media',
        'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $mario->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ]);
    $commento = Commento::create([
        'commentable_type' => $segnalazione->getMorphClass(), 'commentable_id' => $segnalazione->id, 'user_id' => $mario->id,
        'condominio_id' => $condominio->id, 'corpo' => 'Il fabbro non è ancora passato', 'stato' => 'pubblicato',
    ]);

    $this->actingAs($admin)->post(route('admin.commenti.modera', $commento))->assertSessionHasNoErrors();

    expect($commento->fresh()->stato)->toBe('nascosto');
    Notification::assertSentTo($mario, CommentoEliminatoNotification::class, function (CommentoEliminatoNotification $notifica) use ($mario) {
        // La mail si compone nella lingua che la notifica porta con sé, come fa l'invio vero.
        expect($notifica->locale($mario))->toBe('it');
        $prima = app()->getLocale();
        try {
            app()->setLocale($notifica->locale($mario));
            $mail = $notifica->toMail($mario);
        } finally {
            app()->setLocale($prima);
        }

        // L'atteso è la frase del file `it`, letta senza la lingua di riserva: se la chiave manca in
        // italiano, il confronto fallisce invece di passare con il testo inglese. Il nome con cui la
        // frase chiama la segnalazione (`:entity`) non si fissa qui: si legge dalla riga, e deve
        // contenere l'oggetto della segnalazione.
        $oggetto = Lang::get('notifications.deleted_ticket_comment.subject', [], 'it', false);
        [$primaDiEntity, $dopoEntity] = explode(':entity', Lang::get('notifications.deleted_ticket_comment.line_1', [], 'it', false), 2);
        expect($mail->subject)->toBe($oggetto)
            ->and($mail->introLines)->toHaveCount(1);
        $riga = $mail->introLines[0];
        expect($riga)->toStartWith($primaDiEntity)->toEndWith($dopoEntity);
        $entity = mb_substr($riga, mb_strlen($primaDiEntity), mb_strlen($riga) - mb_strlen($primaDiEntity) - mb_strlen($dopoEntity));
        expect($entity)->toContain("Il cancello dell'ingresso «B»");

        return true;
    });
});

test('la chiave della mail «il tuo commento è stato rimosso» c\'è nel file di ogni lingua', function (string $lingua) {
    // Una chiave che manca in una lingua ripiega in silenzio sulla lingua di riserva.
    foreach (['subject', 'line_1'] as $campo) {
        expect(Lang::hasForLocale("notifications.deleted_ticket_comment.{$campo}", $lingua))->toBeTrue("manca in {$lingua}: {$campo}");
    }
})->with(['it', 'en', 'es', 'pt']);
