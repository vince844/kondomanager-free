<?php

namespace App\Http\Requests\Evento;

use Illuminate\Foundation\Http\FormRequest;

class EventoIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'          => ['sometimes', 'integer', 'min:1'],
            'per_page'      => ['sometimes', 'integer', 'min:1', 'max:100'],
            'title'         => ['sometimes', 'string', 'max:255'],
            'category_id'   => ['sometimes', 'array'],
            'category_id.*' => ['integer', 'exists:categorie_evento,id'],
            'search'        => ['nullable', 'string', 'max:255'],
            'date_from'     => ['sometimes', 'date', 'date_format:Y-m-d'],
            'date_to'       => ['sometimes', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        
        // Ensure array for category_id
        if (isset($data['category_id']) && !is_array($data['category_id'])) {
            $data['category_id'] = [$data['category_id']];
        }
        
        return $data;
    }
}