<?php

use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/GestionaleTestHelpers.php';

/**
 * Regressione beta.15 — Indicatore di sforamento di budget nel cruscotto.
 *
 * Bug storico: DashboardController calcolava `is_sforo` (per capitolo) e
 * `has_sforo` (globale) confrontando la spesa reale con `item['budget']`, che
 * BudgetCoverageService espone come `max(budget preventivato, speso reale)` —
 * il "fabbisogno reale". Il confronto `speso > max(preventivo, speso)` è SEMPRE
 * falso per costruzione: la spia di sforamento non si accendeva mai, nemmeno con
 * una spesa palesemente fuori budget. Il fix introduce `budget_teorico` (il
 * preventivo puro, senza il `max`) e confronta la spesa contro quello.
 *
 * Questi test creano una spesa comune reale di 1.500€ su un capitolo con budget
 * preventivato di 1.000€, non coperta da alcun piano rate, e verificano che il
 * cruscotto la segnali come sforo — prima e dopo la ratifica assembleare.
 *
 * Nota unità: `importo_imponibile` in input è in euro (il service lo converte
 * in centesimi ×100), mentre `Conto::importo` è già in centesimi.
 */
if (! function_exists('creaScenarioSforoNonCoperto')) {
    function creaScenarioSforoNonCoperto(): array
    {
        // setupContabile() esegue già Event::fake([FatturaRegistrata::class]).
        [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

        // Il cruscotto itera $esercizio->gestioni (pivot esercizio_gestione): dalla beta.66 il
        // legame lo costruisce `setupContabile()` per tutti, non più un test alla volta.

        // Budget preventivato del capitolo: 1.000€ (in centesimi).
        $capitolo->update(['importo' => 100000]);

        // Fattura comune di 1.500€ fuori budget, registrata come sforo motivato
        // (la presenza di override_budget forza stato_approvazione = 'sforo_motivato').
        $data = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione'        => 'Spesa comune fuori budget',
                'importo_imponibile' => 1500, // euro → 150000 cent, > 100000 cent di budget
                'aliquota_iva'       => 0,
                'conto_id'           => $capitolo->id,
                'is_sopravvenienza'  => false,
            ]],
            'dati_extra' => [
                'fiscal'          => [],
                'competenza'      => null,
                'override_budget' => [
                    'strategia_rientro' => 'rata_integrativa',
                    'motivazione'       => 'Spesa urgente deliberata',
                ],
            ],
        ]);

        $fattura = (new FatturaPassivaService())->registraFattura($data, $condominio->id);

        Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        $user->givePermissionTo('Accesso pannello amministratore');

        return [$condominio, $fattura, $user];
    }
}

it('accende is_sforo e has_sforo quando la spesa comune supera il budget preventivato e non è coperta', function () {
    [$condominio, $fattura, $user] = creaScenarioSforoNonCoperto();

    expect($fattura->stato_approvazione)->toBe('sforo_motivato');

    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('copertura.has_sforo', true) // allarme globale acceso
            ->has('copertura.orfani', 1)
            ->has('copertura.orfani.0', fn ($json) => $json
                ->where('is_sforo', true)              // spia per-capitolo accesa
                ->where('strategia', 'rata_integrativa')
                ->etc()
            )
        );
});

it('mantiene is_sforo acceso dopo la ratifica assembleare finché il deficit non è finanziato', function () {
    [$condominio, $fattura, $user] = creaScenarioSforoNonCoperto();

    // Ratifica dello sforo motivato: sforo_motivato → approvata.
    $this->actingAs($user)
        ->post("/admin/gestionale/{$condominio->id}/fatture/{$fattura->id}/approva-sforo", [
            'note' => 'Ratifica assembleare Art. 1135 c.c.',
        ])
        ->assertSessionHasNoErrors();

    expect($fattura->refresh()->stato_approvazione)->toBe('approvata');

    // La ratifica NON copre il deficit: il denaro mancante non è ancora stato
    // chiesto ai condomini con un piano rate. `is_sforo` deve restare true;
    // solo la `strategia` decade da 'rata_integrativa' a 'nessuna'.
    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('copertura.orfani', 1)
            ->has('copertura.orfani.0', fn ($json) => $json
                ->where('is_sforo', true)
                ->where('strategia', 'nessuna')
                ->etc()
            )
        );
});
