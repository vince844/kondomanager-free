<?php

/**
 * # Allegati, il giro di correzioni: moderazione, Inbox, email e testi (Coda 460)
 *
 * - **Approvare un file già pubblicato non cambia niente**: `moderato_da` e `moderato_at` restano com'erano e
 *   nessuna email riparte. In blocco, i già pubblicati si saltano senza rifiutare gli altri; nelle props
 *   `puo_approvare` è falso su un file pubblicato, in scheda e nella pagina.
 * - **Il modello del documento non accetta in blocco** il legame con l'elemento né i campi della moderazione:
 *   l'archivio si difende proprio con `documentable_type` vuoto.
 * - **La voce «allegato» dell'Inbox porta alla scheda dell'elemento nel pannello** (`meta.action_url`).
 * - **L'email ai vicini non mostra mai l'indirizzo email** di chi ha caricato, nemmeno quando il nome manca.
 * - **Un nome di file più lungo di 255 caratteri** si accorcia e tiene l'estensione: niente errore del database.
 * - **I testi**: la moderazione detta com'è (con la moderazione accesa aspettano tutti, tranne chi può approvare),
 *   il plurale del campo di caricamento, le etichette delle quattro preferenze di notifica nuove.
 *
 * Cosa non copre: l'icona della voce nell'Inbox (si guarda a video), le lingue diverse dall'italiano oltre alla
 * presenza delle voci (la prova già `OgniNotificaHaLaSuaEtichettaTest`).
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = algUtente([$this->aurora->id], 'Luigi Verdi');
});

test('approvare un file già pubblicato non cambia niente e non manda email; in blocco, i pubblicati si saltano', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $pubblicato = algAllegatoSalvato($s, $this->luigi, 'foto.jpg');

    $this->actingAs($this->admin)->post(route('admin.allegati.approva', ['allegato' => $pubblicato]));
    $riga = DB::table('documenti')->find($pubblicato);
    expect($riga->moderato_da)->toBeNull()
        ->and($riga->moderato_at)->toBeNull();
    Notification::assertNothingSent();

    $inAttesa = algAllegatoSalvato($s, $this->luigi, 'serratura.jpg', approvato: false);
    $this->actingAs($this->admin)->post(route('admin.allegati.approva-in-blocco'), ['ids' => [$pubblicato, $inAttesa]])->assertRedirect();

    expect((bool) DB::table('documenti')->find($inAttesa)->is_approved)->toBeTrue('Il file in attesa doveva essere approvato')
        ->and(DB::table('documenti')->find($pubblicato)->moderato_da)->toBeNull();
    Notification::assertSentToTimes($this->luigi, ALG_MAIL_APPROVATO, 1);

    $scheda = algScheda($this, $this->admin, $s, 'admin');
    $pagina = $this->actingAs($this->admin)->get(route('admin.allegati.index'))->viewData('page')['props'];
    $inScheda = collect($scheda['allegati'])->keyBy('id');
    $inPagina = collect($pagina['allegati']['data'])->keyBy('id');
    expect($inScheda[$pubblicato]['puo_approvare'])->toBeFalse()
        ->and($inPagina[$pubblicato]['puo_approvare'])->toBeFalse();
});

test('il modello del documento non accetta in blocco il legame con l\'elemento né i campi della moderazione', function () {
    $documento = new \App\Models\Documento();
    foreach (['documentable_type', 'documentable_id', 'moderato_da', 'moderato_at'] as $campo) {
        expect($documento->isFillable($campo))->toBeFalse("«{$campo}» non deve stare nel fillable di Documento");
    }
});

test('la voce «allegato» dell\'Inbox porta alla scheda dell\'elemento nel pannello', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    algCarica($this, $this->mario, $s, [algFoto()])->assertSessionHasNoErrors();
    algCarica($this, $this->mario, $s, [algFoto('seconda.jpg')])->assertSessionHasNoErrors();

    $voci = DB::table('eventi')->where('tipo', 'allegato')->where('eventable_id', $s->id)->where('is_completed', false)->get();
    expect($voci)->toHaveCount(1)
        ->and(json_decode($voci->first()->meta, true)['action_url'] ?? null)->toBe(route('admin.segnalazioni.show', $s));
});

test('l\'email ai vicini non mostra mai l\'indirizzo email di chi ha caricato, nemmeno quando il nome manca', function () {
    $s = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => false]);
    DB::table('users')->where('id', $this->luigi->id)->update(['name' => '']);
    DB::table('anagrafiche')->where('id', $this->luigi->anagrafica->id)->update(['nome' => '']);
    $indirizzo = $this->luigi->email;

    algCarica($this, $this->luigi->fresh(), $s, [algFoto()])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->mario, ALG_MAIL_NUOVO, function ($notifica) use ($indirizzo) {
        $mail = $notifica->toMail($this->mario);

        return ! str_contains((string) $mail->render(), $indirizzo)
            && ! str_contains((string) $mail->subject, $indirizzo);
    });
});

test('un nome di file più lungo di 255 caratteri si accorcia e tiene l\'estensione', function () {
    $s = algSegnalazione($this->aurora, $this->mario);
    $nome = str_repeat('a', 300) . '.jpg';

    algCarica($this, $this->mario, $s, [algFoto($nome)])->assertSessionHasNoErrors();

    $riga = algRighe($s)->first();
    expect($riga)->not->toBeNull()
        ->and(mb_strlen($riga->name))->toBeLessThanOrEqual(255)
        ->and($riga->name)->toStartWith('aaaa')->toEndWith('.jpg');
});

test('i testi: la moderazione detta com\'è, il plurale del campo di caricamento, le etichette delle preferenze nuove', function () {
    app()->setLocale('it');

    expect(__('allegati.settings.moderation_hint'))->toBe(
        'Accesa: i file nuovi aspettano l\'approvazione, tranne quelli di chi li può approvare. Spenta: escono subito anche quelli di chi ha il permesso di pubblicarli.'
    );
    expect(__('impostazioni.dialogs.allegati_moderati_di_serie_description'))->toBe(
        'Vale per le segnalazioni e le comunicazioni nuove: i file caricati aspettano l\'approvazione, tranne quelli di chi li può approvare. Ogni elemento si cambia dalla sua scheda.'
    );

    $parametri = ['formati' => 'JPG, PDF', 'limite' => '10 MB', 'massimo' => 5];
    expect(trans_choice('allegati.upload.hint', 0, $parametri + ['rimasti' => 0]))
        ->toBe('Formati ammessi: JPG, PDF, al massimo 10 MB per file. Non si possono aggiungere altri file.');
    expect(trans_choice('allegati.upload.hint', 1, $parametri + ['rimasti' => 1]))
        ->toBe('Formati ammessi: JPG, PDF, al massimo 10 MB per file. Si può aggiungere ancora un file (al massimo 5).');
    expect(trans_choice('allegati.upload.hint', 3, $parametri + ['rimasti' => 3]))
        ->toBe('Formati ammessi: JPG, PDF, al massimo 10 MB per file. Si possono aggiungere ancora 3 file (al massimo 5).');

    $etichette = [
        'new_attachment' => ['Nuovo allegato', 'Ricevi una notifica quando vengono aggiunti file a una segnalazione a cui partecipi'],
        'attachment_approved' => ['Allegato approvato', 'Ricevi una notifica quando un tuo allegato in attesa viene approvato'],
        'attachment_deleted' => ['Allegato eliminato', 'Ricevi una notifica quando un tuo allegato viene eliminato da un altro'],
        'attachment_under_moderation' => ['Allegato da approvare', 'Ricevi una notifica quando un nuovo allegato richiede la tua approvazione'],
    ];
    foreach ($etichette as $tipo => [$etichetta, $descrizione]) {
        expect(__("settings.notification_types.{$tipo}.label"))->toBe($etichetta)
            ->and(__("settings.notification_types.{$tipo}.description"))->toBe($descrizione);
    }
});
