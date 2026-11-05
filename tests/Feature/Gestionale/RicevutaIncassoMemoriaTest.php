<?php

use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Il tetto delle ricevute per archivio (200, decisione 208) vale anche per la memoria: lo .zip con un PDF per persona è per chi ha un hosting
 * condiviso, dove PHP ha di solito 128 MB. mPDF tiene dei riferimenti circolari: senza una raccolta dopo ogni PDF la memoria di una persona non
 * torna libera prima della successiva, e a 200 persone l'archivio chiedeva oltre 120 MB più della partenza (misurato; ora circa 50), contro i circa 55 della
 * stampa delle stesse in un PDF solo.
 *
 * Che cosa NON copre: la memoria di una richiesta vera, che parte da meno del processo dei test. Per una prova a 128 MB, il file si lancia da solo:
 * `php -d memory_limit=128M vendor/bin/pest tests/Feature/Gestionale/RicevutaIncassoMemoriaTest.php`.
 */
function memoriaPersonaConIncasso(object $s, int $n): ScritturaContabile
{
    $p = Anagrafica::create(['nome' => sprintf('Persona %03d', $n), 'email' => "p{$n}@prova.it", 'indirizzo' => 'Via ' . (100 + $n) . ', Milano',
        'codice_fiscale' => 'CF' . strtoupper(Str::random(14))]);
    $p->condomini()->attach($s->condominio->id);
    $u = Immobile::create(['condominio_id' => $s->condominio->id, 'nome' => 'Appartamento ' . (100 + $n), 'descrizione' => 'Appartamento', 'interno' => (string) (100 + $n)]);
    $u->anagrafiche()->attach($p->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $q = RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'importo' => 1000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);

    return incassaPerRicevuta($s, $p, [[$q, 10.00]], '2026-02-01', 10.00);
}

test('lo .zip di 200 ricevute di 200 persone non chiede più di 90 MB oltre la partenza: sta dentro i 128 MB di PHP', function () {
    $s = scenarioRicevutaIncasso();
    foreach (range(1, 200) as $n) {
        memoriaPersonaConIncasso($s, $n);
    }
    $incassi = ScritturaContabile::where('condominio_id', $s->condominio->id)->orderBy('id')->get();
    $servizio = app(EmissioneRicevute::class);
    $scelte = $servizio->scelte(['bollo' => 'non_dovuta']);
    expect($incassi)->toHaveCount(200);

    gc_collect_cycles();
    $partenza = memory_get_usage();
    memory_reset_peak_usage();
    $esito = $servizio->archivioPerPersona($s->condominio, $incassi, $scelte, null);
    $oltre = (memory_get_peak_usage() - $partenza) / 1048576;

    $archivio = new ZipArchive;
    $percorso = tempnam(sys_get_temp_dir(), 'memoria-zip');
    file_put_contents($percorso, $esito['zip']);
    expect($archivio->open($percorso))->toBeTrue();
    expect($archivio->numFiles)->toBe(201);
    $archivio->close();
    unlink($percorso);
    expect(RegistroInvio::count())->toBe(200)
        ->and($oltre)->toBeLessThan(90.0, 'l\'archivio ha chiesto ' . round($oltre) . ' MB oltre la partenza');
});
