<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\Maggioranza;
use App\Services\Assemblee\Sala\ContiDellaSala;
use App\Services\Assemblee\Sala\VotazioniDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * La sala aperta (decisione 664, rilievi C44, C48, C52 e C53): il singolare con un condòmino solo; il messaggio della costituzione a ogni «Si vota» che dice
 * il punto; il mezzo della convocazione di ogni testa prima dell'apertura; i contitolari presenti tutti e due (l'entrata con `con`, la colonna `insieme`, il
 * registro, la lettura, il prospetto e il verbale che li nominano, l'uscita insieme); le dichiarazioni a verbale corrette e tolte finché il voto del punto
 * non è confermato, con la riga del diario.
 *
 * Che cosa non copre: la sala vista sul dispositivo (le prove della pagina e di `useSalaSenzaRete`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('con un condòmino solo il singolare: «1 condòmino», «servono 1 condòmino» (C44)', function () {
    expect(ContiDellaSala::condomini(1))->toBe('1 condòmino')
        ->and(ContiDellaSala::condomini(0))->toBe('0 condòmini')
        ->and(ContiDellaSala::condomini(4))->toBe('4 condòmini')
        ->and(VotazioniDellaSala::regolaDellaMaggioranza(Maggioranza::QUATTRO_QUINTI->value, 'prima', 1, 100000000))
        ->toBe('servono 1 condòmino e 800,00 millesimi (art. 1117-ter c.c.)');
});

it('«Si vota» con l\'assemblea non costituita: il messaggio dice il punto, con i conti e chi decide (C52)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'gallo']);

    $voto = fn (int $numero) => SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id,
        'punto_id' => $a->punti()->where('numero', $numero)->value('id')], '2026-12-15 19:00'));
    $primo = $voto(1);
    $terzo = $voto(3);

    expect($primo['messaggio'])->toMatch('/^Punto 1: l\'assemblea non risulta costituita \(2 su 26 · [\d.,]+ su 1\.000,00; servono 9 e 333,34\)\. Decide il presidente\.$/')
        ->and($terzo['messaggio'])->toStartWith('Punto 3: l\'assemblea non risulta costituita (2 su 26 · ')
        ->and($terzo['messaggio'])->not->toBe($primo['messaggio']);
});

it('prima di «Apri l\'assemblea» la sala dice per ogni testa con che cosa è convocata; i comproprietari con un avviso ciascuno «una a testa» (C48)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    \App\Models\Assemblee\ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['rita']->id)->update(['mezzo' => 'pec']);

    $teste = collect(app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['teste']);
    $di = fn (string $chi) => $teste->first(fn ($t) => in_array($s->persone[$chi]->id, $t['persone'], true))['convocato_con'];
    expect($di('rossi'))->toBe('raccomandata')
        ->and($di('rita'))->toBe('PEC')
        ->and($di('marco'))->toBe('raccomandata, una a testa')
        ->and($di('gallo'))->toBeNull();
});

it('i comproprietari presenti tutti e due: chi rappresenta l\'unità entra con l\'altro, presente senza voto; la sala conta una testa; escono insieme (C53)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $marco = ['anagrafica_id' => $s->persone['marco']->id];
    $prima = ViaRoma12::ultimaRiga();

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $marco, 'come' => 'in_sala',
        'con' => [$s->persone['anna']->id]], '2026-12-15 18:50', 0));

    $riga = \App\Models\Assemblee\PresenzaInSala::query()->where('seduta_id', $seduta->id)->sole();
    expect($riga->anagrafica_id)->toBe($s->persone['marco']->id)
        ->and($riga->insieme)->toBe([['anagrafica_id' => $s->persone['anna']->id, 'nome' => 'Russo Anna']])
        ->and($riga->rappresenta)->toBe([$s->unita['5']->id]);
    expect(ViaRoma12::righe($prima)->last()->riepilogo)
        ->toBe('Assemblea ordinaria del 15/12/2026: Russo Marco entra in sala alle 18:50, con Russo Anna, in comproprietà, presente senza voto');

    // Una testa sola, e la lettura della sala porta chi è entrato insieme.
    $sala = app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['barra']['barra'])->toStartWith('1 su 26 · ')
        ->and($sala['presenze'][0]['insieme'])->toBe([['anagrafica_id' => $s->persone['anna']->id, 'nome' => 'Russo Anna']]);

    // Il verbale e il prospetto delle presenze li nominano tutti e due.
    $verbale = app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perIlVerbale($a->refresh());
    expect(\App\Services\Assemblee\Verbale\ComposizioneDelVerbale::presentiSenzaVoto($verbale['sedute'][1]['presenze'], (array) $seduta->fotografia))
        ->toBe(['Russo Marco rappresenta l\'Int. 5; è presente senza voto anche Russo Anna, in comproprietà.']);

    $prospetto = collect(collect(app(\App\Services\Assemblee\Sala\ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['presenze'])->first();
    expect($prospetto['nota'])->toContain('con Russo Anna, in comproprietà, presente senza voto');

    // Escono con la testa: l'uscita di chi rappresenta chiude la riga, con chi era entrato insieme.
    $prima = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $marco], '2026-12-15 19:30', 1));
    expect($riga->refresh()->uscita_il)->not->toBeNull()
        ->and(ViaRoma12::righe($prima)->last()->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026: Russo Marco esce alle 19:30, con Russo Anna');
});

it('con l\'entrata vanno solo le altre persone della stessa testa (C53)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['marco']->id],
        'come' => 'in_sala', 'con' => [$s->persone['rossi']->id]], '2026-12-15 18:50')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Con chi entra vanno solo gli altri comproprietari della stessa unità: Rossi Mario non lo è.');
});

/** Via Roma 12 con l'Int. 99 della sola Russo Anna, che è anche comproprietaria dell'Int. 5 con Russo Marco: due teste per lei. */
function saConAnnaDueTeste($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $s->unitaSeManca('99');
    \Illuminate\Support\Facades\DB::table('quote_tabella')->insert(['tabella_id' => $s->tabelle['Proprietà']->id, 'immobile_id' => $s->unita['99']->id, 'valore' => 27.75,
        'created_at' => now(), 'updated_at' => now()]);
    $s->titolare('99', 'anna');
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    return [$s, $a, SalaDiViaRoma12::apriLaSeconda($t, $s, $a)];
}

it('chi ha anche un\'unità sua non entra «insieme», senza voto: entra da sé, con la sua testa (revisione del 22/11, alta)', function () {
    [$s, $a, $seduta] = saConAnnaDueTeste($this);
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['marco']->id],
        'come' => 'in_sala', 'con' => [$s->persone['anna']->id]], '2026-12-15 18:50')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Russo Anna ha anche un\'unità sua: entra da sé, con «Entra», e vota per la sua testa.');
});

it('chi entra con un contitolare lo trova nella testa in comune anche se ha una testa sua che viene prima per nome (revisione del 22/11, media)', function () {
    [$s, $a, $seduta] = saConAnnaDueTeste($this);
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['anna']->id],
        'come' => 'in_sala', 'con' => [$s->persone['marco']->id]], '2026-12-15 18:50')])->json('risposte.0');
    expect($r['esito'])->toBe('applicato');
    $riga = \App\Models\Assemblee\PresenzaInSala::query()->where('seduta_id', $seduta->id)->sole();
    expect($riga->insieme)->toBe([['anagrafica_id' => $s->persone['marco']->id, 'nome' => 'Russo Marco']]);
});

it('il verbale composto nomina chi rappresenta l\'unità e il contitolare presente senza voto (C53)', function () {
    require_once __DIR__ . '/../Verbale/VerbaleDiViaRoma12.php';
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', 'gallo', ...SalaDiViaRoma12::altri(7)], '2026-12-15 18:44');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['marco']->id],
        'come' => 'in_sala', 'con' => [$s->persone['anna']->id]], '2026-12-15 18:45', 0));
    VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 1, '2026-12-15 19:30');

    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $doc = app(\App\Services\Assemblee\Verbale\ComposizioneDelVerbale::class)->componi($verbale, \App\Services\Assemblee\Verbale\ModelliDelVerbale::scelto($s->c, null))['documento'];
    expect(\App\Services\Assemblee\Verbale\StrutturaDelVerbale::blocchi($doc)['costituzione:' . $seduta->id])
        ->toContain('Russo Marco rappresenta l\'Int. 5; è presente senza voto anche Russo Anna, in comproprietà.');
});

it('la dichiarazione a verbale si corregge e si toglie finché il voto del punto non è confermato; il diario lo scrive; il verbale la riporta com\'è (C53)', function () {
    require_once __DIR__ . '/../Verbale/VerbaleDiViaRoma12.php';
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', 'gallo', 'marco', ...SalaDiViaRoma12::altri(7)], '2026-12-15 18:44');
    $punto = $a->punti()->where('numero', 1)->value('id');
    $dichiara = fn (string $testo, string $chi) => SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id,
        'punto_id' => $punto, 'anagrafica_id' => $s->persone[$chi]->id, 'testo' => $testo], '2026-12-15 19:00'))['dichiarazione_id'];
    $prima = $dichiara('Chiedo il preventivo scritto.', 'rita');
    $seconda = $dichiara('Da togliere.', 'gallo');
    $riga = ViaRoma12::ultimaRiga();

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('dichiarazione_cambia', ['dichiarazione_id' => $prima, 'testo' => 'Chiedo il preventivo scritto entro venerdì.'],
        '2026-12-15 19:05'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('dichiarazione_togli', ['dichiarazione_id' => $seconda], '2026-12-15 19:06'));

    expect(\App\Models\Assemblee\DichiarazioneAVerbale::query()->whereKey($prima)->value('testo'))->toBe('Chiedo il preventivo scritto entro venerdì.')
        ->and(\App\Models\Assemblee\DichiarazioneAVerbale::query()->whereKey($seconda)->exists())->toBeFalse()
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe([
            'Assemblea ordinaria del 15/12/2026: dichiarazione a verbale di Colombo Rita sul punto 1 corretta',
            'Assemblea ordinaria del 15/12/2026: dichiarazione a verbale di Gallo Enzo sul punto 1 tolta',
        ]);

    // Confermato il voto del punto, la dichiarazione resta com'è.
    $votazione = VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 1, '2026-12-15 19:30');
    VerbaleDiViaRoma12::conferma($this, $s, $a, $votazione, '2026-12-15 19:31');
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('dichiarazione_cambia', ['dichiarazione_id' => $prima, 'testo' => 'Ancora.'], '2026-12-15 19:40')])
        ->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Il voto del punto 1 è confermato: la dichiarazione resta com\'è.');

    // Il verbale la riporta com'è.
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $doc = app(\App\Services\Assemblee\Verbale\ComposizioneDelVerbale::class)->componi($verbale, \App\Services\Assemblee\Verbale\ModelliDelVerbale::scelto($s->c, null))['documento'];
    $blocchi = \App\Services\Assemblee\Verbale\StrutturaDelVerbale::blocchi($doc);
    expect($blocchi['dichiarazione:' . $prima])->toContain('Chiedo il preventivo scritto entro venerdì.')
        ->and(array_key_exists('dichiarazione:' . $seconda, $blocchi))->toBeFalse();
});

it('le due versioni di una presenza dicono lo stato senza genere (rilievo C59): «mai in sala in questa seduta», «mai in sala», «fuori dalle 19:20»', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $c30 = ['anagrafica_id' => $s->persone['c30']->id];
    $quella = fn (array $gesto) => SalaDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0.conflitto.quella');

    // Esce chi non è mai entrato, partendo da una versione che non c'è.
    expect($quella(SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $c30], '2026-12-15 18:50', 2)))
        ->toBe('Condomino 30: mai in sala in questa seduta');
    // Entra, partendo da una versione che non c'è, chi non è mai entrato.
    expect($quella(SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $c30, 'come' => 'in_sala'], '2026-12-15 18:51', 2)))
        ->toBe('Condomino 30: mai in sala');
    // Entra e poi esce: un'entrata da una versione vecchia trova «fuori dalle 19:20».
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30'], '2026-12-15 19:00');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $c30], '2026-12-15 19:20', 1));
    expect($quella(SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $c30, 'come' => 'in_sala'], '2026-12-15 19:30', 1)))
        ->toBe('Condomino 30: fuori dalle 19:20');
});
