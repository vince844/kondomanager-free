<?php

namespace App\Enums\Fiscale;

/**
 * Il ciclo di vita di una delega F24.
 *
 * `versata` è il **sigillo**: da lì in poi la delega non si rettifica più. Il design lo
 * dichiara come regola non negoziabile — *«rettifica vietata, storno sempre ammesso»* — e la
 * ragione è che un versamento all'Erario è avvenuto davvero: modificarlo a posteriori
 * significherebbe raccontare una storia diversa da quella che l'Agenzia ha registrato.
 *
 * `annullata` non è una cancellazione: la delega resta, con il motivo scritto. Una delega va
 * conservata dieci anni, e per questo la tabella non ha `softDeletes`.
 */
enum StatoDelegaF24: string
{
    /** Calcolata dal sistema, ancora modificabile: nessun effetto contabile. */
    case BOZZA = 'bozza';

    /** Confermata dall'amministratore, in attesa del versamento. Le righe sono congelate. */
    case CONFERMATA = 'confermata';

    /** Versata: esiste la scrittura DARE 2202 / AVERE banca. Sotto sigillo. */
    case VERSATA = 'versata';

    /** Versamento stornato: la scrittura inversa esiste, il debito verso l'Erario torna aperto. */
    case STORNATA = 'stornata';

    /** Annullata prima del versamento, con motivo. Nessun effetto contabile. */
    case ANNULLATA = 'annullata';

    public function label(): string
    {
        return match ($this) {
            self::BOZZA => 'Bozza',
            self::CONFERMATA => 'Confermata',
            self::VERSATA => 'Versata',
            self::STORNATA => 'Stornata',
            self::ANNULLATA => 'Annullata',
        };
    }

    /** Se la delega ha già prodotto movimenti contabili. */
    public function haEffettoContabile(): bool
    {
        return in_array($this, [self::VERSATA, self::STORNATA], true);
    }

    /** Se le righe possono ancora cambiare. */
    public function isModificabile(): bool
    {
        return $this === self::BOZZA;
    }
}
