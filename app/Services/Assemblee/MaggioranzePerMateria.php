<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;

/**
 * La maggioranza che il programma propone per la materia di un punto (§3 P2 del progetto della tappa 2, §13 rilievo 3; legge §3 e §9). **Propone, non
 * sceglie**: l'amministratore la cambia con il menu.
 *
 * - nomina e revoca dell'amministratore, liti, lavori: «Metà del valore» (art. 1136 c. 4; per i lavori la proposta prudente, che si cambia se non sono di
 *   notevole entità);
 * - innovazioni: «Due terzi del valore» (art. 1136 c. 5); modifica della destinazione d'uso: «Quattro quinti» (art. 1117-ter);
 * - regolamento: «Metà del valore» (art. 1138 c. 3, che rimanda alla maggioranza dell'art. 1136 c. 2, in ogni convocazione);
 * - **le materie con la maggioranza fissata dalla legge** (decisione 640 (1), testi letti su Normattiva il 21/11/2026): «Metà del valore», in prima e in
 *   seconda, per le innovazioni agevolate (art. 1120 c. 2), la videosorveglianza (art. 1122-ter), la tutela della destinazione d'uso (art. 1117-quater) e i
 *   progetti del territorio (art. 1135 c. 3), tutte nell'art. 1136 c. 4, per il sito internet (art. 71-ter disp. att.) e per la rettifica delle tabelle
 *   per errore o per variazione oltre un quinto (art. 69 disp. att.); «Due terzi del valore» per le modalità degli impianti dei singoli (art. 1122-bis
 *   c. 3, nell'art. 1136 c. 5);
 * - varie ed eventuali, comunicazioni, comunicazione dei lavori urgenti: «Senza voto»;
 * - **tabelle e criteri di riparto: nessuna proposta**, con l'avviso della nullità (Cass. SU 9839/2021);
 * - **la delega all'amministratore: nessuna proposta** (i maggiori poteri dell'art. 1131 c. 1 c.c.: nessun documento dice con quale maggioranza);
 * - le altre: «Ordinaria».
 *
 * Sul riscaldamento e il condizionamento il programma propone anche che **il conduttore voti al posto del proprietario** (art. 10 L. 392/1978: spese e
 * modalità di gestione di quei servizi); sulle altre materie nessuna proposta, e il conduttore non vota.
 */
final class MaggioranzePerMateria
{
    public const AVVISO_RIPARTO = 'Una delibera che a maggioranza fissa o cambia i criteri di riparto è nulla (Cass. SU 9839/2021).';

    public const AVVISO_LAVORI = 'Per le riparazioni straordinarie di notevole entità serve la metà del valore (art. 1136 c. 4): se i lavori non lo sono, '
        . 'scegli «Ordinaria».';

    /** Sotto «Innovazioni»: le agevolate hanno la loro materia, e le vietate non si deliberano (art. 1120 c. 2 e c. 4, letti su Normattiva il 21/11/2026). */
    public const AVVISO_INNOVAZIONI = 'Se l\'innovazione è per la sicurezza, le barriere architettoniche, il consumo energetico, i parcheggi, le fonti '
        . 'rinnovabili o gli impianti centralizzati radiotelevisivi, scegli «Innovazioni agevolate»: basta la metà del valore (art. 1120 c. 2). Sono vietate '
        . 'le innovazioni che pregiudicano la stabilità o la sicurezza dell\'edificio, ne alterano il decoro o rendono parti comuni inservibili anche a un solo '
        . 'condòmino (art. 1120 c. 4).';

    /** Che cosa comprendono le innovazioni agevolate (art. 1120 c. 2) e le barriere architettoniche (L. 13/1989 art. 2 c. 1). */
    public const AVVISO_INNOVAZIONI_AGEVOLATE = 'Comprendono le opere per la sicurezza e la salubrità dell\'edificio, per eliminare le barriere architettoniche, '
        . 'per contenere il consumo energetico, per i parcheggi, per le fonti rinnovabili e per gli impianti centralizzati radiotelevisivi (art. 1120 c. 2). '
        . 'Per le barriere architettoniche la metà del valore vale in prima e in seconda convocazione, e non sono mai voluttuarie (L. 13/1989 art. 2).';

    /** La rettifica delle tabelle a maggioranza, nei soli due casi della legge (art. 69 disp. att.). */
    public const AVVISO_RETTIFICA = 'La metà del valore basta solo se i valori delle tabelle sono frutto di un errore, o se il valore di un\'unità è cambiato '
        . 'di più di un quinto per le mutate condizioni dell\'edificio (art. 69 disp. att.); negli altri casi serve l\'unanimità.';

    /** La tutela non è la modifica: la cessazione di un uso (art. 1117-quater), non una destinazione nuova (art. 1117-ter). */
    public const AVVISO_TUTELA = 'È per far cessare le attività che incidono sulle destinazioni d\'uso delle parti comuni (art. 1117-quater). Per cambiare '
        . 'la destinazione scegli «Modifica della destinazione d\'uso»: servono i quattro quinti (art. 1117-ter).';

    /**
     * Le materie che di serie sono di amministrazione straordinaria (§13, rilievo 1). Le materie nuove come la materia di prima più vicina: le innovazioni
     * agevolate e le modalità degli impianti dei singoli come le innovazioni, la videosorveglianza e i progetti del territorio come i lavori, la rettifica come
     * le tabelle; la tutela della destinazione d'uso come le liti e il sito internet come il regolamento, che non ci sono.
     */
    public const STRAORDINARIE = [MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI, MateriaPunto::TABELLE, MateriaPunto::RIPARTO, MateriaPunto::DESTINAZIONE_USO,
        MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::IMPIANTI_SINGOLI, MateriaPunto::VIDEOSORVEGLIANZA, MateriaPunto::PROGETTI_TERRITORIO,
        MateriaPunto::RETTIFICA_TABELLE];

    public static function proposta(string $materia): ?string
    {
        return match (MateriaPunto::tryFrom($materia)) {
            MateriaPunto::NOMINA, MateriaPunto::LITI, MateriaPunto::LAVORI, MateriaPunto::REGOLAMENTO => Maggioranza::META_DEL_VALORE->value,
            // L'art. 1136 c. 4 («sempre» la metà del valore) e le norme che rimandano al suo c. 2 (art. 71-ter e art. 69 disp. att.).
            MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::VIDEOSORVEGLIANZA, MateriaPunto::TUTELA_DESTINAZIONE_USO, MateriaPunto::PROGETTI_TERRITORIO,
            MateriaPunto::SITO_INTERNET, MateriaPunto::RETTIFICA_TABELLE => Maggioranza::META_DEL_VALORE->value,
            MateriaPunto::INNOVAZIONI, MateriaPunto::IMPIANTI_SINGOLI => Maggioranza::DUE_TERZI->value,
            MateriaPunto::DESTINAZIONE_USO => Maggioranza::QUATTRO_QUINTI->value,
            MateriaPunto::VARIE, MateriaPunto::COMUNICAZIONE, MateriaPunto::COMUNICAZIONE_URGENZA => Maggioranza::SENZA_VOTO->value,
            // La delega all'amministratore (art. 1131 c. 1 c.c.): nessun documento dice con quale maggioranza si conferisce, e il programma non la propone.
            MateriaPunto::TABELLE, MateriaPunto::RIPARTO, MateriaPunto::DELEGA => null,
            default => Maggioranza::ORDINARIA->value,
        };
    }

    /** Il conduttore proposto per la materia: vota al posto del proprietario sul riscaldamento e il condizionamento (art. 10 L. 392/1978). */
    public static function conduttore(string $materia): ?string
    {
        return MateriaPunto::tryFrom($materia) === MateriaPunto::RISCALDAMENTO ? \App\Enums\Assemblee\ConduttorePunto::VOTA->value : null;
    }

    /** La frase accanto al menu, dove serve. */
    public static function avviso(string $materia): ?string
    {
        return match (MateriaPunto::tryFrom($materia)) {
            MateriaPunto::TABELLE, MateriaPunto::RIPARTO => self::AVVISO_RIPARTO,
            MateriaPunto::LAVORI => self::AVVISO_LAVORI,
            MateriaPunto::INNOVAZIONI => self::AVVISO_INNOVAZIONI,
            MateriaPunto::INNOVAZIONI_AGEVOLATE => self::AVVISO_INNOVAZIONI_AGEVOLATE,
            MateriaPunto::RETTIFICA_TABELLE => self::AVVISO_RETTIFICA,
            MateriaPunto::TUTELA_DESTINAZIONE_USO => self::AVVISO_TUTELA,
            default => null,
        };
    }

    /**
     * L'amministrazione di serie: straordinaria per le materie di {@see self::STRAORDINARIE}, e per ogni maggioranza diversa da «Ordinaria»
     * (un punto senza voto non delibera, e resta ordinario). Decide chi vota con l'usufrutto e a chi va l'avviso (art. 67 c. 6 e 7 disp. att.).
     *
     * La nomina, la conferma, la revoca e il compenso dell'amministratore restano di amministrazione ordinaria anche con la metà del valore: l'art. 1135
     * n. 1 c.c. li mette fra le attribuzioni ordinarie dell'assemblea, quindi vota l'usufruttuario (art. 67 c. 6 disp. att.). Non c'è una pronuncia
     * della Cassazione: è la lettura concorde dei commenti, e il presidente può cambiarla per il singolo punto.
     */
    public static function amministrazione(string $materia, ?string $maggioranza): string
    {
        $m = MateriaPunto::tryFrom($materia);
        if ($m === MateriaPunto::NOMINA) {
            return AmministrazionePunto::ORDINARIA->value;
        }
        if ($m !== null && in_array($m, self::STRAORDINARIE, true)) {
            return AmministrazionePunto::STRAORDINARIA->value;
        }
        if ($maggioranza !== null && ! in_array($maggioranza, [Maggioranza::ORDINARIA->value, Maggioranza::SENZA_VOTO->value], true)) {
            return AmministrazionePunto::STRAORDINARIA->value;
        }

        return AmministrazionePunto::ORDINARIA->value;
    }
}
