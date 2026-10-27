<?php

namespace App\Http\Requests\Comunicazione;

use App\Enums\Permission;
use App\Models\Comunicazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 */
class CreateComunicazioneRequest extends FormRequest
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
        $regole = [
            'subject'         => 'required|string|max:255',
            'description'     => 'required|string',
            'priority'        => 'required|string',
            'can_comment'     => 'required|boolean',
            'is_featured'     => 'required|boolean',
            'is_published'    => 'required|boolean',
            'anagrafiche'     => ['nullable', 'array'],
            'anagrafiche.*'   => ['integer', Rule::exists('anagrafiche', 'id')],
            'condomini_ids'   => ['required', 'array'],
            'condomini_ids.*' => ['integer', Rule::exists('condomini', 'id')],
            // Solo in modifica: la casella «avvisa i destinatari». In creazione l'avviso parte
            // sempre, quindi il campo non arriva e `nullable` lo lascia passare.
            'avvisa_destinatari' => ['nullable', 'boolean'],
        ];

        // Coda 303: l'autore e l'approvazione si scrivono solo alla creazione. In modifica le due
        // chiavi non hanno regola e non entrano in `validated()`: chi modifica sta in `updated_by`,
        // e l'approvazione cambia solo dall'interruttore, che avvisa il creatore (decisione 127.2).
        if (! $this->inModifica()) {
            $regole['created_by'] = 'required|exists:users,id';
            $regole['is_approved'] = 'required|boolean';
        }

        return $regole;
    }

    /**
     * Coda 304, la gemella di `CreateSegnalazioneRequest::residentiDiUnaPrivata()`: i destinatari di
     * una comunicazione privata non si tolgono tutti dal modulo. La privacy la decide `is_private`, e
     * una privata senza destinatari la vede solo chi l'ha scritta (oltre all'amministratore); il
     * salvataggio che la svuota faceva credere di averla mandata a tutto il palazzo. Per le
     * comunicazioni non c'è un comando «Rendi pubblica»: la frase dice il fatto, non un pulsante.
     *
     * La regola guarda il **cambiamento**, come la gemella: scatta solo se in questo salvataggio i
     * destinatari passano da almeno uno a nessuno. Sta qui e non fra le regole del campo perché
     * `nullable` lascerebbe passare un elenco nullo, che il controller tratta come vuoto; senza il
     * campo non si tocca niente (Coda 307), e la regola tace.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $comunicazione = $this->route('comunicazione');

            if (! ($comunicazione instanceof Comunicazione) || ! $comunicazione->is_private
                || ! array_key_exists('anagrafiche', $this->all()) || ! empty($this->input('anagrafiche'))) {
                return;
            }

            if (! $comunicazione->anagrafiche()->exists()) {
                return;
            }

            $validator->errors()->add('anagrafiche', __('comunicazioni.privacy.recipients_required'));
        }];
    }

    /** La stessa richiesta serve la creazione e la modifica: in modifica la rotta porta il record. */
    private function inModifica(): bool
    {
        return $this->route('comunicazione') instanceof Comunicazione;
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    public function prepareForValidation(): void
    {
        if ($this->inModifica()) {
            return;
        }

        $user = Auth::user();

        $this->merge([
            'created_by' => $user->id,
            'is_approved' => $user->hasPermissionTo(Permission::PUBLISH_COMUNICAZIONI->value) 
            ? true 
            : false,
        ]);
    }

    /**
    * Get custom attributes for validator errors.
    *
    * @return array<string, string>
    */
    public function attributes()
    {
        return [
            'subject'       => __('validation.attributes.comunicazioni.subject'),
            'description'   => __('validation.attributes.comunicazioni.description'),
            'is_published'  => __('validation.attributes.comunicazioni.is_published'),
            'priority'      => __('validation.attributes.comunicazioni.priority'),
            'stato'         => __('validation.attributes.comunicazioni.stato'),
            'condomini_ids' => __('validation.attributes.comunicazioni.condomini_ids'),
        ];
    }
}
