<?php

namespace App\Enums;

/**
 * La natura di una gestione: `gestioni.tipo`, `enum('ordinaria','straordinaria')`
 * (migrazione `2025_09_30_045746`).
 *
 * È l'asse su cui si aggancia la regola doppia della competenza (progetto
 * `docs/subentro_e_competenza_temporale.md`, D3 riscritta, decisione 11): per la **straordinaria**
 * decide la delibera attuativa, per l'**ordinaria** il periodo in cui l'attività è compiuta.
 * Fa fede questa colonna, non `piani_rate.tipo`, che è la sorgente dei dati (preventivo o fatture) e
 * non è vincolata alla gestione.
 *
 * La regola di lettura è la stessa di `RuoloAnagraficaImmobile::catenaSaldoSolidale(?string)`: tutto
 * ciò che non è «straordinaria» — compreso il nullo — è ordinaria. Quel metodo non è stato toccato per
 * non cambiare un comportamento; quando lo si toccherà, legga da qui.
 */
enum NaturaGestione: string
{
    case Ordinaria = 'ordinaria';
    case Straordinaria = 'straordinaria';

    public static function daStringa(?string $tipo): self
    {
        return $tipo === self::Straordinaria->value ? self::Straordinaria : self::Ordinaria;
    }
}
