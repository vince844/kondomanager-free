<?php

/**
 * Il blocco dei piani approvati su una voce del piano dei conti: la tabella e le percentuali, il sottoconto nuovo sotto un capitolo tenuto, il capitolo
 * che torna voce.
 *
 * Una voce che un piano approvato (o emesso, o chiuso) tiene, con la sua riga o con quella di un capitolo sopra di lei (`Conto::pianiCheBloccanoLaVoce`),
 * non cambia importo, padre, tipo, tabella né percentuali: il motore la ripartirebbe in un altro modo, e il piano addebiterebbe ad altre persone. Con la
 * stessa regola e la stessa frase di «Modifica inibita» si rifiutano anche il sottoconto nuovo sotto un capitolo che un piano così tiene, e il capitolo
 * che torna voce. La frase dice la strada che dalla pagina si fa davvero: riportare prima il piano in bozza dalla sua pagina (`BloccoDellaVoce`).
 *
 * Gli scenari sono quelli di `Beta53BloccoDellaVoceTest`: «Pulizie» (capitolo) → «Scale» € 500,00 sulla tabella «Proprietà» (tutta al proprietario,
 * Ugo e Bice a metà); la tabella «Box» è tutta di Bice. Il piano «Preventivo B» tiene le righe date.
 *
 * La prova indipendente è il motore stesso, riletto in sola lettura, per voce e per persona, e lo stato delle tabelle nel database: un rifiuto lascia tutto
 * com'era. La controprova percorre la strada del rifiuto dalle rotte: con il piano in bozza le stesse richieste passano, e il riparto cambia.
 *
 * Fonte dell'atteso: la decisione di Vincenzo del 19/10/2026 (il blocco dei piani approvati vale per ogni modifica che cambia ciò che il piano addebita) e i
 * conti a mano degli scenari: «Scale» € 500,00 sulla tabella «Proprietà» vale 25000 a testa; sulla tabella «Box» vale 50000 a Bice.
 *
 * Cosa resta scoperto: la scheda della voce (la provano i vitest di `ModalModificaConto` e di `DettaglioConto`); i database diversi da sqlite.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Quello che il programma dice di non cambiare, nel rifiuto della modifica di una voce. */
const B53TB_COSA = 'l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano';

/** La strada della bozza, in coda a ogni rifiuto del blocco. */
const B53TB_BOZZA_PER_LA_VOCE = ': riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota '
    .'dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

/** Il motore del piano per persona: nome => centesimi. */
function b53tbPerPersona(array $s, PianoRate $piano): array
{
    $totali = app(\App\Services\CalcoloQuoteService::class)->calcolaPerGestione($s['g']->fresh(), $piano->fresh(), true);
    $nomi = DB::table('anagrafiche')->pluck('nome', 'id')->all();
    $persone = [];
    foreach ($totali as $anagrafica => $immobili) {
        $persone[$nomi[$anagrafica] ?? $anagrafica] = array_sum($immobili);
    }
    ksort($persone);

    return $persone;
}

/** Che cosa il piano chiede: per voce e per persona. */
function b53tbPiano(array $s, PianoRate $piano): array
{
    return ['voci' => b53snRipartito($s, $piano), 'persone' => b53tbPerPersona($s, $piano)];
}

/** Le tabelle della voce nel database: tabella, coefficiente e ripartizioni, nell'ordine dei legami. */
function b53tbTabelle(Conto $voce): array
{
    return DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->orderBy('id')->get()->map(fn ($legame) => [
        'tabella' => (int) $legame->tabella_id,
        'coefficiente' => (float) $legame->coefficiente,
        'ripartizioni' => DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $legame->id)->orderBy('soggetto')
            ->pluck('percentuale', 'soggetto')->map(fn ($p) => (float) $p)->all(),
    ])->all();
}

/** La modifica di una voce dal piano dei conti, come la manda la sua scheda: i campi della voce, con quelli dati in `$campi` riscritti. */
function b53tbModifica($test, array $s, Conto $voce, array $campi = [])
{
    session()->forget('errors');
    session()->forget('message');
    $voce = $voce->fresh();

    return $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $voce]), array_merge([
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $voce->tipo, 'importo' => number_format($voce->importo / 100, 2, ',', '.'), 'descrizione' => '', 'note' => '',
        'parent_id' => $voce->parent_id, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => (bool) $voce->is_capitolo, 'isSottoConto' => $voce->parent_id !== null, 'richiedeGiaVersato' => false,
    ], $campi));
}

/** Il capitolo come lo manda la sua scheda: senza importo, né tabella. */
function b53tbCapitolo(array $campi = []): array
{
    return array_merge(['importo' => 0, 'isCapitolo' => true, 'isSottoConto' => false, 'parent_id' => null, 'tabella_millesimale_id' => null,
        'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null], $campi);
}

// ─── La tabella e le percentuali di una voce che un piano approvato tiene ──────────────────────────────────────

it('la tabella o le percentuali di una voce che un piano approvato tiene non si cambiano: il riparto per persona del piano resta, e la strada della bozza passa dalle rotte', function (array $righe, string $chi, array $campo, string $errore) {
    $s = b53bvScenario($this, $righe);
    $prima = b53tbPiano($s, $s['b']);
    $tabelle = b53tbTabelle($s['scale']);
    $campi = $campo['tabella'] ?? false ? ['tabella_millesimale_id' => $s['box']->id] : $campo;

    b53tbModifica($this, $s, $s['scale'], $campi);

    expect($prima)->toBe(['voci' => [(int) $s['scale']->id => 50000], 'persone' => ['Bice' => 25000, 'Ugo' => 25000]])
        ->and(b53snErrori())->toBe([$errore => ['Modifica inibita: '.B53TB_COSA.' finché '.$chi.' è nel piano «Preventivo B», approvato'.B53TB_BOZZA_PER_LA_VOCE]])
        ->and(b53tbTabelle($s['scale']))->toBe($tabelle)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);

    // La strada della frase: il piano in bozza, poi la modifica passa e cambia il riparto.
    b53bvInBozza($this, $s, $s['b']);
    b53tbModifica($this, $s, $s['scale'], $campi)->assertSessionHasNoErrors();

    expect(b53tbTabelle($s['scale']))->not->toBe($tabelle);
    if ($errore === 'tabella_millesimale_id') {
        expect(b53tbPerPersona($s, $s['b']))->toBe(['Bice' => 50000]);
    }
})->with([
    'la tabella, con la riga sua' => [['scale' => '500,00'], 'la voce', ['tabella' => true], 'tabella_millesimale_id'],
    'la tabella, con la riga del capitolo' => [['pulizie' => ''], 'il suo capitolo «Pulizie»', ['tabella' => true], 'tabella_millesimale_id'],
    'le percentuali, con la riga sua' => [['scale' => '500,00'], 'la voce', ['percentuale_proprietario' => 60, 'percentuale_inquilino' => 40], 'percentuale_proprietario'],
]);

it('controprova — il nome, le note e le stesse tabelle e percentuali di una voce che un piano approvato tiene si salvano: la scheda le rimanda sempre', function () {
    $s = b53bvScenario($this, ['scale' => '500,00']);
    $prima = b53tbPiano($s, $s['b']);
    $tabelle = b53tbTabelle($s['scale']);

    b53tbModifica($this, $s, $s['scale'], ['nome' => 'Scale e androne', 'note' => 'Pulizia settimanale'])->assertSessionHasNoErrors();

    expect(DB::table('conti')->where('id', $s['scale']->id)->value('nome'))->toBe('Scale e androne')
        ->and(b53tbTabelle($s['scale']))->toBe($tabelle)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);
});

it('controprova — una voce che nessun piano approvato tiene cambia tabella e percentuali come prima', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);

    b53tbModifica($this, $s, $s['generali'], ['tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 60, 'percentuale_inquilino' => 40])->assertSessionHasNoErrors();

    expect(b53tbTabelle($s['generali']))->toBe([['tabella' => (int) $s['box']->id, 'coefficiente' => 100.0, 'ripartizioni' => ['inquilino' => 40.0, 'proprietario' => 60.0]]]);
});

it('associare, aggiornare o rimuovere la tabella di una voce che un piano approvato tiene si rifiuta con la stessa frase, e il riparto resta; in bozza passa', function (string $azione) {
    $s = b53bvScenario($this, ['scale' => '500,00']);
    // La voce ha un posto per un'altra tabella: la somma dei coefficienti non è la ragione del rifiuto.
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['scale']->id)->update(['coefficiente' => 60]);
    $prima = b53tbPiano($s, $s['b']);
    $tabelle = b53tbTabelle($s['scale']);
    $parametri = [$s['c'], $s['e'], $s['pc'], $s['scale']];
    $chiama = fn () => match ($azione) {
        'associa' => $this->actingAs($this->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri), [
            'tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 40, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
        'aggiorna' => $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', [...$parametri, $s['tabella']]), [
            'coefficiente' => 50, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
        'dissocia' => $this->actingAs($this->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', [...$parametri, $s['tabella']])),
    };

    session()->forget('message');
    $chiama();

    expect(b53snFlash()['type'] ?? null)->toBe('error')
        ->and(b53snFlash()['message'] ?? null)->toBe('Modifica inibita: '.B53TB_COSA.' finché la voce è nel piano «Preventivo B», approvato'.B53TB_BOZZA_PER_LA_VOCE)
        ->and(b53tbTabelle($s['scale']))->toBe($tabelle)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);

    // La strada della frase: il piano in bozza, poi la richiesta passa.
    b53bvInBozza($this, $s, $s['b']);
    session()->forget('message');
    $chiama();

    expect(b53snFlash()['type'] ?? null)->toBe('success')
        ->and(b53tbTabelle($s['scale']))->not->toBe($tabelle);
})->with(['associa' => ['associa'], 'aggiorna' => ['aggiorna'], 'dissocia' => ['dissocia']]);

it('controprova — le tabelle di una voce che nessun piano approvato tiene si associano, si aggiornano e si rimuovono come prima', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $parametri = [$s['c'], $s['e'], $s['pc'], $s['generali']];
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['generali']->id)->update(['coefficiente' => 60]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri), [
        'tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 40, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
    ]);
    expect(b53snFlash()['type'] ?? null)->toBe('success');

    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', [...$parametri, $s['box']]), [
        'coefficiente' => 30, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
    ]);
    expect(b53snFlash()['type'] ?? null)->toBe('success');

    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', [...$parametri, $s['box']]));
    expect(b53snFlash()['type'] ?? null)->toBe('success')
        ->and(array_column(b53tbTabelle($s['generali']), 'tabella'))->toBe([(int) $s['tabella']->id]);
});

// ─── Il sottoconto nuovo sotto un capitolo che un piano approvato tiene ────────────────────────────────────────

/** La creazione di una voce dal piano dei conti, come la manda il modulo «Nuova voce». */
function b53tbCrea($test, array $s, string $nome, ?int $padre, array $campi = [])
{
    session()->forget('errors');
    session()->forget('message');

    return $test->actingAs($test->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'nome' => $nome, 'codice' => '', 'tipo' => 'spesa', 'importo' => '200,00', 'descrizione' => '', 'note' => '', 'parent_id' => $padre, 'tabella_millesimale_id' => $s['tabella']->id,
        'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => $padre !== null,
        'richiedeGiaVersato' => false,
    ], $campi));
}

it('un sottoconto nuovo sotto un capitolo che un piano approvato tiene si rifiuta, e il piano non lo prende; in bozza si crea', function (string $copia) {
    $s = b53bvScenario($this, ['pulizie' => $copia]);
    $prima = b53tbPiano($s, $s['b']);

    b53tbCrea($this, $s, 'Androne', (int) $s['pulizie']->id);

    expect($prima)->toBe(['voci' => [(int) $s['scale']->id => 50000], 'persone' => ['Bice' => 25000, 'Ugo' => 25000]])
        ->and(b53snErrori())->toBe(['parent_id' => ['Modifica inibita: una voce nuova non si aggiunge sotto «Pulizie» finché il capitolo è nel piano «Preventivo B», approvato: riporta '
            .'prima il piano in bozza dalla sua pagina, poi crea la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; '
            .'con delle rate emesse, prima vanno annullate le emissioni.']])
        ->and(DB::table('conti')->where('nome', 'Androne')->exists())->toBeFalse()
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);

    // La strada della frase: il piano in bozza, poi la voce si crea.
    b53bvInBozza($this, $s, $s['b']);
    b53tbCrea($this, $s, 'Androne', (int) $s['pulizie']->id)->assertSessionHasNoErrors();

    expect(DB::table('conti')->where('nome', 'Androne')->value('parent_id'))->toBe((int) $s['pulizie']->id);
})->with(['la copia nulla' => [''], 'la copia € 500,00' => ['500,00']]);

it('controprova — una voce nuova sotto un capitolo che nessun piano approvato tiene, o di primo livello, si crea come prima', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);

    b53tbCrea($this, $s, 'Cancello', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    b53tbCrea($this, $s, 'Giardino', null)->assertSessionHasNoErrors();

    expect(DB::table('conti')->where('nome', 'Cancello')->value('parent_id'))->toBe((int) $s['capitolo']->id)
        ->and(DB::table('conti')->where('nome', 'Giardino')->exists())->toBeTrue();
});

// ─── Il capitolo che torna voce, e il capitolo dei dati vecchi con un importo ───────────────────────────────────

/** Un capitolo senza sottoconti, «Ascensore», che il piano «Preventivo B» approvato tiene con la copia data (riga scritta come nei dati di prima). */
function b53tbCapitoloTenuto($test, ?int $copia = 30000, int $importo = 0): array
{
    $s = b53snBase($test);
    $s['ascensore'] = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Ascensore', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => null,
        'is_capitolo' => true]);
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    $s['b'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['generali']->id], 'capitoli_config' => [['id' => $s['generali']->id, 'importo' => '', 'note' => '']]], 'Preventivo B');
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['b']->id, 'conto_id' => $s['ascensore']->id, 'importo' => $copia, 'created_at' => now(), 'updated_at' => now()]);
    b52tApprova($test, $s, $s['b']);
    $s['b'] = $s['b']->fresh();

    return $s;
}

it('il capitolo che un piano approvato tiene non torna voce: il piano addebiterebbe la voce a un\'altra cifra, e per altre persone; in bozza torna voce', function () {
    $s = b53tbCapitoloTenuto($this);
    $prima = b53tbPiano($s, $s['b']);

    b53tbModifica($this, $s, $s['ascensore'], ['importo' => '0,00', 'isCapitolo' => false, 'isSottoConto' => false, 'parent_id' => null]);

    expect($prima['voci'])->toBe([(int) $s['generali']->id => 120000])
        ->and(b53snErrori())->toBe(['isCapitolo' => ['Modifica inibita: il capitolo non torna una voce finché è nel piano «Preventivo B», approvato: riporta prima il piano in bozza '
            .'dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '
            .'prima vanno annullate le emissioni.']])
        ->and((bool) DB::table('conti')->where('id', $s['ascensore']->id)->value('is_capitolo'))->toBeTrue()
        ->and(b53tbTabelle($s['ascensore']))->toBe([])
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);

    b53bvInBozza($this, $s, $s['b']);
    b53tbModifica($this, $s, $s['ascensore'], ['importo' => '0,00', 'isCapitolo' => false, 'isSottoConto' => false, 'parent_id' => null])->assertSessionHasNoErrors();

    expect((bool) DB::table('conti')->where('id', $s['ascensore']->id)->value('is_capitolo'))->toBeFalse();
});

it('il capitolo che un piano approvato tiene non torna voce nemmeno con un importo nuovo: il rifiuto è quello del capitolo, sotto «isCapitolo»', function (string $importo) {
    $s = b53tbCapitoloTenuto($this);
    $prima = b53tbPiano($s, $s['b']);

    b53tbModifica($this, $s, $s['ascensore'], ['importo' => $importo, 'isCapitolo' => false, 'isSottoConto' => false, 'parent_id' => null]);

    expect(b53snErrori())->toBe(['isCapitolo' => ['Modifica inibita: il capitolo non torna una voce finché è nel piano «Preventivo B», approvato: riporta prima il piano in bozza '
            .'dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '
            .'prima vanno annullate le emissioni.']])
        ->and((bool) DB::table('conti')->where('id', $s['ascensore']->id)->value('is_capitolo'))->toBeTrue()
        ->and((int) DB::table('conti')->where('id', $s['ascensore']->id)->value('importo'))->toBe(0)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);

    b53bvInBozza($this, $s, $s['b']);
    b53tbModifica($this, $s, $s['ascensore'], ['importo' => $importo, 'isCapitolo' => false, 'isSottoConto' => false, 'parent_id' => null])->assertSessionHasNoErrors();

    expect((bool) DB::table('conti')->where('id', $s['ascensore']->id)->value('is_capitolo'))->toBeFalse()
        ->and((int) DB::table('conti')->where('id', $s['ascensore']->id)->value('importo'))->toBe((int) round((float) str_replace(',', '.', $importo) * 100));
})->with(['l\'importo a zero' => ['0,00'], 'un importo nuovo' => ['300,00']]);

it('la voce a zero che un piano approvato tiene non diventa un capitolo: perderebbe la sua tabella', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);
    $voce = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Pronto soccorso', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0, 'parent_id' => null,
        'is_capitolo' => false]);
    b53bvLega($s, (int) $voce->id);
    DB::table('conti')->where('id', $voce->id)->update(['created_at' => now()->subDay()]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['b']->id, 'conto_id' => $voce->id, 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()]);
    $prima = b53tbPiano($s, $s['b']);
    $tabelle = b53tbTabelle($voce);

    b53tbModifica($this, $s, $voce, b53tbCapitolo(['confermaConversioneCapitolo' => true]));

    expect($prima['voci'])->toHaveKey((int) $voce->id)
        ->and(array_keys(b53snErrori()))->toBe(['tabella_millesimale_id'])
        ->and((bool) DB::table('conti')->where('id', $voce->id)->value('is_capitolo'))->toBeFalse()
        ->and(b53tbTabelle($voce))->toBe($tabelle)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);
});

it('il capitolo dei dati vecchi con un importo, tenuto da un piano approvato, si rinomina: l\'importo resta com\'è, e il piano non cambia', function (string $copia, bool $conSottoconti) {
    if ($conSottoconti) {
        $s = b53bvScenario($this, ['pulizie' => $copia]);
        $capitolo = $s['pulizie'];
        DB::table('conti')->where('id', $capitolo->id)->update(['importo' => 30000]);
    } else {
        $s = b53tbCapitoloTenuto($this, $copia === '' ? null : 50000, 30000);
        $capitolo = $s['ascensore'];
    }
    $prima = b53tbPiano($s, $s['b']);

    b53tbModifica($this, $s, $capitolo, b53tbCapitolo(['nome' => 'Pulizie e custodia']))->assertSessionHasNoErrors();

    expect(DB::table('conti')->where('id', $capitolo->id)->value('nome'))->toBe('Pulizie e custodia')
        ->and((int) DB::table('conti')->where('id', $capitolo->id)->value('importo'))->toBe(30000)
        ->and(b53tbPiano($s, $s['b']))->toBe($prima);
})->with([
    'con i sottoconti, la copia nulla' => ['', true],
    'con i sottoconti, la copia € 500,00' => ['500,00', true],
    'senza sottoconti, la copia nulla' => ['', false],
    'senza sottoconti, la copia € 500,00' => ['500,00', false],
]);

it('un capitolo che nessun piano tiene e resta capitolo non riscrive l\'importo: la scheda di un capitolo non lo mostra', function () {
    $s = b53bvScenario($this, ['pulizie' => ''], false);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 30000]);

    b53tbModifica($this, $s, $s['pulizie'], b53tbCapitolo(['nome' => 'Pulizie e custodia']))->assertSessionHasNoErrors();

    expect((int) DB::table('conti')->where('id', $s['pulizie']->id)->value('importo'))->toBe(30000);
});

it('controprova — la voce che diventa un capitolo azzera l\'importo, e se nessun piano la tiene passa', function () {
    $s = b53bvScenario($this, ['pulizie' => ''], false);
    $voce = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Pronto soccorso', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 24000, 'parent_id' => null, 'is_capitolo' => false]);

    b53tbModifica($this, $s, $voce, b53tbCapitolo())->assertSessionHasNoErrors();

    expect((bool) DB::table('conti')->where('id', $voce->id)->value('is_capitolo'))->toBeTrue()
        ->and((int) DB::table('conti')->where('id', $voce->id)->value('importo'))->toBe(0);
});

// ─── Il rifiuto va sotto il campo che la scheda mostra ──────────────────────────────────────────────────────────

it('la voce libera con l\'importo cambiato e un padre che un piano approvato tiene: il rifiuto va sotto il padre, la sola ragione, non sotto l\'importo', function () {
    $s = b53bvScenario($this, ['pulizie' => '']);

    b53tbModifica($this, $s, $s['generali'], ['importo' => '1.300,00', 'parent_id' => $s['pulizie']->id, 'isSottoConto' => true]);

    expect(b53snErrori())->toBe(['parent_id' => ['Modifica inibita: la voce non si sposta sotto «Pulizie» finché il capitolo è nel piano «Preventivo B», approvato'
        .B53TB_BOZZA_PER_LA_VOCE]])
        ->and((int) DB::table('conti')->where('id', $s['generali']->id)->value('importo'))->toBe(120000);
});
