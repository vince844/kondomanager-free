<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La Coda 683 (decisione 495): quattro piccoli difetti degli allegati
 *
 * 1. I quattro moduli di creazione (segnalazione e comunicazione, pannello e portale) ricevono dal server il limite vero di un file e i
 *    formati, come il riquadro della scheda: prima scrivevano «10 MB» fisso, anche con il server a 20 MB.
 * 2. Il sottotitolo di «Impostazioni generali» è in italiano; le scritte dell'intestazione delle impostazioni non restano in inglese nelle
 *    altre lingue.
 * 3. Le preferenze email degli allegati seguono i permessi dei due tipi: chi approva solo gli allegati delle comunicazioni vede «Allegato
 *    da approvare» fra le preferenze, e con la preferenza accesa riceve l'email; chi carica solo allegati delle comunicazioni vede
 *    «Allegato approvato» e «Allegato eliminato».
 * 4. Il riquadro della scheda riceve quanti file ha l'elemento in tutto (`allegati_config.totale`), così «N / massimo» e i posti rimasti
 *    dicono la stessa cosa; al condòmino arriva il numero, non i file che non vede.
 *
 * Che cosa non copre: come la pagina scrive il limite e il numero (lo provano i vitest dei moduli e del riquadro), l'invio vero dell'email
 * (la notifica è finta), i limiti che il server legge da `php.ini` (li prova il test di `LimiteCaricamento`).
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\Allegati\RegoleAllegati;
use App\Support\LimiteCaricamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    algPrepara($this);
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
});

test('1. i quattro moduli di creazione ricevono il limite vero di un file e i formati, come la scheda', function () {
    $pagine = [
        [$this->admin, 'admin.segnalazioni.create'],
        [$this->admin, 'admin.comunicazioni.create'],
        [$this->mario, 'user.segnalazioni.create'],
        [$this->mario, 'user.comunicazioni.create'],
    ];
    foreach ($pagine as [$chi, $rotta]) {
        $risposta = $this->actingAs($chi)->get(route($rotta));
        if ($risposta->status() === 403) {
            continue; // il portale delle comunicazioni può non avere il modulo per chi non ha il permesso: qui conta chi lo apre
        }
        $props = $risposta->assertOk()->viewData('page')['props'];
        expect($props['allegati_limite_file'] ?? null)->toBe(LimiteCaricamento::etichetta(), "{$rotta}: il limite")
            ->and($props['allegati_formati'] ?? null)->toBe(RegoleAllegati::FORMATI, "{$rotta}: i formati")
            ->and($props['allegati_massimo'])->toBe(RegoleAllegati::massimoPerElemento());
    }
    // Lo stesso limite della scheda.
    $s = algSegnalazione($this->aurora, $this->admin);
    expect(algScheda($this, $this->admin, $s, 'admin')['allegati_config']['limite_file'])->toBe(LimiteCaricamento::etichetta());
});

test('2. il sottotitolo di «Impostazioni generali» è in italiano, e l\'intestazione delle impostazioni non resta in inglese nelle altre lingue', function () {
    expect(__('impostazioni.header.general_settings_description', [], 'it'))->not->toContain('On this page')
        ->and(__('impostazioni.header.general_settings_description', [], 'it'))->toContain('impostazioni generali');

    $inglese = Lang::get('impostazioni.header', [], 'en');
    foreach (['it', 'es', 'pt'] as $lingua) {
        $intestazione = Lang::get('impostazioni.header', [], $lingua);
        foreach ($inglese as $chiave => $testo) {
            expect($intestazione[$chiave] ?? null)->not->toBe($testo, "impostazioni.header.{$chiave} in {$lingua} è rimasta in inglese");
        }
    }
});

test('3. chi approva solo gli allegati delle comunicazioni vede «Allegato da approvare» e, accesa, riceve l\'email', function () {
    $ruolo = algRuolo('Moderatrice bacheca', [
        Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_COMUNICAZIONI->value,
        Permission::APPROVE_ATTACHMENTS_COMUNICAZIONI->value, Permission::CREATE_ATTACHMENTS_COMUNICAZIONI->value, Permission::VIEW_ATTACHMENTS_COMUNICAZIONI->value,
    ]);
    $paola = User::factory()->create(['email_verified_at' => now(), 'name' => 'Paola Bacheca', 'email' => 'paola.bacheca@esempio.invalid']);
    $paola->assignRole($ruolo);

    $visibili = array_keys(app(NotificationPreferenceService::class)->getVisibleNotificationTypes($paola->fresh()));
    expect($visibili)->toContain(NotificationType::ATTACHMENT_UNDER_MODERATION->value)
        ->and($visibili)->toContain(NotificationType::ATTACHMENT_APPROVED->value)
        ->and($visibili)->toContain(NotificationType::ATTACHMENT_DELETED->value);
    // La pagina delle preferenze la mostra.
    $pagina = $this->actingAs($paola)->get(route('admin.settings.notifications.index'))->assertOk()->viewData('page')['props']['preferences'];
    expect(collect($pagina)->pluck('type')->all())->toContain(NotificationType::ATTACHMENT_UNDER_MODERATION->value);

    // Accesa dalla pagina, l'allegato in attesa di una comunicazione le arriva.
    $this->actingAs($paola)->put(route('admin.settings.notifications.update'), ['preferences' => [
        ['type' => NotificationType::ATTACHMENT_UNDER_MODERATION->value, 'enabled' => true],
    ]])->assertSessionHasNoErrors();
    $c = algComunicazione([$this->aurora], $this->admin);
    $inAttesa = \App\Models\Documento::findOrFail(algAllegatoSalvato($c, $this->mario, 'verbale.pdf', false));
    app(\App\Services\Allegati\AllegatoNotificationService::class)->dopoCaricamento($this->mario, $c, collect([$inAttesa]));
    Notification::assertSentTo($paola, ALG_MAIL_IN_ATTESA);

    // Chi approva solo le segnalazioni continua a vederla; chi non approva niente no.
    $soloSegnalazioni = User::factory()->create(['email_verified_at' => now()]);
    $soloSegnalazioni->assignRole(algRuolo('Moderatore guasti', [Permission::ACCESS_ADMIN_PANEL->value, Permission::APPROVE_ATTACHMENTS_SEGNALAZIONI->value]));
    expect(array_keys(app(NotificationPreferenceService::class)->getVisibleNotificationTypes($soloSegnalazioni->fresh())))
        ->toContain(NotificationType::ATTACHMENT_UNDER_MODERATION->value);
    expect(array_keys(app(NotificationPreferenceService::class)->getVisibleNotificationTypes($this->mario)))
        ->not->toContain(NotificationType::ATTACHMENT_UNDER_MODERATION->value);
});

test('4. la scheda dice quanti file ha l\'elemento in tutto: «N / massimo» e i posti rimasti tornano, anche per il condòmino che ne vede meno', function () {
    $s = algSegnalazione($this->aurora, $this->mario, [], [$this->mario]);
    algAllegatoSalvato($s, $this->mario, 'mia.jpg');
    // Due file dello studio non ancora approvati: il condòmino non li vede.
    algAllegatoSalvato($s, $this->admin, 'riservato-1.pdf', false);
    algAllegatoSalvato($s, $this->admin, 'riservato-2.pdf', false);

    $delCondomino = algScheda($this, $this->mario, $s);
    expect($delCondomino['allegati'])->toHaveCount(1)
        ->and($delCondomino['allegati_config']['totale'] ?? null)->toBe(3)
        ->and($delCondomino['allegati_config']['rimasti'])->toBe(RegoleAllegati::massimoPerElemento() - 3);
    // Nessun nome né id dei file che non vede.
    expect(json_encode($delCondomino))->not->toContain('riservato-1')->not->toContain('riservato-2');

    $delloStudio = algScheda($this, $this->admin, $s, 'admin');
    expect($delloStudio['allegati'])->toHaveCount(3)
        ->and($delloStudio['allegati_config']['totale'])->toBe(3);
});
