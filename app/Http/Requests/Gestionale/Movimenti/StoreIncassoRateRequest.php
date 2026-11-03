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
            //
            // `integer` davanti, dalla Fase 1-bis della beta.40 (reperto R5): `exists` su un **array** controlla ogni
            // elemento e passa, e poi `(int)` di un array non vuoto vale 1 — l'incasso si legava alla prima anagrafica
            // del database, anche di un altro palazzo.
            'pagante_id' => ['required', 'integer', $this->personaDelCondominio()],

            // Coda 167, 1.11.0-beta.40 (decisione 30): chi ha versato davvero, se non è la posizione — il compratore che
            // salda l'arretrato del venditore, il figlio che paga per la madre. La stessa regola del pagante, e non
            // «qualunque anagrafica»: le anagrafiche sono comuni a tutti i condomìni, e un id qualunque legherebbe un
            // incasso di questo condominio a una persona di un altro. Il figlio senza unità si associa prima al
            // condominio dall'anagrafica, ed entra dal pivot.
            'versato_da_id' => ['nullable', 'integer', $this->personaDelCondominio()],

            // Decisione 30.7 (Fase 1-bis della beta.40): con «Versato da», del denaro versato e un credito della posizione
            // in gioco, le strade legittime sono due — il credito resta al debitore (prima i soldi versati) o si usa
            // adesso (e la parte in più va a chi ha versato). Dipende da un accordo fra le due persone che il programma
            // non conosce: sceglie l'amministratore, e senza la sua scelta l'incasso non parte. Senza «Versato da», o
            // senza denaro, le due strade coincidono e la domanda non si pone.
            'credito_prima' => [
                'nullable', 'boolean',
                \Illuminate\Validation\Rule::requiredIf(fn () => $this->sceltaSulCreditoNecessaria()),
            ],

            // Decisione 30.8: la rata di chi ha versato su cui va la parte in più, scelta dall'amministratore. Deve essere
            // **sua**, di una rata **emessa** (una bozza bloccherebbe il ricalcolo del piano, precisazione 30.3) e di
            // **questo** condominio. Senza «Versato da» non c'è nessuna quota che la regola accetti.
            'quota_parte_in_piu_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('rate_quote', 'id')
                    ->where('anagrafica_id', is_scalar($this->input('versato_da_id')) ? (int) $this->input('versato_da_id') : 0)
                    ->where(fn ($q) => $q->where('importo', '>', 0))
                    ->whereIn('rata_id', fn ($q) => $q->select('rate.id')->from('rate')
                        ->join('piani_rate', 'rate.piano_rate_id', '=', 'piani_rate.id')
                        ->where('piani_rate.condominio_id', $this->route('condominio')?->id)
                        ->where('rate.stato', 'emessa')),
            ],

            // Decisione 30.11: il credito di una gestione può coprire rate di un'altra? Lo sceglie l'amministratore; il
            // motore lo pretende quando cambierebbe il risultato (`PianoCreditoIncasso`, `serve_scelta`).
            'credito_fra_gestioni' => ['nullable', 'boolean'],

            // L'attività della posta in arrivo che l'incasso chiude (arrivo da una segnalazione). Il perimetro del
            // condominio lo applica `IncassoRateController::store()` cercandola fra le attività di questo condominio:
            // un'attività sparita nel frattempo non deve impedire di registrare l'incasso.
            'related_task_id' => ['nullable', 'integer'],

            // Scopati per condominio: StoreIncassoRateAction fa Cassa::findOrFail e
            // RataQuote::whereIn senza filtro di appartenenza, quindi un id altrui
            // arrivava fino alla scrittura contabile.
            // `integer` su ogni id che arriva dal browser (Coda 208, chiusa nella 1.11.0-beta.40, decisione 30.17): un elenco
            // passava `exists` elemento per elemento, e poi `(int)` di un elenco vale 1 — la gestione n.1 di un altro
            // palazzo, o una pagina d'errore. Lo stesso schema del reperto R5 su `pagante_id`.
            'cassa_id' => [
                'required',
                'integer',
                // Il tipo è vincolato: un fondo è una partizione contabile del c/c,
                // non una cassa su cui incassare. Il form espone solo banca/contanti,
                // ma senza questo whereIn il vincolo via API non esisteva (beta.19).
                \Illuminate\Validation\Rule::exists('casse', 'id')
                    ->where('condominio_id', $this->route('condominio')?->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            'gestione_id' => [
                'nullable',
                'integer',
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
                'integer',
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

    public function messages(): array
    {
        return [
            'credito_prima.required' => 'Il pagante ha un credito e il versamento è di un\'altra persona: scegli se il credito si usa adesso o resta suo.',
            'quota_parte_in_piu_id.exists' => 'La rata scelta per la parte in più deve essere di chi ha versato, emessa e di questo condominio.',
        ];
    }

    /**
     * La scelta della decisione 30.7 serve quando c'è «Versato da» (diverso dal pagante), del denaro versato e almeno
     * una riga di credito.
     */
    private function sceltaSulCreditoNecessaria(): bool
    {
        $versatoDa = $this->input('versato_da_id');
        $pagante = $this->input('pagante_id');

        if (! is_scalar($versatoDa) || (int) $versatoDa === 0 || (is_scalar($pagante) && (int) $versatoDa === (int) $pagante)) {
            return false;
        }

        if (! is_numeric($this->input('importo_totale')) || (float) $this->input('importo_totale') <= 0) {
            return false;
        }

        return collect((array) $this->input('dettaglio_pagamenti', []))
            ->contains(fn ($riga) => is_array($riga) && is_numeric($riga['importo'] ?? null) && (float) $riga['importo'] < 0);
    }

    /**
     * Una persona di questo condominio: nel pivot `anagrafica_condominio`, oppure titolare di un'unità qui (vedi il
     * commento su `pagante_id` per il perché dei due criteri).
     */
    private function personaDelCondominio(): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('anagrafiche', 'id')->where(
            fn ($q) => $q
                ->whereIn('id', fn ($sub) => $sub->select('anagrafica_id')
                    ->from('anagrafica_condominio')
                    ->where('condominio_id', $this->route('condominio')?->id))
                // Senza filtro su `attivo`, di proposito: anche un titolare cessato può
                // pagare una rata di quando c'era. Censito nell'inventario B1 (§4.3 del
                // progetto sul subentro) come punto che NON passa dal risolutore.
                ->orWhereIn('id', fn ($sub) => $sub->select('anagrafica_immobile.anagrafica_id')
                    ->from('anagrafica_immobile')
                    ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
                    ->where('immobili.condominio_id', $this->route('condominio')?->id))
        );
    }
}