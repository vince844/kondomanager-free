<?php

namespace App\Http\Requests\Evento\Utenti;

use App\Enums\Permission;
use App\Enums\VisibilityStatus;
use App\Models\Evento;
use App\Rules\CondominioDellUtente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @method bool merge(string $key)
 */
class EditEventoRequest extends FormRequest
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
        Gate::authorize('update', $this->route('evento'));

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
            'title'                   => 'required|string|max:255',
            'description'             => 'nullable|string',
            'start_time'              => 'required|date',
            'end_time'                => 'required|date|after:start_time',
            'note'                    => 'nullable|string',
            'category_id'             => 'required|exists:categorie_evento,id',
            'visibility'              => 'required|in:public,private,hidden',
            'is_approved'             => 'required|boolean',
            'recurrence_frequency'    => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval'     => 'nullable|integer',
            'recurrence_by_day'       => 'nullable|array',
            'recurrence_by_day.*'     => 'in:MO,TU,WE,TH,FR,SA,SU',
            'recurrence_by_month_day' => 'nullable|integer|min:1|max:31',
            'recurrence_until'        => 'nullable|date',
            'condomini_ids'           => 'required|nullable|array',
            // Non basta che il condominio esista (giro di sicurezza della 1.11.0-beta.39, Coda 184):
            // deve essere uno dei condomìni dell'utente, altrimenti l'evento finisce nell'agenda di
            // un palazzo a cui non appartiene.
            // `integer` prima di tutto: un elemento-array arriverebbe a sync() come «chiave = id da
            // collegare», e la chiave non la controlla nessuno. I condomìni in cui l'evento è già
            // restano ammessi anche se l'autore non ne è più membro.
            'condomini_ids.*'         => [
                'bail', 'integer', 'exists:condomini,id',
                new CondominioDellUtente($this->condominiGiaCollegati()),
            ],
            'mode'                    => 'nullable|string',
            'occurrence_date'         => 'nullable|date',
            'created_by'              => 'required|exists:users,id',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function condominiGiaCollegati(): array
    {
        $evento = $this->route('evento');

        return $evento instanceof Evento
            ? $evento->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    public function prepareForValidation(): void
    {

        $user = Auth::user();

        $this->merge([
            'created_by' => $user->id,
            'visibility' => $user->hasPermissionTo(Permission::PUBLISH_EVENTS->value)
                ? VisibilityStatus::PUBLIC->value
                : VisibilityStatus::HIDDEN->value,
            'is_approved' => $user->hasPermissionTo(Permission::APPROVE_EVENTS->value)
        ]);
    }

    public function messages()
    {
        return [
            'start_time.after_or_equal' => __('validation.custom.evento.after_or_equal:today'),
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
            'title'            => __('validation.attributes.eventi.title'),
            'description'      => __('validation.attributes.eventi.description'),
            'start_time'       => __('validation.attributes.eventi.start_time'),
            'end_time'         => __('validation.attributes.eventi.end_time'),
            'category_id'      => __('validation.attributes.eventi.category_id'),
            'recurrence_until' => __('validation.attributes.eventi.recurrence_until'),
            'condomini_ids'    => __('validation.attributes.eventi.condomini_ids'),
        ];
    }
}
