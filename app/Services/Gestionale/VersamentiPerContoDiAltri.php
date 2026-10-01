<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RigaScrittura;

/**
 * I versamenti che una persona ha fatto per il debito di un'altra, nel condominio (Coda 167, 1.11.0-beta.40,
 * decisione 30.5).
 *
 * Il libro dell'estratto conto mostra solo le righe intestate alla persona (`Anagrafica::movimenti()`), e un versamento
 * per conto di altri non ne ha nessuna: chiude le quote del debitore. Qui lo si ricostruisce dal campo `riferimento`
 * delle righe che chiudono il debito, dove `StoreIncassoRateAction` registra chi ha versato. È un riquadro a parte, come
 * quello della solidarietà, e non una riga del libro: non tocca il saldo di chi ha versato, perché non era suo debito.
 * Uno storno non cancella il versamento: lo marca, come l'estratto conto marca il rimborso stornato.
 */
final class VersamentiPerContoDiAltri
{
    /**
     * @return list<array{scrittura_id:int, data:?string, protocollo:?string, per_conto_di:string, importo_cents:int, importo_formattato:string, stornato:bool}>
     */
    public function per(Condominio $condominio, Anagrafica $chiHaVersato): array
    {
        return RigaScrittura::query()
            ->where('riferimento_type', Anagrafica::class)
            ->where('riferimento_id', $chiHaVersato->id)
            ->where('tipo_riga', 'avere')
            ->whereHas('scrittura', fn ($q) => $q
                ->where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata'))
            ->with(['scrittura', 'anagrafica'])
            ->get()
            ->groupBy('scrittura_id')
            ->map(function ($righe) {
                $scrittura = $righe->first()->scrittura;
                $importo = (int) $righe->sum('importo');

                return [
                    'scrittura_id'       => (int) $scrittura->id,
                    'data'               => $scrittura->data_competenza?->format('d/m/Y'),
                    'protocollo'         => $scrittura->numero_protocollo,
                    'per_conto_di'       => $righe->map(fn ($r) => $r->anagrafica?->nome)->filter()->unique()->implode(', '),
                    'importo_cents'      => $importo,
                    'importo_formattato' => MoneyHelper::format($importo),
                    'stornato'           => $scrittura->stato === 'annullata',
                ];
            })
            ->sortByDesc('scrittura_id')
            ->values()
            ->all();
    }
}
