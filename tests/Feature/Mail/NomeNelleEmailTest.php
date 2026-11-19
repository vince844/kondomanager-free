<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Il titolo e la firma delle email con il nome dello studio, e il piede fisso (decisione 490)
 *
 * Il titolo in alto e la firma di serie («Cordiali saluti,» e il nome) dicono il nome dello studio della pagina delle stampe, se è scritto; altrimenti il nome
 * dell'applicazione delle impostazioni generali se l'amministratore l'ha cambiato; altrimenti «KondoManager». Il piede è sempre «Inviata con KondoManager,
 * software open source per il condominio». Il nome di serie «Kondomanager» diventa «KondoManager», anche nelle impostazioni (una migrazione delle impostazioni,
 * solo dove vale esattamente quello). Le email con una chiusura loro (lettere, ricevute, fogli del conguaglio, avviso di compensazione) non cambiano.
 *
 * Non coprono: l'invio vero né l'aspetto nel programma di posta; l'installatore a video.
 */

use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PIEDE_DELLE_EMAIL = 'Inviata con KondoManager, software open source per il condominio';

function neStudio(?string $nome): void
{
    $stampe = app(PrintSettings::class);
    $stampe->studio_nome = $nome;
    $stampe->save();
}

/** La email, nelle due forme: [html, solo testo]. */
function neRese(?MailMessage $mail = null): array
{
    $mail ??= (new MailMessage)->subject('Prova')->line('Una riga.');
    $testo = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    $html = (string) $mail->render();

    return [$html, preg_replace('/[ \t]+/', ' ', $testo)];
}

test('con il nome dello studio il titolo e la firma lo dicono, in HTML e in solo testo; il piede resta quello fisso', function () {
    neStudio('Studio Bianchi');

    [$html, $testo] = neRese();

    expect($html)->toContain('Studio Bianchi')
        ->and(substr_count($html, 'Studio Bianchi'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*Studio Bianchi/')
        ->and($html)->toContain(PIEDE_DELLE_EMAIL)
        ->and($html)->not->toContain('Kondomanager');
    // Solo testo: il titolo in cima, la firma e il piede.
    expect(trim($testo))->toStartWith('Studio Bianchi')
        ->and($testo)->toContain('Cordiali saluti,')
        ->and($testo)->toContain('Studio Bianchi')
        ->and(substr_count($testo, 'Studio Bianchi'))->toBeGreaterThanOrEqual(2)
        ->and($testo)->toContain(PIEDE_DELLE_EMAIL);
});

test('senza il nome dello studio il titolo e la firma dicono «KondoManager» (anche con il vecchio nome di serie), e il piede è lo stesso', function () {
    neStudio(null);
    config(['app.name' => 'Kondomanager']);

    [$html, $testo] = neRese();

    expect($html)->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*KondoManager/')
        ->and($html)->not->toContain('Kondomanager')
        ->and($html)->toContain(PIEDE_DELLE_EMAIL)
        ->and(trim($testo))->toStartWith('KondoManager')
        ->and($testo)->toContain(PIEDE_DELLE_EMAIL);

    // Lo studio scritto solo di spazi non è un nome.
    neStudio('   ');
    expect(neRese()[0])->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*KondoManager/');
});

test('un nome dell\'applicazione personalizzato, senza lo studio, vale ancora come ripiego; con lo studio vince lo studio', function () {
    neStudio(null);
    config(['app.name' => 'Residenza Sole']);
    [$html, $testo] = neRese();
    expect($html)->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*Residenza Sole/')
        ->and(trim($testo))->toStartWith('Residenza Sole')
        ->and($html)->toContain(PIEDE_DELLE_EMAIL);

    neStudio('Studio Bianchi');
    expect(neRese()[0])->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*Studio Bianchi/')->and(neRese()[0])->not->toMatch('/Cordiali saluti,\s*<br\s*\/?>\s*Residenza Sole/');
});

test('il nome dello studio arriva come testo: l\'HTML è escapato e le parentesi quadre non formano un collegamento', function () {
    neStudio('Studio <b>Rossi</b> & [clic](https://altrove.example/x)');

    [$html, $testo] = neRese();

    expect($html)->not->toContain('<b>Rossi</b>')
        ->and($html)->not->toContain('href="https://altrove.example')
        ->and($html)->toContain('Studio &lt;b&gt;Rossi&lt;/b&gt; &amp; clic(https://altrove.example/x)')
        ->and($testo)->not->toContain('[clic]');
});

test('le email con una chiusura loro non cambiano: la firma resta quella della lettera, anche con il nome dello studio', function () {
    neStudio('Studio Bianchi');

    [$html] = neRese((new MailMessage)->subject('Prova')->line('Una riga.')->salutation('Con i migliori saluti, lo studio del condominio'));

    expect($html)->toContain('Con i migliori saluti, lo studio del condominio')
        ->and($html)->not->toMatch('/Cordiali saluti,\s*<br/');
});

test('le notifiche vere con la loro chiusura (lettere, ricevute, conguaglio, compensazione) non usano la firma di serie', function () {
    // Guardia sul sorgente: ognuna chiude con `->salutation(...)`; se una la perdesse, comparirebbe la firma di serie con il nome dello studio.
    foreach (['LetteraNotification', 'RicevutaIncassoNotification', 'AvvisoCompensazioneNotification', 'FoglioConguaglioNotification'] as $classe) {
        expect(file_get_contents(app_path("Notifications/Gestionale/{$classe}.php")))->toContain('salutation(');
    }
});

test('il nome di serie è «KondoManager» dove si scrive: impostazioni generali, mittente delle email, configurazione, installatore', function () {
    expect((new ReflectionClass(GeneralSettings::class))->getDefaultProperties()['app_name'])->toBe('KondoManager')
        ->and((new ReflectionClass(MailSettings::class))->getDefaultProperties()['mail_from_name'])->toBe('KondoManager')
        ->and(config('installer.app_name'))->toBe('KondoManager');
    $config = require base_path('config/app.php');
    expect($config['name'])->toBeIn(['KondoManager', env('APP_NAME')]);
    expect(file_get_contents(base_path('config/app.php')))->toContain("env('APP_NAME', 'KondoManager')");
    expect(file_get_contents(base_path('resources/js/pages/impostazioni/impostazioniGenerali.vue')))->toContain("app_name: app_name || 'KondoManager'");
});

test('i piedi nelle quattro lingue dicono «KondoManager» e non «Kondomanager»', function (string $lingua) {
    $testo = (include base_path("lang/{$lingua}/mail.php"))['footer_sent_with'] ?? '';
    expect($testo)->toContain('KondoManager')->and($testo)->not->toContain('Kondomanager');
    if ($lingua === 'it') {
        expect($testo)->toBe(PIEDE_DELLE_EMAIL);
    }
})->with(['it', 'en', 'es', 'pt']);

test('la migrazione delle impostazioni cambia solo il valore di serie, e rieseguita non cambia niente', function () {
    $path = database_path('settings/2026_11_18_190000_scrivi_kondomanager_con_la_emme_maiuscola.php');
    expect(file_exists($path))->toBeTrue();
    $valore = fn (string $gruppo, string $nome) => json_decode((string) DB::table('settings')->where('group', $gruppo)->where('name', $nome)->value('payload'));
    $scrivi = fn (string $gruppo, string $nome, string $v) => DB::table('settings')->where('group', $gruppo)->where('name', $nome)->update(['payload' => json_encode($v)]);
    $migrazione = require $path;

    // Di serie: cambiano.
    $scrivi('general', 'app_name', 'Kondomanager');
    $scrivi('mail', 'mail_from_name', 'Kondomanager');
    $migrazione->up();
    expect($valore('general', 'app_name'))->toBe('KondoManager')
        ->and($valore('mail', 'mail_from_name'))->toBe('KondoManager');

    // Personalizzati: restano. Anche «kondomanager» scritto da chi lo voleva così.
    $scrivi('general', 'app_name', 'Residenza Sole');
    $scrivi('mail', 'mail_from_name', 'kondomanager');
    $migrazione->up();
    $migrazione->up();
    expect($valore('general', 'app_name'))->toBe('Residenza Sole')
        ->and($valore('mail', 'mail_from_name'))->toBe('kondomanager');

    // Una proprietà che non c'è: niente, nessun errore.
    DB::table('settings')->where('group', 'mail')->where('name', 'mail_from_name')->delete();
    $migrazione->up();
    expect(DB::table('settings')->where('group', 'mail')->where('name', 'mail_from_name')->exists())->toBeFalse();
});
