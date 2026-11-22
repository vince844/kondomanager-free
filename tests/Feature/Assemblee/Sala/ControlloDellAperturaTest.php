<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Sala\ControlloDellApertura;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Il controllo dell'apertura (R4, art. 1136 c. 6 c.c.: «L'assemblea non può deliberare, se non consta che tutti gli aventi diritto sono stati regolarmente
 * convocati»): chi ha diritto alla data della seduta e non risulta convocato con un invio che vale, chi l'ha ricevuto fuori tempo, chi l'ha rifiutato, il
 * nuovo titolare senza avviso; la PEC non comunicata e la videoconferenza senza il consenso come cose da guardare. **Decide il presidente**: «Rinvia
 * l'assemblea» o «Apri, e il verbale lo scrive», con il motivo; la scelta sta nella seduta e nella riga del registro.
 *
 * Che cosa non copre: le deleghe oltre il quinto con la fotografia nuova (`DelegheDellAssembleaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function cdaApri($t, $s, $a, array $piu = [])
{
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-15 18:40'));

    return SalaDiViaRoma12::manda($t, $s, $a, [SalaDiViaRoma12::gesto('apri', $piu + ['seduta_id' => SalaDiViaRoma12::seduta($a)->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42')])->json('risposte.0');
}

it('tutti convocati in tempo: niente da scegliere, la sala si apre', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $c = app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a));

    expect($c['problemi'])->toBe([])->and($c['serve_la_scelta'])->toBeFalse()
        ->and(cdaApri($this, $s, $a)['esito'])->toBe('applicato');
});

it('chi non risulta convocato: il presidente sceglie «Apri, e il verbale lo scrive» con il motivo, e la seduta lo ricorda', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    $c = app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a));
    expect($c['serve_la_scelta'])->toBeTrue()
        ->and(collect($c['problemi'])->pluck('frase')->all())->toBe(['Gallo Enzo (Int. 7) non risulta fra i convocati.']);

    $r = cdaApri($this, $s, $a);
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Gallo Enzo (Int. 7) non risulta fra i convocati. L\'assemblea non può deliberare se non consta che tutti gli aventi diritto sono stati regolarmente convocati (art. 1136 c. 6 c.c.): scegli «Apri, e il verbale lo scrive» con il motivo, o «Rinvia l\'assemblea».');

    $riga = ViaRoma12::ultimaRiga();
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore'], 'scelta' => 'apri',
        'motivo' => 'Gallo Enzo è presente in sala e dichiara di aver saputo dell\'assemblea'], '2026-12-15 18:42')])->json('risposte.0');
    expect($r['esito'])->toBe('applicato')
        ->and(SalaDiViaRoma12::seduta($a)->apertura)->toMatchArray(['scelta' => 'apri',
            'motivo' => 'Gallo Enzo è presente in sala e dichiara di aver saputo dell\'assemblea'])
        ->and(ViaRoma12::righe($riga)->last()->perche)
        ->toBe('Apri, e il verbale lo scrive: Gallo Enzo (Int. 7) non risulta fra i convocati. Motivo: Gallo Enzo è presente in sala e dichiara di aver saputo dell\'assemblea');
});

it('l\'avviso ricevuto fuori tempo e quello rifiutato sono fra le cose da scegliere', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, [], '2026-12-05', ['bianchi' => '2026-12-11']);
    RegistroInvio::query()->where('anagrafica_id', $s->persone['rita']->id)->update(['esito' => RegistroInvio::ESITO_RIFIUTATA]);

    $frasi = collect(app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a))['problemi'])->pluck('frase')->all();
    expect($frasi)->toBe([
        'Bianchi Anna (Int. 14) ha ricevuto l\'avviso l\'11/12/2026, dopo il 09/12/2026.',
        'Colombo Rita (Int. 12) ha rifiutato l\'avviso: vale come ricevuto, e il presidente lo deve sapere.',
    ]);
});

it('il nuovo titolare senza avviso: chi è titolare il giorno della seduta e non ha ricevuto niente', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['7']->id)->where('anagrafica_id', $s->persone['gallo']->id)
        ->update(['data_fine' => '2026-12-09']);
    $s->titolare('7', 'verdi', 'proprietario', 100, '2026-12-10');
    $s->persone['verdi']->forceFill(['nome' => 'Verdi Sara'])->saveQuietly();

    expect(collect(app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a))['problemi'])->pluck('frase')->all())
        ->toBe(['Verdi Sara (Int. 7) non risulta fra i convocati: è titolare dal 10/12/2026.']);
});

it('la videoconferenza senza il consenso e la PEC non comunicata sono cose da guardare, non da scegliere', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, null, ['modalita' => 'mista', 'piattaforma' => 'Jitsi']), $s);
    \App\Models\Assemblee\ConvocatoDellAssemblea::query()->where('anagrafica_id', $s->persone['paolo']->id)->update(['mezzo' => 'pec']);

    $c = app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a));
    expect($c['serve_la_scelta'])->toBeFalse()
        ->and(collect($c['da_guardare'])->pluck('frase')->all())->toBe([
            'L\'assemblea è anche in videoconferenza, e il consenso della maggioranza dei condòmini non è registrato (art. 66 c. 6 disp. att.).',
            'Neri Paolo: l\'avviso è partito per PEC, e la PEC non è comunicata dalla persona.',
        ]);
});
