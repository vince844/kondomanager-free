<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Fornitore;
use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Database\Eloquent\Model;

/** Il fornitore della rubrica: le parole di `FornitoriEdit.vue` (dove la pagina non scrive l'etichetta, quella dell'elenco degli errori). */
final class FornitoreNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Fornitore::class;

    public const AREA = 'persone';

    public const TIPO = 'fornitore';

    public const PAROLA = 'Fornitore';

    public const ETICHETTE = [
        'ragione_sociale' => 'Ragione sociale',
        'partita_iva' => 'Partita IVA',
        'codice_fiscale' => 'Codice fiscale',
        'indirizzo' => 'Indirizzo e civico',
        'cap' => 'CAP',
        'comune' => 'Comune',
        'provincia' => 'Provincia',
        'iscrizione_cciaa' => 'Iscrizione CCIAA',
        'data_iscrizione_cciaa' => 'Data iscrizione CCIAA',
        'codice_ateco' => 'Codice ATECO',
        'numero_iscrizione_ordine' => 'Iscrizione albo/ordine',
        'categoria_id' => 'Categoria fornitore',
        'certificazione_iso' => 'Certificazione ISO',
        'capitale_sociale' => 'Capitale sociale',
        'telefono' => 'Telefono fisso',
        'cellulare' => 'Cellulare',
        'fax' => 'Fax',
        'email' => 'Email ordinaria',
        'pec' => 'Email PEC',
        'sito_web' => 'Sito internet',
        'stato' => 'Stato operativo',
        'note' => 'Note aggiuntive interne',
        'soggetto_ritenuta' => 'Soggetto a ritenuta d\'acconto',
        'perc_ritenuta' => '% da trattenere',
        'perc_imponibile_ritenuta' => '% base imponibile',
        'codice_tributo' => 'Codice tributo',
        'giorni_scadenza' => 'Giorni di scadenza',
        'modalita_pagamento_default' => 'Modalità di pagamento',
        'iban_principale' => 'IBAN principale',
        'tipo_ritenuta' => 'Regime di ritenuta',
        'natura_percipiente' => 'Natura del percipiente',
        'residente_fiscale' => 'Fornitore fiscalmente residente in Italia',
        'regime_forfetario' => 'Fornitore in regime forfetario',
        'forfetario_dichiarato_il' => 'Data dichiarazione forfetario',
        'forfetario_riferimento' => 'Riferimento documento conservato',
        'provvigioni_base_ridotta' => 'Il percipiente ha dichiarato di avvalersi in via continuativa dell\'opera di dipendenti o di terzi',
        'provvigioni_dichiarazione_il' => 'Data della dichiarazione sulle provvigioni',
    ];

    public const FORMATI = [
        'data_iscrizione_cciaa' => 'data',
        'categoria_id' => ['chiave', \App\Models\CategoriaFornitore::class, 'name'],
        'certificazione_iso' => 'si_no',
        'capitale_sociale' => 'euro',
        'stato' => ['scelta', ['attivo' => 'Attivo', 'sospeso' => 'Sospeso', 'cessato' => 'Cessato']],
        'soggetto_ritenuta' => 'si_no',
        'perc_ritenuta' => 'percentuale',
        'perc_imponibile_ritenuta' => 'percentuale',
        'giorni_scadenza' => 'numero',
        'modalita_pagamento_default' => ['scelta', ['bonifico' => 'Bonifico bancario', 'mav' => 'MAV', 'ri.ba' => 'Ri.Ba.', 'contanti' => 'Contanti']],
        'tipo_ritenuta' => ['scelta', ['appalto_4' => 'Appalto di opere/servizi — 4%', 'lavoro_autonomo_20' => 'Lavoro autonomo — 20%',
            'provvigioni_base_50' => 'Provvigioni — base 50%, 11,5%', 'provvigioni_base_20' => 'Provvigioni — base ridotta al 20% con la dichiarazione del percipiente, 4,6%',
            'non_residente_30' => 'Compenso a non residente — 30% a titolo d\'imposta', 'lavoro_dipendente' => 'Lavoro dipendente']],
        'natura_percipiente' => ['scelta', ['persona_fisica_irpef' => 'Persona fisica / ditta individuale (IRPEF)',
            'societa_persone_irpef' => 'Società di persone (IRPEF)', 'soggetto_ires' => 'Società di capitali / soggetto IRES',
            'ente_non_commerciale' => 'Ente non commerciale (IRES)']],
        'residente_fiscale' => 'si_no',
        'regime_forfetario' => 'si_no',
        'forfetario_dichiarato_il' => 'data',
        'provvigioni_base_ridotta' => 'si_no',
        'provvigioni_dichiarazione_il' => 'data',
    ];

    /** Non in pagina: la nazione vale sempre «Italia», i codici SIA, CUC e SEPA e la tipologia dell'ordine non li scrive nessuna pagina. */
    public const ESCLUSI = ['nazione', 'tipologia_ordine', 'codice_sia', 'codice_cuc', 'codice_sepa'];

    /** Scritta al salvataggio o alla domanda in fattura. */
    public const DEL_PROGRAMMA = ['ritenuta_decisa_il'];

    public const DATI_DELLA_PERSONA = ['ragione_sociale', 'partita_iva', 'codice_fiscale', 'indirizzo', 'cap', 'comune', 'provincia', 'telefono',
        'cellulare', 'fax', 'email', 'pec', 'sito_web', 'iban_principale', 'note', 'forfetario_riferimento'];

    public const RIPRISTINABILI = ['ragione_sociale', 'partita_iva', 'codice_fiscale', 'indirizzo', 'cap', 'comune', 'provincia', 'iscrizione_cciaa',
        'data_iscrizione_cciaa', 'codice_ateco', 'numero_iscrizione_ordine', 'telefono', 'cellulare', 'fax', 'email', 'pec', 'sito_web', 'note'];

    public function nome(Model $modello): string
    {
        return 'Fornitore ' . $modello->getAttribute('ragione_sociale');
    }

    /** Un fornitore è della rubrica dello studio: i suoi condomini sono quelli in cui ha fatture (P7). */
    public function condomini(Model $modello): array
    {
        if (! $modello->exists) {
            return [];
        }
        $ids = FatturaPassiva::query()->where('fornitore_id', $modello->getKey())->distinct()->limit(50)->pluck('condominio_id')->filter()->all();

        return \App\Models\Condominio::query()->whereIn('id', $ids)->orderBy('id')->pluck('nome', 'id')
            ->mapWithKeys(fn ($nome, $id) => [(int) $id => (string) $nome])->all();
    }

    public function soggetti(Model $modello): array
    {
        return [['fornitore', (int) $modello->getKey()]];
    }
}
