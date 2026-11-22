<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * 1.12.0-beta.13, terzo giro (C11): il verbale non si contraddice sui presenti. La frase della costituzione dice i presenti **alla costituzione** (il primo
 * momento in cui i conti bastano, con l'ora); se l'assemblea non risulta mai costituita, i presenti al primo voto o alla chiusura, e la frase lo dice. Nella
 * sala dal foglio, ciò che è segnato nello stesso minuto vale insieme: una presenza dell'ora dell'apertura è presente all'apertura.
 */
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

$tutti = ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))];

it('sala dal vivo: i presenti sono quelli alla costituzione, con l\'ora, non quelli del primo voto', function () use ($tutti) {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, array_slice($tutti, 0, 3), '2026-12-15 18:43');
    SalaDiViaRoma12::entrano($this, $s, $a, array_slice($tutti, 3), '2026-12-15 18:50');
    SalaDiViaRoma12::entrano($this, $s, $a, ['bianchi'], '2026-12-15 19:20');
    VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 1, '2026-12-15 19:30');

    $c = LetturaDellaSala::costituzioneDellaSeduta($seduta->refresh(), VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->get());
    expect($c['costituita'])->toBeTrue()->and($c['al'])->toBe('2026-12-15T17:50:00Z')->and($c['teste'])->toBe(11)->and($c['al_primo_voto'])->toBeFalse();
});

it('sala dal foglio: aperta alle 18:42 e presenze segnate nello stesso minuto, prima del voto: 11 presenti, non 0', function () use ($tutti) {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a, ['dal_foglio' => true]);
    // Le presenze portano i secondi dopo il voto: la riga del voto è del minuto dell'apertura, e le presenze anche.
    foreach ($tutti as $x) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala', 'dal_foglio' => true], '2026-12-15 18:42:40'));
    }
    $seduta->refresh();
    $voto = new VotazioneDelPunto(['votata_il' => \Illuminate\Support\Carbon::parse('2026-12-15 18:42:10', 'Europe/Rome')]);

    $c = LetturaDellaSala::costituzioneDellaSeduta($seduta, collect([$voto]));
    expect($c['teste'])->toBe(11)->and($c['costituita'])->toBeTrue();
});

// La prova a video del 21/11/2026 sera (assemblea n. 8 di Via delle Acacie 12): dal foglio, aperta, presenze e chiusura tutte nello stesso minuto. La chiusura
// fa uscire tutti a quell'ora, e le uscite della chiusura toglievano i presenti anche alla costituzione: «0 condòmini su 4», e poi «intervengono 3».
it('sala dal foglio aperta e chiusa nello stesso minuto: le uscite della chiusura non tolgono i presenti alla costituzione', function () use ($tutti) {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a, ['dal_foglio' => true]);
    foreach ($tutti as $x) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala', 'dal_foglio' => true], '2026-12-15 18:42:40'));
    }
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 18:42:50'));
    $seduta->refresh();
    expect($seduta->chiusa_il)->not->toBeNull();

    $c = LetturaDellaSala::costituzioneDellaSeduta($seduta, VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->get());
    expect($c['teste'])->toBe(11)->and($c['costituita'])->toBeTrue();
});

// La sala chiusa si legge com'era (decisione 660, C39): la barra dice la costituzione vera, non i presenti di adesso (usciti tutti con la chiusura), e la pagina
// porta le presenze dell'ultima seduta con le ore di entrata e di uscita.
it('la sala chiusa nello stesso minuto dell\'apertura: la barra dice 11 su 26 e costituita, non 0; le presenze dell\'ultima seduta ci sono', function () use ($tutti) {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a, ['dal_foglio' => true]);
    foreach ($tutti as $x) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala', 'dal_foglio' => true], '2026-12-15 18:42:40'));
    }
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-15 18:42:50'));

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['seduta_id'])->toBeNull()->and($sala['presenze'])->toBe([])
        ->and($sala['chiusura']['teste'])->toBe(11)->and($sala['chiusura']['costituita'])->toBeTrue()->and($sala['chiusura']['totale_teste'])->toBe(26)
        ->and($sala['chiusura']['valore'])->toBeGreaterThan(0)
        ->and($sala['ultima_seduta']['seduta_id'])->toBe((int) $seduta->id)->and($sala['ultima_seduta']['presenze'])->toHaveCount(count($tutti))
        ->and($sala['ultima_seduta']['presenze'][0]['entrata_il'])->not->toBeNull()->and($sala['ultima_seduta']['presenze'][0]['uscita_il'])->not->toBeNull()
        ->and($sala['rotte']['registro'])->toContain('da_assemblea=' . $a->id);
});

it('la sala aperta non porta l\'ultima seduta: le presenze sono quelle di sempre', function () use ($tutti) {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, array_slice($tutti, 0, 3), '2026-12-15 18:43');

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['ultima_seduta'])->toBeNull()->and($sala['presenze'])->toHaveCount(3);
});

it('il verbale dice la costituzione con l\'ora e i presenti di allora; se non è mai costituita, lo dice', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $doc = app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento'];
    $blocco = StrutturaDelVerbale::blocchi($doc)['costituzione:' . $seduta->id];
    expect($blocco)->toContain('Alle ore 18:45 l\'assemblea è regolarmente costituita.')
        ->toContain('Sono presenti, di persona o per delega, 11 condòmini su 26, per 494,25 millesimi su 1.000, come dall\'elenco in calce.');

    // Restano tre persone sole: l'assemblea non risulta mai costituita, e la frase non dice il contrario.
    PresenzaInSala::query()->where('seduta_id', $seduta->id)->whereNotIn('anagrafica_id', [$s->persone['rossi']->id, $s->persone['marco']->id, $s->persone['rita']->id])
        ->delete();
    $doc = app(ComposizioneDelVerbale::class)->componi($verbale->refresh(), ModelliDelVerbale::scelto($s->c, null))['documento'];
    $blocco = StrutturaDelVerbale::blocchi($doc)['costituzione:' . $seduta->id];
    expect($blocco)->toContain('non risulta costituita')->not->toContain('regolarmente costituita')->toContain('3 condòmini su 26');
});
