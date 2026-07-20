<?php

/**
 * Fase 0 — hardening dei prerequisiti del sigillo contabile.
 *
 * Ogni test qui blinda una porta sul retro trovata dalla mappatura: falle che
 * renderebbero aggirabile qualunque LedgerGuard costruito sopra. Non sono
 * raffinamenti di UX — sono le fondamenta su cui poggia il sigillo.
 */

use App\Enums\StatoPagamentoFattura;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// ─── Esercizio: il contenitore non si cancella per aggirare il sigillo ───────

test('un esercizio che contiene scritture non è eliminabile', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, , , $capitolo] = $ctx;

    // La scrittura NON deve avere una fattura a monte, altrimenti a bloccare
    // l'eliminazione sarebbe la FK restrictOnDelete di fatture_passive e il test
    // resterebbe verde anche senza la guardia (verificato con mutation test).
    // Una regolazione immediata è esattamente una scrittura senza documento.
    $cassaId = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');

    (new \App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction())->execute([
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'conto_id' => $capitolo->id,
        'cassa_id' => $cassaId,
        'fornitore_id' => null,
        'data_operazione' => now()->toDateString(),
        'causale' => 'Commissione bancaria',
        'importo' => 2.50,
    ], $condominio, $esercizio);

    expect(DB::table('fatture_passive')->where('esercizio_id', $esercizio->id)->count())->toBe(0);

    $esercizio->update(['stato' => 'chiuso']);

    // Serve un secondo esercizio, altrimenti scatta prima la guardia "ultimo esercizio".
    Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.esercizi.destroy', [$condominio, $esercizio]))
        ->assertSessionHas('message.type', 'error');

    // Senza la guardia la cascata FK avrebbe distrutto in silenzio il giornale.
    expect(Esercizio::find($esercizio->id))->not->toBeNull()
        ->and(DB::table('scritture_contabili')->where('esercizio_id', $esercizio->id)->count())->toBe(1);
});

test('un esercizio chiuso e senza scritture resta eliminabile', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $vuoto = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'chiuso']);

    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.esercizi.destroy', [$condominio, $vuoto]));

    expect(Esercizio::find($vuoto->id))->toBeNull();
});

// ─── Invariante: al più un esercizio aperto per condominio ──────────────────

test('non si può creare un secondo esercizio aperto nello stesso condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;   // setupContabile ne crea già uno aperto

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.store', $condominio), [
            'nome' => 'Secondo esercizio aperto',
            'descrizione' => 'Tentativo di doppio aperto',
            'data_inizio' => '2027-01-01',
            'data_fine' => '2027-12-31',
            'stato' => 'aperto',
        ])
        ->assertSessionHasErrors('stato');

    expect(Esercizio::where('condominio_id', $condominio->id)->where('stato', 'aperto')->count())->toBe(1);
});

test('si può creare un nuovo esercizio chiuso senza violare l invariante', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.store', $condominio), [
            'nome' => 'Esercizio storico chiuso',
            'descrizione' => 'Import di un anno passato',
            'data_inizio' => '2024-01-01',
            'data_fine' => '2024-12-31',
            'stato' => 'chiuso',
        ])
        ->assertSessionHasNoErrors();
});

test('riaprire un esercizio chiuso è vietato se ce n è già uno aperto', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;   // setupContabile ne crea già uno aperto

    $chiuso = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'chiuso']);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.update', [$condominio, $chiuso]), [
            'nome' => $chiuso->nome,
            'descrizione' => 'Tentativo di riapertura',
            'data_inizio' => $chiuso->data_inizio->format('Y-m-d'),
            'data_fine' => $chiuso->data_fine->format('Y-m-d'),
            'stato' => 'aperto',
        ])
        ->assertSessionHasErrors('stato');
});

test('un esercizio già aperto resta rinominabile anche se il DB ne contiene due', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    // Scenario da ripristino di un backup anteriore a questa versione: due esercizi
    // aperti convivono. L'invariante non deve rendere entrambi immodificabili.
    $secondoAperto = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto',
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.update', [$condominio, $esercizio]), [
            'nome' => 'Nome corretto dopo il ripristino',
            'descrizione' => $esercizio->descrizione ?? 'desc',
            'data_inizio' => $esercizio->data_inizio->format('Y-m-d'),
            'data_fine' => $esercizio->data_fine->format('Y-m-d'),
            'stato' => 'aperto',      // invariato: nessuna transizione
        ])
        ->assertSessionHasNoErrors();

    expect($esercizio->fresh()->nome)->toBe('Nome corretto dopo il ripristino')
        ->and($secondoAperto->fresh()->stato)->toBe('aperto');
});

test('la creazione automatica dell esercizio non ne apre un secondo', function () {
    // Percorso non-HTTP (creazione condominio, installer, seeder): creava sempre
    // 'aperto' senza guardare se ce ne fosse già uno.
    //
    // L'esercizio aperto deve avere date di un ALTRO anno, altrimenti il metodo esce
    // prima sul controllo "esiste già un esercizio di quest'anno" e la guardia non
    // viene nemmeno raggiunta (il test passerebbe anche senza il fix).
    $condominio = \App\Models\Condominio::factory()->create();

    $apertoVecchio = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto',
        'data_inizio' => now()->subYears(2)->startOfYear(),
        'data_fine' => now()->subYears(2)->endOfYear(),
    ]);

    $risultato = app(\App\Services\CondominioService::class)->createEsercizioForCondominio($condominio);

    expect(Esercizio::where('condominio_id', $condominio->id)->where('stato', 'aperto')->count())->toBe(1)
        ->and($risultato->id)->toBe($apertoVecchio->id);
});

// ─── Tenancy: gli id di un altro condominio non passano più ─────────────────

test('non si può registrare una fattura nell esercizio di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $altroCondominio = \App\Models\Condominio::factory()->create();
    $esercizioAltrui = Esercizio::factory()->create([
        'condominio_id' => $altroCondominio->id,
        'stato' => 'aperto',
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio),
            datiFatturaHttpMinima($ctx, ['esercizio_id' => $esercizioAltrui->id]))
        ->assertSessionHasErrors('esercizio_id');
});

test('non si può registrare una fattura in un esercizio chiuso', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    $esercizio->update(['stato' => 'chiuso']);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio), datiFatturaHttpMinima($ctx))
        ->assertSessionHasErrors('esercizio_id');
});

/** Payload minimo accettato da StoreFatturaRequest, per i soli test di validazione. */
function datiFatturaHttpMinima(array $ctx, array $extra = []): array
{
    [, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    return array_merge([
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-TENANCY-'.uniqid(),
        'data_documento' => now()->toDateString(),
        'data_scadenza' => now()->addDays(30)->toDateString(),
        'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'righe' => [[
            'descrizione' => 'Riga di prova',
            'importo_imponibile' => 100,
            'aliquota_iva' => 0,
            'importo_iva' => 0,
            'conto_id' => $capitolo->id,
        ]],
    ], $extra);
}

// ─── Date: il sigillo per anzianità ha bisogno di date oneste ───────────────

test('un pagamento con data futura viene rifiutato', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', $condominio), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->addDays(3)->toDateString(),
            'metodo_pagamento' => \App\Enums\MetodoPagamento::BONIFICO->value,
            'allocazioni' => [[
                'fattura_id' => $fattura->id,
                'tipo' => \App\Enums\TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura->netto_a_pagare,
            ]],
            'allow_overdraft' => true,
        ])
        ->assertSessionHasErrors('data_pagamento');
});

// ─── Doppia fonte di verità: STORNATA non si perde nel ricalcolo ────────────

/**
 * La guardia è in OR: stato_pagamento === STORNATA || dati_extra.is_stornata.
 * Vanno testati i due rami SEPARATAMENTE, altrimenti un fix che ne implementasse
 * uno solo — o una regressione che ne rompesse uno — resterebbe verde.
 */
test('ricalcolaStatoFattura rispetta il congelamento su stato_pagamento', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    DB::table('fatture_passive')->where('id', $fattura->id)->update([
        'stato_pagamento' => 'stornata',
        'dati_extra' => json_encode(['nessun_flag' => true]),   // solo il ramo stato_pagamento
    ]);

    $fattura->refresh();
    (new PagamentoFornitoreService())->ricalcolaStatoFattura($fattura);
    $fattura->refresh();

    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::STORNATA);
});

test('ricalcolaStatoFattura rispetta il congelamento su dati_extra.is_stornata', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    // Il pagamento serve a rendere il test DISCRIMINANTE: senza pivot il ricalcolo
    // deriverebbe comunque APERTA e il test passerebbe anche senza la guardia.
    // Con un pagamento a saldo, il valore derivato sarebbe PAGATA.
    (new PagamentoFornitoreService())->registraPagamento(datiPagamento($ctx, $fattura), $condominio->id);

    // Situazione asimmetrica: solo il flag in dati_extra segnala lo storno.
    DB::table('fatture_passive')->where('id', $fattura->id)->update([
        'stato_pagamento' => 'aperta',
        'dati_extra' => json_encode(['is_stornata' => true, 'stornata_da_id' => 999]),
    ]);

    $fattura->refresh();
    (new PagamentoFornitoreService())->ricalcolaStatoFattura($fattura);
    $fattura->refresh();

    // La guardia deve uscire subito: se leggesse solo stato_pagamento, qui
    // deriverebbe PAGATA dai pivot e la fattura stornata risulterebbe pagata.
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::APERTA)
        ->and($fattura->dati_extra['is_stornata'])->toBeTrue();
});

// ─── Resurrezione: eliminare la NC di storno deve riaprire l'originale ──────

test('eliminando la nota di credito di storno la fattura originale torna davvero aperta', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    // 1. Storno: nasce la NC e l'originale viene congelata su ENTRAMBE le fonti.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fattura]))
        ->assertSessionHas('message.type', 'success');

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::STORNATA)
        ->and($fattura->dati_extra['is_stornata'])->toBeTrue();

    $notaCredito = FatturaPassiva::where('condominio_id', $condominio->id)
        ->where('tipo_documento', 'nota_credito')->firstOrFail();

    // 2. Ripensamento: elimino la NC → la Resurrezione deve sciogliere il congelamento.
    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.fatture.destroy', [$condominio, $notaCredito]));

    $fattura->refresh();

    // La guardia STORNATA in ricalcolaStatoFattura è in OR: ripulire solo dati_extra
    // lasciava la fattura congelata per sempre, non modificabile e non eliminabile.
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::APERTA)
        ->and($fattura->dati_extra['is_stornata'] ?? false)->toBeFalse()
        ->and($fattura->dati_extra['stornata_da_id'] ?? null)->toBeNull();

    // E deve essere di nuovo lavorabile: nessun motivo di blocco residuo.
    expect((new \App\Services\Gestionale\FatturaPassivaService())->motivoBloccoModifica($fattura))->toBeNull();
});

// ─── Storno fattura: prima i pagamenti, poi il documento ────────────────────

test('una fattura con pagamenti registrati non è stornabile finché il pagamento resta vivo', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    (new PagamentoFornitoreService())->registraPagamento(datiPagamento($ctx, $fattura), $condominio->id);

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::PAGATA);

    // Il rifiuto viaggia su withErrors, non sul flash: è l'unico canale che
    // sopravvive al redirect di back() in una visita Inertia e arriva a schermo.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fattura]))
        ->assertSessionHasErrors('storno_vietato');

    // Nessuna nota di credito fabbricata sopra un pagamento ancora vivo.
    expect(FatturaPassiva::where('condominio_id', $condominio->id)
        ->where('tipo_documento', 'nota_credito')->count())->toBe(0);
});

// ─── IVA a zero: un'aliquota 0 non è un'aliquota mancante ───────────────────

/**
 * Segnalazione dal forum (beta.14): registrando una spesa con IVA a 0 —
 * commissioni bancarie, professionisti in regime forfetario — l'anteprima
 * mostrava l'importo corretto ma il documento salvato usciva con il 22%.
 *
 * La causa era lato form (`Number(r.aliquota_iva) || 22`, che scambia lo zero
 * per "valore assente"). Il servizio è sempre stato corretto: questo test lo
 * blinda, così nessun default può rientrare dalla porta di servizio.
 */
test('una riga con aliquota IVA a zero resta senza IVA', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Commissione bancaria',
            'importo_imponibile' => 250,     // in euro: il service moltiplica x100
            'aliquota_iva' => 0,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    expect($fattura->importo_imponibile)->toBe(25000)
        ->and($fattura->importo_iva)->toBe(0)
        ->and($fattura->totale_documento)->toBe(25000);

    // Anche la riga salvata, non solo la testata.
    $riga = DB::table('righe_fattura')->where('fattura_passiva_id', $fattura->id)->first();
    expect((float) $riga->aliquota_iva)->toBe(0.0)
        ->and((int) $riga->importo_iva)->toBe(0);

    // E la scrittura contabile non deve contenere alcuna riga di IVA a credito.
    assertQuadraturaPerfetta($fattura->scritture->first()->id);
});

test('una riga con aliquota IVA valorizzata continua a calcolarla', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Manutenzione ordinaria',
            'importo_imponibile' => 100,
            'aliquota_iva' => 10,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    expect($fattura->importo_imponibile)->toBe(10000)
        ->and($fattura->importo_iva)->toBe(1000)
        ->and($fattura->totale_documento)->toBe(11000);
});
