<?php

namespace App\Http\Requests\Fornitore\Categoria;

use App\Models\CategoriaFornitore;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCategoriaFornitoreRequest extends FormRequest
{
    /**
     * Il permesso prima delle regole (Coda 402): rinominare una categoria dei fornitori chiede «Modifica anagrafiche» a
     * `CategoriaFornitorePolicy`. Chi non lo ha riceve il rifiuto con la sua frase e nessun esito della regola di
     * unicità del nome. `CategoriaFornitoreController` lo chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('categoria'));
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', Rule::unique(CategoriaFornitore::class, 'name')->ignore($this->categoria->id)],
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
