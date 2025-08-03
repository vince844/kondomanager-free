<?php

namespace App\Http\Resources\Evento;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class EditEventoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'description'     => $this->description,
            'note'            => $this->note,
             'visibility'     => $this->visibility,
            'start_time'      => Carbon::parse($this->start_time)->format('Y-m-d\TH:i'),
            'end_time'        => Carbon::parse($this->end_time)->format('Y-m-d\TH:i'),
            'recurrence_id'   => $this->recurrence_id,
            'category_id'     => $this->categoria?->id,
            'condomini_ids'   => $this->condomini->pluck('id'),
            'anagrafiche'     => AnagraficaResource::collection($this->whenLoaded('anagrafiche')), 
            'recurrence'      => $this->whenLoaded('ricorrenza', function () {
                return [
                    'frequency' => $this->ricorrenza->frequency,
                    'interval'  => $this->ricorrenza->interval,
                    'by_day'    => $this->ricorrenza->by_day ?? [],
                    'until'     => $this->ricorrenza->until 
                    ? Carbon::parse($this->ricorrenza->until)->format('Y-m-d\TH:i') 
                    : null,
                ];
            }),
        ];
    }
}
