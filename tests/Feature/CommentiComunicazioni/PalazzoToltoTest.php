<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Un palazzo tolto dai destinatari porta via i commenti scritti da lì (decisione 459)
 *
 * - Nella discussione unica di una comunicazione ai palazzi (decisione 458) un commento si vede finché il suo palazzo è fra i
 *   destinatari, o non ha un palazzo (lo studio senza un'anagrafica fra i destinatari), o chi l'ha scritto ha ancora
 *   un'anagrafica in un palazzo destinatario (chi sta in due palazzi: vede ancora la comunicazione, e il suo commento resta).
 *   Altrimenti non si vede, per nessuno: né nella scheda, del portale e del pannello, né nei contatori, né nella pagina
 *   «Commenti», né nel compito «Da moderare», né nelle email che partono dopo.
 * - Il commento che non si vede non si cancella: rimesso il palazzo fra i destinatari, torna con la sua data.
 * - La regola è una sola, in `RegoleCommenti`, scritta due volte: per un commento e per una richiesta. L'ultima prova le
 *   confronta su ogni commento.
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Notifications\Commenti\CommentoModificatoInAttesaNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

/** Lo studio cambia i palazzi destinatari dal modulo della comunicazione, come `ComunicazioniEdit.vue`. */
function ptlDestinatari($test, Comunicazione $c, array $palazzi): void
{
    $c = $c->fresh();
    $test->actingAs($test->admin)->put(route('admin.comunicazioni.update', $c), [
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority,
        'can_comment' => (bool) $c->can_comment, 'is_featured' => (bool) $c->is_featured, 'is_published' => (bool) $c->is_published,
        'condomini_ids' => array_map(fn (Condominio $k) => $k->id, $palazzi), 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();
}

/** I testi dei commenti della discussione unica nella scheda, in ordine. */
function ptlCorpi($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $discussione = ccmDiscussione(ccmScheda($test, $user, $c, $porta) ?? [], ccmChiaveUnica());
    expect($discussione)->not->toBeNull("{$user->name} non vede la discussione unica");

    return collect($discussione['commenti'])->pluck('corpo')->all();
}

/** Un commento della discussione unica come lo riceve la scheda, o `null`. */
function ptlInScheda($test, User $user, Comunicazione $c, int $id, string $porta = 'user'): ?array
{
    $discussione = ccmDiscussione(ccmScheda($test, $user, $c, $porta) ?? [], ccmChiaveUnica()) ?? ['commenti' => []];

    return collect($discussione['commenti'])->firstWhere('id', $id);
}

/** I contatori della riga della comunicazione nell'elenco della porta data. */
function ptlElenco($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $props = json_decode(json_encode(
        $test->actingAs($user)->get(route($porta . '.comunicazioni.index'))->assertOk()->viewData('page')['props']
    ), true);
    $righe = $porta === 'user' ? $props['comunicazioni']['data'] : $props['comunicazioni'];
    $riga = collect($righe)->firstWhere('id', $c->id);
    expect($riga)->not->toBeNull("La comunicazione {$c->id} non è nell'elenco di {$user->name}");

    return $riga['contatori'];
}

/** Gli id dei commenti della comunicazione nella pagina «Commenti» dello studio. */
function ptlPagina($test, Comunicazione $c): array
{
    $props = $test->actingAs($test->admin)
        ->get(route('admin.commenti.index', ['tipo' => 'comunicazioni', 'elemento' => $c->id]))
        ->assertOk()->viewData('page')['props'];

    return collect($props['commenti']['data'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

/** Il compito «Da moderare» aperto della comunicazione. */
function ptlCompito(Comunicazione $c): Evento
{
    return Evento::where('tipo', 'da_moderare')->where('is_completed', false)
        ->where('eventable_type', $c->getMorphClass())->where('eventable_id', $c->id)->sole();
}

/** I palazzi a cui è legato un compito, per id. */
function ptlCondominiDelCompito(Evento $compito): array
{
    return $compito->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

test('tolta Borea, il commento di Lucia sparisce per tutti, nella scheda, nei contatori e nella pagina «Commenti»; rimessa Borea, torna con la sua data', function () {
    $this->travelTo(Carbon::parse('2026-05-01 10:00', 'Europe/Rome')->utc());
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    ccmScrivi($this, $this->lucia, $c, ccmChiaveUnica(), 'Lucia: il mio vicino non paga')->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-05-02 10:00', 'Europe/Rome')->utc());
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Mario: a che ora?')->assertSessionHasNoErrors();
    $diLucia = ccmCommenti($c)->firstWhere('corpo', 'Lucia: il mio vicino non paga');
    $diMario = ccmCommenti($c)->firstWhere('corpo', 'Mario: a che ora?');
    $quando = ptlInScheda($this, $this->mario, $c, $diLucia->id)['created_at'];

    expect(ptlCorpi($this, $this->mario, $c))->toBe(['Lucia: il mio vicino non paga', 'Mario: a che ora?'])
        ->and(ptlElenco($this, $this->mario, $c)['commenti'])->toBe(2);

    $this->travelTo(Carbon::parse('2026-05-10 10:00', 'Europe/Rome')->utc());
    ptlDestinatari($this, $c, [$this->aurora]);

    // Mario non lo riceve più nella scheda, e il contatore del portale scende con la scheda.
    expect(ptlCorpi($this, $this->mario, $c))->toBe(['Mario: a che ora?'])
        ->and(ptlElenco($this, $this->mario, $c)['commenti'])->toBe(1)
        // Paolo vede ancora la comunicazione, da Aurora: il commento di Lucia nemmeno lui.
        ->and(ptlCorpi($this, $this->paolo, $c))->toBe(['Mario: a che ora?'])
        // Non si vede per nessuno: nemmeno per lo studio, nella scheda e nella riga dell'elenco del pannello.
        ->and(ptlCorpi($this, $this->admin, $c, 'admin'))->toBe(['Mario: a che ora?'])
        ->and(ptlElenco($this, $this->admin, $c, 'admin')['commenti'])->toBe(1)
        // La pagina «Commenti» non lo elenca.
        ->and(ptlPagina($this, $c))->toBe([$diMario->id])
        // Lucia non apre più la comunicazione; il commento non si cancella.
        ->and(ccmScheda($this, $this->lucia, $c))->toBeNull()
        ->and(Commento::find($diLucia->id)?->corpo)->toBe('Lucia: il mio vicino non paga');

    // Le azioni in blocco della pagina passano dalla pagina: il commento che non si vede è un rifiutato.
    $this->actingAs($this->admin)->post(route('admin.commenti.nascondi-in-blocco'), ['ids' => [$diLucia->id]]);
    expect(Commento::find($diLucia->id)->stato)->toBe('pubblicato');

    // Rimessa Borea: torna, con la sua data, per tutti.
    ptlDestinatari($this, $c, [$this->aurora, $this->borea]);
    expect(ptlCorpi($this, $this->mario, $c))->toBe(['Lucia: il mio vicino non paga', 'Mario: a che ora?'])
        ->and(ptlElenco($this, $this->mario, $c)['commenti'])->toBe(2)
        ->and(ptlCorpi($this, $this->lucia, $c))->toBe(['Lucia: il mio vicino non paga', 'Mario: a che ora?'])
        ->and(ptlInScheda($this, $this->mario, $c, $diLucia->id)['created_at'])->toBe($quando)
        ->and(Carbon::parse($quando)->setTimezone('Europe/Rome')->toDateString())->toBe('2026-05-01')
        ->and(ptlPagina($this, $c))->toBe([$diLucia->id, $diMario->id]);
});

test('il caso raro: Paolo sta in Aurora e in Borea; tolta Aurora, il suo commento resta per Lucia e lui lo corregge e lo elimina; quello di Mario, solo di Aurora, no', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    ccmScrivi($this, $this->paolo, $c, ccmChiaveUnica(), 'Paolo: grazie')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Mario: a che ora?')->assertSessionHasNoErrors();
    $diPaolo = ccmCommenti($c)->firstWhere('corpo', 'Paolo: grazie');
    // Il commento porta Aurora, il primo palazzo di Paolo per nome.
    expect((int) $diPaolo->condominio_id)->toBe($this->aurora->id);

    ptlDestinatari($this, $c, [$this->borea]);

    expect(ptlCorpi($this, $this->lucia, $c))->toBe(['Paolo: grazie'])
        ->and(ptlElenco($this, $this->lucia, $c)['commenti'])->toBe(1)
        ->and(ptlCorpi($this, $this->admin, $c, 'admin'))->toBe(['Paolo: grazie'])
        ->and(ptlElenco($this, $this->admin, $c, 'admin')['commenti'])->toBe(1)
        ->and(ptlPagina($this, $c))->toBe([$diPaolo->id])
        ->and(ccmScheda($this, $this->mario, $c))->toBeNull();

    // Paolo lo vede, e può correggerlo ed eliminarlo come prima.
    $suo = ptlInScheda($this, $this->paolo, $c, $diPaolo->id);
    expect($suo)->not->toBeNull()
        ->and($suo['azioni']['modifica'])->toBeTrue()
        ->and($suo['azioni']['elimina'])->toBeTrue();

    $this->actingAs($this->paolo)->patch(route('user.commenti.update', $diPaolo->id), ['corpo' => 'Paolo: grazie, corretto'])->assertSessionHasNoErrors();
    expect(ptlCorpi($this, $this->lucia, $c))->toBe(['Paolo: grazie, corretto']);

    $this->actingAs($this->paolo)->delete(route('user.commenti.destroy', $diPaolo->id))->assertSessionHasNoErrors();
    expect(Commento::find($diPaolo->id))->toBeNull()
        ->and(ptlCorpi($this, $this->lucia, $c))->toBe([]);
});

test('il commento dello studio senza condominio resta dopo aver tolto un palazzo; quello scritto da lì no', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    $studio = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');
    ccmScrivi($this, $studio, $c, ccmChiaveUnica(), 'Lo studio: il cantiere apre alle 8', 'admin')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->lucia, $c, ccmChiaveUnica(), 'Lucia: da Borea')->assertSessionHasNoErrors();
    $delloStudio = ccmCommenti($c)->firstWhere('corpo', 'Lo studio: il cantiere apre alle 8');
    expect($delloStudio->condominio_id)->toBeNull();

    ptlDestinatari($this, $c, [$this->aurora]);

    expect(ptlCorpi($this, $this->mario, $c))->toBe(['Lo studio: il cantiere apre alle 8'])
        ->and(ptlElenco($this, $this->mario, $c)['commenti'])->toBe(1)
        ->and(ptlCorpi($this, $this->admin, $c, 'admin'))->toBe(['Lo studio: il cantiere apre alle 8'])
        ->and(ptlPagina($this, $c))->toBe([$delloStudio->id]);
});

test('un commento in attesa da Borea: tolta Borea, il compito «Da moderare» non è più legato a Borea, il numero scende e, senza altro in attesa, si chiude; rimessa Borea, torna', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    ccmScrivi($this, $this->lucia, $c, ccmChiaveUnica(), 'Da Borea')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Da Aurora')->assertSessionHasNoErrors();
    $compito = ptlCompito($c);
    expect($compito->title)->toBe('2 commenti in attesa')
        ->and(ptlCondominiDelCompito($compito))->toBe(collect([$this->aurora->id, $this->borea->id])->sort()->values()->all());

    ptlDestinatari($this, $c, [$this->aurora]);

    $compito = ptlCompito($c);
    expect($compito->title)->toBe('1 commento in attesa')
        ->and((int) $compito->meta['commenti_in_attesa'])->toBe(1)
        ->and(ptlCondominiDelCompito($compito))->toBe([$this->aurora->id]);

    // Approvato quello di Aurora, non aspetta più niente che si veda: il compito si chiude.
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', Commento::where('corpo', 'Da Aurora')->sole()))->assertSessionHasNoErrors();
    expect(Evento::where('tipo', 'da_moderare')->where('is_completed', false)->count())->toBe(0)
        ->and(Commento::where('corpo', 'Da Borea')->sole()->stato)->toBe('in_attesa');

    // Rimessa Borea: il commento di Lucia torna, e con lui il compito, legato a Borea.
    ptlDestinatari($this, $c, [$this->aurora, $this->borea]);
    $compito = ptlCompito($c);
    expect($compito->title)->toBe('1 commento in attesa')
        ->and(ptlCondominiDelCompito($compito))->toBe([$this->borea->id]);
});

test('il caso raro nel compito: il commento in attesa di Paolo resta, e il compito si lega a Borea, non ad Aurora tolta', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    ccmScrivi($this, $this->paolo, $c, ccmChiaveUnica(), 'Paolo: in attesa')->assertSessionHasNoErrors();
    expect(ptlCondominiDelCompito(ptlCompito($c)))->toBe([$this->aurora->id]);

    ptlDestinatari($this, $c, [$this->borea]);

    $compito = ptlCompito($c);
    expect($compito->title)->toBe('1 commento in attesa')
        ->and(ptlCondominiDelCompito($compito))->toBe([$this->borea->id]);
});

test('un\'email «nuovo commento» su un commento di Borea, in coda, non parte se Borea è tolta prima dell\'invio', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    $id = ccmCommentoSalvato($c, $this->lucia, 'Lucia: da Borea', $this->borea);
    $parte = fn (User $chi) => (new NuovoCommentoNotification(Commento::find($id)))->shouldSend($chi->fresh(), 'mail');

    expect($parte($this->mario))->toBeTrue()
        ->and($parte($this->paolo))->toBeTrue()
        ->and($parte($this->admin))->toBeTrue();

    $c->condomini()->detach($this->borea->id);

    // Non si vede per nessuno, quindi l'email non parte per nessuno: né per chi sta in Aurora, né per chi sta anche in Borea, né per lo studio.
    expect($parte($this->mario))->toBeFalse('Mario non deve ricevere il commento di Borea tolta')
        ->and($parte($this->paolo))->toBeFalse('Paolo legge da Aurora: il commento di Borea tolta non si vede')
        ->and($parte($this->admin))->toBeFalse('Lo studio: il commento di Borea tolta non si vede nemmeno nel pannello');

    // Rimessa Borea, l'email partirebbe di nuovo.
    $c->condomini()->attach($this->borea->id);
    expect($parte($this->mario))->toBeTrue();
});

test('le email «in attesa» di un commento di Borea non partono se Borea è tolta prima dell\'invio, e chi ha scritto solo da lì non è più fra chi partecipa', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    $studioBorea = ccmUtente([$this->borea->id], 'Studio Borea', \App\Enums\Role::AMMINISTRATORE->value);
    ccmCommentoSalvato($c, $studioBorea, 'Lo studio di Borea', $this->borea);
    $id = ccmCommentoSalvato($c, $this->lucia, 'Lucia: in attesa', $this->borea, null, 'in_attesa');
    $attesa = fn (User $chi) => (new CommentoInAttesaNotification(Commento::find($id)))->shouldSend($chi->fresh(), 'mail');
    $modificato = fn (User $chi) => (new CommentoModificatoInAttesaNotification(Commento::find($id)))->shouldSend($chi->fresh(), 'mail');
    expect($attesa($this->admin))->toBeTrue()->and($modificato($this->admin))->toBeTrue();

    $c->condomini()->detach($this->borea->id);

    expect($attesa($this->admin))->toBeFalse('Il commento di Borea tolta non si vede: l\'email «in attesa» non deve partire')
        ->and($modificato($this->admin))->toBeFalse('Il commento di Borea tolta non si vede: l\'email «modificato, in attesa» non deve partire');

    // Mario scrive: lo studio di Borea aveva scritto solo da Borea, tolta; il suo commento non si vede e non lo fa più partecipare.
    Notification::fake();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Mario: da Aurora', 'user')->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', ccmCommenti($c)->last()->id))->assertSessionHasNoErrors();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($studioBorea->id)
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->collaboratrice));
});

test('la regola per un commento e quella per una richiesta dicono lo stesso, su ogni commento', function () {
    $cirro = Condominio::factory()->create(['nome' => 'Condominio Cirro']);
    $gino = ccmUtente([$cirro->id], 'Gino Cirro');
    $studio = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');
    $c = ccmComunicazione([$this->aurora, $this->borea, $cirro], $this->admin);
    $ids = [
        'mario' => ccmCommentoSalvato($c, $this->mario, 'Mario, da Aurora', $this->aurora),
        'lucia' => ccmCommentoSalvato($c, $this->lucia, 'Lucia, da Borea', $this->borea),
        'paolo' => ccmCommentoSalvato($c, $this->paolo, 'Paolo, da Aurora e in Borea', $this->aurora),
        'studio' => ccmCommentoSalvato($c, $studio, 'Lo studio, senza palazzo', null),
        'gino' => ccmCommentoSalvato($c, $gino, 'Gino, da Cirro', $cirro),
        'admin' => ccmCommentoSalvato($c, $this->admin, 'Lo studio, da Aurora e in Borea', $this->aurora),
        'nascosto' => ccmCommentoSalvato($c, $this->mario, 'Mario, nascosto', $this->aurora, null, 'nascosto'),
    ];
    $altra = ccmComunicazione([$cirro], $this->admin, ['subject' => 'Solo Cirro']);
    $diGino = ccmCommentoSalvato($altra, $gino, 'Gino, sulla sua', $cirro);

    $restano = function () use ($c, $altra): array {
        $query = Commento::query()->whereIn('commentable_id', [$c->id, $altra->id])->where('commentable_type', $c->getMorphClass());
        RegoleCommenti::soloQuelliCheRestanoFraIDestinatari($query);
        $perRichiesta = $query->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $perCommento = Commento::query()->whereIn('commentable_id', [$c->id, $altra->id])->where('commentable_type', $c->getMorphClass())->get()
            ->filter(fn (Commento $k) => RegoleCommenti::restaFraIDestinatari($k))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        expect($perCommento)->toBe($perRichiesta);

        return $perRichiesta;
    };

    $tutti = collect($ids)->values()->push($diGino)->sort()->values()->all();
    expect($restano())->toBe($tutti);

    // Restano Borea e la comunicazione a Cirro: via Mario e Gino (Aurora, Cirro), resta chi sta ancora in Borea e lo studio senza palazzo.
    $c->condomini()->sync([$this->borea->id]);
    expect($restano())->toBe(collect([$ids['lucia'], $ids['paolo'], $ids['studio'], $ids['admin'], $diGino])->sort()->values()->all());

    // Nessun palazzo: resta solo il commento senza palazzo (la discussione unica, poi, non c'è più).
    $c->condomini()->sync([]);
    expect($restano())->toBe(collect([$ids['studio'], $diGino])->sort()->values()->all());
});
