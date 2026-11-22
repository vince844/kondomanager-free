<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/*
 * Le rotte delle pagine delle assemblee lette dal router, con un fixture che ha un figlio per ogni parametro, e gli utenti per i permessi: per i test dei
 * permessi e degli id della richiesta (non è un test). Le rotte della convocazione, della sala, del verbale e del seguito hanno il loro file e il loro test.
 */

/** Le rotte delle pagine delle assemblee, con il metodo e i parametri del fixture. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function apRotte(array $p): array
{
    $rotte = [];
    foreach (Route::getRoutes() as $r) {
        $nome = (string) $r->getName();
        $nostra = str_starts_with($nome, 'admin.gestionale.assemblee.') || str_starts_with($nome, 'admin.gestionale.delibere.')
            || str_starts_with($nome, 'admin.gestionale.memo.') || in_array($nome, ['admin.gestionale.tabella-di-proprieta', 'admin.gestionale.fatture.ratifica',
                'admin.gestionale.piani-rate.delibera'], true);
        if (! $nostra || str_contains((string) $r->getActionName(), '\\Convocazione\\') || str_contains((string) $r->getActionName(), '\\Sala\\')
            || str_contains((string) $r->getActionName(), '\\Verbale\\') || str_contains((string) $r->getActionName(), '\\Seguito\\')) {
            continue;
        }
        $parametri = array_intersect_key($p, array_flip($r->parameterNames()));
        $metodo = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $rotte[] = [$metodo, $nome, $parametri];
    }

    return $rotte;
}

/** Il fixture con un figlio per ogni parametro delle rotte. @return array<string, mixed> */
function apFixture($t): array
{
    $s = ViaRoma12::conFacciata($t);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto']]);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $memo = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'condominio_id' => $s->c->id,
        'esercizio_id' => $s->e->id, 'nome' => 'Facciata 2026 — rate', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'prima_rata']);
    \Illuminate\Support\Facades\DB::table('esercizio_gestione')->insertOrIgnore(['esercizio_id' => $s->e->id, 'gestione_id' => $s->gestioni['Facciata 2026']['g']->id]);

    return ['s' => $s, 'condominio' => $s->c->id, 'assemblea' => $a->id, 'punto' => $a->punti()->first()->id, 'delibera' => $d->id, 'memo' => $memo->id,
        'fattura' => $fattura->id, 'esercizio' => $s->e->id, 'pianoRate' => $piano->id];
}

/** Un utente con il ruolo e i permessi dati, più l'accesso al pannello (così il 403 è delle assemblee, non del pannello). */
function apUtente(?string $ruolo, array $permessi): User
{
    $u = User::factory()->create();
    if ($ruolo !== null) {
        $u->assignRole(Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']));
    }
    foreach (['Accesso pannello amministratore', ...array_map(fn (Permesso $p) => $p->value, $permessi)] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $u->givePermissionTo($nome);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $u;
}

