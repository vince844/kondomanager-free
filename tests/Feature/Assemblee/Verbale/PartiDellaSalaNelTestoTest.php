<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ParteDelVerbale;
use App\Services\Assemblee\Verbale\CifreDellaSala;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le parti «Dati della sala» nel testo corretto a mano (V3):
 *
 * - un nome cambiato senza toccare le cifre («Gallo Enzo» al posto di «Colombo Rita» fra i contrari del punto 4) è un avviso leggero, che non ferma la stampa;
 * - la parte dei voti tolta dal testo torna al suo posto con «Aggiorna le parti segnate», come l'avviso promette: dopo il titolo e la discussione del punto;
 * - i comproprietari: il nome di chi vota per la testa viene dalle persone della testa, non dalla stringa spezzata sulla «e».
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** @return array{0: array<string, mixed>, 1: int} */
function pdsIntero($verbale): array
{
    $p = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();

    return [(array) $p->documento, (int) $p->revisione];
}

it('un nome cambiato a mano nella parte dei voti, senza toccare le cifre: un avviso leggero che lo dice', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    [$doc, $rev] = pdsIntero($verbale);
    $nuovo = json_decode(str_replace('Colombo Rita', 'Gallo Enzo', json_encode($doc, JSON_UNESCAPED_UNICODE)), true);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:40');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', $nuovo, '2026-12-15 21:39', $rev)])->assertJsonPath('risposte.0.esito', 'applicato');

    $c = app(CifreDellaSala::class)->controlla($verbale->refresh());
    expect($c['avvisi'])->toBe([])->and($c['sala_cambiata'])->toBeNull()
        ->and(array_column($c['a_mano'], 'frase'))->toContain('Nel punto 4 la parte dei voti è cambiata a mano: i nomi non sono quelli registrati. «Aggiorna le parti '
            . 'segnate» la rifà dai voti.');
    // Il testo che il programma aveva scritto resta nella parte, per l'etichetta «cambiata a mano».
    $blocco = collect((array) $doc['content'])->first(fn ($n) => ($n['type'] ?? '') === 'datiDellaSala' && str_starts_with((string) $n['attrs']['chiave'], 'voto:'));
    expect($blocco['attrs']['originale'])->not->toBe('');
});

it('la parte dei voti tolta dal testo torna al suo posto con «Aggiorna le parti segnate»', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    [$doc, $rev] = pdsIntero($verbale);
    $doc['content'] = array_values(array_filter($doc['content'], fn ($n) => ($n['attrs']['chiave'] ?? null) !== 'voto:' . $v[4]));
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:40');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', $doc, '2026-12-15 21:39', $rev)]);
    expect(array_column(app(CifreDellaSala::class)->controlla($verbale->refresh())['avvisi'], 'frase'))
        ->toContain('Nel punto 4 manca la parte con i voti: «Aggiorna le parti segnate» la rimette.');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.aggiorna_sala', [$s->c, $a, $verbale]))->assertSessionHasNoErrors();
    [$dopo] = pdsIntero($verbale);
    $voci = array_map(fn ($n) => ($n['type'] ?? '') === 'datiDellaSala' ? (string) $n['attrs']['chiave'] : StrutturaDelVerbale::testo((array) $n), (array) $dopo['content']);
    $titolo = array_values(array_filter(array_keys($voci), fn ($i) => str_starts_with($voci[$i], '4. ')))[0];
    $voto = array_search('voto:' . $v[4], $voci, true);
    $cinque = array_values(array_filter(array_keys($voci), fn ($i) => str_starts_with($voci[$i], '5. ')))[0];
    expect($voto)->toBeGreaterThan($titolo)->toBeLessThan($cinque)
        ->and(app(CifreDellaSala::class)->controlla($verbale->refresh())['avvisi'])->toBe([]);
});

it('i comproprietari: il nome di chi rappresenta la testa viene dalle sue persone, non dalla stringa spezzata sulla «e»', function () {
    expect(ComposizioneDelVerbale::rappresentante('Ferro e Acciaio S.r.l. e Rossi Mario', 'Ferro e Acciaio S.r.l.', ['Ferro e Acciaio S.r.l.', 'Rossi Mario']))
        ->toBe('Ferro e Acciaio S.r.l., rappresentante anche di Rossi Mario')
        ->and(ComposizioneDelVerbale::rappresentante('Russo Anna e Russo Marco', 'Russo Marco'))->toBe('Russo Marco, rappresentante anche di Russo Anna');
});

it('chi entra con chi rappresenta l\'unità (rilievo B3): la frase ha il verbo, e «in comproprietà» al posto di «contitolare»', function () {
    $fotografia = ['unita' => [5 => ['nome' => 'Int. 5']]];
    $presenza = fn (array $insieme) => [['nome' => 'Russo Marco', 'rappresenta' => [5], 'insieme' => $insieme]];
    expect(ComposizioneDelVerbale::presentiSenzaVoto($presenza([['anagrafica_id' => 1, 'nome' => 'Russo Anna']]), $fotografia))
        ->toBe(['Russo Marco rappresenta l\'Int. 5; è presente senza voto anche Russo Anna, in comproprietà.'])
        ->and(ComposizioneDelVerbale::presentiSenzaVoto($presenza([['anagrafica_id' => 1, 'nome' => 'Russo Anna'], ['anagrafica_id' => 2, 'nome' => 'Russo Luca']]),
            $fotografia))
        ->toBe(['Russo Marco rappresenta l\'Int. 5; sono presenti senza voto anche Russo Anna e Russo Luca, in comproprietà.']);
});

it('il punto della sola scala: prima del voto la frase di chi vota e su quanti; «Sul rendiconto: si vota» con la minuscola (rilievo B3)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $ascensore = \App\Models\Tabella::create(['condominio_id' => $s->c->id, 'nome' => 'Ascensore B', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (range(30, 35) as $i) {
        $s->unita[(string) $i]->forceFill(['scala_id' => $scala->id])->saveQuietly();
        \Illuminate\Support\Facades\DB::table('quote_tabella')->insert(['tabella_id' => $ascensore->id, 'immobile_id' => $s->unita[(string) $i]->id, 'valore' => 40,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [
        ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
        ['testo' => 'Funi dell\'ascensore della scala B', 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'ambito' => 'scala', 'scala_id' => $scala->id,
            'tabella_id' => $ascensore->id],
    ]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'c30', 'c31', 'c32', 'c33']);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 1),
        'numero' => 1, 'oggetto' => 'rendiconto'], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $r['votazione_id']], '2026-12-15 19:00'));
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $r['votazione_id'], '2026-12-15 19:01');
    VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 2, '2026-12-15 19:10');

    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, \App\Services\Assemblee\Verbale\ModelliDelVerbale::scelto($s->c, null))['documento']);
    $frase = 'Sul punto votano i soli condòmini della scala B: 6 partecipanti per 240,00 millesimi.';
    expect($testo)->toContain('Sul rendiconto: si vota alle ore 19:00')
        ->toContain($frase)
        ->toContain('Si vota alle ore 19:10: intervengono 4 condòmini per 160,00 millesimi')
        ->not->toContain('Sul rendiconto: Si vota')
        ->and(mb_strpos($testo, $frase))->toBeLessThan(mb_strpos($testo, 'Si vota alle ore 19:10'))
        // La frase della parte una volta sola: il rendiconto è di tutto il condominio.
        ->and(mb_substr_count($testo, 'Sul punto votano'))->toBe(1);
});
