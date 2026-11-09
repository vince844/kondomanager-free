<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La voce di una riga nello storno nell'anno dopo (la Variante B1: l'esercizio dell'originale è chiuso, lo storno va nell'esercizio aperto) di una
 * regolazione immediata o di un incasso da terzi (decisioni 252, 253 D6 e 254 D1). Una voce di una gestione che l'esercizio dello storno non ha, o ha con il
 * legame spento, non si ricopia: con la voce di allora la riga starebbe fuori dal riepilogo dell'esercizio dello storno, e il controllo dei costi non
 * tornerebbe. La riga va senza voce, sullo stesso conto (il riepilogo la conta fra i costi non classificati), con una frase che dice la voce di allora.
 * Stesso importo, stesso lato: la partita doppia non cambia. La commissione di un pagamento ha la sua strada, sulle «Spese bancarie» di sistema
 * ({@see PagamentoFornitoreService::stornaPagamento()}).
 *
 * Quelle righe si assegnano a una voce di un'ordinaria dell'esercizio dello storno, come lo storno di una commissione (decisione 255, D1), nell'elenco
 * delle commissioni e degli storni senza voce ({@see CommissioniSenzaVoce}): {@see righeSenzaVoce()} è la regola sola che leggono l'elenco e il riepilogo.
 */
final class VoceNelloStorno
{
    /**
     * [la voce della riga dello storno, la frase per la nota]: la voce com'era, e nessuna frase, se l'esercizio dello storno ha la sua gestione accesa.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function per(?int $voceId, ?Esercizio $originale, Esercizio $storno): array
    {
        if ($voceId === null || ($originale !== null && (int) $originale->id === (int) $storno->id)) {
            return [$voceId, null];
        }
        $voce = Conto::with('pianoConto.gestione')->find($voceId);
        $gestione = $voce?->pianoConto?->gestione;
        if ($gestione !== null && VoceDellaCommissione::legataAllEsercizio((int) $gestione->id, (int) $storno->id)) {
            return [$voceId, null];
        }

        return [null, sprintf('La riga era sulla voce «%s» della gestione «%s» dell\'esercizio «%s», che l\'esercizio «%s» non ha.',
            $voce?->nome ?? '—', $gestione?->nome ?? '—', $originale?->nome ?? '—', $storno->nome)];
    }

    /**
     * Le righe di costo senza voce degli storni di una regolazione immediata o di un incasso da terzi dell'esercizio, il cui originale sta in un altro
     * esercizio: fuori dalle «Spese bancarie» di sistema (lì stanno le commissioni). Una riga dell'originale che era già senza voce c'è anche lei.
     */
    public static function righeSenzaVoce(int $esercizioId): Builder
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'))
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->join('scritture_contabili as originale', 'originale.id', '=', 'sc.scrittura_padre_id')
            ->where('sc.esercizio_id', $esercizioId)
            ->whereColumn('originale.esercizio_id', '<>', 'sc.esercizio_id')
            ->whereIn('sc.tipo_movimento', [TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA->value, TipoMovimentoContabile::STORNO_INCASSO_DIVERSO->value])
            ->whereNull('rs.voce_spesa_id')
            ->where('cc.tipo', 'costo')
            ->where(fn ($q) => $q->whereNull('cc.ruolo')->orWhere('cc.ruolo', '<>', 'spese_bancarie'));
    }
}
