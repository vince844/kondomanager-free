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
    public function calcola(Condominio $condominio, ?Esercizio $esercizio = null): array
    {
        $saldi = $this->saldiPerConto($condominio, $esercizio);

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
            'risultato_esercizio'          => $risultato,
            'liquidita_non_contabilizzata' => $liquiditaNonContabilizzata,
            'sbilancio'                    => $sbilancio,
            'quadra'                       => $sbilancio === 0,
        ];
    }

    /** Lo Stato Patrimoniale chiude? Scorciatoia per test e controlli di integrità. */
    public function quadra(Condominio $condominio, ?Esercizio $esercizio = null): bool
    {
        return $this->calcola($condominio, $esercizio)['quadra'];
    }

    /**
     * Saldo di ogni conto contabile, già orientato secondo la natura del conto.
     */
    private function saldiPerConto(Condominio $condominio, ?Esercizio $esercizio)
    {
        $query = DB::table('conti_contabili as cc')
            ->leftJoin('righe_scritture as rs', 'rs.conto_contabile_id', '=', 'cc.id')
            ->leftJoin('scritture_contabili as sc', function ($join) use ($esercizio) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                     ->whereNull('sc.deleted_at');

                if ($esercizio) {
                    $join->where('sc.esercizio_id', '=', $esercizio->id);
                }
            })
            ->where('cc.condominio_id', $condominio->id)
            ->whereNull('cc.deleted_at')
            ->groupBy('cc.id', 'cc.codice', 'cc.nome', 'cc.tipo', 'cc.categoria')
            ->select([
                'cc.id',
                'cc.codice',
                'cc.nome',
                'cc.tipo',
                'cc.categoria',
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
                'dare'      => $dare,
                'avere'     => $avere,
                'saldo'     => $saldo,
            ];
        })->filter(fn ($c) => $c['saldo'] !== 0 || $c['dare'] !== 0 || $c['avere'] !== 0);
    }
}
