<?php

namespace App\Http\Resources\Gestionale\Immobili;

use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaResource;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ImmobileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'nome'               => Str::ucfirst($this->nome),
            'descrizione'        => $this->descrizione,
            'interno'            => $this->interno,
            'piano'              => $this->piano,
            'superficie'         => $this->superficie,
            'numero_vani'        => $this->numero_vani,
            'codice_immobile'    => $this->codice_immobile,
            'comune_catasto'     => $this->comune_catasto,
            'sezione_catasto'    => $this->sezione_catasto,
            'foglio_catasto'     => $this->foglio_catasto,
            'particella_catasto' => $this->particella_catasto,
            'subalterno_catasto' => $this->subalterno_catasto,
            'codice_catasto'     => $this->codice_catasto,
            'attivo'             => $this->attivo,
            'note'               => $this->note,
            'tipologia'          => new TipologiaImmobileResource($this->whenLoaded('tipologiaImmobile')),
            'palazzina'          => new PalazzinaResource($this->whenLoaded('palazzina')),
            'scala'              => new ScalaResource($this->whenLoaded('scala')),
            'anagrafiche'        => ImmobileAnagraficaResource::collection($this->whenLoaded('anagrafiche')),

            // Il legame «Pertinenza di», nelle sue due forme alternative. `pertinenzaDi` esce solo
            // se la relazione è stata caricata: l'elenco unità la carica, il modulo no — gli basta
            // l'id per riempire il select.
            'pertinenza_di_immobile_id' => $this->pertinenza_di_immobile_id,
            'pertinenza_di_esterna'     => $this->pertinenza_di_esterna,
            'pertinenza_di'             => $this->whenLoaded('pertinenzaDi', fn () => [
                'id'      => $this->pertinenzaDi->id,
                'nome'    => $this->pertinenzaDi->nome,
                'interno' => $this->pertinenzaDi->interno,
                // I titolari del principale, quando sono stati caricati: servono al confronto
                // dell'avviso di divergenza, che li mette a fianco di quelli della pertinenza.
                'anagrafiche' => ImmobileAnagraficaResource::collection(
                    $this->pertinenzaDi->relationLoaded('anagrafiche') ? $this->pertinenzaDi->anagrafiche : collect()
                ),
            ]),
            /*
             * ⚠️ **Il conteggio si calcola da sé se manca, e non è pigrizia.**
             *
             * Con `whenCounted()` il campo spariva ovunque il chiamante avesse scordato
             * `withCount`, cioè in **sei** dei punti che rendono questa risorsa: le pagine
             * anagrafiche e documenti dell'unità, in elenco, creazione e modifica. Là la tab
             * «Pertinenze» del menù dell'unità — che compare solo se c'è qualcosa da mostrare —
             * si limitava a non esserci: nessun errore, nessun vuoto, semplicemente una voce di
             * menù che appare e scompare secondo la pagina da cui la si guarda.
             *
             * Aggiungere `loadCount` nei sei punti l'avrebbe risolto oggi e riaperto al settimo.
             * È la stessa forma della mappa dei ruoli scritta a mano in tre posti, dove nessuno
             * dei tre era d'accordo con gli altri: la cura è togliere l'obbligo di ricordarsene,
             * non ricordarsene meglio.
             *
             * Il costo è una COUNT su colonna indicizzata quando il chiamante non l'ha
             * preparata. Dove conta davvero — l'elenco paginato, unico punto che rende una
             * collezione — il `withCount` c'è e questo ramo non parte mai.
             */
            'pertinenze_count'          => $this->pertinenze_count ?? $this->pertinenze()->count(),
            'documenti'          => DocumentoResource::collection($this->whenLoaded('documenti')),
        ];
    }
}
