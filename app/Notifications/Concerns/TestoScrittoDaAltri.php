<?php

namespace App\Notifications\Concerns;

use Illuminate\Support\Str;

/**
 * Un testo che non ha scritto il gestionale, dentro una mail che parte dal gestionale.
 *
 * Le mail sono Markdown: un nome, un oggetto, un commento, il titolo o la descrizione di un documento
 * scritti da altri non diventano un collegamento né un'immagine nella mail che arriva a un altro (il
 * vicino, l'amministratore), anche con la sintassi di un collegamento. Questi testi
 * arrivano come testo: le sole destinazioni di una mail sono il pulsante e gli indirizzi che il
 * gestionale costruisce. Un indirizzo scritto per intero resta parola; alcuni programmi di posta lo
 * rendono cliccabile da sé, e quello non dipende dal gestionale.
 *
 * Due misure, perché due sono i testi:
 * - un **nome** (di chi scrive, di chi approva, il saluto) come testo semplice, senza i caratteri che il
 *   Markdown interpreta, su una riga e corto: nessun nome diventa un collegamento o una frase;
 * - un **testo** (oggetto, commento, titolo e descrizione di un documento) tiene la sua lunghezza e la
 *   punteggiatura che serve a chi legge, e perde solo i caratteri senza i quali non si forma un
 *   collegamento né un'immagine: le parentesi quadre. Un collegamento, in Markdown, si apre solo con una
 *   parentesi quadra (`[testo](indirizzo)`, i riferimenti, le immagini) o con un'angolare (`<indirizzo>`,
 *   l'HTML), e il convertitore delle mail non ha l'estensione dei collegamenti automatici. Le angolari le
 *   rende innocue il modello delle mail (`resources/views/vendor/notifications/email.blade.php`): scrive
 *   ogni riga e il saluto con `{{ }}`, quindi `<` arriva al Markdown come `&lt;`, che resta un carattere da
 *   leggere. Per questo un testo le tiene: «Temperatura < 18 gradi» arriva intero.
 */
trait TestoScrittoDaAltri
{
    /**
     * Un nome scritto da altri, come testo semplice: senza i caratteri che il Markdown interpreta
     * (collegamenti, immagini, enfasi, codice), su una riga, al massimo sessanta caratteri.
     */
    public static function nomeComeTesto(?string $nome): string
    {
        $senzaMarkdown = preg_replace('/[\[\]()<>*_`\\\\]/u', '', (string) $nome) ?? '';
        $suUnaRiga = trim(preg_replace('/\s+/u', ' ', $senzaMarkdown) ?? '');

        return Str::limit($suUnaRiga, 60, '…');
    }

    /**
     * Un testo scritto da altri, senza le parentesi quadre che formano collegamenti e immagini, su una
     * riga (come la mail lo mette comunque). Le angolari restano: le neutralizza il modello delle mail.
     * La lunghezza resta quella che il chiamante sceglie.
     */
    public static function testoComeTesto(?string $testo): string
    {
        $senzaCollegamenti = preg_replace('/[\[\]]/u', '', (string) $testo) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $senzaCollegamenti) ?? '');
    }
}
