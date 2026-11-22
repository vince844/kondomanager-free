<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Str;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * I gesti della sala, il lato server della tenuta senza rete (R9; decisioni 514 (5) e 519): ogni gesto ha un numero, e **lo stesso gesto arrivato due volte
 * conta una volta** («doppio»); i gesti di un lotto si applicano in ordine, ognuno nella sua transazione; **due versioni**: un gesto partito da una versione
 * cambiata nel frattempo in un altro modo non sovrascrive niente, è «in conflitto», e sceglie l'amministratore («Scegli»), e una votazione con una scelta
 * aperta non si conferma; l'ora del gesto oltre due minuti nel futuro si rifiuta, l'arrivo si scrive sempre; una sessione scaduta (401) non tocca niente, e
 * gli stessi gesti partono dopo; il lotto ha un tetto di 500 gesti.
 *
 * Che cosa non copre: la coda nel browser (i vitest della coda); il 419 della pagina (il token lo controlla il framework prima del controller: lato server
 * vale come il 401, nessun gesto arriva al servizio).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function gdsSala($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);

    return [$s, $a, $seduta];
}

it('lo stesso gesto arrivato due volte conta una volta: la seconda risposta è «doppio»', function () {
    [$s, $a, $seduta] = gdsSala($this);
    $gesto = SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id], 'come' => 'in_sala']);

    $prima = SalaDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0');
    $seconda = SalaDiViaRoma12::manda($this, $s, $a, [$gesto, $gesto], 'telefono di Andrea')->assertOk()->json('risposte');

    expect($prima['esito'])->toBe('applicato')
        ->and(array_column($seconda, 'esito'))->toBe(['doppio', 'doppio'])
        ->and($seconda[0]['messaggio'])->toBe('Questo gesto era già arrivato alle 21:00: conta una volta.')
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->count())->toBe(1)
        ->and(GestoDellaSala::query()->where('gesto', $gesto['gesto'])->count())->toBe(1);
});

it('i gesti di un lotto si applicano in ordine, ognuno per sé: uno rifiutato non ferma gli altri', function () {
    [$s, $a, $seduta] = gdsSala($this);
    $persona = ['anagrafica_id' => $s->persone['rossi']->id];
    $risposte = SalaDiViaRoma12::manda($this, $s, $a, [
        SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_sala'], '2026-12-15 18:45'),
        SalaDiViaRoma12::gesto('balla', [], '2026-12-15 18:46'),
        SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $persona], '2026-12-15 18:50'),
        SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona, 'come' => 'in_sala'], '2026-12-15 19:00'),
    ])->assertOk()->json('risposte');

    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'rifiutato', 'applicato', 'applicato'])
        ->and($risposte[1]['messaggio'])->toBe('Un gesto che la sala non conosce: «balla».')
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->orderBy('id')->get()->map(fn ($p) => [$p->entrata_il->format('H:i'), $p->uscita_il?->format('H:i')])->all())
        ->toBe([['17:45', '17:50'], ['18:00', null]]);
});

it('due versioni di un voto: niente si sovrascrive, la votazione non si conferma, sceglie l\'amministratore', function () {
    [$s, $a, $seduta] = gdsSala($this);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $rita = 'p-' . $s->persone['rita']->id;

    // Il telefono, in linea, segna Colombo Rita astenuta; il portatile, senza rete, l'aveva segnata contraria dalla stessa versione.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $rita, 'voto' => 'astenuto'],
        '2026-12-15 19:03', 1));
    $portatile = SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $rita, 'voto' => 'contrario'], '2026-12-15 19:02', 1);
    $r = SalaDiViaRoma12::manda($this, $s, $a, [$portatile])->json('risposte.0');

    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    expect($r['esito'])->toBe('in_conflitto')
        ->and($r['conflitto'])->toBe(['questa' => 'Colombo Rita: Contrario', 'quella' => 'Colombo Rita: Astenuto', 'quella_dispositivo' => 'portatile di Andrea',
            'quella_segnato_il' => '2026-12-15T18:03:00Z'])
        ->and($votazione->voti()->where('testa', $rita)->value('voto')->value)->toBe('astenuto');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $votazione->id, 'esito' => 'approvata'], '2026-12-15 19:05',
        $votazione->versione)])->json('risposte.0');
    expect($r['messaggio'])->toBe('Il voto del punto 1 ha due versioni da scegliere: scegli prima di confermare.');

    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('scegli', ['gesto' => $portatile['gesto'], 'scelta' => 'questa'], '2026-12-15 19:06'));
    expect($votazione->voti()->where('testa', $rita)->value('voto')->value)->toBe('contrario')
        ->and(GestoDellaSala::query()->where('gesto', $portatile['gesto'])->first()->scelto_da)->toBe($s->admin->id)
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())
        ->toBe(['Assemblea ordinaria del 15/12/2026: fra due versioni scelta «Colombo Rita: Contrario» (l\'altra era «Colombo Rita: Astenuto»)']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $votazione->id, 'esito' => 'respinta'], '2026-12-15 19:07',
        $votazione->refresh()->versione));
});

it('lo stesso cambiamento fatto da due dispositivi non è un conflitto', function () {
    [$s, $a, $seduta] = gdsSala($this);
    $entra = fn () => SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id], 'come' => 'in_sala'],
        '2026-12-15 18:45', 0);
    SalaDiViaRoma12::applica($this, $s, $a, $entra());
    $r = SalaDiViaRoma12::manda($this, $s, $a, [$entra()], 'telefono di Andrea')->json('risposte.0');

    expect($r['esito'])->toBe('applicato')->and($r['messaggio'])->toBe('Era già così: niente da cambiare.')
        ->and(PresenzaInSala::query()->count())->toBe(1);
});

it('l\'ora del gesto oltre due minuti nel futuro si rifiuta; l\'arrivo si scrive sempre', function () {
    [$s, $a, $seduta] = gdsSala($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:45');
    $gesto = fn (string $alle) => SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id],
        'come' => 'in_sala'], $alle);

    $r = SalaDiViaRoma12::manda($this, $s, $a, [$gesto('2026-12-15 18:48')])->json();
    expect($r['risposte'][0]['esito'])->toBe('rifiutato')
        ->and($r['risposte'][0]['messaggio'])->toBe('L\'ora del gesto (18:48) è avanti rispetto al server (18:45): controlla l\'orologio di questo dispositivo.')
        ->and($r['ora_del_server'])->toBe('2026-12-15T17:45:00Z');
    $g = $gesto('2026-12-15 18:46');
    SalaDiViaRoma12::applica($this, $s, $a, $g);
    expect(GestoDellaSala::query()->where('gesto', $g['gesto'])->first()->arrivato_il->format('Y-m-d H:i'))->toBe('2026-12-15 17:45');
});

it('la sessione scaduta non tocca niente: i gesti restano al dispositivo e partono dopo, una volta sola', function () {
    [$s, $a, $seduta] = gdsSala($this);
    $gesto = SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id], 'come' => 'in_sala']);
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), ['dispositivo' => 'portatile di Andrea', 'gesti' => [$gesto]])->assertUnauthorized();
    expect(GestoDellaSala::query()->where('gesto', $gesto['gesto'])->exists())->toBeFalse()->and(PresenzaInSala::query()->count())->toBe(0);

    expect(SalaDiViaRoma12::manda($this, $s, $a, [$gesto])->json('risposte.0.esito'))->toBe('applicato')
        ->and(PresenzaInSala::query()->count())->toBe(1);
});

it('il lotto ha un tetto di 500 gesti, e ogni gesto il numero, il tipo e l\'ora', function () {
    [$s, $a, $seduta] = gdsSala($this);
    $gesti = array_map(fn () => SalaDiViaRoma12::gesto('balla', []), range(1, 501));
    SalaDiViaRoma12::manda($this, $s, $a, $gesti)->assertUnprocessable()->assertJsonValidationErrors('gesti');
    SalaDiViaRoma12::manda($this, $s, $a, [['gesto' => 'non-un-numero', 'tipo' => 'entra', 'dati' => [], 'segnato_il' => 'ieri']])->assertUnprocessable()
        ->assertJsonValidationErrors(['gesti.0.gesto', 'gesti.0.segnato_il']);
    expect(Str::isUuid(SalaDiViaRoma12::gesto('entra', [])['gesto']))->toBeTrue();
});
