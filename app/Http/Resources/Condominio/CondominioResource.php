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
            'nome'                  => Str::ucfirst($this->nome),
            'indirizzo'             => Str::ucfirst($this->indirizzo),
            'email'                 => $this->email,
            'note'                  => $this->note,
            'codice_fiscale'        => Str::upper($this->codice_fiscale),
            'comune_catasto'        => $this->comune_catasto,
            'codice_catasto'        => Str::upper($this->codice_catasto),
            'sezione_catasto'       => Str::upper($this->sezione_catasto),
            'foglio_catasto'        => Str::upper($this->foglio_catasto),
            'particella_catasto'    => Str::upper($this->particella_catasto),
            'codice_identificativo' => $this->codice_identificativo,
            // Aggiungi l’esercizio aperto
            'esercizio_aperto' => new EsercizioResource(
                $this->esercizi()
                    ->where('stato', 'aperto')
                    ->latest('data_inizio')
                    ->first()
            ),
        ];
    }
}
