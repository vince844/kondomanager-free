<?php

namespace App\Http\Resources\Anagrafica;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una persona per una tendina o un elenco che mostra l'indirizzo sotto il nome: l'id, il nome e l'indirizzo.
 *
 * Le tendine di «Nuovo fornitore», dei referenti del fornitore, di «Nuovo utente» e della sua modifica, di «Associa
 * anagrafica» all'unità, e il pannello con l'elenco delle persone (`AnagraficheStack.vue`: i destinatari dei documenti
 * dell'archivio, la persona collegata nell'elenco utenti, le persone dell'elenco condomìni) mostrano il nome e, sotto,
 * l'indirizzo. Il resto della persona sta nella sua scheda, che chiede «Visualizza anagrafiche» (Coda 408).
 */
class AnagraficaConIndirizzoResource extends JsonResource
{
    /**
     * @return array{id: int, nome: string, indirizzo: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'nome'      => $this->nome,
            'indirizzo' => $this->indirizzo,
        ];
    }
}
