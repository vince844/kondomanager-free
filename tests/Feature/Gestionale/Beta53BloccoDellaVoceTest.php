<?php

/**
 * Il blocco dei piani approvati su una voce del piano dei conti: l'importo, il padre, il tipo, la tabella e le percentuali, e l'eliminazione.
 *
 * Una voce che un piano approvato (o emesso, o chiuso) tiene, con la sua riga o con quella di un capitolo sopra di lei, non cambia importo, padre né
 * tipo (`Conto::pianiCheBloccanoLaVoce`), e non si sposta sotto un capitolo che un piano così tiene: il motore la raggiungerebbe in un altro modo, e il
 * piano addebiterebbe un'altra cifra. Il rifiuto è lo stesso per i tre campi, sotto il primo cambiato, e dice la strada che dalla pagina si fa davvero:
 * riportare prima il piano in bozza dalla sua pagina, con quello che costa (`BloccoDellaVoce`). L'eliminazione della voce segue la stessa regola.
 *
 * Gli scenari: «Manutenzione» (capitolo) → «Impianti» → «Rimborso», € 100,00 sulla tabella «Proprietà», poi riscritto a −10000 (al terzo livello, dato
 * delle versioni fino alla 1.9.1); «Pulizie» (capitolo) → «Scale» € 500,00; le voci di `b53snBase`. Il piano «Preventivo B» tiene le righe date, con le
 * copie date; il piano «Ordinario 2026», in bozza, tiene «Spese generali».
 *
 * La prova indipendente è il motore stesso, riletto in sola lettura (`b53snRipartito`), e il totale della pagina del piano: un rifiuto lascia tutti e
 * due com'erano. La controprova percorre la strada del rifiuto dalle rotte: con il piano in bozza le stesse richieste passano.
 *
 * Fonte dell'atteso: la decisione di Vincenzo del 19/10/2026 sul blocco dei piani approvati (padre e tipo come l'importo, la stessa frase, la strada
 * della bozza) e i conti a mano degli scenari.
 *
 * Cosa resta scoperto: la scheda della voce (la provano i vitest di `ModalModificaConto`); un piano senza voci proprie, dei piani più vecchi; i
 * database diversi da sqlite.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BloccoDellaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il rifiuto per una voce che il piano «Preventivo B», approvato, tiene attraverso il capitolo «Impianti». */
const B53BV_RIFIUTO_IMPIANTI = 'Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: '
    .'riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: '
    .'annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

// ─── Il padre e il tipo di una voce che un piano approvato tiene attraverso un capitolo ──────────────────────────

it('il sottoconto al terzo livello che un piano approvato tiene attraverso il suo padre: il padre, il tipo e l\'importo si rifiutano con la stessa frase, e il piano non cambia', function (string $tipo, string $importo, string $campo) {
    $s = b53bvScenario($this, ['impianti' => '300,00']);
    $prima = b53bvPiano($this, $s, $s['b']);
    $voce = b53bvVoce($s['rimborso']);

    b53snModificaVoce($this, $s, $s['rimborso'], $tipo, $importo, (int) $s['capitolo']->id);

    expect($prima['motore'])->toBe([(int) $s['rimborso']->id => 30000])
        ->and(b53snErrori())->toBe([$campo => [B53BV_RIFIUTO_IMPIANTI]])
        ->and(b53bvVoce($s['rimborso']))->toBe($voce)
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
})->with([
    'il solo padre' => ['spesa', '-100,00', 'parent_id'],
    'il provento con il padre nuovo' => ['entrata', '-100,00', 'tipo'],
    'la correzione con il padre nuovo' => ['spesa', '100,00', 'importo'],
]);

it('controprova — con il piano riportato in bozza dalla sua pagina le stesse modifiche passano', function (string $tipo, string $importo) {
    $s = b53bvScenario($this, ['impianti' => '300,00']);

    b53bvInBozza($this, $s, $s['b']);
    b53snModificaVoce($this, $s, $s['rimborso'], $tipo, $importo, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect((int) b53bvVoce($s['rimborso'])['parent_id'])->toBe((int) $s['capitolo']->id)
        ->and(b53bvVoce($s['rimborso'])['tipo'])->toBe($tipo);
})->with([
    'il solo padre' => ['spesa', '-100,00'],
    'il provento con il padre nuovo' => ['entrata', '-100,00'],
    'la correzione con il padre nuovo' => ['spesa', '100,00'],
]);

it('il nuovo padre che lo stesso piano approvato tiene: il rifiuto dice tutte e due le ragioni, e il piano non cambia', function (string $copiaPulizie) {
    $s = b53bvScenario($this, ['impianti' => '300,00', 'pulizie' => $copiaPulizie]);
    $prima = b53bvPiano($this, $s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['pulizie']->id);

    expect($prima['motore'])->toBe([(int) $s['rimborso']->id => 30000, (int) $s['scale']->id => 50000])
        ->and(b53snErrori())->toBe(['tipo' => ['Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nel piano '
            .'«Preventivo B», approvato, e la voce non si sposta sotto «Pulizie» finché il capitolo è nel piano «Preventivo B», approvato: riporta prima il piano in bozza '
            .'dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle '
            .'rate emesse, prima vanno annullate le emissioni.']])
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);

    // La strada della frase: il piano in bozza, poi la modifica passa.
    b53bvInBozza($this, $s, $s['b']);
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['pulizie']->id)->assertSessionHasNoErrors();
})->with(['la copia € 500,00' => ['500,00'], 'la copia nulla' => ['']]);

it('il piano che tiene il capitolo di primo livello con le sue tabelle e il capitolo sotto di lui: il provento si rifiuta verso qualunque padre, e il piano non cambia', function (string $padre) {
    $s = b53snBase($this);
    $voce = fn (string $nome, int $importo, ?int $padre, bool $capitolo) => Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre, 'is_capitolo' => $capitolo]);
    $s['capitolo'] = $voce('Manutenzione', 0, null, true);
    b53bvLega($s, (int) $s['capitolo']->id);
    $s['impianti'] = $voce('Impianti', 0, $s['capitolo']->id, true);
    $s['rimborso'] = $voce('Rimborso', 10000, $s['impianti']->id, false);
    b53bvLega($s, (int) $s['rimborso']->id);
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    // Il piano dei dati di prima: due righe, una sopra l'altra, scritte com'erano.
    $s['b'] = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo B', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-06-05', 'metodo_distribuzione' => 'prima_rata',
    ]);
    foreach ([$s['capitolo']->id => 20000, $s['impianti']->id => 30000] as $id => $copia) {
        DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['b']->id, 'conto_id' => $id, 'importo' => $copia, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('piani_rate')->where('id', $s['b']->id)->update(['stato' => 'approvato']);
    $s['b'] = $s['b']->fresh();
    $s['piano'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['generali']->id], 'capitoli_config' => [['id' => $s['generali']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();
    $prima = b53snRipartito($s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s[$padre]->id);

    expect($prima)->toBe([(int) $s['capitolo']->id => 20000, (int) $s['rimborso']->id => 30000])
        ->and(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché i suoi capitoli «Manutenzione» e «Impianti» sono nel piano «Preventivo B», approvato: riporta prima il piano in bozza')
        ->and(b53bvVoce($s['rimborso'])['tipo'])->toBe('spesa')
        ->and(b53snRipartito($s, $s['b']))->toBe($prima);
})->with(['il padre «Manutenzione»' => ['capitolo'], 'il padre «Spese generali»' => ['generali']]);

it('due piani approvati tengono il capitolo: il rifiuto li nomina al plurale; riportato in bozza uno solo, il rifiuto nomina l\'altro; con tutti e due in bozza la modifica passa', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00'], true, ['Integrativo C' => ['impianti' => '50,00']]);
    $c = $s['altri']['Integrativo C'];
    $prima = [b53bvPiano($this, $s, $s['b']), b53bvPiano($this, $s, $c)];

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);

    expect([$prima[0]['motore'], $prima[1]['motore']])->toBe([[(int) $s['rimborso']->id => 30000], [(int) $s['rimborso']->id => 5000]])
        ->and(b53snErrori())->toBe(['tipo' => ['Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nei piani '
            .'«Preventivo B» e «Integrativo C», approvati: riporta prima i piani in bozza dalle loro pagine, poi modifica la voce. Riportandoli in bozza si azzerano il '
            .'numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.']])
        ->and([b53bvPiano($this, $s, $s['b']), b53bvPiano($this, $s, $c)])->toBe($prima);

    b53bvInBozza($this, $s, $s['b']);
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toContain('finché il suo capitolo «Impianti» è nel piano «Integrativo C», approvato: riporta prima il piano in bozza dalla sua pagina');

    b53bvInBozza($this, $s, $c);
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
});

it('il piano approvato con la prima rata emessa: il provento si rifiuta, le quote e il totale restano', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00']);
    b52tEmetti($this, $s, $s['b'], 1, false);
    $quote = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['b']->id)->sum('rate_quote.importo');
    $prima = b53bvPiano($this, $s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);

    expect(b53snErrori())->toBe(['tipo' => [B53BV_RIFIUTO_IMPIANTI]])
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['b']->id)->sum('rate_quote.importo'))->toBe($quote);
});

// ─── Il sottoconto di secondo livello e la riga della voce ──────────────────────────────────────────────────────

it('il sottoconto di un capitolo che un piano approvato tiene con la copia: il provento si rifiuta, e il piano non lo accredita', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00'], true, [], true);
    $prima = b53bvPiano($this, $s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['impianti']->id);

    expect($prima['motore'])->toBe([(int) $s['rimborso']->id => 30000])
        ->and(b53snErrori())->toBe(['tipo' => [B53BV_RIFIUTO_IMPIANTI]])
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
});

it('la voce con la sua riga in un piano approvato: il provento con un padre nuovo si rifiuta, e il piano non cambia', function (string $copia) {
    $s = b53bvScenario($this, ['rimborso' => $copia]);
    $prima = b53bvPiano($this, $s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);

    expect(b53snErrori())->toBe(['tipo' => ['Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché la voce è nel piano «Preventivo B», approvato: '
        .'riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: '
        .'annotali prima; con delle rate emesse, prima vanno annullate le emissioni.']])
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
})->with(['la copia € 300,00' => ['300,00'], 'la copia a zero' => ['0,00']]);

it('l\'importo del sottoconto al terzo livello che un piano approvato tiene attraverso il capitolo di primo livello si rifiuta, come quello del padre diretto', function () {
    $s = b53bvScenario($this, ['capitolo' => '300,00']);
    $prima = b53bvPiano($this, $s, $s['b']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id);

    expect($prima['motore'])->toBe([(int) $s['rimborso']->id => 30000])
        ->and(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Manutenzione» è nel piano «Preventivo B», approvato')
        ->and((int) b53bvVoce($s['rimborso'])['importo'])->toBe(-10000)
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
});

// ─── Il padre nuovo che un piano approvato tiene ────────────────────────────────────────────────────────────────

it('una voce che nessun piano approvato tiene non si sposta sotto un capitolo che un piano approvato tiene: il piano non la prende', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $prima = b53bvPiano($this, $s, $s['b']);
    $generali = $s['generali'];

    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $generali]), [
        'nome' => 'Spese generali', 'codice' => '', 'tipo' => 'spesa', 'importo' => '1.200,00', 'descrizione' => '', 'note' => '', 'parent_id' => $s['pulizie']->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ]);

    expect($prima['motore'])->toBe([(int) $s['scale']->id => 50000])
        ->and(b53snErrori())->toBe(['parent_id' => ['Modifica inibita: la voce non si sposta sotto «Pulizie» finché il capitolo è nel piano «Preventivo B», approvato: '
            .'riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: '
            .'annotali prima; con delle rate emesse, prima vanno annullate le emissioni.']])
        ->and(DB::table('conti')->where('id', $generali->id)->value('parent_id'))->toBeNull()
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
});

it('controprova — una voce che nessun piano approvato tiene si sposta sotto un capitolo libero e cambia tipo, come prima', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $antenna = $s['antenna'];

    b53snModificaVoce($this, $s, $s['rimborso']->fresh(), 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $antenna]), [
        'nome' => 'Affitto antenna', 'codice' => '', 'tipo' => 'spesa', 'importo' => '240,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    expect(b53bvVoce($s['rimborso']))->toBe(['tipo' => 'spesa', 'importo' => 10000, 'parent_id' => (int) $s['capitolo']->id])
        ->and(DB::table('conti')->where('id', $antenna->id)->value('tipo'))->toBe('spesa');
});

// ─── L'eliminazione ─────────────────────────────────────────────────────────────────────────────────────────────

/** L'eliminazione della voce dal piano dei conti: il messaggio di sessione. */
function b53bvElimina($test, array $s, Conto $voce): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $voce]));

    return b53snFlash();
}

it('il sottoconto che un piano approvato tiene attraverso il capitolo non si elimina, e il piano non cambia; in bozza si elimina', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $prima = b53bvPiano($this, $s, $s['b']);

    expect(b53bvElimina($this, $s, $s['scale']))->toBe(['type' => 'error', 'message' => 'Eliminazione inibita: la voce non si elimina finché il suo capitolo «Pulizie» è nel piano '
        .'«Preventivo B», approvato: riporta prima il piano in bozza dalla sua pagina, poi elimina la voce. Riportandolo in bozza si azzerano il numero del verbale e la '
        .'nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.'])
        ->and(Conto::find($s['scale']->id))->not->toBeNull()
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);

    b53bvInBozza($this, $s, $s['b']);
    expect(b53bvElimina($this, $s, $s['scale'])['type'] ?? null)->toBe('success')
        ->and(Conto::find($s['scale']->id))->toBeNull();
});

it('la voce con la sua riga in un piano approvato non si elimina: era già rifiutata, ora con la frase della modifica', function () {
    $s = b53bvScenario($this, ['scale' => '500,00']);

    expect(b53bvElimina($this, $s, $s['scale'])['message'] ?? null)->toStartWith('Eliminazione inibita: la voce non si elimina finché è nel piano «Preventivo B», approvato: riporta prima il piano in bozza')
        ->and(Conto::find($s['scale']->id))->not->toBeNull();
});

it('la voce e il suo capitolo nello stesso piano approvato: la frase dell\'eliminazione li nomina insieme, senza ripetere «la voce»', function () {
    $s = b53bvScenario($this, ['pulizie' => '', 'scale' => '500,00']);

    expect(b53bvElimina($this, $s, $s['scale'])['message'] ?? null)->toStartWith('Eliminazione inibita: la voce non si elimina finché essa e il suo capitolo «Pulizie» sono nel piano '
        .'«Preventivo B», approvato: riporta prima il piano in bozza')
        ->and(Conto::find($s['scale']->id))->not->toBeNull();
});

// ─── Le righe di fattura: la bozza non le toglie ────────────────────────────────────────────────────────────────

/** Registra `$quante` righe di fattura sulla voce, in una fattura sola: una voce con delle righe di fattura non si elimina. */
function b53bvRigheDiFattura(array $s, Conto $voce, int $quante = 1): void
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Ditta Prova', 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $fattura = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura', 'numero_documento' => 'F'.$voce->id,
        'data_documento' => now(), 'data_scadenza' => now(), 'importo_imponibile' => 10000, 'importo_iva' => 0, 'netto_a_pagare' => 10000, 'totale_documento' => 10000,
        'stato_approvazione' => 'approvata', 'stato_pagamento' => 'da_pagare', 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($i = 0; $i < $quante; $i++) {
        DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura, 'conto_id' => $voce->id, 'descrizione' => 'Pulizia scale', 'importo_imponibile' => 10000,
            'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('la voce con delle righe di fattura non si elimina, anche se un piano approvato la tiene: la frase dice le righe, non propone la bozza, e nessun errore SQL arriva alla pagina', function (int $righe, string $frase) {
    $s = b53bvScenario($this, ['scale' => '500,00']);
    b53bvRigheDiFattura($s, $s['scale'], $righe);
    $prima = b53bvPiano($this, $s, $s['b']);

    expect(b53bvElimina($this, $s, $s['scale']))->toBe(['type' => 'error', 'message' => $frase])
        ->and(Conto::find($s['scale']->id))->not->toBeNull()
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);

    // La bozza non le toglie: la risposta è la stessa, e non è l'errore del database.
    b53bvInBozza($this, $s, $s['b']);
    expect(b53bvElimina($this, $s, $s['scale']))->toBe(['type' => 'error', 'message' => $frase])
        ->and(Conto::find($s['scale']->id))->not->toBeNull();
})->with([
    'una riga' => [1, 'Eliminazione inibita: la voce ha 1 riga di fattura registrata, e una voce con delle righe di fattura non si elimina: la voce resta.'],
    'due righe' => [2, 'Eliminazione inibita: la voce ha 2 righe di fattura registrate, e una voce con delle righe di fattura non si elimina: la voce resta.'],
]);

it('un errore del database nell\'eliminazione della voce non arriva alla pagina: la frase dice che l\'eliminazione non è riuscita, e la voce resta', function () {
    $s = b53bvScenario($this, ['pulizie' => ''], false);
    DB::unprepared("CREATE TRIGGER conti_senza_eliminazione BEFORE DELETE ON conti BEGIN SELECT RAISE(ABORT, 'SQLSTATE[HY000]: guasto del database'); END");

    try {
        $flash = b53bvElimina($this, $s, $s['scale']);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS conti_senza_eliminazione');
    }

    expect($flash)->toBe(['type' => 'error', 'message' => 'Si è verificato un errore durante l\'eliminazione della voce di spesa.'])
        ->and(Conto::find($s['scale']->id))->not->toBeNull();
});

it('la voce con delle righe di fattura e nessun piano che la tiene non si elimina, con la stessa frase', function () {
    $s = b53bvScenario($this, ['pulizie' => ''], false);
    b53bvRigheDiFattura($s, $s['scale']);

    expect(b53bvElimina($this, $s, $s['scale']))->toBe(['type' => 'error', 'message' => 'Eliminazione inibita: la voce ha 1 riga di fattura registrata, e una voce con delle '
        .'righe di fattura non si elimina: la voce resta.'])
        ->and(Conto::find($s['scale']->id))->not->toBeNull();
});

// ─── Le parole ──────────────────────────────────────────────────────────────────────────────────────────────────

it('il passaggio di prima della 1.11.0-beta.37 ferma la bozza: il rifiuto non la propone e dice che la voce resta com\'è', function () {
    $bloccano = [['id' => 7, 'nome' => 'Preventivo B', 'capitolo' => 'Impianti', 'movimenti' => ['passaggio_senza_registro']]];

    expect(BloccoDellaVoce::rifiutoDellaModifica($bloccano))->toBe('Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo '
        .'«Impianti» è nel piano «Preventivo B», approvato, e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che dal programma non si annulla, ferma '
        .'l\'annullamento delle emissioni del piano: la voce resta com\'è.')
        ->and(BloccoDellaVoce::rifiutoDellEliminazione($bloccano))->toEndWith('ferma l\'annullamento delle emissioni del piano: la voce resta.');
});

it('chi il piano tiene: la voce, il suo capitolo, tutti e due, due capitoli', function () {
    $p = fn (?string $capitolo) => ['id' => 7, 'nome' => 'Preventivo B', 'capitolo' => $capitolo, 'movimenti' => []];

    expect(BloccoDellaVoce::chi([$p(null)]))->toBe('la voce è')
        ->and(BloccoDellaVoce::chi([$p('Impianti')]))->toBe('il suo capitolo «Impianti» è')
        ->and(BloccoDellaVoce::chi([$p(null), $p('Impianti')], 'il sottoconto'))->toBe('il sottoconto e il suo capitolo «Impianti» sono')
        ->and(BloccoDellaVoce::chi([$p('Manutenzione'), $p('Impianti')]))->toBe('i suoi capitoli «Manutenzione» e «Impianti» sono');
});

it('chi il piano tiene, per una frase che ha già detto di che voce parla: «è», «essa e il suo capitolo … sono»', function () {
    $p = fn (?string $capitolo) => ['id' => 7, 'nome' => 'Preventivo B', 'capitolo' => $capitolo, 'movimenti' => []];

    expect(BloccoDellaVoce::chi([$p(null)], 'la voce', false))->toBe('è')
        ->and(BloccoDellaVoce::chi([$p('Impianti')], 'la voce', false))->toBe('il suo capitolo «Impianti» è')
        ->and(BloccoDellaVoce::chi([$p(null), $p('Impianti')], 'la voce', false))->toBe('essa e il suo capitolo «Impianti» sono');
});

it('la voce che un piano approvato tiene non diventa un capitolo: l\'importo andrebbe a zero, e il piano la perderebbe', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['b'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '', 'note' => '']]], 'Preventivo B');
    b52tApprova($this, $s, $s['b']);
    $prima = b53bvPiano($this, $s, $s['b']);

    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['rimborso']]), [
        'nome' => 'Rimborso vecchio', 'codice' => '', 'tipo' => 'spesa', 'importo' => 0, 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null,
        'isCapitolo' => true, 'isSottoConto' => false, 'richiedeGiaVersato' => false, 'confermaConversioneCapitolo' => true,
    ]);

    expect($prima)->toBe(['motore' => [(int) $s['rimborso']->id => 24000], 'totale' => 24000])
        ->and(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita: l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché la voce è nel piano «Preventivo B», approvato')
        ->and((array) DB::table('conti')->where('id', $s['rimborso']->id)->first(['importo', 'is_capitolo']))->toBe(['importo' => 24000, 'is_capitolo' => 0])
        ->and(b53bvPiano($this, $s, $s['b']))->toBe($prima);
});

/** L'eliminazione del piano dei conti dall'elenco dei piani dei conti: il messaggio di sessione. */
function b53bvEliminaIlPianoDeiConti($test, array $s): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/piani-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.destroy', [$s['c'], $s['e'], $s['pc']]));

    return b53snFlash();
}

it('il piano dei conti con delle voci in un piano approvato non si elimina, anche con una rata emessa, e il piano non perde le sue righe; in bozza si elimina', function (bool $emessa) {
    $s = b53bvScenario($this, ['pulizie' => '']);
    if ($emessa) {
        b52tEmetti($this, $s, $s['b'], 1, false);
    }
    $prima = b53snRipartito($s, $s['b']);

    expect(b53bvEliminaIlPianoDeiConti($this, $s))->toBe(['type' => 'error', 'message' => 'Eliminazione inibita: il piano dei conti non si elimina finché delle sue voci '
        .'sono nel piano «Preventivo B», approvato: riporta prima il piano in bozza dalla sua pagina, poi elimina il piano dei conti. Riportandolo in bozza si azzerano '
        .'il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.'])
        ->and(DB::table('piani_conti')->where('id', $s['pc']->id)->exists())->toBeTrue()
        ->and(b53snRipartito($s, $s['b']))->toBe($prima)
        ->and($prima)->toBe([(int) $s['scale']->id => 50000]);

    if (! $emessa) {
        b53bvInBozza($this, $s, $s['b']);
        expect(b53bvEliminaIlPianoDeiConti($this, $s)['type'] ?? null)->toBe('success')
            ->and(DB::table('piani_conti')->where('id', $s['pc']->id)->exists())->toBeFalse();
    }
})->with(['senza rate emesse' => [false], 'con la prima rata emessa' => [true]]);

it('il piano dei conti con delle voci che hanno righe di fattura non si elimina, con il piano approvato o riportato in bozza: la frase dice le righe, e il piano dei conti resta', function (int $voci, string $frase) {
    $s = b53bvScenario($this, ['scale' => '500,00']);
    b53bvRigheDiFattura($s, $s['scale']);
    if ($voci === 2) {
        b53bvRigheDiFattura($s, $s['rimborso']);
    }

    expect(b53bvEliminaIlPianoDeiConti($this, $s))->toBe(['type' => 'error', 'message' => $frase])
        ->and(DB::table('piani_conti')->where('id', $s['pc']->id)->exists())->toBeTrue();

    b53bvInBozza($this, $s, $s['b']);
    expect(b53bvEliminaIlPianoDeiConti($this, $s))->toBe(['type' => 'error', 'message' => $frase])
        ->and(DB::table('piani_conti')->where('id', $s['pc']->id)->exists())->toBeTrue();
})->with([
    'una voce' => [1, 'Eliminazione inibita: 1 voce del piano dei conti ha righe di fattura registrate, e una voce con delle righe di fattura non si elimina: il piano dei conti resta.'],
    'due voci' => [2, 'Eliminazione inibita: 2 voci del piano dei conti hanno righe di fattura registrate, e una voce con delle righe di fattura non si elimina: il piano dei conti resta.'],
]);

it('il sottoconto al terzo livello che un piano approvato tiene, salvato con il padre com\'è: il rifiuto del terzo livello dice prima la bozza, e la strada passa dalle rotte', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '-100,00', (int) $s['impianti']->id);

    expect(b53snErrori())->toBe(['parent_id' => ['Questa voce si trova al terzo livello, che il piano dei conti non prevede: è un residuo delle versioni precedenti, e '
        .'l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: riporta prima il piano in '
        .'bozza dalla sua pagina, poi scegli come capitolo padre una voce di primo livello. Riportandolo in bozza si azzerano '
        .'il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.']]);

    // La strada della frase: il piano in bozza, poi il capitolo padre di primo livello.
    b53bvInBozza($this, $s, $s['b']);
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '-100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect((int) b53bvVoce($s['rimborso'])['parent_id'])->toBe((int) $s['capitolo']->id);
});

it('controprova — il sottoconto al terzo livello che nessun piano approvato tiene: il rifiuto del terzo livello resta quello di prima', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00'], false);

    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '-100,00', (int) $s['impianti']->id);

    expect(b53snErrori())->toBe(['parent_id' => ['Questa voce si trova al terzo livello, che il piano dei conti non prevede: è un residuo delle versioni precedenti. '
        .'Per salvare, scegli come capitolo padre una voce di primo livello.']]);
});

it('il sottoconto al terzo livello con delle righe di fattura: la frase indica il capitolo padre di primo livello, la sola strada che passa, e non propone di eliminarlo', function (bool $tenuto) {
    $s = b53bvScenario($this, $tenuto ? ['impianti' => '300,00'] : ['generali' => ''], $tenuto);
    b53bvRigheDiFattura($s, $s['rimborso']);

    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '-100,00', (int) $s['impianti']->id);

    expect(b53snErrori()['parent_id'][0])->toContain('scegli come capitolo padre una voce di primo livello')->not->toContain('elimina');

    // La strada della frase: la bozza, se un piano tiene la voce, poi il capitolo padre di primo livello; l'eliminazione, invece, si rifiuta per le righe.
    if ($tenuto) {
        b53bvInBozza($this, $s, $s['b']);
    }
    expect(b53bvElimina($this, $s, $s['rimborso'])['message'] ?? null)->toStartWith('Eliminazione inibita: la voce ha 1 riga di fattura registrata');
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '-100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect((int) b53bvVoce($s['rimborso'])['parent_id'])->toBe((int) $s['capitolo']->id);
})->with(['tenuto da un piano approvato' => [true], 'nessun piano lo tiene' => [false]]);

// ─── La scheda della voce e l'elenco dei capitoli padre ─────────────────────────────────────────────────────────

it('la riga del piano dei conti porta il blocco della voce con la regola del salvataggio: i piani e la frase per la voce tenuta, niente per le altre', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00']);
    $blocco = fn (string $nome) => array_key_exists('blocco', $riga = b53snRiga($this, $s, $nome)) ? $riga['blocco'] : 'manca';

    expect($blocco('Rimborso'))->toBe(['piani' => [['id' => (int) $s['b']->id, 'nome' => 'Preventivo B']], 'frase' => 'L\'importo, il padre, il tipo, '
        .'la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: riporta prima il piano in bozza dalla sua pagina, poi '
        .'modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno '
        .'annullate le emissioni.'])
        ->and($blocco('Impianti')['piani'] ?? null)->toBe([['id' => (int) $s['b']->id, 'nome' => 'Preventivo B']])
        ->and($blocco('Scale'))->toBeNull()
        ->and($blocco('Spese generali'))->toBeNull();
});

it('il sottoconto nato dopo il piano che tiene il suo capitolo: la scheda lo blocca come il salvataggio', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $androne = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Androne', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000, 'parent_id' => $s['pulizie']->id, 'is_capitolo' => false]);
    b53bvLega($s, (int) $androne->id);
    DB::table('conti')->where('id', $androne->id)->update(['created_at' => $s['b']->created_at->copy()->addDay()]);

    // Il salvataggio lo rifiuta, e la scheda lo sa.
    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $androne]), [
        'nome' => 'Androne', 'codice' => '', 'tipo' => 'spesa', 'importo' => '300,00', 'descrizione' => '', 'note' => '', 'parent_id' => $s['pulizie']->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ]);

    expect(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(b53snRiga($this, $s, 'Androne')['blocco']['piani'] ?? null)->toBe([['id' => (int) $s['b']->id, 'nome' => 'Preventivo B']]);
});

it('l\'elenco dei capitoli padre dice quali tiene un piano approvato', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);

    $r = $this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-conti', ['condominio' => $s['c']->id, 'piano_conto_id' => $s['pc']->id, 'conto_id' => $s['rimborso']->id]));

    expect(collect($r->json())->map(fn ($c) => [$c['nome'], $c['blocco']])->all())->toBe([
        ['Manutenzione', null],
        ['Pulizie', 'nel piano «Preventivo B», approvato'],
    ]);
});
