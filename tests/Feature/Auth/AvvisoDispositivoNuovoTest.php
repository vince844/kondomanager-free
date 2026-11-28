<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'avviso al titolare per l'accesso da un dispositivo nuovo (Coda 385).
 *
 * Un browser che non tiene i cookie è nuovo ogni volta: l'email lo dice, in ogni lingua del programma.
 *
 * Il primo accesso di sempre registra il dispositivo e non avvisa; lo stesso browser (il suo cookie) non avvisa;
 * un browser nuovo avvisa una volta, con il browser, il sistema e l'indirizzo approssimato. Con la verifica in due
 * passaggi la sola password giusta non avvisa e non registra: l'avviso parte dopo la sfida superata. Il sospeso no.
 * Senza la tabella (un aggiornamento non ancora finito) l'accesso riesce lo stesso. La pulizia notturna toglie le
 * righe non viste da tredici mesi.
 */

use App\Enums\Role as RoleEnum;
use App\Listeners\AvvisaDispositivoNuovo;
use App\Models\DispositivoConosciuto;
use App\Models\User;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PragmaRX\Google2FA\Google2FA;

const UA_PORTATILE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
const UA_TELEFONO = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->marco = User::factory()->create(['name' => 'Marco Studio', 'email' => 'marco@example.com', 'email_verified_at' => now()]);
    $this->marco->assignRole(RoleEnum::COLLABORATORE->value);
});

/** Un accesso con la password da un browser: rende il gettone del cookie che il browser riceve (o quello che aveva). */
function accediDa(string $userAgent, ?string $gettone = null, string $ip = '93.45.120.7'): ?string
{
    $test = test();
    $richiesta = $test->withHeader('User-Agent', $userAgent)->withServerVariables(['REMOTE_ADDR' => $ip]);
    if ($gettone !== null) {
        $richiesta = $richiesta->withCookie(AvvisaDispositivoNuovo::COOKIE, $gettone);
    }

    $risposta = $richiesta->post('/login', ['email' => 'marco@example.com', 'password' => 'password']);
    $risposta->assertRedirect();

    $cookie = $risposta->getCookie(AvvisaDispositivoNuovo::COOKIE);
    $ricevuto = $cookie?->getValue() ?? $gettone;

    auth()->guard('web')->logout();
    test()->flushSession();

    return $ricevuto;
}

function avvisiDiAccesso(User $user): int
{
    return Notification::sent($user, AvvisoDiSicurezzaNotification::class)
        ->filter(fn (AvvisoDiSicurezzaNotification $a) => $a->evento === AvvisiDiSicurezza::ACCESSO_DA_DISPOSITIVO_NUOVO)
        ->count();
}

it('il primo accesso di sempre registra il dispositivo e non avvisa', function () {
    $gettone = accediDa(UA_PORTATILE);

    expect($gettone)->not->toBeNull()
        ->and(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(1)
        ->and(DispositivoConosciuto::first()->impronta)->toBe(hash('sha256', $gettone))
        ->and(DispositivoConosciuto::first()->descrizione)->toBe('Chrome su Windows');
    expect(avvisiDiAccesso($this->marco))->toBe(0);
});

it('lo stesso dispositivo non avvisa, e aggiorna l\'ultima volta', function () {
    $gettone = accediDa(UA_PORTATILE);
    $this->travel(2)->days();
    accediDa(UA_PORTATILE, $gettone, '93.45.120.9');

    expect(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(1)
        ->and(DispositivoConosciuto::first()->ultimo_indirizzo)->toBe('93.45.120.9');
    expect(avvisiDiAccesso($this->marco))->toBe(0);
});

it('un dispositivo nuovo avvisa una volta, con il browser, il sistema e l\'indirizzo approssimato', function () {
    accediDa(UA_PORTATILE);
    accediDa(UA_TELEFONO, null, '151.12.34.56');

    expect(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(2);
    expect(avvisiDiAccesso($this->marco))->toBe(1);

    $avviso = Notification::sent($this->marco, AvvisoDiSicurezzaNotification::class)
        ->first(fn (AvvisoDiSicurezzaNotification $a) => $a->evento === AvvisiDiSicurezza::ACCESSO_DA_DISPOSITIVO_NUOVO);
    $mail = $avviso->toMail($this->marco);
    $testo = implode("\n", array_merge([$mail->subject], $mail->introLines));

    expect($testo)->toContain('Nuovo accesso al tuo account')
        ->toContain('Chrome su Android')
        ->toContain('151.12.x.x')
        ->toContain('Se usi la navigazione privata, o se il browser cancella i cookie, questo avviso arriva a ogni accesso.')
        ->not->toContain('151.12.34.56');
});

it('la frase sui cookie c\'è in ogni lingua, e solo nell\'avviso del dispositivo nuovo', function (string $lingua, string $frase) {
    app()->setLocale($lingua);

    $dispositivo = new AvvisoDiSicurezzaNotification(AvvisiDiSicurezza::ACCESSO_DA_DISPOSITIVO_NUOVO, 'Marco Studio', dispositivo: 'Chrome');
    $password = new AvvisoDiSicurezzaNotification(AvvisiDiSicurezza::PASSWORD_CAMBIATA, 'Marco Studio');

    expect($dispositivo->toMail($this->marco)->introLines)->toContain($frase)
        ->and($password->toMail($this->marco)->introLines)->not->toContain($frase);
})->with([
    'italiano' => ['it', 'Se usi la navigazione privata, o se il browser cancella i cookie, questo avviso arriva a ogni accesso.'],
    'inglese' => ['en', 'If you use private browsing, or your browser deletes cookies, this alert arrives at every sign-in.'],
    'spagnolo' => ['es', 'Si usas la navegación privada, o si el navegador borra las cookies, este aviso llega en cada acceso.'],
    'portoghese' => ['pt', 'Se usar a navegação privada, ou se o navegador apagar os cookies, este aviso chega a cada acesso.'],
]);

it('con la verifica in due passaggi la sola password giusta non avvisa; la sfida superata sì', function () {
    accediDa(UA_PORTATILE);
    $segreto = 'JBSWY3DPEHPK3PXP';
    $this->marco->forceFill([
        'two_factor_secret' => encrypt($segreto),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->withHeader('User-Agent', UA_TELEFONO)
        ->post('/login', ['email' => 'marco@example.com', 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    expect(avvisiDiAccesso($this->marco))->toBe(0)
        ->and(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(1);

    $this->withHeader('User-Agent', UA_TELEFONO)
        ->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($segreto)])
        ->assertRedirect();

    expect(avvisiDiAccesso($this->marco))->toBe(1)
        ->and(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(2);
});

it('il sospeso non registra un dispositivo e non riceve l\'avviso', function () {
    accediDa(UA_PORTATILE);
    $this->marco->forceFill(['suspended_at' => now()])->save();

    $this->withHeader('User-Agent', UA_TELEFONO)
        ->post('/login', ['email' => 'marco@example.com', 'password' => 'password']);

    expect(avvisiDiAccesso($this->marco))->toBe(0)
        ->and(DispositivoConosciuto::where('user_id', $this->marco->id)->count())->toBe(1);
});

it('senza la tabella dei dispositivi l\'accesso riesce lo stesso', function () {
    Schema::drop('dispositivi_conosciuti');

    $this->withHeader('User-Agent', UA_PORTATILE)
        ->post('/login', ['email' => 'marco@example.com', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->marco);
});

it('la pulizia notturna toglie i dispositivi non visti da tredici mesi, e chi torna da lì riceve l\'avviso', function () {
    $vecchio = accediDa(UA_PORTATILE);
    $recente = accediDa(UA_TELEFONO);
    DispositivoConosciuto::where('impronta', hash('sha256', $vecchio))->update(['ultima_volta' => now()->subMonths(14)]);

    Artisan::call('model:prune', ['--model' => [DispositivoConosciuto::class]]);

    expect(DispositivoConosciuto::where('user_id', $this->marco->id)->pluck('impronta')->all())
        ->toBe([hash('sha256', $recente)]);

    $prima = avvisiDiAccesso($this->marco);
    accediDa(UA_PORTATILE, $vecchio);
    expect(avvisiDiAccesso($this->marco))->toBe($prima + 1);
});

it('l\'indirizzo approssimato tiene la rete e non la persona', function () {
    expect(AvvisaDispositivoNuovo::indirizzoApprossimato('93.45.120.7'))->toBe('93.45.x.x')
        ->and(AvvisaDispositivoNuovo::indirizzoApprossimato('2001:db8:85a3::8a2e:370:7334'))->toBe('2001:db8:85a3::')
        ->and(AvvisaDispositivoNuovo::indirizzoApprossimato(null))->toBeNull();
});
