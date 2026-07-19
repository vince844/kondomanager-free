<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncassoRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // L'anagrafica è legata al condominio dal pivot anagrafica_condominio.
            'pagante_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('anagrafiche', 'id')->whereIn(
                    'id',
                    fn ($q) => $q->select('anagrafica_id')->from('anagrafica_condominio')
                        ->where('condominio_id', $this->route('condominio')?->id)
                ),
            ],

            // Scopati per condominio: StoreIncassoRateAction fa Cassa::findOrFail e
            // RataQuote::whereIn senza filtro di appartenenza, quindi un id altrui
            // arrivava fino alla scrittura contabile.
            'cassa_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('casse', 'id')
                    ->where('condominio_id', $this->route('condominio')?->id),
            ],
            'gestione_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('gestioni', 'id')
                    ->where('condominio_id', $this->route('condominio')?->id),
            ],
            'data_pagamento' => 'required|date|before_or_equal:'.DateHelper::oggiUtente(),
            
            // MODIFICA 1: Permettiamo importo_totale a 0. 
            // Se una rata è coperta al 100% dal credito, in cassa entrano 0 contanti!
            'importo_totale' => 'required|numeric|min:0', 
            
            'descrizione' => 'nullable|string|max:255',
            'eccedenza' => 'nullable|numeric|min:0',
            'dettaglio_pagamenti' => 'required|array',
            // La quota risale al condominio attraverso rate → piani_rate.
            // StoreIncassoRateAction fa RataQuote::findOrFail senza alcun filtro:
            // una quota altrui sarebbe stata incassata sulla cassa di questo condominio.
            'dettaglio_pagamenti.*.rata_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('rate_quote', 'id')->whereIn(
                    'rata_id',
                    fn ($q) => $q->select('rate.id')->from('rate')
                        ->join('piani_rate', 'rate.piano_rate_id', '=', 'piani_rate.id')
                        ->where('piani_rate.condominio_id', $this->route('condominio')?->id)
                ),
            ],
            
            // MODIFICA 2: Accettiamo numeri positivi (pagamenti) e negativi (prelievo credito)
            // L'unico valore che non ha senso è lo zero netto.
            'dettaglio_pagamenti.*.importo' => 'required|numeric|not_in:0', 
        ];
    }
}