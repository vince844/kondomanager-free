<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

/**
 * Il tipo di un fondo e se il suo denaro si può usare, in un posto solo. I tipi sono quelli della cassa (`casse.sottotipo_fondo`): generico; vincolato ai
 * lavori, con la descrizione del vincolo; TFR; morosità. Lo sblocco dell'assemblea (`is_override_assemblea`, con la motivazione) rende usabile un fondo
 * vincolato.
 *
 * SI PUÒ USARE: un fondo generico o sbloccato dall'assemblea. Un fondo senza tipo vale vincolato, come nello Stato patrimoniale
 * ({@see LiquiditaVincolataService}) e nella tesoreria: è un fondo di prima che il tipo esistesse, e non si presume libero. La stessa regola dice se un
 * fondo paga una spesa imprevista (`Cassa::is_utilizzabile_per_imprevisti`), la riga «Si può usare» della scheda «Cassa» del rendiconto
 * ({@see FondiNelConto}), la liquidità vincolata dello Stato patrimoniale e «Disponibile oggi» della tesoreria.
 *
 * QUANTO TRATTIENE: un fondo che non si può usare trattiene il suo saldo, se è sopra zero ({@see trattiene()}). Un fondo vincolato in rosso ha pagato con
 * denaro della parte libera: il rosso è già nel totale, e non si toglie una seconda volta. Così ciò che si può usare non supera mai tutto il denaro.
 *
 * LE DATE. La scheda «Cassa» legge le registrazioni dell'esercizio, anche quelle con una data dopo oggi; la tesoreria e lo Stato patrimoniale fino al giorno
 * chiesto. Con un conto solo e senza registrazioni dopo oggi le tre dicono la stessa cifra; con una registrazione dopo oggi la scheda la conta già.
 */
final class TipoDelFondo
{
    /** Il denaro di un fondo con questo tipo si può usare? */
    public static function siPuoUsare(?string $sottotipo, bool $sbloccato): bool
    {
        return $sottotipo === 'generico' || $sbloccato;
    }

    /** Quanto un fondo toglie dal denaro che si può usare: il suo saldo se non si può usare ed è sopra zero; niente se si può usare o è in rosso. */
    public static function trattiene(int $saldo, bool $siPuoUsare): int
    {
        return $siPuoUsare ? 0 : max(0, $saldo);
    }

    /**
     * Quanto si può usare di un conto: la sua parte libera più i suoi fondi, tolto ciò che i fondi che non si possono usare trattengono ({@see trattiene()}).
     * Un fondo che si può usare conta con il suo saldo, anche in rosso; uno vincolato in rosso conta con il suo rosso, che è denaro uscito dalla parte libera.
     *
     * @param  iterable<array{sottotipo:?string, sbloccato:bool, saldo:int}>  $fondi
     */
    public static function quantoSiPuoUsare(int $libero, iterable $fondi): int
    {
        $usabile = $libero;
        foreach ($fondi as $f) {
            $saldo = (int) $f['saldo'];
            $usabile += $saldo - self::trattiene($saldo, self::siPuoUsare($f['sottotipo'] ?? null, (bool) ($f['sbloccato'] ?? false)));
        }

        return $usabile;
    }

    /**
     * L'etichetta del tipo di un fondo, e il suo tono: «libero» (si può usare), «vincolato», «sbloccato» (vincolato, sbloccato dall'assemblea),
     * «non_indicato» (senza tipo: conta come vincolato).
     *
     * @return array{testo:string, tono:string}
     */
    public static function etichetta(?string $sottotipo, ?string $vincolo, bool $sbloccato, ?string $motivazione): array
    {
        $con = fn (string $testo, ?string $dettaglio) => $testo.(trim((string) $dettaglio) !== '' ? ': '.trim((string) $dettaglio) : '');
        if ($sottotipo === 'generico') {
            return ['testo' => 'generico, si può usare', 'tono' => 'libero'];
        }
        if ($sbloccato) {
            return ['testo' => $con('vincolato, sbloccato dall\'assemblea', $motivazione), 'tono' => 'sbloccato'];
        }

        return match ($sottotipo) {
            'vincolato_lavori' => ['testo' => $con('vincolato ai lavori', $vincolo), 'tono' => 'vincolato'],
            'tfr' => ['testo' => 'TFR', 'tono' => 'vincolato'],
            'morosita' => ['testo' => 'morosità', 'tono' => 'vincolato'],
            default => ['testo' => LiquiditaVincolataService::SOTTOTIPO_NON_INDICATO.': conta come vincolato', 'tono' => 'non_indicato'],
        };
    }
}
