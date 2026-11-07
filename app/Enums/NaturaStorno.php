<?php

namespace App\Enums;

/**
 * Perché un incasso è stato stornato (decisione 217, D1): solo chi storna lo sa, e lo dice. Persistito su
 * `scritture_contabili.natura_storno`, sull'incasso stornato; nullo per gli storni di prima della 1.12.0-beta.3, per lo storno di un
 * rimborso (che non la chiede) e per ogni altra scrittura.
 *
 * - `ERRORE_REGISTRAZIONE`: la registrazione è sbagliata, perché il versamento non c'è mai stato o va registrato di nuovo in un altro modo
 *   (persona, importo, rata, a che cosa va). La rettifica vale dalla data del versamento (dentro un esercizio aperto).
 * - `VERSAMENTO_TORNATO`: il versamento c'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato). La
 *   rettifica vale dal giorno dello storno.
 *
 * Le parole sono della decisione 219. Per un incasso fatto solo di credito (€ 0,00 in cassa) un versamento non c'è: le stesse due nature,
 * con le stesse date, si dicono con le parole della compensazione.
 */
enum NaturaStorno: string
{
    case ERRORE_REGISTRAZIONE = 'errore_registrazione';
    case VERSAMENTO_TORNATO = 'versamento_tornato';

    /** Come la dice la pagina dell'incasso, accanto a «Vale dal», che porta la data. */
    public function etichetta(bool $soloCredito = false): string
    {
        return match ([$this, $soloCredito]) {
            [self::ERRORE_REGISTRAZIONE, false] => 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo',
            [self::VERSAMENTO_TORNATO, false] => 'Il versamento c\'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato)',
            [self::ERRORE_REGISTRAZIONE, true] => 'La compensazione è sbagliata',
            [self::VERSAMENTO_TORNATO, true] => 'La compensazione era giusta ed è stata annullata',
        };
    }

    /** Come la dice il server quando lo storno di un incasso arriva senza la natura. */
    public static function domanda(bool $soloCredito): string
    {
        return $soloCredito
            ? 'Scegli perché storni questa compensazione: è sbagliata (si toglie dal giorno in cui è stata fatta) o era giusta e si annulla da oggi.'
            : 'Scegli perché storni questo incasso: registrazione sbagliata (vale dal giorno del versamento) o versamento tornato indietro (vale da oggi).';
    }

    /** Un incasso fatto solo di credito: nessun denaro in cassa (la riga in DARE della scrittura, come la legge l'elenco degli incassi). */
    public static function soloCredito(\App\Models\Gestionale\ScritturaContabile $incasso): bool
    {
        return (int) ($incasso->righe()->where('tipo_riga', 'dare')->sum('importo')) === 0;
    }
}
