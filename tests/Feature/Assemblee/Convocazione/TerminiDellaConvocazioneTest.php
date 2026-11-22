<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §4.5 del progetto della tappa 2; decisione 565; legge §1 e §2): l'ultimo giorno di ricezione (cinque giorni di calendario prima
 * della prima convocazione, venti con la destinazione d'uso); la proposta prudente per le raccomandate (giovedì 03/12 per il 14/12, con l'8/12 festa: non
 * contano il giorno della spedizione e il sabato); il lunedì dell'Angelo; la data scelta dall'amministratore con «Come conto» arancio se è più tardi, in tempo se è
 * prima; la riga del passo «Convocazione» e il compito che la seguono; «Come conto» con la settimana e se arriva in tempo.
 *
 * Che cosa non copre: la festa del patrono e il 4 ottobre (non sono nel calendario, §12 del progetto); la pagina (vitest della scheda).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('per la prima convocazione di lunedì 14/12 l\'ultimo giorno è il 09/12 e la proposta giovedì 03/12; spedita il 04/12 arriverebbe il 10/12', function () {
    ConvocazioneDiViaRoma12::oggi();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $t = app(TerminiDellaConvocazione::class);

    expect($t->ultimoGiorno($a)->format('Y-m-d'))->toBe('2026-12-09')
        ->and($t->proposta($a)->format('Y-m-d'))->toBe('2026-12-03')
        ->and($t->arrivoAtteso('2026-12-03')->format('Y-m-d'))->toBe('2026-12-09')
        ->and($t->arrivoAtteso('2026-12-04')->format('Y-m-d'))->toBe('2026-12-10')
        ->and(\App\Http\Controllers\Assemblee\PerLePagine::passi($a)[2]['riga'])->toBe('9 da spedire · entro il 03/12 · 1 senza un mezzo valido');
});

it('il lunedì dell\'Angelo non conta: per la prima convocazione di lunedì 13/04/2026 la proposta è giovedì 02/04', function () {
    ConvocazioneDiViaRoma12::oggi('2026-03-02 10:00');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->where('convocazione', 'prima')->update(['fissata_il' => '2026-04-13 08:00:00']);
    SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->where('convocazione', 'seconda')->update(['fissata_il' => '2026-04-14 18:30:00']);
    $t = app(TerminiDellaConvocazione::class);

    // Ultimo giorno mercoledì 08/04; lunedì 06/04 è il lunedì dell'Angelo. Dal 02/04: 03/04, 07/04, 08/04, in tempo. Dal 03/04: 07/04, 08/04, 09/04, tardi.
    expect($t->ultimoGiorno($a)->format('Y-m-d'))->toBe('2026-04-08')
        ->and($t->arrivoAtteso('2026-04-03')->format('Y-m-d'))->toBe('2026-04-09')
        ->and($t->proposta($a)->format('Y-m-d'))->toBe('2026-04-02');
});

it('la data scelta più tardi della proposta: «Come conto» arancio con le parole della bozza, «Torna alla proposta», la riga del passo e il compito che la seguono; prima, in tempo', function () {
    ConvocazioneDiViaRoma12::oggi();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $t = app(TerminiDellaConvocazione::class);
    $t->scegli($a, '2026-12-04', $s->admin);
    $scheda = $t->perLaScheda($a->refresh(), ['raccomandata' => 5, 'pec' => 11, 'a_mano' => 10], true);

    expect($scheda['raccomandate'])->toMatchArray(['entro' => '2026-12-04', 'proposta' => '2026-12-03', 'scelta_da_te' => true,
        'frase' => 'Spedisci le raccomandate entro venerdì 04/12', 'torna' => 'Torna alla proposta (gio 03/12)'])
        ->and($scheda['conto']['titolo'])->toBe('Come conto venerdì 04/12')
        ->and($scheda['conto']['esito'])->toBe(['in_tempo' => false, 'titolo' => 'Arriva tardi', 'frase' => ': spedita venerdì 04/12 arriverebbe giovedì 10/12, '
            . 'un giorno dopo il termine del 09/12. Per chi la riceve dopo il 09/12 i cinque giorni non ci sono, e la delibera si può impugnare. Se tieni il 04/12, decidi tu.'])
        ->and($scheda['altri'])->toBe(['etichetta' => 'PEC (11) e consegne a mano (10)', 'entro_parole' => 'mercoledì 09/12'])
        ->and(\App\Http\Controllers\Assemblee\PerLePagine::passi($a)[2]['riga'])->toBe('9 da spedire · entro il 04/12 · 1 senza un mezzo valido');
    $compito = \App\Models\Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->where('is_completed', false)->first();
    expect($compito->title)->toBe('Spedire la convocazione entro il 04/12');

    $t->scegli($a, '2026-12-02', $s->admin);
    expect($t->perLaScheda($a->refresh(), ['raccomandata' => 5], true)['conto']['esito'])
        ->toBe(['in_tempo' => true, 'titolo' => 'Arriva in tempo', 'frase' => ', con 2 giorni di margine sul 09/12.']);
    $t->scegli($a, null, $s->admin);
    expect($a->refresh()->raccomandate_entro_il)->toBeNull()
        ->and($compito->refresh()->title)->toBe('Spedire la convocazione entro il 03/12');
});

it('«Come conto»: la settimana dalla spedizione all\'arrivo, con l\'Immacolata, e spedita il 04/12 arriverebbe un giorno dopo', function () {
    ConvocazioneDiViaRoma12::oggi();
    $t = app(TerminiDellaConvocazione::class);
    $conto = $t->conto(CarbonImmutable::parse('2026-12-03'), CarbonImmutable::parse('2026-12-09'), CarbonImmutable::parse('2026-12-14 08:00'));

    expect(array_map(fn ($g) => $g['giorno'] . ' · ' . $g['nota'], $conto['giorni']))->toBe([
        'gio 03 · spedisci · non conta', 'ven 04 · 1° giorno di posta', 'sab 05 · non conta', 'dom 06 · non conta', 'lun 07 · 2° giorno di posta',
        'mar 08 · festa · non conta', 'mer 09 · 3° · arriva in tempo',
    ])->and($conto['titolo'])->toBe('Come conto giovedì 03/12')
        ->and($conto['sotto'])->toBe('Non contano il giorno della spedizione, il sabato, la domenica e martedì 08/12, l\'Immacolata. Poi lunedì 14/12 la prima convocazione.')
        ->and($conto['esito'])->toBe(['in_tempo' => true, 'titolo' => 'Arriva in tempo',
            'frase' => ', l\'ultimo giorno utile. Spedita venerdì 04/12 arriverebbe giovedì 10/12: un giorno dopo.']);
    // Senza feste nella settimana, la riga delle feste non c'è.
    expect($t->conto(CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-17'), CarbonImmutable::parse('2026-11-22 10:00'))['sotto'])->toBeNull();
});

it('con un punto sulla destinazione d\'uso i giorni sono venti, e la scheda dice che cosa manca', function () {
    ConvocazioneDiViaRoma12::oggi('2026-11-10 10:00');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Il cortile diventa parcheggio', 'materia' => 'destinazione_uso']]);
    $t = app(TerminiDellaConvocazione::class);

    expect($t->giorniPrima($a))->toBe(20)
        ->and($t->ultimoGiorno($a)->format('Y-m-d'))->toBe('2026-11-24')
        ->and($t->perLaScheda($a, [], true)['destinazione_uso']['mancano'])->toBe([
            'le date dell\'affissione (almeno trenta giorni consecutivi)', 'nel punto 1 le parti comuni che cambiano', 'nel punto 1 la nuova destinazione d\'uso']);
});
