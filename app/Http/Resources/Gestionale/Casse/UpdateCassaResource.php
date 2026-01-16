<?php

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpdateCassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Carichiamo sempre la relazione se serve, o ci assicuriamo che sia accessibile
        $cc = $this->contoCorrente;

        return [
            'id'             => $this->id,
            'nome'           => $this->nome,
            'tipo'           => $this->tipo, // 'banca', 'contanti', etc.
            'descrizione'    => $this->descrizione,
            'note'           => $this->note,
            'attiva'         => (bool) $this->attiva,
            'has_movements'  => $this->contoContabile?->movimenti()->exists() ?? false,
            'saldo_iniziale' => $this->saldo_iniziale ?? 0,

            // Qui restituiamo un oggetto strutturato, perfetto per il form Vue
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
            ] : null, // Se null, Vue userà i valori di default del form
        ];
    }
}