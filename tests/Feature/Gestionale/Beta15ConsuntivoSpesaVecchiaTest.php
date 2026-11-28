<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La spesa vecchia senza periodo nel consuntivo per persona, con la regola del piano da fatture.
 *
 * Via Verdi (lo scenario del consuntivo per persona): U2 è di Bianchi fino al 30/06/2026, di Rossi dal 01/07 (il passaggio registrato). La fattura del
 * 15/11/2025 di € 300,00 sulla sola U2, senza competenza scritta, registrata sull'ordinaria 2026. Il piano da fatture non indovina chi era titolare
 * quando il costo è maturato e la divide sui giorni della sua base (`CalcoloQuoteService::giornoDellaSpesaPersonale`): € 300,00 × 181 / 365 = € 148,767 a
 * Bianchi e × 184 / 365 = € 151,233 a Rossi, ai resti maggiori € 148,77 e € 151,23 (`Beta15SpesaPersonaleDiUnGiornoTest`). Il consuntivo per persona,
 * per la stessa spesa non ancora chiesta, la dava tutta a Bianchi, titolare il giorno della fattura: piano e consuntivo dicevano due cifre diverse. Ora
 * il consuntivo la divide sui giorni della stessa base (la gestione dentro l'esercizio, come le spese comuni senza competenza). Restano al giorno della
 * fattura la spesa datata dentro l'esercizio, la spesa con la competenza scritta e la spesa di una gestione straordinaria.
 *
 * Fonte dell'atteso: i conti a mano accanto a ogni test, e le cifre del piano da fatture generato sullo stesso caso.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\FogliDelConguaglio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Via Verdi con il passaggio di U2 dal 01/07 e una spesa su U2, senza competenza; `$pregressa` la segna come pregressa. */
function b15svScenario($test, string $data = '2025-11-15', int $importo = 30000, ?string $dal = null, ?string $al = null, bool $pregressa = false): array
{
    $s = cpcViaVerdi($test);
    cpcPassaggio($test, $s);
    $s['riga'] = cpcSpesaPersonale($s, 'U2', $importo, $data);
    $s['fattura'] = (int) DB::table('righe_fattura')->where('id', $s['riga'])->value('fattura_passiva_id');
    DB::table('fatture_passive')->where('id', $s['fattura'])->update(['competenza_dal' => $dal, 'competenza_al' => $al, 'is_pregresso' => $pregressa]);

    return $s;
}

/** Le spese personali non ancora chieste di U2, per persona: [nome => centesimi]. */
function b15svNonChieste($test, array $s): array
{
    $c = cpcLeggi($test, $s);
    $esito = [];
    foreach (['Bianchi', 'Rossi'] as $chi) {
        $esito[$chi] = (int) (cpcDi($c, $s, $chi, 'U2')['personali']['non_chieste'] ?? 0);
    }

    return $esito;
}

/** Il piano da fatture che chiede la fattura dello scenario, generato: quanto chiede a ognuno, dalle righe del riparto. */
function b15svPianoChiede(array $s, int $importo = 30000): array
{
    $piano = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Spese da finanziare', 'stato' => 'approvato', 'tipo' => 'straordinario',
        'numero_rate' => 2, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-08-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    $piano->fatture()->attach($s['fattura'], ['importo_collegato' => $importo]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'La spesa di U2', esercizio: $s['e']);
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $nome) => [$p->id => $nome])->all();
    $esito = [];
    foreach (DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'ad_personam')->get(['anagrafica_id', 'importo']) as $r) {
        $esito[$nomi[(int) $r->anagrafica_id]] = ($esito[$nomi[(int) $r->anagrafica_id]] ?? 0) + (int) $r->importo;
    }
    ksort($esito);

    return $esito;
}

it('la spesa vecchia senza periodo non ancora chiesta: il consuntivo la divide sui giorni dell\'esercizio come il piano, € 148,77 a Bianchi e € 151,23 a Rossi; prima tutta a Bianchi', function () {
    $s = b15svScenario($this);

    // A mano: € 300,00 × 181 / 365 = € 148,767 (dal 01/01 al 30/06), × 184 / 365 = € 151,233 (dal 01/07 al 31/12); ai resti maggiori € 148,77 e € 151,23.
    expect(b15svNonChieste($this, $s))->toBe(['Bianchi' => 14877, 'Rossi' => 15123]);

    // Lo stesso numero del piano da fatture sulla stessa spesa.
    expect(b15svPianoChiede($s))->toBe(['Bianchi' => 14877, 'Rossi' => 15123]);
});

it('la catena: chiesta dal piano, nessuno dei due ha più una parte da conguagliare, e il conguaglio non sposta la differenza', function () {
    $s = b15svScenario($this);
    $prima = cpcLeggi($this, $s);
    $congPrima = ['Bianchi' => cpcDi($prima, $s, 'Bianchi', 'U2')['conguaglio'], 'Rossi' => cpcDi($prima, $s, 'Rossi', 'U2')['conguaglio']];

    expect(b15svPianoChiede($s))->toBe(['Bianchi' => 14877, 'Rossi' => 15123]);
    $dopo = cpcLeggi($this, $s);

    expect(b15svNonChieste($this, $s))->toBe(['Bianchi' => 0, 'Rossi' => 0])
        // Prima del piano il conguaglio proponeva a ognuno la sua parte; dopo il piano la chiede con le rate, e il conguaglio non ne ha più: cambia
        // esattamente di quella parte, per tutti e due.
        ->and(cpcDi($dopo, $s, 'Bianchi', 'U2')['conguaglio'] - $congPrima['Bianchi'])->toBe(-14877)
        ->and(cpcDi($dopo, $s, 'Rossi', 'U2')['conguaglio'] - $congPrima['Rossi'])->toBe(-15123);
});

it('la pregressa senza periodo, anche datata dentro l\'esercizio, si divide sui giorni come nel piano', function () {
    $s = b15svScenario($this, '2026-01-20', 30000, pregressa: true);

    expect(b15svNonChieste($this, $s))->toBe(['Bianchi' => 14877, 'Rossi' => 15123]);
});

it('controprova, decisione 329 (2) — la spesa senza periodo datata dentro l\'esercizio resta tutta a chi era titolare il giorno della fattura', function () {
    $s = b15svScenario($this, '2026-03-10');

    expect(b15svNonChieste($this, $s))->toBe(['Bianchi' => 30000, 'Rossi' => 0]);
});

it('controprova — la spesa vecchia con la competenza scritta di un giorno resta di chi era titolare quel giorno', function () {
    $s = b15svScenario($this, '2025-11-15', 30000, '2025-11-15', '2025-11-15');

    expect(b15svNonChieste($this, $s))->toBe(['Bianchi' => 30000, 'Rossi' => 0]);
});

it('controprova — su una gestione straordinaria la spesa vecchia senza periodo non cambia: tutta a chi era titolare il giorno della fattura', function () {
    $s = cpcViaVerdi($this);
    cpcPassaggio($this, $s);
    $opera = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $opera->id);
    $t = ['g' => $opera] + $s;
    $riga = cpcSpesaPersonale($t, 'U2', 30000, '2025-11-15');
    DB::table('fatture_passive')->where('id', DB::table('righe_fattura')->where('id', $riga)->value('fattura_passiva_id'))->update(['is_pregresso' => false]);

    $c = cpcLeggi($this, $s);

    expect((int) (cpcDi($c, $t, 'Bianchi', 'U2')['personali']['non_chieste'] ?? 0))->toBe(30000)
        ->and((int) (cpcDi($c, $t, 'Rossi', 'U2')['personali']['non_chieste'] ?? 0))->toBe(0);
});

it('il foglio del conguaglio dice la ragione vera: la fattura senza periodo, divisa sui giorni dell\'esercizio', function () {
    $s = b15svScenario($this);
    $this->travelTo('2027-01-10 10:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e'], [], true);
    $foglio = fn (string $chi) => vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'foglio' => app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone'][$chi]->id)])->render());

    expect($foglio('Bianchi'))->toContain('(fattura del 15/11/2025 senza periodo, divisa sui giorni dal 01/01/2026 al 31/12/2026: € 148,77)')
        ->and($foglio('Rossi'))->toContain('(fattura del 15/11/2025 senza periodo, divisa sui giorni dal 01/01/2026 al 31/12/2026: € 151,23)');
});

it('controprova — la spesa datata dentro l\'esercizio il foglio la dice ancora con il suo giorno', function () {
    $s = b15svScenario($this, '2026-03-10', 12000);
    $this->travelTo('2027-01-10 10:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e'], [], true);
    $foglio = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'foglio' => app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone']['Bianchi']->id)])->render());

    expect($foglio)->toContain('(del 10/03/2026: € 120,00)')->not->toContain('senza periodo');
});
