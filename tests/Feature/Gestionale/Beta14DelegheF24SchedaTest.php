<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (10) — la scheda della delega F24: il codice fiscale e lo stato «Confermata».
 *
 * (b) La delega copia il codice fiscale del condominio alla sua nascita. Se il condominio lo prende dopo, la scheda continuava a
 * dire «manca» e mandava a un pulsante che rifà le bozze con un altro indirizzo. Un punto solo, `DelegaF24::codiceFiscaleDelContribuente()`
 * (quello della delega se c'è, altrimenti quello del condominio), lo dice alla scheda e alla stampa del modello.
 *
 * (c) Lo stato «Confermata» c'era nel filtro, nei badge e nelle azioni, ma nessuna rotta ci portava (decisione 696, D6): ora la scheda
 * ha «Conferma la delega» (da bozza) e «Riporta in bozza» (da confermata), mai su una versata. La delega confermata resta com'è anche
 * dopo «Aggiorna scadenze»: non si cancella né si rifà, e il suo promemoria la segue.
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\DelegaF24;
use App\Models\User;
use App\Services\Gestionale\ModelloF24Service;
use App\Services\Gestionale\PagamentoFornitoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('amministratore');
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Promemoria tecnici per l\'amministratore']);
});

/** Un condominio con un fornitore in appalto al 4 %, e una delega in bozza di € 800,00 (€ 20.000,00 × 4 %), senza codice fiscale. */
function b14sDelega($test, ?string $codiceFiscaleCondominio = null): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();
    $ctx[0]->update(['codice_fiscale' => $codiceFiscaleCondominio]);

    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => 20000.00,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => now()->toDateString(),
        'esercizio_id' => $ctx[1]->id,
    ]));
    $test->actingAs($test->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();

    return [$ctx, DelegaF24::where('condominio_id', $ctx[0]->id)->sole()];
}

function b14sScheda($test, array $ctx, DelegaF24 $delega)
{
    return $test->actingAs($test->admin)->get(route('admin.gestionale.f24.show', [$ctx[0]->id, $delega->id]))->assertOk();
}

/** I promemoria aperti delle ritenute, per delega. */
function b14sPromemoria(int $condominioId): \Illuminate\Support\Collection
{
    return Evento::where('meta->type', 'versamento_ritenuta')
        ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))
        ->where('is_completed', false)->orderBy('id')->get();
}

// ── (b) il codice fiscale ────────────────────────────────────────────────────────────────────────────────

test('T6_754_10 — la bozza nata senza codice fiscale: il condominio lo prende dopo, e la scheda e la stampa del modello lo mostrano', function () {
    [$ctx, $delega] = b14sDelega($this);
    expect($delega->cf_contribuente)->toBeNull();

    // Prima: né la delega né il condominio lo hanno.
    b14sScheda($this, $ctx, $delega)->assertInertia(fn (AssertableInertia $p) => $p->where('codice_fiscale_contribuente', null));

    // Il condominio prende il codice fiscale dopo la nascita della bozza.
    $ctx[0]->update(['codice_fiscale' => '80012345678']);

    b14sScheda($this, $ctx, $delega)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('codice_fiscale_contribuente', '80012345678')
        ->where('delega.id', $delega->id)
        ->where('campi_mancanti', fn ($m) => ! collect($m)->contains(fn ($c) => str_contains(strtolower($c), 'codice fiscale')))
    );

    $quadro = app(ModelloF24Service::class)->quadro($delega->fresh());
    expect(implode('', $quadro['contribuente']['codice_fiscale']))->toBe('80012345678')
        ->and($quadro['mancanti'])->not->toContain('Codice fiscale del condominio')
        // la scheda non scrive niente: la delega resta com'era
        ->and($delega->fresh()->cf_contribuente)->toBeNull();
});

test('T6_754_10 — un codice fiscale vuoto sulla delega (stringa vuota) non nasconde quello del condominio; uno scritto sulla delega vince', function () {
    [$ctx, $delega] = b14sDelega($this, '80012345678');
    // Una delega con il campo vuoto e non nullo: `??` lo avrebbe preso per buono.
    $delega->forceFill(['cf_contribuente' => ''])->save();

    expect($delega->fresh()->codiceFiscaleDelContribuente())->toBe('80012345678');
    b14sScheda($this, $ctx, $delega)->assertInertia(fn (AssertableInertia $p) => $p->where('codice_fiscale_contribuente', '80012345678'));
    expect(implode('', app(ModelloF24Service::class)->quadro($delega->fresh())['contribuente']['codice_fiscale']))->toBe('80012345678');

    // Il codice fiscale congelato sulla delega ha la precedenza su quello di oggi del condominio.
    $delega->forceFill(['cf_contribuente' => '11122233344'])->save();
    expect($delega->fresh()->codiceFiscaleDelContribuente())->toBe('11122233344');
    b14sScheda($this, $ctx, $delega)->assertInertia(fn (AssertableInertia $p) => $p->where('codice_fiscale_contribuente', '11122233344'));
});

// ── (c) «Conferma la delega» e «Riporta in bozza» ───────────────────────────────────────────────────────

test('T6_754_10 — «Conferma la delega» porta la bozza a confermata, «Riporta in bozza» la rimette in bozza; con un messaggio che dice che cosa è successo', function () {
    [$ctx, $delega] = b14sDelega($this, '80012345678');

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.conferma-delega', [$ctx[0]->id, $delega->id]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'] ?? '', 'confermata'));
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA);

    // La scheda di una confermata: il motivo del blocco c'è, e dice il vero.
    b14sScheda($this, $ctx, $delega)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('delega.stato', 'confermata')
        ->where('motivo_blocco_modifica', 'La delega è confermata: riportala in bozza per modificarla.')
    );

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'] ?? '', 'bozza'));
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);
});

test('T6_754_10 — la delega confermata resta com\'è dopo «Aggiorna scadenze»: stessa riga, stesso id, niente doppione, e il promemoria la segue', function () {
    [$ctx, $delega] = b14sDelega($this, '80012345678');
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.conferma-delega', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();

    $promemoria = b14sPromemoria($ctx[0]->id);
    expect($promemoria)->toHaveCount(1)
        ->and($promemoria->first()->meta['context']['delega_f24_id'])->toBe($delega->id);
    $righe = DB::table('righe_f24')->where('delega_f24_id', $delega->id)->count();

    $this->actingAs($this->admin)->from(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();

    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->pluck('id')->all())->toBe([$delega->id])
        ->and($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA)
        ->and(DB::table('righe_f24')->where('delega_f24_id', $delega->id)->count())->toBe($righe)
        ->and((int) $delega->fresh()->totale_debito)->toBe(80000);

    $dopo = b14sPromemoria($ctx[0]->id);
    expect($dopo)->toHaveCount(1)
        ->and($dopo->first()->id)->toBe($promemoria->first()->id)
        ->and($dopo->first()->meta['context']['delega_f24_id'])->toBe($delega->id);

    // E dopo il ritorno in bozza, «Aggiorna scadenze» la rifà come prima.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();
    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->count())->toBe(1)
        ->and(DelegaF24::where('condominio_id', $ctx[0]->id)->sole()->stato)->toBe(StatoDelegaF24::BOZZA);
});

test('T6_754_10 — una delega versata, stornata o annullata non si conferma e non si riporta in bozza; una già confermata non si conferma due volte', function () {
    [$ctx, $delega] = b14sDelega($this, '80012345678');
    $conferma = fn () => $this->actingAs($this->admin)->post(route('admin.gestionale.f24.conferma-delega', [$ctx[0]->id, $delega->id]));
    $riporta = fn () => $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$ctx[0]->id, $delega->id]));

    // Una bozza non si «riporta»; una confermata non si riconferma.
    $riporta()->assertSessionHasErrors('stato_delega');
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);
    $conferma()->assertSessionHasNoErrors();
    $conferma()->assertSessionHasErrors('stato_delega');
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA);

    // Versata: sotto sigillo.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
        'data_versamento' => now()->toDateString(), 'conto_corrente_id' => $ctx[4],
    ])->assertSessionHasNoErrors();
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::VERSATA);
    $scritture = DB::table('scritture_contabili')->count();
    $riporta()->assertSessionHasErrors('stato_delega');
    $conferma()->assertSessionHasErrors('stato_delega');
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::VERSATA)
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture);

    // Annullata e stornata: lo stesso.
    foreach ([StatoDelegaF24::ANNULLATA, StatoDelegaF24::STORNATA] as $stato) {
        $delega->forceFill(['stato' => $stato])->save();
        $riporta()->assertSessionHasErrors('stato_delega');
        $conferma()->assertSessionHasErrors('stato_delega');
        expect($delega->fresh()->stato)->toBe($stato);
    }
});

test('T6_754_10 — le due rotte nuove hanno la guardia delle altre azioni: una delega di un altro condominio è un 404, chi non ha il pannello non passa', function () {
    [$a, $delegaA] = b14sDelega($this, '80012345678');
    [$b, $delegaB] = b14sDelega($this, '80099999999');
    expect($a[0]->id)->not->toBe($b[0]->id);

    // La delega di B dall'indirizzo di A.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.conferma-delega', [$a[0]->id, $delegaB->id]))->assertNotFound();
    expect($delegaB->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);
    $delegaB->forceFill(['stato' => StatoDelegaF24::CONFERMATA])->save();
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.riporta-in-bozza', [$a[0]->id, $delegaB->id]))->assertNotFound();
    expect($delegaB->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA);

    // Un utente senza accesso al pannello.
    $inquilino = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($inquilino)->post(route('admin.gestionale.f24.conferma-delega', [$a[0]->id, $delegaA->id]))->assertForbidden();
    expect($delegaA->fresh()->stato)->toBe(StatoDelegaF24::BOZZA);
});
