<?php

use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(
        ['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']
    );
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
});

require_once __DIR__.'/FatturaLifecycleTest.php';

/**
 * La riga **negativa** dentro una fattura ordinaria — non una nota di credito.
 *
 * ⚠️ **È il caso normale di una bolletta, non un caso di laboratorio.** Ogni fornitura di
 * gas o luce porta dentro il documento le sue partite in diminuzione: nel file 06 del
 * collaudo («due riepiloghi, riga negativa») lo storno si chiama «Spesa per Oneri di
 * sistema» e vale −€ 1,80 in mezzo a tre righe positive. Il documento resta una fattura
 * TD01 a saldo positivo: non è una nota di credito e non va trattato come tale.
 *
 * 🐞 **Il difetto, trovato il 05/09/2026 provando l'importatore XML sui file veri.**
 * `registraFattura()` e `aggiornaFattura()` calcolavano l'importo di ogni riga con
 * `abs($imponibile + $iva)` e lo mettevano **sempre** in DARE. Il valore assoluto non
 * riduce: aggiunge. Il lato AVERE invece nasce da `$netto`, cioè da una somma fatta con i
 * segni ancora attaccati, e restava giusto. Da lì lo sbilancio — pari a **due volte** la
 * riga negativa — e `DoubleEntryValidator` che respingeva l'intera fattura con «Sbilancio
 * rilevato tra DARE e AVERE», annullando la registrazione a tutela del bilancio.
 *
 * La guardia dello zero introdotta nella beta.17 dichiarava già legittima la riga negativa
 * («il confronto è con lo ZERO esatto, non con `<= 0`»): mancava il passo successivo, cioè
 * mandarla dal lato giusto del giornale.
 *
 * I numeri di questi test sono scelti tondi apposta: riga +€ 100,00 al 22 % → lordo
 * € 122,00; riga −€ 10,00 al 22 % → lordo −€ 12,20. Il netto verso il fornitore è
 * € 109,80. Con il difetto il DARE valeva € 134,20 e lo sbilancio esatto € 24,40.
 */
function setupPerRigaNegativa(): array
{
    return setupEcosistemaLifecycle();
}

function corpoConRigaNegativa(array $ctx, string $numero, string $tipoDocumento = 'fattura'): array
{
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    return [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => $tipoDocumento,
        'numero_documento' => $numero,
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [
            ['descrizione' => 'Quota fissa fornitura', 'importo_imponibile' => 100, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Spesa per Oneri di sistema', 'importo_imponibile' => -10, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ];
}

it('registra una fattura ordinaria con una riga negativa, e la scrittura quadra', function () {
    $ctx = setupPerRigaNegativa();
    [$condominio] = $ctx;

    // Senza la correzione questa riga sola bastava: il servizio sollevava
    // «Errore di integrità contabile: Sbilancio rilevato tra DARE (€ 134,20) e AVERE (€ 109,80)».
    $fattura = (new FatturaPassivaService())->registraFattura(
        corpoConRigaNegativa($ctx, 'FT-NEG-1'),
        $condominio->id,
    );

    $scritturaId = $fattura->scritture()->first()->id;

    $dare = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'dare')->sum('importo');
    $avere = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'avere')->sum('importo');

    expect($dare)->toBe(12200)
        ->and($avere)->toBe(12200);

    // Il netto verso il fornitore è la somma CON i segni, non la somma dei valori assoluti.
    expect((int) $fattura->netto_a_pagare)->toBe(10980);
});

it('la riga negativa va in AVERE sullo stesso capitolo, non in DARE', function () {
    // ⚠️ Non è un dettaglio di forma: `SpesaPerVoceService` calcola il consumato di un
    // capitolo come dare − avere. Solo mettendola in AVERE **sullo stesso conto** lo storno
    // riduce davvero il budget speso; in DARE lo avrebbe aumentato, che è il difetto visto
    // dall'altro lato — il giornale quadrato ma il capitolo consumato per eccesso.
    $ctx = setupPerRigaNegativa();
    [$condominio, , , , $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService())->registraFattura(
        corpoConRigaNegativa($ctx, 'FT-NEG-2'),
        $condominio->id,
    );

    $righeCapitolo = DB::table('righe_scritture')
        ->where('scrittura_id', $fattura->scritture()->first()->id)
        ->where('voce_spesa_id', $capitolo->id)
        ->get();

    expect($righeCapitolo)->toHaveCount(2);

    $dareCapitolo = $righeCapitolo->firstWhere('tipo_riga', 'dare');
    $avereCapitolo = $righeCapitolo->firstWhere('tipo_riga', 'avere');

    expect($dareCapitolo)->not->toBeNull('La riga positiva deve restare in DARE')
        ->and((int) $dareCapitolo->importo)->toBe(12200)
        ->and($avereCapitolo)->not->toBeNull('La riga negativa deve stare in AVERE sullo stesso capitolo')
        ->and((int) $avereCapitolo->importo)->toBe(1220);

    // Il consumato netto del capitolo è € 109,80, non € 134,20.
    expect((int) $dareCapitolo->importo - (int) $avereCapitolo->importo)->toBe(10980);
});

it('una fattura con una riga negativa resta modificabile', function () {
    // ⚠️ La stessa lezione della riga a zero (beta.17): `aggiornaFattura()` ricostruisce la
    // scrittura da capo e ripassava dallo stesso `abs()`. Correggere solo la registrazione
    // avrebbe lasciato **non modificabile** ogni bolletta con uno storno di riga.
    $ctx = setupPerRigaNegativa();
    [$condominio] = $ctx;

    $servizio = new FatturaPassivaService();
    $base = corpoConRigaNegativa($ctx, 'FT-NEG-MOD');
    $fattura = $servizio->registraFattura($base, $condominio->id);

    $modificata = $base;
    $modificata['righe'][1]['importo_imponibile'] = -20;

    $servizio->aggiornaFattura($fattura, $modificata);

    $scritturaId = $fattura->fresh()->scritture()->first()->id;
    $dare = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'dare')->sum('importo');
    $avere = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'avere')->sum('importo');

    expect($dare)->toBe(12200)
        ->and($avere)->toBe(12200)
        ->and((int) $fattura->fresh()->netto_a_pagare)->toBe(9760);
});

/**
 * Fase 1-bis, rilievo 3 — il documento che vale meno di zero.
 *
 * La bolletta in cui il conguaglio a credito supera il consumo del periodo: righe +€ 50,00 e
 * −€ 100,00. Dare il verso calcolato alle sole righe di dettaglio e lasciarlo fisso sulla riga
 * di testata faceva ricomparire «Sbilancio rilevato», spostato dal livello della riga a quello
 * del documento — cioè il messaggio che questa beta esiste per far sparire.
 */
it('registra un documento il cui totale è negativo perché gli storni superano gli addebiti', function () {
    $ctx = setupPerRigaNegativa();
    [$condominio, , , , $capitolo] = $ctx;

    $dati = corpoConRigaNegativa($ctx, 'FT-NEG-TOTALE');
    $dati['righe'] = [
        ['descrizione' => 'Consumo del periodo', 'importo_imponibile' => 50, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Conguaglio a credito', 'importo_imponibile' => -100, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
    ];

    $fattura = (new FatturaPassivaService())->registraFattura($dati, $condominio->id);
    $scritturaId = $fattura->scritture()->first()->id;

    $dare = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'dare')->sum('importo');
    $avere = (int) DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'avere')->sum('importo');

    expect($dare)->toBe(12200)->and($avere)->toBe(12200);

    // Il documento è a credito: il fornitore deve a noi, non viceversa.
    expect((int) $fattura->netto_a_pagare)->toBe(-6100);

    // E la riga di testata sta in DARE, non in AVERE.
    $rigaFornitore = DB::table('righe_scritture')
        ->where('scrittura_id', $scritturaId)
        ->whereNull('voce_spesa_id')
        ->where('importo', 6100)
        ->first();
    expect($rigaFornitore)->not->toBeNull()
        ->and($rigaFornitore->tipo_riga)->toBe('dare');
});

/**
 * Fase 1-bis, rilievo 1 — lo storno deve essere lo specchio dell'originale.
 *
 * ⚠️ Questo difetto era **latente fino alla beta.17**: una fattura con una riga negativa non si
 * registrava affatto, quindi non la si poteva nemmeno stornare. È la correzione di questa beta ad
 * averlo reso raggiungibile — ed è la ragione per cui va chiuso qui e non in una coda futura.
 *
 * Con `abs()` riga per riga la nota di credito diventava **più grande** della fattura: € 134,20
 * contro € 109,80. Restavano € 24,40 di credito verso il fornitore che non esiste, e il capitolo
 * finiva a spesa NEGATIVA — un numero che entra nel rendiconto e si ripartisce ai condòmini.
 * `DoubleEntryValidator` non poteva vederlo: le due scritture quadrano ciascuna per sé, lo
 * sbilancio è **fra** i due documenti.
 */
it('lo storno di una fattura con riga negativa produce una nota di credito dello stesso importo', function () {
    $ctx = setupPerRigaNegativa();
    [$condominio, , , , $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService())->registraFattura(
        corpoConRigaNegativa($ctx, 'FT-NEG-STORNO'),
        $condominio->id,
    );
    expect((int) $fattura->netto_a_pagare)->toBe(10980);

    $risposta = $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.storno', [$condominio->id, $fattura->id])
    );
    $risposta->assertSessionHasNoErrors();

    $nc = App\Models\Gestionale\FatturaPassiva::where('condominio_id', $condominio->id)
        ->where('tipo_documento', 'nota_credito')
        ->latest('id')->first();

    expect($nc)->not->toBeNull()
        // Lo specchio esatto: € 109,80, non € 134,20.
        ->and((int) $nc->netto_a_pagare)->toBe(-10980)
        ->and((int) $nc->importo_imponibile)->toBe(-9000);

    // E il conto del fornitore torna a zero: nessun credito fantasma.
    $scritture = DB::table('fattura_scrittura')
        ->whereIn('fattura_passiva_id', [$fattura->id, $nc->id])
        ->pluck('scrittura_contabile_id');

    $contoDebiti = App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'debiti_fornitori')->first();

    $saldoFornitore = (int) DB::table('righe_scritture')
        ->whereIn('scrittura_id', $scritture)
        ->where('conto_contabile_id', $contoDebiti->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE -importo END) as saldo")
        ->value('saldo');

    expect($saldoFornitore)->toBe(0, 'Fattura e storno devono annullarsi: nessun credito fantasma');

    // E il capitolo non resta a spesa negativa.
    $saldoCapitolo = (int) DB::table('righe_scritture')
        ->whereIn('scrittura_id', $scritture)
        ->where('voce_spesa_id', $capitolo->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END) as saldo")
        ->value('saldo');

    expect($saldoCapitolo)->toBe(0, 'Il capitolo deve tornare a zero, non andare in negativo');
});

/**
 * Coda 132 — la guardia `min:0` esiste **solo** sulla nota di credito.
 *
 * Su una NC il segno lo porta già il moltiplicatore −1 del servizio: una riga digitata
 * negativa lo annulla e riporta `netto_a_pagare` in positivo. A quel punto
 * `StornoFatturaController` — che distingue una nota di credito da una fattura vera con
 * `if ($fattura->netto_a_pagare < 0)` e nient'altro — non la riconosce più, e il documento
 * diventa pagabile e stornabile pur restando una nota di credito.
 *
 * ⚠️ **I due test sono una coppia e vanno letti insieme**: il primo pretende il divieto, il
 * secondo pretende che il divieto NON si allarghi alla fattura ordinaria. Senza il secondo,
 * un `min:0` messo su tutte le righe sembrerebbe corretto e richiuderebbe con la validazione
 * la porta che il motore contabile ha appena aperto.
 */
it('su una nota di credito la validazione rifiuta una riga con imponibile negativo', function () {
    $ctx = setupPerRigaNegativa();
    [$condominio, , , , $capitolo] = $ctx;

    $servizio = new FatturaPassivaService();
    $corpoNc = corpoConRigaNegativa($ctx, 'NC-NEG-1', 'nota_credito');
    // La NC nasce come la vuole il prodotto: righe positive, il segno lo mette il servizio.
    $corpoNc['righe'] = [
        ['descrizione' => 'Storno fornitura', 'importo_imponibile' => 100, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
    ];
    $nc = $servizio->registraFattura($corpoNc, $condominio->id);

    expect((int) $nc->netto_a_pagare)->toBeLessThan(0, 'Una NC appena creata deve avere netto negativo');

    // Ora si prova a digitare a mano una riga negativa in modifica.
    $modifica = [
        'gestione_id' => $corpoNc['gestione_id'],
        'numero_documento' => 'NC-NEG-1',
        'data_documento' => $corpoNc['data_documento'],
        'data_scadenza' => $corpoNc['data_scadenza'],
        'modalita_pagamento' => 'bonifico',
        'righe' => [
            ['descrizione' => 'Storno fornitura', 'importo_imponibile' => -100, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id],
        ],
    ];

    $risposta = $this->actingAs($this->user)->put(
        route('admin.gestionale.fatture.update', [$condominio->id, $nc->id]),
        $modifica,
    );

    $risposta->assertSessionHasErrors(['righe.0.importo_imponibile']);

    // ⚠️ E il rifiuto deve **spiegarsi**, non essere il testo automatico di Laravel: la pagina
    // di modifica mostra questo messaggio sotto la casella dell'importo (fino alla beta.18 non
    // mostrava nulla per le righe, e il salvataggio falliva in silenzio).
    $errori = session('errors')->get('righe.0.importo_imponibile');
    expect($errori[0])->toContain('nota di credito')
        ->and($errori[0])->toContain('segno');
});

it('su una fattura ordinaria la stessa riga negativa passa la validazione', function () {
    $ctx = setupPerRigaNegativa();
    [$condominio, , , , $capitolo] = $ctx;

    $servizio = new FatturaPassivaService();
    $base = corpoConRigaNegativa($ctx, 'FT-NEG-VAL');
    $fattura = $servizio->registraFattura($base, $condominio->id);

    $modifica = [
        'gestione_id' => $base['gestione_id'],
        'numero_documento' => 'FT-NEG-VAL',
        'data_documento' => $base['data_documento'],
        'data_scadenza' => $base['data_scadenza'],
        'modalita_pagamento' => 'bonifico',
        'righe' => [
            ['descrizione' => 'Quota fissa fornitura', 'importo_imponibile' => 100, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id],
            ['descrizione' => 'Spesa per Oneri di sistema', 'importo_imponibile' => -10, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id],
        ],
    ];

    $risposta = $this->actingAs($this->user)->put(
        route('admin.gestionale.fatture.update', [$condominio->id, $fattura->id]),
        $modifica,
    );

    // Asserto preciso, come nel test gemello delle righe a zero: si misura la sola regola in
    // prova, non la salute dell'intero payload.
    $risposta->assertSessionDoesntHaveErrors(['righe.1.importo_imponibile']);
});
