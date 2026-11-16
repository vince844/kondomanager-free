<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il peso dell'Action inbox con 3.000 compiti aperti in 40 condomini: la pagina intera (le card, i due grafici e «Dove si accumulano» dalla lettura
 * aggregata, la prima pagina dell'elenco con le risposte, i condomini dei sei mesi), la lettura aggregata da sola, e la pagina dopo dell'elenco (lo scorrimento,
 * che chiede solo l'elenco e non rifà la lettura aggregata). I compiti hanno i tipi delle due Inbox, giorni da 200 giorni fa a 60 giorni avanti, 300 in
 * attesa di «Ricordamelo» (che non contano), 200 chiusi; i compiti delle segnalazioni hanno la loro segnalazione.
 *
 * Le soglie: la pagina in 2 s e 32 MB in più; la lettura aggregata in 0,5 s e 8 MB in più; la pagina dopo dell'elenco in 1 s; la ricerca con l'ordine per
 * condominio in 2 s.
 */

use App\Models\Condominio;
use App\Models\Evento;
use App\Services\Gestionale\Inbox\RiepilogoDellInbox;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

const AIM_SECONDI_PAGINA = 2.0;
const AIM_MEGA_PAGINA = 32.0;
const AIM_SECONDI_LETTURA = 0.5;
const AIM_MEGA_LETTURA = 8.0;
const AIM_SECONDI_SCORRIMENTO = 1.0;

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** 40 condomini, 3.000 compiti aperti (più 200 chiusi e 300 in attesa di «Ricordamelo»), scritti a blocchi come li scrive il programma. */
function aimStudio($test): void
{
    $modello = icsCompito($test, 'emissione_rata', ['numero_rata' => 1, 'totale_rata' => 10000], 'Modello');
    $base = collect($modello->getAttributes())->except(['id', 'created_at', 'updated_at'])->all();
    $modello->delete();
    $tipi = [...ICS_SI_SPOSTA, ...ICS_A_PARTE];
    $condomini = collect(range(1, 40))->map(fn ($i) => Condominio::factory()->create(['nome' => sprintf('Condominio %02d', $i)])->id)->all();
    $adesso = now()->format('Y-m-d H:i:s');
    $n = 0;
    foreach (array_chunk(range(0, 3499), 500) as $blocco) {
        $righe = [];
        foreach ($blocco as $i) {
            $giorno = now()->startOfDay()->addDays(($i % 261) - 200)->setTime(9, 0)->format('Y-m-d H:i:s');
            $tipo = $tipi[$i % count($tipi)];
            // Il compito di una segnalazione si legge con la sua segnalazione, che c'è, come nel programma (scritta senza gli eventi del modello).
            $segnalazione = $tipo !== 'segnalazione_guasto' ? null : DB::table('segnalazioni')->insertGetId(['subject' => "Compito {$i}", 'description' => 'Guasto',
                'created_by' => $test->admin->id, 'condominio_id' => $condomini[$i % 40], 'priority' => 'media', 'stato' => 'aperta', 'is_published' => true,
                'is_approved' => true, 'created_at' => $adesso, 'updated_at' => $adesso]);
            $righe[] = ['title' => "Compito {$i}", 'start_time' => $giorno, 'end_time' => $giorno, 'tipo' => $tipo, 'is_completed' => $i >= 3300,
                'eventable_type' => $segnalazione === null ? null : (new \App\Models\Segnalazione())->getMorphClass(), 'eventable_id' => $segnalazione,
                'meta' => json_encode(['type' => $tipo, 'requires_action' => true, 'action_url' => "/admin/gestionale/1/pagina/{$i}", 'numero_rata' => $i % 12 + 1,
                    'totale_rata' => 1000 + $i, 'context' => []]),
                'created_at' => $adesso, 'updated_at' => $adesso] + $base;
        }
        // La colonna `tipo` vale solo per i casi di EventoTipo, come la scrive `InboxService::createTask`.
        $righe = array_map(fn ($r) => ['tipo' => \App\Enums\EventoTipo::tryFrom($r['tipo'])?->value] + $r, $righe);
        DB::table('eventi')->insert($righe);
        $ids = DB::table('eventi')->where('title', 'like', 'Compito %')->orderByDesc('id')->limit(count($righe))->pluck('id')->reverse()->values();
        DB::table('condominio_evento')->insert($ids->map(fn ($id, $k) => ['evento_id' => $id, 'condominio_id' => $condomini[($n + $k) % 40], 'created_at' => $adesso,
            'updated_at' => $adesso])->all());
        $n += count($righe);
    }
    // 300 in attesa di «Ricordamelo», fra quelli aperti: il promemoria fra dieci giorni.
    $promemoria = aimPromemoria($test);
    $attesa = DB::table('eventi')->where('title', 'like', 'Compito %')->where('is_completed', false)->orderBy('id')->limit(300)->pluck('id');
    DB::table('risposte_promemoria')->insert($attesa->map(fn ($id) => ['condominio_id' => $condomini[0], 'argomento' => 'compito', 'evento_id' => $id, 'esercizio_id' => null,
        'anagrafica_id' => null, 'chiave' => "compito|{$id}", 'stato' => 'ricordamelo', 'promemoria_evento_id' => $promemoria, 'giorno' => null, 'dati' => null,
        'nota' => null, 'registro' => '[]', 'created_at' => $adesso, 'updated_at' => $adesso])->all());
}

/** Il promemoria nell'agenda dei compiti in attesa, fra dieci giorni. */
function aimPromemoria($test): int
{
    return \App\Services\Gestionale\InboxService::creaPromemoria('Ricordamelo', 'Nota', now()->addDays(10), $test->admin->id, null, '/admin/inbox')->id;
}

it('3.000 compiti aperti in 40 condomini: la pagina in 2 s e 32 MB, la lettura aggregata in 0,5 s e 8 MB, la pagina dopo dell\'elenco in 1 s', function () {
    aimStudio($this);
    expect(Evento::query()->compitiAperti()->count())->toBe(3000);

    // La lettura aggregata da sola.
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $inizio = hrtime(true);
    $r = app(RiepilogoDellInbox::class)->leggi(null, true);
    $secondiLettura = (hrtime(true) - $inizio) / 1e9;
    $megaLettura = (memory_get_peak_usage() - $base) / 1048576;
    unset($r);

    // La pagina intera, come la apre l'amministratore.
    $this->actingAs($this->admin)->get(route('admin.inbox'))->assertOk();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $inizio = hrtime(true);
    $props = $this->actingAs($this->admin)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];
    $secondiPagina = (hrtime(true) - $inizio) / 1e9;
    $megaPagina = (memory_get_peak_usage() - $base) / 1048576;

    // La pagina dopo dell'elenco, come la chiede lo scorrimento: solo l'elenco.
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $inizio = hrtime(true);
    $dopo = $this->actingAs($this->admin)->get(route('admin.inbox', ['page' => 2]), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'dashboard/ActionInbox', 'X-Inertia-Partial-Data' => 'tasks',
    ]);
    $secondiScorrimento = (hrtime(true) - $inizio) / 1e9;
    $dopo->assertOk();

    // La ricerca (nel titolo, nella descrizione, nel condominio, nella persona) e l'ordine per condominio, nella scheda «Scaduti».
    $inizio = hrtime(true);
    $cercata = $this->actingAs($this->admin)->get(route('admin.inbox', ['cerca' => 'condominio 07', 'ordine' => 'condominio', 'quando' => 'scaduti']))
        ->assertOk()->viewData('page')['props'];
    $secondiRicerca = (hrtime(true) - $inizio) / 1e9;

    fwrite(STDERR, sprintf("\n[misura] Action inbox, 3.000 compiti in 40 condomini: pagina %.2f s, %.1f MB in più; lettura aggregata %.3f s, %.1f MB; pagina dopo %.2f s; "
        . "ricerca e ordine per condominio %.2f s\n", $secondiPagina, $megaPagina, $secondiLettura, $megaLettura, $secondiScorrimento, $secondiRicerca));

    expect($props['counts']['all'])->toBe(3000)
        ->and(collect($props['grafici']['eta'])->sum('valore') + array_sum($props['grafici']['fuori']))->toBe(3000)
        ->and(array_sum($props['gruppi']))->toBe(3000)
        ->and(collect($props['grafici']['tipi'])->sum('valore'))->toBe(3000)
        ->and($props['dove'])->toHaveCount(5)
        ->and($props['tasks']['data'])->toHaveCount(15)
        ->and(array_keys($dopo->json('props')))->not->toContain('counts')
        ->and($dopo->json('props.tasks.data'))->toHaveCount(15)
        ->and($secondiPagina)->toBeLessThan(AIM_SECONDI_PAGINA)
        ->and($megaPagina)->toBeLessThan(AIM_MEGA_PAGINA)
        ->and($secondiLettura)->toBeLessThan(AIM_SECONDI_LETTURA)
        ->and($megaLettura)->toBeLessThan(AIM_MEGA_LETTURA)
        ->and($secondiScorrimento)->toBeLessThan(AIM_SECONDI_SCORRIMENTO)
        ->and($cercata['schede']['tutti'])->toBeGreaterThan(0)
        ->and(collect($cercata['tasks']['data'])->pluck('condominio')->unique()->values()->all())->toBe(['Condominio 07'])
        ->and($secondiRicerca)->toBeLessThan(AIM_SECONDI_PAGINA);
});
