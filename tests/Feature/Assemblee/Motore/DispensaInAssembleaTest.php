<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\StatiDellAssemblea;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../../Gestionale/SeiMesiPerAgireScenario.php';

/**
 * Coda 736 (decisione 650 (1)): i sei mesi e la dispensa entrano in assemblea (art. 1129 c. 9 c.c.). Via Verdi 12, oggi il 20/05/2026: quattro persone con
 * € 200,00 ciascuna di rate dell'esercizio 2025, i sei mesi al 30/06/2026 (fra 41 giorni: il compito è aperto), e € 300,00 ciascuna del 2026 (il 30/06/2027,
 * fra 406 giorni: nessun compito).
 *
 * - Con il compito dei sei mesi aperto, fra i punti «Dal programma» c'è la dispensa per i crediti del 2025, con la sua chiave, senza nomi; senza il compito
 *   (a 61 giorni, dopo «Non serve») no.
 * - Si chiude con una delibera sul punto, approvata o respinta; rinviata o revocata torna.
 * - La catena: il punto, l'assemblea tenuta, «Registra la dispensa» con la delibera e l'esercizio, la dispensa registrata dalla sua rotta; poi il compito si
 *   chiude e il punto non torna.
 * - Il memo nato dai sei mesi e il punto del programma sono un punto solo; la finestra del memo dice che il programma lo propone già.
 * - «Rate scadute» porta l'esercizio della data più vicina per «Da portare in assemblea».
 *
 * Che cosa non copre: la sala con i voti (la dispensa nella sala è un punto come gli altri, catena 6 in `CateneDelleDelibereTest`); le pagine (vitest).
 */
beforeEach(function () {
    $this->user = smaUtente();
    foreach ([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE] as $p) {
        Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
        $this->user->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->withoutVite();
});

/** I punti del gruppo «Dal programma». @return list<array<string, mixed>> */
function diaDalProgramma(array $s): array
{
    return collect(ProposteDellOrdineDelGiorno::per($s['c'], null))->firstWhere('chiave', 'dal_programma')['punti'];
}

/** Il punto della dispensa fra quelli «Dal programma», se c'è. */
function diaDispensa(array $s): ?array
{
    return collect(diaDalProgramma($s))->first(fn ($p) => str_starts_with((string) $p['chiave_fonte'], 'sei_mesi:'));
}

/** L'assemblea con i punti dati, le sedute il 25 e il 26/05/2026. @param list<array<string, mixed>> $punti */
function diaAssemblea($test, array $s, array $punti): Assemblea
{
    return PreparazioneDellAssemblea::crea($s['c'], ['tipo' => 'straordinaria', 'luogo' => 'Sala condominiale', 'sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2026-05-25 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-05-26 18:30'],
    ]], $punti, $test->user);
}

it('con il compito dei sei mesi aperto il programma propone la dispensa per i crediti del 2025, con la sua chiave e senza nomi', function () {
    $s = smaViaVerdi($this);
    $p = diaDispensa($s);

    expect($p)->not->toBeNull()
        ->and($p['chiave_fonte'])->toBe('sei_mesi:' . $s['e2025']->id)
        ->and($p['testo'])->toBe('Dispensa dall\'agire entro sei mesi per i crediti del 2025 (art. 1129 c. 9 c.c.): quattro posizioni scadute, € 800,00')
        ->and($p['testo_pubblico'])->toBe("Dispensa dall'agire entro sei mesi per i crediti del 2025 (art. 1129 c. 9 c.c.)\nQuattro posizioni scadute, € 800,00: dispensa dall'agire entro il 30/06/2026.")
        ->and($p['materia'])->toBe('dispensa')
        ->and($p['fonte'])->toBe('programma')
        ->and($p['origine'])->toBe('dal_programma')
        ->and($p['esercizio_id'])->toBe((int) $s['e2025']->id)
        ->and($p['oggetti'])->toBe([['esercizio', (int) $s['e2025']->id, 'parla_di']])
        ->and($p['spuntato'])->toBeTrue()
        ->and($p['frase'])->toBe('Il compito dei sei mesi è aperto: senza la dispensa dell\'assemblea si agisce entro il 30/06/2026. I nomi sono nel riquadro dei sei mesi.');
    // Nessun nome dei condòmini, né nel testo né in quello per la bacheca né nella riga sotto.
    foreach (['Rossi', 'Bianchi', 'Verdi', 'Neri'] as $nome) {
        expect($p['testo'] . $p['testo_pubblico'] . $p['frase'])->not->toContain($nome);
    }
    // Il 2026 (fra 406 giorni) non ha il compito: niente punto.
    expect(array_column(diaDalProgramma($s), 'chiave_fonte'))->toBe(['sei_mesi:' . $s['e2025']->id]);
});

it('senza il compito non si propone: a 61 giorni no, a 60 sì; «Non serve» lo chiude', function () {
    $s = smaViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    expect(diaDispensa($s))->toBeNull();

    $this->travelTo('2026-05-01 10:00:00');
    expect(diaDispensa($s)['chiave_fonte'])->toBe('sei_mesi:' . $s['e2025']->id);

    smaRispondi($this, $s, ['tipo' => 'non_serve', 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e2025']->id, 'motivo' => 'assemblea informata'])->assertSessionHasNoErrors();
    expect(diaDispensa($s))->toBeNull();
});

it('si chiude con una delibera sul punto, approvata o respinta; rinviata torna con «rinviato dal», revocata torna', function () {
    $s = smaViaVerdi($this);
    $a = diaAssemblea($this, $s, [diaDispensa($s)]);
    $chiave = 'sei_mesi:' . $s['e2025']->id;
    $punto = $a->punti()->where('chiave_fonte', $chiave)->firstOrFail();
    expect($punto->materia->value)->toBe('dispensa')->and((int) $punto->esercizio_id)->toBe((int) $s['e2025']->id)
        // Nell'assemblea in preparazione il punto c'è già: non si propone due volte.
        ->and(diaDispensa($s))->toBeNull();

    $a->update(['stato' => 'chiusa']);
    $delibera = Delibera::create(['condominio_id' => $s['c']->id, 'assemblea_id' => $a->id, 'punto_id' => $punto->id, 'testo' => 'Dispensa respinta',
        'esito' => 'respinta', 'deliberata_il' => '2026-05-26']);
    // Respinta: l'assemblea ha deciso, il punto non torna (l'amministratore agisce entro i sei mesi).
    expect(diaDispensa($s))->toBeNull();
    $delibera->update(['esito' => 'rinviata']);
    expect(diaDispensa($s)['rinviato_dal'])->toBe('2026-05-26');
    $delibera->update(['esito' => 'approvata']);
    expect(diaDispensa($s))->toBeNull();
    $delibera->update(['esito' => 'approvata_con_modifiche']);
    expect(diaDispensa($s))->toBeNull();
    $delibera->update(['revocata_il' => '2026-05-28']);
    expect(diaDispensa($s)['chiave_fonte'])->toBe($chiave);
});

it('la catena: il punto proposto, l\'assemblea tenuta, «Registra la dispensa» con la delibera e l\'esercizio, la dispensa per tutti; poi niente compito e niente punto', function () {
    $s = smaViaVerdi($this);
    $a = diaAssemblea($this, $s, [diaDispensa($s)]);
    $punto = $a->punti()->where('chiave_fonte', 'sei_mesi:' . $s['e2025']->id)->firstOrFail();
    $this->travelTo('2026-05-27 10:00:00');
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $this->user, [$punto->id => ['esito' => 'approvata']], '2026-05-26');
    $d = Delibera::query()->where('punto_id', $punto->id)->firstOrFail();

    // Il seguito propone la cosa da fare con l'anno del punto.
    $righe = collect(SeguitoDelleDelibere::riepilogo($a->fresh())['punti'])->flatMap(fn ($p) => $p['righe'] ?? [])->keyBy('chiave');
    expect($righe->has($d->id . ':cosa:registra_dispensa'))->toBeTrue()
        ->and($righe[$d->id . ':cosa:registra_dispensa']['titolo'])->toBe('Dispensa dall\'agire entro sei mesi per i crediti del 2025');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.assemblee.seguito.conferma', [$s['c'], $a]), ['chiavi' => [$d->id . ':cosa:registra_dispensa']])
        ->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $azione = CoseDaFare::azione($cosa);
    expect($cosa->tipo)->toBe(TipoCosaDaFare::REGISTRA_DISPENSA)
        ->and($azione['testo'])->toBe('Registra la dispensa')
        ->and($azione['url'])->toContain('delibera_id=' . $d->id)->toContain('esercizio_id=' . $s['e2025']->id)->toContain('nuova=1')
        // Approvata e non ancora registrata: il punto non torna, anche se il compito è ancora aperto.
        ->and(smaRiga(smaRiquadro($s), 'Esercizio 2025')['compito'])->toBeTrue()
        ->and(diaDispensa($s))->toBeNull();

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'delibera_id' => $d->id, 'esercizi' => [(int) $s['e2025']->id], 'per_tutti' => '1',
    ])->assertSessionHasNoErrors();
    $dispensa = DispensaSeiMesi::query()->where('condominio_id', $s['c']->id)->firstOrFail();
    // La dispensa copre la data del 2025: le rate 1 e 2 passano all'altra loro data, il 30/06/2027 dell'esercizio 2026 (fra 399 giorni, nessun compito).
    $riquadro = smaRiquadro($s);
    expect($dispensa->data_delibera->format('Y-m-d'))->toBe('2026-05-26')
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and(smaRiga($riquadro, 'Esercizio 2025'))->toBeNull()
        ->and(smaRiga($riquadro, 'Esercizio 2026')['cifra'])->toBe(200000)
        ->and(smaRiga($riquadro, 'Esercizio 2026')['compito'])->toBeFalse()
        ->and(diaDispensa($s))->toBeNull();
});

it('il memo nato dai sei mesi e il punto del programma sono un punto solo; la finestra dice che il programma lo propone già', function () {
    $s = smaViaVerdi($this);
    $chiave = 'sei_mesi:' . $s['e2025']->id;
    $r = $this->actingAs($this->user)->getJson(route('admin.gestionale.memo.proposta', ['condominio' => $s['c']->id, 'origine_tipo' => 'sei_mesi',
        'origine_id' => $s['e2025']->id]))->assertOk();
    expect($r->json('testo'))->toBe('Dispensa dall\'agire entro sei mesi per i crediti del 2025 (art. 1129 c. 9 c.c.): quattro posizioni scadute, € 800,00')
        ->and($r->json('origine.tipo'))->toBe('sei_mesi')
        ->and($r->json('origine.nome'))->toBe('Sei mesi per i crediti del 2025')
        ->and($r->json('gia_proposto'))->toBe('Il programma propone già questo punto fra quelli della prossima assemblea: il tuo memo diventa lo stesso punto, con la tua nota.');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.memo.store', ['condominio' => $s['c']->id]), ['testo' => 'Dispensa per i crediti del 2025',
        'nota' => 'Chiedere ai consiglieri', 'origine_tipo' => 'sei_mesi', 'origine_id' => $s['e2025']->id])->assertCreated();
    $gruppi = collect(ProposteDellOrdineDelGiorno::per($s['c'], null));
    $memo = $gruppi->firstWhere('chiave', 'da_te')['punti'];
    expect(diaDispensa($s))->toBeNull()
        ->and($memo)->toHaveCount(1)
        ->and($memo[0]['chiave_fonte'])->toBe($chiave)
        ->and($memo[0]['testo'])->toBe('Dispensa per i crediti del 2025')
        ->and($memo[0]['materia'])->toBe('dispensa')
        ->and($memo[0]['esercizio_id'])->toBe((int) $s['e2025']->id)
        ->and($memo[0]['testo_pubblico'])->not->toContain('Rossi');
    // «Apri …» nella card della dashboard: il riquadro dei sei mesi, sulla riga dell'esercizio.
    expect(MemoriaDelCondominio::apriDelPunto($s['c'], $chiave, null))->toBe(['voce' => 'Apri i sei mesi per agire',
        'url' => route('admin.gestionale.index', ['condominio' => $s['c']->id], false) . '#sei-mesi-' . $s['e2025']->id]);
});

it('i sei mesi di un esercizio di un altro condominio: 404', function () {
    $s = smaViaVerdi($this);
    $altro = \App\Models\Esercizio::factory()->create(['condominio_id' => \App\Models\Condominio::factory()->create()->id, 'nome' => 'Esercizio 2025']);
    $this->actingAs($this->user)->getJson(route('admin.gestionale.memo.proposta', ['condominio' => $s['c']->id, 'origine_tipo' => 'sei_mesi',
        'origine_id' => $altro->id]))->assertNotFound();
});

it('«Rate scadute» porta l\'esercizio della data più vicina, o quello del filtro «I sei mesi del …», per «Da portare in assemblea»', function () {
    $s = smaViaVerdi($this);
    $pagina = fn (array $q = []) => $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $q))
        ->assertOk()->viewData('page')['props'];

    expect($pagina()['dispensa_in_assemblea'])->toBe(['esercizio_id' => (int) $s['e2025']->id, 'data' => '2026-06-30'])
        ->and($pagina(['sei_mesi_del' => '2027-06-30'])['dispensa_in_assemblea'])->toBe(['esercizio_id' => (int) $s['e']->id, 'data' => '2027-06-30'])
        ->and($pagina(['vista' => 'in_scadenza'])['dispensa_in_assemblea'])->toBeNull();
    // Con la dispensa sul 2025 la data più vicina da fare è quella del 2026.
    smaDispensa($s, [$s['e2025']]);
    expect($pagina()['dispensa_in_assemblea'])->toBe(['esercizio_id' => (int) $s['e']->id, 'data' => '2027-06-30']);
});
