<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;

/**
 * Stato Patrimoniale e verifica della quadratura fondamentale.
 *
 *      ATTIVITÀ = PASSIVITÀ + PATRIMONIO NETTO
 *
 * dove il patrimonio netto, in assenza di conti di netto dedicati nel piano dei
 * conti, coincide con il risultato d'esercizio (Ricavi − Costi).
 *
 * PERCHÉ SERVE. Il `DoubleEntryValidator` verifica che ogni SINGOLA scrittura
 * abbia DARE = AVERE: condizione necessaria ma NON sufficiente. Un saldo entrato
 * nel sistema senza contropartita (es. il saldo di apertura di una cassa prima
 * della beta.25) non viola nessuna singola scrittura, eppure lascia l'attivo
 * scoperto. Solo il controllo A = P + N lo intercetta.
 *
 * È quindi tanto un report quanto un ORACOLO: qualunque futura falla che
 * introduca valore senza contropartita fa emergere uno sbilancio qui, invece di
 * restare invisibile fino alla segnalazione di un amministratore.
 *
 * Convenzioni di segno (coerenti con SaldoCassaService):
 *   attivo, costo   → DARE aumenta  → saldo = Σdare − Σavere
 *   passivo, ricavo → AVERE aumenta → saldo = Σavere − Σdare
 *
 * Le scritture annullate (soft-deleted) sono sempre escluse.
 *
 * DUE LETTURE, E SOLO UNA È UNA FOTOGRAFIA (D16, docs/registri_contabili.md). Con un
 * `$esercizio` il motore somma le sole scritture di quell'esercizio: è il FLUSSO dell'anno, e dal
 * secondo esercizio in poi non dice quanto c'è sui conti — non esiste chiusura né riapertura
 * (Coda ㉜), quindi liquidità, crediti e debiti ereditati non ci sono. Con `$allaData` somma
 * tutti i movimenti con `data_competenza ≤ data`, su tutti gli esercizi: è lo STOCK a quella
 * data, cioè lo stato patrimoniale nel senso proprio. Le due letture quadrano entrambe — R6 è
 * meccanica, dare = avere per scrittura — ma solo la seconda è vera come fotografia. La pagina
 * Stato patrimoniale usa la seconda; il riquadro del Libro Giornale continua a usare la prima,
 * invariata byte per byte.
 *
 * ⚠️ Chi chiama con entrambi chiede una cosa ambigua, e riceve un'eccezione invece di un numero.
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md
 */
class StatoPatrimonialeService
{
    /**
     * @return array{
     *     attivo: array{totale:int, voci:array},
     *     passivo: array{totale:int, voci:array},
     *     costi:int, ricavi:int, risultato_esercizio:int,
     *     liquidita_non_contabilizzata:int,
     *     sbilancio:int, quadra:bool
     * }
     */
    public function calcola(Condominio $condominio, ?Esercizio $esercizio = null, ?string $allaData = null, bool $escludiAperture = false): array
    {
        if ($esercizio !== null && $allaData !== null) {
            throw new \InvalidArgumentException(
                'Stato patrimoniale: o il flusso di un esercizio o la fotografia a una data, non entrambi.'
            );
        }

        $saldi = $this->saldiPerConto($condominio, $esercizio, $allaData, $escludiAperture);

        $attivo  = $saldi->where('tipo', 'attivo');
        $passivo = $saldi->where('tipo', 'passivo');

        $totaleAttivo  = (int) $attivo->sum('saldo');
        $totalePassivo = (int) $passivo->sum('saldo');
        $costi         = (int) $saldi->where('tipo', 'costo')->sum('saldo');
        $ricavi        = (int) $saldi->where('tipo', 'ricavo')->sum('saldo');

        $risultato = $ricavi - $costi;

        // Saldi di apertura delle casse non ancora portati a giornale (installazioni
        // aggiornate ma con la migrazione di backfill non ancora eseguita). Sono
        // liquidità REALE priva di contropartita contabile: va esposta, non nascosta,
        // ed è esattamente ciò che produce lo sbilancio.
        $liquiditaNonContabilizzata = (int) DB::table('casse')
            ->where('condominio_id', $condominio->id)
            ->sum('saldo_iniziale');

        $totaleAttivo += $liquiditaNonContabilizzata;

        $sbilancio = $totaleAttivo - $totalePassivo - $risultato;

        return [
            'attivo' => [
                'totale' => $totaleAttivo,
                'voci'   => $attivo->values()->all(),
            ],
            'passivo' => [
                'totale' => $totalePassivo,
                'voci'   => $passivo->values()->all(),
            ],
            'costi'                        => $costi,
            'ricavi'                       => $ricavi,
            // Voce per voce anche per costi e ricavi: la pagina Stato patrimoniale deve poter dire
            // QUALI costi stanno fra attività e passività, non solo quanti («e quali sono questi
            // costi?» — Vincenzo, a video). Chiavi nuove, gli scalari sopra restano com'erano.
            'costi_voci'                   => $saldi->where('tipo', 'costo')->values()->all(),
            'ricavi_voci'                  => $saldi->where('tipo', 'ricavo')->values()->all(),
            'risultato_esercizio'          => $risultato,
            'liquidita_non_contabilizzata' => $liquiditaNonContabilizzata,
            'sbilancio'                    => $sbilancio,
            'quadra'                       => $sbilancio === 0,
        ];
    }

    /**
     * La divisione per gestione di ciò che si divide, con i perimetri della pagina, così la somma delle gestioni è il numero della pagina:
     * - **i costi dell'esercizio** (le righe dei conti di costo delle scritture dell'esercizio, senza le aperture: i flussi), per la gestione della
     *   voce (la voce → il suo piano dei conti → la gestione); una riga senza voce sta sotto la chiave 0;
     * - **le quote emesse nell'esercizio** («Gestione rate», stessi flussi), per la gestione della scrittura (l'emissione la prende dal piano);
     * - **i crediti verso i condòmini alla data** (tutti gli esercizi, come la fotografia), per la gestione del piano della rata e, per una riga senza
     *   rata, per quella della scrittura.
     * Una scrittura senza gestione sta sotto la chiave 0. La liquidità e i fondi non si dividono: sono del condominio.
     *
     * @return array{costi: array<int,int>, quote_emesse: array<int,int>, crediti: array<int,int>}
     */
    public function perGestione(Condominio $condominio, Esercizio $esercizio, string $allaData): array
    {
        $base = fn () => DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at'))
            ->join('conti_contabili as cc', fn ($j) => $j->on('cc.id', '=', 'rs.conto_contabile_id')->whereNull('cc.deleted_at'))
            ->where('cc.condominio_id', $condominio->id);
        $dellEsercizio = fn ($q) => $q->where('sc.esercizio_id', $esercizio->id)->where('sc.tipo_movimento', '<>', 'apertura');
        // La chiave 0 raccoglie la riga senza gestione (una riga di costo senza voce, una scrittura senza gestione).
        $somma = fn ($q) => $q->get()->groupBy(fn ($r) => (int) $r->gestione_id)->map(fn ($g) => (int) $g->sum('saldo'))->filter(fn ($v) => $v !== 0)->all();

        $costi = $dellEsercizio($base())->where('cc.tipo', 'costo')
            ->leftJoin('conti as v', 'v.id', '=', 'rs.voce_spesa_id')
            ->leftJoin('piani_conti as pc', 'pc.id', '=', 'v.piano_conto_id')
            ->groupBy('pc.gestione_id')->select('pc.gestione_id')
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as saldo");
        $quote = $dellEsercizio($base())->where('cc.ruolo', 'gestione_rate')
            ->groupBy('sc.gestione_id')->select('sc.gestione_id')
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END) as saldo");
        $crediti = $base()->where('cc.ruolo', 'crediti_condomini')
            ->where('sc.data_competenza', '<', \Carbon\Carbon::parse($allaData)->addDay()->format('Y-m-d'))
            ->leftJoin('rate as r', 'r.id', '=', 'rs.rata_id')
            ->leftJoin('piani_rate as pr', 'pr.id', '=', 'r.piano_rate_id')
            ->groupByRaw('COALESCE(pr.gestione_id, sc.gestione_id)')
            ->selectRaw('COALESCE(pr.gestione_id, sc.gestione_id) as gestione_id')
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as saldo");

        return ['costi' => $somma($costi), 'quote_emesse' => $somma($quote), 'crediti' => $somma($crediti)];
    }

    /** Lo Stato Patrimoniale chiude? Scorciatoia per test e controlli di integrità. */
    public function quadra(Condominio $condominio, ?Esercizio $esercizio = null): bool
    {
        return $this->calcola($condominio, $esercizio)['quadra'];
    }

    /**
     * Saldo di ogni conto contabile, già orientato secondo la natura del conto.
     */
    /**
     * @param  bool  $escludiAperture  con `$esercizio`: i FLUSSI dell'esercizio senza le scritture di
     *                                 apertura (tipo `apertura`), che sono disponibilità iniziale e
     *                                 non movimento — è il perimetro del riepilogo finanziario e del
     *                                 risultato di gestione (D19). Irrilevante con `$allaData`.
     */
    private function saldiPerConto(Condominio $condominio, ?Esercizio $esercizio, ?string $allaData = null, bool $escludiAperture = false)
    {
        $query = DB::table('conti_contabili as cc')
            ->leftJoin('righe_scritture as rs', 'rs.conto_contabile_id', '=', 'cc.id')
            ->leftJoin('scritture_contabili as sc', function ($join) use ($esercizio, $allaData, $escludiAperture) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                     ->whereNull('sc.deleted_at');

                if ($esercizio) {
                    $join->where('sc.esercizio_id', '=', $esercizio->id);
                    if ($escludiAperture) {
                        $join->where('sc.tipo_movimento', '<>', 'apertura');
                    }
                }

                // D16: la fotografia taglia per data effettiva del movimento (D6), su tutti gli
                // esercizi. Il confine per esercizio_id e quello per data possono divergere —
                // una scrittura datata fuori dal suo esercizio — e qui vince la data, perché
                // uno stato patrimoniale è «quanto c'era il giorno X», non «cosa ha scritto
                // l'esercizio X». Il filtro sta nel JOIN e non nel WHERE per la stessa ragione
                // di quello per esercizio: un conto senza righe entro la data deve restare a
                // zero, non sparire dal LEFT JOIN.
                // ⚠️ `< giorno dopo`, non `<= data`: su MySQL `data_competenza` è DATE e il confronto
                // con 'Y-m-d' è inclusivo; su SQLite (i test) la colonna è testo 'Y-m-d 00:00:00' e
                // '2025-12-31 00:00:00' <= '2025-12-31' è FALSO — la suite non poteva vedere un
                // movimento datato il giorno stesso del taglio. Con «minore del giorno dopo» le due
                // basi rispondono uguale. Trovato dalla revisione della beta.25.
                if ($allaData !== null) {
                    $join->where('sc.data_competenza', '<', \Carbon\Carbon::parse($allaData)->addDay()->format('Y-m-d'));
                }
            })
            ->where('cc.condominio_id', $condominio->id)
            ->whereNull('cc.deleted_at')
            ->groupBy('cc.id', 'cc.codice', 'cc.nome', 'cc.tipo', 'cc.categoria', 'cc.ruolo', 'cc.parent_id', 'cc.livello')
            ->select([
                'cc.id',
                'cc.codice',
                'cc.nome',
                'cc.tipo',
                'cc.categoria',
                // Per la pagina (D18): le etichette si prendono dal RUOLO, non dalla categoria —
                // «Gestione Rate» ha categoria `fondi` a database e non è un fondo. Gerarchia
                // per la presentazione ad albero; nessuno dei tre entra in una somma.
                'cc.ruolo',
                'cc.parent_id',
                'cc.livello',
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"),
            ]);

        return collect($query->get())->map(function ($riga) {
            $dare  = (int) $riga->dare;
            $avere = (int) $riga->avere;

            // Conti di natura DARE (attivo, costo) vs natura AVERE (passivo, ricavo).
            $saldo = in_array($riga->tipo, ['attivo', 'costo'], true)
                ? $dare - $avere
                : $avere - $dare;

            return [
                'id'        => $riga->id,
                'codice'    => $riga->codice,
                'nome'      => $riga->nome,
                'tipo'      => $riga->tipo,
                'categoria' => $riga->categoria,
                'ruolo'     => $riga->ruolo,
                'parent_id' => $riga->parent_id,
                'livello'   => (int) $riga->livello,
                'dare'      => $dare,
                'avere'     => $avere,
                'saldo'     => $saldo,
            ];
        })->filter(fn ($c) => $c['saldo'] !== 0 || $c['dare'] !== 0 || $c['avere'] !== 0);
    }
}
