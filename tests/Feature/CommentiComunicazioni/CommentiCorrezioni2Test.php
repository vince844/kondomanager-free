<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti, il giro di correzioni 2 (Code 559 e 558; decisione 285)
 *
 * - **Il commento modificato che torna in attesa ha un'email sua** (E1, decisione 285): non quella del commento nuovo in
 *   attesa. Ha la sua preferenza, una per le segnalazioni e una per le comunicazioni, che nasce come «commento da
 *   moderare» dello stesso tipo.
 * - **I testi delle schede stanno nei file di lingua** (E2), non scritti nelle pagine: in un'altra lingua resterebbero in
 *   italiano.
 * - **Una comunicazione senza discussioni non dice «segnalazione»** (E3).
 * - **Senza il valore sull'elemento, una comunicazione si tratta come moderata e una segnalazione no** (E4).
 * - **Una segnalazione ha una discussione sola**: non dipende dal palazzo scritto sul commento (E5).
 * - **Le preferenze dei commenti hanno etichette diverse** per le segnalazioni e per le comunicazioni (E6).
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Services\CommentoNotificationService;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
});

const CCM_MAIL_MODIFICATO = 'App\\Notifications\\Commenti\\CommentoModificatoInAttesaNotification';
const CCM_MIGRAZIONE_MODIFICATO = 'migrations/2026_11_11_130000_seed_preferenze_notifica_commento_modificato.php';

function ccmAccendi(User $user, string $tipo, bool $acceso): void
{
    DB::table('notification_preferences')->updateOrInsert(
        ['user_id' => $user->id, 'type' => $tipo],
        ['enabled' => $acceso, 'created_at' => now(), 'updated_at' => now()]
    );
}

// --- E1: l'email del commento modificato ---------------------------------------------------------------

test('il commento approvato e poi modificato manda a chi approva un\'email sua, con il suo oggetto e il collegamento alla scheda', function () {
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Grazie', $this->aurora);

    $this->actingAs($this->mario)->patch(route('user.commenti.update', $id), ['corpo' => 'Riscritto dopo l\'approvazione'])->assertSessionHasNoErrors();

    expect(class_exists(CCM_MAIL_MODIFICATO))->toBeTrue('Manca la notifica del commento modificato')
        ->and(ccmDestinatari(CCM_MAIL_MODIFICATO))->toBe(ccmIds($this->admin))
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe([]);

    $notifica = null;
    Notification::assertSentTo($this->admin, CCM_MAIL_MODIFICATO, function ($n) use (&$notifica) {
        $notifica = $n;

        return true;
    });
    $mail = $notifica->toMail($this->admin);
    $testo = mb_strtolower(implode("\n", array_merge($mail->introLines, $mail->outroLines)));

    expect($mail->subject)->toBe('Commento modificato in attesa di approvazione')
        ->and($mail->actionUrl)->toBe(route('admin.comunicazioni.show', $this->c) . '#commenti')
        ->and($testo)->toContain('lavori al tetto')
        ->and($testo)->toContain('comunicazion')
        ->and($testo)->not->toContain('segnalazion');
});

test('la preferenza del commento modificato è sua: separata da «commento da moderare», e una per tipo', function () {
    foreach (['comment_edited_under_moderation', 'communication_comment_edited_under_moderation'] as $tipo) {
        expect(\App\Enums\NotificationType::tryFrom($tipo))->not->toBeNull("Manca il tipo di notifica «{$tipo}»");
    }

    // Comunicazione: spenta la preferenza del modificato, accesa «da moderare». Il commento torna in attesa, senza email.
    ccmAccendi($this->admin, 'communication_comment_edited_under_moderation', false);
    ccmAccendi($this->admin, 'communication_comment_under_moderation', true);
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Grazie', $this->aurora);
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $id), ['corpo' => 'Riscritto']);

    expect(Commento::find($id)->stato)->toBe('in_attesa')
        ->and(ccmDestinatari(CCM_MAIL_MODIFICATO))->toBe([])
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe([]);

    // Segnalazione: la sua preferenza è un'altra, ed è accesa: l'email parte.
    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($s, true);
    $idS = ccmCommentoSalvato($s, $this->paolo, 'Anche da me', $this->aurora);
    $this->actingAs($this->paolo)->patch(route('user.commenti.update', $idS), ['corpo' => 'Riscritto']);

    expect(Commento::find($idS)->stato)->toBe('in_attesa')
        ->and(ccmDestinatari(CCM_MAIL_MODIFICATO))->toBe(ccmIds($this->admin));
});

test('l\'email del commento modificato non parte se nel frattempo il commento è stato approvato, o chi la riceve non può approvare', function () {
    expect(class_exists(CCM_MAIL_MODIFICATO))->toBeTrue('Manca la notifica del commento modificato');
    $classe = CCM_MAIL_MODIFICATO;
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Riscritto', $this->aurora, null, 'in_attesa');
    $parte = fn (User $user) => (new $classe(Commento::find($id)))->shouldSend($user, 'mail');

    expect($parte($this->admin))->toBeTrue()
        ->and($parte($this->collaboratrice))->toBeFalse('La collaboratrice non può approvare');

    DB::table('commenti')->where('id', $id)->update(['stato' => 'pubblicato']);
    expect($parte($this->admin))->toBeFalse('Il commento è già stato approvato');
});

test('le due preferenze del commento modificato nascono come «commento da moderare» dello stesso tipo, e la ripresa non le tocca', function () {
    $coppie = [
        'comment_edited_under_moderation' => 'comment_under_moderation',
        'communication_comment_edited_under_moderation' => 'communication_comment_under_moderation',
    ];
    $path = database_path(CCM_MIGRAZIONE_MODIFICATO);
    expect(file_exists($path))->toBeTrue('Manca la migrazione delle preferenze del commento modificato');

    $acceso = User::factory()->create();
    $spento = User::factory()->create();
    DB::table('notification_preferences')->whereIn('user_id', [$acceso->id, $spento->id])->delete();
    foreach ($coppie as $sorella) {
        ccmAccendi($acceso, $sorella, true);
        ccmAccendi($spento, $sorella, false);
    }
    $stato = fn (User $u, string $tipo) => DB::table('notification_preferences')->where('user_id', $u->id)->where('type', $tipo)->pluck('enabled')->map(fn ($v) => (bool) $v)->all();

    $migrazione = require $path;
    $migrazione->up();
    $migrazione->up();
    foreach (array_keys($coppie) as $nuova) {
        expect($stato($acceso, $nuova))->toBe([true], "«{$nuova}» di chi aveva acceso la sorella")
            ->and($stato($spento, $nuova))->toBe([false], "«{$nuova}» di chi aveva spento la sorella");
    }

    // Una scelta già fatta sulla preferenza nuova non si tocca.
    ccmAccendi($spento, 'comment_edited_under_moderation', true);
    $migrazione->up();
    expect($stato($spento, 'comment_edited_under_moderation'))->toBe([true]);
});

test('le due preferenze del commento modificato hanno la loro voce, mostrata a chi può approvare', function () {
    $trova = fn (string $tipo) => collect(config('notifications.types'))->map(fn ($gruppo) => $gruppo[$tipo] ?? null)->filter()->first();
    $attesi = [
        'comment_edited_under_moderation' => 'Approva commenti segnalazioni',
        'communication_comment_edited_under_moderation' => 'Approva commenti comunicazioni',
    ];
    foreach ($attesi as $tipo => $permesso) {
        $voce = $trova($tipo);
        expect($voce)->not->toBeNull("Manca la voce di «{$tipo}» in config/notifications.php");
        $valore = $voce['permission'] instanceof \BackedEnum ? $voce['permission']->value : $voce['permission'];
        expect($valore)->toBe($permesso, "La voce di «{$tipo}»");
    }
});

// --- E6: le etichette delle preferenze ---------------------------------------------------------------------

test('le preferenze dei commenti hanno etichette diverse per le segnalazioni e per le comunicazioni, in ogni lingua', function () {
    $attese = [
        'new_comment' => 'Nuovo commento (segnalazioni)',
        'comment_under_moderation' => 'Commento da moderare (segnalazioni)',
        'comment_edited_under_moderation' => 'Commento modificato da moderare (segnalazioni)',
        'comment_approved' => 'Commento approvato (segnalazioni)',
        'comment_deleted' => 'Commento eliminato o nascosto (segnalazioni)',
        'new_communication_comment' => 'Nuovo commento (comunicazioni)',
        'communication_comment_under_moderation' => 'Commento da moderare (comunicazioni)',
        'communication_comment_edited_under_moderation' => 'Commento modificato da moderare (comunicazioni)',
        'communication_comment_approved' => 'Commento approvato (comunicazioni)',
        'communication_comment_deleted' => 'Commento eliminato o nascosto (comunicazioni)',
    ];
    $etichette = function (string $lingua) use ($attese) {
        $voci = (include base_path("lang/{$lingua}/settings.php"))['notification_types'] ?? [];

        return collect(array_keys($attese))->mapWithKeys(fn ($tipo) => [$tipo => $voci[$tipo]['label'] ?? null])->all();
    };

    expect($etichette('it'))->toBe($attese);
    foreach (['en', 'es', 'pt'] as $lingua) {
        $voci = $etichette($lingua);
        expect(array_filter($voci, fn ($v) => $v === null || $v === ''))->toBe([], "Etichette mancanti in {$lingua}")
            ->and(count(array_unique($voci)))->toBe(count($attese), "In {$lingua} due preferenze dei commenti hanno la stessa etichetta");
    }
});

// --- E2 ed E3: i testi delle schede -------------------------------------------------------------------------

test('i testi delle schede dei commenti stanno nei file di lingua, non scritti nelle pagine', function () {
    $attesi = [
        'commenti.chiusi' => 'Chiusi',
        'commenti.in_moderazione' => 'In moderazione',
        'commenti.con_lo_studio' => 'Con lo studio',
        'commenti.discussione_privata_portale' => 'Questa discussione la leggete solo tu e lo studio.',
        'commenti.discussione_privata_pannello' => 'La leggono solo questa persona e lo studio.',
        'commenti.consenti_hint' => 'Spento, nessuno scrive commenti nuovi, nemmeno lo studio. Quelli che ci sono restano visibili.',
        'commenti.moderazione_hint' => 'Acceso, i commenti dei condòmini aspettano l\'approvazione; quelli dello studio escono subito. Spento, escono subito i commenti di chi ha il permesso di pubblicarli.',
        'commenti.nessuna_discussione' => 'Nessuna discussione',
        'commenti.nessuna_discussione_descrizione' => 'Questa comunicazione non ha destinatari che possano commentare.',
    ];
    foreach ($attesi as $chiave => $testo) {
        expect(Lang::get($chiave, [], 'it'))->toBe($testo, "Il testo di {$chiave}");
        foreach (['en', 'es', 'pt'] as $lingua) {
            expect(Lang::has($chiave, $lingua, false))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    $frasi = ['leggete solo tu e lo studio', 'leggono solo questa persona', 'nemmeno lo studio', 'quelli dello studio escono subito'];
    $file = [
        resource_path('js/components/commenti/DiscussioniComunicazione.vue'),
        resource_path('js/components/commenti/ListaCommenti.vue'),
        resource_path('js/pages/comunicazioni/ComunicazioniView.vue'),
    ];
    $scritte = [];
    foreach ($file as $f) {
        $contenuto = file_get_contents($f);
        foreach ($frasi as $frase) {
            if (str_contains($contenuto, $frase)) {
                $scritte[] = basename($f) . ': «' . $frase . '»';
            }
        }
    }
    expect($scritte)->toBe([], "Testi scritti nella pagina invece che nel file di lingua:\n" . implode("\n", $scritte))
        ->and(file_get_contents(app_path('Support/Commenti/RegoleCommenti.php')))->not->toContain("'Con lo studio'");
});

// --- E4: il ripiego della moderazione ---------------------------------------------------------------------------

test('senza il valore sull\'elemento, una comunicazione si tratta come moderata e una segnalazione no', function () {
    ccmModerazione($this->c, false);
    $comunicazione = Comunicazione::query()->select(['id', 'subject', 'created_by', 'can_comment', 'is_private', 'is_published', 'is_approved'])->find($this->c->id);
    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($s, true);
    $segnalazione = Segnalazione::query()->select(['id', 'subject', 'created_by', 'condominio_id', 'can_comment', 'is_private', 'is_published', 'is_approved'])->find($s->id);

    expect(RegoleCommenti::statoAllaNascita($this->mario, $comunicazione))->toBe('in_attesa')
        ->and(RegoleCommenti::statoAllaNascita($this->mario, $segnalazione))->toBe('pubblicato');
});

// --- E5: la discussione di una segnalazione ----------------------------------------------------------------------

test('una segnalazione ha una discussione sola: un commento senza palazzo, o con un altro, avvisa lo stesso chi partecipa', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmCommentoSalvato($s, $this->paolo, 'Anche da me', $this->aurora);
    $senzaPalazzo = Commento::find(ccmCommentoSalvato($s, $this->mario, 'Ancora rotto', null));
    $altroPalazzo = Commento::find(ccmCommentoSalvato($s, $this->mario, 'Sempre rotto', $this->borea));

    foreach ([$senzaPalazzo, $altroPalazzo] as $commento) {
        expect(app(CommentoNotificationService::class)->destinatariPubblicato($commento, [(int) $commento->user_id])->pluck('id')->all())
            ->toContain($this->paolo->id)
            ->and((new NuovoCommentoNotification($commento))->shouldSend($this->paolo, 'mail'))->toBeTrue();
    }
});
