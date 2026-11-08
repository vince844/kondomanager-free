<?php

/**
 * Coda 466 — la modifica di un pagamento fornitore controlla il limite dei contanti, senza bloccare la correzione di un pagamento già passato.
 *
 * `registraPagamento` rifiuta un pagamento in contanti da € 5.000,00 compresi in su (D.Lgs. 231/2007); `aggiornaPagamento` non aveva il controllo, e il
 * ramo `IllegalCashAmountException` di `PagamentoFornitoreController::update` con la finestra di `PagamentoEdit.vue` non si raggiungevano mai. Dalla pagina
 * l'importo non si digita (parte dalle prop del pagamento): la strada è il cambio di metodo a importo invariato, un bonifico o un assegno portato a
 * «Contanti».
 *
 * La regola (decisione 188.3): la modifica rifiuta solo quando il metodo **diventa** contanti, o quando l'importo in contanti **cresce**, da € 5.000,00
 * compresi (la soglia della registrazione). Un pagamento in contanti già sopra soglia, salvato dalla modifica prima di questa versione, si corregge nella
 * nota o nella data senza rifiuto. L'importo in contanti è l'importo netto: quello che la modifica riscrive sull'allocazione alla fattura e sull'uscita di
 * cassa, e quello che la registrazione confronta con la soglia.
 *
 * Le prove partono dalla rotta: la pagina di modifica (`pagamenti-fornitori.edit`) dà le prop del pagamento, il corpo della richiesta è quello che
 * `PagamentoEdit.vue` manda con quelle prop (senza `fornitore_id`), e la modifica passa da `pagamenti-fornitori.update`. Le cifre sono quelle scritte
 * qui, dalla soglia di € 5.000,00 (500000 centesimi): non vengono dal programma.
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
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

/** Il messaggio della registrazione per € 6.000,00: la modifica deve dire lo stesso. */
const GIRO3_RIFIUTO_6000 = 'Pagamento in contanti di € 6.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).';

/** Una fattura da € 7.320,00 (imponibile € 6.000,00 più IVA al 22%), su cui stanno i pagamenti delle prove. */
function giro3Fattura(array $ctx, int $imponibile = 6000, array $extra = []): FatturaPassiva
{
    [, , , , , $capitolo] = $ctx;

    return registraFatturaServiceTest($ctx, array_merge([
        'righe' => [['descrizione' => 'Lavori', 'importo_imponibile' => $imponibile, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]],
    ], $extra));
}

/** Un pagamento registrato dal servizio, con il metodo e l'importo (in centesimi) detti; l'allocazione alla fattura è l'importo. */
function giro3Pagamento(array $ctx, FatturaPassiva $fattura, string $metodo, int $centesimi): PagamentoFornitore
{
    return (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => $metodo,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => $centesimi]],
    ]));
}

/**
 * Un pagamento in contanti già sopra soglia, com'era possibile salvarlo dalla modifica prima di questa versione: registrato in bonifico, portato a
 * «Contanti» senza controllo.
 */
function giro3ContantiGiaSopraSoglia(array $ctx, FatturaPassiva $fattura, int $centesimi): PagamentoFornitore
{
    $pagamento = giro3Pagamento($ctx, $fattura, MetodoPagamento::BONIFICO->value, $centesimi);
    $pagamento->update(['metodo_pagamento' => MetodoPagamento::CONTANTI]);

    return $pagamento->refresh();
}

/**
 * Il corpo della richiesta come lo manda `PagamentoEdit.vue`: i campi della sua form, inizializzati dalle prop che la pagina di modifica riceve dal
 * controller (`pagamento`, `esercizio`), senza `fornitore_id`. `$cambi` sono le cose che l'amministratore cambia nella pagina, o una richiesta scritta
 * a mano (l'importo).
 */
function giro3CorpoDellaPagina(object $prova, array $ctx, PagamentoFornitore $pagamento, array $cambi = []): array
{
    $props = $prova->actingAs($prova->user)
        ->get(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0], $pagamento]))
        ->assertOk()
        ->viewData('page')['props'];
    $p = $props['pagamento'];

    return array_merge([
        'esercizio_id' => $props['esercizio']['id'],
        'conto_corrente_id' => $p['conto_corrente']['id'],
        'data_pagamento' => $p['data_pagamento'],
        'metodo_pagamento' => $p['metodo_pagamento'],
        'iban_beneficiario' => '',
        'importo_commissioni_cents' => $p['importo_commissione'],
        'importo_lordo_cents' => $p['importo_lordo'],
        'importo_ritenuta_cents' => $p['importo_ritenuta'],
        'importo_netto_cents' => $p['importo_netto'],
        'bonifico_parlante' => (bool) $p['bonifico_parlante'],
        'tipo_detrazione' => null,
        'beneficiari_detrazione' => [],
        'allow_overdraft' => false,
        'allow_overpayment' => false,
        'iban_confermato_manualmente' => false,
        'conferma_duplicato_verificato' => false,
        'note_override' => null,
        'causale_bonifico' => '',
        'riferimento_bancario' => '',
    ], $cambi);
}

function giro3Modifica(object $prova, array $ctx, PagamentoFornitore $pagamento, array $corpo)
{
    return $prova->actingAs($prova->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $pagamento]), $corpo);
}

/** Che cosa la scrittura del pagamento contiene: le righe e l'importo allocato alla fattura, per vedere che un rifiuto non scriva niente. */
function giro3Scritto(PagamentoFornitore $pagamento): array
{
    return [
        'metodo' => $pagamento->fresh()->metodo_pagamento->value,
        'netto' => (int) $pagamento->fresh()->importo_netto,
        'righe' => DB::table('righe_scritture')->where('scrittura_id', $pagamento->scrittura_contabile_id)->orderBy('id')->get(['tipo_riga', 'importo'])->map(fn ($r) => [$r->tipo_riga, (int) $r->importo])->all(),
        'allocato' => DB::table('fattura_scrittura')->where('scrittura_contabile_id', $pagamento->scrittura_contabile_id)->pluck('importo_allocato')->map(fn ($v) => (int) $v)->all(),
    ];
}

// ─── Il metodo diventa «Contanti» ────────────────────────────────────────────

test('Coda 466 — un bonifico da € 6.000,00 portato a contanti, con il corpo della pagina, si rifiuta con il messaggio della registrazione, e non scrive niente', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 600000);
    $prima = giro3Scritto($pagamento);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000]);

    expect(giro3Scritto($pagamento))->toBe($prima)->and($prima['metodo'])->toBe('bonifico');
});

test('Coda 466 — un bonifico da € 5.000,00 portato a contanti si rifiuta: la soglia è compresa', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 500000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasErrors(['illegal_cash' => 'Pagamento in contanti di € 5.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).']);

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe('bonifico');
});

test('Coda 466 — un bonifico da € 4.999,99 portato a contanti passa: un centesimo sotto la soglia', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 499999);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.pagamenti-fornitori.index', ['condominio' => $ctx[0]->id]));

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe('contanti')->and((int) $pagamento->fresh()->importo_netto)->toBe(499999);
});

test('Coda 466 — qualunque metodo diverso da contanti, con € 6.000,00, portato a contanti si rifiuta come un bonifico', function (string $metodo) {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), $metodo, 600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000]);

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe($metodo);
})->with(['assegno', 'rid_sdd', 'altro']);

test('Coda 466 — le commissioni bancarie non fanno parte dell\'importo in contanti, come alla registrazione: € 4.999,99 più € 10,00 di commissioni passano', function () {
    $ctx = setupPagamentiHttp();
    $fattura = giro3Fattura($ctx);
    // La registrazione dello stesso pagamento in contanti, con le stesse commissioni, passa.
    $registrato = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value, 'importo_commissioni_cents' => 1000, 'voce_commissione_id' => $ctx[5]->id,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 499999]],
    ]));
    expect($registrato->metodo_pagamento->value)->toBe('contanti');

    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 499999);

    // Le commissioni aumentano l'uscita dal conto, e la modifica chiede il consenso allo scoperto come sempre: qui interessa il limite dei contanti.
    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti', 'importo_commissioni_cents' => 1000, 'voce_commissione_id' => $ctx[5]->id, 'allow_overdraft' => true]))
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe('contanti')->and((int) $pagamento->fresh()->importo_commissione)->toBe(1000);
});

test('Coda 466 — il rifiuto della modifica è lo stesso testo della registrazione dello stesso importo in contanti', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 600000);
    // Un'altra fattura, per la registrazione: la prima ha già il suo bonifico.
    $fattura = giro3Fattura($ctx);

    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$ctx[0]]), [
        'fornitore_id' => $fattura->fornitore_id, 'esercizio_id' => $ctx[1]->id, 'conto_corrente_id' => $ctx[4],
        'data_pagamento' => now()->toDateString(), 'metodo_pagamento' => 'contanti',
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => 600000]],
        'bonifico_parlante' => false, 'allow_overdraft' => true,
    ])->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000]);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000]);
});

test('Coda 466 — il limite si misura sull\'importo netto, quello che esce in contanti: un lordo oltre soglia con il netto sotto passa, come alla registrazione', function () {
    $ctx = setupPagamentiHttp();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    // Imponibile € 4.200,00, IVA € 924,00, lordo € 5.124,00; ritenuta del 4% sull'imponibile € 168,00; netto da pagare € 4.956,00.
    $fattura = giro3Fattura($ctx, 4200, ['applica_ritenuta' => true]);
    expect((int) $fattura->netto_a_pagare)->toBe(495600);

    $pagamento = giro3Pagamento($ctx, $fattura, MetodoPagamento::BONIFICO->value, 495600);
    expect((int) $pagamento->importo_lordo)->toBe(512400)->and((int) $pagamento->importo_ritenuta)->toBe(16800)->and((int) $pagamento->importo_netto)->toBe(495600);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'contanti']))
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe('contanti');
});

// ─── L'importo in contanti cresce ────────────────────────────────────────────

test('Coda 466 — con una ritenuta l\'importo cresce rispetto al netto già salvato, non al lordo: da € 4.956,00 netti (lordo € 5.124,00) a € 5.000,00 si rifiuta', function () {
    $ctx = setupPagamentiHttp();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx, 4200, ['applica_ritenuta' => true]), MetodoPagamento::BONIFICO->value, 495600);
    $pagamento->update(['metodo_pagamento' => MetodoPagamento::CONTANTI]);
    expect((int) $pagamento->fresh()->importo_lordo)->toBe(512400)->and((int) $pagamento->fresh()->importo_netto)->toBe(495600);

    // Il netto sale a € 5.000,00 (resta sotto il lordo salvato, € 5.124,00): l'importo in contanti è cresciuto e ha raggiunto la soglia. L'allocazione alla
    // fattura supera il suo residuo di € 44,00: si dà il consenso, per arrivare al controllo dei contanti.
    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_netto_cents' => 500000, 'allow_overpayment' => true, 'allow_overdraft' => true]))
        ->assertSessionHasErrors(['illegal_cash' => 'Pagamento in contanti di € 5.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).']);

    expect((int) $pagamento->fresh()->importo_netto)->toBe(495600);
});

test('Coda 466 — un pagamento in contanti da € 4.000,00 portato a € 6.000,00 con una richiesta scritta a mano si rifiuta', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::CONTANTI->value, 400000);
    $prima = giro3Scritto($pagamento);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_lordo_cents' => 600000, 'importo_netto_cents' => 600000, 'allow_overdraft' => true]))
        ->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000]);

    expect(giro3Scritto($pagamento))->toBe($prima);
});

test('Coda 466 — il limite dei contanti vince sul consenso allo scoperto: con il conto che non regge il rifiuto è quello dei contanti, che nessun consenso supera', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::CONTANTI->value, 400000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_lordo_cents' => 600000, 'importo_netto_cents' => 600000, 'allow_overdraft' => false]))
        ->assertSessionHasErrors(['illegal_cash' => GIRO3_RIFIUTO_6000])
        ->assertSessionDoesntHaveErrors('insufficient_funds');
});

test('Coda 466 — da € 4.000,00 a € 4.999,99 in contanti passa, e a € 5.000,00 si rifiuta: cresce, e la soglia è compresa', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::CONTANTI->value, 400000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_lordo_cents' => 499999, 'importo_netto_cents' => 499999, 'allow_overdraft' => true]))
        ->assertSessionHasNoErrors();
    expect((int) $pagamento->fresh()->importo_netto)->toBe(499999);

    giro3Modifica($this, $ctx, $pagamento->fresh(), giro3CorpoDellaPagina($this, $ctx, $pagamento->fresh(), ['importo_lordo_cents' => 500000, 'importo_netto_cents' => 500000, 'allow_overdraft' => true]))
        ->assertSessionHasErrors(['illegal_cash' => 'Pagamento in contanti di € 5.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).']);
    expect((int) $pagamento->fresh()->importo_netto)->toBe(499999);
});

test('Coda 466 — un pagamento in contanti già sopra soglia: anche un centesimo in più si rifiuta, uno in meno no', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3ContantiGiaSopraSoglia($ctx, giro3Fattura($ctx), 600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_lordo_cents' => 600001, 'importo_netto_cents' => 600001, 'allow_overdraft' => true]))
        ->assertSessionHasErrors('illegal_cash');
    expect((int) $pagamento->fresh()->importo_netto)->toBe(600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['importo_lordo_cents' => 599999, 'importo_netto_cents' => 599999]))
        ->assertSessionHasNoErrors();
    expect((int) $pagamento->fresh()->importo_netto)->toBe(599999);
});

// ─── Un pagamento in contanti già passato si corregge ────────────────────────

test('Coda 466 — un pagamento in contanti da € 6.000,00 già salvato: la modifica della nota passa, e la nota si salva', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3ContantiGiaSopraSoglia($ctx, giro3Fattura($ctx), 600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['note_override' => 'Ricevuta ritirata in studio']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.pagamenti-fornitori.index', ['condominio' => $ctx[0]->id]));

    $salvato = $pagamento->fresh();
    expect($salvato->note_override)->toBe('Ricevuta ritirata in studio')
        ->and($salvato->metodo_pagamento->value)->toBe('contanti')
        ->and((int) $salvato->importo_netto)->toBe(600000);
});

test('Coda 466 — un pagamento in contanti da € 6.000,00 già salvato: la modifica della data passa', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3ContantiGiaSopraSoglia($ctx, giro3Fattura($ctx), 600000);
    $dataPrecedente = now()->subDays(3)->toDateString();

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['data_pagamento' => $dataPrecedente]))
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->data_pagamento->toDateString())->toBe($dataPrecedente)->and($pagamento->fresh()->metodo_pagamento->value)->toBe('contanti');
});

test('Coda 466 — la pagina di modifica di un pagamento in contanti da € 6.000,00 aperta e inviata senza toccare niente si salva com\'era', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3ContantiGiaSopraSoglia($ctx, giro3Fattura($ctx), 600000);
    $prima = giro3Scritto($pagamento);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento))
        ->assertSessionHasNoErrors();

    expect(giro3Scritto($pagamento))->toBe($prima);
});

test('Coda 466 — un pagamento in contanti da € 6.000,00 già salvato si può portare a bonifico: il metodo smette di essere contanti', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3ContantiGiaSopraSoglia($ctx, giro3Fattura($ctx), 600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['metodo_pagamento' => 'bonifico']))
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->metodo_pagamento->value)->toBe('bonifico');
});

test('Coda 466 — controprova: un bonifico da € 6.000,00 modificato nella nota, senza cambiare metodo, passa', function () {
    $ctx = setupPagamentiHttp();
    $pagamento = giro3Pagamento($ctx, giro3Fattura($ctx), MetodoPagamento::BONIFICO->value, 600000);

    giro3Modifica($this, $ctx, $pagamento, giro3CorpoDellaPagina($this, $ctx, $pagamento, ['note_override' => 'Bonifico del 3 novembre']))
        ->assertSessionHasNoErrors();

    expect($pagamento->fresh()->note_override)->toBe('Bonifico del 3 novembre')->and($pagamento->fresh()->metodo_pagamento->value)->toBe('bonifico');
});
