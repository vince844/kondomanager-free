<?php

namespace App\Enums;

/**
 * Com'è andata la registrazione del saldo di apertura di una cassa.
 *
 * Nasce nella beta.45 perché `RegistraAperturaCassaAction` restituiva `false` in **cinque**
 * situazioni diverse e loggava un warning che l'utente non vede mai. Finché l'azione aveva un
 * solo chiamante interno andava bene; nel momento in cui la si espone dietro un pulsante,
 * «non è stato possibile» senza dire *cosa manca* sposta il vicolo cieco di un passo invece di
 * toglierlo. È la lezione delle due `IdempotencyKeyConflittoException` distinte della beta.37.
 *
 * ## I sei esiti, contati sul codice
 *
 * Cinque escono da un `return false` e uno è un'eccezione — la partita doppia che non quadra,
 * che resta un guasto vero e non un esito da mostrare. La roadmap ne contava quattro perché
 * teneva insieme esercizio e contropartita, che sono due mancanze diverse con due rimedi
 * diversi.
 *
 * **Due dei sei non sono errori**, e questa è la distinzione che il pulsante deve rispettare:
 * importo zero e apertura già presente significano «non c'è niente da fare», non «è andata
 * male». Dirlo con la stessa faccia sarebbe il difetto che stiamo correggendo, al contrario.
 */
enum EsitoAperturaCassa: string
{
    case REGISTRATA = 'registrata';
    case IMPORTO_ZERO = 'importo_zero';
    case GIA_REGISTRATA = 'gia_registrata';
    case CONTO_MANCANTE = 'conto_mancante';
    case ESERCIZIO_MANCANTE = 'esercizio_mancante';
    case CONTROPARTITA_MANCANTE = 'contropartita_mancante';

    /** La scrittura è stata creata adesso. */
    public function riuscita(): bool
    {
        return $this === self::REGISTRATA;
    }

    /**
     * Non c'è niente da registrare, e non è un problema: la cassa è già in ordine.
     * Serve a non presentare come fallimento uno stato corretto.
     */
    public function giaAPosto(): bool
    {
        return $this === self::IMPORTO_ZERO || $this === self::GIA_REGISTRATA;
    }

    /**
     * Cosa dire all'amministratore. Ogni messaggio nomina **il rimedio**, non solo la causa:
     * un messaggio che descrive un ostacolo senza dire come si supera lascia esattamente dov'era
     * chi lo legge.
     */
    public function messaggio(): string
    {
        return match ($this) {
            self::REGISTRATA => 'Saldo di apertura registrato in contabilità. Lo Stato Patrimoniale ora tiene conto di questa liquidità.',
            self::IMPORTO_ZERO => 'Questa risorsa non ha un saldo di apertura da registrare: non c\'è niente da fare.',
            self::GIA_REGISTRATA => 'Il saldo di apertura di questa risorsa è già in contabilità.',
            self::CONTO_MANCANTE => 'Questa risorsa non ha un conto contabile collegato, quindi non c\'è dove registrare l\'apertura. Va ricreata dalla sezione «Risorse e fondi», che il conto lo crea sempre.',
            self::ESERCIZIO_MANCANTE => 'Il condominio non ha ancora nessun esercizio contabile: l\'apertura si aggancia al primo, quindi va creato prima l\'esercizio.',
            self::CONTROPARTITA_MANCANTE => 'Manca il conto «Fondo Passate Gestioni», che è la contropartita dell\'apertura. Senza, la scrittura non quadrerebbe: va ripristinato nel piano dei conti del condominio.',
        };
    }
}
