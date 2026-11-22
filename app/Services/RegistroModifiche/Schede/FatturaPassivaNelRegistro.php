<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Helpers\MoneyHelper;
use App\Models\Fornitore;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestione;
use App\Models\Saldo;
use Illuminate\Database\Eloquent\Model;

/**
 * La fattura passiva (`FatturaRegisterEdit.vue`, `FatturaShow.vue`): è una scheda, con «Registrata, € 14.500,00» (la bozza). In contabilità non si
 * ripristina: si storna, dalla fattura. Le scritture contabili sono il gesto della loro azione.
 */
final class FatturaPassivaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = FatturaPassiva::class;

    public const AREA = 'contabilita';

    public const TIPO = 'fattura_passiva';

    public const PAROLA = 'Fattura';

    public const ETICHETTE = [
        'fornitore_id' => 'Fornitore',
        'conto_corrente_id' => 'Conto addebito',
        'tipo_documento' => 'Tipo documento',
        'numero_documento' => 'Numero documento',
        'data_documento' => 'Data documento',
        'data_scadenza' => 'Scadenza',
        'importo_imponibile' => 'Imponibile',
        'importo_iva' => 'IVA',
        'importo_ritenuta' => 'Ritenuta d\'acconto',
        'totale_documento' => 'Totale documento',
        'netto_a_pagare' => 'Netto da pagare',
        'stato_approvazione' => 'Approvazione',
        'iban_fornitore' => 'IBAN fornitore',
        'is_pregresso' => 'Debito esercizio precedente',
        'data_competenza_originaria' => 'Data di origine del debito',
        'saldo_patrimoniale_id' => 'Fornitore da pagare',
        'competenza_dal' => 'Competenza (dal)',
        'competenza_al' => 'Competenza (al)',
        'fattura_rettificata_id' => 'Fattura che la nota rettifica',
        'gestione_id' => 'Gestione',
    ];

    public const FORMATI = [
        'fornitore_id' => ['chiave', Fornitore::class, 'ragione_sociale'],
        'conto_corrente_id' => ['chiave', ContoContabile::class, 'nome'],
        'tipo_documento' => ['scelta', ['fattura' => 'Fattura', 'nota_credito' => 'Nota di credito']],
        'data_documento' => 'data',
        'data_scadenza' => 'data',
        'importo_imponibile' => 'euro',
        'importo_iva' => 'euro',
        'importo_ritenuta' => 'euro',
        'totale_documento' => 'euro',
        'netto_a_pagare' => 'euro',
        'stato_approvazione' => ['scelta', ['approvata' => 'Approvata', 'da_approvare' => 'Da approvare', 'contestata' => 'Contestata',
            'sforo_motivato' => 'Sforo motivato']],
        'is_pregresso' => 'si_no',
        'data_competenza_originaria' => 'data',
        'saldo_patrimoniale_id' => ['chiave', Saldo::class, 'descrizione'],
        'competenza_dal' => 'data',
        'competenza_al' => 'data',
        'fattura_rettificata_id' => ['chiave', FatturaPassiva::class, 'numero_documento'],
        'gestione_id' => ['chiave', Gestione::class, 'nome'],
    ];

    public const ESCLUSI = ['condominio_id'];

    /**
     * P8: l'esercizio della pagina, il protocollo generato, lo stato del pagamento ricalcolato, la modalità copiata dal fornitore, i dati tecnici (CIG,
     * CUP, log della sopravvenienza), il ricalcolo e i suoi contatori.
     */
    public const DEL_PROGRAMMA = ['esercizio_id', 'numero_protocollo', 'stato_pagamento', 'modalita_pagamento', 'dati_extra',
        'ultimo_ricalcolo_pagamento_at', 'versione_allocazioni', 'inconsistenza_pagamento', 'ultimo_errore_ricalcolo'];

    public const DATI_DELLA_PERSONA = ['iban_fornitore'];

    public function nome(Model $modello): string
    {
        $fornitore = Fornitore::query()->whereKey($modello->getAttribute('fornitore_id'))->value('ragione_sociale');
        $parola = $modello->getAttribute('tipo_documento') === 'nota_credito' ? 'Nota di credito' : 'Fattura';

        return $parola . ' n. ' . $modello->getAttribute('numero_documento') . ($fornitore !== null ? ' di ' . $fornitore : '');
    }

    public function soggetti(Model $modello): array
    {
        $fornitore = $modello->getAttribute('fornitore_id');

        return $fornitore === null ? [] : [['fornitore', (int) $fornitore]];
    }

    public function riepilogo(Model $modello, string $azione): ?string
    {
        return $azione === 'creata' ? 'Registrata, ' . MoneyHelper::format((int) $modello->getAttribute('totale_documento')) : null;
    }
}
