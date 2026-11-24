<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Errori;

use App\Models\Errori\VoltaDellErrore;
use Carbon\CarbonInterface;

/**
 * Il rapporto dell'errore (decisione 698 (3); bozza docs/design/beta14_pagine_errore_grafica_bozza.html, scena 10): il testo per chi sviluppa, lo stesso con
 * «Copia il rapporto» e con «Scarica il rapporto» (`rapporto-errore-K7M2QX.txt`). Dice dove, quando, con quale versione, che cosa: il codice, l'ora con il
 * fuso, le versioni del programma, di PHP e di Laravel e il tipo di database, la pagina con i segnaposto e l'azione, il ruolo di chi l'ha incontrato (mai il
 * nome), quante volte e l'impronta, se qualcuno ha avvisato (il ruolo e l'ora; la nota no, l'ha scritta una persona e può contenere nomi), il messaggio
 * ripulito, la causa, la traccia senza i valori (le righe del framework di fila raccolte in una). In fondo la riga di ciò che è garantito: niente
 * password, dati dei moduli, valori delle query, nota; i nomi scritti in un messaggio possono restare ({@see Ripulitura}), e la riga lo dice.
 *
 * Legge solo le colonne già ripulite di {@see VoltaDellErrore} ({@see Ripulitura}).
 */
final class RapportoDellErrore
{
    public const TOLTI = 'Senza password, senza i dati dei moduli e i valori delle query, senza la nota di chi ha avvisato; i nomi scritti nei messaggi '
        .'possono restare: rileggilo prima di mandarlo.';

    /** Come si legge il ruolo nel rapporto. */
    private const RUOLI = [
        'utente' => 'condòmino',
        'amministratore' => 'amministratore dello studio',
        'collaboratore' => 'collaboratore dello studio',
        'fornitore' => 'fornitore',
    ];

    public static function testo(VoltaDellErrore $volta): string
    {
        $errore = $volta->relationLoaded('errore') || $volta->errore_id !== null ? $volta->errore : null;
        $etichetta = fn (string $nome) => str_pad($nome, 14);
        $righe = [
            'KondoManager · rapporto dell\'errore '.$volta->codice,
            '',
            $etichetta('Quando').self::quando($volta->avvenuto_il, true).', ora di Roma',
            $etichetta('Programma').'KondoManager '.($volta->versione ?: '?'),
            $etichetta('Ambiente').'PHP '.($volta->versione_php ?: '?').' · Laravel '.($volta->versione_laravel ?: '?').' · database '.self::database($volta->database),
            $etichetta('Pagina').($volta->pagina !== null ? trim(($volta->metodo ?? '').' '.$volta->pagina) : 'nessuna'),
            $etichetta('Azione').($volta->azione ?: '?'),
            $etichetta('Chi').self::chiPerIlRapporto($volta),
        ];
        if ($errore !== null) {
            $righe[] = $etichetta('Volte').$errore->volte.', dal '.self::quando($errore->prima_volta_il, false).' · impronta '.$errore->improntaBreve();
        }
        $righe[] = $etichetta('Avvisato').($volta->avvisato_il !== null
            ? 'sì, il '.self::quando($volta->avvisato_il, false).', dal ruolo «'.($volta->avvisato_ruolo ?: '?').'»'
            : 'no');
        $righe = [...$righe, '', 'L\'errore', $volta->classe, (string) $volta->messaggio];
        if ($volta->file !== null) {
            $righe[] = 'Nel programma: '.$volta->file.($volta->riga !== null ? ', riga '.$volta->riga : '');
        }
        if ($volta->causa !== null) {
            $righe[] = 'Causa: '.$volta->causa;
        }
        $traccia = self::righeDellaTraccia((array) ($volta->traccia ?? []));
        if ($traccia !== []) {
            $righe = [...$righe, '', 'La traccia (senza i valori)', ...$traccia];
        }
        $righe = [...$righe, '', self::TOLTI];

        return implode("\n", $righe)."\n";
    }

    public static function nomeDelFile(VoltaDellErrore $volta): string
    {
        return 'rapporto-errore-'.$volta->codice.'.txt';
    }

    /**
     * La traccia come si legge: una riga per passo, e le righe del framework di fila raccolte in una («… altre 6 righe del framework»), salvo la prima.
     *
     * @param  array<int, array{file?: ?string, riga?: ?int, funzione?: string}>  $traccia
     * @return list<string>
     */
    public static function righeDellaTraccia(array $traccia): array
    {
        $righe = [];
        $raccolte = 0;
        $chiudi = function () use (&$righe, &$raccolte) {
            if ($raccolte === 1) {
                $righe[] = '    … un\'altra riga del framework';
            } elseif ($raccolte > 1) {
                $righe[] = '    … altre '.$raccolte.' righe del framework';
            }
            $raccolte = 0;
        };
        foreach (array_values($traccia) as $i => $passo) {
            $file = $passo['file'] ?? null;
            $delFramework = $file === null || str_starts_with((string) $file, 'vendor/');
            if ($i > 0 && $delFramework) {
                $raccolte++;

                continue;
            }
            $chiudi();
            $righe[] = '#'.$i.'  '.($file !== null ? $file.(isset($passo['riga']) ? ':'.$passo['riga'] : '') : '[interno]').'  '.($passo['funzione'] ?? '');
        }
        $chiudi();

        return $righe;
    }

    /** Chi ha incontrato l'errore, come lo legge l'amministratore nella scheda: il ruolo, mai il nome. */
    public static function chiPerLaScheda(?string $ruolo, bool $conLaPagina): string
    {
        return match (true) {
            $ruolo === 'utente' => 'un condòmino',
            $ruolo === 'amministratore' => 'l\'amministratore',
            $ruolo === 'collaboratore' => 'un collaboratore',
            $ruolo === 'fornitore' => 'un fornitore',
            $ruolo !== null => 'il ruolo «'.$ruolo.'»',
            $conLaPagina => 'un visitatore',
            default => 'un lavoro in background',
        };
    }

    public static function quando(?CarbonInterface $istante, bool $conISecondi): string
    {
        if ($istante === null) {
            return '?';
        }
        $roma = $istante->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));

        return $roma->format('d/m/Y').' alle '.$roma->format($conISecondi ? 'H:i:s' : 'H:i');
    }

    private static function chiPerIlRapporto(VoltaDellErrore $volta): string
    {
        if ($volta->ruolo === null) {
            return $volta->metodo !== null ? 'un visitatore non collegato' : 'nessuno: un lavoro in background';
        }
        $come = self::RUOLI[$volta->ruolo] ?? null;

        return 'il ruolo «'.$volta->ruolo.'»'.($come !== null && $come !== $volta->ruolo ? ' ('.$come.')' : '').', senza il nome';
    }

    private static function database(?string $driver): string
    {
        return match ($driver) {
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'sqlite' => 'SQLite',
            'pgsql' => 'PostgreSQL',
            null, '' => '?',
            default => $driver,
        };
    }
}
