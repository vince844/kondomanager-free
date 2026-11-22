<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestione;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * La riunione di Via Roma 12 tenuta su carta il 15/12/2026: il 16/12 Andrea conferma il riepilogo, il 20/12 carica il verbale firmato n. 42.
 *
 * - **Le frasi che restano scritte nelle schede** (lo sblocco del fondo, il riferimento della regola, il compito della cosa, la descrizione della gestione
 *   nata da «Crea il piano straordinario») dicono il giorno e il punto, senza lo stato del verbale: «Sbloccato con la delibera del 15/12/2026, punto 1.».
 * - **Le frasi calcolate** dicono «(verbale da caricare)» finché il verbale non c'è, e il numero da solo quando arriva.
 *
 * Che cosa non copre: l'addebito e il compito chiuso dalla ratifica, che usano la stessa forma (`DeliberaInParole::frase()` e `breve()` con `scritta`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il fondo «Riserva», vincolato ai lavori, ancora bloccato. */
function fsrFondo(Beta51Scenario $s): Cassa
{
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo', 'codice' => '2010.R', 'nome' => 'Fondo Riserva',
        'tipo' => 'passivo', 'categoria' => 'fondi', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create(['condominio_id' => $s->c->id, 'conto_contabile_id' => $conto, 'nome' => 'Riserva', 'tipo' => 'fondo',
        'sottotipo_fondo' => 'vincolato_lavori', 'vincolo_descrizione' => 'Lavori straordinari', 'is_override_assemblea' => false, 'saldo_iniziale' => 0, 'attiva' => true]);
}

it('catena · su carta il 15/12 → il 16/12 la conferma, la regola e la gestione → il 20/12 il verbale n. 42: le frasi scritte dicono il giorno e il punto, quelle calcolate il numero', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna');
    $e27 = Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31',
        'stato' => 'aperto']);
    $fondo = fsrFondo($s);
    $a = SeguitoDiViaRoma12::suCarta($s, [
        ['testo' => 'Utilizzo del fondo «Riserva»', 'materia' => 'fondo', 'oggetti' => [['cassa', $fondo->id, 'origine']]],
        ['testo' => 'Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti', 'materia' => 'regole'],
        ['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000],
    ]);
    [$d1, $d2, $d3] = [SeguitoDiViaRoma12::delibera($a, 1), SeguitoDiViaRoma12::delibera($a, 2), SeguitoDiViaRoma12::delibera($a, 3)];
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$d3->id . ':cosa:crea_piano_straordinario' => ['entro_il' => '2027-01-15', 'a_chi' => 'me']]])
        ->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d3->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();

    // Il 16/12, prima del verbale: la regola dalla delibera e la gestione del portone.
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.recupero-crediti.store', [$s->c]), [
        'dal' => '2027-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'delibera', 'riferimento' => null, 'delibera_id' => $d2->id,
    ])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario', ['condominio' => $s->c->id, 'assemblea' => $a->id,
        'cosa' => $cosa->id]), ['gestione_nome' => 'Portone d\'ingresso 2027', 'esercizio_id' => $e27->id, 'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31', 'voce_nome' => 'Sostituzione del portone', 'importo' => '14.500,00', 'tabella_id' => $s->tabelle['Proprietà']->id])->assertOk();

    $scritte = fn () => [
        'fondo' => (string) $fondo->fresh()->motivazione_override,
        'regola' => (string) RegolaRecupero::query()->where('condominio_id', $s->c->id)->sole()->riferimento,
        'compito' => (string) Evento::query()->findOrFail($cosa->evento_id)->description,
        'gestione' => (string) Gestione::query()->where('condominio_id', $s->c->id)->where('tipo', 'straordinaria')->sole()->descrizione,
    ];
    expect($scritte())->toBe([
        'fondo' => 'Sbloccato con la delibera del 15/12/2026, punto 1.',
        'regola' => 'Delibera del 15/12/2026, assemblea ordinaria, punto 2',
        'compito' => 'Delibera del 15/12/2026, punto 3, entro il 15/01/2027.',
        'gestione' => 'Dalla delibera del 15/12/2026, punto 3.',
    ])
        // La frase calcolata dice lo stato del verbale.
        ->and(DeliberaInParole::breve($d1->fresh()))->toBe('Delibera del 15/12/2026, punto 1 (verbale da caricare)');

    // Il 20/12 il verbale firmato n. 42: la frase calcolata dice il numero da sola, quelle scritte restano vere.
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    $a->forceFill(['numero_verbale' => '42'])->saveQuietly();
    \App\Services\Assemblee\DeliberaAMano::caricaVerbale($a->fresh(), UploadedFile::fake()->create('verbale.pdf', 20, 'application/pdf'), $s->admin);

    expect(DeliberaInParole::breve($d1->fresh()))->toBe('Delibera del 15/12/2026, punto 1 (verbale n. 42)')
        ->and(implode(' ', $scritte()))->not->toContain('verbale da caricare')
        // Una frase scritta dopo il verbale porta il numero, che c'è già.
        ->and(DeliberaInParole::breve($d1->fresh(), scritta: true))->toBe('Delibera del 15/12/2026, punto 1 (verbale n. 42)');
});
