<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Il sigillo del registro (§3 del progetto, P12): dà alle righe nate senza posto la sequenza, l'impronta di prima e l'impronta, **dopo** il commit della
 * modifica e in una transazione sua e breve (dentro una transazione già aperta, in un savepoint suo, rilasciato alla fine).
 *
 * Perché non dentro la transazione della modifica: una catena unica letta e scritta lì terrebbe una serratura globale fino al commit (un'importazione la
 * terrebbe per secondi) e aprirebbe stalli fra due modifiche che prendono le righe in ordine diverso. Qui la prima istruzione è la serratura sulla riga di
 * `registro_modifiche_catena`; le righe del registro si leggono senza serratura e si aggiornano con `WHERE id = ? AND sequenza IS NULL`, controllando che
 * ne sia toccata una. L'ordine della catena è quello del sigillo, non quello degli `id`: due transazioni che si chiudono a rovescio non lasciano buchi.
 *
 * **Una riga senza la firma del programma non entra nella catena** ({@see FirmaDelRegistro}): una riga aggiunta con il database, anche con l'impronta dei
 * valori giusta, resta senza sequenza, e «Verifica l'integrità» la segna «aggiunta fuori dal programma». Le righe dopo di lei si sigillano lo stesso.
 *
 * È uno dei tre servizi che scrivono su una riga già nata (con il modello è vietato, {@see \App\Models\RegistroModifica}).
 */
final class SigilloDelRegistro
{
    /** Quante righe per transazione: un'importazione grande non tiene la serratura a lungo. */
    public const BLOCCO = 500;

    /**
     * Sigilla tutte le righe ancora senza posto. Restituisce quante ne ha sigillate.
     *
     * @throws Throwable un errore del database: chi chiama dopo un commit usa {@see sigillaSenzaFermare()}
     */
    public static function sigilla(): int
    {
        $totale = 0;
        // L'ultimo `id` letto: una riga lasciata fuori (senza la firma) non si rilegge nello stesso sigillo, e non ferma quelle dopo di lei.
        $dopo = 0;
        do {
            // Senza serratura e fuori da una transazione: se non c'è niente da sigillare non si prende la catena.
            if (! DB::table('registro_modifiche')->whereNull('sequenza')->where('id', '>', $dopo)->exists()) {
                break;
            }
            [$fatte, $lette, $dopo] = DB::transactionLevel() === 0
                ? DB::transaction(fn () => self::unBlocco($dopo))
                : self::nelSavepoint(fn () => self::unBlocco($dopo));
            $totale += $fatte;
        } while ($lette === self::BLOCCO);

        return $totale;
    }

    /**
     * Per `DB::afterCommit`: un errore del sigillo non fa fallire la richiesta (i dati sono già confermati), si scrive nel log e le righe restano da
     * sigillare; le sigilla la volta dopo, la pagina del registro, «Verifica l'integrità» o il comando pianificato. Il `try` sta qui dentro (P12): fuori,
     * l'errore uscirebbe dal commit a dati confermati e fermerebbe le funzioni registrate dopo.
     */
    public static function sigillaSenzaFermare(): void
    {
        try {
            self::sigilla();
        } catch (Throwable $e) {
            Log::warning('Registro delle modifiche: sigillo non riuscito, le righe restano da sigillare', ['errore' => $e->getMessage()]);
        }
    }

    /**
     * Dentro una transazione già aperta (i test, o chi sigilla da una sua transazione) il blocco sta in un savepoint suo, **rilasciato** alla fine: se il
     * sigillo non va si torna al savepoint e l'errore riparte, e il savepoint non resta aperto. Con `DB::transaction` Laravel ne aprirebbe uno senza mai
     * rilasciarlo, e su SQLite ogni scrittura dopo costa in proporzione ai savepoint aperti (misurato sull'emissione di 3.000 unità).
     *
     * @template T
     * @param \Closure(): T $lavoro
     * @return T
     */
    private static function nelSavepoint(\Closure $lavoro): mixed
    {
        // Come fa Laravel con i suoi savepoint: un comando sulla connessione, non una scrittura su una tabella.
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SAVEPOINT registro_sigillo');
        try {
            $esito = $lavoro();
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT registro_sigillo');
                $pdo->exec('RELEASE SAVEPOINT registro_sigillo');
            } catch (Throwable) {
                // La transazione di chi chiama non c'è più (uno stallo l'ha chiusa): resta l'errore di prima.
            }
            throw $e;
        }
        $pdo->exec('RELEASE SAVEPOINT registro_sigillo');

        return $esito;
    }

    /** @return array{0: int, 1: int, 2: int} le righe sigillate, quelle lette, l'ultimo `id` letto */
    private static function unBlocco(int $dopo): array
    {
        $catena = DB::table('registro_modifiche_catena')->where('id', 1)->lockForUpdate()->first();
        if ($catena === null) {
            DB::table('registro_modifiche_catena')->insertOrIgnore(['id' => 1, 'ultima_sequenza' => 0, 'ultima_impronta' => null]);
            $catena = DB::table('registro_modifiche_catena')->where('id', 1)->lockForUpdate()->first();
        }

        $righe = DB::table('registro_modifiche')->whereNull('sequenza')->where('id', '>', $dopo)->orderBy('id')->limit(self::BLOCCO)->get();
        if ($righe->isEmpty()) {
            return [0, 0, $dopo];
        }
        $ids = $righe->pluck('id')->map(fn ($id) => (int) $id)->all();
        $condomini = self::condominiDelle($ids);
        $soggetti = self::soggettiDelle($ids);

        $sequenza = (int) $catena->ultima_sequenza;
        $precedente = $catena->ultima_impronta;
        $fatte = 0;
        foreach ($righe as $riga) {
            if (! FirmaDelRegistro::torna($riga, $condomini[$riga->id] ?? [], $soggetti[$riga->id] ?? [])) {
                // Non è nata dal programma (o è stata cambiata prima del sigillo): resta fuori dalla catena, e la verifica la segna.
                continue;
            }
            $sequenza++;
            $testa = ImprontaDelRegistro::intestazione($riga, $sequenza, $condomini[$riga->id] ?? [], $soggetti[$riga->id] ?? []);
            $impronta = ImprontaDelRegistro::dellaRiga($precedente, $testa, (string) $riga->impronta_valori);

            $toccate = DB::table('registro_modifiche')->where('id', $riga->id)->whereNull('sequenza')
                ->update(['sequenza' => $sequenza, 'impronta_precedente' => $precedente, 'impronta' => $impronta]);
            if ($toccate !== 1) {
                // Sotto la serratura nessun altro sigilla: una riga già sigillata qui vuol dire due catene, e la transazione si annulla.
                throw new RuntimeException("Registro delle modifiche: la riga {$riga->id} è stata sigillata da un altro processo.");
            }
            $precedente = $impronta;
            $fatte++;
        }

        if ($fatte > 0) {
            DB::table('registro_modifiche_catena')->where('id', 1)->update(['ultima_sequenza' => $sequenza, 'ultima_impronta' => $precedente]);
        }

        return [$fatte, count($ids), (int) end($ids)];
    }

    /** @param list<int> $ids @return array<int, array<int, string>> riga => [condominio => nome] */
    public static function condominiDelle(array $ids): array
    {
        $per = [];
        foreach (array_chunk($ids, 1000) as $pezzo) {
            foreach (DB::table('registro_modifiche_condomini')->whereIn('riga_id', $pezzo)->get() as $c) {
                $per[(int) $c->riga_id][(int) $c->condominio_id] = (string) $c->condominio_nome;
            }
        }

        return $per;
    }

    /** @param list<int> $ids @return array<int, list<array{0: string, 1: int}>> */
    public static function soggettiDelle(array $ids): array
    {
        $per = [];
        foreach (array_chunk($ids, 1000) as $pezzo) {
            foreach (DB::table('registro_modifiche_soggetti')->whereIn('riga_id', $pezzo)->get() as $s) {
                $per[(int) $s->riga_id][] = [(string) $s->soggetto_tipo, (int) $s->soggetto_id];
            }
        }

        return $per;
    }
}
