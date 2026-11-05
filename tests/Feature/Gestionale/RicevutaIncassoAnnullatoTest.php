<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * La ricevuta di un incasso annullato si può mandare anche per email, se era già partita, allo stesso indirizzo: il canale lo sceglie
 * l'amministratore, che non deve passare dalla stampa e dalla posta per dire a chi aveva l'originale che quel versamento non c'è più. Si manda
 * solo agli indirizzi a cui la ricevuta era già andata; con «INCASSO ANNULLATO» in testa e le cifre dell'originale. La pagina delle ricevute
 * smette di avvisare quando la ricevuta è uscita di nuovo, per stampa o per email.
 *
 * Che cosa NON copre: il blocco, che salta gli incassi annullati (si parte da una ricevuta, dalla sua finestra).
 */
function inviaUnaAnnullata($test, object $s, ScritturaContabile $incasso, array $campi, $utente = null)
{
    return $test->actingAs($utente ?? amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]), $campi);
}

function opzioniAnnullata($test, object $s, ScritturaContabile $incasso, $utente = null): array
{
    return $test->actingAs($utente ?? amministratoreRicevute())
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->assertOk()->json();
}

/** Una ricevuta mandata per email a Anna (primaria) e partita davvero, poi l'incasso annullato. */
function incassoMandatoEPoiAnnullato($test, object $s, array $tipi = ['email']): ScritturaContabile
{
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $test->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
            ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => $tipi]], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $test->travel(1)->minutes();
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);

    return $incasso->fresh();
}

test('un incasso annullato la cui ricevuta era partita per email si può mandare di nuovo per email, agli stessi indirizzi, con «INCASSO ANNULLATO» in testa', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassoMandatoEPoiAnnullato($this, $s, ['email', 'email_secondaria']);

    $opzioni = opzioniAnnullata($this, $s, $incasso);
    expect($opzioni['annullato'])->toBeTrue()
        ->and($opzioni['inviabile'])->toBeTrue()
        ->and($opzioni['destinatari'])->toHaveCount(1)
        ->and($opzioni['destinatari'][0]['anagrafica_id'])->toBe($s->anna->id)
        ->and($opzioni['destinatari'][0]['indirizzi'])->toBe([
            ['tipo' => 'email', 'indirizzo' => $s->anna->email, 'proposto' => true, 'chiave' => 'email|' . $s->anna->email],
            ['tipo' => 'email_secondaria', 'indirizzo' => $s->anna->email_secondaria, 'proposto' => true, 'chiave' => 'email_secondaria|' . $s->anna->email_secondaria],
        ]);

    inviaUnaAnnullata($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'])
        ->assertOk()->assertJson(['in_coda' => 1, 'messaggi' => 1]);

    $nuova = RegistroInvio::orderByDesc('id')->first();
    expect($nuova->stato)->toBe('in_coda')
        ->and($nuova->destinatario)->toBe($s->anna->email)
        ->and($nuova->dati['contenuto']['annullato'])->toBeTrue()
        ->and($nuova->dati['copia_del'])->not->toBeNull();
    expect(notificheRicevutaA($s->anna->email))->toHaveCount(2);
});

test('della ricevuta annullata si propongono gli indirizzi dell\'originale come erano allora, anche se nell\'anagrafica sono cambiati, e un altro non è ammesso', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassoMandatoEPoiAnnullato($this, $s);
    $vecchio = $s->anna->email;
    $s->anna->update(['email' => 'anna.nuova@prova.it']);

    expect(opzioniAnnullata($this, $s, $incasso)['destinatari'][0]['indirizzi'])->toBe([['tipo' => 'email', 'indirizzo' => $vecchio, 'proposto' => true, 'chiave' => 'email|' . $vecchio]]);

    // La secondaria, la PEC, Carlo, un indirizzo scritto a mano: non erano fra i destinatari della ricevuta.
    foreach ([
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email_secondaria']]]],
        ['destinatari' => [['anagrafica_id' => $s->carlo->id, 'tipi' => ['email']]]],
        ['destinatari' => [], 'altro_indirizzo' => 'chiunque@esterno.example'],
        ['destinatari' => []],
    ] as $campi) {
        inviaUnaAnnullata($this, $s, $incasso, $campi + ['bollo' => 'non_dovuta'])->assertStatus(422);
    }
    // Restano la riga e il messaggio dell'invio di prima: i quattro tentativi rifiutati non hanno scritto né spedito niente.
    expect(RegistroInvio::count())->toBe(1);
    Notification::assertSentTimes(\App\Notifications\Gestionale\RicevutaIncassoNotification::class, 1);

    inviaUnaAnnullata($this, $s, $incasso, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'])->assertOk();
    expect(notificheRicevutaA($vecchio))->toHaveCount(2)->and(notificheRicevutaA('anna.nuova@prova.it'))->toHaveCount(0);
});

test('un indirizzo scritto a mano a cui la ricevuta era andata si propone anche dopo l\'annullamento', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    inviaUnaAnnullata($this, $s, $incasso, ['destinatari' => [], 'altro_indirizzo' => 'studio.legale@prova.it', 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);

    $indirizzi = opzioniAnnullata($this, $s, $incasso->fresh())['destinatari'][0]['indirizzi'];
    expect($indirizzi)->toBe([['tipo' => 'altro', 'indirizzo' => 'studio.legale@prova.it', 'proposto' => true, 'chiave' => 'altro|studio.legale@prova.it']]);

    inviaUnaAnnullata($this, $s, $incasso->fresh(), ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['altro']]], 'bollo' => 'assolta'])->assertOk();
    expect(notificheRicevutaA('studio.legale@prova.it'))->toHaveCount(2);
});

test('se la ricevuta era solo stampata, o l\'invio non è partito o è ancora in coda, l\'incasso annullato non si manda per email: si stampa', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $stampata = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $nonPartita = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $this->actingAs(amministratoreRicevute())->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$stampata->id], 'bollo' => 'assolta'])->assertOk();
    inviaUnaAnnullata($this, $s, $nonPartita, ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::where('documentabile_id', $nonPartita->id)->update(['stato' => 'non_partita', 'errore' => 'Rifiutato']);
    foreach ([$stampata, $nonPartita] as $i) {
        app(StornoIncassoRateAction::class)->execute($i->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    }

    foreach ([$stampata, $nonPartita] as $i) {
        $opzioni = opzioniAnnullata($this, $s, $i->fresh());
        expect($opzioni['inviabile'])->toBeFalse()->and($opzioni['destinatari'])->toBe([]);
        inviaUnaAnnullata($this, $s, $i->fresh(), ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertStatus(422)->assertSee('stampare', false);
    }
});

test('«Invia di nuovo» rimanda anche una ricevuta annullata, se la riga da cui si parte era partita; una riga non partita no', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $incasso = incassoMandatoEPoiAnnullato($this, $s);
    $partita = RegistroInvio::sole();
    $nonPartita = $partita->replicate();
    $nonPartita->stato = 'non_partita';
    $nonPartita->errore = 'Rifiutato';
    $nonPartita->save();
    $url = fn (RegistroInvio $r) => route('admin.gestionale.ricevute.invia-di-nuovo', ['condominio' => $s->condominio->id, 'invio' => $r->id]);

    $this->actingAs(amministratoreRicevute())->postJson($url($nonPartita))->assertStatus(422);
    $this->actingAs(amministratoreRicevute())->postJson($url($partita))->assertOk()->assertJson(['in_coda' => 1]);

    expect(RegistroInvio::orderByDesc('id')->first()->dati['contenuto']['annullato'])->toBeTrue();
});

test('un messaggio accodato prima dell\'annullamento non parte; uno accodato dopo, con «INCASSO ANNULLATO» nel contenuto, parte', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $incasso = incassoMandatoEPoiAnnullato($this, $s);
    $base = RegistroInvio::first()->only(['condominio_id', 'anagrafica_id', 'tipo', 'canale', 'documentabile_type', 'documentabile_id', 'numero', 'destinatario']) + ['stato' => 'in_coda', 'emesso_il' => now()];
    $accodataPrima = RegistroInvio::create($base + ['dati' => ['contenuto' => ['annullato' => false]]]);
    $accodataDopo = RegistroInvio::create($base + ['dati' => ['contenuto' => ['annullato' => true]]]);
    $parte = fn (RegistroInvio $r) => (new \App\Notifications\Gestionale\RicevutaIncassoNotification([$r->id]))->shouldSend(new \Illuminate\Notifications\AnonymousNotifiable, 'mail');

    expect($incasso->stato)->toBe('annullata')
        ->and($parte($accodataPrima))->toBeFalse()
        ->and($accodataPrima->fresh()->stato)->toBe('non_partita')
        ->and($accodataPrima->fresh()->errore)->toContain('annullato prima dell\'invio')
        ->and($parte($accodataDopo))->toBeTrue()
        ->and($accodataDopo->fresh()->stato)->toBe('in_coda');
});

test('l\'avviso «annullate da comunicare» si spegne quando la ricevuta esce di nuovo, per stampa o per email', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $perEmail = incassoMandatoEPoiAnnullato($this, $s);
    $perStampa = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$perStampa->id], 'bollo' => 'assolta'])->assertOk();
    $this->travel(1)->minutes();
    app(StornoIncassoRateAction::class)->execute($perStampa->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio);
    $daComunicare = fn () => collect($this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))
        ->viewData('page')['props']['annullate_da_comunicare'])->pluck('id')->sort()->values()->all();

    expect($daComunicare())->toBe(collect([$perEmail->id, $perStampa->id])->sort()->values()->all());

    $this->travel(1)->minutes();
    inviaUnaAnnullata($this, $s, $perEmail, ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'], $u)->assertOk();
    expect($daComunicare())->toBe([$perStampa->id]);

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$perStampa->id], 'bollo' => 'non_dovuta'])->assertOk();
    expect($daComunicare())->toBe([]);
});
