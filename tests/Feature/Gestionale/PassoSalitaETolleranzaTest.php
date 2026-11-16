<?php

/**
 * Il condominio sceglie che cosa succede dopo una lettera quando scadono rate nuove (la scheda «Impostazioni»): «La salita va avanti» (di serie) o «Per le rate
 * nuove si ricomincia»; il riquadro dei sei mesi la legge per ogni persona. E il confronto con il suggerimento «oltre la tolleranza senza una lettera»
 * dell'elenco «Rate scadute», che usa la stessa condizione della tolleranza.
 *
 * Il caso: quattro persone con le rate mensili dal 05/01/2026 (€ 100,00 a rata), oggi il 14/11/2026, la data dei sei mesi il 30/06/2027 (lontana), 15 giorni di
 * tolleranza. Rossi senza lettere; Bianchi con un sollecito spedito il 20/10 per le rate fino a ottobre (la rata del 05/11 è nella tolleranza); Verdi con un
 * sollecito spedito il 01/10 per le rate fino ad agosto (settembre e ottobre sono oltre la tolleranza); Neri con un promemoria per email del 01/11.
 */

use App\Models\Gestionale\RegolaRecupero;
use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Debiti\PassoProposto;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 250], 'B' => ['bianchi', 250], 'C' => ['verdi', 250], 'D' => ['neri', 250]], 12, 'prima_rata', [], 480000);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2025-01-01', 'tasso_tipo' => 'legale', 'giorni_decorrenza' => 0, 'giorni_tolleranza' => 15]);
    smaLettera($s, 'bianchi', 'sollecito', 'spedita', '2026-10-20', range(1, 10));
    smaLettera($s, 'verdi', 'sollecito', 'spedita', '2026-10-01', range(1, 8));
    smaLettera($s, 'neri', 'promemoria', 'inviata', '2026-11-01', range(1, 11));
    $this->travelTo('2026-11-14 10:00:00');
    $this->s = $s;
});

/** Il caso e il passo di ognuno nel riquadro, per nome. */
function pstPassi(array $s): array
{
    $passi = app(SeiMesiPerAgire::class)->passi($s['c'], PassoProposto::RIQUADRO);
    $esito = [];
    foreach ($s['persone'] as $chi => $p) {
        $esito[$chi] = [$passi[$p->id]['caso'], $passi[$p->id]['passo']];
    }

    return $esito;
}

/** Le persone del suggerimento «oltre la tolleranza» dell'elenco «Rate scadute», per nome. */
function pstSuggerimento($test, array $s): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', $s['c']))->assertOk()->viewData('page')['props'];
    $ids = collect($props['suggerimenti'])->firstWhere('chiave', 'oltre_tolleranza')['persone'] ?? [];

    return collect($s['persone'])->filter(fn ($p) => in_array($p->id, $ids, true))->keys()->sort()->values()->all();
}

function pstSollecitoDaR15(array $passi): array
{
    return collect($passi)->filter(fn ($x) => $x === ['R15', PassoProposto::SOLLECITO])->keys()->sort()->values()->all();
}

it('con la salita (di serie): Bianchi e Verdi alla diffida, con le note delle rate dopo; Rossi e Neri al sollecito', function () {
    $passi = pstPassi($this->s);
    $persone = collect(app(SeiMesiPerAgire::class)->perCondominio($this->s['c'])['righe'][0]['persone'])->keyBy('nome');

    expect($passi)->toBe(['rossi' => ['R15', 'sollecito'], 'bianchi' => ['R13', 'diffida'], 'verdi' => ['R13', 'diffida'], 'neri' => ['R15', 'sollecito']])
        ->and($persone['Bianchi']['passo']['note'])->toBe(['La rata del 05/11/2026, € 100,00, non è nel sollecito: è nella tolleranza.'])
        ->and($persone['Verdi']['passo']['note'])->toBe(['Il sollecito del 01/10/2026 non comprende € 200,00 di rate scadute dopo.',
            'La rata del 05/11/2026, € 100,00, non è nel sollecito: è nella tolleranza.']);
});

it('ricominciando, scelto nelle impostazioni del condominio: Verdi torna al sollecito per le rate oltre la tolleranza; Bianchi resta alla diffida', function () {
    ImpostazioniCondominio::create(['condominio_id' => $this->s['c']->id, 'recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => true]);

    expect(pstPassi($this->s))->toBe(['rossi' => ['R15', 'sollecito'], 'bianchi' => ['R13', 'diffida'], 'verdi' => ['R15', 'sollecito'], 'neri' => ['R15', 'sollecito']]);
});

it('il suggerimento «oltre la tolleranza» e il sollecito della regola: le persone del suggerimento hanno tutte il sollecito; la regola ne propone di più, e la differenza è detta', function () {
    $suggerimento = pstSuggerimento($this, $this->s);
    $avanti = pstSollecitoDaR15(pstPassi($this->s));

    // Il suggerimento conta chi non ha una lettera dopo la sua rata più vecchia oltre la tolleranza: Rossi. Il sollecito della regola va anche a chi ha
    // avuto solo un promemoria (Neri): per il suggerimento il promemoria è una lettera, per la regola non conta come un sollecito.
    expect($suggerimento)->toBe(['rossi'])
        ->and($avanti)->toBe(['neri', 'rossi'])
        ->and(array_diff($suggerimento, $avanti))->toBe([]);

    // Ricominciando, la regola manda il sollecito anche a chi ha rate scadute dopo il suo sollecito e oltre la tolleranza (Verdi), che il suggerimento non conta
    // perché una lettera c'è. Bianchi, con la sola rata nella tolleranza, non è in nessuno dei due: è il caso che prima li divideva.
    ImpostazioniCondominio::create(['condominio_id' => $this->s['c']->id, 'recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => true]);
    $ricomincia = pstSollecitoDaR15(pstPassi($this->s));
    expect($ricomincia)->toBe(['neri', 'rossi', 'verdi'])
        ->and(pstSuggerimento($this, $this->s))->toBe(['rossi'])
        ->and($ricomincia)->not->toContain('bianchi');
});
