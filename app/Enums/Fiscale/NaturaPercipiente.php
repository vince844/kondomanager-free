<?php

namespace App\Enums\Fiscale;

/**
 * Natura giuridica di chi riceve il pagamento. Pilota SOLO il codice tributo
 * (1019 IRPEF vs 1020 IRES): l'aliquota dipende dal regime (TipoRitenuta),
 * non dalla natura del percipiente.
 *
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum NaturaPercipiente: string
{
    case PERSONA_FISICA_IRPEF = 'persona_fisica_irpef';
    case SOCIETA_PERSONE_IRPEF = 'societa_persone_irpef';
    case SOGGETTO_IRES = 'soggetto_ires';
    case ENTE_NON_COMMERCIALE = 'ente_non_commerciale';

    public function label(): string
    {
        return match ($this) {
            self::PERSONA_FISICA_IRPEF => 'Persona fisica / ditta individuale (IRPEF)',
            self::SOCIETA_PERSONE_IRPEF => 'Società di persone (IRPEF)',
            self::SOGGETTO_IRES => 'Società di capitali / soggetto IRES',
            self::ENTE_NON_COMMERCIALE => 'Ente non commerciale (IRES)',
        };
    }

    /**
     * IRES vs IRPEF, l'unica cosa che conta per scegliere fra 1019 e 1020.
     *
     * Il criterio è l'imposta dovuta dal percipiente, non la forma giuridica: la risoluzione 19/E del 5/2/2007 istituisce il
     * 1019 per i percipienti soggetti IRPEF e il 1020 per i soggetti IRES. ⚠️ **L'ente non commerciale è IRES** (art. 73
     * c. 1 lett. c TUIR), come la cooperativa, che è una società (lett. a): fino alla 1.11.0-beta.58 qui tornava vero solo
     * per `SOGGETTO_IRES`, e l'ente non commerciale finiva a 1019 (Coda 316).
     */
    public function ires(): bool
    {
        return $this === self::SOGGETTO_IRES || $this === self::ENTE_NON_COMMERCIALE;
    }
}
