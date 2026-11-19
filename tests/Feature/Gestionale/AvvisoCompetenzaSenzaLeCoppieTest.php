<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'avviso della competenza nel nuovo piano rate («Il periodo di competenza cambia chi paga su 1 unità», `DefuntoNelPiano`) e le coppie dei versamenti degli
 * eredi (decisione 529). Vista a video nella prova di tutto il processo della beta.12 (18/11/2026): dopo il versamento di Cecilia sul debito ereditato da
 * Massimo Bagnara, l'avviso leggeva la coppia nel Wallet (Cecilia − € 32,79, Bagnara + € 32,79, legate alla successione) come un saldo della successione e
 * diceva «Per Massimo Bagnara il saldo legato alla successione è di € 32,79 a debito invece di € 1,74 a credito: correggilo dal Wallet», un consiglio su
 * righe che il Wallet non lascia cambiare. La coppia di un versamento non è la parte che passa agli eredi: il controllo non la conta.
 *
 * Sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): Elena versa € 150,00 a nome suo, poi il piano 2027 della stessa gestione, che conta
 * ancora per Ettore i suoi giorni fino al decesso. L'avviso deve dire solo ciò che manca: i saldi legati alla successione di Ettore, Elena e Marco, come
 * senza il versamento.
 *
 * Che cosa NON copre: la pagina dell'avviso (`DestinatariWarning.vue`, che stampa la frase del server); la coppia rovescia di uno storno dopo un piano emesso
 * (stesso filtro, non provata qui); le righe legate scritte a mano dal Wallet, che restano nel controllo (le prove della beta.53); MySQL.
 */

use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Elena, erede, versa a nome suo sul debito ereditato da Ettore. */
function ascVersaElena($test, array $s, int $subentroId, int $cent): void
{
    $test->travelTo('2026-11-25 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Elena']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [],
        'debito_ereditato' => [['subentro_id' => $subentroId, 'importo' => number_format($cent / 100, 2, '.', '')]],
    ])->assertSessionHasNoErrors();
}

/** Il piano 2027 della gestione di Via Verdi (come `idePianoNuovo`), non ancora generato. */
function ascPiano(array $s): PianoRate
{
    DB::table('esercizi')->where('id', $s['e']->id)->update(['data_fine' => '2027-12-31']);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['data_fine' => '2027-12-31']);
    $voce = cpmVoce($s, 'Manutenzione albero', [[$s['generali'], 100]], 'spesa', 1000);
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo 2027',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-03-31',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    $piano->capitoli()->attach($voce->id, ['importo' => 1000, 'note' => 'La voce del piano nuovo']);

    return $piano;
}

/** La generazione del piano fermata al cancello: la voce del defunto, se c'è. */
function ascVoceDelDefunto(array $s, PianoRate $piano): ?array
{
    try {
        app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: false, esercizio: $s['e']);
    } catch (DestinatariCambiatiException $e) {
        return collect($e->getCambiamenti())->firstWhere('motivo', 'defunto_nel_piano');
    }

    return null;
}

it('l\'avviso della competenza non legge la coppia del versamento di Elena come un saldo della successione: dice solo i saldi legati che mancano, come senza il versamento', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $piano = ascPiano($s);
    // Senza il versamento: la frase che l'avviso deve dire (i saldi legati alla successione di Ettore, Elena e Marco, da registrare).
    $attesa = ascVoceDelDefunto($s, $piano);
    expect($attesa)->not->toBeNull()
        ->and($attesa['frase'])->toContain('registra dal Wallet i saldi legati alla successione')
        ->and($attesa['frase'])->not->toContain('correggilo dal Wallet');

    ascVersaElena($this, $s, (int) $p->id, 15000);
    // Controllo: la coppia c'è, legata alla successione, libera.
    expect(DB::table('saldi')->where('passaggio_id', $p->id)->where('origine', 'automatico')->where('is_applicato', false)->count())->toBe(2);

    $voce = ascVoceDelDefunto($s, $piano);
    // Con il codice di prima: «Per Ettore Fabbri il saldo legato alla successione è di € 150,00 a debito invece di € … a credito: correggilo dal Wallet.» (e
    // lo stesso per Elena), e niente righe da registrare per loro.
    expect($voce)->not->toBeNull()
        ->and($voce['frase'])->not->toContain('correggilo dal Wallet')
        ->and($voce['frase'])->not->toContain('€ 150,00')
        ->and($voce['cifra'])->toBe($attesa['cifra'])
        ->and($voce['frase'])->toBe($attesa['frase']);
});
