<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\StatoPianoRate;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Models\Gestionale\VersionePreventivo;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Le catene delle pagine del seguito delle delibere, da un capo all'altro con le rotte vere e le letture che le pagine chiedono (la pagina dell'assemblea e
 * la sua visita parziale del seguito, la pagina della comunicazione, l'Action inbox, la scheda di arrivo):
 *
 * 1. il verbale registrato → il passo 6 aperto → «Conferma» con una riga tolta → l'esito per riga → «Applica» sulla riga tolta;
 * 2. la cosa da fare → il compito nell'Action inbox e la card della dashboard → «Segna eseguita» con il PDF → «Eseguita il …» nel passo;
 * 3. il preventivo respinto → «Respinta» sulla scheda del piano;
 * 4. «Avvisa i condòmini» → la comunicazione già scritta → pubblicata → la cosa fatta, e la card della dashboard vuota;
 * 5. la riunione su carta → il riepilogo il giorno degli esiti → il verbale caricato → il numero nelle schede, e i trenta giorni che aspettano le consegne;
 * 6. la delibera con una cosa già fatta → «Elimina» con il motivo → la riga del registro con la fotografia → il diario dell'assemblea;
 * 7. il preventivo approvato con modifiche → «Ferma il preventivo» dalla cosa → la versione corretta → «Applica» → la cosa fatta;
 * 8. Rossi con l'Int. 3 e, con Bianchi, l'Int. 5 → un condominio nuovo, di serie → la sala con due teste.
 *
 * Che cosa non copre: come le pagine mostrano le letture (i test vitest dei componenti del seguito).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La visita parziale della pagina dell'assemblea, come la fa la pagina quando apre il passo. @return array<string, mixed> */
function cpnSeguito($t, Beta51Scenario $s, \App\Models\Assemblee\Assemblea $a): array
{
    $url = route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'delibere']);
    $versione = (string) $t->actingAs($s->admin)->get($url)->assertOk()->viewData('page')['version'];

    return (array) $t->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Inertia-Partial-Component' => 'gestionale/assemblee/Assemblea',
        'X-Inertia-Partial-Data' => 'seguito'])->assertOk()->json('props.seguito');
}

/** I permessi che le pagine dell'Action inbox e della comunicazione leggono, tutti all'amministratore dello studio. */
function cpnPermessi(Beta51Scenario $s): void
{
    foreach (\App\Enums\Permission::cases() as $p) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
        $s->admin->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}

/** La card «Delibere da eseguire» della dashboard del condominio, differita. @return list<array<string, mixed>> */
function cpnCard($t, Beta51Scenario $s): array
{
    $card = [];
    $t->actingAs($s->admin)->get(route('admin.gestionale.index', $s->c))->assertOk()
        ->assertInertia(function (AssertableInertia $p) use (&$card) {
            $p->loadDeferredProps('assemblee', function (AssertableInertia $r) use (&$card) {
                $card = (array) ($r->toArray()['props']['delibereDaEseguire'] ?? []);
            });
        });

    return $card;
}

/** La riga della delibera nella tabella del passo 6. @return array<string, mixed> */
function cpnRiga(array $seguito, int $deliberaId): array
{
    return collect($seguito['tabella'])->firstWhere('delibera_id', $deliberaId) ?? [];
}

it('catena 1: il verbale registrato → il passo 6 aperto → «Conferma» con una riga tolta → l\'esito per riga → «Applica» sulla riga tolta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Portone 2027', 'straordinaria');
    $gestione = $s->gestioni['Portone 2027']['g'];
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000,
        'oggetti' => [['gestione', $gestione->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('passi.5.chiave', 'delibere')->where('passi.5.stato', 'adesso'));
    $seguito = cpnSeguito($this, $s, $a);
    $tolta = $d->id . ':gestione:' . $gestione->id . ':delibera';
    $chiavi = collect($seguito['riepilogo']['punti'][0]['righe'])->filter(fn ($r) => $r['spuntata'] && $r['si_applica'])->pluck('chiave')
        ->reject(fn ($k) => $k === $tolta)->values()->all();
    expect($chiavi)->toContain($d->id . ':cosa:crea_piano_straordinario');

    // «Conferma» come la manda la pagina: la riga della gestione tolta.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => $chiavi])->assertRedirect()
        ->assertSessionHas('seguito_esiti');
    $esiti = session('seguito_esiti');
    $seguito = cpnSeguito($this, $s, $a);
    $riga = cpnRiga($seguito, $d->id);

    expect($esiti[$d->id . ':cosa:crea_piano_straordinario']['esito'])->toBe('applicata')
        ->and($esiti)->not->toHaveKey($tolta)
        ->and(array_column($riga['righe_da_applicare'], 'chiave'))->toBe([$tolta])
        ->and(CollegamentoAllaDelibera::query()->where('delibera_id', $d->id)->where('oggetto_tipo', 'gestione')->exists())->toBeFalse();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $tolta])->assertRedirect()
        ->assertSessionHas('seguito_esiti.' . $tolta . '.esito', 'applicata');

    expect(CollegamentoAllaDelibera::query()->where('delibera_id', $d->id)->where('oggetto_tipo', 'gestione')->exists())->toBeTrue()
        ->and(cpnRiga(cpnSeguito($this, $s, $a), $d->id)['righe_da_applicare'])->toBe([]);
});

it('catena 2: la cosa da fare → il compito nell\'Action inbox → «Segna eseguita» con il PDF → «Eseguita il …» nel passo', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: firma del contratto', 'materia' => 'altro']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => [],
        'altre' => [['delibera_id' => $d->id, 'testo' => 'Firma con Serramenti Marino', 'entro_il' => '2026-12-18', 'a_chi' => 'me']]])->assertSessionHasNoErrors();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    cpnPermessi($s);
    $props = $this->actingAs($s->admin)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];
    $righe = collect($props['tasks']['data'] ?? []);
    foreach ($props['anteprima'] ?? [] as $gruppo) {
        $righe = $righe->merge($gruppo['righe']);
    }
    $compito = $righe->firstWhere('id', $cosa->evento_id);
    expect($compito['type'] ?? null)->toBe('delibera_da_eseguire')->and($compito['titolo'] ?? null)->toBe('Delibera da eseguire: Firma con Serramenti Marino')
        ->and(array_column(cpnCard($this, $s), 'testo'))->toBe(['Firma con Serramenti Marino']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.eseguita', [$s->c, $a, $cosa->id]), ['giorno' => '2026-12-17',
        'nota' => 'Contratto firmato', 'documento' => UploadedFile::fake()->create('contratto firmato.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $riga = cpnRiga(cpnSeguito($this, $s, $a), $d->id);

    expect($riga['seguito'])->toMatchArray(['stato' => 'eseguita', 'parola' => 'Eseguita', 'eseguita_il' => '2026-12-17'])
        ->and($riga['cose'][0])->toMatchArray(['stato' => 'fatta', 'fatta_il' => '2026-12-17', 'fatta_da' => $s->admin->name, 'nota' => 'Contratto firmato'])
        ->and($riga['cose'][0]['documento']['nome'])->toBe('contratto firmato.pdf')
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(cpnCard($this, $s))->toBe([]);
});

it('catena 3: il preventivo respinto → «Respinta» sulla scheda del piano, con la frase della delibera e l\'assemblea da aprire', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $piano = $s->piano('Ordinario 2026');
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del preventivo 2026 e del piano di riparto', 'materia' => 'preventivo',
        'esercizio_id' => $s->e->id, 'oggetti' => [['piano_rate', $piano->id, 'origine']]]], [1 => 'respinta']);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    $this->actingAs($s->admin)->getJson(route('admin.gestionale.esiti-nelle-schede', [$s->c, 'piano_rate', $piano->id]))->assertOk()
        ->assertJsonPath('esiti.0.tipo', 'respinta')
        ->assertJsonPath('esiti.0.frase', 'Respinto dall\'assemblea del 15/12/2026, punto 1 (verbale n. 42).')
        ->assertJsonPath('esiti.0.assemblea_id', $a->id);
    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);
});

it('catena 4: «Avvisa i condòmini» → la comunicazione già scritta → pubblicata → la cosa fatta', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione', 'testo_pubblico' => 'Orari del riscaldamento 2026/2027',
        'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $avviso = $d->id . ':cosa:avvisa_condomini';
    expect(collect(cpnSeguito($this, $s, $a)['riepilogo']['punti'][0]['righe'])->firstWhere('chiave', $avviso)['spuntata'])->toBeTrue();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => [$avviso]])->assertSessionHasNoErrors();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $url = (string) cpnRiga(cpnSeguito($this, $s, $a), $d->id)['cose'][0]['azione']['url'];
    cpnPermessi($s);
    expect(cpnCard($this, $s)[0]['azione'])->toBe(['testo' => 'Avvisa i condòmini', 'url' => $url]);

    // La pagina della comunicazione si apre già scritta, e rimanda la cosa da fare nel salvataggio.
    $precompilata = $this->actingAs($s->admin)->get($url)->assertOk()->viewData('page')['props']['precompilata'];
    expect($precompilata['cosa_da_fare_id'])->toBe($cosa->id)->and($precompilata['subject'])->toBe('Assemblea del 15/12/2026: Orari del riscaldamento 2026/2027');
    $campi = ['subject' => $precompilata['subject'], 'description' => $precompilata['description'], 'priority' => 'bassa', 'can_comment' => false,
        'is_featured' => false, 'condomini_ids' => $precompilata['condomini_ids'], 'anagrafiche' => []];
    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), $campi + ['is_published' => false, 'cosa_da_fare_id' => $precompilata['cosa_da_fare_id']])
        ->assertSessionHasNoErrors();
    $comunicazione = \App\Models\Comunicazione::query()->latest('id')->firstOrFail();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE);

    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $this->actingAs($s->admin)->put(route('admin.comunicazioni.update', $comunicazione), $campi + ['is_published' => true])->assertSessionHasNoErrors();

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and(cpnRiga(cpnSeguito($this, $s, $a), $d->id)['seguito'])->toMatchArray(['stato' => 'eseguita', 'eseguita_il' => '2026-12-17'])
        ->and(cpnCard($this, $s))->toBe([]);
});

it('catena 5: la riunione su carta → il riepilogo il giorno degli esiti → il verbale caricato → il numero nelle schede; i trenta giorni aspettano le consegne', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione', 'materia' => 'riscaldamento']]);
    $a->forceFill(['numero_verbale' => '42'])->saveQuietly();
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $seguito = cpnSeguito($this, $s, $a);
    expect($seguito['riepilogo'])->toMatchArray(['aperto' => true, 'su_carta' => true, 'verbale_da_caricare' => true, 'da_confermare' => 1]);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => []])->assertSessionHasNoErrors();
    expect(DeliberaInParole::breve($d->fresh()))->toBe('Delibera del 15/12/2026, punto 1 (verbale da caricare)')
        ->and(cpnSeguito($this, $s, $a)['trenta_giorni']['frase'])->toBe('La data non c\'è finché il verbale non è consegnato a tutti. Resta la nullità.');

    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.store', [$s->c, $a]),
        ['verbale' => UploadedFile::fake()->create('verbale firmato.pdf', 30, 'application/pdf')])->assertSessionHasNoErrors();
    $seguito = cpnSeguito($this, $s, $a);

    expect($seguito['riepilogo']['verbale_da_caricare'])->toBeFalse()
        ->and(DeliberaInParole::breve($d->fresh()))->toBe('Delibera del 15/12/2026, punto 1 (verbale n. 42)')
        ->and($seguito['trenta_giorni']['frase'])->toBe('La data non c\'è finché il verbale non è consegnato a tutti. Resta la nullità.');
});

it('catena 6: la delibera con una cosa già fatta → «Elimina» con il motivo → la riga del registro con la fotografia → il diario dell\'assemblea', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: firma del contratto', 'materia' => 'altro']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [])->assertOk();
    $cosa = CoseDaFare::crea($d, TipoCosaDaFare::FUORI_DAL_PROGRAMMA, 'Firma con Serramenti Marino', null, null, null, $s->admin);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.eseguita', [$s->c, $a, $cosa->id]), ['giorno' => '2026-12-16',
        'documento' => UploadedFile::fake()->create('contratto firmato.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    // La finestra di «Elimina» elenca le cose che se ne vanno, dalla tabella della pagina.
    expect(cpnRiga(cpnSeguito($this, $s, $a), $d->id)['cose'][0])->toMatchArray(['testo' => 'Firma con Serramenti Marino', 'stato' => 'fatta']);
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');

    $this->actingAs($s->admin)->delete(route('admin.gestionale.delibere.destroy', [$s->c, $d]), ['motivo' => 'Esito sbagliato, la registro di nuovo'])
        ->assertSessionHasNoErrors();

    $riga = \App\Models\RegistroModifica::query()->where('oggetto_tipo', 'delibera')->where('oggetto_id', $d->id)->where('azione', 'eliminata')->firstOrFail();
    $diario = collect(DiarioDellAssemblea::righe($a)['righe'])->first(fn ($r) => str_contains((string) $r['frase'], 'Delibera registrata a mano eliminata'));
    expect($riga->perche)->toBe('Esito sbagliato, la registro di nuovo')
        ->and(collect((array) $riga->campi)->firstWhere('chiave', 'cosa:' . $cosa->id)['prima'])->toBe('Fatta il 16/12/2026 da ' . $s->admin->name
            . ' · contratto firmato.pdf')
        ->and($diario)->not->toBeNull()
        ->and($diario['registro']['perche'] ?? null)->toBe('Esito sbagliato, la registro di nuovo')
        ->and(CosaDaFareDellaDelibera::query()->whereKey($cosa->id)->exists())->toBeFalse();
});

it('catena 7: il preventivo approvato con modifiche → «Ferma il preventivo» dalla cosa → la versione corretta → «Applica» → la cosa fatta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [1 => 'approvata_con_modifiche'], function ($a) use ($s) {
        app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['modifiche' => [$d->id => 'Pulizia delle scale a € 5.400,00']])->assertOk();
    $seguito = cpnSeguito($this, $s, $a);
    $cosa = collect(cpnRiga($seguito, $d->id)['cose'])->firstWhere('tipo', TipoCosaDaFare::RIFAI_VERSIONE->value);
    $puntoId = cpnRiga($seguito, $d->id)['punto_id'];
    expect($cosa['stato'])->toBe('da_fare')->and($puntoId)->toBe((int) $a->punti()->value('id'));

    // Il 18/12 la voce corretta; «Ferma il preventivo» sulla riga della cosa manda il punto alla rotta del fascicolo.
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $s->voci['Pulizia delle scale']['conto']->update(['importo' => 540000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $puntoId])->assertSessionHasNoErrors();
    $nuova = VersionePreventivo::query()->where('esercizio_id', $s->e->id)->where('numero', 2)->firstOrFail();
    $daApplicare = collect(cpnRiga(cpnSeguito($this, $s, $a), $d->id)['righe_da_applicare'])->firstWhere('catena', 'preventivo');
    expect($daApplicare['chiave'])->toBe($d->id . ':preventivo_versione:' . $nuova->id . ':approvazione')->and($daApplicare['si_applica'])->toBeTrue();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $daApplicare['chiave']])
        ->assertSessionHas('seguito_esiti.' . $daApplicare['chiave'] . '.esito', 'applicata');

    expect($nuova->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and(CosaDaFareDellaDelibera::query()->findOrFail($cosa['id'])->stato)->toBe(StatoCosaDaFare::FATTA);
});

it('catena 8: Rossi con l\'Int. 3 e, con Bianchi, l\'Int. 5 → un condominio nuovo, di serie → la sala con due teste', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    // Russo Marco ha l'Int. 30 da solo, e la comproprietà di un'altra unità: il caso misto. Il condominio non ha scelto niente.
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['30']->id)->update(['anagrafica_id' => $s->persone['marco']->id]);
    expect(DB::table('impostazioni_condominio')->where('condominio_id', $s->c->id)->exists())->toBeFalse();
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    expect(app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['barra']['barra'])->toBe('0 su 26 · 0,00 su 1.000,00');

    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['marco']);

    expect($seduta->refresh()->fotografia['teste_caso_misto'])->toBe('due_teste')
        ->and(app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['barra']['barra'])->toBe('2 su 26 · 97,75 su 1.000,00');
});
