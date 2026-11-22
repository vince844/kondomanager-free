<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Verbale\CifreDellaSala;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le cifre del verbale (V3, scena 2): una cifra della parte «Dati della sala» corretta nel testo e che non torna con i voti è l'avviso («Nel punto 4 il verbale
 * dice «343,70», i voti registrati dicono 343,75»), con «Rimetti» e «Vai al voto del punto 4»; finché c'è, «Stampa la bozza da firmare» chiede prima. Il voto
 * si corregge in sala fino alla registrazione (§0.4 (5)): allora «I dati della sala sono cambiati: aggiorna le parti segnate», che rifà le sole parti segnate in
 * una versione nuova.
 *
 * Che cosa non copre: la cifra cambiata nell'editor con «Rimetti» (la pagina).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il testo intero con la cifra cambiata nella parte del voto del punto 4. */
function cdsCambiaLaCifra($verbale, int $votazione, string $da, string $a): array
{
    $doc = (array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento');
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    $chiave = '"chiave":"voto:' . $votazione . '"';
    $inizio = strpos($json, $chiave);
    $prima = substr($json, 0, $inizio);
    $dopo = substr($json, $inizio);
    $dopo = preg_replace('/"text":"Favorevoli: ([^"]*?)' . preg_quote($da, '/') . '/u', '"text":"Favorevoli: ${1}' . $a, $dopo, 1);

    return json_decode($prima . $dopo, true);
}

it('una cifra che non torna con i voti è l\'avviso, con «Rimetti» e «Vai al voto»; la stampa della bozza chiede prima, poi stampa', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    expect(app(CifreDellaSala::class)->controlla($verbale)['avvisi'])->toBe([]);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:40');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', cdsCambiaLaCifra($verbale, $v[4], '343,75', '343,70'), '2026-12-15 21:14', 1)]);

    $avvisi = app(CifreDellaSala::class)->controlla($verbale->refresh())['avvisi'];
    expect($avvisi)->toHaveCount(1)->and($avvisi[0])->toMatchArray(['chiave' => 'voto:' . $v[4], 'punto' => 4, 'nel_testo' => '343,70', 'adesso' => '343,75',
        'frase' => 'Nel punto 4 il verbale dice «343,70», i voti registrati dicono 343,75.', 'rimetti' => 'Rimetti 343,75', 'vai_al_voto' => 'Vai al voto del punto 4']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]))
        ->assertSessionHasErrors(['cifre' => 'Nel punto 4 il verbale dice «343,70», i voti registrati dicono 343,75.']);
    expect($verbale->refresh()->stampata)->toBeNull();
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]), ['anche_con_le_cifre' => true]);
    expect($r->status())->toBe(200)->and($r->headers->get('Content-Type'))->toBe('application/pdf')
        // La versione 1 è il testo generato; la correzione non è una versione (la pausa tiene il testo di prima, uguale al generato); la bozza è la 2.
        ->and($verbale->refresh()->stampata)->toMatchArray(['versione' => 2])->and($verbale->stampata['impronta'])->toBe(hash('sha256', (string) $r->getContent()));
});

it('il voto corretto in sala dopo la chiusura, prima della registrazione: la sala è cambiata, e «Aggiorna le parti segnate» rifà le sole parti segnate', function () {
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $discussione = StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Una frase scritta a mano.'),
        ...array_values(array_filter((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento')['content'],
            fn ($n) => true))]);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:25');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', $discussione, '2026-12-15 21:10', 1)]);

    // In sala, dopo la chiusura: il condomino 35 era favorevole, non astenuto.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v[4], 'testa' => VerbaleDiViaRoma12::testa($v[4], 'Condomino 35'),
        'voto' => 'favorevole', 'perche' => 'Il presidente aveva letto male la mano alzata.'], '2026-12-15 21:20', 1));
    VerbaleDiViaRoma12::conferma($this, $s, $a, $v[4], '2026-12-15 21:21');

    $esito = app(CifreDellaSala::class)->controlla($verbale->refresh());
    expect($esito['sala_cambiata'])->not->toBeNull()->and($esito['avvisi'])->not->toBe([]);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.aggiorna_sala', [$s->c, $a, $verbale]))->assertSessionHasNoErrors();
    $testo = StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento'));
    expect(app(CifreDellaSala::class)->controlla($verbale->refresh()))->toBe(['avvisi' => [], 'sala_cambiata' => null, 'a_mano' => []])
        ->and($testo)->toStartWith('Una frase scritta a mano.')->toContain('Favorevoli: 9 condòmini per 371,50 millesimi')
        ->and($verbale->versioni()->reorder()->orderByDesc('id')->value('perche'))->toBe('parti con i dati della sala aggiornate');
});

it('registrato il verbale, il voto della sua riunione non si cambia più in sala', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v[4], 'testa' => VerbaleDiViaRoma12::testa($v[4], 'Condomino 35'),
        'voto' => 'favorevole', 'perche' => 'Dopo.'], '2026-12-15 20:58', 1)])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Il verbale n. 42 è registrato: il voto non si cambia più.');
});
