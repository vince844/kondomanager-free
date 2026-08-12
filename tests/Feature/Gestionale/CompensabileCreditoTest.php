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

/**
 * Il «compensabile»: non quanto credito ha un condòmino, ma **quale debito quel credito copre**.
 *
 * Perché non è un numero solo. Il motore preleva il credito dalle quote di UNA rata alla volta
 * (StoreIncassoRateAction:265-271, tetto a :308-312), quindi un «Rossi ha 300 € di credito»
 * esposto come cifra unica non è eseguibile: al salvataggio serve una riga di payload per ogni
 * rata di origine, e senza quella la registrazione risponde «Credito insufficiente». Il
 * compensabile deve quindi portarsi dietro sia le rate che copre sia le rate da cui il credito
 * viene preso.
 *
 * (Fino alla beta.49 quel rifiuto arrivava all'amministratore come **pagina 500**: ora è un
 * errore di validazione con l'importo disponibile in chiaro — vedi
 * `CompensazioneCreditoRifiutataTest`. Resta comunque un rifiuto, e il compensabile serve
 * esattamente a non incontrarlo.)
 *
 * Le due regole di perimetro, decise e non dedotte:
 *
 * - **Solo rate emesse.** Proporre di compensare una rata che il condòmino non ha mai ricevuto
 *   sarebbe un consiglio su un debito che per lui non esiste ancora.
 * - **Solo dentro la stessa gestione.** Attraversare ordinaria e straordinaria è permesso dal
 *   motore, ma richiede per disegno una spunta esplicita dell'amministratore
 *   (IncassoRateNew.vue:746-760): un suggerimento automatico non può darla per acquisita. Il
 *   credito sull'altra gestione resta contato nel totale — semplicemente non entra nel consiglio.
 */
class CompensabileCreditoTest extends TestCase
{
    use RefreshDatabase;

    private Condominio $condominio;
    private Esercizio $esercizio;
    private Immobile $immobile;
    private Anagrafica $anagrafica;

    protected function setUp(): void
    {
        parent::setUp();

        $this->condominio = Condominio::create([
            'nome' => 'Condominio Compensabile',
            'uuid' => (string) Str::uuid(),
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Milano',
            'cap' => '20100',
            'provincia' => 'MI',
        ]);

        $this->esercizio = Esercizio::create([
            'condominio_id' => $this->condominio->id,
            'nome' => '2025',
            'data_inizio' => '2025-01-01',
            'data_fine' => '2025-12-31',
            'stato' => 'aperto',
        ]);

        $this->immobile = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 1',
            'descrizione' => 'Appartamento test',
            'interno' => '1',
            'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
        ]);

        $this->anagrafica = Anagrafica::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Mario Rossi',
            'email' => 'compensabile@test.it',
            'indirizzo' => 'Via Verdi 10',
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'codice_fiscale' => 'RSSMRA80A01H501U',
        ]);

        $this->immobile->anagrafiche()->attach($this->anagrafica->id, [
            'tipologia' => 'proprietario',
            'quota' => 100,
            'attivo' => true,
            'data_inizio' => now()->subYear(),
        ]);
    }

    private function gestione(string $tipo = 'ordinaria'): Gestione
    {
        $g = Gestione::create([
            'condominio_id' => $this->condominio->id,
            'nome' => ucfirst($tipo),
            'tipo' => $tipo,
            'data_inizio' => '2025-01-01',
        ]);
        $g->esercizi()->attach($this->esercizio->id, ['attiva' => true]);

        return $g;
    }

    private function piano(Gestione $gestione): PianoRate
    {
        return PianoRate::create([
            'condominio_id' => $this->condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'Piano '.$gestione->nome,
            'numero_rate' => 3,
        ]);
    }

    /** Crea una rata con la sua unica quota, e la restituisce. */
    private function rata(
        PianoRate $piano,
        int $numero,
        int $importoCents,
        int $pagatoCents = 0,
        string $stato = 'emessa',
        string $scadenza = '2025-06-30',
    ): Rata {
        $rata = Rata::create([
            'piano_rate_id' => $piano->id,
            'numero_rata' => $numero,
            'data_scadenza' => $scadenza,
            'importo_totale' => $importoCents,
            'stato' => $stato,
        ]);

        RataQuote::create([
            'rata_id' => $rata->id,
            'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => $importoCents,
            'importo_pagato' => $pagatoCents,
            'stato' => $importoCents < 0 ? 'credito' : 'da_pagare',
            'data_scadenza' => $scadenza,
        ]);

        return $rata;
    }

    private function compensabile(): array
    {
        return app(CreditoService::class)
            ->perAnagrafica($this->condominio->id, $this->anagrafica->id)['compensabile'];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_compensabile_copre_le_rate_in_ordine_di_scadenza(): void
    {
        $g = $this->gestione();
        $p = $this->piano($g);

        // 300 € di credito, contro 100 + 250 di debito aperto.
        $rataCredito = $this->rata($p, 0, -30000, 0, 'emessa', '2025-01-01');
        $rataUno     = $this->rata($p, 1, 10000, 0, 'emessa', '2025-03-31');
        $rataDue     = $this->rata($p, 2, 25000, 0, 'emessa', '2025-09-30');

        $c = $this->compensabile();

        $this->assertSame(30000, $c['importo_cents'], 'Copre 300 € dei 350 € dovuti.');
        $this->assertSame(0, $c['residuo_credito_cents'], 'Il credito viene consumato per intero.');

        $this->assertCount(2, $c['rate_coperte']);
        $this->assertSame($rataUno->id, $c['rate_coperte'][0]['rata_id'], 'La più vicina a scadere per prima.');
        $this->assertSame(10000, $c['rate_coperte'][0]['coperto_cents']);
        $this->assertSame($rataDue->id, $c['rate_coperte'][1]['rata_id']);
        $this->assertSame(20000, $c['rate_coperte'][1]['coperto_cents'], 'La seconda solo in parte.');

        $this->assertSame(
            [['rata_id' => $rataCredito->id, 'credito_cents' => 30000]],
            array_map(
                fn ($o) => ['rata_id' => $o['rata_id'], 'credito_cents' => $o['credito_cents']],
                $c['origini']
            ),
            'Le origini servono al salvataggio: il credito si preleva per rata, non in blocco.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_che_avanza_viene_dichiarato(): void
    {
        $g = $this->gestione();
        $p = $this->piano($g);

        $this->rata($p, 0, -50000, 0, 'emessa', '2025-01-01');
        $this->rata($p, 1, 10000, 0, 'emessa', '2025-03-31');

        $c = $this->compensabile();

        $this->assertSame(10000, $c['importo_cents'], 'Si può compensare solo quanto si deve.');
        $this->assertSame(40000, $c['residuo_credito_cents'], 'I 400 € che avanzano vanno detti, non taciuti.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function le_rate_non_emesse_non_entrano_nel_consiglio(): void
    {
        $g = $this->gestione();
        $p = $this->piano($g);

        $this->rata($p, 0, -30000, 0, 'emessa', '2025-01-01');
        $this->rata($p, 1, 10000, 0, 'bozza', '2025-03-31');

        $c = $this->compensabile();

        $this->assertSame(0, $c['importo_cents'], 'La rata in bozza il condòmino non l\'ha mai ricevuta.');
        $this->assertSame([], $c['rate_coperte']);
        $this->assertSame(30000, $c['residuo_credito_cents']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_consiglio_non_attraversa_le_gestioni_da_solo(): void
    {
        $ordinaria = $this->gestione('ordinaria');
        $straordinaria = $this->gestione('straordinaria');

        // Credito sulla straordinaria, debito sull'ordinaria.
        $this->rata($this->piano($straordinaria), 0, -30000, 0, 'emessa', '2025-01-01');
        $this->rata($this->piano($ordinaria), 1, 10000, 0, 'emessa', '2025-03-31');

        $c = $this->compensabile();

        $this->assertSame(
            0,
            $c['importo_cents'],
            'Attraversare le gestioni è permesso ma richiede una spunta esplicita: un '
            .'suggerimento automatico non può darla per acquisita.'
        );
        $this->assertSame(30000, $c['residuo_credito_cents'], 'Il credito resta contato: non sparisce, non è consigliabile.');

        // Ma dirgli «non c'è niente da coprire» sarebbe falso: la rata aperta esiste, e il
        // motore la può coprire — serve solo la spunta che il prodotto già offre. Collassare i
        // due casi nella stessa frase è lo stesso difetto che questa release chiude, ribaltato.
        $this->assertStringNotContainsString('Nessuna rata aperta', $c['frase']);
        $this->assertStringContainsString('gestione', strtolower($c['frase']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_gia_speso_non_viene_riproposto(): void
    {
        $g = $this->gestione();
        $p = $this->piano($g);

        // 300 € di credito di cui 200 già consumati (il consumo si scrive negativo).
        $this->rata($p, 0, -30000, -20000, 'emessa', '2025-01-01');
        $this->rata($p, 1, 50000, 0, 'emessa', '2025-03-31');

        $c = $this->compensabile();

        $this->assertSame(10000, $c['importo_cents'], 'Restano 100 €, non 300.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function senza_debito_aperto_il_compensabile_e_zero_ma_il_credito_resta(): void
    {
        $g = $this->gestione();
        $p = $this->piano($g);

        $this->rata($p, 0, -30000, 0, 'emessa', '2025-01-01');

        $dati = app(CreditoService::class)->perAnagrafica($this->condominio->id, $this->anagrafica->id);

        $this->assertSame(30000, $dati['totale_cents'], 'Il totale del credito non cambia.');
        $this->assertSame(0, $dati['compensabile']['importo_cents']);
        $this->assertSame([], $dati['compensabile']['rate_coperte']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_di_una_rata_mista_va_su_un_altra_rata_non_su_se_stesso(): void
    {
        // Il caso che l'interfaccia mostra come «N/D»: sulla stessa rata una quota strapagata
        // e una scoperta si annullano. Quel credito è reale e il motore lo può spendere — ma
        // **non su quella stessa rata**: la riga arriva a residuo netto zero, quindi non ha
        // casella importo e nessun payload positivo è costruibile per lei. Un consiglio che la
        // nominasse come bersaglio sarebbe ineseguibile, che è il difetto che questa release
        // sta chiudendo.
        $g = $this->gestione();
        $p = $this->piano($g);

        $rataMista = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 1,
            'data_scadenza' => '2025-03-31', 'importo_totale' => 0, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rataMista->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => 20000, 'importo_pagato' => 30000,
            'stato' => 'pagata', 'data_scadenza' => '2025-03-31',
        ]);
        $altroImmobile = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 2', 'descrizione' => 'Secondo',
            'interno' => '2', 'foglio' => '1', 'particella' => '1', 'subalterno' => '2',
        ]);
        RataQuote::create([
            'rata_id' => $rataMista->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altroImmobile->id,
            'importo' => 10000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-03-31',
        ]);

        // La rata davvero aperta, quella su cui il credito può fare qualcosa.
        $rataAperta = $this->rata($p, 2, 15000, 0, 'emessa', '2025-06-30');

        $c = $this->compensabile();

        $this->assertSame(
            [$rataAperta->id],
            array_column($c['rate_coperte'], 'rata_id'),
            'Il bersaglio deve essere la rata con residuo aperto, non quella che netta a zero: '
            .'su quella la pagina di incasso non ha nemmeno la casella per digitare un importo.'
        );
        $this->assertSame(10000, $c['importo_cents']);
        $this->assertSame(
            $rataMista->id,
            $c['origini'][0]['rata_id'],
            'Il credito si preleva comunque dalla rata mista: è lì che vive.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function senza_altre_rate_il_credito_interno_non_viene_promesso(): void
    {
        // Stessa rata mista, ma sola: non c'è nessun posto dove quel credito possa andare, e
        // dirlo è meglio che indicare un bersaglio su cui non si può costruire niente.
        $g = $this->gestione();
        $p = $this->piano($g);

        $rataMista = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 1,
            'data_scadenza' => '2025-03-31', 'importo_totale' => 0, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rataMista->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => 20000, 'importo_pagato' => 30000,
            'stato' => 'pagata', 'data_scadenza' => '2025-03-31',
        ]);
        $altroImmobile = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 2', 'descrizione' => 'Secondo',
            'interno' => '2', 'foglio' => '1', 'particella' => '1', 'subalterno' => '2',
        ]);
        RataQuote::create([
            'rata_id' => $rataMista->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altroImmobile->id,
            'importo' => 10000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-03-31',
        ]);

        $c = $this->compensabile();

        $this->assertSame(0, $c['importo_cents']);
        $this->assertSame([], $c['rate_coperte']);
        $this->assertStringContainsString('Nessuna rata', $c['frase']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_interno_a_una_rata_ancora_scoperta_non_viene_promesso(): void
    {
        // Rata con una quota scoperta da 100 € e una strapagata da 30 €: il netto resta
        // positivo, 70 €. Su quella riga la schermata di incasso mostra la casella dell'importo
        // e NON il pulsante «Usa credito», quindi quei 30 € lì non sono selezionabili: contarli
        // come compensabili prometterebbe una cifra che l'unica pagina dove si compensa non
        // sa offrire. E il residuo da mostrare è il netto, 70 €, non i 100 € lordi.
        $g = $this->gestione();
        $p = $this->piano($g);

        $rata = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 4,
            'data_scadenza' => '2025-03-31', 'importo_totale' => 15000, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rata->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => 10000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-03-31',
        ]);
        $altro = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 3', 'descrizione' => 'Terzo',
            'interno' => '3', 'foglio' => '1', 'particella' => '1', 'subalterno' => '3',
        ]);
        RataQuote::create([
            'rata_id' => $rata->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altro->id,
            'importo' => 5000, 'importo_pagato' => 8000,
            'stato' => 'pagata', 'data_scadenza' => '2025-03-31',
        ]);

        $c = $this->compensabile();

        $this->assertSame(0, $c['importo_cents'], 'Quel credito non è offerto da nessuna schermata.');
        $this->assertSame([], $c['rate_coperte']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function con_una_gestione_sola_non_si_parla_di_altre_gestioni(): void
    {
        // `debito_altrove_cents` confrontava la gestione del debito con quelle delle FONTI di
        // credito. Quando nessuna rata offre credito quella lista è vuota, `in_array` è sempre
        // falso, e ogni debito finisce contato come «altrove»: su un condominio con una
        // gestione sola la frase parlava di un attraversamento che non esiste.
        $g = $this->gestione();
        $p = $this->piano($g);

        // Rata a netto positivo: nessuna fonte di credito offerta, ma il credito esiste.
        $rata = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 1,
            'data_scadenza' => '2025-03-31', 'importo_totale' => 7000, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rata->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => 10000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-03-31',
        ]);
        $altro = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 9', 'descrizione' => 'Nono',
            'interno' => '9', 'foglio' => '1', 'particella' => '1', 'subalterno' => '9',
        ]);
        RataQuote::create([
            'rata_id' => $rata->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altro->id,
            'importo' => 5000, 'importo_pagato' => 8000,
            'stato' => 'pagata', 'data_scadenza' => '2025-03-31',
        ]);

        $c = $this->compensabile();

        $this->assertSame(0, $c['importo_cents']);
        $this->assertStringNotContainsString(
            'altra gestione',
            $c['frase'],
            'Il condominio ha una gestione sola: non c\'è niente da attraversare.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_dichiara_avanzi_di_credito_che_nessuna_schermata_offre(): void
    {
        // «Avanzano X» si calcolava sul totale LORDO del credito, mentre il compensabile è
        // limitato a quello che le schermate offrono davvero. Su una rata con credito 100 e
        // debito 40 il servizio annunciava 40 € di avanzo che non esistono: sono assorbiti
        // dalla quota a debito della stessa rata, e nessuna riga li mette a disposizione.
        $g = $this->gestione();
        $p = $this->piano($g);

        $rataCredito = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 1,
            'data_scadenza' => '2025-01-31', 'importo_totale' => -6000, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rataCredito->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => -10000, 'importo_pagato' => 0,
            'stato' => 'credito', 'data_scadenza' => '2025-01-31',
        ]);
        $altro = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 8', 'descrizione' => 'Ottavo',
            'interno' => '8', 'foglio' => '1', 'particella' => '1', 'subalterno' => '8',
        ]);
        RataQuote::create([
            'rata_id' => $rataCredito->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altro->id,
            'importo' => 4000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-01-31',
        ]);

        // Una rata aperta da 60, esattamente quanto la prima rata offre al netto.
        $this->rata($p, 2, 6000, 0, 'emessa', '2025-02-28');

        $c = $this->compensabile();

        $this->assertSame(6000, $c['importo_cents'], 'Offre 60 € netti e li usa tutti.');
        $this->assertSame(
            0,
            $c['residuo_credito_cents'],
            'Non avanza niente: i 40 € di differenza sono assorbiti dal debito della stessa rata.'
        );
        $this->assertStringNotContainsString('Avanzano', $c['frase']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_netto_di_una_rata_in_bozza_conta_anche_il_suo_debito(): void
    {
        // Asimmetria fra i due lati: `debitiAperti()` scarta le rate in bozza, `queryCreditoBase()`
        // no. Il netto di una rata in bozza usciva quindi come «solo credito», perché il suo
        // debito spariva per strada — e la Rata 0, dove il credito da saldi iniziali vive quasi
        // sempre, nasce proprio in bozza.
        //
        // Il filtro sulle bozze serve a non PROPORRE come bersaglio una rata mai ricevuta. Non
        // deve falsare il conto di quanto quella rata offre.
        $g = $this->gestione();
        $p = $this->piano($g);

        $rataZero = Rata::create([
            'piano_rate_id' => $p->id, 'numero_rata' => 0,
            'data_scadenza' => '2025-01-01', 'importo_totale' => 20000, 'stato' => 'bozza',
            'descrizione' => 'Saldo Pregresso',
        ]);
        RataQuote::create([
            'rata_id' => $rataZero->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $this->immobile->id,
            'importo' => -10000, 'importo_pagato' => 0,
            'stato' => 'credito', 'data_scadenza' => '2025-01-01',
        ]);
        $altro = Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome' => 'Int 7', 'descrizione' => 'Settimo',
            'interno' => '7', 'foglio' => '1', 'particella' => '1', 'subalterno' => '7',
        ]);
        RataQuote::create([
            'rata_id' => $rataZero->id, 'anagrafica_id' => $this->anagrafica->id,
            'immobile_id' => $altro->id,
            'importo' => 30000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => '2025-01-01',
        ]);

        $this->rata($p, 1, 20000, 0, 'emessa', '2025-03-31');

        $c = $this->compensabile();

        $this->assertSame(
            0,
            $c['importo_cents'],
            'La Rata 0 ha 100 € a credito e 300 € a debito: netto +200, non offre niente. '
            .'Contarne il solo credito produrrebbe un consiglio che la pagina di incasso non '
            .'sa eseguire, perché lì quella riga è a debito.'
        );
        $this->assertSame([], $c['rate_coperte']);
    }
}
