<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti, il giro di correzioni 1 (Code 559 e 558; decisioni 281 e 282)
 *
 * - **«Forza moderazione commenti» non c'è più** (C2, decisione 282.2). Chi l'aveva accesa non perde la moderazione: una
 *   migrazione porta il valore nelle due impostazioni «di serie» e nelle segnalazioni che ci sono già, poi toglie
 *   l'impostazione. Rieseguita non tocca le scelte fatte dopo.
 * - **Un commento approvato e poi modificato torna in attesa** (C3, decisione 282.3), se la discussione è in moderazione
 *   e chi modifica non è dello studio; lo studio riceve un'email sua, «commento modificato» (decisione 285), che prova
 *   `CommentiCorrezioni2Test`.
 * - **L'Inbox chiama la comunicazione con il suo oggetto** (C5), non «Comunicazione #303».
 * - **Le email ripetono la guardia quando partono** (C6): un commento nascosto o eliminato dopo, o un destinatario che non
 *   vede più la discussione, non ricevono l'email.
 * - **Approvare e nascondere guardano lo stato** (C12): approvare un commento già pubblicato non rimanda le email. Un
 *   commento nascosto non si modifica; in una discussione chiusa non si modifica, ma si può eliminare.
 * - **Ogni discussione dice il suo tipo** nelle props (C11), perché la pagina dica quando è privata con lo studio: dalla
 *   decisione 458 la discussione unica dei palazzi (`tutti`) o quella con una persona.
 * - **Le migrazioni della moderazione delle segnalazioni** (decisione 281) si riprendono.
 * - **Ogni testo delle pagine dei commenti è tradotto** (C4): nessuna chiave a video.
 */

use App\Models\Commento;
use App\Models\Evento;
use App\Notifications\Commenti\CommentoApprovatoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
});

const CCM_MIGRAZIONE_FORZA = 'settings/2026_11_11_120000_porta_forza_moderazione_commenti_nelle_impostazioni_nuove.php';

function ccmImpostazione(string $nome): mixed
{
    $riga = DB::table('settings')->where('group', 'general')->where('name', $nome)->first();

    return $riga === null ? null : json_decode($riga->payload, true);
}

function ccmScriviImpostazione(string $nome, bool $valore): void
{
    DB::table('settings')->updateOrInsert(
        ['group' => 'general', 'name' => $nome],
        ['payload' => json_encode($valore), 'locked' => false, 'created_at' => now(), 'updated_at' => now()]
    );
}

// --- C2: «Forza moderazione commenti» --------------------------------------------------------------

test('chi aveva «Forza moderazione commenti» accesa la ritrova nelle due impostazioni e nelle segnalazioni che ci sono già', function () {
    $path = database_path(CCM_MIGRAZIONE_FORZA);
    expect(file_exists($path))->toBeTrue('Manca la migrazione che porta «Forza moderazione commenti» nelle impostazioni nuove');

    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($s, false);
    ccmScriviImpostazione('force_comment_moderation', true);
    ccmScriviImpostazione('commenti_segnalazioni_moderati_di_serie', false);
    ccmScriviImpostazione('commenti_comunicazioni_moderati_di_serie', false);

    $migrazione = require $path;
    $migrazione->up();

    expect(ccmImpostazione('commenti_segnalazioni_moderati_di_serie'))->toBeTrue()
        ->and(ccmImpostazione('commenti_comunicazioni_moderati_di_serie'))->toBeTrue()
        ->and((bool) DB::table('segnalazioni')->where('id', $s->id)->value('commenti_moderati'))->toBeTrue()
        ->and(DB::table('settings')->where('group', 'general')->where('name', 'force_comment_moderation')->exists())->toBeFalse();

    // Rieseguita dopo un'interruzione, non riaccende ciò che lo studio ha spento nel frattempo.
    ccmModerazione($s, false);
    ccmScriviImpostazione('commenti_segnalazioni_moderati_di_serie', false);
    $migrazione->up();

    expect((bool) DB::table('segnalazioni')->where('id', $s->id)->value('commenti_moderati'))->toBeFalse()
        ->and(ccmImpostazione('commenti_segnalazioni_moderati_di_serie'))->toBeFalse();
});

test('chi aveva «Forza moderazione commenti» spenta resta com\'è, e l\'impostazione sparisce', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($s, false);
    ccmScriviImpostazione('force_comment_moderation', false);
    ccmScriviImpostazione('commenti_segnalazioni_moderati_di_serie', false);
    ccmScriviImpostazione('commenti_comunicazioni_moderati_di_serie', true);

    (require database_path(CCM_MIGRAZIONE_FORZA))->up();

    expect(ccmImpostazione('commenti_segnalazioni_moderati_di_serie'))->toBeFalse()
        ->and(ccmImpostazione('commenti_comunicazioni_moderati_di_serie'))->toBeTrue()
        ->and((bool) DB::table('segnalazioni')->where('id', $s->id)->value('commenti_moderati'))->toBeFalse()
        ->and(DB::table('settings')->where('group', 'general')->where('name', 'force_comment_moderation')->exists())->toBeFalse();
});

test('«Forza moderazione commenti» non c\'è più: né fra le impostazioni, né nella pagina, né nelle regole', function () {
    expect(property_exists(\App\Settings\GeneralSettings::class, 'force_comment_moderation'))->toBeFalse(
        'GeneralSettings ha ancora la proprietà force_comment_moderation'
    )
        ->and(DB::table('settings')->where('group', 'general')->where('name', 'force_comment_moderation')->exists())->toBeFalse()
        ->and(file_get_contents(app_path('Support/Commenti/RegoleCommenti.php')))->not->toContain('force_comment_moderation');

    $this->withoutVite();
    $props = $this->actingAs($this->admin)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];
    expect($props)->not->toHaveKey('force_comment_moderation')
        ->and($props)->toHaveKey('commenti_segnalazioni_moderati_di_serie')
        ->and($props)->toHaveKey('commenti_comunicazioni_moderati_di_serie');
});

// --- La moderazione delle segnalazioni (decisione 281): le migrazioni ---------------------------------

test('la migrazione della moderazione delle segnalazioni, rieseguita, non cambia lo schema; quelle che ci sono già restano senza moderazione', function () {
    $path = database_path('migrations/2026_11_11_110000_add_commenti_moderati_to_segnalazioni.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata');
    $colonne = fn () => collect(Schema::getColumnListing('segnalazioni'))->sort()->values()->all();

    $id = DB::table('segnalazioni')->insertGetId([
        'subject' => 'Vecchia', 'description' => 'x', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'created_by' => $this->mario->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $prima = $colonne();
    (require $path)->up();

    expect($colonne())->toBe($prima)
        ->and(Schema::hasColumn('segnalazioni', 'commenti_moderati'))->toBeTrue()
        ->and((bool) DB::table('segnalazioni')->where('id', $id)->value('commenti_moderati'))->toBeFalse();
});

test('l\'impostazione delle segnalazioni nasce spenta, e la sua migrazione rieseguita non tocca un valore già scritto', function () {
    $path = database_path('settings/2026_11_11_110100_add_commenti_segnalazioni_moderati_di_serie_to_general_settings.php');
    expect(file_exists($path))->toBeTrue('Manca la migrazione dell\'impostazione');
    expect(ccmImpostazione('commenti_segnalazioni_moderati_di_serie'))->toBeFalse();

    ccmScriviImpostazione('commenti_segnalazioni_moderati_di_serie', true);
    (require $path)->up();

    expect(DB::table('settings')->where('group', 'general')->where('name', 'commenti_segnalazioni_moderati_di_serie')->count())->toBe(1)
        ->and(ccmImpostazione('commenti_segnalazioni_moderati_di_serie'))->toBeTrue();
});

// --- C3: il commento approvato e poi modificato --------------------------------------------------------

test('un commento approvato, modificato dal suo autore in una discussione in moderazione, torna in attesa e avvisa chi approva', function () {
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Grazie', $this->aurora);

    $this->actingAs($this->mario)->patch(route('user.commenti.update', $id), ['corpo' => 'Testo riscritto dopo l\'approvazione'])->assertSessionHasNoErrors();

    $commento = Commento::find($id);
    expect($commento->corpo)->toBe('Testo riscritto dopo l\'approvazione')
        ->and($commento->stato)->toBe('in_attesa')
        ->and(ccmDestinatari('App\\Notifications\\Commenti\\CommentoModificatoInAttesaNotification'))->toBe(ccmIds($this->admin))
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe([]);

    // Il vicino dello stesso palazzo non lo legge più finché non è approvato di nuovo.
    $this->withoutVite();
    $corpi = collect(ccmDiscussione(ccmScheda($this, $this->paolo, $this->c), ccmChiaveUnica())['commenti'])->pluck('corpo')->all();
    expect($corpi)->toBe([]);
});

test('con la moderazione spenta, o quando modifica lo studio, il commento modificato resta pubblicato', function () {
    $diMario = ccmCommentoSalvato($this->c, $this->mario, 'Di Mario', $this->aurora);
    $diAnna = ccmCommentoSalvato($this->c, $this->collaboratrice, 'Di Anna', $this->aurora);

    // Lo studio corregge il commento di un condòmino: è già una moderazione.
    $this->actingAs($this->admin)->patch(route('admin.commenti.update', $diMario), ['corpo' => 'Corretto dallo studio']);
    // La collaboratrice esce sempre subito: anche il suo commento modificato.
    $this->actingAs($this->collaboratrice)->patch(route('admin.commenti.update', $diAnna), ['corpo' => 'Di Anna, corretto']);
    expect(Commento::find($diMario)->stato)->toBe('pubblicato')
        ->and(Commento::find($diAnna)->stato)->toBe('pubblicato');

    ccmModerazione($this->c, false);
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $diMario), ['corpo' => 'Riscritto da Mario']);
    expect(Commento::find($diMario)->corpo)->toBe('Riscritto da Mario')
        ->and(Commento::find($diMario)->stato)->toBe('pubblicato')
        ->and(ccmDestinatari(CommentoInAttesaNotification::class))->toBe([])
        ->and(ccmDestinatari('App\\Notifications\\Commenti\\CommentoModificatoInAttesaNotification'))->toBe([]);
});

test('anche in una segnalazione in moderazione il commento modificato torna in attesa', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario);
    ccmModerazione($s, true);
    $id = ccmCommentoSalvato($s, $this->paolo, 'Anche da me', $this->aurora);

    $this->actingAs($this->paolo)->patch(route('user.commenti.update', $id), ['corpo' => 'Riscritto']);

    expect(Commento::find($id)->corpo)->toBe('Riscritto')
        ->and(Commento::find($id)->stato)->toBe('in_attesa');
});

// --- C5: l'Inbox ---------------------------------------------------------------------------------------

test('l\'Inbox chiama la comunicazione con il suo oggetto', function () {
    ccmScrivi($this, $this->mario, $this->c, ccmChiaveUnica(), 'Si può spostare?')->assertSessionHasNoErrors();

    $evento = Evento::query()->where('tipo', 'da_moderare')
        ->where('eventable_type', $this->c->getMorphClass())->where('eventable_id', $this->c->id)->first();

    // Il titolo dice i numeri («1 commento in attesa»); la riga sotto, la comunicazione con il suo oggetto.
    expect($evento)->not->toBeNull()
        ->and($evento->title)->toBe('1 commento in attesa')
        ->and($evento->description)->toContain('Lavori al tetto')
        ->and($evento->title . ' ' . $evento->description)->not->toContain('Comunicazione #');
});

// --- C6: la guardia quando l'email parte -----------------------------------------------------------------

test('l\'email di un commento nuovo non parte se nel frattempo il commento è stato nascosto o eliminato, o chi la riceve non vede più la discussione', function () {
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Grazie', $this->aurora);
    $parte = fn ($user) => (new NuovoCommentoNotification(Commento::withTrashed()->find($id)))->shouldSend($user->fresh(), 'mail');

    expect(method_exists(NuovoCommentoNotification::class, 'shouldSend'))->toBeTrue('NuovoCommentoNotification non ha shouldSend');
    expect($parte($this->paolo))->toBeTrue();

    DB::table('commenti')->where('id', $id)->update(['stato' => 'nascosto']);
    expect($parte($this->paolo))->toBeFalse('Il commento è nascosto: l\'email non deve partire');

    DB::table('commenti')->where('id', $id)->update(['stato' => 'pubblicato']);
    // La discussione è una sola per tutti i palazzi (decisione 458): uscito da Aurora, Paolo la vede ancora da Borea.
    $this->paolo->anagrafica->condomini()->detach($this->aurora->id);
    expect($parte($this->paolo))->toBeTrue('Paolo sta ancora in Borea: vede ancora la discussione unica');
    $this->paolo->anagrafica->condomini()->detach($this->borea->id);
    expect($parte($this->paolo))->toBeFalse('Paolo non sta più in nessuno dei palazzi: non vede più la discussione');
    expect($parte($this->admin))->toBeTrue();

    Commento::find($id)->delete();
    expect($parte($this->admin))->toBeFalse('Il commento è eliminato: l\'email non deve partire');
});

test('l\'email «in attesa» non parte se il commento è già stato approvato; quella «approvato» non parte se è stato nascosto', function () {
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Si può spostare?', $this->aurora, null, 'in_attesa');
    $commento = fn () => Commento::find($id);

    expect(method_exists(CommentoInAttesaNotification::class, 'shouldSend'))->toBeTrue('CommentoInAttesaNotification non ha shouldSend')
        ->and(method_exists(CommentoApprovatoNotification::class, 'shouldSend'))->toBeTrue('CommentoApprovatoNotification non ha shouldSend');

    expect((new CommentoInAttesaNotification($commento()))->shouldSend($this->admin, 'mail'))->toBeTrue()
        ->and((new CommentoInAttesaNotification($commento()))->shouldSend($this->collaboratrice, 'mail'))->toBeFalse('La collaboratrice non può approvare');

    DB::table('commenti')->where('id', $id)->update(['stato' => 'pubblicato']);
    expect((new CommentoInAttesaNotification($commento()))->shouldSend($this->admin, 'mail'))->toBeFalse()
        ->and((new CommentoApprovatoNotification($commento()))->shouldSend($this->mario, 'mail'))->toBeTrue();

    DB::table('commenti')->where('id', $id)->update(['stato' => 'nascosto']);
    expect((new CommentoApprovatoNotification($commento()))->shouldSend($this->mario, 'mail'))->toBeFalse();
});

// --- C12: lo stato del commento e la discussione chiusa ---------------------------------------------------

test('approvare un commento già pubblicato è rifiutato e non rimanda le email', function () {
    ccmCommentoSalvato($this->c, $this->paolo, 'Ci sarò', $this->aurora);
    $id = ccmCommentoSalvato($this->c, $this->mario, 'Già pubblicato', $this->aurora);

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.approva', $id));

    expect($risposta->status())->toBe(403)
        ->and(Commento::find($id)->moderato_da)->toBeNull()
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toBe([])
        ->and(ccmDestinatari(CommentoApprovatoNotification::class))->toBe([]);
});

test('un commento nascosto non si modifica; in una discussione chiusa il proprio commento non si modifica, ma si elimina', function () {
    $nascosto = ccmCommentoSalvato($this->c, $this->mario, 'Nascosto', $this->aurora, null, 'nascosto');
    $pubblicato = ccmCommentoSalvato($this->c, $this->mario, 'Pubblicato', $this->aurora);

    $this->actingAs($this->mario)->patch(route('user.commenti.update', $nascosto), ['corpo' => 'Riscritto']);
    expect(Commento::find($nascosto)->corpo)->toBe('Nascosto');

    $this->c->update(['can_comment' => false]);
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $pubblicato), ['corpo' => 'Riscritto a discussione chiusa']);
    expect(Commento::find($pubblicato)->corpo)->toBe('Pubblicato');

    $this->actingAs($this->mario)->delete(route('user.commenti.destroy', $pubblicato));
    expect(Commento::find($pubblicato))->toBeNull('Il proprio commento si deve poter eliminare anche a discussione chiusa');
});

// --- C11: il tipo della discussione nelle props ------------------------------------------------------------

test('ogni discussione dice nelle props se è quella unica dei palazzi o con una persona', function () {
    $this->withoutVite();
    $persone = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => 'Il suo sollecito'], [$this->mario, $this->lucia]);

    expect(collect(ccmScheda($this, $this->paolo, $this->c)['discussioni'])->pluck('tipo')->all())->toBe(['tutti'])
        ->and(collect(ccmScheda($this, $this->mario, $persone)['discussioni'])->pluck('tipo')->all())->toBe(['persona'])
        ->and(collect(ccmScheda($this, $this->admin, $persone, 'admin')['discussioni'])->pluck('tipo')->all())->toBe(['persona', 'persona']);
});

// --- C4: i testi delle pagine --------------------------------------------------------------------------------

test('ogni testo usato dalle pagine dei commenti, delle comunicazioni e delle segnalazioni è tradotto in italiano', function () {
    $cartelle = [
        'js/components/commenti', 'js/pages/comunicazioni', 'js/pages/segnalazioni',
        'js/components/comunicazioni', 'js/components/segnalazioni',
    ];
    $mancanti = [];
    foreach ($cartelle as $cartella) {
        $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($cartella), FilesystemIterator::SKIP_DOTS));
        foreach ($file as $f) {
            if (! preg_match('/\.(vue|ts)$/', $f->getFilename()) || str_ends_with($f->getFilename(), '.test.ts')) {
                continue;
            }
            preg_match_all('/\btrans(?:Choice)?\(\s*[\'"]([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)[\'"]/', file_get_contents($f->getPathname()), $m);
            foreach (array_unique($m[1]) as $chiave) {
                if (! Lang::has($chiave, 'it', false)) {
                    $mancanti[$chiave] = $f->getFilename();
                }
            }
        }
    }
    ksort($mancanti);

    expect($mancanti)->toBe([], "Chiavi senza il testo in lang/it (a video esce la chiave):\n" . collect($mancanti)->map(fn ($f, $k) => "{$k} ({$f})")->implode("\n"));
});
