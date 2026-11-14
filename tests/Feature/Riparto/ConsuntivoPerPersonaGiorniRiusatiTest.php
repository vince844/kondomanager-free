<?php

/**
 * Nel consuntivo, le fatture straordinarie con la competenza su un giorno solo riusano i pesi fra i giorni in cui i titolari non cambiano: le cifre
 * sono quelle della lettura senza riuso, e una fattura dopo un passaggio va a chi è titolare quel giorno.
 *
 * Via Verdi, gestione straordinaria, «Facciata» su Generali (500, 300, 200); U2 di Bianchi fino al 30/06, di Rossi dal 01/07. Cinque fatture con la
 * competenza scritta, resti maggiori per fattura: 15/03 € 1.000,00 → 500,00 / 300,00 / 200,00; 20/04 € 500,01 → 250,005 / 150,003 / 100,002, il
 * centesimo a Neri (,5); 30/06 € 333,33 → 166,665 / 99,999 / 66,666: 166,66, 100,00 (Bianchi, ,9), 66,67 (Verdi, ,6); 01/07 € 777,77 → 388,885 /
 * 233,331 / 155,554: 388,89 a Neri (,5), 233,33 a Rossi, 155,55; 11/11 € 100,00 → 50,00 / 30,00 (Rossi) / 20,00. Neri 1.355,56, Bianchi 550,00, Rossi
 * 263,33, Verdi 542,22: € 2.711,11.
 */

use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ConsuntivoPerPersonaScenario.php';

uses(RefreshDatabase::class);

it('le fatture straordinarie di giorni diversi senza un passaggio in mezzo riusano i pesi: le cifre sono quelle senza riuso, e dopo il passaggio vanno a chi entra', function () {
    $s = cpmViaVerdi('straordinaria');
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    $righe = [];
    foreach ([['2026-03-15', 100000], ['2026-04-20', 50001], ['2026-06-30', 33333], ['2026-07-01', 77777], ['2026-11-11', 10000]] as [$giorno, $importo]) {
        $righe[] = ['conto_id' => $facciata->id, 'importo' => $importo, 'competenza_dal' => $giorno, 'competenza_al' => $giorno];
    }
    $riusati = cpmPorta($s, $righe);
    // Con le righe di spiegazione di tutti i pesi non si riusano fra i giorni: è la lettura di riferimento.
    $senzaRiuso = cpmPorta($s, $righe, ['righe_per' => array_map(fn ($p) => $p->id, array_values($s['persone']))]);

    expect(array_map(fn ($chi) => cpmDi($riusati, $s['persone'][$chi], $facciata), ['Neri', 'Bianchi', 'Rossi', 'Verdi']))->toBe([135556, 55000, 26333, 54222])
        ->and($riusati['persone'])->toEqual($senzaRiuso['persone'])
        ->and($riusati['non_attribuite'])->toBe([]);
});

it('con i passaggi in giorni diversi, due tabelle e l\'inquilino per un tratto, i pesi di ogni unità si rifanno solo dove cambia chi c\'è: le cifre sono quelle senza riuso', function () {
    $s = cpmViaVerdi('straordinaria');
    // U3 passa da Verdi a Gialli il 15/09; U1 ha un inquilino, Blu, dal 01/04 al 31/08: tre unità con i cambi in giorni diversi.
    $gialli = \App\Models\Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-riusi@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'CPMGIARIUSI000001']);
    $blu = \App\Models\Anagrafica::forceCreate(['nome' => 'Blu', 'email' => 'blu-riusi@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'CPMBLURIUSI000001']);
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['Verdi']->id)->update(['data_fine' => '2026-09-14']);
    foreach ([[$gialli, 'U3', 'proprietario', '2026-09-15', null], [$blu, 'U1', 'inquilino', '2026-04-01', '2026-08-31']] as [$chi, $u, $ruolo, $dal, $al]) {
        \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $chi->id, 'immobile_id' => $s['unita'][$u]->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
    $scala = cpmTabella($s, 'Scala', ['U1' => 600, 'U2' => 400]);
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    // «Ascensore» su due tabelle, con la parte dell'inquilino: dove l'inquilino non c'è la sua parte va al proprietario, due addizioni sulla stessa persona.
    $ascensore = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['pianoConto']->id, 'nome' => 'Ascensore', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    foreach ([[$s['generali'], 60], [$scala, 40]] as [$tabella, $coefficiente]) {
        $ctm = \Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $ascensore->id, 'tabella_id' => $tabella->id, 'coefficiente' => $coefficiente, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['proprietario', 70], ['inquilino', 30]] as [$soggetto, $percentuale]) {
            \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $righe = [];
    $giorni = ['2026-01-20', '2026-03-31', '2026-04-01', '2026-05-15', '2026-06-30', '2026-07-01', '2026-08-31', '2026-09-01', '2026-09-14', '2026-09-15', '2026-10-10', '2026-12-31'];
    foreach ($giorni as $k => $giorno) {
        foreach ([[$facciata, 33333 + 7 * $k], [$ascensore, 77777 + 11 * $k]] as [$voce, $importo]) {
            $righe[] = ['conto_id' => $voce->id, 'importo' => $importo, 'competenza_dal' => $giorno, 'competenza_al' => $giorno];
        }
    }
    $riusati = cpmPorta($s, $righe);
    $tutti = array_merge(array_map(fn ($p) => $p->id, array_values($s['persone'])), [$gialli->id, $blu->id]);
    $senzaRiuso = cpmPorta($s, $righe, ['righe_per' => $tutti]);

    expect($riusati['persone'])->toEqual($senzaRiuso['persone'])
        ->and($riusati['non_attribuite'])->toBe($senzaRiuso['non_attribuite'])
        ->and($riusati['motivi'])->toBe($senzaRiuso['motivi'])
        // Blu paga solo nei suoi giorni, Gialli solo dal 15/09, Verdi fino al 14/09.
        ->and(cpmDi($riusati, $blu, $ascensore))->toBeGreaterThan(0)
        ->and(cpmDi($riusati, $blu, $facciata))->toBe(0)
        // Gialli, 200 millesimi della «Facciata» il 15/09 (€ 333,96 → 66,792), il 10/10 (€ 334,03 → 66,806, il centesimo del resto) e il 31/12 (€ 334,10 → 66,82).
        ->and(cpmDi($riusati, $gialli, $facciata))->toBe(6679 + 6681 + 6682);
    $sommaAscensore = 0;
    foreach ($giorni as $k => $giorno) {
        $sommaAscensore += 77777 + 11 * $k;
    }
    expect(cpmSommaVoce($riusati, $ascensore))->toBe($sommaAscensore);
});
