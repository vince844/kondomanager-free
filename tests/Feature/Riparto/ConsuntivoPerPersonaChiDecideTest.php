<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il consuntivo per persona dove il programma sceglieva da solo fra esiti legittimi diversi: la spesa personale chiesta in parte, l'unità mista, i periodi
 * diversi di due piani sulla stessa voce, la spesa personale con la competenza su più giorni, il già versato dell'unità non usato dopo un passaggio, la
 * spesa personale di un giorno senza titolare, il cambio di titolare con giorni scoperti e senza un passaggio registrato, il piano con l'esercizio dedotto.
 *
 * I casi sono Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`: U1 Neri 500, U2 Bianchi 300 poi Rossi dal 01/07, U3 Verdi 200, Pulizie € 3.300,00 e
 * Manutenzioni € 900,00) e il caso del balcone (`Beta59313AdPersonamScenari.php`). Le cifre sono rifatte a mano nei commenti; positivo = debito.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\ControlliDelConguaglio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__.'/../Gestionale/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Interno 1 misto: Mario proprietario pieno 50, Nina nuda proprietaria 50, Ugo usufruttuario 50. */
function cpdMista(array $s): array
{
    $altri = [];
    foreach ([['Nina Neri', 'nuda_proprietario'], ['Ugo Gialli', 'usufruttuario']] as [$nome, $ruolo]) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(strtok($nome, ' ')).'-cpd'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.$s['c']->id), 0, 16))]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']['1']->id, 'tipologia' => $ruolo, 'quota' => 50,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $altri[strtolower(strtok($nome, ' '))] = $p;
    }

    return $altri;
}

/** La scheda «Conguaglio» del caso, dal servizio: le righe e le gestioni con le loro frasi. */
function cpdScheda(array $s): array
{
    $k = app(ConguaglioDelRendiconto::class);
    $letto = $k->leggi($s['e']->fresh());

    return ['letto' => $letto, 'righe' => $k->righe($s['c'], $s['e']->fresh(), $letto, ['commissioni_senza_voce' => ['consuntivo' => 0]])];
}

/** Le note della scheda sulle righe di un'unità. */
function cpdNote(array $scheda, int $iid): array
{
    $note = [];
    foreach ($scheda['gestioni'] as $g) {
        foreach ($g['righe'] as $r) {
            if (($r['immobile_id'] ?? null) === $iid) {
                array_push($note, ...$r['note']);
            }
        }
    }

    return $note;
}

// ─── Una spesa personale chiesta in parte da un piano ────────────────────────────────────────────────────────────────────────────────

it('una riga personale chiesta in parte da un piano: il resto è fra le spese personali non chieste, e le spese personali tornano con le anticipazioni', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    // La facciata comune € 500,00 e il balcone di Interno 1 € 100,00 nella stessa fattura; il piano chiede € 300,00 della fattura, la metà di ogni riga:
    // del balcone € 50,00. Il resto del balcone, € 50,00, è ancora da chiedere a Mario.
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 1, '300,00');
    b59apEmetti($this, $s, $piano, [1]);
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $m = $c['persone'][$s['g']->id][$s['persone']['mario']->id][$s['unita']['1']->id];

    // Mario: spese 250,00 (la metà della facciata); personali non chieste 50,00 (il balcone meno la parte chiesta); quote 125,00 (la parte comune delle sue
    // quote, la metà dei 250,00 comuni chiesti: la parte personale B non la conta). Conguaglio 250,00 + 50,00 − 125,00 = +175,00. Prima: personali 0, +125,00.
    expect([$m['a'], $m['personali']['non_chieste'], $m['b']['totale'], $m['conguaglio']])->toBe([25000, 5000, 12500, 17500]);
    // Il controllo per unità: personali non chieste 50,00 + chieste non emesse 0 = anticipazioni aperte 50,00.
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');
    expect($ca['esito'])->toBe('quadra')->and([$ca['sinistra'], $ca['destra']])->toBe([5000, 5000]);
});

it('il controllo delle spese personali non torna dove la riga personale non è né fra le non chieste né fra le chieste, e la riga dell\'unità lo dice', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    // Il citofono di Interno 1 è fra le anticipazioni aperte (€ 100,00); una riga del riparto scritta a mano dice che un piano l'ha chiesto, senza le rate.
    $piano = b59apPiano($this, $s, $fattura, 1, '100,00');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id'))->delete();
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    // Non chieste 0 + chieste non emesse 0 ≠ anticipazioni aperte 100,00: l'unità è nominata, con la cifra che manca.
    expect($ca['esito'])->toBe('non_quadra')->and($ca['nota'])->toContain('Int. 1')->toContain('€ 100,00');
});

it('una spesa personale chiesta da un piano con le rate non ancora emesse è ancora fra le anticipazioni aperte: il controllo la conta fra le chieste non emesse', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    // Il piano chiede il balcone € 100,00; nessuna rata emessa: la spesa resta fra le anticipazioni aperte dell'unità.
    b59apPiano($this, $s, $fattura, 1, '100,00');
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    // Non chieste 0 + chieste con rate non emesse 100,00 = anticipazioni aperte 100,00.
    expect($c['personali_per_unita'][$s['unita']['1']->id])->toMatchArray(['non_chieste' => 0, 'non_emesse' => 10000, 'aperte' => 10000])
        ->and($ca['esito'])->toBe('quadra')->and([$ca['sinistra'], $ca['destra']])->toBe([10000, 10000]);
});

// ─── L'unità mista ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('l\'unità mista: la spesa personale non chiesta va al proprietario pieno e alla nuda proprietaria dell\'altra metà, come nel piano da fatture', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase(['Mario Rossi' => 50]);
    $altri = cpdMista($s);
    b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $di = fn (Anagrafica $p) => $c['persone'][$s['g']->id][$p->id][$s['unita']['1']->id]['personali']['non_chieste'] ?? null;

    // Il balcone € 100,00: Mario 50 e Nina 50 (la nuda proprietà dell'altra metà); Ugo, usufruttuario, niente. Prima: Mario 100,00, Nina niente.
    expect([$di($s['persone']['mario']), $di($altri['nina'])])->toBe([5000, 5000])
        ->and($di($altri['ugo']) ?? 0)->toBe(0);
});

it('l\'unità mista: il già versato dell\'unità non usato va all\'usufruttuario e al proprietario pieno dell\'altra metà, non tutto al primo ruolo', function () {
    $s = cpcViaVerdi($this);
    // U2 mista: Bianchi proprietario pieno della metà, Nina nuda proprietaria e Ugo usufruttuario dell'altra metà.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['quota' => 50]);
    $altri = [];
    foreach ([['Nina', 'nuda_proprietario'], ['Ugo', 'usufruttuario']] as [$nome, $ruolo]) {
        $altri[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-cpdm'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.'cpdm'.$s['c']->id), 0, 16))]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $altri[$nome]->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => $ruolo, 'quota' => 50,
            'attivo' => true, 'data_inizio' => '2020-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $di = fn (int $aid) => (int) ($c['persone'][$s['g']->id][$aid][$s['unita']['U2']->id]['gia_versato_non_usato']['questo_esercizio'] ?? 0);

    // L'ordinaria comincia dall'usufruttuario (Ugo, 50) e unisce il gemello dell'altra metà, il proprietario pieno (Bianchi, 50): 150,00 e 150,00. Prima tutto a Ugo.
    expect([$di($s['persone']['Bianchi']->id), $di($altri['Ugo']->id), $di($altri['Nina']->id)])->toBe([15000, 15000, 0]);
});

// ─── I periodi della voce diversi fra due piani ──────────────────────────────────────────────────────────────────────────────────────

/** Due piani dell'esercizio con la stessa voce Pulizie: il periodo (01/01–30/06) sul primo, sul secondo, o su nessuno; con o senza il passaggio di U2. */
function cpdDuePiani($test, ?string $periodoSu, bool $passaggio = true, ?int $inquilino = null): array
{
    // Con `$inquilino` (la percentuale dell'inquilino sulle voci; 0: nessuna ripartizione per l'inquilino) Nora è inquilina di U2 fino al 30/06 e Olga dal
    // 01/07, righe scritte a mano una dopo l'altra.
    $s = $inquilino === null || $inquilino === 0 ? cpcViaVerdi($test) : cpcViaVerdi($test, ['inquilino' => ['U2', 'Nora', $inquilino, '2020-01-01', '2026-06-30']]);
    if ($inquilino !== null) {
        foreach ($inquilino === 0 ? [['Nora', '2020-01-01', '2026-06-30'], ['Olga', '2026-07-01', null]] : [['Olga', '2026-07-01', null]] as [$nome, $dal, $al]) {
            $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-cpd'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
                'codice_fiscale' => strtoupper(substr(md5($nome.$s['c']->id), 0, 16))]);
            $p->condomini()->syncWithoutDetaching([$s['c']->id]);
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'inquilino', 'quota' => 100,
                'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
            $s['persone'][$nome] = $p;
        }
    }
    cpcRateEIncassi($test, $s);
    if ($passaggio) {
        cpcPassaggio($test, $s);
    }
    $secondo = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Integrativo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-09-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $pivot1 = DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->value('id')
        ?? DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['pulizie']->id, 'importo' => 300000, 'created_at' => now(), 'updated_at' => now()]);
    $pivot2 = DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $secondo->id, 'conto_id' => $s['pulizie']->id, 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()]);
    if ($periodoSu !== null) {
        DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $periodoSu === 'primo' ? $pivot1 : $pivot2, 'dal' => '2026-01-01', 'al' => '2026-06-30', 'ordine' => 1,
            'created_at' => now(), 'updated_at' => now()]);
    }
    cpcSpese($s);

    return $s;
}

it('due piani dell\'esercizio con periodi diversi per la stessa voce, e un\'unità della voce che cambia titolare: la voce non si calcola, e la scheda dice perché', function () {
    $s = cpdDuePiani($this, 'primo');
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];
    $scheda = cpdScheda($s)['righe'];
    $gestione = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    $pulizie = collect($gestione['non_attribuite'])->firstWhere('conto_id', $s['pulizie']->id);

    // Pulizie € 3.300,00 non divise: né Bianchi né Rossi le hanno (prima Bianchi 990,00 con il periodo sul primo piano, 490,93 sul secondo). Manutenzioni sì.
    expect($g['non_attribuite'][$s['pulizie']->id] ?? null)->toBe(330000)
        ->and($g['periodi_diversi_perche'] ?? null)->toBe([$s['pulizie']->id => 'titolare'])
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['manutenzioni']->id] ?? 0)->toBeGreaterThan(0)
        ->and($pulizie['motivo'])->toBe('I piani «Preventivo 2026» e «Integrativo 2026» dividono la voce con periodi diversi, e un\'unità della voce ha cambiato titolare: '
            .'il programma non sceglie quale vale. Scrivi la competenza sulle fatture, o correggi i periodi del piano.');
});

/** Per conto: la spesa delle persone, le non attribuite, le uscite divise e le voci ferme della gestione del caso (il caso non ha già versato dell'unità). */
function cpdTerminiPerConto(array $c, array $s): array
{
    $g = $c['gestioni'][$s['g']->id];
    $t = [];
    foreach ($c['persone'][$s['g']->id] ?? [] as $perUnita) {
        foreach ($perUnita as $r) {
            foreach ($r['voci'] as $conto => $centesimi) {
                $t[$conto]['persone'] = ($t[$conto]['persone'] ?? 0) + $centesimi;
            }
        }
    }
    foreach (['non_attribuite', 'uscite', 'voci_ferme'] as $campo) {
        foreach ($g[$campo] ?? [] as $conto => $centesimi) {
            $t[$conto][$campo] = $centesimi;
        }
    }
    ksort($t);

    return array_map(fn (array $x) => ['persone' => $x['persone'] ?? 0, 'non_attribuite' => $x['non_attribuite'] ?? 0, 'uscite' => $x['uscite'] ?? 0,
        'voci_ferme' => $x['voci_ferme'] ?? 0], $t);
}

it('la voce ferma per i periodi diversi ha un campo suo con la cifra, accanto alle uscite divise: persone + non attribuite = uscite + voci ferme, per conto e per gestione', function () {
    $s = cpdDuePiani($this, 'primo');
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];
    $termini = cpdTerminiPerConto($c, $s);
    $somma = fn (string $k) => array_sum(array_column($termini, $k));

    // Pulizie € 3.300,00 ferme: fra le non attribuite, con il motivo, e fra le voci ferme, non fra le uscite divise; Manutenzioni € 900,00 divise.
    expect($g['voci_ferme'] ?? null)->toBe([$s['pulizie']->id => 330000])
        ->and(array_key_exists($s['pulizie']->id, $g['uscite']))->toBeFalse()
        ->and($g['uscite'])->toBe([$s['manutenzioni']->id => 90000])
        ->and(array_filter($termini, fn (array $x) => $x['persone'] + $x['non_attribuite'] !== $x['uscite'] + $x['voci_ferme']))->toBe([])
        ->and($somma('persone') + $somma('non_attribuite'))->toBe($somma('uscite') + $somma('voci_ferme'))
        ->and($somma('uscite') + $somma('voci_ferme'))->toBe(420000)
        // Il controllo per unità resta sulle voci divise: la voce ferma non lo fa dire «non quadra».
        ->and(ControlliDelConguaglio::perUnita($c)['esito'])->toBe('quadra');
});

it('senza una voce ferma il campo è vuoto, e le uscite sono tutte le voci, divise', function () {
    $s = cpdDuePiani($this, 'primo', false);
    $c = cpcLeggi($this, $s);
    $termini = cpdTerminiPerConto($c, $s);

    expect($c['gestioni'][$s['g']->id]['voci_ferme'] ?? null)->toBe([])
        ->and(array_filter($termini, fn (array $x) => $x['persone'] + $x['non_attribuite'] !== $x['uscite'] + $x['voci_ferme']))->toBe([])
        ->and($c['gestioni'][$s['g']->id]['uscite'])->toEqual([$s['pulizie']->id => 330000, $s['manutenzioni']->id => 90000]);
});

it('due piani con periodi diversi e una riga datata dentro l\'anno senza un predecessore: è il giorno in cui è stata scritta, non un cambio, e la voce si divide', function () {
    $s = cpdDuePiani($this, 'primo', false);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_inizio' => '2026-05-10']);
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];

    // Verdi tutto l'anno per il motore: 200/1000 di 3.300,00 = 660,00. Nessuna voce ferma.
    expect($g['voci_ferme'])->toBe([])
        ->and($g['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['voci'][$s['pulizie']->id] ?? 0)->toBe(66000);
});

it('due piani con periodi diversi e un cambio del solo inquilino, che nella voce non ha una parte: i periodi non cambiano la parte di nessuno, e la voce si divide', function () {
    $s = cpdDuePiani($this, 'primo', false, 0);
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];

    // Le voci sono tutte del proprietario: Bianchi tutto l'anno, 300/1000 di 3.300,00 = 990,00.
    expect($g['voci_ferme'])->toBe([])
        ->and($g['periodi_diversi'])->toBe([])
        ->and($g['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBe(99000);
});

it('due piani con periodi diversi e un cambio del solo inquilino, che nella voce ha una parte: la voce resta ferma, e il motivo dice che è cambiato l\'inquilino', function () {
    $s = cpdDuePiani($this, 'primo', false, 30);
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];
    $motivo = collect(collect(cpdScheda($s)['righe']['gestioni'])->firstWhere('id', $s['g']->id)['non_attribuite'])->firstWhere('conto_id', $s['pulizie']->id)['motivo'] ?? null;

    expect($g['voci_ferme'])->toBe([$s['pulizie']->id => 330000])
        ->and($g['periodi_diversi_perche'] ?? null)->toBe([$s['pulizie']->id => 'inquilino'])
        ->and($motivo)->toBe('I piani «Preventivo 2026» e «Integrativo 2026» dividono la voce con periodi diversi, e un\'unità della voce ha cambiato inquilino: '
            .'il programma non sceglie quale vale. Scrivi la competenza sulle fatture, o correggi i periodi del piano.')
        // Per chi legge la stampa: la stessa cosa, senza i piani e senza l'istruzione.
        ->and(\App\Services\Gestionale\ParoleDelConguaglio::perche($motivo))
        ->toBe('I piani dell\'esercizio dividono la voce in periodi diversi, e un\'unità della voce ha cambiato inquilino: la spesa non è ancora divisa.');
});

it('due piani con periodi diversi ma nessun cambio di titolare: la voce si divide come prima, i periodi non cambiano la parte di nessuno', function () {
    $s = cpdDuePiani($this, 'primo', false);
    $c = cpcLeggi($this, $s);

    // Bianchi tutto l'anno: 300/1000 di 3.300,00 = 990,00.
    expect($c['gestioni'][$s['g']->id]['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id])->toBe(99000);
});

it('due piani con la stessa voce e senza periodi: la voce si divide come prima', function () {
    $s = cpdDuePiani($this, null);
    $c = cpcLeggi($this, $s);

    // Per giorni: Bianchi 181/365 di 990,00 = 490,93, Rossi 184/365 = 499,07.
    expect([cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id], cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id]])->toBe([49093, 49907]);
});

it('una riga che comincia il primo giorno dell\'esercizio non è un cambio di titolare: la voce con i periodi diversi si divide, e il già versato non usato va a lei', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // U2: Bianchi fino al 31/12/2025, Rossi dal 01/01/2026, il primo giorno dell'esercizio.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2025-12-31']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $secondo = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Integrativo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-09-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $pivot1 = DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->value('id')
        ?? DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['pulizie']->id, 'importo' => 300000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $secondo->id, 'conto_id' => $s['pulizie']->id, 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $pivot1, 'dal' => '2026-01-01', 'al' => '2026-06-30', 'ordine' => 1, 'created_at' => now(), 'updated_at' => now()]);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Rossi tutto l'esercizio: le Pulizie 300/1000 di 3.300,00 = 990,00 (dal primo piano, con il periodo dichiarato), il già versato non usato € 300,00.
    expect($c['gestioni'][$s['g']->id]['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBeGreaterThan(0)
        ->and((int) (cpcDi($c, $s, 'Rossi', 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0))->toBe(30000)
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([]);
});

// ─── La spesa personale con la competenza su più giorni ──────────────────────────────────────────────────────────────────────────────

it('una spesa personale con la competenza scritta su più giorni si divide per giorni fra chi era titolare in quei giorni; con un giorno solo, com\'era', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    // Il citofono di U2 € 80,00, fattura del 10/08 con la competenza dal 01/06 al 31/07; il passaggio il 01/07.
    $riga = cpcSpesaPersonale($s, 'U2', 8000, '2026-08-10', null, '2026-06-01');
    DB::table('fatture_passive')->where('id', DB::table('righe_fattura')->where('id', $riga)->value('fattura_passiva_id'))->update(['competenza_al' => '2026-07-31']);
    // E una di un giorno solo, il 15/06: tutta a Bianchi.
    cpcSpesaPersonale($s, 'U2', 1000, '2026-08-10', null, '2026-06-15');
    $c = cpcLeggi($this, $s);
    $personali = fn (string $chi) => cpcDi($c, $s, $chi, 'U2')['personali']['non_chieste'] ?? 0;

    // 80,00 × 30/61 = 39,344 → 39,34 a Bianchi (giugno); 80,00 × 31/61 = 40,655 → 40,66 a Rossi (luglio). Più i 10,00 del 15/06 a Bianchi: 49,34.
    expect([$personali('Bianchi'), $personali('Rossi')])->toBe([4934, 4066]);
});

// ─── Il già versato dell'unità non usato, dopo un passaggio ──────────────────────────────────────────────────────────────────────────

it('il già versato dell\'unità che nessun piano ha usato, dopo un cambio di titolare: su nessun foglio, e la scheda dice «a chi va lo decidi tu»', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);
    $scheda = cpdScheda($s)['righe'];

    // Prima: Rossi 300,00, Bianchi 0. Ora nessuno dei due; la gestione lo tiene da decidere, e le righe di U2 lo dicono.
    expect([$nonUsato('Bianchi'), $nonUsato('Rossi')])->toBe([0, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([$s['unita']['U2']->id => 30000])
        ->and(cpdNote($scheda, $s['unita']['U2']->id))->toContain('Già versato registrato per questa unità e non usato dai piani: € 300,00. L\'unità ha cambiato titolare: '
            .'non è sul foglio di nessuno, a chi va lo decidi tu.');
});

it('il già versato dell\'unità non usato senza un cambio di titolare: a chi è titolare, come prima', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect((int) (cpcDi($c, $s, 'Bianchi', 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0))->toBe(30000)
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([]);
});

/**
 * Via Verdi con Nora inquilina di U2 (il 30 % delle voci) e € 300,00 di già versato dell'unità U2 sulle Pulizie, senza la persona, registrato dopo il piano
 * (nessun piano lo usa). Con `$cambia` Nora lascia U2 il 30/06 e Olga entra il 01/07, righe scritte a mano una dopo l'altra; Bianchi resta proprietaria
 * tutto l'anno. Senza, Nora è inquilina tutto l'anno.
 */
function cpdSoloInquilino($test, bool $cambia, bool $inquilinoPaga = true): array
{
    if ($inquilinoPaga) {
        $s = cpcViaVerdi($test, ['inquilino' => ['U2', 'Nora', 30, '2020-01-01', $cambia ? '2026-06-30' : null]]);
    } else {
        // Le voci senza una parte per l'inquilino (proprietario 100 %): Nora è inquilina di U2, ma nella gestione non paga niente.
        $s = cpcViaVerdi($test);
        $nora = Anagrafica::forceCreate(['nome' => 'Nora', 'email' => 'nora-cpd'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => strtoupper(substr(md5('Nora'.$s['c']->id), 0, 16))]);
        $nora->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nora->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'inquilino', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => $cambia ? '2026-06-30' : null, 'created_at' => now(), 'updated_at' => now()]);
        $s['persone']['Nora'] = $nora;
    }
    if ($cambia) {
        $olga = Anagrafica::forceCreate(['nome' => 'Olga', 'email' => 'olga-cpd'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => strtoupper(substr(md5('Olga'.$s['c']->id), 0, 16))]);
        $olga->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $olga->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'inquilino', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2026-07-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        $s['persone']['Olga'] = $olga;
    }
    cpcRateEIncassi($test, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);

    return $s;
}

/**
 * Le cifre dei due casi qui sotto: il già versato dell'unità non usato non entra nel conguaglio, e dove va (alla proprietaria o da decidere) non ne
 * muove nessuna. Per riga a, spese personali, b, conguaglio, posizione, fuori dalle rate, saldo. Con il cambio, il piano
 * (generato a gennaio, con Nora fino al 30/06) chiede a Bianchi anche la parte dell'inquilino da luglio, e Olga, entrata dopo, non ha quote.
 */
const CPD_CIFRE_CAMBIA_INQUILINO = [
    'righe' => [
        'Bianchi U2' => [88200, 0, 102148, -13948, 0, 0, -13948],
        'Neri U1' => [210000, 0, 200000, 10000, 30000, 0, 40000],
        'Nora U2' => [18745, 0, 17852, 893, 17852, 0, 18745],
        'Olga U2' => [19055, 0, 0, 19055, 0, 0, 19055],
        'Verdi U3' => [84000, 0, 80000, 4000, 0, 0, 4000],
    ],
    'totali' => ['a' => 420000, 'personali' => 0, 'b' => 400000, 'conguaglio' => 20000, 'posizione' => 47852, 'fuori_dalle_rate' => 0, 'saldo' => 67852,
        'non_disponibili' => 0, 'a_non_disponibili' => 0],
];
const CPD_CIFRE_SENZA_CAMBIO = [
    'righe' => [
        'Bianchi U2' => [88200, 0, 84000, 4200, 0, 0, 4200],
        'Neri U1' => [210000, 0, 200000, 10000, 30000, 0, 40000],
        'Nora U2' => [37800, 0, 36000, 1800, 36000, 0, 37800],
        'Verdi U3' => [84000, 0, 80000, 4000, 0, 0, 4000],
    ],
    'totali' => ['a' => 420000, 'personali' => 0, 'b' => 400000, 'conguaglio' => 20000, 'posizione' => 66000, 'fuori_dalle_rate' => 0, 'saldo' => 86000,
        'non_disponibili' => 0, 'a_non_disponibili' => 0],
];

/** Tutte le cifre delle righe della gestione, per «persona unità», e i totali, senza il già versato non usato: quello che il già versato non deve muovere. */
function cpdCifreDelleRighe(array $c, array $s): array
{
    $nomi = array_flip(array_map(fn ($p) => $p->id, $s['persone']));
    $unita = array_flip(array_map(fn ($u) => $u->id, $s['unita']));
    $righe = [];
    foreach ($c['persone'][$s['g']->id] as $aid => $perUnita) {
        foreach ($perUnita as $iid => $r) {
            $righe[$nomi[$aid].' '.$unita[$iid]] = [$r['a'], $r['personali']['non_chieste'], $r['b']['totale'], $r['conguaglio'], $r['posizione'], $r['fuori_dalle_rate'], $r['saldo']];
        }
    }
    ksort($righe);

    return ['righe' => $righe, 'totali' => $c['gestioni'][$s['g']->id]['totali']];
}

it('il già versato dell\'unità non usato, quando nell\'esercizio cambia solo l\'inquilino: su nessun foglio, la scheda dice «a chi va lo decidi tu», e nessun\'altra cifra si muove', function () {
    $s = cpdSoloInquilino($this, true);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);
    $scheda = cpdScheda($s)['righe'];

    // Le cifre di tutte le righe e i totali: il già versato non usato non entra nel conguaglio, e dove va non le muove.
    expect(cpdCifreDelleRighe($c, $s))->toBe(CPD_CIFRE_CAMBIA_INQUILINO)
        // Con il cambio dell'inquilino, che nella gestione paga una parte, la cifra resta da decidere: può essere denaro dell'inquilina che esce.
        ->and([$nonUsato('Bianchi'), $nonUsato('Nora'), $nonUsato('Olga')])->toBe([0, 0, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([$s['unita']['U2']->id => 30000])
        // La nota dice che cosa è cambiato: l'inquilino, non il titolare.
        ->and(cpdNote($scheda, $s['unita']['U2']->id))->toContain('Già versato registrato per questa unità e non usato dai piani: € 300,00. L\'unità ha cambiato inquilino: '
            .'non è sul foglio di nessuno, a chi va lo decidi tu.')
        ->and(implode(' ', cpdNote($scheda, $s['unita']['U2']->id)))->not->toContain('cambiato titolare');
});

it('il già versato dell\'unità non usato, quando cambiano il titolare e l\'inquilino: la nota li dice tutti e due', function () {
    $s = cpdSoloInquilino($this, true);
    cpcPassaggio($this, $s);
    $c = cpcLeggi($this, $s);

    expect($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([$s['unita']['U2']->id => 30000])
        ->and(cpdNote(cpdScheda($s)['righe'], $s['unita']['U2']->id))->toContain('Già versato registrato per questa unità e non usato dai piani: € 300,00. '
            .'L\'unità ha cambiato titolare e inquilino: non è sul foglio di nessuno, a chi va lo decidi tu.');
});

it('l\'inquilino cambia, ma nella gestione l\'inquilino non paga niente: il già versato dell\'unità non può essere suo, e va alla proprietaria', function () {
    $s = cpdSoloInquilino($this, true, false);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);

    expect([$nonUsato('Bianchi'), $nonUsato('Nora'), $nonUsato('Olga')])->toBe([30000, 0, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([]);
});

it('un inquilino scritto nel programma a maggio, senza un predecessore né un passaggio, non è un cambio: per il motore c\'è da sempre, e il già versato va alla proprietaria', function () {
    // La data della riga è il giorno in cui è stata scritta, non quello in cui l'inquilina è entrata: il motore le dà la parte dell'inquilino per tutto l'anno.
    $s = cpcViaVerdi($this, ['inquilino' => ['U2', 'Nora', 30, '2026-05-10', null]]);
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);

    // La parte dell'inquilino per 365 giorni: 30 % di 300/1000 di 4.200,00 = 378,00.
    expect(cpcDi($c, $s, 'Nora', 'U2')['a'])->toBe(37800)
        ->and([$nonUsato('Bianchi'), $nonUsato('Nora')])->toBe([30000, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([])
        ->and(implode(' ', cpdNote(cpdScheda($s)['righe'], $s['unita']['U2']->id)))->not->toContain('a chi va lo decidi tu');
});

it('la riga della proprietaria datata dentro l\'anno, senza un predecessore: è il giorno in cui è stata scritta, non un cambio, e il già versato va a lei', function () {
    $s = cpcViaVerdi($this);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_inizio' => '2026-05-10']);
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Per il motore Bianchi ha U2 tutto l'anno: 300/1000 di 4.200,00.
    expect(cpcDi($c, $s, 'Bianchi', 'U2')['a'])->toBe(126000)
        ->and((int) (cpcDi($c, $s, 'Bianchi', 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0))->toBe(30000)
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([]);
});

it('lo stesso caso senza il cambio dell\'inquilino: il già versato dell\'unità non usato va alla proprietaria, e nessun\'altra cifra si muove', function () {
    $s = cpdSoloInquilino($this, false);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);

    expect([$nonUsato('Bianchi'), $nonUsato('Nora')])->toBe([30000, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([])
        ->and(cpdCifreDelleRighe($c, $s))->toBe(CPD_CIFRE_SENZA_CAMBIO);
});

// ─── La spesa personale di un giorno senza titolare ──────────────────────────────────────────────────────────────────────────────────

it('la spesa personale di un giorno in cui l\'unità non ha un titolare: la scheda la dice con la strada, e il controllo delle spese personali la conta', function () {
    $s = cpcViaVerdi($this);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-09-30']);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 5000, '2026-10-15');
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);
    $gestione = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    $ca = collect(ControlliDelConguaglio::tutti($letto, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    expect($gestione['avvisi'])->toContain('Spese per singole unità di giorni in cui l\'unità non aveva un titolare (Int. U3): € 50,00. Non sono nel conguaglio di nessuno: '
        .'controlla le date dei titolari, o scrivi la competenza sulla fattura.')
        ->and($ca['esito'])->toBe('quadra')->and($ca['nota'])->toContain('senza un titolare € 50,00');
});

// ─── Il cambio di titolare con giorni scoperti e senza un passaggio registrato ───────────────────────────────────────────────────────

it('un cambio di titolare con giorni scoperti e senza un passaggio registrato: la riga dell\'unità e il controllo per unità lo dicono, con «registra il passaggio»', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi fino al 30/06, Rossi dal 15/07: due righe scritte a mano, nessun passaggio. Il motore non cambia (Rossi conta da inizio anno).
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-06-30']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);
    $cu = ControlliDelConguaglio::perUnita($letto);
    $frase = 'Int. U2: Bianchi fino al 30/06/2026, Rossi dal 15/07/2026, senza un passaggio registrato. Così Rossi conta dall\'inizio dell\'anno; '
        .'con il passaggio registrato conterebbe dal 15/07/2026.';

    // Nella scheda la riga dell'unità dice anche dove si registra; il controllo lo dice nel rimedio.
    expect(cpdNote($scheda, $s['unita']['U2']->id))->toContain($frase.' Registra il passaggio dalla pagina dei titolari dell\'unità.')
        ->and($cu['esito'])->toBe('segnalazione')->and($cu['nota'])->toContain($frase)
        ->and($cu['rimedio'])->toContain('Registra il passaggio')
        ->and($cu['azione'])->toBe('titolari')->and($cu['azione_parametro'])->toBe((string) $s['unita']['U2']->id);
});

it('un cambio di titolare contiguo, o con il passaggio registrato, non ha l\'avviso', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);

    expect(implode(' ', cpdNote($scheda, $s['unita']['U2']->id)))->not->toContain('senza un passaggio registrato')
        ->and(ControlliDelConguaglio::perUnita($letto)['esito'])->toBe('quadra');
});

it('un cambio di titolare contiguo scritto a mano, senza il passaggio: nessun giorno scoperto, nessun avviso', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi fino al 14/07, Rossi dal 15/07, senza il passaggio: per il programma è il predecessore chiuso il giorno prima.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-07-14']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);

    expect($letto['passaggi_non_registrati'])->toBe([])
        ->and(implode(' ', cpdNote($scheda, $s['unita']['U2']->id)))->not->toContain('senza un passaggio registrato');
});

// ─── Il piano con l'esercizio dedotto ────────────────────────────────────────────────────────────────────────────────────────────────

it('un piano dell\'esercizio senza l\'esercizio scritto: la scheda dice che l\'esercizio è dedotto, e dove si scrive', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    $gestione = collect(cpdScheda($s)['righe']['gestioni'])->firstWhere('id', $s['g']->id);

    expect($gestione['avvisi'])->toContain('Il piano «Preventivo 2026» non ha l\'esercizio scritto: il programma lo legge come piano di «Esercizio 2026» dalla sua data. '
        .'Se non è il suo, scrivi l\'esercizio nel piano.');
});
