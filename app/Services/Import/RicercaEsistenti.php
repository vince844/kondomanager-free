<?php

namespace App\Services\Import;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\Canonical\CanonicalSoggetto;

/**
 * «Questo esiste già in archivio?» — chiesto **una volta sola**, e da due posti.
 *
 * ## Perché è un servizio e non due metodi privati
 *
 * La ricerca del duplicato viveva dentro `LivelloSoggetti::cercaEsistente()` e
 * `LivelloCondominio::cercaEsistente()`, cioè dentro il **commit**: si scopriva il duplicato nel
 * momento in cui si stava già scrivendo. L'anteprima non poteva mostrarlo, perché non aveva
 * modo di farsi la stessa domanda senza duplicare il codice — e due copie della regola «cosa
 * conta come stessa persona» divergono al primo ritocco.
 *
 * Il risultato di quella separazione è stato un **vicolo cieco**: il motore emetteva un rilievo
 * «da decidere» che nessuna schermata sapeva mostrare, quindi alla seconda importazione sullo
 * stesso archivio l'utente arrivava a un «Si è fermata a Persone» senza niente da cliccare.
 * Adesso la domanda è una sola, e la fa anche l'anteprima.
 *
 * ## Il codice fiscale è la chiave, il nome è il ripiego
 *
 * Sul nome si sbaglia: due «Residenza Aurora» in comuni diversi esistono davvero, e due
 * «Rossi Mario» pure. Per questo il motivo del sospetto viaggia insieme al risultato — chi
 * decide deve sapere *su cosa* abbiamo trovato la somiglianza.
 */
final class RicercaEsistenti
{
    public const PER_CODICE_FISCALE = 'codice_fiscale';

    public const PER_NOME = 'nome';

    public const PER_PERIODO = 'periodo';

    public function soggetto(CanonicalSoggetto $dati): ?Anagrafica
    {
        if ($dati->haCodiceFiscale()) {
            return Anagrafica::where('codice_fiscale', $dati->codiceFiscale)->first();
        }

        return Anagrafica::whereRaw('LOWER(nome) = ?', [mb_strtolower(trim($dati->nome))])->first();
    }

    public function condominio(CanonicalCondominio $dati): ?Condominio
    {
        if ($dati->codiceFiscale !== null) {
            $perCf = Condominio::where('codice_fiscale', $dati->codiceFiscale)->first();

            if ($perCf !== null) {
                return $perCf;
            }
        }

        return Condominio::whereRaw('LOWER(nome) = ?', [mb_strtolower(trim($dati->nome))])->first();
    }

    /**
     * L'esercizio si riconosce dal **periodo**, non dal nome: «2024/2025» e «Esercizio 2024-25»
     * sono lo stesso anno contabile, e due esercizi sulle stesse date sdoppierebbero i saldi.
     *
     * Vive dentro un condominio, quindi senza quello la domanda non ha senso: alla prima
     * importazione non esiste ancora nulla con cui collidere.
     */
    public function esercizio(CanonicalEsercizio $dati, ?Condominio $condominio): ?Esercizio
    {
        if ($condominio === null) {
            return null;
        }

        return Esercizio::where('condominio_id', $condominio->getKey())
            ->whereDate('data_inizio', $dati->dataInizio->toDateString())
            ->whereDate('data_fine', $dati->dataFine->toDateString())
            ->first();
    }

    /**
     * Su cosa abbiamo trovato la somiglianza. Serve a chi decide, non a noi: «stesso codice
     * fiscale» è una certezza, «stesso nome» è un sospetto, e le due meritano risposte diverse.
     */
    public function motivoSoggetto(CanonicalSoggetto $dati): string
    {
        return $dati->haCodiceFiscale() ? self::PER_CODICE_FISCALE : self::PER_NOME;
    }

    public function motivoCondominio(CanonicalCondominio $dati, Condominio $esistente): string
    {
        return $dati->codiceFiscale !== null && $esistente->codice_fiscale === $dati->codiceFiscale
            ? self::PER_CODICE_FISCALE
            : self::PER_NOME;
    }
}
