<?php

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * «A che cosa va il versamento» in una tabella compatta (decisione 206.1): il piano e la gestione si dicono una volta sola, in testa al loro
 * gruppo, e il nome della rata non ripete il nome del piano. Il programma scrive la descrizione delle rate come «Rata n.1 - <piano>»: la
 * ricevuta diceva «Rata 1 — Rata n.1 - <piano>, <piano> — <gestione>».
 *
 * Che cosa NON copre: la finestra (nei test JavaScript), l'aspetto del PDF (verifica a video).
 */
function compattaSecondoPiano(object $s, int $importo = 8000): RataQuote
{
    $piano = PianoRate::create(['condominio_id' => $s->condominio->id, 'gestione_id' => $s->gestione->id, 'nome' => 'Conguaglio 2025', 'numero_rate' => 1]);
    $rata = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-02-28', 'importo_totale' => $importo,
        'stato' => 'emessa', 'descrizione' => 'Rata n.1 - Conguaglio 2025']);

    return RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $s->anna->id, 'immobile_id' => $s->int4->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-02-28']);
}

/** Il testo del PDF di una ricevuta, com'è reso dal modello, su una riga. */
function compattaTestoDelPdf(object $s, array $contenuto): string
{
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => $contenuto, 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

test('il nome della rata non ripete il piano: «Rata 1», non «Rata 1 — Rata n.1 - Preventivo 2026»; una descrizione scritta a mano resta', function () {
    $s = scenarioRicevutaIncasso();
    $s->rate[1]->update(['descrizione' => 'Rata n.1 - Preventivo 2026']);
    $s->rate[2]->update(['descrizione' => 'Acconto di primavera']);
    $s->rate[3]->update(['descrizione' => 'Rata n.3 - Preventivo 2026']);
    $carlo3 = quotaDaPagareDiCarlo($s);

    // Carlo versa € 320,50 per Anna: € 150,00 la rata 1, € 120,50 la rata 2, € 50,00 riducono la rata 3 di Carlo.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00], [$s->anna2, 120.50]], '2026-04-01', 320.50, ['versato_da_id' => $s->carlo->id]);
    $r = app(RicevutaIncasso::class)->per($incasso->fresh());

    expect($carlo3->fresh()->importo_pagato)->toBe(5000)
        ->and(array_column($r['quote'], 'rata'))->toBe(['Rata 1', 'Rata 2 — Acconto di primavera'])
        ->and(array_column($r['quote'], 'piano'))->toBe(['Preventivo 2026', 'Preventivo 2026'])
        // Fuori dalla tabella il piano si dice una volta: «Rata 3, Preventivo 2026».
        ->and($r['eccedenze'][0]['rata'])->toBe('Rata 3, Preventivo 2026');
});

test('la regola del nome breve: toglie il piano ripetuto e il «Rata n.N» doppio, lascia quello che ha scritto l\'amministratore', function () {
    $piano = 'Piano rate per gestione ordinaria 2026 - 2027';

    expect(RicevutaIncasso::nomeBreve('Rata 1 — Rata n.1 - ' . $piano, $piano))->toBe('Rata 1')
        ->and(RicevutaIncasso::nomeBreve('Rata 1', $piano))->toBe('Rata 1')
        ->and(RicevutaIncasso::nomeBreve('Rata 2 — Rata n.2', null))->toBe('Rata 2')
        ->and(RicevutaIncasso::nomeBreve('Rata 2 — Rata n. 2 - ' . strtoupper($piano), $piano))->toBe('Rata 2')
        ->and(RicevutaIncasso::nomeBreve('Rata 3 — Acconto di gennaio', $piano))->toBe('Rata 3 — Acconto di gennaio')
        ->and(RicevutaIncasso::nomeBreve('Rata 3 — Acconto di gennaio - ' . $piano, $piano))->toBe('Rata 3 — Acconto di gennaio')
        // Il numero non è quello della rata: è un testo dell'amministratore, e resta.
        ->and(RicevutaIncasso::nomeBreve('Rata 4 — Rata n.1 bis', $piano))->toBe('Rata 4 — Rata n.1 bis')
        // Il piano è stato rinominato dopo: la descrizione ha il nome vecchio, che non si riconosce, e resta.
        ->and(RicevutaIncasso::nomeBreve('Rata 5 — Rata n.5 - Preventivo vecchio', $piano))->toBe('Rata 5 — Rata n.5 - Preventivo vecchio');
});

test('la finestra riceve per ogni quota la rata breve, il piano, la gestione, l\'unità e la scadenza: il raggruppamento lo fa con questi', function () {
    $s = scenarioRicevutaIncasso();
    $s->rate[1]->update(['descrizione' => 'Rata n.1 - Preventivo 2026']);
    $conguaglio = compattaSecondoPiano($s);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00], [$conguaglio, 80.00]], '2026-03-01', 230.00);

    $quote = $this->actingAs(amministratoreRicevute())
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->assertOk()->json('quote');

    expect(array_map(fn ($q) => [$q['rata'], $q['piano'], $q['gestione'], $q['unita'], $q['scadenza'], $q['imputato'], $q['dopo']], $quote))->toBe([
        ['Rata 1', 'Preventivo 2026', 'Ordinaria 2026', 'Int. 4', '2026-01-31', 15000, 0],
        ['Rata 1', 'Conguaglio 2025', 'Ordinaria 2026', 'Int. 4', '2026-02-28', 8000, 0],
    ]);
});

test('nel PDF il piano e la gestione stanno una volta in testa al loro gruppo, sopra i titoli delle colonne; le righe dicono la rata breve; il totale è in fondo', function () {
    $s = scenarioRicevutaIncasso();
    $s->rate[1]->update(['descrizione' => 'Rata n.1 - Preventivo 2026']);
    $s->rate[2]->update(['descrizione' => 'Rata n.2 - Preventivo 2026']);
    $conguaglio = compattaSecondoPiano($s);
    // € 350,50: € 150,00 la rata 1 e € 120,50 la rata 2 del preventivo, € 80,00 il conguaglio.
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00], [$s->anna2, 120.50], [$conguaglio, 80.00]], '2026-04-01', 350.50);

    $t = compattaTestoDelPdf($s, app(RicevutaIncasso::class)->per($incasso->fresh()));

    expect(substr_count($t, 'Preventivo 2026'))->toBe(1)
        ->and(substr_count($t, 'Conguaglio 2025'))->toBe(1)
        ->and($t)->toContain('Preventivo 2026 — Ordinaria 2026')
        ->and($t)->toContain('Conguaglio 2025 — Ordinaria 2026')
        ->and($t)->not->toContain('Rata n.')
        // Il gruppo del preventivo, i titoli delle colonne sotto il suo nome, le sue due righe; poi il conguaglio, con i suoi titoli: ogni riga con
        // unità, scadenza e le quattro cifre di oggi (Vincenzo, 05/11: i titoli delle colonne stanno sotto il nome del piano, non sopra).
        ->and(substr_count($t, 'Rata Unità Scadenza Quota Già versato Imputato Resta'))->toBe(2)
        ->and($t)->toMatch('/Preventivo 2026 — Ordinaria 2026 Rata Unità Scadenza Quota Già versato Imputato Resta Rata 1 Int\. 4 31\/01\/2026 € 150,00 € 0,00 € 150,00 € 0,00 Rata 2 Int\. 4 31\/03\/2026 € 120,50 € 0,00 € 120,50 € 0,00 Conguaglio 2025 — Ordinaria 2026 Rata Unità Scadenza Quota Già versato Imputato Resta Rata 1 Int\. 4 28\/02\/2026 € 80,00 € 0,00 € 80,00 € 0,00 Totale € 350,50 € 0,00 € 350,50 € 0,00/u');
});

test('una copia emessa prima della regola, con il nome lungo nel contenuto salvato, si legge con il nome breve nella finestra e nel PDF', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    // Il contenuto dell'originale come lo scriveva la versione di prima.
    $riga = RegistroInvio::sole();
    $dati = $riga->dati;
    $dati['contenuto']['quote'][0]['rata'] = 'Rata 1 — Rata n.1 - Preventivo 2026';
    $riga->update(['dati' => $dati]);

    $quote = $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->json('quote');
    $t = compattaTestoDelPdf($s, $dati['contenuto']);

    expect($quote[0]['rata'])->toBe('Rata 1')
        ->and($t)->not->toContain('Rata n.')
        ->and($t)->toContain('Preventivo 2026 — Ordinaria 2026 Rata Unità Scadenza Quota Già versato Imputato Resta Rata 1 Int. 4');
});
