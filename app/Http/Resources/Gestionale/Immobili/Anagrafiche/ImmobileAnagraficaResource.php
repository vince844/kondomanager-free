<?php

namespace App\Http\Resources\Gestionale\Immobili\Anagrafiche;

use App\Helpers\MoneyHelper;
use Cknow\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImmobileAnagraficaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        // ⚠️ **`whenLoaded()` con un solo argomento non è un booleano.** Su una relazione non
        // caricata restituisce `MissingValue`, che è un **oggetto** e quindi sempre truthy: il
        // ramo `null` era irraggiungibile e `$this->saldi->first()` faceva un lazy load a ogni
        // riga. Il metodo esiste per essere *restituito* dentro l'array della Resource — dove il
        // serializzatore lo toglie — non per essere messo in una condizione.
        //
        // Il difetto era latente finché questa Resource la risolveva solo
        // `ImmobileAnagraficaController::index()`, che carica i saldi con `loadMissing()`. La
        // beta.52 ha aggiunto l'eager load di `anagrafiche` all'elenco unità e lo ha svegliato:
        // **86 query, di cui 40 su `saldi`** su una pagina da dieci unità con quattro soggetti
        // ciascuna. Trovato dalla revisione avversariale.
        //
        // `relationLoaded()` è la domanda che si voleva porre, e restituisce un booleano vero.
        $saldo = $this->relationLoaded('saldi')
            ? $this->saldi->first()
            : null;

        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'indirizzo'      => $this->indirizzo,
            'codice_fiscale' => $this->codice_fiscale,
            'pivot' => [
                // L'`id` della riga: dalla 1.11.0-beta.31 le rotte di modifica e «Dissocia» lavorano
                // per periodo, non per persona (decisione 13 del progetto sul subentro).
                'id'              => $this->pivot->id,
                'tipologia'       => $this->pivot->tipologia,
                'quota'           => $this->pivot->quota,
                // La colonna è caduta nella 1.11.0-beta.31 (era NULL ovunque): la chiave resta nel JSON,
                // a null, finché il tipo TS `AnagraficaPivot` la dichiara.
                'tipologie_spese' => null,
                // `TitolaritaImmobile` ha i cast `date:Y-m-d` dalla beta.31: qui l'attributo è un Carbon,
                // e senza `toDateString()` il JSON passerebbe da `2026-01-01` a ISO 8601.
                'data_inizio'     => $this->pivot->data_inizio?->toDateString(),
                'data_fine'       => $this->pivot->data_fine?->toDateString(),
                'attivo'          => $this->pivot->attivo,
                'note'            => $this->pivot->note,
                // Decisione 24: la riga è agganciata a un passaggio registrato (uscente o entrante) — il ruolo non si
                // cambia. Lo scrive il controller che ne ha bisogno, con UNA query per unità (`ImmobileAnagraficaController::index()`);
                // dove nessuno l'ha calcolato resta nullo — nessuna query per riga (l'elenco unità ha il test dell'N+1).
                'agganciata_a_passaggio' => $this->pivot->getAttribute('agganciata_a_passaggio'),
            ],

            'saldo' => [
                'iniziale' => MoneyHelper::format($saldo?->saldo_iniziale ?? 0),
                'finale'   => MoneyHelper::format($saldo?->saldo_finale ?? 0),
                'amounts' => [
                    'iniziale' => $saldo?->saldo_iniziale ?? 0,
                    'finale'   => $saldo?->saldo_finale ?? 0,
                ],
            ]

        ];
    }
}
