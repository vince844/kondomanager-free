<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 485) — un erede con il decesso prima del defunto, o lo stesso giorno, non si registra.
 *
 * Prima la pagina del passaggio accettava come erede una persona che aveva già una successione registrata, con il decesso di prima: con Ada Bianchi
 * morta il 01/03/2026 (successione su U2) nominata erede di Ettore Fabbri, morto il 17/11/2026, la registrazione passava e il motore le intestava i
 * giorni dopo il suo decesso. Ora la richiesta del passaggio (la stessa per l'anteprima e per la registrazione) si ferma nei due versi:
 * - nominando gli eredi, su un erede con una successione non annullata del condominio con il decesso fino al giorno del decesso di questa, lo stesso
 *   giorno compreso (chi muore nello stesso momento non eredita dall'altro, art. 4 c.c.);
 * - registrando la successione di chi è fra gli eredi di una successione non annullata con il decesso da quel giorno in poi.
 * L'erede morto dopo il defunto (la catena della decisione 530) e la successione dell'erede annullata passano. Le successioni già registrate così le
 * dice `kondomanager:verifica-titolarita`, in sola lettura, con la strada.
 *
 * Il caso: Via Verdi (`InteressiSulDebitoEreditatoScenario.php`), Ettore Fabbri su U1, Ada Bianchi su U2; Elena e Marco persone del condominio.
 *
 * La catena: la successione di Ada dalla rotta → la successione di Ettore con Ada fra gli eredi, dall'anteprima e dalla registrazione → il rifiuto, e
 * nessun passaggio scritto.
 *
 * Prefisso `b14em`: Pest carica tutti i file nello stesso processo.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Via Verdi con Bianchi rinominata Ada Bianchi. */
function b14emCaso($test): array
{
    $s = ideCaso($test);
    $s['persone']['Bianchi']->forceFill(['nome' => 'Ada Bianchi'])->save();

    return $s;
}

/** La successione di Ada Bianchi su U2 con il decesso del giorno dato, erede Marco. */
function b14emSuccessioneDiAda(array $s, string $decesso): array
{
    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Bianchi'], 'decorrenza' => $decesso, 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 100]], 'arretrato' => Subentro::ARRETRATO_AGLI_EREDI,
        'erede_di_riferimento' => $s['persone']['Marco']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 2', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'];
}

/** La successione di Ettore Fabbri su U1, morto il 17/11/2026, con gli eredi Ada ed Elena al 50 %. */
function b14emSuccessioneDiEttore(array $s): array
{
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Elena']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Elena']->id;

    return $dati;
}

/** La registrazione dalla rotta del passaggio, il giorno dato. */
function b14emRegistra($test, array $s, string $unita, array $dati, string $il = '2026-11-20 10:00:00')
{
    $test->travelTo($il);
    session()->forget('errors');

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita'][$unita]]), $dati);
}

/** L'anteprima dalla rotta del pannello «Cosa cambierà». */
function b14emAnteprima($test, array $s, string $unita, array $dati)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita'][$unita]]), $dati);
}

/** Le successioni non annullate del condominio. */
function b14emSuccessioni(array $s): int
{
    return Subentro::where('condominio_id', $s['c']->id)->where('tipo_passaggio', 'successione')->count();
}

// ─── Il verso dell'erede ────────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 485 — Ada, con la successione del 01/03/2026 su U2, non può essere erede di Ettore, morto il 17/11/2026: l\'anteprima e la registrazione si fermano', function () {
    $s = b14emCaso($this);
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-03-01'), '2026-11-19 10:00:00')->assertSessionHasNoErrors();
    $frase = 'Il decesso di Ada Bianchi è del 01/03/2026 (successione registrata su U2), prima di quello di Ettore Fabbri: non può essere fra i suoi eredi. '
        .'Indica gli eredi come li dice la dichiarazione di successione.';

    $this->travelTo('2026-11-20 10:00:00');
    b14emAnteprima($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertStatus(422)->assertJsonValidationErrors(['eredi.0.anagrafica_id' => $frase]);
    b14emRegistra($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertSessionHasErrors(['eredi.0.anagrafica_id' => $frase]);
    expect(b14emSuccessioni($s))->toBe(1)
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U1']->id)->where('anagrafica_id', $s['persone']['Bianchi']->id)->exists())->toBeFalse();
});

test('Coda 485 — il decesso dell\'erede lo stesso giorno del defunto: si ferma anche lui (art. 4 c.c.)', function () {
    $s = b14emCaso($this);
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-11-17'), '2026-11-19 10:00:00')->assertSessionHasNoErrors();

    b14emRegistra($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertSessionHasErrors(['eredi.0.anagrafica_id' =>
        'Il decesso di Ada Bianchi è del 17/11/2026 (successione registrata su U2), lo stesso giorno di quello di Ettore Fabbri: non può essere fra i suoi eredi. '
        .'Indica gli eredi come li dice la dichiarazione di successione.']);
    expect(b14emSuccessioni($s))->toBe(1);
});

test('Coda 485 — l\'erede morto dopo il defunto passa (la catena della decisione 530), e così l\'erede con la successione annullata', function () {
    // Ada muore il 19/11, dopo Ettore: la successione di Ettore con Ada fra gli eredi passa, e dopo quella di Ada su U2. In un secondo condominio la
    // successione di Ada del 01/03 è annullata: Ada torna erede possibile di Ettore.
    $s = b14emCaso($this);
    b14emRegistra($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertSessionHasNoErrors();
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-11-19'), '2026-11-21 10:00:00')->assertSessionHasNoErrors();
    expect(b14emSuccessioni($s))->toBe(2);

    $t = b14emCaso($this);
    $ada = b14emRegistra($this, $t, 'U2', b14emSuccessioneDiAda($t, '2026-03-01'), '2026-11-19 10:00:00')->assertSessionHasNoErrors();
    $successioneDiAda = Subentro::where('immobile_id', $t['unita']['U2']->id)->where('tipo_passaggio', 'successione')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$t['c'], $t['unita']['U2'], $successioneDiAda]), ['nota_annullamento' => 'Successione registrata per errore'])
        ->assertRedirect();
    b14emAnteprima($this, $t, 'U1', b14emSuccessioneDiEttore($t))->assertOk();
    b14emRegistra($this, $t, 'U1', b14emSuccessioneDiEttore($t))->assertSessionHasNoErrors();
    expect(b14emSuccessioni($t))->toBe(1);
});

// ─── Il verso del defunto ───────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 485 — Ada è fra gli eredi di Ettore, morto il 17/11/2026: la sua successione con il decesso del 01/03/2026, o dello stesso giorno, si ferma', function () {
    $s = b14emCaso($this);
    b14emRegistra($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertSessionHasNoErrors();
    $prima = 'Ada Bianchi è fra gli eredi di Ettore Fabbri, con il decesso del 17/11/2026: il suo decesso non può essere di prima, del 01/03/2026. '
        .'Se la data è giusta, annulla prima la successione di Ettore Fabbri e registrala con gli eredi giusti.';

    $this->travelTo('2026-11-21 10:00:00');
    b14emAnteprima($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-03-01'))->assertStatus(422)->assertJsonValidationErrors(['decorrenza' => $prima]);
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-03-01'), '2026-11-21 10:00:00')->assertSessionHasErrors(['decorrenza' => $prima]);
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-11-17'), '2026-11-21 10:00:00')->assertSessionHasErrors(['decorrenza' =>
        'Ada Bianchi è fra gli eredi di Ettore Fabbri, con il decesso del 17/11/2026: il suo decesso non può essere dello stesso giorno. '
        .'Se la data è giusta, annulla prima la successione di Ettore Fabbri e registrala con gli eredi giusti.']);
    expect(b14emSuccessioni($s))->toBe(1);
});

// ─── I dati già scritti ─────────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 485 — le successioni già registrate con un erede morto prima: le dice verifica-titolarita, senza toccare niente', function () {
    // Registrate come una versione di prima le lasciava scrivere: Ettore il 17/11 con Ada fra gli eredi, poi la successione di Ada su U2 con il decesso
    // del 01/03 (qui scritta con il 19/11 e portata al 01/03 sulla tabella, perché la rotta di oggi la rifiuta).
    $s = b14emCaso($this);
    b14emRegistra($this, $s, 'U1', b14emSuccessioneDiEttore($s))->assertSessionHasNoErrors();
    b14emRegistra($this, $s, 'U2', b14emSuccessioneDiAda($s, '2026-11-19'), '2026-11-21 10:00:00')->assertSessionHasNoErrors();
    DB::table('subentri')->where('immobile_id', $s['unita']['U2']->id)->where('tipo_passaggio', 'successione')->update(['decorrenza' => '2026-03-01']);
    $prima = DB::table('subentri')->orderBy('id')->get()->toJson();

    Artisan::call('kondomanager:verifica-titolarita', ['--condominio' => $s['c']->id]);
    $uscita = Artisan::output();

    expect($uscita)->toContain('Eredi con il decesso prima del defunto o lo stesso giorno (1)')
        ->toContain('Annulla la successione del defunto e registrala di nuovo con gli eredi come li dice la dichiarazione di successione.')
        ->and(preg_match('/U1\s*\|\s*Ettore Fabbri\s*\|\s*17\/11\/2026\s*\|\s*Ada Bianchi\s*\|\s*01\/03\/2026, successione su U2/', $uscita))->toBe(1)
        ->and(DB::table('subentri')->orderBy('id')->get()->toJson())->toBe($prima);
});
