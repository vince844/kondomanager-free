<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Installer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Il controllo «il database è vuoto» del wizard d'installazione (Coda 755 (3)).
 *
 * Il passo del database del wizard lancia `migrate:fresh`, che cancella **tutte** le tabelle di un database che ha la tabella `migrations`, anche
 * quelle di un altro programma. Fino alla 1.12.0-beta.13 la «verifica» non apriva nessuna connessione. Ora il wizard si ferma se il database ha delle
 * tabelle, di qualunque programma, senza un pulsante che le cancella: si crea un database nuovo e vuoto, o lo si svuota a mano.
 *
 * Lo chiamano tre punti: «Testa connessione» (`FixedEnvironmentSettings`, con un avviso), il passo del wizard prima di scrivere il `.env`
 * (`InstallerWizard::runEnvironmentSetup`) e, per cintura, `PreparaDatabase::migra(fresh: true)`.
 *
 * **Il passo ripetuto.** Chi torna indietro nel wizard e rifà il passo sullo stesso database che il wizard ha appena preparato (host, porta e nome
 * ricordati nel file di progresso, e nessun lock d'installazione) non si ferma: le tabelle sono quelle sue. Un database lasciato a metà da
 * un'installazione fallita va svuotato a mano.
 */
class ControlloDatabaseVuoto
{
    /** La chiave del file di progresso in cui il wizard ricorda il database che ha appena preparato. */
    public const CHIAVE_PROGRESSO = 'database_preparato';

    /**
     * I nomi delle tabelle (le viste no) della connessione data.
     *
     * @return list<string>
     */
    public function tabelle(string $connessione): array
    {
        return array_values(array_map(
            fn (array $tabella) => (string) $tabella['name'],
            Schema::connection($connessione)->getTables(),
        ));
    }

    /**
     * Si ferma se il database ha delle tabelle.
     *
     * @param  string  $nomeDatabase  il nome che la frase dice all'amministratore
     * @param  bool  $preparatoDalWizard  il database che il wizard ha appena preparato: passa
     *
     * @throws \DomainException con la frase del wizard, nella lingua del wizard
     */
    public function controlla(string $connessione, string $nomeDatabase, bool $preparatoDalWizard = false): void
    {
        if ($preparatoDalWizard) {
            return;
        }

        $tabelle = $this->tabelle($connessione);

        if ($tabelle !== []) {
            throw new \DomainException($this->frase($nomeDatabase, count($tabelle)));
        }
    }

    /** La frase che dice che il database non è vuoto: il nome, il numero delle tabelle, che cosa fare. */
    public function frase(string $nomeDatabase, int $tabelle): string
    {
        return trans_choice('installer.database.non_vuoto', $tabelle, ['database' => $nomeDatabase, 'n' => $tabelle]);
    }

    /**
     * Apre una connessione di controllo con le credenziali date, sul modello di `mysql`, e la chiude alla fine: non tocca la connessione
     * dell'applicazione né il `.env`.
     *
     * @param  array{host: string, port: string|int, database: string, username: ?string, password: ?string}  $credenziali
     */
    public function controllaCredenziali(array $credenziali, bool $preparatoDalWizard = false): void
    {
        config(['database.connections.installer_controllo' => array_merge(
            config('database.connections.mysql', []),
            [
                'host' => $credenziali['host'],
                'port' => $credenziali['port'],
                'database' => $credenziali['database'],
                'username' => $credenziali['username'],
                'password' => $credenziali['password'] ?? '',
            ]
        )]);

        try {
            $this->controlla('installer_controllo', $credenziali['database'], $preparatoDalWizard);
        } finally {
            DB::purge('installer_controllo');
        }
    }

    /** Host, porta e nome del database in una stringa sola: ciò che il wizard ricorda del database che ha preparato. */
    public function impronta(string $host, string|int $porta, string $database): string
    {
        return $host.'|'.$porta.'|'.$database;
    }

    /** Il wizard ha appena preparato proprio questo database, e l'installazione non è finita (nessun lock). */
    public function preparatoDalWizard(string $fileProgresso, string $host, string|int $porta, string $database): bool
    {
        if (File::exists((string) config('installer.options.lock_file'))) {
            return false;
        }

        $progresso = $this->leggi($fileProgresso);

        return ($progresso[self::CHIAVE_PROGRESSO] ?? null) === $this->impronta($host, $porta, $database);
    }

    /** Il wizard ha preparato il database: lo scrive nel file di progresso, senza toccare il resto. */
    public function ricordaDatabasePreparato(string $fileProgresso, string $host, string|int $porta, string $database): void
    {
        $progresso = $this->leggi($fileProgresso);
        $progresso[self::CHIAVE_PROGRESSO] = $this->impronta($host, $porta, $database);

        File::put($fileProgresso, json_encode($progresso, JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function leggi(string $fileProgresso): array
    {
        if (! File::exists($fileProgresso)) {
            return [];
        }

        $letto = json_decode(File::get($fileProgresso), true);

        return is_array($letto) ? $letto : [];
    }
}
