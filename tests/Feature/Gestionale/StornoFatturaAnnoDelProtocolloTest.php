<?php

/**
 * La nota di credito dello storno di una fattura prende il numero della serie «NC-<anno>-…» con l'anno del giorno dell'amministratore (Europe/Rome),
 * come ogni protocollo, e la data dello stesso giorno: alle 00:30 di Roma del 01/01 è già l'anno nuovo, anche se in UTC è ancora il 31/12.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

it('alle 00:30 di Roma del 01/01/2027 lo storno di una fattura scrive la nota con il protocollo NC-2027-0001', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-12-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-DIC', [[$s['pulizie']->id, 300.00]], '2026-12-10');
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    expect(now('UTC')->format('Y'))->toBe('2026');

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft]))->assertSessionHasNoErrors();

    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('numero_protocollo', 'like', 'NC-%')->pluck('numero_protocollo')->all())
        ->toBe(['NC-2027-0001']);
});

it('alle 00:30 di Roma del 01/01/2027 la nota dello storno è del 01/01/2027, come la sua scrittura: la situazione del 2026 ha ancora la fattura aperta', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-12-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-DIC', [[$s['pulizie']->id, 300.00]], '2026-12-10');
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft]))->assertSessionHasNoErrors();

    $nota = DB::table('fatture_passive')->where('condominio_id', $s['c']->id)->where('tipo_documento', 'nota_credito')->first();
    $scrittura = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('numero_protocollo', 'like', 'NC-%')->first();
    expect(substr((string) $nota->data_documento, 0, 10))->toBe('2027-01-01')
        ->and(substr((string) $nota->data_scadenza, 0, 10))->toBe('2027-01-01')
        ->and(substr((string) $scrittura->data_competenza, 0, 10))->toBe('2027-01-01')
        ->and(collect(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'])['debiti']['fatture'])->pluck('numero')->all())->toContain('FT-DIC');
});

it('il numero della nota dello storno si legge dentro la transazione e dopo il blocco della riga del condominio: due storni insieme non leggono lo stesso ultimo numero', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-12-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-DIC', [[$s['pulizie']->id, 300.00]], '2026-12-10');
    $base = DB::transactionLevel();
    $query = [];
    DB::listen(function ($q) use (&$query) {
        $query[] = ['sql' => $q->sql, 'livello' => $q->connection->transactionLevel(), 'legami' => $q->bindings];
    });

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft]))->assertSessionHasNoErrors();

    // Il blocco: la lettura della riga del condominio (la sola query sulla tabella `condomini` fatta dopo l'inizio della transazione); il numero: l'ultimo «NC-…».
    $dentro = array_keys(array_filter($query, fn ($q) => $q['livello'] > $base));
    $blocco = array_keys(array_filter($query, fn ($q) => $q['livello'] > $base && str_contains($q['sql'], 'from "condomini"')));
    $numero = array_keys(array_filter($query, fn ($q) => str_contains($q['sql'], 'from "scritture_contabili"')
        && collect($q['legami'])->contains(fn ($l) => is_string($l) && str_starts_with($l, 'NC-'))));

    expect($blocco)->toHaveCount(1)->and($numero)->toHaveCount(1)
        ->and($dentro)->toContain($numero[0])->and($dentro)->toContain($blocco[0])
        // Prima il blocco, poi la lettura del numero: con il blocco dopo, chi arriva insieme legge già lo stesso numero.
        ->and($blocco[0])->toBeLessThan($numero[0])
        // Le due letture stanno nella stessa transazione, quella che poi scrive la nota.
        ->and($query[$blocco[0]]['livello'])->toBe($query[$numero[0]]['livello'])
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('numero_protocollo', 'like', 'NC-%')->count())->toBe(1);
});
