<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Sala\ProspettoDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Ciò che la sala lascia nel diario, nel prospetto e per il verbale:
 *
 * - **«Registra dal foglio della sala» a sala aperta**: ogni riga dice «registrato dal foglio della sala», anche se la seduta non è stata aperta dal foglio;
 * - **«Sospendi» e «Chiudi»** chiudono le presenze aperte all'ora del gesto, come dice la finestra: il prospetto non ha più «Uscita —» per chi era in sala;
 * - **le deleghe rifiutate all'apertura** (oltre il quinto con la fotografia della seduta) sono nel perché della riga dell'apertura e nel prospetto;
 * - **per il verbale** la sala porta anche le deleghe: chi ha delegato chi, le rifiutate e le revocate con il motivo, le decisioni del presidente.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('«Registra dal foglio della sala» a sala aperta: le righe dicono «registrato dal foglio della sala» anche se la seduta non è aperta dal foglio', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $riga = ViaRoma12::ultimaRiga();

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['c45']->id],
        'come' => 'in_sala', 'dal_foglio' => true], '2026-12-15 20:15', 0));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['c45']->id],
        'dal_foglio' => true], '2026-12-15 20:25', 1));

    expect(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe([
        'Assemblea ordinaria del 15/12/2026: Condomino 45 entra in sala alle 20:15 · registrato dal foglio della sala',
        'Assemblea ordinaria del 15/12/2026: Condomino 45 esce alle 20:25 · registrato dal foglio della sala',
    ]);
});

it('«Sospendi» e «Chiudi» chiudono le presenze aperte all\'ora del gesto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'c30']);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id,
        'prosegue_seduta_id' => SalaDiViaRoma12::seduta($a, 'seconda', 2)->id], '2026-12-15 20:30'));
    expect(PresenzaInSala::query()->where('seduta_id', $seduta->id)->whereNull('uscita_il')->count())->toBe(0)
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->pluck('uscita_il')->map(fn ($x) => $x->format('H:i'))->unique()->values()->all())->toBe(['19:30']);

    $prosegue = SalaDiViaRoma12::seduta($a, 'seconda', 2);
    ConvocazioneDiViaRoma12::oggi('2026-12-17 21:00');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prosegue->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:35'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $prosegue->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id],
        'come' => 'in_sala'], '2026-12-17 18:40', 0));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $prosegue->id, 'non_trattati' => 'rinviata'], '2026-12-17 20:00'));
    expect(PresenzaInSala::query()->where('seduta_id', $prosegue->id)->whereNull('uscita_il')->count())->toBe(0)
        ->and(collect(app(ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['presenze'][0]['uscita'] ?? null)->toBe('20:00');
});

it('la delega rifiutata all\'apertura è nel perché della riga dell\'apertura e nel prospetto; il verbale riceve le deleghe', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    foreach (['rita', 'gallo'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    }
    // Il 15/12 Gallo compra l'Int. 31 e l'Int. 32: con la fotografia della seconda Condomino 30 porterebbe 220,50 millesimi su 200,00.
    foreach (['31', '32'] as $u) {
        \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s->unita[$u]->id)->update(['data_fine' => '2026-12-14']);
        $s->titolare($u, 'gallo', 'proprietario', 100, '2026-12-15');
    }
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);

    $frase = 'Delega di Gallo Enzo a Condomino 30 rifiutata: all\'apertura, con la fotografia della seduta, Condomino 30 rappresenterebbe 2 condòmini su 4 · '
        . '220,50 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.).';
    $apertura = ViaRoma12::righe($riga)->first(fn ($r) => str_contains((string) $r->riepilogo, 'aperta in seconda convocazione'));
    expect($apertura->perche)->toBe($frase)
        ->and(collect(app(ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['deleghe_rifiutate'])->toBe([$frase]);
    $verbale = app(LetturaDellaSala::class)->perIlVerbale($a);
    expect(collect($verbale['deleghe'])->firstWhere('delegante', 'Gallo Enzo'))->toMatchArray(['delegato' => 'Condomino 30', 'stato' => 'rifiutata',
        'motivo' => 'All\'apertura, con la fotografia della seduta, Condomino 30 rappresenterebbe 2 condòmini su 4 · 220,50 su 200,00: oltre un quinto del valore '
            . '(art. 67 c. 1 disp. att.).'])
        ->and(collect($verbale['deleghe'])->firstWhere('delegante', 'Colombo Rita'))->toMatchArray(['delegato' => 'Condomino 30', 'stato' => 'valida', 'punti' => null]);
});
