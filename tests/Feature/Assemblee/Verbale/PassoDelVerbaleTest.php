<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il passo 5 «Verbale» della strada dell'assemblea (V14), in ogni stato: chiuso prima della sala e per l'assemblea annullata senza riunione; con la sala in
 * corso «la discussione: N punti scritti»; dopo la sala «da scrivere · entro il 22/12»; la bozza, la bozza stampata, il verbale registrato e la trasmissione
 * che manca; fatto quando ogni destinatario ha il verbale spedito; per l'assemblea deserta le annotazioni nel registro; per quella rinviata dalla sala dopo
 * l'apertura il verbale della riunione che c'è stata.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function pdvPasso(Assemblea $a): array
{
    return collect(PerLePagine::passi($a->refresh()))->keyBy('chiave')->all()['verbale'];
}

it('chiuso prima della sala', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    expect(pdvPasso($a))->toMatchArray(['stato' => 'chiuso', 'riga' => 'dopo la sala', 'manca' => []]);
});

it('con la sala in corso conta i punti scritti; dopo la sala «da scrivere» con la data dei sette giorni', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    expect(pdvPasso($a))->toMatchArray(['stato' => 'adesso', 'riga' => 'la discussione: 0 punti scritti']);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'punto:' . VerbaleDiViaRoma12::punto($a, 4),
        StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Il preventivo della ditta Edil.')]), '2026-12-15 19:50', 0)]);
    expect(pdvPasso($a)['riga'])->toBe('la discussione: 1 punto scritto');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $p = pdvPasso($a);
    expect($p)->toMatchArray(['stato' => 'adesso', 'riga' => 'da scrivere · entro il 22/12', 'scopo' => 'per registrare il verbale'])
        ->and(array_column($p['manca'], 'chiave'))->toBe(['verbale', 'copia', 'trasmissione'])
        ->and($p['manca'][0]['collegamento']['url'])->toBe(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]));
});

it('la bozza, la bozza stampata, il registrato da trasmettere, e fatto quando ognuno ha il verbale spedito', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    expect(pdvPasso($a))->toMatchArray(['stato' => 'adesso', 'riga' => 'bozza alle 21:00 · versione 1'])
        ->and(array_column(pdvPasso($a)['manca'], 'chiave'))->toBe(['bozza']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]))->assertOk();
    expect(pdvPasso($a))->toMatchArray(['riga' => 'da firmare · bozza stampata alle 21:00'])->and(array_column(pdvPasso($a)['manca'], 'chiave'))->toBe(['copia']);

    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $p = pdvPasso($a);
    expect($p)->toMatchArray(['stato' => 'adesso', 'riga' => 'n. 42 · trasmesso a 0 di 18', 'scopo' => 'per trasmettere il verbale'])
        ->and(array_column($p['manca'], 'chiave'))->toBe(['verbale', 'trasmissione', 'libro'])
        ->and($p['manca'][0])->toMatchArray(['fatto' => true, 'informa' => false, 'titolo' => 'Il verbale', 'dettaglio' => 'Registrato · n. 42, sottoscritto il 15/12/2026',
            'collegamento' => ['testo' => 'Apri il verbale', 'url' => route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]), 'passo' => null]])
        ->and($p['manca'][1]['dettaglio'])->not->toBe('')
        // Vincenzo, 21/11/2026 sera: «Trasmetti il verbale» non era un gesto della pagina. Il pulsante ha il nome del prossimo gesto.
        ->and($p['manca'][1]['collegamento']['testo'])->toBeIn(['Stampa le lettere', 'Manda le PEC', 'Registra la spedizione']);

    // Le lettere stampate e non ancora spedite: la card lo dice, e il gesto è «Registra la spedizione».
    foreach (app(DestinatariDelVerbale::class)->per($verbale->refresh())['persone'] as $persona) {
        if ($persona['riceve']) {
            RegistroInvio::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $persona['anagrafica_id'], 'tipo' => 'verbale', 'canale' => 'stampa',
                'documentabile_type' => $a->getMorphClass(), 'documentabile_id' => $a->id, 'dati' => ['mezzo' => 'raccomandata', 'verbale_id' => $verbale->id],
                'stato' => 'stampata', 'emesso_il' => now()]);
        }
    }
    $p = pdvPasso($a);
    expect($p['manca'][1])->toMatchArray(['chiave' => 'trasmissione', 'fatto' => false, 'dettaglio' => '18 stampate, da spedire'])
        ->and($p['manca'][1]['collegamento']['testo'])->toBe('Registra la spedizione');
    RegistroInvio::query()->where('documentabile_id', $a->id)->where('tipo', 'verbale')->delete();

    foreach (app(DestinatariDelVerbale::class)->per($verbale->refresh())['persone'] as $persona) {
        if ($persona['riceve']) {
            RegistroInvio::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $persona['anagrafica_id'], 'tipo' => 'verbale', 'canale' => 'stampa',
                'documentabile_type' => $a->getMorphClass(), 'documentabile_id' => $a->id, 'dati' => ['mezzo' => 'raccomandata', 'verbale_id' => $verbale->id],
                'stato' => 'spedita', 'emesso_il' => now()]);
        }
    }
    expect(pdvPasso($a))->toMatchArray(['stato' => 'fatto', 'riga' => 'n. 42 · trasmesso a tutti: i trenta giorni aspettano le consegne'])
        ->and(array_column(pdvPasso($a)['manca'], 'chiave'))->toBe(['verbale', 'trasmissione', 'libro']);
    // A trasmissione finita la card «da spedire» è quella fatta: la trasmissione resta, con il numero, con chi e l'ultimo giorno, e «Vedi chi» porta alla sezione.
    $trasmissione = pdvPasso($a)['manca'][1];
    expect($trasmissione)->toMatchArray(['chiave' => 'trasmissione', 'fatto' => true, 'informa' => false, 'titolo' => 'La trasmissione',
        'dettaglio' => 'Trasmesso a 18 di 18 · l\'ultimo il 15/12/2026',
        'collegamento' => ['testo' => 'Vedi chi', 'url' => route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]) . '#trasmissione', 'passo' => null]]);

    // Decisione 654 (Vincenzo, 21/11/2026 sera, sull'assemblea n. 8): le card restano tre anche con la pagina incollata nel libro; quella del libro è fatta.
    $verbale->refresh()->forceFill(['nel_libro_il' => '2026-12-16'])->save();
    $p = pdvPasso($a);
    expect(array_column($p['manca'], 'chiave'))->toBe(['verbale', 'trasmissione', 'libro'])
        ->and($p['manca'][2])->toMatchArray(['chiave' => 'libro', 'fatto' => true, 'titolo' => 'La pagina per il libro dei verbali di carta',
            'dettaglio' => 'Incollata nel libro il 16/12/2026'])
        ->and($p['manca'][2]['collegamento']['testo'])->toBe('Il registro dei verbali');
});

it('l\'assemblea deserta: le annotazioni nel registro, fatto; l\'annullata senza riunione è chiusa; la rinviata dalla sala dopo l\'apertura ha il suo verbale', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-15 18:40'));
    ConvocazioneDiViaRoma12::oggi('2026-12-15 19:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id], '2026-12-15 19:40'));
    $p = pdvPasso($a);
    expect($p)->toMatchArray(['stato' => 'fatto', 'riga' => 'annotata nel registro: 2 mancate costituzioni'])
        ->and($p['manca'][0])->toMatchArray(['chiave' => 'libro', 'dettaglio' => '2 da incollare']);

    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $b->forceFill(['stato' => \App\Enums\Assemblee\StatoAssemblea::ANNULLATA->value])->saveQuietly();
    expect(pdvPasso($b))->toMatchArray(['stato' => 'chiuso', 'riga' => 'l\'assemblea è annullata']);
});

it('la rinviata dalla sala dopo l\'apertura: la riunione c\'è stata, il verbale si scrive', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('rinvia', ['motivo' => 'La sala è inagibile per un allagamento', 'sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2027-01-11 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2027-01-12 18:30'],
    ]], '2026-12-15 18:50'));
    expect($a->refresh()->stato)->toBe(\App\Enums\Assemblee\StatoAssemblea::ANNULLATA)
        ->and(pdvPasso($a))->toMatchArray(['stato' => 'adesso', 'riga' => 'da scrivere · entro il 22/12']);
});
