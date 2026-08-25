<?php

namespace App\Http\Requests\Fornitore\Documento;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class FornitoreDocumentoIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /** Tutte e quattro corrispondono a un campo vero. */
    public static function colonneOrdinabili(): array
    {
        return [
            'name'         => 'name',
            'size'         => 'file_size',
            'created_at'   => 'created_at',
            'is_published' => 'is_published',
        ];
    }

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
        return array_merge([
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
