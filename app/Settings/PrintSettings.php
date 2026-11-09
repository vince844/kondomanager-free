<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PrintSettings extends Settings
{
    public ?string $nota_legale_stampe = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';
    public ?string $firma_stampe_path = null;

    /**
     * Lo studio dell'amministratore: in testa a tutte le stampe tranne il modello F24, accanto al condominio (nelle lettere, nelle ricevute e negli
     * avvisi sopra il condominio, perché a destra c'è il destinatario). Senza il nome le stampe hanno la testata di sempre. Vuoti finché
     * l'amministratore non li scrive. L'email è anche quella a cui si risponde alle lettere.
     */
    public ?string $studio_nome = null;
    public ?string $studio_indirizzo = null;
    public ?string $studio_email = null;
    public ?string $studio_pec = null;
    public ?string $studio_telefono = null;
    /** Il comune dello studio: accanto all'indirizzo nella testata delle stampe, e il luogo delle lettere e degli avvisi; senza, quello del condominio. */
    public ?string $studio_comune = null;
    /** La partita IVA (11 cifre), il codice fiscale (11 cifre, o 16 lettere e cifre, in maiuscolo) e il sito dello studio. */
    public ?string $studio_partita_iva = null;
    public ?string $studio_codice_fiscale = null;
    public ?string $studio_sito = null;
    /** Il logo dello studio, sul disco dei file pubblici: un PNG riscritto al caricamento ({@see \App\Services\PDF\ImmagineDiStampa}). */
    public ?string $logo_stampe_path = null;

    /**
     * La carta dello studio di ogni gruppo di documenti ({@see \App\Services\PDF\GruppoCarta}): per gruppo l'id di una carta di `carte_stampa`,
     * o nullo per la predefinita. Un id che non c'è più vale la predefinita. (Senza il tipo degli elementi nel commento: la libreria delle
     * impostazioni lo leggerebbe come un tipo da convertire, e per un intero non ha la conversione.)
     */
    public array $carta_per_gruppo = [];

    public static function group(): string
    {
        return 'print';
    }
}
