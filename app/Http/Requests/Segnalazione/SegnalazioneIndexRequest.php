<?php

namespace App\Http\Requests\Segnalazione;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class SegnalazioneIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * «Condominio» è una relazione singola: si ordina per il suo nome.
     * ⚠️ Fuori «Anagrafiche», che è un elenco.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'subject'      => 'subject',
            'condominio'   => fn () => \App\Models\Condominio::select('nome')
                ->whereColumn('condomini.id', 'segnalazioni.condominio_id'),
            'stato'        => 'stato',
            'priority'     => 'priority',
            'is_published' => 'is_published',
        ];
    }

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
        return array_merge([
            'page'              => ['sometimes', 'integer', 'min:1'],
            'per_page'          => ['sometimes', 'integer'],
            'subject'           => ['sometimes', 'string', 'max:255'],
            'priority'          => ['sometimes', 'array'],
            'priority.*'        => ['string', 'in:bassa,media,alta,urgente'],
            'stato'             => ['sometimes', 'array'],
            'stato.*'           => ['string', 'in:aperta,in lavorazione,chiusa'],
            'condominio_id'     => ['nullable', 'array'],
            'condominio_id.*'   => ['integer'],
            'search'            => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
