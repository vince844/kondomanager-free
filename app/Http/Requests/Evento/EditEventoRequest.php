<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Evento;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La modifica di un evento. L'autore (`created_by`) e l'approvazione (`is_approved`) si scrivono solo alla creazione (Coda 396): qui non hanno una regola e
 * non entrano in `validated()`. La visibilità si cambia solo con «Pubblica scadenze in agenda»: senza, resta quella che l'evento ha.
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
        $regole = [
            'title'                   => 'required|string|max:255',
            'description'             => 'nullable|string',
            'start_time'              => 'required|date',
            'end_time'                => 'required|date|after:start_time',
            'note'                    => 'nullable|string',
            'category_id'             => 'required|exists:categorie_evento,id',
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
        ];

        if ($this->user()->hasPermissionTo(Permission::PUBLISH_EVENTS->value)) {
            $regole['visibility'] = 'required|in:public,private,hidden';
        }

        return $regole;
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


