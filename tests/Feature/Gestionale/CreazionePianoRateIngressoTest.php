<?php

/**
 * 1.11.0-beta.42, decisione 44 — la creazione di un piano rate accetta solo id del condominio dell'indirizzo.
 *
 * Prima bastava che gli id del corpo esistessero: con la gestione, una fattura, una voce, un saldo o una persona di un altro
 * condominio il piano nasceva qui con i numeri di là (la gestione di B con le quote sull'unità di B, la fattura di B segnata
 * come rateizzata, la voce di B impegnata da un piano di A, il saldo di B assorbito). Ogni campo ha il suo caso negativo — 422
 * e nessuna scrittura — e il controllo con gli id giusti, che lo stesso campo lascia passare.
 *
 * Cosa NON copre: le altre rotte dei piani rate (emissione e pubblicazione hanno il loro presidio in
 * `ScopingDelleRotteAnnidateTest`); la modifica di un piano esistente.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->a = cpiCondominio('A');
    $this->b = cpiCondominio('B');
});

/** Un condominio con esercizio, gestione ordinaria legata all'esercizio, una voce, un'unità con il suo proprietario e un saldo. */
function cpiCondominio(string $sigla): array
{
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => "Esercizio 2026 {$sigla}", 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => "Ordinaria {$sigla}", 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => "PC {$sigla}"]);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => "Spese generali {$sigla}", 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CPI-{$sigla}", 'nome' => "Interno {$sigla}", 'interno' => $sigla]);
    $persona = Anagrafica::forceCreate(['nome' => "Ugo {$sigla}", 'email' => "cpi-{$sigla}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => "CPIUGO{$sigla}0000000000"]);
    $persona->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $saldo = Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persona->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    $fornitore = \App\Models\Fornitore::create(['ragione_sociale' => "Impresa {$sigla}", 'partita_iva' => str_pad((string) ord($sigla), 11, '0', STR_PAD_LEFT)]);
    $fattura = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $c->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $e->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-{$sigla}",
        'data_documento' => '2026-03-01', 'data_scadenza' => '2026-04-01', 'importo_imponibile' => 50000, 'importo_iva' => 0, 'totale_documento' => 50000, 'netto_a_pagare' => 50000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('c', 'e', 'g', 'conto', 'persona', 'saldo', 'fattura');
}

/** Il corpo di un piano ordinario di A, senza generazione. */
function cpiCorpo(array $a, array $extra = []): array
{
    return array_merge(['tipo' => 'ordinario', 'gestione_id' => $a['g']->id, 'nome' => 'Preventivo di prova', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'genera_subito' => false], $extra);
}

/** Le chiavi d'errore della risposta (vuote se la richiesta è passata). */
function cpiErrori($risposta): array
{
    return $risposta->status() === 422 ? array_keys($risposta->json('errors')) : [];
}

it('decisione 44 — un id di un altro condominio nel corpo si rifiuta con 422 sul suo campo, e non si scrive niente', function (string $campo, Closure $corpo) {
    $saldoB = $this->b['saldo']->fresh()->toArray();

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$this->a['c'], $this->a['e']]), $corpo($this->a, $this->b));

    expect($r->status())->toBe(422)->and(cpiErrori($r))->toContain($campo)
        ->and(PianoRate::count())->toBe(0)
        ->and(DB::table('piano_rate_capitoli')->count())->toBe(0)
        ->and(DB::table('piano_rate_fatture')->count())->toBe(0)
        ->and($this->b['saldo']->fresh()->toArray())->toBe($saldoB);
})->with([
    'la gestione di B' => ['gestione_id', fn ($a, $b) => cpiCorpo($a, ['gestione_id' => $b['g']->id])],
    'una gestione di A che non è del suo esercizio' => ['gestione_id', function ($a, $b) {
        $altra = Gestione::factory()->create(['condominio_id' => $a['c']->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);

        return cpiCorpo($a, ['gestione_id' => $altra->id]);
    }],
    'la fattura di B nel carrello' => ['fatture_config.0.id', fn ($a, $b) => cpiCorpo($a, ['tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Infiltrazione dal tetto',
        'fatture_config' => [['id' => $b['fattura'], 'importo' => '500,00']]])],
    'la voce di B fra le voci' => ['capitoli_ids.0', fn ($a, $b) => cpiCorpo($a, ['capitoli_ids' => [$b['conto']->id]])],
    'la voce di B nella configurazione' => ['capitoli_config.0.id', fn ($a, $b) => cpiCorpo($a, ['capitoli_config' => [['id' => $b['conto']->id]]])],
    'la voce di B nella competenza' => ['competenze_capitoli.0.conto_id', fn ($a, $b) => cpiCorpo($a, ['competenze_capitoli' => [['conto_id' => $b['conto']->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-12-31']]]]])],
    'il saldo di B nel riparto manuale' => ['saldi_config.0.saldo_id', fn ($a, $b) => cpiCorpo($a, ['saldi_config' => [['saldo_id' => $b['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $a['persona']->id, 'importo' => '50,00']]]]])],
    'una persona di B nel riparto manuale' => ['saldi_config.0.ripartizioni.0.anagrafica_id', fn ($a, $b) => cpiCorpo($a, ['saldi_config' => [['saldo_id' => $a['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $b['persona']->id, 'importo' => '50,00']]]]])],
]);

it('decisione 44, controllo — con gli id del condominio dell\'indirizzo gli stessi campi passano', function () {
    $a = $this->a;
    $campi = [
        'gestione_id' => cpiCorpo($a),
        'capitoli_ids.0' => cpiCorpo($a, ['nome' => 'Con le voci', 'capitoli_ids' => [$a['conto']->id]]),
        'capitoli_config.0.id' => cpiCorpo($a, ['nome' => 'Con la configurazione', 'capitoli_config' => [['id' => $a['conto']->id]]]),
        'competenze_capitoli.0.conto_id' => cpiCorpo($a, ['nome' => 'Con la competenza', 'competenze_capitoli' => [['conto_id' => $a['conto']->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-12-31']]]]]),
        'saldi_config.0.saldo_id' => cpiCorpo($a, ['nome' => 'Con il saldo', 'saldi_config' => [['saldo_id' => $a['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $a['persona']->id, 'importo' => '50,00']]]]]),
        'saldi_config.0.ripartizioni.0.anagrafica_id' => cpiCorpo($a, ['nome' => 'Con la persona', 'saldi_config' => [['saldo_id' => $a['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $a['persona']->id, 'importo' => '50,00']]]]]),
        'fatture_config.0.id' => cpiCorpo($a, ['nome' => 'Con la fattura', 'tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Infiltrazione dal tetto',
            'fatture_config' => [['id' => $a['fattura'], 'importo' => '500,00']]]),
    ];
    foreach ($campi as $campo => $corpo) {
        $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$a['c'], $a['e']]), $corpo);
        expect(cpiErrori($r))->not->toContain($campo);
    }
});

it('rilievo T11 — il rifiuto non dice niente del condominio di là: né la cifra di un suo saldo nel riparto manuale, né il numero di un suo documento nel carrello', function () {
    $this->b['saldo']->update(['saldo_iniziale' => 73456]);
    DB::table('fatture_passive')->where('id', $this->b['fattura'])->update(['tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-RISERVATA-B']);

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$this->a['c'], $this->a['e']]), cpiCorpo($this->a, [
        'saldi_config' => [['saldo_id' => $this->b['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $this->a['persona']->id, 'importo' => '10,00']]]],
    ]));
    $f = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$this->a['c'], $this->a['e']]), cpiCorpo($this->a, [
        'tipo' => 'straordinario', 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Infiltrazione dal tetto', 'fatture_config' => [['id' => $this->b['fattura'], 'importo' => '500,00']],
    ]));

    // Prima: «Il riparto manuale del pregresso da € 734,56 non quadra…» e «Il documento n. NC-RISERVATA-B è una nota di credito…».
    expect($r->status())->toBe(422)->and($r->getContent())->not->toContain('734,56')->and(cpiErrori($r))->toContain('saldi_config.0.saldo_id')
        ->and($f->status())->toBe(422)->and($f->getContent())->not->toContain('NC-RISERVATA-B')->and(cpiErrori($f))->toContain('fatture_config.0.id')
        ->and(PianoRate::count())->toBe(0);
});

it('rilievo T-J — con la gestione di un altro condominio il rifiuto non dice niente di lei: né che è straordinaria, né che vi esiste un piano con quel nome', function () {
    $this->b['g']->update(['tipo' => 'straordinaria']);
    PianoRate::create(['gestione_id' => $this->b['g']->id, 'condominio_id' => $this->b['c']->id, 'esercizio_id' => $this->b['e']->id, 'nome' => 'Preventivo di prova', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'metodo_distribuzione' => 'prima_rata']);

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$this->a['c'], $this->a['e']]), cpiCorpo($this->a, ['gestione_id' => $this->b['g']->id]));

    // Prima: anche «Indica la data della delibera dell'assemblea…» e «Il campo nome è già in uso».
    expect($r->status())->toBe(422)->and(cpiErrori($r))->toBe(['gestione_id'])
        ->and(PianoRate::where('condominio_id', $this->a['c']->id)->count())->toBe(0);
});
