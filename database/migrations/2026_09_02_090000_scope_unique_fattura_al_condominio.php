<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allarga `unique_ft` con `condominio_id`, perché oggi blocca fra condomìni diversi.
 *
 * ## Il difetto, misurato aprendo la beta.13
 *
 * `fatture_passive` porta dal 24/02/2026 un indice
 * `unique_ft (fornitore_id, numero_documento, data_documento)` che **nessun documento del
 * progetto nominava**: `grep -rn "unique_ft" docs/ app/ tests/` dava zero risultati, viveva
 * solo nella riga della migrazione che lo crea.
 *
 * Non ha **nessuno scoping**, e i fornitori sono condivisi fra i condomìni gestiti dallo stesso
 * studio (misurato: il fornitore 10 è usato su quattro condomìni, l'8 su tre). Siccome
 * `numero_documento` è testo libero, la stessa terna su due palazzi diversi è ordinaria
 * amministrazione — «BOLLETTA GENNAIO» dello stesso fornitore, stessa data, due condomìni — e
 * oggi **il secondo non si registra**.
 *
 * ⚠️ Peggio: quando scatta non lo dice a nessuno. Nessuno intercetta la violazione sul percorso
 * di registrazione, e il modulo passa a `.post()` solo `onSuccess`: si preme «Registra
 * documento» e non succede niente. Quella metà la chiude il codice applicativo, non questa
 * migrazione.
 *
 * ## Perché si allarga invece di toglierlo
 *
 * La decisione D4 (`docs/prima_nota_rapida.md`) vuole i duplicati **segnalati, mai bloccati**, e
 * la beta.13 costruisce quei due livelli di avviso. Ma ciò che l'indice blocca **dentro** un
 * condominio — stesso fornitore, stesso numero, stessa data — non è un'euristica: è lo stesso
 * documento, e la protezione dal doppio invio vale tenerla. Sbagliato è il blocco **fra**
 * condomìni. Quindi l'indice resta e prende lo scope che gli mancava.
 *
 * ✅ **Non può fallire su dati esistenti**: il nuovo indice ha una colonna in più, quindi è
 * strettamente **più debole** del vecchio. Ogni coppia di righe che l'indice vecchio ammetteva è
 * ammessa anche da questo.
 */
return new class extends Migration
{
    /**
     * Le colonne di un indice, nell'ordine dichiarato. Vuoto se l'indice non c'è.
     *
     * ⚠️ `Schema::getIndexes()` e non `SHOW INDEX`: la prima stesura usava la forma MySQL e ha
     * fatto fallire **tutti e 34** i casi di `UpgradeMigrationsRerunTest`, che gira su SQLite in
     * memoria («near "SHOW": syntax error»). Una migrazione che sa parlare solo col database di
     * produzione rompe la suite di chiunque, ed è la guardia stessa ad averlo preso.
     */
    private function colonneIndice(string $tabella, string $indice): array
    {
        foreach (Schema::getIndexes($tabella) as $i) {
            if (($i['name'] ?? null) === $indice) {
                return array_values($i['columns'] ?? []);
            }
        }

        return [];
    }

    public function up(): void
    {
        // ⚠️ `fornitore_id` PRIMA, e non è estetica: `unique_ft` è l'indice che regge la chiave
        // esterna su `fornitore_id`, e InnoDB rifiuta di toglierlo finché nessun altro indice ne
        // porta la colonna in testa — errore 1553, «needed in a foreign key constraint»,
        // incontrato al primo tentativo. Creando prima il nuovo indice con `fornitore_id` in
        // testa, è lui a subentrare e il vecchio diventa rimovibile. Per l'unicità l'ordine delle
        // colonne è indifferente: vincola comunque la quaterna. E le due query dei duplicati
        // filtrano sempre condominio **e** fornitore insieme, quindi resta utile a entrambe.
        $volute = ['fornitore_id', 'condominio_id', 'numero_documento', 'data_documento'];

        // Nome nuovo, non lo stesso: creare-poi-togliere richiede che i due indici coesistano per
        // un istante, e due indici omonimi non possono. Il nome nuovo rende anche visibile in
        // `SHOW INDEX` quale delle due versioni è installata.
        $nuovo = 'unique_ft_condominio';

        // Già fatto: una riesecuzione non deve toccare niente. MySQL non ha DDL transazionale,
        // quindi l'aggiornamento può essere ripreso dopo un'interruzione.
        if ($this->colonneIndice('fatture_passive', $nuovo) !== $volute) {
            Schema::table('fatture_passive', function (Blueprint $table) use ($volute, $nuovo) {
                $table->unique($volute, $nuovo);
            });
        }

        // Interruzione possibile proprio qui, fra i due statement: al secondo giro il nuovo indice
        // c'è già (ramo sopra saltato) e resta solo da togliere il vecchio.
        if ($this->colonneIndice('fatture_passive', 'unique_ft') !== []) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->dropUnique('unique_ft');
            });
        }
    }

    /**
     * ⚠️ Il ritorno indietro **può fallire, ed è giusto così**: l'indice vecchio è più stretto,
     * quindi se nel frattempo sono state registrate due fatture con la stessa terna su condomìni
     * diversi — cioè esattamente il caso che questa migrazione sblocca — MySQL rifiuta di
     * ricrearlo. Fallire rumorosamente è meglio che cancellare righe per far posto a un vincolo.
     */
    public function down(): void
    {
        $vecchie = ['fornitore_id', 'numero_documento', 'data_documento'];

        // Stesso ordine invertito dell'andata, per la stessa ragione: prima si rimette il vecchio
        // — che ha `fornitore_id` in testa e quindi può reggere la chiave esterna — e solo dopo si
        // toglie il nuovo.
        if ($this->colonneIndice('fatture_passive', 'unique_ft') !== $vecchie) {
            Schema::table('fatture_passive', function (Blueprint $table) use ($vecchie) {
                $table->unique($vecchie, 'unique_ft');
            });
        }

        if ($this->colonneIndice('fatture_passive', 'unique_ft_condominio') !== []) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->dropUnique('unique_ft_condominio');
            });
        }
    }
};
