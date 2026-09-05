<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use App\Services\Gestionale\Duplicati\RicercaFattureSimili;
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
        // ⚠️ **Il `min:0` vale solo per la nota di credito, e la distinzione è sostanziale.**
        // Su una fattura ordinaria una riga negativa è legittima e frequente: è lo storno
        // «Oneri di sistema» che ogni bolletta gas porta dentro il documento, e il motore
        // contabile ora la registra correttamente come AVERE sul capitolo. Vietarla qui
        // richiuderebbe con la validazione la porta appena aperta nel servizio.
        //
        // Su una **nota di credito** invece il segno lo mette già il moltiplicatore −1 di
        // `FatturaPassivaService`: una riga digitata negativa lo annulla e riporta
        // `netto_a_pagare` in positivo. A quel punto la guardia di `StornoFatturaController`
        // — `if ($fattura->netto_a_pagare < 0)`, l'unica cosa che distingue una NC da una
        // fattura vera al momento dello storno — non scatta più, e il documento diventa
        // pagabile e stornabile pur restando una nota di credito (Coda 132, trovata dalla
        // revisione avversariale della beta.17).
        $isNotaCredito = $this->route('fattura')?->tipo_documento === 'nota_credito';

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
            'righe.*.importo_imponibile' => $isNotaCredito ? 'required|numeric|min:0' : 'required|numeric',
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
            'righe.*.importo_imponibile.min'          => "Su una nota di credito l'importo di riga non può essere negativo: "
                .'il segno lo mette già il tipo di documento. Scrivi la cifra da accreditare.',
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

                // ⚠️ Stessa esenzione della registrazione: una riga da € 0,00 è contenuto
                // del documento, non una spesa da collocare a budget. Senza, una fattura
                // importata da XML con una riga descrittiva diventava **non modificabile** —
                // ogni salvataggio veniva rifiutato su un capitolo che non ha senso chiedere.
                // La condizione qui è diversa da quella di `StoreFatturaRequest` di proposito:
                // là si esentano le sopravvenienze, qui le righe assegnate a un immobile.
                $importoNullo = (float) ($riga['importo_imponibile'] ?? 0) === 0.0;

                if (!$importoNullo && empty($riga['immobile_id']) && empty($riga['conto_id'])) {
                    $validator->errors()->add(
                        "righe.{$idx}.conto_id",
                        'Il capitolo di spesa è obbligatorio per righe non assegnate a un immobile.'
                    );
                }
            }

            $this->guardiaNaturaPercipienteMancante($validator);
            $this->guardiaNumeroDocumentoCollidente($validator);
        });
    }

    /**
     * ⚠️ **Questo blocco, ed è deliberato**: non contraddice la decisione D4 («due livelli,
     * mai bloccanti»), perché non è quella domanda. D4 riguarda l'incertezza — «questa nuova
     * fattura *potrebbe* somigliare a un'altra, decidi tu» — ed è per questo che resta un
     * avviso: il sistema non è sicuro, e un falso positivo è normale.
     *
     * Qui la domanda è diversa. `fornitore_id` e `numero_documento` sono **read-only a
     * video** in questa schermata (vedi il blocco «Fornitore (Read-Only in Edit)» del
     * template, e `numero_documento` reso come testo, non input): l'interfaccia non offre
     * mai un modo di *cambiarli*. Se il numero arriva **cambiato** e per giunta uguale a
     * quello di un'altra fattura viva dello stesso fornitore nello stesso esercizio, non è
     * un'ambiguità da segnalare — è una richiesta che aggira un vincolo che il client
     * dichiara di rispettare da solo: o è un bug del client, o è un tentativo di far
     * combaciare due identità che non possono esserlo.
     *
     * ⛔ **Rettifica dopo la revisione avversariale della beta.13.** Questo docblock diceva
     * «se `numero_documento` arriva uguale a quello di un'altra fattura», senza la parola
     * *cambiato*, e la guardia faceva letteralmente quello: bloccava ogni salvataggio in cui
     * il numero collideva, anche quando nessuno l'aveva toccato. Era il ragionamento a essere
     * rovesciato — proprio *perché* il campo è read-only, il modulo rispedisce **sempre** il
     * numero identico, quindi la collisione preesiste al salvataggio invece di essere prodotta
     * da esso. Con due fatture gemelle (stesso numero, date diverse: uno stato che D4
     * permette apposta) diventavano entrambe non salvabili, e bastava cambiare l'importo.
     * Sorvegliare il numero **cambiato**, non il numero collidente.
     *
     * ⚠️ **Misurato, non teorico**: `numero_documento` qui è validato solo con
     * `required|string|max:50`, senza `unique`, e `FatturaPassivaService::aggiornaFattura()`
     * lo riscrive davvero. L'indice `unique_ft_condominio` non basta a coprirlo: include
     * `data_documento`, che D4-forte non richiede — una data diversa lo bypassa.
     */
    private function guardiaNumeroDocumentoCollidente($validator): void
    {
        $fattura = $this->route('fattura');
        $numero = trim((string) $this->input('numero_documento'));

        if ($fattura === null || $numero === '') {
            return;
        }

        // ⚠️ **Se il numero non è cambiato non c'è niente da sorvegliare, e ometterlo era un
        // blocco vero.** D4 non è bloccante in registrazione, quindi il prodotto permette
        // deliberatamente due fatture dello stesso fornitore, stesso esercizio, stesso numero e
        // **date diverse** (`unique_ft_condominio` include `data_documento`, quindi non le ferma).
        // Senza questa uscita anticipata la guardia trovava la gemella a ogni salvataggio di
        // *entrambe*, e rifiutava anche una modifica che il numero non lo tocca nemmeno — cambiare
        // il solo importo bastava. Il campo è read-only a video, quindi il modulo rispedisce
        // sempre lo stesso valore: dall'interfaccia il blocco non era aggirabile in alcun modo.
        //
        // Il docblock qui sopra sbagliava la premessa: proprio *perché* è read-only il client
        // manda sempre il numero identico, e la collisione preesiste al salvataggio invece di
        // essere prodotta da esso. Quello che va sorvegliato è il numero **cambiato**, non il
        // numero collidente. Trovato dalla revisione avversariale della beta.13, riprodotto da
        // sei revisori indipendenti sulle sole rotte HTTP.
        //
        // Il confronto ricalca `RicercaFattureSimili::forte()` (`LOWER(TRIM(...))`, riga 112):
        // se differissero, un numero con spazi o maiuscole diverse sfuggirebbe a questa uscita e
        // verrebbe poi intercettato dalla ricerca, tornando esattamente al blocco di prima.
        if (mb_strtolower($numero) === mb_strtolower(trim((string) $fattura->numero_documento))) {
            return;
        }

        $collide = app(RicercaFattureSimili::class)
            ->cerca(
                condominioId: $fattura->condominio_id,
                esercizioId: $fattura->esercizio_id,
                fornitoreId: $fattura->fornitore_id,
                numeroDocumento: $numero,
                totaleDocumentoCents: null,
                dataDocumento: null,
                tipoDocumento: $fattura->tipo_documento,
                escludiFatturaId: $fattura->id,
            )
            ->contains(fn ($f) => $f->motivo === RicercaFattureSimili::FORTE);

        if ($collide) {
            $validator->errors()->add(
                'numero_documento',
                'Esiste già un\'altra fattura di questo fornitore con lo stesso numero documento in questo esercizio. Fornitore e numero non sono modificabili da qui: se il numero è cambiato davvero, storna e registra di nuovo.'
            );
        }
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
