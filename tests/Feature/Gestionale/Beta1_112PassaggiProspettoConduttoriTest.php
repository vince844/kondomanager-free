<?php

/**
 * Coda 298, decisione 183.4 — la frase di testa del prospetto degli oneri accessori con più conduttori insieme nell'anno, senza una fine.
 *
 * La testa diceva «Le spese si dividono per giorni di conduzione, con la stessa regola del riparto» per ogni unità senza una fine con la regola del piano,
 * anche con due conduttori insieme: il riparto li divide per quota e per giorni sull'intera voce, e un conduttore entrato dopo la generazione del piano non
 * compare, perché le voci di quei giorni sono nelle rate di chi c'era. La decisione: una frase di testa che dice il caso, nessuna sezione nuova nella
 * stampa, nessuna cifra nuova. Tre casi: nessun periodo con più conduttori insieme (la frase di sempre); più conduttori insieme, ognuno con le sue voci
 * del piano (la frase della regola: per quota e per giorni); più conduttori insieme e uno entrato dopo la generazione di un piano (la stessa frase, e
 * subito dopo una frase con il nome, il giorno, il piano e chi ha quei giorni nelle sue rate).
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 al 30 % all'inquilino, la parte dell'inquilino dell'Interno 1 è 36000; Ugo
 * proprietario al 100 %, Ines inquilina al 50 % dal 2019, sola alla generazione: tutta la parte è sua, 36000 per 365 giorni. Le cifre, dalla regola del
 * motore (quota × giorni sull'intera voce, `CalcoloQuoteService::pesiPerGiorni`): con Luca dal 1/7 al 50 %, pesi 50 × 365 = 18250 e 50 × 184 = 9200, Ines
 * 36000 × 18250/27450 = 23934,43 → 23934 e Luca 12065,57 → 12066; con Dora dal 1/4 al 50 %, 50 × 275 = 13750, Ines 36000 × 18250/32000 = 20531,25 →
 * 20531 (€ 205,31, la cifra della roadmap) e Dora 15468,75 → 15469.
 *
 * Che cosa NON copre: MySQL; due piani dell'esercizio di cui uno generato dopo l'ingresso (la frase nomina il solo piano generato prima: lo dice la
 * costruzione, nessuna prova); un piano della versione precedente senza riparto registrato (lì il piano esce dal prospetto con il suo perché); una
 * pertinenza con i suoi conduttori (il prospetto è per unità).
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** La frase di sempre, vera senza conduttori insieme. */
const POC_GIORNI = 'Le spese si dividono per giorni di conduzione, con la stessa regola del riparto, e non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.';

/** La frase della regola con più conduttori insieme nell'anno. */
const POC_INSIEME = 'Le spese si dividono con la stessa regola del riparto: per giorni di conduzione e, dove più conduttori sono stati insieme nell\'anno, per quota e per giorni sull\'intera voce; non per consumi: dove c\'è la contabilizzazione del calore il risultato è un altro.';

/**
 * Lo scenario: Ugo proprietario al 100 %, gli inquilini dati dal 2019 (di partenza Ines al 50 %), il piano generato; con `$emetti` le rate da gennaio a
 * giugno a giornale, e il piano non si ricalcola più. Le persone che entrano dopo: Luca e Dora.
 */
function pocScenario($test, bool $emetti = true, array $inquilini = [['Ines', 50]]): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => $inquilini]);
    foreach (['Luca', 'Dora'] as $nome) {
        if (! isset($s['persone'][$nome])) {
            fclPersona($s, $nome);
        }
    }
    if ($inquilini !== []) {
        pocPiano($test, $s, $emetti);
    }

    return $s;
}

/** La generazione dalla rotta della pagina del piano, con la presa d'atto del cancello; con `$emetti` le rate fino al 30/6 a giornale. */
function pocPiano($test, array $s, bool $emetti = true): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del preventivo nella prova',
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->count())->toBe(12);
    if ($emetti) {
        fclEmetti($test, $s, '2026-06-30');
        expect(fclEmesse($s))->toBe(6);
    }
}

/** L'inizio della locazione di `$chi` dalla rotta vera, alla quota data. */
function pocInizio($test, array $s, string $chi, string $il, float|int $quota = 50): Subentro
{
    return fclRegistra($test, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
}

/** La stampa dalla rotta del pulsante «Prospetto oneri accessori» (un PDF), e le frasi di testa che la sua vista stampa. @return list<string> */
function pocTesta($test, array $s): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita'], 'esercizio' => $s['e']->id]));
    expect($r->status())->toBe(200)->and((string) $r->headers->get('Content-Type'))->toContain('application/pdf');
    $testa = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'];
    $stampa = fclStampa($s);
    foreach ($testa as $frase) {
        expect($stampa)->toContain($frase);
    }

    return $testa;
}

/** Per conduttore, per nome: [totale, nelle sue rate, giorni]. */
function pocConduttori(array $s): array
{
    return collect(fclProspetto($s)['conduttori'])->map(fn (array $c) => [$c[0], $c[1], $c[3]])->all();
}

it('Coda 298 — un conduttore entrato dopo la generazione di un piano che non si ricalcola più, insieme a chi c\'era: la testa dice la regola per quota e per giorni e, subito dopo, che le voci di quei giorni sono nelle rate di chi c\'era', function (string $chi, string $il) {
    $s = pocScenario($this);
    pocInizio($this, $s, $chi, $il);

    $testa = pocTesta($this, $s);
    $i = array_search(POC_INSIEME, $testa, true);
    expect($i)->not->toBeFalse(implode("\n", $testa))
        ->and($testa)->not->toContain(POC_GIORNI)
        ->and($testa[$i + 1])->toBe(sprintf('Dal %s %s è inquilino insieme a Ines, ma il piano «Preventivo 2026» è stato generato prima: le cifre sono quelle del piano com\'era, con la parte dell\'inquilino di quei giorni nelle rate di Ines, e %s, per quei giorni, non ha voci. Un piano generato dopo dividerà quelle voci fra Ines e %s, per quota e per giorni.',
            \Carbon\CarbonImmutable::parse($il)->format('d/m/Y'), $chi, $chi, $chi))
        // Nessuna cifra nuova e nessuna sezione nuova: Ines ha la parte dell'anno nelle sue rate, come il piano.
        ->and(pocConduttori($s))->toBe(['ines' => [36000, 36000, 365]]);
})->with([
    'Luca dal 1/7' => ['Luca', '2026-07-01'],
    'Dora dal 1/4' => ['Dora', '2026-04-01'],
]);

it('Coda 298 — con il piano che si ricalcola ancora la testa dice lo stesso; ricalcolato dalla pagina del piano, il piano divide per quota e per giorni come la frase prometteva, e la frase dell\'ingresso non c\'è più', function (string $chi, string $il, int $ines, int $entrato, int $giorni) {
    $s = pocScenario($this, emetti: false);
    pocInizio($this, $s, $chi, $il);
    expect(implode("\n", pocTesta($this, $s)))->toContain("{$chi}, per quei giorni, non ha voci");

    pocPiano($this, $s, false);
    $testa = pocTesta($this, $s);
    $attesi = ['ines' => [$ines, $ines, 365], mb_strtolower($chi) => [$entrato, $entrato, $giorni]];
    ksort($attesi);
    expect($testa)->toContain(POC_INSIEME)
        ->and(implode("\n", $testa))->not->toContain('è stato generato prima')
        ->and(pocConduttori($s))->toBe($attesi);
})->with([
    'Luca dal 1/7' => ['Luca', '2026-07-01', 23934, 12066, 184],
    'Dora dal 1/4' => ['Dora', '2026-04-01', 20531, 15469, 275],
]);

it('Coda 298 — due conduttori insieme già alla generazione, ognuno con le sue voci: la testa dice la regola per quota e per giorni, senza la frase dell\'ingresso', function () {
    $s = pocScenario($this, inquilini: []);
    pocInizio($this, $s, 'Dora', '2026-01-01', 50);
    pocInizio($this, $s, 'Luca', '2026-07-01', 50);
    pocPiano($this, $s);

    $testa = pocTesta($this, $s);
    // Dora dal 1/1 (365 giorni) e Luca dal 1/7 (184), al 50 %: gli stessi pesi di Ines e Luca, 23934 e 12066.
    expect($testa)->toContain(POC_INSIEME)
        ->and($testa)->not->toContain(POC_GIORNI)
        ->and(implode("\n", $testa))->not->toContain('è stato generato prima')
        ->and(pocConduttori($s))->toBe(['dora' => [23934, 23934, 365], 'luca' => [12066, 12066, 184]]);
});

it('Coda 298, controprova — un solo conduttore nell\'anno, o due uno dopo l\'altro (Ines esce il 1/7 e Luca entra lo stesso giorno): la frase di sempre', function (bool $cambio) {
    $s = pocScenario($this, inquilini: [['Ines', 100]]);
    if ($cambio) {
        fclRegistra($this, $s, fclModulo($s, $s['persone']['Luca'], '2026-07-01') + ['conguaglio' => 'non_scrivere']);
    }

    $testa = pocTesta($this, $s);
    expect($testa)->toContain(POC_GIORNI)
        ->and($testa)->not->toContain(POC_INSIEME)
        ->and(implode("\n", $testa))->not->toContain('è stato generato prima');
})->with([
    'Ines sola' => [false],
    'il cambio d\'inquilino del 1/7' => [true],
]);

it('Coda 298 — un conduttore con le voci di ripiego (i giorni senza inquilino alla generazione) e senza voci nei giorni in cui era insieme a chi c\'era: la frase dell\'ingresso c\'è lo stesso, per quei giorni', function () {
    // Dora entra il 1/3 al 50 % prima della generazione: gennaio e febbraio (59 giorni) non hanno inquilino e la loro parte la paga Ugo (ripiego). Luca entra
    // dopo la generazione, dal 1/2 al 50 %: i 28 giorni di febbraio, che Ugo ha pagato, il prospetto li divide su di lui; dal 1/3 la parte dell'inquilino è
    // nelle rate di Dora, e Luca lì non ha voci.
    $s = pocScenario($this, inquilini: []);
    pocInizio($this, $s, 'Dora', '2026-03-01', 50);
    pocPiano($this, $s);
    pocInizio($this, $s, 'Luca', '2026-02-01', 50);

    $testa = pocTesta($this, $s);
    $i = array_search(POC_INSIEME, $testa, true);
    expect($i)->not->toBeFalse(implode("\n", $testa))
        ->and($testa[$i + 1])->toBe('Dal 01/03/2026 Luca è inquilino insieme a Dora, ma il piano «Preventivo 2026» è stato generato prima: le cifre sono quelle del piano com\'era, con la parte dell\'inquilino di quei giorni nelle rate di Dora, e Luca, per quei giorni, non ha voci. Un piano generato dopo dividerà quelle voci fra Dora e Luca, per quota e per giorni.')
        // Il piano: Dora 36000 × 306/365 = 30180,82 → 30181 nelle sue rate, il ripiego di Ugo 5819,18 → 5819 per 59 giorni. Il prospetto divide il ripiego sui
        // giorni con un conduttore: a Luca 5819 × 28/59 = 2761,59 → 2762 da rimborsare a Ugo, e i 31 giorni di gennaio restano a Ugo, senza conduttore.
        ->and(pocConduttori($s))->toBe(['dora' => [30181, 30181, 306], 'luca' => [2762, 0, 28]]);
});

it('Coda 298, controprova — nessun inquilino alla generazione e due conduttori entrati dopo, insieme: il prospetto divide il ripiego fra loro per quota e per giorni, e la testa dice la regola senza la frase dell\'ingresso', function () {
    // Il piano senza inquilini dà la parte dell'inquilino a Ugo (ripiego, 36000 per 365 giorni). Dora entra il 1/1 e Luca il 1/7, al 50 %: il prospetto divide
    // il ripiego con la regola del motore, pesi 50 × 365 = 18250 e 50 × 184 = 9200: Dora 23934, Luca 12066, da rimborsare a Ugo.
    $s = pocScenario($this, inquilini: []);
    pocPiano($this, $s);
    pocInizio($this, $s, 'Dora', '2026-01-01', 50);
    pocInizio($this, $s, 'Luca', '2026-07-01', 50);

    $testa = pocTesta($this, $s);
    expect($testa)->toContain(POC_INSIEME)
        ->and(implode("\n", $testa))->not->toContain('è stato generato prima')
        ->and(pocConduttori($s))->toBe(['dora' => [23934, 0, 365], 'luca' => [12066, 0, 184]]);
});

it('Coda 298, controprova — sull\'anello di una fine con la regola del piano un conduttore entrato dopo ha la sua parte con la regola del piano: nessuna frase dell\'ingresso', function () {
    // Il caso C5a della Coda 245: Ines e Dora al 50 %, Ines esce il 1/7 con «Scrivi il conguaglio», Luca entra il 1/9 al 50 %.
    $s = pocScenario($this, inquilini: [['Ines', 50], ['Dora', 50]]);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-07-01') + ['conguaglio' => 'scrivi']);
    pocInizio($this, $s, 'Luca', '2026-09-01');

    $testa = pocTesta($this, $s);
    expect($testa)->toContain(POC_INSIEME)
        ->and(implode("\n", $testa))->not->toContain('è stato generato prima')
        ->and(collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca')['totale'])->toBe(6575);
});

it('Coda 298 — due piani generati prima dell\'ingresso e due conduttori in carica: la frase nomina i due piani e le due persone, e chi entra fra loro', function (bool $duePiani) {
    $s = pocScenario($this, emetti: false, inquilini: $duePiani ? [['Ines', 50]] : [['Ines', 25], ['Dora', 25]]);
    if ($duePiani) {
        // Una seconda gestione, «Riscaldamento 2026», con «Gasolio» € 600,00 al 30 % all'inquilino e il suo piano, generato prima dell'ingresso.
        $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
        legaAEsercizio($s['e'], $g->id);
        $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
        $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Gasolio', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 60000]);
        $tabella = (int) DB::table('conto_tabella_millesimale')->value('tabella_id');
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
        }
        $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate del riscaldamento 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
        $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
            'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del riscaldamento nella prova',
        ])->assertSessionHasNoErrors();
    }
    pocInizio($this, $s, 'Luca', '2026-07-01');

    $testa = pocTesta($this, $s);
    $i = array_search(POC_INSIEME, $testa, true);
    expect($i)->not->toBeFalse(implode("\n", $testa))
        ->and($testa[$i + 1])->toBe($duePiani
            ? 'Dal 01/07/2026 Luca è inquilino insieme a Ines, ma i piani «Preventivo 2026» e «Rate del riscaldamento 2026» sono stati generati prima: le cifre sono quelle dei piani com\'erano, con la parte dell\'inquilino di quei giorni nelle rate di Ines, e Luca, per quei giorni, non ha voci. Un piano generato dopo dividerà quelle voci fra Ines e Luca, per quota e per giorni.'
            : 'Dal 01/07/2026 Luca è inquilino insieme a Dora e Ines, ma il piano «Preventivo 2026» è stato generato prima: le cifre sono quelle del piano com\'era, con la parte dell\'inquilino di quei giorni nelle rate di Dora e Ines, e Luca, per quei giorni, non ha voci. Un piano generato dopo dividerà quelle voci fra Dora, Ines e Luca, per quota e per giorni.');
    if (! $duePiani) {
        // Ines e Dora al 25 % per 365 giorni: 36000 a metà, 18000 ciascuna nelle sue rate.
        expect(pocConduttori($s))->toBe(['dora' => [18000, 18000, 365], 'ines' => [18000, 18000, 365]]);
    }
})->with([
    'due piani' => [true],
    'due conduttori in carica' => [false],
]);
