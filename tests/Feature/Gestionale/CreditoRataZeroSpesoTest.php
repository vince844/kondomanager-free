<?php

namespace Tests\Feature\Gestionale;

use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Listeners\Gestionale\SyncScadenziarioWithPianoRate;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il «Salvadanaio» che il condòmino vede nel portale legge `meta.credito_rata_zero`, uno
 * snapshot scritto da questo listener. Lo snapshot somma la colonna `importo` delle quote di
 * Rata 0 e **ignora `importo_pagato`**, mentre la misura autoritativa del credito ancora
 * spendibile — `RataQuote::credito_disponibile` — sottrae quanto è già stato consumato.
 *
 * È la stessa domanda («quanto credito è ancora spendibile?») con due risposte in due posti:
 * la lezione della beta.44, qui fra portale e motore invece che fra pannello e motore.
 *
 * Conseguenza a schermo, verificata sul codice: il condòmino vede un credito che non ha più,
 * `creditoCapiente` lo dichiara sufficiente e il pulsante «Sì, salda la rata con il credito»
 * invia `credito_richiesto` calcolato su quel numero
 * (resources/js/components/eventi/EventDetailsDialog.vue:113-127). A fermarlo c'è solo il
 * backend, quando l'amministratore apre il task.
 */
class CreditoRataZeroSpesoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Piano con distribuzione a rata zero: un credito di 300 € sulla Rata 0, di cui 100 €
     * sono già stati spesi, e una Rata 1 a debito che genera l'evento del condòmino.
     */
    private function creaScenario(int $creditoCents, int $giaSpesoCents): object
    {
        $condominio = Condominio::create([
            'nome' => 'Condominio Salvadanaio',
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
            'nome' => 'Int 1',
            'descrizione' => 'Appartamento test',
            'interno' => '1',
            'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
        ]);

        $anagrafica = Anagrafica::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Mario Rossi',
            'email' => 'mario.salvadanaio@test.it',
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

        $utente = User::factory()->create();

        // Il condòmino che guarderà la propria scadenza. `User::anagrafica()` è un hasOne:
        // il legame vive sulla colonna `user_id` dell'anagrafica.
        $condomino = User::factory()->create();
        $anagrafica->user_id = $condomino->id;
        $anagrafica->save();

        $piano = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'Piano con rata zero',
            'numero_rate' => 1,
            'metodo_distribuzione' => 'rata_zero',
        ]);

        // Rata 0 — il credito. Il consumo si scrive come `importo_pagato` NEGATIVO
        // (StoreIncassoRateAction:338-341), ed è per questo che l'accessor usa abs() su
        // entrambe le colonne.
        $rataZero = Rata::create([
            'piano_rate_id' => $piano->id,
            'numero_rata' => 0,
            'data_scadenza' => '2025-01-01',
            'importo_totale' => -$creditoCents,
            'stato' => 'emessa',
            'descrizione' => 'Saldo Pregresso',
        ]);
        RataQuote::create([
            'rata_id' => $rataZero->id,
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'importo' => -$creditoCents,
            'importo_pagato' => -$giaSpesoCents,
            'stato' => 'credito',
            'tipo' => 'saldo_iniziale',
            'data_scadenza' => '2025-01-01',
        ]);

        // Rata 1 — il debito, cioè la rata su cui nasce l'evento che il condòmino apre.
        $rataUno = Rata::create([
            'piano_rate_id' => $piano->id,
            'numero_rata' => 1,
            'data_scadenza' => '2025-06-30',
            'importo_totale' => 50000,
            'stato' => 'emessa',
        ]);
        RataQuote::create([
            'rata_id' => $rataUno->id,
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'importo' => 50000,
            'importo_pagato' => 0,
            'stato' => 'da_pagare',
            'data_scadenza' => '2025-06-30',
        ]);

        return (object) compact('condominio', 'esercizio', 'gestione', 'anagrafica', 'piano', 'utente', 'condomino', 'rataUno');
    }

    private function eseguiListener(object $s): void
    {
        (new SyncScadenziarioWithPianoRate())->handle(new PianoRateStatusUpdated(
            $s->condominio,
            $s->esercizio,
            $s->piano,
            $s->utente,
            StatoPianoRate::BOZZA,
            StatoPianoRate::APPROVATO,
        ));
    }

    private function creditoMostratoAlCondomino(object $s): int
    {
        $evento = Evento::query()
            ->whereJsonContains('meta->context->rata_id', $s->rataUno->id)
            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
            ->firstOrFail();

        return (int) ($evento->meta['credito_rata_zero'] ?? 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_salvadanaio_non_mostra_il_credito_gia_speso(): void
    {
        // 300 € di credito, 100 € già consumati: ne restano 200.
        $s = $this->creaScenario(creditoCents: 30000, giaSpesoCents: 10000);

        $this->eseguiListener($s);

        $this->assertSame(
            20000,
            $this->creditoMostratoAlCondomino($s),
            'Il Salvadanaio deve mostrare il credito ANCORA spendibile (200 €), non quello '
            .'originario (300 €): 100 € sono già stati consumati.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_salvadanaio_concorda_con_la_misura_autoritativa_del_credito(): void
    {
        // La controprova che conta: qualunque sia lo scenario, lo snapshot mostrato al
        // condòmino non deve mai divergere da `credito_disponibile`, che è la misura su cui
        // il motore decide davvero quanto si può compensare.
        $s = $this->creaScenario(creditoCents: 45000, giaSpesoCents: 45000);

        $this->eseguiListener($s);

        $atteso = RataQuote::query()
            ->whereHas('rata', fn ($r) => $r->where('numero_rata', 0))
            ->where('anagrafica_id', $s->anagrafica->id)
            ->get()
            ->sum(fn (RataQuote $q) => $q->credito_disponibile);

        $this->assertSame(0, (int) $atteso, 'Precondizione: il credito è stato consumato per intero.');
        $this->assertSame(
            0,
            $this->creditoMostratoAlCondomino($s),
            'Credito esaurito: il Salvadanaio non deve proporre di saldare la rata con un '
            .'credito che non esiste più.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_credito_mai_toccato_resta_mostrato_per_intero(): void
    {
        // Controprova nell'altro verso: la correzione non deve nascondere il credito buono.
        $s = $this->creaScenario(creditoCents: 30000, giaSpesoCents: 0);

        $this->eseguiListener($s);

        $this->assertSame(30000, $this->creditoMostratoAlCondomino($s));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_salvadanaio_non_gonfia_quando_la_rata_zero_porta_anche_un_debito(): void
    {
        // Effetto collaterale della correzione: `credito_disponibile` è clampato a zero sulle
        // quote a debito, quindi su una Rata 0 con una quota a credito e una a debito il
        // Salvadanaio smetterebbe di sottrarre il debito e il numero **crescerebbe**. Il
        // condòmino vedrebbe una capienza che non ha, e chiederebbe la compensazione su quella.
        $s = $this->creaScenario(creditoCents: 30000, giaSpesoCents: 0);

        $piano = $s->piano;
        $rataZero = $piano->rate()->where('numero_rata', 0)->firstOrFail();
        $altro = \App\Models\Immobile::create([
            'condominio_id' => $s->condominio->id,
            'nome' => 'Int 2', 'descrizione' => 'Secondo',
            'interno' => '2', 'foglio' => '1', 'particella' => '1', 'subalterno' => '2',
        ]);
        RataQuote::create([
            'rata_id' => $rataZero->id,
            'anagrafica_id' => $s->anagrafica->id,
            'immobile_id' => $altro->id,
            'importo' => 10000, 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'tipo' => 'saldo_iniziale',
            'data_scadenza' => '2025-01-01',
        ]);

        $this->eseguiListener($s);

        $this->assertSame(
            20000,
            $this->creditoMostratoAlCondomino($s),
            'Sulla stessa Rata 0 ci sono 300 € a credito e 100 € a debito: la capienza vera è 200 €.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_condomino_vede_il_credito_aggiornato_dopo_che_e_stato_speso(): void
    {
        // ⚠️ Questo test gira nell'ORDINE CHE L'APPLICAZIONE PRODUCE DAVVERO, ed è la ragione
        // per cui esiste. Gli altri di questo file costruiscono lo stato «credito già speso»
        // *prima* di eseguire il listener — una sequenza che non capita mai. Il listener scrive
        // `meta.credito_rata_zero` solo all'approvazione del piano e la guardia `if ($esiste)
        // continue;` gli impedisce di riscriverlo: nella realtà il credito viene consumato
        // DOPO, e lo snapshot resta fermo al valore di allora.
        //
        // La correzione della formula, da sola, non arriva mai in produzione. Quello che il
        // condòmino legge deve essere calcolato quando l'evento gli viene servito.
        $s = $this->creaScenario(creditoCents: 30000, giaSpesoCents: 0);

        // 1. Il piano viene approvato: l'evento nasce col credito intero.
        $this->eseguiListener($s);
        $this->assertSame(30000, $this->creditoMostratoAlCondomino($s), 'Precondizione: 300 € a credito.');

        // 2. Più tardi l'amministratore ne spende 200. Nessuno riscrive l'evento.
        RataQuote::whereHas('rata', fn ($r) => $r->where('numero_rata', 0))
            ->where('anagrafica_id', $s->anagrafica->id)
            ->update(['importo_pagato' => -20000]);

        // 3. Il condòmino riapre la sua scadenza.
        $evento = Evento::query()
            ->whereJsonContains('meta->context->rata_id', $s->rataUno->id)
            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
            ->firstOrFail();

        $this->actingAs($s->condomino);

        $servito = (new \App\Http\Resources\Evento\EventoResource($evento))
            ->toArray(request());

        $this->assertSame(
            10000,
            (int) ($servito['meta']['credito_rata_zero'] ?? -1),
            'Ne restano 100 €. Il numero servito al condòmino deve dirlo, non ripetere lo '
            .'snapshot di quando il piano fu approvato.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function servire_molti_eventi_non_moltiplica_le_query(): void
    {
        // Il numero si calcola al momento, e la Resource gira per OGNI evento: il cruscotto del
        // condòmino ne mostra parecchi. Senza memoizzazione sarebbe un N+1 su una pagina che si
        // apre a ogni accesso — il motivo per cui questa correzione era stata dichiarata «da
        // misurare, non da stimare».
        $s = $this->creaScenario(creditoCents: 30000, giaSpesoCents: 0);
        $this->eseguiListener($s);

        $eventi = Evento::query()
            ->whereJsonContains('meta->type', 'scadenza_rata_condomino')
            ->with('anagrafiche')
            ->get();

        $this->assertGreaterThan(0, $eventi->count());

        $this->actingAs($s->condomino);
        $s->condomino->load('anagrafica');

        $query = 0;
        \DB::listen(function () use (&$query) { $query++; });

        // Serve lo stesso evento venti volte: è la forma di una lista.
        foreach (range(1, 20) as $i) {
            foreach ($eventi as $e) {
                (new \App\Http\Resources\Evento\EventoResource($e))->toArray(request());
            }
        }

        $this->assertLessThanOrEqual(
            $eventi->count(),
            $query,
            'Il credito di Rata 0 va calcolato una volta per coppia piano/anagrafica e poi '
            .'riusato: la Resource gira per ogni evento della lista.'
        );
    }
}
