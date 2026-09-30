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
class UserCreateSegnalazioneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Solo in modifica, e prima delle regole (giro di sicurezza della 1.11.0-beta.39): la regola
        // su `condominio_id` ammette il condominio attuale della segnalazione, e validando per
        // primi chiunque saprebbe in quale palazzo sta una segnalazione che non può toccare. In
        // creazione non c'è un record, e il permesso lo controlla il controller.
        $segnalazione = $this->route('segnalazione');

        if ($segnalazione instanceof Segnalazione) {
            Gate::authorize('update', $segnalazione);
        }

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
            'stato'         => 'required|string',
            'created_by'    => 'required|exists:users,id',
            'is_approved'   => 'required|boolean',
            'is_published'  => 'required|boolean',
            'is_private'    => 'sometimes|boolean',
            'condominio_id' => [
                'required',
                'integer',
                Rule::exists('condomini', 'id'),
                // The reporter may only open a segnalazione for a condominio they belong to, not
                // for any existing building id. Updating one already filed in a condominio the
                // author no longer belongs to (the admin can remove a pivot row at any time) is
                // still allowed as long as the building doesn't change: this is the same request
                // class for store() and update(), and the author's own edit form pre-fills this
                // field with the record's current value — rejecting it would corrupt data (the
                // only way through the UI would be moving the segnalazione into another building).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $user = Auth::user();
                    $belongs = $user?->anagrafica?->condomini()->whereKey($value)->exists() ?? false;

                    if ($belongs) {
                        return;
                    }

                    $segnalazione = $this->route('segnalazione');
                    if ($segnalazione instanceof Segnalazione && (int) $value === (int) $segnalazione->condominio_id) {
                        return;
                    }

                    $fail(__('validation.exists', [
                        'attribute' => __('validation.attributes.segnalazioni.condominio_id'),
                    ]));
                },
            ],
        ];
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
            'created_by'   => $user->id,
            'is_approved'  => $user->hasPermissionTo(Permission::PUBLISH_SEGNALAZIONI->value),
            'is_published' => $user->hasPermissionTo(Permission::PUBLISH_SEGNALAZIONI->value),
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
            'priority'      => __('validation.attributes.segnalazioni.priority'),
            'stato'         => __('validation.attributes.segnalazioni.stato'),
            'condominio_id' => __('validation.attributes.segnalazioni.condominio_id'),
        ];
    }
}
