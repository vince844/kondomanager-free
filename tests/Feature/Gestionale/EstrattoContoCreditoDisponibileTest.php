<?php

namespace Tests\Feature\Gestionale;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Gestionale\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Perché l'Estratto Conto mostra «saldo finale a credito 1.922,13 €» e, due card più in là,
 * «credito disponibile 38,00 €».
 *
 * Segnalazione dal forum: l'amministratore non chiede «è sbagliato?», chiede «cos'è?». I due
 * numeri rispondono a due domande diverse e la pagina non lo dice:
 *
 * - il SALDO FINALE è il saldo contabile della timeline: saldo iniziale + addebiti − versamenti,
 *   dove gli addebiti sono le sole rate EMESSE (una rata in bozza non ha scrittura, quindi non
 *   produce nessun DARE);
 * - il CREDITO DISPONIBILE (CreditoService) somma quota per quota solo ciò che NON è ancora
 *   assegnato a nessuna rata: l'eccedenza incassata, o la parte non consumata di un importo
 *   negativo.
 *
 * Il divario fra i due è quindi denaro già versato e già appoggiato su quote di rate non ancora
 * emesse. Non è un errore e non è credito perduto: si riassorbe da sé man mano che quelle rate
 * vengono emesse. Questi test fissano il meccanismo, così la spiegazione data all'amministratore
 * resta vera anche fra sei mesi.
 */
class EstrattoContoCreditoDisponibileTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): object
    {
        $condominio = Condominio::create([
            'nome' => 'Condominio Estratto Conto',
            'uuid' => (string) Str::uuid(),
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Milano',
            'cap' => '20100',
            'provincia' => 'MI',
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
            'nome' => 'ORDINARIA',
            'tipo' => 'ordinaria',
            'data_inizio' => '2026-01-01',
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
            'nome' => 'Raffaele',
            'cognome' => 'Grillo',
            'email' => 'grillo@test.it',
            'indirizzo' => 'Via Verdi 10',
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'codice_fiscale' => 'GRLRFL80A01H501U',
        ]);

        $immobile->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario',
            'quota' => 100,
            'attivo' => true,
            'data_inizio' => '2026-01-01',
        ]);

        $contoCrediti = ContoContabile::create([
            'condominio_id' => $condominio->id,
            'ruolo' => 'crediti_condomini',
            'codice' => 'CRED-COND',
            'nome' => 'Crediti verso Condomini',
            'tipo' => 'attivo',
            'categoria' => 'crediti',
        ]);

        $piano = PianoRate::create([
            'condominio_id' => $condominio->id,
            'gestione_id' => $gestione->id,
            'nome' => 'ORDINARIO 2026',
            'numero_rate' => 2,
        ]);

        return (object) compact(
            'condominio', 'esercizio', 'gestione', 'immobile', 'anagrafica', 'contoCrediti', 'piano'
        );
    }

    /** Rata + quota. `$statoRata` decide se esiste anche la scrittura di emissione. */
    private function rata(object $s, int $numero, int $importoCents, int $pagatoCents, string $statoRata): Rata
    {
        $rata = Rata::create([
            'piano_rate_id' => $s->piano->id,
            'numero_rata' => $numero,
            'data_scadenza' => sprintf('2026-%02d-05', $numero),
            'importo_totale' => $importoCents,
            'stato' => $statoRata,
        ]);

        RataQuote::create([
            'rata_id' => $rata->id,
            'anagrafica_id' => $s->anagrafica->id,
            'immobile_id' => $s->immobile->id,
            'importo' => $importoCents,
            'importo_pagato' => $pagatoCents,
            'stato' => $pagatoCents >= $importoCents ? 'pagata' : 'da_pagare',
            'data_scadenza' => sprintf('2026-%02d-05', $numero),
        ]);

        // Solo l'EMISSIONE genera la scrittura in DARE: è questa, e non la rata in sé,
        // a fare da addebito nell'Estratto Conto.
        if ($statoRata === 'emessa') {
            $this->scrittura($s, 'emissione_rata', 'dare', $importoCents, $rata->id);
        }

        return $rata;
    }

    private function scrittura(object $s, string $tipoMovimento, string $tipoRiga, int $importoCents, ?int $rataId): void
    {
        $scrittura = ScritturaContabile::create([
            'condominio_id' => $s->condominio->id,
            'esercizio_id' => $s->esercizio->id,
            'gestione_id' => $s->gestione->id,
            'data_registrazione' => '2026-05-26',
            'data_competenza' => '2026-05-26',
            'causale' => $tipoMovimento,
            'tipo_movimento' => $tipoMovimento,
            'stato' => 'registrata',
        ]);

        RigaScrittura::create([
            'scrittura_id' => $scrittura->id,
            'conto_contabile_id' => $s->contoCrediti->id,
            'tipo_riga' => $tipoRiga,
            'importo' => $importoCents,
            'immobile_id' => $s->immobile->id,
            'anagrafica_id' => $s->anagrafica->id,
            'rata_id' => $rataId,
        ]);
    }

    /** Le statistiche esattamente come le calcola la pagina (show() e print() usano questo metodo). */
    private function stats(object $s): array
    {
        $metodo = new ReflectionMethod(EstrattoContoAnagraficaController::class, 'buildLedger');
        $metodo->setAccessible(true);

        [, $stats] = $metodo->invoke(app(EstrattoContoAnagraficaController::class), $s->condominio, $s->esercizio, $s->anagrafica);

        return $stats;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function il_divario_fra_saldo_finale_e_credito_disponibile_e_l_anticipo_su_rate_non_emesse()
    {
        $s = $this->scenario();

        // Rata 1 EMESSA da 100,00, incassati 138,00 → 38,00 di eccedenza non assegnata.
        $rata1 = $this->rata($s, 1, 10000, 13800, 'emessa');
        $this->scrittura($s, 'incasso_rata', 'avere', 13800, $rata1->id);

        // Rata 2 ancora IN BOZZA da 500,00, già pagata per intero: è un anticipo.
        // Nessuna scrittura di emissione, quindi nessun addebito nella timeline.
        $rata2 = $this->rata($s, 2, 50000, 50000, 'bozza');
        $this->scrittura($s, 'incasso_rata', 'avere', 50000, $rata2->id);

        $stats = $this->stats($s);
        $credito = app(CreditoService::class)->perAnagrafica($s->condominio->id, $s->anagrafica->id);

        // Addebiti: solo la rata emessa, benché a database esistano 600,00 € di quote.
        $this->assertSame(0, (int) $stats['saldo_iniziale_raw']);
        $this->assertSame(MoneyHelper::format(10000), $stats['totale_addebiti']);
        $this->assertSame(MoneyHelper::format(63800), $stats['totale_versamenti']);
        $this->assertSame(-53800, (int) $stats['saldo_raw'], 'saldo finale = 0 + 100,00 − 138,00 − 500,00');

        // Il credito SPENDIBILE è solo l'eccedenza: l'anticipo è già assegnato alla sua quota.
        $this->assertSame(3800, $credito['totale_cents']);

        // Ed è qui la risposta all'amministratore: il divario non è credito perduto.
        $divario = abs((int) $stats['saldo_raw']) - $credito['totale_cents'];
        $this->assertSame(50000, $divario, 'il divario è esattamente l\'anticipo sulla rata non ancora emessa');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function emettere_la_rata_riassorbe_il_divario_senza_compensare_nulla()
    {
        $s = $this->scenario();

        $rata1 = $this->rata($s, 1, 10000, 13800, 'emessa');
        $this->scrittura($s, 'incasso_rata', 'avere', 13800, $rata1->id);

        $rata2 = $this->rata($s, 2, 50000, 50000, 'bozza');
        $this->scrittura($s, 'incasso_rata', 'avere', 50000, $rata2->id);

        // L'amministratore emette la rata 2: nasce l'addebito che finora mancava.
        $rata2->update(['stato' => 'emessa']);
        $this->scrittura($s, 'emissione_rata', 'dare', 50000, $rata2->id);

        $stats = $this->stats($s);
        $credito = app(CreditoService::class)->perAnagrafica($s->condominio->id, $s->anagrafica->id);

        // Il saldo scende da solo a −38,00: ora i due numeri coincidono.
        $this->assertSame(-3800, (int) $stats['saldo_raw']);
        $this->assertSame(3800, $credito['totale_cents']);
        $this->assertSame(0, abs((int) $stats['saldo_raw']) - $credito['totale_cents']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function una_rata_in_bozza_non_pagata_non_sposta_nessuno_dei_due_numeri()
    {
        $s = $this->scenario();

        $rata1 = $this->rata($s, 1, 10000, 13800, 'emessa');
        $this->scrittura($s, 'incasso_rata', 'avere', 13800, $rata1->id);

        // Rata in bozza e non pagata: né addebito né credito. Serve a escludere che il
        // divario dipenda dall'esistenza della bozza invece che dal denaro versato su di essa.
        $this->rata($s, 2, 50000, 0, 'bozza');

        $stats = $this->stats($s);
        $credito = app(CreditoService::class)->perAnagrafica($s->condominio->id, $s->anagrafica->id);

        $this->assertSame(-3800, (int) $stats['saldo_raw']);
        $this->assertSame(3800, $credito['totale_cents']);
    }
}
