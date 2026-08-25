<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validazione input per la registrazione di un pagamento fornitore.
 *
 * Copre:
 *  - Dati anagrafici (fornitore, conto, esercizio)
 *  - Allocazioni multi-fattura con tipo pagamento/compensazione
 *  - Bonifico Parlante (detrazioni fiscali condominiali)
 *  - Flag sentinella: IBAN, duplicati, overdraft
 *  - Commissioni bancarie
 *
 * La logica di dominio complessa (overpayment, capienza, netting) è delegata
 * a PagamentoFornitoreService::validaInput() che opera DOPO il lock pessimistico.
 */
class StorePagamentoFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // --- DATI PRINCIPALI ---
            'fornitore_id'       => ['required', 'integer', 'exists:fornitori,id'],

            // Scopato per condominio: validaInput() controlla lo stato dell'esercizio
            // ma mai l'appartenenza, quindi un esercizio_id di un altro condominio
            // passava indisturbato fino alla scrittura.
            'esercizio_id'       => [
                'required', 'integer',
                Rule::exists('esercizi', 'id')->where('condominio_id', $this->route('condominio')?->id),
            ],
            // Non aveva nemmeno un exists: il service risolve la cassa con
            // Cassa::where('conto_contabile_id', ...) senza filtro, e verificaCapienza()
            // sommava i movimenti di quel conto chiunque ne fosse il proprietario.
            // Con un id altrui si pagava attingendo al saldo della banca di un altro
            // condominio.
            'conto_corrente_id'  => [
                'required', 'integer',
                Rule::exists('conti_contabili', 'id')->where('condominio_id', $this->route('condominio')?->id),
                // Il denaro esce solo da casse reali: i fondi sono partizioni
                // contabili del c/c e si consumano via giroconto, mai pagando.
                // Il form esclude già i fondi; questo chiude il varco via API (beta.19).
                Rule::exists('casse', 'conto_contabile_id')
                    ->where('condominio_id', $this->route('condominio')?->id)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],

            // Il sigillo per anzianità (30 giorni) si ancora a created_at, ma una
            // data_pagamento nel futuro resta comunque priva di senso contabile:
            // il movimento non è ancora avvenuto.
            'data_pagamento'     => ['required', 'date', 'before_or_equal:'.DateHelper::oggiUtente()],

            'metodo_pagamento'   => [
                'required',
                Rule::in([
                    MetodoPagamento::BONIFICO->value,
                    MetodoPagamento::CONTANTI->value,
                    MetodoPagamento::ASSEGNO->value,
                ]),
            ],

            // --- IBAN & CAUSALE ---
            'iban_beneficiario' => ['nullable', 'string', 'max:34'],
            'causale_bonifico'  => ['nullable', 'string', 'max:1000'],

            // --- COMMISSIONI E RITENUTE ---
            'importo_commissioni_cents' => ['nullable', 'integer', 'min:0'],
            'importo_ritenuta_cents'    => ['nullable', 'integer', 'min:0'],

            // --- ALLOCAZIONI (multi-fattura) ---
            'allocazioni'                        => ['required', 'array', 'min:1'],
            // validaInput() controlla solo che le fatture allocate siano OMOGENEE fra
            // loro, non che appartengano al condominio della rotta: si potevano pagare
            // fatture di un altro condominio (caso tutt'altro che teorico — lo stesso
            // fornitore lavora per più stabili).
            'allocazioni.*.fattura_id' => [
                'required', 'integer',
                Rule::exists('fatture_passive', 'id')->where('condominio_id', $this->route('condominio')?->id),
            ],
            'allocazioni.*.tipo'                  => [
                'required',
                Rule::in([
                    TipoAllocazioneFattura::PAGAMENTO->value,
                    TipoAllocazioneFattura::COMPENSAZIONE->value,
                ]),
            ],
            'allocazioni.*.importo_allocato_cents' => ['required', 'integer', 'min:1'],

            // --- BONIFICO PARLANTE (DETRAZIONI FISCALI) ---
            'bonifico_parlante'      => ['boolean'],
            'tipo_detrazione'        => [
                'nullable',
                'required_if:bonifico_parlante,true',
                Rule::in(array_column(TipoDetrazione::cases(), 'value')),
            ],
            'beneficiari_detrazione'                  => ['nullable', 'array'],
            'beneficiari_detrazione.*.codice_fiscale'  => ['nullable', 'string', 'max:16'],

            // --- FLAG SENTINELLA ---
            'allow_overdraft'               => ['boolean'],
            'allow_overpayment'             => ['boolean'],
            'iban_confermato_manualmente'    => ['boolean'],
            'conferma_duplicato_verificato' => ['boolean'],

            // --- AUDIT TRAIL OVERRIDE ---
            // Obbligatoria a livello UI (min 10 char) quando si bypassa un blocco.
            // Nullable lato server per non rompere submit senza override.
            'nota_override' => ['nullable', 'string', 'max:2000'],

            // --- IDEMPOTENCY ---
            'idempotency_key' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'allocazioni.required'                       => 'Seleziona almeno una fattura da pagare.',
            'allocazioni.min'                            => 'Seleziona almeno una fattura da pagare.',
            'allocazioni.*.importo_allocato_cents.min'   => 'L\'importo allocato deve essere almeno 1 centesimo.',
            'fornitore_id.required'                      => 'Seleziona il fornitore.',
            'conto_corrente_id.required'                 => 'Seleziona il conto di addebito.',
            'esercizio_id.required'                      => 'L\'esercizio contabile è obbligatorio.',
            'tipo_detrazione.required_if'                => 'Per il Bonifico Parlante è necessario specificare il tipo di detrazione.',
        ];
    }

    /**
     * Prepara i dati aggiungendo il condominio_id dal parametro di route.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'condominio_id' => $this->route('condominio')?->id,
        ]);
    }
}
