<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class IncassoRateService
{
    /**
     * Recupera la query per gli incassi con filtri ed Eager Loading
     */
    public function getIncassiQuery(Condominio $condominio, ?string $search = null): Builder
    {
        $query = ScritturaContabile::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->with([
                'gestione', 
                'righe.anagrafica', 
                'righe.cassa',
                // 🔥 EAGER LOADING: Carichiamo le quote e le rate padre in un colpo solo
                'quotePagate.rata' 
            ]);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('numero_protocollo', 'like', "%{$search}%")
                  ->orWhere('causale', 'like', "%{$search}%")
                  ->orWhereHas('righe', function($qr) use ($search) {
                      $qr->whereHas('anagrafica', function($qa) use ($search) {
                             $qa->where('nome', 'like', "%{$search}%");
                         });
                  });
            });
        }

        return $query->orderByDesc('data_registrazione')
            ->orderByDesc('numero_protocollo');
    }

    /**
     * Trasforma un movimento in array per il frontend
     */
    public function formatMovimentoForFrontend(ScritturaContabile $movimento): array
    {
        $rigaCassa = $movimento->righe->firstWhere('tipo_riga', 'dare');
        
        $rigaPagantePrinc = $movimento->righe
            ->where('tipo_riga', 'avere')
            ->whereNotNull('anagrafica_id')
            ->first();

        $nomiPaganti = $movimento->righe
            ->where('tipo_riga', 'avere')
            ->whereNotNull('anagrafica_id')
            ->map(fn($r) => $r->anagrafica->nome ?? null)
            ->filter()
            ->unique()
            ->values();

        return [
            'id'                       => $movimento->id,
            'numero_protocollo'        => $movimento->numero_protocollo,
            'data_competenza'          => $movimento->data_competenza?->format('Y-m-d'),
            'data_registrazione'       => $movimento->data_registrazione?->format('Y-m-d'),
            'causale'                  => $movimento->causale,
            
            // 🔥 Passiamo l'oggetto intero, non l'ID, per usare i dati in memoria
            'dettagli_rate'            => $this->getDettagliRate($movimento),
            
            'importo_totale_raw'       => $rigaCassa ? $rigaCassa->importo / 100 : 0,
            'importo_totale_formatted' => MoneyHelper::format($rigaCassa?->importo ?? 0),
            'stato'                    => $movimento->stato,
            'pagante' => [
                'principale'     => $nomiPaganti->first() ?? 'Sconosciuto',
                'altri_count'    => max(0, $nomiPaganti->count() - 1),
                'lista_completa' => $nomiPaganti->join(', '),
                'ruolo'          => $this->getRuoloPagante($rigaPagantePrinc)
            ],
            'cassa_nome'               => $rigaCassa?->cassa?->nome ?? 'N/D',
            'cassa_tipo_label'         => $this->getTipoRisorsaLabel($rigaCassa),
            'gestione_nome'            => $movimento->gestione?->nome ?? 'Generica',
            'anagrafica_id_principale' => $rigaPagantePrinc?->anagrafica_id,
        ];
    }

    /**
     * Recupera i dettagli delle rate (Zero Query, usa le relazioni caricate)
     */
    private function getDettagliRate(ScritturaContabile $movimento): array
    {
        // Se non ci sono quote pagate (es. anticipo puro), ritorna array vuoto
        if ($movimento->quotePagate->isEmpty()) {
            return [];
        }

        return $movimento->quotePagate
            ->sortBy(fn($quota) => $quota->rata->numero_rata ?? 0) // Ordina in memoria (PHP)
            ->map(function($quota) {
                return [
                    'numero' => $quota->rata->numero_rata ?? '-',
                    
                    'scadenza' => $quota->rata->data_scadenza 
                        ? $quota->rata->data_scadenza->format('d/m/Y') 
                        : '-',
                    
                    // 🔥 Legge dalla pivot caricata in memoria
                    'importo_formatted' => MoneyHelper::format($quota->pivot->importo_pagato)
                ];
            })
            ->values() // Resetta indici array
            ->toArray();
    }

    /**
     * Determina il ruolo del pagante
     */
    private function getRuoloPagante($rigaPagantePrinc): string
    {
        $ruoloPagante = 'Condòmino';

        // Nota: Questa query DB rimane perché dipende da una relazione complessa anagrafica-immobile
        // Ottimizzarla richiederebbe caricare tutte le relazioni immobiliari all'inizio.
        // Dato che è una query per riga (veloce, su indici), per ora è accettabile.
        if ($rigaPagantePrinc && $rigaPagantePrinc->anagrafica_id && $rigaPagantePrinc->immobile_id) {
            $ruoloDb = DB::table('anagrafica_immobile')
                ->where('anagrafica_id', $rigaPagantePrinc->anagrafica_id)
                ->where('immobile_id', $rigaPagantePrinc->immobile_id)
                ->value('tipologia');

            if ($ruoloDb) {
                $ruoloPagante = ucfirst($ruoloDb);
            }
        }

        return $ruoloPagante;
    }

    /**
     * Ottiene la label del tipo di risorsa
     */
    private function getTipoRisorsaLabel($rigaCassa): string
    {
        if (!$rigaCassa || !$rigaCassa->cassa) {
            return 'N/D';
        }

        $labels = [
            'banca' => 'Conto Corrente',
            'contanti' => 'Cassa Contanti',
            'postale' => 'Conto Postale'
        ];

        return $labels[$rigaCassa->cassa->tipo] ?? ucfirst($rigaCassa->cassa->tipo);
    }
}