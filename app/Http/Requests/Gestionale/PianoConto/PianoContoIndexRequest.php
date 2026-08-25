<?php

namespace App\Http\Requests\Gestionale\PianoConto;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class PianoContoIndexRequest extends FormRequest
{
    use OrdinaElenco;

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

    /**
     * Le colonne ordinabili dell'elenco piani dei conti.
     *
     * ⚠️ **Fuori «Budget & Composizione»**: è un totale aggregato dai conti figli, e ordinarlo
     * vuol dire sommare in una sottoquery. Si può fare, ma è una scelta da prendere.
     */
    /** @return array<string, string|\Closure> */
    public static function colonneOrdinabili(): array
    {
        return [
        'nome'           => 'nome',
        'gestione'       => fn () => \App\Models\Gestione::select('nome')
            ->whereColumn('gestioni.id', 'piani_conti.gestione_id'),
        'data_creazione' => 'created_at',
        ];
    }

    
}
