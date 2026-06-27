<?php

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Setup condiviso per generare un ecosistema contabile valido.
 * Utilizza la stessa logica consolidata dei test di FatturaPassivaService.
 */
function setupEcosistemaLifecycle(): array
{
    // Disabilita eventi non essenziali per focalizzarsi sui record a DB
    Event::fake([
        \App\Events\Gestionale\FatturaRegistrata::class,
        \App\Events\Gestionale\PagamentoRegistrato::class,
    ]);

    $condominio = \App\Models\Condominio::factory()->create();

    $esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Esercizio Corrente',
        'stato'         => 'aperto',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Gestione Ordinaria',
        'tipo'          => 'ordinaria',
        'attiva'        => true,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale'            => 'Elettricista Mario Rossi',
        'soggetto_ritenuta'          => true,  // Forziamo ritenuta per un test completo
        'perc_imponibile_ritenuta'   => 100,
        'perc_ritenuta'              => 4,
        'codice_tributo'             => '1019',
        'giorni_scadenza'            => 30,
        'modalita_pagamento_default' => 'bonifico',
        'created_at'                 => now(),
        'updated_at'                 => now(),
    ]);

    // Creazione dei Mastro necessari con CODICI UNIVOCI espliciti
    $ruoli = [
        'debiti_fornitori'       => ['passivo', 'debiti', 'DEB-FOR'],
        'debiti_erario_ritenute' => ['passivo', 'debiti', 'DEB-RIT'],
        'crediti_condomini'      => ['attivo', 'crediti', 'CRED-COND'], // ⚠️ AGGIUNTO QUI per soddisfare il guard del Service
        'banca'                  => ['attivo', 'liquidita', 'BANCA'],
        'spese_bancarie'         => ['attivo', 'crediti', 'SP-BANC'],
    ];

    foreach ($ruoli as $ruolo => $props) {
        DB::table('conti_contabili')->insert([
            'condominio_id' => $condominio->id,
            'ruolo'         => $ruolo,
            'codice'        => $props[2], // Usa il codice univoco esplicito
            'nome'          => ucfirst(str_replace('_', ' ', $ruolo)),
            'tipo'          => $props[0],
            'categoria'     => $props[1],
            'attivo'        => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    $contoBanca = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'banca')->first();
    
    // Creazione della Cassa associata al conto Banca
    DB::table('casse')->insert([
        'condominio_id'      => $condominio->id,
        'conto_contabile_id' => $contoBanca->id,
        'nome'               => 'Conto Corrente Principale',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Setup Piano dei Conti (per la registrazione fattura)
    $contoContabileCostoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo'         => null,
        'codice'        => 'MANUT',
        'nome'          => 'Manutenzione Ordinaria',
        'tipo'          => 'attivo',    // Allineato ai vincoli del DB (era 'costo')
        'categoria'     => 'crediti',   // Allineato ai vincoli del DB (era 'costi')
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $pianoContoId = DB::table('piani_conti')->insertGetId([
        'gestione_id'   => $gestioneId,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano dei Conti',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $capitoloId = DB::table('conti')->insertGetId([
        'piano_conto_id'     => $pianoContoId,
        'conto_contabile_id' => $contoContabileCostoId,
        'nome'               => 'Riparazioni Elettriche',
        'tipo'               => 'spesa',
        'importo'            => 100000,
        'is_tecnico'         => false,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    return [
        $condominio,
        \App\Models\Esercizio::find($esercizioId),
        \App\Models\Gestione::find($gestioneId),
        \App\Models\Fornitore::find($fornitoreId),
        Conto::find($capitoloId),
        $contoBanca
    ];
}

it('esegue il ciclo completo E2E: Registrazione Fattura (Competenza) -> Pagamento (Cassa) con quadratura perfetta', function () {
    // 1. SETUP AMBIENTE
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, $contoBanca] = setupEcosistemaLifecycle();
    $contoDebiti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_fornitori')->first();
    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();

    // =====================================================================================
    // FASE 1: REGISTRAZIONE FATTURA (Il debito nasce)
    // =====================================================================================
    $fatturaData = [
        'fornitore_id'       => $fornitore->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'tipo_documento'     => 'fattura',
        'numero_documento'   => 'FATT-LIFECYCLE-1',
        'data_documento'     => now()->format('Y-m-d'),
        'data_scadenza'      => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta'   => true, // Attiva ritenuta al 4% (configurata nel setup)
        'dati_extra'         => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [[
            'descrizione'        => 'Riparazione quadro elettrico',
            'importo_imponibile' => 1000, // 1.000,00 € (Imput a float/int per il service)
            'aliquota_iva'       => 22,   // 22% -> 220,00 €
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ];

    $fatturaService = new FatturaPassivaService();
    $fattura = $fatturaService->registraFattura($fatturaData, $condominio->id);
    
    // Ricalcoliamo mentalmente:
    // Imponibile = 1000.00
    // IVA = 220.00
    // Lordo (Totale Documento) = 1220.00 (122000 cents)
    // Ritenuta 4% sull'imponibile = 40.00 (4000 cents)
    // Netto a pagare = 1180.00 (118000 cents)

    expect($fattura->totale_documento)->toEqual(122000)
        ->and($fattura->importo_ritenuta)->toEqual(4000)
        ->and($fattura->netto_a_pagare)->toEqual(118000)
        ->and($fattura->residuo)->toEqual(118000)
        ->and($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);

    // Snapshot Saldo Debiti DOPO la registrazione
    // Deve essere in AVERE per il Netto (118000)
    $saldoDebitiPostRegistrazione = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoDebiti->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) - SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as saldo")
        ->value('saldo');
    
    expect((int) $saldoDebitiPostRegistrazione)->toEqual(118000);

    // =====================================================================================
    // FASE 2: APPROVAZIONE (Workflow)
    // =====================================================================================
    $fattura->update(['stato_approvazione' => 'approvata']);

    // =====================================================================================
    // FASE 3: PAGAMENTO (Il debito muore, esce la cassa)
    // =====================================================================================
    $pagamentoData = [
        'fornitore_id'      => $fornitore->id,
        'condominio_id'     => $condominio->id,
        'esercizio_id'      => $esercizio->id,
        'gestione_id'       => $gestione->id,
        'conto_corrente_id' => $contoBanca->id,
        'data_pagamento'    => now()->format('Y-m-d'),
        'metodo_pagamento'  => MetodoPagamento::BONIFICO->value,
        'allocazioni'       => [
            [
                'fattura_id'             => $fattura->id,
                'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => 118000, // Paghiamo esattamente il netto residuo
            ]
        ],
        'importo_commissioni_cents' => 150, // 1,50 € di commissioni bancarie
        'allow_overdraft' => true, // La banca parte da 0, forziamo l'addebito
    ];

    $pagamentoService = new PagamentoFornitoreService();
    $pagamento = $pagamentoService->registraPagamento($pagamentoData);

    // =====================================================================================
    // FASE 4: VERIFICHE FINALI (Integrità Ledger e Stati)
    // =====================================================================================
    $fattura->refresh();

    // 4.1 Stati aggiornati correttamente
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA)
        ->and($fattura->residuo)->toEqual(0);

    // 4.2 Quadratura Partita Doppia della Scrittura di Pagamento
    $darePagamento  = DB::table('righe_scritture')->where('scrittura_id', $pagamento->scrittura_contabile_id)->where('tipo_riga', 'dare')->sum('importo');
    $averePagamento = DB::table('righe_scritture')->where('scrittura_id', $pagamento->scrittura_contabile_id)->where('tipo_riga', 'avere')->sum('importo');
    
    // Dare: 118000 (Chiusura Debito) + 150 (Commissioni) = 118150
    // Avere: 118150 (Uscita Banca Totale)
    expect((int) $darePagamento)->toEqual(118150)
        ->and((int) $averePagamento)->toEqual(118150);

    // 4.3 THE ULTIMATE LEDGER TEST: Saldo Mastro Debiti Fornitori = 0
    $saldoFinaleDebiti = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoDebiti->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) - SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as saldo")
        ->value('saldo');
    
    expect((int) $saldoFinaleDebiti)->toEqual(0, 'Il Mastro Debiti v/Fornitori non si è azzerato!');

    // 4.4 THE ULTIMATE LEDGER TEST: Saldo Mastro Erario (Ritenute da versare) = 4000
    $saldoFinaleErario = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoErario->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) - SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as saldo")
        ->value('saldo');
    
    expect((int) $saldoFinaleErario)->toEqual(4000, 'Il debito verso Erario (F24) non combacia!');

    // 4.5 THE ULTIMATE LEDGER TEST: Saldo Mastro Banca = -118150
    $saldoFinaleBanca = DB::table('righe_scritture')
        ->where('conto_contabile_id', $contoBanca->id)
        ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) - SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as saldo")
        ->value('saldo');
    
    expect((int) $saldoFinaleBanca)->toEqual(-118150, 'L\'uscita di cassa sulla Banca è errata!');
});