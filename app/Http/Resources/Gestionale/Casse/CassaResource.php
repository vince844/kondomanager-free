<?php

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cc = $this->whenLoaded('contoCorrente');

        $saldoIniziale = (int) ($this->saldo_iniziale ?? 0);
        $dare  = (int) ($this->totale_entrate ?? 0); 
        $avere = (int) ($this->totale_uscite ?? 0);

        // Per i fondi (passività): AVERE aumenta, DARE diminuisce
        // Per i conti (attività): DARE aumenta, AVERE diminuisce
        if ($this->tipo === 'fondo') {
            $saldoCentesimi = $saldoIniziale + $avere - $dare;
        } else {
            $saldoCentesimi = $saldoIniziale + $dare - $avere;
        }

        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'tipo'        => $this->tipo, 
            'tipo_label'  => ucfirst($this->tipo), 
            'descrizione' => $this->descrizione,
            'attiva'      => (bool) $this->attiva,
            
            // --- DATI GOVERNANCE FONDI ---
            'sottotipo_fondo'                => $this->sottotipo_fondo,
            'vincolo_descrizione'            => $this->vincolo_descrizione,
            'is_override_assemblea'          => (bool) $this->is_override_assemblea,
            'is_utilizzabile_per_imprevisti' => (bool) $this->is_utilizzabile_per_imprevisti, 
            
            // --- DATI BANCA ---
            'banca_istituto'    => $cc ? $cc->istituto : null,
            'banca_iban'        => $cc ? $cc->iban : null,
            'banca_predefinito' => $cc ? (bool) $cc->predefinito : false,
            'banca_tipo_conto'  => $cc ? $cc->tipo : null, 

            // --- SALDI ---
            'saldo_iniziale_raw'       => MoneyHelper::fromCents($saldoIniziale), 
            'saldo_iniziale_formatted' => MoneyHelper::format($saldoIniziale),
            'saldo_raw'                => MoneyHelper::fromCents($saldoCentesimi), 
            'saldo_formatted'          => MoneyHelper::format($saldoCentesimi),
            'totale_entrate_formatted' => MoneyHelper::format($dare),
            'totale_uscite_formatted'  => MoneyHelper::format($avere),

            'note' => $this->note,
        ];
    }
}