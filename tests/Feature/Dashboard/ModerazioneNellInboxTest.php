<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il gruppo «Da moderare» dell'Action inbox (decisioni 445 e 449): ciò che aspetta un'approvazione, un compito per elemento.
 *
 * - una segnalazione o una comunicazione con commenti o allegati in attesa ha un compito solo, con i numeri letti dal database;
 * - una comunicazione, un documento dell'archivio o un evento dell'agenda in attesa hanno il loro compito;
 * - il compito si chiude da solo quando non resta niente in attesa, da qualunque pagina, e si riapre con un arrivo nuovo;
 * - un commento pubblicato senza moderazione non crea un compito;
 * - il gruppo non scade: sta fuori dagli scaduti, dai «In arrivo» e dal grafico, e nell'elenco sta dopo le segnalazioni e i bonifici.
 */

use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';
require_once __DIR__.'/ModerazioneScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('un commento in attesa crea un compito solo, «1 commento in attesa», nel gruppo «Da moderare»', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin);

    $compito = mdAperti()->sole();
    $props = mdProps($this);
    $riga = mdRiga($props, $compito->id);

    expect($compito->title)->toBe('1 commento in attesa')
        ->and($compito->eventable_id)->toBe($s->id)
        ->and($riga['titolo'])->toBe('1 commento in attesa')
        ->and($riga['gruppo'])->toBe('da_moderare')
        ->and($riga['sotto'])->toBe('Segnalazione «Ascensore fermo»')
        ->and($riga['condominio'])->toBe('Condominio Aurora')
        ->and($props['counts']['da_moderare'])->toBe(1)
        ->and($props['gruppi']['da_moderare'])->toBe(1);
});

it('un allegato in attesa crea un compito, «1 allegato in attesa»', function () {
    $s = mdSegnalazione($this);
    mdAllegato($s, $this->admin);

    expect(mdAperti()->sole()->title)->toBe('1 allegato in attesa');
});

it('i numeri del titolo vengono dal database: commenti e allegati, singolare e plurale', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin);
    mdCommento($s, $this->admin);
    expect(mdAperti()->sole()->title)->toBe('2 commenti in attesa');

    mdAllegato($s, $this->admin);
    mdAllegato($s, $this->admin);
    expect(mdAperti()->sole()->title)->toBe('2 commenti e 2 allegati in attesa');

    Commento::where('commentable_id', $s->id)->first()->delete();
    expect(mdAperti()->sole()->title)->toBe('1 commento e 2 allegati in attesa');

    Commento::where('commentable_id', $s->id)->get()->each->delete();
    expect(mdAperti()->sole()->title)->toBe('2 allegati in attesa');
    Documento::where('documentable_id', $s->id)->first()->delete();
    expect(mdAperti()->sole()->title)->toBe('1 allegato in attesa');
});

it('il titolo a video segue la lingua di chi legge, con il singolare e il plurale', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin);
    mdAllegato($s, $this->admin);
    mdAllegato($s, $this->admin);
    $id = mdAperti()->sole()->id;

    foreach (['it' => '1 commento e 2 allegati in attesa', 'en' => '1 comment and 2 attachments awaiting approval',
        'es' => '1 comentario y 2 adjuntos pendientes', 'pt' => '1 comentário e 2 anexos pendentes'] as $lingua => $atteso) {
        $impostazioni = app(\App\Settings\GeneralSettings::class);
        $impostazioni->language = $lingua;
        $impostazioni->save();
        expect(mdRiga(mdProps($this), $id)['titolo'])->toBe($atteso);
    }
    app(\App\Settings\GeneralSettings::class)->language = 'it';
});

it('un commento pubblicato senza moderazione non crea nessun compito', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin, 'pubblicato');

    expect(Evento::whereIn('tipo', ['da_moderare', 'commento'])->count())->toBe(0)
        ->and(mdProps($this)['counts']['da_moderare'])->toBe(0);
});

it('il commento pubblicato dal servizio senza moderazione non crea un compito (decisione 445, punto 2)', function () {
    $s = mdSegnalazione($this);
    $this->admin->givePermissionTo(Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value);
    app(\App\Services\CommentoService::class)->crea($s, $this->admin, 'Ok, lo vedo domani', $this->condominio->id);

    expect(Evento::whereIn('tipo', ['da_moderare', 'commento'])->count())->toBe(0);
});

it('il compito si chiude approvando il commento dalla scheda', function () {
    $s = mdSegnalazione($this);
    $c = mdCommento($s, $this->admin);

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $c))->assertSessionHasNoErrors();

    expect(mdAperti())->toHaveCount(0)->and(Evento::where('tipo', 'da_moderare')->sole()->is_completed)->toBeTrue();
});

it('il compito si chiude dalla pagina «Commenti», in blocco: approvando ed eliminando; nascondere un commento pubblicato non lo tocca', function () {
    $s = mdSegnalazione($this);
    $uno = mdCommento($s, $this->admin);
    $due = mdCommento($s, $this->admin);
    $pubblicato = mdCommento($s, $this->admin, 'pubblicato');

    $this->actingAs($this->admin)->post(route('admin.commenti.nascondi-in-blocco'), ['ids' => [$pubblicato->id]])->assertSessionHasNoErrors();
    expect($pubblicato->fresh()->stato)->toBe('nascosto')->and(mdAperti()->sole()->title)->toBe('2 commenti in attesa');
    $this->actingAs($this->admin)->post(route('admin.commenti.approva-in-blocco'), ['ids' => [$uno->id]])->assertSessionHasNoErrors();
    expect(mdAperti()->sole()->title)->toBe('1 commento in attesa');
    $this->actingAs($this->admin)->post(route('admin.commenti.elimina-in-blocco'), ['ids' => [$due->id]])->assertSessionHasNoErrors();

    expect(mdAperti())->toHaveCount(0);
});

it('il compito si chiude anche nascondendo un commento che è tornato pubblicato dopo l\'approvazione, e dal servizio «modera» su uno in attesa', function () {
    $s = mdSegnalazione($this);
    $c = mdCommento($s, $this->admin);

    app(\App\Services\CommentoService::class)->modera($c, $this->admin);

    expect($c->fresh()->stato)->toBe('nascosto')->and(mdAperti())->toHaveCount(0);
});

it('il compito si chiude approvando gli allegati dalla scheda e dalla pagina «Allegati», in blocco', function () {
    $s = mdSegnalazione($this);
    $a1 = mdAllegato($s, $this->admin);
    $a2 = mdAllegato($s, $this->admin);
    $a3 = mdAllegato($s, $this->admin);

    $this->actingAs($this->admin)->post(route('admin.allegati.approva', $a1))->assertSessionHasNoErrors();
    expect(mdAperti()->sole()->title)->toBe('2 allegati in attesa');
    $this->actingAs($this->admin)->post(route('admin.allegati.approva-in-blocco'), ['ids' => [$a2->id, $a3->id]])->assertSessionHasNoErrors();

    expect(mdAperti())->toHaveCount(0);
});

it('il compito si chiude anche eliminando gli allegati in blocco, e quando la segnalazione sparisce', function () {
    $s = mdSegnalazione($this);
    $a = mdAllegato($s, $this->admin);
    $this->actingAs($this->admin)->post(route('admin.allegati.elimina-in-blocco'), ['ids' => [$a->id]])->assertSessionHasNoErrors();
    expect(mdAperti())->toHaveCount(0);

    $altra = mdSegnalazione($this, 'Cancello');
    mdCommento($altra, $this->admin);
    expect(mdAperti())->toHaveCount(1);
    $altra->delete();

    expect(mdAperti())->toHaveCount(0);
});

it('il compito si riapre con un commento nuovo in attesa, e non ci sono mai due compiti di moderazione per lo stesso elemento', function () {
    $s = mdSegnalazione($this);
    $c = mdCommento($s, $this->admin);
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $c))->assertSessionHasNoErrors();
    expect(mdAperti())->toHaveCount(0);

    mdCommento($s, $this->admin);
    mdAllegato($s, $this->admin);
    mdCommento($s, $this->admin);

    expect(mdAperti())->toHaveCount(1)
        ->and(mdAperti()->sole()->title)->toBe('2 commenti e 1 allegato in attesa')
        ->and(Evento::whereIn('tipo', ['da_moderare', 'commento', 'allegato'])->where('eventable_id', $s->id)->where('is_completed', false)->count())->toBe(1);
});

it('i compiti di prima «Nuovo commento» e «Allegati da approvare» confluiscono nel compito unico dell\'elemento', function () {
    $s = mdSegnalazione($this);
    $commento = \App\Services\Gestionale\InboxService::createTask(tipo: \App\Enums\EventoTipo::COMMENTO, title: 'Nuovo commento — Ascensore fermo', description: 'x',
        scadenza: now(), createdByUserId: $this->admin->id, condominioId: $this->condominio->id, eventableType: $s->getMorphClass(), eventableId: $s->id);
    $allegato = \App\Services\Gestionale\InboxService::createTask(tipo: \App\Enums\EventoTipo::ALLEGATO, title: 'Allegati da approvare — Ascensore fermo', description: 'x',
        scadenza: now(), createdByUserId: $this->admin->id, condominioId: $this->condominio->id, eventableType: $s->getMorphClass(), eventableId: $s->id);

    mdCommento($s, $this->admin);

    expect($commento->fresh()->is_completed)->toBeTrue()->and($allegato->fresh()->is_completed)->toBeTrue()
        ->and(mdAperti())->toHaveCount(1);
});

it('i servizi di prima non scrivono più i compiti «commento» e «allegato»', function () {
    $s = mdSegnalazione($this);
    $c = mdCommento($s, $this->admin);
    app(\App\Services\CommentoEventoService::class)->sincronizza($c);
    app(\App\Services\Allegati\AllegatoEventoService::class)->sincronizza($s, $this->admin);

    expect(Evento::whereIn('tipo', ['commento', 'allegato'])->count())->toBe(0)->and(mdAperti())->toHaveCount(1);
});

it('il giorno del compito è quello del primo commento o allegato ancora in attesa', function () {
    $s = mdSegnalazione($this);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-10 09:00', 'Europe/Rome')->utc());
    $primo = mdCommento($s, $this->admin);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-18 09:00', 'Europe/Rome')->utc());
    mdAllegato($s, $this->admin);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());

    $riga = mdRiga(mdProps($this), mdAperti()->sole()->id);
    expect($riga['giorno'])->toBe('2026-05-10')->and($riga['giorni'])->toBe(-10);

    $primo->delete();
    expect(mdRiga(mdProps($this), mdAperti()->sole()->id)['giorno'])->toBe('2026-05-18');
});

it('il gruppo non scade: sta fuori dagli scaduti, dalle schede, dal grafico e dalle fasce, anche con un giorno vecchio', function () {
    // Una segnalazione chiusa non ha il suo compito: resta solo quello da moderare.
    $s = mdSegnalazione($this, 'Ascensore fermo', ['stato' => 'chiusa']);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-02 09:00', 'Europe/Rome')->utc());
    mdCommento($s, $this->admin);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());

    $props = mdProps($this);

    expect($props['counts']['all'])->toBe(1)->and($props['counts']['da_moderare'])->toBe(1)
        ->and($props['counts']['scaduti'])->toBe(0)->and($props['counts']['settimana'])->toBe(0)->and($props['counts']['entro_30'])->toBe(0)
        ->and(array_sum(array_column($props['grafici']['eta'], 'valore')))->toBe(0)
        ->and($props['schede'])->toBe(['tutti' => 1, 'scaduti' => 0, 'in_arrivo' => 0])
        ->and($props['gruppi'])->toMatchArray(['scaduti' => 0, 'da_moderare' => 1, 'avanti' => 0])
        ->and($props['grafici']['fuori']['da_moderare'])->toBe(1);
    expect(mdProps($this, ['quando' => 'scaduti'])['tasks']['data'])->toBe([])
        ->and(mdProps($this, ['quando' => 'in_arrivo'])['tasks']['data'])->toBe([]);
});

it('la regola sta in FasceDeiCompiti: il tipo «da_moderare» è a parte, come le segnalazioni e i bonifici', function () {
    $fasce = new FasceDeiCompiti('2026-05-20');

    expect($fasce->fascia('2020-01-01', 'da_moderare'))->toBe('da_moderare')
        ->and(FasceDeiCompiti::gruppo('da_moderare'))->toBe('da_moderare')
        ->and(FasceDeiCompiti::GRUPPI)->toBe(['scaduti', 'segnalazioni', 'bonifici', 'da_moderare', 'avanti']);
});

it('nell\'elenco il gruppo sta dopo le segnalazioni e i bonifici, prima degli altri', function () {
    $s = mdSegnalazione($this, 'Guasto');
    mdCommento($s, $this->admin);
    icsBonifico($this);
    icsCompito($this, 'scadenza', [], 'Una scadenza vecchia');
    $avanti = icsCompito($this, 'scadenza', [], 'Una scadenza di domani');
    $avanti->update(['start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour()]);

    $props = mdProps($this);
    $ordine = collect($props['anteprima'])->pluck('gruppo')->all();

    expect($ordine)->toBe(['scaduti', 'segnalazioni', 'bonifici', 'da_moderare', 'avanti']);
    $elenco = mdProps($this, ['tipo' => 'da_moderare']);
    expect($elenco['tasks']['data'])->toHaveCount(1)->and($elenco['tasks']['data'][0]['gruppo'])->toBe('da_moderare');
});

it('il numero del menu conta il gruppo come conta le segnalazioni e i bonifici, qualunque sia il giorno', function () {
    $s = mdSegnalazione($this);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-25 09:00', 'Europe/Rome')->utc());
    mdCommento($s, $this->admin);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    Evento::where('tipo', 'da_moderare')->update(['start_time' => now()->addDays(5)]);

    // La segnalazione aperta ha il suo compito (1) e il gruppo nuovo il suo (1).
    expect(icsBarra($this))->toBe(2);
});

it('la prossima azione apre la pagina «Commenti» filtrata sull\'elemento, o la pagina «Allegati» se aspettano solo allegati', function () {
    $s = mdSegnalazione($this);
    $c = mdCommento($s, $this->admin);
    $riga = mdRiga(mdProps($this), mdAperti()->sole()->id);

    expect($riga['azione']['testo'])->toBe('Modera i commenti')
        ->and($riga['azione']['url'])->toBe('/admin/commenti?stato=in_attesa&tipo=segnalazioni&elemento=' . $s->id);

    $c->delete();
    mdAllegato($s, $this->admin);
    $riga = mdRiga(mdProps($this), mdAperti()->sole()->id);
    expect($riga['azione']['testo'])->toBe('Modera gli allegati')
        ->and($riga['azione']['url'])->toBe('/admin/allegati?stato=in_attesa&tipo=segnalazioni&elemento=' . $s->id);

    $com = mdComunicazione($this, 'Avviso acqua');
    mdCommento($com, $this->admin);
    $riga = mdRiga(mdProps($this), mdAperti()->last()->id);
    expect($riga['azione']['url'])->toBe('/admin/commenti?stato=in_attesa&tipo=comunicazioni&elemento=' . $com->id)
        ->and($riga['sotto'])->toBe('Comunicazione «Avviso acqua»');
});

it('la pagina «Commenti» e la pagina «Allegati» filtrano per elemento', function () {
    $uno = mdSegnalazione($this, 'Uno');
    $due = mdSegnalazione($this, 'Due');
    mdCommento($uno, $this->admin);
    mdCommento($due, $this->admin);
    mdAllegato($uno, $this->admin);
    mdAllegato($due, $this->admin);

    $commenti = $this->actingAs($this->admin)->get(route('admin.commenti.index', ['stato' => 'in_attesa', 'tipo' => 'segnalazioni', 'elemento' => $uno->id]))
        ->assertOk()->viewData('page')['props'];
    $allegati = $this->actingAs($this->admin)->get(route('admin.allegati.index', ['stato' => 'in_attesa', 'tipo' => 'segnalazioni', 'elemento' => $due->id]))
        ->assertOk()->viewData('page')['props'];

    expect($commenti['commenti']['data'])->toHaveCount(1)->and($commenti['commenti']['data'][0]['elemento'])->toBe('Uno')
        ->and($commenti['filtri']['elemento'])->toBe($uno->id)
        ->and($allegati['allegati']['data'])->toHaveCount(1)
        ->and($allegati['filtri']['elemento'])->toBe($due->id);
});

it('una comunicazione in attesa ha il suo compito «Comunicazione da approvare», che si chiude approvandola', function () {
    $com = mdComunicazione($this, 'Lavori in cortile', false);

    $compito = mdAperti()->sole();
    $riga = mdRiga(mdProps($this), $compito->id);
    expect($compito->title)->toBe('Comunicazione da approvare')
        ->and($riga['sotto'])->toBe('Comunicazione «Lavori in cortile»')
        ->and($riga['azione']['testo'])->toBe('Approva la comunicazione')
        ->and($riga['azione']['url'])->toBe('/admin/comunicazioni/' . $com->id);

    mdCommento($com, $this->admin);
    expect(mdAperti()->sole()->title)->toBe('Comunicazione da approvare, 1 commento in attesa');

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $com))->assertSessionHasNoErrors();
    expect(mdAperti()->sole()->title)->toBe('1 commento in attesa');
});

it('un documento dell\'archivio in attesa ha il suo compito, e si chiude approvandolo; un allegato non è un documento dell\'archivio', function () {
    $d = Documento::create(['name' => 'Regolamento', 'path' => 'documenti/r.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => false]);
    $d->condomini()->attach($this->condominio->id);
    $d->touch();

    $compito = mdAperti()->sole();
    $riga = mdRiga(mdProps($this), $compito->id);
    expect($compito->title)->toBe('Documento da approvare — Regolamento')
        ->and($riga['azione']['testo'])->toBe('Approva il documento')
        ->and($riga['azione']['url'])->toBe('/admin/documenti?search=Regolamento');

    $this->actingAs($this->admin)->put(route('admin.documenti.toggle-approval', $d))->assertSessionHasNoErrors();
    expect(mdAperti())->toHaveCount(0);

    // Un allegato in attesa non è un documento dell'archivio: il compito è dell'elemento, non suo.
    $s = mdSegnalazione($this);
    mdAllegato($s, $this->admin);
    expect(mdAperti()->sole()->title)->toBe('1 allegato in attesa');
});

it('un evento dell\'agenda in attesa ha il suo compito, e si chiude approvandolo; i compiti stessi non sono eventi da approvare', function () {
    $e = Evento::create(['title' => 'Festa del palazzo', 'description' => 'x', 'start_time' => now()->addDays(10), 'end_time' => now()->addDays(10)->addHour(),
        'created_by' => $this->admin->id, 'visibility' => 'hidden', 'is_approved' => false]);
    $e->condomini()->attach($this->condominio->id);
    $e->touch();

    $compito = mdAperti()->sole();
    $riga = mdRiga(mdProps($this), $compito->id);
    expect($compito->title)->toBe('Evento da approvare — Festa del palazzo')
        ->and($riga['azione']['testo'])->toBe('Approva l\'evento')
        ->and($riga['azione']['url'])->toBe('/admin/eventi?search=' . rawurlencode('Festa del palazzo'));

    $this->actingAs($this->admin)->put(route('admin.eventi.toggle-approval', $e))->assertSessionHasNoErrors();
    expect(mdAperti())->toHaveCount(0)
        // Il compito creato, a sua volta un evento, non genera un altro compito.
        ->and(Evento::where('tipo', 'da_moderare')->count())->toBe(1);
});

it('«Fatto» chiude il compito e non lo fa risuscitare finché non arriva qualcosa di nuovo', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin);
    mdCommento($s, $this->admin);
    $compito = mdAperti()->sole();

    icsRispondi($this, $compito, ['tipo' => 'fatto', 'nota' => 'li guardo dal telefono'])->assertSessionHasNoErrors();
    expect(mdAperti())->toHaveCount(0);

    // Una modifica che non porta niente di nuovo non lo riapre.
    mdAllegato($s, $this->admin, true);
    expect(mdAperti())->toHaveCount(0);

    $this->travelTo(now()->addMinute());
    mdCommento($s, $this->admin);
    expect(mdAperti())->toHaveCount(1)->and(mdAperti()->sole()->title)->toBe('3 commenti in attesa');
});

it('un utente che non apre l\'elemento non ne legge né ne conta il compito', function () {
    $mia = mdSegnalazione($this, 'Della sua');
    $altra = mdSegnalazione($this, 'Di un altro');
    $com = mdComunicazione($this, 'Avviso');
    foreach ([$mia, $altra] as $s) {
        mdCommento($s, $this->admin);
    }
    mdCommento($com, $this->admin);
    $d = Documento::create(['name' => 'Verbale', 'path' => 'documenti/v.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => false]);
    $d->touch();
    expect(mdAperti())->toHaveCount(4);

    // Modera i commenti delle segnalazioni (decisione 454: il compito lo legge solo chi sa fare la sua prossima azione), ma apre solo le sue.
    RuoloSpatie::findOrCreate('Solo proprie', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_EVENTS->value,
        Permission::VIEW_OWN_SEGNALAZIONI->value, Permission::EDIT_EVENTS->value, Permission::VIEW_COMMENTS_SEGNALAZIONI->value,
        Permission::APPROVE_COMMENTS_SEGNALAZIONI->value]);
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole('Solo proprie');
    $mia->forceFill(['created_by' => $chi->id])->saveQuietly();
    $chi = $chi->fresh();

    $props = mdProps($this, [], $chi);
    $titoli = collect($props['tasks']['data'])->pluck('sotto')->all();

    expect($props['counts']['da_moderare'])->toBe(1)->and($titoli)->toContain('Segnalazione «Della sua»')
        ->and($titoli)->not->toContain('Segnalazione «Di un altro»')->not->toContain('Comunicazione «Avviso»')->not->toContain('Documento «Verbale»')
        ->and($props['inbox_count'])->toBe(2);

    $this->actingAs($chi)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', Evento::where('tipo', 'da_moderare')->where('eventable_id', $altra->id)->sole()),
        ['tipo' => 'fatto'])->assertNotFound();
});

it('le query dell\'Action inbox non crescono con i compiti da moderare', function () {
    $conta = function () {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.inbox'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $primo = mdSegnalazione($this, 'Prima');
    mdCommento($primo, $this->admin);
    // Una prima lettura scalda i permessi, che si leggono una volta per processo.
    $conta();
    $con1 = $conta();

    foreach (range(1, 12) as $i) {
        $s = mdSegnalazione($this, "Altra {$i}");
        mdCommento($s, $this->admin);
        mdAllegato($s, $this->admin);
        mdAllegato(mdComunicazione($this, "Com {$i}", false), $this->admin);
    }
    expect(mdAperti()->count())->toBeGreaterThan(24);

    expect($conta())->toBe($con1);
});
