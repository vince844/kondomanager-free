<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Incolla da un foglio di calcolo», nella pagina delle quote di una tabella.
 *
 * La finestra legge il testo incollato **nel browser** (`incollaMillesimi.ts`, con le sue prove
 * Vitest) e riempie la griglia: il salvataggio resta `TabellaQuotaController::update()`, invariato.
 * Del server le servono due cose, e sono le due che questo file tiene ferme:
 *
 * - l'elenco delle unità della pagina porta **particella e subalterno**, perché le tabelle dei
 *   tecnici sono spesso per subalterno e la finestra riconosce l'unità anche da lì; la `select()`
 *   dell'elenco è chiusa, e una colonna che non vi è scritta alla pagina non arriva;
 * - ciò che la finestra produce — righe nuove senza `id` accanto a righe già salvate, valori col
 *   punto e fino a cinque decimali — è una richiesta che il salvataggio di oggi accetta così com'è.
 *
 * L'elenco delle unità porta anche la **palazzina**, che c'era già: la finestra la scrive accanto
 * al nome («Appartamento 1 · Palazzina B»), perché in un complesso il nome da solo non dice quale.
 */

use App\Models\Condominio;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function qifUtente(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    // Dalla Coda 714 le tabelle e i millesimi si cambiano con «Modifica condomini».
    Permission::firstOrCreate(['name' => 'Modifica condomini', 'guard_name' => 'web']);
    $utente = \App\Models\User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore', 'Modifica condomini');

    return $utente;
}

/** Un condominio con l'esercizio e il piano dei conti che i middleware della rotta pretendono. */
function qifCondominio(): Condominio
{
    $condominio = Condominio::factory()->create();

    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione Ordinaria', 'tipo' => 'ordinaria', 'attiva' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('piani_conti')->insert([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestioneId, 'nome' => 'Piano dei conti 2026',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $condominio;
}

function qifTabella(Condominio $condominio): Tabella
{
    return Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà generale', 'tipo' => 'standard', 'quota' => 'millesimi',
        'numero_decimali' => 2, 'attiva' => true, 'data_inizio' => now(),
    ]);
}

function qifUnita(Condominio $condominio, string $nome, ?string $particella = null, ?string $subalterno = null): int
{
    return DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'codice_immobile' => 'U'.uniqid(), 'nome' => $nome, 'attivo' => true,
        'particella_catasto' => $particella, 'subalterno_catasto' => $subalterno, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('l\'elenco delle unità della pagina porta particella e subalterno', function () {
    $condominio = qifCondominio();
    $tabella = qifTabella($condominio);
    $primo = qifUnita($condominio, 'Appartamento 1', '658', '10');
    qifUnita($condominio, 'Negozio');
    $palazzina = DB::table('palazzine')->insertGetId([
        'condominio_id' => $condominio->id, 'name' => 'Palazzina B', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('immobili')->where('id', $primo)->update(['palazzina_id' => $palazzina]);

    $this->actingAs(qifUtente())
        ->withoutVite()
        ->get(route('admin.gestionale.tabelle.quote.index', [$condominio->id, $tabella->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->component('gestionale/tabelle/quote/QuoteList')
            ->has('immobili', 2)
            ->where('immobili.0.nome', 'Appartamento 1')
            ->where('immobili.0.particella_catasto', '658')
            ->where('immobili.0.subalterno_catasto', '10')
            ->where('immobili.1.particella_catasto', null)
            ->where('immobili.1.subalterno_catasto', null)
            ->where('immobili.0.palazzina.name', 'Palazzina B')
            ->where('immobili.1.palazzina', null)
        );
});

test('ciò che la finestra applica si salva con il salvataggio di oggi: una riga cambiata, una nuova, una intatta', function () {
    $condominio = qifCondominio();
    $tabella = qifTabella($condominio);
    $primo = qifUnita($condominio, 'Appartamento 1', '658', '10');
    $secondo = qifUnita($condominio, 'Appartamento 2', '658', '11');
    $terzo = qifUnita($condominio, 'Appartamento 3', '658', '22');

    $quotaPrimo = DB::table('quote_tabella')->insertGetId([
        'tabella_id' => $tabella->id, 'immobile_id' => $primo, 'valore' => 500, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $quotaSecondo = DB::table('quote_tabella')->insertGetId([
        'tabella_id' => $tabella->id, 'immobile_id' => $secondo, 'valore' => 300, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Incollato: «Appartamento 2 → 95,97» e «658/22 → 67,455». L'Appartamento 1 non è nel foglio.
    $this->actingAs(qifUtente())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [
                ['id' => $quotaPrimo, 'immobile' => ['id' => $primo], 'valore' => '500.00'],
                ['id' => $quotaSecondo, 'immobile' => ['id' => $secondo], 'valore' => '95.97'],
                ['id' => null, 'immobile' => ['id' => $terzo], 'valore' => '67.455'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $valori = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->pluck('valore', 'immobile_id')
        ->map(fn ($v) => (float) $v)->all();

    expect($valori)->toBe([$primo => 500.0, $secondo => 95.97, $terzo => 67.455]);
});
