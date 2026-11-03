<?php

namespace App\Http\Requests\Fornitore\Categoria;

use App\Models\CategoriaFornitore;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CreateCategoriaFornitoreRequest extends FormRequest
{
    /**
     * Il permesso prima delle regole (Coda 402): creare una categoria dei fornitori chiede «Crea anagrafiche» o «Modifica
     * anagrafiche» a `CategoriaFornitorePolicy`. Chi non li ha riceve il rifiuto con la sua frase e nessun esito della
     * regola di unicità del nome. `CategoriaFornitoreController` lo chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('create', CategoriaFornitore::class);
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
