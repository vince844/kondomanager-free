<?php

namespace App\Support;

/**
 * La virgola che un amministratore italiano batte davanti a una visura.
 *
 * ## Perché esiste
 *
 * Segnalazione dal forum, agosto 2026: *«Nello specifico ho provato sia ad inserire "6.5" che
 * "6,5"»*. Il primo veniva rifiutato dalla regola `integer`, il secondo dalla regola **e** dal
 * separatore: due rifiuti per lo stesso gesto, e nessuno dei due visibile a schermo.
 *
 * La convenzione del progetto è quella scritta nella beta.61 per i millesimi, e vale anche qui:
 * *«chi la batte per abitudine non deve essere corretto da un messaggio d'errore, gli si
 * normalizza il valore e basta»*. Il posto giusto per farlo è **prima** della validazione, così
 * la regola vede sempre e solo il punto e il messaggio d'errore, quando serve, parla del numero e
 * non del separatore.
 *
 * ## Perché sta qui e non dentro le due request
 *
 * `CreateImmobileRequest` e `UpdateImmobileRequest` sono copie l'una dell'altra sul blocco dei
 * dati tecnici. Scrivere la normalizzazione in tutte e due significherebbe rifare l'errore che ha
 * prodotto la segnalazione sul download dei documenti: la stessa regola in due copie, corretta in
 * una sola. Una funzione, due chiamanti.
 *
 * ## Cosa NON fa
 *
 * **Non tocca il separatore delle migliaia**, e non ci prova: `1.234,56` e `1,234.56` sono
 * ambigui senza sapere quale convenzione ha in testa chi scrive, e indovinare su un numero è il
 * modo di sbagliarlo in silenzio. Qui i valori in gioco — vani e metri quadri — non hanno
 * migliaia, quindi il caso non si pone; il giorno che si ponesse, la risposta è rifiutare con un
 * messaggio, non tirare a indovinare.
 *
 * Non converte, non arrotonda e non valida: sostituisce un carattere. Se il valore non è un
 * numero resta com'è, e a rifiutarlo ci pensa la regola — che è chi ha il messaggio da dare.
 */
final class DecimaleItaliano
{
    /**
     * Sostituisce la virgola decimale con il punto, lasciando intatto tutto il resto.
     *
     * `null` resta `null` e la stringa vuota resta vuota: un campo facoltativo non compilato non
     * deve diventare uno zero, e nemmeno una stringa che la regola `nullable` non riconoscerebbe
     * più come assente.
     */
    public static function conIlPunto(mixed $valore): mixed
    {
        if (! is_string($valore)) {
            return $valore;
        }

        $valore = trim($valore);

        if ($valore === '' || ! str_contains($valore, ',')) {
            return $valore;
        }

        // Una virgola sola: `6,5,5` non è un numero scritto all'italiana, è un errore di battitura,
        // e va rifiutato dalla regola invece che raddrizzato in qualcosa che l'utente non ha scritto.
        if (substr_count($valore, ',') > 1) {
            return $valore;
        }

        return str_replace(',', '.', $valore);
    }
}
