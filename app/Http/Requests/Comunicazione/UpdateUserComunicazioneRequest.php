<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

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
            'condomini_ids' => ['required', 'array', Rule::exists('condomini', 'id')],
        ];
    }

    public function prepareForValidation(): void
    {

        $user = Auth::user();

        $this->merge([
            'created_by'   => $user->id,
            'is_approved'  => $user->hasPermissionTo('Pubblica comunicazioni'),
            'is_published' => $user->hasPermissionTo('Pubblica comunicazioni')
        ]);
    }

    public function attributes()
    {
        return [
            'subject' => __('validation.attributes.comunicazioni.subject'),
            'description' => __('validation.attributes.comunicazioni.description'),
            'is_published' => __('validation.attributes.comunicazioni.is_published'),
            'priority' => __('validation.attributes.comunicazioni.priority'),
            'condomini_ids' => __('validation.attributes.comunicazioni.condomini_ids'),
        ];
    }
}
