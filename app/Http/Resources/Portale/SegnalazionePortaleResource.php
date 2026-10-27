<?php

namespace App\Http\Resources\Portale;

use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * La segnalazione come la riceve il portale del condòmino (Coda 260).
 *
 * Un elenco di campi ammessi, non un elenco di campi tolti: un campo nuovo della risorsa del
 * pannello non arriva al portale finché qualcuno non lo aggiunge qui. L'autore con id e nome
 * (`AutorePortale`); il condominio con id e nome; le persone a cui è indirizzata e l'assegnatario
 * non arrivano (le pagine del portale non li mostrano, e sono dati di altri). I commenti hanno
 * già la loro risorsa minima.
 */
class SegnalazionePortaleResource extends SegnalazioneResource
{
    private const CAMPI = [
        'id', 'subject', 'description', 'priority', 'stato', 'is_resolved', 'is_locked', 'is_featured',
        'is_private', 'is_published', 'is_approved', 'can_comment', 'created_at', 'commenti', 'commentiInAttesa',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return Arr::only(parent::toArray($request), self::CAMPI) + [
            'created_by' => $this->whenLoaded('createdBy', fn () => AutorePortale::di($this->createdBy)),
            'condominio' => [
                'option' => new CondominioOptionsResource($this->whenLoaded('condominio')),
                'full'   => new CondominioPortaleResource($this->whenLoaded('condominio')),
            ],
        ];
    }
}
