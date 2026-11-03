<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Un blocco DatiRiepilogo: quanto la fattura dichiara di sé, per aliquota.
 * Importi in centesimi.
 *
 * ⚠️ NON è la somma delle righe, e non va confuso con essa. Sull'esempio
 * ufficiale FPR02 dell'Agenzia le righe sommano € 25,00 mentre il
 * riepilogo dichiara € 27,00 (verificato 01/09/2026). Le differenze
 * legittime hanno più cause — spese accessorie, arrotondamenti, contributo
 * cassa previdenziale, sconti di documento — e in FPR02 non ce n'è
 * nessuna: l'esempio è semplicemente incoerente con sé stesso.
 *
 * Per registrare una fattura passiva **fa fede questo blocco**, non la
 * somma delle righe: è ciò che il fornitore afferma di chiedere. Le righe
 * servono a capire cosa assegnare a quale conto.
 */
class FatturaPaRiepilogo
{
    public function __construct(
        public readonly float $aliquotaIva,
        public readonly ?string $natura,
        public readonly int $imponibileCents,
        public readonly int $impostaCents,
    ) {
    }
}
