<?php

/**
 * Il compito dei sei mesi nell'Inbox operativa del condominio: c'è per ogni riga con almeno una persona aperta e la data entro 60 giorni o passata, senza
 * una risposta che lo tenga chiuso («Non serve»; «Ricordamelo» con il giorno non ancora arrivato). Si calcola con il riquadro, nella stessa richiesta, e arriva
 * alla pagina in una prop sua (`compitiSeiMesi`): non è un evento dell'agenda, e non entra nel numero della barra laterale.
 */

use App\Models\Evento;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function scmCompiti(array $s): array
{
    return collect(smaRiquadro($s)['compiti'])->where('argomento', 'sei_mesi')->values()->all();
}

it('a 61 giorni no, a 60 sì, a data passata sì', function () {
    $s = smaViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    expect(scmCompiti($s))->toBe([]);

    $this->travelTo('2026-05-01 10:00:00');
    $c = scmCompiti($s);
    expect($c)->toHaveCount(1)
        ->and($c[0])->toMatchArray(['esercizio' => ['id' => $s['e2025']->id, 'nome' => 'Esercizio 2025'], 'data' => '2026-06-30', 'giorni' => 60, 'quante' => 4, 'cifra' => 80000,
            'ancora' => 'sei-mesi-' . $s['e2025']->id]);

    $this->travelTo('2026-07-12 10:00:00');
    expect(scmCompiti($s)[0]['giorni'])->toBe(-12)->and(scmCompiti($s)[0]['colore'])->toBe('passata');
});

it('«Non serve» chiude il compito; la riga resta. Una persona nuova nella riga lo fa tornare, e lo dice', function () {
    $s = smaViaVerdi($this);
    // All'inizio Neri è a posto con le rate del 2025: le paga, e la riga ha tre persone.
    lcdIncassa($this, $s, 'neri', [lcdQuota($s, 'neri', 1) => 10000, lcdQuota($s, 'neri', 2) => 10000], '2026-05-20');
    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea informata'])->assertSessionHasNoErrors();

    expect(scmCompiti($s))->toBe([])->and(smaRiga(smaRiquadro($s), 'Esercizio 2025'))->not->toBeNull();

    // L'incasso di Neri si annulla: Neri torna nella riga, che prima non lo aveva.
    lcdStorna($this, $s, (int) \Illuminate\Support\Facades\DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id'));
    $c = scmCompiti($s);
    expect($c)->toHaveCount(1)->and($c[0]['tornato'])->toBe('Dopo il tuo «Non serve» c\'è una persona nuova nella riga: il compito è tornato.');
});

it('«Ricordamelo» nasconde il compito fino al giorno scelto, e quel giorno torna', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'ricordamelo', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    expect(scmCompiti($s))->toBe([]);

    // Alle 23:30 di Roma del 24 è ancora il 24; alle 00:30 di Roma del 25 (le 22:30 del 24 in UTC) è il giorno scelto.
    $this->travelTo('2026-05-24 21:30:00');
    expect(scmCompiti($s))->toBe([]);

    $this->travelTo('2026-05-24 22:30:00');
    expect(scmCompiti($s))->toHaveCount(1);
});

it('la dashboard del condominio manda i compiti nella prop `compitiSeiMesi`, e non scrive eventi nell\'agenda', function () {
    $s = smaViaVerdi($this);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']))->assertOk()->viewData('page')['props'];

    // Il compito dei sei mesi del 2025 (fra 41 giorni) e quello del rendiconto 2025 (da convocare entro il 29/06/2026, fra 40 giorni, ancora una bozza).
    expect(collect($props['compitiSeiMesi'])->pluck('argomento')->all())->toBe(['sei_mesi', 'rendiconto'])
        ->and($props['seiMesi']['righe'])->toHaveCount(2)
        ->and(Evento::count())->toBe(0);
});

it('senza persone aperte nella riga il compito non c\'è', function () {
    $s = smaViaVerdi($this);
    smaRispondi($this, $s, ['tipo' => 'fatto', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id,
        'persone' => array_map(fn ($p) => $p->id, array_values($s['persone'])), 'giorno' => '2026-05-19', 'nota' => 'diffide a tutti'])->assertSessionHasNoErrors();

    expect(scmCompiti($s))->toBe([])->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['quante'])->toBe(0);
});

it('il compito ha le cifre della sua riga: le persone aperte, la cifra e i cartellini, con una persona chiusa e una con la diffida stampata', function () {
    $s = smaViaVerdi($this);
    smaLettera($s, 'neri', 'diffida', 'stampata', '2026-05-10', [1, 2, 3, 4, 5]);
    smaRispondi($this, $s, ['tipo' => 'agito', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'anagrafica_id' => $s['persone']['rossi']->id,
        'giorno' => '2026-05-12', 'nota' => 'pratica dal legale'])->assertSessionHasNoErrors();
    $r = smaRiquadro($s);

    foreach (collect($r['righe'])->where('compito', true) as $riga) {
        $compito = collect($r['compiti'])->firstWhere('chiave', $riga['chiave']);
        expect($compito)->not->toBeNull()
            ->and([$compito['quante'], $compito['cifra'], $compito['cartellini'], $compito['data'], $compito['giorni']])
            ->toBe([$riga['quante'], $riga['cifra'], $riga['cartellini'], $riga['data'], $riga['giorni']]);
    }
    $compito = collect($r['compiti'])->firstWhere('argomento', 'sei_mesi');
    expect($compito['quante'])->toBe(3)->and($compito['cifra'])->toBe(60000)->and($compito['cartellini']['diffida_da_spedire'])->toBe(1);
});

it('un caricamento parziale che non chiede il riquadro non lo calcola; la pagina intera lo calcola una volta per le due prop', function () {
    $s = smaViaVerdi($this);
    // Il riquadro legge le risposte una volta per calcolo (`RispostePromemoria::delCondominio`, con gli argomenti del riquadro): le letture di
    // `risposte_promemoria` per argomenti contano i calcoli. Dalla 1.12.0-beta.10 le legge così anche la tesoreria, una volta, per la prossima azione delle
    // persone che mostra: la pagina intera ne fa due, il riquadro una per le sue due prop. Le altre letture della tabella sono dell'Inbox (la regola che
    // nasconde i compiti in attesa di «Ricordamelo», le risposte dei compiti della pagina) e del numero della barra laterale.
    $chiamate = 0;
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$chiamate) {
        if (str_contains($q->sql, 'risposte_promemoria') && str_contains($q->sql, '"argomento" in')) {
            $chiamate++;
        }
    });
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $intestazioni = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'gestionale/dashboard/Dashboard',
        'X-Inertia-Partial-Data' => 'inboxTasks'];

    $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']), $intestazioni)->assertOk();
    expect($chiamate)->toBe(0);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']))->assertOk()->viewData('page')['props'];
    expect($chiamate)->toBe(2)->and($props['compitiSeiMesi'])->toBe($props['seiMesi']['compiti']);
});
