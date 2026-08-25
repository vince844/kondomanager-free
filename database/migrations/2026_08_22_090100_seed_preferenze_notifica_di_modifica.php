<?php

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le tre preferenze nuove ereditano lo stato della loro sorella «nuova».
 *
 * ## Il problema che risolve
 *
 * La beta.64 aggiunge tre tipi di notifica — comunicazione, segnalazione e documento **aggiornati**
 * — separati da quelli di creazione, così che chi vuole sapere delle cose nuove possa non ricevere
 * anche ogni correzione.
 *
 * Ma la schermata delle preferenze legge le righe di `notification_preferences`, e **una riga che
 * non c'è vale spento** (`$saved[$type] ?? false`). Senza questa migrazione tutti gli utenti già a
 * database si troverebbero il nuovo interruttore spento, e l'amministratore che spunta «avvisa i
 * destinatari» vedrebbe non partire niente — cioè una funzione che sembra rotta il giorno stesso in
 * cui esce.
 *
 * ## La regola, e perché è questa
 *
 * ⚠️ **Ogni preferenza nuova nasce con lo stato della sua sorella «nuova», non accesa d'ufficio.**
 * Chi riceve le comunicazioni nuove riceverà anche gli aggiornamenti; chi le aveva **spente**
 * continua a non ricevere niente. Accenderle tutte sarebbe stato più semplice e avrebbe scritto
 * mail nella casella di chi le aveva esplicitamente rifiutate: una scelta dell'utente non si
 * ribalta con una migrazione.
 *
 * Chi non ha nessuna riga per la sorella non ne riceve una nuova: resta com'era, cioè spento.
 *
 * ⚠️ **Rieseguibile.** Non tocca le righe che esistono già — quindi un utente che nel frattempo
 * avesse spento l'interruttore a mano non se lo ritrova riacceso da un secondo giro della
 * migrazione dopo un'interruzione.
 */
return new class extends Migration
{
    /** preferenza nuova => preferenza da cui eredita lo stato. */
    private const EREDITA_DA = [
        NotificationType::UPDATED_COMMUNICATION->value    => NotificationType::NEW_COMMUNICATION->value,
        NotificationType::UPDATED_TICKET->value           => NotificationType::NEW_TICKET->value,
        NotificationType::UPDATED_ARCHIVE_DOCUMENT->value => NotificationType::NEW_ARCHIVE_DOCUMENT->value,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        foreach (self::EREDITA_DA as $nuova => $sorella) {
            $giaPresenti = DB::table('notification_preferences')
                ->where('type', $nuova)
                ->pluck('user_id')
                ->all();

            $daCreare = DB::table('notification_preferences')
                ->where('type', $sorella)
                ->when($giaPresenti !== [], fn ($q) => $q->whereNotIn('user_id', $giaPresenti))
                ->get(['user_id', 'enabled']);

            if ($daCreare->isEmpty()) {
                continue;
            }

            DB::table('notification_preferences')->insert(
                $daCreare->map(fn ($r) => [
                    'user_id'    => $r->user_id,
                    'type'       => $nuova,
                    'enabled'    => $r->enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        DB::table('notification_preferences')
            ->whereIn('type', array_keys(self::EREDITA_DA))
            ->delete();
    }
};
