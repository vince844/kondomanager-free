<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\RegistroModifica;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il registro delle modifiche del verbale (V15): una riga per ogni gesto che conta — le discussioni scritte in sala alla chiusura, la generazione, una
 * versione (non ogni salvataggio), l'aggiornamento delle parti della sala, la bozza stampata, la registrazione, la copia sostituita, «Impugnata»,
 * l'annullamento — con l'ora del gesto e l'ora d'arrivo per ciò che è arrivato senza rete. Il diario dell'assemblea le legge da sé.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('una riga per gesto, dalla sala all\'annullamento; un salvataggio non è una riga; il diario le legge', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $prima = (int) RegistroModifica::query()->max('id');
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $doc = fn ($t) => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($t)]);
    $p4 = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    // Tre salvataggi della stessa discussione, senza rete: arrivano alle 21:00, segnati alle 19:50, alle 19:51 e, dopo una pausa, alle 19:58. I primi due
    // sono un salvataggio, non una riga; la pausa fa la versione del testo delle 19:51, con l'ora in cui è stato scritto e l'ora d'arrivo.
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo'), '2026-12-15 19:50', 0)]);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo della ditta Edil'), '2026-12-15 19:51', 1)]);
    expect(RegistroModifica::query()->where('id', '>', $prima)->count())->toBe(0);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo della ditta Edil, € 18.400'), '2026-12-15 19:58', 2)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $chiusura = (int) RegistroModifica::query()->max('id');

    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.aggiorna_sala', [$s->c, $a, $verbale]))->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]))->assertOk();
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.verbale.copia', [$s->c, $a, $verbale]), ['copia' => VerbaleDiViaRoma12::copia('verbale_completo.pdf'),
        'perche' => 'Mancava la pagina 3'])->assertSessionHasNoErrors();
    $delibera = \App\Models\Assemblee\Delibera::query()->where('assemblea_id', $a->id)->orderBy('id')->firstOrFail();
    $this->post(route('admin.gestionale.assemblee.delibere.impugnata', [$s->c, $a, $delibera]), ['giorno' => '2026-12-15',
        'nota' => 'Citazione del condomino 31'])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale->refresh()]), ['motivo' => 'Il numero era sbagliato'])
        ->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
    $ora = fn ($t) => $t->copy()->setTimezone('Europe/Rome')->format('H:i');
    expect($righe->map(fn ($r) => $r->oggetto_tipo . ' · ' . $r->riepilogo . ' · ' . $ora($r->avvenuta_il) . '/' . $ora($r->registrata_il))->all())->toBe([
        'verbale · versione 1, scritta in sala: la discussione del punto 4 · dal dispositivo «portatile di Andrea» · 19:51/21:00',
        'assemblea · Assemblea ordinaria del 15/12/2026: chiusa alle 20:55: 5 delibere, 1 senza voto · 20:55/21:00',
        'verbale · discussioni scritte in sala: il punto 4 · 20:55/21:00',
        'verbale · generato dal modello «Testo del programma», versione 1 · 21:00/21:00',
        'verbale · parti con i dati della sala aggiornate, versione 2 · 21:00/21:00',
        'verbale · bozza stampata per la firma, versione 3, impronta ' . substr((string) $verbale->refresh()->stampata['impronta'], 0, 8) . '… · 21:00/21:00',
        'verbale · registrato n. 42 nel registro dei verbali di Via Roma 12, sottoscritto il 15/12/2026 · 21:00/21:00',
        'registro_dei_verbali · Registro dei verbali di Via Roma 12: continua il libro di carta, ultimo numero 41 del 22/10/2026 · 21:00/21:00',
        'verbale · copia sottoscritta sostituita: verbale_completo.pdf; la copia di prima resta fra le versioni · 21:00/21:00',
        'delibera · impugnata il 15/12/2026 · 21:00/21:00',
        'verbale · registrazione n. 42 annullata; il numero non torna, e il testo torna in bozza · 21:00/21:00',
    ])
        ->and($righe->firstWhere('oggetto_tipo', 'delibera')->perche)->toBe('Citazione del condomino 31')
        ->and($righe->last()->perche)->toBe('Il numero era sbagliato')
        ->and($righe->where('oggetto_tipo', 'verbale')->pluck('scheda_tipo')->unique()->values()->all())->toBe(['assemblea']);

    $diario = collect(DiarioDellAssemblea::righe($a->refresh(), 'modifiche')['righe'])->pluck('frase')->implode("\n");
    foreach (['bozza stampata per la firma', 'registrato n. 42', 'impugnata il 15/12/2026', 'registrazione n. 42 annullata', 'scritta in sala'] as $frase) {
        expect($diario)->toContain($frase);
    }
});
