<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: l'impostazione generale e i testi (Coda 594, decisioni 310 e 311)
 *
 * - **L'impostazione** «Registra quando una comunicazione viene aperta nel portale» è spenta di serie, si accende e si
 *   spegne dalla pagina delle impostazioni generali, e un salvataggio che non la manda la lascia com'era.
 * - **Spenta, le aperture già registrate restano.** Il pulsante «Cancella le aperture registrate» le toglie tutte e
 *   lascia le prese visione.
 * - **I testi** stanno in `lang/`, nelle quattro lingue. La frase della guida in testa all'elenco delle comunicazioni
 *   («Monitora lo stato di consegna e lettura…») non era vera e si riscrive. Le parole delle letture non fanno pensare
 *   a una notifica: non ha valore legale.
 */

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    lctPrepara($this);
});

/** Il modulo delle impostazioni generali com'è salvato oggi, con i campi dati in più. */
function lctModuloImpostazioni(array $campi = []): array
{
    $impostazioni = app(GeneralSettings::class);

    return array_merge([
        'user_frontend_registration' => (bool) $impostazioni->user_frontend_registration,
        'language' => 'it',
        'app_name' => 'Kondomanager',
        'open_condominio_on_login' => false,
        'default_condominio_id' => null,
        'default_user_role' => $impostazioni->default_user_role,
        'default_per_page' => 10,
    ], $campi);
}

function lctSalvaImpostazioni($test, array $campi = []): void
{
    $test->actingAs($test->admin)->post(route('impostazioni.generali.store'), lctModuloImpostazioni($campi))->assertSessionHasNoErrors();
}

/** I testi italiani, esatti. */
const LCT_TESTI = [
    'comunicazioni.guides.tracking_title' => 'Letture',
    'comunicazioni.guides.tracking_desc' => 'Per ogni comunicazione vedi chi ha confermato la presa visione e, se lo attivi nelle impostazioni generali, chi l\'ha aperta nel portale.',
    'comunicazioni.label.ask_acknowledgement' => 'Chiedi la presa visione',
    'comunicazioni.tooltip.ask_acknowledgement' => 'Sotto la comunicazione il condòmino trova il pulsante «Confermo di aver letto», e nella scheda vedi chi ha confermato e chi no. Non ha valore legale.',
    'comunicazioni.readings.title' => 'Letture',
    'comunicazioni.readings.no_legal_value' => 'È un\'informazione per lo studio: non ha valore legale.',
    'comunicazioni.readings.how_to_enable' => 'Per questa comunicazione non si registra niente. Per sapere chi l\'ha letta, chiedi la presa visione dalla modifica, oppure accendi «Registra quando una comunicazione viene aperta nel portale» nelle impostazioni generali.',
    'comunicazioni.readings.summary_opened' => 'Aperta da :count su :total',
    'comunicazioni.readings.summary_acknowledged' => 'Presa visione di :count su :total',
    'comunicazioni.readings.summary_no_access' => 'Senza accesso al portale: :count',
    'comunicazioni.readings.status_no_access' => 'Senza accesso al portale',
    'comunicazioni.readings.status_not_opened' => 'Non aperta',
    'comunicazioni.readings.status_not_acknowledged' => 'Non ha confermato',
    'comunicazioni.readings.status_opened' => 'Aperta il :date alle :time',
    'comunicazioni.readings.status_acknowledged' => 'Presa visione il :date alle :time',
    'comunicazioni.readings.filter_missing' => 'Chi manca',
    'comunicazioni.readings.nobody_missing' => 'Non manca nessuno.',
    'comunicazioni.readings.no_recipients' => 'Nessun destinatario: le letture si contano da quando la comunicazione esce.',
    'comunicazioni.readings.tracking_off_note' => 'La registrazione delle aperture è spenta: qui ci sono quelle registrate prima.',
    'comunicazioni.readings.page' => 'Pagina :page di :pages',
    'comunicazioni.readings.previous' => 'Precedente',
    'comunicazioni.readings.next' => 'Successiva',
    'comunicazioni.readings.list_opened' => 'Aperture: :count',
    'comunicazioni.readings.list_acknowledged' => 'Prese visione: :count',
    'comunicazioni.readings.portal_opened' => 'Lo studio vede che hai aperto questa comunicazione il :date alle :time.',
    'comunicazioni.readings.confirm_hint' => 'Lo studio chiede di confermare che hai letto questa comunicazione.',
    'comunicazioni.readings.confirm_button' => 'Confermo di aver letto',
    'comunicazioni.readings.confirmed_on' => 'Hai confermato la presa visione il :date alle :time.',
    'impostazioni.dialogs.comunicazioni_registra_aperture_title' => 'Registra quando una comunicazione viene aperta nel portale',
    'impostazioni.dialogs.comunicazioni_registra_aperture_description' => 'Con questa impostazione accesa il programma segna, per ogni condòmino, il giorno e l\'ora in cui apre una comunicazione nel portale. Lo vede solo lo studio, e il condòmino ne è avvisato sotto la comunicazione. Vale per tutte le comunicazioni. È diversa da «Chiedi la presa visione», che si sceglie per la singola comunicazione: lì è il condòmino a confermare di aver letto, premendo un pulsante.',
    'impostazioni.dialogs.comunicazioni_registra_aperture_privacy' => 'Registrare le aperture è un trattamento di dati delle persone: prima di accenderla conviene sentire chi segue la privacy dello studio.',
    'impostazioni.dialogs.comunicazioni_aperture_registrate' => 'Aperture registrate: :count',
    'impostazioni.dialogs.comunicazioni_cancella_aperture' => 'Cancella le aperture registrate',
    'impostazioni.dialogs.comunicazioni_cancella_aperture_conferma' => 'Le aperture registrate si cancellano per tutte le comunicazioni, e non si recuperano. Le prese visione restano.',
    'impostazioni.dialogs.comunicazioni_cancella_aperture_si' => 'Sì, cancella',
    'impostazioni.dialogs.comunicazioni_cancella_aperture_no' => 'Annulla',
    'impostazioni.success_delete_openings' => 'Aperture registrate cancellate.',
];

// --- La pagina delle impostazioni generali ----------------------------------------------------------------

test('la pagina delle impostazioni generali riceve l\'interruttore, spento di serie, e quante aperture sono registrate', function () {
    $props = lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')));

    expect($props[LCT_IMPOSTAZIONE] ?? null)->toBeFalse()
        ->and($props['aperture_registrate'] ?? null)->toBe(0);

    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    lctScrivi($this->c, $this->collaboratrice, null, '2026-11-11 09:05:00');

    $props = lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')));

    // Le aperture registrate sono due: la terza riga è una presa visione senza apertura.
    expect($props[LCT_IMPOSTAZIONE] ?? null)->toBeTrue()
        ->and($props['aperture_registrate'] ?? null)->toBe(2);
});

test('salvando la pagina l\'interruttore si accende e si spegne', function () {
    lctSalvaImpostazioni($this, [LCT_IMPOSTAZIONE => true]);
    expect(property_exists(GeneralSettings::class, LCT_IMPOSTAZIONE))->toBeTrue("Manca l'impostazione generale " . LCT_IMPOSTAZIONE)
        ->and(lctImpostazioneAccesa())->toBeTrue();

    lctSalvaImpostazioni($this, [LCT_IMPOSTAZIONE => false]);
    expect(lctImpostazioneAccesa())->toBeFalse();
});

test('un salvataggio che non manda il campo lo lascia com\'era', function () {
    lctImpostazione(true);

    lctSalvaImpostazioni($this);

    expect(lctImpostazioneAccesa())->toBeTrue();
});

test('spegnendo l\'impostazione dalla pagina le aperture già registrate restano', function () {
    lctImpostazione(true);
    lctApri($this, $this->mario, $this->c)->assertOk();

    lctSalvaImpostazioni($this, [LCT_IMPOSTAZIONE => false]);
    lctApri($this, $this->paolo, $this->c)->assertOk();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($this->c, $this->mario)->aperta_il)->toBe(LCT_ADESSO);
});

// --- «Cancella le aperture registrate» ---------------------------------------------------------------------

test('la rotta che cancella le aperture c\'è, e si chiama con DELETE', function () {
    expect(Route::has(LCT_ROTTA_CANCELLA))->toBeTrue('Manca la rotta ' . LCT_ROTTA_CANCELLA);

    expect(Route::getRoutes()->getByName(LCT_ROTTA_CANCELLA)->methods())->toBe(['DELETE']);
});

test('il pulsante cancella le aperture di tutte le comunicazioni e lascia le prese visione', function () {
    $altra = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Pulizia delle scale']));
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    lctScrivi($altra, $this->mario, '2026-11-11 10:00:00');
    lctScrivi($altra, $this->collaboratrice, null, '2026-11-11 10:05:00');

    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA))->assertRedirect()->assertSessionHasNoErrors();

    // Restano le due righe con una presa visione, senza l'apertura; le due con la sola apertura non ci sono più.
    expect(lctRighe())->toHaveCount(2)
        ->and(lctRiga($this->c, $this->mario))->toBeNull()
        ->and(lctRiga($altra, $this->mario))->toBeNull()
        ->and(lctRiga($this->c, $this->paolo)->aperta_il)->toBeNull()
        ->and(lctRiga($this->c, $this->paolo)->presa_visione_il)->toBe('2026-11-11 09:05:00')
        ->and(lctRiga($altra, $this->collaboratrice)->presa_visione_il)->toBe('2026-11-11 10:05:00')
        ->and(json_encode(session()->all(), JSON_UNESCAPED_UNICODE))->toContain('Aperture registrate cancellate.');

    expect(lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')))['aperture_registrate'] ?? null)->toBe(0);
});

test('cancellare le aperture non accende e non spegne l\'impostazione', function (bool $accesa) {
    lctImpostazione($accesa);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA))->assertSessionHasNoErrors();

    expect(lctImpostazioneAccesa())->toBe($accesa)
        ->and(lctRighe())->toHaveCount(0);
})->with(['accesa' => [true], 'spenta' => [false]]);

test('chi non può aprire le impostazioni generali non cancella niente', function () {
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00');

    $this->actingAs($this->mario)->delete(route(LCT_ROTTA_CANCELLA))->assertForbidden();

    expect(lctRiga($this->c, $this->paolo)->aperta_il)->toBe('2026-11-11 09:00:00');
});

// --- I testi ----------------------------------------------------------------------------------------------

test('i testi ci sono nelle quattro lingue, e in italiano sono quelli decisi', function () {
    foreach (LCT_TESTI as $chiave => $italiano) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
        expect(__($chiave, [], 'it'))->toBe($italiano);
    }
});

test('nelle altre tre lingue i testi sono tradotti, non lasciati in italiano', function () {
    // «Annulla» in portoghese è «Anular»/«Cancelar»: tutte le chiavi hanno una traduzione diversa dall'italiano.
    foreach (LCT_TESTI as $chiave => $italiano) {
        foreach (['en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
            expect(__($chiave, [], $lingua))->not->toBe($italiano, "{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

test('la frase della guida in testa all\'elenco non promette più consegna e lettura', function () {
    $vecchie = [
        'en' => ['Delivery Tracking', 'Monitor the delivery and read status of every communication sent to residents.'],
        'es' => ['Delivery Tracking', 'Monitor the delivery and read status of every communication sent to residents.'],
        'pt' => ['Rastreio de Envios', 'Monitorize o estado de entrega e leitura de cada comunicação enviada aos residentes.'],
        'it' => ['Tracciamento Invii', 'Monitora lo stato di consegna e lettura di ogni comunicazione inviata ai condomini.'],
    ];

    foreach ($vecchie as $lingua => [$titolo, $frase]) {
        expect(__('comunicazioni.guides.tracking_title', [], $lingua))->not->toBe($titolo, "Il titolo della guida in {$lingua} è quello di prima")
            ->and(__('comunicazioni.guides.tracking_desc', [], $lingua))->not->toBe($frase, "La frase della guida in {$lingua} è quella di prima");
    }
    // Lo spagnolo era rimasto in inglese: il titolo nuovo non è quello inglese.
    expect(__('comunicazioni.guides.tracking_title', [], 'es'))->not->toBe(__('comunicazioni.guides.tracking_title', [], 'en'));
});

test('le parole delle letture non fanno pensare a una notifica', function () {
    $letture = Lang::get('comunicazioni.readings', [], 'it');
    expect($letture)->toBeArray('Manca il gruppo comunicazioni.readings');

    foreach ($letture as $chiave => $testo) {
        expect(preg_match('/notific|consegn|ricevut|recapit/iu', (string) $testo))->toBe(0, "comunicazioni.readings.{$chiave} parla di notifica o consegna: «{$testo}»");
    }
    expect(preg_match('/notific|consegn|ricevut|recapit/iu', __('comunicazioni.guides.tracking_desc', [], 'it')))->toBe(0);
});

test('le pagine prendono i testi delle letture da lang, non li scrivono', function () {
    $file = [
        'resources/js/components/comunicazioni/LettureComunicazione.vue',
        'resources/js/components/comunicazioni/PresaVisione.vue',
        'resources/js/components/comunicazioni/columns.ts',
        'resources/js/pages/comunicazioni/ComunicazioniNew.vue',
        'resources/js/pages/comunicazioni/ComunicazioniEdit.vue',
        'resources/js/pages/impostazioni/impostazioniGenerali.vue',
    ];
    $usate = [];
    foreach ($file as $percorso) {
        expect(file_exists(base_path($percorso)))->toBeTrue("Manca {$percorso}");
        $testo = file_get_contents(base_path($percorso));
        preg_match_all('/[\'"`]((?:comunicazioni\.readings|comunicazioni\.label\.ask|comunicazioni\.tooltip\.ask|impostazioni\.dialogs\.comunicazioni)[a-z_.]*)[\'"`]/', $testo, $trovate);
        $usate = array_merge($usate, $trovate[1]);

        // Nessuna delle frasi italiane è scritta nel file della pagina.
        foreach (['Confermo di aver letto', 'Chi manca', 'Senza accesso al portale', 'Cancella le aperture', 'Chiedi la presa visione', 'Aperture registrate'] as $frase) {
            expect(str_contains($testo, $frase))->toBeFalse("«{$frase}» è scritta in {$percorso}: va in lang, nelle quattro lingue");
        }
    }

    $usate = array_values(array_unique($usate));
    expect(count($usate))->toBeGreaterThanOrEqual(20, 'Le pagine usano poche chiavi delle letture: ' . implode(', ', $usate));
    foreach ($usate as $chiave) {
        // Una chiave che finisce con «_» o con «.» è composta nella pagina (per esempio lo stato): si controllano le sue figlie.
        if (str_ends_with($chiave, '_') || str_ends_with($chiave, '.')) {
            continue;
        }
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("La pagina usa {$chiave}, che manca in {$lingua}");
        }
    }
});
