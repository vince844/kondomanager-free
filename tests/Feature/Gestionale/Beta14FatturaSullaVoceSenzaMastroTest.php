<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La fattura su una voce di spesa nata senza il suo conto (Coda 113, 1.12.0-beta.14, tappa 5).
 *
 * Il caso. Le voci di spesa importate prima della correzione nascevano senza `conto_contabile_id`, e la prima fattura su quella voce si
 * fermava su «Integrità compromessa: Manca l'ancoraggio in Partita Doppia per il capitolo di spesa.». La migrazione dell'aggiornamento
 * le sistema con «Costi per Servizi», ma nel condominio in cui quel mastro non c'è più (cancellato dall'amministratore) le lascia come
 * sono, e il suo commento diceva che la prima fattura avrebbe ricreato il mastro: la fattura non lo faceva.
 *
 * Adesso la fattura, registrata o modificata, chiede il mastro della voce di spesa che non l'ha alla stessa regola del salvataggio a
 * mano (`CreazioneDellaVoce::contoContabile()`, che crea il mastro che manca) e lo scrive sulla voce: vale per le voci nate senza conto
 * da qualunque strada. Una voce di entrata senza mastro resta come prima. Tutto dalle rotte `fatture.store` e `fatture.update`.
 */

use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Un capitolo e una sua voce di spesa scritti come li lasciava l'importazione: senza il conto. */
function b14fmVoceSenzaConto(array $s): int
{
    $piano = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $capitolo = DB::table('conti')->insertGetId(['piano_conto_id' => $piano->id, 'parent_id' => null, 'is_capitolo' => true, 'nome' => 'Spese generali',
        'tipo' => 'spesa', 'tipo_spesa' => 'standard', 'importo' => 0, 'conto_contabile_id' => null, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('conti')->insertGetId(['piano_conto_id' => $piano->id, 'parent_id' => $capitolo, 'is_capitolo' => false, 'nome' => 'Manutenzione verde',
        'tipo' => 'spesa', 'tipo_spesa' => 'standard', 'importo' => 0, 'conto_contabile_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** Lo sforo della voce senza preventivo, come la finestra lo chiede (la voce ha importo zero). */
function b14fmSforo(): array
{
    return ['dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => [
        'motivazione' => 'Voce senza preventivo', 'importo_sforo' => 50000, 'strategia_rientro' => 'rata_integrativa',
    ]]];
}

it('nel condominio senza «Costi per Servizi» la migrazione lascia la voce senza conto, e la prima fattura ricrea il mastro e la registra', function () {
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $voce = b14fmVoceSenzaConto($s);
    ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->delete(); // cancellato dall'amministratore

    (require database_path('migrations/2026_11_23_100000_assegna_il_conto_alle_voci_di_spesa_importate.php'))->up();
    expect(DB::table('conti')->where('id', $voce)->value('conto_contabile_id'))->toBeNull();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $voce, 500.0, b14fmSforo(), 'FT-VERDE'));

    expect(be52Errori())->toBe([])
        ->and(DB::table('fatture_passive')->where('numero_documento', 'FT-VERDE')->count())->toBe(1);

    $mastro = ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->first();
    expect($mastro)->not->toBeNull()
        ->and((int) DB::table('conti')->where('id', $voce)->value('conto_contabile_id'))->toBe((int) $mastro->id)
        // La riga della fattura sta sul mastro, con la voce.
        ->and(DB::table('righe_scritture')->where('conto_contabile_id', $mastro->id)->where('voce_spesa_id', $voce)->sum('importo'))->toBe(50000);
});

it('con il mastro che c\'è, la voce senza conto prende quello del salvataggio a mano: nessun mastro nuovo', function () {
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    $voce = b14fmVoceSenzaConto($s);
    $mastri = ContoContabile::where('condominio_id', $s['c']->id)->count();
    $costi = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->value('id');

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $voce, 500.0, b14fmSforo(), 'FT-SPESA'));

    expect(be52Errori())->toBe([])
        ->and((int) DB::table('conti')->where('id', $voce)->value('conto_contabile_id'))->toBe($costi)
        ->and(ContoContabile::where('condominio_id', $s['c']->id)->count())->toBe($mastri);
});

it('la modifica di una fattura su una voce rimasta senza conto si salva, e la voce riprende il suo mastro', function () {
    $s = be52Scenario($this);
    $ft = be52FatturaDalServizio($s, be52Fornitore(), $s['spesa']->id, 100.00, 'FT-MODIFICA');
    $costi = (int) $s['spesa']->conto_contabile_id;
    DB::table('conti')->where('id', $s['spesa']->id)->update(['conto_contabile_id' => null]);

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $ft]), be52PayloadModifica($ft, $s, [be52Riga($s['spesa']->id, 120.00)]));

    expect(be52Errori())->toBe([])
        ->and((int) DB::table('conti')->where('id', $s['spesa']->id)->value('conto_contabile_id'))->toBe($costi)
        ->and((int) DB::table('righe_scritture')->where('conto_contabile_id', $costi)->where('voce_spesa_id', $s['spesa']->id)->sum('importo'))->toBe(12000);
});
