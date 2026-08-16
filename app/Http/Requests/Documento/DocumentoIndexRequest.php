<?php

namespace App\Http\Requests\Documento;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class DocumentoIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * «Categoria» è una relazione: si ordina per il suo nome con una sottoquery correlata.
     * ⚠️ Fuori «Condomini» e «Anagrafiche», che sono elenchi.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'name'         => 'name',
            'categoria'    => fn () => \App\Models\CategoriaDocumento::select('name')
                ->whereColumn('categorie_documento.id', 'documenti.category_id'),
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
            'name'              => ['sometimes', 'string', 'max:255'],
            'category_id'       => ['sometimes', 'array'],
            'category_id.*'     => ['integer', 'exists:categorie_documento,id'],
            'condominio_id'     => ['nullable', 'array'], 
            'condominio_id.*'   => ['integer'],           
            'search'            => ['nullable', 'string'],

        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
