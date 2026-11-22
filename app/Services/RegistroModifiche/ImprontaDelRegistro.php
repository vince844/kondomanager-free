<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

/**
 * Le impronte del registro (decisione 518, §3 del progetto): l'impronta dei valori nasce con la riga; l'impronta della riga, legata a quella di prima, la
 * scrive il sigillo. L'oscuramento cambia solo i valori: l'intestazione e `impronta_valori` restano, la catena non si rompe.
 *
 * Il JSON è **canonico**: chiavi ordinate a ogni livello, liste nell'ordine loro, Unicode e barre come sono. Così l'impronta ricalcolata dai valori riletti
 * dal database torna uguale anche se MySQL (colonna JSON) riordina le chiavi.
 */
final class ImprontaDelRegistro
{
    /** Le colonne dell'intestazione, nell'ordine in cui il progetto le elenca; più i condomini e i soggetti, che dal §18 (P7, P9) stanno in tabelle. */
    public const INTESTAZIONE = ['sequenza', 'id', 'avvenuta_il', 'registrata_il', 'autore_tipo', 'autore_id', 'autore_nome', 'autore_ruolo', 'origine',
        'origine_dettaglio', 'area', 'oggetto_tipo', 'oggetto_id', 'oggetto_nome', 'scheda_tipo', 'scheda_id', 'azione'];

    private const INTERI = ['sequenza', 'id', 'autore_id', 'oggetto_id', 'scheda_id'];

    public static function canonico(mixed $valore): string
    {
        return (string) json_encode(self::ordina($valore), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * L'impronta dei valori: `campi`, `riepilogo`, `perche`, `rimando`, `lotto`.
     *
     * @param array<string, mixed>|object $riga una riga del registro (array o oggetto di `DB::table`), con i JSON già decodificati o come testo
     */
    public static function deiValori(array|object $riga): string
    {
        $riga = (array) $riga;
        $valori = [];
        foreach (['campi', 'riepilogo', 'perche', 'rimando', 'lotto'] as $chiave) {
            $v = $riga[$chiave] ?? null;
            if (in_array($chiave, ['campi', 'rimando', 'lotto'], true) && is_string($v)) {
                $v = json_decode($v, true);
            }
            $valori[$chiave] = $v;
        }

        return hash('sha256', self::canonico($valori));
    }

    /**
     * L'intestazione della riga, con la sequenza data dal sigillo.
     *
     * @param array<string, mixed>|object $riga
     * @param array<int, string> $condomini id => nome
     * @param list<array{0: string, 1: int}> $soggetti
     * @return array<string, mixed>
     */
    public static function intestazione(array|object $riga, int $sequenza, array $condomini, array $soggetti): array
    {
        $riga = (array) $riga;
        $testa = [];
        foreach (self::INTESTAZIONE as $colonna) {
            $v = $colonna === 'sequenza' ? $sequenza : ($riga[$colonna] ?? null);
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d H:i:s');
            }
            if (in_array($colonna, ['avvenuta_il', 'registrata_il'], true) && is_string($v)) {
                // Un datetime riletto può avere i decimali dei secondi in un driver e non nell'altro.
                $v = substr($v, 0, 19);
            }
            $testa[$colonna] = in_array($colonna, self::INTERI, true) && $v !== null ? (int) $v : ($v === null ? null : (string) $v);
        }
        ksort($condomini);
        $testa['condomini'] = array_map(fn ($id, $nome) => [(int) $id, (string) $nome], array_keys($condomini), array_values($condomini));
        usort($soggetti, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $testa['soggetti'] = array_map(fn ($s) => [(string) $s[0], (int) $s[1]], $soggetti);

        return $testa;
    }

    /** L'impronta della riga: `sha256(impronta di prima | intestazione canonica | impronta dei valori)`. */
    public static function dellaRiga(?string $precedente, array $intestazione, string $improntaValori): string
    {
        return hash('sha256', ($precedente ?? '') . '|' . self::canonico($intestazione) . '|' . $improntaValori);
    }

    private static function ordina(mixed $valore): mixed
    {
        if (! is_array($valore)) {
            return $valore;
        }
        if (array_is_list($valore)) {
            return array_map(fn ($v) => self::ordina($v), $valore);
        }
        // Chiavi 0..n-1 fuori ordine: il JSON le scrive come oggetto, ma chi lo rilegge (MySQL le riordina) trova una lista. Si scrivono come lista,
        // nell'ordine delle chiavi, da tutte e due le parti.
        $chiavi = array_keys($valore);
        $interi = array_filter($chiavi, 'is_int');
        if (count($interi) === count($chiavi)) {
            sort($interi);
            if ($interi === range(0, count($interi) - 1)) {
                ksort($valore, SORT_NUMERIC);

                return array_map(fn ($v) => self::ordina($v), array_values($valore));
            }
        }
        ksort($valore, SORT_STRING);

        return array_map(fn ($v) => self::ordina($v), $valore);
    }
}
