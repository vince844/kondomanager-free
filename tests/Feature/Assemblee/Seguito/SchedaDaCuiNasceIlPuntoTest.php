<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Una scheda cambia con la delibera solo se il punto **nasce** da lei (il ruolo «origine» fra gli oggetti del punto) e la materia è la sua: l'accordo con un
 * punto sull'accordo, la revoca della dispensa, la gestione dei lavori, il fondo. Un punto che ne parla soltanto non la tocca.
 *
 * Che cosa non copre: la ratifica (la fattura è sempre l'origine dei punti di ratifica proposti dal programma).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('un punto sulle liti che parla dell\'accordo di Gallo Enzo non lo autorizza: nessuna riga sull\'accordo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi();
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['gallo']->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Decreto ingiuntivo contro Gallo Enzo per le rate non pagate dell\'accordo', 'materia' => 'liti',
        'oggetti' => [['accordo_rientro', $accordo->id, 'parla_di']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    expect(SeguitoDiViaRoma12::righe($a))->not->toHaveKey($d->id . ':accordo_rientro:' . $accordo->id . ':autorizzazione');

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeFalse();
});

it('un punto che rinnova la dispensa e parla di quella vecchia propone «Registra la dispensa», non la revoca', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi();
    $vecchia = DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2026-03-10', 'tutte_le_persone' => true, 'registrata_da' => $s->admin->id]);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Dispensa dall\'agire entro sei mesi, crediti del 2026 (come quella del 10/03)', 'materia' => 'dispensa',
        'esercizio_id' => $s->e->id, 'oggetti' => [['dispensa_sei_mesi', $vecchia->id, 'parla_di']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);

    $righe = SeguitoDiViaRoma12::righe($a);

    expect($righe)->toHaveKey($d->id . ':cosa:registra_dispensa')
        ->and($righe)->not->toHaveKey($d->id . ':dispensa_sei_mesi:' . $vecchia->id . ':revoca_della_dispensa');
});

it('un punto sui lavori che parla di un\'altra gestione non la lega, e un punto sul fondo che ne parla soltanto non lo sblocca', function () {
    $s = ViaRoma12::nuovo($this);
    $s->gestione('Facciata 2025', 'straordinaria');
    $vecchia = $s->gestioni['Facciata 2025']['g'];
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo', 'codice' => '2010.R', 'nome' => 'Fondo Riserva',
        'tipo' => 'passivo', 'categoria' => 'fondi', 'created_at' => now(), 'updated_at' => now()]);
    $cassa = Cassa::create(['condominio_id' => $s->c->id, 'conto_contabile_id' => $conto, 'nome' => 'Riserva', 'tipo' => 'fondo',
        'sottotipo_fondo' => 'vincolato_lavori', 'vincolo_descrizione' => 'Lavori straordinari', 'is_override_assemblea' => false, 'saldo_iniziale' => 0,
        'attiva' => true]);
    $a = SeguitoDiViaRoma12::suCarta($s, [
        ['testo' => 'Completamento della facciata', 'materia' => 'lavori', 'importo' => 900000, 'oggetti' => [['gestione', $vecchia->id, 'parla_di']]],
        ['testo' => 'Il fondo «Riserva» resta per la facciata', 'materia' => 'fondo', 'oggetti' => [['cassa', $cassa->id, 'parla_di']]],
    ]);
    $lavori = SeguitoDiViaRoma12::delibera($a, 1);
    $fondo = SeguitoDiViaRoma12::delibera($a, 2);

    $righe = SeguitoDiViaRoma12::righe($a);

    expect($righe)->not->toHaveKey($lavori->id . ':gestione:' . $vecchia->id . ':delibera')
        ->and($righe)->toHaveKey($lavori->id . ':cosa:crea_piano_straordinario')
        ->and($righe)->not->toHaveKey($fondo->id . ':cassa:' . $cassa->id . ':deroga');
});
