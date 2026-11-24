<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\AssembleeDellaPersona;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\TerminiDellImpugnazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/../Seguito/SeguitoDiViaRoma12.php';

/**
 * Il rendiconto e il riparto votati a parte (due «Si vota» del punto 1, `numero` 1 e 2: Coda 750 B2). Il voto di una persona si legge **per votazione**,
 * cioè per delibera, mai per numero del punto: prima l'ultima votazione copriva la prima.
 *
 * La sala del 15/12/2026 di Via Roma 12: alle 18:45 entrano Rossi, Russo Marco, Colombo Rita, Gallo e i condòmini 30–36. Alle 19:00 il rendiconto, tutti
 * favorevoli e Colombo Rita contraria; alle 19:02 esce il condomino 36, alle 19:05 il riparto, tutti favorevoli (Colombo Rita compresa), alle 19:07 il
 * condomino 36 rientra. Alle 19:28 entra Bianchi Anna, dopo le due votazioni e il punto 2. Poi i punti 2–5 tutti favorevoli, le varie senza voto, la chiusura, il verbale
 * n. 42 registrato.
 *
 * - Colombo Rita: la scheda dice «Punto 1, il rendiconto · Contrario», favorevole al riparto del punto 1, e «Può impugnare fino al 14/01/2027» (prima: nessun
 *   termine); i suoi termini sono per la sola delibera del rendiconto.
 * - Il condomino 36, fuori al riparto e non al rendiconto: riceve il verbale per il riparto, e i suoi trenta giorni corrono dalla consegna per quella delibera
 *   sola.
 * - Bianchi Anna, fuori alle due votazioni: «assente ai punti 1 e 2», mai «1 e 1».
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** «Si vota» del punto con la votazione e l'oggetto dati, tutti favorevoli, i voti diversi, la conferma. @param array<string, string> $diversi */
function dvpVota($t, Beta51Scenario $s, Assemblea $a, int $numero, int $votazione, ?string $oggetto, string $alle, array $diversi = []): int
{
    $seduta = SalaDiViaRoma12::seduta($a);
    $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', array_filter(['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, $numero),
        'numero' => $votazione, 'oggetto' => $oggetto]), $alle));
    $id = (int) $r['votazione_id'];
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], $alle));
    foreach ($diversi as $chi => $voto) {
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $id, 'testa' => VerbaleDiViaRoma12::testa($id, $s->persone[$chi]->nome),
            'voto' => $voto], $alle, 1));
    }
    VerbaleDiViaRoma12::conferma($t, $s, $a, $id, $alle);

    return $id;
}

/** La sala chiusa e il verbale n. 42 registrato. @return array{0: Beta51Scenario, 1: Assemblea, 2: \App\Models\Assemblee\VerbaleDellAssemblea, 3: array<string, int>} */
function dvpRegistrato($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
    $v = [];
    $v['rendiconto'] = dvpVota($t, $s, $a, 1, 1, 'rendiconto', '2026-12-15 19:00', ['rita' => 'contrario']);
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['c36']->id]],
        '2026-12-15 19:02', 1));
    $v['riparto'] = dvpVota($t, $s, $a, 1, 2, 'riparto', '2026-12-15 19:05');
    SalaDiViaRoma12::entrano($t, $s, $a, ['c36'], '2026-12-15 19:07');
    SalaDiViaRoma12::entrano($t, $s, $a, ['bianchi'], '2026-12-15 19:28');
    foreach ([2 => '19:25', 3 => '19:30', 4 => '19:40', 5 => '20:05'] as $numero => $ora) {
        $v[(string) $numero] = dvpVota($t, $s, $a, $numero, 1, null, '2026-12-15 ' . $ora);
    }
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 6)], '2026-12-15 20:30'));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a->refresh());
    VerbaleDiViaRoma12::registra($t, $s, $verbale)->assertSessionHasNoErrors();

    return [$s, $a->refresh(), $verbale->refresh(), $v];
}

function dvpPersona(array $d, string $nome): array
{
    return collect($d['persone'])->firstWhere('nome', $nome) ?? throw new \RuntimeException('Nessuno: ' . $nome);
}

function dvpDelibera(int $votazione): int
{
    return (int) VotazioneDelPunto::query()->findOrFail($votazione)->delibera_id;
}

it('la scheda della persona: contraria al rendiconto e favorevole al riparto dello stesso punto, con il termine per impugnare il rendiconto', function () {
    [$s, $a] = dvpRegistrato($this);

    $rita = collect(AssembleeDellaPersona::perLaScheda($s->persone['rita'], $s->admin))->firstWhere('id', $a->id);
    expect(collect($rita['altri_voti'])->map(fn ($v) => [$v['punto'], $v['oggetto'], $v['voto']])->all())->toBe([[1, 'rendiconto', 'Contrario']])
        ->and($rita['favorevole_ai_punti'])->toBe([2, 3, 4])
        ->and($rita['favorevole_alle_parti'])->toBe([['punto' => 1, 'oggetto' => 'riparto']])
        // Trenta giorni dal 15/12/2026, la lettura prudente: il 14/01/2027.
        ->and($rita['puo_impugnare_fino_al'])->toBe('14/01/2027');
});

it('i termini per delibera: Colombo Rita per il solo rendiconto; il condomino 36 fuori al solo riparto riceve il verbale per quella delibera', function () {
    [$s, $a, $verbale, $v] = dvpRegistrato($this);
    $d = app(DestinatariDelVerbale::class)->per($verbale);

    // I punti del verbale: una riga per votazione, con la votazione, la delibera e l'oggetto.
    expect(collect($d['punti'])->where('numero', 1)->map(fn ($p) => [$p['votazione'], $p['oggetto'], $p['delibera_id']])->values()->all())
        ->toBe([[1, 'rendiconto', dvpDelibera($v['rendiconto'])], [2, 'riparto', dvpDelibera($v['riparto'])]]);

    $rita = dvpPersona($d, 'Colombo Rita');
    expect($rita)->toMatchArray(['gruppo' => 'cera', 'riceve' => false])
        ->and($rita['termini'])->toHaveCount(1)
        ->and($rita['termini'][0])->toMatchArray(['punti' => [1], 'perche' => 'contrario', 'fino_al' => '2027-01-14', 'delibere' => [dvpDelibera($v['rendiconto'])]]);

    $c36 = dvpPersona($d, 'Condomino 36');
    expect($c36)->toMatchArray(['gruppo' => 'fuori', 'riceve' => true, 'punti_assente' => [1], 'perche' => 'assente al riparto del punto 1'])
        ->and($c36['termini'])->toHaveCount(1)
        ->and($c36['termini'][0])->toMatchArray(['punti' => [1], 'perche' => 'assente', 'da_consegnare' => true, 'delibere' => [dvpDelibera($v['riparto'])]]);

    $termini = collect(app(TerminiDellImpugnazione::class)->perIlVerbale($verbale))->keyBy('delibera_id');
    $rendiconto = $termini[dvpDelibera($v['rendiconto'])];
    $riparto = $termini[dvpDelibera($v['riparto'])];
    expect([$rendiconto['punto'], $rendiconto['oggetto'], $riparto['punto'], $riparto['oggetto']])->toBe([1, 'rendiconto', 1, 'riparto'])
        ->and(collect($rendiconto['chi'])->pluck('nome')->all())->toContain('Colombo Rita')->not->toContain('Condomino 36')
        ->and(collect($riparto['chi'])->pluck('nome')->all())->toContain('Condomino 36')->not->toContain('Colombo Rita');
});

it('chi era fuori alle due votazioni del punto: «assente ai punti 1 e 2», e nessun elenco di punti con un numero ripetuto', function () {
    [, , $verbale] = dvpRegistrato($this);
    $d = app(DestinatariDelVerbale::class)->per($verbale);

    foreach ($d['persone'] as $p) {
        expect($p['punti_assente'])->toBe(array_values(array_unique($p['punti_assente'])), $p['nome']);
        foreach ($p['termini'] as $t) {
            expect($t['punti'])->toBe(array_values(array_unique($t['punti'])), $p['nome']);
        }
    }
    expect(dvpPersona($d, 'Bianchi Anna'))->toMatchArray(['gruppo' => 'fuori', 'punti_assente' => [1, 2], 'perche' => 'in sala dalle 19:28: assente ai punti 1 e 2'])
        ->and(dvpPersona($d, 'Bianchi Anna')['termini'][0])->toMatchArray(['punti' => [1, 2], 'perche' => 'assente']);
});

it('la catena dalle rotte: i due voti in sala → il verbale registrato e consegnato a tutti → la scheda, i termini, la tabella del passo 6 e «Trenta giorni»', function () {
    [$s, $a, $verbale, $v] = dvpRegistrato($this);
    // Le lettere a chi riceve il verbale, stampate, spedite il 15/12 e consegnate il 18/12, dalle rotte della trasmissione e degli invii.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]))->assertOk();
    $invii = \App\Models\Gestionale\RegistroInvio::query()->where('tipo', 'verbale')->pluck('id')->all();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => $invii, 'canale' => 'raccomandata',
        'data' => '2026-12-15'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    foreach (\App\Models\Gestionale\RegistroInvio::query()->whereIn('id', $invii)->get() as $riga) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $riga]), ['esito' => 'consegnata', 'giorno' => '2026-12-18'])
            ->assertSessionHasNoErrors();
    }
    $d = app(DestinatariDelVerbale::class)->per($verbale->refresh());
    expect($d['consegnati'])->toBe($d['quanti'])
        ->and(dvpPersona($d, 'Condomino 36')['termini'][0])->toMatchArray(['perche' => 'assente', 'dal' => '2026-12-18', 'fino_al' => '2027-01-18']);

    // La scheda di Colombo Rita: il rendiconto contrario, e il suo termine dalla delibera.
    $rita = collect(AssembleeDellaPersona::perLaScheda($s->persone['rita'], $s->admin))->firstWhere('id', $a->id);
    expect($rita['altri_voti'][0])->toMatchArray(['punto' => 1, 'oggetto' => 'rendiconto', 'voto' => 'Contrario'])->and($rita['puo_impugnare_fino_al'])->toBe('14/01/2027');

    // I termini per delibera, e la tabella del passo 6 con le due righe del punto 1.
    $termini = collect(app(TerminiDellImpugnazione::class)->perIlVerbale($verbale))->keyBy('delibera_id');
    expect(collect($termini[dvpDelibera($v['rendiconto'])]['chi'])->firstWhere('nome', 'Colombo Rita'))->toMatchArray(['perche' => 'contrario', 'fino_al' => '2027-01-14'])
        ->and(collect($termini[dvpDelibera($v['riparto'])]['chi'])->firstWhere('nome', 'Condomino 36'))->toMatchArray(['perche' => 'assente', 'fino_al' => '2027-01-18']);
    $pagina = \App\Services\Assemblee\Seguito\SeguitoDelleDelibere::perLaPagina($a->refresh(), $s->admin);
    $punto1 = collect($pagina['tabella'])->where('punto', 1)->values();
    expect($punto1->map(fn ($r) => [$r['oggetto'], $r['trenta_giorni']['per_tutti']])->all())->toBe([['rendiconto', '2027-01-18'], ['riparto', '2027-01-18']])
        ->and(collect($punto1[0]['chi_puo_impugnare'])->pluck('nome')->all())->toContain('Colombo Rita')->not->toContain('Condomino 36')
        ->and(collect($punto1[1]['chi_puo_impugnare'])->pluck('nome')->all())->toContain('Condomino 36')->not->toContain('Colombo Rita')
        ->and($pagina['trenta_giorni'])->toMatchArray(['per_tutti' => '2027-01-18', 'frase' => 'Passati per tutti dal 19/01/2027. Resta la nullità.']);
});

// --- Il giro di correzioni della tappa 3: la lettera e il registro dicono la delibera, il punto non si ripete ---------------------------------------

it('la lettera a chi era fuori al solo riparto del punto 1 dice quale delibera, e il termine è per quella sola', function () {
    [$s, , $verbale] = dvpRegistrato($this);
    $d = app(DestinatariDelVerbale::class)->per($verbale);
    $lettera = (new ReflectionMethod(\App\Services\Assemblee\Verbale\TrasmissioneDelVerbale::class, 'perLaLettera'))
        ->invoke(app(\App\Services\Assemblee\Verbale\TrasmissioneDelVerbale::class), dvpPersona($d, 'Condomino 36'));

    expect($lettera['righe'][0])->toContain('al riparto del punto 1')
        ->and($lettera['righe'][0])->toContain('quella delibera')
        ->and($lettera['righe'][0])->not->toContain('quei punti');
    // Fuori a tutte e due le votazioni del punto 1 e al punto 2: «ai punti 1 e 2», le delibere al plurale.
    $bianchi = (new ReflectionMethod(\App\Services\Assemblee\Verbale\TrasmissioneDelVerbale::class, 'perLaLettera'))
        ->invoke(app(\App\Services\Assemblee\Verbale\TrasmissioneDelVerbale::class), dvpPersona($d, 'Bianchi Anna'));
    expect($bianchi['righe'][0])->toContain('ai punti 1 e 2')->and($bianchi['righe'][0])->toContain('quelle delibere');
});

it('«Aggiungi una persona» non ripete il punto 1: il registro e i punti salvati hanno i numeri una volta, e la riga è senza genere', function () {
    [$s, , $verbale] = dvpRegistrato($this);
    $trasmissione = app(\App\Services\Assemblee\Verbale\TrasmissioneDelVerbale::class);
    $prima = ViaRoma12::ultimaRiga();
    $trasmissione->aggiungi($verbale, (int) $s->persone['rossi']->id, null, 'Prova', $s->admin);

    $riga = ViaRoma12::righe($prima)->last()->riepilogo;
    expect($riga)->not->toContain('1, 1')->and($riga)->toContain('aggiunta a mano: ')->and($riga)->not->toContain('aggiunto ')
        ->and($verbale->refresh()->trasmissione['aggiunti'][(int) $s->persone['rossi']->id]['punti'])->toBe([1, 2, 3, 4, 5]);

    $prima = ViaRoma12::ultimaRiga();
    $trasmissione->togli($verbale->refresh(), (int) $s->persone['rossi']->id, 'Prova', $s->admin);
    $riga = ViaRoma12::righe($prima)->last()->riepilogo;
    expect($riga)->toContain('tolta dalla lista: ')->and($riga)->not->toContain('tolto ');
});
