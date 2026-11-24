<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 113 (decisione 707 (3)): il capitolo e le voci importati dal bilancio consuntivo nascono con il loro conto di partita doppia.
 *
 * Fino alla 1.12.0-beta.13 `LivelloCapitoli` li scriveva senza `conto_contabile_id`: la regolazione immediata non li offriva e la prima fattura
 * passiva su una voce importata si fermava su «Integrità compromessa». La catena provata qui va dall'importazione alla fattura (dalla rotta) e
 * alla regolazione immediata sulla voce importata.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalCapitolo;
use App\Services\Import\Canonical\CanonicalStrutturaSpese;
use App\Services\Import\Canonical\CanonicalVoceSpesa;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCapitoli;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Gestionale/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

/** Il bilancio consuntivo di un capitolo con due voci, importato nel condominio dello scenario. */
function b14CapitoliImporta(array $s): array
{
    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
    $ctx->conCanonico(LivelloCapitoli::CHIAVE, new CanonicalStrutturaSpese([
        new CanonicalCapitolo('SPESE GENERALI', [new CanonicalVoceSpesa('Manutenzione verde', 120000), new CanonicalVoceSpesa('Pulizie', 80000)], 200000),
    ], 200000, 0));
    $ctx->risolvi(LivelloCondominio::CHIAVE, $s['c']);
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $s['e']);
    $esito = (new LivelloCapitoli)->commit($ctx);
    expect($esito->riuscito())->toBeTrue();

    return [
        'capitolo' => Conto::where('nome', 'SPESE GENERALI')->firstOrFail(),
        'verde' => Conto::where('nome', 'Manutenzione verde')->firstOrFail(),
        'pulizie' => Conto::where('nome', 'Pulizie')->firstOrFail(),
    ];
}

it('ogni conto del lotto ha il mastro «Costi per Servizi» del suo condominio', function () {
    $s = be52Condominio();
    $costi = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->value('id');
    $c = b14CapitoliImporta($s);

    expect($costi)->toBeGreaterThan(0)
        ->and((int) $c['capitolo']->conto_contabile_id)->toBe($costi)
        ->and((int) $c['verde']->conto_contabile_id)->toBe($costi)
        ->and((int) $c['pulizie']->conto_contabile_id)->toBe($costi);
});

it('in un condominio di prima, senza il mastro, l\'importazione lo crea e ancora le voci', function () {
    $s = be52Condominio();
    ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->forceDelete();
    // `ensureDefaultConti` esce appena trova «Crediti v/Condomini»: nei condomini di prima il mastro mancante lo ricrea la richiesta.
    $c = b14CapitoliImporta($s);

    $costi = ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->get();
    expect($costi)->toHaveCount(1)
        ->and((int) $c['verde']->conto_contabile_id)->toBe((int) $costi->first()->id)
        ->and((int) $c['capitolo']->conto_contabile_id)->toBe((int) $costi->first()->id);
});

it('la catena: la fattura passiva su una voce appena importata si registra dalla rotta, e la voce si offre alla regolazione immediata', function () {
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $this->user = be52Utente();
    $c = b14CapitoliImporta($s);
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);

    // La voce importata ha il preventivo a zero: la fattura la porta oltre, con la motivazione dello sforamento, come fa l'amministratore.
    $extra = ['dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => [
        'motivazione' => 'Voce importata senza preventivo', 'importo_sforo' => 50000, 'strategia_rientro' => 'rata_integrativa',
    ]]];
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $c['verde']->id, 500.0, $extra, 'FT-GIARDINIERE'))
        ->assertSessionHasNoErrors();
    expect(DB::table('fatture_passive')->where('numero_documento', 'FT-GIARDINIERE')->count())->toBe(1)
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $c['verde']->id)->count())->toBeGreaterThan(0);

    $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', [$s['c']]))
        ->assertInertia(fn ($p) => $p->where('capitoli', fn ($lista) => collect($lista)->pluck('id')->contains($c['pulizie']->id)));
});
