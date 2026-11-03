<?php

namespace App\Http\Requests\Settings;

use App\Enums\Role;
use App\Settings\GeneralSettings;
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
            'default_user_role'          => [
                'required',
                'string',
                // Chi non è amministratore non cambia questo ruolo e il modulo lo rimanda com'è: se il nome salvato
                // non è più un ruolo (eliminato o rinominato prima di questa versione, Coda 258), il resto delle
                // impostazioni si salva lo stesso e il nome resta com'era. L'amministratore, che lo sceglie, ne
                // sceglie uno che esiste.
                Rule::when(! $this->rimandaIlRuoloSenzaPoterloCambiare(), ['exists:roles,name']),
                $this->regolaRuoloDellaRegistrazione(),
            ],
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
     * Il ruolo della registrazione pubblica lo cambia solo un amministratore (Coda 258, decisione 134.1).
     *
     * È il ruolo che ricevono quelli che si registrano dal sito, e chi accetta un invito quando è «utente» o
     * «fornitore» (`UserRegistrationService`): sceglierlo vale quanto concederlo a chiunque arrivi. Chi gestisce le
     * impostazioni generali con un ruolo su misura salva il resto del modulo; la condizione è «il ruolo cambia»,
     * perché la pagina manda il modulo intero.
     */
    private function regolaRuoloDellaRegistrazione(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if ($this->user()?->hasRole(Role::AMMINISTRATORE->value) || $value === app(GeneralSettings::class)->default_user_role) {
                return;
            }

            $fail(__('validation.custom.default_user_role.only_admin'));
        };
    }

    /**
     * Chi salva non è amministratore e rimanda il ruolo dei nuovi iscritti com'è salvato?
     *
     * Allora la regola `exists` non si applica (Coda 258): un nome che non è più un ruolo non gli impedisce di
     * salvare il resto delle impostazioni, e il nome resta com'era. Un nome diverso lo rifiuta comunque
     * `regolaRuoloDellaRegistrazione()`; l'amministratore, che lo sceglie, ne sceglie uno che esiste.
     */
    private function rimandaIlRuoloSenzaPoterloCambiare(): bool
    {
        return ! $this->user()?->hasRole(Role::AMMINISTRATORE->value)
            && $this->input('default_user_role') === app(GeneralSettings::class)->default_user_role;
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