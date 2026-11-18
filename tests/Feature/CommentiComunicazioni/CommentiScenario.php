<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Lo scenario dei test dei commenti alle comunicazioni (Coda 559, con le Code 558 e 389)
 *
 * Due palazzi, Aurora e Borea, con i ruoli di serie (il seeder vero, come all'installazione e a ogni aggiornamento) e le
 * preferenze di notifica tutte accese. Le persone:
 * - lo studio: l'amministratore (anagrafica in tutti e due i palazzi) e una collaboratrice (anagrafica in Aurora);
 * - Mario Rossi, in Aurora; Lucia Verdi, in Borea; Paolo Neri, con unità in tutti e due.
 *
 * Le rotte, i permessi e le colonne nuove si nominano per stringa: finché non esistono, il test fallisce sul punto che
 * manca, non su un errore di sintassi del file.
 */

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** I permessi dei commenti delle comunicazioni, con i nomi che il progetto fissa (due sono nuovi). */
const CCM_PERMESSI = [
    'commenta' => 'Commenta comunicazioni',
    'pubblica' => 'Pubblica commenti comunicazioni',
    'approva' => 'Approva commenti comunicazioni',
    'modifica' => 'Modifica commenti comunicazioni',
    'modifica_propri' => 'Modifica propri commenti comunicazioni',
    'elimina' => 'Elimina commenti comunicazioni',
    'elimina_propri' => 'Elimina propri commenti comunicazioni',
    'visualizza' => 'Visualizza commenti comunicazioni',
];

const CCM_PERMESSI_SEGNALAZIONI = [
    'commenta' => 'Commenta segnalazioni',
    'pubblica' => 'Pubblica commenti segnalazioni',
    'approva' => 'Approva commenti segnalazioni',
    'modifica' => 'Modifica commenti segnalazioni',
    'modifica_propri' => 'Modifica propri commenti segnalazioni',
    'elimina' => 'Elimina commenti segnalazioni',
    'elimina_propri' => 'Elimina propri commenti segnalazioni',
    'visualizza' => 'Visualizza commenti segnalazioni',
];

/** I palazzi, lo studio e i tre condòmini: da chiamare nel `beforeEach`. */
function ccmPrepara($test): void
{
    $test->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $test->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $test->borea = Condominio::factory()->create(['nome' => 'Condominio Borea']);
    $test->admin = ccmUtente([$test->aurora->id, $test->borea->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
    $test->collaboratrice = ccmUtente([$test->aurora->id], 'Anna Collaboratrice', Role::COLLABORATORE->value);
    $test->mario = ccmUtente([$test->aurora->id], 'Mario Rossi');
    $test->lucia = ccmUtente([$test->borea->id], 'Lucia Verdi');
    $test->paolo = ccmUtente([$test->aurora->id, $test->borea->id], 'Paolo Neri');
}

/** Un utente con il suo ruolo, la sua anagrafica nei palazzi dati e tutte le preferenze di notifica accese. */
function ccmUtente(array $condominiIds, string $nome, string $ruolo = 'utente'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'name' => $nome,
        'email' => Str::slug($nome, '.') . '.' . Str::lower(Str::random(5)) . '@esempio.invalid',
    ]);
    $user->assignRole($ruolo);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominiIds);

    return $user->fresh();
}

/**
 * Un utente dello studio senza nessuna anagrafica: è il caso di tutti i giorni, perché chi amministra non è un
 * condòmino dei palazzi che gestisce.
 */
function ccmStudioSenzaAnagrafica(string $nome, string $ruolo = 'amministratore'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'name' => $nome,
        'email' => Str::slug($nome, '.') . '.' . Str::lower(Str::random(5)) . '@esempio.invalid',
    ]);
    $user->assignRole($ruolo);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $user->fresh();
}

/** Accende o spegne «Commenti in moderazione» su una segnalazione o una comunicazione, dal database. */
function ccmModerazione(Model $elemento, bool $accesa): void
{
    DB::table($elemento->getTable())->where('id', $elemento->getKey())->update(['commenti_moderati' => $accesa]);
}

/** Un ruolo su misura con i permessi dati (per nome). */
function ccmRuolo(string $nome, array $permessi): string
{
    \Spatie\Permission\Models\Role::create(['name' => $nome, 'guard_name' => 'web'])->givePermissionTo($permessi);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $nome;
}

/**
 * Una comunicazione pubblicata e approvata, con i commenti accesi e la moderazione spenta salvo che i campi dicano
 * altro. Senza persone collegate e non privata va a palazzi interi; con persone collegate va a quelle persone.
 */
function ccmComunicazione(array $condomini, User $autore, array $campi = [], array $collegate = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Lavori al tetto', 'description' => 'I lavori cominciano lunedì.',
        'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false,
        'can_comment' => true,
    ], array_diff_key($campi, ['commenti_moderati' => true])));
    if (\Illuminate\Support\Facades\Schema::hasColumn('comunicazioni', 'commenti_moderati')) {
        DB::table('comunicazioni')->where('id', $c->id)->update(['commenti_moderati' => (bool) ($campi['commenti_moderati'] ?? false)]);
    }
    $c->condomini()->attach(array_map(fn (Condominio $k) => $k->id, $condomini));
    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c->fresh();
}

function ccmSegnalazione(Condominio $condominio, User $autore, array $campi = []): Segnalazione
{
    return Segnalazione::create(array_merge([
        'subject' => 'Il cancello non chiude', 'description' => 'Da due giorni il cancello dell\'ingresso B resta aperto.',
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $campi))->fresh();
}

/**
 * La chiave della discussione unica di una comunicazione ai palazzi, uno o più (decisione 458): tutti i destinatari e lo studio
 * leggono e scrivono lì. Prima c'era una discussione per palazzo, `palazzo:<id>` (decisione 261.1).
 */
function ccmChiaveUnica(): string
{
    return 'tutti';
}

function ccmChiavePersona(User $user): string
{
    return 'persona:' . $user->id;
}

/** Un commento già a database, in una discussione: serve a preparare gli stati senza passare dalla rotta. */
function ccmCommentoSalvato(Model $elemento, User $autore, string $corpo, ?Condominio $palazzo, ?User $persona = null, string $stato = 'pubblicato'): int
{
    return DB::table('commenti')->insertGetId([
        'commentable_type' => $elemento->getMorphClass(),
        'commentable_id' => $elemento->getKey(),
        'user_id' => $autore->id,
        'condominio_id' => $palazzo?->id,
        'discussione_user_id' => $persona?->id,
        'corpo' => $corpo,
        'stato' => $stato,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Scrive un commento dalla rotta della porta data. */
function ccmScrivi($test, User $user, Comunicazione $comunicazione, string $chiave, string $corpo, string $porta = 'user'): TestResponse
{
    return $test->actingAs($user)->post(
        route($porta . '.comunicazioni.commenti.store', $comunicazione),
        ['corpo' => $corpo, 'discussione' => $chiave]
    );
}

/** Le props della scheda della comunicazione, o `null` se la scheda non si apre. */
function ccmScheda($test, User $user, Comunicazione $comunicazione, string $porta = 'user'): ?array
{
    $risposta = $test->actingAs($user)->get(route($porta . '.comunicazioni.show', $comunicazione));
    if (in_array($risposta->status(), [403, 404], true)) {
        return null;
    }
    $risposta->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

/** Le chiavi delle discussioni che la scheda mostra all'utente, in ordine. */
function ccmChiaviInScheda($test, User $user, Comunicazione $comunicazione, string $porta = 'user'): array
{
    $props = ccmScheda($test, $user, $comunicazione, $porta);
    expect($props)->not->toBeNull("La scheda della comunicazione non si apre per {$user->name}");
    expect($props)->toHaveKey('discussioni');

    return collect($props['discussioni'])->pluck('chiave')->values()->all();
}

/** La discussione con la chiave data fra le props, o `null`. */
function ccmDiscussione(array $props, string $chiave): ?array
{
    return collect($props['discussioni'] ?? [])->firstWhere('chiave', $chiave);
}

/** I commenti di una comunicazione a database, in ordine. */
function ccmCommenti(Comunicazione $comunicazione): \Illuminate\Support\Collection
{
    return Commento::query()
        ->where('commentable_type', $comunicazione->getMorphClass())
        ->where('commentable_id', $comunicazione->getKey())
        ->orderBy('id')
        ->get();
}

/** La risposta è un rifiuto: chi non vede una discussione non sa nemmeno se esiste. */
function ccmRifiutato(TestResponse $risposta): bool
{
    return in_array($risposta->status(), [403, 404], true);
}

/** Gli utenti a cui è partita una notifica della classe data. */
function ccmDestinatari(string $classe): array
{
    $ids = [];
    foreach (User::all() as $u) {
        if (Notification::sent($u, $classe)->isNotEmpty()) {
            $ids[] = $u->id;
        }
    }
    sort($ids);

    return $ids;
}

function ccmIds(User ...$utenti): array
{
    $ids = array_map(fn (User $u) => $u->id, $utenti);
    sort($ids);

    return $ids;
}
