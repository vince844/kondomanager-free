<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * IL LUCCHETTO ORFANO (beta.32).
 *
 * Fino alla beta.31 il blocco dei saldi pregressi era rappresentato da due
 * booleani senza soggetto: `gestioni.saldo_applicato` e `saldi.is_applicato`.
 * Dicevano "questi saldi sono stati assorbiti da un piano rate", ma non da
 * QUALE piano. Per sbloccarli, la cancellazione del piano doveva dedurlo a
 * posteriori leggendo il contenuto delle quote generate — e ogni ricalcolo
 * (che cancella e rigenera le rate) faceva sparire quella traccia, lasciando
 * il wallet chiuso a chiave senza più nessuno che potesse riaprirlo.
 *
 * Questa migrazione dà un soggetto al lucchetto:
 *
 *  - `saldi.piano_rate_id` — quale piano ha assorbito questo saldo. Si azzera
 *    da sé quando il piano viene eliminato (nullOnDelete), quindi il legame
 *    non può sopravvivere al proprio piano.
 *  - `piani_rate.applica_saldi` — la scelta dell'amministratore al momento
 *    della creazione, persistita. Serve ai piani creati senza "genera subito":
 *    quando le rate vengono generate più tardi, l'intenzione è ancora leggibile
 *    invece di essere dedotta dallo stato del lucchetto. NULL = piani legacy.
 *
 * RIPARAZIONE DATI. Il passaggio finale ricuce le installazioni già colpite:
 * per ogni gestione bloccata cerca il piano che contiene davvero le quote di
 * saldo e lo registra; se nessun piano le contiene, il lucchetto è orfano
 * (nato da un ricalcolo o da un piano cancellato) e viene aperto. I debiti
 * verso fornitori — che vivono nella stessa tabella con is_applicato=true
 * proprio per restare fuori dai piani rate — non vengono mai toccati.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale e `foreignId()->constrained()` produce
        // DUE statement (ADD COLUMN, poi ADD CONSTRAINT). Se il web server tronca
        // la richiesta fra i due, alla riesecuzione `hasColumn` è già vero: con
        // una sola guardia la foreign key non verrebbe creata MAI, e senza
        // `nullOnDelete` un saldo potrebbe restare intestato a un piano defunto,
        // cioè bloccato per sempre — di nuovo il difetto che questa migrazione
        // esiste per chiudere. Colonna e vincolo hanno quindi guardie separate.
        if (! Schema::hasColumn('saldi', 'piano_rate_id')) {
            // Percorso normale: colonna e vincolo insieme (su SQLite il vincolo
            // può essere creato solo qui, insieme alla tabella).
            Schema::table('saldi', function (Blueprint $table) {
                $table->foreignId('piano_rate_id')
                    ->nullable()
                    ->after('gestione_id')
                    ->constrained('piani_rate')
                    ->nullOnDelete();
            });
        } elseif ($this->mancaForeignKeyMysql('saldi', 'saldi_piano_rate_id_foreign')) {
            // Percorso di ripresa: la colonna c'è ma il vincolo no, cioè
            // l'interruzione è caduta esattamente fra i due statement.
            Schema::table('saldi', function (Blueprint $table) {
                $table->foreign('piano_rate_id')
                    ->references('id')->on('piani_rate')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('piani_rate', 'applica_saldi')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->boolean('applica_saldi')->nullable()->after('metodo_distribuzione');
            });
        }

        if (! Schema::hasColumn('piani_rate', 'saldi_config')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->json('saldi_config')->nullable()->after('applica_saldi');
            });
        }

        $this->riparaLucchettiOrfani();
    }

    /**
     * Solo MySQL: la colonna esiste ma il vincolo no? È lo stato che lascia
     * un'interruzione fra i due ALTER. Altrove non c'è nulla da recuperare.
     */
    private function mancaForeignKeyMysql(string $tabella, string $nomeVincolo): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        return ! DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $tabella)
            ->where('CONSTRAINT_NAME', $nomeVincolo)
            ->exists();
    }

    public function down(): void
    {
        if (Schema::hasColumn('saldi', 'piano_rate_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->dropConstrainedForeignId('piano_rate_id');
            });
        }

        if (Schema::hasColumn('piani_rate', 'applica_saldi')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('applica_saldi');
            });
        }

        if (Schema::hasColumn('piani_rate', 'saldi_config')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('saldi_config');
            });
        }
    }

    /**
     * Per ogni gestione col lucchetto chiuso: trova il piano che ha davvero
     * assorbito i saldi e lo registra, oppure apre il lucchetto se il piano
     * non esiste più (o non ha mai contenuto quelle quote).
     */
    private function riparaLucchettiOrfani(): void
    {
        $gestioniBloccate = DB::table('gestioni')->where('saldo_applicato', true)->pluck('id');

        foreach ($gestioniBloccate as $gestioneId) {
            // Le righe a zero non sono mai entrate in un piano rate (la
            // generazione le scarta): erano bloccate solo perché il vecchio
            // lock agiva a tappeto su tutta la gestione. Tornano libere.
            DB::table('saldi')
                ->where('gestione_id', $gestioneId)
                ->where('is_applicato', true)
                ->whereNull('fornitore_id')
                ->where('saldo_iniziale', 0)
                ->update(['is_applicato' => false, 'piano_rate_id' => null]);

            // I saldi dei condòmini: i debiti verso fornitori (fornitore_id
            // valorizzato) sono bloccati per un motivo diverso e restano fuori.
            $saldiCondomini = DB::table('saldi')
                ->where('gestione_id', $gestioneId)
                ->where('is_applicato', true)
                ->whereNull('fornitore_id');

            if (! (clone $saldiCondomini)->exists()) {
                // Lucchetto sulla gestione senza nessuna riga bloccata sotto:
                // non c'è niente da proteggere.
                DB::table('gestioni')->where('id', $gestioneId)
                    ->update(['saldo_applicato' => false, 'nota_saldo' => null]);

                Log::info('beta.32 riparazione lucchetti: nessuna riga bloccata, flag spento', [
                    'gestione_id' => $gestioneId,
                ]);
                continue;
            }

            $saldiIds = (clone $saldiCondomini)->pluck('id')->all();
            $pianiConSaldi = $this->pianiConSaldi($gestioneId);

            // Un solo piano rivendica i saldi: è il proprietario, glieli intesto.
            if (count($pianiConSaldi) === 1) {
                (clone $saldiCondomini)->update(['piano_rate_id' => $pianiConSaldi[0]]);

                Log::info('beta.32 riparazione lucchetti: saldi intestati al piano', [
                    'gestione_id'   => $gestioneId,
                    'piano_rate_id' => $pianiConSaldi[0],
                    'saldi_ids'     => $saldiIds,
                ]);
                continue;
            }

            // Più piani contengono quote di saldo: non c'è modo di sapere quale
            // riga appartenga a quale, e indovinare significherebbe rischiare di
            // sbloccare i saldi di un piano che continua ad addebitarli (doppio
            // addebito al piano successivo). Si lascia tutto com'è e si registra:
            // meglio un lucchetto da aprire a mano che un addebito doppio.
            if (count($pianiConSaldi) > 1) {
                Log::warning('beta.32 riparazione lucchetti: più piani contengono saldi, nessuna intestazione automatica', [
                    'gestione_id' => $gestioneId,
                    'piani_ids'   => $pianiConSaldi,
                    'saldi_ids'   => $saldiIds,
                ]);
                continue;
            }

            // Nessun piano rivendica questi saldi: lucchetto orfano.
            $notaPrecedente = DB::table('gestioni')->where('id', $gestioneId)->value('nota_saldo');

            (clone $saldiCondomini)->update(['is_applicato' => false, 'piano_rate_id' => null]);
            DB::table('gestioni')->where('id', $gestioneId)
                ->update(['saldo_applicato' => false, 'nota_saldo' => null]);

            Log::info('beta.32 riparazione lucchetti: lucchetto orfano riaperto', [
                'gestione_id'     => $gestioneId,
                'saldi_ids'       => $saldiIds,
                'nota_precedente' => $notaPrecedente,
            ]);
        }
    }

    /**
     * TUTTI i piani rate della gestione le cui quote contengono un saldo
     * pregresso diverso da zero. Restituire il primo trovato non basterebbe:
     * su dati storici due piani della stessa gestione possono contenerne
     * entrambi, e in quel caso il chiamante deve saperlo per non indovinare.
     *
     * @return int[]
     */
    private function pianiConSaldi(int|string $gestioneId): array
    {
        $pianiIds = DB::table('piani_rate')->where('gestione_id', $gestioneId)->pluck('id');
        $conSaldi = [];

        foreach ($pianiIds as $pianoId) {
            $quote = DB::table('rate_quote')
                ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
                ->where('rate.piano_rate_id', $pianoId)
                ->select('rate_quote.tipo', 'rate_quote.regole_calcolo')
                ->get();

            foreach ($quote as $quota) {
                $regole = is_string($quota->regole_calcolo)
                    ? json_decode($quota->regole_calcolo, true)
                    : (array) $quota->regole_calcolo;

                if ($quota->tipo === 'saldo_iniziale' || ! empty($regole['importi']['saldo_usato'])) {
                    $conSaldi[] = (int) $pianoId;
                    break;
                }
            }
        }

        return $conSaldi;
    }
};
