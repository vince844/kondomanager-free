<?php

namespace App\Http\Requests\Gestionale\Scala;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class ScalaIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * Le colonne ordinabili dell'elenco scale.
     *
     * «Palazzina» è una relazione: si ordina per il suo nome con una sottoquery correlata, non
     * con una `join()` — `scale.name` e `palazzine.name` si chiamano uguale.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'name'        => 'name',
            'description' => 'description',
            'palazzina'   => fn () => \App\Models\Palazzina::select('name')
                ->whereColumn('palazzine.id', 'scale.palazzina_id'),
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
            'name'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
