<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Una comunicazione ai palazzi ha una discussione sola (decisione 458)
 *
 * - Mandata a uno o più palazzi, la comunicazione ha una discussione unica (`tutti`), che leggono e dove scrivono tutti i
 *   destinatari che la aprono, di qualunque palazzo, e lo studio: nessun titolo di condominio sopra. Chi sta in due palazzi
 *   la vede una volta. Un palazzo tolto dai destinatari porta via i commenti scritti da lì (decisione 459, `PalazzoToltoTest`).
 * - Il commento nuovo porta il palazzo di chi scrive fra i destinatari (il primo per nome); lo studio senza un'anagrafica
 *   fra i destinatari lo lascia vuoto, e il commento si vede, si conta e si modera lo stesso.
 * - Il condominio di chi scrive lo vede solo lo studio, e solo quando i palazzi sono più di uno: il portale non lo riceve.
 * - Le email «nuovo commento» vanno a chi partecipa alla discussione unica, di qualunque palazzo, se la vede.
 * - Una comunicazione a persone, o privata, resta una discussione per persona con lo studio (decisione 458 (a)).
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Notifications\Commenti\NuovoCommentoNotification;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

/** I contatori della riga della comunicazione nell'elenco della porta data. */
function ducElenco($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $props = json_decode(json_encode(
        $test->actingAs($user)->get(route($porta . '.comunicazioni.index'))->assertOk()->viewData('page')['props']
    ), true);
    $righe = $porta === 'user' ? $props['comunicazioni']['data'] : $props['comunicazioni'];
    $riga = collect($righe)->firstWhere('id', $c->id);
    expect($riga)->not->toBeNull("La comunicazione {$c->id} non è nell'elenco di {$user->name}");

    return $riga['contatori'];
}

/** I testi dei commenti della discussione unica nella scheda, in ordine. */
function ducCorpi($test, User $user, Comunicazione $c, string $porta = 'user'): array
{
    $discussione = ccmDiscussione(ccmScheda($test, $user, $c, $porta) ?? [], ccmChiaveUnica());
    expect($discussione)->not->toBeNull("{$user->name} non vede la discussione unica");

    return collect($discussione['commenti'])->pluck('corpo')->all();
}

test('il condòmino di Borea e quello di Aurora vedono e scrivono nella stessa discussione; chi sta in due palazzi la vede una volta', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);

    foreach ([$this->mario, $this->lucia, $this->paolo] as $chi) {
        expect(ccmChiaviInScheda($this, $chi, $c))->toBe([ccmChiaveUnica()], "Le discussioni di {$chi->name}");
    }
    expect(ccmChiaviInScheda($this, $this->admin, $c, 'admin'))->toBe([ccmChiaveUnica()]);

    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Da Aurora: a che ora?')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->lucia, $c, ccmChiaveUnica(), 'Da Borea: alle otto')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->paolo, $c, ccmChiaveUnica(), 'Da tutti e due: grazie')->assertSessionHasNoErrors();

    // Il palazzo di chi scrive fra i destinatari; chi sta in due, il primo per nome. Nessuna persona.
    $commenti = ccmCommenti($c);
    expect($commenti->pluck('condominio_id')->all())->toBe([$this->aurora->id, $this->borea->id, $this->aurora->id])
        ->and($commenti->pluck('discussione_user_id')->filter()->all())->toBe([]);

    $tutti = ['Da Aurora: a che ora?', 'Da Borea: alle otto', 'Da tutti e due: grazie'];
    foreach ([[$this->mario, 'user'], [$this->lucia, 'user'], [$this->paolo, 'user'], [$this->admin, 'admin']] as [$chi, $porta]) {
        $discussione = ccmDiscussione(ccmScheda($this, $chi, $c, $porta), ccmChiaveUnica());
        expect(collect($discussione['commenti'])->pluck('corpo')->all())->toBe($tutti, "I commenti che legge {$chi->name}")
            // Nessun titolo di condominio sopra la discussione, né nel portale né nel pannello.
            ->and($discussione['titolo'])->toBe('', "Il titolo della discussione di {$chi->name}")
            ->and($discussione['puo_scrivere'])->toBeTrue();
    }
});

test('un commento sparisce dalla discussione quando il palazzo del suo autore è tolto dai destinatari (decisione 459), e chi è stato tolto non la vede più', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    ccmCommentoSalvato($c, $this->lucia, 'Da Borea, prima', $this->borea);
    ccmCommentoSalvato($c, $this->mario, 'Da Aurora', $this->aurora);

    $c->condomini()->detach($this->borea->id);

    expect(ducCorpi($this, $this->mario, $c))->toBe(['Da Aurora'])
        ->and(ducCorpi($this, $this->admin, $c, 'admin'))->toBe(['Da Aurora'])
        ->and(ccmScheda($this, $this->lucia, $c))->toBeNull();
});

test('a persone o privata: due destinatari non vedono l\'uno i commenti dell\'altro, e la discussione unica non c\'è', function () {
    $persone = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => 'Il suo sollecito'], [$this->mario, $this->lucia]);
    ccmScrivi($this, $this->mario, $persone, ccmChiavePersona($this->mario), 'Mario: ho pagato ieri')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->lucia, $persone, ccmChiavePersona($this->lucia), 'Lucia: pago domani')->assertSessionHasNoErrors();

    $mario = ccmScheda($this, $this->mario, $persone);
    $lucia = ccmScheda($this, $this->lucia, $persone);
    expect(collect($mario['discussioni'])->pluck('chiave')->all())->toBe([ccmChiavePersona($this->mario)])
        ->and(collect($lucia['discussioni'])->pluck('chiave')->all())->toBe([ccmChiavePersona($this->lucia)])
        ->and(json_encode($mario, JSON_UNESCAPED_UNICODE))->not->toContain('Lucia: pago domani')
        ->and(json_encode($mario, JSON_UNESCAPED_UNICODE))->not->toContain('Lucia Verdi')
        ->and(json_encode($lucia, JSON_UNESCAPED_UNICODE))->not->toContain('Mario: ho pagato ieri')
        ->and(json_encode($lucia, JSON_UNESCAPED_UNICODE))->not->toContain('Mario Rossi');

    // La chiave della discussione unica, su una comunicazione a persone o privata, è un rifiuto: anche per lo studio.
    $privata = ccmComunicazione([$this->aurora], $this->mario, ['is_private' => true, 'subject' => 'Infiltrazione in cantina']);
    $prove = [
        [$this->mario, $persone, 'user'],
        [$this->admin, $persone, 'admin'],
        [$this->mario, $privata, 'user'],
        [$this->admin, $privata, 'admin'],
    ];
    foreach ($prove as [$chi, $comunicazione, $porta]) {
        $risposta = ccmScrivi($this, $chi, $comunicazione, ccmChiaveUnica(), 'Per tutti', $porta);
        expect(ccmRifiutato($risposta))->toBeTrue("{$chi->name} su «{$comunicazione->subject}»: {$risposta->status()}");
    }
    expect(ccmCommenti($persone))->toHaveCount(2)
        ->and(ccmCommenti($privata))->toHaveCount(0)
        ->and(ccmChiaviInScheda($this, $this->mario, $privata))->toBe([ccmChiavePersona($this->mario)]);
});

test('lo studio vede il condominio accanto all\'autore quando i palazzi sono più di uno, e non con uno solo; il portale non lo riceve', function () {
    $cirro = Condominio::factory()->create(['nome' => 'Condominio Cirro']);
    $giorgio = ccmUtente([$this->aurora->id, $this->borea->id, $cirro->id], 'Giorgio Tre Palazzi');
    $studio = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');
    $c = ccmComunicazione([$this->aurora, $this->borea, $cirro], $this->admin);
    $mario = ccmCommentoSalvato($c, $this->mario, 'Da Aurora', $this->aurora);
    $lucia = ccmCommentoSalvato($c, $this->lucia, 'Da Borea', $this->borea);
    // Il condominio del commento viene per primo, gli altri seguono per nome.
    $suo = ccmCommentoSalvato($c, $giorgio, 'Da Cirro, con tre palazzi', $cirro);
    $delloStudio = ccmCommentoSalvato($c, $studio, 'Dallo studio', null);

    $condomini = fn (User $chi, string $porta = 'admin') => collect(ccmDiscussione(ccmScheda($this, $chi, $c, $porta), ccmChiaveUnica())['commenti'])
        ->mapWithKeys(fn ($commento) => [$commento['id'] => $commento['condomini'] ?? 'assente'])->all();

    expect($condomini($this->admin))->toBe([
        $mario => ['Condominio Aurora'],
        $lucia => ['Condominio Borea'],
        $suo => ['Condominio Cirro', 'Condominio Aurora', 'Condominio Borea'],
        $delloStudio => [],
    ])
        // Anche chi è dello studio e non modera.
        ->and($condomini($this->collaboratrice)[$suo])->toBe(['Condominio Cirro', 'Condominio Aurora', 'Condominio Borea']);

    // Il portale: né il campo né i nomi degli altri condomìni.
    $portale = ccmScheda($this, $this->mario, $c);
    foreach (ccmDiscussione($portale, ccmChiaveUnica())['commenti'] as $commento) {
        expect($commento)->not->toHaveKey('condomini');
    }
    expect(json_encode($portale, JSON_UNESCAPED_UNICODE))->not->toContain('Condominio Borea')
        ->and(json_encode($portale, JSON_UNESCAPED_UNICODE))->not->toContain('Condominio Cirro');

    // Un palazzo solo: lo studio non lo vede accanto all'autore.
    $unoSolo = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Solo Aurora']);
    ccmCommentoSalvato($unoSolo, $this->mario, 'Da Aurora, da solo', $this->aurora);
    foreach (ccmDiscussione(ccmScheda($this, $this->admin, $unoSolo, 'admin'), ccmChiaveUnica())['commenti'] as $commento) {
        expect($commento)->not->toHaveKey('condomini');
    }
});

test('l\'email «nuovo commento» arriva al partecipante di un altro palazzo, non a chi non vede la comunicazione', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    $cirro = Condominio::factory()->create(['nome' => 'Condominio Cirro']);
    $gino = ccmUtente([$cirro->id], 'Gino Tolto');
    // Gino aveva scritto quando Cirro era fra i destinatari: il palazzo è stato tolto, e lui non vede più la comunicazione.
    ccmCommentoSalvato($c, $gino, 'Da Cirro, prima', $cirro);
    ccmCommentoSalvato($c, $this->lucia, 'Da Borea', $this->borea);

    ccmScrivi($this, $this->admin, $c, ccmChiaveUnica(), 'Il cantiere apre alle 8', 'admin')->assertSessionHasNoErrors();

    // Lo studio scrive: Lucia, che ha scritto da Borea, lo riceve; Gino no. La collaboratrice è dello studio di Aurora.
    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->collaboratrice, $this->lucia));

    // L'approvazione del commento di Mario (Aurora) avvisa chi partecipa da Borea, non chi non vede.
    Notification::fake();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Da Aurora: si può spostare?')->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', ccmCommenti($c)->last()->id))->assertSessionHasNoErrors();

    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->collaboratrice, $this->lucia));
});

test('lo studio che l\'email avvisa è quello dei palazzi della discussione: di Borea per un commento da Aurora, mai di un palazzo che non è fra i destinatari', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    $cirro = Condominio::factory()->create(['nome' => 'Condominio Cirro']);
    $studioBorea = ccmUtente([$this->borea->id], 'Studio Borea', \App\Enums\Role::AMMINISTRATORE->value);
    $studioCirro = ccmUtente([$cirro->id], 'Studio Cirro', \App\Enums\Role::AMMINISTRATORE->value);
    $studioSenza = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');

    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Da Aurora')->assertSessionHasNoErrors();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->admin, $this->collaboratrice, $studioBorea));

    // Lo studio senza un'anagrafica fra i destinatari risponde: il commento non ha un condominio, e l'avviso va allo studio dei
    // palazzi della discussione e a chi partecipa, non a tutto il pannello.
    Notification::fake();
    ccmScrivi($this, $studioSenza, $c, ccmChiaveUnica(), 'Risponde lo studio', 'admin')->assertSessionHasNoErrors();
    expect(ccmDestinatari(NuovoCommentoNotification::class))->toBe(ccmIds($this->admin, $this->collaboratrice, $studioBorea, $this->mario))
        ->and(ccmDestinatari(NuovoCommentoNotification::class))->not->toContain($studioCirro->id);
});

test('il commento dello studio senza anagrafica fra i destinatari ha il condominio vuoto, e si vede, si conta, si modera', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    $studio = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');

    ccmScrivi($this, $studio, $c, ccmChiaveUnica(), 'Il cantiere apre alle 8', 'admin')->assertSessionHasNoErrors();
    $commento = ccmCommenti($c)->sole();
    expect($commento->condominio_id)->toBeNull()
        ->and($commento->discussione_user_id)->toBeNull()
        ->and($commento->stato)->toBe('pubblicato');

    // Si vede, dai condòmini dei due palazzi e dallo studio.
    expect(ducCorpi($this, $this->mario, $c))->toBe(['Il cantiere apre alle 8'])
        ->and(ducCorpi($this, $this->lucia, $c))->toBe(['Il cantiere apre alle 8'])
        ->and(ducCorpi($this, $this->admin, $c, 'admin'))->toBe(['Il cantiere apre alle 8']);

    // Si conta, nell'elenco del portale e del pannello.
    expect(ducElenco($this, $this->lucia, $c)['commenti'])->toBe(1)
        ->and(ducElenco($this, $this->admin, $c, 'admin')['commenti'])->toBe(1);

    // Si modera: chi dello studio scrive senza poter pubblicare lascia un commento in attesa, senza condominio.
    $ruolo = ccmRuolo('Studio che scrive', ['Accesso pannello amministratore', 'Visualizza comunicazioni', CCM_PERMESSI['visualizza'], CCM_PERMESSI['commenta']]);
    $scrive = ccmStudioSenzaAnagrafica('Studio Che Scrive', $ruolo);
    ccmScrivi($this, $scrive, $c, ccmChiaveUnica(), 'Da approvare', 'admin')->assertSessionHasNoErrors();
    $inAttesa = ccmCommenti($c)->last();
    expect($inAttesa->stato)->toBe('in_attesa')->and($inAttesa->condominio_id)->toBeNull();

    $compito = Evento::where('tipo', 'da_moderare')->where('is_completed', false)->where('eventable_id', $c->id)->sole();
    $pagina = $this->actingAs($this->admin)->get(route('admin.commenti.index', ['tipo' => 'comunicazioni']))->assertOk()->viewData('page')['props'];
    $nellaScheda = collect(ccmDiscussione(ccmScheda($this, $this->admin, $c, 'admin'), ccmChiaveUnica())['commenti'])->firstWhere('id', $inAttesa->id);
    expect((int) $compito->meta['commenti_in_attesa'])->toBe(1)
        ->and(collect($pagina['commenti']['data'])->pluck('id')->sort()->values()->all())->toBe([$commento->id, $inAttesa->id])
        ->and($nellaScheda['azioni']['approva'])->toBeTrue()
        ->and(ducElenco($this, $this->admin, $c, 'admin')['in_attesa'])->toBe(1);

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $inAttesa->id))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $commento->id))->assertSessionHasNoErrors();
    expect(Commento::find($inAttesa->id)->stato)->toBe('pubblicato')
        ->and(Commento::find($commento->id)->stato)->toBe('nascosto');
});

test('il contatore del portale e quello della scheda dicono lo stesso numero per il condòmino di Borea', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    $studio = ccmStudioSenzaAnagrafica('Studio Senza Anagrafica');
    ccmCommentoSalvato($c, $this->mario, 'Aurora uno', $this->aurora);
    ccmCommentoSalvato($c, $this->paolo, 'Aurora due', $this->aurora);
    ccmCommentoSalvato($c, $this->lucia, 'Borea uno', $this->borea);
    ccmCommentoSalvato($c, $studio, 'Dello studio', null);
    ccmCommentoSalvato($c, $this->mario, 'In attesa di Mario', $this->aurora, null, 'in_attesa');
    ccmCommentoSalvato($c, $this->lucia, 'Nascosto di Lucia', $this->borea, null, 'nascosto');

    // Lucia conta anche i commenti di Aurora e dello studio; non l'in attesa di Mario né il nascosto.
    $scheda = collect(ccmScheda($this, $this->lucia, $c)['discussioni'])->sum(fn ($d) => count($d['commenti']));
    expect(ducElenco($this, $this->lucia, $c)['commenti'])->toBe(4)
        ->and($scheda)->toBe(4)
        // Mario aggiunge il suo in attesa.
        ->and(ducElenco($this, $this->mario, $c)['commenti'])->toBe(5);
});
