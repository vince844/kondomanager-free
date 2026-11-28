<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Chi paga che cosa con le percentuali per soggetto di una voce, dalle rotte della pagina alle rate: dodici casistiche del riparto che non chiedono
 * nessuna scelta, con gli importi per persona al centesimo.
 *
 * Il condominio: Interno 1 (600 millesimi) di Rossi e Interno 2 (400 millesimi) di Bianchi sulla tabella «Proprietà»; la voce «Pulizie», € 1.000,00,
 * sulla gestione ordinaria 2026. Su Interno 1 ogni caso aggiunge l'inquilino (Verdi) e l'usufruttuario (Neri); con l'usufrutto Rossi è il nudo
 * proprietario. Su Interno 2 non c'è nessun altro: la parte di un ruolo che manca torna a Bianchi.
 *
 * L'atteso è fatto a mano con le regole del programma: la riga assente vale 0 (le righe a zero non si salvano), un ruolo che sull'unità non c'è
 * risale la catena fino al proprietario, la somma delle quote è l'importo ripartito al centesimo. Le terne con il proprietario a 0 si rileggono
 * dalle props della pagina del piano dei conti, come le riapre la finestra (il componente è provato da `ModalAssociaTabella.test.ts`).
 *
 * Che cosa non copre: i decimali, la somma oltre il 100 nei dati vecchi, gli accordi su una sola unità, i canali degli errori delle finestre, le
 * altre stampe; i database diversi da sqlite.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CondominioService;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il condominio dei casi: due unità, la tabella «Proprietà», la voce «Pulizie» € 1.000,00 al 100 % al proprietario. */
function b15rpsScenario($test): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, '1', 'rossi', $s['tabella'], 600.0);
    b52tUnita($s, '2', 'bianchi', $s['tabella'], 400.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti 2026']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $s['voce'] = b52tVoce($test, $s, 'Pulizie', 'spesa', '1.000,00', $s['tabella']);

    return $s;
}

/** Su Interno 1 un inquilino, un usufruttuario o tutti e due; con l'usufruttuario Rossi diventa il nudo proprietario. */
function b15rpsSuInterno1(array &$s, array $ruoli): void
{
    if (in_array('usufruttuario', $ruoli, true)) {
        DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['1']->id)->where('anagrafica_id', $s['persone']['rossi']->id)->update(['tipologia' => 'nuda_proprietario']);
    }
    foreach ($ruoli as $ruolo) {
        $chi = $ruolo === 'inquilino' ? 'verdi' : 'neri';
        $s['persone'][$chi] = tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "b15rps-{$chi}{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(str_pad("B15{$chi}", 8, 'X')).str_pad((string) $s['seq'], 8, '0', STR_PAD_LEFT)]),
            fn (Anagrafica $a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']['1']->id, 'tipologia' => $ruolo, 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}

/** La terna (proprietario, inquilino, usufruttuario) come la manda la finestra, per «Associa» o per «Modifica associazione tabella». */
function b15rpsTerna(array $terna, float $coefficiente = 100): array
{
    return ['coefficiente' => $coefficiente, 'percentuale_proprietario' => $terna[0], 'percentuale_inquilino' => $terna[1], 'percentuale_usufruttuario' => $terna[2]];
}

/** «Modifica associazione tabella» dalla sua rotta; restituisce l'esito (il messaggio della pagina). */
function b15rpsAggiorna($test, array $s, array $terna, float $coefficiente = 100, ?Tabella $tabella = null): array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella',
        [$s['c'], $s['e'], $s['pc'], $s['voce'], $tabella ?? $s['tabella']]), b15rpsTerna($terna, $coefficiente))->assertRedirect('/piano-dei-conti');

    return session('message') ?? [];
}

/** «Associa» dalla sua rotta. */
function b15rpsAssocia($test, array $s, Tabella $tabella, array $terna, float $coefficiente): array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella',
        [$s['c'], $s['e'], $s['pc'], $s['voce']]), ['tabella_millesimale_id' => $tabella->id] + b15rpsTerna($terna, $coefficiente))->assertRedirect('/piano-dei-conti');

    return session('message') ?? [];
}

/** Le tabelle della voce come le legge la pagina del piano dei conti (le props che la finestra riapre): tabella => [coefficiente, soggetto => %]. */
function b15rpsDallaPagina($test, array $s): array
{
    $conti = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]))->assertOk()
        ->viewData('page')['props']['conti'];
    $voce = collect(json_decode(json_encode($conti), true))->firstWhere('id', $s['voce']->id);

    return collect($voce['tabelle_millesimali'])->mapWithKeys(fn (array $t) => [$t['tabella']['nome'] => [
        (float) $t['coefficiente'], collect($t['ripartizioni'])->mapWithKeys(fn (array $r) => [$r['soggetto'] => (float) $r['percentuale']])->sortKeys()->all(),
    ]])->all();
}

/** Il piano dalla pagina «Nuovo piano rate» e le quote per persona, in centesimi. */
function b15rpsQuote($test, array $s, array $extra = []): array
{
    return b52tSommeQuote(b52tPiano($test, $s, $extra));
}

/** Una tabella in più del condominio, con i millesimi di Interno 1 e Interno 2. */
function b15rpsTabella(array $s, string $nome, float $uno, float $due, ?Condominio $condominio = null): Tabella
{
    $t = Tabella::create(['condominio_id' => ($condominio ?? $s['c'])->id, 'nome' => $nome, 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    if ($condominio === null) {
        foreach (['1' => $uno, '2' => $due] as $sigla => $valore) {
            DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $s['unita'][$sigla]->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    return $t;
}

/** Una terna salvata, riletta dalla pagina com'è, rimandata com'è stata riletta: le righe a zero non ci sono e il salvato non cambia. */
function b15rpsSalvaERiapre($test, array $s, array $terna): void
{
    expect(b15rpsAggiorna($test, $s, $terna)['type'] ?? null)->toBe('success');
    $attese = collect(['proprietario' => $terna[0], 'inquilino' => $terna[1], 'usufruttuario' => $terna[2]])->filter()->map(fn ($p) => (float) $p)->sortKeys()->all();
    $letta = b15rpsDallaPagina($test, $s)['Proprietà'];
    expect($letta)->toBe([100.0, $attese]);

    // Come la riapre la finestra: una riga assente vale 0 se ce n'è un'altra.
    expect(b15rpsAggiorna($test, $s, [$letta[1]['proprietario'] ?? 0, $letta[1]['inquilino'] ?? 0, $letta[1]['usufruttuario'] ?? 0])['type'] ?? null)->toBe('success')
        ->and(b15rpsDallaPagina($test, $s)['Proprietà'])->toBe($letta);
}

dataset('b15 riparto per soggetto', [
    'proprietario 100 % con l\'inquilino attivo' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino']);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Rossi' => 60000]];
    }],
    '50/50 fra proprietario e inquilino, per persona' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino']);
        expect(b15rpsAggiorna($test, $s, [50, 50, 0])['type'])->toBe('success');

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Rossi' => 30000, 'Verdi' => 30000]];
    }],
    '33/33/34 con i tre ruoli attivi' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino', 'usufruttuario']);
        expect(b15rpsAggiorna($test, $s, [33, 33, 34])['type'])->toBe('success');

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Neri' => 20400, 'Rossi' => 19800, 'Verdi' => 19800]];
    }],
    '0/100/0 salvato, riletto dalla pagina e dato al motore' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino']);
        b15rpsSalvaERiapre($test, $s, [0, 100, 0]);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Verdi' => 60000]];
    }],
    '0/0/100 salvato, riletto dalla pagina e dato al motore' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino', 'usufruttuario']);
        b15rpsSalvaERiapre($test, $s, [0, 0, 100]);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Neri' => 60000]];
    }],
    '0/50/50 salvato, riletto dalla pagina e dato al motore' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino', 'usufruttuario']);
        b15rpsSalvaERiapre($test, $s, [0, 50, 50]);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Neri' => 30000, 'Verdi' => 30000]];
    }],
    'due tabelle con i coefficienti 60 e 40, ognuna con le sue percentuali' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino']);
        $scale = b15rpsTabella($s, 'Scale', 200.0, 800.0);
        expect(b15rpsAggiorna($test, $s, [100, 0, 0], 60)['type'])->toBe('success')
            ->and(b15rpsAssocia($test, $s, $scale, [0, 100, 0], 40)['type'])->toBe('success');

        // «Proprietà» 60 %: € 360,00 a Rossi e € 240,00 a Bianchi. «Scale» 40 %: € 80,00 a Verdi e € 320,00 a Bianchi, che di Interno 2 non ha inquilino.
        return [b15rpsQuote($test, $s), ['Bianchi' => 56000, 'Rossi' => 36000, 'Verdi' => 8000]];
    }],
    'coefficiente sotto il 100: lo scoperto si dichiara, e le quote sono il 60 %' => [function ($test) {
        $s = b15rpsScenario($test);
        expect(b15rpsAggiorna($test, $s, [100, 0, 0], 60)['type'])->toBe('success');

        // Senza accettare lo scoperto il piano non nasce.
        session()->forget('message');
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
            'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Senza accettare', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
            'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true, 'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [],
            'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
        ]);
        expect(PianoRate::where('nome', 'Senza accettare')->exists())->toBeFalse();

        return [b15rpsQuote($test, $s, ['accetta_scoperti' => true, 'nota_scoperti' => 'Il 40 % resta fuori']), ['Bianchi' => 24000, 'Rossi' => 36000]];
    }],
    'usufrutto con «Usufruttuario 100 %» nella gestione ordinaria' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['usufruttuario']);
        expect(b15rpsAggiorna($test, $s, [0, 0, 100])['type'])->toBe('success');

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Neri' => 60000]];
    }],
    'rifiuto: la somma delle percentuali diversa da 100' => [function ($test) {
        $s = b15rpsScenario($test);
        b15rpsSuInterno1($s, ['inquilino']);
        $prima = b15rpsDallaPagina($test, $s);
        $esito = b15rpsAggiorna($test, $s, [50, 40, 0]);
        expect($esito['type'])->toBe('error')
            ->and($esito['message'])->toContain('La somma delle percentuali deve essere 100%')
            ->and(b15rpsDallaPagina($test, $s))->toBe($prima);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Rossi' => 60000]];
    }],
    'rifiuto: il coefficiente oltre il residuo' => [function ($test) {
        $s = b15rpsScenario($test);
        $scale = b15rpsTabella($s, 'Scale', 200.0, 800.0);
        $prima = b15rpsDallaPagina($test, $s);
        $esito = b15rpsAssocia($test, $s, $scale, [100, 0, 0], 10);
        expect($esito['type'])->toBe('error')
            ->and($esito['message'])->toContain('supererebbe il 100%')
            ->and(b15rpsDallaPagina($test, $s))->toBe($prima);

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Rossi' => 60000]];
    }],
    'rifiuto: la tabella di un altro condominio' => [function ($test) {
        $s = b15rpsScenario($test);
        $altrui = b15rpsTabella($s, 'Altrui', 0, 0, Condominio::factory()->create());
        expect(b15rpsAggiorna($test, $s, [100, 0, 0], 60)['type'])->toBe('success');
        $prima = b15rpsDallaPagina($test, $s);
        $esito = b15rpsAssocia($test, $s, $altrui, [100, 0, 0], 40);
        expect($esito['type'])->toBe('error')
            ->and($esito['message'])->toContain('Tabella millesimale non trovata per questo condominio.')
            ->and(b15rpsDallaPagina($test, $s))->toBe($prima)
            ->and(b15rpsAggiorna($test, $s, [100, 0, 0], 100)['type'])->toBe('success');

        return [b15rpsQuote($test, $s), ['Bianchi' => 40000, 'Rossi' => 60000]];
    }],
]);

it('il riparto per soggetto, per persona al centesimo', function (Closure $caso) {
    [$quote, $attese] = $caso($this);

    expect($quote)->toBe($attese);
})->with('b15 riparto per soggetto');

it('0/100/0 nella stampa del riparto per tabella: l\'inquilino porta la quota di Interno 1 e il proprietario non c\'è', function () {
    $s = b15rpsScenario($this);
    b15rpsSuInterno1($s, ['inquilino']);
    expect(b15rpsAggiorna($this, $s, [0, 100, 0])['type'])->toBe('success');
    $piano = b52tPiano($this, $s);

    $matrice = app(RipartoTabelleService::class)->buildMatrice($piano->fresh());
    $interno1 = $matrice['righe'][$s['unita']['1']->id]['soggetti'];

    expect(array_keys($interno1))->toBe([$s['persone']['verdi']->id])
        ->and($interno1[$s['persone']['verdi']->id]['ruolo_raw'])->toBe('inquilino')
        ->and($interno1[$s['persone']['verdi']->id]['totale'])->toBe(60000)
        ->and($matrice['gran_totale'])->toBe(100000);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.print-riparto-tabelle', [$s['c'], $s['e'], $piano]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
