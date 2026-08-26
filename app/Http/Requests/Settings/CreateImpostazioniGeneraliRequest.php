<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateImpostazioniGeneraliRequest extends FormRequest
{
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
        return [
            'user_frontend_registration' => 'required|boolean',
            'force_comment_moderation'   => 'required|boolean',
            'language'                   => 'required|in:it,en,pt,es',
            'app_name'                   => 'required|string|max:255',
            'open_condominio_on_login'   => 'required|boolean',
            'default_user_role'          => ['required', 'string', 'exists:roles,name'],
            // Qui `Rule::in` è al posto giusto: è un modulo che l'amministratore compila, non un
            // parametro d'indirizzo, e un valore fuori lista va detto invece che corretto in
            // silenzio. Il 100 resta fuori per via del portale, che non ha un selettore.
            'default_per_page'           => [
                'required',
                'integer',
                Rule::in(array_filter(config('pagination.consentite') ?? [], fn (int $v) => $v <= 50)),
            ],
            'default_condominio_id'      => [
                'required_if:open_condominio_on_login,true',
                'nullable',
                'exists:condomini,id',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_condominio_id.required_if' => 'Devi selezionare un condominio.',
            'default_condominio_id.exists' => 'Il condominio selezionato non è valido.',
        ];
    }
}