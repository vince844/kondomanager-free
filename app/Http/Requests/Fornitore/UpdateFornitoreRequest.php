<?php

namespace App\Http\Requests\Fornitore;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\TipoRitenuta;
use App\Helpers\MoneyHelper;
use App\Models\Fornitore;
use App\Rules\UniqueEmailAcrossTables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Support\Str;

class UpdateFornitoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // --- Dati Base e Stato ---
            'ragione_sociale'          => ['required','string','max:255',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            'partita_iva'              => ['nullable','string','max:20',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            // Le lunghezze seguono le colonne, non un numero comodo: `codice_fiscale` è
            // `varchar(20)`, `nazione` `varchar(50)`, `provincia` `varchar(5)`. Con `strict => true`
            // una validazione più larga della colonna non protegge — sposta soltanto il rifiuto
            // dal messaggio accanto al campo all'eccezione SQL del controller.
            'codice_fiscale'           => ['nullable','string','max:20',Rule::unique(Fornitore::class)->ignore($this->fornitore->id)],
            'nazione'                  => 'nullable|string|max:50',
            'indirizzo'                => 'nullable|string|max:255',
            'comune'                   => 'nullable|string|max:100',
            'cap'                      => 'nullable|string|max:10',
            'provincia'                => 'nullable|string|max:5',
            'stato'                    => 'required|in:attivo,sospeso,cessato',

            // --- Contatti ---
            // 50 e non 20: le colonne sono `varchar(255)` e il limite stretto non difendeva niente,
            // mentre rifiutava «06 1234567 / 333 1234567», che è come un amministratore scrive
            // davvero due recapiti nella stessa casella.
            'telefono'                 => 'nullable|string|max:50',
            'cellulare'                => 'nullable|string|max:50',
            'fax'                      => 'nullable|string|max:50',
            'email'                    => ['nullable','email','max:255',new UniqueEmailAcrossTables($this->fornitore->id, 'fornitori')],
            'pec'                      => ['nullable','email','max:255','different:email',new UniqueEmailAcrossTables($this->fornitore->id, 'fornitori')],
            'sito_web'                 => 'nullable|string|max:255',
            'note'                     => 'nullable|string',
            
            // --- Dati Societari ---
            'iscrizione_cciaa'         => 'nullable|string|max:100',
            'data_iscrizione_cciaa'    => 'nullable|date',
            'capitale_sociale'         => 'nullable',
            'categoria_id'             => 'nullable|integer|exists:categorie_fornitore,id',
            'codice_ateco'             => 'nullable|string|max:20',
            'numero_iscrizione_ordine' => 'nullable|string|max:100',
            'certificazione_iso'       => 'boolean',
            // ⚠️ Nessun `anagrafica_id` qui, ed è una rimozione voluta della beta.7. I
            // rappresentanti di un fornitore sono **N, ognuno con un ruolo obbligatorio** fra sei, e
            // si gestiscono dalla loro scheda (`fornitori.anagrafiche`), che è raggiungibile dalla
            // stessa barra di questa pagina. La casella «Referente principale» che stava qui era un
            // secondo editor più povero della stessa relazione: non sapeva esprimere il ruolo,
            // quindi qualunque cosa scrivesse perdeva un dato, e il nome non esisteva nel modello —
            // `referente_principale` è una colonna che nessuno scrive.

            // --- NUOVI CAMPI: Fatturazione e Pagamenti ---
            'iban_principale'            => 'nullable|string|max:34',
            'modalita_pagamento_default' => 'required|string|in:bonifico,mav,ri.ba,contanti',
            'giorni_scadenza'            => 'required|integer|min:0',
            
            // --- NUOVI CAMPI: Ritenuta d'Acconto ---
            // ⚠️ Questi tre campi sono l'«Override manuale (facoltativo)» della schermata, e fino
            // alla beta.6 erano `required_if:soggetto_ritenuta,true` — cioè il riquadro si
            // dichiarava facoltativo e il server li pretendeva. La contraddizione rendeva
            // **impossibile da salvare** ogni fornitore già a database con la spunta e uno dei tre
            // vuoto, qualunque campo si stesse modificando: misurati due su otto in sviluppo, fra
            // cui quello del `CondominioDemoSeeder`, e il backfill del regime fiscale non
            // valorizza `codice_tributo`.
            //
            // La regola vera la detta `RitenutaService`: con `tipo_ritenuta` valorizzato il calcolo
            // passa da `calcolaRegimeNuovo()`, che ricava aliquota e codice tributo dall'enum e non
            // guarda nessuno dei tre; senza, si cade in `calcolaRegimeLegacy()`, che solleva
            // `DomainException` se manca **`perc_ritenuta`** — e solo quella.
            'soggetto_ritenuta'          => 'boolean',
            'perc_ritenuta'              => ['nullable', Rule::requiredIf(
                fn () => $this->boolean('soggetto_ritenuta') && blank($this->input('tipo_ritenuta'))
            ), 'numeric', 'min:0', 'max:100'],
            'perc_imponibile_ritenuta'   => 'nullable|numeric|min:0|max:100',
            'codice_tributo'             => 'nullable|string|max:10',

            // --- NUOVI CAMPI: Regime Fiscale Ritenuta (v1.10, Fase 1) ---
            'tipo_ritenuta'                => ['nullable', Rule::in(array_column(TipoRitenuta::cases(), 'value'))],
            'natura_percipiente'           => ['nullable', Rule::in(array_column(NaturaPercipiente::cases(), 'value'))],
            'residente_fiscale'            => 'boolean',
            'regime_forfetario'            => 'boolean',
            'forfetario_dichiarato_il'     => 'nullable|date|required_if:regime_forfetario,true',
            'forfetario_riferimento'       => 'nullable|string|max:255',
            'provvigioni_base_ridotta'     => 'boolean',
            'provvigioni_dichiarazione_il' => 'nullable|date|required_if:provvigioni_base_ridotta,true',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'capitale_sociale'      => MoneyHelper::toCents($this->capitale_sociale) ?? 0,
            'data_iscrizione_cciaa' => $this->data_iscrizione_cciaa ? Carbon::parse($this->input('data_iscrizione_cciaa'))->toDateString() : null,
            'email'                 => $this->email ? Str::lower($this->input('email')) : null,
            'pec'                   => $this->pec ? Str::lower($this->input('pec')) : null,
            
            // `perc_imponibile_ritenuta` è `decimal(5,2) NOT NULL default 100.00`: una casella
            // svuotata arriva qui come null e farebbe esplodere l'UPDATE. Torna al default della
            // colonna, che è anche il caso normale — la base ridotta è l'eccezione.
            'perc_imponibile_ritenuta' => blank($this->input('perc_imponibile_ritenuta'))
                ? 100
                : $this->input('perc_imponibile_ritenuta'),

            // Cast booleani
            'soggetto_ritenuta'     => filter_var($this->soggetto_ritenuta, FILTER_VALIDATE_BOOLEAN),
            'certificazione_iso'    => filter_var($this->certificazione_iso, FILTER_VALIDATE_BOOLEAN),
            'residente_fiscale'         => filter_var($this->input('residente_fiscale', true), FILTER_VALIDATE_BOOLEAN),
            'regime_forfetario'         => filter_var($this->regime_forfetario, FILTER_VALIDATE_BOOLEAN),
            'provvigioni_base_ridotta'  => filter_var($this->provvigioni_base_ridotta, FILTER_VALIDATE_BOOLEAN),
            
            // Pulizia IBAN
            'iban_principale'       => $this->iban_principale ? Str::upper(str_replace(' ', '', $this->iban_principale)) : null,
            
            // Maiuscolo per codici
            'codice_tributo'        => $this->codice_tributo ? Str::upper($this->codice_tributo) : null,
            'partita_iva'           => $this->partita_iva ? Str::upper($this->partita_iva) : null,
            'codice_fiscale'        => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
            // Il campo ha `class="uppercase"`, che è solo CSS: scrivendo «rm» a schermo si legge RM
            // ma a database finiva «rm», e la scheda di sola lettura lo ristampa grezzo.
            'provincia'             => $this->provincia ? Str::upper($this->provincia) : null,
        ]);
    }
}