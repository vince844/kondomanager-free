<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Errori;

use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Support\CodiceDellErrore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;
use WeakMap;

/**
 * Il registro degli errori nel database (decisione 698 (1)), accanto al file `storage/logs/laravel.log`, che non cambia.
 *
 * Lo chiama il gestore delle eccezioni (`bootstrap/app.php`) per ogni errore che il registro scrive come guasto: i 500 delle pagine, anche quelli scritti a
 * mano (`abort(500)`, che Laravel non scrive nel registro: lo fa {@see registraAMano()}), e gli errori dei lavori in background. Non i 404, 403, 419, 429 né i
 * moduli compilati male, che Laravel non scrive.
 *
 * - Un errore per impronta ({@see Ripulitura::impronta()}) con quante volte, la prima e l'ultima; una riga per volta, con il codice
 *   ({@see CodiceDellErrore}) e i dati ripuliti ({@see Ripulitura}).
 * - Al massimo {@see MASSIMO_AL_GIORNO} volte al giorno per lo stesso errore: dopo cresce solo il conteggio, e il codice resta nel file.
 * - **L'errore del database che non risponde non si prova a scrivere nel database**: non si fa aspettare la pagina, e il codice resta nel file.
 * - Un errore di scrittura qui non diventa un secondo errore: si ingoia, e la pagina lo sa ({@see esito()}).
 *
 * L'esito di ogni eccezione resta in memoria per la pagina d'errore della stessa richiesta: salvata o no, e perché.
 */
final class RegistroDegliErrori
{
    /** Quanti giorni si tiene una volta: la pulizia di ogni notte (`model:prune`) toglie le più vecchie. */
    public const GIORNI = 90;

    public const MASSIMO_AL_GIORNO = 200;

    public const DATABASE = 'database';

    public const TROPPE = 'troppe';

    /** I segni di una connessione che non c'è (MySQL, MariaDB, PostgreSQL, SQLite). */
    private const CONNESSIONE_MANCATA = [
        '[2002]', '[2003]', '[2005]', '[2006]', '[2013]', '[2054]', '[1044]', '[1045]', '[1049]', 'SQLSTATE[08', 'Connection refused', 'server has gone away',
        'Lost connection', 'getaddrinfo', 'php_network_getaddresses', 'No such file or directory', 'could not find driver', 'Can\'t connect',
        'Connection timed out', 'is not allowed to connect', 'unable to open database file', 'Too many connections', 'Unknown database',
    ];

    /** @var WeakMap<Throwable, array{salvato: bool, motivo: ?string, volta_id: ?int}>|null */
    private static ?WeakMap $esiti = null;

    /** @return array{salvato: bool, motivo: ?string, volta_id: ?int} */
    public static function registra(Throwable $e): array
    {
        if (($gia = self::esito($e)) !== null) {
            return $gia;
        }
        $esiti = self::$esiti ??= new WeakMap();
        $codice = CodiceDellErrore::di($e);

        if (self::eIlDatabaseCheNonRisponde($e)) {
            return $esiti[$e] = ['salvato' => false, 'motivo' => self::DATABASE, 'volta_id' => null];
        }

        try {
            $dati = self::datiDellaVolta($e, $codice);
            $adesso = $dati['avvenuto_il'];
            $errore = Errore::query()->firstOrCreate(['impronta' => $dati['impronta']], [
                'classe' => $dati['classe'], 'messaggio' => $dati['messaggio'], 'file' => $dati['file'], 'riga' => $dati['riga'], 'volte' => 0,
                'prima_volta_il' => $adesso, 'ultima_volta_il' => $adesso, 'stato' => Errore::NUOVO,
            ]);
            $oggi = VoltaDellErrore::query()->where('errore_id', $errore->id)->where('avvenuto_il', '>=', $adesso->copy()->startOfDay())->count();
            Errore::query()->whereKey($errore->id)->update(['volte' => DB::raw('volte + 1'), 'ultima_volta_il' => $adesso]);
            if ($oggi >= self::MASSIMO_AL_GIORNO) {
                return $esiti[$e] = ['salvato' => false, 'motivo' => self::TROPPE, 'volta_id' => null];
            }
            unset($dati['impronta']);
            $volta = VoltaDellErrore::query()->create(['errore_id' => $errore->id] + $dati);

            return $esiti[$e] = ['salvato' => true, 'motivo' => null, 'volta_id' => (int) $volta->id];
        } catch (Throwable) {
            return $esiti[$e] = ['salvato' => false, 'motivo' => self::DATABASE, 'volta_id' => null];
        }
    }

    /**
     * Un errore 500 scritto a mano dal programma (`abort(500, …)`): Laravel non lo scrive nel registro, perché è un errore HTTP. Si scrive qui, con il codice
     * nella stessa riga, e nel database. Il testo resta nel file del server; alla pagina e alla risposta JSON non arriva.
     */
    public static function registraAMano(Throwable $e): void
    {
        if (self::esito($e) !== null) {
            return;
        }
        try {
            Log::error($e->getMessage() !== '' ? $e->getMessage() : 'Errore interno', ['codice_errore' => CodiceDellErrore::di($e), 'exception' => $e]);
        } catch (Throwable) {
            // Il registro su file che non si scrive non ferma la pagina.
        }
        self::registra($e);
    }

    /** @return array{salvato: bool, motivo: ?string, volta_id: ?int}|null */
    public static function esito(Throwable $e): ?array
    {
        if (self::$esiti === null) {
            return null;
        }
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (isset(self::$esiti[$x])) {
                return self::$esiti[$x];
            }
        }

        return null;
    }

    public static function eIlDatabaseCheNonRisponde(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (! $x instanceof PDOException && ! $x instanceof QueryException) {
                continue;
            }
            foreach (self::CONNESSIONE_MANCATA as $segno) {
                if (str_contains($x->getMessage(), $segno)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Le colonne di una volta (e l'impronta del suo errore), ripulite: quelle che si salvano e che il rapporto legge. Servono anche alla pagina quando la volta
     * non si è potuta salvare.
     *
     * @return array<string, mixed>
     */
    public static function datiDellaVolta(Throwable $e, string $codice): array
    {
        $messaggio = Ripulitura::messaggio($e);
        $punto = Ripulitura::puntoDelProgramma($e);
        $richiesta = app()->bound('request') ? request() : null;

        // Le colonne hanno una lunghezza: un nome di classe anonima o una rotta lunghissima si tagliano, non fanno fallire la scrittura.
        $taglia = fn (?string $testo, int $quanto) => $testo === null ? null : mb_substr($testo, 0, $quanto);
        $pagina = Ripulitura::pagina($e, $richiesta);

        return [
            'impronta' => Ripulitura::impronta($e, $messaggio),
            'codice' => $codice,
            'avvenuto_il' => now(),
            'metodo' => $taglia($pagina['metodo'], 10),
            'pagina' => $taglia($pagina['pagina'], 500),
            'rotta' => $taglia($pagina['rotta'], 255),
            'azione' => $taglia($pagina['azione'], 255),
            'ruolo' => $taglia(self::ruolo(), 50),
            'versione' => (string) config('app.version'),
            'versione_php' => PHP_VERSION,
            'versione_laravel' => app()->version(),
            'database' => rescue(fn () => DB::connection()->getDriverName(), null, false),
            'classe' => $taglia(get_class($e), 255),
            'messaggio' => mb_substr($messaggio, 0, 5000),
            'causa' => ($causa = Ripulitura::causa($e)) === null ? null : mb_substr($causa, 0, 2000),
            'file' => $taglia($punto['file'], 500),
            'riga' => $punto['riga'],
            'traccia' => Ripulitura::traccia($e),
        ];
    }

    /** Il ruolo di chi ha incontrato l'errore, mai il nome; null per un visitatore o un lavoro in background. */
    private static function ruolo(): ?string
    {
        return rescue(function () {
            $utente = auth()->user();

            return $utente !== null && method_exists($utente, 'getRoleNames') ? ($utente->getRoleNames()->first() ?: null) : null;
        }, null, false);
    }
}
