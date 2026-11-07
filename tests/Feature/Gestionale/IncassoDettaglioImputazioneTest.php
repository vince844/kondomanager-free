<?php

/**
 * La pagina dell'incasso dice chi l'ha registrato, a che cosa va il versamento (l'imputazione e la sua nota) e, se è stornato, chi l'ha
 * stornato, perché e da quando vale (1.12.0-beta.3, decisioni 217 e 218). Gli incassi e gli storni di prima, senza le colonne nuove, si
 * aprono e lo dicono. «Stornato da» si legge dalla rettifica: il vecchio ramo sullo stato `stornato`, che la colonna non ammette, non c'è più.
 */

use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['name' => 'Anna Registra']);
    $this->user->assignRole($ruolo);
    $this->altro = User::factory()->create(['name' => 'Bruno Storna']);
    $this->altro->assignRole($ruolo);
});

/** Ugo, tre rate da 10000 (05/01, 05/02, 05/03), emesse. */
function idiScenario($test): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'prima_rata', [], 30000, '2026-01-05');
    lcdEmetti($test, $s, [1, 2, 3], '2026-01-01');

    return $s;
}

function idiPagina($test, array $s, int $incasso): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], $incasso]))->assertOk()->viewData('page')['props'];
}

it('dice chi ha registrato l\'incasso e a che cosa va: secondo l\'ordine delle scadenze; indicata da chi ha versato, con la nota', function () {
    $s = idiScenario($this);
    $nellOrdine = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-03-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20', 'importo_totale' => '100.00',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'imputazione_scelta' => 'indicata_dal_versante',
        'imputazione_nota' => 'Causale: rata di marzo',
        'dettaglio_pagamenti' => [['rata_id' => lcdQuota($s, 'ugo', 3), 'importo' => '100.00']],
    ])->assertSessionHasNoErrors();
    $indicata = (int) DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->max('id');

    $uno = idiPagina($this, $s, $nellOrdine);
    $due = idiPagina($this, $s, $indicata);

    expect($uno['utenteCreatore'])->toBe('Anna Registra')
        ->and($uno['imputazione'])->toBe(['codice' => 'ordine_scadenze', 'testo' => 'Secondo l\'ordine delle scadenze', 'nota' => null])
        ->and($due['imputazione'])->toBe(['codice' => 'indicata_dal_versante', 'testo' => 'Indicata da chi ha versato', 'nota' => 'Causale: rata di marzo'])
        ->and($uno['storno'])->toBeNull();
});

it('un incasso di prima, senza imputazione né chi l\'ha registrato, si apre, lo dice, e la sua ricevuta non ha la riga del criterio', function () {
    $s = idiScenario($this);
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-03-20');
    DB::table('scritture_contabili')->where('id', $incasso)->update(['imputazione' => null, 'imputazione_nota' => null, 'created_by' => null]);

    $pagina = idiPagina($this, $s, $incasso);
    $ricevuta = app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($incasso));
    $opzioni = $this->actingAs($this->user)->getJson(route('admin.gestionale.ricevute.opzioni', [$s['c'], $incasso]))->assertOk()->json();

    expect($pagina['utenteCreatore'])->toBeNull()
        ->and($pagina['imputazione'])->toBe(['codice' => null, 'testo' => 'Non dichiarata: registrato prima che il programma la chiedesse', 'nota' => null])
        ->and($ricevuta['imputazione'])->toBeNull()
        ->and($opzioni['imputazione'])->toBeNull();
});

it('uno stornato dice chi l\'ha stornato (dalla rettifica), quando, da quando vale e perché', function () {
    $s = idiScenario($this);
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-03-10');
    $this->travelTo('2026-03-25 10:00:00');
    $this->actingAs($this->altro)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]), ['natura_storno' => 'errore_registrazione'])->assertSessionHasNoErrors();

    $pagina = idiPagina($this, $s, $incasso);

    expect($pagina['utenteCreatore'])->toBe('Anna Registra')
        ->and($pagina['utenteStornatore'])->toBe('Bruno Storna')
        ->and($pagina['storno'])->toMatchArray([
            'stornato_il' => '2026-03-25', 'vale_dal' => '2026-03-10', 'natura' => 'errore_registrazione',
            'natura_testo' => 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo',
        ]);
});

it('uno storno di prima, senza natura né chi l\'ha fatto, lo dice', function () {
    $s = idiScenario($this);
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-03-10');
    lcdStorna($this, $s, $incasso);
    DB::table('scritture_contabili')->where('id', $incasso)->update(['natura_storno' => null]);
    DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->update(['created_by' => null]);

    $pagina = idiPagina($this, $s, $incasso);

    expect($pagina['utenteStornatore'])->toBeNull()
        ->and($pagina['storno'])->toMatchArray(['natura' => null, 'natura_testo' => 'Natura non dichiarata: stornato prima che il programma la chiedesse', 'vale_dal' => '2026-03-20']);
});
