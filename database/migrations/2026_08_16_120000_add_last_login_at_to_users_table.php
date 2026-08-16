<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beta.55 — `users.last_login_at`: l'ultimo ingresso di ogni utente.
 *
 * ## Perché una colonna e non la tabella `sessions`
 *
 * La tabella `sessions` esiste già e ha `user_id` e `last_activity`, ma risponde a un'altra
 * domanda: **chi è collegato adesso**. Le sue righe le ripulisce il garbage collector delle
 * sessioni, e `last_activity` è l'ultima *attività*, non l'ultimo *accesso*. Per «questo condòmino
 * ha mai aperto il portale?» — che è la domanda vera dell'amministratore, quella che decide se la
 * convocazione va mandata cartacea — serve un dato che non scada.
 *
 * ## Perché nasce adesso e non con un registro completo
 *
 * Un registro degli accessi (con esito, ip e user agent) risponde a domande di sicurezza ed è
 * un'altra cosa: dati personali, quindi conservazione a termine dichiarata e riga
 * nell'informativa. Questa colonna non li contiene, si giustifica da sé, e vale la regola del
 * progetto «ogni nuova data deve nascere con il suo lettore»: il lettore c'è, è la colonna
 * dell'elenco utenti che dice «mai».
 *
 * La scrive `AggiornaUltimoAccesso` sull'evento `Login`, che copre tutti e tre i percorsi di
 * autenticazione — modulo, doppia autenticazione e ripristino da cookie *remember me*.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: su MySQL il DDL non è transazionale, e una migrazione interrotta a metà
        // lascia la colonna senza registrare l'esecuzione. Alla ripresa questa guardia la salta.
        if (Schema::hasColumn('users', 'last_login_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')
                ->nullable()
                ->comment('Ultimo accesso riuscito, scritto dal listener sull\'evento Login (beta.55)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'last_login_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
