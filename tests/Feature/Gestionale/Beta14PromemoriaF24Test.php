<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 718 (decisione 675): il promemoria delle ritenute, uno per delega.
 *
 * Fino alla 1.12.0-beta.13 ogni pagamento con la ritenuta scriveva un promemoria «F24 Ritenuta» con il 16 del mese dopo, mentre la
 * delega applica il cumulo: un appalto pagato il 10/03/2026 con € 20,00 di ritenuta diceva 16/04 nell'Inbox e 16/06 nella delega
 * (caso F di t=202). Con la decisione: un promemoria per ogni delega aperta, con la sua scadenza («F24 da versare · ‹condominio›»,
 * «Apri la delega»); finché una ritenuta non è in nessuna delega, un promemoria solo per condominio («Prepara le deleghe F24 ·
 * ‹condominio›», «Prepara l'F24») con la data del cumulo. Lo scrive solo `PromemoriaDelleRitenute::allinea()`.
 *
 * Le cifre si rifanno a mano: appalto al 4 % dell'imponibile; sotto € 500,00 nel mese le ritenute fino a maggio si versano il
 * 16 giugno (martedì 16/06/2026); € 500,00 raggiunti in un mese, il 16 del mese dopo. Il 20 % con € 100,00 e il 16 dicembre.
 *
 * Le prove partono dalla rotta del pagamento con gli eventi veri (`setupPagamentiHttp()` non ferma `PagamentoRegistrato`): il
 * listener `SyncF24WithPagamento` gira come nel programma, in coda e dopo il commit. La bozza della pagina è
 * `docs/design/beta14_promemoria_f24_bozza.html`.
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Role;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\F24\PromemoriaDelleRitenute;
use App\Services\Gestionale\PagamentoFornitoreService;
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
    // La categoria dei promemoria, come la scrive l'installazione (`CategoriaEventoSeeder`).
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Promemoria tecnici per l\'amministratore']);
    // Il 30/03/2026 alle 10:00 di Roma: i pagamenti di marzo sono già fatti.
    $this->travelTo(Carbon::parse('2026-03-30 10:00', 'Europe/Rome')->utc());
});

/** Un condominio «Via Roma 12» con la sua banca e un fornitore in appalto al 4 %, persona fisica (codice 1019); gli eventi veri. */
function b14pCtx(string $nome = 'Via Roma 12'): array
{
    $ctx = setupPagamentiHttp();
    $ctx[0]->update(['nome' => $nome]);
    $ctx[3]->update([
        'ragione_sociale' => 'Idraulica Neri',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();

    return $ctx;
}

/** Un secondo fornitore dello stesso contesto, professionista al 20 % (codice 1040). */
function b14pProfessionista(array $ctx): array
{
    $id = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Studio Bianchi',
        'soggetto_ritenuta' => true,
        'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 20,
        'tipo_ritenuta' => 'lavoro_autonomo_20',
        'natura_percipiente' => 'persona_fisica_irpef',
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $altro = $ctx;
    $altro[3] = \App\Models\Fornitore::findOrFail($id);

    return $altro;
}

/** Una fattura con la ritenuta sull'imponibile dato (IVA 22 %), pagata per intero dalla rotta del pagamento alla data data. */
function b14pPaga($test, array $ctx, float $imponibile, string $data): PagamentoFornitore
{
    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Lavori',
            'importo_imponibile' => $imponibile,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    $test->actingAs($test->admin)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$ctx[0]]), datiPagamento($ctx, $fattura, ['data_pagamento' => $data]))
        ->assertSessionHasNoErrors();

    return PagamentoFornitore::where('fornitore_id', $ctx[3]->id)->latest('id')->firstOrFail();
}

/** I promemoria delle ritenute del condominio, aperti e chiusi, per id. */
function b14pTutti(int $condominioId): \Illuminate\Support\Collection
{
    return Evento::where('meta->type', 'versamento_ritenuta')
        ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))
        ->orderBy('id')->get();
}

/** Le righe dell'Inbox del condominio con le ritenute: titolo a video, giorno, riga sotto, azione. */
function b14pInbox($test, int $condominioId): array
{
    $righe = $test->actingAs($test->admin)->get(route('admin.inbox', ['condominio_id' => $condominioId]))->assertOk()->viewData('page')['props']['tasks']['data'];

    return collect($righe)->where('type', 'versamento_ritenuta')
        ->map(fn ($r) => ['titolo' => $r['titolo'], 'giorno' => $r['giorno'], 'sotto' => $r['sotto'], 'azione' => $r['azione']])
        ->sortBy('giorno')->values()->all();
}

function b14pAggiorna($test, array $ctx): void
{
    $test->actingAs($test->admin)->from(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->post(route('admin.gestionale.f24.genera', $ctx[0]->id))->assertSessionHasNoErrors();
}

/** Le ritenute del cruscotto del condominio (`DatiDelleAzioni`), con la finestra fino a fine anno. */
function b14pCruscotto(array $ctx): array
{
    return app(\App\Services\Treasury\DatiDelleAzioni::class)->perCondominio($ctx[0]->fresh(), null,
        \Carbon\CarbonImmutable::parse('2026-03-30'), \Carbon\CarbonImmutable::parse('2026-12-31'), [])['ritenute']['scadenze'];
}

function b14pIndice(array $ctx): string
{
    return route('admin.gestionale.f24.index', ['condominio' => $ctx[0]->id], false);
}

test('Coda 718, caso F — un appalto pagato il 10/03/2026 con € 20,00 di ritenuta: nell\'Inbox «Prepara le deleghe F24» al 16/06/2026, non il 16/04', function () {
    $ctx = b14pCtx();
    $pagamento = b14pPaga($this, $ctx, 500, '2026-03-10');
    // € 500,00 × 4 % = € 20,00
    expect((int) $pagamento->importo_ritenuta)->toBe(2000);

    $compiti = b14pTutti($ctx[0]->id);
    expect($compiti)->toHaveCount(1);
    $prepara = $compiti->first();
    expect($prepara->title)->toBe('Prepara le deleghe F24 · Via Roma 12')
        ->and($prepara->start_time->format('Y-m-d H:i'))->toBe('2026-06-16 09:00')
        ->and($prepara->is_completed)->toBeFalse()
        ->and($prepara->meta['context']['prepara_deleghe'])->toBeTrue()
        ->and((int) $prepara->meta['importo'])->toBe(2000)
        ->and($prepara->created_by)->toBeNull();

    expect(b14pInbox($this, $ctx[0]->id))->toBe([[
        'titolo' => 'Prepara le deleghe F24',
        'giorno' => '2026-06-16',
        'sotto' => '1 ritenuta senza delega · € 20,00 · con il cumulo si versa entro il 16/06/2026',
        'azione' => ['testo' => 'Prepara l\'F24', 'url' => b14pIndice($ctx)],
    ]]);
});

test('Coda 718 — due pagamenti dello stesso condominio: un compito solo, con la cifra sommata (scena 1 della bozza)', function () {
    $ctx = b14pCtx();
    b14pPaga($this, $ctx, 500, '2026-03-10');   // € 20,00
    $secondo = b14pPaga($this, $ctx, 300, '2026-03-25');   // € 12,00

    // € 32,00 a marzo, sotto la soglia di € 500,00: al 16/06/2026.
    $aperti = b14pTutti($ctx[0]->id)->where('is_completed', false);
    expect($aperti)->toHaveCount(1)
        ->and(b14pInbox($this, $ctx[0]->id))->toBe([[
            'titolo' => 'Prepara le deleghe F24',
            'giorno' => '2026-06-16',
            'sotto' => '2 ritenute senza delega · € 32,00 · con il cumulo si versano entro il 16/06/2026',
            'azione' => ['testo' => 'Prepara l\'F24', 'url' => b14pIndice($ctx)],
        ]]);

    // Lo storno di un pagamento ricalcola il compito: resta la ritenuta dell'altro.
    (new PagamentoFornitoreService)->stornaPagamento($secondo->fresh(), 'Pagato due volte');
    expect(b14pInbox($this, $ctx[0]->id)[0]['sotto'])->toBe('1 ritenuta senza delega · € 20,00 · con il cumulo si versa entro il 16/06/2026')
        ->and(b14pTutti($ctx[0]->id)->where('is_completed', false))->toHaveCount(1);
});

test('Coda 718, la catena — pagamento → «Prepara» al 16/06 → «Aggiorna scadenze» → la delega al 16/06 → versamento (e rifiuto con la banca di un altro) → niente → storno → «Prepara» di nuovo → annullamento → «Prepara»', function () {
    $ctx = b14pCtx();
    $altro = b14pCtx('Corso Italia 3');
    b14pPaga($this, $ctx, 500, '2026-03-10');
    $prepara = b14pTutti($ctx[0]->id)->sole();
    // Il cruscotto del condominio e la pagina F24 dicono la stessa data del promemoria.
    expect(b14pCruscotto($ctx))->toBe([['giorno' => '2026-06-16', 'cifra' => 2000, 'delega' => null]]);
    $this->actingAs($this->admin)->get(route('admin.gestionale.f24.index', $ctx[0]->id))->assertOk()
        ->assertInertia(fn ($p) => $p->where('da_calcolare.numero', 1)->where('da_calcolare.totale_cents', 2000));

    b14pAggiorna($this, $ctx);
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($delega->data_scadenza->toDateString())->toBe('2026-06-16')
        ->and(b14pCruscotto($ctx))->toBe([['giorno' => '2026-06-16', 'cifra' => 2000, 'delega' => $delega->id]]);
    $this->actingAs($this->admin)->get(route('admin.gestionale.f24.index', $ctx[0]->id))->assertOk()
        ->assertInertia(fn ($p) => $p->where('riepilogo.prossima_scadenza', '2026-06-16')->where('da_calcolare.numero', 0));

    // Il «Prepara» si chiude, nasce il compito della delega.
    expect($prepara->fresh()->is_completed)->toBeTrue()
        ->and(b14pInbox($this, $ctx[0]->id))->toBe([[
            'titolo' => 'F24 da versare',
            'giorno' => '2026-06-16',
            'sotto' => 'Delega del 16/06/2026 · 1 ritenuta · € 20,00',
            'azione' => ['testo' => 'Apri la delega', 'url' => route('admin.gestionale.f24.show', ['condominio' => $ctx[0]->id, 'delega' => $delega->id], false)],
        ]]);
    $compitoDelega = b14pTutti($ctx[0]->id)->firstWhere(fn ($e) => ($e->meta['context']['delega_f24_id'] ?? null) === $delega->id);
    expect($compitoDelega->title)->toBe('F24 da versare · Via Roma 12');

    // Il versamento sulla banca di un altro condominio è rifiutato (Coda 432): il compito resta.
    $this->travelTo(Carbon::parse('2026-06-12 10:00', 'Europe/Rome')->utc());
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
        'data_versamento' => '2026-06-12', 'conto_corrente_id' => $altro[4],
    ])->assertSessionHasErrors('conto_corrente_id');
    expect(b14pInbox($this, $ctx[0]->id))->toHaveCount(1);

    // Sulla banca del condominio passa, e l'Inbox delle ritenute è vuota.
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
        'data_versamento' => '2026-06-12', 'conto_corrente_id' => $ctx[4],
    ])->assertSessionHasNoErrors();
    expect(b14pInbox($this, $ctx[0]->id))->toBe([])
        ->and($compitoDelega->fresh()->is_completed)->toBeTrue();

    // Lo storno del versamento: la ritenuta torna senza delega, e torna lo stesso «Prepara».
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.storna', [$ctx[0]->id, $delega->id]), ['motivo' => 'Conto sbagliato'])
        ->assertSessionHasNoErrors();
    expect(b14pInbox($this, $ctx[0]->id))->toBe([[
        'titolo' => 'Prepara le deleghe F24',
        'giorno' => '2026-06-16',
        'sotto' => '1 ritenuta senza delega · € 20,00 · con il cumulo si versa entro il 16/06/2026',
        'azione' => ['testo' => 'Prepara l\'F24', 'url' => b14pIndice($ctx)],
    ]])->and($prepara->fresh()->is_completed)->toBeFalse();

    // Una delega nuova, annullata: torna «Prepara».
    b14pAggiorna($this, $ctx);
    $nuova = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect(b14pInbox($this, $ctx[0]->id)[0]['titolo'])->toBe('F24 da versare');
    $this->actingAs($this->admin)->post(route('admin.gestionale.f24.annulla', [$ctx[0]->id, $nuova->id]), ['motivo' => 'Da rifare'])
        ->assertSessionHasNoErrors();
    expect(collect(b14pInbox($this, $ctx[0]->id))->pluck('titolo')->all())->toBe(['Prepara le deleghe F24'])
        ->and(b14pTutti($ctx[0]->id)->where('is_completed', false)->pluck('id')->all())->toBe([$prepara->id]);

    // L'altro condominio non ha niente.
    expect(b14pTutti($altro[0]->id))->toHaveCount(0);
});

test('Coda 718 — «Aggiorna scadenze» rifà la bozza: il compito segue la delega nuova, lo stesso, e se la data non cambia un «Segna come fatto» resta', function () {
    $ctx = b14pCtx();
    b14pPaga($this, $ctx, 500, '2026-03-10');
    b14pAggiorna($this, $ctx);
    $prima = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    $compito = b14pTutti($ctx[0]->id)->firstWhere(fn ($e) => ($e->meta['context']['delega_f24_id'] ?? null) === $prima->id);

    $this->actingAs($this->admin)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    expect($compito->fresh()->is_completed)->toBeTrue();

    // La bozza si rifà da capo (id nuovo), con la stessa data: il compito è lo stesso, legato alla delega nuova, e resta chiuso.
    b14pAggiorna($this, $ctx);
    $seconda = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($seconda->id)->not->toBe($prima->id)
        ->and($compito->fresh()->meta['context']['delega_f24_id'])->toBe($seconda->id)
        ->and($compito->fresh()->is_completed)->toBeTrue()
        ->and(b14pTutti($ctx[0]->id)->where('is_completed', false))->toHaveCount(0)
        ->and(b14pInbox($this, $ctx[0]->id))->toBe([]);

    // Un pagamento grande ad aprile porta il cumulo oltre € 500,00: la delega rifatta scade il 18/05 (il 16/05 è sabato) e il compito torna.
    $this->travelTo(Carbon::parse('2026-04-20 10:00', 'Europe/Rome')->utc());
    b14pPaga($this, $ctx, 15000, '2026-04-15');   // € 600,00
    b14pAggiorna($this, $ctx);
    $terza = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($terza->data_scadenza->toDateString())->toBe('2026-05-18')
        ->and($terza->totale_debito)->toBe(62000)
        ->and($compito->fresh()->meta['context']['delega_f24_id'])->toBe($terza->id)
        ->and($compito->fresh()->is_completed)->toBeFalse()
        ->and(b14pInbox($this, $ctx[0]->id))->toBe([[
            'titolo' => 'F24 da versare',
            'giorno' => '2026-05-18',
            'sotto' => 'Delega del 18/05/2026 · 2 ritenute · € 620,00',
            'azione' => ['testo' => 'Apri la delega', 'url' => route('admin.gestionale.f24.show', ['condominio' => $ctx[0]->id, 'delega' => $terza->id], false)],
        ]]);
});

test('Coda 718 — «Segna come fatto» sul «Prepara» e un pagamento nuovo che non cambia la data: resta chiuso; uno che la cambia: torna', function () {
    $ctx = b14pCtx();
    b14pPaga($this, $ctx, 500, '2026-03-10');
    $prepara = b14pTutti($ctx[0]->id)->sole();
    $this->actingAs($this->admin)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $prepara), ['tipo' => 'fatto'])->assertSessionHasNoErrors();

    // € 12,00 il 25/03: il cumulo resta sotto la soglia, la data resta il 16/06.
    b14pPaga($this, $ctx, 300, '2026-03-25');
    expect($prepara->fresh()->is_completed)->toBeTrue()
        ->and(b14pTutti($ctx[0]->id))->toHaveCount(1)
        ->and(b14pInbox($this, $ctx[0]->id))->toBe([]);

    // € 600,00 il 27/03: marzo arriva a € 632,00 ≥ € 500,00, la prima scadenza diventa il 16/04 e il compito torna.
    b14pPaga($this, $ctx, 15000, '2026-03-27');
    expect($prepara->fresh()->is_completed)->toBeFalse()
        ->and($prepara->fresh()->start_time->format('Y-m-d'))->toBe('2026-04-16')
        ->and(b14pInbox($this, $ctx[0]->id)[0]['sotto'])->toBe('3 ritenute senza delega · € 632,00 · con il cumulo si versano entro il 16/04/2026');
});

test('Coda 718 — una ritenuta nuova accanto a una delega già pronta: due compiti, ognuno con la sua data (scena 3 della bozza)', function () {
    $ctx = b14pCtx();
    b14pPaga($this, $ctx, 500, '2026-03-10');
    b14pPaga($this, $ctx, 300, '2026-03-25');
    b14pAggiorna($this, $ctx);

    // A giugno, un professionista al 20 %: € 750,00 × 20 % = € 150,00, oltre € 100,00 nel mese → il 16/07/2026 (giovedì).
    $this->travelTo(Carbon::parse('2026-06-16 10:00', 'Europe/Rome')->utc());
    b14pPaga($this, b14pProfessionista($ctx), 750, '2026-06-10');

    expect(b14pInbox($this, $ctx[0]->id))->toBe([
        [
            'titolo' => 'F24 da versare',
            'giorno' => '2026-06-16',
            'sotto' => 'Delega del 16/06/2026 · 2 ritenute · € 32,00',
            'azione' => ['testo' => 'Apri la delega', 'url' => route('admin.gestionale.f24.show', ['condominio' => $ctx[0]->id,
                'delega' => DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole()->id], false)],
        ],
        [
            'titolo' => 'Prepara le deleghe F24',
            'giorno' => '2026-07-16',
            'sotto' => '1 ritenuta senza delega · € 150,00 · con il cumulo si versa entro il 16/07/2026',
            'azione' => ['testo' => 'Prepara l\'F24', 'url' => b14pIndice($ctx)],
        ],
    ]);
});

test('Coda 718 — la modifica di un pagamento ricalcola il compito (il listener di `PagamentoAggiornato`)', function () {
    $ctx = b14pCtx();
    $pagamento = b14pPaga($this, $ctx, 500, '2026-03-10');
    $prepara = b14pTutti($ctx[0]->id)->sole();

    // Spostato a dicembre: le ritenute di dicembre si versano il 16 gennaio (il 16/01/2027 è sabato → lunedì 18/01/2027).
    $pagamento->update(['data_pagamento' => '2026-12-10']);
    event(new \App\Events\Gestionale\PagamentoAggiornato($pagamento->fresh(), '2026-03-10', 2000));

    expect($prepara->fresh()->start_time->format('Y-m-d'))->toBe('2027-01-18')
        ->and(b14pTutti($ctx[0]->id))->toHaveCount(1);
});

test('Coda 718 — allinea due volte non cambia niente: gli stessi compiti, le stesse date', function () {
    $ctx = b14pCtx();
    b14pPaga($this, $ctx, 500, '2026-03-10');
    b14pAggiorna($this, $ctx);
    $this->travelTo(Carbon::parse('2026-06-16 10:00', 'Europe/Rome')->utc());
    b14pPaga($this, b14pProfessionista($ctx), 750, '2026-06-10');
    $prima = b14pTutti($ctx[0]->id)->map(fn ($e) => [$e->id, $e->start_time->format('Y-m-d H:i'), $e->is_completed, $e->meta])->all();

    app(PromemoriaDelleRitenute::class)->allinea($ctx[0]->fresh());
    app(PromemoriaDelleRitenute::class)->allinea($ctx[0]->fresh());

    expect(b14pTutti($ctx[0]->id)->map(fn ($e) => [$e->id, $e->start_time->format('Y-m-d H:i'), $e->is_completed, $e->meta])->all())->toBe($prima);
});

// ════════════════════════════════════════════════════════════════════════════
// La migrazione: i promemoria per pagamento che ci sono già (decisione 685 (7))
// ════════════════════════════════════════════════════════════════════════════

/** Il promemoria «F24 Ritenuta» di un pagamento, come lo scriveva il listener fino alla 1.12.0-beta.13. */
function b14pVecchio(PagamentoFornitore $p, string $giorno, bool $chiuso = false): Evento
{
    $categoria = CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $e = Evento::create([
        'title' => 'F24 Ritenuta — Idraulica Neri ('.$p->condominio->nome.')',
        'start_time' => $giorno.' 09:00:00',
        'end_time' => $giorno.' 10:00:00',
        'created_by' => null,
        'description' => "Versare ritenuta d'acconto (Cod. 1019).\nImporto: 20,00 €",
        'category_id' => $categoria->id,
        'visibility' => 'hidden',
        'is_approved' => true,
        'is_completed' => $chiuso,
        'meta' => [
            'type' => 'versamento_ritenuta', 'requires_action' => true, 'is_completed' => false, 'condominio_nome' => $p->condominio->nome,
            'importo' => (int) $p->importo_ritenuta, 'fornitore' => 'Idraulica Neri', 'titolo_azione' => 'Vedi pagamento',
            'context' => ['pagamento_id' => $p->id, 'fornitore_id' => $p->fornitore_id, 'is_f24' => true],
        ],
    ]);
    $e->condomini()->syncWithoutDetaching([$p->condominio_id]);

    return $e;
}

/** Una fotografia dei promemoria delle ritenute: id, titolo, giorno, chiuso, chiave. */
function b14pFoto(): array
{
    return Evento::where('meta->type', 'versamento_ritenuta')->orderBy('id')->get()
        ->map(fn ($e) => [$e->id, $e->title, $e->start_time->format('Y-m-d'), (bool) $e->is_completed,
            $e->meta['context']['delega_f24_id'] ?? ($e->meta['context']['prepara_deleghe'] ?? false ? 'prepara' : 'pagamento '.($e->meta['context']['pagamento_id'] ?? '?'))])
        ->all();
}

test('Coda 718, la migrazione — i promemoria per pagamento aperti si chiudono, nascono quello della delega e il «Prepara»; due volte di fila, lo stesso risultato', function () {
    $migrazione = require database_path('migrations/2026_11_22_100000_allinea_i_promemoria_delle_ritenute.php');

    // Il database di prima: A ha un pagamento in una delega in bozza e uno senza delega, con i loro promemoria per pagamento aperti, e un
    // terzo pagamento con il promemoria chiuso dall'amministratore; B ha solo promemoria chiusi; C ha una ritenuta senza nessun promemoria.
    $a = b14pCtx();
    $p1 = b14pPaga($this, $a, 500, '2026-03-10');
    b14pAggiorna($this, $a);
    $delega = DelegaF24::where('condominio_id', $a[0]->id)->aperte()->sole();
    $p2 = b14pPaga($this, $a, 300, '2026-03-25');
    $p3 = b14pPaga($this, $a, 250, '2026-02-05');
    $b = b14pCtx('Corso Italia 3');
    $pb = b14pPaga($this, $b, 500, '2026-03-12');
    $c = b14pCtx('Piazza Dante 1');
    b14pPaga($this, $c, 500, '2026-03-12');
    // Ciò che la beta.14 ha scritto si toglie: il database torna com'era con il listener di prima.
    Evento::where('meta->type', 'versamento_ritenuta')->get()->each->delete();
    $e1 = b14pVecchio($p1, '2026-04-16');
    $e2 = b14pVecchio($p2, '2026-04-16');
    $e3 = b14pVecchio($p3, '2026-03-16', chiuso: true);
    $eb = b14pVecchio($pb, '2026-04-16', chiuso: true);

    $migrazione->up();

    // A: i due aperti si chiudono, quello chiuso resta com'è; nasce il compito della delega (16/06, € 20,00) e il «Prepara» con le
    // ritenute senza delega, P2 (€ 12,00) e P3 (€ 10,00): € 22,00 a febbraio e marzo, sotto la soglia → il 16/06/2026.
    expect($e1->fresh()->is_completed)->toBeTrue()
        ->and($e1->fresh()->completed_at)->not->toBeNull()
        ->and($e2->fresh()->is_completed)->toBeTrue()
        ->and($e3->fresh()->is_completed)->toBeTrue()
        ->and($e3->fresh()->completed_at)->toBeNull();
    $nuoviA = b14pTutti($a[0]->id)->whereNotIn('id', [$e1->id, $e2->id, $e3->id])->values();
    expect($nuoviA)->toHaveCount(2);
    $diDelega = $nuoviA->firstWhere(fn ($e) => ($e->meta['context']['delega_f24_id'] ?? null) === $delega->id);
    $prepara = $nuoviA->firstWhere(fn ($e) => ($e->meta['context']['prepara_deleghe'] ?? false) === true);
    expect($diDelega->start_time->format('Y-m-d'))->toBe('2026-06-16')
        ->and((int) $diDelega->meta['importo'])->toBe(2000)
        ->and($diDelega->is_completed)->toBeFalse()
        ->and($prepara->start_time->format('Y-m-d'))->toBe('2026-06-16')
        ->and((int) $prepara->meta['importo'])->toBe(2200)
        ->and((int) $prepara->meta['numero_ritenute'])->toBe(2)
        ->and($prepara->is_completed)->toBeFalse();

    // B: solo promemoria chiusi, non si tocca; C: nessun promemoria per pagamento aperto, non si tocca.
    expect(b14pTutti($b[0]->id)->pluck('id')->all())->toBe([$eb->id])
        ->and($eb->fresh()->is_completed)->toBeTrue()
        ->and(b14pTutti($c[0]->id))->toHaveCount(0);

    // Due volte di fila: niente di nuovo, niente di diverso.
    $dopo = b14pFoto();
    $migrazione->up();
    expect(b14pFoto())->toBe($dopo);
});

test('Coda 718, la migrazione — senza la categoria «Scadenze amministrative» salta il condominio e lo scrive nel registro', function () {
    $migrazione = require database_path('migrations/2026_11_22_100000_allinea_i_promemoria_delle_ritenute.php');
    $a = b14pCtx();
    $p1 = b14pPaga($this, $a, 500, '2026-03-10');
    Evento::where('meta->type', 'versamento_ritenuta')->get()->each->delete();
    $e1 = b14pVecchio($p1, '2026-04-16');
    // La categoria se ne va con i suoi eventi: il promemoria di prima lo si riscrive senza.
    Evento::whereKey($e1->id)->update(['category_id' => null]);
    CategoriaEvento::where('name', 'Scadenze amministrative')->delete();

    \Illuminate\Support\Facades\Log::spy();
    $migrazione->up();

    expect($e1->fresh()->is_completed)->toBeFalse()
        ->and(b14pTutti($a[0]->id)->pluck('id')->all())->toBe([$e1->id]);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Scadenze amministrative'))->once();
});

test('Coda 718, la migrazione — un errore su un condominio non ferma l\'aggiornamento: gli altri si allineano e il guasto va nel registro', function () {
    $migrazione = require database_path('migrations/2026_11_22_100000_allinea_i_promemoria_delle_ritenute.php');
    $a = b14pCtx();
    $pa = b14pPaga($this, $a, 500, '2026-03-10');
    $b = b14pCtx('Corso Italia 3');
    $pb = b14pPaga($this, $b, 500, '2026-03-12');
    $pa2 = b14pPaga($this, $a, 300, '2026-03-25');
    $pb2 = b14pPaga($this, $b, 300, '2026-03-26');
    Evento::where('meta->type', 'versamento_ritenuta')->get()->each->delete();
    $ea = b14pVecchio($pa, '2026-04-16');
    $eb = b14pVecchio($pb, '2026-04-16');
    // Un promemoria per pagamento senza il legame con il condominio, per A e per B: il condominio si ricava dal pagamento.
    $senzaA = b14pVecchio($pa2, '2026-04-16');
    $senzaB = b14pVecchio($pb2, '2026-04-16');
    DB::table('condominio_evento')->whereIn('evento_id', [$senzaA->id, $senzaB->id])->delete();

    // Un guasto vero sul solo condominio A: la scrittura dei suoi promemoria nuovi fallisce (e la transazione dell'allineamento torna indietro).
    $idA = (int) $a[0]->id;
    $nomeA = $a[0]->nome;
    Evento::creating(function (Evento $e) use ($nomeA) {
        if (($e->meta['condominio_nome'] ?? null) === $nomeA) {
            throw new \RuntimeException('guasto di prova');
        }
    });
    \Illuminate\Support\Facades\Log::spy();

    $migrazione->up();

    // B si è allineato (il suo promemoria per pagamento è chiuso, ne è nato uno nuovo); A resta com'era, e il guasto è nel registro.
    expect($eb->fresh()->is_completed)->toBeTrue()
        ->and(b14pTutti($b[0]->id)->where('is_completed', false)->count())->toBeGreaterThan(0)
        ->and($ea->fresh()->is_completed)->toBeFalse()
        ->and(b14pTutti($a[0]->id)->pluck('id')->all())->toBe([$ea->id])
        // Il promemoria senza legame si chiude solo nel condominio andato bene.
        ->and($senzaB->fresh()->is_completed)->toBeTrue()
        ->and($senzaA->fresh()->is_completed)->toBeFalse();
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, "#{$idA}") && str_contains($m, 'guasto di prova'))->once();
    Evento::flushEventListeners();
});

test('Coda 718, la migrazione fallita — il promemoria per pagamento senza legame si chiude al primo allineamento riuscito del condominio (un pagamento o «Aggiorna scadenze»)', function (string $chi) {
    $migrazione = require database_path('migrations/2026_11_22_100000_allinea_i_promemoria_delle_ritenute.php');
    $a = b14pCtx();
    $pa = b14pPaga($this, $a, 500, '2026-03-10');
    $pa2 = b14pPaga($this, $a, 300, '2026-03-25');
    Evento::where('meta->type', 'versamento_ritenuta')->get()->each->delete();
    $ea = b14pVecchio($pa, '2026-04-16');
    $senzaA = b14pVecchio($pa2, '2026-04-16');
    DB::table('condominio_evento')->where('evento_id', $senzaA->id)->delete();

    $nomeA = $a[0]->nome;
    $guasto = true;
    Evento::creating(function (Evento $e) use ($nomeA, &$guasto) {
        if ($guasto && ($e->meta['condominio_nome'] ?? null) === $nomeA) {
            throw new \RuntimeException('guasto di prova');
        }
    });
    $migrazione->up();
    expect([$ea->fresh()->is_completed, $senzaA->fresh()->is_completed])->toBe([false, false]);
    $guasto = false;

    // A funziona di nuovo: il primo allineamento riuscito chiude anche il promemoria senza legame.
    if ($chi === 'pagamento') {
        b14pPaga($this, $a, 200, '2026-03-28');
    } else {
        b14pAggiorna($this, $a);
    }

    expect($ea->fresh()->is_completed)->toBeTrue()
        ->and($senzaA->fresh()->is_completed)->toBeTrue()
        ->and(Evento::where('meta->type', 'versamento_ritenuta')->where('is_completed', false)->get()
            ->every(fn ($e) => isset($e->meta['context']['prepara_deleghe']) || isset($e->meta['context']['delega_f24_id'])))->toBeTrue();
    Evento::flushEventListeners();
})->with(['pagamento', 'aggiorna scadenze']);
