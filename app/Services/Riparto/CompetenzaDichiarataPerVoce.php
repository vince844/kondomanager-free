<?php

namespace App\Services\Riparto;

use Illuminate\Support\Facades\DB;

/**
 * La competenza che una voce ha **già** dichiarata in un piano rate della sua gestione (`competenze_capitolo`,
 * decisione 20): serve a chi apre un piano nuovo sulla stessa voce — la rata integrativa dopo uno sforo — e a chi
 * registra una fattura su quella voce.
 *
 * - Sull'integrativa il default della scheda «Competenza delle voci» era il periodo della gestione: il riscaldamento
 *   dichiarato sulla stagione nel piano madre tornava sull'anno intero nel piano dei 300 euro di sforo, e i giorni
 *   di venditore e acquirente cambiavano fra i due piani senza che nessuno lo dicesse. Qui si **propone** quella già
 *   scritta, con il nome del piano da cui viene; l'amministratore la vede e la cambia se vuole (verifica a video di
 *   Vincenzo, 20/09/2026).
 * - Sulla fattura è un'informazione: sull'ordinario il riparto segue la competenza della voce, non quella dichiarata
 *   sulla fattura (decisione 19), e va detto dove si registra.
 *
 * Per ogni conto vale il piano **più recente** (id maggiore, stati bozza/approvato) che ha tratti sul conto stesso;
 * se il conto non ne ha, quelli del suo capitolo padre (una competenza sul capitolo vale per tutte le sue voci).
 */
class CompetenzaDichiarataPerVoce
{
    /**
     * @param list<int> $contoIds
     * @param array<int, ?int> $padreDi conto → parent_id, per ereditare dal capitolo
     * @return array<int, array{piano_rate_id: int, piano: string, tratti: list<array{dal: string, al: string}>}>
     */
    public function perConti(array $contoIds, array $padreDi = [], ?int $gestioneId = null, ?int $esercizioId = null): array
    {
        $cercati = array_values(array_unique(array_filter(array_merge($contoIds, array_values($padreDi)))));
        if ($cercati === []) {
            return [];
        }

        $righe = DB::table('competenze_capitolo as cc')
            ->join('piano_rate_capitoli as prc', 'prc.id', '=', 'cc.piano_rate_capitolo_id')
            ->join('piani_rate as pr', 'pr.id', '=', 'prc.piano_rate_id')
            ->whereIn('prc.conto_id', $cercati)
            ->whereIn('pr.stato', ['bozza', 'approvato'])
            ->when($gestioneId !== null, fn ($q) => $q->where('pr.gestione_id', $gestioneId))
            ->when($esercizioId !== null, fn ($q) => $q->where(fn ($w) => $w->where('pr.esercizio_id', $esercizioId)->orWhereNull('pr.esercizio_id')))
            ->orderByDesc('pr.id')->orderBy('cc.dal')
            ->get(['prc.conto_id', 'pr.id as piano_rate_id', 'pr.nome as piano', 'cc.dal', 'cc.al']);

        $dichiarate = [];
        foreach ($righe->groupBy('conto_id') as $contoId => $g) {
            $ultimo = $g->first();
            $dichiarate[(int) $contoId] = [
                'piano_rate_id' => (int) $ultimo->piano_rate_id,
                'piano' => (string) $ultimo->piano,
                'tratti' => $g->where('piano_rate_id', $ultimo->piano_rate_id)
                    ->map(fn ($t) => ['dal' => substr((string) $t->dal, 0, 10), 'al' => substr((string) $t->al, 0, 10)])->values()->all(),
            ];
        }

        $esito = [];
        foreach ($contoIds as $id) {
            $trovata = $dichiarate[$id] ?? (isset($padreDi[$id]) && $padreDi[$id] !== null ? ($dichiarate[$padreDi[$id]] ?? null) : null);
            if ($trovata !== null) {
                $esito[$id] = $trovata;
            }
        }

        return $esito;
    }
}
