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
        $isStraordinario = $this->tipo === 'straordinario';

        // 1. CARICAMENTO RELAZIONI E CALCOLO TOTALE REALE
        if ($isStraordinario) {
            // SCENARIO 2: Leggiamo le fatture
            if (!$this->relationLoaded('fattureStraordinarie')) {
                $this->load('fattureStraordinarie.fornitore');
            }
            $totaleReale = $this->fattureStraordinarie->sum('pivot.importo_collegato');
        } else {
            // SCENARIO 1: Leggiamo i capitoli (Vecchia logica invariata)
            if (!$this->relationLoaded('capitoli')) {
                $this->load(['capitoli' => function($q) {
                    $q->withPivot(['importo', 'note']);
                }]);
            }
            $totaleReale = $this->capitoli->sum(function ($capitolo) {
                return !is_null($capitolo->pivot->importo) 
                    ? $capitolo->pivot->importo 
                    : $capitolo->importo;
            });
        }

        // Gestione Stato
        $statoValue = $this->stato instanceof \UnitEnum ? $this->stato->value : $this->stato;

        return [
            'id'                            => $this->id,
            'tipo'                          => $this->tipo ?? 'ordinario',
            'nome'                          => $this->nome,
            'descrizione'                   => $this->descrizione,
            'numero_rate'                   => $this->numero_rate,
            'stato'                         => $statoValue,
            'giorno_scadenza'               => $this->giorno_scadenza,
            'metodo_distribuzione'          => $this->metodo_distribuzione,
            // ⚠️ La chiave resta `data_inizio` **di proposito**, ma legge `created_at`.
            // `piani_rate.data_inizio` era in `$fillable` e nei `$casts` del Model, ma **la
            // colonna non esiste** (26 colonne, verificato): l'accessor restituiva sempre null e
            // il ripiego su `created_at` era già l'unico ramo davvero percorso. Crearla adesso
            // cambierebbe ciò che il frontend riceve, che è l'opposto di una correzione: si
            // toglie la finta colonna, si tiene la chiave in uscita.
            'data_inizio'                   => $this->created_at?->format('Y-m-d'),
            'data_delibera_assemblea'       => $this->data_delibera_assemblea?->format('Y-m-d'),
            'numero_verbale'                => $this->numero_verbale,
            'nota_approvazione'             => $this->nota_approvazione,
            'nota_scoperti'                 => $this->nota_scoperti,
            'tipo_autorizzazione'           => $this->tipo_autorizzazione,
            'motivazione_autorizzazione'    => $this->motivazione_autorizzazione,
            'approvato_da_user_id'          => $this->approvato_da_user_id,
            'approvato_il'                  => $this->approvato_il?->format('Y-m-d H:i:s'),
            'totale_capitoli'               => (int) $totaleReale,
            'totale_piano'                  => $this->relationLoaded('rate') ? (int) $this->rate->sum('importo_totale') : 0,
            'gestione'                      => new GestioneResource($this->whenLoaded('gestione')),
            'budget_movements'              => $this->whenLoaded('budgetMovements'),
            
            'has_saldi' => DB::table('rate_quote') 
                ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
                ->where('rate.piano_rate_id', $this->id)
                ->where(function ($q) {
                    $q->where('rate_quote.tipo', 'saldo_iniziale')
                      ->orWhere(function ($or) {
                          // Confronto bindato: '0' passa come parametro preparato, mai inline nel SQL
                          $or->whereRaw(
                                  "JSON_UNQUOTE(JSON_EXTRACT(rate_quote.regole_calcolo, '$.importi.saldo_usato')) != ?",
                                  ['0']
                              )
                             ->whereRaw(
                                  "JSON_EXTRACT(rate_quote.regole_calcolo, '$.importi.saldo_usato') IS NOT NULL"
                              );
                      });
                })
                ->exists(),
            
            // --- IL TRUCCO DI MAGIA: MASCHERIAMO LE FATTURE DA CAPITOLI ---
            'capitoli' => $isStraordinario 
                ? $this->whenLoaded('fattureStraordinarie', function() {
                    return $this->fattureStraordinarie->map(function ($f) {
                        $fornitore = $f->fornitore ? $f->fornitore->ragione_sociale : 'Fornitore Sconosciuto';
                        return [
                            'id'                => $f->id, // <--- RIMOSSO "clone" QUI!
                            'nome'              => "Fattura " . ($f->numero_documento ?? 'S/N') . " - " . $fornitore,
                            'importo'           => (int) $f->pivot->importo_collegato,
                            'importo_originale' => (int) ($f->importo_imponibile + $f->importo_iva),
                            'is_frazionato'     => false,
                            'note'              => 'Spesa straordinaria (Art. 1135 c.c.)',
                            'is_parent'         => false,
                            'figli_names'       => '',
                        ];
                    });
                })
                : $this->whenLoaded('capitoli', function() {
                    return $this->capitoli->map(function ($c) {

                        $isParent = $c->sottoconti->isNotEmpty();

                        $importoEffettivo = !is_null($c->pivot->importo) ? $c->pivot->importo : $c->importo;

                        $sottocontiSnapshot = $c->sottoconti->filter(
                            fn($s) => $s->created_at->lte($this->created_at)
                        );

                         $importoOriginale = $isParent 
                            ? $sottocontiSnapshot->sum('importo')  // ← usa snapshot
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
                            'figli_names'       => $isParent ? $sottocontiSnapshot->pluck('nome')->join(', ') : '',
                        ];
                    });
                }),
        ];
    }
}