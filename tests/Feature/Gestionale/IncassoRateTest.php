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
            'stato' => 'da_pagare'
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
    
    /** @test */
    public function registra_incasso_totale_rata_happy_path() {
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
    
    /** @test */
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
    
    /** @test */
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
    
        $this->assertDatabaseHas('righe_scritture', [
            'conto_contabile_id' => $data->contoAnticipi->id,
            'importo' => 2000,
            'tipo_riga' => 'avere',
            'note' => 'Anticipo / Eccedenza'
        ]);
    }
    
    /** @test */
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