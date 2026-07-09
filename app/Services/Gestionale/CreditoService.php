<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Collection;

class CreditoService
{
    /**
     * Credito disponibile di una singola anagrafica in un condominio,
     * raggruppato per gestione. Copre entrambe le forme di credito: saldo
     * iniziale a importo negativo e quote strapagate.
     *
     * @return array{totale_cents:int, totale_formatted:string, per_gestione:array<int,array{gestione_id:int|null,gestione_nome:string,importo_cents:int,importo_formatted:string}>}
     */
    public function perAnagrafica(int $condominioId, int $anagraficaId): array
    {
        $quote = $this->queryCreditoBase($condominioId)
            ->where('anagrafica_id', $anagraficaId)
            ->get();

        $perGestione = $quote
            ->groupBy(fn($q) => $q->rata->pianoRate->gestione_id ?? 0)
            ->map(function ($gruppo) {
                $primo = $gruppo->first();
                $importoCents = $gruppo->sum(fn($q) => $q->credito_disponibile);

                return [
                    'gestione_id'       => $primo->rata->pianoRate->gestione_id ?? null,
                    'gestione_nome'     => $primo->rata->pianoRate->gestione->nome ?? 'Generica',
                    'importo_cents'     => $importoCents,
                    'importo_formatted' => MoneyHelper::format($importoCents),
                ];
            })
            ->filter(fn($g) => $g['importo_cents'] > 0)
            ->values()
            ->toArray();

        $totaleCents = array_sum(array_column($perGestione, 'importo_cents'));

        return [
            'totale_cents'     => $totaleCents,
            'totale_formatted' => MoneyHelper::format($totaleCents),
            'per_gestione'     => $perGestione,
        ];
    }

    /**
     * Elenco delle anagrafiche con credito disponibile in un condominio,
     * opzionalmente ristretto a un sottoinsieme di anagrafiche. Usato dal
     * widget dashboard (nessun filtro) e dal suggerimento di compensazione
     * all'emissione (filtrato alle anagrafiche delle rate appena emesse).
     *
     * @param  array<int>|null  $anagraficheIds
     * @return Collection<int,array{anagrafica_id:int,nome:string,totale_cents:int,totale_formatted:string}>
     */
    public function perCondominio(int $condominioId, ?array $anagraficheIds = null): Collection
    {
        $query = $this->queryCreditoBase($condominioId)
            ->whereNotNull('anagrafica_id')
            ->with('anagrafica:id,nome');

        if ($anagraficheIds !== null) {
            $query->whereIn('anagrafica_id', $anagraficheIds);
        }

        return $query->get()
            ->groupBy('anagrafica_id')
            ->map(function ($quote) {
                $totaleCents = $quote->sum(fn($q) => $q->credito_disponibile);

                return [
                    'anagrafica_id'    => $quote->first()->anagrafica_id,
                    'nome'             => $quote->first()->anagrafica->nome ?? 'Condomino',
                    'totale_cents'     => $totaleCents,
                    'totale_formatted' => MoneyHelper::format($totaleCents),
                ];
            })
            ->filter(fn($c) => $c['totale_cents'] > 0)
            ->values();
    }

    /**
     * Query di base: tutte le quote che portano credito (strapagamento o
     * saldo iniziale/anticipo negativo) in un condominio, con le relazioni
     * verso gestione già caricate per evitare N+1 nei consumer.
     */
    private function queryCreditoBase(int $condominioId)
    {
        return RataQuote::whereHas('rata.pianoRate', fn($p) => $p->where('condominio_id', $condominioId))
            ->where(function ($q) {
                $q->whereRaw('importo_pagato > importo')
                  ->orWhere('importo', '<', 0);
            })
            ->with(['rata.pianoRate.gestione:id,nome']);
    }
}
