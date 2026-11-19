<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Lo scenario dei test della pubblicazione programmata delle comunicazioni (Coda 589)
 *
 * È lo scenario dei commenti (`CommentiScenario.php`: due palazzi, Aurora e Borea, lo studio e tre condòmini, con i
 * ruoli di serie e le preferenze di notifica accese), con l'orologio fermo a **giovedì 12/11/2026, ore 10:00 UTC**:
 * le 11:00 a Roma, ora solare.
 *
 * Le colonne, le rotte e le classi nuove si nominano per stringa: finché non esistono, il test fallisce sul punto che
 * manca, non su un errore di sintassi del file.
 */

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

const CPR_ADESSO = '2026-11-12 10:00:00';
const CPR_MIGRAZIONE_COLONNE = 'migrations/2026_11_12_100000_add_pubblicazione_programmata_to_comunicazioni.php';
const CPR_MIGRAZIONE_RIEMPIMENTO = 'migrations/2026_11_12_100100_riempi_pubblicata_il_delle_comunicazioni.php';
const CPR_MIGRAZIONE_PREFERENZA = 'migrations/2026_11_12_100200_seed_preferenza_notifica_comunicazione_programmata.php';
const CPR_REGOLE = 'App\\Support\\Comunicazioni\\PubblicazioneProgrammata';
const CPR_MAIL_CONFERMA = 'App\\Notifications\\Comunicazioni\\ComunicazioneProgrammataPubblicataNotification';
const CPR_MAIL_NUOVA = 'App\\Notifications\\Comunicazioni\\NewComunicazioneNotification';
const CPR_TIPO_CONFERMA = 'scheduled_communication_published';
const CPR_COMANDO = 'comunicazioni:pubblica-programmate';
const CPR_EVENTO = 'pubblica-comunicazioni-programmate';

/** I palazzi e le persone dei commenti, con l'orologio fermo. Da chiamare nel `beforeEach`. */
function cprPrepara($test): void
{
    $test->travelTo(Carbon::parse(CPR_ADESSO, 'UTC'));
    ccmPrepara($test);
}

/** I campi del modulo del pannello per una comunicazione nuova, in bozza salvo che i campi dicano altro. */
function cprModulo(array $condomini, array $campi = []): array
{
    return array_merge([
        'subject' => 'Chiusura dell\'acqua',
        'description' => 'Martedì dalle 9 alle 12 l\'acqua resta chiusa.',
        'priority' => 'media',
        'can_comment' => true,
        'is_featured' => false,
        'is_published' => false,
        'condomini_ids' => array_map(fn ($k) => $k->id, $condomini),
    ], $campi);
}

/** Crea una comunicazione dalla rotta del pannello. */
function cprCrea($test, User $user, array $modulo): TestResponse
{
    return $test->actingAs($user)->post(route('admin.comunicazioni.store'), $modulo);
}

/** Modifica una comunicazione dalla rotta del pannello. */
function cprModifica($test, User $user, Comunicazione $comunicazione, array $modulo): TestResponse
{
    return $test->actingAs($user)->put(route('admin.comunicazioni.update', $comunicazione), $modulo);
}

/** L'ultima comunicazione scritta a database. */
function cprUltima(): Comunicazione
{
    return Comunicazione::query()->orderByDesc('id')->firstOrFail();
}

/** Una riga di `comunicazioni` letta dal database, senza passare dal model. */
function cprRiga(int|Comunicazione $comunicazione): object
{
    $id = $comunicazione instanceof Comunicazione ? $comunicazione->id : $comunicazione;

    return DB::table('comunicazioni')->where('id', $id)->first();
}

/**
 * Una comunicazione già a database, approvata, non pubblicata e programmata per l'ora data (UTC): serve a preparare gli
 * stati senza passare dal modulo. Le colonne nuove si scrivono dal database.
 */
function cprProgrammata(array $condomini, User $autore, string $perUtc, array $campi = [], ?User $programmataDa = null): Comunicazione
{
    $c = ccmComunicazione($condomini, $autore, array_merge(['is_published' => false, 'subject' => 'Chiusura dell\'acqua'], $campi));
    DB::table('comunicazioni')->where('id', $c->id)->update([
        'programmata_per' => $perUtc,
        'programmata_da' => ($programmataDa ?? $autore)->id,
        'pubblicata_il' => null,
    ]);

    return $c->fresh();
}

/** Lancia il comando che il pianificatore lancia ogni minuto. */
function cprPubblica($test): void
{
    $test->artisan(CPR_COMANDO)->assertExitCode(0);
}

/** Gli utenti la cui anagrafica ha ricevuto una notifica della classe data (gli avvisi delle comunicazioni vanno alle anagrafiche). */
function cprAvvisati(string $classe): array
{
    $ids = [];
    foreach (Anagrafica::whereNotNull('user_id')->get() as $anagrafica) {
        if (Notification::sent($anagrafica, $classe)->isNotEmpty()) {
            $ids[] = (int) $anagrafica->user_id;
        }
    }
    sort($ids);

    return $ids;
}

/** Accende o spegne una preferenza di notifica di un utente. */
function cprPreferenza(User $user, string $tipo, bool $accesa): void
{
    DB::table('notification_preferences')->updateOrInsert(
        ['user_id' => $user->id, 'type' => $tipo],
        ['enabled' => $accesa, 'created_at' => now(), 'updated_at' => now()]
    );
}

/** Le props di una pagina Inertia, o `null` se la pagina non si apre. */
function cprProps(TestResponse $risposta): ?array
{
    if (in_array($risposta->status(), [403, 404], true)) {
        return null;
    }
    $risposta->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

/** Una risorsa fra le props: sciolta o dentro `data`. */
function cprRisorsa(mixed $valore): array
{
    return is_array($valore) && array_key_exists('data', $valore) && is_array($valore['data']) && ! array_is_list($valore) ? $valore['data'] : (array) $valore;
}
