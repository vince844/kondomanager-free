<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\ImpostazioniCondominio;
use App\Models\RegistroModifica;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\Sala\TesteDelCondominio;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\Verbale\RegistrazioneDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * Le pagine del seguito delle delibere, dal lato del server: il passo 6 «Delibere eseguite» nella strada (il lucchetto fino al verbale registrato, il
 * riepilogo da confermare, la riga «N su M · 1 da eseguire» con «Da eseguire» e «Trenta giorni», il verbale annullato), la pagina che apre il passo con la
 * cosa da fare, la card della dashboard, le frasi degli esiti sulle schede di arrivo, «verbale da caricare» nelle schede della riunione su carta, «Elimina»
 * con il motivo, e «due teste» di serie per il caso misto dei comproprietari.
 *
 * Che cosa non copre: come le pagine mostrano queste letture (i test vitest dei componenti).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La visita parziale della pagina dell'assemblea, come la fa la pagina quando apre il passo: con la versione degli asset. @return array<string, mixed> */
function pdsParziale($t, string $url, string $prop): array
{
    $versione = (string) $t->get($url)->assertOk()->viewData('page')['version'];

    return (array) $t->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Inertia-Partial-Component' => 'gestionale/assemblee/Assemblea',
        'X-Inertia-Partial-Data' => $prop])->assertOk()->json('props.' . $prop);
}

/** Il passo 6 della strada. @return array<string, mixed> */
function pdsPasso(\App\Models\Assemblee\Assemblea $a): array
{
    return collect(PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'delibere') ?? [];
}

it('il passo 6 nella strada: il lucchetto fino al verbale registrato, poi il riepilogo da confermare, poi «0 su 1 · 1 da eseguire» con le due voci in testa', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]], [], null, false);

    expect(array_column(PerLePagine::passi($a), 'chiave'))->toBe(['date', 'documenti', 'convocazione', 'sala', 'verbale', 'delibere'])
        ->and(pdsPasso($a))->toMatchArray(['numero' => 6, 'nome' => 'Delibere eseguite', 'stato' => 'chiuso', 'riga' => 'si apre con il verbale registrato']);

    SeguitoDiViaRoma12::registra($this, $s, $a);
    expect(pdsPasso($a))->toMatchArray(['stato' => 'adesso', 'riga' => 'da confermare: «Che cosa cambia nel gestionale»']);

    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:crea_piano_straordinario';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2027-01-15']]])->assertOk();
    $passo = pdsPasso($a);

    // Decisione 645: le tre card («Da fare adesso», «Le delibere», «Trenta giorni») le disegna il passo dal seguito; niente badge giallo né voci di «che cosa manca».
    expect($passo)->toMatchArray(['stato' => 'adesso', 'riga' => '0 su 1 · 1 da eseguire', 'badge' => null, 'manca' => []]);
});

it('il verbale annullato dopo la conferma chiude il passo con il lucchetto e la frase, e il passo si apre lo stesso per le schede e le cose aperte', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$d->id . ':cosa:crea_piano_straordinario'])->assertOk();
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    app(RegistrazioneDelVerbale::class)->annulla(VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->where('stato', 'registrato')->firstOrFail(),
        'Il verbale registrato non era quello sottoscritto', $s->admin);

    expect(pdsPasso($a))->toMatchArray(['stato' => 'chiuso', 'perche' => SeguitoDelleDelibere::VERBALE_ANNULLATO, 'riga' => 'il verbale è annullato',
        'apribile' => true]);
});

it('la pagina dell\'assemblea apre il passo 6 con «?scheda=delibere» e dice la cosa da aprire; il seguito arriva con la visita parziale', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$d->id . ':cosa:crea_piano_straordinario'])->assertOk();
    $cosa = \App\Models\Assemblee\CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $url = route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'delibere', 'cosa' => $cosa->id]);

    $this->actingAs($s->admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'delibere')
        ->where('cosa', $cosa->id)->where('delibera', null)->missing('seguito')->where('passi.5.chiave', 'delibere'));
    $seguito = pdsParziale($this, $url, 'seguito');

    expect($seguito['passo']['riga'])->toBe('0 su 1 · 1 da eseguire')
        ->and($seguito['da_eseguire'][0]['azione']['testo'])->toBe('Crea il piano straordinario');
});

it('la visita parziale che chiede i passi e il seguito insieme legge il seguito una volta sola', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$d->id . ':cosa:crea_piano_straordinario'])->assertOk();
    $url = route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'delibere']);
    $versione = (string) $this->actingAs($s->admin)->get($url)->assertOk()->viewData('page')['version'];

    // Le letture delle cose da fare della delibera: quante ne fa il seguito da solo, e quante con i passi.
    $letture = 0;
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$letture) {
        if (str_contains($q->sql, 'delibere_cose_da_fare')) {
            $letture++;
        }
    });
    $chiedi = fn (string $prop) => $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $versione,
        'X-Inertia-Partial-Component' => 'gestionale/assemblee/Assemblea', 'X-Inertia-Partial-Data' => $prop])->assertOk()->json('props');
    $chiedi('seguito');
    $daSolo = $letture;
    $letture = 0;
    $props = $chiedi('passi,seguito');

    expect($props['passi'][5]['badge'])->toBeNull()
        ->and($props['seguito']['passo']['riga'])->toBe('0 su 1 · 1 da eseguire')
        ->and($daSolo)->toBeGreaterThan(0)
        ->and($letture)->toBe($daSolo);
});

it('la card «Delibere da eseguire» della dashboard: differita, con le cose aperte e la loro azione; chi non vede le assemblee non la riceve', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Firma del contratto del portone', 'materia' => 'altro']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['altre' => [['delibera_id' => $d->id, 'testo' => 'Firma con Serramenti Marino', 'entro_il' => '2027-01-10']]])
        ->assertOk();

    $this->actingAs($s->admin)->get(route('admin.gestionale.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->missing('delibereDaEseguire')
            ->loadDeferredProps('assemblee', fn (AssertableInertia $r) => $r->has('delibereDaEseguire', 1)
                ->where('delibereDaEseguire.0.testo', 'Firma con Serramenti Marino')->where('delibereDaEseguire.0.entro_il', '2027-01-10')
                ->where('delibereDaEseguire.0.assemblea_id', $a->id)));
});

it('gli esiti sulle schede: l\'accordo con l\'autorizzazione respinta lo dice con la frase della delibera; un\'altra scheda è 404, e senza «Vede» 403', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $accordo = \App\Models\Gestionale\AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('gallo')->id,
        'data_accordo' => '2026-09-01', 'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Autorizzazione del piano di rientro di Gallo Enzo', 'materia' => 'accordo',
        'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]]], '2026-12-15', [1 => 'respinta']);
    $altro = ViaRoma12::nuovo($this, 'Via Verdi 3');

    $this->actingAs($s->admin)->getJson(route('admin.gestionale.esiti-nelle-schede', [$s->c, 'accordo_rientro', $accordo->id]))->assertOk()
        ->assertJsonPath('esiti.0.tipo', 'respinta')
        ->assertJsonPath('esiti.0.frase', 'Autorizzazione respinta dall\'assemblea del 15/12/2026, punto 1 (verbale da caricare).')
        ->assertJsonPath('esiti.0.assemblea_id', $a->id);
    $this->actingAs($altro->admin)->getJson(route('admin.gestionale.esiti-nelle-schede', [$altro->c, 'accordo_rientro', $accordo->id]))->assertNotFound();
    $this->actingAs(apUtente(null, []))->getJson(route('admin.gestionale.esiti-nelle-schede', [$s->c, 'accordo_rientro', $accordo->id]))->assertForbidden();
});

it('la riunione su carta: le schede dicono «verbale da caricare» al posto del numero, e il numero compare da solo quando il verbale è caricato', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']]);
    $a->forceFill(['numero_verbale' => '42'])->saveQuietly();
    $d = SeguitoDiViaRoma12::delibera($a, 1);

    expect(DeliberaInParole::breve($d))->toBe('Delibera del 15/12/2026, punto 1 (verbale da caricare)');

    \App\Services\Assemblee\DeliberaAMano::caricaVerbale($a, UploadedFile::fake()->create('verbale.pdf', 20, 'application/pdf'), $s->admin);

    expect(DeliberaInParole::breve($d->fresh()))->toBe('Delibera del 15/12/2026, punto 1 (verbale n. 42)');
});

it('«Elimina» su una delibera con una cosa già fatta: chiede il motivo; con il motivo la riga del registro tiene la fotografia, e il diario la mostra', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: firma del contratto', 'materia' => 'altro']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [])->assertOk();
    $cosa = CoseDaFare::crea($d, TipoCosaDaFare::FUORI_DAL_PROGRAMMA, 'Firma con Serramenti Marino', null, null, null, $s->admin);
    CoseDaFare::segnaEseguita($cosa, '2026-12-16', 'Contratto firmato', UploadedFile::fake()->create('contratto firmato.pdf', 10, 'application/pdf'), $s->admin);
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');

    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d]))->assertSessionHasErrors('motivo');
    expect($d->fresh())->not->toBeNull();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d]), ['motivo' => 'Esito sbagliato, la registro di nuovo'])
        ->assertSessionHasNoErrors();

    $riga = RegistroModifica::query()->where('oggetto_tipo', 'delibera')->where('oggetto_id', $d->id)->where('azione', 'eliminata')->firstOrFail();
    $campi = collect((array) $riga->campi)->keyBy('chiave');
    expect($d->fresh())->toBeNull()
        ->and($riga->perche)->toBe('Esito sbagliato, la registro di nuovo')
        ->and($campi['delibera']['prima'])->toBe('Approvata · Delibera del 15/12/2026, punto 1 (verbale da caricare)')
        ->and($campi['cosa:' . $cosa->id]['etichetta'])->toBe('Firma con Serramenti Marino')
        ->and($campi['cosa:' . $cosa->id]['prima'])->toBe('Fatta il 16/12/2026 da ' . $s->admin->name . ' · contratto firmato.pdf')
        ->and(collect(DiarioDellAssemblea::righe($a)['righe'])->pluck('frase')->filter(fn ($f) => str_contains((string) $f, 'Delibera registrata a mano eliminata'))
            ->count())->toBe(1);
});

it('il caso misto dei comproprietari: un condominio nuovo conta «due teste» di serie; «una testa» resta la scelta del condominio con una regola sua', function () {
    $s = ViaRoma12::nuovo($this);

    expect(ImpostazioniCondominio::DI_SERIE['assemblee_teste'])->toBe(ImpostazioniCondominio::DUE_TESTE)
        ->and(ImpostazioniCondominio::delCondominio($s->c->id)->assemblee_teste)->toBe('due_teste')
        ->and(app(TesteDelCondominio::class)->perLaPagina($s->c)['teste'])->toBe('due_teste');
});

it('«Apri il piano» dalla riga che resta fuori porta alla pagina del piano; una scheda di un altro condominio è 404', function () {
    $s = ViaRoma12::conFacciata($this);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2026 — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata']);
    $altro = ViaRoma12::nuovo($this, 'Via Verdi 3');

    $this->actingAs($s->admin)->get(route('admin.gestionale.schede.apri', [$s->c, 'piano_rate', $piano->id]))
        ->assertRedirect(route('admin.gestionale.esercizi.piani-rate.show', [$s->c, $s->e, $piano]));
    $this->actingAs($altro->admin)->get(route('admin.gestionale.schede.apri', [$altro->c, 'piano_rate', $piano->id]))->assertNotFound();
});

it('il punto «Stato di esecuzione delle delibere precedenti» nell\'ordine del giorno porta alla pagina i due gruppi, fermi al giorno in cui si prepara', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-10-23 10:00');
    $facciata = ViaRoma12::ordinaria($s, [['testo' => 'Rifacimento della facciata', 'materia' => 'lavori', 'importo' => 9000000]], ['tipo' => 'straordinaria']);
    $facciata = \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($facciata, $s->admin, [(int) $facciata->punti()->value('id') => ['esito' => 'approvata']],
        '2026-10-22');
    SeguitoDiViaRoma12::conferma($this, $s, $facciata, [SeguitoDiViaRoma12::delibera($facciata, 1)->id . ':cosa:crea_piano_straordinario'])->assertOk();
    ConvocazioneDiViaRoma12::oggi('2027-04-28 10:00');
    $maggio = \App\Services\Assemblee\PreparazioneDellAssemblea::crea($s->c, ['tipo' => 'ordinaria', 'luogo' => 'Sala condominiale', 'sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2027-05-20 08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2027-05-21 18:30'],
    ]], [], $s->admin);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.dalle-proposte', [$s->c, $maggio]), ['proposte' => [\App\Services\Assemblee\Seguito\StatoDiEsecuzione::CHIAVE]])
        ->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $maggio]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('ordine.0.testo', 'Stato di esecuzione delle delibere precedenti')
            ->where('ordine.0.dati.stato_esecuzione.al', '2027-04-28')
            ->where('ordine.0.dati.stato_esecuzione.aperte.0.testo', 'Lavori nelle parti comuni')
            ->where('ordine.0.dati.stato_esecuzione.eseguite', []));
});
