<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Models\Gestionale\AccordoRientro;
use App\Services\Gestionale\AutorizzazioneDellAccordo;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * R1 e R2 quando «Conferma» arriva di nuovo (la prosecuzione del 20/12, un doppio clic, la pagina rimasta aperta): applica soltanto le righe delle delibere
 * ancora da confermare, e per le altre c'è «Applica»; una riga tolta non torna spuntata; una cosa scritta non si scrive due volte; senza niente da fare, nessuna
 * riga nel registro. Una riga cambiata fra l'apertura e la conferma risponde con la sua frase. «Che cosa ha cambiato l'assemblea» ha la sua riga nel registro.
 *
 * Che cosa non copre: due richieste nello stesso istante (la suite è sequenziale: il blocco sull'assemblea si legge nel codice).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la riga tolta alla prima conferma non torna spuntata, e una seconda «Conferma» con le righe di serie non la applica; «Applica» sì', function () {
    $s = ViaRoma12::nuovo($this);
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('gallo')->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Autorizzazione del piano di rientro di Gallo Enzo', 'materia' => 'accordo',
        'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':accordo_rientro:' . $accordo->id . ':autorizzazione';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [])->assertOk();
    $dopo = SeguitoDiViaRoma12::righe($a)[$chiave];

    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave])->assertOk()->assertJsonPath('esiti.' . $chiave . '.esito', 'non_applicata');

    expect($dopo['spuntata'])->toBeFalse()->and((bool) $accordo->fresh()->autorizzato_assemblea)->toBeFalse();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $chiave])->assertOk();
    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeTrue();
});

it('«Conferma» premuta due volte con la stessa cosa scritta la scrive una volta, e la seconda non scrive nessuna riga nel registro', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $corpo = ['altre' => [['delibera_id' => $d->id, 'testo' => 'Firma con Serramenti Marino', 'entro_il' => '2027-01-10']]];
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], $corpo)->assertOk();
    $prima = ViaRoma12::ultimaRiga();

    SeguitoDiViaRoma12::conferma($this, $s, $a, [], $corpo)->assertOk()->assertJsonPath('esiti.altra:0.esito', 'non_applicata');

    expect(CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->count())->toBe(1)
        ->and(Evento::query()->where('meta->type', 'delibera_da_eseguire')->count())->toBe(1)
        ->and(ViaRoma12::righe($prima)->count())->toBe(0);
});

it('la stessa cosa scritta due volte nella stessa delibera con «Un\'altra cosa da fare» si rifiuta con la frase', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [])->assertOk();
    $rotta = route('admin.gestionale.assemblee.seguito.cose.store', [$s->c, $a, $d]);
    $this->actingAs($s->admin)->post($rotta, ['testo' => 'Firma con Serramenti Marino'])->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->post($rotta, ['testo' => 'Firma con  Serramenti Marino'])->assertSessionHasErrors('testo');

    expect(CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->count())->toBe(1);
});

it('una riga cambiata fra l\'apertura e la conferma risponde con la sua frase («già autorizzato il …»), non con «ricarica la pagina»', function () {
    $s = ViaRoma12::nuovo($this);
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('gallo')->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Autorizzazione del piano di rientro di Gallo Enzo', 'materia' => 'accordo',
        'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':accordo_rientro:' . $accordo->id . ':autorizzazione';
    expect(SeguitoDiViaRoma12::righe($a)[$chiave]['tipo'])->toBe('applica');
    // Fra l'apertura e la conferma l'accordo si autorizza dalla sua pagina, con un'altra delibera.
    $altra = ViaRoma12::delibera($s, '2026-12-14', 'Autorizzazione dell\'accordo', 'approvata', '41');
    AutorizzazioneDellAccordo::dallaDelibera($accordo, $altra, $s->admin);

    $r = SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave])->assertOk();

    expect($r->json('esiti.' . $chiave . '.esito'))->toBe('non_applicata')
        ->and($r->json('esiti.' . $chiave . '.frase'))->toStartWith('Accordo di rientro di ')
        ->and($r->json('esiti.' . $chiave . '.frase'))->toContain(': già autorizzato il 14/12/2026');
});

it('«Che cosa ha cambiato l\'assemblea» ha la sua riga nel registro sulla delibera, e una conferma successiva non la riscrive', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento']], '2026-12-15',
        [1 => 'approvata_con_modifiche']);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $prima = ViaRoma12::ultimaRiga();
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['modifiche' => [$d->id => 'Accensione dalle 6:00 invece che dalle 5:30']])->assertOk();
    $righe = ViaRoma12::righe($prima, 'delibera');
    expect($righe->count())->toBe(1)
        ->and($righe->first()->riepilogo)->toBe('Approvata con modifiche: scritto che cosa ha cambiato l\'assemblea');

    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['modifiche' => [$d->id => 'Riscritta in silenzio']])->assertOk();

    expect($d->fresh()->dati['modifiche'])->toBe('Accensione dalle 6:00 invece che dalle 5:30');
});
