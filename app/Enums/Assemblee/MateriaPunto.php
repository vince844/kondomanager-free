<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Di che cosa parla un punto dell'ordine del giorno (§1.1 e §13, rilievo 3): la materia decide la maggioranza proposta e l'amministrazione di serie.
 *
 * Le materie con la maggioranza fissata dalla legge (decisione 640 (1), testi letti su Normattiva il 21/11/2026) portano l'articolo nella parola e stanno
 * accanto alla materia di prima più vicina: le innovazioni agevolate e le modalità degli impianti dei singoli dopo le innovazioni, la videosorveglianza e i
 * progetti del territorio dopo i lavori, la rettifica delle tabelle dopo le tabelle, il sito internet dopo il regolamento, la tutela della destinazione
 * d'uso dopo la sua modifica.
 */
enum MateriaPunto: string
{
    use ConLeParole;

    case RENDICONTO = 'rendiconto';
    case PREVENTIVO = 'preventivo';
    case LAVORI = 'lavori';
    case INNOVAZIONI = 'innovazioni';
    case INNOVAZIONI_AGEVOLATE = 'innovazioni_agevolate';
    case IMPIANTI_SINGOLI = 'impianti_singoli';
    case VIDEOSORVEGLIANZA = 'videosorveglianza';
    case PROGETTI_TERRITORIO = 'progetti_territorio';
    case RATIFICA = 'ratifica';
    case COMUNICAZIONE_URGENZA = 'comunicazione_urgenza';
    case DISPENSA = 'dispensa';
    case ACCORDO = 'accordo';
    case REGOLE = 'regole';
    case FONDO = 'fondo';
    case NOMINA = 'nomina';
    case LITI = 'liti';
    case TABELLE = 'tabelle';
    case RETTIFICA_TABELLE = 'rettifica_tabelle';
    case RIPARTO = 'riparto';
    case REGOLAMENTO = 'regolamento';
    case SITO_INTERNET = 'sito_internet';
    case RISCALDAMENTO = 'riscaldamento';
    case DESTINAZIONE_USO = 'destinazione_uso';
    case TUTELA_DESTINAZIONE_USO = 'tutela_destinazione_uso';
    case COMUNICAZIONE = 'comunicazione';
    case DELEGA = 'delega';
    case VARIE = 'varie';
    case ALTRO = 'altro';

    public function parola(): string
    {
        return match ($this) {
            self::RENDICONTO => 'Rendiconto',
            self::PREVENTIVO => 'Preventivo',
            self::LAVORI => 'Lavori',
            self::INNOVAZIONI => 'Innovazioni',
            self::INNOVAZIONI_AGEVOLATE => 'Innovazioni agevolate (art. 1120 c. 2)',
            self::IMPIANTI_SINGOLI => 'Modalità per gli impianti dei singoli (art. 1122-bis c. 3)',
            self::VIDEOSORVEGLIANZA => 'Videosorveglianza sulle parti comuni (art. 1122-ter)',
            self::PROGETTI_TERRITORIO => 'Progetti del territorio (art. 1135 c. 3)',
            self::RATIFICA => 'Ratifica di una spesa',
            self::COMUNICAZIONE_URGENZA => 'Comunicazione dei lavori urgenti',
            self::DISPENSA => 'Dispensa dei sei mesi',
            self::ACCORDO => 'Accordo di rientro',
            self::REGOLE => 'Regole del recupero dei crediti',
            self::FONDO => 'Fondo',
            self::NOMINA => 'Nomina o revoca dell\'amministratore',
            self::LITI => 'Liti',
            self::TABELLE => 'Tabelle millesimali',
            self::RETTIFICA_TABELLE => 'Rettifica delle tabelle (art. 69 disp. att.)',
            self::RIPARTO => 'Criteri di riparto',
            self::REGOLAMENTO => 'Regolamento',
            self::SITO_INTERNET => 'Sito internet del condominio (art. 71-ter disp. att.)',
            self::RISCALDAMENTO => 'Riscaldamento e condizionamento',
            self::DESTINAZIONE_USO => 'Modifica della destinazione d\'uso',
            self::TUTELA_DESTINAZIONE_USO => 'Tutela della destinazione d\'uso (art. 1117-quater)',
            self::COMUNICAZIONE => 'Comunicazione',
            self::DELEGA => 'Delega all\'amministratore',
            self::VARIE => 'Varie ed eventuali',
            self::ALTRO => 'Altro',
        };
    }
}
