<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 778 — `kondomanager:verifica-incassi-dell-erede`: gli incassi dell'erede con il credito prelevato e riaccreditato tutto.
 *
 * Prima della correzione del piano del credito, un erede con un credito che pagava insieme una sua rata e il debito ereditato si vedeva prelevare il
 * credito e riaccreditarlo tutto dalla compensazione (la scrittura `storno_credito` figlia dell'incasso): il credito restava, e la sua rata restava aperta
 * della cifra del credito. Il caso: Via Verdi (`InteressiSulDebitoEreditatoScenario.php`), Ettore Fabbri morto il 17/11/2026, Bianchi erede al 50 % e
 * condòmino di U2, che il 20/11 versa la sua rata 1 e € 150,00 sul debito ereditato dalla rotta dell'incasso. Per la forma di allora: la sua rata 1 pagata
 * con il denaro meno € 50,00, e la compensazione figlia che preleva € 50,00 di credito da un'altra sua quota e li riaccredita tutti.
 *
 * La catena: l'incasso dell'erede scritto → la compensazione → la rata rimasta aperta → il comando la elenca e non scrive niente.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** La successione con Bianchi erede e il suo incasso (la sua rata 1 meno `$menoCredito`, e € 150,00 sul debito ereditato), dalle rotte. */
function vieIncasso($test, int $menoCredito): array
{
    $s = ideCaso($test);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $p = ideRegistra($test, $s, $dati);
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Bianchi']->id)->firstOrFail();
    $aperto = (int) $quota->importo - (int) $quota->importo_pagato;
    $rata = $aperto - $menoCredito;
    $test->travelTo('2026-11-20 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Bianchi']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-20', 'importo_totale' => number_format(($rata + 15000) / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => number_format($rata / 100, 2, '.', '')]],
        'debito_ereditato' => [['subentro_id' => $p->id, 'importo' => '150.00']],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $incasso = ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    return [$s, $incasso, $quota];
}

/** Una compensazione figlia dell'incasso, come la scriveva il codice: preleva `$credito` da una quota di Bianchi e lo mette su `$su` (o lo riaccredita tutto). */
function vieCompensazione(array $s, ScritturaContabile $incasso, int $credito, ?RataQuote $su = null): void
{
    $daQuota = RataQuote::where('anagrafica_id', $s['persone']['Bianchi']->id)->where('rata_id', '!=', lcdRata($s, 1))->orderBy('id')->first()
        ?? RataQuote::where('anagrafica_id', $s['persone']['Bianchi']->id)->orderBy('id')->firstOrFail();
    $conto = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $storno = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $incasso->esercizio_id, 'gestione_id' => $incasso->gestione_id,
        'scrittura_padre_id' => $incasso->id, 'data_registrazione' => '2026-11-20', 'data_competenza' => '2026-11-20', 'causale' => 'Compensazione con il credito - Bonifico',
        'tipo_movimento' => 'storno_credito', 'stato' => 'registrata']);
    $verso = $su ?? $daQuota;
    foreach ([['dare', $daQuota, -$credito, 'Utilizzo del credito'], ['avere', $verso, $credito, $su === null ? 'Credito non utilizzato, riaccreditato' : 'Compensazione credito su rata n.1']] as [$tipo, $q, $pivot, $nota]) {
        $storno->righe()->create(['conto_contabile_id' => $conto, 'anagrafica_id' => $q->anagrafica_id, 'rata_id' => $q->rata_id, 'immobile_id' => $q->immobile_id,
            'tipo_riga' => $tipo, 'importo' => $credito, 'note' => $nota]);
        DB::table('quota_scrittura')->insert(['rate_quota_id' => $q->id, 'scrittura_contabile_id' => $storno->id, 'importo_pagato' => $pivot, 'data_pagamento' => '2026-11-20',
            'created_at' => now(), 'updated_at' => now()]);
    }
    if ($su !== null) {
        $su->forceFill(['importo_pagato' => (int) $su->importo_pagato + $credito])->save();
    }
}

/** Le righe delle tabelle che il comando legge, per vedere che non scrive. */
function vieConteggi(): array
{
    return array_map(fn (string $t) => DB::table($t)->count(), ['scritture_contabili', 'righe_scritture', 'quota_scrittura', 'rate_quote', 'saldi']);
}

it('Coda 778 — l\'incasso di Bianchi scritto come allora (il credito di € 50,00 prelevato e riaccreditato tutto): il comando lo elenca con il condominio, la data, il pagante, la rata rimasta aperta e il credito, e non scrive niente', function () {
    [$s, $incasso] = vieIncasso($this, 5000);
    vieCompensazione($s, $incasso, 5000);
    $prima = vieConteggi();

    Artisan::call('kondomanager:verifica-incassi-dell-erede', ['--condominio' => $s['c']->id]);
    $out = Artisan::output();

    expect($out)->toContain($s['c']->nome)->toContain('20/11/2026')->toContain('Bianchi')->toContain('rata n. 1')->toContain('€ 50,00 da pagare')->toContain('50,00')
        ->toContain('storna l\'incasso e registralo di nuovo con «Usa credito»');
    expect(vieConteggi())->toBe($prima);
});

it('Coda 778, guardie — l\'incasso con il credito usato sulla rata (la compensazione di oggi) e quello senza credito non ci sono', function () {
    [$s, $incasso, $quota] = vieIncasso($this, 5000);
    vieCompensazione($s, $incasso, 5000, $quota);

    Artisan::call('kondomanager:verifica-incassi-dell-erede', ['--condominio' => $s['c']->id]);
    expect(Artisan::output())->toContain('Nessun incasso dell\'erede con il credito prelevato e riaccreditato tutto.');

    [$t] = vieIncasso($this, 0);
    Artisan::call('kondomanager:verifica-incassi-dell-erede', ['--condominio' => $t['c']->id]);
    expect(Artisan::output())->toContain('Nessun incasso dell\'erede')->not->toContain('da pagare');
});
