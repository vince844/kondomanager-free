<?php

namespace App\Http\Resources\Portale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Il condominio come lo riceve il portale del condòmino (Coda 260): id e nome, quello che le pagine
 * leggono (`condominio.full.nome`, le tendine con `label="nome"`). La `CondominioResource` completa
 * resta al pannello: porta le «note», che l'amministratore scrive per sé, e l'esercizio aperto con
 * le sue, e calcolava l'esercizio con una query per ogni condominio.
 */
class CondominioPortaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'nome' => $this->nome ? Str::ucfirst($this->nome) : null,
        ];
    }
}
