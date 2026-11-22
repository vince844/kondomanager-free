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
use App\Models\Gestionale\VersionePreventivo;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Il preventivo che l'assemblea approva è quello spedito con la convocazione, e il piano rate si approva solo se chiede ciò che l'assemblea ha approvato
 * (decisione 622 (8)): le cifre delle voci, le tabelle con il coefficiente, le rate già generate. Il piano «fuori» tiene la delibera «Da eseguire» con la
 * cosa «Rimetti il piano come il preventivo approvato».
 *
 * Che cosa non copre: lo scadenziario per condòmino (si confronta il piano con le sue voci e con l'allineamento delle sue rate, non persona per persona).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('«Ferma il preventivo» dopo la convocazione si rifiuta con la frase, e «Conferma» approva la versione spedita con il piano fuori', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $spedita = null;
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s, &$spedita) {
        $spedita = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
        // Dopo la spedizione: la voce passa da € 3.800,00 a € 5.000,00.
        $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
    });
    $piano = $s->piano('Ordinario 2026');
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $a->punti()->value('id')])
        ->assertSessionHasErrors(['punto' => VersioniDelPreventivo::DOPO_LA_CONVOCAZIONE]);
    expect(VersionePreventivo::query()->where('esercizio_id', $s->e->id)->count())->toBe(1);

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect($spedita->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);
});

it('il preventivo fermato di nuovo dopo la spedizione: si approva la versione spedita, la nuova resta fuori con la frase, e il piano resta fuori', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $spedita = null;
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s, &$spedita) {
        $spedita = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    });
    $piano = $s->piano('Ordinario 2026');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    // Una versione fermata dopo la spedizione, come la scriveva il programma prima della regola: la voce a € 5.000,00 e la versione 2.
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
    $stato = $a->stato->value;
    $a->forceFill(['stato' => 'in_preparazione'])->saveQuietly();
    $nuova = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    $a->forceFill(['stato' => $stato])->saveQuietly();

    $righe = SeguitoDiViaRoma12::righe($a);
    $dellaSpedita = $righe[$d->id . ':preventivo_versione:' . $spedita->id . ':approvazione'];
    $dellaNuova = $righe[$d->id . ':preventivo_versione:' . $nuova->id . ':approvazione'];
    expect($dellaSpedita['tipo'])->toBe('applica')->and($dellaSpedita['spuntata'])->toBeTrue()
        ->and($dellaNuova['tipo'])->toBe('fuori')
        ->and($dellaNuova['perche_no'])->toBe('La versione 2 è stata fermata il 17/12/2026, dopo la spedizione: l\'assemblea ha approvato la versione 1, € 3.800,00.');

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect($spedita->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($nuova->fresh()->stato)->not->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);
});

it('il piano con le rate generate sulla cifra cambiata resta fuori anche se la voce è tornata come prima: il piano chiede ciò che dicono le sue voci', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s) {
        app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
        // Dopo la spedizione: la voce a € 5.000,00, il piano generato su quella; poi la voce torna a € 3.800,00, il piano no.
        $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
        $s->genera('Ordinario 2026');
        $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 380000]);
    });
    $piano = $s->piano('Ordinario 2026');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    expect((int) DB::table('rate')->where('piano_rate_id', $piano->id)->sum('importo_totale'))->toBe(500000);
    $riga = SeguitoDiViaRoma12::righe($a)[$d->id . ':piano_rate:' . $piano->id . ':delibera'];
    expect($riga['tipo'])->toBe('fuori')
        ->and($riga['perche_no'])->toBe('Il piano chiede € 5.000,00 (rate generate il 26/11/2026), l\'assemblea ha approvato € 3.800,00.');

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);
});

it('la voce spostata su un\'altra tabella dopo la spedizione: il riparto non è quello approvato, e il piano resta fuori con la tabella nella frase', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->tabella('Scala A', collect($s->unita)->keys()->mapWithKeys(fn ($u) => [$u => 100])->all());
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s) {
        app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
        // Dopo la spedizione: la stessa voce, € 6.000,00, ora sulla tabella «Scala A» invece che «Proprietà».
        DB::table('conto_tabella_millesimale')->where('conto_id', $s->voci['Pulizia delle scale']['conto']->id)
            ->update(['tabella_id' => $s->tabelle['Scala A']->id]);
    });
    $piano = $s->piano('Ordinario 2026');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $riga = SeguitoDiViaRoma12::righe($a)[$d->id . ':piano_rate:' . $piano->id . ':delibera'];
    expect($riga['tipo'])->toBe('fuori')
        ->and($riga['perche_no'])->toContain('la voce «Pulizia delle scale» è passata dalla tabella «Proprietà» alla tabella «Scala A»');

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);
});

it('il piano fuori tiene la delibera «Da eseguire»: nasce «Rimetti il piano come il preventivo approvato» con il compito, e il passo 6 ha la riga', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s) {
        app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
        $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
    });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    $chiaveDellaCosa = $d->id . ':cosa:rimetti_piano';
    expect(SeguitoDiViaRoma12::righe($a)[$chiaveDellaCosa]['spuntata'])->toBeTrue();

    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$chiaveDellaCosa => ['entro_il' => '2026-12-22']]])->assertOk();

    $pagina = SeguitoDelleDelibere::perLaPagina($a, $s->admin);
    $riga = collect($pagina['tabella'])->firstWhere('delibera_id', $d->id);
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::RIMETTI_PIANO->value)->firstOrFail();
    expect(StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('da_eseguire')
        ->and($pagina['passo']['stato'])->toBe('adesso')
        ->and($cosa->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and([$cosa->oggetto_tipo, (int) $cosa->oggetto_id])->toBe(['piano_rate', (int) $piano->id])
        ->and(Evento::query()->findOrFail($cosa->evento_id)->meta['azione'])->toBe('Apri il piano')
        ->and(array_column($riga['righe_da_applicare'], 'chiave'))->toContain($d->id . ':piano_rate:' . $piano->id . ':delibera');
});
