<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # I contatori di commenti e allegati nell'elenco delle segnalazioni (decisione 453)
 *
 * - Nel pannello l'elenco conta ciò che la scheda mostra a chi guarda: i commenti pubblicati, più quelli in attesa per chi
 *   modera; gli allegati che la scheda gli apre. «In attesa» solo dove si può approvare.
 * - Nel portale conta ciò che la scheda mostra al condòmino: i pubblicati più i suoi in attesa, gli allegati che può aprire.
 * - I numeri sono calcolati dal server per tutte le righe insieme: le richieste al database non crescono con le righe.
 * - Per ogni caso il numero dell'elenco è uguale a quello della scheda.
 */

use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

function cesAllegato(Segnalazione $s, User $autore, bool $pubblicato = true, bool $approvato = true): int
{
    return DB::table('documenti')->insertGetId([
        'name' => 'foto.jpg', 'path' => 'allegati/foto.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10,
        'created_by' => $autore->id, 'is_published' => $pubblicato, 'is_approved' => $approvato,
        'documentable_type' => $s->getMorphClass(), 'documentable_id' => $s->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** I contatori della riga della segnalazione nell'elenco della porta data. */
function cesElenco($test, User $user, Segnalazione $s, string $porta = 'user'): array
{
    $props = json_decode(json_encode(
        $test->actingAs($user)->get(route($porta . '.segnalazioni.index'))->assertOk()->viewData('page')['props']
    ), true);
    $righe = $porta === 'user' ? $props['segnalazioni']['data'] : $props['segnalazioni'];
    $riga = collect($righe)->firstWhere('id', $s->id);
    expect($riga)->not->toBeNull("La segnalazione {$s->id} non è nell'elenco di {$user->name}");
    expect($riga)->toHaveKey('contatori');

    return $riga['contatori'];
}

/** Quanti commenti e quanti allegati mostra la scheda. */
function cesScheda($test, User $user, Segnalazione $s, string $porta = 'user'): array
{
    $props = json_decode(json_encode(
        $test->actingAs($user)->get(route($porta . '.segnalazioni.show', $s))->assertOk()->viewData('page')['props']
    ), true);

    return [
        'commenti' => count($props['segnalazione']['commenti'] ?? []) + count($props['segnalazione']['commentiInAttesa'] ?? []),
        'allegati' => count($props['allegati']),
    ];
}

/** Una segnalazione di Aurora con tre pubblicati, uno nascosto, uno in attesa di Mario e uno di Paolo. */
function cesScenario($test): Segnalazione
{
    $s = ccmSegnalazione($test->aurora, $test->mario);
    ccmCommentoSalvato($s, $test->mario, 'Uno', $test->aurora);
    ccmCommentoSalvato($s, $test->admin, 'Due', $test->aurora);
    ccmCommentoSalvato($s, $test->paolo, 'Tre', $test->aurora);
    ccmCommentoSalvato($s, $test->paolo, 'Nascosto', $test->aurora, null, 'nascosto');
    ccmCommentoSalvato($s, $test->mario, 'In attesa di Mario', $test->aurora, null, 'in_attesa');
    ccmCommentoSalvato($s, $test->paolo, 'In attesa di Paolo', $test->aurora, null, 'in_attesa');
    cesAllegato($s, $test->admin);
    cesAllegato($s, $test->mario, true, false);
    cesAllegato($s, $test->paolo, true, false);

    return $s;
}

test('nel portale l\'elenco conta i pubblicati più i propri in attesa, e la scheda dice lo stesso', function () {
    $s = cesScenario($this);

    // Mario: 3 pubblicati + il suo in attesa; allegati: l'approvato e il suo.
    expect(cesElenco($this, $this->mario, $s))->toBe(['commenti' => 4, 'allegati' => 2, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0]);
    // Paolo: 3 pubblicati + il suo; allegati: l'approvato e il suo.
    expect(cesElenco($this, $this->paolo, $s))->toBe(['commenti' => 4, 'allegati' => 2, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0]);

    foreach ([$this->mario, $this->paolo] as $chi) {
        $elenco = cesElenco($this, $chi, $s);
        $scheda = cesScheda($this, $chi, $s);
        expect($elenco['commenti'])->toBe($scheda['commenti'], "Commenti di {$chi->name}")
            ->and($elenco['allegati'])->toBe($scheda['allegati'], "Allegati di {$chi->name}");
    }
});

test('nel pannello l\'elenco conta tutto ciò che la scheda mostra, con quanti ne ha da approvare', function () {
    $s = cesScenario($this);

    // Commenti: 3 pubblicati + 2 in attesa (il nascosto non si vede); allegati: 3; da approvare: 2 commenti e 2 allegati.
    $elenco = cesElenco($this, $this->admin, $s, 'admin');
    // Decisione 463: ciò che aspetta, diviso fra commenti e allegati; `in_attesa` resta la somma.
    expect($elenco)->toBe(['commenti' => 5, 'allegati' => 3, 'in_attesa' => 4, 'commenti_in_attesa' => 2, 'allegati_in_attesa' => 2]);

    $scheda = cesScheda($this, $this->admin, $s, 'admin');
    expect($elenco['commenti'])->toBe($scheda['commenti'])->and($elenco['allegati'])->toBe($scheda['allegati']);
});

test('chi nel pannello non può moderare conta i pubblicati e basta, come la scheda', function () {
    $s = cesScenario($this);
    $studio = $this->collaboratrice;
    $studio->revokePermissionTo(CCM_PERMESSI_SEGNALAZIONI['approva']);
    foreach ($studio->roles as $ruolo) {
        $ruolo->revokePermissionTo(CCM_PERMESSI_SEGNALAZIONI['approva']);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $studio = $studio->fresh();

    $elenco = cesElenco($this, $studio, $s, 'admin');
    $scheda = cesScheda($this, $studio, $s, 'admin');

    expect($elenco['commenti'])->toBe($scheda['commenti'])
        ->and($elenco['allegati'])->toBe($scheda['allegati'])
        ->and($elenco['in_attesa'])->toBe(0);
});

test('con gli allegati riservati allo studio il condòmino non ne conta, la scheda neppure', function () {
    $s = cesScenario($this);
    DB::table('segnalazioni')->where('id', $s->id)->update(['allegati_accesso' => 'riservati']);

    expect(cesElenco($this, $this->mario, $s)['allegati'])->toBe(0)
        ->and(cesScheda($this, $this->mario, $s->fresh())['allegati'])->toBe(0);
});

test('le richieste al database dell\'elenco non crescono con le righe', function () {
    $righe = function (int $da, int $a) {
        foreach (range($da, $a) as $n) {
            $s = ccmSegnalazione($this->aurora, $this->mario, ['subject' => "Guasto numero {$n}"]);
            ccmCommentoSalvato($s, $this->mario, "Commento {$n}", $this->aurora);
            ccmCommentoSalvato($s, $this->mario, "In attesa {$n}", $this->aurora, null, 'in_attesa');
            cesAllegato($s, $this->admin);
            cesAllegato($s, $this->mario, true, false);
        }
    };
    $conta = function (string $porta, User $chi): int {
        $this->actingAs($chi)->get(route($porta . '.segnalazioni.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($chi)->get(route($porta . '.segnalazioni.index'))->assertOk();
        // Dell'elenco del pannello si escludono le richieste degli esercizi: le fa `CondominioResource` per ogni riga, e c'erano
        // prima dei contatori (non sono della decisione 453: vedi il rapporto della tappa F).
        $n = collect(DB::getQueryLog())->reject(fn ($q) => str_contains($q['query'], 'from "esercizi"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    $righe(1, 2);
    $portale2 = $conta('user', $this->mario);
    $pannello2 = $conta('admin', $this->admin);
    $righe(3, 10);
    $portale10 = $conta('user', $this->mario);
    $pannello10 = $conta('admin', $this->admin);

    expect($portale10)->toBe($portale2, "Il portale fa {$portale2} richieste con 2 righe e {$portale10} con 10")
        ->and($pannello10)->toBe($pannello2, "Il pannello fa {$pannello2} richieste con 2 righe e {$pannello10} con 10");
});

test('il portale non manda altro del modello oltre ai contatori', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario);

    expect(cesElenco($this, $this->mario, $s))->toHaveKeys(['commenti', 'allegati', 'in_attesa']);
});
