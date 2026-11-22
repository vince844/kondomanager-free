<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * La composizione del verbale (V2): dal modello (il testo del programma o uno dello studio, con i campi riempiti) e dai dati della sala, le frasi della bozza
 * approvata, ognuna con i dati in una parte «Dati della sala» con la sua chiave: la convocazione, la costituzione al primo voto («come dall'elenco in calce»),
 * per ogni punto il titolo, la discussione scritta in sala, il voto con favorevoli, contrari, astenuti e chi non ha votato con i nomi e i millesimi, il
 * conflitto d'interessi, le note del presidente, l'esito; le dichiarazioni con il nome; la chiusura. La riunione sospesa chiude con la sospensione, la
 * prosecuzione apre con «prosegue l'assemblea del …»; un verbale per riunione.
 *
 * Che cosa non copre: le cifre che non tornano (CifreDellaSalaTest), il testo nel PDF (le stampe).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function cdvTesto(array $documento): string
{
    return StrutturaDelVerbale::testo($documento);
}

it('il verbale della riunione tenuta: l\'apertura dal modello, la convocazione, la costituzione al primo voto, la chiusura', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $doc = app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento'];
    $testo = cdvTesto($doc);

    expect($testo)->toContain('Il giorno 15 dicembre 2026, alle ore 18:42, presso Sala condominiale, Via Roma 12, si è riunita in seconda convocazione, essendo andata '
        . 'deserta la prima del 14 dicembre 2026, l\'assemblea ordinaria dei condòmini del Condominio Via Roma 12.')
        ->toContain('Assume la presidenza Rossi Mario; funge da segretario l\'amministratore.')
        ->toContain('L\'assemblea è stata convocata con avvisi spediti il 5 dicembre 2026, per raccomandata.')
        ->toContain('Sono presenti, di persona o per delega, 11 condòmini su 26, per 494,25 millesimi su 1.000, come dall\'elenco in calce.')
        ->toContain('Esauriti gli argomenti all\'ordine del giorno, il presidente dichiara chiusa l\'assemblea alle ore 20:55.')
        ->toContain('Letto, approvato e sottoscritto.');
    expect(array_keys(StrutturaDelVerbale::blocchi($doc)))->toContain('convocazione:' . $a->id, 'costituzione:' . $seduta->id);
    expect(StrutturaDelVerbale::valida($doc))->toBe($doc);
});

it('ogni punto: il titolo, la discussione scritta in sala, il voto con i nomi e i millesimi, chi non ha votato, il conflitto, la nota, la dichiarazione, l\'esito', function () {
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $punto4 = VerbaleDiViaRoma12::punto($a, 4);
    \App\Models\Assemblee\ParteDelVerbale::create(['verbale_id' => $verbale->id, 'parte' => 'punto:' . $punto4, 'punto_id' => $punto4, 'revisione' => 1,
        'documento' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('L\'amministratore illustra i tre preventivi ricevuti.')])]);

    $doc = app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento'];
    $blocchi = StrutturaDelVerbale::blocchi($doc);
    $testo = cdvTesto($doc);

    expect($testo)->toContain('4. Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00' . "\n" . 'L\'amministratore illustra i tre preventivi ricevuti.')
        ->and($blocchi['voto:' . $v[4]])->toContain('Si vota alle ore 19:44: intervengono 11 condòmini per 531,50 millesimi; servono la maggioranza degli intervenuti e 500,00 millesimi (art. 1136 c. 4 c.c.).')
        ->toContain('Favorevoli: 8 condòmini per 343,75 millesimi (Rossi Mario 65,00, Gallo Enzo 75,00, Bianchi Anna 65,00, Condomino 30 27,75, Condomino 31 27,75, '
            . 'Condomino 32 27,75, Condomino 33 27,75, Condomino 34 27,75).')
        ->toContain('Contrari: 1 condòmino per 90,00 millesimi (Colombo Rita 90,00).')
        ->toContain('Astenuti: 1 condòmino per 27,75 millesimi (Condomino 35 27,75).')
        ->toContain('Non hanno votato: 1 condòmino per 70,00 millesimi (Russo Anna e Russo Marco 70,00).')
        ->toContain('Condomino 30 dichiara di essere in conflitto d\'interessi: È il titolare della prima impresa.')
        ->toContain('Il presidente annota su chi non ha votato: Russo Marco era in sala e non ha espresso il voto.')
        ->toContain('La proposta è respinta.')
        ->and($blocchi['dichiarazione:' . \App\Models\Assemblee\DichiarazioneAVerbale::query()->value('id')])
        ->toBe('Rossi Mario chiede che sia messo a verbale: «I preventivi sono arrivati il giorno prima dell\'assemblea.»')
        ->and($blocchi['voto:' . $v[1]])->toContain('Contrari: 1 condòmino per 90,00 millesimi (Colombo Rita 90,00).')->toContain('La proposta è approvata.')
        ->and($blocchi['voto:' . $v[6]])->toBe('Il punto è trattato senza voto.');
});

it('il modello dello studio: i due testi con i campi riempiti, e il nome copiato', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $modello = app(ModelliDelVerbale::class)->salva(null, ['nome' => 'Verbale ordinario dello studio', 'condominio_id' => null,
        'prima' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Oggi [giorno] alle [ora], a [luogo], l\'assemblea [tipo] del [condominio] in [convocazione]. Presiede [presidente], verbalizza [segretario].')]),
        'dopo' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Chiusa alle [ora_di_chiusura].')])], $s->admin);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $esito = app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, $modello->id));

    expect(cdvTesto($esito['documento']))->toStartWith('Oggi 15 dicembre 2026 alle 18:42, a Sala condominiale, Via Roma 12, l\'assemblea ordinaria del Condominio Via Roma 12 '
        . 'in seconda convocazione, essendo andata deserta la prima del 14 dicembre 2026. Presiede Rossi Mario, verbalizza l\'amministratore.')
        ->toEndWith('Chiusa alle 20:55.')
        ->and($esito['modello'])->toBe(['id' => $modello->id, 'nome' => 'Verbale ordinario dello studio'])
        ->and($esito['impronta_sala'])->toHaveLength(64);
});

it('le parti «Dati della sala» rifatte da adesso hanno la stessa impronta finché la sala non cambia', function () {
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $composizione = app(ComposizioneDelVerbale::class);
    $prima = $composizione->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['impronta_sala'];
    expect($composizione->improntaDiAdesso($verbale))->toBe($prima);

    // Un voto corretto dopo la chiusura (§0.4 (5): fino alla registrazione): la sala è cambiata.
    \App\Models\Assemblee\VotoInSala::query()->where('votazione_id', $v[4])->where('nome', 'Condomino 35')->update(['voto' => 'favorevole']);
    app(\App\Services\Assemblee\Sala\VotazioniDellaSala::class)->ricalcola(\App\Models\Assemblee\VotazioneDelPunto::query()->findOrFail($v[4]));
    expect($composizione->improntaDiAdesso($verbale))->not->toBe($prima);
});

it('la riunione sospesa chiude con la sospensione e i punti che restano; la prosecuzione apre con «prosegue l\'assemblea del …»: un verbale per riunione', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto'], ['testo' => 'Conferma dell\'amministratore', 'materia' => 'nomina'],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a->forceFill(['tabella_id' => $s->tabelle['Proprietà']->id])->saveQuietly();
    $a = SalaDiViaRoma12::convoca($a->refresh(), $s);
    $martedi = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(10)]);
    VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 1, '2026-12-15 19:00');
    $giovedi = SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->where('numero', 2)->firstOrFail();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $martedi->id, 'prosegue_seduta_id' => $giovedi->id], '2026-12-15 21:30'));

    $n42 = VerbaliDellAssemblea::perLaSeduta($martedi->refresh(), $s->admin);
    $testo = cdvTesto(app(ComposizioneDelVerbale::class)->componi($n42, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toContain('1. Rendiconto 2025')->not->toContain('2. Conferma dell\'amministratore')
        ->toContain('Alle ore 21:30 il presidente sospende l\'assemblea, che prosegue giovedì 17 dicembre 2026 alle ore 18:30, presso Sala condominiale, Via Roma 12, '
            . 'come indicato nell\'avviso di convocazione, per i punti 2 e 3.')
        ->not->toContain('Esauriti gli argomenti');

    ConvocazioneDiViaRoma12::oggi('2026-12-17 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $giovedi->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:41'));
    ConvocazioneDiViaRoma12::oggi('2026-12-17 21:00');
    $n43 = VerbaliDellAssemblea::perLaSeduta($giovedi->refresh(), $s->admin);
    expect($n43->id)->not->toBe($n42->id);
    $testo = cdvTesto(app(ComposizioneDelVerbale::class)->componi($n43, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toStartWith('Il giorno 17 dicembre 2026, alle ore 18:41, presso Sala condominiale, Via Roma 12, prosegue l\'assemblea ordinaria del 15 dicembre 2026 '
        . 'dei condòmini del Condominio Via Roma 12, sospesa alle ore 21:30, per i punti 2 e 3 dell\'ordine del giorno.')
        ->not->toContain('1. Rendiconto 2025');
});
