<?php

namespace App\Http\Resources\Gestionale\PianiRate;

use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class PianoRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Carichiamo la relazione se non presente
        if (!$this->relationLoaded('capitoli')) {
            $this->load(['capitoli' => function($q) {
                // Fondamentale: carichiamo i dati extra della pivot
                $q->withPivot(['importo', 'note']);
            }]);
        }

        // CALCOLO TOTALE REALE (FIX 200€ vs 823€)
        // Se nella pivot c'è un importo (override), usiamo quello.
        // Altrimenti usiamo l'importo standard del conto.
        $totaleReale = $this->capitoli->sum(function ($capitolo) {
            return !is_null($capitolo->pivot->importo) 
                ? $capitolo->pivot->importo 
                : $capitolo->importo;
        });

        // Gestione Stato
        $statoValue = $this->stato;
        if ($this->stato instanceof \UnitEnum) {
            $statoValue = $this->stato->value;
        }

        return [
            'id'              => $this->id,
            'nome'            => $this->nome,
            'descrizione'     => $this->descrizione,
            'numero_rate'     => $this->numero_rate,
            'stato'           => $statoValue,
            'giorno_scadenza' => $this->giorno_scadenza,
            'metodo_distribuzione'  => $this->metodo_distribuzione,
            'data_inizio'     => $this->data_inizio?->format('Y-m-d') ?? $this->created_at?->format('Y-m-d'),
            // FIX: Usiamo il totale calcolato dalla pivot
            'totale_capitoli' => (int) $totaleReale,
            // Totale rate generate (controllo incrociato)
            'totale_piano'    => $this->relationLoaded('rate') ? (int) $this->rate->sum('importo_totale') : 0,
            'gestione'        => new GestioneResource($this->whenLoaded('gestione')),
            'budget_movements' => $this->whenLoaded('budgetMovements'),
            'has_saldi' => DB::table('rate_quote') 
                ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
                ->where('rate.piano_rate_id', $this->id)
                ->where(function($q) {
                    // Fallback V1.8
                    $q->where('rate_quote.tipo', 'saldo_iniziale')
                      // Controllo V1.9 (Guarda se c'è un valore nel JSON)
                      ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(rate_quote.regole_calcolo, '$.importi.saldo_usato')) != '0' AND JSON_UNQUOTE(JSON_EXTRACT(rate_quote.regole_calcolo, '$.importi.saldo_usato')) IS NOT NULL");
                })
                ->exists(),
            'capitoli' => $this->whenLoaded('capitoli', function() {
                return $this->capitoli->map(function ($c) {
                    $isParent = $c->sottoconti()->exists();
                    
                    $importoEffettivo = !is_null($c->pivot->importo) 
                        ? $c->pivot->importo 
                        : $c->importo;

                    // FIX: Se è un padre, l'importo originale è la somma dei sottoconti
                    // Se è una voce singola, è il suo importo standard
                    $importoOriginale = $isParent 
                        ? $c->sottoconti->sum('importo') 
                        : $c->importo;

                    $isFrazionato = !is_null($c->pivot->importo) && abs($c->pivot->importo - $importoOriginale) > 1;

                    return [
                        'id'                => $c->id,
                        'nome'              => $c->nome,
                        'importo'           => (int) $importoEffettivo,
                        'importo_originale' => (int) $importoOriginale,
                        'is_frazionato'     => $isFrazionato,
                        'note'              => $c->pivot->note,
                        'is_parent'         => $isParent,
                        'figli_names'       => $isParent ? $c->sottoconti->pluck('nome')->join(', ') : '',
                    ];
                });
            }),
        ];
    }
}