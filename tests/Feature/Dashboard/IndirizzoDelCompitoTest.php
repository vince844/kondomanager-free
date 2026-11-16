<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il collegamento di un compito non dipende dall'indirizzo del sito con cui è stato scritto. Un compito salvato con l'indirizzo intero
 * («https://kondomanager-free.test/admin/…», o «http://localhost/…» se è nato da un comando) porta alla pagina giusta anche dopo un cambio di indirizzo: alla
 * lettura si tengono solo il percorso, la query e l'ancora. I compiti nuovi salvano il percorso. Niente si riscrive nel database.
 */

use App\Models\Evento;
use App\Services\Gestionale\Inbox\IndirizzoDelCompito;
use App\Services\Gestionale\InboxService;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Un compito salvato con l'indirizzo dato in `meta.action_url`, come era scritto prima. */
function idcSalvato($test, string $tipo, string $indirizzo, string $titolo): Evento
{
    $e = icsCompito($test, $tipo, [], $titolo);
    Evento::whereKey($e->id)->update(['meta' => json_encode(array_merge($e->meta, ['action_url' => $indirizzo]))]);

    return $e->fresh();
}

it('dall\'indirizzo intero restano il percorso, la query e l\'ancora; un percorso resta com\'è', function () {
    expect(IndirizzoDelCompito::percorso('https://kondomanager-free.test/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3&related_task_id=9#riga'))
        ->toBe('/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3&related_task_id=9#riga')
        ->and(IndirizzoDelCompito::percorso('http://localhost/admin/gestionale/2/fatture?search=12'))->toBe('/admin/gestionale/2/fatture?search=12')
        ->and(IndirizzoDelCompito::percorso('http://localhost:8001/admin/inbox'))->toBe('/admin/inbox')
        ->and(IndirizzoDelCompito::percorso('/admin/gestionale/2/rate-scadute'))->toBe('/admin/gestionale/2/rate-scadute')
        ->and(IndirizzoDelCompito::percorso('admin/gestionale/2/rate-scadute'))->toBe('/admin/gestionale/2/rate-scadute')
        ->and(IndirizzoDelCompito::percorso(null))->toBeNull()
        ->and(IndirizzoDelCompito::percorso(''))->toBeNull()
        // I compiti delle quote non assegnate e dell'eccedenza erano salvati senza «/admin»: la pagina non c'era.
        ->and(IndirizzoDelCompito::percorso('/gestionale/4/esercizi/7/piani-rate/12'))->toBe('/admin/gestionale/4/esercizi/7/piani-rate/12');
});

it('nelle due Inbox il compito salvato con «https://kondomanager-free.test/admin/…» e quello con «http://localhost/…» portano al percorso', function () {
    $test = idcSalvato($this, 'controllo_incassi', 'https://kondomanager-free.test/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3#riga', 'Dal dominio di prova');
    $comando = idcSalvato($this, 'pagamento_fornitore', 'http://localhost/admin/gestionale/2/pagamenti-fornitori/create?fattura_id=12', 'Da un comando');

    $casella = collect($this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props']['tasks']['data'])->keyBy('title');
    expect($casella['Dal dominio di prova']['azione']['url'])->toBe('/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3#riga')
        ->and($casella['Dal dominio di prova']['context']['action_url'])->toBe('/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3#riga')
        ->and($casella['Da un comando']['azione']['url'])->toBe('/admin/gestionale/2/pagamenti-fornitori/create?fattura_id=12');

    $condominio = collect($this->actingAs($this->admin)->get(route('admin.gestionale.index', $this->condominio))->viewData('page')['props']['inboxTasks']['data'])->keyBy('title');
    expect($condominio['Dal dominio di prova']['action_url'])->toBe('/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3#riga')
        ->and($condominio['Da un comando']['action_url'])->toBe('/admin/gestionale/2/pagamenti-fornitori/create?fattura_id=12');

    // Il database non si riscrive.
    expect($test->fresh()->meta['action_url'])->toBe('https://kondomanager-free.test/admin/gestionale/4/movimenti-rate/create?prefill_rata_id=3#riga')
        ->and($comando->fresh()->meta['action_url'])->toBe('http://localhost/admin/gestionale/2/pagamenti-fornitori/create?fattura_id=12');
});

it('i compiti nuovi e i promemoria salvano il percorso, non l\'indirizzo intero', function () {
    $compito = InboxService::createTask(tipo: \App\Enums\EventoTipo::EMISSIONE_RATA, title: 'Emettere rata 2', description: 'Rata 2', scadenza: now(),
        createdByUserId: $this->admin->id, condominioId: $this->condominio->id,
        actionUrl: route('admin.gestionale.rate-scadute.index', ['condominio' => $this->condominio->id, 'ordine' => 'sei_mesi']));
    $promemoria = InboxService::creaPromemoria('Ricordamelo', 'Nota', now()->addDay(), $this->admin->id, $this->condominio->id,
        route('admin.inbox', ['condominio_id' => $this->condominio->id]));

    expect($compito->meta['action_url'])->toBe(route('admin.gestionale.rate-scadute.index', ['condominio' => $this->condominio->id, 'ordine' => 'sei_mesi'], false))
        ->and($compito->meta['action_url'])->toStartWith('/admin/')
        ->and($promemoria->meta['action_url'])->toBe('/admin/inbox?condominio_id=' . $this->condominio->id);
});

it('un collegamento salvato con la barra rovesciata, una tabulazione o un a capo resta un percorso del sito, non porta a un altro sito', function () {
    // Il browser legge «\» come «/» e toglie le tabulazioni e gli a capo: «/\altro.example» diventerebbe «//altro.example», un altro sito.
    $vie = ['/\\altro.example' => '/altro.example', '\\\\altro.example' => '/altro.example', 'https://kondomanager-free.test/\\altro.example' => '/altro.example',
        '/\\/altro.example' => '/altro.example',
        // Senza la tabulazione o l'a capo è «//altro.example»: un altro sito, di cui non si tiene niente, e si resta nel sito.
        "/\t/altro.example" => '/', "/\n/altro.example/x" => '/x',
        '//altro.example/x' => '/x', 'javascript:alert(1)' => '/alert(1)'];

    foreach ($vie as $salvato => $atteso) {
        $link = IndirizzoDelCompito::perIlLink($salvato);
        $come = json_encode($salvato);
        expect($link)->toBe($atteso, $come)
            ->and(preg_match('#^/(?![/\\\\])#', $link))->toBe(1, $come)
            ->and(preg_match('/[\t\n\r]/', $link))->toBe(0, $come);
    }

    // Nelle due Inbox il compito salvato così porta al percorso.
    idcSalvato($this, 'controllo_incassi', '/\\altro.example', 'Con la barra rovesciata');
    $casella = collect($this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props']['tasks']['data'])->keyBy('title');
    expect($casella['Con la barra rovesciata']['azione']['url'])->toBe('/altro.example')
        ->and($casella['Con la barra rovesciata']['context']['action_url'])->toBe('/altro.example');
});
