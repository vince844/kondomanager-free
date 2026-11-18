<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Ricordamelo il…» su un compito dell'agenda: non chiude niente; il compito sparisce dalle due Inbox, dai loro conteggi e dal numero della barra laterale fino
 * al giorno scelto, e quel giorno torna. Il giorno va nell'agenda: per i compiti il cui giorno è un promemoria si sposta l'evento (alle 09:00 di Roma, la stessa
 * durata); per i sei tipi in cui il giorno è una scadenza o lo riscrive il programma (il pagamento di una fattura, l'F24, la convocazione, la ratifica, la
 * sopravvenienza, il deficit), per i commenti e gli allegati, e per la segnalazione e il bonifico segnalato (il loro giorno dice da quando aspettano: «aperta
 * il», «segnalato il»), l'evento resta e il promemoria è un evento a parte. Il giorno lo dice l'agenda; un promemoria tolto fa tornare il compito; un commento
 * o un allegato nuovo lo fanno tornare prima; una scadenza che il programma anticipa lo fa tornare quel giorno.
 *
 * La segnalazione e il bonifico segnalato erano fra i tipi di cui il giorno si sposta: «Ricordamelo il 25/05» su una segnalazione del 20/05 la faceva tornare
 * «aperta il 25/05 · oggi», in fondo ai più vecchi. Ora sono fra quelli con il promemoria a parte (`ICS_A_PARTE`), e le due prove sui tipi lo dicono.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Carbon;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function irmRisposta(Evento $e): ?RispostaPromemoria
{
    return RispostaPromemoria::where('evento_id', $e->id)->where('argomento', 'compito')->first();
}

/** I commenti in attesa di una segnalazione del condominio: il compito «da moderare» lo scrive il programma, da sé. */
function irmCommenti($test): \Closure
{
    // Chiusa: il compito della segnalazione stessa (che ha le sue prove, `ActionInboxSegnalazioniTest.php`) non entra nella casella; qui conta quello del commento.
    $segnalazione = \App\Models\Segnalazione::factory()->create(['condominio_id' => $test->condominio->id, 'subject' => 'Ascensore', 'stato' => 'chiusa']);

    return function (string $corpo) use ($test, $segnalazione) {
        \App\Models\Commento::factory()->create(['commentable_id' => $segnalazione->id, 'commentable_type' => $segnalazione->getMorphClass(), 'user_id' => $test->admin->id,
            'corpo' => $corpo, 'stato' => 'in_attesa', 'condominio_id' => $test->condominio->id]);
    };
}

it('il compito sparisce dalle due Inbox, dai conteggi e dal numero della barra fino al giorno, e quel giorno torna', function () {
    $e = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    expect(icsCasella($this))->toContain('Verifica incassi - Rata 3')->and(icsBarra($this))->toBe(1)->and(icsConteggi($this))->toBe([1, 1]);

    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25', 'nota' => 'dopo la banca'])->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Ti ricordo il compito il 25/05/2026: è nell\'agenda.');
    expect($e->fresh()->is_completed)->toBeFalse()
        ->and(icsCasella($this))->toBe([])
        ->and(icsInboxDelCondominio($this))->toBe([])
        ->and(icsConteggi($this))->toBe([0, 0])
        ->and(icsBarra($this))->toBe(0);

    $this->travelTo(Carbon::parse('2026-05-25 00:30', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe(['Verifica incassi - Rata 3'])
        ->and(icsInboxDelCondominio($this))->toBe(['Verifica incassi - Rata 3'])
        ->and(icsConteggi($this))->toBe([1, 1]);
});

it('per i compiti il cui giorno è un promemoria si sposta l\'evento, con la stessa durata; il giorno di prima resta nel registro', function (string $tipo) {
    $e = icsCompito($this, $tipo);
    $prima = $e->start_time->copy();
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    $r = irmRisposta($e);

    expect(Evento::count())->toBe(1)
        ->and($e->fresh()->start_time->format('Y-m-d H:i'))->toBe('2026-06-15 09:00')
        ->and($e->fresh()->end_time->format('Y-m-d H:i'))->toBe('2026-06-15 10:00')
        ->and($r->promemoria_evento_id)->toBe($e->id)
        ->and($r->ricordami_il->format('Y-m-d'))->toBe('2026-06-15')
        ->and($r->registro[0]['dati']['prima'])->toBe($prima->utc()->format('Y-m-d H:i'));
})->with(ICS_SI_SPOSTA);

it('per i tipi con la scadenza, i commenti e gli allegati l\'evento resta al suo giorno e il promemoria è un evento a parte, che non è un compito', function (string $tipo) {
    $e = icsCompito($this, $tipo, [], 'Pagare la ditta');
    $prima = $e->start_time->copy();
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    $r = irmRisposta($e);
    $p = Evento::find($r->promemoria_evento_id);

    expect(Evento::count())->toBe(2)
        ->and($e->fresh()->start_time->equalTo($prima))->toBeTrue()
        ->and($p->id)->not->toBe($e->id)
        ->and($p->tipo)->toBe(EventoTipo::PROMEMORIA)
        ->and($p->meta['requires_action'])->toBeFalse()
        // Il compito di una segnalazione non porta l'oggetto nell'agenda, che legge anche chi non apre le segnalazioni.
        ->and($p->title)->toBe($tipo === 'segnalazione_guasto' ? 'Riprendi una segnalazione · Condominio Aurora' : 'Ricordamelo: Pagare la ditta')
        ->and($p->start_time->format('Y-m-d H:i'))->toBe('2026-06-15 09:00')
        ->and($p->condomini()->pluck('condomini.id')->all())->toBe([$this->condominio->id])
        ->and(icsCasella($this))->toBe([]);
})->with(ICS_A_PARTE);

it('un secondo «Ricordamelo» non scrive un secondo evento', function () {
    $a = icsCompito($this, 'pagamento_fornitore');
    icsRispondi($this, $a, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    icsRispondi($this, $a, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-20'])->assertSessionHasNoErrors();

    expect(Evento::count())->toBe(2)->and(Evento::find(irmRisposta($a)->promemoria_evento_id)->start_time->utc()->format('Y-m-d'))->toBe('2026-06-20');
});

it('il promemoria tolto dall\'agenda fa tornare il compito subito, e la riga lo dice', function () {
    $e = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta');
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    expect(icsCasella($this))->toBe([]);
    Evento::find(irmRisposta($e)->promemoria_evento_id)->delete();

    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    $compito = collect($props['tasks']['data'])->firstWhere('title', 'Pagare la ditta');
    expect($compito)->not->toBeNull()
        ->and($compito['risposta']['tornato'])->toBe('Il promemoria nell\'agenda è stato tolto: il compito è tornato.');
});

it('spostato nell\'agenda, il compito torna il giorno nuovo', function () {
    $e = icsCompito($this, 'controllo_incassi', [], 'Verifica');
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    $e->fresh()->update(['start_time' => '2026-05-22 07:00:00', 'end_time' => '2026-05-22 08:00:00']);
    expect(icsCasella($this))->toBe([]);

    $this->travelTo(Carbon::parse('2026-05-22 08:00', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe(['Verifica']);
});

it('un commento nuovo dopo «Ricordamelo» fa tornare il compito, e la riga lo dice; per gli altri tipi un aggiornamento dell\'evento non conta', function () {
    $commenta = irmCommenti($this);
    $commenta('Il primo commento');
    $commento = Evento::where('tipo', 'da_moderare')->firstOrFail();
    $fattura = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta');
    icsRispondi($this, $commento, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    icsRispondi($this, $fattura, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    expect(icsCasella($this))->toBe([]);

    // Un minuto dopo: il listener della fattura riscrive il suo evento (il meta, la descrizione) e il compito resta nascosto; poi arriva un commento nuovo.
    $this->travel(1)->minutes();
    $f = $fattura->fresh();
    $f->update(['description' => 'Fattura salvata di nuovo', 'meta' => array_merge($f->meta, ['importo' => 999])]);
    expect(icsCasella($this))->toBe([]);
    $commenta('Il secondo commento');

    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect(collect($props['tasks']['data'])->pluck('title')->all())->toBe([$commento->fresh()->title])
        ->and(collect($props['tasks']['data'])->first()['risposta']['tornato'])->toBe('È arrivato qualcosa di nuovo da moderare dopo il tuo «Ricordamelo»: il compito è tornato.');
});

it('il giorno è nel calendario dell\'amministratore: alle 00:30 di Roma «domani» è il giorno di Roma', function () {
    $e = icsCompito($this, 'controllo_incassi');
    $this->travelTo(Carbon::parse('2026-05-21 00:30', 'Europe/Rome')->utc());
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-21'])->assertSessionHasErrors(['giorno' => 'Scegli un giorno da domani in poi.']);
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-22'])->assertSessionHasNoErrors();

    expect($e->fresh()->start_time->format('Y-m-d H:i'))->toBe('2026-05-22 09:00');
});

it('dopo «Ricordamelo» «Fatto» chiude il compito e toglie il promemoria a parte dall\'agenda', function () {
    $e = icsCompito($this, 'pagamento_fornitore');
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    icsRispondi($this, $e, ['tipo' => 'fatto'])->assertSessionHasNoErrors();

    expect(Evento::count())->toBe(1)->and($e->fresh()->is_completed)->toBeTrue()->and(irmRisposta($e)->promemoria_evento_id)->toBeNull();
});

it('per gli allegati, con il servizio vero: le approvazioni non fanno tornare il compito, un allegato nuovo sì, anche dopo più approvazioni che arrivi', function () {
    // Chiusa: il compito della segnalazione stessa non entra nella casella; qui conta quello degli allegati.
    $segnalazione = \App\Models\Segnalazione::factory()->create(['condominio_id' => $this->condominio->id, 'subject' => 'Ascensore', 'stato' => 'chiusa']);
    $nuovo = fn () => \Illuminate\Support\Facades\DB::table('documenti')->insertGetId(['name' => 'f.pdf', 'path' => 'x/' . uniqid() . '.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 10, 'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => false, 'documentable_type' => $segnalazione->getMorphClass(),
        'documentable_id' => $segnalazione->id, 'created_at' => now(), 'updated_at' => now()]);
    $servizio = app(\App\Services\Allegati\AllegatoEventoService::class);
    $allegati = [$nuovo(), $nuovo(), $nuovo()];
    $servizio->sincronizza($segnalazione, $this->admin);
    $e = Evento::where('tipo', 'da_moderare')->firstOrFail();
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();

    // Un minuto dopo l'amministratore ne approva due: ne resta uno in attesa.
    $this->travel(1)->minutes();
    \Illuminate\Support\Facades\DB::table('documenti')->whereIn('id', [$allegati[0], $allegati[1]])->update(['is_approved' => true]);
    $servizio->sincronizza($segnalazione, $this->admin);
    expect(icsCasella($this))->toBe([]);

    // Un altro minuto dopo ne arriva uno nuovo: sono due in attesa, meno dei tre di prima, e il compito torna.
    $this->travel(1)->minutes();
    $nuovo();
    $servizio->sincronizza($segnalazione, $this->admin);
    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];

    expect(collect($props['tasks']['data'])->pluck('title')->all())->toBe([$e->fresh()->title])
        ->and($props['tasks']['data'][0]['risposta']['tornato'])->toBe('È arrivato qualcosa di nuovo da moderare dopo il tuo «Ricordamelo»: il compito è tornato.')
        ->and(icsInboxDelCondominio($this))->toBe([$e->fresh()->title]);
});

it('un commento tornato prima conta nella barra e nel gruppo «Da moderare», non fra gli scaduti: il compito tiene il giorno del primo commento', function () {
    $commenta = irmCommenti($this);
    $commenta('Il primo commento');
    $commento = Evento::where('tipo', 'da_moderare')->firstOrFail();
    icsRispondi($this, $commento, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    // Tre giorni dopo arriva un commento nuovo: il compito torna, con il giorno del primo commento ancora in attesa, e non è scaduto (il gruppo non scade).
    $this->travel(3)->days();
    $commenta('Il secondo commento');
    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];

    expect(collect($props['tasks']['data'])->pluck('title')->all())->toBe(['2 commenti in attesa'])
        ->and($props['tasks']['data'][0]['giorno'])->toBe('2026-05-20')->and($props['tasks']['data'][0]['gruppo'])->toBe('da_moderare')
        ->and($props['counts']['scaduti'])->toBe(0)
        ->and(icsBarra($this))->toBe(1);
});

it('la barra non conta la fattura e il bonifico in attesa di «Ricordamelo»', function () {
    $f = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta');
    [$b] = icsBonifico($this);
    expect(icsBarra($this))->toBe(2);
    icsRispondi($this, $f, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    expect(icsBarra($this))->toBe(1);
    icsRispondi($this, $b, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    expect(icsBarra($this))->toBe(0);
});

it('la scadenza della fattura anticipata dal programma mentre il compito aspetta: torna il giorno della scadenza nuova, prima del giorno scelto, e la riga lo dice', function () {
    $e = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta Rossi');
    $e->update(['start_time' => '2026-06-30 07:00:00', 'end_time' => '2026-06-30 08:00:00']);
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-20'])->assertSessionHasNoErrors();
    expect(icsCasella($this))->toBe([]);
    // Come `FatturaPassivaService` (la scadenza della fattura corretta): il giorno dell'evento è la scadenza nuova.
    Evento::whereKey($e->id)->update(['start_time' => '2026-05-22 07:00:00', 'end_time' => '2026-05-22 08:00:00']);
    expect(icsCasella($this))->toBe([]);

    $this->travelTo(Carbon::parse('2026-05-22 08:00', 'Europe/Rome')->utc());
    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect(collect($props['tasks']['data'])->pluck('title')->all())->toBe(['Pagare la ditta Rossi'])
        ->and($props['tasks']['data'][0]['risposta']['tornato'])->toBe('La scadenza è cambiata: il compito è tornato.');
    // La barra conta i compiti con il giorno arrivato, oggi compreso.
    $this->travelTo(Carbon::parse('2026-05-22 10:00', 'Europe/Rome')->utc());
    expect(icsBarra($this))->toBe(1);
});

it('una scadenza spostata più avanti non fa tornare prima il compito: vale il giorno scelto', function () {
    $e = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta Rossi');
    $e->update(['start_time' => '2026-06-30 07:00:00', 'end_time' => '2026-06-30 08:00:00']);
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-20'])->assertSessionHasNoErrors();
    Evento::whereKey($e->id)->update(['start_time' => '2026-07-31 07:00:00']);

    $this->travelTo(Carbon::parse('2026-06-19 12:00', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe([]);
    $this->travelTo(Carbon::parse('2026-06-20 08:00', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe(['Pagare la ditta Rossi']);
});

it('l\'F24 riscritto e riaperto con una scadenza prima del giorno di «Ricordamelo» torna subito', function () {
    $f24 = icsCompito($this, 'versamento_ritenuta', ['context' => ['pagamento_id' => 5]], 'F24 Ritenuta — Ditta Rossi');
    $f24->update(['start_time' => '2026-06-16 07:00:00', 'end_time' => '2026-06-16 08:00:00']);
    icsRispondi($this, $f24, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-10'])->assertSessionHasNoErrors();
    Evento::where('meta->type', 'versamento_ritenuta')->update(['start_time' => '2026-05-16 07:00:00', 'is_completed' => false]);

    expect(icsCasella($this))->toBe(['F24 Ritenuta — Ditta Rossi']);
});

it('un compito nuovo con l\'id di un compito cancellato non eredita la sua risposta', function () {
    $x = icsCompito($this, 'emissione_rata', [], 'Emettere rata 2');
    icsRispondi($this, $x, ['tipo' => 'non_serve', 'motivo' => 'la emetto la settimana prossima'])->assertSessionHasNoErrors();
    $id = $x->id;
    Evento::whereKey($id)->delete();
    // Come MySQL 5.7 dopo un riavvio: il contatore riparte da MAX(id) + 1 e l'id torna libero.
    $y = Evento::forceCreate(['id' => $id, 'title' => 'Verifica incassi - Rata 9', 'description' => 'd', 'start_time' => now()->subDay(), 'end_time' => now()->subDay()->addHour(),
        'created_by' => $this->admin->id, 'visibility' => 'hidden', 'is_approved' => true, 'tipo' => 'controllo_incassi', 'meta' => ['type' => 'controllo_incassi', 'requires_action' => true]]);
    $y->condomini()->attach($this->condominio->id);
    icsRispondi($this, $y, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();
    $nuova = irmRisposta($y);

    expect(icsCasella($this))->toBe([])
        ->and(RispostaPromemoria::where('titolo', 'Emettere rata 2')->value('stato'))->toBe('non_serve')
        ->and($nuova->titolo)->toBe('Verifica incassi - Rata 9')
        ->and($nuova->tipo_compito)->toBe('controllo_incassi')
        ->and(collect($nuova->registro)->pluck('tipo')->all())->toBe(['ricordamelo'])
        ->and(RispostaPromemoria::count())->toBe(2);
});

it('le due Inbox portano per ogni compito il registro delle risposte e, per i sei tipi con la scadenza, il promemoria a parte; e il giorno di oggi', function () {
    icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta');
    $incassi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi');
    icsRispondi($this, $incassi, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-21', 'nota' => 'dopo la banca'])->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-05-21 08:00', 'Europe/Rome')->utc());

    $casella = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    $condominio = $this->actingAs($this->admin)->get(route('admin.gestionale.index', $this->condominio))->viewData('page')['props'];
    foreach ([$casella['tasks']['data'], $condominio['inboxTasks']['data']] as $righe) {
        $compiti = collect($righe)->keyBy('title');
        expect($compiti['Pagare la ditta']['promemoria_a_parte'])->toBeTrue()
            ->and($compiti['Pagare la ditta']['risposta'])->toBeNull()
            ->and($compiti['Verifica incassi']['promemoria_a_parte'])->toBeFalse()
            ->and($compiti['Verifica incassi']['risposta']['tornato'])->toBeNull()
            ->and($compiti['Verifica incassi']['risposta']['registro'][0])->toMatchArray(['tipo' => 'ricordamelo', 'utente' => 'Vincenzo', 'nota' => 'dopo la banca', 'quando' => '20/05/2026 10:00']);
    }
    expect($casella['oggi'])->toBe('2026-05-21')->and($condominio['oggi'])->toBe('2026-05-21');
});

it('la segnalazione e il bonifico segnalato tornano con il loro giorno, «aperta il» e «segnalato il», e al loro posto fra i più vecchi', function () {
    $this->travelTo(Carbon::parse('2026-05-18 10:00', 'Europe/Rome')->utc());
    $vecchia = \App\Models\Segnalazione::create(['subject' => 'Citofono', 'description' => 'Muto.', 'created_by' => $this->admin->id, 'condominio_id' => $this->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true]);
    [$bonifico] = icsBonifico($this);
    $this->travelTo(Carbon::parse('2026-05-19 10:00', 'Europe/Rome')->utc());
    \App\Models\Segnalazione::create(['subject' => 'Cancello', 'description' => 'Rotto.', 'created_by' => $this->admin->id, 'condominio_id' => $this->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true]);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    $compito = Evento::where('tipo', 'segnalazione_guasto')->where('eventable_id', $vecchia->id)->sole();

    foreach ([$compito, $bonifico] as $e) {
        icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
        expect($e->fresh()->start_time->format('Y-m-d'))->toBe($e->start_time->format('Y-m-d'));
    }
    expect(icsCasella($this))->toBe(['Cancello']);

    $this->travelTo(Carbon::parse('2026-05-25 10:00', 'Europe/Rome')->utc());
    $righe = collect($this->actingAs($this->admin)->get(route('admin.inbox', ['tipo' => 'segnalazione_guasto']))->viewData('page')['props']['tasks']['data']);
    expect($righe->map(fn ($r) => [$r['title'], $r['giorno'], $r['giorni']])->all())->toBe([['Citofono', '2026-05-18', -7], ['Cancello', '2026-05-19', -6]]);
    $riga = collect($this->actingAs($this->admin)->get(route('admin.inbox', ['tipo' => 'verifica_pagamento']))->viewData('page')['props']['tasks']['data'])->sole();
    expect([$riga['title'], $riga['giorno']])->toBe(['Verifica incasso della rata 3 di Mario', '2026-05-17']);
});
