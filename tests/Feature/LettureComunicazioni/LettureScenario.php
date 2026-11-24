<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Lo scenario dei test della conferma di lettura delle comunicazioni (Coda 594, decisioni 310 e 311)
 *
 * È lo scenario dei commenti (`CommentiScenario.php`: due palazzi, Aurora e Borea, lo studio e tre condòmini, con i
 * ruoli di serie), con l'orologio fermo a **giovedì 12/11/2026, ore 10:00 UTC** (le 11:00 a Roma), e in più:
 * - **Giulia Senzaccount**, una persona in rubrica nel palazzo Aurora che **non ha un account**: è il caso di tutti i
 *   giorni, e nella scheda risulta «senza accesso al portale»;
 * - `$this->c`, una comunicazione dello studio al palazzo Aurora, pubblicata e approvata.
 *
 * I destinatari di `$this->c`, cioè chi la trova nel portale meno chi l'ha scritta: Anna Collaboratrice (ha
 * un'anagrafica in Aurora), Giulia Senzaccount, Mario Rossi e Paolo Neri. Lo Studio Bianchi l'ha scritta e non conta.
 *
 * La tabella, le colonne, le rotte e l'impostazione nuove si nominano per stringa: finché non esistono, il test
 * fallisce sul punto che manca, non su un errore di sintassi del file.
 */

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

const LCT_ADESSO = '2026-11-12 10:00:00';
const LCT_TABELLA = 'comunicazione_letture';
const LCT_COLONNA = 'chiedi_presa_visione';
const LCT_IMPOSTAZIONE = 'comunicazioni_registra_aperture';
const LCT_MIGRAZIONE_TABELLA = 'migrations/2026_11_23_110000_create_comunicazione_letture_table.php';
const LCT_MIGRAZIONE_COLONNA = 'migrations/2026_11_23_110100_add_chiedi_presa_visione_to_comunicazioni.php';
const LCT_MIGRAZIONE_IMPOSTAZIONE = 'settings/2026_11_23_110200_add_comunicazioni_registra_aperture_to_general_settings.php';
const LCT_ROTTA_PRESA_VISIONE = 'user.comunicazioni.presa-visione';
const LCT_ROTTA_CANCELLA = 'impostazioni.generali.aperture.destroy';

/** I palazzi e le persone dei commenti, Giulia senza account, la comunicazione e l'orologio fermo. Nel `beforeEach`. */
function lctPrepara($test): void
{
    $test->travelTo(Carbon::parse(LCT_ADESSO, 'UTC'));
    ccmPrepara($test);
    $test->giulia = lctSenzaAccount('Giulia Senzaccount', [$test->aurora->id]);
    $test->c = ccmComunicazione([$test->aurora], $test->admin);
}

/** Una persona in rubrica nei palazzi dati, senza account. */
function lctSenzaAccount(string $nome, array $condominiIds): Anagrafica
{
    $anagrafica = Anagrafica::factory()->create(['user_id' => null, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominiIds);

    return $anagrafica->fresh();
}

/** Accende o spegne l'impostazione generale «Registra quando una comunicazione viene aperta nel portale». */
function lctImpostazione(bool $accesa): void
{
    $impostazioni = app(GeneralSettings::class);
    expect(property_exists($impostazioni, LCT_IMPOSTAZIONE))->toBeTrue("Manca l'impostazione generale " . LCT_IMPOSTAZIONE);
    $impostazioni->{LCT_IMPOSTAZIONE} = $accesa;
    $impostazioni->save();
}

/** Com'è adesso l'impostazione generale, riletta dal database. */
function lctImpostazioneAccesa(): bool
{
    app()->forgetInstance(GeneralSettings::class);

    return (bool) app(GeneralSettings::class)->{LCT_IMPOSTAZIONE};
}

/** Mette o toglie «Chiedi la presa visione» su una comunicazione, dal database. */
function lctChiedi(Comunicazione $comunicazione, bool $chiesta = true): Comunicazione
{
    expect(Schema::hasColumn('comunicazioni', LCT_COLONNA))->toBeTrue('Manca la colonna comunicazioni.' . LCT_COLONNA);
    DB::table('comunicazioni')->where('id', $comunicazione->id)->update([LCT_COLONNA => $chiesta]);

    return $comunicazione->fresh();
}

/**
 * La comunicazione chiede la presa visione? Letto dal database. Prima controlla che la colonna ci sia: su SQLite una
 * colonna che non esiste, scritta fra virgolette, si legge come una stringa, e il test passerebbe da solo.
 */
function lctChiesta(int $comunicazioneId): bool
{
    expect(Schema::hasColumn('comunicazioni', LCT_COLONNA))->toBeTrue('Manca la colonna comunicazioni.' . LCT_COLONNA);

    return (bool) DB::table('comunicazioni')->where('id', $comunicazioneId)->value(LCT_COLONNA);
}

/** Apre la scheda di una comunicazione dalla porta data: `user` è il portale, `admin` il pannello. */
function lctApri($test, User $user, Comunicazione $comunicazione, string $porta = 'user', array $intestazioni = []): TestResponse
{
    return $test->actingAs($user)->get(route($porta . '.comunicazioni.show', $comunicazione), $intestazioni);
}

/** Il condòmino preme «Confermo di aver letto». */
function lctConferma($test, User $user, Comunicazione $comunicazione): TestResponse
{
    return $test->actingAs($user)->post(route(LCT_ROTTA_PRESA_VISIONE, $comunicazione));
}

/** Tutte le righe del registro delle letture, o quelle di una comunicazione. */
function lctRighe(?Comunicazione $comunicazione = null): Collection
{
    expect(Schema::hasTable(LCT_TABELLA))->toBeTrue('Manca la tabella ' . LCT_TABELLA);

    return DB::table(LCT_TABELLA)
        ->when($comunicazione, fn ($q) => $q->where('comunicazione_id', $comunicazione->id))
        ->orderBy('id')
        ->get();
}

/** La riga del registro di una persona per una comunicazione, o `null`. */
function lctRiga(Comunicazione $comunicazione, User $user): ?object
{
    return lctRighe($comunicazione)->firstWhere('user_id', $user->id);
}

/** Scrive una riga del registro dal database: serve a preparare gli stati senza passare dalle pagine. Le date sono UTC. */
function lctScrivi(Comunicazione $comunicazione, User $user, ?string $apertaIl, ?string $presaVisioneIl = null): void
{
    expect(Schema::hasTable(LCT_TABELLA))->toBeTrue('Manca la tabella ' . LCT_TABELLA);
    DB::table(LCT_TABELLA)->insert([
        'comunicazione_id' => $comunicazione->id,
        'user_id' => $user->id,
        'aperta_il' => $apertaIl,
        'presa_visione_il' => $presaVisioneIl,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Le props di una pagina Inertia, o `null` se la pagina non si apre. */
function lctProps(TestResponse $risposta): ?array
{
    if (in_array($risposta->status(), [302, 403, 404], true)) {
        return null;
    }
    $risposta->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

/** Una risorsa fra le props: sciolta o dentro `data`. */
function lctRisorsa(mixed $valore): array
{
    return is_array($valore) && array_key_exists('data', $valore) && is_array($valore['data']) && ! array_is_list($valore) ? $valore['data'] : (array) $valore;
}

/** Le righe dell'elenco delle comunicazioni fra le props (un elenco sciolto o dentro `data`), per id. */
function lctElenco(array $props): array
{
    $valore = $props['comunicazioni'] ?? [];
    $righe = array_values(array_is_list($valore) ? $valore : ($valore['data'] ?? []));

    return collect($righe)->keyBy('id')->all();
}

/** La prop `letture` della scheda del pannello. */
function lctLetture($test, User $user, Comunicazione $comunicazione): array
{
    $props = lctProps(lctApri($test, $user, $comunicazione, 'admin'));
    expect($props)->not->toBeNull('La scheda del pannello non si apre')
        ->and($props)->toHaveKey('letture');

    return $props['letture'];
}

/** La prop `lettura` della scheda del portale. */
function lctLettura($test, User $user, Comunicazione $comunicazione): array
{
    $props = lctProps(lctApri($test, $user, $comunicazione));
    expect($props)->not->toBeNull("La scheda del portale non si apre per {$user->name}")
        ->and($props)->toHaveKey('lettura');

    return $props['lettura'];
}

/** La risposta è un rifiuto. */
function lctRifiutato(TestResponse $risposta): bool
{
    return in_array($risposta->status(), [403, 404], true);
}

/** Quante richieste al database fa l'azione. */
function lctRichieste(callable $azione): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $azione();
    $quante = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $quante;
}

/**
 * Toglie dal database le righe di tutti i permessi tranne quelli dati: è lo stato di un'installazione prima del
 * seeder dei ruoli, e di molti test del core che creano solo i permessi che usano. Lì `hasPermissionTo()` su un
 * permesso che manca lancia un'eccezione, e la pagina cade con un errore 500.
 */
function lctSoloIPermessi(array $nomi): void
{
    $daTogliere = DB::table('permissions')->whereNotIn('name', $nomi)->pluck('id');
    DB::table('role_has_permissions')->whereIn('permission_id', $daTogliere)->delete();
    DB::table('model_has_permissions')->whereIn('permission_id', $daTogliere)->delete();
    DB::table('permissions')->whereIn('id', $daTogliere)->delete();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}
