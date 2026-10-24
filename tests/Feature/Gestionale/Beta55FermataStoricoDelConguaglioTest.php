<?php

/**
 * 1.11.0-beta.55 — «Annulla il conguaglio» dello storico si offre solo dove la rotta lo accetta.
 *
 * Il conguaglio di una fine «Scrivi» non si annulla se una sua riga è fra le cifre da cui una successione con l'arretrato agli eredi ha calcolato l'arretrato del defunto
 * (`AnnullaConguaglioAction`: annullarlo lascerebbe sbagliate le righe degli eredi). Lo storico dell'unità offriva il comando lo stesso, con gli avvisi da seguire («correggi
 * dal Wallet i saldi che si modificano»), e la rotta lo rifiutava dopo: la regola era scritta due volte, e lo storico ne guardava una sola (l'arretrato agli eredi del
 * conguaglio stesso). Ora la regola è una (`AnnullaConguaglioAction::successioneCheLeggeLaCoppia`) e lo storico, dove non si può, dice la strada al posto del comando:
 * prima la successione, poi il conguaglio.
 *
 * Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). Ugo muore il 15/10 con l'arretrato agli eredi (Bruno ed Elsa al 37,5 %):
 * l'arretrato è calcolato anche sulle righe della coppia. Bruno vende il suo 37,5 % ad Anna il 1/12: 18148 × 37,5 × 31/18400 = 1146,58 → 1147, a carico di Bruno.
 *
 * Che cosa NON copre: il conguaglio già assorbito da un piano e le righe che non sommano zero (la rotta le rifiuta con le sue frasi, e lo storico non le ripete); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La voce di un passaggio nello storico che la pagina dell'unità («Titolari») dà alla scheda: dalla rotta, non dal servizio. */
function fer55StoricoDallaPagina($test, array $s, Subentro $passaggio): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['unita']]))->assertOk()->viewData('page')['props'];

    return collect($props['storico']['subentri'])->firstWhere('id', $passaggio->id) ?? [];
}

it('Annulla il conguaglio (una successione ha letto la coppia) — lo storico non offre il comando, dice la strada e non dà avvisi da seguire; la rotta lo rifiuta con le stesse parole', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));
    $vendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Anna', '2026-12-01', 37.5));

    $conguaglio = fer55StoricoDallaPagina($this, $s, $s['fine'])['conguaglio'];

    // La fine non ha l'arretrato agli eredi, ma una riga della sua coppia è fra le fonti di quello di Ugo: il comando non c'è, e la strada sì.
    expect($conguaglio['stato'])->toBe('proposto')
        ->and($conguaglio['annullabile'])->toBeFalse()
        ->and($conguaglio['avvisi'])->toBe([])
        ->and($conguaglio['motivo'])->toContain('è fra le cifre da cui è calcolato l\'arretrato di Ugo, passato agli eredi con la successione del 15 ottobre 2026')
        // Coda 357 (1.11.0-beta.55): la strada dice anche la vendita registrata dopo la successione, che va annullata prima di lei e registrata di nuovo (prima diceva solo
        // «Annulla prima la successione dallo storico dell'unità, poi questo conguaglio, e registra di nuovo la successione», e la successione non si annullava).
        ->and($conguaglio['motivo'])->toContain('Annulla prima la vendita del 1 dicembre 2026, poi la successione dallo storico dell\'unità, poi questo conguaglio, e registra di nuovo la successione e la vendita del 1 dicembre 2026;');

    // La rotta dice la stessa cosa: l'annullamento è rifiutato, e il conguaglio resta com'era.
    $r = sl53AnnullaConguaglio($this, $s, $s['fine']->fresh());
    expect(sl53Errori($r)['conguaglio'][0] ?? '')->toBe($conguaglio['motivo'])
        ->and($s['fine']->fresh()->conguaglioAnnullato())->toBeFalse();

    // La strada: prima la vendita, poi la successione (l'ultima registrata si annulla per prima); allora il comando c'è, con i suoi avvisi, e la rotta lo accetta. Annullata la
    // vendita, la strada dice di nuovo solo la successione.
    expect(sl53Errori(sl53Annulla($this, $s, $vendita)))->toBe([])
        ->and(fer55StoricoDallaPagina($this, $s, $s['fine'])['conguaglio']['motivo'])->toContain('Annulla prima la successione dallo storico dell\'unità, poi questo conguaglio, e registra di nuovo la successione;')
        ->and(sl53Errori(sl53Annulla($this, $s, $successione->fresh())))->toBe([]);
    $dopo = fer55StoricoDallaPagina($this, $s, $s['fine'])['conguaglio'];
    expect($dopo['annullabile'])->toBeTrue()
        ->and($dopo['motivo'] ?? null)->toBeNull();
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine']->fresh())))->toBe([])
        ->and($s['fine']->fresh()->conguaglioAnnullato())->toBeTrue();
});

it('Annulla il conguaglio (nessuna successione ha letto la coppia) — lo storico offre il comando con gli avvisi dei passaggi dopo, e la rotta lo accetta', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));

    $conguaglio = fer55StoricoDallaPagina($this, $s, $s['fine'])['conguaglio'];

    // Conto a mano: la vendita di Anna a Elsa (1/10) ferma 4537 × 92/184 = 2268,5 → 2268 a carico di Anna; l'avviso lo dice, e il conguaglio si annulla.
    expect($conguaglio['annullabile'])->toBeTrue()
        ->and($conguaglio['motivo'] ?? null)->toBeNull()
        ->and($conguaglio['avvisi'])->toHaveCount(1)
        ->and($conguaglio['avvisi'][0])->toContain('€ 22,68');
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine']->fresh())))->toBe([])
        ->and($s['fine']->fresh()->conguaglioAnnullato())->toBeTrue();
});

it('Annulla il conguaglio (l\'arretrato agli eredi del conguaglio stesso) — il comando non c\'è, come prima: la scheda dice la strada con il suo testo, e la rotta rifiuta', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));

    // Il conguaglio della successione e il suo arretrato fanno un conto solo: non si annulla da solo, e la strada è annullare il passaggio (il testo sta nella scheda).
    expect(fer55StoricoDallaPagina($this, $s, $successione)['conguaglio']['annullabile'])->toBeFalse();
    $r = sl53AnnullaConguaglio($this, $s, $successione->fresh());
    expect(sl53Errori($r)['conguaglio'][0] ?? '')->toContain('il conguaglio e l\'arretrato fanno un conto solo');
});

it('Annulla il conguaglio (l\'arretrato agli eredi, letto da una seconda successione) — la scheda dice ancora che coppia e arretrato fanno un conto solo, come la rotta, non la strada dell\'altra successione', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $prima = fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]));
    fer55Passo($this, $s, fer55Successione($s, 'Bruno', 'proprietario', '2026-10-18', 75, ['Anna' => 37.5, 'Dora' => 37.5]));

    // La riga della prima successione è fra le fonti dell'arretrato di Bruno, passato agli eredi con la seconda: la rotta lo rifiuta comunque per primo con l'arretrato agli eredi del
    // conguaglio stesso, e lo storico non dice un'altra strada (il testo di sempre sta nella scheda).
    $conguaglio = fer55StoricoDallaPagina($this, $s, $prima)['conguaglio'];
    expect($conguaglio['annullabile'])->toBeFalse()
        ->and($conguaglio['motivo'] ?? null)->toBeNull()
        ->and($conguaglio['avvisi'])->toBe([]);
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $prima->fresh()))['conguaglio'][0] ?? '')->toContain('il conguaglio e l\'arretrato fanno un conto solo');
});
