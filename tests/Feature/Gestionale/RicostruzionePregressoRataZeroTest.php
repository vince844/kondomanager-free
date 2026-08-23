<?php

namespace Tests\Feature\Gestionale;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Enums\StatoPianoRate;
use App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il percorso «il passato come posizione»: un condominio che entra in Kondomanager portando
 * dentro solo la fotografia del pregresso, chiamato a pagamento con una Rata 0.
 *
 * È il percorso che consigliamo a chi rileva un condominio senza passaggio di consegne, ed è
 * quindi quello su cui vale la pena sapere esattamente cosa finisce a libro giornale — perché
 * l'emissione registra soltanto `regole_calcolo.importi.quota_pura_gestione`
 * (EmissioneRateController:111-119), che sulla Rata 0 vale zero per costruzione.
 *
 * Le tre domande a cui questi test rispondono con una prova eseguita, non con una lettura:
 *
 *  1. l'emissione di una Rata 0 produce una scrittura contabile? (no)
 *  2. la rata risulta comunque «emessa»? (sì — muro contabile e interfaccia dicono due cose)
 *  3. incassare quella quota che effetto ha su `crediti_condomini`?
 *
 * Il caso è quello reale di un autogestito di quattro unità: € 1.800,00 di pregresso ripartito
 * fra i quattro, più una prima rata di preventivo da € 400,00 a testa.
 */
class RicostruzionePregressoRataZeroTest extends TestCase
{
    use RefreshDatabase;

    /** Pregresso ricostruito dall'estratto conto, per unità, in centesimi. */
    private const PREGRESSO = [45000, 60000, 30000, 45000]; // € 450 / 600 / 300 / 450 = € 1.800,00

    /** Quota di preventivo della prima rata, per unità, in centesimi. */
    private const PREVENTIVO = 40000; // € 400,00

    private function scenario(): object
    {
        $condominio = Condominio::create([
            'nome' => 'Condominio Via Aurelia 12',
            'uuid' => (string) Str::uuid(),
            'indirizzo' => 'Via Aurelia 12',
            'citta' => 'Roma',
            'cap' => '00165',
            'provincia' => 'RM',
        ]);

        $esercizio = Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome' => '2026',
            'data_inizio' => '2026-01-01',
            'data_fine' => '2026-12-31',
            'stato' => 'aperto',
        ]);

        $gestione = Gestione::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Ordinaria 2026',
            'tipo' => 'ordinaria',
            'data_inizio' => '2026-01-01',
        ]);
        $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

        $contoBanca = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '10.10', 'nome' => 'Banca',
            'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita',
        ]);

        $contoCrediti = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '10.20', 'nome' => 'Crediti vs Condomini',
            'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti',
        ]);

        $contoGestione = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '30.10', 'nome' => 'Gestione Rate',
            'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti',
        ]);

        ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '20.10', 'nome' => 'Anticipi Condomini',
            'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti',
        ]);

        $contoPassate = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'codice' => '2301', 'nome' => 'Fondo Passate Gestioni',
            'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'fondi',
        ]);

        $cassa = Cassa::create([
            'condominio_id' => $condominio->id,
            'conto_contabile_id' => $contoBanca->id,
            'nome' => 'Conto corrente condominiale',
            'tipo' => 'banca',
            'attiva' => true,
        ]);

        $pianoRate = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'Piano 2026',
            'numero_rate' => 1,
            'stato' => StatoPianoRate::APPROVATO,
        ]);

        // Rata 0 — assorbe il pregresso. Nasce con quota_pura_gestione = 0.
        $rataZero = Rata::create([
            'piano_rate_id' => $pianoRate->id,
            'numero_rata' => 0,
            'data_scadenza' => '2026-02-28',
            'importo_totale' => array_sum(self::PREGRESSO),
            'stato' => 'bozza',
        ]);

        // Rata 1 — preventivo corrente. Ha una quota_pura_gestione vera.
        $rataUno = Rata::create([
            'piano_rate_id' => $pianoRate->id,
            'numero_rata' => 1,
            'data_scadenza' => '2026-02-28',
            'importo_totale' => self::PREVENTIVO * 4,
            'stato' => 'bozza',
        ]);

        $anagrafiche = [];
        $quoteZero = [];
        $quoteUno = [];

        foreach (['Rossi', 'Bianchi', 'Verdi', 'Neri'] as $i => $cognome) {
            $anagrafica = Anagrafica::create([
                'condominio_id' => $condominio->id,
                'nome' => 'Condomino',
                'cognome' => $cognome,
                'email' => strtolower($cognome).'@aurelia12.test',
                'indirizzo' => 'Via Aurelia 12',
                'cap' => '00165', 'citta' => 'Roma', 'provincia' => 'RM',
            ]);

            $immobile = Immobile::create([
                'condominio_id' => $condominio->id,
                'nome' => 'Int '.($i + 1),
                'descrizione' => 'Appartamento',
                'interno' => (string) ($i + 1),
                'foglio' => '1', 'particella' => '1', 'subalterno' => (string) ($i + 1),
            ]);

            $immobile->anagrafiche()->attach($anagrafica->id, [
                'tipologia' => 'proprietario',
                'quota' => 100,
                'attivo' => true,
                'data_inizio' => '2020-01-01',
            ]);

            $quoteZero[] = RataQuote::create([
                'rata_id' => $rataZero->id,
                'anagrafica_id' => $anagrafica->id,
                'immobile_id' => $immobile->id,
                'importo' => self::PREGRESSO[$i],
                'importo_pagato' => 0,
                'stato' => 'da_pagare',
                'data_scadenza' => '2026-02-28',
                // La forma che GeneratePianoRateAction dà a una quota di Rata 0:
                // tutto l'importo è pregresso, la componente di gestione è zero.
                'regole_calcolo' => ['importi' => ['quota_pura_gestione' => 0]],
            ]);

            $quoteUno[] = RataQuote::create([
                'rata_id' => $rataUno->id,
                'anagrafica_id' => $anagrafica->id,
                'immobile_id' => $immobile->id,
                'importo' => self::PREVENTIVO,
                'importo_pagato' => 0,
                'stato' => 'da_pagare',
                'data_scadenza' => '2026-02-28',
                'regole_calcolo' => ['importi' => ['quota_pura_gestione' => self::PREVENTIVO]],
            ]);

            $anagrafiche[] = $anagrafica;
        }

        return (object) compact(
            'condominio', 'esercizio', 'gestione', 'cassa', 'contoCrediti', 'contoGestione', 'contoPassate',
            'pianoRate', 'rataZero', 'rataUno', 'anagrafiche', 'quoteZero', 'quoteUno'
        );
    }

    private function emetti(object $s, array $rateIds): void
    {
        $request = Request::create('/emetti', 'POST', [
            'rate_ids' => $rateIds,
            'data_emissione' => '2026-01-15',
            'invia_notifiche' => false,
        ]);
        $request->setLaravelSession(app('session.store'));

        app(EmissioneRateController::class)->store($request, $s->condominio, $s->pianoRate);
    }

    private function saldoConto(int $contoContabileId): array
    {
        $dare = (int) DB::table('righe_scritture')
            ->where('conto_contabile_id', $contoContabileId)->where('tipo_riga', 'dare')->sum('importo');
        $avere = (int) DB::table('righe_scritture')
            ->where('conto_contabile_id', $contoContabileId)->where('tipo_riga', 'avere')->sum('importo');

        return ['dare' => $dare, 'avere' => $avere, 'saldo' => $dare - $avere];
    }

    public function test_la_rata_di_solo_preventivo_chiude_tutta_su_gestione_rate(): void
    {
        $s = $this->scenario();

        $this->emetti($s, [$s->rataUno->id]);

        $this->assertSame(self::PREVENTIVO * 4, $this->saldoConto($s->contoCrediti->id)['dare']);
        $this->assertSame(self::PREVENTIVO * 4, $this->saldoConto($s->contoGestione->id)['avere']);
        $this->assertSame(0, $this->saldoConto($s->contoPassate->id)['avere'], 'Senza riporto il Fondo Passate Gestioni non si tocca');

        foreach ($s->quoteUno as $q) {
            $this->assertNotNull($q->fresh()->scrittura_contabile_id);
        }
    }

    /**
     * ★ Il caso che la beta.62 corregge. Fino alla .61 il ramo che legge
     * `regole_calcolo.importi.quota_pura_gestione` era irraggiungibile — cast `'json'` sul
     * Model, quindi array, quindi `(object) $array` superficiale e `isset()` sempre falso —
     * e l'intero importo finiva su Gestione Rate.
     *
     * Su una quota deliberatamente asimmetrica (€ 500,00 di cui € 200,00 di sola gestione)
     * le due componenti ora si separano.
     */
    public function test_la_quota_mista_si_spacca_fra_gestione_e_riporto(): void
    {
        $s = $this->scenario();

        $s->quoteUno[0]->update([
            'importo' => 50000,
            'regole_calcolo' => ['importi' => ['quota_pura_gestione' => 20000, 'saldo_usato' => 30000]],
        ]);

        $this->emetti($s, [$s->rataUno->id]);

        $rigaCredito = DB::table('righe_scritture')
            ->where('conto_contabile_id', $s->contoCrediti->id)
            ->where('anagrafica_id', $s->anagrafiche[0]->id)
            ->first();

        $this->assertSame(50000, (int) $rigaCredito->importo, 'Il condomino deve la quota intera');

        // Gli altri tre portano 400,00 di sola gestione: 120000 + 20000 = 140000.
        $this->assertSame(140000, $this->saldoConto($s->contoGestione->id)['avere']);
        $this->assertSame(30000, $this->saldoConto($s->contoPassate->id)['avere'], 'Il riporto va sul Fondo Passate Gestioni');

        $this->assertQuadra($s);
    }

    /**
     * La Rata 0 e' fatta di solo riporto: non deve lasciare nulla fra le entrate dell'anno.
     */
    public function test_la_rata_0_chiude_tutta_sul_fondo_passate_gestioni(): void
    {
        $s = $this->scenario();

        $this->emetti($s, [$s->rataZero->id]);

        $this->assertSame(array_sum(self::PREGRESSO), $this->saldoConto($s->contoCrediti->id)['dare'], 'Il pregresso nasce come credito verso i condomini');
        $this->assertSame(array_sum(self::PREGRESSO), $this->saldoConto($s->contoPassate->id)['avere'], 'La contropartita e il Fondo Passate Gestioni');
        $this->assertSame(0, $this->saldoConto($s->contoGestione->id)['avere'], 'Nessun euro di riporto fra le entrate del 2026');

        foreach ($s->quoteZero as $q) {
            $this->assertNotNull($q->fresh()->scrittura_contabile_id, 'Anche le quote di Rata 0 ricevono la scrittura');
        }

        $this->assertQuadra($s);
    }

    /**
     * ★ La regressione che questa correzione doveva evitare. Il pregresso continua a produrre
     * il suo DARE su Crediti v/Condomini: se il ramo fosse stato "riparato" senza cambiare la
     * contropartita, la Rata 0 non avrebbe prodotto scritture e l'incasso avrebbe scaricato in
     * AVERE un credito mai aperto, mandando il conto a credito.
     */
    public function test_incassato_tutto_il_conto_crediti_torna_a_zero(): void
    {
        $s = $this->scenario();

        $this->emetti($s, [$s->rataZero->id, $s->rataUno->id]);

        foreach ($s->anagrafiche as $i => $anagrafica) {
            $totale = self::PREGRESSO[$i] + self::PREVENTIVO;

            app(StoreIncassoRateAction::class)->execute([
                'pagante_id' => $anagrafica->id,
                'cassa_id' => $s->cassa->id,
                'gestione_id' => $s->gestione->id,
                'data_pagamento' => '2026-03-02',
                'importo_totale' => $totale / 100,
                'descrizione' => 'Saldo pregresso e prima rata',
                'eccedenza' => 0,
                'dettaglio_pagamenti' => [
                    ['rata_id' => $s->quoteZero[$i]->id, 'importo' => self::PREGRESSO[$i] / 100],
                    ['rata_id' => $s->quoteUno[$i]->id, 'importo' => self::PREVENTIVO / 100],
                ],
            ], $s->condominio, $s->esercizio);
        }

        $crediti = $this->saldoConto($s->contoCrediti->id);

        $this->assertSame(0, $crediti['saldo'], 'Il conto Crediti verso condomini si chiude');
        $this->assertSame(array_sum(self::PREGRESSO) + self::PREVENTIVO * 4, $crediti['dare']);
        $this->assertSame(array_sum(self::PREGRESSO) + self::PREVENTIVO * 4, $crediti['avere']);
    }

    /**
     * ★ Il punto della correzione: il 2026 deve dichiarare i 1.600,00 deliberati, non 3.400,00.
     */
    public function test_il_deliberato_dell_anno_resta_distinguibile_dal_riporto(): void
    {
        $s = $this->scenario();

        $this->emetti($s, [$s->rataZero->id, $s->rataUno->id]);

        $this->assertSame(self::PREVENTIVO * 4, $this->saldoConto($s->contoGestione->id)['avere'], 'Gestione Rate 2026 = solo il preventivo deliberato');
        $this->assertSame(array_sum(self::PREGRESSO), $this->saldoConto($s->contoPassate->id)['avere'], 'Il riporto sta su un conto suo');

        $this->assertQuadra($s);
    }

    /**
     * Il condomino che arriva a CREDITO ha `saldo_usato` negativo: la sua componente di riporto
     * riduce il debito invece di aumentarlo, e va scritta nel verso opposto. E' il caso in cui
     * una gestione ingenua dei segni produrrebbe una scrittura sbilanciata.
     */
    public function test_il_riporto_a_credito_si_scrive_nel_verso_opposto(): void
    {
        $s = $this->scenario();

        // Rossi arriva con 100,00 di credito: deve 400,00 di preventivo meno 100,00 = 300,00.
        $s->quoteUno[0]->update([
            'importo' => 30000,
            'regole_calcolo' => ['importi' => ['quota_pura_gestione' => 40000, 'saldo_usato' => -10000]],
        ]);

        $this->emetti($s, [$s->rataUno->id]);

        $passate = $this->saldoConto($s->contoPassate->id);

        $this->assertSame(10000, $passate['dare'], 'Il credito pregresso e un DARE sul Fondo Passate Gestioni');
        $this->assertSame(0, $passate['avere']);
        $this->assertSame(self::PREVENTIVO * 4, $this->saldoConto($s->contoGestione->id)['avere'], 'La gestione resta il deliberato pieno');
        $this->assertSame(30000 + self::PREVENTIVO * 3, $this->saldoConto($s->contoCrediti->id)['dare'], 'Il credito verso Rossi e gia al netto');

        $this->assertQuadra($s);
    }

    /**
     * Ogni scrittura prodotta dall'emissione deve quadrare da sola. Il
     * DoubleEntryValidator gia' lo impone dentro il controller: questa e' la controprova
     * indipendente, sull'intero giornale del condominio.
     */
    private function assertQuadra(object $s): void
    {
        $scritture = DB::table('scritture_contabili')->where('condominio_id', $s->condominio->id)->pluck('id');

        foreach ($scritture as $id) {
            $dare = (int) DB::table('righe_scritture')->where('scrittura_id', $id)->where('tipo_riga', 'dare')->sum('importo');
            $avere = (int) DB::table('righe_scritture')->where('scrittura_id', $id)->where('tipo_riga', 'avere')->sum('importo');

            $this->assertSame($dare, $avere, "La scrittura {$id} non quadra: DARE {$dare} contro AVERE {$avere}");
        }
    }
}
