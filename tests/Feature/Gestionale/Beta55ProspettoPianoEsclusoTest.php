<?php

/**
 * 1.11.0-beta.55, Coda 359 — la testa del prospetto degli oneri accessori quando un piano rate approvato dell'esercizio resta fuori.
 *
 * Il prospetto lascia fuori un piano approvato quando non lo sa leggere: un piano della 1.10 (senza riparto registrato) con i titolari cambiati dopo la
 * generazione, un piano senza rate generate, un piano della 1.10 sull'anello di una fine con la regola del piano che non si ricalcola più. Prima la testa
 * diceva lo stesso «Nessun piano rate approvato in questo esercizio: il prospetto è vuoto.» e la stampa «Nessuna voce a carico dell'inquilino su questa unità
 * nei piani rate approvati dell'esercizio.», mentre il piano è approvato, è dell'esercizio, e ha una parte a carico dell'inquilino. Ora dice quale piano è
 * rimasto fuori e perché; la frase di prima resta per il solo caso in cui è vera (nessun piano approvato: il piano in bozza).
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 al 30 % all'inquilino (l'anello dell'Interno 1 è 36000, € 360,00 a carico
 * dell'inquilino); Ugo proprietario al 100 %; le rate da gennaio a giugno a giornale.
 *
 * I CONTI A MANO: il cambio Ines → Luca il 1/7 (Ines al 100 %): 36000 × 184 / 365 = 18147,95 → 18148; con «Scrivi» Ines − 18148, Luca + 18148 nei saldi.
 *
 * Cosa NON copre: la pagina Vue; MySQL.
 */

use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il piano generato con le rate da gennaio a giugno a giornale, poi senza le righe del riparto (generato dalla 1.10), e Luca che entrerà. */
function ppe359Scenario($test, array $inquilini = [['Ines', 100]]): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => $inquilini]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    fclPersona($s, 'Luca');

    return $s;
}

function ppe359Prospetto(array $s): array
{
    return app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
}

const PPE359_FUORI = 'Il piano «Preventivo 2026» non compare: generato da una versione precedente senza riparto registrato, e i titolari dell\'unità sono cambiati dopo la generazione: le voci non si possono ricondurre alle rate.';

it('Coda 359 — il cambio d\'inquilino su un piano della 1.10: la testa dice che il piano approvato resta fuori e perché, e la stampa non dice che a carico dell\'inquilino non c\'è niente', function (string $scelta, array $saldi) {
    $s = ppe359Scenario($this);
    fclRegistra($this, $s, fclModulo($s, $s['persone']['Luca']) + ['conguaglio' => $scelta]);
    expect(fclSaldi($s))->toBe($saldi);
    $p = ppe359Prospetto($s);

    expect($p['piani'])->toBe([])
        ->and($p['conduttori'])->toBe([])
        ->and($p['intestazione'])->toContain(PPE359_FUORI)
        ->and(implode(' ', $p['intestazione']))->not->toContain('Nessun piano rate approvato in questo esercizio')
        ->and($p['intestazione'])->toContain('Il prospetto è vuoto: il piano rate approvato «Preventivo 2026» di questo esercizio non si può leggere qui, e il perché è qui sotto.');
    $stampa = fclStampa($s);
    expect($stampa)->not->toContain('Nessuna voce a carico dell\'inquilino su questa unità nei piani rate approvati')
        ->and($stampa)->not->toContain('Nessun piano rate approvato in questo esercizio')
        ->and($stampa)->toContain('Le voci a carico dell\'inquilino del piano «Preventivo 2026» non sono riportate: il piano è rimasto fuori, con il suo motivo qui sopra.')
        ->and($p['piani_approvati_fuori'])->toBe(['Preventivo 2026']);
})->with([
    'non scritto' => ['non_scrivere', []],
    'scritto' => ['scrivi', ['Ines' => -18148, 'Luca' => 18148]],
]);

it('Coda 359 — l\'inizio di Luca su un piano della 1.10 generato senza inquilino: la stessa testa', function () {
    $s = ppe359Scenario($this, []);
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
    $p = ppe359Prospetto($s);

    expect($p['intestazione'])->toContain(PPE359_FUORI)
        ->and(implode(' ', $p['intestazione']))->not->toContain('Nessun piano rate approvato in questo esercizio')
        ->and($p['intestazione'])->toContain('Il prospetto è vuoto: il piano rate approvato «Preventivo 2026» di questo esercizio non si può leggere qui, e il perché è qui sotto.')
        ->and(fclStampa($s))->not->toContain('Nessuna voce a carico dell\'inquilino su questa unità nei piani rate approvati')
        ->and($p['piani_approvati_fuori'])->toBe(['Preventivo 2026']);
});

it('Coda 359 — un piano approvato senza rate generate: la testa lo nomina, e la stampa non dice che a carico dell\'inquilino non c\'è niente', function () {
    // Scritto dopo il codice (il rosso è la mutazione): il piano approvato e non ancora generato, come lo lascia la pagina dei piani prima di «Genera».
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 100]]]);
    $p = ppe359Prospetto($s);

    expect($p['piani'])->toBe([])
        ->and($p['intestazione'])->toContain('Il prospetto è vuoto: il piano rate approvato «Preventivo 2026» di questo esercizio non si può leggere qui, e il perché è qui sotto.')
        ->and($p['intestazione'])->toContain('Il piano «Preventivo 2026» non compare: senza rate generate.')
        ->and(fclStampa($s))->toContain('Le voci a carico dell\'inquilino del piano «Preventivo 2026» non sono riportate: il piano è rimasto fuori, con il suo motivo qui sopra.')
        ->and($p['piani_approvati_fuori'])->toBe(['Preventivo 2026']);
});

it('Coda 359 — il solo piano in bozza: la frase di prima è vera, e resta', function () {
    $s = ppe359Scenario($this);
    $s['piano']->update(['stato' => 'bozza']);
    $p = ppe359Prospetto($s);

    expect($p['intestazione'])->toContain('Nessun piano rate approvato in questo esercizio: il prospetto è vuoto.')
        ->and($p['intestazione'])->toContain('Il piano «Preventivo 2026» non compare: in bozza: non ancora approvato.')
        ->and(fclStampa($s))->toContain('Nessuna voce a carico dell\'inquilino su questa unità nei piani rate approvati dell\'esercizio.')
        ->and($p['piani_approvati_fuori'] ?? [])->toBe([]);
});

it('Coda 359 — un piano letto senza voci a carico dell\'inquilino accanto al piano della 1.10 rimasto fuori: la stampa non dice che a carico dell\'inquilino non c\'è niente', function () {
    // «Lavori tetto» € 500,00 tutta al proprietario, in un piano della stessa gestione generato con il riparto: il prospetto lo legge, e non ha voci per
    // l'inquilino; il preventivo della 1.10 resta fuori dopo il cambio Ines → Luca.
    $s = ppe359Scenario($this);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    // «Spese generali» è una voce del preventivo (come lo crea la pagina): il piano integrativo, creato senza voci scelte, prende solo le voci libere.
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000]]);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Lavori tetto', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '500,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativa lavori 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-08-05', 'genera_subito' => true,
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del piano integrativo',
    ])->assertSessionHasNoErrors();
    $integrativa = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Integrativa lavori 2026')->firstOrFail();
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $integrativa]), ['approvato' => true, 'data_delibera_assemblea' => '2026-07-15'])
        ->assertSessionHasNoErrors();
    fclRegistra($this, $s, fclModulo($s, $s['persone']['Luca']) + ['conguaglio' => 'non_scrivere']);
    $p = ppe359Prospetto($s);

    expect(array_column($p['piani'], 'nome'))->toBe(['Integrativa lavori 2026'])
        ->and($p['conduttori'])->toBe([])
        ->and($p['intestazione'])->toContain(PPE359_FUORI);
    $stampa = fclStampa($s);
    expect($stampa)->not->toContain('Nessuna voce a carico dell\'inquilino su questa unità nei piani rate approvati')
        ->and($stampa)->toContain('Le voci a carico dell\'inquilino del piano «Preventivo 2026» non sono riportate: il piano è rimasto fuori, con il suo motivo qui sopra.')
        ->and($p['piani_approvati_fuori'])->toBe(['Preventivo 2026']);
});
