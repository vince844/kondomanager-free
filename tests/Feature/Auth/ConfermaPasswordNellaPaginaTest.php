<?php

/**
 * Coda 404 — la password ridigitata si dà in una finestra sulla stessa pagina, e la richiesta rifatta
 * salva quello che era stato scritto.
 *
 * ## Perché questo file esiste
 *
 * Le quindici azioni della Coda 261 (`ConfermaPasswordRecente`) chiedono la password ridigitata da non
 * più di quindici minuti. A conferma scaduta il server risponde alle richieste che non sono una
 * pagina del browser, quelle Inertia e quelle JSON, con un 423 che la pagina riconosce
 * (`conferma_password: true`): la pagina non lascia il modulo, e la modifica scritta resta dov'è. Apre
 * una finestra, manda la password in JSON a `POST /confirm-password` (che risponde 200
 * `{"confermata": true}`) e rifà la stessa richiesta con gli stessi dati. Lo scarico di un backup,
 * che è un collegamento e non una richiesta della pagina, chiede prima lo stato della conferma a
 * `GET /confirm-password/stato`.
 *
 * Il file prova il server, per ognuna delle quindici azioni, con le richieste come le manda la pagina:
 * le Inertia con `X-Inertia`, `X-Requested-With` e `Accept: text/html, application/xhtml+xml` (senza
 * `Accept` Laravel le leggerebbe come richieste JSON); le `fetch` e `axios` con `Accept: application/json`.
 *
 * - il 423 arriva prima di ogni scrittura: nessuna tabella del database cambia, e la risposta non porta i
 *   dati della pagina protetta;
 * - il 423 non lascia un `url.intended` in sessione e toglie quello eventuale;
 * - il caso della Coda: la richiesta con il modulo compilato riceve il 423 e non salva; la conferma in JSON
 *   risponde 200; la stessa richiesta salva i dati scritti;
 * - il browser senza JavaScript (un modulo che non è Inertia) riceve il rimando alla pagina di conferma, che
 *   resta come rete di sicurezza;
 * - la conferma in JSON: password sbagliata 422 con l'errore sul campo, il limite delle prove 429 in JSON,
 *   la conferma come modulo con il rimando di sempre;
 * - lo stato della conferma, per lo scarico.
 *
 * ## Cosa questo file NON copre
 *
 * Che cosa fa la pagina con il 423 (la finestra, la richiesta rifatta con gli stessi dati e le stesse
 * funzioni di ritorno): è del vitest (`FinestraConfermaPassword.test.ts`, `useConfermaPassword.test.ts`,
 * `lib/clientHttpConConferma.test.ts` per le visite di Inertia, `lib/confermaPasswordDiInizioAFine.test.ts` per il
 * caso della Coda dal modulo alla modifica salvata).
 *
 * Quando la password si chiede e quando no, per ogni azione (`ConfermaPasswordESessioniTest`,
 * `ConfermaPasswordPerIPoteriTest`, `ConfermaPasswordPermessiEmailRegistrazioneTest`).
 */

use App\Enums\BackupStatus;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupPasswordStore;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Mail::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);

    $this->conDueFattori = User::factory()->create([
        'email_verified_at'         => now(),
        'two_factor_secret'         => encrypt((new Google2FA)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['recupero-uno-0001'])),
        'two_factor_confirmed_at'   => now(),
    ]);

    $this->senzaDueFattori = User::factory()->create(['email_verified_at' => now()]);
});

afterEach(function () {
    app(BackupPasswordStore::class)->clear();
});

// ─── Le richieste, come le manda la pagina ───────────────────────────────────────────────────────────

/** Una visita Inertia (`useForm`, `router.*`, `Link`): non chiede JSON, e porta la versione dei file della pagina. */
function c404Inertia(): array
{
    return [
        'X-Inertia'         => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With'  => 'XMLHttpRequest',
        'Accept'            => 'text/html, application/xhtml+xml',
    ];
}

/** Una chiamata `fetch` o `axios` della pagina: chiede JSON. */
function c404Json(): array
{
    return ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
}

/** Una richiesta nuova di `$chi`, con le sole intestazioni che manda la pagina: quelle di una richiesta di prima non restano. */
function c404Come($test, User $chi, array $intestazioni)
{
    return $test->flushHeaders()->actingAs($chi)->withHeaders($intestazioni);
}

/** La conferma come la manda la finestra: `axios.post` con `Accept: application/json`. */
function c404ConfermaInGiu($test, User $chi, string $password = 'password')
{
    return c404Come($test, $chi, c404Json())->postJson('/confirm-password', ['password' => $password]);
}

/** Una fotografia del database: ogni tabella, con l'impronta delle sue righe. */
function c404Fotografia(): array
{
    $foto = [];

    foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $tabella) {
        $foto[$tabella->name] = md5(json_encode(DB::table($tabella->name)->get()->all()));
    }

    return $foto;
}

function c404IdPermessi(PermissionEnum ...$permessi): array
{
    return array_map(fn (PermissionEnum $p) => Permission::where('name', $p->value)->firstOrFail()->id, $permessi);
}

function c404IdRuolo(string $nome): int
{
    return Role::where('name', $nome)->firstOrFail()->id;
}

/** I nomi dei permessi, nell'ordine in cui li dà `Collection::sort()`. */
function c404Ordinati(PermissionEnum ...$permessi): array
{
    return collect($permessi)->map(fn (PermissionEnum $p) => $p->value)->sort()->values()->all();
}

function c404SenzaCache(): void
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/** Le impostazioni della posta di una installazione che ha già un server e una password. */
function c404PostaSalvata(): void
{
    $posta = app(MailSettings::class);
    $posta->mail_enabled = true;
    $posta->mail_driver = 'smtp';
    $posta->mail_host = 'smtp.vero.example';
    $posta->mail_port = 587;
    $posta->mail_username = 'utente@vero.example';
    $posta->mail_password = Crypt::encryptString('segreto-smtp-vero');
    $posta->mail_encryption = 'tls';
    $posta->mail_from_address = 'condominio@vero.example';
    $posta->mail_from_name = 'Studio';
    $posta->save();
}

/** Il modulo della posta come lo manda la pagina, con la password lasciata vuota. */
function c404CorpoPosta(array $cambi = []): array
{
    return array_merge([
        'mail_enabled'      => true,
        'mail_driver'       => 'smtp',
        'mail_host'         => 'smtp.vero.example',
        'mail_port'         => 587,
        'mail_username'     => 'utente@vero.example',
        'mail_password'     => '',
        'mail_encryption'   => 'tls',
        'mail_from_address' => 'condominio@vero.example',
        'mail_from_name'    => 'Studio',
    ], $cambi);
}

/** Il modulo delle impostazioni generali come lo manda la pagina, dai dati che riceve. */
function c404Impostazioni($test, User $chi, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];

    return array_merge([
        'user_frontend_registration' => (bool) $props['can_register'],
        'language'                   => $props['language'],
        'app_name'                   => $props['app_name'],
        'open_condominio_on_login'   => (bool) $props['open_condominio_on_login'],
        'default_condominio_id'      => $props['default_condominio_id'] ? (string) $props['default_condominio_id'] : '',
        'default_user_role'          => $props['default_user_role'],
        'force_comment_moderation'   => (bool) $props['force_comment_moderation'],
        'default_per_page'           => (int) $props['default_per_page'],
    ], $modifiche);
}

/**
 * Le quindici azioni della Coda 261, ognuna con la richiesta come la manda la sua pagina.
 *
 * Per ogni azione: `chi` agisce; `prepara` mette lo stato di partenza; `invia` fa la richiesta; `intatto`
 * dice che cosa deve essere rimasto com'era dopo il 423; `fatto` dice che cosa è successo dopo la
 * conferma; `risposta` controlla la risposta della richiesta rifatta; `pagina` dice se la pagina la manda
 * come visita Inertia o come chiamata JSON (le quattro della 2FA e la prova della posta).
 *
 * @return array<string, mixed>
 */
function c404Azione(string $nome, $t): array
{
    $inertia = c404Inertia();
    $json = c404Json();

    return match ($nome) {
        // 1 — la pagina della 2FA, una navigazione GET con `Link`.
        '2fa-pagina' => [
            'chi' => $t->conDueFattori, 'pagina' => 'inertia', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->conDueFattori, $inertia)->get(route('two-factor.show')),
            'intatto' => fn ($r) => $r->assertDontSee('recupero-uno-0001'),
            'risposta' => fn ($r) => $r->assertOk()->assertJsonPath('component', 'settings/TwoFactor')->assertJsonPath('props.recoveryCodes', ['recupero-uno-0001']),
            'fatto' => fn () => null,
        ],
        // 2 — attivare la 2FA: il segreto nasce qui.
        '2fa-attiva' => [
            'chi' => $t->senzaDueFattori, 'pagina' => 'json', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->senzaDueFattori, $json)->json('POST', route('two-factor.enable')),
            'intatto' => fn () => expect($t->senzaDueFattori->fresh()->two_factor_secret)->toBeNull(),
            'risposta' => fn ($r) => $r->assertOk()->assertJsonStructure(['qrCode', 'secret']),
            'fatto' => fn () => expect($t->senzaDueFattori->fresh()->two_factor_secret)->not->toBeNull(),
        ],
        // 3 — confermare la 2FA con il codice a sei cifre.
        '2fa-conferma' => [
            'chi' => $t->senzaDueFattori, 'pagina' => 'json',
            'prepara' => function () use ($t) {
                $t->segreto404 = (new Google2FA)->generateSecretKey();
                $t->senzaDueFattori->forceFill(['two_factor_secret' => encrypt($t->segreto404)])->save();
            },
            'invia' => fn () => c404Come($t, $t->senzaDueFattori, $json)
                ->json('POST', route('two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($t->segreto404)]),
            'intatto' => fn () => expect($t->senzaDueFattori->fresh()->two_factor_confirmed_at)->toBeNull(),
            'risposta' => fn ($r) => $r->assertOk()->assertJsonPath('status', 'two-factor-authentication-confirmed'),
            'fatto' => fn () => expect($t->senzaDueFattori->fresh()->two_factor_confirmed_at)->not->toBeNull(),
        ],
        // 4 — rigenerare i codici di recupero.
        '2fa-codici' => [
            'chi' => $t->conDueFattori, 'pagina' => 'json', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->conDueFattori, $json)->json('POST', route('two-factor.regenerate-recovery-codes')),
            'intatto' => fn () => expect(json_decode(decrypt($t->conDueFattori->fresh()->two_factor_recovery_codes)))->toBe(['recupero-uno-0001']),
            'risposta' => fn ($r) => $r->assertOk()->assertJsonStructure(['recovery_codes']),
            'fatto' => fn () => expect(json_decode(decrypt($t->conDueFattori->fresh()->two_factor_recovery_codes)))->not->toBe(['recupero-uno-0001']),
        ],
        // 5 — disattivare la 2FA.
        '2fa-disattiva' => [
            'chi' => $t->conDueFattori, 'pagina' => 'json', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->conDueFattori, $json)->json('DELETE', route('two-factor.disable')),
            'intatto' => fn () => expect($t->conDueFattori->fresh()->two_factor_confirmed_at)->not->toBeNull(),
            'risposta' => fn ($r) => $r->assertSuccessful(),
            'fatto' => fn () => expect($t->conDueFattori->fresh()->two_factor_confirmed_at)->toBeNull(),
        ],
        // 6 — salvare le impostazioni della posta.
        'posta-salva' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia', 'prepara' => fn () => c404PostaSalvata(),
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('impostazioni.mail'))
                ->post(route('admin.settings.mail.update'), c404CorpoPosta(['mail_from_name' => "Studio D'Angelo – Condominî"])),
            'intatto' => fn () => expect(app(MailSettings::class)->refresh()->mail_from_name)->toBe('Studio'),
            'risposta' => fn ($r) => $r->assertRedirect(route('impostazioni.mail')),
            'fatto' => fn () => expect(app(MailSettings::class)->refresh()->mail_from_name)->toBe("Studio D'Angelo – Condominî"),
        ],
        // 7 — la prova della posta, con il modulo intero.
        'posta-prova' => [
            'chi' => $t->amministratore, 'pagina' => 'json', 'prepara' => fn () => c404PostaSalvata(),
            'invia' => fn () => c404Come($t, $t->amministratore, $json)
                ->json('POST', route('admin.settings.mail.test'), c404CorpoPosta(['mail_host' => 'smtp.prova404.example', 'mail_password' => 'nuova-password-smtp', 'test_email' => 'prova@vero.example'])),
            'intatto' => fn () => expect(config('mail.mailers.smtp.host'))->not->toBe('smtp.prova404.example'),
            'risposta' => fn ($r) => $r->assertOk()->assertJsonPath('success', true),
            'fatto' => fn () => expect(config('mail.mailers.smtp.host'))->toBe('smtp.prova404.example')
                ->and(config('mail.mailers.smtp.password'))->toBe('nuova-password-smtp'),
        ],
        // 8 — lo scarico di un backup (il collegamento della pagina non porta le intestazioni Inertia: qui si prova il 423 generale).
        'backup-scarico' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia',
            'prepara' => function () use ($t) {
                Storage::fake('backups');
                Storage::disk('backups')->put('kondo-prova.zip', 'contenuto del backup');
                $t->backup404 = Backup::create(['status' => BackupStatus::COMPLETED, 'filename' => 'kondo-prova.zip', 'disk' => 'backups']);
            },
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->get(route('impostazioni.backups.download', $t->backup404->uuid)),
            'intatto' => fn ($r) => $r->assertHeaderMissing('Content-Disposition'),
            'risposta' => fn ($r) => $r->assertOk()->assertDownload('kondo-prova.zip'),
            'fatto' => fn () => null,
        ],
        // 9 — impostare una password nuova dei backup, dal modulo delle impostazioni.
        'backup-impostazioni' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('impostazioni.backups'))
                ->post(route('impostazioni.backups.settings'), [
                    'retention_keep_last' => 9, 'password' => 'chiave-nuova-dei-backup', 'password_confirmation' => 'chiave-nuova-dei-backup',
                ]),
            'intatto' => fn () => expect(app(BackupPasswordStore::class)->has())->toBeFalse()
                ->and(app(\App\Settings\BackupSettings::class)->refresh()->retention_keep_last)->not->toBe(9),
            'risposta' => fn ($r) => $r->assertRedirect(route('impostazioni.backups')),
            'fatto' => fn () => expect(app(BackupPasswordStore::class)->get())->toBe('chiave-nuova-dei-backup')
                ->and(app(\App\Settings\BackupSettings::class)->refresh()->retention_keep_last)->toBe(9),
        ],
        // 10 — togliere la password dei backup.
        'backup-password-tolta' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia',
            'prepara' => fn () => app(BackupPasswordStore::class)->set('chiave-dei-backup'),
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('impostazioni.backups'))
                ->post(route('impostazioni.backups.password'), ['remove' => true]),
            'intatto' => fn () => expect(app(BackupPasswordStore::class)->get())->toBe('chiave-dei-backup'),
            'risposta' => fn ($r) => $r->assertRedirect(route('impostazioni.backups')),
            'fatto' => fn () => expect(app(BackupPasswordStore::class)->has())->toBeFalse(),
        ],
        // 11 — il ruolo dei nuovi iscritti, dalle impostazioni generali.
        'generali' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia',
            'prepara' => fn () => $t->moduloGenerali404 = c404Impostazioni($t, $t->amministratore, [
                'default_user_role' => RoleEnum::AMMINISTRATORE->value, 'user_frontend_registration' => true, 'app_name' => 'Condominio Aurora',
            ]),
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('impostazioni.generali'))
                ->post(route('impostazioni.generali.store'), $t->moduloGenerali404),
            'intatto' => fn () => expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe(RoleEnum::UTENTE->value)
                ->and(app(GeneralSettings::class)->app_name)->not->toBe('Condominio Aurora'),
            'risposta' => fn ($r) => $r->assertRedirect(route('impostazioni.generali')),
            'fatto' => fn () => expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe(RoleEnum::AMMINISTRATORE->value)
                ->and(app(GeneralSettings::class)->app_name)->toBe('Condominio Aurora'),
        ],
        // 12 — creare un ruolo su misura.
        'ruolo-nuovo' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('ruoli.create'))
                ->post(route('ruoli.store'), [
                    'name' => 'Contabile', 'description' => "Contabilità «ordinaria» e d'esercizio",
                    'permissions' => c404IdPermessi(PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::VIEW_USERS), 'accessAdmin' => true,
                ]),
            'intatto' => fn () => expect(Role::where('name', 'Contabile')->exists())->toBeFalse(),
            'risposta' => fn ($r) => $r->assertRedirect(route('ruoli.index')),
            'fatto' => function () {
                c404SenzaCache();
                $ruolo = Role::where('name', 'Contabile')->first();
                expect($ruolo?->description)->toBe("Contabilità «ordinaria» e d'esercizio")
                    ->and($ruolo?->permissions->pluck('name')->sort()->values()->all())->toBe(c404Ordinati(
                        PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::VIEW_USERS,
                    ));
            },
        ],
        // 13 — modificare un ruolo su misura: il caso della demo, tre permessi in più.
        'ruolo-modifica' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia',
            'prepara' => function () use ($t) {
                $t->ruolo404 = Role::create(['name' => 'Amministratore demo', 'guard_name' => 'web', 'description' => 'Demo']);
                $t->ruolo404->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value, PermissionEnum::CREATE_USERS->value]);
            },
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('ruoli.edit', ['ruoli' => $t->ruolo404->id]))
                ->put(route('ruoli.update', ['ruoli' => $t->ruolo404->id]), [
                    'name' => 'Amministratore demo', 'description' => 'Demo',
                    'permissions' => c404IdPermessi(PermissionEnum::VIEW_USERS, PermissionEnum::CREATE_USERS, PermissionEnum::EDIT_USERS, PermissionEnum::DELETE_USERS, PermissionEnum::MANAGE_GENERAL_SETTINGS),
                    'accessAdmin' => true,
                ]),
            'intatto' => function () use ($t) {
                c404SenzaCache();
                expect($t->ruolo404->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(c404Ordinati(
                    PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::CREATE_USERS, PermissionEnum::VIEW_USERS,
                ));
            },
            'risposta' => fn ($r) => $r->assertRedirect(route('ruoli.index')),
            'fatto' => function () use ($t) {
                c404SenzaCache();
                expect($t->ruolo404->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(c404Ordinati(
                    PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::CREATE_USERS, PermissionEnum::DELETE_USERS,
                    PermissionEnum::EDIT_USERS, PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::VIEW_USERS,
                ));
            },
        ],
        // 14 — creare un utente con un ruolo oltre il portale.
        'utente-nuovo' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('utenti.create'))
                ->post(route('utenti.store'), [
                    'name' => "Ombra D'Àngelo", 'email' => 'ombra@example.com', 'roles' => c404IdRuolo(RoleEnum::AMMINISTRATORE->value),
                    'permissions' => [], 'anagrafica' => null,
                ]),
            'intatto' => fn () => expect(User::where('email', 'ombra@example.com')->exists())->toBeFalse(),
            'risposta' => fn ($r) => $r->assertRedirect(route('utenti.index')),
            'fatto' => fn () => expect(User::where('email', 'ombra@example.com')->first()?->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue(),
        ],
        // 15 — dare un ruolo oltre il portale dalla scheda di un utente.
        'utente-modifica' => [
            'chi' => $t->amministratore, 'pagina' => 'inertia', 'prepara' => fn () => null,
            'invia' => fn () => c404Come($t, $t->amministratore, $inertia)->from(route('utenti.edit', ['utenti' => $t->condomino->id]))
                ->put(route('utenti.update', ['utenti' => $t->condomino->id]), [
                    'name' => $t->condomino->name, 'email' => $t->condomino->email, 'roles' => c404IdRuolo(RoleEnum::AMMINISTRATORE->value),
                    'permissions' => [], 'anagrafica' => null,
                ]),
            'intatto' => fn () => expect($t->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]),
            'risposta' => fn ($r) => $r->assertRedirect(route('utenti.index')),
            'fatto' => fn () => expect($t->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value]),
        ],
    };
}

dataset('le quindici azioni', [
    '1 la pagina della 2FA' => ['2fa-pagina'],
    '2 attivare la 2FA' => ['2fa-attiva'],
    '3 confermare la 2FA' => ['2fa-conferma'],
    '4 rigenerare i codici di recupero' => ['2fa-codici'],
    '5 disattivare la 2FA' => ['2fa-disattiva'],
    '6 salvare la posta' => ['posta-salva'],
    '7 provare la posta' => ['posta-prova'],
    '8 scaricare un backup' => ['backup-scarico'],
    '9 la password dei backup, dalle impostazioni' => ['backup-impostazioni'],
    '10 togliere la password dei backup' => ['backup-password-tolta'],
    '11 il ruolo dei nuovi iscritti' => ['generali'],
    '12 creare un ruolo' => ['ruolo-nuovo'],
    '13 modificare un ruolo' => ['ruolo-modifica'],
    '14 creare un utente' => ['utente-nuovo'],
    '15 modificare un utente' => ['utente-modifica'],
]);

// ─── Il 423, prima di ogni scrittura ─────────────────────────────────────────────────────────────────

it('Coda 404 — a conferma scaduta la richiesta della pagina riceve il 423 che la pagina riconosce, prima di ogni scrittura', function (string $nome) {
    $azione = c404Azione($nome, $this);
    $azione['prepara']();
    $prima = c404Fotografia();

    $risposta = $azione['invia']();

    $risposta->assertStatus(423)
        ->assertJsonPath('conferma_password', true)
        ->assertJsonPath('message', __('auth.header.confirm_password.description'));
    $azione['intatto']($risposta);

    // Nessuna tabella del database è cambiata: il controllo viene prima di qualunque scrittura.
    expect(c404Fotografia())->toBe($prima);
})->with('le quindici azioni');

it('Coda 404 — il 423 non lascia un indirizzo di ritorno in sessione, e toglie quello che c\'era', function (string $nome) {
    $azione = c404Azione($nome, $this);
    $azione['prepara']();

    // Un ritorno rimasto da un'altra richiesta non decide dove andrà la prossima conferma.
    $this->withSession(['url.intended' => 'https://esempio.test/rimasto'])->actingAs($azione['chi']);
    $azione['invia']()->assertStatus(423);

    $this->assertFalse(session()->has('url.intended'));
})->with('le quindici azioni');

it('Coda 404 — il caso della Coda: la richiesta con il modulo compilato riceve il 423, la conferma in JSON risponde 200 e la stessa richiesta salva i dati scritti', function (string $nome) {
    $azione = c404Azione($nome, $this);
    $azione['prepara']();

    $prima = $azione['invia']();
    $prima->assertStatus(423);
    $azione['intatto']($prima);

    c404ConfermaInGiu($this, $azione['chi'])->assertOk()->assertExactJson(['confermata' => true]);

    $dopo = $azione['invia']();
    $azione['risposta']($dopo);
    $azione['fatto']();
})->with('le quindici azioni');

it('Coda 404 — con la conferma ancora valida la richiesta della pagina passa senza il 423', function (string $nome) {
    $azione = c404Azione($nome, $this);
    $azione['prepara']();

    $this->withSession(['auth.password_confirmed_at' => time() - 60]);
    $risposta = $azione['invia']();

    expect($risposta->getStatusCode())->not->toBe(423);
    $azione['risposta']($risposta);
    $azione['fatto']();
})->with('le quindici azioni');

it('Coda 404 — la pagina della 2FA e lo scarico aperti dal browser (non da Inertia) ricevono ancora il rimando alla pagina di conferma', function () {
    $azione = c404Azione('backup-scarico', $this);
    $azione['prepara']();

    $this->actingAs($this->conDueFattori)->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));
    $this->actingAs($this->amministratore)->get(route('impostazioni.backups.download', $this->backup404->uuid))
        ->assertRedirect(route('password.confirm'));
});

it('Coda 404 — i salvataggi mandati come modulo del browser ricevono il rimando alla pagina di conferma, e niente è salvato', function () {
    $this->actingAs($this->amministratore)->from(route('ruoli.create'))
        ->post(route('ruoli.store'), ['name' => 'Contabile', 'description' => 'x', 'permissions' => [], 'accessAdmin' => false])
        ->assertRedirect(route('password.confirm'));
    expect(Role::where('name', 'Contabile')->exists())->toBeFalse();

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), ['name' => 'Ombra', 'email' => 'ombra@example.com', 'roles' => c404IdRuolo(RoleEnum::AMMINISTRATORE->value), 'permissions' => [], 'anagrafica' => null])
        ->assertRedirect(route('password.confirm'));
    expect(User::where('email', 'ombra@example.com')->exists())->toBeFalse();

    c404PostaSalvata();
    $this->actingAs($this->amministratore)->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), c404CorpoPosta(['mail_from_name' => 'Altro']))
        ->assertRedirect(route('password.confirm'));
    expect(app(MailSettings::class)->refresh()->mail_from_name)->toBe('Studio');
});

it('Coda 404 — un\'azione che non chiede la password passa senza il 423, anche con le intestazioni di Inertia', function () {
    // Salvare un condòmino senza dargli poteri non chiede niente: il 423 non è per ogni richiesta Inertia.
    c404Come($this, $this->amministratore, c404Inertia())->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), [
            'name' => 'Nuovo Nome', 'email' => $this->condomino->email, 'roles' => c404IdRuolo(RoleEnum::UTENTE->value),
            'permissions' => [], 'anagrafica' => null,
        ])
        ->assertRedirect(route('utenti.index'));

    expect($this->condomino->fresh()->name)->toBe('Nuovo Nome');
});

// ─── La conferma in JSON ─────────────────────────────────────────────────────────────────────────────

it('Coda 404 — la conferma in JSON con la password giusta risponde 200 e scrive l\'ora della conferma, senza un indirizzo di ritorno', function () {
    $prima = time();

    c404ConfermaInGiu($this, $this->amministratore)->assertOk()->assertExactJson(['confermata' => true]);

    expect((int) session('auth.password_confirmed_at'))->toBeGreaterThanOrEqual($prima);
    $this->assertFalse(session()->has('url.intended'));
});

it('Coda 404 — la conferma in JSON non usa né consuma l\'indirizzo di ritorno di una conferma come pagina', function () {
    $this->withSession(['url.intended' => route('two-factor.show')]);

    c404ConfermaInGiu($this, $this->amministratore)->assertOk();

    // Non è un rimando: la risposta è il JSON, e l'indirizzo che la pagina aveva chiesto per un'altra via resta com'era.
    expect(session('url.intended'))->toBe(route('two-factor.show'));
});

it('Coda 404 — la conferma in JSON con la password sbagliata risponde 422 con l\'errore sul campo, e non conferma niente', function () {
    c404ConfermaInGiu($this, $this->amministratore, 'sbagliata')
        ->assertStatus(422)
        ->assertJsonValidationErrors('password')
        ->assertJsonPath('errors.password.0', __('auth.password'));

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('Coda 404 — la settima prova in un minuto riceve il 429 in JSON, con i secondi da aspettare', function () {
    for ($i = 0; $i < 6; $i++) {
        c404ConfermaInGiu($this, $this->amministratore, "sbagliata-{$i}")->assertStatus(422);
    }

    $risposta = c404ConfermaInGiu($this, $this->amministratore);

    $risposta->assertStatus(429)->assertJsonStructure(['message']);
    expect($risposta->headers->get('Content-Type'))->toContain('application/json')
        ->and((int) $risposta->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('Coda 404 — la conferma come modulo (la pagina di conferma) fa ancora il rimando di sempre', function () {
    $this->actingAs($this->amministratore)->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));

    // Dalla pagina di conferma, con le intestazioni Inertia del suo modulo: il rimando all'indirizzo chiesto.
    c404Come($this, $this->amministratore, c404Inertia())->from('/confirm-password')
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('two-factor.show'));

    expect((int) session('auth.password_confirmed_at'))->toBeGreaterThan(0);
});

it('Coda 404 — la conferma come modulo con la password sbagliata torna alla pagina con l\'errore sul campo', function () {
    c404Come($this, $this->amministratore, c404Inertia())->from('/confirm-password')
        ->post('/confirm-password', ['password' => 'sbagliata'])
        ->assertRedirect('/confirm-password')
        ->assertSessionHasErrors('password');
});

// ─── Lo stato della conferma, per lo scarico ─────────────────────────────────────────────────────────

it('Coda 404 — lo stato della conferma dice false senza conferma e dopo quindici minuti, true prima', function () {
    expect(route('password.confirm.stato', absolute: false))->toBe('/confirm-password/stato');

    c404Come($this, $this->amministratore, c404Json())->getJson(route('password.confirm.stato'))
        ->assertOk()->assertExactJson(['confermata' => false]);

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
        ->getJson(route('password.confirm.stato'))->assertOk()->assertExactJson(['confermata' => true]);

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time() - (15 * 60 + 5)])
        ->getJson(route('password.confirm.stato'))->assertOk()->assertExactJson(['confermata' => false]);
});

it('Coda 404 — lo stato della conferma diventa true dopo la conferma in JSON, e la lettura non conferma niente', function () {
    $this->actingAs($this->amministratore)->getJson(route('password.confirm.stato'))->assertExactJson(['confermata' => false]);
    $this->actingAs($this->amministratore)->getJson(route('password.confirm.stato'))->assertExactJson(['confermata' => false]);

    c404ConfermaInGiu($this, $this->amministratore)->assertOk();

    $this->actingAs($this->amministratore)->getJson(route('password.confirm.stato'))->assertExactJson(['confermata' => true]);
});

it('Coda 404 — lo stato della conferma è di chi è entrato: un ospite non ci arriva', function () {
    $this->getJson(route('password.confirm.stato'))->assertUnauthorized();
    $this->get(route('password.confirm.stato'))->assertRedirect(route('login'));
});

it('Coda 404 — con la conferma valida lo stato dice true e lo scarico parte', function () {
    $azione = c404Azione('backup-scarico', $this);
    $azione['prepara']();

    // La pagina chiede lo stato, trova `true` e va all'indirizzo del file: il file si scarica.
    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time() - 30]);
    $this->getJson(route('password.confirm.stato'))->assertExactJson(['confermata' => true]);

    $this->get(route('impostazioni.backups.download', $this->backup404->uuid))->assertOk()->assertDownload('kondo-prova.zip');
});
