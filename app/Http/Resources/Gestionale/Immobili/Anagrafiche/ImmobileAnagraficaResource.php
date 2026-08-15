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
                'tipologia'       => $this->pivot->tipologia,
                'quota'           => $this->pivot->quota,
                'tipologie_spese' => $this->pivot->tipologie_spese,
                'data_inizio'     => $this->pivot->data_inizio,
                'data_fine'       => $this->pivot->data_fine,
                'attivo'          => $this->pivot->attivo,
                'note'            => $this->pivot->note,
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
