<?php

namespace App\Http\Requests\Comunicazione;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ComunicazioneIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /** ⚠️ Fuori «Condomini» e «Anagrafiche»: sono elenchi, non valori. */
    public static function colonneOrdinabili(): array
    {
        return [
            'subject'      => 'subject',
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
            'condominio_id'     => ['nullable', 'array'],
            'condominio_id.*'   => ['integer'],
            'search'            => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
