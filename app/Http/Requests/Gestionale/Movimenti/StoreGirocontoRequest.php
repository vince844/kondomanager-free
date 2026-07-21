<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione di un giroconto — spostamento di liquidità fra casse.
 *
 * Tutti gli `exists` sono SCOPATI sul condominio della rotta. Le regole di
 * dominio (capienza, coppie di tipi, governance fondi vincolati, coerenza della
 * copertura collegata) vivono nell'Action, non qui: sono regole contabili, non
 * di forma, e devono valere anche quando l'Action è invocata da un service o da
 * un test senza passare dall'HTTP.
 */
class StoreGirocontoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId),
            ],
            'esercizio_id' => [
                'required',
                Rule::exists('esercizi', 'id')
                    ->where('condominio_id', $condominioId)
                    ->where('stato', 'aperto'),
            ],
            'cassa_origine_id' => [
                'required',
                Rule::exists('casse', 'id')->where('condominio_id', $condominioId),
            ],
            'cassa_destinazione_id' => [
                'required',
                'different:cassa_origine_id',
                Rule::exists('casse', 'id')->where('condominio_id', $condominioId),
            ],

            // NB: non chiamarlo 'data' — lato Inertia collide con il metodo form.data()
            // di useForm e il campo non si lega mai al v-model.
            'data_operazione' => ['required', 'date', 'before_or_equal:'.DateHelper::oggiUtente()],
            'causale' => ['required', 'string', 'min:3', 'max:255'],
            'importo' => ['required', 'numeric', 'gt:0'],

            // Conferma di una copertura sforo: la coerenza (stato, importo, fondo)
            // è verificata nell'Action.
            'fattura_copertura_id' => ['nullable', 'integer', 'exists:fattura_coperture,id'],
            'idempotency_key' => ['nullable', 'uuid'],

            // "Registra e nuovo": dopo il salvataggio si torna al modulo vuoto
            // invece che al dettaglio della scrittura.
            'crea_altro' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'esercizio_id.exists' => "L'esercizio selezionato non esiste o non è aperto.",
            'cassa_origine_id.exists' => 'La cassa di origine non appartiene a questo condominio.',
            'cassa_destinazione_id.exists' => 'La cassa di destinazione non appartiene a questo condominio.',
            'cassa_destinazione_id.different' => 'Origine e destinazione devono essere due casse diverse.',
            'importo.gt' => "L'importo deve essere maggiore di zero.",
            'causale.required' => 'La causale è obbligatoria: è ciò che rende leggibile il libro giornale.',
            'data_operazione.before_or_equal' => 'Non è possibile registrare un movimento con data futura.',
            'data_operazione.required' => 'La data operazione è obbligatoria.',
        ];
    }
}
