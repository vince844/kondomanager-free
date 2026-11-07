<?php

/**
 * Le rotte nuove e cambiate della 1.12.0-beta.3 (l'ordine dell'imputazione, lo storno con la natura, la pagina dell'incasso, la situazione
 * debitoria, la dashboard col Treasury, i filtri ricordati delle ricevute) contro chi è fuori dal pannello e contro gli id di un altro
 * condominio. Ogni rifiuto controlla anche che non si scriva niente.
 */

use App\Models\User;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function sicUtente(string $ruolo): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $r = Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

/** Ugo (A) e Vera (B), tre rate da 10000 (05/01, 05/02, 05/03), tutte emesse; Ugo ha versato la rata 2. */
function sicScenario($test): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['vera', 500]], 3, 'prima_rata', [], 60000, '2026-01-05');
    lcdEmetti($test, $s, [1, 2, 3], '2026-01-01');
    $s['incasso'] = lcdIncassa($test, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000], '2026-03-20');

    return $s;
}

function sicCorpo(array $s, int $paganteId, array $righe, array $extra = []): array
{
    return $extra + [
        'pagante_id' => $paganteId, 'importo_totale' => number_format(array_sum($righe) / 100, 2, '.', ''), 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ];
}

/** Lo stato delle tabelle che uno storno o un incasso cambiano. */
function sicFoto(): array
{
    return [
        DB::table('scritture_contabili')->count(),
        DB::table('righe_scritture')->count(),
        DB::table('quota_scrittura')->count(),
        (int) DB::table('rate_quote')->sum('importo_pagato'),
        DB::table('scritture_contabili')->whereNotNull('natura_storno')->count(),
        DB::table('scritture_contabili')->where('stato', 'annullata')->count(),
    ];
}

test('chi è fuori dal pannello (condòmino, fornitore, senza ruolo) non entra nelle rotte nuove e cambiate: 403, niente scritto, nessun filtro ricordato', function () {
    $s = sicScenario($this);
    $c = $s['c']->id;
    $ugo = $s['persone']['ugo']->id;
    $rotte = [
        'ordine'           => ['POST', route('admin.gestionale.movimenti-rate.ordine', [$c]), sicCorpo($s, $ugo, [lcdQuota($s, 'ugo', 3) => 10000])],
        'storno'           => ['POST', route('admin.gestionale.movimenti-rate.storno', [$c, $s['incasso']]), ['natura_storno' => 'errore_registrazione']],
        'storno.anteprima' => ['GET', route('admin.gestionale.movimenti-rate.storno.anteprima', [$c, $s['incasso']]), []],
        'show'             => ['GET', route('admin.gestionale.movimenti-rate.show', [$c, $s['incasso']]), []],
        'situazione'       => ['GET', route('admin.gestionale.situazione-debitoria', [$c, 'anagrafica_id' => $ugo]), []],
        'dashboard'        => ['GET', route('admin.gestionale.index', [$c]), []],
        'ricevute.index'   => ['GET', route('admin.gestionale.ricevute.index', [$c, 'ricevuta' => 'tutte', 'persone' => [$ugo]]), []],
        'ricevute.riapri'  => ['GET', route('admin.gestionale.ricevute.stampa.riapri', [$c]), []],
    ];
    $prima = sicFoto();

    $senzaRuolo = User::factory()->create(['email_verified_at' => now()]);
    foreach (['utente' => sicUtente('utente'), 'fornitore' => sicUtente('fornitore'), 'nessuno' => $senzaRuolo] as $chi => $utente) {
        foreach ($rotte as $nome => [$metodo, $url, $corpo]) {
            $r = $metodo === 'GET' ? $this->actingAs($utente)->getJson($url) : $this->actingAs($utente)->postJson($url, $corpo);
            expect($r->status())->toBe(403, "{$chi} su {$nome}");
        }
    }

    expect(sicFoto())->toBe($prima)
        ->and(session()->has('ricevute.filtri.'.$c))->toBeFalse();
});

test('lo storno con la natura di un incasso di un altro condominio: 403 e niente scritto; l\'anteprima e la pagina non lo aprono', function () {
    $s = sicScenario($this);
    $altro = sicScenario($this);
    $prima = sicFoto();

    foreach (['errore_registrazione', 'versamento_tornato'] as $natura) {
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$altro['c'], $s['incasso']]), ['natura_storno' => $natura])
            ->assertForbidden();
    }
    $anteprima = $this->actingAs($this->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$altro['c'], $s['incasso']]));
    expect($anteprima->status())->toBeIn([403, 404])
        ->and($anteprima->json('natura'))->toBeNull();
    expect($this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.show', [$altro['c'], $s['incasso']]))->status())->toBeIn([403, 404]);

    expect(sicFoto())->toBe($prima)
        ->and(DB::table('scritture_contabili')->where('id', $s['incasso'])->value('stato'))->toBe('registrata')
        ->and(DB::table('scritture_contabili')->where('id', $s['incasso'])->value('natura_storno'))->toBeNull();
});

test('la rotta dell\'ordine con id mescolati fra condomini risponde 422; con la quota di un vicino non dice niente del vicino', function () {
    $s = sicScenario($this);
    $altro = sicScenario($this);
    $url = route('admin.gestionale.movimenti-rate.ordine', [$s['c']]);
    $ugo = $s['persone']['ugo']->id;
    $prima = sicFoto();

    // Il pagante di qui, la quota dell'altro condominio.
    $this->actingAs($this->user)->postJson($url, sicCorpo($s, $ugo, [lcdQuota($altro, 'ugo', 3) => 10000]))->assertStatus(422)->assertJsonValidationErrors('dettaglio_pagamenti.0.rata_id');
    // Il pagante dell'altro condominio, la quota di qui.
    $this->actingAs($this->user)->postJson($url, sicCorpo($s, $altro['persone']['ugo']->id, [lcdQuota($s, 'ugo', 3) => 10000]))->assertStatus(422)->assertJsonValidationErrors('pagante_id');
    // «Versato da» e la gestione dell'altro condominio.
    $this->actingAs($this->user)->postJson($url, sicCorpo($s, $ugo, [lcdQuota($s, 'ugo', 3) => 10000], ['versato_da_id' => $altro['persone']['vera']->id]))->assertStatus(422)->assertJsonValidationErrors('versato_da_id');
    $this->actingAs($this->user)->postJson($url, sicCorpo($s, $ugo, [lcdQuota($s, 'ugo', 3) => 10000], ['gestione_id' => $altro['g']->id]))->assertStatus(422)->assertJsonValidationErrors('gestione_id');
    // Un elenco al posto dell'id: rifiutato, non agganciato.
    $this->actingAs($this->user)->postJson($url, sicCorpo($s, $ugo, [lcdQuota($s, 'ugo', 3) => 10000], ['pagante_id' => [$altro['persone']['ugo']->id => $ugo]]))->assertStatus(422);

    // La quota della vicina, con Ugo come pagante: la risposta parla solo delle rate di Ugo.
    $r = $this->actingAs($this->user)->postJson($url, sicCorpo($s, $ugo, [lcdQuota($s, 'vera', 3) => 10000]))->assertOk();
    expect(json_encode($r->json()))->not->toContain('Vera')->not->toContain('Int. B')
        ->and($r->json('rata_aperta.unita'))->toBe('Int. A');

    expect(sicFoto())->toBe($prima);
});

test('lo storno con una natura che non è un testo o che non esiste: rifiutato, niente 500, niente scritto', function () {
    $s = sicScenario($this);
    $prima = sicFoto();

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $s['incasso']]), ['natura_storno' => ['errore_registrazione']])
        ->assertSessionHasErrors('natura_storno');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $s['incasso']]), ['natura_storno' => 'boh'])
        ->assertStatus(422);
    expect(sicFoto())->toBe($prima);
});

test('la scelta e la nota dell\'imputazione che non sono testi: 422, niente scritto', function () {
    $s = sicScenario($this);
    $prima = sicFoto();
    $corpo = fn (array $extra) => sicCorpo($s, $s['persone']['ugo']->id, [lcdQuota($s, 'ugo', 3) => 10000], $extra + [
        'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20', 'descrizione' => 'Bonifico', 'gestione_id' => $s['g']->id,
    ]);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $corpo(['imputazione_scelta' => ['scelta_amministratore']]))->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $corpo(['imputazione_scelta' => 'scelta_amministratore', 'imputazione_nota' => ['x']]))->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $corpo(['imputazione_scelta' => 'fuori_ordine']))->assertStatus(422);
    expect(sicFoto())->toBe($prima);
});

test('la previsione di cassa di un condominio nomina solo le persone e le unità di quel condominio, anche se una persona dell\'altro è associata qui', function () {
    $s = sicScenario($this);
    $altro = sicScenario($this);
    // Ugo dell'altro condominio è associato anche qui, senza unità: il suo scaduto là non entra qui.
    $altro['persone']['ugo']->condomini()->syncWithoutDetaching([$s['c']->id]);

    $qui = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    $ids = array_column($qui['morositaImpattanti'], 'anagraficaId');

    expect($ids)->not->toBeEmpty()
        ->and(array_diff($ids, [$s['persone']['ugo']->id, $s['persone']['vera']->id]))->toBe([])
        ->and($ids)->not->toContain($altro['persone']['ugo']->id)
        ->and(array_unique(array_map(fn ($m) => $m['routeParams']['condominio'], $qui['morositaImpattanti'])))->toBe([$s['c']->id]);
    foreach ($qui['morositaImpattanti'] as $m) {
        expect($m['immobile'])->toBeIn(['Int. A', 'Int. B', 'Int. A, Int. B']);
    }
});

test('un id di quota con un segno o uno spazio davanti passa «integer» ed «exists»: si legge come il numero che è, niente 500', function () {
    $s = sicScenario($this);
    $ugo = $s['persone']['ugo']->id;
    $quota = lcdQuota($s, 'ugo', 3);
    $esitoPulito = $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), sicCorpo($s, $ugo, [$quota => 10000]))->assertOk()->json();

    foreach (['+'.$quota, ' '.$quota] as $id) {
        $corpo = sicCorpo($s, $ugo, [$quota => 10000]);
        $corpo['dettaglio_pagamenti'][0]['rata_id'] = $id;

        expect($this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), $corpo)->assertOk()->json())->toBe($esitoPulito);
    }

    $corpo = sicCorpo($s, $ugo, [$quota => 10000]);
    $corpo['dettaglio_pagamenti'][0]['rata_id'] = '+'.$quota;
    $corpo += ['cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20', 'descrizione' => 'Bonifico', 'gestione_id' => $s['g']->id];
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $corpo)->assertSessionHasNoErrors()->assertRedirect();

    expect((int) DB::table('rate_quote')->where('id', $quota)->value('importo_pagato'))->toBe(10000);
});

test('la nota dell\'imputazione nella ricevuta: quella «indicata» resta testo nel PDF (niente immagini né tag); quella della scelta interna non esce né nel PDF né nella finestra', function () {
    $s = sicScenario($this);
    $incassa = function (array $righe, array $extra) use ($s) {
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $extra + sicCorpo($s, $s['persone']['ugo']->id, $righe, [
            'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20', 'descrizione' => 'Bonifico', 'gestione_id' => $s['g']->id,
        ]))->assertSessionHasNoErrors();

        return (int) DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->max('id');
    };
    $html = fn (int $id) => \Illuminate\Support\Facades\View::make('pdf.gestionale.ricevute_incasso', [
        'condominio' => $s['c'],
        'ricevute'   => [['contenuto' => app(\App\Services\Gestionale\Ricevute\RicevutaIncasso::class)->per(\App\Models\Gestionale\ScritturaContabile::findOrFail($id)), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '20/03/2026', 'indirizzo_per_posta' => false]],
    ])->render();

    // La rata 1 è aperta: pagare la 3 è fuori ordine.
    $indicata = $incassa([lcdQuota($s, 'ugo', 3) => 5000], ['imputazione_scelta' => 'indicata_dal_versante', 'imputazione_nota' => '<img src="http://spia.example/p.png"><b>grassetto</b>']);
    $interna = $incassa([lcdQuota($s, 'ugo', 3) => 5000], ['imputazione_scelta' => 'scelta_amministratore', 'imputazione_nota' => 'SEGRETO lite con Vera']);

    expect($html($indicata))->not->toContain('<img src="http://spia')->not->toContain('<b>grassetto')->toContain('&lt;img')
        ->and($html($interna))->not->toContain('SEGRETO')
        ->and(json_encode($this->actingAs($this->user)->getJson(route('admin.gestionale.ricevute.opzioni', [$s['c'], $interna]))->assertOk()->json()))->not->toContain('SEGRETO');
});
