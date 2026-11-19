<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: il comando e gli avvisi (decisione 294, punti 1 e 2)
 *
 * - **Un comando suo**, `admin.segnalazioni.assegna`: assegna a un collaboratore, riassegna, toglie. Scrive a chi è
 *   assegnata, chi ha assegnato e quando.
 * - **Riceve chi ha il pannello e non è sospeso.**
 * - **Chi la riceve lo sa da un'email**, «Segnalazione assegnata a te»; chi non la segue più riceve «Segnalazione non
 *   più assegnata a te». Non parte niente a chi ha fatto il gesto.
 * - **Una preferenza sola**, `ticket_assigned`, per tutte e due.
 * - **Dal modulo di modifica e da «Crea segnalazione» si assegna con le stesse regole** (giro 3, decisione 303): le
 *   prove sono in `AssegnazioneCorrezioni3Test.php`.
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC: le 11:00 a Roma.
 */

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** L'email della classe data arrivata all'utente, o fallisce se non è partita. */
function asgMail(User $user, string $classe): MailMessage
{
    expect(class_exists($classe))->toBeTrue("Manca la notifica {$classe}");
    $notifica = null;
    Notification::assertSentTo($user, $classe, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });

    return $notifica->toMail($user);
}

/** Gli utenti a cui è partita una notifica della classe, se la classe esiste. */
function asgDestinatari(string $classe): array
{
    return class_exists($classe) ? ccmDestinatari($classe) : [];
}

// --- Il comando ----------------------------------------------------------------------------------------

test('assegnare scrive a chi, da chi e quando, e lo dice', function () {
    asgAssegna($this, $this->admin, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    $riga = asgRiga($this->s);
    expect((int) $riga->assigned_to)->toBe($this->elena->id)
        ->and((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe(ASG_ADESSO)
        ->and(asgMessaggi())->toContain('Segnalazione assegnata a Elena Studio.');
});

test('assegnare non cambia nient\'altro della segnalazione', function () {
    $prima = asgRiga($this->s);

    asgAssegna($this, $this->admin, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    $dopo = asgRiga($this->s);
    foreach (['subject', 'description', 'stato', 'priority', 'is_published', 'is_approved', 'is_private', 'created_by', 'condominio_id'] as $campo) {
        expect($dopo->{$campo})->toBe($prima->{$campo}, "È cambiato {$campo}");
    }
});

test('riassegnare cambia a chi, da chi e quando', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->elena, $s, $this->admin->id)->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assigned_to)->toBe($this->admin->id)
        ->and((int) $riga->assegnata_da)->toBe($this->elena->id)
        ->and($riga->assegnata_il)->toBe(ASG_ADESSO);
});

test('togliere l\'assegnazione svuota i tre campi, e lo dice', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->admin, $s, null)->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect($riga->assigned_to)->toBeNull()
        ->and($riga->assegnata_da)->toBeNull()
        ->and($riga->assegnata_il)->toBeNull()
        ->and(asgMessaggi())->toContain('Assegnazione tolta.');
});

test('assegnare di nuovo alla stessa persona non cambia niente e non avvisa nessuno', function () {
    $s = asgAssegnata($this, $this->collaboratrice, $this->admin);

    asgAssegna($this, $this->elena, $s, $this->collaboratrice->id)->assertSessionHasNoErrors();

    $riga = asgRiga($s);
    expect((int) $riga->assegnata_da)->toBe($this->admin->id)
        ->and($riga->assegnata_il)->toBe('2026-11-10 08:30:00')
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([])
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('il comando vuole il campo: senza, non fa niente', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.assegna', $s), [])->assertSessionHasErrors('assigned_to');

    expect((int) asgRiga($s)->assigned_to)->toBe($this->collaboratrice->id);
});

// --- Chi può ricevere ----------------------------------------------------------------------------------

test('non si assegna a chi non ha il pannello, a chi è sospeso, a chi non esiste', function (string $caso) {
    $sospesa = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    $sospesa->forceFill(['suspended_at' => now()])->save();
    $id = ['condomino' => $this->mario->id, 'sospesa' => $sospesa->id, 'inesistente' => 999999][$caso];

    $risposta = asgAssegna($this, $this->admin, $this->s, $id);

    $risposta->assertSessionHasErrors('assigned_to');
    expect(asgRiga($this->s)->assigned_to)->toBeNull()
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
    if ($caso !== 'inesistente') {
        expect(session('errors')->first('assigned_to'))->toBe(__('segnalazioni.assignment.not_assignable'));
    }
})->with(['condomino', 'sospesa', 'inesistente']);

test('si assegna anche a chi non può assegnare: basta il pannello', function () {
    $ruolo = ccmRuolo('operatore', ['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $operatore = ccmStudioSenzaAnagrafica('Otto Operatore', $ruolo);

    asgAssegna($this, $this->admin, $this->s, $operatore->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($operatore->id);
});

// --- Gli avvisi ----------------------------------------------------------------------------------------

test('chi riceve la segnalazione lo sa da un\'email; chi assegna no', function () {
    asgAssegna($this, $this->admin, $this->s, $this->elena->id);

    expect(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena))
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe([]);

    $mail = asgMail($this->elena, ASG_MAIL_ASSEGNATA);
    expect($mail->subject)->toBe('Segnalazione assegnata a te: Il cancello non chiude')
        ->and($mail->introLines)->toBe(['Studio Bianchi ti ha assegnato la segnalazione "Il cancello non chiude" (Condominio Aurora).'])
        ->and($mail->actionText)->toBe('Visualizza segnalazione')
        ->and($mail->actionUrl)->toBe(route('admin.segnalazioni.show', $this->s));
});

test('chi se la assegna da solo non riceve niente', function () {
    asgAssegna($this, $this->elena, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id)
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
});

test('riassegnando, chi la riceve e chi non la segue più lo sanno tutti e due', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->admin, $s, $this->elena->id);

    expect(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena))
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe(ccmIds($this->collaboratrice));

    $mail = asgMail($this->collaboratrice, ASG_MAIL_TOLTA);
    expect($mail->subject)->toBe('Segnalazione non più assegnata a te: Il cancello non chiude')
        ->and($mail->introLines)->toBe(['Studio Bianchi ha assegnato a Elena Studio la segnalazione "Il cancello non chiude", che seguivi tu.'])
        ->and($mail->actionText)->toBe('Visualizza segnalazione')
        ->and($mail->actionUrl)->toBe(route('admin.segnalazioni.show', $s));
});

test('togliendo l\'assegnazione, chi la seguiva lo sa', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->admin, $s, null);

    expect(asgDestinatari(ASG_MAIL_TOLTA))->toBe(ccmIds($this->collaboratrice))
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
    expect(asgMail($this->collaboratrice, ASG_MAIL_TOLTA)->introLines)
        ->toBe(['Studio Bianchi ha tolto l\'assegnazione della segnalazione "Il cancello non chiude", che seguivi tu.']);
});

test('chi lascia da solo una segnalazione, o la passa a un altro, non riceve «non più assegnata»', function () {
    $lasciata = asgAssegnata($this, $this->collaboratrice);
    $passata = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->collaboratrice, $lasciata, null);
    asgAssegna($this, $this->collaboratrice, $passata, $this->elena->id);

    expect(asgDestinatari(ASG_MAIL_TOLTA))->toBe([])
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->elena));
});

test('chi prende per sé una segnalazione di un altro lo fa sapere a chi la seguiva', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->elena, $s, $this->elena->id);

    expect(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([])
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe(ccmIds($this->collaboratrice));
    expect(asgMail($this->collaboratrice, ASG_MAIL_TOLTA)->introLines)
        ->toBe(['Elena Studio ha preso per sé la segnalazione "Il cancello non chiude", che seguivi tu.']);
});

/*
 * Fase 1-bis della beta.12, S3: chi seguiva la segnalazione e nel frattempo è stato sospeso, o non ha più il pannello, non riceve «non più assegnata a te».
 * La regola è quella di chi riceve (decisione 295 (4): riceve chi ha il pannello e non è sospeso; chi è sospeso non riceve più gli avvisi), la stessa
 * `puoRicevere()`. Con il codice di prima l'email partiva: il titolo della segnalazione e chi l'ha presa, a chi non entra più nel programma.
 * Che cosa non copre: gli altri avvisi del programma ai sospesi (i commenti, le comunicazioni), che sono di prima e fuori da questa regola.
 */
test('chi seguiva la segnalazione ed è stato sospeso, o non ha più il pannello, non riceve «non più assegnata»; chi la prende sì', function (string $caso) {
    $uscita = ccmStudioSenzaAnagrafica('Elena Uscita', 'collaboratore');
    $s = asgAssegnata($this, $uscita, null, ['subject' => 'Perdita dal bagno dell\'interno 5']);
    if ($caso === 'sospesa') {
        $uscita->forceFill(['suspended_at' => now()])->save();
    } else {
        $uscita->syncRoles([ccmRuolo('Senza pannello', ['Visualizza segnalazioni'])]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    asgAssegna($this, $this->admin, $s, $this->collaboratrice->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($s)->assigned_to)->toBe($this->collaboratrice->id)
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe(ccmIds($this->collaboratrice))
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe([]);
})->with(['sospesa', 'senza il pannello']);

/*
 * P6 della lente P (Fase 1-bis della beta.12): «Assegnata a» con una persona cancellata mentre la scheda era aperta rispondeva «L'elemento assigned to
 * selezionato non è valido.», sotto il menu. Che cosa non copre: la pagina che lo mostra (`AssegnazioneSegnalazione.vue`).
 */
test('una persona che non c\'è più, o un valore che non è una persona, dicono che cosa fare con le parole della pagina', function (mixed $aChi) {
    $this->actingAs($this->admin)->from(route('admin.segnalazioni.show', $this->s))->patch(route('admin.segnalazioni.assegna', $this->s), ['assigned_to' => $aChi])
        ->assertSessionHasErrors('assigned_to');

    expect(session('errors')->getBag('default')->first('assigned_to'))
        ->toBe('La persona scelta non c\'è più: ricarica la pagina e scegli di nuovo a chi assegnare la segnalazione.')
        ->and(asgRiga($this->s)->assigned_to)->toBeNull();
})->with(['cancellata' => 999999, 'non una persona' => 'Elena']);

test('un titolo o un nome scritti come un collegamento arrivano nelle due email come testo', function () {
    $s = asgAssegnata($this, $this->collaboratrice, null, ['subject' => 'Guasto [clicca qui](https://esempio.invalid/x)']);
    $this->admin->forceFill(['name' => 'Studio [Bianchi](https://esempio.invalid/y)'])->save();
    $this->elena->forceFill(['name' => 'Elena [Studio](https://esempio.invalid/z)'])->save();

    asgAssegna($this, $this->admin->fresh(), $s, $this->elena->id)->assertSessionHasNoErrors();

    foreach ([[$this->elena, ASG_MAIL_ASSEGNATA], [$this->collaboratrice, ASG_MAIL_TOLTA]] as [$chi, $classe]) {
        $mail = asgMail($chi, $classe);
        $testo = $mail->subject . "\n" . implode("\n", $mail->introLines);

        expect($testo)->not->toContain('](')
            ->and($testo)->not->toContain('[')
            ->and($testo)->toContain('clicca qui');
    }
});

test('se prima dell\'invio la segnalazione è passata a un altro, o non c\'è più, «assegnata a te» non parte', function () {
    asgAssegna($this, $this->admin, $this->s, $this->elena->id);
    $notifica = null;
    Notification::assertSentTo($this->elena, ASG_MAIL_ASSEGNATA, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });
    expect(method_exists($notifica, 'shouldSend'))->toBeTrue('La notifica non ha `shouldSend`')
        ->and($notifica->shouldSend($this->elena, 'mail'))->toBeTrue();

    Illuminate\Support\Facades\DB::table('segnalazioni')->where('id', $this->s->id)->update(['assigned_to' => $this->collaboratrice->id]);
    expect($notifica->shouldSend($this->elena, 'mail'))->toBeFalse();

    Illuminate\Support\Facades\DB::table('segnalazioni')->where('id', $this->s->id)->delete();
    expect($notifica->shouldSend($this->elena, 'mail'))->toBeFalse();
});

test('se prima dell\'invio la segnalazione è tornata a chi la seguiva, o non c\'è più, «non più assegnata» non parte', function () {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgAssegna($this, $this->admin, $s, $this->elena->id);
    $notifica = null;
    Notification::assertSentTo($this->collaboratrice, ASG_MAIL_TOLTA, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });
    expect(method_exists($notifica, 'shouldSend'))->toBeTrue('La notifica non ha `shouldSend`')
        ->and($notifica->shouldSend($this->collaboratrice, 'mail'))->toBeTrue();

    Illuminate\Support\Facades\DB::table('segnalazioni')->where('id', $s->id)->update(['assigned_to' => $this->collaboratrice->id]);
    expect($notifica->shouldSend($this->collaboratrice, 'mail'))->toBeFalse();

    Illuminate\Support\Facades\DB::table('segnalazioni')->where('id', $s->id)->delete();
    expect($notifica->shouldSend($this->collaboratrice, 'mail'))->toBeFalse();
});

// --- La preferenza -------------------------------------------------------------------------------------

test('con la preferenza spenta, o mai salvata, l\'email non parte; l\'assegnazione sì', function (?bool $stato) {
    asgPreferenza($this->elena, ASG_TIPO, $stato);

    asgAssegna($this, $this->admin, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id)
        ->and(class_exists(ASG_MAIL_ASSEGNATA))->toBeTrue('Manca la notifica dell\'assegnazione')
        ->and(asgDestinatari(ASG_MAIL_ASSEGNATA))->toBe([]);
})->with(['spenta' => [false], 'mai salvata' => [null]]);

test('la stessa preferenza vale per «non più assegnata»', function () {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgPreferenza($this->collaboratrice, ASG_TIPO, false);

    asgAssegna($this, $this->admin, $s, null);

    expect(class_exists(ASG_MAIL_TOLTA))->toBeTrue('Manca la notifica della fine dell\'assegnazione')
        ->and(asgDestinatari(ASG_MAIL_TOLTA))->toBe([]);
});

test('la preferenza ha il suo tipo, mostrato a chi ha il pannello', function () {
    $tipo = App\Enums\NotificationType::tryFrom(ASG_TIPO);
    expect($tipo)->not->toBeNull('Manca il tipo ' . ASG_TIPO . ' in NotificationType')
        ->and($tipo->label())->toBe('Segnalazione assegnata');

    $config = config('notifications.types');
    $voce = ($config['common'] ?? [])[ASG_TIPO] ?? ($config['admin'] ?? [])[ASG_TIPO] ?? null;
    expect($voce)->not->toBeNull('Manca la voce in config/notifications.php');
    $permesso = $voce['permission'] instanceof App\Enums\Permission ? $voce['permission']->value : $voce['permission'];
    expect($permesso)->toBe('Accesso pannello amministratore');

    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        foreach (['label', 'description'] as $parte) {
            expect(Lang::hasForLocale('settings.notification_types.' . ASG_TIPO . '.' . $parte, $lingua))->toBeTrue("Manca l'etichetta della preferenza ({$parte}) in {$lingua}");
        }
    }
    expect(__('settings.notification_types.' . ASG_TIPO . '.label', [], 'it'))->toBe('Segnalazione guasto assegnata')
        ->and(__('settings.notification_types.' . ASG_TIPO . '.description', [], 'it'))->toBe('Ricevi una notifica quando una segnalazione guasto ti viene assegnata, o non è più assegnata a te');
});

// --- I testi delle email -------------------------------------------------------------------------------

test('i testi delle due email stanno nei file di lingua, nelle quattro lingue', function () {
    $chiavi = [
        'notifications.assigned_ticket.subject', 'notifications.assigned_ticket.line_1', 'notifications.assigned_ticket.action',
        'notifications.unassigned_ticket.subject', 'notifications.unassigned_ticket.line_1', 'notifications.unassigned_ticket.line_1_nobody',
        'notifications.unassigned_ticket.line_1_self',
        'notifications.unassigned_ticket.action',
    ];
    foreach ($chiavi as $chiave) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    $it = fn (string $chiave) => __("notifications.{$chiave}", [], 'it');
    expect($it('assigned_ticket.subject'))->toBe('Segnalazione assegnata a te: :title')
        ->and($it('assigned_ticket.line_1'))->toBe(':user ti ha assegnato la segnalazione ":title" (:building).')
        ->and($it('assigned_ticket.action'))->toBe('Visualizza segnalazione')
        ->and($it('unassigned_ticket.subject'))->toBe('Segnalazione non più assegnata a te: :title')
        ->and($it('unassigned_ticket.line_1'))->toBe(':user ha assegnato a :name la segnalazione ":title", che seguivi tu.')
        ->and($it('unassigned_ticket.line_1_nobody'))->toBe(':user ha tolto l\'assegnazione della segnalazione ":title", che seguivi tu.')
        ->and($it('unassigned_ticket.line_1_self'))->toBe(':user ha preso per sé la segnalazione ":title", che seguivi tu.')
        ->and($it('unassigned_ticket.action'))->toBe('Visualizza segnalazione');

    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['assigned_ticket.subject', 'assigned_ticket.line_1', 'unassigned_ticket.subject', 'unassigned_ticket.line_1', 'unassigned_ticket.line_1_nobody', 'unassigned_ticket.line_1_self'] as $chiave) {
            expect(__("notifications.{$chiave}", [], $lingua))->not->toBe($it($chiave), "{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

test('in un\'altra lingua le due email non restano in italiano', function (string $lingua) {
    $s = asgAssegnata($this, $this->collaboratrice);
    asgAssegna($this, $this->admin, $s, $this->elena->id);
    $assegnata = null;
    $tolta = null;
    Notification::assertSentTo($this->elena, ASG_MAIL_ASSEGNATA, function ($n) use (&$assegnata) {
        $assegnata = $n;

        return true;
    });
    Notification::assertSentTo($this->collaboratrice, ASG_MAIL_TOLTA, function ($n) use (&$tolta) {
        $tolta = $n;

        return true;
    });

    app()->setLocale($lingua);
    foreach ([[$assegnata, $this->elena], [$tolta, $this->collaboratrice]] as [$notifica, $chi]) {
        $mail = $notifica->toMail($chi);
        $testo = implode("\n", array_merge([(string) $mail->subject, (string) $mail->actionText], $mail->introLines, $mail->outroLines));

        expect($testo)->not->toContain('assegnat')
            ->and($testo)->not->toContain('seguivi')
            ->and($testo)->toContain('Il cancello non chiude');
    }
})->with(['en', 'es', 'pt']);

test('la preferenza nasce da «Nuova segnalazione», e la ripresa non la tocca', function () {
    Illuminate\Support\Facades\DB::table('notification_preferences')->where('type', ASG_TIPO)->delete();
    asgPreferenza($this->admin, 'new_ticket', true);
    asgPreferenza($this->collaboratrice, 'new_ticket', false);
    asgPreferenza($this->elena, 'new_ticket', null);
    $percorso = database_path(ASG_MIGRAZIONE_PREFERENZA);
    expect(file_exists($percorso))->toBeTrue('Manca la migrazione della preferenza');

    (require $percorso)->up();

    $stato = fn (User $u) => Illuminate\Support\Facades\DB::table('notification_preferences')->where('user_id', $u->id)->where('type', ASG_TIPO)->value('enabled');
    expect((bool) $stato($this->admin))->toBeTrue()
        ->and($stato($this->collaboratrice))->not->toBeNull()
        ->and((bool) $stato($this->collaboratrice))->toBeFalse()
        ->and($stato($this->elena))->toBeNull();

    asgPreferenza($this->admin, ASG_TIPO, false);
    (require $percorso)->up();

    expect((bool) $stato($this->admin))->toBeFalse()
        ->and(Illuminate\Support\Facades\DB::table('notification_preferences')->where('user_id', $this->admin->id)->where('type', ASG_TIPO)->count())->toBe(1);
});
