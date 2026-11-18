<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti, il giro di correzioni 3 (Coda 559; decisioni 286 e 287)
 *
 * - **I testi dell'email del commento modificato stanno nei file di lingua** (F3). Scritti nella classe, in un'altra lingua
 *   resterebbero in italiano. Il testo italiano è quello approvato (decisione 286) e non cambia.
 * - **La scheda della comunicazione dà la data della modifica di un commento** (F4). Con il solo «modificato» la pagina
 *   scriveva «Modificato il» con la data di creazione.
 * - **«Scrivi»**, il pulsante che apre una discussione chiusa (F2), sta nei file di lingua.
 *
 * La disposizione della scheda (F1) e le discussioni chiuse (F2) le provano due vitest:
 * `resources/js/pages/comunicazioni/ComunicazioniView.test.ts` e
 * `resources/js/components/commenti/DiscussioniComunicazioneChiuse.test.ts`.
 */

use App\Models\Commento;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
});

const CCM3_MAIL_MODIFICATO = 'App\\Notifications\\Commenti\\CommentoModificatoInAttesaNotification';
const CCM3_LINGUE = ['it', 'en', 'es', 'pt'];
const CCM3_VOCI = ['subject', 'line_1', 'line_2', 'action'];

/** L'email del commento modificato, per un commento in attesa già a database. */
function ccm3Mail(int $commentoId, $notifiable): MailMessage
{
    $classe = CCM3_MAIL_MODIFICATO;

    return (new $classe(Commento::findOrFail($commentoId)))->toMail($notifiable);
}

/** Tutto ciò che l'email fa leggere: oggetto, righe, pulsante. */
function ccm3Testo(MailMessage $mail): string
{
    return implode("\n", array_merge([(string) $mail->subject, (string) $mail->actionText], $mail->introLines, $mail->outroLines));
}

// --- F3: i testi dell'email del commento modificato ---------------------------------------------------

test('i testi dell\'email del commento modificato stanno nei file di lingua, nelle quattro lingue', function () {
    foreach (['ticket', 'communication'] as $tipo) {
        foreach (CCM3_VOCI as $voce) {
            $chiave = "notifications.edited_pending_{$tipo}_comment.{$voce}";
            foreach (CCM3_LINGUE as $lingua) {
                expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
            }
        }
    }

    $it = fn (string $chiave) => __("notifications.{$chiave}", [], 'it');

    expect($it('edited_pending_ticket_comment.subject'))->toBe('Commento modificato in attesa di approvazione')
        ->and($it('edited_pending_ticket_comment.line_1'))->toBe(':user ha modificato un commento per la segnalazione ":title":')
        ->and($it('edited_pending_ticket_comment.line_2'))->toBe('Accedi per approvare o rifiutare il commento.')
        ->and($it('edited_pending_ticket_comment.action'))->toBe('Visualizza segnalazione')
        ->and($it('edited_pending_communication_comment.subject'))->toBe('Commento modificato in attesa di approvazione')
        ->and($it('edited_pending_communication_comment.line_1'))->toBe(':user ha modificato un commento per la comunicazione ":title":')
        ->and($it('edited_pending_communication_comment.line_2'))->toBe('Accedi per approvare o rifiutare il commento.')
        ->and($it('edited_pending_communication_comment.action'))->toBe('Visualizza comunicazione');

    // Nelle altre lingue i testi sono tradotti: l'italiano copiato non è una traduzione.
    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['ticket', 'communication'] as $tipo) {
            foreach (['subject', 'line_1', 'line_2'] as $voce) {
                $chiave = "notifications.edited_pending_{$tipo}_comment.{$voce}";
                expect(__($chiave, [], $lingua))->not->toBe(__($chiave, [], 'it'), "{$chiave} in {$lingua} è rimasta in italiano");
            }
        }
    }
});

test('in italiano l\'email del commento modificato è quella approvata, per una comunicazione e per una segnalazione', function () {
    $suComunicazione = ccmCommentoSalvato($this->c, $this->mario, 'Riscritto dopo l\'approvazione', $this->aurora, null, 'in_attesa');
    $segnalazione = ccmSegnalazione($this->aurora, $this->mario);
    $suSegnalazione = ccmCommentoSalvato($segnalazione, $this->mario, 'Riscritto anche qui', $this->aurora, null, 'in_attesa');

    $mail = ccm3Mail($suComunicazione, $this->admin);
    expect($mail->subject)->toBe('Commento modificato in attesa di approvazione')
        ->and($mail->introLines[0])->toBe('Mario Rossi ha modificato un commento per la comunicazione "Lavori al tetto":')
        ->and($mail->introLines[1])->toBe('"Riscritto dopo l\'approvazione"')
        ->and($mail->actionText)->toBe('Visualizza comunicazione')
        ->and($mail->actionUrl)->toBe(route('admin.comunicazioni.show', $this->c) . '#commenti')
        ->and($mail->outroLines)->toBe(['Accedi per approvare o rifiutare il commento.']);

    $mail = ccm3Mail($suSegnalazione, $this->admin);
    expect($mail->subject)->toBe('Commento modificato in attesa di approvazione')
        ->and($mail->introLines[0])->toBe('Mario Rossi ha modificato un commento per la segnalazione "Il cancello non chiude":')
        ->and($mail->introLines[1])->toBe('"Riscritto anche qui"')
        ->and($mail->actionText)->toBe('Visualizza segnalazione')
        ->and($mail->actionUrl)->toBe(route('admin.segnalazioni.show', $segnalazione) . '#commenti')
        ->and($mail->outroLines)->toBe(['Accedi per approvare o rifiutare il commento.']);
});

test('in un\'altra lingua l\'email del commento modificato non resta in italiano', function (string $lingua) {
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Riscritto dopo l\'approvazione', $this->aurora, null, 'in_attesa');

    app()->setLocale($lingua);
    $mail = ccm3Mail($id, $this->admin);
    $testo = ccm3Testo($mail);

    expect($mail->subject)->toBe(__('notifications.edited_pending_communication_comment.subject', [], $lingua))
        ->and($mail->subject)->not->toBe('Commento modificato in attesa di approvazione')
        ->and($mail->actionText)->toBe(__('notifications.edited_pending_communication_comment.action', [], $lingua))
        ->and($mail->outroLines)->toBe([__('notifications.edited_pending_communication_comment.line_2', [], $lingua)])
        ->and($testo)->not->toContain('ha modificato un commento')
        ->and($testo)->not->toContain('Accedi per approvare')
        // Il titolo e il testo del commento sono di chi li ha scritti: restano com'erano.
        ->and($testo)->toContain('Lavori al tetto')
        ->and($testo)->toContain('Riscritto dopo l\'approvazione');
})->with(['en', 'es', 'pt']);

// --- F4: la data della modifica nella scheda ----------------------------------------------------------

test('la scheda della comunicazione dà la data della modifica di un commento, e niente per chi non è mai stato modificato', function () {
    $modificato = ccmCommentoSalvato($this->c, $this->mario, 'Grazie, ho corretto', $this->aurora);
    $intatto = ccmCommentoSalvato($this->c, $this->mario, 'Mai toccato', $this->aurora);
    DB::table('commenti')->where('id', $modificato)->update([
        'created_at' => '2026-11-01 09:00:00',
        'modificato_at' => '2026-11-05 16:30:00',
    ]);

    $props = ccmScheda($this, $this->mario, $this->c);
    $discussione = ccmDiscussione($props, ccmChiaveUnica());
    expect($discussione)->not->toBeNull('Mario non vede la discussione unica');
    $commenti = collect($discussione['commenti'])->keyBy('id');

    expect($commenti[$modificato]['modificato'])->toBeTrue()
        ->and($commenti[$modificato])->toHaveKey('modificato_at')
        ->and($commenti[$modificato]['modificato_at'])->toBeString()
        ->and(Carbon::parse($commenti[$modificato]['modificato_at'])->equalTo(Commento::findOrFail($modificato)->modificato_at))->toBeTrue('La data della modifica non è quella del commento')
        ->and(Carbon::parse($commenti[$modificato]['modificato_at'])->equalTo(Carbon::parse($commenti[$modificato]['created_at'])))->toBeFalse('La data della modifica è quella di creazione')
        ->and($commenti[$intatto]['modificato'])->toBeFalse()
        ->and($commenti[$intatto])->toHaveKey('modificato_at')
        ->and($commenti[$intatto]['modificato_at'])->toBeNull();
});

// --- F2: «Scrivi» -------------------------------------------------------------------------------------

test('«Scrivi», il pulsante che apre una discussione chiusa, sta nei file di lingua', function () {
    foreach (CCM3_LINGUE as $lingua) {
        expect(Lang::hasForLocale('commenti.scrivi', $lingua))->toBeTrue("Manca commenti.scrivi in {$lingua}");
    }

    expect(__('commenti.scrivi', [], 'it'))->toBe('Scrivi');
});
