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

        // Convenzione UNICA (attivo): DARE aumenta, AVERE diminuisce — anche per i
        // fondi. Sono partizioni dell'unico c/c (conti figli del mastro 1010,
        // attivo/liquidità): un accantonamento è DARE fondo / AVERE banca, un
        // utilizzo è l'inverso. Prima della beta.19 questo ramo leggeva i fondi
        // da passivo (avere − dare), in contraddizione con Cassa::saldo_reale e
        // con il Treasury Guardian sulla stessa riga di giornale.
        //
        // beta.25: il saldo non si ricalcola più qui — arriva da SaldoCassaService
        // (via l'accessor `saldo_reale`), unica fonte. Il withSum dei controller
        // resta solo per i due totali di visualizzazione entrate/uscite.
        $saldoCentesimi = (int) $this->saldo_reale;

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