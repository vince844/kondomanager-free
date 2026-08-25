<?php

namespace App\Http\Requests\Gestionale\Casse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCassaRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
             // Dati Generali Cassa
            'nome'           => 'required|string|max:255',
            'descrizione'    => 'nullable|string|max:255',
            'tipo'           => 'required|in:contanti,banca,fondo,virtuale',
            'saldo_iniziale' => 'nullable|string',
            'note'           => 'nullable|string',
            
            // Dati Bancari (Richiesti SOLO se tipo == banca)
            // ⚠️ **L'IBAN non è obbligatorio, e dalla beta.75 non lo è più.** Lo era con
            // `required_if:tipo,banca`, e bloccava la creazione del conto corrente — cioè il
            // **primo** passo contabile di un condominio. Chi rileva uno stabile senza passaggio
            // di consegne l'IBAN spesso non ce l'ha ancora, e restava fermo prima di poter
            // registrare qualunque cosa. Serve a compilare i bonifici, non a tenere la
            // contabilità: quando c'è viene validato nella forma, quando manca si aggiunge dopo.
            'iban'         => 'nullable|size:27', 
            'istituto'     => 'nullable|string|max:255',
            'bic'          => 'nullable|string|max:20',
            'intestatario' => 'nullable|string|max:255',
            
            // Validazione enum database per tipo_conto
            'tipo_conto' => [
                'nullable',
                Rule::in(['ordinario', 'dedicato', 'estero', 'postale', 'contabilita_speciale', 'altro'])
            ],
            
            'predefinito' => 'boolean',
            
            // Dati Indirizzo Filiale
            'indirizzo' => 'nullable|string|max:255',
            'comune'    => 'nullable|string|max:255',
            'cap'       => 'nullable|string|max:10',
            'provincia' => 'nullable|string|max:5',

            // --- NUOVE REGOLE: GOVERNANCE FONDI ---
            'sottotipo_fondo' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo') === 'fondo'),
                Rule::in(['generico', 'vincolato_lavori', 'tfr', 'morosita'])
            ],
            
            'vincolo_descrizione'   => 'nullable|string|max:255',
            'is_override_assemblea' => 'boolean',
            
            // IL CUORE DELL'AUDIT: Motivazione obbligatoria solo se sbloccano un fondo vincolato
            'motivazione_override'  => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => 
                    $this->input('tipo') === 'fondo' && 
                    $this->input('sottotipo_fondo') !== 'generico' && 
                    $this->input('is_override_assemblea') === true
                )
            ],
        ];
    }

    /**
     * Messaggi di errore personalizzati per una UX perfetta in Inertia/Vue.
     */
    public function messages(): array
    {
        return [
            'sottotipo_fondo.required' => 'Devi specificare la destinazione d\'uso del fondo di riserva.',
            'motivazione_override.required' => 'Inserisci gli estremi della delibera o la motivazione per sbloccare questo fondo vincolato.',
        ];
    }
}