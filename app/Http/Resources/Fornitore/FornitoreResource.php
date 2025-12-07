<?php

namespace App\Http\Resources\Fornitore;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FornitoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'ragione_sociale'  => $this->ragione_sociale,
        ];
    }
}
