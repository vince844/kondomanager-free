<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # I contatori di commenti e allegati nell'elenco delle comunicazioni (decisione 441, punti 2, 3 e 4)
 *
 * - Nel portale l'elenco conta ciò che la scheda mostra al condòmino: i commenti delle discussioni che vede (pubblicati più
 *   i suoi in attesa) e gli allegati che può aprire. Una comunicazione ai palazzi ha una discussione sola (decisione 458): si
 *   contano i commenti di tutti i palazzi.
 * - Nel pannello l'elenco conta tutto, più «in attesa».
 * - I numeri sono calcolati dal server per tutte le righe insieme: le richieste al database non crescono con le righe.
 * - Per ogni caso il numero dell'elenco è uguale a quello della scheda.
 */

use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

/** Un allegato già a database, nello stato dato. */
function cecAllegato(Comunicazione $c, User $autore, bool $pubblicato = true, bool $approvato = true): int
{
    return DB::table('documenti')->insertGetId([
        'name' => 'allegato.pdf', 'path' => 'allegati/allegato.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $autore->id, 'is_published' => $pubblicato, 'is_approved' => $approvato,
        'documentable_type' => $c->getMorphClass(), 'documentable_id' => $c->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** I contatori della riga della comunicazione nell'elenco della porta data. */
function cecElenco($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $props = json_decode(json_encode(
        $test->actingAs($user)->get(route($porta . '.comunicazioni.index'))->assertOk()->viewData('page')['props']
    ), true);
    $righe = $porta === 'user' ? $props['comunicazioni']['data'] : $props['comunicazioni'];
    $riga = collect($righe)->firstWhere('id', $c->id);
    expect($riga)->not->toBeNull("La comunicazione {$c->id} non è nell'elenco di {$user->name}");
    expect($riga)->toHaveKey('contatori');

    return $riga['contatori'];
}

/** Quanti commenti e quanti allegati mostra la scheda: i numeri con cui si confronta l'elenco. */
function cecScheda($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $props = ccmScheda($test, $user, $c, $porta);

    return [
        // Chi modera trova nella scheda anche i nascosti (decisione 456), ma sono segnaposto: l'elenco, come prima, non li conta.
        'commenti' => collect($props['discussioni'])->sum(fn ($d) => collect($d['commenti'])->where('stato', '!=', 'nascosto')->count()),
        'allegati' => count($props['allegati']),
    ];
}

/** La comunicazione a sei palazzi, con commenti scritti da due (Aurora e Borea) e uno in attesa di Mario. */
function cecSeiPalazzi($test): Comunicazione
{
    $altri = collect(range(1, 4))->map(fn ($n) => Condominio::factory()->create(['nome' => "Condominio Extra {$n}"]));
    $c = ccmComunicazione([$test->aurora, $test->borea, ...$altri->all()], $test->admin, ['commenti_moderati' => true]);

    ccmCommentoSalvato($c, $test->mario, 'Aurora uno', $test->aurora);
    ccmCommentoSalvato($c, $test->admin, 'Aurora due', $test->aurora);
    ccmCommentoSalvato($c, $test->lucia, 'Borea uno', $test->borea);
    ccmCommentoSalvato($c, $test->lucia, 'Borea due', $test->borea);
    ccmCommentoSalvato($c, $test->lucia, 'Borea tre', $test->borea);
    ccmCommentoSalvato($c, $test->mario, 'Aurora in attesa di Mario', $test->aurora, null, 'in_attesa');
    ccmCommentoSalvato($c, $test->paolo, 'Aurora in attesa di Paolo', $test->aurora, null, 'in_attesa');
    ccmCommentoSalvato($c, $test->lucia, 'Borea nascosto', $test->borea, null, 'nascosto');

    return $c;
}

test('un condòmino vede nell\'elenco il numero della discussione unica, e la scheda dice lo stesso', function () {
    $c = cecSeiPalazzi($this);
    // La discussione è una sola (decisione 458). Lucia, di Borea, conta i 5 pubblicati di Aurora e di Borea (il nascosto no).
    // Mario, di Aurora, i 5 pubblicati più il suo in attesa; quello di Paolo non si conta.
    expect(cecElenco($this, $this->lucia, $c)['commenti'])->toBe(5)
        ->and(cecElenco($this, $this->mario, $c)['commenti'])->toBe(6);

    foreach ([$this->lucia, $this->mario, $this->paolo] as $chi) {
        expect(cecElenco($this, $chi, $c)['commenti'])->toBe(cecScheda($this, $chi, $c)['commenti'], "Elenco e scheda di {$chi->name} non coincidono");
    }
});

test('un commento in attesa conta per il suo autore e non per un altro condòmino', function () {
    $c = cecSeiPalazzi($this);
    $altro = ccmUtente([$this->aurora->id], 'Giulia Bianchi');

    // 5 pubblicati, di Aurora e di Borea. Mario aggiunge il suo in attesa (6); Giulia, dello stesso palazzo, non lo vede (5).
    expect(cecElenco($this, $this->mario, $c)['commenti'])->toBe(6)
        ->and(cecElenco($this, $altro, $c)['commenti'])->toBe(5)
        ->and(cecElenco($this, $altro, $c)['commenti'])->toBe(cecScheda($this, $altro, $c)['commenti']);
});

test('un allegato in attesa conta per il suo autore e non per un altro condòmino; gli approvati contano per tutti', function () {
    $c = cecSeiPalazzi($this);
    $altro = ccmUtente([$this->aurora->id], 'Giulia Bianchi');
    cecAllegato($c, $this->admin);
    cecAllegato($c, $this->mario, true, false);

    expect(cecElenco($this, $this->mario, $c)['allegati'])->toBe(2)
        ->and(cecElenco($this, $altro, $c)['allegati'])->toBe(1);
    foreach ([$this->mario, $altro, $this->lucia] as $chi) {
        expect(cecElenco($this, $chi, $c)['allegati'])->toBe(cecScheda($this, $chi, $c)['allegati']);
    }

    // Con gli allegati riservati allo studio il condòmino non ne vede, e quindi non ne conta.
    DB::table('comunicazioni')->where('id', $c->id)->update(['allegati_accesso' => 'riservati']);
    expect(cecElenco($this, $altro, $c)['allegati'])->toBe(0)
        ->and(cecScheda($this, $altro, $c->fresh())['allegati'])->toBe(0);
});

test('lo studio vede nell\'elenco il totale di tutte le discussioni e di tutti gli allegati, con quanti ne ha da approvare', function () {
    $c = cecSeiPalazzi($this);
    cecAllegato($c, $this->admin);
    cecAllegato($c, $this->mario, true, false);

    $contatori = cecElenco($this, $this->admin, $c, 'admin');

    // Tutti i commenti non nascosti (7: 5 pubblicati e 2 in attesa) e i due allegati; da approvare: 2 commenti e 1 allegato.
    // Decisione 463: ciò che aspetta, diviso fra commenti e allegati; `in_attesa` resta la somma.
    expect($contatori)->toBe(['commenti' => 7, 'allegati' => 2, 'in_attesa' => 3, 'commenti_in_attesa' => 2, 'allegati_in_attesa' => 1]);
    $scheda = cecScheda($this, $this->admin, $c, 'admin');
    expect($contatori['commenti'])->toBe($scheda['commenti'])->and($contatori['allegati'])->toBe($scheda['allegati']);
});

test('con i commenti spenti e nessun commento il numero è zero, e gli allegati contano lo stesso', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['can_comment' => false]);

    expect(cecElenco($this, $this->mario, $c))->toBe(['commenti' => 0, 'allegati' => 0, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0])
        ->and(cecElenco($this, $this->admin, $c, 'admin'))->toBe(['commenti' => 0, 'allegati' => 0, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0]);

    cecAllegato($c, $this->admin);
    expect(cecElenco($this, $this->mario, $c)['allegati'])->toBe(1);
});

test('una comunicazione a persone: ognuno conta solo la discussione con lo studio; la scheda dice lo stesso', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, [], [$this->mario, $this->lucia]);
    ccmCommentoSalvato($c, $this->mario, 'Mario allo studio', $this->aurora, $this->mario);
    ccmCommentoSalvato($c, $this->admin, 'Lo studio a Mario', $this->aurora, $this->mario);
    ccmCommentoSalvato($c, $this->lucia, 'Lucia allo studio', $this->borea, $this->lucia);

    expect(cecElenco($this, $this->mario, $c)['commenti'])->toBe(2)
        ->and(cecElenco($this, $this->lucia, $c)['commenti'])->toBe(1)
        ->and(cecElenco($this, $this->admin, $c, 'admin')['commenti'])->toBe(3);
    foreach ([$this->mario, $this->lucia] as $chi) {
        expect(cecElenco($this, $chi, $c)['commenti'])->toBe(cecScheda($this, $chi, $c)['commenti']);
    }
});

test('una comunicazione privata di un condòmino: l\'autore conta la sua discussione con lo studio, come la scheda', function () {
    $c = ccmComunicazione([$this->aurora], $this->mario, ['is_private' => true]);
    ccmCommentoSalvato($c, $this->mario, 'Una domanda', $this->aurora, $this->mario);
    ccmCommentoSalvato($c, $this->admin, 'La risposta', $this->aurora, $this->mario);

    expect(cecElenco($this, $this->mario, $c)['commenti'])->toBe(2)
        ->and(cecElenco($this, $this->mario, $c)['commenti'])->toBe(cecScheda($this, $this->mario, $c)['commenti'])
        ->and(cecElenco($this, $this->admin, $c, 'admin')['commenti'])->toBe(2);
});

test('chi non può vedere i commenti non li conta', function () {
    $c = cecSeiPalazzi($this);
    $this->mario->revokePermissionTo(CCM_PERMESSI['visualizza']);
    foreach ($this->mario->roles as $ruolo) {
        $ruolo->revokePermissionTo(CCM_PERMESSI['visualizza']);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    expect(cecElenco($this, $this->mario->fresh(), $c)['commenti'])->toBe(0);
});

test('le richieste al database dell\'elenco non crescono con le righe', function () {
    $righe = function (int $da, int $a) {
        foreach (range($da, $a) as $n) {
            $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => "Avviso numero {$n}"]);
            ccmCommentoSalvato($c, $this->mario, "Commento {$n}", $this->aurora);
            ccmCommentoSalvato($c, $this->mario, "In attesa {$n}", $this->aurora, null, 'in_attesa');
            cecAllegato($c, $this->admin);
            cecAllegato($c, $this->mario, true, false);
        }
    };
    $conta = function (string $porta, User $chi): int {
        $this->actingAs($chi)->get(route($porta . '.comunicazioni.index'))->assertOk(); // scalda le cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($chi)->get(route($porta . '.comunicazioni.index'))->assertOk();
        // Dell'elenco del pannello si escludono le richieste degli esercizi: le fa `CondominioResource` per ogni palazzo di ogni
        // riga, e c'erano prima dei contatori (non sono della decisione 441: vedi il rapporto della tappa E).
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

test('la risposta non porta i contatori dove nessuno li ha calcolati, e il portale non manda altro del modello', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);
    $props = ccmScheda($this, $this->mario, $c);

    expect($props['comunicazione'])->not->toHaveKey('contatori')
        ->and(cecElenco($this, $this->mario, $c))->toHaveKeys(['commenti', 'allegati', 'in_attesa']);
});

/**
 * Fase 1-bis della 1.12.0-beta.11: nel pannello chi non approva non è un moderatore. La collaboratrice di serie non ha «Approva
 * commenti comunicazioni» né «Approva allegati comunicazioni»: l'elenco le conta ciò che la sua scheda le mostra (i pubblicati
 * e i propri in attesa, gli allegati che può aprire) e nessun «in attesa».
 */
test('nel pannello chi non approva conta ciò che la sua scheda gli mostra, senza «in attesa»', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    ccmCommentoSalvato($c, $this->mario, 'Pubblicato', $this->aurora);
    ccmCommentoSalvato($c, $this->mario, 'In attesa, di Mario', $this->aurora, null, 'in_attesa');
    cecAllegato($c, $this->admin);
    cecAllegato($c, $this->mario, true, false);
    $collab = $this->collaboratrice->fresh();
    // I suoi, in attesa: li vede (è l'autrice), ma non li approva, quindi non sono «in attesa» per lei.
    ccmCommentoSalvato($c, $collab, 'In attesa, della collaboratrice', $this->aurora, null, 'in_attesa');
    cecAllegato($c, $collab, true, false);

    $elenco = cecElenco($this, $collab, $c, 'admin');
    $scheda = cecScheda($this, $collab, $c, 'admin');

    expect($elenco)->toBe(['commenti' => 2, 'allegati' => 2, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0])
        ->and($elenco['commenti'])->toBe($scheda['commenti'])
        ->and($elenco['allegati'])->toBe($scheda['allegati']);

    // Lo studio, che approva tutto, conta anche ciò che aspetta: quattro «in attesa», due commenti e due allegati.
    expect(cecElenco($this, $this->admin, $c, 'admin')['in_attesa'])->toBe(4);
});
