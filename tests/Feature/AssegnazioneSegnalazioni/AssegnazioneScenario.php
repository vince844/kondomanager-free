<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Lo scenario dei test dell'assegnazione delle segnalazioni (decisione 294)
 *
 * È lo scenario dei commenti (`CommentiScenario.php`: due palazzi, Aurora e Borea, lo studio e tre condòmini, con i
 * ruoli di serie e le preferenze di notifica accese), con l'orologio fermo a **giovedì 12/11/2026, ore 10:00 UTC**:
 * le 11:00 a Roma. In più Elena, una collaboratrice dello studio **senza anagrafica** in nessun palazzo: è il caso di
 * tutti i giorni per chi lavora nello studio.
 *
 * Le colonne, le rotte, le classi e le impostazioni nuove si nominano per stringa: finché non esistono, il test fallisce
 * sul punto che manca, non su un errore di sintassi del file.
 */

use App\Models\Segnalazione;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

const ASG_ADESSO = '2026-11-12 10:00:00';
const ASG_PERMESSO = 'Assegna segnalazioni';
const ASG_REGOLE = 'App\\Support\\Segnalazioni\\AssegnazioneSegnalazioni';
const ASG_MAIL_ASSEGNATA = 'App\\Notifications\\Segnalazioni\\SegnalazioneAssegnataNotification';
const ASG_MAIL_TOLTA = 'App\\Notifications\\Segnalazioni\\SegnalazioneNonPiuAssegnataNotification';
const ASG_TIPO = 'ticket_assigned';
const ASG_IMP_PORTALE = 'segnalazioni_assegnazione_visibile_nel_portale';
const ASG_IMP_STATO = 'segnalazioni_assegnazione_porta_in_lavorazione';
const ASG_MIGRAZIONE_COLONNE = 'migrations/2026_11_12_110000_add_assegnazione_to_segnalazioni.php';
const ASG_MIGRAZIONE_IMPOSTAZIONI = 'settings/2026_11_12_110100_add_assegnazione_segnalazioni_to_general_settings.php';
const ASG_MIGRAZIONE_PREFERENZA = 'migrations/2026_11_12_110200_seed_preferenza_notifica_segnalazione_assegnata.php';

/** I palazzi e le persone dei commenti, Elena dello studio, e l'orologio fermo. Da chiamare nel `beforeEach`. */
function asgPrepara($test): void
{
    $test->travelTo(Carbon::parse(ASG_ADESSO, 'UTC'));
    ccmPrepara($test);
    $test->elena = ccmStudioSenzaAnagrafica('Elena Studio', 'collaboratore');
    $test->s = ccmSegnalazione($test->aurora, $test->mario);
}

/** Il comando dell'assegnazione, dalla sua rotta del pannello. `null` toglie l'assegnazione. */
function asgAssegna($test, User $chi, Segnalazione $segnalazione, ?int $aChi): TestResponse
{
    return $test->actingAs($chi)->patch(route('admin.segnalazioni.assegna', $segnalazione), ['assigned_to' => $aChi]);
}

/** Una riga di `segnalazioni` letta dal database, senza passare dal model. */
function asgRiga(Segnalazione $segnalazione): object
{
    return DB::table('segnalazioni')->where('id', $segnalazione->id)->first();
}

/** Una segnalazione già assegnata, scritta dal database: serve a preparare gli stati senza passare dal comando. */
function asgAssegnata($test, User $aChi, ?User $da = null, array $campi = []): Segnalazione
{
    $s = ccmSegnalazione($test->aurora, $test->mario, $campi);
    DB::table('segnalazioni')->where('id', $s->id)->update([
        'assigned_to' => $aChi->id,
        'assegnata_da' => ($da ?? $test->admin)->id,
        'assegnata_il' => '2026-11-10 08:30:00',
    ]);

    return $s->fresh();
}

/** Accende o spegne un'impostazione generale. */
function asgImpostazione(string $nome, bool $valore): void
{
    $impostazioni = app(GeneralSettings::class);
    expect(property_exists($impostazioni, $nome))->toBeTrue("Manca l'impostazione generale {$nome}");
    $impostazioni->{$nome} = $valore;
    $impostazioni->save();
}

/** Accende o spegne una preferenza di notifica di un utente; `null` toglie la riga. */
function asgPreferenza(User $user, string $tipo, ?bool $accesa): void
{
    if ($accesa === null) {
        DB::table('notification_preferences')->where('user_id', $user->id)->where('type', $tipo)->delete();

        return;
    }
    DB::table('notification_preferences')->updateOrInsert(
        ['user_id' => $user->id, 'type' => $tipo],
        ['enabled' => $accesa, 'created_at' => now(), 'updated_at' => now()]
    );
}

/** Le props di una pagina Inertia, o `null` se la pagina non si apre. */
function asgProps(TestResponse $risposta): ?array
{
    if (in_array($risposta->status(), [302, 403, 404], true)) {
        return null;
    }
    $risposta->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

/** Una risorsa fra le props: sciolta o dentro `data`. */
function asgRisorsa(mixed $valore): array
{
    return is_array($valore) && array_key_exists('data', $valore) && is_array($valore['data']) && ! array_is_list($valore) ? $valore['data'] : (array) $valore;
}

/** Le righe di un elenco fra le props: un elenco sciolto o dentro `data`. */
function asgElenco(array $props, string $chiave = 'segnalazioni'): array
{
    $valore = $props[$chiave] ?? [];

    return array_values(array_is_list($valore) ? $valore : ($valore['data'] ?? []));
}

/** Il messaggio rimasto in sessione dopo un comando, comunque sia fatto il lampo. */
function asgMessaggi(): string
{
    return json_encode(session()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
