<?php

namespace App\Http\Requests\Segnalazione;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

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
            'condominio_id' => ['required', 'integer', Rule::exists('condomini', 'id')],
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
            'is_approved'  => $user->hasPermissionTo('Pubblica segnalazioni'),
            'is_published' => $user->hasPermissionTo('Pubblica segnalazioni'),
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
