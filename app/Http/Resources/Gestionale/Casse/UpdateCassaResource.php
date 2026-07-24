<?php

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper; 
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
            // Coerente con la guardia di UpdateCassaAction: la scrittura di apertura
            // non è un movimento dell'amministratore, quindi non deve disabilitare
            // i campi "tipo" e "saldo di apertura" nel form.
            'has_movements'  => $this->resource->hasMovimentiOperativi(),
            
            // --- CAMPI GOVERNANCE FONDI ---
            // Usa 'generico' come fallback di sicurezza per i vecchi fondi creati prima dell'aggiornamento
            'sottotipo_fondo'         => $this->sottotipo_fondo ?? 'generico', 
            'vincolo_descrizione'     => $this->vincolo_descrizione,
            'is_override_assemblea'   => (bool) $this->is_override_assemblea,
            'motivazione_override'    => $this->motivazione_override,

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