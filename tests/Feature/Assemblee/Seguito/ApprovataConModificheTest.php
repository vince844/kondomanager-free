<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\StatoPianoRate;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\VersionePreventivo;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * R6, la delibera approvata con modifiche: il preventivo aspetta una versione fermata dopo il giorno della delibera (il rendiconto ha la sua catena in
 * `CateneDelleDelibereTest`), e «Correggi e ferma una versione nuova» si chiude quando quella versione si approva con «Applica»; le altre schede si
 * correggono prima per la loro strada, e la loro riga nasce spenta.
 *
 * Che cosa non copre: la pagina del riepilogo con «Che cosa ha cambiato l'assemblea» (si guarda a video).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il preventivo approvato con modifiche: dopo la riunione «Ferma il preventivo» c\'è, e la versione nuova approvata chiude la cosa da fare', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $spedita = null;
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [1 => 'approvata_con_modifiche'], function ($a) use ($s, &$spedita) {
        $spedita = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['modifiche' => [$d->id => 'Pulizia delle scale a € 5.400,00']])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::RIFAI_VERSIONE->value)->firstOrFail();
    expect($spedita->fresh()->stato)->toBe(StatoVersionePreventivo::PRESENTATA)->and($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);

    // Il 18/12 Andrea corregge la voce e ferma il preventivo di nuovo: con le modifiche la rotta lo accetta.
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $s->voci['Pulizia delle scale']['conto']->update(['importo' => 540000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $a->punti()->value('id')])
        ->assertSessionHasNoErrors();
    $nuova = VersionePreventivo::query()->where('esercizio_id', $s->e->id)->where('numero', 2)->firstOrFail();
    $applica = $d->id . ':preventivo_versione:' . $nuova->id . ':approvazione';
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $applica])->assertOk();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]),
        ['chiave' => $d->id . ':piano_rate:' . $piano->id . ':delibera'])->assertOk();

    expect($nuova->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::APPROVATO)
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and(StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('eseguita');
});

it('l\'accordo approvato con modifiche (sei rate invece di dieci) nasce spento con la frase: «Conferma» non lo autorizza, «Applica» dopo la correzione sì', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('gallo')->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Autorizzazione del piano di rientro di Gallo Enzo', 'materia' => 'accordo',
        'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]]], '2026-12-15', [1 => 'approvata_con_modifiche']);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':accordo_rientro:' . $accordo->id . ':autorizzazione';
    $riga = SeguitoDiViaRoma12::righe($a)[$chiave];
    expect($riga['spuntata'])->toBeFalse()->and($riga['frase'])->toStartWith('Approvata con modifiche: correggi prima');

    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['modifiche' => [$d->id => 'Sei rate invece di dieci']])->assertOk();
    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeFalse();

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $chiave])->assertOk();
    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeTrue();
});

it('la versione fermata alle 00:30 di Roma del giorno dopo è «fermata dopo il giorno della delibera», anche se in UTC è ancora quel giorno', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = SeguitoDiViaRoma12::versioneDelRendiconto($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]], [1 => [1 => 'approvata_con_modifiche', 2 => 'approvata']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1, 1);
    // Il 16/12 alle 00:30 di Roma (il 15/12 alle 23:30 in UTC) Andrea ferma la versione corretta.
    ConvocazioneDiViaRoma12::oggi('2026-12-16 00:30');
    $nuova = SeguitoDiViaRoma12::versioneDelRendiconto($s, 3, '2026-12-16 00:30:00');

    expect(SeguitoDiViaRoma12::righe($a))->toHaveKey($d->id . ':rendiconto_versione:' . $nuova->id . ':approvazione');
});

it('il preventivo corretto approvato con «Applica» e il piano che chiede ancora la cifra di prima: nasce «Rimetti il piano», e la delibera resta da eseguire', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', capitoli: true, bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [1 => 'approvata_con_modifiche'], function ($a) use ($s) {
        app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    $chiaveDellaCosa = $d->id . ':cosa:rifai_versione';
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$chiaveDellaCosa => ['entro_il' => '2026-12-22']],
        'modifiche' => [$d->id => 'Pulizia delle scale a € 5.400,00']])->assertOk();
    // Il 18/12 la voce corretta e la versione nuova; il piano ha ancora la sua copia della voce a € 6.000,00.
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $s->voci['Pulizia delle scale']['conto']->update(['importo' => 540000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $a->punti()->value('id')])
        ->assertSessionHasNoErrors();
    $nuova = VersionePreventivo::query()->where('esercizio_id', $s->e->id)->where('numero', 2)->firstOrFail();

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]),
        ['chiave' => $d->id . ':preventivo_versione:' . $nuova->id . ':approvazione'])->assertOk();

    $rimetti = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::RIMETTI_PIANO->value)->first();
    expect($nuova->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA)
        ->and($rimetti?->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and($rimetti?->entro_il?->format('Y-m-d'))->toBe('2026-12-22')
        ->and((int) $rimetti?->oggetto_id)->toBe((int) $piano->id)
        ->and(StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('da_eseguire');
});
