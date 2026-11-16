<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il riquadro «Sei mesi per agire» della dashboard del condominio: una riga per esercizio con le rate scadute la cui data dei sei mesi (la più vicina)
 * viene da quell'esercizio, con la data, i giorni, le persone aperte e la cifra «di questo esercizio»; le persone chiuse (la dispensa su tutte le loro rate
 * della riga, «hai agito» ancora valido) in fondo; le rate senza data a parte, con il perché. Le cifre vengono dalla lettura dell'elenco «Rate scadute», a
 * blocchi di persone, e la somma per persona torna con lo scaduto della tesoreria.
 *
 * Lo scenario è Via Verdi 12 (`SeiMesiPerAgireScenario.php`): oggi 20/05/2026, quattro persone, € 200,00 a testa dell'esercizio 2025 (30/06/2026, fra 41
 * giorni) e € 300,00 del 2026 (30/06/2027, fra 406).
 */

use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('una riga per esercizio, in ordine di data: il 2025 aperto fra 41 giorni, il 2026 «più avanti» fra 406; le cifre di questo esercizio', function () {
    $s = smaViaVerdi($this);
    $r = smaRiquadro($s);

    expect(collect($r['righe'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2025', 'Esercizio 2026']);
    $r25 = smaRiga($r, 'Esercizio 2025');
    $r26 = smaRiga($r, 'Esercizio 2026');
    expect($r25)->toMatchArray(['data' => '2026-06-30', 'giorni' => 41, 'colore' => 'vicina', 'aperta' => true, 'piu_avanti' => false, 'quante' => 4, 'cifra' => 80000,
        'chiave' => 'sei-mesi-' . $s['e2025']->id])
        ->and($r26)->toMatchArray(['data' => '2027-06-30', 'giorni' => 406, 'colore' => 'lontana', 'aperta' => false, 'piu_avanti' => true, 'quante' => 4, 'cifra' => 120000])
        ->and(smaPersona($r25, 'Rossi')['cifra'])->toBe(20000)
        ->and(smaPersona($r26, 'Rossi')['cifra'])->toBe(30000)
        ->and($r['senza_data'])->toBeNull();
});

it('la data dei sei mesi porta l\'esercizio da cui viene (`piu_vicina_esercizio`), senza rifare la scelta', function () {
    $s = smaViaVerdi($this);
    $calcolo = app(SeiMesi::class)->perCondominio((int) $s['c']->id);
    $righe = collect(lcdLeggi($s, 'rossi')['rate'])->keyBy('numero_rata');

    expect($calcolo->perRiga($righe[1], (int) $s['persone']['rossi']->id)['piu_vicina_esercizio'])->toMatchArray(['id' => $s['e2025']->id, 'nome' => 'Esercizio 2025'])
        ->and($calcolo->perRiga($righe[3], (int) $s['persone']['rossi']->id)['piu_vicina_esercizio'])->toMatchArray(['id' => $s['e']->id, 'nome' => 'Esercizio 2026']);
});

it('una rata con la data dalla competenza prima di quella dalla scadenza va nella riga dell\'esercizio della competenza', function () {
    $s = smaViaVerdi($this);
    // Il piano diventa di competenza del 2025: le rate 3, 4 e 5 (scadute nel 2026) hanno la data dalla competenza al 30/06/2026, più vicina del 30/06/2027.
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => $s['e2025']->id]);
    $r = smaRiquadro($s);

    expect(collect($r['righe'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2025'])
        ->and(smaPersona(smaRiga($r, 'Esercizio 2025'), 'Rossi')['cifra'])->toBe(50000);
});

it('la dispensa su tutte le date delle rate chiude la persona nella riga: in fondo, con la frase; il cartellino «dispensata»', function () {
    $s = smaViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['rossi']);
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect($r25['quante'])->toBe(3)->and($r25['cifra'])->toBe(60000)
        ->and(smaPersona($r25, 'Rossi'))->toBeNull()
        ->and(collect($r25['chiuse'])->firstWhere('nome', 'Rossi'))->toMatchArray(['motivo' => 'dispensa', 'cifra' => 20000,
            'frase' => 'Dispensa dell\'assemblea del 15/03/2026 (verbale n. 2): per queste rate il programma non suggerisce azioni.'])
        ->and($r25['cartellini']['dispensata'])->toBe(1);
});

it('la dispensa su una sola delle due date sposta la rata nella riga dell\'altra data, dove non è dispensata', function () {
    $s = smaViaVerdi($this);
    // Le rate 1 e 2: la data dalla scadenza (esercizio 2025) è dispensata, quella dalla competenza (esercizio del piano, 2026) no.
    smaDispensa($s, [$s['e2025']]);
    $r = smaRiquadro($s);

    expect(collect($r['righe'])->pluck('esercizio.nome')->all())->toBe(['Esercizio 2026'])
        ->and(smaPersona(smaRiga($r, 'Esercizio 2026'), 'Rossi')['cifra'])->toBe(50000)
        ->and(smaRiga($r, 'Esercizio 2026')['chiuse'])->toBe([]);
});

it('«hai agito» chiude la persona nella riga; torna con una rata nuova dell\'esercizio, e la frase dice il giorno in cui hai registrato', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'pratica data all\'avv. Gallo'])->assertSessionHasNoErrors();
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect(smaPersona($r25, 'Rossi'))->toBeNull()
        ->and(collect($r25['chiuse'])->firstWhere('nome', 'Rossi'))->toMatchArray(['motivo' => 'agito',
            'frase' => 'Hai agito il 12/05/2026: «pratica data all\'avv. Gallo» (Vincenzo).'])
        // Nell'altra riga Rossi resta aperto.
        ->and(smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2026'), 'Rossi'))->not->toBeNull();

    // Il 25/05 la rata 6 ha la scadenza spostata nel 2025: è una rata nuova della riga.
    $this->travelTo('2026-05-25 10:00:00');
    smaSposta($s, 6, '2025-12-20');
    $rossi = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), 'Rossi');

    expect($rossi)->not->toBeNull()
        ->and($rossi['cifra'])->toBe(30000)
        ->and($rossi['torna'])->toBe('Dopo che hai registrato (il 20/05/2026), c\'è una rata nuova di questo esercizio: € 100,00.');
});

it('«hai agito» e poi la cifra della riga che sale (un incasso annullato): la persona torna, con le due cifre', function () {
    $s = smaViaVerdi($this);
    $incasso = lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 1) => 4000], '2026-05-20');
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-20', 'nota' => 'telefonata'])->assertSessionHasNoErrors();
    lcdStorna($this, $s, $incasso);
    $rossi = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), 'Rossi');

    expect($rossi['torna'])->toBe('Dopo che hai registrato (il 20/05/2026), le rate di questo esercizio sono salite da € 160,00 a € 200,00.');
});

it('i cartellini della riga e la situazione di ogni persona', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    smaLettera($s, 'neri', 'diffida', 'stampata', '2026-05-10', [1, 2, 3, 4, 5]);
    smaLettera($s, 'rossi', 'sollecito', 'inviata', '2026-05-01', [1, 2, 3, 4, 5]);
    smaAccordo($s, 'verdi', [1, 2, 3, 4, 5]);
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect($r25['cartellini'])->toBe(['senza_lettera' => 0, 'promemoria' => 0, 'sollecito' => 1, 'diffida_spedita' => 1, 'diffida_da_spedire' => 1, 'accordo' => 1, 'dispensata' => 0])
        ->and(collect($r25['tutte'])->pluck('situazione', 'nome')->sortKeys()->all())->toBe(['Bianchi' => 'diffida', 'Neri' => 'diffida', 'Rossi' => 'sollecito', 'Verdi' => 'accordo']);
});

it('il passo di una persona è uno, uguale in ogni riga in cui compare: anche per chi, con le sole rate della riga lontana, avrebbe un altro passo', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    smaLettera($s, 'neri', 'diffida', 'stampata', '2026-05-10', [1, 2, 3, 4, 5]);
    smaAccordo($s, 'verdi', [1, 2]);
    $r = smaRiquadro($s);
    $r25 = smaRiga($r, 'Esercizio 2025');
    $r26 = smaRiga($r, 'Esercizio 2026');

    // Ogni persona che sta nelle due righe: lo stesso passo, la stessa frase, gli stessi possibili.
    foreach (['Rossi', 'Bianchi', 'Verdi', 'Neri'] as $nome) {
        expect(smaPersona($r26, $nome)['passo'])->toBe(smaPersona($r25, $nome)['passo'], $nome);
    }
    // Rossi: con le sole rate del 2026 (30/06/2027, fra 406 giorni) la regola darebbe il sollecito; con tutte le sue rate aperte la data più vicina è il
    // 30/06/2026, e il passo è la diffida in tutte e due le righe.
    expect(smaPersona($r26, 'Rossi')['passo']['caso'])->toBe('R11')
        ->and(smaPersona($r26, 'Rossi')['passo']['passo'])->toBe('diffida')
        ->and(smaPersona($r26, 'Rossi')['passo']['frase'])->toBe('Nessuna lettera; la data dei sei mesi è il 30/06/2026.')
        ->and(smaPersona($r25, 'Bianchi')['passo']['caso'])->toBe('R9');
});

it('chi ha avuto solo un promemoria non è «senza lettera»: ha la sua situazione e il suo cartellino', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'rossi', 'promemoria', 'inviata', '2026-04-01', [3]);
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');

    expect(collect($r25['tutte'])->firstWhere('nome', 'Rossi')['situazione'])->toBe('promemoria')
        ->and($r25['cartellini']['promemoria'])->toBe(1)
        ->and($r25['cartellini']['senza_lettera'])->toBe(3);
});

it('una riga con tutte le persone chiuse non è la riga aperta di serie: si apre la prima con qualcuno da seguire', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id,
        'persone' => array_map(fn ($p) => $p->id, array_values($s['persone'])), 'giorno' => '2026-05-19', 'nota' => 'diffide a tutti'])->assertSessionHasNoErrors();
    $r = smaRiquadro($s);

    expect(smaRiga($r, 'Esercizio 2025')['quante'])->toBe(0)
        ->and(smaRiga($r, 'Esercizio 2025')['aperta'])->toBeFalse();
});

it('i pulsanti in blocco: un passo che almeno due persone della riga hanno, con le persone e le lettere da spedire', function () {
    $s = smaViaVerdi($this);
    $b = smaLettera($s, 'bianchi', 'diffida', 'stampata', '2026-05-10', [1, 2, 3, 4, 5]);
    $n = smaLettera($s, 'neri', 'diffida', 'stampata', '2026-05-11', [1, 2, 3, 4, 5]);
    $r25 = smaRiga(smaRiquadro($s), 'Esercizio 2025');
    $blocchi = collect($r25['in_blocco'])->keyBy('passo');

    expect($blocchi->keys()->sort()->values()->all())->toBe(['diffida', 'spedizione'])
        ->and($blocchi['diffida']['persone'])->toEqualCanonicalizing([$s['persone']['rossi']->id, $s['persone']['verdi']->id])
        ->and($blocchi['spedizione']['persone'])->toEqualCanonicalizing([$s['persone']['bianchi']->id, $s['persone']['neri']->id])
        ->and($blocchi['spedizione']['lettere'])->toEqualCanonicalizing([$b->id, $n->id]);
});

it('oltre 8 persone la riga ne mostra 5, le cifre più alte, e dice quante mancano e la loro cifra; fino a 8 tutte', function (int $quante, int $mostrate) {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, $quante) as $i) {
        $unita["U{$i}"] = ["p{$i}", $i];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 50000 * $quante);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-05-20 10:00:00');
    $riga = smaRiquadro($s)['righe'][0];
    $nomi = collect($riga['tutte'])->sortByDesc('cifra')->pluck('nome')->values();

    expect($riga['quante'])->toBe($quante)
        ->and($riga['tutte'])->toHaveCount($quante)
        ->and($riga['persone'])->toHaveCount($mostrate)
        ->and(collect($riga['persone'])->pluck('nome')->all())->toBe($nomi->take($mostrate)->all())
        ->and($riga['oltre'])->toBe($quante > 8
            ? ['mancano' => $quante - 5, 'cifra' => (int) collect($riga['tutte'])->whereNotIn('nome', $nomi->take(5)->all())->sum('cifra')]
            : null);
})->with([[9, 5], [8, 8]]);

it('il condominio importato con il pregresso di tre esercizi d\'origine: tre righe passate in cima, ognuna con il suo compito; chi è senza origine sta fra le rate senza data', function () {
    $this->travelTo('2026-01-20 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 250], 'B' => ['bianchi', 250], 'C' => ['ugo', 250], 'D' => ['neri', 250]], 12, 'rata_zero',
        [['rossi', 'A', 30000], ['bianchi', 'B', 20000], ['ugo', 'C', 50000], ['neri', 'D', 10000]], 120000, '2026-01-31', 31);
    lcdEmetti($this, $s, [0], '2026-01-20');
    foreach ([2022, 2023, 2024] as $anno) {
        $s["e{$anno}"] = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01",
            'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
    }
    $saldo = fn (string $chi) => (int) DB::table('saldi')->where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
    foreach (['rossi' => 2022, 'bianchi' => 2023, 'ugo' => 2024] as $chi => $anno) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.origine', ['condominio' => $s['c']->id]), ['saldi' => [$saldo($chi)], 'origine' => 'esercizio',
            'esercizio_origine_id' => $s["e{$anno}"]->id])->assertSessionHasNoErrors();
    }
    $this->travelTo('2026-02-05 10:00:00');
    $r = smaRiquadro($s);

    expect(collect($r['righe'])->map(fn ($x) => [$x['esercizio']['nome'], $x['data'], $x['colore'], $x['compito']])->all())->toBe([
        ['Esercizio 2022', '2023-06-30', 'passata', true],
        ['Esercizio 2023', '2024-06-30', 'passata', true],
        ['Esercizio 2024', '2025-06-30', 'passata', true],
    ])
        ->and(collect($r['compiti'])->where('argomento', 'sei_mesi')->count())->toBe(3)
        ->and($r['senza_data'])->toMatchArray(['rate' => 1, 'persone' => 1, 'cifra' => 10000])
        ->and($r['senza_data']['perche'])->toBe(['Origine non dichiarata: le date non si calcolano.']);

    // La prova incrociata: per ogni persona, la somma delle sue cifre «di questo esercizio» più le sue rate senza data è il suo scaduto in tesoreria.
    $tesoreria = collect(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->morositaImpattanti)->pluck('importoCents', 'anagraficaId');
    $perPersona = [];
    foreach ($r['righe'] as $riga) {
        foreach ([...$riga['tutte'], ...$riga['chiuse']] as $p) {
            $perPersona[$p['anagrafica_id']] = ($perPersona[$p['anagrafica_id']] ?? 0) + $p['cifra'];
        }
    }
    foreach ($r['senza_data']['per_persona'] as $id => $cifra) {
        $perPersona[$id] = ($perPersona[$id] ?? 0) + $cifra;
    }
    ksort($perPersona);
    expect($perPersona)->toBe($tesoreria->sortKeys()->all());
});

it('la prova incrociata con la tesoreria su Via Verdi: per ogni persona le cifre dei due esercizi sono il suo scaduto', function () {
    $s = smaViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['rossi']);
    $r = smaRiquadro($s);
    $tesoreria = collect(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->morositaImpattanti)->pluck('importoCents', 'anagraficaId');
    $perPersona = [];
    foreach ($r['righe'] as $riga) {
        foreach ([...$riga['tutte'], ...$riga['chiuse']] as $p) {
            $perPersona[$p['anagrafica_id']] = ($perPersona[$p['anagrafica_id']] ?? 0) + $p['cifra'];
        }
    }
    ksort($perPersona);

    expect($perPersona)->toBe($tesoreria->sortKeys()->all());
});

it('la lettura a blocchi di persone è uguale a quella di un blocco solo', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    smaAccordo($s, 'verdi', [1, 2]);

    expect(app(SeiMesiPerAgire::class)->perCondominio($s['c'], null, 1)['righe'])->toEqual(app(SeiMesiPerAgire::class)->perCondominio($s['c'])['righe']);
});

it('vuoto: nessuna riga, nessun compito', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    $r = smaRiquadro($s);

    expect($r['righe'])->toBe([])->and($r['senza_data'])->toBeNull()->and(collect($r['compiti'])->where('argomento', 'sei_mesi')->all())->toBe([]);
});
