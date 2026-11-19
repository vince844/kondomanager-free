<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni: la conferma a chi ha programmato (Coda 589, decisione 290)
 *
 * Quando una comunicazione programmata esce da sola, chi l'ha programmata riceve un'email: «Comunicazione pubblicata:
 * <oggetto>». Ha la sua preferenza di notifica, `scheduled_communication_published`, mostrata a chi ha «Pubblica
 * comunicazioni».
 *
 * - La riceve **chi ha scelto l'ora** (`programmata_da`), non l'autore e non tutto lo studio.
 * - I testi stanno nei file `notifications.php` delle quattro lingue; le date sono nel fuso dell'installazione.
 * - Se è uscita con più di cinque minuti di ritardo, l'email lo dice, con l'ora vera.
 * - «Pubblica subito» a mano non la manda (lo prova `ProgrammateModuloTest`).
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC: le 11:00 a Roma.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

const CPR_LINGUE = ['it', 'en', 'es', 'pt'];

/** L'email di conferma arrivata all'utente, o fallisce se non è partita. */
function cprConferma(User $user): Illuminate\Notifications\Messages\MailMessage
{
    expect(class_exists(CPR_MAIL_CONFERMA))->toBeTrue('Manca la notifica di conferma');
    $notifica = null;
    Notification::assertSentTo($user, CPR_MAIL_CONFERMA, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });

    return $notifica->toMail($user);
}

// --- Il tipo di preferenza -----------------------------------------------------------------------------

test('la conferma ha il suo tipo di preferenza, mostrato a chi ha «Pubblica comunicazioni»', function () {
    $tipo = NotificationType::tryFrom(CPR_TIPO_CONFERMA);
    expect($tipo)->not->toBeNull('Manca il tipo ' . CPR_TIPO_CONFERMA . ' in NotificationType');

    $config = config('notifications.types');
    $voce = ($config['common'] ?? [])[CPR_TIPO_CONFERMA] ?? ($config['admin'] ?? [])[CPR_TIPO_CONFERMA] ?? null;
    expect($voce)->not->toBeNull('Manca la voce in config/notifications.php');
    $permesso = $voce['permission'] instanceof Permission ? $voce['permission']->value : $voce['permission'];
    expect($permesso)->toBe('Pubblica comunicazioni');

    foreach (CPR_LINGUE as $lingua) {
        foreach (['label', 'description'] as $parte) {
            $chiave = 'settings.notification_types.' . CPR_TIPO_CONFERMA . '.' . $parte;
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }
    expect(__('settings.notification_types.' . CPR_TIPO_CONFERMA . '.label', [], 'it'))->toBe('Comunicazione programmata pubblicata')
        ->and($tipo->label())->toBe('Comunicazione programmata pubblicata');
});

// --- I testi -------------------------------------------------------------------------------------------

test('i testi della conferma stanno nei file di lingua, nelle quattro lingue', function () {
    foreach (['subject', 'line_1', 'line_late', 'action'] as $voce) {
        foreach (CPR_LINGUE as $lingua) {
            $chiave = "notifications.scheduled_communication_published.{$voce}";
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    $it = fn (string $voce) => __("notifications.scheduled_communication_published.{$voce}", [], 'it');
    expect($it('subject'))->toBe('Comunicazione pubblicata: :title')
        ->and($it('line_1'))->toBe('La comunicazione ":title", programmata per il :date alle :time, è stata pubblicata.')
        ->and($it('line_late'))->toBe('È uscita in ritardo, il :date alle :time: all\'ora scelta il pianificatore non era attivo.')
        ->and($it('action'))->toBe('Visualizza comunicazione');

    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['subject', 'line_1', 'line_late'] as $voce) {
            $chiave = "notifications.scheduled_communication_published.{$voce}";
            expect(__($chiave, [], $lingua))->not->toBe(__($chiave, [], 'it'), "{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

// --- L'email -------------------------------------------------------------------------------------------

test('uscita all\'ora scelta, la conferma dice l\'oggetto e l\'ora nel fuso dell\'installazione, e porta alla scheda', function () {
    // Le 10:00 UTC di adesso sono le 11:00 di Roma.
    $c = cprProgrammata([$this->aurora], $this->admin, CPR_ADESSO);

    cprPubblica($this);

    $mail = cprConferma($this->admin);
    expect($mail->subject)->toBe('Comunicazione pubblicata: Chiusura dell\'acqua')
        ->and($mail->introLines)->toBe(['La comunicazione "Chiusura dell\'acqua", programmata per il 12/11/2026 alle 11:00, è stata pubblicata.'])
        ->and($mail->actionText)->toBe('Visualizza comunicazione')
        ->and($mail->actionUrl)->toBe(route('admin.comunicazioni.show', $c));
});

test('con pochi minuti di scarto non è un ritardo; oltre cinque lo è, e la conferma dice l\'ora vera', function (string $perUtc, bool $inRitardo) {
    cprProgrammata([$this->aurora], $this->admin, $perUtc);

    cprPubblica($this);

    $mail = cprConferma($this->admin);
    $righe = array_merge($mail->introLines, $mail->outroLines);
    if ($inRitardo) {
        expect($righe)->toContain('È uscita in ritardo, il 12/11/2026 alle 11:00: all\'ora scelta il pianificatore non era attivo.');
    } else {
        expect(implode("\n", $righe))->not->toContain('ritardo');
    }
})->with([
    'cinque minuti prima di adesso: non è un ritardo' => ['2026-11-12 09:55:00', false],
    'sei minuti prima: è un ritardo' => ['2026-11-12 09:54:00', true],
    'tre giorni prima: è un ritardo' => ['2026-11-09 08:00:00', true],
]);

test('la conferma dell\'uscita in ritardo dice anche l\'ora che era stata scelta', function () {
    // Programmata per lunedì 9 alle 9:00 di Roma, esce giovedì 12 alle 11:00.
    cprProgrammata([$this->aurora], $this->admin, '2026-11-09 08:00:00');

    cprPubblica($this);

    expect(cprConferma($this->admin)->introLines[0])
        ->toBe('La comunicazione "Chiusura dell\'acqua", programmata per il 09/11/2026 alle 09:00, è stata pubblicata.');
});

test('con un altro fuso dell\'installazione le ore della conferma sono in quel fuso', function () {
    config(['app.user_timezone' => 'America/New_York']);
    // Le 10:00 UTC sono le 5:00 a New York.
    cprProgrammata([$this->aurora], $this->admin, CPR_ADESSO);

    cprPubblica($this);

    expect(cprConferma($this->admin)->introLines[0])->toContain('12/11/2026')->toContain('05:00');
});

test('in un\'altra lingua la conferma non resta in italiano', function (string $lingua) {
    cprProgrammata([$this->aurora], $this->admin, '2026-11-09 08:00:00');
    cprPubblica($this);
    $notifica = null;
    Notification::assertSentTo($this->admin, CPR_MAIL_CONFERMA, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });

    app()->setLocale($lingua);
    $mail = $notifica->toMail($this->admin);
    $testo = implode("\n", array_merge([(string) $mail->subject, (string) $mail->actionText], $mail->introLines, $mail->outroLines));

    expect($mail->actionText)->toBe(__('notifications.scheduled_communication_published.action', [], $lingua))
        ->and($testo)->not->toContain('è stata pubblicata')
        ->and($testo)->not->toContain('in ritardo')
        ->and($testo)->toContain('Chiusura dell\'acqua');
})->with(['en', 'es', 'pt']);

// --- Chi la riceve -------------------------------------------------------------------------------------

test('la conferma va a chi ha scelto l\'ora, non all\'autore e non a tutto lo studio', function () {
    // L'autore è l'amministratore; l'ora l'ha scelta la collaboratrice.
    cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:58:00', [], $this->collaboratrice);

    cprPubblica($this);

    expect(ccmDestinatari(CPR_MAIL_CONFERMA))->toBe(ccmIds($this->collaboratrice));
});

test('chi ha spento la preferenza non riceve la conferma; la comunicazione esce lo stesso', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:58:00');
    cprPreferenza($this->admin, CPR_TIPO_CONFERMA, false);

    cprPubblica($this);

    expect((bool) cprRiga($c)->is_published)->toBeTrue()
        ->and(class_exists(CPR_MAIL_CONFERMA))->toBeTrue('Manca la notifica di conferma')
        ->and(ccmDestinatari(CPR_MAIL_CONFERMA))->toBe([]);
});

test('chi non ha mai salvato le preferenze non riceve la conferma: senza la riga il tipo è spento', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:58:00');
    Illuminate\Support\Facades\DB::table('notification_preferences')->where('user_id', $this->admin->id)->where('type', CPR_TIPO_CONFERMA)->delete();

    cprPubblica($this);

    expect((bool) cprRiga($c)->is_published)->toBeTrue()
        ->and(class_exists(CPR_MAIL_CONFERMA))->toBeTrue('Manca la notifica di conferma')
        ->and(ccmDestinatari(CPR_MAIL_CONFERMA))->toBe([]);
});

test('se chi ha programmato non c\'è più, o non pubblica più, la comunicazione esce senza conferma', function () {
    $ruolo = ccmRuolo('segreteria', ['Accesso pannello amministratore', 'Crea comunicazioni', 'Visualizza comunicazioni']);
    $exCollaboratore = ccmUtente([$this->aurora->id], 'Elia Ex', $ruolo);
    $perso = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:58:00', [], $exCollaboratore);
    $orfana = cprProgrammata([$this->borea], $this->admin, '2026-11-12 09:58:00');
    Illuminate\Support\Facades\DB::table('comunicazioni')->where('id', $orfana->id)->update(['programmata_da' => 999999]);

    cprPubblica($this);

    expect((bool) cprRiga($perso)->is_published)->toBeTrue()
        ->and((bool) cprRiga($orfana)->is_published)->toBeTrue()
        ->and(class_exists(CPR_MAIL_CONFERMA))->toBeTrue('Manca la notifica di conferma')
        ->and(ccmDestinatari(CPR_MAIL_CONFERMA))->toBe([]);
});
