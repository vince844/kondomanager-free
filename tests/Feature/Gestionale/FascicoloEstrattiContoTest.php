<?php

/**
 * Gli estratti conto del fascicolo per il legale: uno per ogni esercizio **dei piani** delle rate del prospetto (`CompetenzaDelPiano::esercizioDelPiano`), non
 * quello della data dei sei mesi. In Via Verdi le rate 1 e 2 hanno la data dei sei mesi dell'esercizio 2025 (la scadenza è nel 2025), ma sono del piano
 * «Preventivo 2026», dell'esercizio 2026: l'estratto è quello del 2026, che le ha; il 2025 non le avrebbe. Con un piano dell'esercizio 2025 gli estratti sono due.
 * Il testo dell'estratto è quello della stampa della pagina per lo stesso esercizio.
 */

use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/FascicoloLegaleScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('un estratto per esercizio dei piani: le rate con la data dei sei mesi del 2025 e il piano del 2026 sono nell\'estratto del 2026', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);

    [, $file] = fslScarica($this, $s, 'rossi');
    $estratti = collect(array_keys($file))->filter(fn ($n) => str_starts_with($n, '02 '))->values()->all();
    $testo = fslTesto($file['02 Estratto conto 2026.pdf']);
    $rossi = $s['persone']['rossi'];
    $dellaPagina = fslTesto(app(\App\Services\Gestionale\EstrattoContoDellaPersona::class)->pdf($s['c'], $s['e'], $rossi));

    expect($estratti)->toBe(['02 Estratto conto 2026.pdf'])
        ->and($testo)->toContain('Esercizio: Esercizio 2026')->toContain('Rata n.1 (Scad. 05/11/2025)')->toContain('Rata n.2 (Scad. 05/12/2025)')
        ->and($testo)->toBe($dellaPagina);
});

it('con un piano dell\'esercizio 2025 gli estratti sono due, uno per esercizio dei piani; tolte le rate di un piano, il suo estratto non c\'è', function () {
    $s = fslScenario($this);
    // Le rate 1 e 2 passano a un piano dell'esercizio 2025, della stessa gestione.
    $piano2025 = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e2025']->id, 'nome' => 'Preventivo 2025',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2025-11-05', 'metodo_distribuzione' => 'prima_rata']);
    DB::table('rate')->whereIn('id', [lcdRata($s, 1), lcdRata($s, 2)])->update(['piano_rate_id' => $piano2025->id]);

    [, $file] = fslScarica($this, $s, 'rossi');
    expect(collect(array_keys($file))->filter(fn ($n) => str_starts_with($n, '02 '))->values()->all())->toBe(['02 Estratto conto 2025.pdf', '02 Estratto conto 2026.pdf']);

    [, $file] = fslScarica($this, $s, 'rossi', fslChiavi($s, 'rossi', [3, 4, 5]));
    expect(collect(array_keys($file))->filter(fn ($n) => str_starts_with($n, '02 '))->values()->all())->toBe(['02 Estratto conto 2026.pdf']);
});
