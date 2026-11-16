<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I giorni sulla riga del conguaglio sono quelli di ogni ruolo. Chi ha due ruoli sulla stessa unità (l'inquilina tutto l'anno che la compra a marzo)
 * ha i giorni da proprietaria, che non si sommano con quelli da inquilina; chi ha un ruolo solo ha il numero dei giorni. Nella scheda, nel consuntivo
 * per tabella, nel conguaglio per persona e nel foglio della persona; i giorni sono parole, e le cifre sono le stesse in tutte le letture.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`) con Nora inquilina di U2, il 30 % delle voci: Bianchi vende U2 a Nora dal
 * 01/03/2026, con le righe scritte a mano una dopo l'altra (nessun giorno scoperto). Il 2026 ha 365 giorni: Bianchi 01/01–28/02 = 59, Nora
 * proprietaria 01/03–31/12 = 306.
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\FogliDelConguaglio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

/** Via Verdi con Nora inquilina di U2 (fino a `$inquilinaFino`, o tutto l'anno) che compra U2 da Bianchi dal 01/03/2026. */
function cgrInquilinaCheCompra($test, ?string $inquilinaFino = null): array
{
    $s = cpcViaVerdi($test, ['inquilino' => ['U2', 'Nora', 30, '2020-01-01', $inquilinaFino]]);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-02-28']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Nora']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-03-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $test->travelTo('2027-01-10 10:00:00');

    return $s;
}

/** La riga di una persona su U2 in un elenco di righe con `anagrafica_id`. */
function cgrRiga(array $righe, array $s, string $chi): array
{
    return collect($righe)->first(fn ($r) => ! ($r['subtotale'] ?? false) && (int) $r['anagrafica_id'] === (int) $s['persone'][$chi]->id) ?? [];
}

/** Le quattro letture del caso: la scheda, la stampa del conguaglio per persona, il consuntivo per tabella e il foglio di Nora e di Bianchi, con i testi delle stampe. */
function cgrLetture(array $s): array
{
    $k = app(ConguaglioDelRendiconto::class);
    $e = $s['e']->fresh();
    $letto = $k->leggi($e, [], true);
    $fuori = ['commissioni_senza_voce' => ['consuntivo' => 0]];
    $scheda = $k->righe($s['c'], $e, $letto, $fuori);
    $stampa = $k->righe($s['c'], $e, $letto, $fuori, true);
    $tabelle = $k->perTabella($s['c'], $e, $letto);
    $fogli = app(FogliDelConguaglio::class);
    $foglio = fn (string $chi) => $fogli->datiDelFoglio($s['c'], $e, $letto, $s['persone'][$chi]->id);

    return [
        'giorni' => $k->giorniDiTitolarita($e, [$s['unita']['U2']->id]),
        'scheda' => $scheda['gestioni'][0]['righe'],
        'stampa' => $stampa['gestioni'][0]['righe'],
        'tabelle' => $tabelle['gestioni'][0]['righe'],
        'foglio_nora' => $foglio('Nora')['gestioni'][0]['unita'][0],
        'foglio_bianchi' => $foglio('Bianchi')['gestioni'][0]['unita'][0],
        'testo_stampa' => vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $e, 'conguaglio' => $stampa])->render()),
        'testo_tabelle' => vrmTesto(view('pdf.gestionale.consuntivo_per_tabella', ['condominio' => $s['c'], 'esercizio' => $e, 'consuntivo' => $tabelle])->render()),
        'testo_foglio_nora' => vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $e, 'foglio' => $foglio('Nora')])->render()),
        'testo_foglio_bianchi' => vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $e, 'foglio' => $foglio('Bianchi')])->render()),
    ];
}

it('l\'inquilina tutto l\'anno che compra a marzo: i giorni dei due ruoli non si sommano, e la sua riga dice tutti e due i ruoli in tutte e quattro le letture', function () {
    $s = cgrInquilinaCheCompra($this);
    $l = cgrLetture($s);
    $u2 = $s['unita']['U2']->id;
    $giorni = fn (array $r) => ['giorni' => array_key_exists('giorni', $r) ? $r['giorni'] : 'assente', 'giorni_per_ruolo' => $r['giorni_per_ruolo'] ?? 'assente'];
    // Con più ruoli la riga li dice tutti, anche quello dell'anno intero: la sua spesa comprende la parte dell'inquilino per tutto l'anno.
    $nora = ['giorni' => null, 'giorni_per_ruolo' => [['ruolo' => 'inquilino', 'giorni' => 365], ['ruolo' => 'proprietario', 'giorni' => 306]]];
    $bianchi = ['giorni' => 59, 'giorni_per_ruolo' => []];

    // Per ruolo: Nora 365 da inquilina (dal 2020) e 306 da proprietaria, non la somma fermata all'anno; Bianchi 59 da proprietaria.
    expect($l['giorni'][$s['persone']['Nora']->id][$u2] ?? null)->toBe(['inquilino' => 365, 'proprietario' => 306])
        ->and($l['giorni'][$s['persone']['Bianchi']->id][$u2] ?? null)->toBe(['proprietario' => 59])
        // La scheda, la stampa per persona, il consuntivo per tabella, il foglio.
        ->and($giorni(cgrRiga($l['scheda'], $s, 'Nora')))->toBe($nora)
        ->and($giorni(cgrRiga($l['scheda'], $s, 'Bianchi')))->toBe($bianchi)
        ->and($giorni(cgrRiga($l['stampa'], $s, 'Nora')))->toBe($nora)
        ->and($giorni(cgrRiga($l['tabelle'], $s, 'Nora')))->toBe($nora)
        ->and($giorni(cgrRiga($l['tabelle'], $s, 'Bianchi')))->toBe($bianchi)
        ->and($giorni($l['foglio_nora']))->toBe($nora)
        ->and($giorni($l['foglio_bianchi']))->toBe($bianchi)
        // Le parole delle stampe: il ruolo accanto ai giorni di chi ne ha due; per chi ne ha uno, il solo numero.
        ->and($l['testo_stampa'])->toContain('Nora 365 giorni come inquilino, 306 giorni come proprietario')
        ->and($l['testo_tabelle'])->toContain('Nora (365 giorni come inquilino, 306 giorni come proprietario)')->toContain('Bianchi (59 giorni)')
        ->and($l['testo_foglio_nora'])->toContain('Int. U2 (nell\'esercizio: 365 giorni come inquilino, 306 giorni come proprietario)')
        ->and($l['testo_foglio_bianchi'])->toContain('Int. U2 (59 giorni nell\'esercizio)')
        // La sigla del ruolo nel consuntivo per tabella: tutti i ruoli della riga, nello stesso ordine dei giorni.
        ->and(cgrRiga($l['tabelle'], $s, 'Nora')['ruolo'])->toBe('I, P')
        ->and(cgrRiga($l['tabelle'], $s, 'Bianchi')['ruolo'])->toBe('P');
});

it('l\'inquilina fino al 28/02 che compra dal 01/03: tutti e due i ruoli per una parte dell\'anno, e la riga li dice tutti e due, nell\'ordine in cui cominciano', function () {
    $s = cgrInquilinaCheCompra($this, '2026-02-28');
    $l = cgrLetture($s);
    $nora = ['giorni' => null, 'giorni_per_ruolo' => [['ruolo' => 'inquilino', 'giorni' => 59], ['ruolo' => 'proprietario', 'giorni' => 306]]];

    expect($l['giorni'][$s['persone']['Nora']->id][$s['unita']['U2']->id] ?? null)->toBe(['inquilino' => 59, 'proprietario' => 306])
        ->and(array_intersect_key(cgrRiga($l['scheda'], $s, 'Nora'), $nora))->toBe($nora)
        ->and(array_intersect_key(cgrRiga($l['tabelle'], $s, 'Nora'), $nora))->toBe($nora)
        ->and(array_intersect_key($l['foglio_nora'], $nora))->toBe($nora)
        ->and($l['testo_stampa'])->toContain('Nora 59 giorni come inquilino, 306 giorni come proprietario')
        ->and($l['testo_foglio_nora'])->toContain('(nell\'esercizio: 59 giorni come inquilino, 306 giorni come proprietario)');
});

it('chi ha un ruolo solo ha il numero dei giorni, e chi ha avuto l\'unità tutto l\'anno non ne ha', function () {
    $s = cgrInquilinaCheCompra($this);
    $l = cgrLetture($s);

    // Neri (U1) e Verdi (U3) tutto l'anno: nessun giorno. Il subtotale e le righe da altre gestioni non ne hanno.
    expect(array_intersect_key(cgrRiga($l['scheda'], $s, 'Neri'), ['giorni' => 0, 'giorni_per_ruolo' => 0]))->toBe(['giorni' => null, 'giorni_per_ruolo' => []])
        ->and(array_intersect_key(cgrRiga($l['tabelle'], $s, 'Verdi'), ['giorni' => 0, 'giorni_per_ruolo' => 0]))->toBe(['giorni' => null, 'giorni_per_ruolo' => []])
        ->and(ConguaglioDelRendiconto::giorniDellaRiga(['proprietario' => 181], 365))->toBe(['giorni' => 181, 'giorni_per_ruolo' => []])
        ->and(ConguaglioDelRendiconto::giorniDellaRiga(['proprietario' => 365], 365))->toBe(['giorni' => null, 'giorni_per_ruolo' => []])
        ->and(ConguaglioDelRendiconto::giorniDellaRiga(['inquilino' => 365, 'proprietario' => 365], 365))->toBe(['giorni' => null, 'giorni_per_ruolo' => []])
        ->and(ConguaglioDelRendiconto::giorniDellaRiga(['proprietario' => 59, 'inquilino' => 365], 365))
        ->toBe(['giorni' => null, 'giorni_per_ruolo' => [['ruolo' => 'proprietario', 'giorni' => 59], ['ruolo' => 'inquilino', 'giorni' => 365]]])
        ->and(ConguaglioDelRendiconto::giorniDellaRiga([], 365))->toBe(['giorni' => null, 'giorni_per_ruolo' => []])
        ->and(\App\Services\Gestionale\ParoleDelConguaglio::giorni(1, []))->toBe('1 giorno')
        ->and(\App\Services\Gestionale\ParoleDelConguaglio::giorni(null, [['ruolo' => 'nuda_proprietario', 'giorni' => 1]]))->toBe('1 giorno come nudo proprietario')
        ->and(\App\Services\Gestionale\ParoleDelConguaglio::giorni(null, []))->toBeNull();
});

it('la proprietaria che vende il 28/02 e resta inquilina: i ruoli in ordine del primo giorno, non in quello in cui le righe sono scritte né in quello alfabetico', function () {
    $s = cpcViaVerdi($this);
    // Nel database la riga da inquilina di Bianchi viene per prima, poi quella da proprietario di Rossi e quella da proprietaria di Bianchi: nel database e in
    // ordine alfabetico l'inquilino precede, nel tempo il proprietario (dal 2020) precede l'inquilino (dal 01/03/2026, senza un predecessore: per il motore
    // c'è da sempre). La riga segue il tempo.
    $vecchia = (array) DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->first();
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->delete();
    $riga = fn (string $chi, string $ruolo, string $dal, ?string $al) => DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id,
        'immobile_id' => $s['unita']['U2']->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al,
        'created_at' => now(), 'updated_at' => now()]);
    $riga('Bianchi', 'inquilino', '2026-03-01', null);
    $riga('Rossi', 'proprietario', '2026-03-01', null);
    $riga('Bianchi', 'proprietario', substr((string) $vecchia['data_inizio'], 0, 10), '2026-02-28');
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $k = app(ConguaglioDelRendiconto::class);
    $letto = $k->leggi($s['e']->fresh(), [], true);
    $bianchi = cgrRiga($k->righe($s['c'], $s['e']->fresh(), $letto)['gestioni'][0]['righe'], $s, 'Bianchi');

    expect($k->giorniDiTitolarita($s['e']->fresh(), [$s['unita']['U2']->id])[$s['persone']['Bianchi']->id][$s['unita']['U2']->id] ?? null)
        ->toBe(['proprietario' => 59, 'inquilino' => 365])
        ->and($bianchi['giorni_per_ruolo'])->toBe([['ruolo' => 'proprietario', 'giorni' => 59], ['ruolo' => 'inquilino', 'giorni' => 365]])
        ->and(\App\Services\Gestionale\ParoleDelConguaglio::giorni($bianchi['giorni'], $bianchi['giorni_per_ruolo']))->toBe('59 giorni come proprietario, 365 giorni come inquilino')
        ->and(cgrRiga($k->perTabella($s['c'], $s['e']->fresh(), $letto)['gestioni'][0]['righe'], $s, 'Bianchi')['ruolo'])->toBe('P, I');
});

it('i giorni per ruolo sono parole: le stampe dicono le cifre della scheda, e la spesa di U2 è tutta fra Bianchi e Nora', function () {
    $s = cgrInquilinaCheCompra($this);
    $l = cgrLetture($s);
    $cifre = fn (array $r) => array_intersect_key($r, array_flip(['a', 'b', 'conguaglio', 'saldo']));

    // U2 ha 300/1000 di Pulizie 3.300,00 e Manutenzioni 900,00 = 1.260,00, fra Bianchi (59 giorni della parte del proprietario) e Nora (il resto): le stampe
    // dicono le cifre della scheda.
    expect(cgrRiga($l['scheda'], $s, 'Bianchi')['a'] + cgrRiga($l['scheda'], $s, 'Nora')['a'])->toBe(126000)
        ->and($cifre(cgrRiga($l['stampa'], $s, 'Nora')))->toBe($cifre(cgrRiga($l['scheda'], $s, 'Nora')))
        ->and(cgrRiga($l['tabelle'], $s, 'Nora')['totale'])->toBe(cgrRiga($l['scheda'], $s, 'Nora')['a']);
});
