<?php

namespace App\Http\Resources\Anagrafica;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una persona per una tendina o per un elenco che la nomina: l'id e il nome, e basta.
 *
 * Le tendine dei destinatari delle comunicazioni, delle scadenze in agenda, dei documenti e delle segnalazioni, la
 * persona collegata a un utente, i destinatari e l'autore negli elenchi, nelle schede e nella dashboard mostrano le
 * persone per nome. Codice fiscale, telefoni, email, documento d'identità, nascita e note stanno nella scheda della
 * persona, che chiede «Visualizza anagrafiche» (Coda 408). Dove la pagina mostra anche l'indirizzo sotto il nome c'è
 * `AnagraficaConIndirizzoResource`.
 */
class AnagraficaNomeResource extends JsonResource
{
    /**
     * @return array{id: int, nome: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'nome' => $this->nome,
        ];
    }
}
