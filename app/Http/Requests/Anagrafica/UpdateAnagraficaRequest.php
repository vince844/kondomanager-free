<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Anagrafica;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\UniqueEmailAcrossTables;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * @method bool filled(string $key)
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method bool has(string $key)
 */
class UpdateAnagraficaRequest extends FormRequest
{
    /**
     * Il permesso prima delle regole (decisione 137): chi non può modificare l'anagrafica della rotta riceve il rifiuto
     * di `AnagraficaPolicy::update`, con la sua frase, e nessun esito delle regole, nemmeno di quelle di unicità.
     * `AnagraficaController::update()` lo chiede di nuovo.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('anagrafica'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                => 'required|string|max:255',
            'indirizzo'           => 'required|string|max:255',
            'email'               => ['nullable','string','email','max:255',new UniqueEmailAcrossTables($this->anagrafica->id, 'anagrafiche')],
            'email_secondaria'    => ['nullable','string','email','max:255','different:email', new UniqueEmailAcrossTables($this->anagrafica->id, 'anagrafiche')],
            'pec'                 => ['nullable','string','lowercase','email','max:255',new UniqueEmailAcrossTables($this->anagrafica->id, 'anagrafiche')],
            'tipologia_documento' => 'nullable|string|max:255',
            'numero_documento'    => 'nullable|string|max:255',
            'codice_fiscale'      => ['nullable','string','max:255',Rule::unique('anagrafiche')->ignore($this->anagrafica->id)],
            'telefono'            => 'nullable|string|max:255',
            'cellulare'           => 'nullable|string|max:255',
            'luogo_nascita'       => 'nullable|string|max:255',
            'scadenza_documento'  => $this->scadenzaDocumentoCambiata() ? 'nullable|date|after:today' : 'nullable|date',
            'data_nascita'        => 'nullable|date|before:today',
            'note'                => 'nullable|string',
            // Come si convoca alle assemblee (art. 66 c. 3 disp. att.): vuoto, il programma lo propone dai dati; l'email semplice non è un mezzo. E il giorno in
            // cui la persona ha comunicato la sua PEC.
            'mezzo_convocazione'  => ['nullable', Rule::in(\App\Enums\Assemblee\MezzoConvocazione::valori())],
            'pec_comunicata_il'   => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . \App\Helpers\DateHelper::oggiUtente()],
            'condomini'           => ['nullable', 'array', Rule::exists('condomini', 'id')]
        ];
    }

    /**
     * La scadenza del documento è cambiata rispetto a quella salvata? (Coda 403)
     *
     * Solo una scadenza nuova deve essere dopo oggi: quella salvata, anche passata, si conserva com'è, e una persona con
     * il documento scaduto si salva aperta e salvata senza toccare niente, o cambiando soltanto il telefono. Le due date
     * si confrontano come giorni (`toDateString()`), la forma in cui `prepareForValidation()` riscrive quella mandata.
     */
    private function scadenzaDocumentoCambiata(): bool
    {
        $salvata = $this->route('anagrafica')?->getOriginal('scadenza_documento');

        return $this->input('scadenza_documento') !== ($salvata ? Carbon::parse($salvata)->toDateString() : null);
    }

    // Manipulate the date before validation
    protected function prepareForValidation()
    {
        $this->merge([
            'scadenza_documento' => $this->scadenza_documento 
                ? Carbon::parse($this->input('scadenza_documento'))->toDateString() 
                : null,
            'data_nascita' => $this->data_nascita 
                ? Carbon::parse($this->input('data_nascita'))->toDateString() 
                : null,
            'email' => $this->email
                ? Str::lower($this->input('email')) 
                : null,
            'email_secondaria' => $this->email_secondaria 
                ? Str::lower($this->input('email_secondaria')) 
                : null,
            'pec' => $this->pec 
                ? Str::lower($this->input('pec')) 
                : null,
        ]);
    }
    
    public function messages()
    {
        return [
            'mezzo_convocazione.in'            => 'Come si convoca: PEC, raccomandata, a mano o fax.',
            'pec_comunicata_il.before_or_equal' => 'Il giorno in cui ha comunicato la PEC non può essere dopo oggi.',
            'scadenza_documento.after' => __('validation.custom.anagrafica.after:today'),
            'data_nascita.before'      => __('validation.custom.anagrafica.before:today'),
            'codice_fiscale.unique'    => __('validation.custom.anagrafica.codice_fiscale.unique')
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
            'nome'               => __('validation.attributes.anagrafica.nome'),
            'indirizzo'          => __('validation.attributes.anagrafica.indirizzo'),
            'scadenza_documento' => __('validation.attributes.anagrafica.scadenza_documento'),
            'data_nascita'       => __('validation.attributes.anagrafica.data_nascita'),
            'codice_fiscale'     => __('validation.attributes.anagrafica.codice_fiscale')
        ];
    }
}
