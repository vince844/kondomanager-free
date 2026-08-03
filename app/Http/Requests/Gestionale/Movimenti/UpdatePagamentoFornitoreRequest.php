<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Enums\TipoDetrazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida i dati per la modifica di un pagamento fornitore confermato.
 *
 * Campi immutabili (non validati): fornitore_id, allocazioni fatture,
 * uuid, idempotency_key, numero_protocollo scrittura.
 */
class UpdatePagamentoFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La data inviata coincide con quella già salvata sul pagamento?
     *
     * Se sì, l'utente non sta introducendo una data futura: la sta solo riproponendo
     * perché il form la rimanda sempre. In quel caso il vincolo non deve scattare,
     * altrimenti un record storico con data futura diventa immodificabile per sempre.
     */
    private function dataPagamentoInvariata(): bool
    {
        $pagamento = $this->route('pagamento');

        if (! $pagamento instanceof \App\Models\Gestionale\PagamentoFornitore) {
            return false;
        }

        $inviata = $this->input('data_pagamento');
        if (! $inviata) {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse($inviata)->isSameDay($pagamento->data_pagamento);
        } catch (\Throwable) {
            return false;
        }
    }

    public function rules(): array
    {
        return [
            // Coerente con lo Store: una data di pagamento futura descrive un movimento
            // che non è ancora avvenuto (il service la copia in data_registrazione,
            // quindi produrrebbe una scrittura di giornale datata nel futuro).
            //
            // DEROGA per i record preesistenti: il vincolo si applica solo se la data
            // viene CAMBIATA. Fino a questa versione erano ammesse date future, e i DB
            // in produzione possono contenerne (assegni postdatati, bonifici
            // programmati, errori di battitura). Bloccare l'intero form impedirebbe
            // perfino di correggere quella data — o di aggiungere una nota — su record
            // che l'utente non ha creato oggi.
            'data_pagamento' => $this->dataPagamentoInvariata()
                ? 'required|date'
                : 'required|date|before_or_equal:'.DateHelper::oggiUtente(),
            'metodo_pagamento' => 'required|string',
            // Stesse regole dello Store (rafforzate in beta.19): conto del condominio
            // della rotta E cassa reale — i fondi sono partizioni contabili del c/c
            // e si consumano via giroconto, mai pagando. Senza questo vincolo la
            // modifica era la porta di servizio del varco chiuso sullo Store.
            'conto_corrente_id' => [
                'required', 'integer',
                Rule::exists('conti_contabili', 'id')->where('condominio_id', $this->route('condominio')?->id),
                Rule::exists('casse', 'conto_contabile_id')
                    ->where('condominio_id', $this->route('condominio')?->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            'importo_lordo_cents' => 'required|integer|min:1',
            'importo_ritenuta_cents' => 'nullable|integer|min:0',
            'importo_netto_cents' => 'required|integer|min:1',
            'importo_commissioni_cents' => 'nullable|integer|min:0',
            'causale_bonifico' => 'nullable|string|max:500',
            'riferimento_bancario' => 'nullable|string|max:100',
            'iban_beneficiario' => 'nullable|string|max:50',
            'note_override' => 'nullable|string|max:2000',

            // --- BONIFICO PARLANTE (DETRAZIONI FISCALI) ---
            'bonifico_parlante' => ['boolean'],
            'tipo_detrazione' => [
                'nullable',
                'required_if:bonifico_parlante,true',
                Rule::in(array_column(TipoDetrazione::cases(), 'value')),
            ],

            // --- FLAG SENTINELLA ---
            'allow_overdraft' => 'boolean',
            'allow_overpayment' => 'boolean',
            'iban_confermato_manualmente' => 'boolean',
            'conferma_duplicato_verificato' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'data_pagamento.required' => 'La data di pagamento è obbligatoria.',
            'data_pagamento.before_or_equal' => 'La data di pagamento non può essere futura.',
            'metodo_pagamento.required' => 'Il metodo di pagamento è obbligatorio.',
            'conto_corrente_id.required' => 'Il conto bancario è obbligatorio.',
            'importo_lordo_cents.required' => "L'importo lordo è obbligatorio.",
            'importo_netto_cents.required' => "L'importo netto è obbligatorio.",
            'tipo_detrazione.required_if' => 'Per il Bonifico Parlante è necessario specificare il tipo di detrazione.',
        ];
    }

    /**
     * `importo_ritenuta_cents` assente significa «non toccare», non «azzera».
     *
     * Qui c'era il contrario: un `merge(['importo_ritenuta_cents' => 0])` quando il campo
     * mancava. Poiché il servizio lo scriveva poi pari pari sul pagamento, un aggiornamento
     * che non rimandava la ritenuta la **azzerava** — e le allocazioni alle fatture non
     * sono nemmeno modificabili in questa schermata, quindi non c'era modo di dichiarare
     * legittimamente «questo pagamento non ha più ritenuta». Il flusso reale era salvo solo
     * perché `PagamentoEdit.vue:113` il campo lo rimanda sempre; nulla lo garantiva.
     *
     * Ora l'assenza è assenza: `PagamentoFornitoreService::aggiornaPagamento()` conserva il
     * valore registrato, e un valore presente che non coincide con quello viene rifiutato.
     */
}
