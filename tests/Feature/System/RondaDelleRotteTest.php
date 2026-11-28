<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La ronda delle rotte: ogni rotta, con ogni suo metodo, chiamata da tre persone senza privilegi (Coda 202).
 *
 * Le tre persone: **l'ospite** (nessun login), **un condòmino di Belvedere** (ruolo «utente», con la sua anagrafica in
 * Belvedere e nessun permesso in più) e **un fornitore** (ruolo «fornitore»). Lo scenario è fisso: due condomini, Aurora
 * e Belvedere, e in Aurora un oggetto di ogni tipo (unità, persone, titolarità, tabella, gestione, piano dei conti, voce,
 * piano rate con le sue rate, cassa, saldo, assemblea con i suoi punti, segnalazione, comunicazione, commento, documento,
 * categoria, evento, fornitore). Ogni parametro delle rotte ha il suo valore in `rondaValori()`: un parametro nuovo senza
 * valore fa fallire la ronda con il nome della rotta; la mappa si completa, non si salta.
 *
 * Che cosa si pretende:
 * - **mai 500**, su nessuna rotta e da nessuno;
 * - sotto `/admin`, `/impostazioni`, `/utenti`, `/ruoli`, `/logs`, `/system` e l'importazione: **mai 2xx** (al più il rimando
 *   al login, 403 o 404);
 * - sulle rotte del portale che nominano un oggetto di Aurora, il condòmino di Belvedere e il fornitore ricevono 403, 404 o un
 *   rimando, mai 2xx;
 * - **nessuna scrittura** dopo una richiesta che non è `GET`, sulle rotte che chiedono il login: si confrontano, prima e dopo, il
 *   numero delle righe e l'ultimo `updated_at` di ogni tabella. Le rotte senza login che scrivono sono di
 *   `RottePubblicheCheScrivonoTest`; le rotte dell'account (la propria password, le proprie preferenze) possono scrivere sul solo
 *   account di chi chiama, e sono in `RONDA_SCRIVONO_SUL_PROPRIO_ACCOUNT` con il perché.
 *
 * I quattro gesti sulle persone (`users.permissions.destroy`, `roles.permissions.destroy`, `utenti.reinvite`,
 * `utenti.toggle-verification`) hanno come bersaglio un altro condòmino senza privilegi: la ronda li prova per costruzione.
 *
 * Che cosa non prova: che chi **ha** un permesso faccia solo ciò che deve (il ruolo su misura con il solo pannello è del modulo
 * dei permessi); i `GET` che mostrano qualcosa senza scrivere a chi entra legittimamente.
 */

use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
require_once __DIR__.'/../Assemblee/Convocazione/ConvocazioneDiViaRoma12.php';

/** L'id di un oggetto che lo scenario non costruisce: la rotta risponde 404 prima di arrivare al controller. */
const RONDA_ASSENTE = 987654;

/** Le rotte che la ronda non chiama, con il perché. Nome della rotta (o «METODO uri») => motivo. */
const RONDA_FUORI = [
    'storage.local' => 'del framework: serve i file del disco locale con un indirizzo firmato',
    'storage.local.upload' => 'del framework: riceve un file con un indirizzo firmato',
];

/**
 * Le rotte dell'account che, chiamate da chi ha fatto l'accesso, scrivono solo sul suo account. Nome => motivo.
 */
const RONDA_SCRIVONO_SUL_PROPRIO_ACCOUNT = [
    'logout' => 'chiude la propria sessione',
];

/** Le rotte del portale con un parametro che non è di un condominio: rispondono a chiunque entri nel portale. Nome => motivo. */
const RONDA_PORTALE_CONDIVISE = [
    'user.categorie-documenti.show' => 'le categorie sono di tutta l\'installazione; l\'elenco mostra solo i documenti dei condomini di chi la apre',
];

/** I prefissi delle rotte riservate allo studio e all'installazione: mai 2xx per le tre persone. */
const RONDA_RISERVATE = ['admin', 'impostazioni', 'utenti', 'ruoli', 'roles', 'users', 'logs', 'system', 'importa-dati', 'inviti', 'gestionale'];

/** Le tabelle che cambiano senza che nessuno scriva un dato: la sessione, la cache, le code, il registro degli errori. */
const RONDA_TABELLE_DI_SERVIZIO = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

/**
 * Lo scenario: Aurora con un oggetto di ogni tipo, Belvedere con il suo condòmino; il fornitore; il bersaglio (un altro
 * condòmino di Aurora, senza privilegi).
 *
 * @return array<string, mixed>
 */
function rondaScenario(\Tests\TestCase $t): array
{
    $t->seed(RolesAndPermissionsSeeder::class);

    $s = ConvocazioneDiViaRoma12::nuovo($t);
    $assemblea = ConvocazioneDiViaRoma12::ordinaria($s);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizie', 120000, ['Proprietà' => 100])
        ->pianoOrdinario('Piano 2026', 'Ordinaria 2026', 4)->genera('Piano 2026');
    $cassa = $s->cassa();
    $aurora = $s->c;
    $persona = $s->persone['rossi'];
    $unita = $s->unita['3'];

    $belvedere = Condominio::factory()->create(['nome' => 'Belvedere']);

    $condomino = User::factory()->create(['name' => 'Franco Neri', 'email' => 'franco@example.com', 'email_verified_at' => now()]);
    $condomino->assignRole(RoleEnum::UTENTE->value);
    $suaAnagrafica = Anagrafica::factory()->create(['user_id' => $condomino->id, 'nome' => 'Franco Neri']);
    $suaAnagrafica->condomini()->attach($belvedere->id);

    $fornitoreUtente = User::factory()->create(['email' => 'impresa@example.com', 'email_verified_at' => now()]);
    $fornitoreUtente->assignRole(RoleEnum::FORNITORE->value);

    $bersaglio = User::factory()->create(['email' => 'lucia@example.com', 'email_verified_at' => now()]);
    $bersaglio->assignRole(RoleEnum::UTENTE->value);
    $persona->forceFill(['user_id' => $bersaglio->id])->saveQuietly();

    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $aurora->id, 'created_by' => $bersaglio->id]);
    $comunicazione = Comunicazione::factory()->create(['created_by' => $s->admin->id, 'is_published' => true, 'is_approved' => true]);
    $comunicazione->condomini()->attach($aurora->id);
    $commento = Commento::factory()->create([
        'commentable_type' => Segnalazione::class, 'commentable_id' => $segnalazione->id, 'user_id' => $bersaglio->id, 'condominio_id' => $aurora->id,
    ]);
    $categoria = CategoriaDocumento::create(['name' => 'Verbali di assemblea', 'description' => 'I verbali']);
    $documento = Documento::create([
        'name' => 'Regolamento', 'description' => 'Il regolamento', 'created_by' => $s->admin->id, 'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/regolamento.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024,
    ]);
    $documento->condomini()->attach($aurora->id);
    $documento->categorie()->attach($categoria->id);
    $evento = Evento::create([
        'title' => 'Pulizia scale', 'description' => 'Pulizia', 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour(),
        'created_by' => $bersaglio->id, 'visibility' => 'public', 'is_approved' => true, 'timezone' => 'Europe/Rome',
    ]);
    $evento->condomini()->attach($aurora->id);

    $gestione = $s->gestioni['Ordinaria 2026'];
    $piano = $s->piani['Piano 2026']['p'];

    return [
        'aurora' => $aurora,
        'condomino' => $condomino,
        'fornitore' => $fornitoreUtente,
        'bersaglio' => $bersaglio,
        'id' => [
            'condominio' => $aurora->id,
            'esercizio' => $s->e->id,
            'assemblea' => $assemblea->id,
            'punto' => (int) (DB::table('assemblee_punti')->where('assemblea_id', $assemblea->id)->value('id') ?? RONDA_ASSENTE),
            'immobile' => $unita->id,
            'anagrafica' => $persona->id,
            'anagrafiche' => $persona->id,
            'persona' => $persona->id,
            'titolarita' => (int) (DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->value('id') ?? RONDA_ASSENTE),
            'tabella' => $s->tabelle['Proprietà']->id,
            'gestione' => $gestione['g']->id,
            'pianoConto' => $gestione['pc']->id,
            'conto' => $s->voci['Pulizie']['conto']->id,
            'capitolo' => $s->voci['Pulizie']['conto']->id,
            'pianoRate' => $piano->id,
            'rata' => (int) (DB::table('rate')->where('piano_rate_id', $piano->id)->value('id') ?? RONDA_ASSENTE),
            'cassa' => $cassa->id,
            'contoContabile' => $cassa->conto_contabile_id,
            'segnalazione' => $segnalazione->id,
            'comunicazione' => $comunicazione->id,
            'commento' => $commento->id,
            'documento' => $documento->id,
            'allegato' => $documento->id,
            'categoria' => $categoria->id,
            'categoriaDocumento' => $categoria->id,
            'evento' => $evento->id,
            'fornitore' => (int) (DB::table('fornitori')->value('id') ?? RONDA_ASSENTE),
            'user' => $bersaglio->id,
            'utenti' => $bersaglio->id,
            'ruoli' => (int) DB::table('roles')->where('name', RoleEnum::UTENTE->value)->value('id'),
            'role' => (int) DB::table('roles')->where('name', RoleEnum::UTENTE->value)->value('id'),
            'permission' => (int) DB::table('permissions')->value('id'),
        ],
    ];
}

/**
 * Il valore di ogni parametro delle rotte. Gli oggetti dello scenario per id; per gli altri tipi l'id di nessuno
 * (`RONDA_ASSENTE`: la rotta trova niente e risponde 404, e la ronda prova il rimando, il rifiuto o il 404 che arriva prima);
 * le stringhe per i parametri che non sono un id.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, string|int>
 */
function rondaValori(array $scenario): array
{
    $assenti = [
        'verbaleDellAssemblea', 'invio', 'fattura', 'versione', 'scrittura', 'delibera', 'delega', 'accordo', 'modello', 'pagamento',
        'saldo', 'rigaDiNomina', 'dispensa', 'subentro', 'memo', 'palazzina', 'scala', 'carta', 'delegaRicevuta', 'riga', 'addebito',
        'seduta', 'budgetMovement', 'regola', 'volta', 'cosa', 'task', 'testo', 'batch', 'inviti', 'condominio_id',
    ];

    return array_merge(
        array_fill_keys($assenti, RONDA_ASSENTE),
        $scenario['id'],
        [
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'tipo' => 'immobile',
            'id' => $scenario['id']['immobile'],
            'soggetto_tipo' => 'anagrafica',
            'soggetto_id' => $scenario['id']['anagrafica'],
            'file' => 'nessuno.csv',
            'path' => 'nessuno.txt',
            'filename' => 'nessuno.png',
            'backup' => 'nessuno.zip',
            'component' => 'nessuno',
            'step' => 'welcome',
            'token' => str_repeat('a', 64),
            'hash' => sha1('nessuno'),
            'email' => $scenario['bersaglio']->email,
        ],
    );
}

/** L'URI della rotta con i valori dello scenario, o il nome del parametro che manca. */
function rondaUri(Route $route, array $valori): string
{
    return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($route, $valori) {
        if (! array_key_exists($m[1], $valori)) {
            throw new RuntimeException("Il parametro {{$m[1]}} della rotta «".($route->getName() ?? $route->uri())."» non ha un valore in rondaValori().");
        }

        return rawurlencode((string) $valori[$m[1]]);
    }, '/'.ltrim($route->uri(), '/'));
}

/** Righe e ultimo `updated_at` di ogni tabella: due fotografie diverse dicono che qualcosa è stato scritto. */
function rondaFotografia(): array
{
    static $tabelle = null;
    $tabelle ??= collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name')->reject(fn ($t) => in_array($t, RONDA_TABELLE_DI_SERVIZIO, true))->values()->all();
    static $conData = [];

    $parti = [];
    foreach ($tabelle as $t) {
        $conData[$t] ??= \Illuminate\Support\Facades\Schema::hasColumn($t, 'updated_at');
        $parti[] = "SELECT '{$t}' AS t, COUNT(*) AS n, ".($conData[$t] ? 'MAX(updated_at)' : 'NULL')." AS u FROM \"{$t}\"";
    }

    $foto = [];
    foreach (array_chunk($parti, 200) as $pezzo) {
        foreach (DB::select(implode(' UNION ALL ', $pezzo)) as $r) {
            $foto[$r->t] = $r->n.'|'.$r->u;
        }
    }

    return $foto;
}

function rondaRiservata(string $uri): bool
{
    $primo = explode('/', ltrim($uri, '/'))[0];

    return in_array($primo, RONDA_RISERVATE, true);
}

/**
 * Chiama ogni rotta, con ogni metodo, come `$chi` (null: l'ospite) e torna gli esiti che non vanno.
 *
 * @return list<string>
 */
function rondaGiro(\Tests\TestCase $t, ?User $chi, array $scenario, bool $portaleSoloRifiuti): array
{
    $valori = rondaValori($scenario);
    $esiti = [];

    foreach (app('router')->getRoutes() as $route) {
        $chiave = $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();
        if (array_key_exists($chiave, RONDA_FUORI) || str_starts_with($route->uri(), '_')) {
            continue;
        }

        $uri = rondaUri($route, $valori);
        $conLogin = in_array(Authenticate::class, app('router')->gatherRouteMiddleware($route), true);
        $conOggetto = str_contains($route->uri(), '{');

        foreach (array_diff($route->methods(), ['HEAD']) as $metodo) {
            $prima = ($metodo !== 'GET' && $conLogin) ? rondaFotografia() : null;

            app('auth')->forgetGuards();
            if ($chi !== null) {
                $t->actingAs($chi);
            }
            $risposta = $t->call($metodo, $uri, [], [], [], ['HTTP_ACCEPT' => 'text/html']);
            $stato = $risposta->getStatusCode();
            $chiDice = $chi?->email ?? 'ospite';

            // 503 è la risposta voluta della salute (`/up`) e della manutenzione, non un guasto.
            if ($stato >= 500 && $stato !== 503) {
                $esiti[] = "{$stato} {$metodo} {$uri} ({$chiave}) da {$chiDice}";
            }
            // Un metodo vuoto (le rotte di risorsa mai scritte: `show` degli utenti e dei ruoli) risponde 200 senza niente: non mostra
            // e non scrive. Lo tiene d'occhio la categoria «metodo vuoto» di `RotteSoloLoginTest`.
            $vuota = trim((string) $risposta->getContent()) === '';

            if (! $vuota && rondaRiservata($route->uri()) && $stato >= 200 && $stato < 300) {
                $esiti[] = "{$stato} {$metodo} {$uri} ({$chiave}) da {$chiDice}: riservata";
            }
            if (! $vuota && $portaleSoloRifiuti && $conOggetto && str_starts_with($route->uri(), 'user/') && ! array_key_exists($chiave, RONDA_PORTALE_CONDIVISE)
                && $stato >= 200 && $stato < 300) {
                $esiti[] = "{$stato} {$metodo} {$uri} ({$chiave}) da {$chiDice}: oggetto di Aurora";
            }
            if ($prima !== null && ! array_key_exists($chiave, RONDA_SCRIVONO_SUL_PROPRIO_ACCOUNT)) {
                $dopo = rondaFotografia();
                $cambiate = array_keys(array_diff_assoc($dopo, $prima));
                if ($cambiate !== []) {
                    $esiti[] = "scrittura {$metodo} {$uri} ({$chiave}) da {$chiDice}: ".implode(', ', $cambiate);
                }
            }
        }
    }

    return $esiti;
}

beforeEach(function () {
    Notification::fake();
    Mail::fake();
    Queue::fake();
    Bus::fake();
    Http::fake();
    Storage::fake('local');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    config(['app.debug' => false]);
    $this->scenario = rondaScenario($this);
    Event::fake([\Illuminate\Auth\Events\Logout::class]);
});

it('l\'ospite: mai 500, niente di riservato, nessuna scrittura', function () {
    expect(rondaGiro($this, null, $this->scenario, false))->toBe([]);
});

it('il condòmino di Belvedere: mai 500, niente di riservato, niente di Aurora, nessuna scrittura', function () {
    expect(rondaGiro($this, $this->scenario['condomino'], $this->scenario, true))->toBe([]);
});

it('il fornitore: mai 500, niente di riservato, niente di Aurora, nessuna scrittura', function () {
    expect(rondaGiro($this, $this->scenario['fornitore'], $this->scenario, true))->toBe([]);
});

it('ogni parametro delle rotte ha un valore nella mappa della ronda', function () {
    $valori = rondaValori($this->scenario);
    $mancano = [];
    foreach (app('router')->getRoutes() as $route) {
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
        foreach (array_diff($m[1], array_keys($valori)) as $p) {
            $mancano[] = ($route->getName() ?? $route->uri()).": {{$p}}";
        }
    }

    expect($mancano)->toBe([]);
});

it('la fotografia delle tabelle vede una riga nuova e una riga cambiata', function () {
    $prima = rondaFotografia();
    DB::table('categorie_documento')->insert(['name' => 'Varie', 'description' => 'Varie', 'created_at' => now(), 'updated_at' => now()]);
    expect(array_keys(array_diff_assoc(rondaFotografia(), $prima)))->toBe(['categorie_documento']);

    $this->travel(1)->minutes();
    $prima = rondaFotografia();
    DB::table('segnalazioni')->where('id', $this->scenario['id']['segnalazione'])->update(['updated_at' => now()]);
    expect(array_keys(array_diff_assoc(rondaFotografia(), $prima)))->toBe(['segnalazioni']);
});
