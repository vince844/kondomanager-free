<?php

namespace App\Http\Requests\User;

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
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
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'permissions' => ['sometimes', 'array'],
            'anagrafica' => [
                'nullable',
                Rule::exists('anagrafiche', 'id'), 
                function ($attribute, $value, $fail) {

                    $anagrafica = Anagrafica::with('user')->find($value);
                    
                    if ($anagrafica && $anagrafica->user) {
                        $fail("Questa anagrafica è già associata all'utente: " . $anagrafica->user->name);
                    }
                    
                }
            ],

        ];
    }

}
