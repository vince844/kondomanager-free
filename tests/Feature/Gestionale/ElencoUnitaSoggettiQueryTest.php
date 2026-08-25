<?php

/**
 * beta.52 — L'elenco unità non fa una query per ogni soggetto.
 *
 * ⚠️ **Reperto della revisione avversariale.** La beta.52 ha aggiunto la colonna «Soggetti»
 * all'elenco unità, e con essa `->with([… , 'anagrafiche'])` su una query paginata. Il commento
 * che accompagnava quella riga dichiarava «è un eager load e non una query per riga»: era falso,
 * e per una ragione che non stava nel controller.
 *
 * `ImmobileAnagraficaResource::toArray()` apriva con
 * `$saldo = $this->whenLoaded('saldi') ? $this->saldi->first() : null;`. **`whenLoaded()` con un
 * solo argomento non restituisce un booleano:** su una relazione non caricata restituisce
 * `MissingValue`, che è un oggetto e quindi sempre truthy. Il ramo `null` era irraggiungibile e
 * `$this->saldi->first()` faceva un lazy load per ogni coppia (unità, soggetto). Misurato prima
 * della correzione su dieci unità con quattro soggetti ciascuna: **86 query, di cui 40 su
 * `saldi`**.
 *
 * Il difetto era **latente da prima**: la Resource la risolveva solo
 * `ImmobileAnagraficaController::index()`, che i saldi li carica. La colonna nuova lo ha svegliato.
 *
 * ## Cosa questo test NON copre
 *
 * Non fissa un numero massimo di query: fissa che il conteggio **non cresca con il numero di
 * soggetti**. Un tetto assoluto si romperebbe alla prima query aggiunta altrove per ragioni
 * legittime, e verrebbe alzato senza guardare — che è il modo in cui un presidio smette di
 * presidiare. Non copre l'elenco anagrafiche dell'unità, che i saldi li carica apposta e li mostra.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function elencoUnitaConSoggetti(int $unita, int $soggettiPerUnita): Condominio
{
    $condominio = Condominio::factory()->create();

    for ($i = 1; $i <= $unita; $i++) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome'          => "Interno $i",
            'interno'       => (string) $i,
            'descrizione'   => 'Unità di prova',
        ]);

        for ($k = 1; $k <= $soggettiPerUnita; $k++) {
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => Anagrafica::factory()->create()->id,
                'immobile_id'   => $immobile->id,
                'tipologia'     => 'proprietario',
                'quota'         => 100 / $soggettiPerUnita,
                'attivo'        => true,
                'data_inizio'   => now(),
            ]);
        }
    }

    return $condominio;
}

function utenteAmministratoreElenco(): User
{
    $utente = User::factory()->create();
    $utente->assignRole(
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web'])
    );

    return $utente;
}

/** Conta le query eseguite mentre si apre l'elenco unità di quel condominio. */
function queryPerElencoUnita(Condominio $condominio): int
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });

    test()->get(route('admin.gestionale.immobili.index', ['condominio' => $condominio->id]))
        ->assertOk();

    return $n;
}

it('il numero di query non cresce con il numero di soggetti collegati', function () {
    $this->actingAs(utenteAmministratoreElenco());

    // Stesse unità, soggetti quadruplicati. Con il lazy load la seconda pagina costava
    // 30 query in più — una per ogni soggetto aggiunto.
    $pochi  = queryPerElencoUnita(elencoUnitaConSoggetti(unita: 10, soggettiPerUnita: 1));
    $molti  = queryPerElencoUnita(elencoUnitaConSoggetti(unita: 10, soggettiPerUnita: 4));

    expect($molti)->toBeLessThanOrEqual($pochi + 2);
});
