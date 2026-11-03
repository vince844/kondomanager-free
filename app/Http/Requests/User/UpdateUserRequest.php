<?php

namespace App\Http\Requests\User;

use App\Models\Anagrafica;
use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;


class UpdateUserRequest extends FormRequest
{
    use ValidaConcessioneRuoli;

    /**
     * La scheda la salva chi ha «Modifica utenti», e chi non è amministratore solo per un account nella sua
     * portata (`UserPolicy::update`, Coda 258, decisione 136.1). Qui e non solo nel controller: il rifiuto, con
     * il suo perché, arriva prima degli errori sui campi, che per un account fuori portata non hanno senso.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->utenti);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'email'       => [
                'required','string','lowercase','max:255','email', Rule::unique('users')->ignore($this->utenti, 'email'),
                // Coda 261: la propria email si cambia dal profilo, con la password attuale, non da qui.
                $this->regolaEmailDiSeStessi($this->utenti),
                // Coda 258: l'email di un account fuori dalla portata di chi salva la cambia solo un amministratore.
                $this->regolaEmailFuoriPortata($this->utenti),
            ],
            'roles'       => [
                'required',
                $this->regolaRuoloConcedibile($this->utenti),
                $this->regolaUltimoAmministratoreNonDegradabile($this->utenti),
                // Coda 258: e così i suoi ruoli, anche per toglierli.
                $this->regolaRuoliFuoriPortata($this->utenti),
            ],
            'permissions' => [
                'sometimes',
                'array',
                $this->regolaPermessiConcedibili($this->utenti),
                // Coda 258: e così i suoi permessi diretti, anche per toglierli.
                $this->regolaPermessiFuoriPortata($this->utenti),
            ],
            'anagrafica' => [
                'nullable',
                Rule::exists('anagrafiche', 'id'), 
                function ($attribute, $value, $fail) {

                    $anagrafica = Anagrafica::with('user')->find($value);

                    // L'anagrafica è già sua: si confrontano gli id. Con `==` fra i due oggetti `User`
                    // il confronto falliva appena le regole sui ruoli avevano caricato i ruoli del
                    // bersaglio, e la scheda non si salvava nemmeno rimandata com'era (decisione 130.3).
                    if ($anagrafica && $anagrafica->user_id !== null
                        && (int) $anagrafica->user_id === (int) $this->utenti->getKey()) {
                        return;
                    }

                    if ($anagrafica && $anagrafica->user) {
                        $fail("Questa anagrafica è già associata all'utente: " . $anagrafica->user->name);
                    }

                }
            ],
        ];
    }

    /**
     * Senza il campo `permissions` il salvataggio scrive l'elenco vuoto (`UserService::updateUser`): qui vale
     * come l'elenco vuoto anche per le regole, che altrimenti con `sometimes` non lo guarderebbero (Coda 258).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('permissions')) {
            $this->merge(['permissions' => []]);
        }
    }

    /**
    * Get custom attributes for validator errors.
    *
    * @return array<string, string>
    */
    public function attributes()
    {
        return [
            'roles' => __('validation.attributes.user.roles'),
        ];
    }
}
