<?php

namespace App\Enums\Fiscale;

/**
 * Cosa rappresenta economicamente una riga fattura, ai fini della base
 * ritenuta. Guida il DEFAULT proposto per `concorre_base_ritenuta`, non il
 * valore forzato — l'amministratore può sempre correggerlo riga per riga.
 *
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum NaturaRigaRitenuta: string
{
    /** Corrispettivo per la prestazione: concorre normalmente. */
    case IMPONIBILE = 'imponibile';

    /** Contributo integrativo cassa professionale: escluso dalla base E dal punto 4 CU. */
    case CASSA_PROFESSIONALE = 'cassa_professionale';

    /** Rivalsa INPS gestione separata (4%): concorre alla base, a differenza della cassa professionale. */
    case RIVALSA_INPS_GS = 'rivalsa_inps_gs';

    /** Rimborso spese analitiche art. 15 TUIR: escluso dalla base. */
    case RIMBORSO_ART15 = 'rimborso_art15';

    /** Rimborso a piè di lista di spese anticipate in nome e per conto: escluso dalla base. */
    case RIMBORSO_PIE_LISTA = 'rimborso_pie_lista';

    /** Bollo riaddebitato in fattura: escluso di default (vedi decisione §7.3 del design). */
    case BOLLO_RIADDEBITATO = 'bollo_riaddebitato';

    /** Fornitura di bene con posa in opera meramente accessoria: esclusa (beni significativi). */
    case FORNITURA_CON_POSA = 'fornitura_con_posa';

    /** Default proposto per `concorre_base_ritenuta` quando questa natura è selezionata. */
    public function concorreDiDefault(): bool
    {
        return match ($this) {
            self::IMPONIBILE, self::RIVALSA_INPS_GS => true,
            self::CASSA_PROFESSIONALE,
            self::RIMBORSO_ART15,
            self::RIMBORSO_PIE_LISTA,
            self::BOLLO_RIADDEBITATO,
            self::FORNITURA_CON_POSA => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IMPONIBILE => 'Corrispettivo prestazione',
            self::CASSA_PROFESSIONALE => 'Contributo cassa professionale',
            self::RIVALSA_INPS_GS => 'Rivalsa INPS gestione separata',
            self::RIMBORSO_ART15 => 'Rimborso spese art. 15 TUIR',
            self::RIMBORSO_PIE_LISTA => 'Rimborso a piè di lista',
            self::BOLLO_RIADDEBITATO => 'Bollo riaddebitato',
            self::FORNITURA_CON_POSA => 'Fornitura con posa accessoria (bene significativo)',
        };
    }
}
