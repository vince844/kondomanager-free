<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: che cosa ricevono le pagine (decisione 294)
 *
 * - **La scheda del pannello** riceve `segnalazione.assegnata_a` (a chi, se è ancora attivo, da chi, il giorno e l'ora
 *   nel fuso dell'installazione) e `assegnazione` (se chi guarda può assegnare, a chi, e chi è lui). Il vecchio
 *   `assigned_to`, che portava l'utente intero con email, ruoli e permessi, non c'è più.
 * - **L'elenco del pannello** riceve `assegnata_a` su ogni riga, `assegnatari` per il filtro, e filtra con
 *   `assegnazione[]`: `mie`, `nessuno`, o l'id di una persona. Si ordina per `assegnata_a`.
 * - **Il portale** non filtra e non ordina per assegnazione: sarebbe un modo di leggere ciò che l'impostazione nasconde.
 * - **Chi segue una segnalazione riceve l'avviso dei commenti nuovi**, anche se non ha un'anagrafica nel palazzo.
 *
 * Ciò che riceve il portale con l'impostazione accesa sta in `AssegnazioneImpostazioniTest.php`.
 */

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Commenti\NuovoCommentoNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

function asgSchedaPannello($test, User $chi, Segnalazione $s): array
{
    $props = asgProps($test->actingAs($chi)->get(route('admin.segnalazioni.show', $s)));
    expect($props)->not->toBeNull('La scheda del pannello non si apre');

    return $props;
}

/** Gli id delle righe dell'elenco del pannello con i parametri dati, in ordine. */
function asgIdsElenco($test, User $chi, array $parametri): array
{
    $risposta = $test->actingAs($chi)->get(route('admin.segnalazioni.index', $parametri));
    $risposta->assertSessionHasNoErrors();

    return collect(asgElenco(asgProps($risposta)))->pluck('id')->map(fn ($id) => (int) $id)->all();
}

function asgSospesa(string $nome): User
{
    $u = ccmStudioSenzaAnagrafica($nome, 'collaboratore');
    $u->forceFill(['suspended_at' => now()])->save();

    return $u->fresh();
}

// --- La scheda del pannello ----------------------------------------------------------------------------

test('la scheda di una segnalazione che non segue nessuno lo dice, senza il vecchio campo', function () {
    $scheda = asgRisorsa(asgSchedaPannello($this, $this->admin, $this->s)['segnalazione']);

    expect($scheda)->toHaveKey('assegnata_a')
        ->and($scheda['assegnata_a'])->toBeNull()
        ->and($scheda)->not->toHaveKey('assigned_to');
});

test('la scheda dice a chi è assegnata, da chi, il giorno e l\'ora nel fuso dell\'installazione', function () {
    $s = asgAssegnata($this, $this->elena);

    $props = asgSchedaPannello($this, $this->collaboratrice, $s);
    $scheda = asgRisorsa($props['segnalazione']);

    expect($scheda['assegnata_a'])->toEqual([
        'id' => $this->elena->id, 'name' => 'Elena Studio', 'attivo' => true,
        'da' => 'Studio Bianchi', 'il' => '10/11/2026', 'alle' => '09:30',
    ])
        ->and($scheda)->not->toHaveKey('assigned_to')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain($this->elena->email);
});

test('se chi ha assegnato non c\'è più, la scheda dice solo quando', function () {
    $s = asgAssegnata($this, $this->elena);
    DB::table('segnalazioni')->where('id', $s->id)->update(['assegnata_da' => 999999]);

    $assegnata = asgRisorsa(asgSchedaPannello($this, $this->admin, $s)['segnalazione'])['assegnata_a'];

    expect($assegnata['da'])->toBeNull()
        ->and($assegnata['il'])->toBe('10/11/2026')
        ->and($assegnata['name'])->toBe('Elena Studio');
});

test('chi segue una segnalazione e viene sospeso resta scritto, e la scheda dice che non è più attivo', function () {
    $ida = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    $s = asgAssegnata($this, $ida);
    $ida->forceFill(['suspended_at' => now()])->save();

    $props = asgSchedaPannello($this, $this->admin, $s);
    $assegnata = asgRisorsa($props['segnalazione'])['assegnata_a'];

    expect($assegnata['name'])->toBe('Ida Sospesa')
        ->and($assegnata['attivo'])->toBeFalse()
        ->and(collect($props['assegnazione']['assegnabili'])->pluck('name')->all())->not->toContain('Ida Sospesa');
});

test('chi può assegnare riceve l\'elenco di chi può ricevere, in ordine di nome, con id e nome soltanto', function () {
    asgSospesa('Ida Sospesa');
    $bruno = ccmStudioSenzaAnagrafica('bruno minuscolo', 'collaboratore');

    $assegnazione = asgSchedaPannello($this, $this->admin, $this->s)['assegnazione'] ?? null;

    expect($assegnazione)->not->toBeNull('La scheda non riceve `assegnazione`')
        ->and($assegnazione['puo_assegnare'])->toBeTrue()
        ->and($assegnazione['io'])->toBe($this->admin->id)
        ->and($assegnazione['assegnabili'])->toEqual([
            ['id' => $this->collaboratrice->id, 'name' => 'Anna Collaboratrice'],
            // L'ordine non distingue maiuscole e minuscole: è lo stesso su ogni database.
            ['id' => $bruno->id, 'name' => 'bruno minuscolo'],
            ['id' => $this->elena->id, 'name' => 'Elena Studio'],
            ['id' => $this->admin->id, 'name' => 'Studio Bianchi'],
        ]);
});

test('chi ha il pannello ma non il permesso legge a chi è assegnata, e non riceve l\'elenco', function () {
    $ruolo = ccmRuolo('operatore', ['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $operatore = ccmStudioSenzaAnagrafica('Otto Operatore', $ruolo);
    $s = asgAssegnata($this, $this->elena);

    $props = asgSchedaPannello($this, $operatore, $s);

    expect($props['assegnazione'] ?? null)->toEqual(['puo_assegnare' => false, 'io' => $operatore->id, 'assegnabili' => []])
        ->and(asgRisorsa($props['segnalazione'])['assegnata_a']['name'])->toBe('Elena Studio');
});

// --- L'elenco del pannello -----------------------------------------------------------------------------

test('l\'elenco del pannello dice a chi è assegnata ogni segnalazione', function () {
    $assegnata = asgAssegnata($this, $this->elena);

    $props = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.index')));
    $righe = collect(asgElenco($props))->keyBy('id');

    expect($righe[$this->s->id])->toHaveKey('assegnata_a')
        ->and($righe[$this->s->id]['assegnata_a'])->toBeNull()
        ->and($righe[$assegnata->id]['assegnata_a'])->toMatchArray(['id' => $this->elena->id, 'name' => 'Elena Studio', 'attivo' => true])
        ->and($righe[$assegnata->id])->not->toHaveKey('assigned_to')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain($this->elena->email);
});

test('l\'elenco riceve i nomi per il filtro: chi può ricevere, e chi segue ancora qualcosa pur non potendo più', function () {
    $ida = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    asgAssegnata($this, $ida);
    $ida->forceFill(['suspended_at' => now()])->save();
    asgSospesa('Ugo Uscito');
    $bruno = ccmStudioSenzaAnagrafica('bruno minuscolo', 'collaboratore');

    $props = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.index')));

    expect($props['assegnatari'] ?? null)->toEqual([
        ['id' => $this->collaboratrice->id, 'name' => 'Anna Collaboratrice', 'attivo' => true],
        ['id' => $bruno->id, 'name' => 'bruno minuscolo', 'attivo' => true],
        ['id' => $this->elena->id, 'name' => 'Elena Studio', 'attivo' => true],
        ['id' => $ida->id, 'name' => 'Ida Sospesa', 'attivo' => false],
        ['id' => $this->admin->id, 'name' => 'Studio Bianchi', 'attivo' => true],
    ]);
});

test('il filtro dell\'elenco: le mie, quelle di nessuno, quelle di una persona, e più scelte insieme', function () {
    $diElena = asgAssegnata($this, $this->elena);
    $diAnna = asgAssegnata($this, $this->collaboratrice);
    $mia = asgAssegnata($this, $this->admin);
    $ids = fn (array $scelte) => collect(asgIdsElenco($this, $this->admin, ['assegnazione' => $scelte]))->sort()->values()->all();

    expect($ids(['mie']))->toBe([$mia->id])
        ->and($ids(['nessuno']))->toBe([$this->s->id])
        ->and($ids([(string) $this->elena->id]))->toBe([$diElena->id])
        ->and($ids(['mie', (string) $this->elena->id]))->toBe([$diElena->id, $mia->id])
        ->and($ids(['nessuno', (string) $this->collaboratrice->id]))->toBe([$this->s->id, $diAnna->id])
        ->and(collect(asgIdsElenco($this, $this->admin, []))->sort()->values()->all())->toBe([$this->s->id, $diElena->id, $diAnna->id, $mia->id]);
});

test('«le mie» sono quelle di chi guarda', function () {
    $diElena = asgAssegnata($this, $this->elena);
    asgAssegnata($this, $this->admin);

    expect(asgIdsElenco($this, $this->elena, ['assegnazione' => ['mie']]))->toBe([$diElena->id]);
});

test('il filtro si somma agli altri, e l\'elenco lo rimanda alla pagina', function () {
    $aperta = asgAssegnata($this, $this->elena);
    $chiusa = asgAssegnata($this, $this->elena, null, ['stato' => 'chiusa']);

    $risposta = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', ['assegnazione' => [(string) $this->elena->id], 'stato' => ['chiusa']]));
    $props = asgProps($risposta);

    expect(collect(asgElenco($props))->pluck('id')->all())->toBe([$chiusa->id])
        ->and($props['filters']['assegnazione'] ?? null)->toEqual([(string) $this->elena->id])
        ->and($aperta->id)->not->toBe($chiusa->id);
});

test('il filtro rifiuta un valore che non conosce', function () {
    $this->actingAs($this->admin)->from(route('admin.segnalazioni.index'))
        ->get(route('admin.segnalazioni.index', ['assegnazione' => ['chiunque']]))
        ->assertSessionHasErrors('assegnazione.0');
});

test('l\'elenco si ordina per chi segue la segnalazione', function () {
    asgAssegnata($this, $this->elena);
    asgAssegnata($this, $this->admin);
    asgAssegnata($this, $this->collaboratrice);
    $nomi = function (string $verso) {
        $risposta = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', ['sort' => 'assegnata_a', 'direction' => $verso]));
        $risposta->assertSessionHasNoErrors();

        return collect(asgElenco(asgProps($risposta)))->pluck('assegnata_a.name')->filter()->values()->all();
    };

    expect($nomi('asc'))->toBe(['Anna Collaboratrice', 'Elena Studio', 'Studio Bianchi'])
        ->and($nomi('desc'))->toBe(['Studio Bianchi', 'Elena Studio', 'Anna Collaboratrice']);
});

test('l\'elenco non fa richieste al database per ogni riga assegnata', function () {
    $conta = function (): int {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', ['per_page' => 30]))->assertOk();
        $quante = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $quante;
    };
    $segnalazioni = collect(range(1, 8))->map(fn (int $n) => ccmSegnalazione($this->aurora, $this->mario, ['subject' => "Guasto numero {$n}"]));
    $senza = $conta();

    $segnalazioni->each(function ($s, int $i) {
        $persona = ccmStudioSenzaAnagrafica("Collaboratore {$i}", 'collaboratore');
        DB::table('segnalazioni')->where('id', $s->id)->update(['assigned_to' => $persona->id, 'assegnata_da' => $this->admin->id, 'assegnata_il' => now()]);
    });
    $con = $conta();

    // Otto righe assegnate a otto persone diverse: qualche richiesta in più per caricarle tutte insieme, non una (o due) per riga.
    expect($con - $senza)->toBeLessThanOrEqual(5, "Con otto righe assegnate l'elenco fa " . ($con - $senza) . ' richieste in più');
});

// --- Il portale non filtra e non ordina per assegnazione -----------------------------------------------

test('nel portale il filtro dell\'assegnazione non filtra niente', function (array $scelte) {
    $assegnata = asgAssegnata($this, $this->elena);

    $risposta = $this->actingAs($this->mario)->get(route('user.segnalazioni.index', ['assegnazione' => $scelte]));
    if ($risposta->status() === 302) {
        $risposta->assertSessionHasErrors();

        return;
    }
    $ids = collect(asgElenco(asgProps($risposta)))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe([$this->s->id, $assegnata->id]);
})->with(['nessuno' => [['nessuno']], 'mie' => [['mie']]]);

test('nel portale l\'elenco non si ordina per chi segue la segnalazione', function () {
    $a = asgAssegnata($this, $this->collaboratrice, null, ['subject' => 'Ascensore fermo']);
    $b = asgAssegnata($this, $this->admin, null, ['subject' => 'Buca nel cortile']);
    $c = asgAssegnata($this, $this->elena, null, ['subject' => 'Citofono muto']);
    DB::table('segnalazioni')->where('id', $this->s->id)->delete();

    $risposta = $this->actingAs($this->mario)->get(route('user.segnalazioni.index', ['sort' => 'assegnata_a', 'direction' => 'desc']));
    if ($risposta->status() === 302) {
        $risposta->assertSessionHasErrors();

        return;
    }

    // L'ordine di serie: le più recenti prima (qui l'istante è lo stesso, quindi l'ultima inserita). Per chi segue, al
    // contrario, sarebbe: Buca (Studio), Citofono (Elena), Ascensore (Anna).
    expect(collect(asgElenco(asgProps($risposta)))->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

// --- Gli avvisi dei commenti ---------------------------------------------------------------------------

test('un commento nuovo avvisa anche chi segue la segnalazione, pur senza anagrafica nel palazzo', function () {
    $assegnata = asgAssegnata($this, $this->elena);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $this->s), ['corpo' => 'Ancora aperto'])->assertRedirect();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->elena->id);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $assegnata), ['corpo' => 'Ancora aperto anche questo'])->assertRedirect();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->toContain($this->elena->id);
});

test('chi segue la segnalazione e ha spento «Nuovo commento» non riceve l\'avviso', function () {
    $assegnata = asgAssegnata($this, $this->elena);
    asgPreferenza($this->elena, 'new_comment', false);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $assegnata), ['corpo' => 'Ancora aperto'])->assertRedirect();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->elena->id);
});

test('chi segue la segnalazione e scrive lui il commento non riceve l\'avviso del suo', function () {
    $assegnata = asgAssegnata($this, $this->elena);

    $this->actingAs($this->elena)->post(route('admin.segnalazioni.commenti.store', $assegnata), ['corpo' => 'Il tecnico passa giovedì'])->assertRedirect();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($this->elena->id)
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toContain($this->mario->id);
});

test('chi seguiva la segnalazione ed è stato sospeso non riceve gli avvisi dei commenti', function () {
    $ida = ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore');
    $assegnata = asgAssegnata($this, $ida);
    $ida->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $assegnata), ['corpo' => 'Ancora aperto'])->assertRedirect();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($ida->id);
});

// --- I testi -------------------------------------------------------------------------------------------

test('i testi dell\'assegnazione stanno nei file di lingua, nelle quattro lingue', function () {
    $testi = [
        'segnalazioni.assignment.label' => 'Assegnata a',
        'segnalazioni.assignment.none' => 'Nessuno',
        'segnalazioni.assignment.placeholder' => 'Scegli a chi assegnarla',
        'segnalazioni.assignment.assign_to_me' => 'Assegna a me',
        'segnalazioni.assignment.assigned' => 'Segnalazione assegnata a :name.',
        'segnalazioni.assignment.unassigned' => 'Assegnazione tolta.',
        'segnalazioni.assignment.not_assignable' => 'Questa persona non può ricevere segnalazioni: non ha accesso al pannello, o è sospesa.',
        'segnalazioni.assignment.assigned_by' => 'Assegnata da :user il :date alle :time',
        'segnalazioni.assignment.assigned_on' => 'Assegnata il :date alle :time',
        'segnalazioni.assignment.inactive' => ':name non ha più accesso al pannello: assegna la segnalazione a un\'altra persona.',
        'segnalazioni.assignment.inactive_short' => 'Non più attivo',
        'segnalazioni.assignment.followed_by' => 'Seguita da :name',
        'segnalazioni.assignment.filter_mine' => 'A me',
        'segnalazioni.assignment.filter_nobody' => 'A nessuno',
        'policies.assign_ticket' => 'Non hai permessi sufficienti per assegnare questa segnalazione guasto!',
    ];

    foreach ($testi as $chiave => $italiano) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
        expect(__($chiave, [], 'it'))->toBe($italiano);
    }

    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['label', 'assign_to_me', 'assigned', 'unassigned', 'not_assignable', 'assigned_by', 'inactive', 'followed_by', 'filter_nobody'] as $chiave) {
            expect(__("segnalazioni.assignment.{$chiave}", [], $lingua))
                ->not->toBe($testi["segnalazioni.assignment.{$chiave}"], "segnalazioni.assignment.{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

test('chi non può assegnare legge perché', function () {
    $ruolo = ccmRuolo('operatore', ['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $operatore = ccmStudioSenzaAnagrafica('Otto Operatore', $ruolo);

    $risposta = $this->actingAs($operatore)->patchJson(route('admin.segnalazioni.assegna', $this->s), ['assigned_to' => $this->elena->id]);

    $risposta->assertForbidden();
    expect($risposta->json('message'))->toBe(__('policies.assign_ticket'));
});
