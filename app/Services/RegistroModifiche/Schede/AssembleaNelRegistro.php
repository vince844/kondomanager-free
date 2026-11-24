<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Models\User;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;

/**
 * Un'assemblea, o una riunione registrata a mano (§6.1 e §6.2 del progetto della tappa 2): la sua «Storia» è anche il «Diario» della pagina, con le sedute,
 * i punti e le delibere (decisione 518). Qui non si ripristina: si cambia dalla scheda.
 */
final class AssembleaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Assemblea::class;

    public const AREA = 'assemblee';

    public const TIPO = 'assemblea';

    public const PAROLA = 'Assemblea';

    public const ETICHETTE = [
        'ambito' => 'Per chi',
        'palazzina_id' => 'Palazzina',
        'scala_id' => 'Scala',
        'tipo' => 'Tipo',
        'origine' => 'Come è nata',
        'stato' => 'Stato',
        'luogo' => 'Dove',
        'modalita' => 'Come si partecipa',
        'piattaforma' => 'La piattaforma',
        'consenso_videoconferenza_il' => 'Consenso alla videoconferenza del',
        'consenso_videoconferenza_modo' => 'Come è stato dato il consenso',
        'tabella_id' => 'Tabella del valore',
        'numero_verbale' => 'Numero del verbale',
        'verbale_documento_id' => 'Il verbale sottoscritto',
        'comunicazione_id' => 'Avviso in bacheca',
        'affissione_dal' => 'Affissa dal',
        'affissione_al' => 'Affissa fino al',
        'raccomandate_entro_il' => 'Spedire le raccomandate entro (scelto da te)',
        'riconvocata_da_id' => 'Riconvoca l\'assemblea',
        'annullata_il' => 'Annullata il',
        'annullata_motivo' => 'Perché è annullata',
        'annullata_da' => 'Annullata da',
    ];

    public const FORMATI = [
        'ambito' => ['scelta', [AmbitoAssemblea::CONDOMINIO->value => 'Tutto il condominio', AmbitoAssemblea::PALAZZINA->value => 'La palazzina',
            AmbitoAssemblea::SCALA->value => 'La scala', AmbitoAssemblea::GRUPPO->value => 'I condòmini di una tabella']],
        'palazzina_id' => ['chiave', Palazzina::class, 'name'],
        'scala_id' => ['chiave', Scala::class, 'name'],
        'tipo' => ['scelta', [TipoAssemblea::ORDINARIA->value => 'Ordinaria', TipoAssemblea::STRAORDINARIA->value => 'Straordinaria']],
        'origine' => ['scelta', [OrigineAssemblea::PROGRAMMA->value => 'Preparata nel programma', OrigineAssemblea::A_MANO->value => 'Registrata a mano']],
        'stato' => ['scelta', [StatoAssemblea::IN_PREPARAZIONE->value => 'In preparazione', StatoAssemblea::CONVOCATA->value => 'Convocata',
            StatoAssemblea::ANNULLATA->value => 'Annullata', StatoAssemblea::REGISTRATA_A_MANO->value => 'Registrata a mano',
            StatoAssemblea::IN_CORSO->value => 'In corso', StatoAssemblea::DESERTA->value => 'Deserta', StatoAssemblea::CHIUSA->value => 'Chiusa']],
        'modalita' => ['scelta', [ModalitaAssemblea::PRESENZA->value => 'In presenza', ModalitaAssemblea::VIDEOCONFERENZA->value => 'In videoconferenza',
            ModalitaAssemblea::MISTA->value => 'In presenza e in videoconferenza']],
        'consenso_videoconferenza_il' => 'data',
        'tabella_id' => ['chiave', Tabella::class, 'nome'],
        'verbale_documento_id' => ['chiave', Documento::class, 'name'],
        'comunicazione_id' => ['chiave', Comunicazione::class, 'subject'],
        'affissione_dal' => 'data',
        'affissione_al' => 'data',
        'raccomandate_entro_il' => 'data',
        'riconvocata_da_id' => ['chiave', Assemblea::class, '@nome'],
        'annullata_il' => 'data_ora',
        'annullata_da' => ['chiave', User::class, 'name'],
    ];

    /** Il condominio viene dall'indirizzo della pagina (§13, rilievo 23). */
    public const ESCLUSI = ['condominio_id'];

    /**
     * Il giorno della riunione lo scrive solo il servizio, dalle sedute o dal giorno della riunione a mano (§13, rilievo 6); l'ora dell'ultima stampa del
     * foglio della sala la scrive la stampa; il giorno dei documenti visti lo scrive «Avanti: Convocazione».
     */
    public const DEL_PROGRAMMA = ['data', 'foglio_stampato_il', 'documenti_visti_il'];

    /** Il motivo dell'annullamento può nominare persone (§13, rilievo 18). */
    public const DATI_DELLA_PERSONA = ['annullata_motivo'];

    /** «Assemblea ordinaria del 15/12/2026»; la riunione di carta «Riunione del 12/03/2024 registrata a mano, verbale n. 3/2024». */
    public function nome(Model $modello): string
    {
        $grezzi = $modello->getAttributes();
        $giorno = FormatoDelValore::testo($grezzi['data'] ?? null, 'data');
        if (($grezzi['origine'] ?? null) === OrigineAssemblea::A_MANO->value) {
            $verbale = trim((string) ($grezzi['numero_verbale'] ?? ''));

            return 'Riunione' . ($giorno !== null ? ' del ' . $giorno : '') . ' registrata a mano' . ($verbale !== '' ? ', verbale n. ' . $verbale : '');
        }
        $tipo = TipoAssemblea::tryFrom((string) ($grezzi['tipo'] ?? ''))?->value ?? '';

        return 'Assemblea' . ($tipo !== '' ? ' ' . $tipo : '') . ($giorno !== null ? ' del ' . $giorno : '');
    }
}
