<?php

/**
 * La ricevuta dice con quale criterio è imputato il versamento (decisione 218, punto 4): una riga sotto «A che cosa va il versamento».
 * L'ordine delle scadenze; «indicata da chi ha versato» con la sua nota (è sua); «scelta dall'amministratore» senza la sua nota, che può
 * essere interna come la causale (decisione 202.1); la compensazione. Per «fuori ordine» e per gli incassi di prima nessuna riga.
 */

use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
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
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il PDF della ricevuta di un incasso, come HTML. */
function rimHtml(array $s, int $incasso): string
{
    return View::make('pdf.gestionale.ricevute_incasso', [
        'condominio' => $s['c'],
        'ricevute'   => [['contenuto' => app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($incasso)), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '20/03/2026', 'indirizzo_per_posta' => false]],
    ])->render();
}

it('una riga per ogni criterio: la nota di chi ha versato sì, quella dell\'amministratore no; niente per «fuori ordine»', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'rata_zero', [['ugo', 'A', -5000]], 30000, '2026-01-05');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-01');
    $incassa = function (array $righe, array $extra = []) use ($s) {
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $extra + [
            'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20',
            'importo_totale' => number_format(max(0, array_sum($righe)) / 100, 2, '.', ''), 'descrizione' => 'Bonifico', 'eccedenza' => '0.00',
            'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        ])->assertSessionHasNoErrors();

        return (int) DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->max('id');
    };

    $fuori = $incassa([lcdQuota($s, 'ugo', 2) => 2000]);
    $indicata = $incassa([lcdQuota($s, 'ugo', 2) => 3000], ['imputazione_scelta' => 'indicata_dal_versante', 'imputazione_nota' => 'Bonifico per la rata di febbraio']);
    $scelta = $incassa([lcdQuota($s, 'ugo', 3) => 1000], ['imputazione_scelta' => 'scelta_amministratore', 'imputazione_nota' => 'Nota interna: lite in corso']);
    $ordine = $incassa([lcdQuota($s, 'ugo', 1) => 10000]);
    $compensazione = $incassa([lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 2) => 5000]);
    expect(DB::table('scritture_contabili')->whereIn('id', [$fuori, $indicata, $scelta, $ordine, $compensazione])->orderBy('id')->pluck('imputazione')->all())
        ->toBe(['fuori_ordine', 'indicata_dal_versante', 'scelta_amministratore', 'ordine_scadenze', 'compensazione']);

    $frase = fn (int $id) => app(RicevutaIncasso::class)->per(ScritturaContabile::findOrFail($id))['imputazione'];

    expect($frase($ordine))->toBe('Imputazione: secondo l\'ordine delle scadenze.')
        ->and($frase($indicata))->toBe('Imputazione: indicata da chi ha versato: «Bonifico per la rata di febbraio».')
        ->and($frase($scelta))->toBe('Imputazione: scelta dall\'amministratore.')
        ->and($frase($compensazione))->toBe('Imputazione: compensazione con il credito.')
        ->and($frase($fuori))->toBeNull()
        // Nel PDF il testo passa da `e()`, come ogni testo della vista.
        ->and(rimHtml($s, $indicata))->toContain(e('Imputazione: indicata da chi ha versato: «Bonifico per la rata di febbraio».'))
        ->and(rimHtml($s, $ordine))->toContain(e('Imputazione: secondo l\'ordine delle scadenze.'))
        ->and(rimHtml($s, $scelta))->toContain(e('Imputazione: scelta dall\'amministratore.'))
        ->and(rimHtml($s, $scelta))->not->toContain('lite in corso')
        ->and(rimHtml($s, $fuori))->not->toContain('Imputazione:')
        ->and($this->actingAs($this->user)->getJson(route('admin.gestionale.ricevute.opzioni', [$s['c'], $indicata]))->assertOk()->json('imputazione'))
        ->toBe('Imputazione: indicata da chi ha versato: «Bonifico per la rata di febbraio».');

    // Nessuna parola di effetto legale.
    foreach (['quietanza liberatoria', 'moroso', 'esigibile', 'diffidato', 'ingiungibile'] as $parola) {
        expect(rimHtml($s, $indicata))->not->toContain($parola);
    }
});
