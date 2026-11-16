<?php

/**
 * Le risposte dell'amministratore ai sei mesi e ai 180 giorni, scritte solo dal server nella tabella `risposte_promemoria`, una riga per cosa a cui si
 * risponde (la riga di un esercizio, la persona in quella riga), ognuna con il suo registro {tipo, il, utente_id, utente, nota, dati}: «Registra che hai
 * agito», «Fatto» per le persone spuntate, «Non serve», «Ricordamelo», «Riapri». Chi può è chi chiude i compiti dell'Inbox («Modifica scadenza in agenda»).
 */

use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function srpRiga(array $s, string $argomento, ?int $esercizioId, ?string $chi = null): ?RispostaPromemoria
{
    return RispostaPromemoria::where('chiave', RispostePromemoria::chiave($argomento, (int) $s['c']->id, $esercizioId, $chi === null ? null : (int) $s['persone'][$chi]->id))->first();
}

it('«Registra che hai agito»: lo stato, il giorno, la nota, la cifra e le rate del giorno della registrazione, e una riga nel registro', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'pratica data all\'avv. Gallo', 'registro' => [['tipo' => 'falso']]])->assertRedirect()->assertSessionHasNoErrors();
    $r = srpRiga($s, 'sei_mesi', $s['e2025']->id, 'rossi');

    expect($r->stato)->toBe('agito')
        ->and($r->giorno->format('Y-m-d'))->toBe('2026-05-12')
        ->and($r->nota)->toBe('pratica data all\'avv. Gallo')
        ->and($r->condominio_id)->toBe($s['c']->id)
        ->and($r->argomento)->toBe('sei_mesi')
        ->and($r->dati)->toMatchArray(['cifra' => 20000, 'registrato_il' => '2026-05-20'])
        ->and($r->dati['chiavi'])->toEqualCanonicalizing([smaChiave($s, 'rossi', 1), smaChiave($s, 'rossi', 2)])
        // Il registro mandato dalla pagina non conta: lo scrive solo il server.
        ->and($r->registro)->toHaveCount(1)
        ->and(array_keys($r->registro[0]))->toBe(['tipo', 'il', 'utente_id', 'utente', 'nota', 'dati'])
        ->and($r->registro[0])->toMatchArray(['tipo' => 'agito', 'utente_id' => $this->user->id, 'utente' => 'Vincenzo', 'nota' => 'pratica data all\'avv. Gallo'])
        ->and($r->registro[0]['dati'])->toMatchArray(['giorno' => '2026-05-12', 'cifra' => 20000])
        ->and($r->aggiornata_da)->toBe($this->user->id);
});

it('i rifiuti di «hai agito»: la nota, il giorno dopo oggi, la persona che non è nella riga', function () {
    $s = smaViaVerdi($this);
    $altra = \App\Models\Anagrafica::forceCreate(['nome' => 'Senza rate', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SNZRTE00A00A000A']);
    $altra->condomini()->attach($s['c']->id);
    $base = ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id, 'giorno' => '2026-05-12', 'nota' => 'x y z'];

    smaRispondi($this, $s, ['nota' => ''] + $base)->assertSessionHasErrors(['nota' => 'Scrivi che cosa hai fatto.']);
    smaRispondi($this, $s, ['giorno' => '2026-05-21'] + $base)->assertSessionHasErrors(['giorno' => 'Il giorno in cui hai agito non può essere dopo oggi.']);
    smaRispondi($this, $s, ['anagrafica_id' => $altra->id] + $base)->assertSessionHasErrors(['anagrafica_id' => 'Questa persona non ha rate di questo esercizio nel riquadro.']);
    expect(RispostaPromemoria::count())->toBe(0);
});

it('«Fatto» con le persone spuntate: una riga della riga dell\'esercizio con le persone, e «hai agito» per ognuna con `da = fatto`', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'persone' => [$s['persone']['rossi']->id, $s['persone']['bianchi']->id],
        'giorno' => '2026-05-19', 'nota' => 'diffide mandate a mano'])->assertSessionHasNoErrors();
    $riga = srpRiga($s, 'sei_mesi', $s['e2025']->id);
    $rossi = srpRiga($s, 'sei_mesi', $s['e2025']->id, 'rossi');
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect($riga->stato)->toBe('fatto')
        ->and($riga->dati['persone'])->toEqualCanonicalizing([$s['persone']['rossi']->id, $s['persone']['bianchi']->id])
        ->and($riga->registro[0]['tipo'])->toBe('fatto')
        ->and($rossi->stato)->toBe('agito')
        ->and($rossi->dati['da'])->toBe('fatto')
        ->and($rossi->registro[0]['dati']['da'])->toBe('fatto')
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id, 'verdi'))->toBeNull()
        ->and($r25['quante'])->toBe(2)
        ->and($r25['risposta']['frase'])->toBe('Fatto il 19/05/2026 per 2 persone.');
});

it('i rifiuti di «Fatto»: nessuna persona spuntata, la nota vuota, una persona che non è nella riga', function () {
    $s = smaViaVerdi($this);
    $altra = \App\Models\Anagrafica::forceCreate(['nome' => 'Senza rate', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SNZRTE00A00A000B']);
    $altra->condomini()->attach($s['c']->id);
    $base = ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'persone' => [$s['persone']['rossi']->id], 'giorno' => '2026-05-19', 'nota' => 'fatto'];

    smaRispondi($this, $s, ['persone' => []] + $base)->assertSessionHasErrors(['persone' => 'Spunta almeno una persona.']);
    smaRispondi($this, $s, ['nota' => ''] + $base)->assertSessionHasErrors(['nota' => 'Scrivi che cosa hai fatto.']);
    smaRispondi($this, $s, ['persone' => [$altra->id]] + $base)->assertSessionHasErrors(['persone' => 'Questa persona non ha rate di questo esercizio nel riquadro.']);
    expect(RispostaPromemoria::count())->toBe(0);
});

it('«Non serve» con il motivo, «Ricordamelo» da domani, «Riapri» che toglie lo stato e lascia il registro', function () {
    $s = smaViaVerdi($this);
    $base = ['argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id];

    smaRispondi($this, $s, $base + ['tipo' => 'non_serve', 'motivo' => ''])->assertSessionHasErrors(['motivo' => 'Scrivi perché non serve.']);
    smaRispondi($this, $s, $base + ['tipo' => 'non_serve', 'motivo' => 'no'])->assertSessionHasErrors(['motivo' => 'Scrivi perché non serve.']);
    smaRispondi($this, $s, $base + ['tipo' => 'non_serve', 'motivo' => 'assemblea del 20/05 informata'])->assertSessionHasNoErrors();
    expect(srpRiga($s, 'sei_mesi', $s['e2025']->id)->stato)->toBe('non_serve')
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id)->dati['persone'])->toHaveCount(4)
        ->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase'])->toBe('Non serve: «assemblea del 20/05 informata» (20/05/2026, Vincenzo).');

    smaRispondi($this, $s, $base + ['tipo' => 'riapri'])->assertSessionHasNoErrors();
    expect(srpRiga($s, 'sei_mesi', $s['e2025']->id)->stato)->toBeNull()
        ->and(collect(srpRiga($s, 'sei_mesi', $s['e2025']->id)->registro)->pluck('tipo')->all())->toBe(['non_serve', 'riapri']);

    smaRispondi($this, $s, $base + ['tipo' => 'ricordamelo', 'giorno' => '2026-05-20'])->assertSessionHasErrors(['giorno' => 'Scegli un giorno da domani in poi.']);
    smaRispondi($this, $s, $base + ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15', 'nota' => ''])->assertSessionHasNoErrors();
    $r = srpRiga($s, 'sei_mesi', $s['e2025']->id);
    expect($r->stato)->toBe('ricordamelo')->and($r->ricordami_il->format('Y-m-d'))->toBe('2026-06-15')->and($r->promemoria_evento_id)->not->toBeNull()
        ->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['risposta']['frase'])->toBe('Ti ricordo il 15/06/2026.');
});

it('404 con l\'esercizio o la persona di un altro condominio', function () {
    $s = smaViaVerdi($this);
    $altro = smaViaVerdi($this);

    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $altro['e2025']->id, 'motivo' => 'per prova'])->assertNotFound();
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $altro['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'per prova'])->assertNotFound();
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'persone' => [$altro['persone']['rossi']->id],
        'giorno' => '2026-05-12', 'nota' => 'per prova'])->assertNotFound();
    expect(RispostaPromemoria::count())->toBe(0);
});

it('403 senza «Modifica scadenza in agenda»', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'per prova'], smaUtente(false))->assertForbidden();

    expect(RispostaPromemoria::count())->toBe(0);
});

it('due «Fatto» sulla stessa riga: una riga sola, e la seconda risposta non è un errore', function () {
    $s = smaViaVerdi($this);
    $dati = ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'persone' => [$s['persone']['rossi']->id], 'giorno' => '2026-05-19', 'nota' => 'prima'];
    smaRispondi($this, $s, $dati)->assertRedirect()->assertSessionHasNoErrors();
    // La seconda arriva con la riga già scritta: `insertOrIgnore` non trova niente da fare, il lucchetto la legge, la scrittura la aggiorna.
    smaRispondi($this, $s, ['persone' => [$s['persone']['bianchi']->id], 'nota' => 'seconda'] + $dati)->assertRedirect()->assertSessionHasNoErrors();

    expect(RispostaPromemoria::where('chiave', RispostePromemoria::chiave('sei_mesi', (int) $s['c']->id, (int) $s['e2025']->id, null))->count())->toBe(1)
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id)->registro)->toHaveCount(2)
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id)->nota)->toBe('seconda')
        ->and(DB::table('risposte_promemoria')->count())->toBe(3);
});

it('la chiave regge una riga senza persona, una persona senza esercizio e un compito dell\'agenda', function () {
    expect(RispostePromemoria::chiave('sei_mesi', 7, 12, null))->toBe('sei_mesi|7|12|-')
        ->and(RispostePromemoria::chiave('persona', 7, null, 31))->toBe('persona|7|-|31')
        ->and(RispostePromemoria::chiave('rendiconto', 7, 12, null))->toBe('rendiconto|7|12|-')
        ->and(RispostePromemoria::chiaveDelCompito(5012))->toBe('compito|5012');
});

it('«Riapri» della riga chiusa da «Fatto» riapre le persone di quel «Fatto», e il messaggio dice quante; chi ha registrato a parte resta chiuso', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'persone' => [$s['persone']['rossi']->id, $s['persone']['bianchi']->id],
        'giorno' => '2026-05-19', 'nota' => 'diffide mandate a mano'])->assertSessionHasNoErrors();
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['verdi']->id,
        'giorno' => '2026-05-19', 'nota' => 'pratica dal legale'])->assertSessionHasNoErrors();
    expect(smaRiga(smaRiquadro($s), 'Esercizio 2025')['quante'])->toBe(1);

    smaRispondi($this, $s, ['tipo' => 'riapri', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id])
        ->assertSessionHasNoErrors()->assertSessionHas('message.message', 'Riaperto per 2 persone: tornano nel riquadro. La riga del registro resta.');
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect($r25['quante'])->toBe(3)
        ->and(smaPersona($r25, 'Rossi'))->not->toBeNull()
        ->and(smaPersona($r25, 'Bianchi'))->not->toBeNull()
        ->and(collect($r25['chiuse'])->pluck('nome')->all())->toBe(['Verdi'])
        ->and(collect(srpRiga($s, 'sei_mesi', $s['e2025']->id, 'rossi')->registro)->pluck('tipo')->all())->toBe(['agito', 'riapri'])
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id, 'rossi')->stato)->toBeNull()
        ->and(srpRiga($s, 'sei_mesi', $s['e2025']->id, 'verdi')->stato)->toBe('agito');
});

it('la frase di «Non serve» dice il giorno e chi l\'ha scritto, anche dopo un altro gesto sulla stessa riga', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea già convocata'])->assertSessionHasNoErrors();
    $this->travelTo('2026-05-22 10:00:00');
    $altra = smaUtente();
    $altra->update(['name' => 'Collaboratrice']);
    smaRispondi($this, $s, ['tipo' => 'in_agenda', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id], $altra)->assertSessionHasNoErrors();
    $r = collect(smaRiquadro($s)['rendiconti'])->firstWhere('esercizio.nome', 'Esercizio 2025');

    expect($r['risposta']['frase'])->toBe('Non serve: «assemblea già convocata» (20/05/2026, Vincenzo).');
});

it('«Riapri» dove non c\'è una risposta è un 404, e non scrive righe vuote', function () {
    $s = smaViaVerdi($this);

    smaRispondi($this, $s, ['tipo' => 'riapri', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id])->assertNotFound();
    smaRispondi($this, $s, ['tipo' => 'riapri', 'argomento' => 'rendiconto', 'esercizio_id' => $s['e2025']->id])->assertNotFound();
    expect(RispostaPromemoria::count())->toBe(0);
});
