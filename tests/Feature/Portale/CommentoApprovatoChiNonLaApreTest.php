<?php

/**
 * # «Il tuo commento è stato approvato» a chi la segnalazione non la apre più (Code 263 e 304)
 *
 * L'avviso va all'autore del commento, che non sempre è l'autore della segnalazione. Fra il commento
 * e l'approvazione passano giorni: nel frattempo chi ha commentato può uscire dal palazzo, la
 * segnalazione può essere indirizzata ad altre persone, o tornare in bozza. Il pulsante portava sempre
 * alla scheda, che allora risponde 403.
 *
 * La regola è quella degli altri avvisi a chi ha scritto (`PulsanteSoloSeLaApre`): se quando la mail
 * si compone il destinatario non apre la scheda (la `show` della policy), la mail parte senza il
 * pulsante e dice perché, con una ragione vera per lui:
 * - non ha il permesso di vedere le segnalazioni;
 * - non è l'autore e la segnalazione in questo momento non è pubblicata e approvata (una bozza, o una
 *   segnalazione pubblicata e in attesa di approvazione);
 * - non è l'autore, e la segnalazione è privata o indirizzata a persone fra cui lui non c'è più;
 * - altrimenti non sta nel palazzo della segnalazione.
 * La regola di chi vede che cosa non cambia: cambia solo ciò che la mail dice.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\CommentoApprovatoNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
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

    // Un condòmino i cui commenti passano dalla moderazione: il ruolo di serie senza «Pubblica commenti».
    RuoloSpatie::findOrCreate('Condòmino moderato', 'web')->syncPermissions([
        Permission::VIEW_SEGNALAZIONI->value,
        Permission::CREATE_SEGNALAZIONI->value,
        Permission::COMMENT_SEGNALAZIONI->value,
        Permission::VIEW_COMMENTS_SEGNALAZIONI->value,
    ]);
});

/** Un condòmino «moderato» con le preferenze accese e, se `$condominiIds` non è `null`, l'anagrafica in quei palazzi. */
function approvatoUtente(?array $condominiIds, string $nome, string $ruolo = 'Condòmino moderato'): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole($ruolo);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($condominiIds !== null) {
        Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominiIds);
    }

    return $user->fresh();
}

function approvatoSegnalazione(Condominio $condominio, User $autore, array $stato = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => "L'ascensore «B» è fermo", 'description' => "Dal terzo piano\nnon scende",
        'priority' => 'alta', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

/** Il commento di `$chi`, mandato dalla rotta del portale come la scheda: resta in moderazione. */
function approvatoCommenta(User $chi, Segnalazione $s): Commento
{
    test()->actingAs($chi)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => "Peggiora: l'acqua arriva al «box»"])
        ->assertRedirect();
    $commento = Commento::query()->where('user_id', $chi->id)->latest('id')->firstOrFail();
    expect($commento->eInAttesa())->toBeTrue('il commento deve aspettare la moderazione');

    return $commento;
}

/** L'amministratore approva dalla rotta del pannello; la mail mandata a `$chi`, composta adesso. */
function approvatoMail(User $admin, Commento $commento, User $chi): array
{
    test()->actingAs($admin)->post(route('admin.commenti.approva', $commento))->assertRedirect();

    $mail = null;
    Notification::assertSentTo($chi, CommentoApprovatoNotification::class, function ($notifica) use ($chi, &$mail) {
        $mail = $notifica->toMail($chi->fresh());

        return true;
    });

    return [
        'pulsante' => $mail->actionUrl ? (string) parse_url($mail->actionUrl, PHP_URL_PATH) : null,
        'testo'    => implode("\n", array_merge([$mail->subject], $mail->introLines, $mail->outroLines)),
    ];
}

test('Coda 263 — il commento approvato dell\'autore uscito dal palazzo: la mail parte senza il pulsante e dice il palazzo', function () {
    // Mario apre la segnalazione nell'Aurora, la commenta, poi esce dall'Aurora (resta nella Borea).
    $mario = approvatoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($mario, $s);
    $mario->anagrafica->condomini()->detach($this->aurora->id);

    $mail = approvatoMail($this->admin, $commento, $mario);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.')
        ->not->toContain('non è più indirizzata a te');
    $this->actingAs($mario->fresh())->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — il commento approvato di un vicino uscito dal palazzo: senza pulsante, la ragione è il palazzo', function () {
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id, $this->borea->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($luigi, $s);
    $luigi->anagrafica->condomini()->detach($this->aurora->id);

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.');
    $this->actingAs($luigi->fresh())->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — il commento approvato dell\'autore uscito dal palazzo, su una segnalazione indirizzata ad altre persone: la ragione è il palazzo, non l\'indirizzo', function () {
    // L'autore di una non privata la apre finché è nel palazzo, anche se è indirizzata ad altri: per
    // lui la ragione vera è il palazzo.
    $mario = approvatoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $anna  = approvatoUtente([$this->aurora->id], 'Anna Neri');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($mario, $s);
    $s->anagrafiche()->attach($anna->anagrafica->id);
    $mario->anagrafica->condomini()->detach($this->aurora->id);

    $mail = approvatoMail($this->admin, $commento, $mario);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.')
        ->not->toContain('non è più indirizzata a te');
    $this->actingAs($mario->fresh())->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — il commento approvato di chi non è più collegato a una privata, ancora nel palazzo: senza pulsante, la ragione non è il palazzo', function () {
    // La privata di Mario indirizzata anche a Luigi; Luigi commenta; l'amministratore la indirizza a
    // Mario soltanto (dal modulo, come la pagina di modifica). Luigi è ancora nell'Aurora: «non risulti
    // fra i condòmini» sarebbe falso.
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario, $luigi]);
    $commento = approvatoCommenta($luigi, $s);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => [$mario->anagrafica->id], 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non è più indirizzata a te.')
        ->not->toContain('non risulti fra i condòmini');
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — il commento approvato di un vicino quando la segnalazione è stata indirizzata ad altre persone: senza pulsante, non è più indirizzata a lui', function () {
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $anna  = approvatoUtente([$this->aurora->id], 'Anna Neri');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($luigi, $s);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => [$anna->anagrafica->id], 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non è più indirizzata a te.');
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 198 — il commento approvato di un vicino quando la segnalazione è tornata in bozza: senza pulsante, dice che non è pubblicata', function () {
    // Né il palazzo né l'indirizzo: Luigi è nell'Aurora e la segnalazione è di tutto il palazzo, ma
    // non è pubblicata, e una bozza la apre solo il suo autore.
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($luigi, $s);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => false,
        'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $mail = approvatoMail($this->admin, $commento, $luigi);

    // La frase dice le due condizioni della regola, «pubblicata e approvata», come le guide: con la sola
    // prima metà era falsa su una segnalazione pubblicata e in attesa di approvazione (il test qui sotto).
    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: in questo momento non è pubblicata e approvata.')
        ->not->toContain('non risulti fra i condòmini')
        ->not->toContain('non è più indirizzata a te');
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 198 — il commento approvato di un vicino quando la segnalazione è pubblicata ma in attesa di approvazione: la ragione non dice che non è pubblicata', function () {
    // L'interruttore ritira l'approvazione (spegne pubblicata e approvata), poi il modulo del pannello la
    // rimette «pubblicata»: è pubblicata, e ancora in attesa di approvazione. Luigi non la apre.
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($luigi, $s);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();
    $s = $s->fresh();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();
    expect((bool) $s->fresh()->is_published)->toBeTrue()
        ->and((bool) $s->fresh()->is_approved)->toBeFalse();

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: in questo momento non è pubblicata e approvata.');
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — il commento approvato di chi è ancora collegato a una privata tornata in moderazione: la ragione è lo stato, non l\'indirizzo', function () {
    // Le due ragioni di chi non è l'autore valgono insieme su una privata in moderazione; per Luigi, ancora
    // collegato e nel palazzo, è vera solo la prima: la segnalazione gli è ancora indirizzata.
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario, $luigi]);
    $commento = approvatoCommenta($luigi, $s);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: in questo momento non è pubblicata e approvata.')
        ->not->toContain('non è più indirizzata a te');
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 198 — il commento approvato dell\'autore sulla propria segnalazione tornata in bozza: la apre ancora, e il pulsante resta', function () {
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($mario, $s);
    $s->update(['is_published' => false]);

    $mail = approvatoMail($this->admin, $commento, $mario);

    expect($mail['pulsante'])->toBe('/user/segnalazioni/'.$s->id)
        ->and($mail['testo'])->not->toContain('nel portale');
    $this->actingAs($mario)->get($mail['pulsante'])->assertOk();
});

test('Coda 263 — il commento approvato dell\'autore uscito dal palazzo, sulla propria tornata in bozza: la ragione è il palazzo, non lo stato', function () {
    // L'autore apre la propria anche in bozza: lo stato non lo ferma, il palazzo sì.
    $mario = approvatoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($mario, $s);
    $s->update(['is_published' => false]);
    $mario->anagrafica->condomini()->detach($this->aurora->id);

    $mail = approvatoMail($this->admin, $commento, $mario);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi più nel portale: non risulti fra i condòmini di Condominio Aurora.')
        ->not->toContain('non è pubblicata');
    $this->actingAs($mario->fresh())->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 263 — il commento approvato di chi apre ancora la segnalazione: la mail porta il pulsante, e la scheda si apre', function () {
    // Quattro commentatori che la aprono: il vicino nel palazzo; l'autore di una privata uscito dal
    // palazzo (una privata resta sempre del suo autore); il collegato a una non privata uscito dal
    // palazzo; il collaboratore del pannello.
    $mario = approvatoUtente([$this->aurora->id, $this->borea->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $anna  = approvatoUtente([$this->aurora->id, $this->borea->id], 'Anna Neri');
    $giulia = approvatoUtente(null, 'Giulia Ferri', Role::COLLABORATORE->value);

    $pubblica = approvatoSegnalazione($this->aurora, $mario);
    $privata  = approvatoSegnalazione($this->aurora, $mario, ['is_private' => true], [$mario]);
    $collegata = approvatoSegnalazione($this->aurora, $mario, [], [$anna]);

    $casi = [
        'il vicino nel palazzo'          => [$luigi, $pubblica, '/user/segnalazioni/'.$pubblica->id],
        'l\'autore della privata, uscito' => [$mario, $privata, '/user/segnalazioni/'.$privata->id],
        'la collegata, uscita'           => [$anna, $collegata, '/user/segnalazioni/'.$collegata->id],
    ];
    $commenti = [];
    foreach ($casi as $caso => [$chi, $s]) {
        $commenti[$caso] = approvatoCommenta($chi, $s);
    }
    $mario->anagrafica->condomini()->detach($this->aurora->id);
    $anna->anagrafica->condomini()->detach($this->aurora->id);

    foreach ($casi as $caso => [$chi, $s, $atteso]) {
        Notification::fake();
        $mail = approvatoMail($this->admin, $commenti[$caso], $chi);

        expect($mail['pulsante'])->toBe($atteso, $caso)
            ->and($mail['testo'])->not->toContain('nel portale');
        $this->actingAs($chi->fresh())->get($mail['pulsante'].'')->assertOk();
    }

    // Il collaboratore commenta dal pannello: il commento è subito pubblico, lo si rimette in attesa.
    $this->actingAs($giulia)->post(route('admin.segnalazioni.commenti.store', $pubblica), ['corpo' => 'Mando il tecnico'])->assertRedirect();
    $delPannello = Commento::query()->where('user_id', $giulia->id)->latest('id')->firstOrFail();
    $delPannello->update(['stato' => 'in_attesa']);
    Notification::fake();
    $mail = approvatoMail($this->admin, $delPannello, $giulia);
    expect($mail['pulsante'])->toBe('/admin/segnalazioni/'.$pubblica->id);
    $this->actingAs($giulia)->get($mail['pulsante'])->assertOk();
});

test('Coda 263 — il commento approvato di chi non ha più il permesso di vedere le segnalazioni: senza pulsante, la ragione è il permesso', function () {
    $mario = approvatoUtente([$this->aurora->id], 'Mario Rossi');
    $luigi = approvatoUtente([$this->aurora->id], 'Luigi Verdi');
    $s = approvatoSegnalazione($this->aurora, $mario);
    $commento = approvatoCommenta($luigi, $s);
    RuoloSpatie::findOrCreate('Solo commenti', 'web')->syncPermissions([Permission::COMMENT_SEGNALAZIONI->value]);
    $luigi->syncRoles(['Solo commenti']);

    $mail = approvatoMail($this->admin, $commento, $luigi);

    expect($mail['pulsante'])->toBeNull()
        ->and($mail['testo'])->toContain('Non la vedi nel portale: il tuo account non ha il permesso di aprirla.');
    $this->actingAs($luigi->fresh())->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — le ragioni nuove della mail senza pulsante esistono in ogni lingua', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        foreach (['not_addressed', 'not_published'] as $ragione) {
            $chiave = "notifications.author_cannot_open.{$ragione}";
            expect(trans($chiave, [], $lingua))->not->toBe($chiave, "{$lingua}: manca {$chiave}");
        }
    }
});
