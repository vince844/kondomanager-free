<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il registro dei verbali (V8): le registrazioni, le annotazioni delle sedute deserte («Mancata costituzione», senza numero), le riunioni registrate a mano
 * («A mano», con il numero scritto), le registrazioni annullate con il perché, per data; il registro della scala con la sua numerazione (decisione 598); «Da
 * dove parte» nel registro delle modifiche; «Incollata nel libro» per un verbale e per un'annotazione; la stampa e la pagina dell'annotazione.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('le righe per data: l\'annotazione della prima deserta, il verbale registrato, la riunione a mano; il punto di partenza nel registro delle modifiche', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $prima = (int) \App\Models\RegistroModifica::query()->max('id');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.partenza', $s->c), ['scelta' => 'libro', 'ultimo' => 41, 'del' => '2026-10-22'])
        ->assertSessionHasNoErrors();
    $riga = \App\Models\RegistroModifica::query()->where('id', '>', $prima)->firstOrFail();
    expect($riga->riepilogo)->toContain('Registro dei verbali di Via Roma 12: continua il libro di carta, ultimo numero 41 del 22/10/2026');

    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['partenza' => null])->assertSessionHasNoErrors();
    ViaRoma12::delibera($s, '2026-03-12', 'Rifacimento della facciata', 'approvata', '43');

    $righe = app(RegistroDeiVerbali::class)->righe($s->c, 'condominio');
    expect(array_map(fn ($r) => [$r['giorno'], $r['numero'], $r['parola']], $righe))->toBe([
        ['2026-03-12', '43', 'A mano'],
        ['2026-12-14', null, 'Mancata costituzione'],
        ['2026-12-15', 42, 'Sottoscritto'],
    ])->and($righe[2]['assemblea']['parola'])->toBe('Ordinaria, seconda convocazione')->and($righe[2]['contenuto'])->toBe('5 delibere · 1 dichiarazione')
        ->and($righe[1]['assemblea']['parola'])->toBe('Ordinaria, prima convocazione')
        // La riunione a mano con il n. 43 sposta il numero proposto: il registro va solo avanti.
        ->and(app(RegistroDeiVerbali::class)->prossimoNumero($s->c, 'condominio'))->toBe(44);
});

it('l\'annullata resta con il perché; «Incollata nel libro» per il verbale e per l\'annotazione; la stampa del registro e la pagina dell\'annotazione', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale]), ['motivo' => 'Scansione di un\'altra assemblea.']);
    $primaDeserta = SalaDiViaRoma12::seduta($a, 'prima');
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.libro', $s->c), ['seduta_id' => $primaDeserta->id, 'giorno' => '2026-12-16'])
        ->assertSessionHasNoErrors();

    $righe = collect(app(RegistroDeiVerbali::class)->righe($s->c, 'condominio'));
    expect($righe->firstWhere('tipo', 'verbale')['annullato']['motivo'])->toBe('Scansione di un\'altra assemblea.')
        ->and($righe->firstWhere('tipo', 'verbale')['parola'])->toBe('Annullato')
        ->and($righe->firstWhere('tipo', 'annotazione')['nel_libro_il'])->toBe('2026-12-16');

    $stampa = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.stampa', $s->c));
    expect($stampa->status())->toBe(200)->and($stampa->headers->get('Content-Type'))->toBe('application/pdf');
    $pagina = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.annotazione', [$s->c, $primaDeserta->id]));
    expect($pagina->status())->toBe(200)->and($pagina->headers->get('Content-Type'))->toBe('application/pdf');
    // Una seduta di un altro condominio, o non deserta, non ha la pagina.
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro.annotazione', [$s->c, $seduta->id]))->assertNotFound();
});

it('l\'assemblea della sola scala ha il suo registro, con la sua numerazione (decisione 598); quello del condominio non cambia', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Funi dell\'ascensore della scala B', 'materia' => 'lavori']]), $s);
    $b->forceFill(['ambito' => 'scala', 'scala_id' => $scala->id])->saveQuietly();
    $seduta = SalaDiViaRoma12::seduta($b);
    $verbaleB = \App\Models\Assemblee\VerbaleDellAssemblea::create(['assemblea_id' => $b->id, 'condominio_id' => $s->c->id,
        'registro' => \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registroDi($b->refresh()), 'seduta_id' => $seduta->id, 'stato' => 'bozza']);
    \App\Models\Assemblee\ParteDelVerbale::create(['verbale_id' => $verbaleB->id, 'parte' => 'intero', 'revisione' => 1,
        'documento' => \App\Services\Assemblee\Verbale\StrutturaDelVerbale::documento([\App\Services\Assemblee\Verbale\StrutturaDelVerbale::paragrafo('Scala B.')])]);
    $seduta->forceFill(['aperta_il' => '2026-12-15 17:42:00', 'esito' => 'tenuta', 'chiusa_il' => '2026-12-15 19:00:00'])->saveQuietly();

    VerbaleDiViaRoma12::registra($this, $s, $verbaleB, ['partenza' => ['scelta' => 'da_uno']])->assertSessionHasNoErrors();

    expect($verbaleB->refresh()->registro)->toBe('scala:' . $scala->id)->and($verbaleB->numero)->toBe(1)
        ->and(app(RegistroDeiVerbali::class)->prossimoNumero($s->c, 'condominio'))->toBe(43)
        ->and(RegistroDeiVerbali::perimetro($s->c, 'scala:' . $scala->id)['nome'])->toBe('Registro dei verbali della scala B di Via Roma 12')
        ->and(collect(app(RegistroDeiVerbali::class)->registri($s->c))->pluck('chiave')->all())->toBe(['condominio', 'scala:' . $scala->id]);
});

it('il ritorno da dove si è venuti: l\'assemblea, il verbale, altrimenti l\'archivio; il server controlla che siano del condominio e non prende nessun indirizzo dalla richiesta', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $ritorno = fn (array $query) => $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro', [$s->c] + $query))->assertOk()
        ->viewData('page')['props']['ritorno'];
    $archivio = ['url' => route('admin.gestionale.assemblee.index', $s->c), 'testo' => 'Assemblee'];

    expect($ritorno([]))->toBe($archivio)
        ->and($ritorno(['da_assemblea' => $a->id]))->toBe(['url' => route('admin.gestionale.assemblee.show', [$s->c, $a]), 'testo' => 'L\'assemblea'])
        ->and($ritorno(['da_verbale' => $verbale->id]))->toBe(['url' => route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]) . '?verbale=' . $verbale->id, 'testo' => 'Il verbale'])
        // Un indirizzo libero, un numero che non c'è, una scritta al posto del numero: l'archivio.
        ->and($ritorno(['da_assemblea' => 'https://esempio.test/fuori']))->toBe($archivio)
        ->and($ritorno(['da_assemblea' => 999999]))->toBe($archivio)
        ->and($ritorno(['da_verbale' => '../..']))->toBe($archivio);

    // L'assemblea e il verbale di un altro condominio non valgono.
    $altro = \App\Models\Condominio::factory()->create();
    $a->forceFill(['condominio_id' => $altro->id])->saveQuietly();
    $verbale->forceFill(['condominio_id' => $altro->id])->saveQuietly();
    expect($ritorno(['da_assemblea' => $a->id]))->toBe($archivio)
        ->and($ritorno(['da_verbale' => $verbale->id]))->toBe($archivio);
});

it('i collegamenti al registro portano l\'assemblea o il verbale da cui si parte', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('verbale.rotte.registro', fn ($u) => str_contains($u, 'da_verbale=' . $verbale->id)));
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $passo = collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->keyBy('chiave')->all()['verbale'];
    $libro = collect($passo['manca'])->firstWhere('chiave', 'libro');
    expect($libro['collegamento']['url'])->toContain('da_assemblea=' . $a->id);
});
