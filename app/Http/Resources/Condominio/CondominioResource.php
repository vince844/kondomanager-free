<?php

namespace App\Http\Resources\Condominio;

use App\Http\Resources\Gestionale\Esercizi\EsercizioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class CondominioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'codice_identificativo' => $this->codice_identificativo,
            
            // Dati Generali
            'nome'                  => $this->nome ? Str::ucfirst($this->nome) : null,
            'codice_fiscale'        => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
            'email'                 => $this->email,
            'note'                  => $this->note,
            
            // Ubicazione
            'indirizzo'             => $this->indirizzo ? Str::ucfirst($this->indirizzo) : null,
            'comune'                => $this->comune ? Str::title($this->comune) : null,
            'provincia'             => $this->provincia ? Str::upper($this->provincia) : null,
            'cap'                   => $this->cap,
            
            // Dati Strutturali
            'anno_costruzione'      => $this->anno_costruzione,
            'anno_acquisizione'     => $this->anno_acquisizione,
            'numero_piani'          => $this->numero_piani,
            
            // Dati Catastali
            'comune_catasto'        => $this->comune_catasto ? Str::title($this->comune_catasto) : null,
            'codice_catasto'        => $this->codice_catasto ? Str::upper($this->codice_catasto) : null,
            'sezione_catasto'       => $this->sezione_catasto ? Str::upper($this->sezione_catasto) : null,
            'foglio_catasto'        => $this->foglio_catasto ? Str::upper($this->foglio_catasto) : null,
            'particella_catasto'    => $this->particella_catasto ? Str::upper($this->particella_catasto) : null,
            
            // Relazioni ed Esercizio Aperto
            'esercizio_aperto'      => new EsercizioResource(
                $this->esercizi()
                    ->where('stato', 'aperto')
                    ->latest('data_inizio')
                    ->first()
            ),
            'anagrafiche'           => $this->whenLoaded('anagrafiche'),
        ];
    }
}