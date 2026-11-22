<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\AccordoRientro;
use App\Services\Assemblee\AssembleeDellaPersona;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/Verbale/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/Seguito/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/Pagine/RotteDelleAssemblee.php';

/**
 * «Le sue assemblee» nella scheda della persona (decisione 622 (5), la scena 6 della bozza approvata): la sala del 15/12/2026 di Via Roma 12 (quella del
 * verbale), il verbale n. 42 registrato. Per Colombo Rita, contraria ai punti 1 e 4: la convocazione, «Presente di persona», i voti, «Può impugnare fino al
 * 14/01/2027». Per Rossi Mario la dichiarazione a verbale sul portone, e il suo accordo di rientro autorizzato fra le cose sue. Per Bianchi Anna, entrata dopo
 * i punti 1 e 2: assente a quei due, e il termine parte dalla consegna del verbale. Solo lo studio, con «Vede le assemblee»; la pagina la manda differita.
 *
 * Che cosa non copre: il riquadro a video (`LeSueAssemblee.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la persona nelle sue assemblee: la convocazione, in sala, i voti con la dichiarazione, fino a quando può impugnare, le delibere sulle sue cose', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    SeguitoDiViaRoma12::registra($this, $s, $a);
    // L'accordo di rientro di Rossi, autorizzato con la delibera del punto 3.
    $accordo = AccordoRientro::query()->create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['rossi']->id, 'data_accordo' => '2026-11-10',
        'fino_al' => '2027-06-30', 'autorizzato_assemblea' => false]);
    CollegamentiDelleDelibere::collega($accordo, 'autorizzazione', SeguitoDiViaRoma12::delibera($a, 3), null, $s->admin);

    $rita = collect(AssembleeDellaPersona::perLaScheda($s->persone['rita'], $s->admin))->firstWhere('id', $a->id);
    expect($rita['nome'])->toBe('Ordinaria del 15/12/2026')
        ->and($rita['verbale'])->toBe('42')
        ->and($rita['convocazione'])->toStartWith('Per ')
        ->and($rita['in_sala'])->toBe('Presente di persona')
        ->and($rita['favorevole_ai_punti'])->toBe([2, 3])
        ->and(collect($rita['altri_voti'])->map(fn ($v) => [$v['punto'], $v['voto']])->all())->toBe([[1, 'Contrario'], [4, 'Contrario']])
        // Trenta giorni dal 15/12/2026, la lettura prudente: il 14/01/2027.
        ->and($rita['puo_impugnare_fino_al'])->toBe('14/01/2027')
        ->and($rita['sulle_sue_cose'])->toBe([]);

    $rossi = collect(AssembleeDellaPersona::perLaScheda($s->persone['rossi'], $s->admin))->firstWhere('id', $a->id);
    $portone = collect($rossi['altri_voti'])->firstWhere('punto', 4);
    expect($rossi['favorevole_ai_punti'])->toContain(4)
        ->and($rossi['puo_impugnare_fino_al'])->toBeNull()
        ->and($rossi['sulle_sue_cose'][0]['frase'])->toBe('Punto 3, il suo piano di rientro, approvata')
        ->and($portone)->toBeNull();
    // A favore del portone, Rossi non ha la riga del voto: le righe con la dichiarazione sono quelle dei contrari, degli astenuti e degli assenti.
    expect(collect($rossi['altri_voti'])->pluck('dichiarazioni')->flatten()->all())->toBe([]);

    $anna = collect(AssembleeDellaPersona::perLaScheda($s->persone['bianchi'], $s->admin))->firstWhere('id', $a->id);
    expect(collect($anna['altri_voti'])->where('voto', 'Assente')->pluck('punto')->all())->toBe([1, 2])
        ->and($anna['impugnare_dopo_la_consegna'])->toBeTrue();

    // Solo lo studio con «Vede le assemblee»: senza, niente.
    expect(AssembleeDellaPersona::perLaScheda($s->persone['rita'], apUtente(null, [])))->toBeNull();
});

it('la scheda «Dettagli» non porta più l\'elenco: solo il riepilogo «N assemblee in M condomini, l\'ultima il …»; senza «Vede le assemblee» niente', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    SeguitoDiViaRoma12::registra($this, $s, $a);

    $studio = apUtente(null, [\App\Enums\Permission::VIEW_ANAGRAFICHE, \App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($studio)->get(route('admin.anagrafiche.show', $s->persone['rita']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('anagrafiche/AnagraficheView')->missing('assemblee')
            ->where('assemblee_riepilogo.totale', 1)->where('assemblee_riepilogo.condomini', 1)->where('assemblee_riepilogo.ultima', '15/12/2026'));

    $senza = apUtente(null, [\App\Enums\Permission::VIEW_ANAGRAFICHE]);
    $this->actingAs($senza)->get(route('admin.anagrafiche.show', $s->persone['rita']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('assemblee_riepilogo', null));
});

it('la linguetta «Assemblee»: la rotta, il permesso, le tre card, la riga con «Per lei» e la dichiarazione a verbale', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    SeguitoDiViaRoma12::registra($this, $s, $a);
    \App\Models\Assemblee\DichiarazioneAVerbale::query()->create(['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 4),
        'anagrafica_id' => $s->persone['rita']->id, 'nome' => $s->persone['rita']->nome, 'testo' => 'Il portone si può riparare: la spesa è troppo alta.']);

    expect(route('admin.anagrafiche.assemblee', $s->persone['rita'], false))->toBe('/admin/anagrafiche/' . $s->persone['rita']->id . '/assemblee');

    $studio = apUtente(null, [\App\Enums\Permission::VIEW_ANAGRAFICHE, \App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($studio)->get(route('admin.anagrafiche.assemblee', $s->persone['rita']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('anagrafiche/AnagraficheAssemblee')
            ->where('anagrafica.id', $s->persone['rita']->id)
            ->where('riepilogo.totale', 1)->where('riepilogo.condomini', 1)->where('riepilogo.ultima', '15/12/2026')
            ->where('riepilogo.puo_impugnare.quante', 1)->where('riepilogo.puo_impugnare.fino_al', '14/01/2027')
            ->where('assemblee_riepilogo.totale', 1)
            ->where('righe.0.id', $a->id)->where('righe.0.condominio', $s->c->nome)->where('righe.0.per_lei.parola', 'Può impugnare')->where('righe.0.per_lei.tono', 'ambra')
            ->where('righe.0.altri_voti.1.dichiarazioni.0', 'Il portone si può riparare: la spesa è troppo alta.')
            ->where('meta.total', 1)->where('meta.last_page', 1)->where('filtri.condominio', null)->where('filtri.anno', null)
            ->where('condomini.0.id', $s->c->id)->where('anni', [2026]));
});

it('la linguetta «Assemblee» è solo dello studio: senza «Vede le assemblee» 403, senza «Visualizza anagrafiche» 403', function () {
    [$s] = VerbaleDiViaRoma12::salaChiusa($this);
    $this->actingAs(apUtente(null, [\App\Enums\Permission::VIEW_ANAGRAFICHE]))->get(route('admin.anagrafiche.assemblee', $s->persone['rita']))->assertForbidden();
    $this->actingAs(apUtente(null, [\App\Enums\Permission::VIEW_ASSEMBLEE]))->get(route('admin.anagrafiche.assemblee', $s->persone['rita']))->assertForbidden();
});

it('i filtri per condominio e per anno e le pagine lavorano sul server; le card contano tutto, non solo la pagina', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    SeguitoDiViaRoma12::registra($this, $s, $a);
    $rita = $s->persone['rita'];
    $altra = $a->replicate();
    $altra->data = '2025-03-10';
    $altra->save();
    \App\Models\Assemblee\ConvocatoDellAssemblea::create(['assemblea_id' => $altra->id, 'anagrafica_id' => $rita->id, 'nome' => $rita->nome, 'unita' => [], 'punti' => [], 'fissato_il' => '2025-02-01 10:00:00', 'mezzo' => \App\Enums\Assemblee\MezzoConvocazione::PEC]);

    $studio = apUtente(null, [\App\Enums\Permission::VIEW_ANAGRAFICHE, \App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($studio);
    $this->get(route('admin.anagrafiche.assemblee', $rita))->assertInertia(fn (AssertableInertia $p) => $p->where('meta.total', 2)->where('righe.0.id', $a->id)
        ->where('anni', [2026, 2025])->where('riepilogo.totale', 2));
    $this->get(route('admin.anagrafiche.assemblee', [$rita, 'anno' => 2025]))->assertInertia(fn (AssertableInertia $p) => $p->where('meta.total', 1)
        ->where('righe.0.id', $altra->id)->where('filtri.anno', 2025)->where('riepilogo.totale', 2));
    $this->get(route('admin.anagrafiche.assemblee', [$rita, 'condominio' => $s->c->id + 999]))->assertInertia(fn (AssertableInertia $p) => $p->where('meta.total', 0));
    $this->get(route('admin.anagrafiche.assemblee', [$rita, 'per_page' => 1, 'page' => 2]))->assertInertia(fn (AssertableInertia $p) => $p->where('meta.total', 2)
        ->where('meta.current_page', 2)->where('meta.last_page', 2)->where('righe.0.id', $altra->id)->has('righe', 1));
});
