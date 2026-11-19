<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Segnalazioni: il giro 3 (decisione 303 — nella pagina di modifica tutto si salva con «Salva»)
 *
 * - **C1** «Assegnata a» è un campo del modulo di modifica del pannello. Conta solo per chi può assegnare; senza il
 *   campo l'assegnazione non si tocca; il campo vuoto la toglie. Passa dalle stesse regole del comando della scheda: chi
 *   può ricevere, da chi e quando, le due email, «porta in lavorazione».
 * - **C2** «Approvazione» è una tendina del modulo di modifica, per chi approva le segnalazioni. Senza il campo, salvare
 *   continua a non approvare (decisione 127.2). Dare l'approvazione avvisa come lo scudo dell'elenco, una volta sola.
 * - **C3** «Assegnata a» anche in «Crea segnalazione» del pannello.
 * - **C4** Nell'elenco la data e chi segue la segnalazione stanno sotto il titolo: qui ci sono solo i testi.
 *
 * Il portale non assegna mai: né creando né modificando.
 */

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\ApprovedSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** Il modulo di modifica del pannello come lo manda la pagina, con i campi dati al posto di quelli salvati. */
function asgSalva($test, User $chi, Segnalazione $s, array $campi = []): TestResponse
{
    return $test->actingAs($chi)->put(route('admin.segnalazioni.update', $s), $campi + [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'can_comment' => true, 'is_featured' => false, 'is_published' => (bool) $s->is_published,
        'condominio_id' => $s->condominio_id,
    ]);
}

/** Il modulo «Crea segnalazione» del pannello, per il palazzo Aurora. */
function asgCrea($test, User $chi, array $campi = []): TestResponse
{
    return $test->actingAs($chi)->post(route('admin.segnalazioni.store'), $campi + [
        'subject' => 'Lampada del vano scale', 'description' => 'Al terzo piano è spenta da ieri.', 'priority' => 'media',
        'stato' => 'aperta', 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'condominio_id' => $test->aurora->id,
    ]);
}

/** La segnalazione nata dall'ultimo «Crea segnalazione», letta dal database; `null` se non ne è nata nessuna. */
function asgCreata(): ?object
{
    return \Illuminate\Support\Facades\DB::table('segnalazioni')->where('subject', 'Lampada del vano scale')->orderByDesc('id')->first();
}

/** Qualcuno dello studio che modifica e crea le segnalazioni, ma non le assegna e non le approva. */
function asgRedattrice(): User
{
    $ruolo = ccmRuolo('redattrice', ['Accesso pannello amministratore', 'Visualizza segnalazioni', 'Modifica segnalazioni', 'Crea segnalazioni']);

    return ccmStudioSenzaAnagrafica('Rita Redattrice', $ruolo);
}

/** Qualcuno che non può ricevere una segnalazione: un condòmino, una collaboratrice sospesa, nessuno. */
function asgNonRiceve($test, string $caso): int
{
    $sospesa = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    $sospesa->forceFill(['suspended_at' => now()])->save();

    return ['condomino' => $test->mario->id, 'sospesa' => $sospesa->id, 'inesistente' => 999999][$caso];
}

/** Quante volte una notifica della classe è partita a qualcuno. */
function asgVolte(object $aChi, string $classe): int
{
    return Notification::sent($aChi, $classe)->count();
}

// --- C1: «Assegnata a» nel modulo di modifica ----------------------------------------------------------------------

test('dal modulo di modifica si assegna: a chi, da chi e quando, insieme agli altri campi', function () {
    asgSalva($this, $this->admin, $this->s, ['subject' => 'Il cancello non chiude, ancora', 'assigned_to' => $this->elena->id])
        ->assertSessionHasNoErrors();

    $riga = asgRiga($this->s);
    expect((int) $riga->assigned_to)->toBe($this->elena->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe(ASG_ADESSO)
        ->and($riga->subject)->toBe('Il cancello non chiude, ancora')
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena))
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('dal modulo si riassegna, e lo sanno chi la riceve e chi non la segue più', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgSalva($this, $this->admin, $s, ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assigned_to)->toBe($this->elena->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe(ASG_ADESSO)
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena))
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe(ccmIds($this->collaboratrice));
});

test('il campo vuoto toglie l\'assegnazione, e chi la seguiva lo sa', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgSalva($this, $this->admin, $s, ['assigned_to' => null])->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect($riga->assigned_to)->toBeNull()
        ->and($riga->assegnata_da)->toBeNull()
        ->and($riga->assegnata_il)->toBeNull()
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe(ccmIds($this->collaboratrice));
});

test('senza il campo l\'assegnazione non si tocca', function () {
    $s = asgAssegnata($this, $this->collaboratrice, $this->admin);

    asgSalva($this, $this->elena, $s, ['subject' => 'Il cancello non chiude, ancora'])->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assigned_to)->toBe($this->collaboratrice->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe('2026-11-10 08:30:00')
        ->and($riga->subject)->toBe('Il cancello non chiude, ancora')
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([])
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('salvare con la persona che la segue già non cambia da chi e quando, e non avvisa nessuno', function () {
    $s = asgAssegnata($this, $this->collaboratrice, $this->admin);

    asgSalva($this, $this->elena, $s, ['assigned_to' => $this->collaboratrice->id])->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assigned_to)->toBe($this->collaboratrice->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe('2026-11-10 08:30:00')
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([])
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('dal modulo non si assegna a chi non può ricevere, e non si salva niente', function (string $caso) {
    $id = asgNonRiceve($this, $caso);

    $risposta = asgSalva($this, $this->admin, $this->s, ['subject' => 'Il cancello non chiude, ancora', 'assigned_to' => $id]);

    $risposta->assertSessionHasErrors('assigned_to');
    $riga = asgRiga($this->s);
    expect($riga->assigned_to)->toBeNull()
        ->and($riga->subject)->toBe('Il cancello non chiude')
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
    if ($caso !== 'inesistente') {
        expect(session('errors')->first('assigned_to'))->toBe(__('segnalazioni.assignment.not_assignable'));
    }
})->with(['condomino', 'sospesa', 'inesistente']);

test('un valore scritto male nel campo è rifiutato', function () {
    asgSalva($this, $this->admin, $this->s, ['assigned_to' => 'elena'])->assertSessionHasErrors('assigned_to');

    expect(asgRiga($this->s)->assigned_to)->toBeNull();
});

test('se chi la segue non può più ricevere, il modulo si salva lo stesso e lei resta', function () {
    $s = asgAssegnata($this, $this->collaboratrice, $this->admin);
    $this->collaboratrice->forceFill(['suspended_at' => now()])->save();

    asgSalva($this, $this->admin, $s, ['subject' => 'Il cancello non chiude, ancora', 'assigned_to' => $this->collaboratrice->id])
        ->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assigned_to)->toBe($this->collaboratrice->id)
        ->and($riga->assegnata_il)->toBe('2026-11-10 08:30:00')
        ->and($riga->subject)->toBe('Il cancello non chiude, ancora');
});

test('chi non può assegnare salva il modulo, e il campo non conta', function () {
    $redattrice = asgRedattrice();
    $libera = $this->s;
    $seguita = asgAssegnata($this, $this->collaboratrice, $this->admin);

    asgSalva($this, $redattrice, $libera, ['subject' => 'Titolo nuovo', 'assigned_to' => $this->elena->id])->assertSessionHasNoErrors();
    asgSalva($this, $redattrice, $seguita, ['subject' => 'Titolo nuovo', 'assigned_to' => null])->assertSessionHasNoErrors();
    asgSalva($this, $redattrice, $seguita->fresh(), ['assigned_to' => $this->mario->id])->assertSessionHasNoErrors();

    expect(asgRiga($libera)->assigned_to)->toBeNull()
        ->and(asgRiga($libera)->subject)->toBe('Titolo nuovo')
        ->and((int) asgRiga($seguita)->assigned_to)->toBe($this->collaboratrice->id)
        ->and(asgRiga($seguita)->subject)->toBe('Titolo nuovo')
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([])
        ->and(ccmDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('con «porta in lavorazione» accesa, assegnare dal modulo una segnalazione aperta la porta in lavorazione', function (bool $accesa, string $attesa) {
    asgImpostazione(ASG_IMP_STATO, $accesa);

    asgSalva($this, $this->admin, $this->s, ['stato' => 'aperta', 'assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    expect(asgRiga($this->s)->stato)->toBe($attesa)
        ->and((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id);
})->with([[true, 'in lavorazione'], [false, 'aperta']]);

test('con «porta in lavorazione» accesa, rimettere «aperta» una segnalazione già seguita la lascia aperta', function () {
    asgImpostazione(ASG_IMP_STATO, true);
    $s = asgAssegnata($this, $this->elena, $this->admin, ['stato' => 'in lavorazione']);

    asgSalva($this, $this->admin, $s, ['stato' => 'aperta', 'assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    expect(asgRiga($s)->stato)->toBe('aperta');
});

test('l\'assegnazione non è fra i campi che il model accetta in blocco', function () {
    expect((new Segnalazione)->isFillable('assigned_to'))->toBeFalse();

    $s = ccmSegnalazione($this->aurora, $this->mario, ['assigned_to' => $this->elena->id]);
    $s->update(['assigned_to' => $this->collaboratrice->id]);

    expect(asgRiga($s)->assigned_to)->toBeNull();
});

// --- C2: «Approvazione» nel modulo di modifica ---------------------------------------------------------------------

test('chi approva le segnalazioni approva dal modulo, e gli avvisi sono quelli dello scudo, una volta sola', function (bool $avvisa) {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgSalva($this, $this->admin, $s, ['is_approved' => true, 'avvisa_destinatari' => $avvisa])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeTrue()
        ->and((bool) asgRiga($s)->is_published)->toBeTrue()
        // Chi l'ha scritta sa che è approvata; non riceve anche «nuova segnalazione» della propria.
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(1)
        ->and(asgVolte($this->mario->anagrafica, NewSegnalazioneNotification::class))->toBe(0)
        // Gli altri del palazzo la vedono adesso: «nuova segnalazione», una volta, e non «aggiornata».
        ->and(asgVolte($this->paolo->anagrafica, NewSegnalazioneNotification::class))->toBe(1)
        ->and(asgVolte($this->paolo->anagrafica, UpdatedSegnalazioneNotification::class))->toBe(0)
        ->and(asgVolte($this->lucia->anagrafica, NewSegnalazioneNotification::class))->toBe(0);
})->with([false, true]);

test('salvare una segnalazione già approvata non avvisa di nuovo', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);

    asgSalva($this, $this->admin, $s, ['subject' => 'Il cancello non chiude, ancora', 'is_approved' => true])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeTrue()
        ->and(asgRiga($s)->subject)->toBe('Il cancello non chiude, ancora')
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(0)
        ->and(asgVolte($this->paolo->anagrafica, NewSegnalazioneNotification::class))->toBe(0);
});

test('dal modulo si toglie l\'approvazione: nessuno la vede più, e nessuno riceve un avviso', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);
    $s->anagrafiche()->attach($this->mario->anagrafica->id);

    // Nello stesso salvataggio entra un destinatario nuovo: non riceve «nuova segnalazione» di una che non vede.
    asgSalva($this, $this->admin, $s, [
        'is_approved' => false, 'anagrafiche' => [$this->mario->anagrafica->id, $this->paolo->anagrafica->id],
    ])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeFalse()
        ->and((bool) asgRiga($s)->is_published)->toBeTrue()
        ->and(asgVolte($this->paolo->anagrafica, NewSegnalazioneNotification::class))->toBe(0)
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(0);
});

test('senza il campo, salvare continua a non approvare e a non togliere l\'approvazione', function () {
    $inAttesa = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => false]);
    $approvata = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);

    asgSalva($this, $this->admin, $inAttesa, ['is_published' => true])->assertSessionHasNoErrors();
    asgSalva($this, $this->admin, $approvata, ['subject' => 'Titolo nuovo'])->assertSessionHasNoErrors();

    expect((bool) asgRiga($inAttesa)->is_approved)->toBeFalse()
        ->and((bool) asgRiga($inAttesa)->is_published)->toBeTrue()
        ->and((bool) asgRiga($approvata)->is_approved)->toBeTrue()
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(0);
});

test('chi non approva le segnalazioni salva il modulo, e il campo non conta', function () {
    $inAttesa = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);
    $approvata = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);

    asgSalva($this, $this->collaboratrice, $inAttesa, ['subject' => 'Titolo nuovo', 'is_approved' => true])->assertSessionHasNoErrors();
    asgSalva($this, $this->collaboratrice, $approvata, ['subject' => 'Titolo nuovo', 'is_approved' => false])->assertSessionHasNoErrors();

    expect((bool) asgRiga($inAttesa)->is_approved)->toBeFalse()
        ->and(asgRiga($inAttesa)->subject)->toBe('Titolo nuovo')
        ->and((bool) asgRiga($approvata)->is_approved)->toBeTrue()
        ->and(asgRiga($approvata)->subject)->toBe('Titolo nuovo')
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(0);
});

test('un valore scritto male nell\'approvazione è rifiutato, e non si salva niente', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgSalva($this, $this->admin, $s, ['subject' => 'Titolo nuovo', 'is_approved' => 'forse'])->assertSessionHasErrors('is_approved');

    expect((bool) asgRiga($s)->is_approved)->toBeFalse()
        ->and(asgRiga($s)->subject)->toBe('Il cancello non chiude');
});

test('assegnare e approvare nello stesso salvataggio fanno tutte e due le cose', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgSalva($this, $this->admin, $s, ['is_approved' => true, 'assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeTrue()
        ->and((int) asgRiga($s)->assigned_to)->toBe($this->elena->id)
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena))
        ->and(asgVolte($this->mario, ApprovedSegnalazioneNotification::class))->toBe(1);
});

// --- C3: «Assegnata a» in «Crea segnalazione» ----------------------------------------------------------------------

test('la pagina «Crea segnalazione» riceve ciò che serve al campo, come la scheda', function () {
    $scheda = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $this->s)));
    $crea = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.create')));

    expect($crea)->not->toBeNull('La pagina «Crea segnalazione» non si apre')
        ->and($crea['assegnazione'] ?? null)->toEqual($scheda['assegnazione'])
        ->and($crea['assegnazione']['puo_assegnare'])->toBeTrue()
        ->and(array_column($crea['assegnazione']['assegnabili'], 'name'))->toBe(['Anna Collaboratrice', 'Elena Studio', 'Studio Bianchi']);
});

test('a chi non può assegnare la pagina «Crea segnalazione» non dà nomi', function () {
    $redattrice = asgRedattrice();

    $crea = asgProps($this->actingAs($redattrice)->get(route('admin.segnalazioni.create')));

    expect($crea['assegnazione'] ?? null)->toEqual(['puo_assegnare' => false, 'io' => $redattrice->id, 'assegnabili' => []]);
});

test('creando una segnalazione la si assegna: a chi, da chi e quando, e chi la riceve lo sa', function () {
    asgCrea($this, $this->admin, ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    $riga = asgCreata();
    expect($riga)->not->toBeNull('La segnalazione non è nata')
        ->and((int) $riga->assigned_to)->toBe($this->elena->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe(ASG_ADESSO)
        ->and((int) $riga->created_by)->toBe($this->admin->id)
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena));
});

test('chi crea una segnalazione e la tiene per sé non riceve «assegnata a te»', function () {
    asgCrea($this, $this->elena, ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    expect((int) asgCreata()->assigned_to)->toBe($this->elena->id)
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
});

test('senza il campo, o con il campo vuoto, la segnalazione nasce di nessuno', function (array $campi) {
    asgCrea($this, $this->admin, $campi)->assertSessionHasNoErrors();

    $riga = asgCreata();
    expect($riga)->not->toBeNull('La segnalazione non è nata')
        ->and($riga->assigned_to)->toBeNull()
        ->and($riga->assegnata_da)->toBeNull()
        ->and($riga->assegnata_il)->toBeNull()
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
})->with([[[]], [['assigned_to' => null]], [['assigned_to' => '']]]);

test('non nasce una segnalazione assegnata a chi non può ricevere', function (string $caso) {
    $id = asgNonRiceve($this, $caso);

    asgCrea($this, $this->admin, ['assigned_to' => $id])->assertSessionHasErrors('assigned_to');

    expect(asgCreata())->toBeNull()
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
})->with(['condomino', 'sospesa', 'inesistente']);

test('chi non può assegnare crea la segnalazione, e il campo non conta', function () {
    asgCrea($this, asgRedattrice(), ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    $riga = asgCreata();
    expect($riga)->not->toBeNull('La segnalazione non è nata')
        ->and($riga->assigned_to)->toBeNull()
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
});

test('con «porta in lavorazione» accesa, una segnalazione creata già assegnata nasce in lavorazione', function (bool $accesa, string $attesa) {
    asgImpostazione(ASG_IMP_STATO, $accesa);

    asgCrea($this, $this->admin, ['assigned_to' => $this->elena->id])->assertSessionHasNoErrors();

    expect(asgCreata()->stato)->toBe($attesa);
})->with([[true, 'in lavorazione'], [false, 'aperta']]);

test('in creazione l\'approvazione continua a deciderla il permesso di pubblicare, non il campo', function () {
    asgCrea($this, $this->admin, ['is_approved' => false])->assertSessionHasNoErrors();

    expect((bool) asgCreata()->is_approved)->toBeTrue();
});

// --- Il portale ----------------------------------------------------------------------------------------------------

test('nel portale non si assegna, né creando né modificando', function () {
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Portone', 'description' => 'Non chiude', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false, 'assigned_to' => $this->elena->id,
    ])->assertSessionHasNoErrors();
    $nata = Segnalazione::where('subject', 'Portone')->first();

    expect($nata)->not->toBeNull('La segnalazione del portale non è nata')
        ->and(asgRiga($nata)->assigned_to)->toBeNull();

    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $nata), [
        'subject' => 'Portone', 'description' => 'Non chiude ancora', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false, 'assigned_to' => $this->elena->id,
    ]);

    expect(asgRiga($nata)->assigned_to)->toBeNull()
        ->and(ccmDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
});

// --- I testi -------------------------------------------------------------------------------------------------------

test('i testi nuovi ci sono nelle quattro lingue, e quelli del riquadro che si salvava subito non ci sono più', function () {
    $it = fn (string $chiave) => __("segnalazioni.{$chiave}", [], 'it');

    expect($it('label.approval'))->toBe('Approvazione')
        ->and($it('approval.approved'))->toBe('Approvata')
        ->and($it('approval.pending'))->toBe('Da approvare')
        ->and($it('tooltip.assigned_to'))->toBe('Chi nello studio segue la segnalazione. Riceve un\'email quando gliela assegni.')
        ->and($it('tooltip.approval'))->toBe('Una segnalazione si vede nel portale quando è pubblicata e approvata. Quando la approvi, chi l\'ha scritta riceve un avviso.');

    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        foreach (['label.approval', 'approval.approved', 'approval.pending', 'tooltip.assigned_to', 'tooltip.approval'] as $chiave) {
            expect(Lang::hasForLocale("segnalazioni.{$chiave}", $lingua))->toBeTrue("Manca segnalazioni.{$chiave} in {$lingua}");
        }
        foreach (['actions.save_first', 'section.assignment_title', 'section.assignment_desc', 'table.sent'] as $chiave) {
            expect(Lang::hasForLocale("segnalazioni.{$chiave}", $lingua))->toBeFalse("segnalazioni.{$chiave} in {$lingua} non serve più");
        }
    }
    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['approval.pending', 'tooltip.assigned_to', 'tooltip.approval'] as $chiave) {
            expect(__("segnalazioni.{$chiave}", [], $lingua))->not->toBe($it($chiave), "segnalazioni.{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});
