<?php

namespace Tests\Feature\Gestionale;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

class IncassoRateTest extends TestCase
{
    use RefreshDatabase;

    // Helper interno alla classe
    // Helper interno alla classe
    private function createIncassoScenario() {
        // 1. Condominio e Esercizio
        $condominio = Condominio::create([
            'nome' => 'Condominio Test', 
            'uuid' => (string) Str::uuid(),
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Milano',
            'cap' => '20100',
            'provincia' => 'MI'
        ]);
    
        $esercizio = Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome' => '2025', 
            'data_inizio' => '2025-01-01', 
            'data_fine' => '2025-12-31', 
            'stato' => 'aperto'
        ]);
        
        // 2. Gestione
        $gestione = Gestione::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Ordinaria', 
            'tipo' => 'ordinaria',
            'data_inizio' => '2025-01-01',
        ]);
        $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);
    
        // 3. Conti Contabili (CORRETTI CON ENUM VALIDI)
        $contoBanca = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '10.10', 
            'nome' => 'Banca X', 
            'tipo' => 'attivo',
            'ruolo' => 'banca',
            'categoria' => 'liquidita' // <--- VALORE CORRETTO
        ]);
    
        $contoCrediti = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '10.20', 
            'nome' => 'Crediti vs Condomini', 
            'tipo' => 'attivo', 
            'ruolo' => 'crediti_condomini',
            'categoria' => 'crediti' // <--- VALORE CORRETTO
        ]);
    
        $contoAnticipi = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '20.10', 
            'nome' => 'Anticipi Condomini', 
            'tipo' => 'passivo', 
            'ruolo' => 'anticipi_condomini',
            'categoria' => 'debiti' // <--- VALORE CORRETTO (Anticipi = Debito verso condomini)
        ]);
    
        // 4. Cassa
        $cassa = Cassa::create([
            'condominio_id' => $condominio->id,
            'conto_contabile_id' => $contoBanca->id,
            'nome' => 'Cassa Principale',
            'tipo' => 'banca',
            'attiva' => true
        ]);
    
        // 5. Anagrafica e Immobile
        $anagrafica = Anagrafica::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@test.it',
            'indirizzo' => 'Via Verdi 10', 
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'codice_fiscale' => 'RSSMRA80A01H501U'
        ]);
        
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Int 1',
            'descrizione' => 'Appartamento test',
            'interno' => '1',
            'foglio' => '1', 'particella' => '1', 'subalterno' => '1'
        ]);
    
        // 6. Piano Rate e Rata
        $pianoRate = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'Piano 2025',
            'numero_rate' => 12
        ]);
    
        $rataHeader = Rata::create([
            'piano_rate_id' => $pianoRate->id,
            'numero_rata' => 1,
            'data_scadenza' => '2025-01-31',
            'importo_totale' => 10000, 
            'stato' => 'emessa'
        ]);
    
        $quota = RataQuote::create([
            'rata_id' => $rataHeader->id,
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'importo' => 10000,
            'importo_pagato' => 0,
            'stato' => 'da_pagare',
            'data_scadenza' => '2025-01-31'
        ]);
    
        $immobile->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario', 
            'quota' => 100, 
            'attivo' => true,
            'data_inizio' => now()->subYear()
        ]);
    
        return (object) compact(
            'condominio', 'esercizio', 'gestione', 'cassa', 
            'anagrafica', 'quota', 'contoCrediti', 'contoAnticipi'
        );
    }
    
    public function test_registra_incasso_totale_rata_happy_path() {
        $data = $this->createIncassoScenario();
        
        $payload = [
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 100.00, 
            'descrizione' => 'Saldo Rata 1',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                [
                    'rata_id' => $data->quota->id, // ID QUOTA
                    'importo' => 100.00 
                ]
            ]
        ];
    
        app(StoreIncassoRateAction::class)->execute($payload, $data->condominio, $data->esercizio);
    
        $data->quota->refresh();
        $this->assertEquals('pagata', $data->quota->stato);
        $this->assertEquals(10000, $data->quota->importo_pagato);
    
        $this->assertDatabaseHas('scritture_contabili', [
            'condominio_id' => $data->condominio->id,
            'gestione_id' => $data->gestione->id,
            'tipo_movimento' => 'incasso_rata',
            'causale' => 'Saldo Rata 1'
        ]);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function registra_incasso_parziale() {
        $data = $this->createIncassoScenario();
        
        $payload = [
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 50.00,
            'descrizione' => 'Acconto',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                [
                    'rata_id' => $data->quota->id,
                    'importo' => 50.00
                ]
            ]
        ];
    
        app(StoreIncassoRateAction::class)->execute($payload, $data->condominio, $data->esercizio);
    
        $data->quota->refresh();
        
        $this->assertEquals('parzialmente_pagata', $data->quota->stato);
        $this->assertEquals(5000, $data->quota->importo_pagato);
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function registra_incasso_con_eccedenza_anticipo() {
        $data = $this->createIncassoScenario();

        $payload = [
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 120.00,
            'descrizione' => 'Saldo + Anticipo',
            'eccedenza' => 20.00,
            'dettaglio_pagamenti' => [
                [
                    'rata_id' => $data->quota->id,
                    'importo' => 100.00
                ]
            ]
        ];

        app(StoreIncassoRateAction::class)->execute($payload, $data->condominio, $data->esercizio);

        $data->quota->refresh();

        $this->assertEquals('pagata', $data->quota->stato);

        // L'eccedenza diventa strapagamento sulla quota: credito visibile e spendibile
        $this->assertEquals(12000, $data->quota->importo_pagato);
        $this->assertEquals(2000, $data->quota->credito_disponibile);

        // La riga contabile resta sui crediti, agganciata alla rata
        $this->assertDatabaseHas('righe_scritture', [
            'conto_contabile_id' => $data->contoCrediti->id,
            'rata_id' => $data->quota->rata_id,
            'importo' => 2000,
            'tipo_riga' => 'avere',
            'note' => 'Anticipo / Eccedenza'
        ]);

        // Nessun vicolo cieco sugli anticipi
        $this->assertDatabaseMissing('righe_scritture', [
            'conto_contabile_id' => $data->contoAnticipi->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function eccedenza_diventa_credito_spendibile_su_rate_emesse_dopo() {
        $data = $this->createIncassoScenario();

        // 1. Incasso 120 su una rata da 100: nasce un credito (anticipo) di 20
        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 120.00,
            'descrizione' => 'Saldo + Anticipo',
            'eccedenza' => 20.00,
            'dettaglio_pagamenti' => [
                ['rata_id' => $data->quota->id, 'importo' => 100.00],
            ],
        ], $data->condominio, $data->esercizio);

        // 2. Viene emessa una nuova rata
        $rata2 = Rata::create([
            'piano_rate_id' => $data->quota->rata->piano_rate_id,
            'numero_rata' => 2,
            'data_scadenza' => '2025-02-28',
            'importo_totale' => 10000,
            'stato' => 'emessa'
        ]);
        $quota2 = RataQuote::create([
            'rata_id' => $rata2->id,
            'anagrafica_id' => $data->anagrafica->id,
            'immobile_id' => $data->quota->immobile_id,
            'importo' => 10000,
            'importo_pagato' => 0,
            'stato' => 'da_pagare',
            'data_scadenza' => '2025-02-28'
        ]);

        // 3. Compensazione a cassa zero: il credito di 20 paga (in parte) la rata 2
        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-03-01',
            'importo_totale' => 0,
            'descrizione' => 'Compensazione anticipo',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $data->quota->id, 'importo' => -20.00],
                ['rata_id' => $quota2->id, 'importo' => 20.00],
            ],
        ], $data->condominio, $data->esercizio);

        $data->quota->refresh();
        $quota2->refresh();

        // Lo strapagamento è stato riassorbito...
        $this->assertEquals(10000, $data->quota->importo_pagato);
        $this->assertEquals(0, $data->quota->credito_disponibile);
        $this->assertEquals('pagata', $data->quota->stato);

        // ...e la rata 2 risulta pagata per 20
        $this->assertEquals(2000, $quota2->importo_pagato);
        $this->assertEquals('parzialmente_pagata', $quota2->stato);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function usa_credito_da_strapagamento_legacy_per_pagare_altra_rata() {
        // Scenario identico al caso reale: una quota strapagata da una versione
        // vecchia (pagato 120 su 100 dovuti) e una nuova rata emessa impagata.
        $data = $this->createIncassoScenario();

        $scritturaLegacy = \App\Models\Gestionale\ScritturaContabile::create([
            'condominio_id' => $data->condominio->id,
            'esercizio_id' => $data->esercizio->id,
            'gestione_id' => $data->gestione->id,
            'data_registrazione' => '2025-01-15',
            'data_competenza' => '2025-01-15',
            'causale' => 'Incasso legacy senza tetto',
            'tipo_movimento' => 'incasso_rata',
            'stato' => 'registrata',
        ]);
        $data->quota->pagamenti()->attach($scritturaLegacy->id, [
            'importo_pagato' => 12000,
            'data_pagamento' => '2025-01-15',
        ]);
        $data->quota->ricalcolaStato();

        $this->assertEquals(2000, $data->quota->fresh()->credito_disponibile);

        $rata2 = Rata::create([
            'piano_rate_id' => $data->quota->rata->piano_rate_id,
            'numero_rata' => 2,
            'data_scadenza' => '2025-02-28',
            'importo_totale' => 10000,
            'stato' => 'emessa'
        ]);
        $quota2 = RataQuote::create([
            'rata_id' => $rata2->id,
            'anagrafica_id' => $data->anagrafica->id,
            'immobile_id' => $data->quota->immobile_id,
            'importo' => 10000,
            'importo_pagato' => 0,
            'stato' => 'da_pagare',
            'data_scadenza' => '2025-02-28'
        ]);

        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-03-01',
            'importo_totale' => 0,
            'descrizione' => 'Compensazione strapagamento',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $data->quota->id, 'importo' => -20.00],
                ['rata_id' => $quota2->id, 'importo' => 20.00],
            ],
        ], $data->condominio, $data->esercizio);

        $data->quota->refresh();
        $quota2->refresh();

        $this->assertEquals(10000, $data->quota->importo_pagato);
        $this->assertEquals('pagata', $data->quota->stato);
        $this->assertEquals(2000, $quota2->importo_pagato);
        $this->assertEquals('parzialmente_pagata', $quota2->stato);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function usa_credito_da_saldo_iniziale_negativo_regressione() {
        // Il flusso storico del "salvadanaio" (quota saldo_iniziale a importo
        // negativo) deve continuare a funzionare dopo la generalizzazione.
        $data = $this->createIncassoScenario();

        $rataZero = Rata::create([
            'piano_rate_id' => $data->quota->rata->piano_rate_id,
            'numero_rata' => 0,
            'data_scadenza' => '2025-01-01',
            'importo_totale' => -5000,
            'stato' => 'emessa',
            'descrizione' => 'Saldo Pregresso'
        ]);
        $quotaCredito = RataQuote::create([
            'rata_id' => $rataZero->id,
            'anagrafica_id' => $data->anagrafica->id,
            'immobile_id' => $data->quota->immobile_id,
            'importo' => -5000,
            'importo_pagato' => 0,
            'stato' => 'credito',
            'tipo' => 'saldo_iniziale',
            'data_scadenza' => '2025-01-01'
        ]);

        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 50.00,
            'descrizione' => 'Contanti + credito',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $quotaCredito->id, 'importo' => -50.00],
                ['rata_id' => $data->quota->id, 'importo' => 100.00],
            ],
        ], $data->condominio, $data->esercizio);

        $quotaCredito->refresh();
        $data->quota->refresh();

        // Il salvadanaio è stato consumato per 50
        $this->assertEquals(-5000, $quotaCredito->importo_pagato);
        $this->assertEquals(0, $quotaCredito->credito_disponibile);

        // La rata è saldata: 50 contanti + 50 credito
        $this->assertEquals(10000, $data->quota->importo_pagato);
        $this->assertEquals('pagata', $data->quota->stato);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function situazione_debitoria_mostra_strapagamento_come_credito() {
        $data = $this->createIncassoScenario();

        // Strapagamento legacy: 120 incassati su 100 dovuti
        $scritturaLegacy = \App\Models\Gestionale\ScritturaContabile::create([
            'condominio_id' => $data->condominio->id,
            'esercizio_id' => $data->esercizio->id,
            'gestione_id' => $data->gestione->id,
            'data_registrazione' => '2025-01-15',
            'data_competenza' => '2025-01-15',
            'causale' => 'Incasso legacy senza tetto',
            'tipo_movimento' => 'incasso_rata',
            'stato' => 'registrata',
        ]);
        $data->quota->pagamenti()->attach($scritturaLegacy->id, [
            'importo_pagato' => 12000,
            'data_pagamento' => '2025-01-15',
        ]);
        $data->quota->ricalcolaStato();

        $request = \Illuminate\Http\Request::create('/', 'GET', [
            'anagrafica_id' => $data->anagrafica->id,
        ]);

        $controller = app(\App\Http\Controllers\Gestionale\Movimenti\SituazioneDebitoriaController::class);
        $rate = $controller($request, $data->condominio)->getData(true)['rate'];

        $this->assertCount(1, $rate);
        $this->assertTrue($rate[0]['is_credito']);
        $this->assertEquals(-20.0, $rate[0]['residuo']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function compensazione_a_cassa_zero_mostra_pagante_e_dettaglio_rate() {
        // Regressione: una compensazione a credito puro (importo versato € 0) non
        // crea alcuna riga sulla scrittura padre — prima del fix il pagante
        // risultava "Sconosciuto" e lo spaccato rate restava vuoto, sia in lista
        // che nel dettaglio incasso, perché il fallback sulle scritture figlie
        // mancava e perché tipo_movimento (un enum) veniva confrontato con una
        // stringa raw (sempre falso).
        $data = $this->createIncassoScenario();

        $scritturaLegacy = \App\Models\Gestionale\ScritturaContabile::create([
            'condominio_id' => $data->condominio->id,
            'esercizio_id' => $data->esercizio->id,
            'gestione_id' => $data->gestione->id,
            'data_registrazione' => '2025-01-15',
            'data_competenza' => '2025-01-15',
            'causale' => 'Incasso legacy senza tetto',
            'tipo_movimento' => 'incasso_rata',
            'stato' => 'registrata',
        ]);
        $data->quota->pagamenti()->attach($scritturaLegacy->id, [
            'importo_pagato' => 12000,
            'data_pagamento' => '2025-01-15',
        ]);
        $data->quota->ricalcolaStato();

        $rata2 = Rata::create([
            'piano_rate_id' => $data->quota->rata->piano_rate_id,
            'numero_rata' => 2,
            'data_scadenza' => '2025-02-28',
            'importo_totale' => 10000,
            'stato' => 'emessa'
        ]);
        $quota2 = RataQuote::create([
            'rata_id' => $rata2->id,
            'anagrafica_id' => $data->anagrafica->id,
            'immobile_id' => $data->quota->immobile_id,
            'importo' => 10000,
            'importo_pagato' => 0,
            'stato' => 'da_pagare',
            'data_scadenza' => '2025-02-28'
        ]);

        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-03-01',
            'importo_totale' => 0,
            'descrizione' => 'Compensazione a cassa zero',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $data->quota->id, 'importo' => -20.00],
                ['rata_id' => $quota2->id, 'importo' => 20.00],
            ],
        ], $data->condominio, $data->esercizio);

        $scritturaPadre = \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $data->condominio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->where('causale', 'Compensazione a cassa zero')
            ->firstOrFail();

        $scritturaPadre->load([
            'righe.anagrafica',
            'quotePagate.rata',
            'quotePagate.immobile',
            'figlie.quotePagate.rata',
            'figlie.quotePagate.immobile',
            'figlie.righe.anagrafica',
        ]);

        $formattato = app(\App\Services\Gestionale\IncassoRateService::class)
            ->formatMovimentoForFrontend($scritturaPadre);

        $this->assertEquals('Mario', $formattato['pagante']['principale']);
        $this->assertNotEmpty($formattato['dettagli_rate']);
        $this->assertEquals('credito', $formattato['dettagli_rate'][0]['tipo']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function impedisce_incasso_se_totale_matematico_non_quadra() {
        $data = $this->createIncassoScenario();
        
        $payload = [
            'pagante_id' => $data->anagrafica->id,
            'cassa_id' => $data->cassa->id,
            'gestione_id' => $data->gestione->id,
            'data_pagamento' => '2025-02-01',
            'importo_totale' => 100.00, 
            'descrizione' => 'Errore',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                [
                    'rata_id' => $data->quota->id,
                    'importo' => 90.00 // Manca 10€
                ]
            ]
        ];
    
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Totale non corrispondente');

        app(StoreIncassoRateAction::class)->execute($payload, $data->condominio, $data->esercizio);
    }
}