<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La pagina «Commenti» del pannello (Coda 657, decisione 442): l'elenco
 *
 * - Chi la apre e chi no; un permesso tolto dal database non fa cadere la pagina.
 * - Quali commenti vede ognuno: quelli degli elementi che apre nel pannello; gli eliminati no; i nascosti e gli in attesa degli
 *   altri solo chi modera quel tipo.
 * - Il filtro di stato, i filtri uno per uno, l'ordine (prima ciò che aspetta).
 * - Per ogni riga, le azioni sono quelle che la regola della scheda concede a chi guarda (`RegoleCommenti`).
 * - Le richieste al database non crescono con le righe.
 */

use App\Models\Commento;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermessoSpatie;

require_once __DIR__ . '/CommentiPaginaScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

test('la pagina la aprono lo studio e chi nel pannello può leggere i commenti; un condòmino no', function () {
    $this->actingAs($this->admin)->get(route('admin.commenti.index'))->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('commenti/CommentiList'));
    $this->actingAs($this->collaboratrice)->get(route('admin.commenti.index'))->assertOk();
    $this->actingAs($this->mario)->get(route('admin.commenti.index'))->assertForbidden();

    $lettore = cpStudioConRuolo('Lettore Segnalazioni', ['Accesso pannello amministratore', CCM_PERMESSI_SEGNALAZIONI['visualizza']]);
    $this->actingAs($lettore)->get(route('admin.commenti.index'))->assertOk();

    $senza = cpStudioConRuolo('Portiere Senza Commenti', ['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $this->actingAs($senza)->get(route('admin.commenti.index'))->assertForbidden();
});

test('un permesso dei commenti tolto dal database non fa cadere la pagina', function () {
    cpScenario($this);
    PermessoSpatie::query()->where('name', CCM_PERMESSI['visualizza'])->delete();
    PermessoSpatie::query()->where('name', CCM_PERMESSI_SEGNALAZIONI['elimina_propri'])->delete();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($this->admin->fresh())->get(route('admin.commenti.index'))->assertOk();
    $this->actingAs($this->collaboratrice->fresh())->get(route('admin.commenti.index'))->assertOk();
});

test('i commenti eliminati non ci sono, i nascosti sì', function () {
    $sc = cpScenario($this);
    $ids = cpIds($this, $this->admin);

    expect($ids)->not->toContain($sc['id']['s_eli'])
        ->and($ids)->toContain($sc['id']['s_nas'])
        ->and($ids)->toHaveCount(count($sc['id']) - 1);
});

test('prima ciò che aspetta, poi i più recenti', function () {
    $sc = cpScenario($this);
    $righe = cpRighe($this, $this->admin);

    $stati = array_column($righe, 'stato');
    $primoNonInAttesa = array_search(true, array_map(fn ($s) => $s !== 'in_attesa', $stati), true);
    expect(array_slice($stati, 0, $primoNonInAttesa))->each->toBe('in_attesa')
        ->and(array_slice($stati, $primoNonInAttesa))->not->toContain('in_attesa');

    // Dentro lo stesso gruppo, il più recente per primo: i commenti si salvano in ordine, quindi gli id scendono.
    $inAttesa = array_column(array_filter($righe, fn ($r) => $r['stato'] === 'in_attesa'), 'id');
    $altri = array_column(array_filter($righe, fn ($r) => $r['stato'] !== 'in_attesa'), 'id');
    $scendono = fn (array $ids) => $ids === collect($ids)->sortDesc()->values()->all();
    expect($scendono($inAttesa))->toBeTrue()->and($scendono($altri))->toBeTrue();
});

test('il filtro di stato: tutti, in attesa, pubblicato, nascosto', function () {
    $sc = cpScenario($this);
    $id = $sc['id'];

    expect(cpIds($this, $this->admin, ['stato' => 'in_attesa']))->toEqualCanonicalizing([$id['s_att'], $id['c_aur_att'], $id['c_bor_att'], $id['p_lucia']])
        ->and(cpIds($this, $this->admin, ['stato' => 'nascosto']))->toBe([$id['s_nas']])
        ->and(cpIds($this, $this->admin, ['stato' => 'pubblicato']))->toEqualCanonicalizing([$id['s_pub'], $id['c_aur'], $id['c_bor'], $id['p_mario']])
        ->and(cpIds($this, $this->admin))->toHaveCount(count($id) - 1);

    $this->actingAs($this->admin)->get(route('admin.commenti.index', ['stato' => 'sconosciuto']))->assertRedirect();
});

test('i filtri uno per uno: tipo, condominio, autore, periodo', function () {
    $sc = cpScenario($this);
    $id = $sc['id'];

    // Tipo.
    expect(cpIds($this, $this->admin, ['tipo' => 'segnalazioni']))->toEqualCanonicalizing([$id['s_pub'], $id['s_att'], $id['s_nas']])
        ->and(cpIds($this, $this->admin, ['tipo' => 'comunicazioni']))->toHaveCount(6);

    // Condominio: la segnalazione di Aurora, le comunicazioni a tutti e due i palazzi.
    expect(cpIds($this, $this->admin, ['condominio_id' => $this->borea->id]))->toEqualCanonicalizing(
        [$id['c_aur'], $id['c_aur_att'], $id['c_bor'], $id['c_bor_att'], $id['p_mario'], $id['p_lucia']]
    );

    // Autore: per nome utente.
    expect(cpIds($this, $this->admin, ['autore' => 'Lucia']))->toEqualCanonicalizing([$id['c_bor'], $id['c_bor_att'], $id['p_lucia']])
        ->and(cpIds($this, $this->admin, ['autore' => 'nessuno così']))->toBe([]);

    // Periodo: un commento di dieci giorni fa.
    DB::table('commenti')->where('id', $id['s_pub'])->update(['created_at' => now()->subDays(10)]);
    expect(cpIds($this, $this->admin, ['al' => now()->subDays(5)->toDateString()]))->toBe([$id['s_pub']])
        ->and(cpIds($this, $this->admin, ['dal' => now()->subDays(5)->toDateString()]))->not->toContain($id['s_pub']);
});

test('la pagina dice i filtri in uso e i condomini', function () {
    cpScenario($this);
    $props = cpProps($this, $this->admin, ['stato' => 'in_attesa', 'tipo' => 'segnalazioni', 'condominio_id' => $this->aurora->id, 'autore' => 'Mario']);

    expect($props['filtri'])->toMatchArray(['stato' => 'in_attesa', 'tipo' => 'segnalazioni', 'condominio_id' => $this->aurora->id, 'autore' => 'Mario'])
        ->and(collect($props['condomini'])->pluck('nome')->all())->toContain('Condominio Aurora', 'Condominio Borea');
});

test('ogni riga dice il testo, l\'elemento, il condominio o la persona, l\'autore e lo stato', function () {
    $sc = cpScenario($this);
    $righe = collect(cpRighe($this, $this->admin))->keyBy('id');

    $segnalazione = $righe[$sc['id']['s_pub']];
    expect($segnalazione)->toMatchArray([
        'testo' => 'Il cancello è ancora aperto', 'tipo' => 'segnalazioni', 'elemento' => 'Il cancello non chiude',
        'condominio' => 'Condominio Aurora', 'autore' => 'Mario Rossi', 'stato' => 'pubblicato',
        'url_elemento' => route('admin.segnalazioni.show', $sc['s']),
        'url_commenti' => route('admin.segnalazioni.show', $sc['s']) . '#commenti',
    ]);

    // Una comunicazione a palazzi: il palazzo della discussione; a persone: sempre un condominio, e la persona sotto il titolo (decisione 479 (2)).
    expect($righe[$sc['id']['c_bor']])->toMatchArray(['tipo' => 'comunicazioni', 'condominio' => 'Condominio Borea', 'discussione_con' => null, 'url_commenti' => route('admin.comunicazioni.show', $sc['c']) . '#commenti'])
        ->and($righe[$sc['id']['p_lucia']])->toMatchArray(['condominio' => 'Condominio Borea', 'discussione_con' => 'Lucia Verdi', 'stato' => 'in_attesa'])
        ->and($righe[$sc['id']['s_pub']]['discussione_con'])->toBeNull();
});

test('in una discussione a persone, se il commento non porta un condominio, la colonna dice quello della persona fra i destinatari (decisione 479 (2))', function () {
    $sc = cpScenario($this);
    $id = ccmCommentoSalvato($sc['p'], $this->admin, 'Lo studio risponde a Lucia', null, $this->lucia);
    $riga = collect(cpRighe($this, $this->admin))->keyBy('id')[$id];

    expect($riga)->toMatchArray(['condominio' => 'Condominio Borea', 'discussione_con' => 'Lucia Verdi', 'autore' => $riga['autore']]);
});

/** Chi vede quali commenti: la regola della scheda, commento per commento. */
test('la pagina mostra i commenti degli elementi che chi guarda apre nel pannello, né più né meno', function () {
    $sc = cpScenario($this);

    // Chi vede tutte le segnalazioni e le comunicazioni.
    $tutto = cpStudioConRuolo('Studio Completo', ['Accesso pannello amministratore', 'Visualizza segnalazioni', 'Visualizza comunicazioni', CCM_PERMESSI_SEGNALAZIONI['visualizza'], CCM_PERMESSI['visualizza']]);
    // Chi vede solo le proprie segnalazioni (e nessuna comunicazione nei commenti).
    $proprie = cpStudioConRuolo('Studio Proprie', ['Accesso pannello amministratore', 'Visualizza proprie segnalazioni', CCM_PERMESSI_SEGNALAZIONI['visualizza']]);
    $solo_comunicazioni = cpStudioConRuolo('Studio Bacheca', ['Accesso pannello amministratore', 'Visualizza comunicazioni', CCM_PERMESSI['visualizza']]);

    foreach ([$this->admin, $this->collaboratrice, $tutto, $proprie, $solo_comunicazioni] as $chi) {
        $attesi = Commento::query()->with('commentable')->get()->filter(function (Commento $c) use ($chi) {
            $elemento = $c->commentable;
            $tipo = RegoleCommenti::tipo($elemento);

            // Il perimetro dell'elemento, più quello dello stato (Fase 1-bis della 1.12.0-beta.11): chi non modera legge i
            // pubblicati e i propri in attesa, come nella scheda.
            $stato = RegoleCommenti::puoModerare($chi, $elemento)
                || $c->stato === 'pubblicato'
                || ($c->stato === 'in_attesa' && (int) $c->user_id === (int) $chi->id);

            return RegoleCommenti::haPermesso($chi, RegoleCommenti::permesso($tipo, 'visualizza'))
                && RegoleCommenti::apreSchedaPannello($chi, $elemento)
                && $stato;
        })->pluck('id')->sort()->values()->all();

        $visti = collect(cpIds($this, $chi->fresh(), ['per_page' => 100]))->sort()->values()->all();
        expect($visti)->toBe($attesi, "I commenti di {$chi->name} non sono quelli che le schede gli aprono");
    }

    // Alcuni numeri a mano, perché il confronto con la regola da solo non dice che non sia sempre vuoto.
    expect(cpIds($this, $this->admin->fresh()))->toHaveCount(count($sc['id']) - 1)
        // Chi legge solo le comunicazioni e non le approva: i tre pubblicati delle due comunicazioni, non i tre in attesa.
        ->and(cpIds($this, $solo_comunicazioni->fresh()))->toHaveCount(3)
        ->and(cpIds($this, $proprie->fresh()))->toBe([]);

    // Nessuna segnalazione propria: la persona dello studio che ha scritto la segnalazione vede i suoi commenti.
    $mia = ccmSegnalazione($this->aurora, $proprie, ['subject' => 'La mia segnalazione']);
    $suo = ccmCommentoSalvato($mia, $this->mario, 'Un commento alla sua', $this->aurora);
    expect(cpIds($this, $proprie->fresh()))->toBe([$suo]);
});

test('per ogni riga le azioni sono quelle che la regola della scheda concede a chi guarda', function () {
    cpScenario($this);
    $tutto = cpStudioConRuolo('Studio Senza Approvare', [
        'Accesso pannello amministratore', 'Visualizza segnalazioni', 'Visualizza comunicazioni',
        CCM_PERMESSI_SEGNALAZIONI['visualizza'], CCM_PERMESSI['visualizza'], CCM_PERMESSI['commenta'], CCM_PERMESSI_SEGNALAZIONI['commenta'],
        CCM_PERMESSI['elimina_propri'],
    ]);

    foreach ([$this->admin, $this->collaboratrice, $tutto] as $chi) {
        $chi = $chi->fresh();
        foreach (cpRighe($this, $chi, ['per_page' => 100]) as $riga) {
            $commento = Commento::query()->with('commentable')->findOrFail($riga['id']);
            $elemento = $commento->commentable;
            $chiave = RegoleCommenti::chiaveDelCommento($commento);
            $nome = "{$chi->name}, commento {$commento->id}";

            expect($riga['puo_approvare'])->toBe(RegoleCommenti::puoApprovare($chi, $commento), "Approva: {$nome}")
                ->and($riga['puo_nascondere'])->toBe(RegoleCommenti::puoNascondere($chi, $commento), "Nascondi: {$nome}")
                ->and($riga['puo_modificare'])->toBe(RegoleCommenti::puoModificare($chi, $commento), "Modifica: {$nome}")
                ->and($riga['puo_eliminare'])->toBe(RegoleCommenti::puoEliminare($chi, $commento), "Elimina: {$nome}")
                ->and($riga['puo_rispondere'])->toBe(RegoleCommenti::puoScrivereInDiscussione($chi, $elemento, $chiave, 'admin'), "Rispondi: {$nome}");
        }
    }
});

test('con i commenti chiusi sull\'elemento la voce «Rispondi» non c\'è', function () {
    $sc = cpScenario($this);
    DB::table('comunicazioni')->where('id', $sc['c']->id)->update(['can_comment' => false]);
    DB::table('segnalazioni')->where('id', $sc['s']->id)->update(['can_comment' => false]);
    $righe = collect(cpRighe($this, $this->admin))->keyBy('id');

    expect($righe[$sc['id']['c_bor']]['puo_rispondere'])->toBeFalse()
        ->and($righe[$sc['id']['s_pub']]['puo_rispondere'])->toBeFalse()
        ->and($righe[$sc['id']['p_mario']]['puo_rispondere'])->toBeTrue();
});

test('«Rispondi» di una comunicazione a più palazzi scrive nella discussione unica, con il palazzo di chi risponde', function () {
    $sc = cpScenario($this);
    $righe = collect(cpRighe($this, $this->admin))->keyBy('id');
    $rispondi = $righe[$sc['id']['c_bor']]['rispondi'];

    // Si risponde al commento di Borea nella discussione unica (decisione 458): il commento nuovo porta il palazzo dell'amministratore,
    // che ha un'anagrafica in Aurora e in Borea, il primo per nome.
    expect($rispondi['dati'])->toBe(['discussione' => ccmChiaveUnica()]);
    $this->actingAs($this->admin)->post($rispondi['url'], $rispondi['dati'] + ['corpo' => 'Confermato per lunedì'])->assertRedirect();

    $nuovo = Commento::query()->where('corpo', 'Confermato per lunedì')->firstOrFail();
    expect($nuovo->commentable_id)->toBe($sc['c']->id)
        ->and($nuovo->condominio_id)->toBe($this->aurora->id)
        ->and($nuovo->discussione_user_id)->toBeNull()
        ->and($nuovo->user_id)->toBe($this->admin->id);
});

test('«Rispondi» di una comunicazione a persone scrive nella discussione di quella persona; di una segnalazione, nella segnalazione', function () {
    $sc = cpScenario($this);
    $righe = collect(cpRighe($this, $this->admin))->keyBy('id');

    $persona = $righe[$sc['id']['p_lucia']]['rispondi'];
    expect($persona['dati'])->toBe(['discussione' => 'persona:' . $this->lucia->id]);
    $this->actingAs($this->admin)->post($persona['url'], $persona['dati'] + ['corpo' => 'Risposta a Lucia'])->assertRedirect();
    $nuovo = Commento::query()->where('corpo', 'Risposta a Lucia')->firstOrFail();
    expect($nuovo->commentable_id)->toBe($sc['p']->id)->and($nuovo->discussione_user_id)->toBe($this->lucia->id);

    $segnalazione = $righe[$sc['id']['s_pub']]['rispondi'];
    expect($segnalazione['dati'])->toBe([]);
    $this->actingAs($this->admin)->post($segnalazione['url'], ['corpo' => 'Passiamo domani'])->assertRedirect();
    $nuovo = Commento::query()->where('corpo', 'Passiamo domani')->firstOrFail();
    expect($nuovo->commentable_id)->toBe($sc['s']->id)->and($nuovo->condominio_id)->toBe($this->aurora->id);
});

test('le richieste al database non crescono con le righe', function () {
    $righe = function (int $da, int $a) {
        foreach (range($da, $a) as $n) {
            $s = ccmSegnalazione($this->aurora, $this->mario, ['subject' => "Guasto {$n}"]);
            ccmCommentoSalvato($s, $this->mario, "Commento alla segnalazione {$n}", $this->aurora);
            $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => "Avviso {$n}"]);
            ccmCommentoSalvato($c, $this->mario, "Commento all'avviso {$n}", $this->aurora, null, 'in_attesa');
            $p = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => "Avviso personale {$n}"], [$this->mario, $this->lucia]);
            ccmCommentoSalvato($p, $this->lucia, "Commento personale {$n}", $this->borea, $this->lucia);
        }
    };
    $conta = function ($chi): int {
        $this->actingAs($chi)->get(route('admin.commenti.index', ['per_page' => 100]))->assertOk(); // scalda le cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $risposta = $this->actingAs($chi)->get(route('admin.commenti.index', ['per_page' => 100]))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(count($risposta->viewData('page')['props']['commenti']['data']))->toBeGreaterThan(0);

        return $n;
    };

    $righe(1, 4);       // 12 commenti
    $admin12 = $conta($this->admin);
    $collab12 = $conta($this->collaboratrice);
    $righe(5, 10);      // 30 commenti
    $admin30 = $conta($this->admin);
    $collab30 = $conta($this->collaboratrice);

    expect($admin30)->toBe($admin12, "Lo studio fa {$admin12} richieste con 12 commenti e {$admin30} con 30")
        ->and($collab30)->toBe($collab12, "La collaboratrice fa {$collab12} richieste con 12 commenti e {$collab30} con 30");
});
