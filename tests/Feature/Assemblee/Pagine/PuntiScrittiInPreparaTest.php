<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\RegistroModifica;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): in «Prepara l'assemblea» si scrivono anche i punti dell'amministratore, con la finestra di «Aggiungi un punto», e nascono con
 * l'assemblea.
 *
 * - Con «Prepara l'assemblea» i punti scritti nascono insieme ai proposti spuntati, dopo di loro e prima di «Varie ed eventuali», come «Aggiungi un punto»
 *   della pagina dell'assemblea: punto scritto a mano, la maggioranza, il conduttore, la scala e la tabella risolte nel condominio, l'importo in centesimi.
 *   Il registro ha una riga sola, quella della preparazione, che li conta con gli altri.
 * - La stessa strada: lo stesso punto scritto qui e con «Aggiungi un punto» nella pagina dell'assemblea ha gli stessi campi.
 * - Un punto scritto che non va torna sulla pagina con l'errore sul punto (`punti.N.campo`), le parole di «Aggiungi un punto», e l'assemblea non nasce.
 * - Ciò che la finestra non manda non entra (R18): né la fonte, né la chiave del sospeso, né il memo, né gli oggetti; una scala di un altro condominio è 404.
 *
 * Che cosa non copre: la pagina a video (`PreparaPuntiScritti.test.ts`, `FinestraDelPuntoNellaPagina.test.ts`); la catena fino all'avviso
 * (`CatenaDelPuntoScrittoTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});

/** Le righe dell'area delle assemblee scritte dopo `$prima`. */
function pspRighe(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)
        ->whereIn('oggetto_tipo', ['assemblea', 'seduta', 'punto', 'delibera', 'memo_assemblea', 'condominio', 'documento'])->orderBy('id')->get();
}

/** «Prepara l'assemblea» dalla rotta, con le date della bozza. */
function pspPrepara($t, Beta51Scenario $s, array $proposte, array $punti)
{
    return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale, Via Roma 12', 'proposte' => $proposte, 'punti' => $punti,
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
    ]);
}

/** Un punto come lo manda la finestra: tutti i campi, anche vuoti. */
function pspPunto(array $campi): array
{
    return $campi + ['testo_pubblico' => null, 'maggioranza' => 'ordinaria', 'conduttore' => null, 'ambito' => 'condominio', 'palazzina_id' => null,
        'scala_id' => null, 'tabella_id' => null, 'importo' => null];
}

/** Via Roma 12 con la scala B e la sua tabella «Ascensore B», e un memo in attesa. @return array{0: Beta51Scenario, 1: \App\Models\Scala, 2: MemoDellAssemblea} */
function pspViaRoma($t): array
{
    $s = ViaRoma12::nuovo($t)->tabella('Ascensore B', ['B1' => 500, 'B2' => 500]);
    $scala = \App\Models\Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    foreach (['B1', 'B2'] as $u) {
        $s->unita[$u]->forceFill(['scala_id' => $scala->id])->saveQuietly();
    }
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone che non si chiude'], $s->admin);

    return [$s, $scala, $memo];
}

function pspFuni(Beta51Scenario $s, \App\Models\Scala $scala): array
{
    return pspPunto(['testo' => 'Sostituzione delle funi dell\'ascensore della scala B: spesa di € 8.200,00', 'testo_pubblico' => 'Le funi dell\'ascensore della scala B',
        'materia' => 'lavori', 'maggioranza' => 'meta_del_valore', 'ambito' => 'scala', 'scala_id' => $scala->id,
        'tabella_id' => $s->tabelle['Ascensore B']->id, 'importo' => '8.200,00']);
}

it('i punti scritti nascono con i proposti spuntati, dopo di loro e prima di «Varie ed eventuali», con i loro campi; il registro ha una riga sola', function () {
    [$s, $scala, $memo] = pspViaRoma($this);
    $prima = ViaRoma12::ultimaRiga();

    pspPrepara($this, $s, ['memo:' . $memo->id, 'varie'], [
        pspFuni($s, $scala),
        pspPunto(['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento', 'conduttore' => 'vota']),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();
    $punti = $a->punti()->orderBy('numero')->get();
    expect($punti->pluck('testo')->all())->toBe(['Il portone che non si chiude', 'Sostituzione delle funi dell\'ascensore della scala B: spesa di € 8.200,00',
        'Riscaldamento: orari di accensione 2026/2027', 'Varie ed eventuali'])
        ->and($punti->pluck('numero')->all())->toBe([1, 2, 3, 4])
        ->and($punti[1]->fonte->value)->toBe('a_mano')->and($punti[1]->chiave_fonte)->toBeNull()
        ->and($punti[1]->testo_pubblico)->toBe('Le funi dell\'ascensore della scala B')
        ->and($punti[1]->materia->value)->toBe('lavori')->and($punti[1]->maggioranza->value)->toBe('meta_del_valore')
        ->and($punti[1]->ambito->value)->toBe('scala')->and($punti[1]->scala_id)->toBe($scala->id)
        ->and($punti[1]->tabella_id)->toBe($s->tabelle['Ascensore B']->id)
        ->and($punti[1]->importo)->toBe(820000)
        ->and($punti[2]->conduttore->value)->toBe('vota')->and($punti[2]->maggioranza->value)->toBe('ordinaria')
        // Il memo spuntato è entrato nel suo punto, come sempre.
        ->and($memo->refresh()->punto_id)->toBe($punti[0]->id);

    $righe = pspRighe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 preparata: 4 punti, prima convocazione 14/12/2026 alle 08:00, seconda 15/12/2026 alle 18:30');
});

it('la stessa strada di «Aggiungi un punto»: lo stesso punto scritto qui e nella pagina dell\'assemblea ha gli stessi campi', function () {
    [$s, $scala] = pspViaRoma($this);
    pspPrepara($this, $s, [], [pspFuni($s, $scala)])->assertSessionHasNoErrors();
    $qui = PuntoDellOrdineDelGiorno::query()->whereHas('assemblea', fn ($q) => $q->where('condominio_id', $s->c->id))->firstOrFail();

    $b = ViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $b]), pspFuni($s, $scala))->assertSessionHasNoErrors();
    $la = $b->punti()->firstOrFail();

    $campi = ['testo', 'testo_pubblico', 'materia', 'maggioranza', 'maggioranza_libera', 'amministrazione', 'conduttore', 'ambito', 'palazzina_id', 'scala_id',
        'tabella_id', 'esercizio_id', 'importo', 'dati', 'fonte', 'chiave_fonte'];
    expect($qui->assemblea_id)->not->toBe($la->assemblea_id)
        ->and(collect($qui->getAttributes())->only($campi)->all())->toBe(collect($la->getAttributes())->only($campi)->all());
});

it('un punto scritto che non va torna con l\'errore sul punto, con le parole di «Aggiungi un punto», e l\'assemblea non nasce', function () {
    [$s, $scala] = pspViaRoma($this);
    $prima = ViaRoma12::ultimaRiga();

    pspPrepara($this, $s, ['varie'], [
        pspPunto(['testo' => 'La pulizia delle scale', 'materia' => 'altro', 'maggioranza' => 'a_piacere', 'ambito' => 'scala', 'importo' => '8 mila']),
        pspPunto(['testo' => '', 'materia' => 'lavori']),
    ])->assertSessionHasErrors([
        'punti.0.maggioranza' => 'Scegli la maggioranza fra quelli proposti.',
        'punti.0.scala_id' => 'Scegli la scala del punto.',
        'punti.0.importo' => 'L\'importo si scrive così: 8.200,00.',
        'punti.1.testo' => 'Scrivi il punto, come andrà nell\'avviso.',
    ]);

    expect(Assemblea::query()->where('condominio_id', $s->c->id)->count())->toBe(0)
        ->and(pspRighe($prima))->toHaveCount(0);
});

it('ciò che la finestra non manda non entra: niente fonte, chiave, memo né oggetti dalla richiesta; una scala di un altro condominio è 404', function () {
    [$s, , $memo] = pspViaRoma($this);
    pspPrepara($this, $s, [], [pspPunto(['testo' => 'Il cancello del cortile', 'materia' => 'lavori', 'fonte' => 'memo', 'chiave_fonte' => 'memo:' . $memo->id,
        'memo_id' => $memo->id, 'oggetti' => [['tipo' => 'memo_assemblea', 'id' => $memo->id]]])])->assertSessionHasNoErrors();
    $p = PuntoDellOrdineDelGiorno::query()->whereHas('assemblea', fn ($q) => $q->where('condominio_id', $s->c->id))->firstOrFail();

    expect($p->fonte->value)->toBe('a_mano')->and($p->chiave_fonte)->toBeNull()
        ->and(OggettoDelPunto::query()->where('punto_id', $p->id)->count())->toBe(0)
        ->and($memo->refresh()->eInAttesa())->toBeTrue();

    $altro = ViaRoma12::nuovo($this);
    $scalaAltrui = \App\Models\Scala::query()->create(['condominio_id' => $altro->c->id, 'name' => 'Z']);
    pspPrepara($this, $s, [], [pspPunto(['testo' => 'La scala Z', 'materia' => 'lavori', 'ambito' => 'scala', 'scala_id' => $scalaAltrui->id])])->assertNotFound();
    expect(Assemblea::query()->where('condominio_id', $s->c->id)->count())->toBe(1);
});

it('senza punti scritti «Prepara l\'assemblea» resta com\'era: i proposti spuntati e basta', function () {
    [$s] = pspViaRoma($this);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'proposte' => ['varie'],
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00']],
    ])->assertSessionHasNoErrors();

    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();
    expect($a->punti()->pluck('testo')->all())->toBe(['Varie ed eventuali'])
        // Il memo non spuntato resta fra le proposte, per la prossima.
        ->and(collect(ProposteDellOrdineDelGiorno::per($s->c, $a))->flatMap(fn ($g) => $g['punti'])->pluck('testo')->all())->toContain('Il portone che non si chiude');
});
