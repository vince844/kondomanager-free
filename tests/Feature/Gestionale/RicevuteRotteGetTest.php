<?php

use App\Http\Controllers\Gestionale\Movimenti\RicevuteIncassiController;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Gli indirizzi dei PDF delle ricevute con un GET (1.12.0-beta.2). La stampa, l'anteprima e l'archivio per persona si chiedono con un POST,
 * perché la stampa scrive il registro; un GET sullo stesso indirizzo è la scheda di un PDF ricaricata, riaperta dalla cronologia o copiata, e
 * rispondeva 405. Ora riporta alla pagina delle ricevute dello stesso condominio, con una frase che dice da dove si riapre, e non scrive niente.
 * Le protezioni sono quelle delle POST: chi non entra nel pannello non arriva né all'una né all'altro. La pagina, dal canto suo, chiede il PDF
 * con una richiesta in sottofondo in JSON: il server ne legge i campi come quelli del modulo di prima.
 *
 * Che cosa NON copre: la scheda nuova del browser e il PDF che ci arriva (nei test JavaScript della ricevuta, e a video).
 */
const ROTTE_GET_RICEVUTE = [
    'stampa'          => 'admin.gestionale.ricevute.stampa',
    'pdf-anteprima'   => 'admin.gestionale.ricevute.pdf-anteprima',
    'pdf-per-persona' => 'admin.gestionale.ricevute.pdf-per-persona',
];

/** L'indirizzo di un PDF: lo stesso per il GET e per la POST, che hanno nomi diversi. */
function indirizzoDelPdfDelleRicevute(object $s, string $pdf): string
{
    return route(ROTTE_GET_RICEVUTE[$pdf], ['condominio' => $s->condominio->id]);
}

/** I dati con cui il programma ha reso il modello del PDF, una volta per ogni PDF fatto. */
function rotteGetPdfRenduti(): \ArrayObject
{
    $resi = new \ArrayObject;
    View::composer('pdf.gestionale.ricevute_incasso', function ($vista) use ($resi) {
        $resi[] = $vista->getData();
    });

    return $resi;
}

test('un GET su uno dei tre indirizzi dei PDF riporta alla pagina delle ricevute dello stesso condominio, con la frase, e non scrive niente', function () {
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Corso Italia');
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    foreach (array_keys(ROTTE_GET_RICEVUTE) as $pdf) {
        // Anche con i campi della stampa nell'indirizzo: un GET non stampa.
        $this->actingAs($u)->get(indirizzoDelPdfDelleRicevute($s, $pdf) . '?incassi[]=' . $incasso->id . '&bollo=assolta')
            ->assertRedirect(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))
            ->assertSessionHas('message', ['type' => 'warning', 'message' => RicevuteIncassiController::FRASI_RIAPRI_PDF[$pdf]]);
        $this->actingAs($u)->get(indirizzoDelPdfDelleRicevute($altro, $pdf))
            ->assertRedirect(route('admin.gestionale.ricevute.index', ['condominio' => $altro->condominio->id]));
    }

    expect(RegistroInvio::count())->toBe(0);
    // La ricevuta che esce dopo è ancora l'originale.
    $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->assertJson(['copia' => null]);
});

test('le frasi dicono da quale pulsante il PDF si riapre, in italiano, e la stampa dice che non ha stampato niente', function () {
    expect(RicevuteIncassiController::FRASI_RIAPRI_PDF['stampa'])->toContain('«Stampa o invia ricevuta»')->toContain('non ha stampato niente')->toContain('esce come copia')->not->toContain('premi «Stampa»')
        ->and(RicevuteIncassiController::FRASI_RIAPRI_PDF['pdf-anteprima'])->toContain('«Anteprima»')
        ->and(RicevuteIncassiController::FRASI_RIAPRI_PDF['pdf-per-persona'])->toContain('«Scarica un PDF per persona»');
    foreach (RicevuteIncassiController::FRASI_RIAPRI_PDF as $frase) {
        expect($frase)->not->toMatch('/405|Method|GET|POST/');
    }
});

test('la pagina delle ricevute riceve la frase fra le sue proprietà, dove la legge il suo avviso', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();

    $this->actingAs($u)->followingRedirects()->get(indirizzoDelPdfDelleRicevute($s, 'stampa'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/incassi/RicevuteIncassi')
            ->where('condominio.id', $s->condominio->id)
            ->where('flash.message.type', 'warning')
            ->where('flash.message.message', RicevuteIncassiController::FRASI_RIAPRI_PDF['stampa']));

    // Una volta letta, la frase non resta: la pagina riaperta dopo non la ripete.
    $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))
        ->assertInertia(fn ($page) => $page->where('flash.message', null));
});

test('il GET ha una rotta sua con un nome suo, che nessuna rotta con {scrittura} prende; la POST sullo stesso indirizzo resta quella del PDF', function () {
    $s = scenarioRicevutaIncasso();
    $rotte = app('router')->getRoutes();

    foreach (ROTTE_GET_RICEVUTE as $pdf => $nome) {
        $url = indirizzoDelPdfDelleRicevute($s, $pdf);
        expect($rotte->match(Request::create($url, 'GET'))->getName())->toBe($nome . '.riapri')
            ->and($rotte->match(Request::create($url, 'POST'))->getName())->toBe($nome)
            ->and($rotte->match(Request::create($url, 'GET'))->getActionMethod())->toBe('riapriPdf');
    }
});

test('chi non ha il permesso è trattato sul GET come sulla POST: l\'ospite va all\'accesso, chi non ha la verifica a verificarla, chi è fuori dal pannello ha 403', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $fuori = User::factory()->create(['email_verified_at' => now()]);
    $daVerificare = amministratoreRicevute();
    $daVerificare->forceFill(['email_verified_at' => null])->save();
    $campi = ['incassi' => [$incasso->id], 'bollo' => 'assolta'];

    foreach (array_keys(ROTTE_GET_RICEVUTE) as $pdf) {
        $url = indirizzoDelPdfDelleRicevute($s, $pdf);
        // L'ospite: nessuna sessione.
        auth()->logout();
        $postOspite = $this->post($url, $campi);
        $getOspite = $this->get($url);
        $postOspite->assertRedirect(route('login'));
        $getOspite->assertRedirect(route('login'));

        foreach ([$fuori, $daVerificare] as $utente) {
            $post = $this->actingAs($utente)->post($url, $campi);
            $get = $this->actingAs($utente)->get($url);
            expect($get->status())->toBe($post->status())
                ->and($get->headers->get('Location'))->toBe($post->headers->get('Location'));
        }
        $this->actingAs($fuori)->get($url)->assertForbidden();
        $this->actingAs($daVerificare)->get($url)->assertRedirect(route('verification.notice'));
    }

    expect(RegistroInvio::count())->toBe(0);
});

test('in JSON la stampa e l\'anteprima leggono gli stessi campi del modulo di prima: le liste, i filtri annidati, i valori 0 e 1, i vuoti', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-02', 60.00);
    $resi = rotteGetPdfRenduti();
    $url = fn (string $pdf) => indirizzoDelPdfDelleRicevute($s, $pdf);

    // «Tutte le N dei filtri», come le mandava il modulo nascosto (ogni valore un testo, i vuoti assenti) e come le manda ora la richiesta.
    $this->actingAs($u)->post($url('pdf-anteprima'), [
        'tutte' => '1', 'filtri' => ['ricevuta' => 'mai', 'data_da' => '2026-01-01', 'data_a' => '2026-12-31', 'persone' => [(string) $s->anna->id, (string) $s->ugo->id]],
        'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12', 'nota' => 'Prova',
        'stampa_causale' => '1', 'indirizzi' => ['email', 'email_secondaria'], 'anche_chi_ha_versato' => '0', 'ordine' => 'data',
    ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($u)->json('POST', $url('pdf-anteprima'), [
        'tutte' => 1, 'filtri' => ['ricevuta' => 'mai', 'data_da' => '2026-01-01', 'data_a' => '2026-12-31', 'persone' => [(string) $s->anna->id, (string) $s->ugo->id]],
        'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12', 'nota' => 'Prova',
        'stampa_causale' => 1, 'indirizzi' => ['email', 'email_secondaria'], 'anche_chi_ha_versato' => 0, 'ordine' => 'data',
    ], ['Accept' => 'application/json, text/plain, */*'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($resi)->toHaveCount(2);
    $voci = fn (array $dati) => array_map(fn ($v) => Arr::only($v, ['etichetta', 'bollo', 'modo_bollo', 'estremi_bollo', 'nota', 'stampa_causale', 'copia_del'])
        + ['numero' => $v['contenuto']['numero']], $dati['ricevute']);
    expect($voci($resi[1]))->toBe($voci($resi[0]))
        ->and(array_column($voci($resi[1]), 'numero'))->toBe([$anna->numero_protocollo, $ugo->numero_protocollo])
        ->and($voci($resi[1])[0])->toMatchArray(['bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12', 'nota' => 'Prova', 'stampa_causale' => true])
        ->and($voci($resi[1])[1]['bollo'])->toBe('non_dovuta')
        ->and(RegistroInvio::count())->toBe(0);

    // Una ricevuta sola: la lista con il numero (non il testo), i vuoti come null, «Stampa la causale» come 0. La riga del registro è quella del modulo.
    $this->actingAs($u)->post($url('stampa'), ['incassi' => [(string) $anna->id], 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => 'n. 0117', 'stampa_causale' => '0'])
        ->assertOk();
    $this->actingAs($u)->json('POST', $url('stampa'), ['incassi' => [$ugo->id], 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => 'n. 0117', 'nota' => null, 'stampa_causale' => 0],
        ['Accept' => 'application/json, text/plain, */*'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $righe = RegistroInvio::orderBy('id')->get();
    $scelte = fn (RegistroInvio $r) => Arr::only($r->dati, ['bollo', 'modo_bollo', 'estremi_bollo', 'nota', 'stampa_causale']);
    expect($righe)->toHaveCount(2)
        ->and($righe[1]->documentabile_id)->toBe($ugo->id)
        ->and($scelte($righe[1]))->toBe($scelte($righe[0]))
        ->and($scelte($righe[1]))->toBe(['bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '0117', 'stampa_causale' => false]);
});
