<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le tre card in testa alle impostazioni generali (`impostazioniGenerali.vue`): titoli e frasi nei file di lingua, in italiano, inglese, spagnolo e
 * portoghese, e le chiavi delle card di prima non ci sono più. La pagina le chiede con queste chiavi (`impostazioniGenerali.test.ts`).
 *
 * Le descrizioni delle righe dicono ciò che il programma fa: il nome nelle email (in testa e in firma vince il nome dello studio delle stampe,
 * `NomeNelleEmail`), il condominio aperto dopo l'accesso (solo per chi salva, `RedirectHelper`), i commenti in moderazione (chi dal pannello li può
 * approvare o pubblicare non aspetta, `RegoleCommenti::statoAllaNascita`).
 */

use Illuminate\Support\Facades\Lang;

const IGC_CARD = ['program_access', 'reports_communications', 'security'];

it('le tre card e la frase del menu dei condomini ci sono in ogni lingua; le chiavi di prima no', function (string $lingua) {
    foreach (IGC_CARD as $card) {
        foreach (['title', 'desc'] as $parte) {
            expect(Lang::hasForLocale("impostazioni.guides.{$card}_{$parte}", $lingua))->toBeTrue("Manca impostazioni.guides.{$card}_{$parte} in {$lingua}");
        }
    }
    expect(Lang::hasForLocale('impostazioni.placeholder.no_building', $lingua))->toBeTrue("Manca impostazioni.placeholder.no_building in {$lingua}");
    foreach (['language', 'access', 'errors_notice', 'two_factor_panel'] as $prima) {
        foreach (['title', 'desc'] as $parte) {
            expect(Lang::hasForLocale("impostazioni.guides.{$prima}_{$parte}", $lingua))->toBeFalse("impostazioni.guides.{$prima}_{$parte} è ancora in {$lingua}");
        }
    }
})->with(['it', 'en', 'es', 'pt']);

it('in italiano le card dicono le parole della bozza approvata', function () {
    expect(array_map(fn (string $c) => [__("impostazioni.guides.{$c}_title", [], 'it'), __("impostazioni.guides.{$c}_desc", [], 'it')], IGC_CARD))->toBe([
        ['Programma e accesso', 'Lingua, nome del programma, condominio aperto all\'ingresso e chi può registrarsi, con quale ruolo.'],
        ['Segnalazioni e comunicazioni', 'Moderazione di commenti e allegati, chi segue una segnalazione, le aperture delle comunicazioni.'],
        ['Sicurezza', 'La verifica in due passaggi per chi entra nel pannello e gli avvisi dalle pagine d\'errore.'],
    ]);
});

it('in italiano le descrizioni del nome, del condominio all\'ingresso e dei commenti dicono ciò che il programma fa', function () {
    expect(__('impostazioni.dialogs.app_name_settings_description', [], 'it'))->toBe(
        'Compare nella scheda del browser e nelle email di invito, di benvenuto e di sicurezza. In testa e in firma alle email compare solo se nelle impostazioni delle stampe non c\'è il nome dello studio.'
    )->and(__('impostazioni.dialogs.default_building_description', [], 'it'))->toBe(
        'Vale solo per te: acceso, dopo l\'accesso entri nel condominio predefinito invece che nella dashboard, se sei amministratore o collaboratore.'
    )->and(__('impostazioni.dialogs.select_building_description', [], 'it'))->toBe('Il condominio che si apre dopo il tuo accesso.')
        ->and(__('impostazioni.dialogs.commenti_segnalazioni_moderati_di_serie_description', [], 'it'))->toBe(
            'Vale per le segnalazioni nuove: i commenti aspettano l\'approvazione, tranne quelli di chi dal pannello li può approvare o pubblicare. Ogni segnalazione si cambia dalla sua scheda.'
        )->and(__('impostazioni.dialogs.commenti_comunicazioni_moderati_di_serie_description', [], 'it'))->toBe(
            'Vale per le comunicazioni nuove: i commenti aspettano l\'approvazione, tranne quelli di chi dal pannello li può approvare o pubblicare. Ogni comunicazione si cambia dalla sua scheda.'
        );
});

it('nelle altre lingue le stesse descrizioni non promettono un amministratore né le email ai condòmini, e il condominio all\'ingresso è di chi salva', function (string $lingua, array $vecchie, string $soloPerTe) {
    $descrizioni = array_map(fn (string $k) => __("impostazioni.dialogs.{$k}", [], $lingua), ['app_name_settings_description', 'default_building_description',
        'select_building_description', 'commenti_segnalazioni_moderati_di_serie_description', 'commenti_comunicazioni_moderati_di_serie_description']);
    foreach ($vecchie as $parola) {
        expect(implode(' ', $descrizioni))->not->toContain($parola);
    }
    expect($descrizioni[1])->toStartWith($soloPerTe);
})->with([
    ['en', ['unit owners', 'administrator approval', 'the user will be redirected'], 'Only for you'],
    ['es', ['propietarios', 'de un administrador', 'If enabled', 'Select the building'], 'Solo para ti'],
    ['pt', ['condóminos', 'de um administrador', 'o utilizador será redirecionado'], 'Só para si'],
]);

it('in spagnolo le righe del condominio all\'ingresso hanno il titolo in spagnolo', function () {
    expect(__('impostazioni.dialogs.default_building_title', [], 'es'))->toBe('Abrir una comunidad al iniciar sesión')
        ->and(__('impostazioni.dialogs.select_building_title', [], 'es'))->toBe('Comunidad predeterminada');
});
