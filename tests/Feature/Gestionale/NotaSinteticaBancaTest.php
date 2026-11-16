<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il saldo dell'estratto conto della banca nella nota sintetica, su Via Verdi (il caso del conguaglio, con le spese scritte con le fatture) al 01/03/2027.
 *
 * La banca del caso, a mano: il saldo di apertura € 1.000,00 (non registrato a giornale) e gli incassi del 01/03/2026, € 1.700,00 + € 1.200,00 + € 750,00:
 * al 31/12/2026 € 4.650,00 in contabilità, la stessa cifra della liquidità della Sintesi; al 01/02/2026, prima degli incassi, € 1.000,00.
 *
 * Il fatto dice le due cifre e «coincidono» o la differenza con il suo verso, mai un errore; senza l'estratto non si propone, e il controllo «non è
 * calcolato»; un conto senza movimenti c'è, uno chiuso senza saldo no; più conti, un fatto per conto.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\User;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\SaldiDelleBanche;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

function nsbViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

/** Il saldo dell'estratto di un conto, nella bozza; e i fatti, il confronto e il controllo di oggi. */
function nsbConBanca(array $s, array $banca): array
{
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['banca' => $banca]), null);
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);
    $f = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture);

    return ['fatti' => array_column($f['fatti'], null, 'chiave'), 'non' => array_column($f['non_proposti'], 'perche', 'chiave'), 'banche' => $f['banche'],
        'controllo' => SaldiDelleBanche::controllo($f['banche']), 'letture' => $letture];
}

/** «€ 4.650,00» con lo spazio che non va a capo. */
function nsbE(string $cifra): string
{
    return '€'."\u{a0}".$cifra;
}

it('senza il saldo dell\'estratto: il fatto non si propone, la pagina dice che manca, il controllo non è calcolato', function () {
    $s = nsbViaVerdi($this);
    $r = nsbConBanca($s, []);
    $id = $s['cassa']->id;
    expect($r['fatti'])->not->toHaveKey('G6B:'.$id)
        ->and($r['non']['G6B:'.$id])->toBe('Il saldo che dice la banca per «Banca principale» non è scritto: scrivi qui sotto quello che leggi sull\'estratto conto che ti manda la banca, e il programma lo confronta con il saldo registrato in KondoManager.')
        ->and($r['banche'])->toBe([['cassa_id' => $id, 'cassa' => 'Banca principale', 'data' => '2026-12-31', 'estratto' => null, 'sul_conto' => 465000, 'nei_fondi' => 0,
            'contabilita' => 465000, 'fondi' => 'tutti', 'differenza' => null,
            'esito' => 'non_calcolato', 'spiegazione' => '']])
        // La contabilità del conto è la liquidità della Sintesi: una fonte sola.
        ->and($r['letture']['sintesi']['riquadri']['liquidita']['liquidita'])->toBe(465000)
        ->and($r['controllo'])->toMatchArray(['id' => 'BC', 'esito' => 'non_calcolato',
            'nota' => 'Il saldo che dice la banca non è scritto nella nota sintetica per «Banca principale»: il confronto con il saldo registrato in KondoManager alla stessa data non si fa.']);
    $nota = app(NotaSinteticaDelRendiconto::class);
    expect($nota->perLaScheda($s['c'], $s['e'], $r['letture'])['mancanze'])->toContain('Il saldo che dice la banca non è scritto per «Banca principale»: scrivilo accanto alla liquidità, nella parte «La gestione dell\'anno» della nota.');
});

it('le cifre uguali: «coincidono», e il controllo torna; il fatto si stampa senza «Tieni», si toglie con «Togli»', function () {
    $s = nsbViaVerdi($this);
    $id = (string) $s['cassa']->id;
    $r = nsbConBanca($s, [$id => ['data' => '2026-12-31', 'saldo' => 465000]]);
    $frase = 'Il conto «Banca principale» al 31/12/2026: secondo la banca (dal suo estratto conto) '.nsbE('4.650,00').', secondo la contabilità '.nsbE('4.650,00').': coincidono.';
    expect($r['fatti']['G6B:'.$id]['frase'])->toBe($frase)
        ->and($r['controllo'])->toMatchArray(['esito' => 'quadra', 'sinistra' => 465000, 'destra' => 465000]);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $stampa = fn () => collect($nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
    expect($stampa())->toBe(['G6B:'.$id => $frase]);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['banca' => [$id => ['data' => '2026-12-31', 'saldo' => 465000]],
        'fatti' => ['G6B:'.$id => ['stato' => 'tolto']]]), null);
    expect($stampa())->toBe([]);
});

it('una differenza: le due cifre e il verso, la spiegazione dell\'amministratore accanto; il controllo è da leggere, mai «errore»', function () {
    $s = nsbViaVerdi($this);
    $id = (string) $s['cassa']->id;
    // Dalla pagina: le cifre in euro, la conversione in centesimi una volta.
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => ['banca' => [$id => ['data' => '2026-12-31', 'saldo' => '4700.00',
            'spiegazione' => 'Un versamento del 31/12 è arrivato in banca e non è ancora registrato']]]])->assertSessionHasNoErrors();
    expect(NotaSinteticaDelRendiconto::contenuto($s['e'])['banca'][$id]['saldo'])->toBe(470000);
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);
    $f = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture);
    $fatto = collect($f['fatti'])->firstWhere('chiave', 'G6B:'.$id);
    $frase = 'Il conto «Banca principale» al 31/12/2026: secondo la banca (dal suo estratto conto) '.nsbE('4.700,00').', secondo la contabilità '.nsbE('4.650,00')
        .': la differenza è '.nsbE('50,00').', in più secondo la banca.';
    expect($fatto['frase'])->toBe($frase)
        ->and(NotaSinteticaDelRendiconto::testoDelFatto($fatto, NotaSinteticaDelRendiconto::contenuto($s['e'])))->toBe($frase.' Un versamento del 31/12 è arrivato in banca e non è ancora registrato.')
        ->and(SaldiDelleBanche::controllo($f['banche']))->toMatchArray(['esito' => 'segnalazione', 'sinistra' => 470000, 'destra' => 465000])
        ->and(SaldiDelleBanche::controllo($f['banche'])['nota'])->toContain('la differenza è '.nsbE('50,00'))->not->toMatch('/errore|sbagliat|ammanco/i');
    // Il verso opposto: più nella contabilità.
    $r = nsbConBanca($s, [$id => ['data' => '2026-12-31', 'saldo' => 460000]]);
    expect($r['fatti']['G6B:'.$id]['frase'])->toEndWith(': la differenza è '.nsbE('50,00').', in più nella contabilità.');
});

it('alla data dell\'estratto: un estratto del 01/02/2026 si confronta con la contabilità di quel giorno, prima degli incassi', function () {
    $s = nsbViaVerdi($this);
    $id = (string) $s['cassa']->id;
    $r = nsbConBanca($s, [$id => ['data' => '2026-02-01', 'saldo' => 100000]]);
    expect($r['banche'][0])->toMatchArray(['data' => '2026-02-01', 'contabilita' => 100000, 'differenza' => 0, 'esito' => 'quadra']);
});

it('più conti della banca: un fatto per conto; un conto senza movimenti c\'è, uno chiuso senza saldo no', function () {
    $s = nsbViaVerdi($this);
    $conto = fn (string $codice, string $nome) => ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => $codice, 'nome' => $nome, 'tipo' => 'attivo',
        'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $postale = Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto('10.11', 'Conto postale')->id, 'nome' => 'Conto postale', 'tipo' => 'banca',
        'attiva' => true, 'saldo_iniziale' => 0]);
    Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto('10.12', 'Banca chiusa')->id, 'nome' => 'Banca chiusa', 'tipo' => 'banca',
        'attiva' => false, 'saldo_iniziale' => 0]);
    $r = nsbConBanca($s, [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 465000], (string) $postale->id => ['data' => '2026-12-31', 'saldo' => 0]]);
    expect(array_column($r['banche'], 'cassa'))->toBe(['Banca principale', 'Conto postale'])
        ->and($r['fatti']['G6B:'.$postale->id]['frase'])->toBe('Il conto «Conto postale» al 31/12/2026: secondo la banca (dal suo estratto conto) '.nsbE('0,00')
            .', secondo la contabilità '.nsbE('0,00').': coincidono.')
        ->and($r['controllo'])->toMatchArray(['esito' => 'quadra', 'sinistra' => 465000, 'destra' => 465000])
        ->and($r['controllo']['nota'])->toBe('Il saldo che dice la banca e il saldo registrato in KondoManager coincidono per «Banca principale», «Conto postale».');
    // Un conto scritto e uno no: il controllo non è calcolato, e dice quale manca.
    $r = nsbConBanca($s, [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 465000]]);
    expect($r['controllo'])->toMatchArray(['esito' => 'non_calcolato'])->and($r['controllo']['nota'])->toContain('per «Conto postale»');
});

it('la copia dall\'anno prima non porta mai il saldo della banca: è una cifra dell\'anno', function () {
    $s = nsbViaVerdi($this);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['banca' => [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 465000,
        'spiegazione' => 'Spiegata']], 'parti' => ['altre' => ['testo' => 'Un testo.']]]), null);
    expect(NotaSinteticaDelRendiconto::copiabile($s['e'])['contenuto']['banca'])->toBe([])
        ->and(NotaSinteticaDelRendiconto::copiabile($s['e'])['contenuto']['parti']['altre']['testo'])->toBe('Un testo.');
});

it('con il confronto della banca stampato, la liquidità dice la sua cifra e non che il confronto lo fa l\'amministratore: è lì sotto', function () {
    $s = nsbViaVerdi($this);
    $id = (string) $s['cassa']->id;
    $nota = app(NotaSinteticaDelRendiconto::class);
    $stampa = fn () => collect($nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']))['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
    $lunga = 'La liquidità secondo la contabilità è '.nsbE('4.650,00').'. Non è il saldo dell\'estratto conto della banca: il confronto lo fa l\'amministratore.';
    // Senza l'estratto: la frase intera.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['G6' => ['stato' => 'tenuto']]]), null);
    expect($stampa())->toBe(['G6' => $lunga]);
    // Con l'estratto scritto: la cifra sola, e sotto il confronto.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['G6' => ['stato' => 'tenuto']],
        'banca' => [$id => ['data' => '2026-12-31', 'saldo' => 465000]]]), null);
    expect($stampa())->toMatchArray(['G6' => 'La liquidità secondo la contabilità è '.nsbE('4.650,00').'.'])->toHaveKey('G6B:'.$id);
    // Il confronto tolto: la liquidità torna a dirlo.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['G6' => ['stato' => 'tenuto'], 'G6B:'.$id => ['stato' => 'tolto']],
        'banca' => [$id => ['data' => '2026-12-31', 'saldo' => 465000]]]), null);
    expect($stampa())->toBe(['G6' => $lunga]);
});

it('la differenza della banca si spiega in un posto solo, sotto il saldo della banca: «Ferma» la conta, la nota la stampa una volta, il controllo è spiegato', function () {
    $s = nsbViaVerdi($this);
    $id = (string) $s['cassa']->id;
    $nota = app(NotaSinteticaDelRendiconto::class);
    $frase = NotaSinteticaDelRendiconto::FRASE_BANCA_SENZA_SPIEGAZIONE;
    $mancanzeDellaSintesi = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))
        ->assertOk()->viewData('page')['props']['nota']['mancanze'];
    $stampa = fn () => $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    $testi = fn (array $st) => collect($st['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
    // Gli altri controlli da spiegare del caso, che non c'entrano: la riga che li conta resta la loro.
    $altri = fn () => collect($nota->letture($s['c'], $s['e'])['sintesi']['esito']['righe'])
        ->filter(fn ($c) => $c['id'] !== 'BC' && in_array($c['esito'], ['non_quadra', 'segnalazione'], true))->count();

    // La differenza senza spiegazione: il controllo è da leggere; la nota non ha un secondo campo per lui, e «Ferma» chiede la spiegazione sotto la banca.
    $r = nsbConBanca($s, [$id => ['data' => '2026-12-31', 'saldo' => 470000]]);
    $perLaScheda = $nota->perLaScheda($s['c'], $s['e'], $r['letture']);
    expect($r['controllo']['esito'])->toBe('segnalazione')
        ->and($r['fatti'])->toHaveKey('G6B:'.$id)->not->toHaveKey('G9:BC')
        ->and(collect($perLaScheda['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'chiave'))->all())->not->toContain('G9:BC')
        ->and($perLaScheda['mancanze'])->toContain($frase)
        ->and($mancanzeDellaSintesi())->toContain($frase)
        ->and($stampa()['spiegati'])->toBe(0);

    // Scritta sotto il saldo della banca: «Ferma» non la chiede più, la nota la stampa accanto alle cifre e una volta sola, e il controllo conta fra gli spiegati.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['banca' => [$id => ['data' => '2026-12-31', 'saldo' => 470000,
        'spiegazione' => 'Un versamento del 31/12 è arrivato in banca e non è ancora registrato']]]), null);
    $letture = $nota->letture($s['c'], $s['e']);
    $st = $stampa();
    $mancanze = $mancanzeDellaSintesi();
    expect($nota->perLaScheda($s['c'], $s['e'], $letture)['mancanze'])->not->toContain($frase)
        ->and($mancanze)->not->toContain($frase)
        ->and(collect($mancanze)->filter(fn ($m) => str_contains($m, 'la spiegazione'))->count())->toBe($altri() > 0 ? 1 : 0)
        ->and($testi($st))->not->toHaveKey('G9:BC')
        ->and($testi($st)['G6B:'.$id])->toEndWith(': la differenza è '.nsbE('50,00').', in più secondo la banca. Un versamento del 31/12 è arrivato in banca e non è ancora registrato.')
        ->and(substr_count(implode(' ', $testi($st)), 'Un versamento del 31/12'))->toBe(1)
        ->and($st['spiegati'])->toBe(1);

    // Le cifre uguali con una riga scritta: non c'è niente da spiegare, e la riga non conta un controllo spiegato.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['banca' => [$id => ['data' => '2026-12-31', 'saldo' => 465000,
        'spiegazione' => 'Letto sull\'estratto di gennaio']]]), null);
    expect($stampa()['spiegati'])->toBe(0)
        ->and($mancanzeDellaSintesi())->not->toContain($frase);
});
