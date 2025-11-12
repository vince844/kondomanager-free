<?php

namespace App\Http\Requests\Gestionale\PianoRate;

use Illuminate\Foundation\Http\FormRequest;

class CreatePianoRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gestione_id'          => ['required', 'exists:gestioni,id'],
            'nome'                 => ['required', 'string', 'max:255'],
            'descrizione'          => ['nullable', 'string'],
            'metodo_calcolo'       => ['required', 'in:proporzionale,tabella,manuale'],
            'numero_rate'          => ['required', 'integer', 'min:1', 'max:36'],
            'giorno_scadenza'      => ['nullable', 'integer', 'min:1', 'max:31'],
           /*  'data_inizio'          => ['required', 'date'], */
            'note'                 => ['nullable', 'string'],
            'recurrence_enabled'   => ['sometimes', 'boolean'],
            'recurrence_frequency' => ['nullable', 'in:DAILY,WEEKLY,MONTHLY,YEARLY'],
            'recurrence_interval'  => ['nullable', 'integer', 'min:1'],
            'recurrence_by_day'    => ['nullable', 'array'],
            'recurrence_until'     => ['nullable', 'date'],
            'genera_subito'        => ['sometimes', 'boolean'],
        ];
    }
}
