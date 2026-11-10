<?php

/**
 * # Allegati: le email (Coda 460, decisione 241.5)
 *
 * Come i commenti (`CommentoNotificationService::destinatari()`): un caricamento pubblicato avvisa chi ha aperto
 * la segnalazione, chi ha già commentato o caricato e gli amministratori del condominio, mai chi ha caricato, e
 * solo chi vede i file; un'email per caricamento, anche con cinque file. Un caricamento in attesa avvisa chi
 * può approvare, e nessun altro finché non è approvato; all'approvazione l'autore riceve «approvato» e gli altri
 * l'avviso del file nuovo. L'autore riceve «eliminato» quando il suo file lo elimina un altro. Sulle comunicazioni
 * gli allegati aggiunti dopo la pubblicazione avvisano i lettori con la preferenza «comunicazione aggiornata»;
 * quelli del modulo di creazione viaggiano con la comunicazione e non mandano un'email a sé.
 *
 * Ogni email rispetta le preferenze della persona; le quattro preferenze nuove nascono con lo stato delle loro
 * sorelle dei commenti. La guardia si ripete quando l'email parte (`shouldSend`): un file eliminato o diventato
 * invisibile nel frattempo non si annuncia. Il collegamento porta alla scheda, mai al file, e il nome del file
 * arriva come testo, non come Markdown.
 */

use App\Enums\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = algUtente([$this->aurora->id], 'Luigi Verdi');
    $this->anna = algUtente([$this->aurora->id], 'Anna Russo');
    $this->paolo = algUtente([$this->aurora->id], 'Paolo Galli');
    $this->s = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => false]);
    // Anna ha già commentato: è una partecipante. Paolo sta nel palazzo ma non ha partecipato.
    DB::table('commenti')->insert([
        'commentable_type' => $this->s->getMorphClass(), 'commentable_id' => $this->s->id, 'user_id' => $this->anna->id,
        'condominio_id' => $this->aurora->id, 'corpo' => 'Anche da me', 'stato' => 'pubblicato', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('tre foto pubblicate in un caricamento: un\'email sola a chi ha aperto la segnalazione, a chi ha commentato e all\'amministratore; niente a chi ha caricato e a chi non partecipa', function () {
    algCarica($this, $this->luigi, $this->s, [algFoto('a.jpg'), algFoto('b.jpg'), algFoto('c.jpg')])->assertSessionHasNoErrors();

    foreach ([$this->mario, $this->anna, $this->admin] as $destinatario) {
        Notification::assertSentToTimes($destinatario, ALG_MAIL_NUOVO, 1);
    }
    Notification::assertNotSentTo($this->luigi, ALG_MAIL_NUOVO);
    Notification::assertNotSentTo($this->paolo, ALG_MAIL_NUOVO);
});

test('chi ha caricato prima è un partecipante: il caricamento dopo lo avvisa', function () {
    algAllegatoSalvato($this->s, $this->paolo, 'prima.jpg');

    algCarica($this, $this->luigi, $this->s, [algFoto('dopo.jpg')])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->paolo, ALG_MAIL_NUOVO, 1);
});

test('chi ha spento la preferenza «nuovo allegato» non riceve l\'email', function () {
    DB::table('notification_preferences')->where('user_id', $this->anna->id)->where('type', 'new_attachment')->update(['enabled' => false]);

    algCarica($this, $this->luigi, $this->s, [algFoto()])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->anna, ALG_MAIL_NUOVO);
    Notification::assertSentTo($this->mario, ALG_MAIL_NUOVO);
});

test('un partecipante che non vede più la segnalazione non riceve l\'email', function () {
    $this->anna->anagrafica->condomini()->detach($this->aurora->id);

    algCarica($this, $this->luigi, $this->s, [algFoto()])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->anna->fresh(), ALG_MAIL_NUOVO);
});

test('un caricamento in attesa avvisa una volta chi può approvare, e nessun partecipante; l\'approvazione avvisa l\'autore e poi i partecipanti', function () {
    $moderata = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => true]);
    $collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');

    algCarica($this, $this->luigi, $moderata, [algFoto('a.jpg'), algFoto('b.jpg')])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->admin, ALG_MAIL_IN_ATTESA, 1);
    Notification::assertNotSentTo($collaboratore, ALG_MAIL_IN_ATTESA);
    Notification::assertNotSentTo($this->mario, ALG_MAIL_NUOVO);

    $id = algRighe($moderata)->first()->id;
    $this->actingAs($this->admin)->post(route('admin.allegati.approva', ['allegato' => $id]))->assertRedirect();

    Notification::assertSentToTimes($this->luigi, ALG_MAIL_APPROVATO, 1);
    Notification::assertSentToTimes($this->mario, ALG_MAIL_NUOVO, 1);
    Notification::assertNotSentTo($this->luigi, ALG_MAIL_NUOVO);
});

test('l\'autore riceve «eliminato» quando il suo file lo elimina un altro; non quando lo elimina lui', function () {
    $diLuigi = algAllegatoSalvato($this->s, $this->luigi, 'di-luigi.jpg');
    $altro = algAllegatoSalvato($this->s, $this->luigi, 'altro.jpg');

    $this->actingAs($this->luigi)->delete(route('user.allegati.destroy', ['allegato' => $altro]))->assertRedirect();
    Notification::assertNotSentTo($this->luigi, ALG_MAIL_ELIMINATO);

    $this->actingAs($this->admin)->delete(route('admin.allegati.destroy', ['allegato' => $diLuigi]))->assertRedirect();
    Notification::assertSentToTimes($this->luigi, ALG_MAIL_ELIMINATO, 1);
});

test('l\'email porta alla scheda, non al file; elenca i nomi dei file, e un nome con il Markdown arriva come testo', function () {
    // Il nome non può contenere «/»: il browser manda solo l'ultima parte del percorso. Un collegamento senza barre basta.
    algCarica($this, $this->luigi, $this->s, [algFoto('[premi qui](esempio.invalid).jpg'), algFoto('serratura.png')])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario, ALG_MAIL_NUOVO, function ($notifica) {
        $mail = $notifica->toMail($this->mario);
        $html = (string) $mail->render();

        return str_contains((string) $mail->actionUrl, (string) $this->s->id)
            && ! str_contains((string) $mail->actionUrl, '/allegati/')
            && str_contains($html, 'serratura.png')
            && ! str_contains($html, 'href="esempio.invalid');
    });
});

test('la guardia si ripete quando l\'email parte: un file eliminato, o un elemento diventato «Solo amministrazione», non si annuncia più', function () {
    algCarica($this, $this->luigi, $this->s, [algFoto('foto.jpg')])->assertSessionHasNoErrors();
    $notifica = Notification::sent($this->mario, ALG_MAIL_NUOVO)->first();
    expect($notifica->shouldSend($this->mario, 'mail'))->toBeTrue();

    DB::table('segnalazioni')->where('id', $this->s->id)->update(['allegati_accesso' => 'riservati']);
    expect($notifica->shouldSend($this->mario, 'mail'))->toBeFalse();

    DB::table('segnalazioni')->where('id', $this->s->id)->update(['allegati_accesso' => 'aperti']);
    expect($notifica->shouldSend($this->mario, 'mail'))->toBeTrue();

    DB::table('documenti')->where('documentable_id', $this->s->id)->where('documentable_type', $this->s->getMorphClass())->delete();
    expect($notifica->shouldSend($this->mario, 'mail'))->toBeFalse();
});

test('la richiesta di approvazione non parte più se il file nel frattempo è stato approvato o eliminato', function () {
    $moderata = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => true]);
    algCarica($this, $this->luigi, $moderata, [algFoto()])->assertSessionHasNoErrors();
    $notifica = Notification::sent($this->admin, ALG_MAIL_IN_ATTESA)->first();
    expect($notifica->shouldSend($this->admin, 'mail'))->toBeTrue();

    DB::table('documenti')->where('documentable_id', $moderata->id)->where('documentable_type', $moderata->getMorphClass())->update(['is_approved' => true]);
    expect($notifica->shouldSend($this->admin, 'mail'))->toBeFalse();
});

test('un PDF aggiunto dopo a una comunicazione pubblicata avvisa i suoi lettori, una volta, con la preferenza «comunicazione aggiornata»; non chi l\'ha caricato né chi non la vede', function () {
    $carla = algUtente([$this->borea->id], "Carla D'Amico");
    $c = algComunicazione([$this->aurora], $this->admin);
    DB::table('notification_preferences')->where('user_id', $this->anna->id)->where('type', NotificationType::UPDATED_COMMUNICATION->value)->update(['enabled' => false]);

    algCarica($this, $this->admin, $c, [algPdf('verbale.pdf'), algPdf('allegato al verbale.pdf')], 'admin')->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->mario, ALG_MAIL_COMUNICAZIONE, 1);
    Notification::assertSentToTimes($this->luigi, ALG_MAIL_COMUNICAZIONE, 1);
    Notification::assertNotSentTo($this->anna, ALG_MAIL_COMUNICAZIONE);
    Notification::assertNotSentTo($carla, ALG_MAIL_COMUNICAZIONE);
    Notification::assertNotSentTo($this->admin, ALG_MAIL_COMUNICAZIONE);
});

test('gli allegati del modulo «Nuova comunicazione» non mandano un\'email a sé; quelli di una comunicazione in bozza nemmeno', function () {
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Assemblea ordinaria', 'description' => 'Convocazione.', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id],
        'allegati' => [algPdf('convocazione.pdf')],
    ])->assertSessionHasNoErrors();

    $bozza = algComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    algCarica($this, $this->admin, $bozza, [algPdf()], 'admin')->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->mario, ALG_MAIL_COMUNICAZIONE);
});

test('le quattro preferenze nuove compaiono a chi può caricare, e nascono con lo stato delle loro sorelle dei commenti', function () {
    $tipi = array_keys(app(\App\Services\NotificationPreferenceService::class)->getVisibleNotificationTypes($this->mario));
    expect($tipi)->toContain('new_attachment', 'attachment_approved', 'attachment_deleted');
    $tipiAdmin = array_keys(app(\App\Services\NotificationPreferenceService::class)->getVisibleNotificationTypes($this->admin));
    expect($tipiAdmin)->toContain('attachment_under_moderation');

    // Un'installazione aggiornata: le preferenze dei commenti ci sono, quelle degli allegati no.
    DB::table('notification_preferences')->whereIn('type', ['new_attachment', 'attachment_under_moderation', 'attachment_approved', 'attachment_deleted'])->delete();
    DB::table('notification_preferences')->where('user_id', $this->mario->id)->where('type', 'new_comment')->update(['enabled' => false]);

    $migrazione = require database_path('migrations/2026_11_08_200300_seed_preferenze_notifica_allegati.php');
    $migrazione->up();
    $migrazione->up();

    $stato = fn (int $utente, string $tipo) => DB::table('notification_preferences')->where('user_id', $utente)->where('type', $tipo)->pluck('enabled')->map(fn ($v) => (bool) $v)->all();
    expect($stato($this->mario->id, 'new_attachment'))->toBe([false])
        ->and($stato($this->luigi->id, 'new_attachment'))->toBe([true])
        ->and($stato($this->admin->id, 'attachment_under_moderation'))->toBe([true])
        ->and($stato($this->luigi->id, 'attachment_approved'))->toBe([true])
        ->and($stato($this->luigi->id, 'attachment_deleted'))->toBe([true]);
});
