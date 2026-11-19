<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni: che cosa ricevono le pagine (Coda 589, decisione 290)
 *
 * - **Il modulo** (creazione e modifica) riceve `programmazione`: chi può programmare, il fuso, se il pianificatore è
 *   attivo, l'ora di adesso nel fuso.
 * - **La risorsa del pannello** porta `data_programmata` (l'ora scelta, nel fuso, com'è nel campo) e `pubblicata_il`.
 * - **L'elenco del pannello** filtra le programmate, e «Bozza» non le comprende più.
 * - **Il portale** non vede una programmata finché non esce. Poi la dashboard, che mostra le ultime tre, la mette in cima
 *   con la data dell'uscita; l'elenco resta nell'ordine che l'utente sceglie. Non riceve mai `data_programmata`.
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC: le 11:00 a Roma.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

/** Le comunicazioni dell'elenco, dalle props: un elenco sciolto o dentro `data`. */
function cprElenco(array $props): array
{
    $valore = $props['comunicazioni'] ?? [];

    return array_values(array_is_list($valore) ? $valore : ($valore['data'] ?? []));
}

// --- Il modulo -----------------------------------------------------------------------------------------

test('il modulo di creazione sa chi può programmare, il fuso, l\'ora di adesso e se il pianificatore è attivo', function () {
    Cache::put('system_cron_heartbeat', now()->timestamp, now()->addMinutes(10));

    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.create')));

    expect($props)->toHaveKey('programmazione')
        ->and($props['programmazione'])->toMatchArray([
            'puo_programmare' => true,
            'fuso' => 'Europe/Rome',
            'pianificatore_attivo' => true,
            'adesso' => '2026-11-12T11:00',
        ]);
});

test('senza la prova di vita del pianificatore il modulo lo dice', function () {
    Cache::forget('system_cron_heartbeat');

    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.create')));

    expect($props['programmazione']['pianificatore_attivo'])->toBeFalse();
});

test('il modulo segue il fuso dell\'installazione', function () {
    config(['app.user_timezone' => 'America/New_York']);

    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.create')));

    expect($props['programmazione']['fuso'])->toBe('America/New_York')
        ->and($props['programmazione']['adesso'])->toBe('2026-11-12T05:00');
});

test('chi non pubblica non può programmare, e il modulo lo sa', function () {
    $ruolo = ccmRuolo('segreteria', ['Accesso pannello amministratore', 'Crea comunicazioni', 'Visualizza comunicazioni', 'Modifica comunicazioni']);
    $segreteria = ccmUtente([$this->aurora->id], 'Sara Segreteria', $ruolo);

    $props = cprProps($this->actingAs($segreteria)->get(route('admin.comunicazioni.create')));

    expect($props['programmazione']['puo_programmare'])->toBeFalse();
});

test('il modulo di modifica riceve l\'ora scelta com\'è nel campo, nel fuso dell\'installazione', function () {
    $programmata = cprProgrammata([$this->aurora], $this->admin, '2027-07-15 07:00:00');
    $bozza = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $inAttesa = ccmComunicazione([$this->aurora], $this->mario, ['is_approved' => false, 'is_published' => false]);

    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.edit', $programmata)));
    expect(cprRisorsa($props['comunicazione'])['data_programmata'])->toBe('2027-07-15T09:00')
        ->and($props['programmazione']['puo_programmare'])->toBeTrue()
        ->and($props['programmazione']['fuso'])->toBe('Europe/Rome');

    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.edit', $bozza)));
    expect(cprRisorsa($props['comunicazione']))->toHaveKey('data_programmata')
        ->and(cprRisorsa($props['comunicazione'])['data_programmata'])->toBeNull()
        ->and($props['programmazione']['puo_programmare'])->toBeTrue();

    // Una comunicazione in attesa di approvazione non si programma: il modulo non lo offre.
    $props = cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.edit', $inAttesa)));
    expect($props['programmazione']['puo_programmare'])->toBeFalse();
});

// --- La scheda e l'elenco del pannello -----------------------------------------------------------------

test('la scheda del pannello riceve l\'ora scelta di una programmata, e la data di uscita di una pubblicata', function () {
    $programmata = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');
    $pubblicata = ccmComunicazione([$this->aurora], $this->admin);

    $c = cprRisorsa(cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.show', $programmata)))['comunicazione']);
    expect($c['data_programmata'])->toBe('2027-01-15T09:00')
        ->and($c)->toHaveKey('pubblicata_il')
        ->and($c['pubblicata_il'])->toBeNull();

    $this->travelTo(Carbon::parse('2026-11-14 10:00:00', 'UTC'));
    $c = cprRisorsa(cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.show', $pubblicata)))['comunicazione']);
    expect($c['data_programmata'])->toBeNull()
        // Nella stessa forma di `created_at` in questa risorsa: il tempo trascorso, a parole.
        ->and($c['pubblicata_il'])->toBe(Carbon::parse(CPR_ADESSO, 'UTC')->diffForHumans());
});

test('l\'elenco del pannello filtra le programmate, e «Bozza» non le comprende', function () {
    $programmata = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');
    $bozza = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $pubblicata = ccmComunicazione([$this->aurora], $this->admin);

    $ids = fn (array $filtro) => collect(cprElenco(cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index', $filtro)))))
        ->pluck('id')->sort()->values()->all();

    expect($ids(['visibility' => ['scheduled']]))->toBe([$programmata->id])
        ->and($ids(['visibility' => ['draft']]))->toBe([$bozza->id])
        ->and($ids(['visibility' => ['published']]))->toBe([$pubblicata->id])
        ->and($ids(['visibility' => ['scheduled', 'draft']]))->toBe([$programmata->id, $bozza->id])
        ->and($ids([]))->toBe([$programmata->id, $bozza->id, $pubblicata->id]);

    $riga = collect(cprElenco(cprProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index')))))->firstWhere('id', $programmata->id);
    expect($riga['data_programmata'])->toBe('2027-01-15T09:00');
});

// --- Il portale ----------------------------------------------------------------------------------------

test('il portale non vede una programmata finché non esce, e non ne riceve mai l\'ora scelta', function () {
    $programmata = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 10:30:00');
    $elenco = fn () => collect(cprElenco(cprProps($this->actingAs($this->mario)->get(route('user.comunicazioni.index')))));

    expect($elenco()->pluck('id')->all())->not->toContain($programmata->id);

    $this->travelTo(Carbon::parse('2026-11-12 10:30:00', 'UTC'));
    cprPubblica($this);

    $riga = $elenco()->firstWhere('id', $programmata->id);
    expect($riga)->not->toBeNull('Uscita, la comunicazione non è nell\'elenco del portale')
        ->and($riga)->not->toHaveKey('data_programmata')
        ->and($riga)->not->toHaveKey('programmata_per')
        ->and($riga)->not->toHaveKey('programmata_da')
        ->and($riga)->toHaveKey('pubblicata_il');

    $scheda = cprRisorsa(cprProps($this->actingAs($this->mario)->get(route('user.comunicazioni.show', $programmata)))['comunicazione']);
    expect($scheda)->not->toHaveKey('data_programmata')
        ->and($scheda)->toHaveKey('pubblicata_il');
});

test('nella dashboard del portale, fra le ultime comunicazioni, una scritta giorni fa e uscita adesso sta in cima', function () {
    $piuVecchia = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Uscita una settimana fa']);
    $vecchia = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Uscita lunedì']);
    $programmata = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:59:00', ['subject' => 'Scritta martedì, esce giovedì']);
    $diIeri = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Uscita mercoledì']);
    DB::table('comunicazioni')->where('id', $piuVecchia->id)->update(['created_at' => '2026-11-05 08:00:00', 'pubblicata_il' => '2026-11-05 08:00:00']);
    DB::table('comunicazioni')->where('id', $vecchia->id)->update(['created_at' => '2026-11-09 08:00:00', 'pubblicata_il' => '2026-11-09 08:00:00']);
    DB::table('comunicazioni')->where('id', $programmata->id)->update(['created_at' => '2026-11-10 08:00:00']);
    DB::table('comunicazioni')->where('id', $diIeri->id)->update(['created_at' => '2026-11-11 08:00:00', 'pubblicata_il' => '2026-11-11 08:00:00']);

    cprPubblica($this);

    $this->travelTo(Carbon::parse('2026-11-12 12:00:00', 'UTC'));
    $ultime = collect(cprElenco(cprProps($this->actingAs($this->mario)->get(route('user.dashboard')))));

    // Le ultime tre, dalla più recente: conta quando è uscita, non quando è stata scritta.
    expect($ultime->pluck('id')->all())->toBe([$programmata->id, $diIeri->id, $vecchia->id])
        // Uscita due ore fa, non «2 giorni fa» come la creazione.
        ->and($ultime->firstWhere('id', $programmata->id)['pubblicata_il'])->toBe(Carbon::parse(CPR_ADESSO, 'UTC')->diffForHumans())
        ->and($ultime->firstWhere('id', $programmata->id))->not->toHaveKey('data_programmata');
});

test('nella dashboard del portale l\'autore ritrova le proprie non ancora uscite per data di creazione', function () {
    $ruolo = ccmRuolo('condomino che scrive', ['Crea comunicazioni', 'Visualizza comunicazioni']);
    $autore = ccmUtente([$this->aurora->id], 'Carlo Condomino', $ruolo);
    $uscita = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Uscita ieri']);
    $inAttesa = ccmComunicazione([$this->aurora], $autore, ['subject' => 'In attesa, scritta oggi', 'is_approved' => false, 'is_published' => false]);
    DB::table('comunicazioni')->where('id', $uscita->id)->update(['created_at' => '2026-11-11 08:00:00', 'pubblicata_il' => '2026-11-11 08:00:00']);

    $ultime = collect(cprElenco(cprProps($this->actingAs($autore)->get(route('user.dashboard')))));

    expect($ultime->pluck('id')->all())->toBe([$inAttesa->id, $uscita->id]);
});

test('l\'elenco del portale resta nell\'ordine che l\'utente sceglie: di serie per oggetto', function () {
    $zeta = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Zona parcheggi']);
    $alfa = cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:59:00', ['subject' => 'Acqua chiusa martedì']);

    cprPubblica($this);

    $elenco = collect(cprElenco(cprProps($this->actingAs($this->mario)->get(route('user.comunicazioni.index')))));
    expect($elenco->pluck('id')->all())->toBe([$alfa->id, $zeta->id]);
});

// --- I testi -------------------------------------------------------------------------------------------

test('i testi delle pagine stanno nei file di lingua, nelle quattro lingue', function () {
    $chiavi = [
        'comunicazioni.publication.scheduled',
        'comunicazioni.scheduling.field_label',
        'comunicazioni.scheduling.timezone_hint',
        'comunicazioni.scheduling.scheduler_down',
        'comunicazioni.scheduling.scheduled_for',
        'comunicazioni.success_schedule_communication',
        'validation.attributes.comunicazioni.data_programmata',
    ];
    foreach ($chiavi as $chiave) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    $it = fn (string $chiave) => __($chiave, [], 'it');
    expect($it('comunicazioni.publication.scheduled'))->toBe('Programmata')
        ->and($it('comunicazioni.scheduling.field_label'))->toBe('Data e ora di pubblicazione')
        // Decisione 504: sotto la data resta solo il fuso, e solo se non è quello italiano (lo decide la pagina).
        ->and($it('comunicazioni.scheduling.timezone_hint'))->toBe('Ora di :timezone.')
        ->and($it('comunicazioni.scheduling.scheduler_down'))->toBe('Il pianificatore non risulta attivo: la comunicazione uscirà quando riparte. Controlla le impostazioni del cron.')
        ->and($it('comunicazioni.scheduling.scheduled_for'))->toBe('Programmata per il :date alle :time')
        ->and($it('comunicazioni.success_schedule_communication'))->toBe('Comunicazione programmata per il :date alle :time.');
});

test('dopo aver programmato, il messaggio dice per quando', function () {
    $risposta = cprCrea($this, $this->admin, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']));

    $risposta->assertSessionHasNoErrors();
    expect(json_encode(session()->all(), JSON_UNESCAPED_UNICODE))->toContain('Comunicazione programmata per il 15\/01\/2027 alle 09:00.');
});
