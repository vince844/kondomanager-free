<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\Verbale\RegistrazioneDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Il verbale nel seguito delle delibere: i trenta giorni quando nessuno può impugnare, il numero del verbale del giorno della delibera, il verbale agganciato al
 * rendiconto approvato che si apre dalla pagina del rendiconto, e il verbale annullato dopo la conferma (il passo si chiude con la frase, le cose da fare
 * restano).
 *
 * Che cosa non copre: le consegne del verbale una per una (le provano i test della tappa del verbale).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('i trenta giorni con tutti presenti e tutti favorevoli: nessuno può impugnare, la frase lo dice, e la chiusura ha il giorno della delibera', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = SeguitoDiViaRoma12::versioneDelRendiconto($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]]);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    $pagina = SeguitoDelleDelibere::perLaPagina($a, $s->admin);

    expect($pagina['tabella'][0]['trenta_giorni']['frase'])->toBe('Nessuno può impugnarla: tutti presenti e favorevoli. Resta la nullità.')
        ->and($pagina['trenta_giorni']['per_tutti'])->toBe('2026-12-15')
        ->and(VersioniDelRendiconto::perLaChiusura($s->e)['delibere']['rendiconto']['trenta_giorni_per_tutti'])->toBe('2026-12-15');
});

it('la delibera del secondo giorno, con registrato solo il verbale n. 42 del primo, non prende il numero 42; la riunione registrata a mano tiene il suo', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Ratifica della spesa urgente', 'materia' => 'ratifica']]);
    $prima = $a->sedute()->where('convocazione', 'prima')->firstOrFail();
    $seconda = $a->sedute()->where('convocazione', 'seconda')->firstOrFail();
    VerbaleDellAssemblea::query()->forceCreate(['assemblea_id' => $a->id, 'condominio_id' => $s->c->id, 'registro' => VerbaliDellAssemblea::registroDi($a),
        'seduta_id' => $prima->id, 'stato' => 'registrato', 'numero' => 42, 'registrato_il' => now(), 'sottoscritto_il' => $prima->fissata_il]);
    $a->forceFill(['stato' => 'chiusa', 'numero_verbale' => VerbaliDellAssemblea::numeriRegistrati($a)])->saveQuietly();
    $d = Delibera::create(['condominio_id' => $s->c->id, 'assemblea_id' => $a->id, 'punto_id' => $a->punti()->value('id'), 'testo' => 'Ratificata',
        'esito' => 'approvata', 'deliberata_il' => $seconda->fissata_il->format('Y-m-d')]);
    $aMano = ViaRoma12::delibera($s, '2026-11-02', 'Sostituzione della caldaia', 'approvata', '38');

    expect(DeliberaInParole::numeroDelVerbale($d))->toBeNull()
        ->and(DeliberaInParole::numeroDelVerbale($aMano))->toBe('38');
});

it('il verbale agganciato al rendiconto approvato dal seguito si apre dalla pagina del rendiconto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = SeguitoDiViaRoma12::versioneDelRendiconto($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]]);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $verbale = VersioniDelRendiconto::elenco($s->e)[0]['verbale'] ?? null;
    expect($verbale)->not->toBeNull();

    $r = $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.rendiconto.versioni.documento', [$s->c, $s->e, $v->id, $verbale['id']]));

    expect($r->status())->toBe(200);
});

it('il verbale annullato dopo la conferma chiude il passo con la frase, e le cose da fare restano nel passo con la loro azione', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$d->id . ':cosa:crea_piano_straordinario'])->assertOk();
    $verbale = VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->where('stato', 'registrato')->firstOrFail();
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    app(RegistrazioneDelVerbale::class)->annulla($verbale, 'Il verbale registrato non era quello sottoscritto', $s->admin);

    $pagina = SeguitoDelleDelibere::perLaPagina($a, $s->admin);

    expect($pagina['passo']['stato'])->toBe('chiuso')
        ->and($pagina['passo']['perche'])->toBe(SeguitoDelleDelibere::VERBALE_ANNULLATO)
        ->and(array_column($pagina['da_eseguire'], 'tipo'))->toBe(['crea_piano_straordinario'])
        ->and(collect($pagina['tabella'])->firstWhere('delibera_id', $d->id)['verbale_annullato'])->toBeTrue()
        ->and(CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail()->stato)->toBe(StatoCosaDaFare::DA_FARE);
});
