<?php

/**
 * La prossima azione delle persone nella tesoreria: la regola dei sei mesi, vista tesoreria, per le
 * persone mostrate. A chi ha la diffida la tesoreria non suggerisce un sollecito, anche con un sollecito dopo; a chi non ha lettere e la data lontana sì. La
 * dispensa senza la casella lascia il sollecito; con «Togli anche il sollecito in tesoreria» lo toglie. Con «Scelgo io la prossima azione» nessuna azione è
 * segnata. La frase dell'accordo è quella della regola: una riga sola, non più `accordoTesto`.
 *
 * Via Verdi 12 (`SeiMesiPerAgireScenario.php`), oggi il 10/03/2026: scadute le rate 1 (05/11/2025), 2 (05/12/2025) e 3 (05/03/2026), € 100,00 l'una per
 * persona; la data dei sei mesi delle rate 1 e 2 è il 30/06/2026, fra 112 giorni (lontana).
 */

use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Debiti\PassoProposto;
use App\Services\Treasury\TreasuryGuardianService;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function tpsViaVerdi($test): array
{
    $s = smaViaVerdi($test);
    $test->travelTo('2026-03-10 10:00:00');

    return $s;
}

/** La riga della tesoreria di una persona, per nome («Rossi»). */
function tpsRiga(array $s, string $nome): ?array
{
    return collect(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->morositaImpattanti)->firstWhere('condomino', $nome);
}

it('chi ha la diffida non ha «Manda un sollecito», anche con un sollecito dopo la diffida; chi non ha lettere e la data lontana sì', function () {
    $s = tpsViaVerdi($this);
    smaLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3]);
    smaLettera($s, 'rossi', 'sollecito', 'spedita', '2026-03-05', [1, 2, 3]);

    $rossi = tpsRiga($s, 'Rossi');
    $bianchi = tpsRiga($s, 'Bianchi');
    expect($rossi['passo']['passo'])->not->toBe(PassoProposto::SOLLECITO)
        ->and($rossi['passo']['caso'])->toBe('R8')
        ->and($rossi['passo']['frase'])->toBe('Diffida spedita il 01/03/2026: il termine è il 16/03/2026.')
        ->and($bianchi['passo']['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($bianchi['passo']['caso'])->toBe('R15')
        ->and($bianchi['passo']['frase'])->toBe(PassoProposto::FRASE_NESSUNA_LETTERA);
});

it('la stessa prossima azione del riquadro dei sei mesi per la stessa persona', function () {
    $s = tpsViaVerdi($this);
    smaLettera($s, 'rossi', 'diffida', 'spedita', '2026-02-01', [1, 2, 3]);
    smaLettera($s, 'verdi', 'sollecito', 'stampata', '2026-03-02', [1, 2, 3]);
    $riquadro = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    foreach (['Rossi', 'Bianchi', 'Verdi', 'Neri'] as $nome) {
        $nelRiquadro = smaPersona($riquadro, $nome)['passo'];
        $inTesoreria = tpsRiga($s, $nome)['passo'];
        expect([$inTesoreria['caso'], $inTesoreria['passo'], $inTesoreria['frase']])->toBe([$nelRiquadro['caso'], $nelRiquadro['passo'], $nelRiquadro['frase']], $nome);
    }
});

it('con la dispensa senza la casella il sollecito resta; con «Togli anche il sollecito in tesoreria» no, e la frase lo dice', function () {
    $s = tpsViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['verdi'], false, '2026-03-01');
    smaDispensa($s, [$s['e2025'], $s['e']], ['neri'], true, '2026-03-01');

    $verdi = tpsRiga($s, 'Verdi');
    $neri = tpsRiga($s, 'Neri');
    expect($verdi['passo']['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($neri['passo']['passo'])->toBeNull()
        ->and($neri['passo']['frase'])->toBe('Dispensa dell\'assemblea del 01/03/2026: niente solleciti, come hai scelto.');
});

it('con «Scelgo io la prossima azione» nessuna azione è segnata e resta la frase dei fatti', function () {
    $s = tpsViaVerdi($this);
    $acceso = tpsRiga($s, 'Bianchi');
    ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id], ['recupero_suggerisce_azione' => false]);
    $t = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    $spento = collect($t['morositaImpattanti'])->firstWhere('condomino', 'Bianchi');

    expect($acceso['passo']['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($t['suggerisceAzione'])->toBeFalse()
        ->and($spento['passo']['passo'])->toBeNull()
        ->and($spento['passo']['frase'])->toBe($acceso['passo']['frase_dei_fatti']);
});

it('la frase dell\'accordo è quella della regola, una riga sola: non c\'è più `accordoTesto`', function () {
    $s = tpsViaVerdi($this);
    smaAccordo($s, 'neri', [1, 2, 3]);
    $neri = tpsRiga($s, 'Neri');

    expect($neri)->not->toHaveKey('accordoTesto')
        ->and($neri['passo']['caso'])->toBe('R3')
        ->and($neri['passo']['passo'])->toBeNull()
        ->and($neri['passo']['frase'])->toStartWith('Accordo di rientro del 10/01/2026, fino al 30/09/2026.');
});

it('la tesoreria legge solo le persone che mostra: con più di 8 persone, la prossima azione delle 5 mostrate', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, 12) as $i) {
        $unita["U{$i}"] = ["p{$i}", 10 * $i];
    }
    $s = lcdScenario($unita, 1, 'prima_rata', [], 100 * 10 * 78);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-03-01 10:00:00');
    $righe = app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->morositaImpattanti;

    expect($righe)->toHaveCount(5)
        ->and(collect($righe)->every(fn ($r) => $r['passo'] !== null && $r['passo']['passo'] === PassoProposto::SOLLECITO))->toBeTrue();
});
