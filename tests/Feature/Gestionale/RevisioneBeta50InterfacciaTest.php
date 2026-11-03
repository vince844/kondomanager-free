<?php

/**
 * 1.11.0-beta.50, Fase 1-bis — la parte del server dei rilievi sul modulo «Registra passaggio» (`PassaggioNew.vue`); la pagina è in
 * `PassaggioNewRevisione.test.ts`.
 *
 * - R13 (decisione 83.4: la scheda delle pertinenze anche nella fine e nell'inizio della locazione). La scheda è del modulo; qui la
 *   strada che il modulo farà con la scheda: la fine e l'inizio con il Box 12 spuntato, mandati con i campi che il modulo manda,
 *   registrano un passaggio solo per le due unità e una scelta sola sul conguaglio. È il controllo che la strada del server c'era
 *   già: il difetto del rilievo era nella pagina.
 * - R16 (il modulo dice «l'unità resta sfitta» per una fine retrodatata con la coinquilina uscita prima di oggi). La pagina riceve
 *   `titolari`, le sole righe in corso oggi, e non può sapere di Dora: il server, come già per l'estinzione (`righeDellEstinzione`),
 *   le passa anche le righe d'inquilino chiuse, da guardare alla data scritta. Il nome della prop, `righeDellaLocazione`, è quello
 *   che usa il vitest della pagina.
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» e 70 %
 * «Proprietario»; il «Preventivo 2026» in dodici rate mensili; con il box l'Interno 1 ha 900 millesimi e il «Box 12» 100. Il 2026 ha
 * 365 giorni; Ines esce il 1/7: 181 giorni suoi (1/1–30/6), 184 dopo (1/7–31/12).
 *
 * Nati come test rossi, con accanto a ciascuno un controllo che dice che il rosso non veniva dal test.
 *
 * Cosa NON copre: la pagina Vue (scheda, casella «Nessuno», scelta che resta, pulsante: sono nel vitest); la fine registrata dal box
 * come unità principale; l'avviso del modulo quando chi esce è inquilino anche di una pertinenza non spuntata (non deciso); le cifre
 * del prospetto dopo l'inizio con il box; R17 e R21, che vivono solo nella pagina; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * I campi che il modulo manda all'anteprima e alla registrazione (`corpoAnteprima` e `submit` di `PassaggioNew.vue`), con i valori che
 * ha quando l'amministratore non tocca nient'altro: la nota del cancello solo se il pannello la chiede (lo fa `fclStore`).
 */
function rbiComeLoMandaIlModulo(array $campi): array
{
    return $campi + [
        'sottotipo' => null, 'copia_autentica' => false, 'copia_autentica_il' => null, 'estremi_titolo' => null, 'nota' => null,
        'data_fine_locazione' => null, 'regime_contratto' => null, 'ordinaria_dopo_atto' => 'usufruttuario', 'voci_da_tenere' => [],
        'nudi_che_tornano' => [], 'nudi_per_quota' => false, 'accrescimento' => false, 'conguaglio' => null,
        'promemoria_scadenza' => false, 'promemoria_giorni' => null, 'rinuncia_conguaglio' => false, 'nota_conguaglio' => null, 'ordinaria_impronta' => null,
    ];
}

/**
 * L'Interno 1 di Ugo e il Box 12, sua pertinenza, di chi si dice (Ugo, come nel rilievo, o Rita, perché la coppia del box vada a una
 * persona diversa); Ines inquilina di tutti e due con lo stesso contratto dal 2019; il piano generato e sei rate a giornale.
 */
function rbiAppartamentoEBox($test, string $delBox): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [[$delBox, 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** La fine di Ines il 1/7 senza un nuovo inquilino, con il Box 12 spuntato nella scheda, come la manda il modulo. */
function rbiFineConIlBox(array $s): array
{
    return rbiComeLoMandaIlModulo(['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => fclRiga($s, 'Ines'),
        'anagrafica_entrante_id' => null, 'decorrenza' => '2026-07-01', 'quota' => '100', 'pertinenze' => [(int) $s['box']->id]]);
}

/** Le righe di una persona su un'unità con un ruolo: [data_inizio, data_fine], in ordine. */
function rbiRighe(array $s, \App\Models\Immobile $unita, string $nome, string $ruolo = 'inquilino'): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $s['persone'][$nome]->id)->where('tipologia', $ruolo)
        ->orderBy('id')->get(['data_inizio', 'data_fine'])
        ->map(fn ($r) => [substr((string) $r->data_inizio, 0, 10), $r->data_fine !== null ? substr((string) $r->data_fine, 0, 10) : null])->all();
}

// ---------------------------------------------------------------------------------------------------------------------
// R13, decisione 83.4: la fine e l'inizio con le pertinenze spuntate, come li manderà il modulo con la scheda
// ---------------------------------------------------------------------------------------------------------------------

it('R13, la strada del server — la fine di Ines con il Box 12 spuntato e «Scrivi», con i campi del modulo: una scelta sola nel registro del passaggio, il box la legge dal padre, e la coppia di ogni unità a chi la paga lì (+ € 163,33 sull\'Interno 1, + € 18,15 sul box)', function (string $delBox) {
    // Decisione 77 punto 1 («una scelta sola per l'appartamento e le pertinenze spuntate, con le cifre per unità e per persona») e
    // 83.4. A mano, con la regola della coppia (a ognuno la parte intera, il centesimo che avanza al resto più grande):
    // Interno 1: € 1.200,00 × 900/1000 = 108000, «Inquilino» 30 % = 32400; 32400 × 181/365 = 16066,85 e 32400 × 184/365 = 16333,15:
    //   16066 + 16333 = 32399, il centesimo a ,85 → Ines 16067, la coppia 16333 a Ugo.
    // Box 12: € 1.200,00 × 100/1000 = 12000, «Inquilino» 3600; 3600 × 181/365 = 1785,21 e 3600 × 184/365 = 1814,79: 1785 + 1814 = 3599,
    //   il centesimo a ,79 → Ines 1785, la coppia 1815 a chi ha il box.
    $s = rbiAppartamentoEBox($this, $delBox);
    $modulo = rbiFineConIlBox($s);

    $a = fclAnteprima($this, $s, $modulo);
    expect($a['rate']['scelta_del_conguaglio'] ?? null)->toBeTrue()
        ->and(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333], [(int) $s['box']->id, $delBox, 1815]]);

    $fine = fclRegistra($this, $s, ['conguaglio' => 'scrivi'] + $modulo);
    $figli = Subentro::where('subentro_padre_id', $fine->id)->get();

    expect($fine->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and($figli->pluck('immobile_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $s['box']->id])
        // Il box non ha una scelta sua: legge quella del padre.
        ->and($figli->first()->registro['conguaglio'] ?? null)->toBeNull()
        ->and($figli->first()->haUnConguaglio())->toBeTrue()
        ->and(rbiRighe($s, $s['unita'], 'Ines'))->toBe([['2019-01-01', '2026-06-30']])
        ->and(rbiRighe($s, $s['box'], 'Ines'))->toBe([['2019-01-01', '2026-06-30']])
        ->and(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, $delBox => 1815]);
})->with([
    'il box di Ugo' => ['Ugo'],
    'il box di Rita' => ['Rita'],
]);

it('R13, la strada del server — l\'inizio di Luca il 1/9 con il Box 12 spuntato, dopo la fine «Scrivi» di tutti e due: un passaggio per le due unità, nessuna seconda scelta, e su ogni unità Luca rimborsa chi ha preso la coppia della fine', function (string $delBox) {
    // Decisione 83.4: la scheda anche nell'inizio. L'inizio non ha una scelta sua sul conguaglio: legge quella della fine (decisione
    // 77), una per l'appartamento e il box. Il modulo manda `conguaglio` null (`sceltaConguaglioDelPassaggio` è falsa nell'inizio).
    $s = rbiAppartamentoEBox($this, $delBox);
    fclRegistra($this, $s, ['conguaglio' => 'scrivi'] + rbiFineConIlBox($s));
    $luca = fclPersona($s, 'Luca');
    $modulo = rbiComeLoMandaIlModulo(['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $luca->id,
        'decorrenza' => '2026-09-01', 'quota' => '100', 'data_fine_locazione' => '2030-08-31', 'regime_contratto' => 'abitativo', 'pertinenze' => [(int) $s['box']->id]]);

    $a = fclAnteprima($this, $s, $modulo);
    $frasi = implode(' ', $a['rate']['frasi']);
    expect($a['rate']['scelta_del_conguaglio'] ?? false)->toBeFalse()
        ->and($a['anagrafica']['pertinenze'])->toBe(['Box 12'])
        ->and($frasi)->toContain('li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo')
        ->and($frasi)->toContain("li ha presi con il conguaglio {$delBox}, e li rimborsa Luca a {$delBox}");

    $inizio = fclRegistra($this, $s, $modulo);
    $figli = Subentro::where('subentro_padre_id', $inizio->id)->get();

    expect($inizio->tipo_passaggio)->toBe('inizio_locazione')
        ->and($inizio->registro['conguaglio'] ?? null)->toBeNull()
        ->and($figli->pluck('immobile_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $s['box']->id])
        ->and($figli->first()->registro['conguaglio'] ?? null)->toBeNull()
        ->and(rbiRighe($s, $s['unita'], 'Luca'))->toBe([['2026-09-01', null]])
        ->and(rbiRighe($s, $s['box'], 'Luca'))->toBe([['2026-09-01', null]])
        // L'inizio non scrive righe in saldi: restano le due coppie della fine.
        ->and(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, $delBox => 1815]);
})->with([
    'il box di Ugo' => ['Ugo'],
    'il box di Rita' => ['Rita'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// R16: la fine retrodatata con la coinquilina uscita prima di oggi
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Ines e Dora inquiline al 50 % dal 2019, Ugo proprietario; la fine di Dora del 1/9 registrata (la sua riga si chiude il 31/8). Oggi è
 * il 12/10/2026: l'amministratore registra in ritardo la fine di Ines al 1/7.
 */
function rbiDoraUscitaPrima($test): array
{
    Carbon::setTestNow('2026-10-12 10:00:00');
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($test, $s, fclModulo($s, null, '2026-09-01', [], 'Dora'));
    expect(rbiRighe($s, $s['unita'], 'Dora'))->toBe([['2019-01-01', '2026-08-31']]);

    return $s;
}

it('R16, il controllo — il pannello del server sa che il 1/7 resta inquilina Dora: «Dal 1 luglio 2026 resta inquilino Dora, al 50 %.», e non dice «sfitta»', function () {
    $s = rbiDoraUscitaPrima($this);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01'));

    // Fra il numero e «%» il pannello mette uno spazio che non va a capo.
    expect($a['riferimento']['frase'])->toBe('Ines risulterà inquilino fino al 30 giugno 2026 compreso. Dal 1 luglio 2026 resta inquilino Dora.')
        ->and($a['anagrafica']['frasi'])->toBe(['Ines risulterà inquilino fino al 30 giugno 2026.', "Dal 1 luglio 2026 resta inquilino Dora, al 50\u{00A0}%."]);
});

it('R16 — la pagina della fine di Ines riceve anche la riga di Dora chiusa il 31/8 (`righeDellaLocazione`), per guardarla il giorno della fine come il pannello', function () {
    // Atteso (proposta del rilievo, «passare al modulo anche le righe d'inquilino chiuse, come già righeDellEstinzione, e leggerle alla
    // data scritta»): fra le righe che la pagina riceve c'è quella di Dora, inquilina dal 2019 al 31/8/2026, che il 1/7 era in corso.
    $s = rbiDoraUscitaPrima($this);

    $pagina = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.passaggi.create', [$s['c'], $s['unita'], 'tipo' => 'fine_locazione', 'riga' => fclRiga($s, 'Ines')]))
        ->assertOk()->viewData('page')['props'];

    // Controlli (già giusti): oggi è il 12/10, la pagina sceglie già Ines, e `titolari` ha le sole righe in corso oggi (Ugo e Ines, non
    // Dora).
    expect($pagina['oggi'])->toBe('2026-10-12')
        ->and($pagina['rigaPreselezionata'])->toBe(fclRiga($s, 'Ines'))
        ->and(collect($pagina['titolari'])->pluck('anagrafica.nome')->sort()->values()->all())->toBe(['Ines', 'Ugo']);

    $dora = collect($pagina['righeDellaLocazione'] ?? [])->first(fn ($r) => ($r['anagrafica']['nome'] ?? null) === 'Dora');
    expect($dora)->not->toBeNull('la riga di Dora non arriva alla pagina')
        ->and($dora['tipologia'])->toBe('inquilino')
        ->and((float) $dora['quota'])->toBe(50.0)
        ->and($dora['data_inizio'])->toBe('2019-01-01')
        ->and($dora['data_fine'])->toBe('2026-08-31');
});
