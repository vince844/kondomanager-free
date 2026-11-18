<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un compito scritto adesso ha il giorno di Roma, scritto come l'agenda scrive le ore (l'ora così come si legge): fra le 00:00 e l'01:00 di Roma d'inverno, e
 * fra le 00:00 e le 02:00 d'estate, il server è ancora a ieri, e un compito scritto con l'ora del server nasceva «scaduto da ieri». La segnalazione è «aperta
 * il» giorno in cui l'ha scritta il condòmino, il bonifico è «segnalato il» giorno in cui l'ha segnalato; il commento e l'allegato da guardare aspettano due
 * giorni da oggi. Nessun punto del programma scrive un compito con l'ora del server.
 */

use App\Enums\EventoTipo;
use App\Enums\Role;
use App\Models\Commento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';
require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
    $this->user = $this->admin;
});

/** Le due notti: d'inverno le 00:30 di Roma sono le 23:30 UTC del giorno prima, d'estate le 22:30. */
const GDR_NOTTI = [
    'inverno, alle 00:30' => ['2026-11-16 00:30', '2026-11-16'],
    'ora legale, alle 00:30' => ['2026-07-16 00:30', '2026-07-16'],
    'ora legale, alle 01:30' => ['2026-07-16 01:30', '2026-07-16'],
];

/** La riga dell'Action inbox del compito, come la legge l'amministratore. */
function gdrRiga($test, Evento $compito): array
{
    \Illuminate\Support\Facades\Cache::flush();
    $p = $test->actingAs($test->admin)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];
    $righe = collect($p['tasks']['data'] ?? [])->merge(collect($p['anteprima'] ?? [])->flatMap(fn ($g) => $g['righe']));

    return $righe->firstWhere('id', $compito->id) ?? throw new RuntimeException('Il compito non è nella casella.');
}

it('la segnalazione è «aperta il» giorno di Roma in cui è stata scritta', function (string $adesso, string $oggi) {
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());
    $s = Segnalazione::create(['subject' => 'Ascensore', 'description' => 'Fermo.', 'created_by' => $this->admin->id, 'condominio_id' => $this->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true]);
    $compito = Evento::where('tipo', 'segnalazione_guasto')->where('eventable_id', $s->id)->sole();

    $riga = gdrRiga($this, $compito);
    expect($riga['giorno'])->toBe($oggi)->and($riga['giorni'])->toBe(0)->and($riga['gruppo'])->toBe('segnalazioni')
        ->and($compito->start_time->format('Y-m-d H:i'))->toBe(substr($adesso, 0, 16));
})->with(GDR_NOTTI);

it('il compito del commento in attesa ha il giorno del commento, nel giorno di Roma, e sta nel gruppo «Da moderare»', function (string $adesso, string $oggi) {
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $this->condominio->id, 'subject' => 'Ascensore', 'stato' => 'chiusa']);
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());
    Commento::factory()->create(['commentable_id' => $segnalazione->id, 'commentable_type' => $segnalazione->getMorphClass(), 'user_id' => $this->admin->id,
        'corpo' => 'Quando arriva il tecnico?', 'stato' => 'in_attesa', 'condominio_id' => $this->condominio->id]);
    $compito = Evento::where('tipo', EventoTipo::DA_MODERARE->value)->sole();

    $riga = gdrRiga($this, $compito);
    expect($riga['giorno'])->toBe($oggi)->and($riga['giorni'])->toBe(0)->and($riga['gruppo'])->toBe('da_moderare');
})->with(GDR_NOTTI);

it('il compito degli allegati da approvare ha il giorno dell\'allegato, nel giorno di Roma, e sta nel gruppo «Da moderare»', function (string $adesso, string $oggi) {
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $this->condominio->id, 'subject' => 'Ascensore', 'stato' => 'chiusa']);
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());
    $segnalazione->allegati()->create(['name' => 'foto.jpg', 'path' => 'documenti/allegati/foto.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 4096,
        'is_published' => false, 'is_approved' => false, 'created_by' => $this->admin->id]);
    $compito = Evento::where('tipo', EventoTipo::DA_MODERARE->value)->sole();

    $riga = gdrRiga($this, $compito);
    expect($riga['giorno'])->toBe($oggi)->and($riga['giorni'])->toBe(0)->and($riga['gruppo'])->toBe('da_moderare');
})->with(GDR_NOTTI);

it('il bonifico è «segnalato il» giorno di Roma in cui il condòmino l\'ha segnalato dal portale', function (string $adesso, string $oggi) {
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    \App\Events\Gestionale\PianoRateStatusUpdated::dispatch($s['c'], $s['e'], $s['piano'], $this->admin, \App\Enums\StatoPianoRate::BOZZA,
        \App\Enums\StatoPianoRate::APPROVATO);
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(Role::UTENTE->value);
    $s['persone']['ugo']->update(['user_id' => $condomino->id]);
    $rata = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', lcdRata($s, 3))
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $s['persone']['ugo']->id))->firstOrFail();

    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());
    $this->actingAs($condomino)->post(route('user.eventi.report_payment', $rata))->assertSessionHasNoErrors();
    $compito = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->sole();

    $riga = gdrRiga($this, $compito);
    expect($riga['giorno'])->toBe($oggi)->and($riga['giorni'])->toBe(0)->and($riga['gruppo'])->toBe('bonifici');
})->with(GDR_NOTTI);

/**
 * Le due forme con cui un compito nasce con l'ora del server: la scadenza data come `now()` (anche `Carbon::now()`, `Date::now()`), e un giorno fra N giorni
 * alle 09:00 calcolato da `now()` (`now()->addDays(7)->setTime(9, 0)`), che fra le 00:00 e l'01:00 di Roma è un giorno prima. Il giorno di Roma si scrive con
 * `DateHelper::adessoComeSiLegge()`.
 */
const GDR_ORA_DEL_SERVER = [
    '/\bscadenza:\s*(now\(\)|(\\\\?Illuminate\\\\Support\\\\(Facades\\\\)?)?(Carbon|Date)::now\(\))/',
    '/(\bnow\(\)|(Carbon|Date)::now\(\))(->add\w*\([^)]*\))+->setTime\(/',
];

function gdrConLOraDelServer(string $sorgente): array
{
    $trovati = [];
    foreach (explode("\n", $sorgente) as $n => $riga) {
        foreach (GDR_ORA_DEL_SERVER as $forma) {
            if (preg_match($forma, $riga)) {
                $trovati[] = $n + 1;
                break;
            }
        }
    }

    return $trovati;
}

it('nessun punto del programma scrive un compito con l\'ora del server', function () {
    $trovati = [];
    $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iteratore as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        foreach (gdrConLOraDelServer(file_get_contents($file->getPathname())) as $n) {
            $trovati[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . $n;
        }
    }

    expect($trovati)->toBe([], 'Questi compiti nascono con l\'ora del server, non con il giorno di Roma: ' . implode(', ', $trovati));
});

it('la guardia morde: la scadenza con l\'ora del server e il giorno fra N giorni calcolato da now(); il giorno di Roma e le scadenze della cache no', function () {
    expect(gdrConLOraDelServer("scadenza: now(),\nscadenza: Carbon::now(),\nscadenza: Date::now(),\nscadenza: \\Illuminate\\Support\\Carbon::now(),"))->toBe([1, 2, 3, 4])
        ->and(gdrConLOraDelServer("\$a = now()->addDays(15)->setTime(9, 0);\n\$b = Carbon::now()->addDays(7)->setTime(9, 0);\n\$c = Date::now()->addWeek()->addDay()->setTime(9, 0);"))
        ->toBe([1, 2, 3])
        ->and(gdrConLOraDelServer("scadenza: DateHelper::adessoComeSiLegge(),\n\$a = DateHelper::adessoComeSiLegge()->addDays(15)->setTime(9, 0);\n"
            . "Cache::put(\$k, true, now()->addMinutes(2));\n'expires_at' => Carbon::now()->addDays(3),"))->toBe([]);
});
