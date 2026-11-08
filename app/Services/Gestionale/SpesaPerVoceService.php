<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;

/**
 * Quanto è stato REALMENTE speso su ogni voce di spesa, letto dal libro giornale.
 *
 * PERCHÉ ESISTE. Fino alla beta.30 questo numero veniva ricostruito da una query
 * su `righe_fattura`, duplicata identica in quattro controller. Quella fonte è
 * incompleta per costruzione: solo le fatture passive scrivono in `righe_fattura`.
 * Restavano quindi invisibili al budget
 *
 *   - le REGOLAZIONI IMMEDIATE (RegistraRegolazioneImmediataAction scrive solo in
 *     `righe_scritture`), e
 *   - le FATTURE PREGRESSE (FatturaPassivaService salta le righe per `$isPregresso`),
 *
 * con la conseguenza che un capitolo poteva risultare libero mentre i soldi erano
 * già usciti dalla cassa: il saldo scendeva, il cruscotto sfori no. Segnalato da un
 * amministratore in beta.29 («ho consumato 106,72 su 300 preventivati… 6,72 con
 * regolazione immediata»).
 *
 * LA FONTE CORRETTA è `righe_scritture.voce_spesa_id`, che ogni produttore di
 * scritture popola già: fatture (FatturaPassivaService), regolazioni immediate,
 * sopravvenienze da pregresse e i rispettivi storni.
 *
 * Convenzione di segno, identica a StatoPatrimonialeService: la voce di spesa è un
 * conto di COSTO, quindi saldo = Σdare − Σavere. Ne consegue gratis che
 *
 *   - una nota di credito scomputa (FatturaPassivaService inverte i versi di tutte
 *     le righe della scrittura), e
 *   - uno storno azzera (StornaRegolazioneImmediataAction ricopia `voce_spesa_id`
 *     sulla riga inversa, così il budget del capitolo torna libero).
 *
 * Le spese ad personam sono escluse senza bisogno di un filtro esplicito: nascono
 * con `voce_spesa_id = null` e `immobile_id` valorizzato (art. 63 disp. att. c.c.).
 *
 * @see docs/layer-reporting-consuntivo.md §7 — «lo scostamento e il consuntivo
 *      ragionano per competenza; i cruscotti di tesoreria per cassa». Questo
 *      servizio sta sul primo piano: una fattura registrata e non ancora pagata è
 *      già spesa, coerentemente con i Costi di StatoPatrimonialeService.
 */
class SpesaPerVoceService
{
    /** Quante righe al massimo restituisce il drill-down di una singola voce. */
    public const LIMITE_MOVIMENTI = 200;


    /**
     * Mappa `voce_spesa_id => centesimi spesi` per l'esercizio indicato.
     *
     * Sostituisce, con lo stesso contratto, la vecchia `$fatturatoMap`.
     *
     * @param  array<int>|null  $vociIds  restringe il calcolo a queste voci (nessun filtro se null)
     * @param  int|null  $escludiFatturaId  fattura in corso di modifica: le sue scritture non
     *                                      devono pesare sul budget, altrimenti in edit la
     *                                      fattura conta sé stessa e il form segnala un falso
     *                                      sforamento sommando di nuovo l'importo digitato
     * @return array<int,int>
     */
    public function perEsercizio(Esercizio $esercizio, ?array $vociIds = null, ?int $escludiFatturaId = null): array
    {
        if ($vociIds !== null && $vociIds === []) {
            return [];
        }

        $righe = $this->queryBase($esercizio, $escludiFatturaId)
            ->when($vociIds !== null, fn ($q) => $q->whereIn('rs.voce_spesa_id', $vociIds))
            ->groupBy('rs.voce_spesa_id')
            ->select([
                'rs.voce_spesa_id',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"),
            ])
            ->get();

        $mappa = [];

        foreach ($righe as $riga) {
            $mappa[(int) $riga->voce_spesa_id] = (int) $riga->dare - (int) $riga->avere;
        }

        return $mappa;
    }

    /**
     * Lo speso per voce diviso in due: l'**incassato** degli incassi da terzi (positivo) e lo speso di tutto il resto.
     *
     * L'incasso da terzi (`RegistraIncassoDaTerziAction`) scrive AVERE sul mastro della voce di entrata, con la voce: dare meno avere dà uno
     * speso negativo (−€ 240,00 per l'antenna), che è giusto per i costi a giornale (l'entrata li riduce) ma non è uno speso. Le pagine che
     * mostrano lo speso per voce (il piano dei conti, il cruscotto, i capitoli del piano rate) leggono da qui: l'incassato è il netto delle sole
     * scritture `incasso_diverso` e dei loro storni, e la voce dice «incassato € 240,00»; **tutto il resto della voce resta speso, come prima**.
     * Così i dati scritti prima su una voce di entrata (una fattura registrata quando la registrazione le accettava, una regolazione scritta a
     * rovescio, una voce diventata un'entrata dopo) si leggono come si leggevano: nessuna lettura nuova per i dati vecchi.
     *
     * @param  array<int,int>  $mappa  il risultato di `perEsercizio()` per lo stesso esercizio
     * @return array{spese: array<int,int>, incassato: array<int,int>}
     */
    public function perTipo(array $mappa, Esercizio $esercizio): array
    {
        if ($mappa === []) {
            return ['spese' => [], 'incassato' => []];
        }

        $incassato = $this->incassatoPerEsercizio($esercizio, array_map('intval', array_keys($mappa)));
        $spese = [];

        foreach ($mappa as $voceId => $speso) {
            // Lo speso comprende l'incasso con il segno meno: tolto quello, resta lo speso di prima.
            $spese[$voceId] = (int) $speso + (int) ($incassato[$voceId] ?? 0);
        }

        return ['spese' => $spese, 'incassato' => $incassato];
    }

    /**
     * L'incassato per voce: il netto, positivo, delle righe con la voce delle scritture `incasso_diverso` e `storno_incasso_diverso`
     * dell'esercizio, con gli stessi filtri dello speso (`queryBase`).
     *
     * @param  array<int>|null  $vociIds
     * @return array<int,int>
     */
    public function incassatoPerEsercizio(Esercizio $esercizio, ?array $vociIds = null): array
    {
        if ($vociIds !== null && $vociIds === []) {
            return [];
        }

        $righe = $this->queryBase($esercizio)
            ->whereIn('sc.tipo_movimento', [TipoMovimentoContabile::INCASSO_DIVERSO->value, TipoMovimentoContabile::STORNO_INCASSO_DIVERSO->value])
            ->when($vociIds !== null, fn ($q) => $q->whereIn('rs.voce_spesa_id', $vociIds))
            ->groupBy('rs.voce_spesa_id')
            ->select([
                'rs.voce_spesa_id',
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"),
                DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
            ])
            ->get();

        $mappa = [];
        foreach ($righe as $riga) {
            $mappa[(int) $riga->voce_spesa_id] = (int) $riga->avere - (int) $riga->dare;
        }

        return $mappa;
    }

    /**
     * I singoli movimenti che compongono lo speso di una voce — la risposta alla
     * domanda «per colpa di cosa?», dopo che il totale ha detto «quanto».
     *
     * Stessi filtri di `perEsercizio()`: quello che si somma là si elenca qui, così i
     * due numeri non possono divergere. Gli storni compaiono come righe negative
     * invece di essere nascosti: sono parte della storia che spiega il totale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movimentiPerVoce(Esercizio $esercizio, int $voceId, int $limite = self::LIMITE_MOVIMENTI): array
    {
        $righe = $this->queryBase($esercizio)
            ->where('rs.voce_spesa_id', $voceId)
            ->leftJoin('anagrafiche as an', 'an.id', '=', 'rs.anagrafica_id')
            ->orderByDesc('sc.data_competenza')
            ->orderByDesc('sc.id')
            // Una voce con centinaia di movimenti renderebbe la modale lenta e
            // illeggibile. Il taglio è dichiarato in UI, mai silenzioso: chi ha
            // davvero bisogno dell'elenco completo passa dal Libro Giornale.
            ->limit($limite)
            ->select([
                'rs.id as riga_id',
                'sc.id as scrittura_id',
                'sc.data_competenza',
                'sc.causale',
                'sc.numero_protocollo',
                'sc.tipo_movimento',
                'sc.stato',
                'rs.tipo_riga',
                'rs.importo',
                'rs.note',
                'an.nome as controparte',
            ])
            ->get();

        return $righe->map(fn ($r) => [
            'id' => (int) $r->riga_id,
            'scrittura_id' => (int) $r->scrittura_id,
            'data' => $r->data_competenza,
            'causale' => $r->causale,
            'descrizione' => $r->note,
            'protocollo' => $r->numero_protocollo,
            'tipo_movimento' => $r->tipo_movimento,
            'tipo_movimento_label' => TipoMovimentoContabile::tryFrom((string) $r->tipo_movimento)?->label()
                ?? $r->tipo_movimento,
            'stato' => $r->stato,
            'controparte' => $r->controparte,
            // Segno coerente col totale: DARE somma, AVERE (storni, note di credito)
            // sottrae. La somma di questa colonna è esattamente lo speso della voce.
            'importo' => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo,
        ])->all();
    }

    /**
     * Il predicato delle scritture legate a una fattura contestata (con qualunque legame: la competenza o il pagamento), da usare dentro un
     * `whereExists` / `whereNotExists` su `sc`. Il consuntivo per voce le esclude; il riepilogo per competenza le mostra in una riga sua, con lo
     * stesso predicato, così il consuntivo e la riga delle contestate sommano ai costi.
     */
    public static function legataAContestata($sub)
    {
        return $sub->select(DB::raw(1))
            ->from('fattura_scrittura as fs')
            ->join('fatture_passive as fp', 'fp.id', '=', 'fs.fattura_passiva_id')
            ->whereColumn('fs.scrittura_contabile_id', 'sc.id')
            ->where('fp.stato_approvazione', 'contestata');
    }

    /**
     * Il consuntivo di una gestione dal suo inizio fino all'esercizio che comincia il giorno dato: le spese delle sue voci in ogni esercizio a cui è
     * agganciata, con la regola del consuntivo per voce. Per una gestione straordinaria su più esercizi è il cumulato dell'opera, che il riepilogo e
     * la situazione patrimoniale mostrano accanto alla parte dell'esercizio.
     *
     * @return array{dal:?string, consuntivo:int}
     */
    public function cumulatoDellaGestione(int $gestioneId, string $dal): array
    {
        $esercizi = Esercizio::join('esercizio_gestione as eg', 'eg.esercizio_id', '=', 'esercizi.id')->where('eg.gestione_id', $gestioneId)
            ->where('esercizi.data_inizio', '<', \Carbon\CarbonImmutable::parse($dal)->addDay()->format('Y-m-d'))->orderBy('esercizi.data_inizio')->get(['esercizi.*']);
        $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->where('pc.gestione_id', $gestioneId)->where('conti.tipo', 'spesa')->pluck('conti.id')->all();
        $totale = 0;
        foreach ($esercizi as $e) {
            $totale += (int) array_sum($this->perTipo($this->perEsercizio($e, $voci), $e)['spese']);
        }

        return ['dal' => $esercizi->first()?->data_inizio?->format('Y-m-d'), 'consuntivo' => $totale];
    }

    /**
     * Filtri condivisi fra il totale e il dettaglio: se divergessero, la modale
     * mostrerebbe movimenti che non spiegano il numero da cui è stata aperta.
     */
    private function queryBase(Esercizio $esercizio, ?int $escludiFatturaId = null)
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                    ->whereNull('sc.deleted_at');
            })
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereNotNull('rs.voce_spesa_id')
            // Una fattura contestata resta a giornale (il costo di competenza c'è),
            // ma non deve pesare sul budget: è la regola già applicata dalle query
            // che questo servizio sostituisce, qui preservata invariata.
            ->whereNotExists(fn ($sub) => self::legataAContestata($sub))
            ->when($escludiFatturaId !== null, fn ($q) => $q->whereNotExists(function ($sub) use ($escludiFatturaId) {
                $sub->select(DB::raw(1))
                    ->from('fattura_scrittura as fx')
                    ->whereColumn('fx.scrittura_contabile_id', 'sc.id')
                    ->where('fx.fattura_passiva_id', $escludiFatturaId);
            }));
    }
}
