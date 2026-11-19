<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # L'avviso «Commento eliminato» all'autore, e «Commento nascosto» con il testo nuovo (decisioni 478 (2) e 483 (3) e (4))
 *
 * Quando un altro elimina un commento, l'autore riceve un'email se ha acceso la preferenza «Commento eliminato» e ha ancora il permesso
 * di commentare quel tipo; mai se l'autore elimina il suo; dalla pagina «Commenti», in blocco, un'email per commento; anche per un
 * commento già nascosto. La notifica va in coda e porta con sé il testo: non dipende dal commento né dalla scheda.
 *
 * Questi test non coprono: l'invio vero in coda (le notifiche sono simulate: si compone la mail dalla notifica ricevuta), l'aspetto
 * della mail nel programma di posta, la pagina delle preferenze a video (la guardia del menu è in un test di Inertia a parte).
 */

use App\Enums\NotificationType;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\CommentoEliminatoNotification;
use App\Notifications\Commenti\CommentoNascostoNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    config(['app.user_timezone' => 'Europe/Rome']);
    $this->palazzi = ccmComunicazione([$this->aurora], $this->admin);
    $this->segnalazione = ccmSegnalazione($this->aurora, $this->mario);
});

/** Il testo di una mail composta dalla notifica ricevuta, nella lingua italiana: oggetto, righe e pulsante. */
function aceComposta(object $notifica, User $destinatario): object
{
    $prima = app()->getLocale();
    try {
        app()->setLocale('it');

        return $notifica->toMail($destinatario);
    } finally {
        app()->setLocale($prima);
    }
}

/** La prima notifica della classe arrivata all'utente. */
function aceNotifica(User $utente, string $classe): ?object
{
    return Notification::sent($utente, $classe)->first();
}

/** Mette la data di un commento (UTC, come il database). */
function aceData(int $id, string $quando): void
{
    DB::table('commenti')->where('id', $id)->update(['created_at' => $quando]);
}

test('eliminare dalla scheda il commento pubblicato di un altro manda a lui l\'email: oggetto, riga con data e titolo, inizio del testo e pulsante, per le comunicazioni', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Questo è fuori tema', $this->aurora);
    // 18/11/2026 00:30 a Roma (23:30 del 17 in UTC): la data è quella del fuso dell'installazione.
    aceData($id, '2026-11-17 23:30:00');

    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    expect(Commento::find($id))->toBeNull();
    $notifica = aceNotifica($this->mario, CommentoEliminatoNotification::class);
    expect($notifica)->not->toBeNull();
    $mail = aceComposta($notifica, $this->mario);
    expect($mail->subject)->toBe('Un tuo commento è stato eliminato')
        ->and($mail->introLines[0])->toBe('Il tuo commento del 18/11/2026 sulla comunicazione "Lavori al tetto" è stato eliminato dall\'amministratore del condominio.')
        ->and($mail->introLines[1])->toBe('Il commento cominciava così: «Questo è fuori tema»')
        ->and($mail->actionText)->toBe('Visualizza comunicazione')
        ->and($mail->actionUrl)->toBe(route('user.comunicazioni.show', $this->palazzi) . '#commenti');
});

test('lo stesso per le segnalazioni, con «segnalazione» e il pulsante della scheda della segnalazione', function () {
    $id = ccmCommentoSalvato($this->segnalazione, $this->mario, 'Il fabbro non è ancora passato', $this->aurora);

    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    $mail = aceComposta(aceNotifica($this->mario, CommentoEliminatoNotification::class), $this->mario);
    expect($mail->subject)->toBe('Un tuo commento è stato eliminato')
        ->and($mail->introLines[0])->toContain('sulla segnalazione "Il cancello non chiude" è stato eliminato dall\'amministratore del condominio.')
        ->and($mail->actionText)->toBe('Visualizza segnalazione')
        ->and($mail->actionUrl)->toBe(route('user.segnalazioni.show', $this->segnalazione) . '#commenti');
});

test('un commento ancora in attesa eliminato manda «non è stato pubblicato»', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Aspetto il via libera', $this->aurora, null, 'in_attesa');

    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    $mail = aceComposta(aceNotifica($this->mario, CommentoEliminatoNotification::class), $this->mario);
    expect($mail->subject)->toBe('Un tuo commento non è stato pubblicato')
        ->and($mail->introLines[0])->toContain('non è stato approvato ed è stato eliminato dall\'amministratore del condominio.')
        ->and($mail->introLines[1])->toBe('Il commento cominciava così: «Aspetto il via libera»')
        ->and($mail->actionText)->toBe('Visualizza comunicazione');
});

test('l\'autore che elimina il suo commento non riceve niente', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Ci ho ripensato', $this->aurora);

    $this->actingAs($this->mario)->delete(route('user.commenti.destroy', $id))->assertRedirect();

    expect(Commento::find($id))->toBeNull()
        ->and(ccmDestinatari(CommentoEliminatoNotification::class))->toBe([]);
});

test('con la preferenza «Commento eliminato» spenta, o senza la riga della preferenza, non parte niente; la preferenza «Commento nascosto» non c\'entra', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Uno', $this->aurora);
    DB::table('notification_preferences')->where('user_id', $this->mario->id)->where('type', NotificationType::COMMUNICATION_COMMENT_REMOVED->value)->update(['enabled' => false]);
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    $id2 = ccmCommentoSalvato($this->segnalazione, $this->paolo, 'Due', $this->aurora);
    DB::table('notification_preferences')->where('user_id', $this->paolo->id)->where('type', NotificationType::COMMENT_REMOVED->value)->delete();
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id2))->assertRedirect();

    // Spenta solo «nascosto»: l'avviso dell'eliminazione parte lo stesso.
    $id3 = ccmCommentoSalvato($this->palazzi, $this->lucia, 'Tre', $this->borea);
    $this->palazzi->condomini()->attach($this->borea->id);
    DB::table('notification_preferences')->where('user_id', $this->lucia->id)->where('type', NotificationType::COMMUNICATION_COMMENT_HIDDEN->value)->update(['enabled' => false]);
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id3))->assertRedirect();

    expect(ccmDestinatari(CommentoEliminatoNotification::class))->toBe(ccmIds($this->lucia));
});

test('chi non ha più il permesso di commentare quel tipo non riceve l\'avviso', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Ero un condòmino', $this->aurora);
    $this->mario->syncRoles(ccmRuolo('solo_lettura', ['Visualizza commenti comunicazioni']));
    $this->mario->syncPermissions([]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    expect(Commento::find($id))->toBeNull()
        ->and(ccmDestinatari(CommentoEliminatoNotification::class))->toBe([]);
});

test('eliminare in blocco dalla pagina «Commenti» manda un\'email per commento, anche due allo stesso autore', function () {
    $a = ccmCommentoSalvato($this->palazzi, $this->mario, 'Primo di Mario', $this->aurora);
    $b = ccmCommentoSalvato($this->palazzi, $this->mario, 'Secondo di Mario', $this->aurora);
    $c = ccmCommentoSalvato($this->segnalazione, $this->paolo, 'Di Paolo', $this->aurora);

    $this->actingAs($this->admin)->post(route('admin.commenti.elimina-in-blocco'), ['ids' => [$a, $b, $c]])->assertRedirect();

    expect(Commento::whereIn('id', [$a, $b, $c])->count())->toBe(0)
        ->and(Notification::sent($this->mario, CommentoEliminatoNotification::class))->toHaveCount(2)
        ->and(Notification::sent($this->paolo, CommentoEliminatoNotification::class))->toHaveCount(1);
    $inizi = Notification::sent($this->mario, CommentoEliminatoNotification::class)->map(fn ($n) => $n->inizio)->sort()->values()->all();
    expect($inizi)->toBe(['Primo di Mario', 'Secondo di Mario']);
});

test('un commento nascosto e poi eliminato manda due email: «nascosto» e poi «eliminato»', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Fuori tema', $this->aurora);

    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $id))->assertRedirect();
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();

    expect(Notification::sent($this->mario, CommentoNascostoNotification::class))->toHaveCount(1)
        ->and(Notification::sent($this->mario, CommentoEliminatoNotification::class))->toHaveCount(1);
});

test('la notifica in coda non fallisce se il commento è eliminato e la scheda sparisce: l\'email parte senza il pulsante', function () {
    $id = ccmCommentoSalvato($this->segnalazione, $this->mario, 'Resta il testo', $this->aurora);
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $id))->assertRedirect();
    $inCoda = unserialize(serialize(aceNotifica($this->mario, CommentoEliminatoNotification::class)));

    // La segnalazione sparisce del tutto prima che la coda giri.
    DB::table('commenti')->where('commentable_type', $this->segnalazione->getMorphClass())->where('commentable_id', $this->segnalazione->id)->delete();
    DB::table('segnalazioni')->where('id', $this->segnalazione->id)->delete();

    expect($inCoda->shouldSend($this->mario, 'mail'))->toBeTrue();
    $mail = aceComposta($inCoda, $this->mario);
    expect($mail->subject)->toBe('Un tuo commento è stato eliminato')
        ->and($mail->introLines[0])->toContain('sulla segnalazione "Il cancello non chiude"')
        ->and($mail->introLines[1])->toBe('Il commento cominciava così: «Resta il testo»')
        ->and($mail->actionText)->toBeNull();
});

test('un commento senza autore (la relazione è vuota) si elimina senza avvisare nessuno e senza errori', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Senza autore', $this->aurora);
    $commento = Commento::findOrFail($id);
    $commento->setRelation('autore', null);

    app(\App\Services\CommentoService::class)->elimina($commento, $this->admin);

    expect(Commento::find($id))->toBeNull()
        ->and(ccmDestinatari(CommentoEliminatoNotification::class))->toBe([]);
});

test('l\'inizio del commento: testo come testo, circa 120 caratteri tagliati a una parola intera, con «…» se tagliato', function () {
    expect(CommentoEliminatoNotification::inizioDelTesto('Breve'))->toBe('Breve')
        ->and(CommentoEliminatoNotification::inizioDelTesto("Una   riga\ne  un'altra [link](http://x.invalid)"))->toBe("Una riga e un'altra link(http://x.invalid)");

    $lungo = str_repeat('parola ', 40);
    $inizio = CommentoEliminatoNotification::inizioDelTesto($lungo);
    expect(mb_strlen($inizio))->toBeLessThanOrEqual(121)
        ->and($inizio)->toEndWith('parola…')
        ->and($inizio)->not->toContain('parola…parola');

    // Un taglio che cade dentro una parola torna all'ultimo spazio: mai una parola a metà.
    $parole = implode(' ', array_fill(0, 11, 'abcdefghi')) . ' xxxxxxxxxxxxxxxxxxxx';
    $tagliato = CommentoEliminatoNotification::inizioDelTesto($parole);
    expect($tagliato)->not->toContain('xxx')->and($tagliato)->toEndWith('abcdefghi…');
});

test('l\'email del commento nascosto ha il testo nuovo: oggetto, riga con data e titolo e pulsante', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Fuori tema', $this->aurora);
    aceData($id, '2026-11-17 10:00:00');

    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $id))->assertRedirect();

    $notifica = aceNotifica($this->mario, CommentoNascostoNotification::class);
    $mail = aceComposta($notifica, $this->mario);
    expect($mail->subject)->toBe('Un tuo commento è stato nascosto')
        ->and($mail->introLines[0])->toBe('Il tuo commento del 17/11/2026 sulla comunicazione "Lavori al tetto" è stato nascosto dall\'amministratore del condominio: gli altri non lo vedono più.')
        ->and($mail->actionText)->toBe('Visualizza comunicazione')
        ->and($mail->actionUrl)->toBe(route('user.comunicazioni.show', $this->palazzi) . '#commenti');
    expect(mb_strtolower(implode(' ', [$mail->subject, ...$mail->introLines])))->not->toContain('rimosso');
});

test('la migrazione accende «Commento eliminato» solo a chi ha accesa «Commento nascosto», e rieseguita non duplica né cambia una scelta fatta', function () {
    $path = database_path('migrations/2026_11_18_180000_seed_preferenze_notifica_commento_eliminato.php');
    expect(file_exists($path))->toBeTrue();

    $acceso = User::factory()->create();
    $spento = User::factory()->create();
    $senzaRiga = User::factory()->create();
    DB::table('notification_preferences')->whereIn('user_id', [$acceso->id, $spento->id, $senzaRiga->id])->delete();
    foreach (['comment_deleted', 'communication_comment_deleted'] as $tipo) {
        DB::table('notification_preferences')->insert([
            ['user_id' => $acceso->id, 'type' => $tipo, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $spento->id, 'type' => $tipo, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    $righe = fn (User $u, string $tipo) => DB::table('notification_preferences')->where('user_id', $u->id)->where('type', $tipo)->pluck('enabled')->map(fn ($v) => (bool) $v)->all();

    $migrazione = require $path;
    $migrazione->up();
    $migrazione->up();
    foreach (['comment_removed', 'communication_comment_removed'] as $nuova) {
        expect($righe($acceso, $nuova))->toBe([true], "«{$nuova}» di chi aveva acceso «nascosto»")
            ->and($righe($spento, $nuova))->toBe([false], "«{$nuova}» di chi l'aveva spento")
            ->and($righe($senzaRiga, $nuova))->toBe([], "«{$nuova}» di chi non ha la riga");
    }

    // Una scelta già fatta sulla preferenza nuova non si tocca.
    DB::table('notification_preferences')->where('user_id', $spento->id)->where('type', 'comment_removed')->update(['enabled' => true]);
    $migrazione->up();
    expect($righe($spento, 'comment_removed'))->toBe([true]);
});

test('le etichette delle preferenze: «Commento nascosto» e «Commento eliminato», per segnalazioni e comunicazioni, nelle quattro lingue', function () {
    $it = [
        'comment_deleted' => 'Commento nascosto (segnalazioni)',
        'comment_removed' => 'Commento eliminato (segnalazioni)',
        'communication_comment_deleted' => 'Commento nascosto (comunicazioni)',
        'communication_comment_removed' => 'Commento eliminato (comunicazioni)',
    ];
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        $voci = (include base_path("lang/{$lingua}/settings.php"))['notification_types'];
        $etichette = [];
        foreach (array_keys($it) as $tipo) {
            $etichette[$tipo] = $voci[$tipo]['label'] ?? '';
            expect($voci[$tipo]['description'] ?? '')->not->toBe('', "Descrizione mancante in {$lingua}: {$tipo}");
        }
        expect(count(array_unique($etichette)))->toBe(4, "In {$lingua} due preferenze hanno la stessa etichetta")
            ->and(array_filter($etichette, fn ($e) => $e === ''))->toBe([]);
        if ($lingua === 'it') {
            expect($etichette)->toBe($it);
        }
    }
    // Le voci stanno in config, con lo stesso permesso di commentare di prima.
    $trova = fn (string $tipo) => collect(config('notifications.types'))->map(fn ($g) => $g[$tipo] ?? null)->filter()->first();
    expect($trova('comment_removed')['permission']->value)->toBe('Commenta segnalazioni')
        ->and($trova('communication_comment_removed')['permission']->value)->toBe('Commenta comunicazioni')
        ->and($trova('comment_deleted')['label'])->toBe('Commento nascosto (segnalazioni)');
});

test('i testi delle due email ci sono nel file di ogni lingua', function (string $lingua) {
    foreach (['hidden', 'deleted'] as $quale) {
        foreach (['ticket', 'communication'] as $tipo) {
            $campi = $quale === 'hidden'
                ? ['subject', 'line_1', 'action']
                : ['subject', 'subject_pending', 'line_1', 'line_1_pending', 'started', 'action'];
            foreach ($campi as $campo) {
                expect(Lang::hasForLocale("notifications.{$quale}_{$tipo}_comment.{$campo}", $lingua))->toBeTrue("manca in {$lingua}: {$quale}_{$tipo}_comment.{$campo}");
            }
        }
    }
    foreach (['ticket', 'communication'] as $tipo) {
        expect(Lang::hasForLocale("notifications.comment_entity.{$tipo}", $lingua))->toBeTrue("manca in {$lingua}: comment_entity.{$tipo}");
    }
})->with(['it', 'en', 'es', 'pt']);
