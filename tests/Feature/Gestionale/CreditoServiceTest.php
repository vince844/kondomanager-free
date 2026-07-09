<?php

namespace Tests\Feature\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Gestionale\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function creaScenarioDueGestioni(): object
    {
        $condominio = Condominio::create([
            'nome' => 'Condominio Test',
            'uuid' => (string) Str::uuid(),
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Milano',
            'cap' => '20100',
            'provincia' => 'MI',
        ]);

        $esercizio = Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome' => '2025',
            'data_inizio' => '2025-01-01',
            'data_fine' => '2025-12-31',
            'stato' => 'aperto',
        ]);

        $gestioneOrdinaria = Gestione::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Ordinaria',
            'tipo' => 'ordinaria',
            'data_inizio' => '2025-01-01',
        ]);
        $gestioneOrdinaria->esercizi()->attach($esercizio->id, ['attiva' => true]);

        $gestioneStraordinaria = Gestione::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Straordinaria',
            'tipo' => 'straordinaria',
            'data_inizio' => '2025-01-01',
        ]);
        $gestioneStraordinaria->esercizi()->attach($esercizio->id, ['attiva' => true]);

        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Int 1',
            'descrizione' => 'Appartamento test',
            'interno' => '1',
            'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
        ]);

        $anagrafica = Anagrafica::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario@test.it',
            'indirizzo' => 'Via Verdi 10',
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'codice_fiscale' => 'RSSMRA80A01H501U',
        ]);

        $immobile->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario',
            'quota' => 100,
            'attivo' => true,
            'data_inizio' => now()->subYear(),
        ]);

        // Quota strapagata sulla gestione ordinaria: 150 pagati su 100 dovuti -> 50 credito
        $pianoOrdinario = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestioneOrdinaria->id,
            'nome' => 'Piano ordinario',
            'numero_rate' => 1,
        ]);
        $rataOrdinaria = Rata::create([
            'piano_rate_id' => $pianoOrdinario->id,
            'numero_rata' => 1,
            'data_scadenza' => '2025-01-31',
            'importo_totale' => 10000,
            'stato' => 'emessa',
        ]);
        $quotaOrdinaria = RataQuote::create([
            'rata_id' => $rataOrdinaria->id,
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'importo' => 10000,
            'importo_pagato' => 15000,
            'stato' => 'pagata',
            'data_scadenza' => '2025-01-31',
        ]);

        // Saldo iniziale a credito sulla gestione straordinaria: -30 non consumato
        $pianoStraordinario = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestioneStraordinaria->id,
            'nome' => 'Piano straordinario',
            'numero_rate' => 1,
        ]);
        $rataZero = Rata::create([
            'piano_rate_id' => $pianoStraordinario->id,
            'numero_rata' => 0,
            'data_scadenza' => '2025-01-01',
            'importo_totale' => -3000,
            'stato' => 'emessa',
            'descrizione' => 'Saldo Pregresso',
        ]);
        $quotaCreditoStraordinaria = RataQuote::create([
            'rata_id' => $rataZero->id,
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'importo' => -3000,
            'importo_pagato' => 0,
            'stato' => 'credito',
            'tipo' => 'saldo_iniziale',
            'data_scadenza' => '2025-01-01',
        ]);

        return (object) compact(
            'condominio', 'esercizio', 'anagrafica', 'immobile',
            'gestioneOrdinaria', 'gestioneStraordinaria',
            'quotaOrdinaria', 'quotaCreditoStraordinaria'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function per_anagrafica_raggruppa_il_credito_per_gestione()
    {
        $data = $this->creaScenarioDueGestioni();

        $risultato = app(CreditoService::class)->perAnagrafica($data->condominio->id, $data->anagrafica->id);

        $this->assertEquals(8000, $risultato['totale_cents']); // 50 + 30
        $this->assertCount(2, $risultato['per_gestione']);

        $perNome = collect($risultato['per_gestione'])->keyBy('gestione_nome');
        $this->assertEquals(5000, $perNome['Ordinaria']['importo_cents']);
        $this->assertEquals(3000, $perNome['Straordinaria']['importo_cents']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function per_anagrafica_esclude_quote_senza_credito()
    {
        $data = $this->creaScenarioDueGestioni();

        // Consuma del tutto il credito straordinario
        $data->quotaCreditoStraordinaria->pagamenti()->attach(
            \App\Models\Gestionale\ScritturaContabile::create([
                'condominio_id' => $data->condominio->id,
                'esercizio_id' => $data->esercizio->id,
                'gestione_id' => $data->gestioneStraordinaria->id,
                'data_registrazione' => now(),
                'data_competenza' => now(),
                'causale' => 'Consumo test',
                'tipo_movimento' => 'storno_credito',
                'stato' => 'registrata',
            ])->id,
            ['importo_pagato' => -3000, 'data_pagamento' => now()]
        );
        $data->quotaCreditoStraordinaria->ricalcolaStato();

        $risultato = app(CreditoService::class)->perAnagrafica($data->condominio->id, $data->anagrafica->id);

        $this->assertEquals(5000, $risultato['totale_cents']);
        $this->assertCount(1, $risultato['per_gestione']);
        $this->assertEquals('Ordinaria', $risultato['per_gestione'][0]['gestione_nome']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function per_condominio_elenca_le_anagrafiche_con_credito()
    {
        $data = $this->creaScenarioDueGestioni();

        $risultato = app(CreditoService::class)->perCondominio($data->condominio->id);

        $this->assertCount(1, $risultato);
        $this->assertEquals($data->anagrafica->id, $risultato->first()['anagrafica_id']);
        $this->assertEquals('Mario', $risultato->first()['nome']);
        $this->assertEquals(8000, $risultato->first()['totale_cents']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function per_condominio_rispetta_il_filtro_anagrafiche_ids()
    {
        $data = $this->creaScenarioDueGestioni();

        $altraAnagrafica = Anagrafica::create([
            'condominio_id' => $data->condominio->id,
            'nome' => 'Luigi',
            'cognome' => 'Bianchi',
            'email' => 'luigi@test.it',
            'indirizzo' => 'Via Bianchi 1',
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'codice_fiscale' => 'BNCLGU80A01H501X',
        ]);

        $risultatoFiltrato = app(CreditoService::class)->perCondominio($data->condominio->id, [$altraAnagrafica->id]);
        $this->assertCount(0, $risultatoFiltrato);

        $risultatoCompleto = app(CreditoService::class)->perCondominio($data->condominio->id, [$data->anagrafica->id]);
        $this->assertCount(1, $risultatoCompleto);
    }
}
