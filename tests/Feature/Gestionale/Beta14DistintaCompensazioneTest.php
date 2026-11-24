<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (3): la distinta del pagamento contava due volte la compensazione.
 *
 * Il pagamento di una fattura di € 1.220,00 con una nota di credito di € 183,00 (€ 150,00 + IVA 22 %) ha tre righe nel legame fra il
 * pagamento e i documenti: la fattura con la parte pagata (€ 1.037,00), la fattura con la parte compensata (€ 183,00) e la nota con la
 * stessa parte (€ 183,00). La distinta le sommava tutte: «Totale Allocato» € 1.403,00 su una fattura di € 1.220,00 (prova R3 della
 * revisione delle guide). La regola del modello dice il contrario (`TipoAllocazioneFattura`): la somma delle righe «pagamento» è
 * l'uscita di cassa.
 *
 * La distinta dice per ogni riga come si chiude, la nota con il meno, e il piede ha due cifre: «Uscito dal conto» (€ 1.037,00, uguale
 * al «Netto pagato» del riepilogo e alla riga in avere della banca) e «Chiuso con note di credito» (€ 183,00). Letta con `pdftotext`,
 * dalla rotta del pagamento e da quella della distinta.
 */

use App\Enums\MetodoPagamento;
use App\Enums\Role;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    $this->admin = $this->admin->fresh();
    $this->travelTo(Carbon::parse('2026-03-20 10:00', 'Europe/Rome')->utc());
});

/** Paga dalla rotta: `$righe` è [documento, tipo, centesimi]. */
function b14dcPaga($test, array $ctx, array $righe): PagamentoFornitore
{
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    $test->actingAs($test->admin)->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => '2026-03-20',
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'bonifico_parlante' => false,
        'allow_overdraft' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => array_map(fn ($r) => ['fattura_id' => $r[0]->id, 'tipo' => $r[1]->value, 'importo_allocato_cents' => $r[2]], $righe),
    ])->assertSessionHasNoErrors();

    return PagamentoFornitore::latest('id')->firstOrFail();
}

/** La distinta dalla sua rotta, letta con `pdftotext -layout`: le righe, con gli spazi ridotti a uno. */
function b14dcDistinta($test, array $ctx, PagamentoFornitore $pagamento): array
{
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $test->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }
    $risposta = $test->actingAs($test->admin)->get(route('admin.gestionale.pagamenti-fornitori.distinta', [$ctx[0], $pagamento]))->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'dst').'.pdf';
    file_put_contents($file, $risposta->streamedContent());
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return array_values(array_filter(array_map(fn ($r) => trim(preg_replace('/[\s\x{a0}]+/u', ' ', $r)), explode("\n", $testo))));
}

function b14dcRiga(array $righe, string $inizio): ?string
{
    foreach ($righe as $riga) {
        if (str_contains($riga, $inizio)) {
            return $riga;
        }
    }

    return null;
}

it('R3: la distinta dice € 1.037,00 usciti dal conto e € 183,00 chiusi con la nota, non più € 1.403,00', function () {
    $ctx = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-12', 'data_documento' => '2026-03-10']);
    $nota = registraFatturaServiceTest($ctx, [
        'tipo_documento' => 'nota_credito',
        'numero_documento' => 'NC-3',
        'data_documento' => '2026-03-12',
        'applica_ritenuta' => false,
        'righe' => [['descrizione' => 'Reso', 'importo_imponibile' => 150, 'aliquota_iva' => 22, 'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false]],
    ]);

    $pagamento = b14dcPaga($this, $ctx, [
        [$fattura, TipoAllocazioneFattura::PAGAMENTO, 103700],
        [$nota, TipoAllocazioneFattura::COMPENSAZIONE, 18300],
        [$fattura, TipoAllocazioneFattura::COMPENSAZIONE, 18300],
    ]);

    // Il giornale: DARE debiti € 1.220,00, AVERE banca € 1.037,00, AVERE debiti € 183,00.
    $banca = (int) DB::table('righe_scritture')->where('scrittura_id', $pagamento->scrittura_contabile_id)
        ->where('conto_contabile_id', $ctx[4])->where('tipo_riga', 'avere')->sum('importo');
    expect($banca)->toBe(103700)
        ->and((int) $pagamento->importo_netto)->toBe(103700);

    $righe = b14dcDistinta($this, $ctx, $pagamento);
    $testo = implode("\n", $righe);

    expect($testo)->not->toContain('1.403,00')
        ->and($testo)->not->toContain('Totale Allocato')
        ->and(b14dcRiga($righe, 'Uscito dal conto'))->toContain('€ 1.037,00')
        ->and(b14dcRiga($righe, 'Chiuso con note di credito'))->toContain('€ 183,00')
        ->and(b14dcRiga($righe, 'Netto pagato'))->toContain('€ 1.037,00');

    // Per ogni riga, come si chiude: la fattura pagata, la fattura compensata con la nota, la nota usata con il meno.
    $pagata = collect($righe)->first(fn ($r) => str_contains($r, 'FT-12') && str_contains($r, 'Pagamento'));
    $compensata = collect($righe)->first(fn ($r) => str_contains($r, 'FT-12') && str_contains($r, 'Compensazione con la nota di credito NC-3'));
    $usata = collect($righe)->first(fn ($r) => str_contains($r, 'NC-3') && str_contains($r, 'Nota di credito usata'));
    expect($pagata)->toContain('€ 1.037,00')
        ->and($compensata)->toContain('€ 183,00')
        ->and($usata)->toContain('- € 183,00');
});

it('la controprova: due fatture senza note, il totale uscito dal conto è la somma, e nessuna riga di note di credito', function () {
    $ctx = setupPagamentiHttp();
    $prima = registraFatturaServiceTest($ctx, ['numero_documento' => 'FT-20']);
    $seconda = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'FT-21',
        'righe' => [['descrizione' => 'Altro', 'importo_imponibile' => 500, 'aliquota_iva' => 22, 'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false]],
    ]);

    $pagamento = b14dcPaga($this, $ctx, [
        [$prima, TipoAllocazioneFattura::PAGAMENTO, 122000],
        [$seconda, TipoAllocazioneFattura::PAGAMENTO, 61000],
    ]);

    $righe = b14dcDistinta($this, $ctx, $pagamento);

    expect(b14dcRiga($righe, 'Uscito dal conto'))->toContain('€ 1.830,00')
        ->and(b14dcRiga($righe, 'Netto pagato'))->toContain('€ 1.830,00')
        ->and(b14dcRiga($righe, 'Chiuso con note di credito'))->toBeNull();
});
