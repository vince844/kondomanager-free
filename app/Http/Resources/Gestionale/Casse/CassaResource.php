<?php

namespace App\Http\Resources\Gestionale\Casse;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Relazione Conto Corrente
        $cc = $this->whenLoaded('contoCorrente');

        // --- CALCOLO SALDO ---
        // Recuperiamo i dati calcolati da withSum nel controller
        // Se non esistono (es. chiamata singola senza withSum), fallback a 0
        $saldoIniziale = $this->saldo_iniziale ?? 0;
        $entrate = $this->totale_entrate ?? 0;
        $uscite = $this->totale_uscite ?? 0;

        $saldoCentesimi = $saldoIniziale + $entrate - $uscite;

        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'tipo'        => $this->tipo, 
            'tipo_label'  => ucfirst($this->tipo), 
            'descrizione' => $this->descrizione,
            'attiva'      => (bool) $this->attiva,
            
            // DATI BANCA
            'banca_istituto'    => $cc ? $cc->istituto : null,
            'banca_iban'        => $cc ? $cc->iban : null,
            'banca_predefinito' => $cc ? (bool) $cc->predefinito : false,
            'banca_tipo_conto'  => $cc ? $cc->tipo : null, 
            // 1. Valore numerico (float) -> Serve per Ordinamento tabella e Colore (Rosso/Verde)
            'saldo_raw' => MoneyHelper::fromCents($saldoCentesimi), 
            // 2. Stringa formattata (string) -> Serve solo da stampare a video
            // Es: "€ 1.250,00"
            'saldo_formatted' => MoneyHelper::format($saldoCentesimi),
            'note'            => $this->note,
        ];
    }
}