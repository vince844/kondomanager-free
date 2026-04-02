<?php

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper; // <-- Assicurati che sia importato
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpdateCassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cc = $this->contoCorrente;

        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'tipo'           => $this->tipo,
            'descrizione'    => $this->descrizione,
            'note'           => $this->note,
            'attiva'         => (bool) $this->attiva,
            'has_movements'  => $this->contoContabile?->movimenti()->exists() ?? false,
            
            // 🔥 LA MAGIA È QUI: 
            // Formattiamo in "500,00" senza il simbolo dell'Euro!
            'saldo_iniziale' => $this->saldo_iniziale 
                                    ? MoneyHelper::format($this->saldo_iniziale, false) 
                                    : '',

            'conto_corrente' => $cc ? [
                'id'           => $cc->id,
                'istituto'     => $cc->istituto,
                'iban'         => $cc->iban,
                'swift'        => $cc->swift, 
                'intestatario' => $cc->intestatario,
                'tipo'         => $cc->tipo,
                'predefinito'  => (bool) $cc->predefinito,
                'indirizzo'    => $cc->indirizzo,
                'comune'       => $cc->comune,
                'cap'          => $cc->cap,
                'provincia'    => $cc->provincia,
                'nazione'      => $cc->nazione,
            ] : null,
        ];
    }
}