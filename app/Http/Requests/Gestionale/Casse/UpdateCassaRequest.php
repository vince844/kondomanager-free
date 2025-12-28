<?php

namespace App\Http\Requests\Gestionale\Casse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCassaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Recuperiamo la cassa dalla rotta per escluderla dai check unique
        $cassa = $this->route('cassa'); 
        $condominio = $this->route('condominio');

        return [
            'nome' => [
                'required', 'string', 'max:255',
                // Unique nella tabella casse, ma solo per questo condominio ed escludendo la cassa attuale
                Rule::unique('casse')->where(function ($query) use ($condominio) {
                    return $query->where('condominio_id', $condominio->id);
                })->ignore($cassa->id),
            ],
            'descrizione' => 'nullable|string|max:255',
            'note'        => 'nullable|string',
            'saldo_iniziale' => 'nullable|string',
            
            // Il TIPO non dovrebbe essere modificabile, ma se lo passi, deve coincidere
            // Oppure semplicemente lo ignoriamo nel controller.
            
            // Dati Bancari (Solo se la cassa è di tipo banca)
            'iban'         => 'nullable|required_if:tipo,banca|size:27',
            'istituto'     => 'nullable|string|max:255',
            'bic'          => 'nullable|string|max:20',
            'intestatario' => 'nullable|string|max:255',
            'tipo_conto'   => ['nullable', Rule::in(['ordinario', 'dedicato', 'estero', 'postale', 'contabilita_speciale', 'altro'])],
            'tipo' => ['required', Rule::in(['contanti', 'banca', 'fondo', 'virtuale'])],
            
            'predefinito'  => 'boolean',
            
            'indirizzo' => 'nullable|string|max:255',
            'comune'    => 'nullable|string|max:255',
            'cap'       => 'nullable|string|max:10',
            'provincia' => 'nullable|string|max:5',
        ];
    }
}