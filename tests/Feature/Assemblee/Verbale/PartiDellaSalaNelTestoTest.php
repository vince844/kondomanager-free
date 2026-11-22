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
