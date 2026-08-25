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
use App\Services\Dashboard\Widgets\CreditiDaCompensareWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il widget «Crediti da compensare» diceva **quanto** credito ha un condòmino e mandava alla
 * pagina di incasso con la sola anagrafica precompilata. L'amministratore arrivava davanti a un
 * elenco di rate senza sapere quale di quelle il credito coprisse: la regia mancava tutta.
 *
 * Da questa versione il widget dice anche **cosa copre**, e il link porta la rata bersaglio.
 *
 * Perché il link NON porta `intent_usa_credito`: quel parametro significa «il condòmino ha
 * chiesto di usare il suo credito» e accende un avviso che lo dichiara. Qui a muoversi è
 * l'amministratore di sua iniziativa, e scriverlo sarebbe una richiesta che nessuno ha fatto.
 * La rata bersaglio da sola basta: la pagina attiva comunque il credito quando la riconosce
 * (StoreIncassoRateAction lato server, `isInboxMode` lato pagina).
 */
class WidgetCreditiCompensabiliTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): object
    {
        $condominio = Condominio::create([
            'nome' => 'Condominio Widget',
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

        $gestione = Gestione::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Ordinaria',
            'tipo' => 'ordinaria',
            'data_inizio' => '2025-01-01',
        ]);
        $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Int 1', 'descrizione' => 'Appartamento',
            'interno' => '1', 'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
        ]);

        $anagrafica = Anagrafica::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Mario Rossi',
            'email' => 'widget@test.it',
            'indirizzo' => 'Via Verdi 10', 'cap' => '00100',
            'citta' => 'Roma', 'provincia' => 'RM',
            'codice_fiscale' => 'RSSMRA80A01H501U',
        ]);
        $immobile->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => now()->subYear(),
        ]);

        $piano = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'Piano', 'numero_rate' => 2,
        ]);

        $crea = function (int $numero, int $importo, string $scadenza) use ($piano, $anagrafica, $immobile) {
            $rata = Rata::create([
                'piano_rate_id' => $piano->id,
                'numero_rata' => $numero,
                'data_scadenza' => $scadenza,
                'importo_totale' => $importo,
                'stato' => 'emessa',
            ]);
            RataQuote::create([
                'rata_id' => $rata->id,
                'anagrafica_id' => $anagrafica->id,
                'immobile_id' => $immobile->id,
                'importo' => $importo, 'importo_pagato' => 0,
                'stato' => $importo < 0 ? 'credito' : 'da_pagare',
                'data_scadenza' => $scadenza,
            ]);

            return $rata;
        };

        // 200 € di credito; due rate aperte da 150 e 300.
        $crea(0, -20000, '2025-01-01');
        $rataUno = $crea(1, 15000, '2025-03-31');
        $crea(2, 30000, '2025-09-30');

        return (object) compact('condominio', 'esercizio', 'gestione', 'immobile', 'anagrafica', 'piano', 'rataUno');
    }

    private function voce(object $s): array
    {
        return app(CreditiDaCompensareWidget::class)->payload($s->condominio->id)[0];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_widget_dice_quale_rata_il_credito_copre(): void
    {
        $s = $this->scenario();
        $voce = $this->voce($s);

        $this->assertSame('€ 200,00', $voce['totale_formatted']);
        $this->assertSame('€ 200,00', $voce['compensabile_formatted'], 'Tutto il credito è spendibile.');
        $this->assertSame(
            $s->rataUno->id,
            $voce['rata_bersaglio_id'],
            'La rata più vicina a scadere è quella su cui mandare l\'amministratore.'
        );
        $this->assertStringContainsString('rata 1', strtolower($voce['copre']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_link_porta_la_rata_bersaglio_ma_non_una_richiesta_mai_fatta(): void
    {
        $s = $this->scenario();
        $voce = $this->voce($s);

        $this->assertStringContainsString('prefill_anagrafica_id='.$s->anagrafica->id, $voce['url']);
        $this->assertStringContainsString('prefill_rata_id='.$s->rataUno->id, $voce['url']);
        $this->assertStringNotContainsString(
            'intent_usa_credito',
            $voce['url'],
            'Quel parametro dichiara una richiesta del condòmino: qui a muoversi è l\'amministratore.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_senza_niente_da_coprire_lo_dice_invece_di_promettere(): void
    {
        $s = $this->scenario();

        // Salda entrambe le rate aperte: resta il credito, sparisce il debito.
        RataQuote::whereHas('rata', fn ($r) => $r->where('numero_rata', '>', 0))
            ->update(['importo_pagato' => \DB::raw('importo')]);

        $voce = $this->voce($s);

        $this->assertSame('€ 200,00', $voce['totale_formatted'], 'Il credito c\'è ancora.');
        $this->assertSame(0, $voce['compensabile_cents']);
        $this->assertNull($voce['rata_bersaglio_id']);
        $this->assertStringNotContainsString(
            'prefill_rata_id',
            $voce['url'],
            'Senza una rata da coprire il link non deve puntare a niente.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function la_riga_che_invita_a_compensare_offre_la_strada_per_farlo(): void
    {
        // Credito sulla straordinaria, rata aperta sull'ordinaria: il consiglio non attraversa
        // le gestioni da solo, quindi `compensabile_cents` è zero — ma la frase dice
        // «la compensazione è possibile, va confermata da te». Rendere non cliccabile ogni riga
        // a compensabile zero trasformava quell'invito in un vicolo cieco: prima funzionava,
        // si arrivava alla pagina di incasso e si spuntava la casella cross-gestione.
        $s = $this->scenario();

        $straordinaria = Gestione::create([
            'condominio_id' => $s->condominio->id, 'nome' => 'Straordinaria',
            'tipo' => 'straordinaria', 'data_inizio' => '2025-01-01',
        ]);
        $straordinaria->esercizi()->attach($s->esercizio->id, ['attiva' => true]);

        // Il credito si sposta sulla straordinaria: la rata 0 dell'ordinaria sparisce.
        RataQuote::whereHas('rata', fn ($r) => $r->where('numero_rata', 0))->delete();

        $pianoStra = PianoRate::create([
            'condominio_id' => $s->condominio->id, 'gestione_id' => $straordinaria->id,
            'nome' => 'Piano straordinario', 'numero_rate' => 1,
        ]);
        $rataStra = Rata::create([
            'piano_rate_id' => $pianoStra->id, 'numero_rata' => 0,
            'data_scadenza' => '2025-01-01', 'importo_totale' => -20000, 'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rataStra->id, 'anagrafica_id' => $s->anagrafica->id,
            'immobile_id' => $s->immobile->id,
            'importo' => -20000, 'importo_pagato' => 0,
            'stato' => 'credito', 'data_scadenza' => '2025-01-01',
        ]);

        $voce = $this->voce($s);

        $this->assertSame(0, $voce['compensabile_cents'], 'Il consiglio non attraversa le gestioni.');
        $this->assertStringContainsString('altra gestione', $voce['copre']);
        $this->assertTrue(
            $voce['azionabile'],
            'La frase dice che si può fare: la riga deve dare il modo di arrivarci.'
        );
        $this->assertStringContainsString('prefill_anagrafica_id='.$s->anagrafica->id, $voce['url']);
        $this->assertStringNotContainsString(
            'prefill_rata_id',
            $voce['url'],
            'Nessun bersaglio scelto dal consiglio: la rata la sceglie l\'amministratore.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function la_riga_senza_niente_da_fare_resta_non_azionabile(): void
    {
        // Controprova: la correzione non deve riaprire il vicolo cieco che aveva chiuso.
        $s = $this->scenario();
        RataQuote::whereHas('rata', fn ($r) => $r->where('numero_rata', '>', 0))
            ->update(['importo_pagato' => \DB::raw('importo')]);

        $voce = $this->voce($s);

        $this->assertSame(0, $voce['compensabile_cents']);
        $this->assertFalse($voce['azionabile']);
        $this->assertStringContainsString('Nessuna rata', $voce['copre']);
    }
}
