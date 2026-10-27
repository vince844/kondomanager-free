<?php

namespace App\Http\Requests\Segnalazione;

use App\Enums\Permission;
use App\Models\Segnalazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @method bool merge(string $key)
 */
class CreateSegnalazioneRequest extends FormRequest
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
            // Solo in modifica: la casella «avvisa i destinatari». In creazione l'avviso parte
            // sempre, quindi il campo non arriva e `nullable` lo lascia passare.
            'avvisa_destinatari' => ['nullable', 'boolean'],
            'subject'       => 'required|string|max:255',
            'description'   => 'required|string',
            'priority'      => 'required|string',
            'stato'         => 'required|string',
            'can_comment'   => 'required|boolean',
            'is_featured'   => 'required|boolean',
            'is_published'  => 'required|boolean',
            'anagrafiche'   => ['sometimes', 'array', Rule::exists('anagrafiche', 'id'), $this->residentiDiUnaPrivata(...)],
            'condominio_id' => ['required', 'integer', Rule::exists('condomini', 'id')],
        ];

        // Coda 303: l'autore e l'approvazione si scrivono solo alla creazione. In modifica le due
        // chiavi non hanno regola, quindi non entrano in `validated()` neanche se il corpo le porta:
        // l'autore resta chi ha aperto la segnalazione (chi modifica sta in `updated_by`, che mette
        // il controller), e l'approvazione cambia solo dall'interruttore, che avvisa il creatore
        // (decisione 127.2).
        if (! $this->inModifica()) {
            $regole['created_by'] = 'required|exists:users,id';
            $regole['is_approved'] = 'required|boolean';
        }

        return $regole;
    }

    /**
     * Coda 304, decisione 127.4: i residenti di una segnalazione privata non si tolgono tutti dal
     * modulo. La privacy la decide `is_private`, e una privata senza residenti la vede comunque solo
     * il suo autore; ma il modulo che la svuota farebbe credere all'amministratore di averla resa di
     * tutto il condominio. Per renderla pubblica c'è il comando apposito, con la conferma.
     *
     * La regola guarda il **cambiamento**: scatta solo se in questo salvataggio i residenti passano da
     * almeno uno a nessuno. Una privata che non ne aveva già (un dato che può esistere nel database)
     * si apre e si salva com'è; resta privata, del suo autore.
     *
     * Il messaggio indica una strada che chi salva ha: «Rendi pubblica» a chi lo può usare
     * (`SegnalazionePolicy::rendiPubblica`), e chi lo può fare a chi non lo può usare.
     */
    private function residentiDiUnaPrivata(string $attribute, mixed $value, \Closure $fail): void
    {
        $segnalazione = $this->route('segnalazione');

        if (! ($segnalazione instanceof Segnalazione) || ! $segnalazione->is_private || ! empty($value)) {
            return;
        }

        if (! $segnalazione->anagrafiche()->exists()) {
            return;
        }

        $fail(Gate::forUser($this->user())->allows('rendiPubblica', $segnalazione)
            ? __('segnalazioni.privacy.residents_required')
            : __('segnalazioni.privacy.residents_required_ask_approver'));
    }

    /** La stessa richiesta serve la creazione e la modifica: in modifica la rotta porta il record. */
    private function inModifica(): bool
    {
        return $this->route('segnalazione') instanceof Segnalazione;
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
            'created_by'  => $user->id,
            'is_approved' => $user->hasPermissionTo(Permission::PUBLISH_SEGNALAZIONI->value)
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
            'subject'       => __('validation.attributes.segnalazioni.subject'),
            'description'   => __('validation.attributes.segnalazioni.description'),
            'is_published'  => __('validation.attributes.segnalazioni.is_published'),
            'priority'      => __('validation.attributes.segnalazioni.priority'),
            'stato'         => __('validation.attributes.segnalazioni.stato'),
            'condominio_id' => __('validation.attributes.segnalazioni.condominio_id'),
        ];
    }
}
