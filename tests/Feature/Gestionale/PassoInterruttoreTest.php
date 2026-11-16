<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Il programma suggerisce la prossima azione» o «Scelgo io la prossima azione», una scelta del condominio (la scheda «Impostazioni»): la regola è una, i modi
 * di mostrarla due. Spento, il riquadro dei sei mesi non segna un passo e ha i passi possibili di ognuno; i fatti («A che punto è») restano. Vale solo per il
 * condominio in cui è scelto. In nessuno dei due modi parte una lettera: aprire le pagine non scrive niente nel registro degli invii.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Debiti\PassoProposto;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function pitImposta(array $s, bool $acceso): void
{
    ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id], ['recupero_suggerisce_azione' => $acceso]);
}

it('acceso: il riquadro dice che propone, e ogni persona ha il passo e i possibili', function () {
    $s = smaViaVerdi($this);
    $r = smaRiquadro($s);
    $rossi = smaPersona(smaRiga($r, 'Esercizio 2025'), 'Rossi');

    expect($r['passo_proposto'])->toBeTrue()
        ->and($rossi['passo']['passo'])->toBe(PassoProposto::DIFFIDA)
        ->and($rossi['passo']['possibili'])->toBe([PassoProposto::SOLLECITO, PassoProposto::DIFFIDA, PassoProposto::FASCICOLO, PassoProposto::AGITO]);
});

it('spento: il riquadro non segna un passo, i passi possibili restano, e la frase dei fatti è la stessa', function () {
    $s = smaViaVerdi($this);
    $acceso = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), 'Rossi');
    pitImposta($s, false);
    $r = smaRiquadro($s);
    $rossi = smaPersona(smaRiga($r, 'Esercizio 2025'), 'Rossi');

    expect($r['passo_proposto'])->toBeFalse()
        ->and($rossi['passo']['passo'])->toBeNull()
        ->and($rossi['passo']['possibili'])->toBe($acceso['passo']['possibili'])
        ->and($rossi['passo']['frase'])->toBe($acceso['passo']['frase'])
        // Il fondo della riga non propone «Manda la diffida a N persone»: con «Scelgo io» c'è «Scrivi alle N persone della riga».
        ->and(collect(smaRiga($r, 'Esercizio 2025')['in_blocco'])->pluck('passo')->all())->not->toContain(PassoProposto::DIFFIDA);
});

it('la scelta vale solo per il condominio in cui è fatta', function () {
    $s = smaViaVerdi($this);
    $altro = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    pitImposta($altro, false);

    expect(smaRiquadro($s)['passo_proposto'])->toBeTrue()
        ->and(smaRiquadro($altro)['passo_proposto'])->toBeFalse();
});

it('in tutti e due i modi aprire la dashboard non scrive niente nel registro degli invii', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    $prima = RegistroInvio::count();

    $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']))->assertOk();
    pitImposta($s, false);
    $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']))->assertOk();

    expect(RegistroInvio::count())->toBe($prima);
});

it('spento, la persona nella tolleranza ha la frase dei fatti, non quella che propone il sollecito', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-01-02');
    \App\Models\Gestionale\RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2025-01-01', 'tasso_tipo' => 'legale', 'giorni_decorrenza' => 0, 'giorni_tolleranza' => 15]);
    $this->travelTo('2026-01-10 10:00:00');
    $acceso = smaRiquadro($s)['righe'][0]['persone'][0]['passo'];
    pitImposta($s, false);
    $spento = smaRiquadro($s)['righe'][0]['persone'][0]['passo'];

    expect($acceso['frase'])->toBe('Nella tolleranza di 15 giorni del condominio: il sollecito si propone dal 21/01/2026.')
        ->and($spento['passo'])->toBeNull()
        ->and($spento['frase'])->toBe('Nella tolleranza di 15 giorni del condominio, fino al 20/01/2026.');
});
