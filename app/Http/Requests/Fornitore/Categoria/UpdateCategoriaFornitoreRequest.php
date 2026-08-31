<?php

namespace App\Http\Requests\Fornitore\Categoria;

use App\Models\CategoriaFornitore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoriaFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
