<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # I testi del prodotto che promettevano ciò che non c'è (Coda 672)
 *
 * Tre frasi, trovate dalle guide del sito:
 * - sotto il titolo di «Impostazioni generali» una frase in inglese: oggi è in italiano (dalla 1.12.0-beta.12);
 * - in cima all'elenco delle comunicazioni «Tracciamento invii — Monitora lo stato di consegna e lettura»: la guida ora si chiama «Letture» e dice ciò che
 *   la conferma di lettura fa (Coda 594);
 * - sul pulsante «Pubblica nascoste» del piano rate la visibilità «nell'App» e le notifiche «Push/Email»: l'app e le notifiche push non esistono, e la rotta
 *   del pulsante non manda nessuna email (rende visibili le scadenze nel portale e chiude il compito dell'emissione dello studio). La frase dice questo, e
 *   la chiave di traduzione con la stessa promessa, che nessuna pagina usava, non c'è più.
 *
 * Il giro di correzioni dopo lo scettico (tappa 5): la stessa promessa stava anche nella finestra dell'emissione (la casella «Rendi visibile e invia
 * notifiche», il suggerimento, il messaggio «Rate emesse e notificate correttamente ai condòmini.»), nel riquadro verde dopo «Pubblica nascoste», nella
 * guida del piano e nelle chiavi di traduzione. Nessuna rata manda una notifica o un'email: l'unico ascoltatore dell'evento chiude il compito dello
 * studio. Le frasi dicono che le rate si vedono nel portale.
 */

use App\Models\Evento;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../Gestionale/Beta52TotaliScenari.php';

const TNP_PAGINA_PIANO = 'resources/js/pages/gestionale/pianiRate/PianiRateShow.vue';

/** Le frasi di un file di traduzione, una per chiave, con la chiave scritta per intero. @return array<string, string> */
function tnpFrasi(string $file): array
{
    $frasi = [];
    $scendi = function (array $voci, string $prefisso) use (&$scendi, &$frasi) {
        foreach ($voci as $chiave => $valore) {
            is_array($valore) ? $scendi($valore, $prefisso . $chiave . '.') : $frasi[$prefisso . $chiave] = (string) $valore;
        }
    };
    $scendi(require base_path($file), '');

    return $frasi;
}

/** Il riquadro che si apre passando sopra «Pubblica nascoste», dal commento del pulsante alla sua chiusura. */
function tnpRiquadroPubblicaNascoste(): string
{
    $pagina = (string) file_get_contents(base_path(TNP_PAGINA_PIANO));
    $inizio = strpos($pagina, '<!-- PUBBLICA NASCOSTE -->');
    expect($inizio)->not->toBeFalse('Manca il pulsante «Pubblica nascoste» nella pagina del piano rate');

    return substr($pagina, $inizio, strpos($pagina, '</HoverCard>', $inizio) - $inizio);
}

it('nessuna frase del piano rate, in nessuna lingua, promette l\'app o le notifiche push', function (string $lingua) {
    $promesse = array_filter(tnpFrasi("lang/{$lingua}/gestionale.php"), fn (string $frase) => preg_match('/\bApp\b|\bPush\b/u', $frase) === 1);

    expect($promesse)->toBe([]);
})->with(['it', 'en', 'es', 'pt']);

it('la pagina del piano rate non promette l\'app né le notifiche push', function () {
    $pagina = (string) file_get_contents(base_path(TNP_PAGINA_PIANO));

    expect(preg_match_all('/\bApp\b|\bPush\b/u', $pagina))->toBe(0);
});

it('«Pubblica nascoste» dice che cosa fa: le rate nascoste diventano visibili nel portale dei condòmini, senza promettere un\'email', function () {
    $riquadro = tnpRiquadroPubblicaNascoste();

    expect($riquadro)->toContain('Rate in sospeso')
        ->and($riquadro)->toContain('<p>Ci sono rate emesse ma ancora <strong>nascoste</strong> ai condòmini.</p>')
        ->and($riquadro)->toContain('<p>Il pulsante le rende visibili nel portale dei condòmini.</p>')
        ->and(mb_strtolower($riquadro))->not->toContain('email')
        ->and(mb_strtolower($riquadro))->not->toContain('notific');
});

it('le altre due frasi della Coda dicono il vero, in italiano', function () {
    expect(__('impostazioni.header.general_settings_description', [], 'it'))->toBe('In questa pagina gestisci le impostazioni generali del programma')
        ->and(__('comunicazioni.guides.tracking_title', [], 'it'))->toBe('Letture')
        ->and(__('comunicazioni.guides.tracking_desc', [], 'it'))
        ->toBe('Per ogni comunicazione vedi chi ha confermato la presa visione e, se lo attivi nelle impostazioni generali, chi l\'ha aperta nel portale.');
});

it('la catena di «Pubblica nascoste»: le scadenze nascoste diventano visibili ai condòmini, e nessuna email parte', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = b52tAdmin();
    b52tSqliteJson();
    [$s, $piano] = b52tK3($this, emetti: true, notifiche: false);
    expect(b52tEvento($s, $piano, 'bice', 1)->visibility)->toBe('hidden');
    Notification::fake();
    Mail::fake();

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.publish-silent', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();

    expect(b52tEvento($s, $piano, 'bice', 1)->visibility)->toBe('private')
        ->and(b52tEvento($s, $piano, 'ugo', 1)->visibility)->toBe('private')
        ->and(Evento::where('tipo', 'scadenza_rata_condomino')->where('visibility', 'hidden')->count())->toBe(0);
    Notification::assertNothingSent();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

/** Il testo di un file con gli spazi ridotti a uno, per leggere una frase andata a capo. */
function tnpTesto(string $file): string
{
    return preg_replace('/\s+/u', ' ', (string) file_get_contents(base_path($file)));
}

it('la finestra dell\'emissione e il riquadro dopo «Pubblica nascoste» non promettono notifiche', function () {
    $pagina = tnpTesto(TNP_PAGINA_PIANO);

    expect($pagina)->not->toContain('Rendi visibile e invia notifiche')
        ->and($pagina)->not->toContain('non riceveranno notifiche')
        ->and($pagina)->not->toContain('le notifiche sono state inviate')
        ->and($pagina)->toContain('Rendi visibili ai condòmini')
        ->and($pagina)->toContain('Le rate sono ora visibili ai condòmini nel portale.');
});

it('la guida del piano dice che «Pubblica nascoste» rende visibili le rate, senza notifiche', function () {
    $guida = tnpTesto('resources/js/components/guides/PianoRateDettaglioGuide.vue');

    expect($guida)->not->toContain('Rendi visibile e invia notifiche')
        ->and($guida)->not->toContain('invia le notifiche')
        ->and($guida)->toContain('«Rendi visibili ai condòmini»')
        ->and($guida)->toContain('le rende visibili a quel punto');
});

it('le chiavi di traduzione dell\'emissione e della pubblicazione non promettono notifiche, in nessuna lingua', function (string $lingua) {
    $frasi = tnpFrasi("lang/{$lingua}/gestionale.php");

    foreach (['publish_and_notify', 'silent_emission_line_2', 'publish_completed_description'] as $chiave) {
        $frase = collect($frasi)->first(fn ($f, $k) => str_ends_with($k, '.'.$chiave));
        expect($frase)->not->toBeNull()
            ->and(preg_match('/notific|notification|notificaç/iu', (string) $frase))->toBe(0, "{$lingua}: {$chiave} promette una notifica: {$frase}");
    }
})->with(['it', 'en', 'es', 'pt']);
