<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fonte unica del saldo disponibile di una cassa — banca, contanti o fondo.
 *
 * Convenzione UNICA (attivo): saldo = saldo_iniziale + Σ DARE − Σ AVERE.
 * Vale anche per i fondi: sono partizioni dell'unico conto corrente reale
 * (conti figli del mastro 1010, tipo attivo/liquidità), non passività.
 * Un accantonamento banca→fondo è AVERE banca / DARE fondo: la banca scende,
 * il fondo sale, la liquidità complessiva non cambia — nessun denaro reale
 * si è mosso.
 *
 * Storia: prima della beta.19 tre punti del codice leggevano i fondi in
 * convenzione passiva (avere − dare) mentre modello e Treasury li leggevano
 * da attivo. Questa classe chiude la contraddizione: chiunque abbia bisogno
 * di un saldo cassa passa da qui.
 */
class SaldoCassaService
{
    /**
     * Saldo disponibile in centesimi. Esclude le righe di scritture soft-deleted
     * (stesso perimetro di PagamentoFornitoreService::saldoCorrente).
     */
    public function saldoDisponibile(Cassa $cassa): int
    {
        if (! $cassa->conto_contabile_id) {
            return (int) ($cassa->saldo_iniziale ?? 0);
        }

        $movimenti = DB::table('righe_scritture')
            ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->where('righe_scritture.conto_contabile_id', $cassa->conto_contabile_id)
            ->whereNull('scritture_contabili.deleted_at')
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as dare")
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as avere")
            ->first();

        return (int) (($cassa->saldo_iniziale ?? 0) + ($movimenti->dare ?? 0) - ($movimenti->avere ?? 0));
    }

    /**
     * Saldi di tutte le casse attive del condominio in una sola query.
     *
     * @return Collection<int, array{id:int,nome:string,tipo:string,conto_contabile_id:int,saldo_cents:int,sottotipo_fondo:?string,is_override_assemblea:bool,is_utilizzabile_per_imprevisti:bool}>
     */
    public function saldiPerCondominio(Condominio $condominio): Collection
    {
        $casse = Cassa::where('condominio_id', $condominio->id)
            ->where('attiva', true)
            ->whereNotNull('conto_contabile_id')
            ->get();

        if ($casse->isEmpty()) {
            return collect();
        }

        $movimenti = DB::table('righe_scritture')
            ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->whereIn('righe_scritture.conto_contabile_id', $casse->pluck('conto_contabile_id'))
            ->whereNull('scritture_contabili.deleted_at')
            ->groupBy('righe_scritture.conto_contabile_id')
            ->selectRaw('righe_scritture.conto_contabile_id')
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as dare")
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as avere")
            ->get()
            ->keyBy('conto_contabile_id');

        return $casse->map(function (Cassa $cassa) use ($movimenti) {
            $m = $movimenti->get($cassa->conto_contabile_id);

            return [
                'id' => $cassa->id,
                'nome' => $cassa->nome,
                'tipo' => $cassa->tipo,
                'conto_contabile_id' => $cassa->conto_contabile_id,
                'saldo_cents' => (int) (($cassa->saldo_iniziale ?? 0) + ($m->dare ?? 0) - ($m->avere ?? 0)),
                'sottotipo_fondo' => $cassa->sottotipo_fondo,
                'is_override_assemblea' => (bool) $cassa->is_override_assemblea,
                'is_utilizzabile_per_imprevisti' => (bool) $cassa->is_utilizzabile_per_imprevisti,
            ];
        })->values();
    }
}
