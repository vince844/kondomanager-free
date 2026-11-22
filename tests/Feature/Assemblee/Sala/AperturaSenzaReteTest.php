<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Sala\ProspettoDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * L'apertura che arriva dopo, con il controllo dell'art. 1136 c. 6 cambiato nel frattempo (la decisione 519: niente del lavoro senza rete si perde).
 *
 * - La pagina dice nel gesto che cosa ha visto del controllo (`controllo_visto`: le persone con un problema, vuoto se era pulito, null se non l'aveva). Se
 *   adesso il controllo chiede la scelta e la pagina non l'ha vista, l'apertura **si applica con la scelta «da decidere»**: le presenze e i voti segnati dopo
 *   arrivano; la sala dice che decide il presidente.
 * - Se la pagina ha visto gli stessi problemi e non ha scelto, si rifiuta come prima (la pagina non lo lascia fare).
 * - Finché il presidente non decide l'assemblea non si chiude né si sospende; «Apri, e il verbale lo scrive» con il motivo chiude la scelta, con la sua riga;
 *   il prospetto lo scrive.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/**
 * Gallo Enzo senza avviso: alle 21:00 arriva il lotto della sala senza rete, la prima deserta alle 18:35 e la seconda aperta alle 18:42 senza la scelta; con
 * `$prima` un'apertura che la pagina manda prima di quella, con un altro `controllo_visto`.
 */
function asrLotto($t, ?array $visto, ?array $prima = null): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:00');
    $seconda = SalaDiViaRoma12::seduta($a);
    $apri = fn ($v) => SalaDiViaRoma12::gesto('apri', ['seduta_id' => $seconda->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore'], 'controllo_visto' => $v === 'gallo' ? [$s->persone['gallo']->id] : $v], '2026-12-15 18:42');
    $r = SalaDiViaRoma12::manda($t, $s, $a, [
        SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-15 18:35'),
        ...($prima === null ? [] : [$apri($prima[0])]),
        $apri($visto),
        SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seconda->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id], 'come' => 'in_sala'],
            '2026-12-15 18:45', 0),
    ])->assertOk()->json('risposte');

    return [$s, $a, $seconda, $r];
}

it('un\'apertura senza la scelta, da una pagina che non sapeva del controllo cambiato, si applica «da decidere»: le presenze dopo arrivano', function () {
    // Prima l'apertura di una pagina che aveva visto lo stesso problema e non ha scelto: si rifiuta come prima (la pagina non lo lascia fare).
    [$s, $a, $seconda, $r] = asrLotto($this, [], ['gallo']);

    expect(array_column($r, 'esito'))->toBe(['applicato', 'rifiutato', 'applicato', 'applicato'])
        ->and($r[2]['messaggio'])->toBe('Il controllo dell\'apertura è cambiato mentre la sala era senza rete: Gallo Enzo (Int. 7) non risulta fra i convocati. '
            . 'Decide il presidente: «Apri, e il verbale lo scrive» con il motivo, o «Rinvia l\'assemblea».')
        ->and($seconda->refresh()->apertura['scelta'])->toBe('da_decidere')
        ->and(PresenzaInSala::query()->where('seduta_id', $seconda->id)->count())->toBe(1);
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect(collect($sala['sedute'])->firstWhere('id', $seconda->id)['apertura_da_decidere'])->toBe(['Gallo Enzo (Int. 7) non risulta fra i convocati.']);
});

it('la pagina che non aveva il controllo (la sala proiettata dopo «Segna deserta» senza rete) apre «da decidere»', function () {
    [, , $seconda, $r] = asrLotto($this, null);

    expect(array_column($r, 'esito'))->toBe(['applicato', 'applicato', 'applicato'])
        ->and($seconda->refresh()->apertura['scelta'])->toBe('da_decidere');
});

it('finché il presidente non decide l\'assemblea non si chiude; «Apri, e il verbale lo scrive» con il motivo chiude la scelta, con la riga e nel prospetto', function () {
    [$s, $a, $seconda] = asrLotto($this, []);
    $chiudi = fn () => SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seconda->id, 'non_trattati' => 'rinviata'],
        '2026-12-15 20:50')])->json('risposte.0');

    $r = $chiudi();
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Prima di chiudere decide il presidente sull\'apertura: Gallo Enzo (Int. 7) non risulta fra i convocati.');
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('apertura_decidi', ['seduta_id' => $seconda->id], '2026-12-15 20:45')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Scrivi il motivo del presidente: va nel verbale.');

    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apertura_decidi', ['seduta_id' => $seconda->id,
        'motivo' => 'Gallo Enzo è presente e non si oppone'], '2026-12-15 20:46'));
    expect($seconda->refresh()->apertura)->toMatchArray(['scelta' => 'apri', 'motivo' => 'Gallo Enzo è presente e non si oppone'])
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026: il presidente decide sull\'apertura alle 20:46: '
            . 'aperta, e il verbale lo scrive'])
        ->and(ViaRoma12::righe($riga)->first()->perche)->toBe('Apri, e il verbale lo scrive: Gallo Enzo (Int. 7) non risulta fra i convocati. Motivo: Gallo Enzo '
            . 'è presente e non si oppone')
        ->and(collect(app(ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['scelta'])
        ->toBe('Aperta alle 18:42 senza la scelta, il controllo era cambiato senza rete; il presidente ha deciso alle 20:46: Gallo Enzo (Int. 7) non risulta fra i '
            . 'convocati. Motivo: Gallo Enzo è presente e non si oppone')
        ->and($chiudi()['esito'])->toBe('applicato');
});
