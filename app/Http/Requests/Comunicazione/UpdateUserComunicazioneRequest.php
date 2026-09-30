<?php

namespace App\Http\Requests\Comunicazione;

use App\Enums\Permission;
use App\Models\Comunicazione;
use App\Rules\CondominioDellUtente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @method bool merge(string $key)
 */
class UpdateUserComunicazioneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Prima delle regole (giro di sicurezza della 1.11.0-beta.39): `condomini_ids.*` ammette i
        // condomìni in cui il record è già, e validando per primi chiunque saprebbe in quali palazzi
        // sta un record che non può toccare (403 contro errore di validazione). Il Gate::authorize
        // nel controller resta: è ridondante ma innocuo, e lo cerca RotteSoloLoginTest.
        Gate::authorize('update', $this->route('comunicazione'));

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
            'subject'       => 'required|string|max:255',
            'description'   => 'required|string',
            'priority'      => 'required|string',
            'is_featured'   => 'required|boolean',
            'is_published'  => 'required|boolean',
            'is_approved'   => 'required|boolean',
            'is_private'    => 'sometimes|boolean',
            'created_by'    => 'required|exists:users,id',
            'condomini_ids' => ['required', 'array'],
            // Non basta che il condominio esista (giro di sicurezza della 1.11.0-beta.39, Coda 184):
            // deve essere uno dei condomìni dell'utente, altrimenti la comunicazione finisce nella
            // bacheca di un palazzo a cui non appartiene.
            // `integer` prima di tutto: un elemento-array arriverebbe a sync() come «chiave = id da
            // collegare», e la chiave non la controlla nessuno. I condomìni in cui la comunicazione
            // è già restano ammessi anche se l'autore non ne è più membro.
            'condomini_ids.*' => [
                'bail', 'integer', Rule::exists('condomini', 'id'),
                new CondominioDellUtente($this->condominiGiaCollegati()),
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function condominiGiaCollegati(): array
    {
        $comunicazione = $this->route('comunicazione');

        return $comunicazione instanceof Comunicazione
            ? $comunicazione->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    public function prepareForValidation(): void
    {

        $user = Auth::user();

        $this->merge([
            'created_by'   => $user->id,
            'is_approved'  => $user->hasPermissionTo(Permission::PUBLISH_COMUNICAZIONI->value),
            'is_published' => $user->hasPermissionTo(Permission::PUBLISH_COMUNICAZIONI->value)
        ]);
    }

    public function attributes()
    {
        return [
            'subject'       => __('validation.attributes.comunicazioni.subject'),
            'description'   => __('validation.attributes.comunicazioni.description'),
            'is_published'  => __('validation.attributes.comunicazioni.is_published'),
            'priority'      => __('validation.attributes.comunicazioni.priority'),
            'condomini_ids' => __('validation.attributes.comunicazioni.condomini_ids'),
        ];
    }
}
