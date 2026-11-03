<?php

/**
 * 1.11.0-beta.59, Coda 413 — il prospetto degli oneri accessori dopo un accordo fra le parti.
 *
 * Dopo un cambio d'inquilino regolato fra le parti (Sara → Zoe, «Annulla il conguaglio»), se Zoe passa i suoi giorni a Elsa con «Non scriverlo», Elsa entra nell'accordo al posto di Zoe
 * (decisione 148.1). L'accordo copre solo i giorni della parte con cui è fatto (decisione 144): finisce quando chi era uscito rientra (148.2) e, dalla decisione 153.3, anche quando chi ha
 * preso il posto della parte esce con la sua fine. Chi entra dopo, che dell'accordo non è parte, rimborsa a chi ha il pezzo con il conguaglio la sua parte dei giorni fuori dall'accordo, per
 * quota e per giorni, come nei giorni di un cambio con il conguaglio scritto (decisione 117, Coda 364). Prima `ProspettoOneriAccessori::giorniDellaParte` restituiva l'accordo senza fine
 * appena la parte passava i suoi giorni a un altro, e nessuno rimborsava niente.
 *
 * Le stampe si leggono dalla rotta vera (`immobili.prospetto-oneri`): il prospetto è quello che il controller passa alla vista del PDF (`p59DallaRotta`). I passaggi e le frasi del pannello
 * «Cosa cambierà» dalle rotte dei passaggi, come la pagina.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni: 181 fino al 30/6, 184 dal 1/7; «Spese generali» € 1.200,00 al 30 % all'inquilino, la riga di Ines all'80 % 36000; i resti maggiori di
 * `MoneyHelper::ripartisciPerQuote`, un centesimo al resto più grande):
 * - il cambio Ines → Sara «Scrivi» del 1/7 dà a Sara 36000 × 184/365 = 18147,95 → 18148 (decisione 77.3: Ines − 18148, Sara + 18148); Ines 17852 per i suoi 181 giorni.
 * - l'accordo dal 1/10 al 30/11, 61 giorni su 184 del pezzo di Sara: fuori 18148 × 123/184 = 12131,54 e dentro 6016,46 → 12131 + 6016, il centesimo al resto maggiore: fuori 12132.
 * - (a) Sara rientra il 1/12, Teo dal 1/12 al 20 %: Sara 80 × 123 = 9840, Teo 20 × 31 = 620; 12132 × 620/10460 = 719,11 → 719. Sara 18148 − 719 = 17429.
 * - (b) la fine di Elsa il 1/12, Teo dal 1/12 all'80 %: Sara 80 × 92 = 7360, Teo 80 × 31 = 2480; 12132 × 2480/9840 = 3057,66, 9074,34 → 3057 + 9074, il centesimo a Teo: 3058. Sara 15090.
 *
 * Cosa NON copre: l'accordo con chi ha la riga (la domanda D-a, decisione 153.4: resta com'è fino alla 1.12, qui solo la guardia); la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Ines sola inquilina all'80 %, Ugo proprietario, il piano generato con le rate da gennaio a giugno a giornale. */
function p59Scenario($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Un passaggio dell'Interno 1 dalle rotte vere: il cambio d'inquilino (con chi entra) o la fine (senza); la scelta data se l'anteprima la chiede, e solo se la chiede. */
function p59Passaggio($test, array &$s, string $esce, ?string $entra, string $il, string $scelta = ''): Subentro
{
    if ($entra !== null && ! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')]
        + fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, [], $esce);
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBe($scelta !== '', $esce . ' il ' . $il);

    return fclRegistra($test, $s, $dati + ($scelta !== '' ? ['conguaglio' => $scelta] : []));
}

/** «Annulla il conguaglio» di un passaggio, dallo storico dell'Interno 1 (la rotta vera, con la nota). */
function p59Annulla($test, array $s, Subentro $passaggio): void
{
    $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $passaggio]),
        ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertStatus(302);
}

/** L'inizio della locazione di `$chi` il giorno dato, con la quota data, dalle rotte vere: le frasi del pannello «Cosa cambierà», poi la registrazione. */
function p59Inizio($test, array &$s, string $chi, string $il, int $quota): string
{
    if (! isset($s['persone'][$chi])) {
        fclPersona($s, $chi);
    }
    $dati = ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
    $frasi = fclFrasi(fclAnteprima($test, $s, $dati));
    fclRegistra($test, $s, $dati);

    return $frasi;
}

/** La stampa dalla rotta vera: il PDF risponde, e il prospetto è quello che il controller ha passato alla vista. */
function p59DallaRotta($test, array $s): array
{
    $spia = new class extends \App\Services\PDF\PdfService
    {
        public array $dati = [];

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            $this->dati = $data;

            return parent::generate($view, $data, $config);
        }
    };
    app()->instance(\App\Services\PDF\PdfService::class, $spia);
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita'], 'esercizio' => $s['e']->id]));
    $r->assertOk();
    expect(substr((string) $r->getContent(), 0, 4))->toBe('%PDF');

    return $spia->dati['prospetto'];
}

/** Il prospetto in breve: per conduttore [totale, nelle sue rate, da rimborsare, giorni]; «senza conduttore» [totale, giorni]. I nomi in minuscolo. */
function p59Breve(array $p): array
{
    $out = ['conduttori' => [], 'senza' => [(int) $p['senza_conduttore']['totale'], (int) collect($p['senza_conduttore']['voci'])->sum('giorni')]];
    foreach ($p['conduttori'] as $c) {
        $out['conduttori'][mb_strtolower($c['nome'])] = [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare'], (int) collect($c['voci'])->sum('giorni')];
    }
    ksort($out['conduttori']);

    return $out;
}

/** Chi ha pagato i giorni che un conduttore rimborsa: chi ha pagato → la cifra. */
function p59PagateDa(array $p, string $nome): array
{
    $c = collect($p['conduttori'])->firstWhere('nome', $nome);

    return collect($c['voci'] ?? [])->filter(fn ($v) => ! empty($v['pagato_da']))->groupBy('pagato_da')->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all();
}

/** La sezione di un conduttore: [totale, con il conguaglio, la parte di chi è entrato dopo, per nome (negativa: da ricevere)]. */
function p59Sezione(array $p, string $nome): array
{
    $c = collect($p['conduttori'])->firstWhere('nome', $nome);

    return [(int) ($c['totale'] ?? 0), (int) ($c['con_il_conguaglio'] ?? 0), $c['entrati_dopo'] ?? []];
}

/** Il registro dell'inizio di un conduttore: i giorni del conduttore che la registrazione ha scritto, [cifra, chi li ha pagati]. */
function p59GiorniDelConduttore(array $s, string $chi): array
{
    $inizio = Subentro::where('immobile_id', $s['unita']->id)->where('tipo_passaggio', 'inizio_locazione')
        ->where('anagrafica_entrante_id', $s['persone'][$chi]->id)->latest('id')->firstOrFail();

    return collect($inizio->registro['giorni_del_conduttore'] ?? [])->map(fn ($g) => [(int) $g['cifra'], array_values((array) ($g['pagato_da'] ?? []))])->all();
}

/**
 * La storia della Coda 413: Ines → Sara «Scrivi» il 1/7, Sara → Zoe «Scrivi» il 1/10 con «Annulla il conguaglio», Zoe → Elsa «Non scriverlo» il 1/11; poi `$elsa`: `rientro` (Elsa → Sara il 1/12),
 * `fine` (la fine di Elsa il 1/12) o `pia` (Elsa → Pia «Non scriverlo» il 15/11, poi la fine di Pia il 1/12); Teo dal 1/12 con la quota data. Lo scenario e le frasi del pannello dell'inizio di Teo.
 */
function p59Storia($test, string $elsa, int $quotaTeo): array
{
    $s = p59Scenario($test);
    p59Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($test, $s, p59Passaggio($test, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Passaggio($test, $s, 'Zoe', 'Elsa', '2026-11-01', 'non_scrivere');
    if ($elsa === 'rientro') {
        p59Passaggio($test, $s, 'Elsa', 'Sara', '2026-12-01');
    } elseif ($elsa === 'pia') {
        p59Passaggio($test, $s, 'Elsa', 'Pia', '2026-11-15');
        p59Passaggio($test, $s, 'Pia', null, '2026-12-01');
    } else {
        p59Passaggio($test, $s, 'Elsa', null, '2026-12-01');
    }
    $frasi = p59Inizio($test, $s, 'Teo', '2026-12-01', $quotaTeo);

    return [$s, $frasi];
}

// --- A-1 (a): il rientro di chi era uscito dopo che la parte ha passato i suoi giorni a un altro -------------------------------------------------------------------------------

it('Coda 413 — dopo l\'accordo fra Sara e Zoe e il cambio Zoe → Elsa «Non scriverlo», Sara rientra il 1/12: Teo, entrato il 1/12 al 20 %, le rimborsa la sua parte dei giorni fuori dall\'accordo, € 7,19', function () {
    // L'accordo copre i giorni di Zoe e di Elsa, che ne ha preso il posto (decisioni 144 e 148.1), dal 1/10 al 30/11: col rientro di Sara finisce (148.2). Fuori 12132 (vedi in testa); Teo,
    // che dell'accordo non è parte, lo divide con Sara per quota e per giorni (Sara 80 × 123, Teo 20 × 31): 719 «pagata da Sara». Sara 18148 − 719 = 17429, con il conguaglio 18148 (il
    // Wallet); il controllo 17852 + 17429 + 719 = 36000. Il pannello dell'inizio di Teo lo dice. Era: dal cambio Zoe → Elsa l'accordo non finiva più, e Teo non rimborsava niente.
    [$s, $frasi] = p59Storia($this, 'rientro', 20);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [17429, 0, -719, 184],
            'teo' => [719, 0, 719, 31],
        ], 'senza' => [0, 0]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 719])
        ->and(p59Sezione($p, 'Sara'))->toBe([17429, 18148, ['Teo' => -719]])
        ->and($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro');
});

it('Coda 413 — il rientro con le date di un mese prima (Sara → Zoe il 1/9, Zoe → Elsa il 1/10, Elsa → Sara il 1/11) e Teo dal 1/11 al 30/11 al 20 %: Teo rimborsa a Sara € 6,97', function () {
    // L'accordo dal 1/9 al 31/10, 61 giorni: fuori 12132. Sara 80 × 123 (1/7–31/8 e 1/11–31/12), Teo 20 × 30: 12132 × 600/10440 = 697,24 → 697. Sara 18148 − 697 = 17451.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-09-01', 'scrivi'));
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-10-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Elsa', 'Sara', '2026-11-01');
    $frasi = p59Inizio($this, $s, 'Teo', '2026-11-01', 20);
    p59Passaggio($this, $s, 'Teo', null, '2026-12-01');
    $p = p59DallaRotta($this, $s);
    expect(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 697])
        ->and(p59Sezione($p, 'Sara'))->toBe([17451, 18148, ['Teo' => -697]])
        ->and($frasi)->toContain('li rimborsa Teo a Sara');
});

// --- A-1 (b): l'uscita di chi ha preso il posto della parte (decisione 153.3) ----------------------------------------------------------------------------------------------------

it('Coda 413 — dopo l\'accordo fra Sara e Zoe e il cambio Zoe → Elsa «Non scriverlo», la fine di Elsa il 1/12 chiude l\'accordo come la fine di Zoe: Teo, dal 1/12 all\'80 %, rimborsa a Sara € 30,58', function () {
    // Decisione 153.3: l'uscita di chi ha preso il posto della parte chiude l'accordo come l'uscita della parte. L'accordo dal 1/10 al 30/11, fuori 12132; nei giorni fuori Sara 80 × 92 (fino al
    // 30/9) e Teo 80 × 31: 3058 «pagata da Sara» (vedi in testa). Ines € 178,52 com'è, Sara € 150,90 a suo carico, con il conguaglio € 181,48; i 61 giorni dell'accordo, € 60,16, restano fra
    // Sara, Zoe ed Elsa. Il prospetto e il pannello dicono la stessa cosa. Era: Sara € 181,48 e Teo niente, e il pannello diceva l'accordo.
    [$s, $frasi] = p59Storia($this, 'fine', 80);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [15090, 0, -3058, 184],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 3058])
        ->and(p59Sezione($p, 'Sara'))->toBe([15090, 18148, ['Teo' => -3058]])
        // Il registro dell'inizio scrive i giorni del conduttore solo per le coppie di una fine «Scrivi» (decisione 85.4), come dopo ogni cambio con il conguaglio scritto (Coda 364):
        // qui la coppia è quella del cambio del 1/7, e il registro non dice niente che contraddica il prospetto.
        ->and(p59GiorniDelConduttore($s, 'Teo'))->toBe([])
        ->and($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro');
});

it('Coda 413 — la fine del successore con le date di un mese prima (la fine di Elsa il 1/11, Teo dal 5/11 al 19/11 al 20 %): Teo rimborsa a Sara € 4,33, e i giorni senza conduttore restano a Sara', function () {
    // L'accordo dal 1/9 al 31/10, fuori 12132; dei 123 giorni fuori 46 senza conduttore (1/11–4/11, 20/11–31/12) restano a Sara (Coda 364): 12132 × 77/123 = 7594,83 → 7595 ai conduttori;
    // Sara 80 × 62, Teo 20 × 15: 7595 × 300/5260 = 433,17 → 433. Sara 18148 − 433 = 17715.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-09-01', 'scrivi'));
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-10-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Elsa', null, '2026-11-01');
    $frasi = p59Inizio($this, $s, 'Teo', '2026-11-05', 20);
    p59Passaggio($this, $s, 'Teo', null, '2026-11-20');
    $p = p59DallaRotta($this, $s);
    expect(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 433])
        ->and(p59Sezione($p, 'Sara'))->toBe([17715, 18148, ['Teo' => -433]])
        ->and($frasi)->toContain('li rimborsa Teo a Sara');
});

it('Coda 413 — l\'accordo segue chi prende il posto di chi l\'aveva preso (Zoe → Elsa il 1/11, Elsa → Pia il 15/11, la fine di Pia il 1/12): Teo dal 1/12 all\'80 % rimborsa a Sara € 30,58, come con la fine di Elsa', function () {
    // Pia entra nell'accordo al posto di Elsa come Elsa al posto di Zoe (148.1), e la sua fine lo chiude (153.3): l'accordo dal 1/10 al 30/11, le stesse cifre della fine di Elsa.
    [$s, $frasi] = p59Storia($this, 'pia', 80);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [15090, 0, -3058, 184],
        'teo' => [3058, 0, 3058, 31],
    ])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 3058])
        ->and($frasi)->toContain('li rimborsa Teo a Sara');
});

it('Coda 413 — l\'uscita di chi ha preso il posto della parte si cerca dal giorno in cui l\'ha preso: Elsa, entrata e uscita nei giorni di Zoe e poi al posto di Zoe, chiude l\'accordo con la fine del 1/12, non con quella del 25/10', function () {
    // Elsa entra il 15/10 al 20 % nei giorni di Zoe ed esce il 25/10 (i giorni dell'accordo: non divide, Coda 367); il 1/11 Zoe → Elsa «Non scriverlo», ed Elsa prende il posto di Zoe (148.1);
    // la fine di Elsa del 1/12 chiude l'accordo (153.3), e Teo dal 1/12 all'80 % rimborsa a Sara 3058, come senza il primo ingresso di Elsa. Se la ricerca partisse dal giorno dell'accordo,
    // la fine di Elsa del 25/10 chiuderebbe l'accordo al 24/10, e i giorni di Zoe dopo il 25/10 si dividerebbero.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Inizio($this, $s, 'Elsa', '2026-10-15', 20);
    p59Passaggio($this, $s, 'Elsa', null, '2026-10-25');
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-11-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Elsa', null, '2026-12-01');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [15090, 0, -3058, 184],
        'teo' => [3058, 0, 3058, 31],
    ])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Sara' => 3058]);
});

it('Coda 413 — le guardie: chi entra nei giorni di chi ha preso il posto della parte non li divide, il cambio verso un altro non chiude l\'accordo, e l\'accordo con chi ha la riga resta com\'è (decisione 153.4)', function () {
    // Teo dal 15/11 al 30/11 al 20 % nei giorni di Elsa, poi la fine di Teo il 1/12, e nessuno dopo: sono i giorni dell'accordo (144, 148.1, Coda 367), e Teo non rimborsa niente. Il pannello del
    // suo inizio dice l'accordo. Sara 18148 con il conguaglio, Elsa senza sezione.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-11-01', 'non_scrivere');
    $frasi = p59Inizio($this, $s, 'Teo', '2026-11-15', 20);
    p59Passaggio($this, $s, 'Teo', null, '2026-12-01');
    $p = p59DallaRotta($this, $s);
    expect($frasi)->toContain('le parti lo hanno regolato fra loro')
        ->and(p59Breve($p)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'sara' => [18148, 0, 0, 184]]);

    // Il cambio Elsa → Pia del 15/11 senza la fine di Pia: Pia prende il posto di Elsa nell'accordo (148.1), che non finisce dentro la voce; Teo dal 1/12 al 20 % nei giorni di Pia non divide.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-11-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Elsa', 'Pia', '2026-11-15');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 20);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'sara' => [18148, 0, 0, 184]])
        ->and(p59PagateDa($p, 'Teo'))->toBe([]);

    // D-a (decisione 153.4, alla 1.12): l'accordo con chi ha la riga (Ines → Sara «Scrivi» il 1/7 annullato), la fine di Sara «Non scriverlo» il 1/10, Elsa dal 15/10 all'80 %: i giorni di chi ha
    // la riga restano nelle sue rate, ed Elsa non rimborsa niente. Ines 36000 nelle sue rate, il Wallet vuoto.
    $s = p59Scenario($this);
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    p59Passaggio($this, $s, 'Sara', null, '2026-10-01', 'non_scrivere');
    p59Inizio($this, $s, 'Elsa', '2026-10-15', 80);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe([])
        ->and(p59Breve($p))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]])
        ->and(p59PagateDa($p, 'Elsa'))->toBe([]);
});

// --- Il pannello dell'inizio nei giorni dell'accordo nomina chi ha i giorni con il conguaglio ------------------------------------------------------------------------------

it('Coda 413 — chi entra nei giorni dell\'accordo fra Sara e Zoe: il pannello del suo inizio dice che quei giorni li ha presi con il conguaglio Sara e che le parti hanno regolato l\'uscita di Sara, non quella di Ines', function () {
    // Il cambio del 1/7 «Scrivi» ha dato a Sara i giorni di Ines con il conguaglio (Ines − 18148, Sara + 18148 nel Wallet); le parti hanno regolato fra loro il cambio Sara → Zoe del 1/10
    // (decisioni 43 e 46). Il pannello diceva «il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro» con «la sua uscita» di Ines, che ha le righe: falso, quel
    // conguaglio è scritto. Chi entra nei giorni dell'accordo non rimborsa niente (Coda 367), e il prospetto lo conferma.
    $atteso = 'quei giorni li ha presi con il conguaglio Sara, e il conguaglio dei giorni dopo l\'uscita di Sara le parti lo hanno regolato fra loro';
    $falso = 'resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro';

    // Elsa dal 15/10 al 24/10 al 20 %, nei giorni di Zoe.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Elsa', '2026-10-15', 20);
    p59Passaggio($this, $s, 'Elsa', null, '2026-10-25');
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 15 ottobre 2026 resta nelle rate di Ines: ' . $atteso . '.')
        ->and($frasi)->not->toContain($falso)
        ->and(p59PagateDa(p59DallaRotta($this, $s), 'Elsa'))->toBe([]);

    // Teo dal 15/11 al 20 %, nei giorni di Elsa, entrata al posto di Zoe con «Non scriverlo» (148.1).
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Passaggio($this, $s, 'Zoe', 'Elsa', '2026-11-01', 'non_scrivere');
    $frasi = p59Inizio($this, $s, 'Teo', '2026-11-15', 20);
    expect($frasi)->toContain($atteso)->and($frasi)->not->toContain($falso);

    // La fine di Sara del 1/10 «Scrivi», poi «Annulla il conguaglio» (l'accordo senza chi entra), e Teo dal 15/10 all'80 %.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', null, '2026-10-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-10-15', 80);
    expect($frasi)->toContain($atteso)->and($frasi)->not->toContain($falso)
        ->and(p59PagateDa(p59DallaRotta($this, $s), 'Teo'))->toBe([]);
});

it('Coda 413 — la guardia del pannello: con l\'accordo fatto con chi ha la riga (il cambio Ines → Sara annullato) la frase resta quella di prima, perché è l\'uscita di Ines che le parti hanno regolato', function () {
    // Il cambio Ines → Sara del 1/7 annullato: i giorni dopo l'uscita di Ines restano nelle sue rate, regolati fra le parti (decisioni 43 e 46). Elsa dal 15/10, dopo la fine di Sara
    // «Non scriverlo» del 1/10: la frase di prima è vera, e resta (la domanda D-a, decisione 153.4, va alla 1.12).
    $s = p59Scenario($this);
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    p59Passaggio($this, $s, 'Sara', null, '2026-10-01', 'non_scrivere');
    $frasi = p59Inizio($this, $s, 'Elsa', '2026-10-15', 80);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 15 ottobre 2026 resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('con il conguaglio Sara');

    // Teo dal 1/9 al 20 %, nei giorni di Sara dopo il cambio annullato: lo stesso.
    $s = p59Scenario($this);
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-09-01', 20);
    expect($frasi)->toContain('resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('con il conguaglio Sara');

    // Due accordi nella stessa catena: Ines → Sara «Scrivi» il 1/7 annullato, Sara → Ines «Non scriverlo» il 1/9 (Ines rientra), Ines → Pia «Scrivi» il 1/10, Pia → Zoe «Scrivi» il 1/11
    // annullato; Teo dal 15/8 al 20 %. I giorni di Teo dal 15/8 sono prima i giorni che Ines e Sara hanno regolato fra loro (fino all'uscita di Ines del 1/10, Coda 379), poi quelli che Pia ha
    // con il conguaglio e ha regolato con Zoe: la frase con il solo nome di Pia sarebbe falsa per agosto e settembre, e resta quella dell'accordo di Ines.
    $s = p59Scenario($this);
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', 'Pia', '2026-10-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Pia', 'Zoe', '2026-11-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-08-15', 20);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 15 agosto 2026 resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('con il conguaglio Pia');

    // Due inquilini usciti, con due accordi diversi: Ines (50 %) → Sara «Scrivi» il 1/7, poi Sara → Zoe «Scrivi» il 1/10 annullato (i giorni li ha Sara con il conguaglio); Luca (30 %) → Pia
    // «Scrivi» il 1/8 annullato (l'accordo è con Luca, che ha le righe). Teo dal 15/10 al 20 %: la frase con il solo nome di Sara sarebbe falsa per i giorni di Luca, e resta quella di prima.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Luca', 30]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Luca', 'Pia', '2026-08-01', 'scrivi'));
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-10-15', 20);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 15 ottobre 2026 resta nelle rate di Ines e Luca: il conguaglio dei giorni dopo la loro uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('con il conguaglio Sara');
});

it('Coda 413 — chi entra nei giorni di due accordi: il pannello nomina tutti e due quelli che hanno i giorni con il conguaglio, al plurale, e dice che le parti hanno regolato la loro uscita', function () {
    // Ines (50 %) → Sara «Scrivi» il 1/7 e Luca (30 %) → Pia «Scrivi» il 1/8; poi le parti regolano fra loro Sara → Zoe (1/10) e Pia → Elsa (1/11), con «Annulla il conguaglio».
    // Teo dal 15/11 al 20 %: i giorni li hanno Sara e Pia con il conguaglio dei due cambi, e le parti hanno regolato la loro uscita, di tutti e due: «li hanno presi» e «la loro uscita».
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Luca', 30]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Luca', 'Pia', '2026-08-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Pia', 'Elsa', '2026-11-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-11-15', 20);

    expect($frasi)->toContain('resta nelle rate di Ines e Luca: quei giorni li hanno presi con il conguaglio Sara e Pia, e il conguaglio dei giorni dopo la loro uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('li ha presi con il conguaglio Sara e Pia');
});

it('Coda 413 — quando i giorni li ha presi con il conguaglio la stessa persona che ha le righe, il pannello non la nomina due volte: dice che le parti hanno regolato la sua uscita', function () {
    // Il cambio Ines → Sara del 1/7 «Scrivi»; Sara → Ines «Scrivi» il 1/9 (Ines riprende i suoi giorni con il conguaglio); Ines → Zoe «Scrivi» il 1/10 con «Annulla il conguaglio»
    // (le parti l'hanno regolato fra loro). Teo dal 15/10 al 20 %: nelle rate di Ines, che ha le righe e di cui è l'uscita regolata. «Quei giorni li ha presi con il conguaglio Ines»
    // accanto a «resta nelle rate di Ines» ripeteva il nome senza dire altro: la frase vera è quella dell'accordo con chi ha le righe.
    $s = p59Scenario($this);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'scrivi');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Zoe', '2026-10-01', 'scrivi'));
    $frasi = p59Inizio($this, $s, 'Teo', '2026-10-15', 20);

    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 15 ottobre 2026 resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and($frasi)->not->toContain('con il conguaglio Ines');
});

// --- Un piano che solo un passaggio dopo ha preso ------------------------------------------------------------------------------------------------------------------------

/** Un secondo piano ordinario sulla stessa gestione, «Integrativo 2026», con la sola voce «Pulizie» € 365,00 (30 % all'inquilino), dalle pagine; approvato e generato. Con un nome e una voce dati, un altro piano uguale. */
function p59SecondoPiano($test, array $s, string $piano = 'Integrativo 2026', string $voce = 'Pulizie'): \App\Models\Gestionale\PianoRate
{
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => $voce, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '365,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $conto = \App\Models\Gestionale\Conto::where('nome', $voce)->where('piano_conto_id', $pc->id)->firstOrFail();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => $piano, 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => [['id' => $conto->id, 'importo' => '365,00', 'note' => '']],
    ])->assertSessionHasNoErrors();
    $p2 = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', $piano)->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $p2]), [
        'approvato' => true, 'data_delibera_assemblea' => '2025-12-15',
    ])->assertSessionHasNoErrors();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($p2->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione', esercizio: $s['e']);

    return $p2->fresh();
}

/** L'emissione vera, dalla rotta della pagina del piano, delle rate del piano dato ancora in bozza fino al giorno dato. */
function p59Emetti($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
}

/** Le voci di un conto, per conduttore: [importo, modo, pagata da, giorni]; «senza» per i giorni senza conduttore. */
function p59Voci(array $p, string $conto): array
{
    $out = [];
    foreach ($p['conduttori'] as $c) {
        foreach ($c['voci'] as $v) {
            if (str_starts_with((string) $v['conto'], $conto)) {
                $out[$c['nome']][] = [(int) $v['importo'], $v['modo'], $v['pagato_da'] ?? null, $v['giorni'] ?? null];
            }
        }
    }
    foreach ($p['senza_conduttore']['voci'] as $v) {
        if (str_starts_with((string) $v['conto'], $conto)) {
            $out['senza'][] = [(int) $v['importo'], $v['modo'], $v['pagato_da'] ?? null, $v['giorni'] ?? null];
        }
    }
    ksort($out);

    return $out;
}

/**
 * La storia della decisione 145 con un secondo piano: Ines sola all'80 %, il «Preventivo 2026» (P1) emesso fino a giugno e l'«Integrativo 2026» (P2) generato; P2 emesso fino a giugno
 * (`prima`), fino a ottobre (`ottobre`) o mai (`mai`); Ines → Sara «Scrivi» il 1/7, Sara → Ines «Non scriverlo» il 1/9, poi `$terza` il 1/12: la fine di Ines «Scrivi» (`fine`) o il cambio
 * Ines → Teo «Scrivi» (`cambio`); con la fine, Teo dal 1/12 all'80 %. Lo scenario, i passaggi e le frasi del pannello dell'inizio di Teo.
 */
function p59DuePiani($test, string $quando, string $terza = 'fine'): array
{
    $s = p59Scenario($test);
    $p2 = p59SecondoPiano($test, $s);
    if ($quando === 'prima') {
        p59Emetti($test, $s, $p2, '2026-06-30');
    }
    $a = p59Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $b = p59Passaggio($test, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    if ($quando === 'ottobre') {
        p59Emetti($test, $s, $p2, '2026-10-31');
    }
    $c = p59Passaggio($test, $s, 'Ines', $terza === 'cambio' ? 'Teo' : null, '2026-12-01', 'scrivi');
    $frasi = $terza === 'fine' ? p59Inizio($test, $s, 'Teo', '2026-12-01', 80) : '';

    return [$s, $p2, [$a, $b, $c], $frasi];
}

it('Coda 413 — un secondo piano emesso in ottobre, che il cambio del 1/7 non aveva preso e la fine di Ines del 1/12 sì: Teo rimborsa a Ugo anche il suo dicembre, € 9,30, come il registro del suo inizio', function () {
    // P2 «Pulizie» 10950 all'inquilino, nelle rate di Ines. Il cambio del 1/7 e il ritorno del 1/9 non l'hanno preso (le rate non erano emesse: «si ricalcola ancora»), e i giorni di Sara su P2
    // restano nelle rate di Ines (decisione 43: su un piano che la coppia non ha preso la voce resta com'è). La fine di Ines del 1/12 «Scrivi» l'ha preso, e ha scritto il suo dicembre: 10950 ×
    // 31/365 = 930 (Ines − 930, Ugo + 930). Teo, conduttore di dicembre, lo rimborsa a Ugo (decisione 45, nota del 12/10), come il P1 (3058, decisione 145): 3988, come il registro del suo inizio.
    // Ines su P2: 10950 × 334/365 = 10020 nelle sue rate. Ines 26827 + 10020 = 36847, nelle sue rate 14794 + 10020 = 24814 (il Wallet: 46950 di rate e − 22136 di saldi), da rimborsare a Sara
    // 12033 (P1, decisione 145); Sara 6115 com'è; il controllo 36847 + 6115 + 3988 = 46950. Era: la catena di P2 si fermava al cambio del 1/7, Ines teneva tutti i 10950 e Ugo restava con € 9,30
    // che nessuno gli rimborsava.
    [$s, $p2, [$a, $b, $c], $frasi] = p59DuePiani($this, 'ottobre');
    expect(array_map(fn (Subentro $x) => array_map('intval', $x->fresh()->registro['piani_presi'] ?? []), [$a, $b, $c]))->toBe([[(int) $s['piano']->id], [(int) $s['piano']->id], [(int) $s['piano']->id, (int) $p2->id]]);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -22136, 'Sara' => 18148, 'Ugo' => 3988])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [36847, 24814, 12033, 606],
            'sara' => [6115, 0, -12033, 62],
            'teo' => [3988, 0, 3988, 62],
        ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10020, 'rate', null, 334]], 'Teo' => [[930, 'proprietario', 'Ugo', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3988])
        ->and(p59GiorniDelConduttore($s, 'Teo'))->toBe([[3988, ['Ugo']]])
        ->and($frasi)->toContain('Il piano «Integrativo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha. La parte a carico dell\'inquilino dal 1 dicembre 2026 '
            . 'resta nelle rate di Ines: i giorni dopo la sua uscita li ha presi con il conguaglio Ugo, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori.')
        ->and($frasi)->not->toContain('lo decide l\'amministratore');
});

it('Coda 413 — lo stesso secondo piano con il cambio Ines → Teo «Scrivi» del 1/12 al posto della fine: Teo ha il dicembre di P2 con il conguaglio, come nel Wallet', function () {
    // Il cambio Ines → Teo del 1/12 ha preso P2 e ha dato a Teo il suo dicembre con il conguaglio, 930 (decisione 77.3): Teo «con il conguaglio», non da rimborsare. Ines su P2 10020.
    [$s, $p2, [, , $c]] = p59DuePiani($this, 'ottobre', 'cambio');
    expect(array_map('intval', $c->fresh()->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id, (int) $p2->id]);
    $p = p59DallaRotta($this, $s);
    expect(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10020, 'rate', null, 334]], 'Teo' => [[930, 'conguaglio', null, 31]]]);
});

it('Coda 413 — le guardie del secondo piano: emesso prima del 1/7 lo prendono tutti i passaggi, mai emesso non lo prende nessuno, e il prospetto resta com\'era', function () {
    // Emesso fino a giugno: P2 come P1. Il cambio del 1/7 dà a Sara 10950 × 184/365 = 5520; al 1/9 Sara 62 giorni, 1860, e dopo 3660, che Ines rimborsa a Sara (decisione 145): 2730 fino al 30/11
    // e 930 di dicembre, che la fine di Ines ha scritto a Ugo (Teo lo rimborsa a Ugo). Ines su P2 5430 + 2730 + 930 − 930 = 8160, Sara 1860, Teo 930. Ines 26827 + 8160 = 34987, nelle sue rate
    // 14794 + 5430 − 930 = 19294 (il Wallet: 46950 − 27656), da rimborsare 12033 + 3660 = 15693; Sara 6115 + 1860 = 7975; Teo 3988.
    [$s] = p59DuePiani($this, 'prima');
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -27656, 'Sara' => 23668, 'Ugo' => 3988])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [34987, 19294, 15693, 544],
            'sara' => [7975, 0, -15693, 124],
            'teo' => [3988, 0, 3988, 62],
        ], 'senza' => [0, 0]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3988]);

    // Mai emesso: nessun passaggio l'ha preso, e si ricalcola ancora; P2 resta nelle rate di Ines, 10950 per 365 giorni (decisione 43), e Teo rimborsa a Ugo solo il P1, 3058.
    [$s, $p2, [, , $c]] = p59DuePiani($this, 'mai');
    expect(array_map('intval', $c->fresh()->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id]);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -21206, 'Sara' => 18148, 'Ugo' => 3058])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [37777, 25744, 12033, 637],
            'sara' => [6115, 0, -12033, 62],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10950, 'rate', null, 365]]]);
});

/**
 * La strada della decisione 83.3 con il secondo piano: Ines → Sara «Scrivi» il 1/7 con «Annulla il conguaglio», P2 emesso fino a giugno (`prima`), fino a ottobre (`ottobre`) o mai
 * (`mai`), la fine di Sara «Scrivi» il 1/12, Teo dal 1/12 all'80 %. Lo scenario, il cambio, la fine e le frasi del pannello dell'inizio di Teo.
 */
function p59RegolatoConDuePiani($test, string $quando): array
{
    $s = p59Scenario($test);
    $p2 = p59SecondoPiano($test, $s);
    if ($quando === 'prima') {
        p59Emetti($test, $s, $p2, '2026-06-30');
    }
    $a = p59Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Annulla($test, $s, $a);
    if ($quando === 'ottobre') {
        p59Emetti($test, $s, $p2, '2026-10-31');
    }
    $c = p59Passaggio($test, $s, 'Sara', null, '2026-12-01', 'scrivi');
    $frasi = p59Inizio($test, $s, 'Teo', '2026-12-01', 80);

    return [$s, $p2, [$a, $c], $frasi];
}

it('Coda 413 — dopo il cambio Ines → Sara annullato, un secondo piano emesso in ottobre che il cambio non aveva preso: la fine di Sara non ne ha la coppia, e Teo non rimborsa a Ugo € 9,30 che Ugo non ha nel Wallet', function () {
    // Il cambio del 1/7 ha preso solo P1 (decisione 42: P2 non era emesso, «si ricalcola ancora»), e l'accordo fra Ines e Sara è il suo conguaglio, 18148 su P1 (decisione 46: `regolato_fuori`).
    // P2, emesso in ottobre senza ricalcolo, ha tutte le quote nelle rate di Ines (decisione 40). La fine di Sara del 1/12 l'ha preso, ma la sua coppia scrive solo il dicembre di P1: Sara −
    // 3058, Ugo + 3058 (18148 × 31/184 = 3057,52 → 3058, il centesimo al resto maggiore; Sara 15090). Su P1 la catena della 83.3 resta: Ines 17852, Sara 15090 «regolata fra le parti», Teo
    // 3058 a Ugo. Su P2 la catena non segue la fine di Sara, che non ne ha la coppia: il cambio regolato fra le parti si legge come senza la fine dopo (decisioni 43 e 46), e P2 resta tutto
    // nelle rate di Ines, 10950; dopo un accordo con chi ha la riga l'uscita della parte non chiude l'accordo (decisione 153.4, la domanda D-a alla 1.12): Teo non rimborsa niente su P2. Ines
    // 17852 + 10950 = 28802, tutto nelle sue rate; Sara 15090; Teo 3058, come il Wallet e come il registro del suo inizio; il controllo 28802 + 15090 + 3058 = 46950. Era: Sara 4590 «regolata
    // fra le parti» su P2 (153 giorni) e Teo 930 «pagata da Ugo», che non li ha mai avuti, come diceva anche il pannello.
    [$s, $p2, [$a, $c], $frasi] = p59RegolatoConDuePiani($this, 'ottobre');
    expect(array_map(fn (Subentro $x) => array_map('intval', $x->fresh()->registro['piani_presi'] ?? []), [$a, $c]))->toBe([[(int) $s['piano']->id], [(int) $s['piano']->id, (int) $p2->id]]);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Sara' => -3058, 'Ugo' => 3058])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [28802, 28802, 0, 546],
            'sara' => [15090, 0, 0, 153],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10950, 'rate', null, 365]]])
        ->and(p59Voci($p, 'Spese generali'))->toBe(['Ines' => [[17852, 'rate', null, 181]], 'Sara' => [[15090, 'regolato', null, 153]], 'Teo' => [[3058, 'proprietario', 'Ugo', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3058])
        ->and(p59GiorniDelConduttore($s, 'Teo'))->toBe([[3058, ['Ugo']]])
        ->and($frasi)->toContain('Il piano «Integrativo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha. La parte a carico dell\'inquilino dal 1 dicembre 2026 '
            . 'resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and(substr_count($frasi, 'li ha presi Ugo con il conguaglio della fine locazione del 1 dicembre 2026, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori.'))->toBe(1);
});

it('Coda 413 — con il cambio Ines → Sara «Scrivi» non annullato, un secondo piano emesso in ottobre che il cambio non aveva preso e la fine di Sara: chi ha le quote (Ines) non esce, e Teo non rimborsa niente su quel piano', function () {
    // Il cambio del 1/7 ha preso solo P1 (P2 non era emesso, «si ricalcola ancora»): Ines − 18148, Sara + 18148. P2, emesso in ottobre, ha tutte le quote nelle rate di Ines (10950 per i suoi
    // 365 giorni). La fine di Sara del 1/12 «Scrivi» scrive solo il dicembre di P1, 18148 × 31/184 = 3057,52 → 3058 (Sara − 3058, Ugo + 3058), e Teo, dal 1/12 all'80 %, lo rimborsa a Ugo.
    // Sulle quote di P2 il prospetto legge il cambio come se non ci fosse: Ines non esce, quindi nessuno entra dopo una sua uscita e nessuno rimborsa niente. Ines 17852 + 10950 = 28802,
    // Sara 18148 − 3058 = 15090 con il conguaglio, Teo 3058 a Ugo; il controllo 28802 + 15090 + 3058 = 46950.
    $s = p59Scenario($this);
    $p2 = p59SecondoPiano($this, $s);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Emetti($this, $s, $p2, '2026-10-31');
    p59Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);

    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Ugo' => 3058])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10950, 'rate', null, 365]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3058])
        ->and(p59Breve($p)['conduttori']['ines'][0] + p59Breve($p)['conduttori']['sara'][0] + p59Breve($p)['conduttori']['teo'][0])->toBe(46950);
});

it('Coda 413 — la strada 83.3 con il secondo piano mai emesso, e la guardia del piano emesso prima del cambio: chi entra rimborsa solo ciò che la coppia della fine ha scritto', function () {
    // Mai emesso: nessun passaggio l'ha preso, e si ricalcola ancora (decisione 43). P2 resta nelle rate di Ines, 10950, come senza la fine (la guardia «mai» della decisione 145 con due piani):
    // Sara non ha mai pagato P2, e Teo non le rimborsa niente. Era: Sara 4590 «regolata fra le parti», 930 «regolata» e − 930 da restituire, e Teo 930 «pagata da Sara».
    [$s] = p59RegolatoConDuePiani($this, 'mai');
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Sara' => -3058, 'Ugo' => 3058])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10950, 'rate', null, 365]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3058]);

    // Emesso fino a giugno: il cambio del 1/7 ha preso anche P2, e la coppia della fine di Sara ha anche il suo dicembre: 10950 × 184/365 = 5520 a Sara, 5520 × 31/184 = 930 a Ugo (Sara −
    // 3988, Ugo + 3988). P2 come P1: Ines 5430, Sara 4590 «regolata fra le parti», Teo 930 a Ugo; Teo rimborsa a Ugo 3988, come il registro del suo inizio. Com'era.
    [$s, $p2, [$a, $c]] = p59RegolatoConDuePiani($this, 'prima');
    expect(array_map('intval', $a->fresh()->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id, (int) $p2->id]);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Sara' => -3988, 'Ugo' => 3988])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[5430, 'rate', null, 181]], 'Sara' => [[4590, 'regolato', null, 153]], 'Teo' => [[930, 'proprietario', 'Ugo', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 3988])
        ->and(p59GiorniDelConduttore($s, 'Teo'))->toBe([[3988, ['Ugo']]]);
});

/**
 * La storia della decisione 145 con il secondo piano emesso il 31/12, dopo l'ultimo passaggio (`$cambi` false: senza il cambio del 1/7 e il ritorno del 1/9, la fine di Ines e basta);
 * `$luca`: Luca entra il 1/12 all'80 % al posto di Teo, e dopo l'emissione registra il cambio Luca → Pia del 15/12 (`$lucaDopo`: anche l'inizio di Luca si registra dopo l'emissione). Lo
 * scenario, P2, la fine di Ines e le frasi del pannello di chi entra il 1/12.
 */
function p59EmessoDopo($test, bool $cambi = true, bool $luca = false, bool $lucaDopo = false): array
{
    $s = p59Scenario($test);
    $p2 = p59SecondoPiano($test, $s);
    if ($cambi) {
        p59Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        p59Passaggio($test, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    }
    $c = p59Passaggio($test, $s, 'Ines', null, '2026-12-01', 'scrivi');
    $frasi = $luca && ! $lucaDopo ? p59Inizio($test, $s, 'Luca', '2026-12-01', 80) : '';
    p59Emetti($test, $s, $p2, '2026-12-31');
    if ($luca) {
        $frasi = $lucaDopo ? p59Inizio($test, $s, 'Luca', '2026-12-01', 80) : $frasi;
        p59Passaggio($test, $s, 'Luca', 'Pia', '2026-12-15');
    } else {
        $frasi = p59Inizio($test, $s, 'Teo', '2026-12-01', 80);
    }

    return [$s, $p2, $c, $frasi];
}

it('Coda 413 — un secondo piano emesso dopo l\'ultimo passaggio, che nessun passaggio ha preso: si legge come se il cambio del 1/7 non ci fosse, e Teo rimborsa a Ines il suo dicembre, € 9,30', function () {
    // Decisione 156.2: P2 emesso il 31/12 senza ricalcolo, dopo la fine di Ines del 1/12, che non l'ha preso (`piani_presi` [P1]). Le sue quote sono tutte nelle rate di Ines (decisione 40: chi emette
    // senza ricalcolare fa pagare l'inquilino uscito). Il cambio del 1/7 e il ritorno del 1/9 non l'hanno preso: i giorni di Sara restano nelle rate di Ines (decisione 43), e la fine di Ines si
    // legge come senza i cambi, con la strada A′ (nessun conguaglio su P2): 10950 × 334/365 = 10020 a carico di Ines, e dicembre 10950 × 31/365 = 930 nelle sue rate, da restituirle; Teo
    // glielo rimborsa. P1 com'è (decisione 145): Ines 26827, di cui 14794 nelle sue rate, da rimborsare a Sara 12033; Sara 6115; Teo 3058 a Ugo. Ines 26827 + 10020 = 36847, nelle sue rate
    // 14794 + 10950 = 25744, da rimborsare 12033 − 930 = 11103; Teo 3058 + 930 = 3988; il controllo 36847 + 6115 + 3988 = 46950. Il registro dell'inizio di Teo ha solo la coppia della fine
    // (decisione 85.4): 3058 a Ugo. Era: la catena di P2 si fermava al cambio del 1/7, Ines teneva tutti i 10950, e il pannello diceva «lo decide l'amministratore».
    [$s, $p2, $c, $frasi] = p59EmessoDopo($this);
    expect(array_map('intval', $c->fresh()->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id]);
    $p = p59DallaRotta($this, $s);
    $restituzione = [[10020, 'rate', null, 334], [930, 'rate', null, null], [-930, 'restituzione', null, null]];
    expect(fclSaldi($s))->toBe(['Ines' => -21206, 'Sara' => 18148, 'Ugo' => 3058])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [36847, 25744, 11103, 606],
            'sara' => [6115, 0, -12033, 62],
            'teo' => [3988, 0, 3988, 62],
        ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => $restituzione, 'Teo' => [[930, 'proprietario', 'Ines', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ines' => 930, 'Ugo' => 3058])
        ->and(p59GiorniDelConduttore($s, 'Teo'))->toBe([[3058, ['Ugo']]])
        ->and($frasi)->toContain('Il piano «Integrativo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha. La parte a carico dell\'inquilino dal 1 dicembre 2026 '
            . 'resta nelle rate di Ines: i giorni dopo la sua uscita li rimborsa Teo a Ines, con il prospetto degli oneri accessori.')
        ->and($frasi)->not->toContain('lo decide l\'amministratore');

    // Come se il cambio non ci fosse: senza il cambio del 1/7 e il ritorno del 1/9 le righe di P2 sono le stesse (decisione 40, la strada A′ della fine che non l'ha preso).
    [$s] = p59EmessoDopo($this, cambi: false);
    $p = p59DallaRotta($this, $s);
    expect(p59Voci($p, 'Pulizie'))->toBe(['Ines' => $restituzione, 'Teo' => [[930, 'proprietario', 'Ines', 31]]]);
});

it('Coda 413 — lo stesso con un\'altra unità nel secondo piano: il cambio d\'inquilino dell\'Interno 2, registrato dopo l\'emissione, prende il piano per l\'Interno 2, e sull\'Interno 1 Teo rimborsa a Ines il suo dicembre', function () {
    // Decisioni 156.2 e 158: il piano fermo (`PianoRate::eImmutabile`, che guarda il piano intero) si legge come se il cambio che non l'aveva preso non ci fosse, anche se l'ha preso poi il passaggio
    // di un'altra unità. L'Interno 2 (1.000 millesimi come l'Interno 1, aggiunto dopo la generazione di P1: P1 resta tutto dell'Interno 1) entra in P2, che si divide a metà: all'inquilino
    // dell'Interno 1 10950 / 2 = 5475, 15 al giorno. Dora → Eva «Scrivi» il 15/12 sull'Interno 2, registrato dopo l'emissione, prende P2 per l'Interno 2; sull'Interno 1 la fine di Ines si
    // legge come senza i cambi: 15 × 334 = 5010 a carico di Ines, dicembre 15 × 31 = 465 che Teo le rimborsa. Ines 26827 + 5010 = 31837, nelle sue rate 14794 + 5475 = 20269, da rimborsare 12033 − 465 = 11568; Teo 3058 + 465 = 3523; il controllo 31837 + 6115 + 3523 = 36000 + 5475.
    $s = p59Scenario($this);
    $interno2 = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'FCL-I2-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $interno2->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Olga', 'proprietario', 100], ['Dora', 'inquilino', 80]] as [$nome, $ruolo, $quota]) {
        $persona = fclPersona($s, $nome);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $interno2->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $p2 = p59SecondoPiano($this, $s);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    p59Emetti($this, $s, $p2, '2026-12-31');
    $s2 = array_replace($s, ['unita' => $interno2]);
    $dora = p59Passaggio($this, $s2, 'Dora', 'Eva', '2026-12-15', 'scrivi');
    expect(array_map('intval', $dora->fresh()->registro['piani_presi'] ?? []))->toContain((int) $p2->id);
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p))->toBe(['conduttori' => [
        'ines' => [31837, 20269, 11568, 606],
        'sara' => [6115, 0, -12033, 62],
        'teo' => [3523, 0, 3523, 62],
    ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[5010, 'rate', null, 334], [465, 'rate', null, null], [-465, 'restituzione', null, null]], 'Teo' => [[465, 'proprietario', 'Ines', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ines' => 465, 'Ugo' => 3058]);
});

it('Coda 413 — il secondo piano fermo per un incasso su una sua quota, senza quote a giornale: si legge come quello emesso, e Teo rimborsa a Ines il suo dicembre, € 9,30', function () {
    // Decisione 158: basta che il piano non si ricalcoli più (`PianoRate::eImmutabile`: una quota a giornale, un movimento su una quota o un conguaglio di un passaggio). Qui P2 non ha quote a
    // giornale e nessun passaggio l'ha preso (`piani_presi` [P1]): ha un incasso su una sua quota, e il ricalcolo lo rifiuta. L'incasso si scrive sulla quota (`importo_pagato`), perché la rotta
    // degli incassi prende solo rate emesse, ed è la forma che `PianoRate::haIncassiRegistrati` riconosce da sola. Le quote restano a chi le aveva (decisione 40), e le cifre sono quelle
    // della storia del piano emesso dopo l'ultimo passaggio (decisioni 43, 145 e 156.2): Ines 26827 + 10020 = 36847, nelle sue rate 14794 + 10950 = 25744, da rimborsare 12033 − 930 = 11103;
    // Sara 6115; Teo 3058 + 930 = 3988; il controllo 36847 + 6115 + 3988 = 46950.
    $s = p59Scenario($this);
    $p2 = p59SecondoPiano($this, $s);
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    $c = p59Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $p2->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 100]);
    expect(array_map('intval', $c->fresh()->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id])
        ->and([$p2->fresh()->haRateEmesse(), $p2->fresh()->haIncassiRegistrati(), $p2->fresh()->conguagliato()])->toBe([false, true, false]);
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p))->toBe(['conduttori' => [
        'ines' => [36847, 25744, 11103, 606],
        'sara' => [6115, 0, -12033, 62],
        'teo' => [3988, 0, 3988, 62],
    ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10020, 'rate', null, 334], [930, 'rate', null, null], [-930, 'restituzione', null, null]], 'Teo' => [[930, 'proprietario', 'Ines', 31]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ines' => 930, 'Ugo' => 3058]);
});

it('Coda 413 — con un terzo piano mai emesso accanto al secondo, emesso dopo l\'ultimo passaggio: il secondo si legge come se il cambio non ci fosse, il terzo resta nelle rate di Ines', function () {
    // Il piano fermo si guarda piano per piano: P2 (emesso il 31/12, dopo la fine di Ines) è fermo e Teo rimborsa a Ines il suo dicembre, 930 (decisione 158, come nella storia con un solo
    // secondo piano); P3, «Manutenzione 2026» con la voce «Giardino» € 365,00 come P2 (10950 all'inquilino), non è mai emesso e si ricalcola ancora: nessun passaggio l'ha preso e la catena si
    // ferma al cambio del 1/7 (decisione 43), tutto nelle rate di Ines, 10950 per 365 giorni. Ines 36847 + 10950 = 47797, nelle sue rate 25744 + 10950 = 36694, da rimborsare 11103; Sara 6115;
    // Teo 3988; il controllo 47797 + 6115 + 3988 = 57900 = 46950 + 10950.
    $s = p59Scenario($this);
    $p2 = p59SecondoPiano($this, $s);
    p59SecondoPiano($this, $s, 'Manutenzione 2026', 'Giardino');
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    p59Emetti($this, $s, $p2, '2026-12-31');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    expect(p59Breve($p))->toBe(['conduttori' => [
        'ines' => [47797, 36694, 11103, 971],
        'sara' => [6115, 0, -12033, 62],
        'teo' => [3988, 0, 3988, 62],
    ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => [[10020, 'rate', null, 334], [930, 'rate', null, null], [-930, 'restituzione', null, null]], 'Teo' => [[930, 'proprietario', 'Ines', 31]]])
        ->and(p59Voci($p, 'Giardino'))->toBe(['Ines' => [[10950, 'rate', null, 365]]])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ines' => 930, 'Ugo' => 3058]);
});

it('Coda 413 — il secondo piano emesso dopo la fine di Ines e preso poi dal cambio di chi è entrato dopo (Luca → Pia il 15/12): basta che il piano non si ricalcoli più, e Luca e Pia rimborsano a Ines il dicembre di P2, € 4,20 e € 5,10', function () {
    // Decisione 158 (che allarga la 156.2): il cambio del 1/7 e il ritorno del 1/9 non hanno preso P2, emesso il 31/12 dopo la fine di Ines senza ricalcolo; per leggerlo come se quei cambi non
    // ci fossero basta che il piano non si ricalcoli più (`PianoRate::eImmutabile`), anche se poi l'ha preso il passaggio di un altro: il cambio Luca → Pia del 15/12, registrato dopo
    // l'emissione (`piani_presi` [P1, P2]). Le quote di Ines non dipendono dal passaggio di un altro. I giorni di Sara restano nelle rate di Ines (decisione 43), e la fine di Ines si legge con
    // la strada A′ (nessun conguaglio su P2, decisione 40): 10950 × 334/365 = 10020 a carico di Ines, dicembre 10950 × 31/365 = 930 nelle sue rate, da restituirle; lo rimborsano chi è
    // entrato, per quota (80 % tutti e due) e per giorni: Luca 14 giorni 930 × 14/31 = 420, Pia 17 giorni 930 × 17/31 = 510. P1 com'è: il dicembre che la fine di Ines ha scritto a Ugo, 3058,
    // lo rimborsano Luca per 14 giorni, 3058 × 14/31 = 1381,03 → 1381, e Pia per 17, 1677. Ines 26827 + 10020 = 36847, nelle sue rate 14794 + 10950 = 25744, da rimborsare 12033 − 930 = 11103;
    // Sara 6115; Luca 1381 + 420 = 1801; Pia 1677 + 510 = 2187; il controllo 36847 + 6115 + 1801 + 2187 = 46950. Era: la catena di P2 si fermava al cambio del 1/7, Ines teneva tutti i
    // 10950 (37777, da rimborsare 12033) e Luca e Pia non le rimborsavano niente.
    [$s, $p2] = p59EmessoDopo($this, luca: true);
    $cambio = Subentro::where('immobile_id', $s['unita']->id)->where('anagrafica_uscente_id', $s['persone']['Luca']->id)->firstOrFail();
    expect(array_map('intval', $cambio->registro['piani_presi'] ?? []))->toBe([(int) $s['piano']->id, (int) $p2->id]);
    $p = p59DallaRotta($this, $s);
    $restituzione = [[10020, 'rate', null, 334], [930, 'rate', null, null], [-930, 'restituzione', null, null]];
    expect(fclSaldi($s))->toBe(['Ines' => -21206, 'Sara' => 18148, 'Ugo' => 3058])
        ->and(p59Breve($p))->toBe(['conduttori' => [
            'ines' => [36847, 25744, 11103, 606],
            'luca' => [1801, 0, 1801, 28],
            'pia' => [2187, 0, 2187, 34],
            'sara' => [6115, 0, -12033, 62],
        ], 'senza' => [0, 0]])
        ->and(p59Voci($p, 'Pulizie'))->toBe(['Ines' => $restituzione, 'Luca' => [[420, 'proprietario', 'Ines', 14]], 'Pia' => [[510, 'proprietario', 'Ines', 17]]])
        ->and(p59PagateDa($p, 'Luca'))->toBe(['Ines' => 420, 'Ugo' => 1381])
        ->and(p59PagateDa($p, 'Pia'))->toBe(['Ines' => 510, 'Ugo' => 1677]);
});

it('Coda 413 — l\'inizio di Luca registrato dopo l\'emissione di P2: il pannello dice che Luca rimborsa a Ines il dicembre di P2, e dopo il cambio Luca → Pia il prospetto dice lo stesso, € 4,20 e € 5,10', function () {
    // Decisione 158: il pannello dell'inizio di Luca, calcolato con P2 già emesso e nessun passaggio che lo abbia preso, legge la strada A′ (decisione 40): la parte dell'inquilino dal 1 dicembre
    // resta nelle rate di Ines e i giorni dopo la sua uscita li rimborsa Luca a Ines. Il cambio Luca → Pia del 15/12 prende poi P2 (`piani_presi` [P1, P2]): il prospetto non deve smentire il
    // pannello, e divide i 930 del dicembre di P2 per i giorni, 14 e 17 su 31: 420 a Luca e 510 a Pia, «pagata da Ines». Il registro dell'inizio di Luca ha solo la coppia della fine di Ines,
    // 3058 a Ugo (decisione 85.4), come quello di Teo nella storia senza Luca. Era: Ines teneva i 10950 di P2 e Luca e Pia non le rimborsavano niente, contro la frase del pannello.
    [$s, , , $frasi] = p59EmessoDopo($this, luca: true, lucaDopo: true);
    $p = p59DallaRotta($this, $s);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 1 dicembre 2026 resta nelle rate di Ines: i giorni dopo la sua uscita li rimborsa Luca a Ines, con il prospetto degli oneri accessori.')
        ->and(\Illuminate\Support\Arr::only(p59Voci($p, 'Pulizie'), ['Luca', 'Pia']))->toBe(['Luca' => [[420, 'proprietario', 'Ines', 14]], 'Pia' => [[510, 'proprietario', 'Ines', 17]]])
        ->and(p59PagateDa($p, 'Luca'))->toBe(['Ines' => 420, 'Ugo' => 1381])
        ->and(p59PagateDa($p, 'Pia'))->toBe(['Ines' => 510, 'Ugo' => 1677])
        ->and(p59GiorniDelConduttore($s, 'Luca'))->toBe([[3058, ['Ugo']]]);
});

// --- La riga del credito di chi riprende i giorni, con i giorni che nessuno copriva (Coda 380, R2) ---------------------------------------------------------------------------

/** Le righe di un conduttore, ordinate: [voce, importo, modo, pagata da, nota]. */
function p59Righe(array $p, string $nome): array
{
    $c = collect($p['conduttori'])->firstWhere('nome', $nome);

    return collect($c['voci'] ?? [])->map(fn ($v) => [(string) $v['conto'], (int) $v['importo'], (string) $v['modo'], $v['pagato_da'] ?? null, $v['nota'] ?? null])
        ->sortBy(fn ($r) => sprintf('%s|%s|%012d|%s', $r[0], $r[2], $r[1] + 500000000, (string) $r[4]))->values()->all();
}

/** Il testo della stampa: la vista del PDF resa con i dati che la rotta le ha passato (tag tolti, spazi uniti). */
function p59Stampa($test, array $s): string
{
    $prospetto = p59DallaRotta($test, $s);
    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $s['unita']->fresh(), 'prospetto' => $prospetto,
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();

    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

it('Coda 413 — la storia della Coda 380 con € 120,00 di già versato e la riga di Ugo chiusa il 15/12: il credito di Ines è la coppia del Wallet, € 13,32, non tutto dicembre', function () {
    // Il già versato «dell'unità» € 120,00 sulle «Spese generali» (decisione 17, D8): all'inquilino 3600, la riga di Ines al netto 32400. Il cambio Ines → Sara «Scrivi» del 1/7, al netto,
    // 32400 × 184/365 = 16333,15 → 16333 (Ines − 16333, Sara + 16333); Sara → Ines «Non scriverlo» il 1/9: Sara 62 giorni, 16333 × 62/184 = 5503,51 → 5504, dopo 10829, che Ines le rimborsa
    // tutti (decisione 145; 8077 fino al 30/11 e 2752 di dicembre). La fine di Ines «Scrivi» del 1/12 scrive la coppia solo per i giorni in cui Ugo stava al posto dell'inquilino, fino al 15/12
    // (R2): 2752 × 15/31 = 1331,61 → 1332 (Ines − 1332, Ugo + 1332); gli altri 16 giorni, 1420, non sono nella coppia. Teo, dal 1/12 all'80 %, rimborsa 1332 a Ugo e 1420 a Ines, che li paga a Sara.
    // Il credito di Ines nel Wallet è 1332, e la sua riga lo dice; i 1420 dei giorni che nessuno copriva sono una riga a parte, verso Sara. Le righe dei giorni scoperti «nelle sue rate» (lordo
    // 3058 × 16/31 = 1578,32 → 1578, e il già versato 158) non ci sono più: quei giorni Ines li aveva ceduti a Sara il 1/7, e le sue rate non li hanno. Il già versato della riga di Ines: −3600 +
    // 611 + 898 + 306 = −1785, i suoi 181 giorni (17852 − 16067). Ines: 8077 + 1332 + 1420 + 17852 − 1332 − 1785 − 1420 = 24144, nelle sue rate 17852 − 1332 − 1785 = 14735 (il Wallet),
    // da rimborsare 9409: i totali di prima. Era: il credito € −27,52, e € 15,78 «nelle sue rate» per giorni che le sue rate non hanno.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    // La riga di Ugo chiusa il 15/12 da «Modifica associazione», senza un passaggio: dal 16/12 nessuno sta al posto dell'inquilino.
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], fclRiga($s, 'Ugo', 'proprietario')]), [
        'anagrafica_id' => $s['persone']['Ugo']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-12-15', 'note' => '',
    ])->assertSessionHasNoErrors();
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    $nota = 'giorni dopo la sua uscita del 01/12/2026, passati ad altri con il conguaglio di quella uscita';
    expect(fclSaldi($s))->toBe(['Ines' => -17665, 'Sara' => 16333, 'Ugo' => 1332])
        ->and(p59Breve($p)['conduttori'])->toBe([
            'ines' => [24144, 14735, 9409, 272],
            'sara' => [5504, 0, -10829, 62],
            'teo' => [2752, 0, 2752, 31],
        ])
        ->and(p59Righe($p, 'Ines'))->toBe([
            ['Spese generali — già versato', -1785, 'rate', null, null],
            ['Spese generali', 1332, 'proprietario', 'Sara', $nota],
            ['Spese generali', 1420, 'proprietario', 'Sara', 'giorni dopo la sua uscita del 01/12/2026 in cui nessuno stava al posto dell\'inquilino, rimasti fuori dalla coppia di quella uscita'],
            ['Spese generali', 8077, 'proprietario', 'Sara', null],
            ['Spese generali', -1332, 'rate', null, 'il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet'],
            ['Spese generali', 17852, 'rate', null, null],
            ['Spese generali', -1420, 'restituzione', null, 'giorni dopo l\'uscita, dal 01/12/2026'],
        ])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ines' => 1420, 'Ugo' => 1332]);
    $stampa = p59Stampa($this, $s);
    expect($stampa)->toContain('il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet Proprietà1.000 su 1.000 millesimi 30 % 01/01/2026–31/12/2026 — € -13,32 nelle sue rate')
        ->and($stampa)->not->toContain('€ -27,52 nelle sue rate')
        ->and($stampa)->toContain('Totale a carico di Ines € 241,44');
});

it('Coda 413 — la guardia del credito: senza giorni scoperti (la riga di Ugo aperta) il credito di Ines è tutto dicembre, € 27,52, come la coppia, e le righe sono quelle di prima', function () {
    // La storia della Coda 380 con il già versato (`Beta55ProspettoCambioCoinquilinoTest`, «Coda 380 — la stessa storia con € 120,00 di già versato…»): la coppia del 1/12 è 2752, tutta a Ugo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -19085, 'Sara' => 16333, 'Ugo' => 2752])
        ->and(p59Righe($p, 'Ines'))->toBe([
            ['Spese generali — già versato', -1785, 'rate', null, null],
            ['Spese generali', 2752, 'proprietario', 'Sara', 'giorni dopo la sua uscita del 01/12/2026, passati ad altri con il conguaglio di quella uscita'],
            ['Spese generali', 8077, 'proprietario', 'Sara', null],
            ['Spese generali', -2752, 'rate', null, 'il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet'],
            ['Spese generali', 17852, 'rate', null, null],
        ])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 2752]);
});

/** L'«Antenna» (€ 3.650,00, una voce di entrata, 30 % all'inquilino) in un secondo piano ordinario «Antenna 2026», dalle pagine; approvato, generato ed emesso fino a giugno. */
function p59Antenna($test, array $s): void
{
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '3650,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $voce = \App\Models\Gestionale\Conto::where('nome', 'Antenna')->where('piano_conto_id', $pc->id)->firstOrFail();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => 'Antenna 2026', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => [['id' => $voce->id, 'importo' => '3650,00', 'note' => '']],
    ])->assertSessionHasNoErrors();
    $p2 = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Antenna 2026')->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $p2]), ['approvato' => true, 'data_delibera_assemblea' => '2025-12-15'])
        ->assertSessionHasNoErrors();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($p2->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: generazione', esercizio: $s['e']);
    p59Emetti($test, $s, $p2->fresh(), '2026-06-30');
}

// --- P-2: il credito di chi riprende i giorni con il pezzo regolato fra le parti (Coda 380 e decisione 83.3) -------------------------------------------------------------------------

it('Coda 413 — chi riprende i giorni ha il pezzo regolato fra le parti (Ines → Sara annullato, Sara → Pia «Scrivi» il 1/8, Pia → Sara «Non scriverlo» il 1/9, la fine di Sara il 1/12): la riga del credito di Sara dice «a suo credito nel Wallet», e la parte regolata fra le parti resta € 27,52', function () {
    // Il già versato «dell'unità» € 120,00 (decisione 17, D8): la riga di Ines al netto 32400. Il cambio Ines → Sara del 1/7, annullato, lascia a Sara i suoi giorni regolati fra le parti
    // (decisione 83.3, con la fine «Scrivi» di Sara dopo): 32400 × 184/365 = 16333,15 → 16333 al netto. Sara → Pia «Scrivi» il 1/8: luglio 16333 × 31/184 = 2751,79 → 2752 resta a Sara,
    // regolato fra le parti (3058 al lordo e 306 di già versato), e Pia prende 13581 con il conguaglio (Sara − 13581, Pia + 13581). Pia → Sara «Non scriverlo» il 1/9: agosto
    // 13581 × 31/153 = 2751,71 → 2752 a Pia, e i 10829 dopo restano a Pia, che li ha pagati, e glieli rimborsa Sara (decisione 145). La fine di Sara «Scrivi» il 1/12 scrive la coppia del
    // dicembre che Sara aveva ceduto, 10829 × 31/122 = 2751,66 → 2752 (Sara − 2752, Ugo + 2752); settembre–novembre 8077. Teo rimborsa 2752 a Ugo. Sara: 2752 + 8077 + 2752 − 2752 = 10829,
    // tutti da rimborsare a Pia; Pia 2752, con il conguaglio 13581, da ricevere 10829; Ines 17852 − 1785 = 16067; il controllo 16067 + 10829 + 2752 + 2752 = 32400. Il credito di Sara è la
    // coppia della sua uscita nel Wallet, non un accordo fra le parti (decisione 156.4): la stampa lo dice, e la parte regolata fra le parti, luglio, resta € 27,52 nel piede. Era: la riga
    // diceva «regolata fra le parti al cambio d'inquilino del 01/07/2026», e nel piede la parte regolata fra le parti faceva zero.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    p59Passaggio($this, $s, 'Sara', 'Pia', '2026-08-01', 'scrivi');
    p59Passaggio($this, $s, 'Pia', 'Sara', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    $credito = ['Spese generali', -2752, 'credito', null, 'il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet'];
    $sara = collect($p['conduttori'])->firstWhere('nome', 'Sara');
    expect(fclSaldi($s))->toBe(['Pia' => 13581, 'Sara' => -16333, 'Ugo' => 2752])
        ->and(p59Breve($p)['conduttori'])->toBe([
            'ines' => [16067, 16067, 0, 181],
            'pia' => [2752, 0, -10829, 31],
            'sara' => [10829, 0, 10829, 122],
            'teo' => [2752, 0, 2752, 31],
        ])
        ->and(p59Righe($p, 'Sara'))->toContain($credito)
        ->and([$sara['regolato_fra_le_parti'], $sara['a_suo_credito_nel_wallet'], $sara['da_rimborsare']])->toBe([2752, -2752, 10829])
        ->and(p59PagateDa($p, 'Teo'))->toBe(['Ugo' => 2752]);
    $stampa = p59Stampa($this, $s);
    expect($stampa)->toContain('il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet Proprietà1.000 su 1.000 millesimi 30 % 01/01/2026–31/12/2026 — € -27,52 a suo credito nel Wallet')
        ->and($stampa)->not->toContain('€ -27,52 regolata fra le parti')
        // Con la riga del credito le altre non sono più «di cui» (sommano al totale insieme a lei), e neanche l'ultima: la parte da rimborsare.
        ->and($stampa)->toContain('Totale a carico di Sara € 108,29 regolata fra le parti al cambio d\'inquilino € 27,52 a suo credito nel Wallet, con il conguaglio della sua uscita € 27,52 da rimborsare a chi le ha pagate € 108,29')
        ->and($stampa)->not->toContain('di cui da rimborsare a chi le ha pagate € 108,29');
});

it('Coda 413 — la stessa storia con un\'entrata, l\'«Antenna» del secondo piano (€ 3.650,00, 30 % all\'inquilino), emessa fino a giugno: la riga della coppia dell\'entrata è a debito di Sara nel Wallet, e la stampa lo dice', function () {
    // L'entrata all'inquilino: 365000 × 30 % = 109500 in meno, 300 al giorno. Il piano emesso fino a giugno lo prendono tutti i passaggi, come P1. Sara: luglio − 9300 regolato fra le parti, settembre–
    // novembre − 27300 e dicembre − 9300 «pagata da Pia» (Pia ha avuto con il conguaglio i giorni dell'entrata, a credito); la coppia della fine di Sara le scrive a debito il dicembre dell'entrata,
    // + 9300 (decisione 156.4: la riga dice come sta nel Wallet). Sull'«Antenna» Sara − 9300 − 27300 − 9300 + 9300 = − 36600; con le «Spese generali» (10829) − 25771, a favore di Sara. Nel piede
    // la parte del Wallet è − 2752 + 9300 = 6548, a suo debito.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    p59Antenna($this, $s);
    p59Annulla($this, $s, p59Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    p59Passaggio($this, $s, 'Sara', 'Pia', '2026-08-01', 'scrivi');
    p59Passaggio($this, $s, 'Pia', 'Sara', '2026-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    p59Inizio($this, $s, 'Teo', '2026-12-01', 80);
    $p = p59DallaRotta($this, $s);
    $sara = collect($p['conduttori'])->firstWhere('nome', 'Sara');
    // La nota della riga dice come sta nel Wallet quanto la stampa dice nella colonna «Come»: l'«Antenna» è una riga a debito (+ 9300), le «Spese generali» a credito (− 2752).
    expect(collect(p59Righe($p, 'Sara'))->where(2, 'credito')->map(fn ($r) => [$r[0], $r[1], $r[4]])->values()->all())->toBe([
        ['Antenna', 9300, 'il conguaglio della sua uscita del 01/12/2026, a suo debito nel Wallet'],
        ['Spese generali', -2752, 'il conguaglio della sua uscita del 01/12/2026, a suo credito nel Wallet'],
    ])
        ->and([(int) $sara['totale'], $sara['a_suo_credito_nel_wallet'], $sara['da_rimborsare']])->toBe([-25771, 6548, -25771]);
    $stampa = p59Stampa($this, $s);
    expect($stampa)->toContain('— € 93,00 a suo debito nel Wallet')
        ->and($stampa)->toContain('— € -27,52 a suo credito nel Wallet')
        ->and($stampa)->toContain('a suo debito nel Wallet, con il conguaglio della sua uscita € 65,48');
    // Nel piede nessuna cifra con «€ -» (Coda 252.2): il verso è nelle parole. A mano, per luglio Sara ha le «Spese generali» regolate fra le parti, 3058 al lordo e 306 di già versato = 2752,
    // e l'«Antenna» − 9300: − 6548, a suo credito; Pia ha con il conguaglio le «Spese generali» da agosto, 13581, e l'«Antenna» − 45900 (300 al giorno per i 153 giorni dal 1/8): − 32319, a suo
    // credito, e da rimborsare a chi le ha pagate 25771 (il pari di quello che Sara riceve). Le righe che sommano a un totale con una parte a credito non sono «di cui».
    $piede = [];
    foreach (['Sara', 'Pia'] as $nome) {
        preg_match('/Totale a (?:favore|carico) di '.$nome.' (.*?) (?:Conduttore|Totale delle voci)/s', $stampa, $m);
        $piede[$nome] = $m[1] ?? '';
    }
    expect($piede['Sara'])->toContain('regolata fra le parti al cambio d\'inquilino, a suo credito € 65,48')
        ->and($piede['Pia'])->toContain('con il conguaglio del cambio d\'inquilino, a suo credito € 323,19')
        ->and($piede['Pia'])->toContain('da rimborsare a chi le ha pagate € 257,71')
        ->and($piede['Pia'])->not->toContain('di cui')
        ->and($piede['Sara'].' '.$piede['Pia'])->not->toContain('€ -');
});

it('Coda 413 — con un\'entrata che supera la spesa il piede di chi esce dice «a credito nelle sue rate» e nessuna riga ha una cifra con «€ -» (Coda 252.2)', function () {
    // Ines sola all'80 %, le «Spese generali» (36000 all'inquilino nell'anno) e l'«Antenna» (entrata, 109500 all'inquilino), emesse fino a giugno; la fine di Ines il 1/9 «Non scriverlo», Teo dal
    // 1/9 all'80 %. A mano: nelle rate di Ines 36000 − 109500 = − 73500, a credito. I 122 giorni da settembre sono di Teo: 36000 × 122/365 = 12032,88 → 12033 di spese e 109500 × 122/365 = 36600 di
    // entrata, in tutto − 24567 per Teo, che Ines (che ha le rate dell'entrata) gli deve: 24567. Il totale di Ines − 73500 + 24567 = − 48933, a suo favore. Le righe non sono «di cui»: una parte è a credito.
    $s = p59Scenario($this);
    p59Antenna($this, $s);
    p59Passaggio($this, $s, 'Ines', null, '2026-09-01', 'non_scrivere');
    p59Inizio($this, $s, 'Teo', '2026-09-01', 80);
    $stampa = p59Stampa($this, $s);
    $piede = [];
    foreach (['Ines', 'Teo'] as $nome) {
        preg_match('/Totale a (?:favore|carico) di '.$nome.' (.*?) (?:Conduttore|Totale delle voci)/s', $stampa, $m);
        $piede[$nome] = $m[1] ?? '';
    }

    expect(p59Breve(p59DallaRotta($this, $s))['conduttori'])->toBe(['ines' => [-48933, -73500, 24567, 486], 'teo' => [-24567, 0, -24567, 244]])
        ->and($piede['Ines'])->toBe('€ 489,33 a credito nelle sue rate € 735,00 da rimborsare a chi le ha pagate € 245,67')
        ->and($piede['Teo'])->toBe('€ 245,67 a favore di Teo: da ricevere da chi ha pagato i suoi giorni € 245,67')
        ->and($piede['Ines'].' '.$piede['Teo'])->not->toContain('€ -')
        ->and($stampa)->toContain('Totale a favore di Ines')
        ->and($stampa)->not->toContain('di cui già nelle sue rate € -');
});

it('Coda 413 — la storia della Coda 380 con la riga di Ugo chiusa il 15/12 e Ines che rientra il 20/12 nei giorni che nessuno copriva: i suoi giorni restano a suo carico una volta sola', function () {
    // Nel passato (2025), perché la rotta dell'inizio rifiuta chi ha una riga in corso oggi. Le cifre della storia del credito (sopra, € 120,00 di già versato): la coppia della fine di Ines
    // del 1/12 è 1332 (fino al 15/12), i 16 giorni scoperti 1420, che Ines rimborsa a Sara (decisioni 145 e 156.3). Ines rientra il 20/12: dei 1420, 12 giorni sono suoi, 1420 × 12/16 = 1065,
    // e restano a lei (nessuno glieli rimborsa); gli altri 4, 355, non li ha nessuno (la parte «senza conduttore», con i 1332 dei giorni della coppia senza conduttore: 1687 per 19 giorni).
    // Ines 16067 + 8077 + 1332 + 1420 − 1332 − 355 = 25209, nelle sue rate 14735 (il Wallet: 32400 − 16333 − 1332), da rimborsare 10474 (10829 a Sara − 355); il controllo 25209 + 5504 +
    // 1687 = 32400. Una riga «nelle sue rate» dei suoi 12 giorni li conterebbe due volte (la riga a Sara li ha già): € 11,84 in più.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]], 'anno' => 2025]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2025-06-30');
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], fclRiga($s, 'Ugo', 'proprietario')]), [
        'anagrafica_id' => $s['persone']['Ugo']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2019-01-01', 'data_fine' => '2025-12-15', 'note' => '',
    ])->assertSessionHasNoErrors();
    p59Passaggio($this, $s, 'Ines', 'Sara', '2025-07-01', 'scrivi');
    p59Passaggio($this, $s, 'Sara', 'Ines', '2025-09-01', 'non_scrivere');
    p59Passaggio($this, $s, 'Ines', null, '2025-12-01', 'scrivi');
    p59Inizio($this, $s, 'Ines', '2025-12-20', 80);
    $p = p59DallaRotta($this, $s);
    $breve = p59Breve($p);
    expect(fclSaldi($s))->toBe(['Ines' => -17665, 'Sara' => 16333, 'Ugo' => 1332])
        ->and(array_map(fn (array $c) => array_slice($c, 0, 3), $breve['conduttori']))->toBe(['ines' => [25209, 14735, 10474], 'sara' => [5504, 0, -10829]])
        ->and($breve['senza'])->toBe([1687, 19])
        ->and(collect(p59Righe($p, 'Ines'))->where(2, 'rate')->sum(1))->toBe(14735);
});
