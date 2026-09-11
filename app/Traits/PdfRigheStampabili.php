<?php

namespace App\Traits;

/**
 * Quante righe di una tabella a blocchi (vedi il commento in `libro_giornale.blade.php`) mPDF
 * può stampare senza schiantarsi, dato il `memory_limit` vero dell'host.
 *
 * Misurata una sola volta, sul Libro Giornale, nella Fase 1-bis della beta.23 — rilievo di punta
 * trovato da tre lenti indipendenti dopo che la stampa esauriva la memoria PRIMA del tempo e
 * moriva con un fatal error dentro mPDF: pagina bianca, nessuna eccezione applicativa, niente nei
 * log. La tabella a blocchi ha portato la capienza da ~1.000 a ~4.500 righe con
 * `memory_limit = 128M`, misurato su quello stesso template:
 *
 *   2.000 righe → 100 MB · 3.500 → 116 MB · 4.500 → 122 MB · 6.000 → oltre 128 MB (fatale)
 *
 * Da cui: **~78 MB di base** (Laravel + mPDF + font) e **~0,0105 MB per riga**, con un margine
 * dell'85% perché l'ultima allocazione di mPDF è un blocco unico da decine di MB (misurato: 32 MB).
 *
 * ⚠️ **Ogni stampa che riusa questo trait riusa la MISURA del Libro Giornale, non la propria.**
 * La beta.24 ha provato a dichiarare un tetto più alto per il registro di contabilità
 * ragionando che «ogni riga qui è più semplice» — un'assunzione mai misurata, e la Fase 1-bis
 * l'ha trovata falsa guardando il markup vero (due celle multi-riga su ogni riga, non meno
 * annidamento del Libro Giornale). Finché non esiste una misura propria di un template, il tetto
 * corretto è quello già misurato, non uno inventato per ottimismo — è esattamente il difetto che
 * questa formula esiste per evitare.
 */
trait PdfRigheStampabili
{
    private static function righeStampabili(): int
    {
        return self::righeStampabiliCon(ini_get('memory_limit'));
    }

    /**
     * La formula, separata da `ini_get()` perché sia misurabile.
     *
     * Tenerle insieme rendeva il tetto testabile solo abbassando davvero il `memory_limit` del
     * processo — cosa che fallisce appena la suite ha già allocato più di quel valore («Failed
     * to set memory limit to 134217728 bytes, current usage is 210763776»). Il calcolo non ha
     * bisogno dello stato del processo: gli basta la stringa.
     *
     * @param string|false $limite il valore grezzo di `memory_limit` ('128M', '1G', '-1', …)
     */
    private static function righeStampabiliCon($limite): int
    {
        // '-1' = nessun limite (tipico da riga di comando): nessun tetto da applicare.
        if ($limite === false || (int) $limite === -1) {
            return PHP_INT_MAX;
        }

        $unita = strtoupper(substr(trim((string) $limite), -1));
        $valore = (float) $limite;
        $mb = match ($unita) {
            'G' => $valore * 1024,
            'K' => $valore / 1024,
            default => $unita === 'M' ? $valore : $valore / 1048576,
        };

        $disponibiliMb = ($mb * 0.85) - 78;

        // Sotto la base non si stampa comunque nulla: si lascia un minimo simbolico, così
        // l'errore che l'amministratore riceve resta questo messaggio e non un fatale.
        return max(200, (int) ($disponibiliMb / 0.0105));
    }
}
