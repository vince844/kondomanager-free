<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * L'uscita che arriva tardi da un altro dispositivo (decisione 519 (4)): se toglie dagli intervenuti di un voto non ancora confermato una testa che **ha già
 * votato**, non si applica in silenzio. È «Due versioni» («Colombo Rita esce alle 19:38» contro «Colombo Rita: ha votato contrario al punto 1 alle 19:41»),
 * il punto non si conferma finché non si sceglie, e sceglie l'amministratore. Scelta l'uscita, il voto non conta più: lo dicono la riga del registro e il
 * messaggio. Mai un voto tolto senza una riga.
 *
 * E il delegato fuori dall'anagrafica esce anche dopo che la sua delega è revocata: la sua riga non resta aperta.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La seconda aperta, undici in sala, il punto 1 votato alle 19:40 e Colombo Rita contraria alle 19:41 dal portatile; poi arriva l'uscita del telefono. */
function utdUscita($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $v = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:40'));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => 'p-' . $s->persone['rita']->id,
        'voto' => 'contrario'], '2026-12-15 19:41', 0));
    $r = SalaDiViaRoma12::manda($t, $s, $a, [SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rita']->id]],
        '2026-12-15 19:38', 1)], 'telefono di Marco')->json('risposte.0');

    return [$s, $a, $seduta, VotazioneDelPunto::query()->findOrFail($v['votazione_id']), $r];
}

it('l\'uscita tardiva che toglierebbe un voto già segnato è «Due versioni»: il voto resta, e il punto non si conferma finché non si sceglie', function () {
    [$s, $a, , $v, $r] = utdUscita($this);

    expect($r['esito'])->toBe('in_conflitto')
        ->and($r['conflitto']['questa'])->toBe('Colombo Rita: esce alle 19:38')
        ->and($r['conflitto']['quella'])->toBe('Colombo Rita: ha votato contrario al punto 1 alle 19:41')
        ->and($v->refresh()->voti()->count())->toBe(1)
        ->and($v->intervenuti['numero'])->toBe(11);
    $conferma = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v->id, 'esito' => 'approvata'], '2026-12-15 19:50')])
        ->json('risposte.0');
    expect($conferma['esito'])->toBe('rifiutato')->and($conferma['messaggio'])->toContain('due versioni da scegliere');
});

it('scelta l\'uscita, il voto non conta più: la riga del registro e il messaggio lo dicono', function () {
    [$s, $a, $seduta, $v, $r] = utdUscita($this);
    $riga = ViaRoma12::ultimaRiga();

    $scelta = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('scegli', ['gesto' => $r['gesto'], 'scelta' => 'questa'], '2026-12-15 19:55'));
    expect($v->refresh()->voti()->count())->toBe(0)
        ->and($v->intervenuti['numero'])->toBe(10)
        ->and($scelta['messaggio'])->toBe('Il voto di Colombo Rita al punto 1 non conta più: l\'uscita alle 19:38 è prima del voto.')
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->implode(' | '))
        ->toContain('fra due versioni scelta «Colombo Rita: esce alle 19:38» (l\'altra era «Colombo Rita: ha votato contrario al punto 1 alle 19:41»). '
            . 'Il voto di Colombo Rita al punto 1 non conta più: l\'uscita alle 19:38 è prima del voto');
});

it('scelto il voto, l\'uscita non c\'è: Colombo Rita resta in sala e il suo voto conta', function () {
    [$s, $a, $seduta, $v, $r] = utdUscita($this);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('scegli', ['gesto' => $r['gesto'], 'scelta' => 'quella'], '2026-12-15 19:55'));
    expect($v->refresh()->voti()->count())->toBe(1)
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('anagrafica_id', $s->persone['rita']->id)->whereNull('uscita_il')->count())->toBe(1);
});

it('il delegato fuori dall\'anagrafica esce anche dopo che la sua delega è revocata', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $d = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone['rita']->id,
        'delegato' => ['nome' => 'Verdi Paolo']], '2026-12-15 18:44'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $d['delega_id']], 'come' => 'in_sala'],
        '2026-12-15 18:45', 0));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega_revoca', ['delega_id' => $d['delega_id'], 'motivo' => 'Colombo Rita è arrivata'],
        '2026-12-15 19:00'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $d['delega_id']]],
        '2026-12-15 19:05', 1)])->json('risposte.0');
    expect($r['esito'])->toBe('applicato')
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('chiave', 'delega:' . $d['delega_id'])->whereNull('uscita_il')->count())->toBe(0);
    // Ma non rientra: la delega non vale più.
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $d['delega_id']],
        'come' => 'in_sala'], '2026-12-15 19:10', 2)])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato');
});
