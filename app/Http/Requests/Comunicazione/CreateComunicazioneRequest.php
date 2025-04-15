<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        return [
            'subject'       => 'required|string|max:255',
            'description'   => 'required|string',
            'priority'      => 'required|string',
            'can_comment'   => 'required|boolean',
            'is_featured'   => 'required|boolean',
            'is_published'  => 'required|boolean',
            'created_by'    => 'required|exists:users,id',
            'is_approved'   => 'required|boolean',
            'anagrafiche'   => ['sometimes', 'array', Rule::exists('anagrafiche', 'id')],
            'condomini_ids' => ['required', 'array', Rule::exists('condomini', 'id')],
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
            'is_approved' => $user->hasPermissionTo('Pubblica comunicazioni')
        ]);
    }
}
