<?php

namespace App\Http\Requests\Evento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 */
class CreateEventoRequest extends FormRequest
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

            'title'                => 'required|string|max:255',
            'description'          => 'required|string',
            'start_time'           => 'required|date|after_or_equal:today',
            'end_time'             => 'nullable|date|after_or_equal:start_time',
            'note'                 => 'sometimes|nullable|string',
            'recurrence_frequency' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval'  => 'nullable|integer|min:1',
            'recurrence_by_day'    => 'nullable|array',
            'recurrence_by_day.*'  => 'string|in:MO,TU,WE,TH,FR,SA,SU',
            'recurrence_until'     => 'nullable|date|after:start_time',
            'anagrafiche'          => ['nullable', 'array'],
            'anagrafiche.*'        => ['integer', Rule::exists('anagrafiche', 'id')],
            'condomini_ids'        => ['required', 'array'],
            'condomini_ids.*'      => ['integer', Rule::exists('condomini', 'id')],
            'created_by'           => ['integer', Rule::exists('users', 'id')],
            'category_id'          => ['required', 'integer', Rule::exists('categorie_evento', 'id')],
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
