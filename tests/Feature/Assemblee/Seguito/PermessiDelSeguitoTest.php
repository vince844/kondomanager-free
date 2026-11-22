<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * I permessi del seguito delle delibere (§9 del progetto della tappa): ogni rotta con «Gestisce le assemblee», il documento di «Segna eseguita» con «Vede»;
 * i ruoli `utente` e `fornitore` mai. Ogni id nell'indirizzo (l'assemblea, la delibera, la cosa da fare) è del suo condominio e della sua assemblea,
 * altrimenti 404 (R13).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le rotte del seguito lette dal router, con il metodo e i parametri del fixture. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function psgRotte(array $p): array
{
    $rotte = [];
    foreach (Route::getRoutes() as $r) {
        if (! str_contains((string) $r->getActionName(), '\\Assemblee\\Seguito\\')) {
            continue;
        }
        $rotte[] = [collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first(), (string) $r->getName(), array_intersect_key($p, array_flip($r->parameterNames()))];
    }

    return $rotte;
}

/** Una riunione su carta con la delibera del punto 1 e una cosa da fare eseguita con il documento. @return array<string, mixed> */
function psgFixture($t, string $nome = 'Via Roma 12'): array
{
    $s = ViaRoma12::nuovo($t, $nome);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']]);
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [(int) $a->punti()->value('id') => ['esito' => 'approvata']], '2026-12-15');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $cosa = CoseDaFare::crea($d, TipoCosaDaFare::FUORI_DAL_PROGRAMMA, 'Avvisa il manutentore', null, null, null, $s->admin);
    CoseDaFare::segnaEseguita($cosa, '2026-12-16', null, UploadedFile::fake()->create('lettera.pdf', 10, 'application/pdf'), $s->admin);

    return ['s' => $s, 'condominio' => $s->c->id, 'assemblea' => $a->id, 'delibera' => $d->id, 'cosa' => $cosa->id];
}

it('ogni rotta del seguito risponde 403 ai condòmini, ai fornitori e a chi nello studio non ha i permessi; «Vede» legge solo il documento', function () {
    $p = psgFixture($this);
    $rotte = psgRotte($p);
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE, Permesso::REGISTER_VERBALE_SOTTOSCRITTO];
    $sbagliate = [];
    foreach (['condòmino' => apUtente(Ruolo::UTENTE->value, $tutti), 'fornitore' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza permessi' => apUtente(null, [])] as $nome => $u) {
        foreach ($rotte as [$metodo, $rotta, $parametri]) {
            $stato = $this->actingAs($u)->json($metodo, route($rotta, $parametri))->status();
            if ($stato !== 403) {
                $sbagliate[] = "{$nome}: {$metodo} {$rotta} → {$stato}";
            }
        }
    }
    $vede = apUtente(null, [Permesso::VIEW_ASSEMBLEE]);
    foreach ($rotte as [$metodo, $rotta, $parametri]) {
        $stato = $this->actingAs($vede)->json($metodo, route($rotta, $parametri))->status();
        // «Vede» legge il documento di «Segna eseguita» e la stampa dello stato di esecuzione.
        $legge = in_array($rotta, ['admin.gestionale.assemblee.seguito.cose.documento', 'admin.gestionale.assemblee.seguito.stampa'], true);
        if ($legge ? $stato !== 200 : $stato !== 403) {
            $sbagliate[] = "vede: {$metodo} {$rotta} → {$stato}";
        }
    }
    expect(count($rotte))->toBe(15)->and($sbagliate)->toBe([]);
});

it('ogni id dell\'indirizzo di un altro condominio dà 404: l\'assemblea, la delibera, la cosa da fare', function () {
    $nostro = psgFixture($this);
    $altro = psgFixture($this, 'Corso Italia 3');
    $sbagliate = [];
    expect(psgRotte($nostro))->toHaveCount(15);
    foreach (psgRotte($nostro) as [$metodo, $rotta, $parametri]) {
        foreach (array_diff(array_keys($parametri), ['condominio']) as $figlio) {
            $stato = $this->actingAs($nostro['s']->admin)->json($metodo, route($rotta, [...$parametri, $figlio => $altro[$figlio]]))->status();
            if ($stato !== 404) {
                $sbagliate[] = "{$metodo} {$rotta} con {$figlio} dell'altro condominio → {$stato}";
            }
        }
    }
    expect($sbagliate)->toBe([]);
});
