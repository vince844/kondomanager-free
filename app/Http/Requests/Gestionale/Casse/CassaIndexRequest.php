<?php

namespace App\Http\Requests\Gestionale\Casse;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class CassaIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * Le colonne ordinabili dell'elenco casse e fondi.
     *
     * ⚠️ **Fuori «Saldo Attuale»**, che non è un campo: è calcolato sommando i movimenti, e
     * ordinarlo richiederebbe una sottoquery di somma su tutta la contabilità della cassa. Si
     * può fare, ma è una scelta sul costo della query, non un default.
     *
     * «Saldo Apertura» invece è una colonna vera e si ordina.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'tipo'               => 'tipo',
            'nome'               => 'nome',
            'saldo_iniziale_raw' => 'saldo_iniziale',
            'attiva'             => 'attiva',
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
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'nome'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
