<?php

/**
 * Il tasso legale di un anno che il programma non ha ancora, scritto a mano dallo studio: l'anno, il tasso e la fonte (il decreto e la Gazzetta
 * Ufficiale), una volta per tutti i condomini, da chi gestisce le impostazioni generali. La proposta degli interessi lo usa per i giorni di quell'anno;
 * se poi il programma ha il tasso di quell'anno con una cifra diversa, vale quello del programma e la pagina lo dice.
 *
 * Il caso è Via Verdi (`AccessoriScenario.php`): Neri, € 300,00 della rata 1 scaduta il 31/03/2026, mai versati. Il tasso legale del 2026 è 1,60 %
 * (`config/fiscale.php`); quello del 2027 non c'è. Ogni cifra è rifatta a mano accanto al suo caso.
 */

use App\Models\User;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use App\Services\Gestionale\Accessori\TassoLegaleAMano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

defined('TLM_FONTE') || define('TLM_FONTE', 'D.M. 10/12/2026, G.U. n. 290 del 14/12/2026');

/** Chi gestisce le impostazioni generali: l'amministratore del pannello con il permesso, «Mario Rossi». */
function tlmStudio(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $utente = User::factory()->create(['name' => 'Mario Rossi']);
    $utente->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
    $utente->givePermissionTo(Permission::firstOrCreate(['name' => 'Gestisci impostazioni generali', 'guard_name' => 'web']));

    return $utente;
}

/** Le proprietà della pagina «Recupero dei crediti», per chi la apre. */
function tlmPagina($test, array $s, User $chi): array
{
    return $test->actingAs($chi)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props'];
}

function tlmScrivi($test, array $s, User $chi, array $dati)
{
    return $test->actingAs($chi)->from(route('admin.gestionale.recupero-crediti.index', [$s['c']]))
        ->post(route('admin.gestionale.recupero-crediti.tasso-legale.store', [$s['c']]), $dati);
}

function tlmTogli($test, array $s, User $chi, int $anno)
{
    return $test->actingAs($chi)->from(route('admin.gestionale.recupero-crediti.index', [$s['c']]))
        ->delete(route('admin.gestionale.recupero-crediti.tasso-legale.destroy', [$s['c']]), ['anno' => $anno]);
}

/** I tratti di una riga, in breve: giorni, capitale, tasso, fonte, divisore, causa. */
function tlmTratti(array $riga): array
{
    return array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['capitale'], $t['tasso'], $t['fonte'], $t['divisore'], $t['causa']], $riga['tratti']);
}

it('il 15/11/2026 la pagina dice che il 2027 non c\'è ancora e quando esce il decreto; il pulsante lo vede solo chi gestisce le impostazioni generali', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-11-15 10:00:00');
    $studio = tlmStudio();

    $pagina = tlmPagina($this, $s, $studio);
    expect($pagina['anni_mancanti'])->toBe([['anno' => 2027, 'frase' => 'non ancora: il decreto esce entro il 15 dicembre 2026']])
        ->and($pagina['legali_a_mano'])->toBe([])
        ->and($pagina['anni_da_scrivere'])->toBe([2027])
        ->and($pagina['puo_scrivere_tasso_legale'])->toBeTrue()
        ->and(array_column($pagina['tabella_legale'], 'anno'))->toBe([2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019])
        ->and($pagina['tabella_legale'][0])->toBe(['anno' => 2026, 'tasso' => '1,60 %', 'a_mano_diverso' => null])
        ->and(tlmPagina($this, $s, $this->user)['puo_scrivere_tasso_legale'])->toBeFalse();

    // Dopo il 15 dicembre il decreto del 2027 c'è: se il programma non l'ha, lo dice così.
    $this->travelTo('2026-12-16 10:00:00');
    expect(tlmPagina($this, $s, $studio)['anni_mancanti'])->toBe([['anno' => 2027, 'frase' => 'non ancora nel programma']]);

    // Nel 2027, senza l'aggiornamento: il 2027 non è nel programma; il 2028 non si conosce ancora.
    $this->travelTo('2027-02-01 10:00:00');
    expect(tlmPagina($this, $s, $studio)['anni_mancanti'])->toBe([
        ['anno' => 2028, 'frase' => 'non ancora: il decreto esce entro il 15 dicembre 2027'],
        ['anno' => 2027, 'frase' => 'non ancora nel programma'],
    ])->and(tlmPagina($this, $s, $studio)['anni_da_scrivere'])->toBe([2027, 2028]);
});

it('il tasso del 2027 scritto a mano: la proposta lo usa per i giorni del 2027, a cavallo con il 1,60 % del 2026, e dice chi l\'ha scritto, quando e la fonte', function () {
    $s = accViaVerdi($this);
    $studio = tlmStudio();

    // Il 31/01/2027 senza il tasso del 2027 la riga di Neri si ferma, con la frase.
    $this->travelTo('2027-01-31 10:00:00');
    $ferma = accDi(accProposta($this, $s, ['Neri'])->assertOk()->json(), $s, 'Neri')['righe'][0];
    expect($ferma['fermo'])->toStartWith('Il tasso legale del 2027 non è ancora nel programma: puoi proporre gli interessi fino al 31/12/2026.')
        ->and($ferma['fermo'])->toContain('chi gestisce le impostazioni generali lo può scrivere a mano');

    // Lo studio lo scrive il 16/12/2026 (il decreto è uscito): 2,25 %.
    $this->travelTo('2026-12-16 10:00:00');
    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => TLM_FONTE])->assertSessionHasNoErrors()->assertRedirect()
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Tasso legale del 2027 registrato: 2,25 %, per tutti i condomini.');
    expect(TassoLegaleAMano::perAnno())->toBe([2027 => ['anno' => 2027, 'tasso_e4' => 22500, 'fonte' => TLM_FONTE, 'scritto_da' => $studio->id,
        'scritto_da_nome' => 'Mario Rossi', 'scritto_il' => '2026-12-16']]);

    $this->travelTo('2027-01-31 10:00:00');
    $r = accProposta($this, $s, ['Neri'])->assertOk()->json();
    $riga = accDi($r, $s, 'Neri')['righe'][0];
    // 300,00 × 1,60 % × 275/365 = 3,616438 (dal 01/04 al 31/12/2026) + 300,00 × 2,25 % × 31/365 = 0,573288 (gennaio 2027) = 4,189726 → € 4,19.
    expect($riga['fermo'])->toBeNull()->and($riga['interessi'])->toBe(419)->and($r['totale'])->toBe(419)
        ->and(tlmTratti($riga))->toBe([
            ['2026-04-01', '2026-12-31', 275, 30000, '1,60 %', 'tasso legale', 365, 'scadenza'],
            ['2027-01-01', '2027-01-31', 31, 30000, '2,25 %', 'tasso legale scritto a mano', 365, 'anno'],
        ])
        // Gli esatti, in centesimi di centesimo: 361,6438 → 36.164; 57,3288 → 5.733.
        ->and(array_column($riga['tratti'], 'esatti'))->toBe([36164, 5733])
        ->and($r['tassi_a_mano'])->toBe(['Il tasso legale del 2027, 2,25 %, è scritto a mano: da Mario Rossi il 16/12/2026, con la fonte «' . TLM_FONTE . '».']);

    // La pagina lo mostra come scritto a mano, con chi, quando e la fonte; l'anno non manca più. Per la lettera è il tasso legale.
    $pagina = tlmPagina($this, $s, $studio);
    expect($pagina['legali_a_mano'])->toBe([['anno' => 2027, 'tasso' => '2,25 %', 'tasso_annuo' => '2,25', 'fonte' => TLM_FONTE, 'scritto_da' => 'Mario Rossi', 'scritto_il' => '2026-12-16']])
        ->and(array_column($pagina['anni_mancanti'], 'anno'))->toBe([2028])
        ->and($pagina['tasso_di_oggi'])->toBe('2,25 % annuo, tasso legale')
        // È lo stesso per tutti i condomini: non è una regola del condominio.
        ->and(RegoleDelRecupero::tassoPerLaLettera(collect(), '2027-03-01'))->toBe('2,25 % annuo, tasso legale');
});

it('se il programma ha poi il tasso del 2027 con una cifra diversa vale quello del programma, e la pagina lo dice; con la stessa cifra non dice niente', function () {
    $s = accViaVerdi($this);
    $studio = tlmStudio();
    $this->travelTo('2026-12-16 10:00:00');
    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => TLM_FONTE])->assertSessionHasNoErrors();

    // L'aggiornamento porta il 2027 al 2,50 %.
    config()->set('fiscale.interessi_legali', array_merge(config('fiscale.interessi_legali'), [['dal' => '2027-01-01', 'tasso' => 2.5]]));
    $this->travelTo('2027-01-31 10:00:00');
    $r = accProposta($this, $s, ['Neri'])->assertOk()->json();
    $riga = accDi($r, $s, 'Neri')['righe'][0];
    // 3,616438 + 300,00 × 2,50 % × 31/365 = 0,636986 → 4,253424 → € 4,25.
    expect($riga['interessi'])->toBe(425)
        ->and(tlmTratti($riga)[1])->toBe(['2027-01-01', '2027-01-31', 31, 30000, '2,50 %', 'tasso legale', 365, 'anno'])
        ->and($r['tassi_a_mano'])->toBe([]);

    $pagina = tlmPagina($this, $s, $studio);
    expect($pagina['tabella_legale'][0])->toBe(['anno' => 2027, 'tasso' => '2,50 %',
        'a_mano_diverso' => 'Per il 2027 lo studio aveva scritto a mano 2,25 % (Mario Rossi, il 16/12/2026): il programma ha 2,50 %, e vale quello del programma.'])
        ->and($pagina['legali_a_mano'])->toBe([])
        ->and($pagina['anni_mancanti'])->toBe([['anno' => 2028, 'frase' => 'non ancora: il decreto esce entro il 15 dicembre 2027']]);

    // Con la stessa cifra la pagina non dice niente.
    config()->set('fiscale.interessi_legali', array_merge(array_slice(config('fiscale.interessi_legali'), 0, -1), [['dal' => '2027-01-01', 'tasso' => 2.25]]));
    expect(tlmPagina($this, $s, $studio)['tabella_legale'][0])->toBe(['anno' => 2027, 'tasso' => '2,25 %', 'a_mano_diverso' => null]);
});

it('senza il permesso delle impostazioni generali il pulsante non c\'è e scrivere o togliere il tasso risponde 403; il tasso non cambia', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-12-16 10:00:00');
    $studio = tlmStudio();

    expect(tlmPagina($this, $s, $this->user)['puo_scrivere_tasso_legale'])->toBeFalse();
    tlmScrivi($this, $s, $this->user, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => TLM_FONTE])->assertForbidden();
    expect(TassoLegaleAMano::perAnno())->toBe([]);

    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => TLM_FONTE])->assertSessionHasNoErrors();
    tlmTogli($this, $s, $this->user, 2027)->assertForbidden();
    expect(array_keys(TassoLegaleAMano::perAnno()))->toBe([2027]);
});

it('le guardie dell\'anno, del tasso e della fonte; scrivere di nuovo un anno lo corregge, «Togli» lo toglie; gli interessi registrati restano', function () {
    $s = accViaVerdi($this);
    $studio = tlmStudio();
    $this->travelTo('2026-11-15 10:00:00');

    tlmScrivi($this, $s, $studio, ['anno' => 2026, 'tasso' => '2', 'fonte' => TLM_FONTE])->assertSessionHasErrors(['anno' => 'Il tasso legale del 2026 è già nel programma: 1,60 %.']);
    tlmScrivi($this, $s, $studio, ['anno' => 2028, 'tasso' => '2', 'fonte' => TLM_FONTE])
        ->assertSessionHasErrors(['anno' => 'Il tasso legale del 2028 non si conosce ancora: il decreto esce entro il 15 dicembre 2027.']);
    tlmScrivi($this, $s, $studio, ['anno' => 2018, 'tasso' => '2', 'fonte' => TLM_FONTE])
        ->assertSessionHasErrors(['anno' => 'Per il 2018 il programma non ha il tasso legale: scrivi un tasso con la sua fonte nelle regole del condominio.']);
    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => 'tre', 'fonte' => TLM_FONTE])
        ->assertSessionHasErrors(['tasso' => 'Scrivi il tasso come un numero, per esempio 2 o 2,5: più di zero, al più 100, con al più quattro decimali.']);
    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => ' '])
        ->assertSessionHasErrors(['fonte' => 'Scrivi la fonte: il decreto del Ministero dell\'economia e la Gazzetta Ufficiale.']);
    expect(TassoLegaleAMano::perAnno())->toBe([]);

    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,25', 'fonte' => TLM_FONTE])->assertSessionHasNoErrors();
    $this->travelTo('2026-12-17 10:00:00');
    tlmScrivi($this, $s, $studio, ['anno' => 2027, 'tasso' => '2,2', 'fonte' => 'D.M. 11/12/2026'])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Tasso legale del 2027 corretto: 2,20 %, per tutti i condomini. Gli interessi già registrati restano come sono.');
    expect(TassoLegaleAMano::perAnno()[2027])->toBe(['anno' => 2027, 'tasso_e4' => 22000, 'fonte' => 'D.M. 11/12/2026', 'scritto_da' => $studio->id,
        'scritto_da_nome' => 'Mario Rossi', 'scritto_il' => '2026-12-17']);

    tlmTogli($this, $s, $studio, 2027)->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Tasso legale del 2027 tolto. Gli interessi già registrati restano come sono.');
    expect(TassoLegaleAMano::perAnno())->toBe([]);
    tlmTogli($this, $s, $studio, 2027)->assertSessionHasErrors(['anno' => 'Il 2027 non ha un tasso legale scritto a mano.']);
});
