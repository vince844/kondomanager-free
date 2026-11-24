<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Assemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Verbale/VerbaleDiViaRoma12.php';

/**
 * Il passo 4 «In sala» della strada dei passi: chiuso in preparazione; **dalla prima spedizione registrata si apre** con «Deleghe ricevute» e il foglio
 * della sala, anche con la convocazione non ancora completa, e «Apri la sala» resta spento fino al giorno della prima convocazione; dal giorno «Apri la
 * sala» si accende, e chi non risulta fra i convocati lo vede il presidente all'apertura (art. 1136 c. 6); durante «in corso» con i presenti e il punto;
 * dopo «chiusa» con le delibere, o «deserta». La convocazione è fatta dalla sala in poi.
 *
 * Che cosa non copre: la strada a video (i vitest della strada e della pagina dell'assemblea).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function pisPassi(Assemblea $a): array
{
    return collect(PerLePagine::passi($a->refresh()))->keyBy('chiave')->all();
}

it('in preparazione il passo è chiuso: si apre dalla prima spedizione', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $p = pisPassi(SalaDiViaRoma12::ordinaria($s));

    expect($p['sala'])->toMatchArray(['stato' => 'chiuso', 'riga' => 'si apre dalla prima spedizione', 'perche' => 'si apre dalla prima spedizione'])
        ->and(PerLePagine::apriLaSala(SalaDiViaRoma12::ordinaria($s)))->toMatchArray(['attiva' => false]);
});

it('dalla prima spedizione si apre, anche con la convocazione da finire: «Deleghe ricevute», il foglio della sala, «Apri la sala» spento fino al giorno', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['rossi']->id,
        'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-11-26'])->assertSessionHasNoErrors();
    $p = pisPassi($a);

    expect($p['convocazione']['stato'])->toBe('adesso')
        ->and($p['sala'])->toMatchArray(['stato' => 'adesso', 'riga' => '1 delega · dal 14/12', 'scopo' => 'prima dell\'assemblea'])
        ->and(array_column($p['sala']['manca'], 'chiave'))->toBe(['deleghe', 'foglio', 'apri'])
        ->and(array_column($p['sala']['manca'], 'informa'))->toBe([true, true, true])
        // «Vedi le deleghe» apre il cassetto delle deleghe nella pagina, non un punto della pagina.
        ->and($p['sala']['manca'][0])->toMatchArray(['titolo' => 'Deleghe ricevute', 'dettaglio' => '1 valida',
            'collegamento' => ['testo' => 'Vedi le deleghe', 'url' => null, 'passo' => null, 'apre' => 'deleghe']])
        ->and($p['sala']['manca'][1])->toMatchArray(['titolo' => 'Il foglio della sala', 'collegamento' => ['testo' => 'Stampa il foglio',
            'url' => route('admin.gestionale.assemblee.sala.foglio', [$s->c->id, $a->id]), 'passo' => null, 'stampa' => true]])
        // «Apri la sala» prima del giorno: il pulsante c'è, spento, con il perché.
        ->and($p['sala']['manca'][2])->toMatchArray(['titolo' => 'Apri la sala', 'dettaglio' => 'si accende il 14/12, il giorno della prima convocazione',
            'collegamento' => ['testo' => 'Apri la sala', 'url' => null, 'passo' => null, 'spento' => 'La sala si apre il giorno della prima convocazione, il 14/12/2026.']])
        ->and(json_encode($p['sala']['manca']))->not->toContain('#deleghe-ricevute')
        ->and(PerLePagine::apriLaSala($a))->toMatchArray(['attiva' => false, 'perche' => 'La sala si apre il giorno della prima convocazione, il 14/12/2026.']);
});

it('senza deleghe la card dice «nessuna, per ora», e il pulsante apre subito «Segna una delega ricevuta»', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    $p = pisPassi($a);

    expect($p['sala']['manca'][0])->toMatchArray(['chiave' => 'deleghe', 'informa' => true, 'dettaglio' => 'nessuna, per ora',
        'collegamento' => ['testo' => 'Segna una delega ricevuta', 'url' => null, 'passo' => null, 'apre' => 'segna_delega']]);
});

it('dal giorno della prima «Apri la sala» si accende; chi non risulta fra i convocati lo vede il presidente all\'apertura', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 07:30');
    $p = pisPassi($a);

    expect($p['sala'])->toMatchArray(['stato' => 'adesso', 'riga' => 'nessuna delega · si apre oggi'])
        ->and(collect($p['sala']['manca'])->firstWhere('chiave', 'apri'))->toMatchArray(['fatto' => false, 'informa' => false,
            'collegamento' => ['testo' => 'Apri la sala', 'url' => route('admin.gestionale.assemblee.sala', [$s->c, $a]), 'passo' => null]])
        ->and(collect($p['sala']['manca'])->firstWhere('chiave', 'convocazione'))->toMatchArray(['informa' => true,
            'dettaglio' => '1 da spedire: all\'apertura il presidente vede chi non risulta fra i convocati (art. 1136 c. 6 c.c.) e decide'])
        ->and(PerLePagine::apriLaSala($a))->toMatchArray(['attiva' => true, 'url' => route('admin.gestionale.assemblee.sala', [$s->c, $a])]);
});

it('in corso: i presenti e il punto; la convocazione è fatta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:02', 2));
    $p = pisPassi($a);

    // Con la sala aperta il verbale si scrive già: la discussione sotto il voto di ogni punto (il passo 5 è aperto insieme alla sala).
    expect(array_map(fn ($x) => $x['stato'], $p))->toBe(['date' => 'fatto', 'documenti' => 'fatto', 'convocazione' => 'fatto', 'sala' => 'adesso', 'verbale' => 'adesso',
        'delibere' => 'chiuso'])
        ->and($p['sala']['riga'])->toBe('in corso · 11 su 26 presenti · punto 2 di 6')
        ->and(PerLePagine::apriLaSala($a))->toMatchArray(['attiva' => true, 'testo' => 'Torna in sala']);
});

it('chiusa: fatto, con le delibere; deserta: fatto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 20:40'));
    $p = pisPassi($a);
    expect($p['sala'])->toMatchArray(['stato' => 'fatto', 'riga' => 'chiusa alle 20:40 · 6 punti rinviati']);

    $s2 = SalaDiViaRoma12::ordinaria($s);
    $b = SalaDiViaRoma12::convoca($s2, $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:00');
    SalaDiViaRoma12::applica($this, $s, $b, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($b, 'prima')->id], '2026-12-14 09:00'));
    SalaDiViaRoma12::applica($this, $s, $b, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($b)->id], '2026-12-15 19:30'));
    expect(pisPassi($b)['sala'])->toMatchArray(['stato' => 'fatto', 'riga' => 'deserta in prima e in seconda convocazione']);
});

it('chiusa: le delibere, i punti senza voto e i rinviati si dicono separati, sia nel passo sia nella riga del registro (1.12.0-beta.14, C57)', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this, true);

    // Cinque punti votati e uno trattato senza voto: non sono «6 delibere».
    expect(pisPassi($a)['sala']['riga'])->toBe('chiusa alle 20:55 · 5 delibere, 1 senza voto');
    $riga = \App\Models\RegistroModifica::query()->where('oggetto_tipo', 'assemblea')->where('riepilogo', 'like', '%chiusa alle%')->latest('id')->first();
    expect($riga->riepilogo)->toContain('chiusa alle 20:55: 5 delibere, 1 senza voto')->not->toContain('6 delibere');
});

it('la riga del passo è corta e non si sovrappone: le deleghe, le rifiutate, il giorno della sala', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    foreach (['c30', 'c31'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-11-26'])->assertSessionHasNoErrors();
    }
    // Una delega all'amministratore non si registra; una oltre il quinto sì, rifiutata: la riga la conta.
    \App\Models\Assemblee\DelegaDellAssemblea::query()->where('delegante_anagrafica_id', $s->persone['c31']->id)->firstOrFail()
        ->forceFill(['stato' => 'rifiutata', 'motivo' => 'Oltre un quinto del valore (art. 67 c. 1 disp. att.).'])->saveQuietly();

    $riga = pisPassi($a)['sala']['riga'];
    expect($riga)->toBe('1 delega · 1 rifiutata · dal 14/12')
        ->and(mb_strlen($riga))->toBeLessThanOrEqual(40);
});

it('la prima deserta: la riga dice la seconda; sospesa: «sospesa · prosegue …» con il giorno della settimana', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-14 08:40'));
    expect(pisPassi($a)['sala']['riga'])->toBe('prima deserta · la seconda martedì 15/12 alle 18:30');

    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id,
        'prosegue_seduta_id' => SalaDiViaRoma12::seduta($a, 'seconda', 2)->id], '2026-12-15 21:30'));
    $p = pisPassi($a);
    expect($p['sala'])->toMatchArray(['stato' => 'adesso', 'riga' => 'sospesa · prosegue giovedì 17/12 alle 18:30']);
});

it('a sala chiusa il passo ha tre card: «La sala» (fatta, com\'è finita), «Le deleghe» (da vedere), «Le presenze e i voti»', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    \App\Models\Assemblee\DelegaDellAssemblea::query()->create(['assemblea_id' => $a->id, 'delegante_anagrafica_id' => $s->persone['c30']->id, 'delegante_nome' => 'Condomino',
        'unita' => [], 'delegato_nome' => 'Bianchi Giorgio', 'arrivata_come' => 'carta_prima', 'ricevuta_il' => '2026-12-10', 'stato' => 'valida']);
    $p = pisPassi($a)['sala'];

    expect(array_column($p['manca'], 'chiave'))->toBe(['sala', 'deleghe', 'prospetto'])
        ->and(array_column($p['manca'], 'informa'))->toBe([false, true, true])
        ->and($p['manca'][0])->toMatchArray(['fatto' => true, 'titolo' => 'La sala',
            'collegamento' => ['testo' => 'Apri la sala', 'url' => route('admin.gestionale.assemblee.sala', [$s->c, $a]), 'passo' => null]])
        ->and($p['manca'][0]['dettaglio'])->toStartWith('Chiusa alle 20:55 · ')->toContain(' convocazione con ')->toMatch('/ con \d+ su 26$/')
        ->and($p['manca'][1])->toMatchArray(['titolo' => 'Le deleghe', 'dettaglio' => '1 valida',
            'collegamento' => ['testo' => 'Vedi le deleghe', 'url' => null, 'passo' => null, 'apre' => 'deleghe']])
        ->and($p['manca'][2]['collegamento']['testo'])->toBe('Stampa il prospetto');
});

it('a sala chiusa senza deleghe «Le deleghe» dice «Nessuna valida»; con una revocata la conta', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    expect(pisPassi($a)['sala']['manca'][1]['dettaglio'])->toBe('Nessuna valida');

    \App\Models\Assemblee\DelegaDellAssemblea::query()->create(['assemblea_id' => $a->id, 'delegante_anagrafica_id' => $s->persone['c30']->id, 'delegante_nome' => 'Condomino',
        'unita' => [], 'delegato_nome' => 'Bianchi Giorgio', 'arrivata_come' => 'carta_prima', 'ricevuta_il' => '2026-12-10', 'stato' => 'valida']);
    \App\Models\Assemblee\DelegaDellAssemblea::query()->create(['assemblea_id' => $a->id, 'delegante_anagrafica_id' => $s->persone['c31']->id, 'delegante_nome' => 'Condomino',
        'unita' => [], 'delegato_nome' => 'Bianchi Giorgio', 'arrivata_come' => 'carta_prima', 'ricevuta_il' => '2026-12-10', 'stato' => 'revocata']);
    expect(pisPassi($a)['sala']['manca'][1]['dettaglio'])->toBe('1 valida · 1 revocata');
});
