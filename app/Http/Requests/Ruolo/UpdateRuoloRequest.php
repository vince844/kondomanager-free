<?php

namespace App\Http\Requests\Ruolo;

use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateRuoloRequest extends FormRequest
{
    use ValidaConcessioneRuoli;

    /**
     * Le pagine dei ruoli sono dell'amministratore (`RolePolicy`, Coda 258, decisione 134.1): chi non lo è
     * riceve il rifiuto con il suo perché prima di qualunque errore sui campi.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', Role::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required','string','max:255', Rule::unique('roles')->ignore($this->ruoli, 'name')],
            'description' => 'required|string|max:255',
            // ⚠️ **La stessa regola dei permessi sull'utente, applicata al ruolo: nessuno mette in un
            // ruolo un permesso che non ha.** Oggi è una difesa (Coda 258, decisione 134.1):
            // queste pagine le apre solo l'amministratore (`authorize()` qui sopra), che ha tutti i
            // permessi. Resta perché un ruolo è un modo di dare permessi, e segue la stessa regola
            // della scheda utenti: vale anche se un giorno un permesso non fosse
            // dell'amministratore.
            'permissions' => ['sometimes', 'array', $this->regolaPermessiConcedibili()],
            'accessAdmin' => 'nullable|boolean'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'name'        => __('validation.attributes.ruoli.name'),
            'description' => __('validation.attributes.ruoli.description'),
        ];
    }
}
