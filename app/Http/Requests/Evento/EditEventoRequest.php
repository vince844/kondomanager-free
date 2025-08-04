<?php

namespace App\Http\Requests\Evento;

use App\Enums\Permission;
use App\Enums\VisibilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

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
            'condomini_ids.*'         => 'exists:condomini,id',
            'anagrafiche'             => 'nullable|array',
            'anagrafiche.*'           => 'exists:anagrafiche,id',
            'mode'                    => 'nullable|string',
            'occurrence_date'         => 'nullable|date',
            'created_by'              => 'required|exists:users,id',
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
            'visibility'       => __('validation.attributes.eventi.visibility'),
        ];
    }
}


