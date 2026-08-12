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
            // È condòmino di questo stabile chi è **nel pivot** `anagrafica_condominio`
            // **oppure** chi **possiede un'unità** qui. Servono entrambi i criteri, e
            // nessuno dei due basta da solo.
            //
            // ## Perché il solo pivot non bastava
            //
            // La schermata **propone** i paganti con l'altro criterio
            // (`IncassoRateController:125`, `whereHas('immobili')`): il modulo offriva una
            // persona che poi il server rifiutava, con un messaggio incomprensibile a chi
            // aveva scelto un nome dall'elenco che il gestionale stesso gli aveva dato.
            //
            // Sull'**importato** le due divergevano sempre, perché l'importatore popola le
            // unità e non il pivot: misurato l'11/08/2026 su «Le Terrazze», 16 anagrafiche con
            // unità e 0 nel pivot. Un amministratore importava lo stabile e **non poteva
            // registrare un solo incasso** — vedi la coda ⑫ in roadmap.
            //
            // ## Perché le sole unità non bastano
            //
            // Il pivot copre un caso che le unità non danno: una persona associata al
            // condominio che **non possiede niente** — accesso al portale, consiglieri. A
            // database esiste davvero. Toglierlo le leverebbe un diritto che aveva.
            //
            // ⚠️ **Quello che la regola continua a impedire, ed è la ragione per cui esiste:**
            // intestare un incasso a un'anagrafica di un **altro** condominio. Allargare il
            // criterio non apre quella porta — è blindato da
            // `tests/Feature/Gestionale/PaganteDelCondominioTest.php`, dove il test
            // sull'estraneo conta quanto quelli sui casi ammessi.
            'pagante_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('anagrafiche', 'id')->where(
                    fn ($q) => $q
                        ->whereIn('id', fn ($sub) => $sub->select('anagrafica_id')
                            ->from('anagrafica_condominio')
                            ->where('condominio_id', $this->route('condominio')?->id))
                        ->orWhereIn('id', fn ($sub) => $sub->select('anagrafica_immobile.anagrafica_id')
                            ->from('anagrafica_immobile')
                            ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
                            ->where('immobili.condominio_id', $this->route('condominio')?->id))
                ),
            ],

            // Scopati per condominio: StoreIncassoRateAction fa Cassa::findOrFail e
            // RataQuote::whereIn senza filtro di appartenenza, quindi un id altrui
            // arrivava fino alla scrittura contabile.
            'cassa_id' => [
                'required',
                // Il tipo è vincolato: un fondo è una partizione contabile del c/c,
                // non una cassa su cui incassare. Il form espone solo banca/contanti,
                // ma senza questo whereIn il vincolo via API non esisteva (beta.19).
                \Illuminate\Validation\Rule::exists('casse', 'id')
                    ->where('condominio_id', $this->route('condominio')?->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
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