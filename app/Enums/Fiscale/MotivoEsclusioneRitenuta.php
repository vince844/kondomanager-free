<?php

namespace App\Enums\Fiscale;

/**
 * Perché una fattura di un fornitore normalmente soggetto a ritenuta viene
 * registrata SENZA ritenuta. Guida il messaggio mostrato in UI e la presenza
 * del motivo salvato: la ritenuta non è una proprietà del fornitore (lo
 * stesso fornitore può emettere fatture soggette e non soggette — es. CEA
 * estintori: manutenzione soggetta, vendita estintore no), quindi ogni
 * esclusione va motivata documento per documento.
 *
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum MotivoEsclusioneRitenuta: string
{
    /** La banca opera già l'11% con bonifico parlante (detrazioni edilizie). */
    case BONIFICO_PARLANTE = 'bonifico_parlante';

    /** Fornitore in regime forfetario: nessuna ritenuta per legge. */
    case FORFETARIO = 'forfetario';

    /** La prestazione/cessione non rientra nel regime di ritenuta (es. vendita di un bene). */
    case FUORI_CAMPO = 'fuori_campo';

    /** Fornitura con posa in opera meramente accessoria alla prestazione. */
    case POSA_ACCESSORIA = 'posa_accessoria';

    /** Nessuno dei casi tipizzati: l'amministratore spiega a testo libero. */
    case OVERRIDE_MANUALE = 'override_manuale';

    public function label(): string
    {
        return match ($this) {
            self::BONIFICO_PARLANTE => "Bonifico parlante (ritenuta 11% già operata dalla banca)",
            self::FORFETARIO => 'Fornitore in regime forfetario',
            self::FUORI_CAMPO => 'Fuori dal campo di applicazione della ritenuta',
            self::POSA_ACCESSORIA => 'Posa in opera accessoria alla fornitura',
            self::OVERRIDE_MANUALE => 'Altro motivo (specificato a testo libero)',
        };
    }
}
