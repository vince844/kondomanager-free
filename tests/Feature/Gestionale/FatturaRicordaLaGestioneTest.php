<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 687 (decisione 507): la colonna `fatture_passive.gestione_id`, scritta alla registrazione e riempita dalla migrazione per le fatture che ci sono.
 *
 * - La registrazione la scrive (la stessa gestione della scrittura), lo storno dà alla nota la gestione della fattura.
 * - La migrazione dei dati la riempie dalla scrittura di competenza, poi dalle voci; **non corregge**: una fattura che una modifica ha spostato sull'ordinaria
 *   prende l'ordinaria della sua scrittura, e la elenca il controllo (`FattureToccateDallaModificaTest`). Rieseguita non cambia niente.
 * - La migrazione della colonna è rieseguibile, con due guardie (colonna e chiave esterna), anche interrotta a metà.
 *
 * Cosa non copre: MySQL (la suite gira su sqlite; le guardie MySQL leggono `information_schema` come le altre migrazioni del dataset di
 * `UpgradeMigrationsRerunTest`); la cancellazione di una gestione con fatture (la chiave mette a vuoto la colonna: letto, non provato qui).
 */

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/SpesaNonPrevistaScenario.php';

uses(RefreshDatabase::class);

it('la registrazione scrive la gestione della fattura, la stessa della sua scrittura; lo storno dà alla nota la gestione della fattura', function () {
    $r = snpScenario($this);
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false);

    expect($f->gestione_id)->toBe($r['g']->id)->and(snpGestioniDelleScritture($r))->toBe([$r['g']->id]);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.storno', [$r['s']->c, $f]), ['motivo' => 'Registrata due volte'])->assertSessionHasNoErrors();
    expect(FatturaPassiva::where('tipo_documento', 'nota_credito')->sole()->gestione_id)->toBe($r['g']->id);
});

it('la migrazione dei dati riempie la colonna dalla scrittura, poi dalle voci; la fattura spostata resta dove la mette la sua scrittura; rieseguita non cambia niente', function () {
    $r = snpScenario($this);
    $ordinaria = $r['s']->gestioni['Ordinaria 2026']['g']->id;
    $voce = snpVoce($this, $r, '1.220,00');
    $normale = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-NORMALE');
    $spostata = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SPOSTATA');
    $senzaScrittura = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SENZA');
    $nuda = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-NUDA');

    // La spostata come la lasciava «Modifica» della 1.11.0 (B4); due fatture senza scrittura, come le pregresse del database di sviluppo: una con la voce, una
    // senza righe. Poi la colonna vuota, come prima della migrazione.
    $competenza = fn (FatturaPassiva $f) => DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->pluck('scrittura_contabile_id');
    DB::table('scritture_contabili')->whereIn('id', $competenza($spostata))->update(['gestione_id' => $ordinaria]);
    foreach ([$senzaScrittura, $nuda] as $f) {
        $ids = $competenza($f);
        DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->delete();
        DB::table('righe_scritture')->whereIn('scrittura_id', $ids)->delete();
        DB::table('scritture_contabili')->whereIn('id', $ids)->delete();
    }
    DB::table('righe_fattura')->where('fattura_passiva_id', $nuda->id)->delete();
    DB::table('fatture_passive')->update(['gestione_id' => null]);

    $migrazione = require database_path('migrations/2026_11_18_210100_riempi_gestione_delle_fatture_passive.php');
    $migrazione->up();
    $colonna = fn () => DB::table('fatture_passive')->orderBy('id')->pluck('gestione_id', 'numero_documento')->map(fn ($g) => $g === null ? null : (int) $g)->all();
    $atteso = ['FT-NORMALE' => $r['g']->id, 'FT-SPOSTATA' => $ordinaria, 'FT-SENZA' => $r['g']->id, 'FT-NUDA' => null];
    expect($colonna())->toBe($atteso);

    // Le scritture e le righe non si toccano.
    expect(DB::table('scritture_contabili')->whereIn('id', $competenza($spostata))->value('gestione_id'))->toBe($ordinaria);

    $migrazione->up();
    expect($colonna())->toBe($atteso);

    // Chi legge una fattura con la colonna vuota ripiega: la nuda non sa la sua gestione, e non si indovina l'ordinaria.
    expect(FatturaPassiva::where('numero_documento', 'FT-NUDA')->sole()->gestioneDellaFattura())->toBeNull();
});

it('la migrazione della colonna, interrotta dopo la colonna e prima della chiave, si riprende; andata e ritorno due volte', function () {
    $migrazione = require database_path('migrations/2026_11_18_210000_add_gestione_id_to_fatture_passive.php');
    $chiavi = fn () => collect(Schema::getForeignKeys('fatture_passive'))->filter(fn ($k) => $k['columns'] === ['gestione_id'])
        ->map(fn ($k) => $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->values()->all();

    expect(Schema::hasColumn('fatture_passive', 'gestione_id'))->toBeTrue()->and($chiavi())->toBe(['gestioni set null']);

    Schema::table('fatture_passive', fn ($t) => $t->dropForeign(['gestione_id']));
    expect($chiavi())->toBe([]);

    $migrazione->up();
    $migrazione->up();
    expect($chiavi())->toBe(['gestioni set null']);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasColumn('fatture_passive', 'gestione_id'))->toBeFalse();

    $migrazione->up();
    expect(Schema::hasColumn('fatture_passive', 'gestione_id'))->toBeTrue()->and($chiavi())->toBe(['gestioni set null']);
});
