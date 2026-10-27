<?php

use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\Route;

/**
 * # Coda 264 — le intestazioni di sicurezza su ogni risposta
 *
 * Il gestionale non si carica in un riquadro di un altro sito (`X-Frame-Options: SAMEORIGIN` e, della
 * CSP, solo `frame-ancestors 'self'`), il browser non indovina il tipo dei file (`nosniff`), l'indirizzo
 * completo delle pagine non esce verso altri siti (`Referrer-Policy`), e su una richiesta sicura il
 * browser ricorda di usare solo https (`Strict-Transport-Security`, senza sottodomini né preload).
 *
 * Le mette un middleware globale, in testa alla pila: vale per le pagine, per le risposte JSON, per
 * `/up` (fuori dal gruppo `web`), per la 503 del ripristino (che `CheckRestoreMode` dà prima del resto
 * del gruppo `web`), per la manutenzione e per le pagine d'errore. Copre ogni installazione, anche chi
 * non usa l'immagine Docker. Una sede sola: nginx non le ripete (due righe della stessa intestazione la
 * rendono non valida), e un'intestazione che la risposta ha già non si tocca.
 *
 * HSTS solo se la richiesta è sicura dopo i proxy fidati (`$request->isSecure()`): da un proxy non
 * fidato `X-Forwarded-Proto: https` non basta.
 */
const INTESTAZIONI_FISSE = [
    'X-Frame-Options' => 'SAMEORIGIN',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Content-Security-Policy' => "frame-ancestors 'self'",
];

function controllaIntestazioniFisse($risposta): void
{
    foreach (INTESTAZIONI_FISSE as $nome => $valore) {
        $risposta->assertHeader($nome, $valore);
        expect($risposta->headers->all(strtolower($nome)))->toHaveCount(1);
    }
}

beforeEach(function () {
    Route::get('/__intestazioni-gia-messe', fn () => response('x')
        ->header('X-Frame-Options', 'DENY')
        ->header('Content-Security-Policy', "default-src 'self'"));
});

afterEach(function () {
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
});

it('Coda 264 — una pagina per ospiti ha le intestazioni di sicurezza', function () {
    controllaIntestazioniFisse($this->get('/login')->assertOk());
});

it('Coda 264 — /up, fuori dal gruppo web, ha le intestazioni di sicurezza', function () {
    controllaIntestazioniFisse($this->get('/up'));
});

it('Coda 264 — una risposta JSON ha le intestazioni di sicurezza', function () {
    $stato = app(RestoreState::class);
    $stato->put(['uuid' => 'x', 'phase' => 'pending']);
    $token = $stato->issueToken(3600);

    controllaIntestazioniFisse(
        $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token])->assertOk()->assertJsonPath('restore.phase', 'pending')
    );
    controllaIntestazioniFisse($this->getJson('/ripristino/stato')->assertForbidden());
});

it('Coda 264 — la 503 del ripristino ha le intestazioni di sicurezza', function () {
    app(RestoreMode::class)->enter('uuid-di-prova', 'it');

    controllaIntestazioniFisse($this->get('/admin/dashboard')->assertStatus(503)->assertSee('Ripristino in corso', false));
});

it('Coda 264 — la manutenzione e la pagina 404 hanno le intestazioni di sicurezza', function () {
    controllaIntestazioniFisse($this->get('/pagina-che-non-esiste')->assertNotFound());

    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->app->forgetInstance(MaintenanceModeManager::class);
    $this->app->maintenanceMode()->activate(['except' => [], 'status' => 503, 'retry' => null, 'refresh' => null, 'secret' => null, 'redirect' => null, 'template' => null]);

    controllaIntestazioniFisse($this->get('/login')->assertStatus(503));
});

it('Coda 264 — un\'intestazione che la risposta ha già non si sovrascrive', function () {
    $risposta = $this->get('/__intestazioni-gia-messe')->assertOk();

    $risposta->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($risposta->headers->all('x-frame-options'))->toBe(['DENY']);
});

it('Coda 264 — su http niente HSTS', function () {
    $this->call('GET', 'http://localhost/login', [], [], [], ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('Coda 264 — su https HSTS, senza sottodomini né preload', function () {
    $valore = $this->call('GET', 'https://localhost/login', [], [], [], ['REMOTE_ADDR' => '8.8.8.8', 'HTTPS' => 'on'])
        ->assertOk()
        ->assertHeader('Strict-Transport-Security')
        ->headers->get('Strict-Transport-Security');

    expect($valore)->toMatch('/^max-age=\d+$/')
        ->and((int) substr($valore, 8))->toBeGreaterThan(0);
});

it('Coda 264 — dietro un proxy fidato che dice https, HSTS', function () {
    config(['trustedproxy.proxies' => 'PRIVATE_SUBNETS']);

    $this->call('GET', 'http://localhost/login', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk()->assertHeader('Strict-Transport-Security');
});

it('Coda 264 — da un proxy non fidato «X-Forwarded-Proto: https» non porta HSTS', function () {
    config(['trustedproxy.proxies' => 'PRIVATE_SUBNETS']);

    $this->call('GET', 'http://localhost/login', [], [], [], [
        'REMOTE_ADDR' => '8.8.4.4',
        'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('Coda 264 — nginx dell\'immagine nasconde la versione e non ripete le intestazioni', function () {
    $nginx = file_get_contents(base_path('docker/production/nginx.conf'));
    $senzaCommenti = preg_replace('/#.*$/m', '', $nginx);

    expect($senzaCommenti)->toMatch('/^\s*server_tokens\s+off\s*;/m');

    foreach ([...array_keys(INTESTAZIONI_FISSE), 'Strict-Transport-Security'] as $nome) {
        expect(stripos($senzaCommenti, $nome))->toBeFalse();
    }
});
