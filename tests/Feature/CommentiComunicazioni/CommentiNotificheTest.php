<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti alle comunicazioni: le email (Coda 559, decisioni 261.3 e 269; Coda 558)
 *
 * Un commento avvisa lo studio e chi partecipa alla stessa discussione, mai tutti i lettori: chi ha scritto la
 * comunicazione, chi ha il pannello e sta nel palazzo di chi scrive, chi ha già commentato lì (nella discussione unica di una
 * comunicazione ai palazzi, da qualunque palazzo: decisione 458) e, per una discussione con una persona, quella persona.
 * Nessuno riceve l'avviso di una discussione che non vede. Un commento in attesa avvisa
 * tutti quelli che lo possono approvare, anche senza un'anagrafica nel palazzo (decisione 282.1: lo studio di norma non ne
 * ha una); l'approvazione avvisa l'autore e chi partecipa. Il collegamento porta alla scheda
 * della comunicazione, nella porta di chi riceve; il testo dice «comunicazione». Le preferenze sono separate da quelle
 * dei commenti delle segnalazioni (decisione 269): chi spegne le une tiene le altre.
 */

use App\Enums\Role;
use App\Models\Commento;
use App\Notifications\Commenti\CommentoApprovatoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\CommentoNascostoNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->palazzi = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
});

/** Il testo di un'email come la vede chi la riceve: oggetto, righe e pulsante. */
function ccmTestoEmail(object $notifica, $notifiable): string
{
    $mail = $notifica->toMail($notifiable);

    return implode("\n", array_merge([$mail->subject, $mail->actionText], $mail->introLines, $mail->outroLines));
}

test('un commento pubblicato da Aurora avvisa lo studio di Aurora e chi ha già commentato nella discussione, anche da Borea', function () {
    ccmCommentoSalvato($this->palazzi, $this->paolo, 'Ci sarò', $this->aurora);
    ccmCommentoSalvato($this->palazzi, $this->lucia, 'Anche noi?', $this->borea);

    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Grazie')->assertSessionHasNoErrors();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->admin, $this->collaboratrice, $this->paolo, $this->lucia));
});

test('in una discussione con una persona l\'avviso va a quella persona e allo studio del suo palazzo, non agli altri destinatari', function () {
    $persone = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => 'Il suo sollecito'], [$this->mario, $this->lucia]);
    ccmCommentoSalvato($persone, $this->lucia, 'Ho già pagato', $this->borea, $this->lucia);

    ccmScrivi($this, $this->admin, $persone, ccmChiavePersona($this->mario), 'Ci mandi la ricevuta', 'admin')->assertSessionHasNoErrors();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->mario, $this->collaboratrice));
});

test('un commento in attesa avvisa tutti quelli che lo possono approvare, anche senza un\'anagrafica nel palazzo; non chi non approva', function () {
    $this->palazzi->update(['commenti_moderati' => true]);
    $studioBorea = ccmUtente([$this->borea->id], 'Studio Borea', Role::AMMINISTRATORE->value);
    $studioSenzaAnagrafica = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');

    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Si può spostare?')->assertSessionHasNoErrors();

    // La collaboratrice ha il pannello ma non «Approva»: non lo riceve. Mario è l'autore del commento.
    expect(ccmCommenti($this->palazzi)->first()->stato)->toBe('in_attesa')
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe(ccmIds($this->admin, $studioBorea, $studioSenzaAnagrafica))
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toBe([]);
});

test('l\'approvazione avvisa l\'autore e chi partecipa alla discussione, non chi ha approvato', function () {
    $this->palazzi->update(['commenti_moderati' => true]);
    ccmCommentoSalvato($this->palazzi, $this->paolo, 'Ci sarò', $this->aurora);
    ccmCommentoSalvato($this->palazzi, $this->lucia, 'Anche noi?', $this->borea);
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Si può spostare?', $this->aurora, null, 'in_attesa');

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $id));

    // Lucia ha scritto da Borea nella stessa discussione unica (decisione 458): partecipa anche lei.
    expect(Commento::find($id)->stato)->toBe('pubblicato')
        ->and(ccmDestinatari(CommentoApprovatoNotification::class))->toBe(ccmIds($this->mario))
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->collaboratrice, $this->paolo, $this->lucia));
});

test('l\'email porta alla scheda della comunicazione nella porta di chi la riceve, e dice «comunicazione»', function () {
    ccmScrivi($this, $this->admin, $this->palazzi, ccmChiaveUnica(), 'Il cantiere apre alle 8', 'admin')->assertSessionHasNoErrors();
    ccmCommentoSalvato($this->palazzi, $this->mario, 'Grazie', $this->aurora);

    $controlla = function ($destinatario, string $porta) {
        $notifica = null;
        Notification::assertSentTo($destinatario, NuovoCommentoNotification::class, function ($n) use (&$notifica) {
            $notifica = $n;

            return true;
        });
        $mail = $notifica->toMail($destinatario);
        expect($mail->actionUrl)->toBe(route($porta . '.comunicazioni.show', $this->palazzi) . '#commenti');

        $testo = ccmTestoEmail($notifica, $destinatario);
        expect(mb_strtolower($testo))->toContain('comunicazion')
            ->and(mb_strtolower($testo))->not->toContain('segnalazion');
    };

    $controlla($this->collaboratrice, 'admin');
    Notification::fake();
    ccmScrivi($this, $this->admin, $this->palazzi, ccmChiaveUnica(), 'Confermo', 'admin')->assertSessionHasNoErrors();
    $controlla($this->mario, 'user');
});

test('un commento nascosto dallo studio avvisa il suo autore', function () {
    $id = ccmCommentoSalvato($this->palazzi, $this->mario, 'Fuori tema', $this->aurora);

    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $id));

    expect(Commento::find($id)->stato)->toBe('nascosto')
        ->and(ccmDestinatari(CommentoNascostoNotification::class))->toBe(ccmIds($this->mario));
});

function ccmPreferenza(\App\Models\User $user, string $tipo, bool $acceso): void
{
    \Illuminate\Support\Facades\DB::table('notification_preferences')->where('user_id', $user->id)->where('type', $tipo)->update(['enabled' => $acceso]);
}

test('le preferenze dei commenti delle comunicazioni sono separate da quelle delle segnalazioni', function () {
    $segnalazione = ccmSegnalazione($this->aurora, $this->mario);
    ccmCommentoSalvato($this->palazzi, $this->paolo, 'Ci sarò', $this->aurora);
    ccmCommentoSalvato($segnalazione, $this->paolo, 'Anche da me', $this->aurora);
    ccmPreferenza($this->paolo, 'new_communication_comment', false);
    ccmPreferenza($this->paolo, 'new_comment', true);

    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Grazie')->assertSessionHasNoErrors();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->paolo->id);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $segnalazione), ['corpo' => 'Ancora rotto'])->assertSessionHasNoErrors();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->toContain($this->paolo->id);
});

test('lo studio che spegne l\'avviso dei commenti in attesa delle comunicazioni lo tiene per le segnalazioni', function () {
    $this->palazzi->update(['commenti_moderati' => true]);
    ccmPreferenza($this->admin, 'communication_comment_under_moderation', false);
    ccmPreferenza($this->admin, 'comment_under_moderation', true);
    $segnalazione = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($segnalazione, true);

    ccmScrivi($this, $this->mario, $this->palazzi, ccmChiaveUnica(), 'Si può spostare?')->assertSessionHasNoErrors();
    expect(ccmDestinatari(CommentoInAttesaNotification::class))->toBe([]);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $segnalazione), ['corpo' => 'Ancora rotto'])->assertSessionHasNoErrors();
    expect(ccmDestinatari(CommentoInAttesaNotification::class))->toBe(ccmIds($this->admin));
});

test('le cinque preferenze delle comunicazioni hanno la loro voce, mostrata a chi ha il permesso', function () {
    // Le voci stanno in `notifications.types`, divise per gruppo: si cerca il tipo in tutti i gruppi.
    $trova = fn (string $tipo) => collect(config('notifications.types'))->map(fn ($gruppo) => $gruppo[$tipo] ?? null)->filter()->first();
    $attesi = [
        'new_communication_comment' => 'Commenta comunicazioni',
        'communication_comment_under_moderation' => 'Approva commenti comunicazioni',
        'communication_comment_approved' => 'Commenta comunicazioni',
        'communication_comment_deleted' => 'Commenta comunicazioni',
        'communication_comment_removed' => 'Commenta comunicazioni',
    ];
    foreach ($attesi as $tipo => $permesso) {
        $voce = $trova($tipo);
        expect($voce)->not->toBeNull("Manca la voce di «{$tipo}» in config/notifications.php");
        $valore = $voce['permission'] instanceof \BackedEnum ? $voce['permission']->value : $voce['permission'];
        expect($valore)->toBe($permesso, "La voce di «{$tipo}»");
    }
});
