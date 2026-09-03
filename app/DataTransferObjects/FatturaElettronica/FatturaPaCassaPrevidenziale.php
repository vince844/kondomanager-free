<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Un blocco `DatiCassaPrevidenziale`: il contributo integrativo che un professionista
 * iscritto a una cassa (geometri, ingegneri, avvocati, commercialisti…) addebita in
 * fattura e poi gira alla propria cassa.
 *
 * ## Perché è una RIGA di spesa e non una partita a sé
 *
 * Sembra il gemello della ritenuta d'acconto — due percentuali attaccate a una parcella
 * — e invece è il suo opposto. Deciso con Vincenzo il 03/09/2026 leggendo i numeri di
 * una fattura vera (`tests/Fixtures/fatturapa/collaudo_reali/10-…`):
 *
 * |                        | Ritenuta d'acconto        | Contributo cassa            |
 * | :--------------------- | :------------------------ | :-------------------------- |
 * | Chi lo incassa         | l'Erario                  | il professionista           |
 * | Chi lo versa           | il condominio, con F24    | il professionista           |
 * | Sul netto da pagare    | lo **riduce**             | lo **aumenta**              |
 * | Base IVA               | non c'entra               | **concorre**                |
 * | Per il condominio      | partita di giro           | **costo del servizio**      |
 *
 * La ritenuta ha bisogno di un campo suo perché crea una **seconda destinazione del
 * denaro** e un debito verso l'Erario. Il contributo cassa no: è una componente in più
 * del corrispettivo, che finisce nello stesso capitolo di spesa e nel rendiconto come
 * costo, IVA compresa (il condominio non detrae).
 *
 * Che concorra alla base IVA non è un'opinione, è scritto nel documento: su quella
 * fattura le righe fanno 3.200,00, il contributo 160,00 e `DatiRiepilogo` dichiara
 * `ImponibileImporto` 3.360,00 con `Imposta` 739,20 — cioè il 22% di 3.360, non di
 * 3.200.
 *
 * ## `AliquotaIVA` è QUI, e non si eredita dalle righe
 *
 * Lo schema mette un'aliquota dentro questo blocco proprio perché può differire da
 * quella delle righe (una parcella con prestazioni a due aliquote ha un contributo con
 * la sua). È anche la prova strutturale che «riga» è la forma giusta: solo una riga di
 * dettaglio ha imponibile e aliquota propri.
 *
 * ## `soggettaRitenuta` si LEGGE, non si deduce
 *
 * Il contributo integrativo di regola non è soggetto a ritenuta, ma ci sono casi in cui
 * lo è — la rivalsa INPS di chi non ha una cassa è quello tipico. Lo schema ha un campo
 * `<Ritenuta>` apposta: si legge quello invece di dedurlo dal `TipoCassa`, così la
 * risposta la dà il documento e non una nostra tabella da tenere aggiornata. Assente
 * significa «no», che è il caso normale.
 */
class FatturaPaCassaPrevidenziale
{
    public function __construct(
        /** TC01…TC22 — quale cassa. Serve a scrivere una descrizione riconoscibile. */
        public readonly ?string $tipoCassa,
        /** La percentuale dichiarata (5.00), per la descrizione: il calcolo è già fatto dal file. */
        public readonly ?float $aliquotaContributo,
        public readonly int $importoContributoCents,
        /** L'aliquota IVA di QUESTO contributo, che può differire da quella delle righe. */
        public readonly ?float $aliquotaIva,
        public readonly bool $soggettaRitenuta,
    ) {
    }
}
