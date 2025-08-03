<?php

namespace App\Http\Requests\Evento;

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
            'visibility'              => 'nullable|in:public,private',
            'recurrence_frequency'    => 'nullable|in:daily,weekly,monthly,yearly',
            'recurrence_interval'     => 'nullable|integer',
            'recurrence_by_day'       => 'nullable|array',
            'recurrence_by_day.*'     => 'in:MO,TU,WE,TH,FR,SA,SU',
            'recurrence_by_month_day' => 'nullable|integer|min:1|max:31',
            'recurrence_until'        => 'nullable|date',
            'condomini_ids'           => 'nullable|array',
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
            'created_by' => $user->id
        ]);
    }
}


