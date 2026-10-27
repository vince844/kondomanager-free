<?php

/**
 * # Gli avvisi all'autore su un oggetto che non apre più (Code 263, 198 e 304)
 *
 * Fuori dal pannello l'autore di una segnalazione o di una comunicazione **non privata** la apre finché
 * la sua anagrafica è nel palazzo, o finché è indirizzata a lui (`scopeVisibiliNelPortale()`). Gli
 * avvisi che vanno a lui — «approvata», «resa pubblica» — portavano sempre il pulsante verso la scheda:
 * all'autore uscito dal palazzo il pulsante rispondeva 403, e la mail non diceva perché.
 *
 * La regola, una per tutti questi avvisi: se **quando la mail si compone** l'autore non apre l'oggetto
 * (la stessa domanda della scheda), la mail parte senza il pulsante e dice perché. La conferma di
 * «Rendi pubblica» nella pagina dell'amministratore dice, prima del comando, quando l'autore non la
 * vedrà più: la pagina riceve il fatto dal server (`rendi_pubblica.autore_perde_la_scheda`).
 *
 * La regola di chi vede che cosa non cambia: qui cambia solo ciò che le mail e la conferma dicono.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Comunicazioni\ApprovedComunicazioneNotification;
use App\Notifications\Segnalazioni\ApprovedSegnalazioneNotification;
use App\Notifications\Segnalazioni\SegnalazioneResaPubblicaNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
});

/** Un utente con il ruolo `$ruolo`, le preferenze accese e, se `$condominiIds` non è `null`, l'anagrafica in quei palazzi. */
function apreUtente(?array $condominiIds, string $nome, string $ruolo = 'utente', array $permessiInPiu = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole($ruolo);
    if ($permessiInPiu !== []) {
        $user->givePermissionTo($permessiInPiu);
    }
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($condominiIds !== null) {
        $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
        $anagrafica->condomini()->attach($condominiIds);
    }

    return $user->fresh();
}

/**
 * Un ruolo su misura che scrive e vede una sola entità: la ragione «non risulti fra i condòmini» deve
 * dipendere dal permesso di vedere **quell'entità**, non da quello dell'altra.
 */
function apreRuoloSolo(string $nome, array $permessi): string
{
    RuoloSpatie::findOrCreate($nome, 'web')->syncPermissions($permessi);

    return $nome;
}

function apreSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => "Muffa nella camera «grande»", 'description' => "Riguarda l'appartamento\ndel terzo piano",
        'priority' => 'alta', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

function apreComunicazione(array $condomini, User $autore, array $stato = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Turni delle pulizie', 'description' => 'Il nuovo calendario', 'priority' => 'media',
        'created_by' => $autore->id, 'is_featured' => false, 'is_private' => false, 'is_published' => true,
        'is_approved' => true, 'can_comment' => false,
    ], $stato));
    $c->condomini()->attach(array_map(fn (Condominio $x) => $x->id, $condomini));

    return $c->fresh();
}

function apreStacca(User $user, Condominio $condominio): User
{
    $user->anagrafica->condomini()->detach($condominio->id);

    return $user->fresh();
}

/** La mail `$classe` mandata a `$utente`, composta adesso: il pulsante (o `null`) e il testo delle righe. */
function apreMail(User $utente, string $classe): array
{
    $mail = null;
    Notification::assertSentTo($utente, $classe, function ($notifica) use ($utente, &$mail) {
        $mail = $notifica->toMail($utente);

        return true;
    });

    return [
        'pulsante' => $mail->actionUrl ? (string) parse_url($mail->actionUrl, PHP_URL_PATH) : null,
        'testo'    => implode("\n", array_merge([$mail->subject], $mail->introLines, $mail->outroLines)),
        'righe'    => $mail->introLines,
    ];
}

// ─── «Rendi pubblica» ───────────────────────────────────────────────────────────────────────

test('Coda 304 — «Rendi pubblica» sulla privata di un autore uscito dal palazzo: la mail parte senza il pulsante e dice perché', function () {
    // La regola: se nel momento in cui la mail si compone l'autore non può aprire l'oggetto, la mail
    // parte senza il pulsante e dice perché. Chi vede che cosa non cambia: tolta la privacy, l'autore
    // fuori dal palazzo non la apre più.
    // Due autori: il condòmino di serie, e un ruolo su misura che scrive e vede solo le segnalazioni.
    $soloSegnalazioni = apreRuoloSolo('Solo segnalazioni', [Permission::CREATE_SEGNALAZIONI->value, Permission::VIEW_SEGNALAZIONI->value]);
    foreach ([['Mario Rossi', 'utente'], ['Gino Rossi', $soloSegnalazioni]] as [$nome, $ruolo]) {
        Notification::fake();
        $autore = apreUtente([$this->aurora->id, $this->borea->id], $nome, $ruolo);
        $s = apreSegnalazione($this->aurora, $autore, ['is_private' => true], [$autore]);
        $autore = apreStacca($autore, $this->aurora);
        $this->actingAs($autore)->get(route('user.segnalazioni.show', $s))->assertOk();

        $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect()->assertSessionHasNoErrors();

        $mail = apreMail($autore, SegnalazioneResaPubblicaNotification::class);
        expect($mail['pulsante'])->toBeNull($nome)
            ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.');
        $this->actingAs($autore)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    }
});

test('Coda 304 — «Rendi pubblica» sulla privata di un autore ancora nel palazzo: la mail porta il pulsante alla scheda, che lui apre', function () {
    $mario = apreUtente([$this->aurora->id], 'Mario Rossi');
    $s = apreSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    $mail = apreMail($mario, SegnalazioneResaPubblicaNotification::class);
    expect($mail['pulsante'])->toBe('/user/segnalazioni/'.$s->id)
        ->and($mail['testo'])->not->toContain('nel portale');
    $this->actingAs($mario)->get($mail['pulsante'])->assertOk();
});

// ─── «Approvata» ────────────────────────────────────────────────────────────────────────────

test('Coda 263 — l\'approvazione della segnalazione di un autore uscito dal palazzo: la mail parte senza il pulsante e dice perché', function () {
    // La regola è la stessa di «Rendi pubblica».
    $soloSegnalazioni = apreRuoloSolo('Solo segnalazioni', [Permission::CREATE_SEGNALAZIONI->value, Permission::VIEW_SEGNALAZIONI->value]);
    foreach ([['Mario Rossi', 'utente'], ['Gino Rossi', $soloSegnalazioni]] as [$nome, $ruolo]) {
        Notification::fake();
        $autore = apreUtente([$this->aurora->id, $this->borea->id], $nome, $ruolo);
        $s = apreSegnalazione($this->aurora, $autore, ['is_published' => false, 'is_approved' => false]);
        $autore = apreStacca($autore, $this->aurora);

        $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

        $mail = apreMail($autore, ApprovedSegnalazioneNotification::class);
        expect($mail['pulsante'])->toBeNull($nome)
            ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.');
        $this->actingAs($autore)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    }
});

test('Coda 263 — l\'approvazione della segnalazione di un autore nel palazzo, o collegata a lui fuori dal palazzo: la mail porta il pulsante, e la scheda si apre', function () {
    $mario = apreUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $nelPalazzo = apreSegnalazione($this->borea, $mario, ['is_published' => false, 'is_approved' => false]);
    $collegata = apreSegnalazione($this->aurora, $mario, ['is_published' => false, 'is_approved' => false], [$mario]);
    $mario = apreStacca($mario, $this->aurora);

    foreach ([$nelPalazzo, $collegata] as $s) {
        Notification::fake();
        $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

        $mail = apreMail($mario, ApprovedSegnalazioneNotification::class);
        expect($mail['pulsante'])->toBe('/user/segnalazioni/'.$s->id)
            ->and($mail['testo'])->not->toContain('nel portale');
        $this->actingAs($mario)->get($mail['pulsante'])->assertOk();
    }
});

test('Coda 304 — «Rendi pubblica» sulla privata di chi non ha il permesso di vedere le segnalazioni: la mail parte senza il pulsante e dice il permesso, non il palazzo', function () {
    // Un ruolo su misura che apre segnalazioni ma non le vede: la ragione del rifiuto è il permesso.
    // L'avviso «approvata» a lui non parte (le preferenze chiedono «Visualizza segnalazioni»,
    // `config/notifications.php`); quello «resa pubblica» non passa dalle preferenze, e parte.
    $sara = apreUtente([$this->aurora->id], 'Sara Neri', apreRuoloSolo('Solo apertura', [Permission::CREATE_SEGNALAZIONI->value]));
    $s = apreSegnalazione($this->aurora, $sara, ['is_private' => true], [$sara]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    $mail = apreMail($sara, SegnalazioneResaPubblicaNotification::class);
    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: il tuo account non ha il permesso di aprirla.')
        ->not->toContain('non risulti fra i condòmini');
    $this->actingAs($sara)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — l\'approvazione della segnalazione di un collaboratore: la mail porta il pulsante al pannello', function () {
    // Chi ha il pannello apre ogni segnalazione: il pulsante resta, verso la sua scheda.
    $collaboratore = apreUtente(null, 'Giulia Ferri', Role::COLLABORATORE->value);
    $s = apreSegnalazione($this->aurora, $collaboratore, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

    $mail = apreMail($collaboratore, ApprovedSegnalazioneNotification::class);
    expect($mail['pulsante'])->toBe('/admin/segnalazioni/'.$s->id);
    $this->actingAs($collaboratore)->get($mail['pulsante'])->assertOk();
});

test('Coda 198 — l\'approvazione della comunicazione di un autore uscito dai suoi palazzi: la mail parte senza il pulsante e dice perché', function () {
    // Due autori: il condòmino di serie con il permesso di scrivere comunicazioni, e un ruolo su misura
    // che scrive e vede solo le comunicazioni.
    $soloComunicazioni = apreRuoloSolo('Solo comunicazioni', [Permission::CREATE_COMUNICAZIONI->value, Permission::VIEW_COMUNICAZIONI->value]);
    foreach ([['Carla Bianchi', 'utente'], ['Rita Bianchi', $soloComunicazioni]] as [$nome, $ruolo]) {
        Notification::fake();
        $autore = apreUtente([$this->aurora->id, $this->borea->id], $nome, $ruolo, $ruolo === 'utente' ? [Permission::CREATE_COMUNICAZIONI->value] : []);
        $c = apreComunicazione([$this->aurora, $this->borea], $autore, ['is_published' => false, 'is_approved' => false]);
        $autore->anagrafica->condomini()->detach();
        $autore = $autore->fresh();

        $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

        $mail = apreMail($autore, ApprovedComunicazioneNotification::class);
        expect($mail['pulsante'])->toBeNull($nome)
            ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora, Condominio Borea.');
        $this->actingAs($autore)->get(route('user.comunicazioni.show', $c))->assertForbidden();
    }
});

test('Coda 198 — l\'approvazione della comunicazione di un autore ancora in uno dei suoi palazzi: la mail porta il pulsante, e la scheda si apre', function () {
    $carla = apreUtente([$this->aurora->id, $this->borea->id], 'Carla Bianchi', permessiInPiu: [Permission::CREATE_COMUNICAZIONI->value]);
    $c = apreComunicazione([$this->aurora, $this->borea], $carla, ['is_published' => false, 'is_approved' => false]);
    $carla = apreStacca($carla, $this->aurora);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

    $mail = apreMail($carla, ApprovedComunicazioneNotification::class);
    expect($mail['pulsante'])->toBe('/user/comunicazioni/'.$c->id);
    $this->actingAs($carla)->get($mail['pulsante'])->assertOk();
});

// ─── La riga 1 della mail «non è più privata» ──────────────────────────────────────────────

test('Coda 304 — nella mail «non è più privata» la prima riga non dice «ha reso pubblica»; in quella «ora è pubblica» sì', function () {
    // La prima riga dice ciò che dice l'oggetto: quando non è pubblicata e approvata, «non è più
    // privata» (`line_1_pending`), non «ha reso pubblica».
    $mario = apreUtente([$this->aurora->id], 'Mario Rossi');
    $inModerazione = apreSegnalazione($this->aurora, $mario, ['is_private' => true, 'is_published' => false, 'is_approved' => false], [$mario]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $inModerazione))->assertRedirect();
    $mail = apreMail($mario, SegnalazioneResaPubblicaNotification::class);
    expect($mail['righe'][0])->toBe('Studio Bianchi ha deciso che la tua segnalazione non è più privata.')
        ->and($mail['testo'])->not->toContain('reso pubblica');

    Notification::fake();
    $inBozza = apreSegnalazione($this->aurora, $mario, ['is_private' => true, 'is_published' => false, 'is_approved' => true], [$mario]);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $inBozza))->assertRedirect();
    expect(apreMail($mario, SegnalazioneResaPubblicaNotification::class)['righe'][0])->toBe('Studio Bianchi ha deciso che la tua segnalazione non è più privata.');

    Notification::fake();
    $visibile = apreSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario]);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $visibile))->assertRedirect();
    expect(apreMail($mario, SegnalazioneResaPubblicaNotification::class)['righe'][0])->toBe('Studio Bianchi ha reso pubblica la tua segnalazione.');
});

// ─── La conferma di «Rendi pubblica» nella pagina dell'amministratore ───────────────────────

test('Coda 304 — la scheda dell\'amministratore sa, prima del comando, se l\'autore perderà la segnalazione: è la scheda che l\'autore apre dopo', function () {
    // Il fatto che la pagina riceve prima di «Rendi pubblica» si confronta con ciò che succede dopo:
    // l'autore la apriva e dopo il comando non la apre più. Gli autori: nel palazzo; uscito; uscito ma
    // ancora collegato (il comando toglie i collegamenti); un ruolo su misura che vede le proprie; un
    // collaboratore; uno senza anagrafica; un ruolo su misura che non vede le segnalazioni.
    apreRuoloSolo('Proprie segnalazioni', [Permission::CREATE_SEGNALAZIONI->value, Permission::VIEW_OWN_SEGNALAZIONI->value]);
    apreRuoloSolo('Solo apertura', [Permission::CREATE_SEGNALAZIONI->value]);

    // [autore, collegato a lei, esce dall'Aurora, l'atteso]
    $casi = [
        'nel palazzo'             => [apreUtente([$this->aurora->id], 'Anna Neri'), true, false, false],
        'uscito dal palazzo'      => [apreUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi'), false, true, true],
        'uscito, ma collegato'    => [apreUtente([$this->aurora->id, $this->borea->id], 'Luigi Verdi'), true, true, true],
        'vede le proprie, uscito' => [apreUtente([$this->aurora->id, $this->borea->id], 'Sara Neri', 'Proprie segnalazioni'), true, true, false],
        'collaboratore'           => [apreUtente(null, 'Giulia Ferri', Role::COLLABORATORE->value), false, false, false],
        'senza anagrafica'        => [apreUtente(null, 'Paolo Galli'), false, false, true],
        // Non la apre né prima né dopo (non ha il permesso di vedere): non perde niente.
        'senza permesso, uscito'  => [apreUtente([$this->aurora->id, $this->borea->id], 'Elena Fabbri', 'Solo apertura'), true, true, false],
    ];

    foreach ($casi as $caso => [$autore, $collegato, $esce, $atteso]) {
        $s = apreSegnalazione($this->aurora, $autore, ['is_private' => true], $collegato ? [$autore] : []);
        if ($esce) {
            $autore = apreStacca($autore, $this->aurora);
        }

        $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props'];
        $prima = Gate::forUser($autore)->allows('show', $s->fresh());

        $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();
        $dopo = Gate::forUser($autore->fresh())->allows('show', $s->fresh());

        expect($props['rendi_pubblica']['autore_perde_la_scheda'] ?? null)->toBe($atteso, "caso: {$caso}")
            ->and($atteso)->toBe($prima && ! $dopo, "caso: {$caso} (la regola)");
    }
});

test('Coda 304 — su una segnalazione non privata la scheda dell\'amministratore non annuncia niente all\'autore', function () {
    // Il comando non c'è (non è privata): il fatto è falso anche se l'autore è fuori dal palazzo, sia
    // quando oggi non la apre, sia quando la apre perché è collegata a lui.
    $mario = apreUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $nonCollegata = apreSegnalazione($this->aurora, $mario);
    $collegata = apreSegnalazione($this->aurora, $mario, [], [$mario]);
    $mario = apreStacca($mario, $this->aurora);
    $this->actingAs($mario)->get(route('user.segnalazioni.show', $collegata))->assertOk();

    foreach ([$nonCollegata, $collegata] as $s) {
        $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props'];
        expect($props['rendi_pubblica']['autore_perde_la_scheda'])->toBeFalse();
    }
});

// ─── L'autore il cui account non ha più un'anagrafica ──────────────────────────────────────

test('decisione 134 — l\'autore il cui account non ha più un\'anagrafica: «approvata», «il tuo commento è stato approvato» e «resa pubblica» partono senza il pulsante e dicono perché', function () {
    // Il portale manda chi non ha un'anagrafica (e non ha il pannello) a completare i suoi dati, prima
    // ancora della scheda: il pulsante non porterebbe alla segnalazione. La policy, da sola, gliela apre
    // (una privata resta del suo autore), quindi la ragione viene prima della domanda alla scheda.
    $moderato = apreRuoloSolo('Condòmino moderato', [
        Permission::VIEW_SEGNALAZIONI->value, Permission::CREATE_SEGNALAZIONI->value,
        Permission::COMMENT_SEGNALAZIONI->value, Permission::VIEW_COMMENTS_SEGNALAZIONI->value,
    ]);
    $mario = apreUtente([$this->aurora->id], 'Mario Rossi', $moderato);
    $s = apreSegnalazione($this->aurora, $mario, ['is_private' => true, 'is_published' => false, 'is_approved' => false], [$mario]);
    $this->actingAs($mario)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => "Peggiora: l'acqua arriva al «box»"])->assertRedirect();
    $commento = \App\Models\Commento::query()->where('user_id', $mario->id)->latest('id')->firstOrFail();
    expect($commento->eInAttesa())->toBeTrue();

    // L'amministratore elimina l'anagrafica di Mario; l'account resta.
    $mario->anagrafica->condomini()->detach();
    $mario->anagrafica->delete();
    $mario = $mario->fresh();
    expect($mario->anagrafica)->toBeNull();

    $ragione = 'Non la vedi nel portale: il tuo account non è collegato a un\'anagrafica. Per usare il portale accedi e compila i tuoi dati.';

    // Ogni mail si compone subito dopo il suo comando, com'è quando parte: la segnalazione è ancora
    // privata per le prime due, e la policy gliela apre.
    $passi = [
        ApprovedSegnalazioneNotification::class => fn () => $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s)),
        \App\Notifications\Commenti\CommentoApprovatoNotification::class => fn () => $this->actingAs($this->admin)->post(route('admin.commenti.approva', $commento)),
        SegnalazioneResaPubblicaNotification::class => fn () => $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s)),
    ];
    foreach ($passi as $classe => $comando) {
        $comando()->assertRedirect();
        $mail = apreMail($mario, $classe);
        expect($mail['pulsante'])->toBeNull($classe)
            ->and($mail['testo'])->toContain($ragione)
            ->not->toContain('non risulti fra i condòmini');

        // La ragione è vera: dal portale la scheda non si apre, si va a completare i dati.
        $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertRedirect(route('user.anagrafiche.create'));
    }
});

test('decisione 134 — l\'approvazione della comunicazione di un autore senza più un\'anagrafica: la mail parte senza il pulsante e dice perché', function () {
    $carla = apreUtente([$this->aurora->id], 'Carla Bianchi', permessiInPiu: [Permission::CREATE_COMUNICAZIONI->value]);
    $c = apreComunicazione([$this->aurora], $carla, ['is_published' => false, 'is_approved' => false]);
    $carla->anagrafica->condomini()->detach();
    $carla->anagrafica->delete();
    $carla = $carla->fresh();

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

    $mail = apreMail($carla, ApprovedComunicazioneNotification::class);
    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: il tuo account non è collegato a un\'anagrafica. Per usare il portale accedi e compila i tuoi dati.');
    $this->actingAs($carla)->get(route('user.comunicazioni.show', $c))->assertRedirect(route('user.anagrafiche.create'));
});

test('decisione 134 — l\'autore senza anagrafica che ha il pannello riceve il pulsante del pannello, che apre la scheda', function () {
    // Un ruolo su misura con l'accesso al pannello: il pulsante porta alla scheda del pannello, che non
    // chiede un'anagrafica. La ragione nuova vale solo per chi il pulsante manderebbe al portale.
    $segreteria = apreRuoloSolo('Segreteria', [
        Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_SEGNALAZIONI->value, Permission::CREATE_SEGNALAZIONI->value,
    ]);
    $gina = apreUtente(null, 'Gina Ferri', $segreteria);
    $s = apreSegnalazione($this->aurora, $gina, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

    $mail = apreMail($gina, ApprovedSegnalazioneNotification::class);
    expect($mail['pulsante'])->toBe('/admin/segnalazioni/'.$s->id)
        ->and($mail['testo'])->not->toContain('nel portale');
    $this->actingAs($gina)->get($mail['pulsante'])->assertOk();
});

test('decisione 134 — la ragione dell\'account senza anagrafica esiste in ogni lingua', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(trans('notifications.author_cannot_open.no_anagrafica', [], $lingua))
            ->not->toBe('notifications.author_cannot_open.no_anagrafica', "{$lingua}: manca la frase");
    }
});
