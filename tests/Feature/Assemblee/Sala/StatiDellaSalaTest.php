<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Gli stati della sala (R5): «Segna deserta la prima convocazione» dall'ora della prima; «Apri l'assemblea» dal giorno della seduta, con la fotografia, il
 * presidente e il segretario; «Sospendi, prosegue il …» solo verso una data dell'avviso (art. 66 c. 5 disp. att.); «Rinvia l'assemblea» anche da in corso,
 * con la riconvocazione; «Chiudi l'assemblea» solo con un esito per ogni punto (i non trattati «Rinviata»). Ogni gesto è una riga del registro con l'ora
 * del gesto.
 *
 * Che cosa non copre: il controllo dell'apertura (`ControlloDellAperturaTest`); la pagina (i vitest della sala).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function sdsAssemblea($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);

    return [$s, SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s)];
}

it('la prima convocazione si segna deserta dall\'ora della prima, non prima; la riga del registro ha l\'ora del gesto', function () {
    [$s, $a] = sdsAssemblea($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 07:50');
    $prima = SalaDiViaRoma12::seduta($a, 'prima');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 07:50')])->assertOk()->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('La prima convocazione è fissata alle 08:00 del 14/12/2026: si segna deserta da quell\'ora.');

    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:40');
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 08:30'));
    $righe = ViaRoma12::righe($riga);
    expect($prima->refresh()->esito?->value)->toBe('deserta')
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA)
        ->and($righe->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026: la prima convocazione del 14/12/2026 è deserta'])
        ->and($righe->first()->avvenuta_il->format('Y-m-d H:i'))->toBe('2026-12-14 07:30')
        ->and($righe->first()->registrata_il->format('Y-m-d H:i'))->toBe('2026-12-14 07:40');
});

it('la sala si apre dal giorno della seduta, e la seconda solo dopo la prima deserta', function () {
    [$s, $a] = sdsAssemblea($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 20:00');
    $seconda = SalaDiViaRoma12::seduta($a);
    $apri = fn (string $alle) => SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('apri', ['seduta_id' => $seconda->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], $alle)])->json('risposte.0');

    expect($apri('2026-12-14 19:59')['messaggio'])->toBe('La sala si apre il giorno della seduta, il 15/12/2026.');
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
    expect($apri('2026-12-15 18:40')['messaggio'])->toBe('Prima segna deserta la prima convocazione del 14/12/2026.');
});

it('«Apri l\'assemblea»: la fotografia con la sua impronta, presidente e segretario, l\'assemblea in corso nel giorno della seduta', function () {
    [$s, $a] = sdsAssemblea($this);
    $riga = ViaRoma12::ultimaRiga();
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);

    expect($seduta->aperta_il->format('Y-m-d H:i'))->toBe('2026-12-15 17:42')
        ->and($seduta->presidente)->toBe(['anagrafica_id' => $s->persone['rossi']->id, 'nome' => 'Rossi Mario'])
        ->and($seduta->segretario)->toBe(['anagrafica_id' => null, 'nome' => 'L\'amministratore'])
        ->and($seduta->fotografia['costituzione']['totale_teste'])->toBe(26)
        ->and($seduta->impronta_fotografia)->toBe(FotografiaDellaSeduta::impronta($seduta->fotografia))
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::IN_CORSO)
        ->and($a->data->format('Y-m-d'))->toBe('2026-12-15')
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->last())
        ->toBe('Assemblea ordinaria del 15/12/2026: aperta in seconda convocazione alle 18:42, presidente Rossi Mario, segretario L\'amministratore');
});

it('«Sospendi, prosegue il …» solo verso una data dell\'avviso; la prosecuzione si riapre', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id,
        'prosegue_seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-15 20:30')])->json('risposte.0');
    expect($r['messaggio'])->toBe('Si sospende solo verso una data di prosecuzione scritta nell\'avviso (art. 66 c. 5 disp. att.).');

    $prosegue = SalaDiViaRoma12::seduta($a, 'seconda', 2);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id],
        '2026-12-15 20:30'));
    expect($seduta->refresh()->esito?->value)->toBe('sospesa')
        ->and($seduta->chiusa_il->format('H:i'))->toBe('19:30')
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::IN_CORSO);

    ConvocazioneDiViaRoma12::oggi('2026-12-17 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prosegue->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:35'));
    expect($prosegue->refresh()->aperta_il)->not->toBeNull()->and($prosegue->fotografia['giorno'])->toBe('2026-12-17');
});

it('«Rinvia l\'assemblea» anche da in corso: l\'assemblea si annulla «rinviata dalla sala» e ne nasce una nuova con le date nuove', function () {
    [$s, $a] = sdsAssemblea($this);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $riga = ViaRoma12::ultimaRiga();

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('rinvia', ['motivo' => 'La sala è inagibile per un allagamento', 'sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2027-01-11 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2027-01-12 18:30'],
    ]], '2026-12-15 18:50'));

    $nuova = Assemblea::query()->where('riconvocata_da_id', $a->id)->firstOrFail();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::ANNULLATA)
        ->and($a->annullata_motivo)->toBe('Rinviata dalla sala: La sala è inagibile per un allagamento')
        ->and($seduta->refresh()->esito?->value)->toBe('rinviata')
        ->and($nuova->punti()->count())->toBe(6)
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026 rinviata dalla sala e riconvocata per il 12/01/2027']);
});

it('«Chiudi l\'assemblea» solo con un esito per ogni punto: i non trattati «Rinviata», poi chiusa', function () {
    [$s, $a] = sdsAssemblea($this);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'gallo', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->first();
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1->id], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:40')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Il punto 1 ha una votazione da confermare: confermala prima di chiudere.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:02',
        $v['versione'] + 1));
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:40')])->json('risposte.0');
    expect($r['messaggio'])->toBe('5 punti senza esito (2, 3, 4, 5 e 6): segnali «Rinviata», o votali prima di chiudere.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 20:40'));
    expect($a->refresh()->stato)->toBe(StatoAssemblea::CHIUSA)
        ->and($seduta->refresh()->esito?->value)->toBe('tenuta')
        ->and($seduta->chiusa_il->format('H:i'))->toBe('19:40')
        ->and(Delibera::query()->where('assemblea_id', $a->id)->orderBy('punto_id')->pluck('esito')->map(fn ($e) => $e->value)->all())
        ->toBe(['approvata', 'rinviata', 'rinviata', 'rinviata', 'rinviata', 'rinviata']);
});

it('deserta anche la seconda: l\'assemblea è deserta', function () {
    [$s, $a] = sdsAssemblea($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:00');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-14 09:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id], '2026-12-15 19:30'));

    expect($a->refresh()->stato)->toBe(StatoAssemblea::DESERTA)
        ->and(SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->pluck('esito')->map(fn ($e) => $e?->value)->all())->toBe(['deserta', 'deserta']);
});

it('un sospeso che è un punto dell\'assemblea in corso non si propone in un\'altra assemblea', function () {
    [$s, $a] = sdsAssemblea($this);
    $a->punti()->where('numero', 3)->first()->forceFill(['chiave_fonte' => 'fattura:45'])->saveQuietly();
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);

    expect(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::chiaviOccupate($s->c, null))->toContain('fattura:45')
        ->and(\App\Services\Assemblee\ProposteDellOrdineDelGiorno::puntoConLaChiave($s->c, 'fattura:45'))->toMatchArray(['numero' => 3, 'assemblea_id' => $a->id]);
});
