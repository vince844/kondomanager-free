<?php

namespace App\Http\Requests\Fornitore\Categoria;

use App\Models\CategoriaFornitore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCategoriaFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unico sul nome: due categorie chiamate uguale non si distinguono in una tendina, e la
            // guardia della migrazione iniziale usa lo stesso criterio.
            'name'        => ['required', 'string', 'max:255', Rule::unique(CategoriaFornitore::class, 'name')],

            // ⚠️ **Facoltativa, a differenza delle categorie dei documenti.** Qui il momento d'uso è
            // dentro il modulo del fornitore, mentre si sta compilando altro: chiedere una
            // descrizione obbligatoria per aggiungere «Autospurgo» è attrito nel punto in cui serve
            // meno. Si aggiunge dopo, dalla pagina di gestione.
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'        => 'nome della categoria',
            'description' => 'descrizione',
        ];
    }
}
