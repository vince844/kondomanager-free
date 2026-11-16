<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi ha caricato un documento può essere eliminato senza portarsi via il documento.
 *
 * `documenti.created_by` nasce `NOT NULL` con la chiave in cascata: eliminare l'utente cancellava le righe dei documenti che aveva creato (il file
 * restava sul disco, senza nessuno che lo trovasse). Fra questi ci sono il PDF del rendiconto conservato per l'assemblea, il verbale dell'approvazione
 * e i PDF delle lettere mandate: documenti che devono restare. Qui la colonna diventa vuotabile e la chiave «lascia vuoto», come `updated_by` sulla
 * stessa tabella: si perde il nome di chi l'ha caricato, resta il documento, e le pagine dicono «utente eliminato».
 *
 * Nessun riempimento: le righe di oggi hanno tutte un autore, e lo tengono.
 *
 * Tre passi, ognuno con la sua guardia, perché su MySQL sono tre statement e il DDL non è transazionale: la chiave in cascata si toglie, la colonna
 * diventa vuotabile, la chiave nuova si mette. Un'interruzione dopo il primo o dopo il secondo lascia uno stato che la guardia riconosce, e la
 * ripresa fa solo ciò che manca. L'ordine non è libero: MySQL rifiuta una chiave «lascia vuoto» su una colonna `NOT NULL` (errore 1830).
 */
return new class extends Migration
{
    private const NOME = 'documenti_created_by_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('documenti') || ! Schema::hasTable('users') || ! Schema::hasColumn('documenti', 'created_by')) {
            return;
        }

        $chiave = $this->chiave();
        if ($chiave !== null && $chiave['regola'] !== 'set null') {
            $this->togliChiave($chiave);
        }

        if (! $this->vuotabile()) {
            Schema::table('documenti', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable()->change());
        }

        if ($this->chiave() === null) {
            Schema::table('documenti', fn (Blueprint $table) => $table->foreign('created_by', self::NOME)->references('id')->on('users')->nullOnDelete());
        }
    }

    /**
     * Torna alla cascata. La colonna torna `NOT NULL` solo se nessun documento ha perso l'autore: una riga con l'autore vuoto non ha un valore da
     * rimettere, e cancellarla per tornare indietro sarebbe il difetto che questa migrazione toglie.
     */
    public function down(): void
    {
        if (! Schema::hasTable('documenti') || ! Schema::hasColumn('documenti', 'created_by')) {
            return;
        }

        $chiave = $this->chiave();
        if ($chiave !== null && $chiave['regola'] !== 'cascade') {
            $this->togliChiave($chiave);
        }

        if ($this->vuotabile() && ! DB::table('documenti')->whereNull('created_by')->exists()) {
            Schema::table('documenti', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable(false)->change());
        }

        if ($this->chiave() === null) {
            Schema::table('documenti', fn (Blueprint $table) => $table->foreign('created_by', self::NOME)->references('id')->on('users')->cascadeOnDelete());
        }
    }

    /**
     * La chiave esterna su `created_by`, con il suo nome e la regola alla cancellazione in minuscolo («cascade», «set null»), o null se non c'è. Si
     * legge dallo schema, non si deduce dalla colonna: la colonna può esserci senza la chiave, ed è lo stato che un'interruzione a metà lascia.
     *
     * @return array{nome: ?string, regola: string}|null
     */
    private function chiave(): ?array
    {
        foreach (Schema::getForeignKeys('documenti') as $k) {
            if ($k['columns'] === ['created_by']) {
                return ['nome' => $k['name'] ?? null, 'regola' => strtolower((string) ($k['on_delete'] ?? ''))];
            }
        }

        return null;
    }

    /** Su MySQL la chiave si toglie per nome; su SQLite per colonna (la tabella si riscrive senza). */
    private function togliChiave(array $chiave): void
    {
        Schema::table('documenti', function (Blueprint $table) use ($chiave) {
            DB::getDriverName() === 'mysql' && $chiave['nome'] !== null ? $table->dropForeign($chiave['nome']) : $table->dropForeign(['created_by']);
        });
    }

    private function vuotabile(): bool
    {
        foreach (Schema::getColumns('documenti') as $colonna) {
            if ($colonna['name'] === 'created_by') {
                return (bool) $colonna['nullable'];
            }
        }

        return false;
    }
};
