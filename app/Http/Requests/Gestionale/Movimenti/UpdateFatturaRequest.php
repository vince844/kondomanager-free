<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida i dati per la modifica di una fattura passiva aperta.
 *
 * Differenze rispetto a StoreFatturaRequest:
 *  - fornitore_id e tipo_documento sono immutabili: non accettati né validati
 *  - is_pregresso non accettato (fatture pregresse non modificabili)
 *  - stato_approvazione non accettato (non modificabile con update)
 *  - dati_extra.override_budget non accettato (sforo_motivato blocca a monte)
 *  - Le coperture sopravvenienza non sono accettate (bloccate a monte)
 *  - Obbligatoria la gestione_id per ricostruire la scrittura contabile
 */
class UpdateFatturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where('condominio_id', $this->route('condominio')->id),
            ],

            'numero_documento'   => 'required|string|max:50',
            'data_documento'     => 'required|date',
            'data_scadenza'      => 'required|date',
            'modalita_pagamento' => 'required|string',
            'iban_fornitore'     => 'nullable|string',
            'conto_corrente_id'  => 'nullable|exists:conti_contabili,id',
            'applica_ritenuta'   => 'nullable|boolean',
            'dati_extra.fiscal.motivo_esclusione_ritenuta' => [
                'nullable', 'string', Rule::in(array_column(MotivoEsclusioneRitenuta::cases(), 'value')),
                'required_if:applica_ritenuta,false',
            ],
            'dati_extra.fiscal.motivo_esclusione_ritenuta_note' => [
                'nullable', 'string', 'max:500',
                'required_if:dati_extra.fiscal.motivo_esclusione_ritenuta,'.MotivoEsclusioneRitenuta::OVERRIDE_MANUALE->value,
            ],
            'dati_extra.fiscal.conferma_codice_tributo_mancante' => 'nullable|boolean',

            'righe'                      => 'required|array|min:1',
            'righe.*.descrizione'        => 'required|string',
            'righe.*.importo_imponibile' => 'required|numeric',
            'righe.*.aliquota_iva'       => 'required|numeric|min:0|max:100',
            'righe.*.conto_id'           => 'nullable|exists:conti,id',
            'righe.*.immobile_id'        => 'nullable|exists:immobili,id',
            'righe.*.concorre_base_ritenuta' => 'nullable|boolean',
            'righe.*.natura_riga_ritenuta' => [
                'nullable', 'string', Rule::in(array_column(NaturaRigaRitenuta::cases(), 'value')),
            ],

            // ⚠️ Niente più 'file' salvato qui (Coda 102, 1.11.0-beta.12): allegare passava da
            // aggiornaFattura(), che riscrive la contabilità per un file e cancellava ogni
            // allegato esistente. L'upload vive ora in StoreFatturaDocumentoRequest, su una
            // rotta a sé che non tocca le scritture.
            //
            // ⚠️ **'prohibited', non l'assenza della chiave.** Senza validazione un file
            // inviato comunque — un bundle vecchio ancora aperto in una scheda durante un
            // aggiornamento dell'applicazione, o un'integrazione ferma al contratto
            // precedente — veniva ignorato in silenzio: nessun errore, nessun salvataggio,
            // e la risposta restava «Fattura aggiornata con successo». Trovato dalla
            // revisione avversariale della beta.12. Ora fallisce con un messaggio che dice
            // dove allegare davvero, invece di far sparire un file sotto una conferma.
            'file' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.prohibited' => 'Non è più possibile allegare un documento da qui: usa il pulsante «Allega» nella pagina di dettaglio della fattura.',
            'righe.required'                          => 'Devi inserire almeno una voce di spesa.',
            'righe.*.descrizione.required'            => 'La causale della riga è obbligatoria.',
            'righe.*.importo_imponibile.required'     => "L'importo è obbligatorio.",
            'righe.*.importo_imponibile.numeric'      => "L'importo deve essere un numero.",
            'gestione_id.required'                    => 'La gestione contabile è obbligatoria.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $righe = $this->input('righe', []);

            foreach ($righe as $idx => $riga) {
                $isSopravvenienza = filter_var($riga['is_sopravvenienza'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($isSopravvenienza) {
                    $validator->errors()->add(
                        "righe.{$idx}.is_sopravvenienza",
                        'Le righe di sopravvenienza non sono modificabili: usa lo storno.'
                    );
                }

                if (empty($riga['immobile_id']) && empty($riga['conto_id'])) {
                    $validator->errors()->add(
                        "righe.{$idx}.conto_id",
                        'Il capitolo di spesa è obbligatorio per righe non assegnate a un immobile.'
                    );
                }
            }

            $this->guardiaNaturaPercipienteMancante($validator);
        });
    }

    /**
     * Design §2.4 M2: stessa guardia di StoreFatturaRequest. Fornitore e
     * tipo_documento sono immutabili in modifica: si leggono dalla fattura
     * legata alla rotta, non dall'input.
     */
    private function guardiaNaturaPercipienteMancante($validator): void
    {
        $fattura = $this->route('fattura');
        $fornitore = $fattura?->fornitore;
        if (! $fornitore || ! $fornitore->soggetto_ritenuta || $fornitore->regime_forfetario || ! $fornitore->tipo_ritenuta) {
            return;
        }

        if ($fornitore->natura_percipiente || $fornitore->codice_tributo) {
            return;
        }

        $richiestaApplicazione = $this->input('applica_ritenuta') ?? ($fattura->tipo_documento !== 'nota_credito');
        if (! filter_var($richiestaApplicazione, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (filter_var($this->input('dati_extra.fiscal.conferma_codice_tributo_mancante', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $validator->errors()->add(
            'dati_extra.fiscal.conferma_codice_tributo_mancante',
            "Impossibile determinare il codice tributo (1019 o 1020): sull'anagrafica di {$fornitore->ragione_sociale} manca la natura del percipiente. Completala nell'anagrafica fornitore oppure conferma di voler procedere comunque."
        );
    }
}
