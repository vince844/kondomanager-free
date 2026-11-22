<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\MemoDellAssemblea;

require_once __DIR__ . '/RotteDelleAssemblee.php';

/**
 * 1.12.0-beta.13 (Coda 679, R18 e §7 del progetto della tappa 2): **un id che arriva nella richiesta è del condominio della rotta**. Per le pagine delle
 * assemblee: la scheda di «Scegli la delibera», l'origine e l'assemblea del memo, il contesto e la riunione della delibera a mano, la riunione dello
 * spostamento, la tabella e il perimetro della tabella di proprietà, la tabella e la scala dell'assemblea e del punto, la delibera della ratifica e del
 * piano: di Corso Italia 3, chiesti da Via Roma 12, danno 404 e niente si scrive. E ogni rotta annidata con un figlio di Corso Italia 3 nell'indirizzo di Via
 * Roma 12 dà 404.
 *
 * Che cosa non copre: i `delibera_id` delle dodici pagine (hanno i loro test); le rotte della convocazione.
 */

beforeEach(fn () => $this->withoutVite());

it('gli id nel corpo e nella query di un altro condominio danno 404, e niente si scrive', function () {
    $s = ViaRoma12::conFacciata($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Proprietà CI', ['X' => 1000]);
    $assembleaAltra = ViaRoma12::ordinaria($altro);
    $deliberaAltra = ViaRoma12::delibera($altro, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $fatturaAltra = ViaRoma12::fatturaInSforo($altro);
    $scalaAltra = \App\Models\Scala::query()->create(['condominio_id' => $altro->c->id, 'name' => 'Z']);
    $tabellaAltra = $altro->tabelle['Proprietà CI'];
    $a = ViaRoma12::ordinaria($s);
    $memo = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $riunione = ViaRoma12::delibera($s, '2026-10-21', 'Il cancello', 'approvata', '40');
    $fattura = ViaRoma12::fatturaInSforo($s, '46');
    $assemblee = Assemblea::query()->count();
    $delibere = Delibera::query()->count();
    $memi = MemoDellAssemblea::query()->count();
    $c = $s->c;
    $u = $this->actingAs($s->admin);

    $u->getJson(route('admin.gestionale.delibere.scegli', [$c, 'oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => $fatturaAltra->id, 'ruolo' => 'ratifica']))->assertNotFound();
    $u->getJson(route('admin.gestionale.memo.proposta', [$c, 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fatturaAltra->id]))->assertNotFound();
    $u->postJson(route('admin.gestionale.memo.store', $c), ['testo' => 'Il tetto', 'assemblea_id' => $assembleaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.memo.store', $c), ['testo' => 'Il tetto', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fatturaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.memo.nell-ordine', [$c, $memo]), ['assemblea_id' => $assembleaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.delibere.a-mano', $c), ['giorno' => '2026-10-20', 'testo' => 'Il tetto', 'esito' => 'approvata',
        'oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => $fatturaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.delibere.a-mano', $c), ['giorno' => '2026-10-20', 'testo' => 'Il tetto', 'esito' => 'approvata',
        'parla_di' => [['tipo' => 'fattura_passiva', 'id' => $fatturaAltra->id]]])->assertNotFound();
    $u->postJson(route('admin.gestionale.delibere.a-mano', $c), ['giorno' => '2026-10-22', 'testo' => 'Il tetto', 'esito' => 'approvata',
        'riunione_id' => $deliberaAltra->assemblea_id])->assertNotFound();
    $u->postJson(route('admin.gestionale.delibere.a-mano', $c), ['giorno' => '2026-10-20', 'testo' => 'Il tetto', 'esito' => 'approvata', 'ambito' => 'scala',
        'scala_id' => $scalaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.delibere.sposta', [$c, $riunione]), ['riunione_id' => $deliberaAltra->assemblea_id])->assertNotFound();
    $u->postJson(route('admin.gestionale.tabella-di-proprieta', $c), ['perimetro_tipo' => 'condominio', 'tabella_id' => $tabellaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.tabella-di-proprieta', $c), ['perimetro_tipo' => 'scala', 'perimetro_id' => $scalaAltra->id, 'tabella_id' => null])->assertNotFound();
    $u->postJson(route('admin.gestionale.assemblee.store', $c), ['tipo' => 'ordinaria', 'ambito' => 'condominio', 'tabella_id' => $tabellaAltra->id,
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00']]])->assertNotFound();
    $u->postJson(route('admin.gestionale.assemblee.store', $c), ['tipo' => 'ordinaria', 'ambito' => 'scala', 'scala_id' => $scalaAltra->id,
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00']]])->assertNotFound();
    $u->postJson(route('admin.gestionale.assemblee.punti.store', [$c, $a]), ['testo' => 'Le funi', 'materia' => 'lavori', 'ambito' => 'scala', 'scala_id' => $scalaAltra->id])
        ->assertNotFound();
    $u->postJson(route('admin.gestionale.assemblee.punti.store', [$c, $a]), ['testo' => 'Le funi', 'materia' => 'lavori', 'tabella_id' => $tabellaAltra->id])->assertNotFound();
    $u->postJson(route('admin.gestionale.fatture.ratifica', [$c, $fattura]), ['delibera_id' => $deliberaAltra->id])->assertNotFound();

    expect(Assemblea::query()->count())->toBe($assemblee)->and(Delibera::query()->count())->toBe($delibere)->and(MemoDellAssemblea::query()->count())->toBe($memi)
        ->and($a->punti()->count())->toBe(0)->and($fattura->fresh()->stato_approvazione)->toBe('sforo_motivato')
        ->and($s->c->fresh()->tabella_proprieta_id)->toBeNull();
});

it('ogni rotta annidata delle pagine delle assemblee, con un figlio di un altro condominio nell\'indirizzo, dà 404', function () {
    $nostro = apFixture($this);
    $altro = apFixture($this);
    $sbagliate = [];
    foreach (apRotte($nostro) as [$metodo, $rotta, $parametri]) {
        $figli = array_diff(array_keys($parametri), ['condominio']);
        if ($figli === []) {
            continue;
        }
        foreach ($figli as $figlio) {
            $misti = [...$parametri, $figlio => $altro[$figlio]];
            // Il punto è figlio dell'assemblea: con l'assemblea di Via Roma e il punto di Corso Italia.
            $stato = $this->actingAs($nostro['s']->admin)->json($metodo, route($rotta, $misti))->status();
            if ($stato !== 404) {
                $sbagliate[] = "{$metodo} {$rotta} con {$figlio} dell'altro condominio → {$stato}";
            }
        }
    }
    expect($sbagliate)->toBe([]);
});
