<?php

namespace App\Http\Resources\Evento;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\User\UserResource;
use App\Services\Gestionale\CreditoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EventoResource extends JsonResource
{

    /**
     * Il `meta` dell'evento, con i valori che invecchiano ricalcolati al momento.
     *
     * `credito_rata_zero` alimenta il «Salvadanaio» del portale: quanto credito il condòmino
     * può ancora usare per saldare la rata. Il listener lo scrive **una volta sola**, quando il
     * piano viene approvato, e non lo riscrive mai (`SyncScadenziarioWithPianoRate`, guardia
     * `if ($esiste) continue;`). Nella realtà il credito si consuma dopo, e lo snapshot resta
     * fermo al valore di allora: il condòmino vedeva un credito che non aveva più, e il
     * pulsante «Sì, salda la rata con il credito» calcolava su quello.
     *
     * Qui passa tutto ciò che arriva al condòmino — quattro controller usano questa Resource —
     * quindi è il punto giusto per far dire al numero la verità di adesso. Il `meta` a database
     * resta com'è: non serve migrare niente, e se un giorno servisse lo storico è ancora lì.
     */
    private function metaAggiornato(): mixed
    {
        $meta = $this->meta;

        if (! is_array($meta) || ! array_key_exists('credito_rata_zero', $meta)) {
            return $meta;
        }

        $pianoRateId = $meta['context']['piano_rate_id'] ?? null;
        $anagraficaId = $this->anagrafiche->first()?->id;

        if (! $pianoRateId || ! $anagraficaId) {
            return $meta;
        }

        // Solo per il condòmino intestatario. Questa Resource serve anche le liste
        // dell'amministratore, che contengono gli eventi di TUTTI i condòmini: ricalcolare lì
        // costerebbe una query per persona a ogni pagina, per un numero che quella schermata
        // non mostra — il «Salvadanaio» sta dietro `v-if="isCondomino"`.
        //
        // `auth()` e non `$request->user()`: il secondo passa dal risolutore che il middleware
        // installa sulla richiesta, e chi rende questa Resource fuori da un ciclo HTTP completo
        // non ce l'ha. La fonte è la stessa, l'accesso è uno che vale sempre.
        if (auth()->user()?->anagrafica?->id !== $anagraficaId) {
            return $meta;
        }

        $meta['credito_rata_zero'] = app(CreditoService::class)
            ->creditoRataZeroSpendibile((int) $pianoRateId, (int) $anagraficaId);

        return $meta;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $occursAt = $this->occurs_at ?? $this->start_time;

        return [
            'id'              => $this->id,
            'title'           => Str::ucfirst($this->title),
            'description'     => $this->description,
            'recurrence_id'   => $this->recurrence_id,
            
            // Date
            'start_time'      => $this->start_time,
            'end_time'        => $this->end_time,
            'occurs_at_human' => Carbon::parse($occursAt)->diffForHumans(),
            'occurs'          => $this->occurs_at,
            'occurs_at'       => Carbon::parse($occursAt)->format('d/m/Y \a\l\l\e H:i'),
            'updated_at'      => $this->updated_at,
            
            // Relazioni
            'categoria'       => new CategoriaEventoResource($this->whenLoaded('categoria')),
            'condomini'       => CondominioResource::collection($this->whenLoaded('condomini')),
            'anagrafiche'     => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
            
            // Configurazione
            'timezone'        => $this->timezone,
            'visibility'      => $this->visibility,
            'is_approved'     => $this->is_approved,
            
            'meta'            => $this->metaAggiornato(),

            'created_by'      => $this->whenLoaded('createdBy', function () {
                                    return [
                                        'user'       => new UserResource($this->createdBy),
                                        'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                                            ? new AnagraficaResource($this->createdBy->anagrafica)
                                            : null,
                                    ];
                                 }),
        ];
    }
}