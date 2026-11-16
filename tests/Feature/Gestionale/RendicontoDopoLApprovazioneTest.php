<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Dopo l'approvazione del rendiconto: l'avviso con le cifre di allora e di oggi, nessun blocco; il contratto con la chiusura; i testi senza promesse.
 *
 * Via Verdi, con le spese scritte con le fatture (così il prospetto della Sintesi le vede). La versione si ferma il 01/03/2027 e si approva il
 * 15/03/2027 (verbale n. 2, anche lo stato di riparto). Le cifre a mano (positivo = debito):
 * - approvate: spese € 4.200,00; conguaglio Neri +100,00 (500/1000 di 4.200,00 = 2.100,00 − 2.000,00), Bianchi +29,75 e Rossi +30,25 (U2, 300/1000, per
 *   181 e 184 giorni), Verdi +120,00 (840,00 + 80,00 − 800,00); in tutto +280,00;
 * - il 20/03/2027, con il 2026 aperto, una fattura di Manutenzioni di € 60,00 scritta nel 2026: Manutenzioni 900,00 → 960,00, spese 4.200,00 → 4.260,00.
 *   Per voce e per giorni, con i resti maggiori: Pulizie U2 990,00 → Bianchi 490,93, Rossi 499,07; Manutenzioni U2 288,00 → 142,8164 e 145,1836 →
 *   142,81 e 145,18, il centesimo che avanza a Bianchi (0,64 contro 0,36): 142,82. Neri 2.130,00 − 2.000,00 = +130,00; Bianchi 633,75 − 595,07 = +38,68;
 *   Rossi 644,25 − 604,93 = +39,32; Verdi 852,00 + 80,00 − 800,00 = +132,00; in tutto +340,00. Cambiano tutte e quattro le persone.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Gestione;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Gestionale\ConfrontoDelRendiconto;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\VersioniDelRendiconto;
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
    $this->user = User::factory()->create(['name' => 'Anna']);
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

afterEach(function () {
    (new ReflectionProperty(ConguaglioDelRendiconto::class, 'soglia'))->setValue(null, null);
});

function rdaViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    return $s;
}

function rdaGiorno($test, string $giorno): void
{
    $test->travelTo(\Carbon\Carbon::parse($giorno.' 10:00', 'Europe/Rome'));
}

/** Fermata il 01/03/2027 con il solo riepilogo nel PDF, approvata il 15/03/2027 (verbale n. 2) con lo stato di riparto sì o no. */
function rdaApprovata($test, array $s, string $riparto = '1'): RendicontoVersione
{
    rdaGiorno($test, '2027-03-01');
    $sintesi = route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]);
    $test->actingAs($test->user)->from($sintesi)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['riepilogo']])
        ->assertSessionHasNoErrors();
    $v = RendicontoVersione::where('esercizio_id', $s['e']->id)->latest('numero')->firstOrFail();
    rdaGiorno($test, '2027-03-15');
    $test->actingAs($test->user)->from($sintesi)->post(route('admin.gestionale.esercizi.rendiconto.versioni.approva', [$s['c'], $s['e'], $v->id]),
        ['data_delibera' => '2027-03-15', 'numero_verbale' => '2', 'riparto_approvato' => $riparto])->assertSessionHasNoErrors();

    return $v->fresh();
}

/** Le proprietà della pagina di una scheda del rendiconto. */
function rdaPagina($test, string $rotta, array $s, array $query = []): array
{
    $url = route($rotta, [$s['c'], $s['e']]).($query === [] ? '' : '?'.http_build_query($query));

    return $test->actingAs($test->user)->get($url)->assertOk()->viewData('page')['props'];
}

/**
 * Una registrazione cancellata e scritta di nuovo con lo stesso contenuto, come fa la modifica di una fattura aperta (`FatturaPassivaService::aggiornaFattura`:
 * le righe e la registrazione si cancellano e si riscrivono, con gli id nuovi). Con `$importo` la riga di costo e quella del debito cambiano importo.
 */
function rdaRiscritta(array $s, ?int $importo = null): int
{
    $sc = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'fattura_acquisto')->orderBy('id')->first();
    $righe = DB::table('righe_scritture')->where('scrittura_id', $sc->id)->orderBy('id')->get();
    $pivot = DB::table('fattura_scrittura')->where('scrittura_contabile_id', $sc->id)->get();
    DB::table('fattura_scrittura')->where('scrittura_contabile_id', $sc->id)->delete();
    DB::table('righe_scritture')->where('scrittura_id', $sc->id)->delete();
    DB::table('scritture_contabili')->where('id', $sc->id)->delete();
    $nuova = (array) $sc;
    unset($nuova['id']);
    $id = DB::table('scritture_contabili')->insertGetId($nuova);
    foreach ($righe as $r) {
        $r = (array) $r;
        unset($r['id']);
        $r['scrittura_id'] = $id;
        $r['importo'] = $importo ?? $r['importo'];
        DB::table('righe_scritture')->insert($r);
    }
    foreach ($pivot as $x) {
        $x = (array) $x;
        unset($x['id']);
        $x['scrittura_contabile_id'] = $id;
        DB::table('fattura_scrittura')->insert($x);
    }

    return $id;
}

/** Le cifre di una versione riportate alla forma 1: senza l'impronta del giornale. */
function rdaFormaUno(RendicontoVersione $v): void
{
    $cifre = $v->cifre;
    unset($cifre['giornale']);
    $cifre['forma'] = 1;
    DB::table('rendiconto_versioni')->where('id', $v->id)->update(['cifre' => json_encode($cifre), 'forma' => 1]);
}

function rdaE(string $cifra): string
{
    return '€'."\u{a0}".$cifra;
}

it('una fattura di € 60,00 scritta nel 2026 aperto dopo l\'approvazione: l\'avviso con € 4.200,00 e € 4.260,00 e le 4 persone, in ogni scheda; la versione non cambia', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    $cifre = DB::table('rendiconto_versioni')->where('id', $v->id)->value('cifre');

    // Finché niente cambia, niente avviso.
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->toBeNull()
        ->and($p['confronto'])->toMatchArray(['automatico' => true, 'fatto' => true])->and($p['confronto']['approvata']['cambiato'])->toBeFalse();

    rdaGiorno($this, '2027-03-20');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    $a = $p['confronto']['approvata'];

    expect($a['frase'])->toBe('Dopo l\'approvazione del 15/03/2027 sono cambiati i conti dell\'esercizio: nel giornale 1 registrazione nuova; le spese dell\'esercizio erano '
        .rdaE('4.200,00').', oggi sono '.rdaE('4.260,00').'; Manutenzioni era '.rdaE('900,00').', oggi è '.rdaE('960,00').'; i debiti verso i fornitori erano '
        .rdaE('4.280,00').', oggi sono '.rdaE('4.340,00').'; il conguaglio è cambiato per 4 persone su 4. '
        .'Il rendiconto approvato resta quello del PDF.')
        ->and(collect($a['persone'])->mapWithKeys(fn ($r) => [$r['nome'] => [$r['prima'], $r['oggi']]])->all())
        ->toBe(['Bianchi' => [2975, 3868], 'Neri' => [10000, 13000], 'Rossi' => [3025, 3932], 'Verdi' => [12000, 13200]])
        // Una spesa nuova cambia il conguaglio e, della stessa cifra, il saldo finale.
        ->and(collect($a['persone'])->every(fn ($r) => $r['saldo_oggi'] - $r['saldo_prima'] === $r['oggi'] - $r['prima']))->toBeTrue()
        ->and(array_column($a['scritture'], 'cosa'))->toBe(['nuova'])
        // In testa a ogni scheda, non solo nella Sintesi.
        ->and($p['stato_rendiconto']['dopo'])->toBe('Dopo l\'approvazione del 15/03/2027 sono cambiate registrazioni che queste schede leggono: le schede non sono più '
            .'quelle approvate. Il rendiconto approvato resta quello del PDF.');
    foreach (['admin.gestionale.esercizi.rendiconto.riepilogo', 'admin.gestionale.esercizi.stato-patrimoniale.index', 'admin.gestionale.esercizi.rendiconto.cassa',
        'admin.gestionale.esercizi.rendiconto.condomini', 'admin.gestionale.esercizi.rendiconto.conguaglio', 'admin.gestionale.esercizi.rendiconto.controlli',
        'admin.gestionale.esercizi.rendiconto.nota'] as $rotta) {
        expect(rdaPagina($this, $rotta, $s)['stato_rendiconto']['dopo'])->toBe($p['stato_rendiconto']['dopo']);
    }

    // La versione approvata non cambia: le sue cifre sono quelle di allora, lo stato resta, l'esercizio resta aperto.
    $dopo = RendicontoVersione::findOrFail($v->id);
    expect(DB::table('rendiconto_versioni')->where('id', $v->id)->value('cifre'))->toBe($cifre)
        ->and($dopo->stato)->toBe(RendicontoVersione::APPROVATA)
        ->and($s['e']->fresh()->stato)->toBe('aperto');
});

it('una versione della forma di prima senza niente di cambiato: niente avviso, e una sua parte si conserva', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    rdaFormaUno($v);
    rdaGiorno($this, '2027-03-20');
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['confronto']['approvata'])->toMatchArray(['cambiato' => false, 'frase' => null, 'frasi' => [], 'persone' => []])
        ->and($p['stato_rendiconto']['dopo'])->toBeNull();

    // Una versione solo fermata, della forma di prima: «Conserva le parti una per una» la conserva.
    $t = rdaViaVerdi($this);
    rdaGiorno($this, '2027-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$t['c'], $t['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
    $f = RendicontoVersione::where('esercizio_id', $t['e']->id)->firstOrFail();
    rdaFormaUno($f);
    rdaGiorno($this, '2027-03-05');
    expect(app(VersioniDelRendiconto::class)->conservaParte($f->fresh(), 'riepilogo', $this->user))->toBeNull();
});

it('una versione della forma 2, con l\'impronta del giornale fatta con gli id: non si confronta con quella di oggi, e senza niente di cambiato niente avviso', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    $cifre = $v->cifre;
    $cifre['forma'] = 2;
    $cifre['giornale'] = ['scritture' => 1, 'impronta' => str_repeat('a', 64), 'per_scrittura' => ['1:abcdefabcdef']];
    DB::table('rendiconto_versioni')->where('id', $v->id)->update(['cifre' => json_encode($cifre), 'forma' => 2]);
    rdaGiorno($this, '2027-03-20');

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect(ConfrontoDelRendiconto::giornaleCambiato($v->fresh()))->toBeNull()
        ->and($p['stato_rendiconto']['dopo'])->toBeNull()
        ->and($p['confronto']['approvata'])->toMatchArray(['cambiato' => false, 'scritture' => []]);
});

it('i nomi non sono cifre: una persona, un\'unità, una voce, il condominio e l\'esercizio con un altro nome dopo l\'approvazione non fanno l\'avviso', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    DB::table('anagrafiche')->where('id', $s['persone']['Bianchi']->id)->update(['nome' => 'Bianchi Maria']);
    DB::table('immobili')->where('id', $s['unita']['U1']->id)->update(['interno' => 'A1', 'nome' => 'Attico']);
    DB::table('conti')->where('id', $s['manutenzioni']->id)->update(['nome' => 'Manutenzioni ordinarie']);
    DB::table('condomini')->where('id', $s['c']->id)->update(['indirizzo' => 'Via Verdi 3', 'nome' => 'Condominio Via Verdi']);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['nome' => 'Ordinario 2026']);

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['confronto']['approvata'])->toMatchArray(['cambiato' => false, 'frase' => null, 'persone' => []])
        ->and($p['stato_rendiconto']['dopo'])->toBeNull();
});

it('una fattura riscritta con le stesse cifre e la stessa competenza (la modifica della sola scadenza): niente avviso, e una parte si conserva; con un\'altra cifra sì', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    rdaRiscritta($s);
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->toBeNull()->and($p['confronto']['approvata']['cambiato'])->toBeFalse();

    // Una versione solo fermata non rifiuta la sua parte per una fattura riscritta uguale.
    $t = rdaViaVerdi($this);
    rdaGiorno($this, '2027-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$t['c'], $t['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
    $f = RendicontoVersione::where('esercizio_id', $t['e']->id)->firstOrFail();
    rdaGiorno($this, '2027-03-05');
    rdaRiscritta($t);
    expect(app(VersioniDelRendiconto::class)->conservaParte($f->fresh(), 'riepilogo', $this->user))->toBeNull();

    // Con l'importo cambiato, da € 3.300,00 a € 3.400,00: l'avviso c'è, e la registrazione è «cambiata».
    rdaGiorno($this, '2027-03-21');
    rdaRiscritta($s, 340000);
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->not->toBeNull()
        ->and($p['confronto']['approvata']['frasi'][0])->toBe('nel giornale 1 registrazione cambiata')
        ->and(array_column($p['confronto']['approvata']['scritture'], 'cosa'))->toBe(['cambiata']);
});

it('una registrazione eliminata dopo l\'approvazione: l\'avviso lo dice, «tolta»', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $id = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'fattura_acquisto')->orderByDesc('id')->value('id');
    DB::table('scritture_contabili')->where('id', $id)->update(['deleted_at' => now()]);

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->not->toBeNull()
        ->and($p['confronto']['approvata']['frasi'][0])->toBe('nel giornale 1 registrazione tolta')
        ->and(array_column($p['confronto']['approvata']['scritture'], 'cosa'))->toBe(['tolta']);
});

it('una registrazione di un esercizio prima, ancora aperto, scritta dopo l\'approvazione: la testa delle schede lo dice, e la Sintesi dice i debiti', function () {
    $s = rdaViaVerdi($this);
    $e25 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $e25->id,
        'data_registrazione' => '2027-03-20', 'data_competenza' => '2025-11-20', 'numero_protocollo' => 'RDA-2025', 'causale' => 'fattura 2025', 'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $s['giornale']['costi'], 'voce_spesa_id' => $s['manutenzioni']->id, 'tipo_riga' => 'dare', 'importo' => 6000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $s['giornale']['debiti_fornitori'], 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => 6000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // I debiti verso i fornitori alla fine del 2026: € 4.280,00 delle tre fatture del 2026 (3.300,00 + 900,00 + 80,00), non pagate; oggi € 60,00 in più.
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->toBe('Dopo l\'approvazione del 15/03/2027 sono cambiate registrazioni che queste schede leggono: le schede non sono più quelle '
        .'approvate. Il rendiconto approvato resta quello del PDF.')
        ->and($p['confronto']['approvata']['frasi'])->toContain('i debiti verso i fornitori erano '.rdaE('4.280,00').', oggi sono '.rdaE('4.340,00'));
});

it('il saldo dell\'estratto della banca scritto dopo l\'approvazione non è una registrazione: niente avviso', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $c = \App\Services\Gestionale\NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = [(string) $s['cassa']->id => ['data' => '2026-12-31', 'saldo' => 470000, 'spiegazione' => '']];
    \App\Services\Gestionale\NotaSinteticaDelRendiconto::salva($s['e'], $c, null);

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect(collect($p['sintesi']['esito']['righe'])->firstWhere('id', 'BC')['esito'])->toBe('segnalazione')
        ->and($p['stato_rendiconto']['dopo'])->toBeNull()
        ->and($p['confronto']['approvata']['cambiato'])->toBeFalse();
});

it('una versione fermata senza l\'impronta del giornale (la forma di prima): la testa delle schede tace, il confronto delle cifre dice il registro', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    $cifre = $v->cifre;
    unset($cifre['giornale']);
    $cifre['forma'] = 1;
    DB::table('rendiconto_versioni')->where('id', $v->id)->update(['cifre' => json_encode($cifre), 'forma' => 1]);
    rdaGiorno($this, '2027-03-20');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 4) => 10000], '2027-03-20');

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect(ConfrontoDelRendiconto::giornaleCambiato($v))->toBeNull()
        ->and($p['stato_rendiconto']['dopo'])->toBeNull()
        ->and($p['confronto']['approvata']['frasi'])->toContain('1 riga del registro di contabilità è nuova o cambiata')
        ->and($p['confronto']['approvata']['scritture'])->toBe([]);
});

it('un incasso del 20/03/2027 scritto nel 2026 aperto: l\'avviso lo dice, letto per esercizio e non per data', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $quota = lcdQuota($s, 'Neri', 4);
    $incasso = lcdIncassa($this, $s, 'Neri', [$quota => 10000], '2027-03-20');
    expect((int) DB::table('scritture_contabili')->where('id', $incasso)->value('esercizio_id'))->toBe($s['e']->id);

    $a = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['confronto']['approvata'];
    expect($a['cambiato'])->toBeTrue()
        ->and($a['frasi'][0])->toBe('nel giornale 1 registrazione nuova')
        ->and(array_column($a['scritture'], 'scrittura_id'))->toBe([$incasso])
        // Versato dopo la fine dell'esercizio: la posizione sulle rate al 31/12/2026 non cambia, e nessuna riga del conguaglio con lei.
        ->and($a['persone'])->toBe([]);
});

it('un incasso con la data del 20/12/2026, registrato dopo l\'approvazione: per la persona il conguaglio resta, il saldo finale scende; l\'elenco dice le due coppie', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $incasso = lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 4) => 10000], '2026-12-20');

    $a = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['confronto']['approvata'];
    // La riga di Neri: il conguaglio (spese meno quote) è quello approvato, +100,00; il saldo finale scende dei € 100,00 versati. Con il solo conguaglio
    // l'elenco direbbe due volte la stessa cifra, mentre la frase dice che la riga è cambiata.
    $approvato = collect($v->cifre['conguaglio']['righe'])->firstWhere('nome', 'Neri');
    expect(array_column($a['scritture'], 'scrittura_id'))->toBe([$incasso])
        ->and($a['frasi'])->toContain('il conguaglio è cambiato per 1 persona su 4')
        ->and(collect($a['persone'])->pluck('nome')->all())->toBe(['Neri'])
        ->and($a['persone'][0])->toMatchArray(['prima' => 10000, 'oggi' => 10000, 'saldo_prima' => $approvato['saldo'], 'saldo_oggi' => $approvato['saldo'] - 10000])
        ->and($a['persone'][0]['saldo_prima'])->not->toBe($a['persone'][0]['saldo_oggi']);
});

it('un passaggio registrato dopo, senza scritture: la testa delle schede tace, il confronto delle cifre per persona dice le persone', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    $gialli = \App\Models\Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-rda@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'RDAGIA0000000001']);
    $gialli->condomini()->syncWithoutDetaching([$s['c']->id]);
    $prima = DB::table('scritture_contabili')->count();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U3']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righe']['Verdi'], 'anagrafica_entrante_id' => $gialli->id, 'decorrenza' => '2026-10-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2027-03-20',
        'estremi_titolo' => 'atto notaio Neri, rep. 2', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto',
    ])->assertSessionHasNoErrors();
    expect(DB::table('scritture_contabili')->count())->toBe($prima);

    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    $a = $p['confronto']['approvata'];
    expect($p['stato_rendiconto']['dopo'])->toBeNull()
        ->and($a['cambiato'])->toBeTrue()
        ->and($a['frasi'])->toContain('il conguaglio è cambiato per 2 persone su 5')
        ->and(collect($a['persone'])->pluck('nome')->all())->toBe(['Gialli', 'Verdi'])
        ->and($a['persone'][0]['prima'])->toBeNull()
        ->and($a['persone'][0]['saldo_prima'])->toBeNull()
        ->and($a['scritture'])->toBe([]);
});

it('con il 2026 chiuso, la stessa fattura scritta nel 2027 con la data del 2027: l\'avviso del 2026 tace; con la data del 2026 parla', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    $s['e']->update(['stato' => 'chiuso']);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    rdaGiorno($this, '2027-03-20');
    $scrivi = function (string $data) use ($s, $e27) {
        $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $e27->id,
            'data_registrazione' => '2027-03-20', 'data_competenza' => $data, 'numero_protocollo' => 'RDA-'.$data, 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto',
            'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('righe_scritture')->insert([
            ['scrittura_id' => $id, 'conto_contabile_id' => $s['giornale']['costi'], 'voce_spesa_id' => $s['manutenzioni']->id, 'tipo_riga' => 'dare', 'importo' => 6000, 'created_at' => now(), 'updated_at' => now()],
            ['scrittura_id' => $id, 'conto_contabile_id' => $s['giornale']['debiti_fornitori'], 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => 6000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $id;
    };
    $nel2027 = $scrivi('2027-03-20');
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->toBeNull()->and($p['confronto']['approvata']['cambiato'])->toBeFalse();

    // Con la data del 20/12/2026 la stessa fattura sta nella situazione patrimoniale del 2026 (i debiti alla fine dell'esercizio) e oltre il suo confine.
    DB::table('righe_scritture')->where('scrittura_id', $nel2027)->delete();
    DB::table('scritture_contabili')->where('id', $nel2027)->delete();
    $scrivi('2026-12-20');
    $p = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s);
    expect($p['stato_rendiconto']['dopo'])->not->toBeNull()->and($p['confronto']['approvata']['frasi'][0])->toBe('nel giornale 1 registrazione nuova');
});

it('sopra la soglia di peso il confronto non si fa da sé: la Sintesi dice se il giornale è cambiato e offre il pulsante; con il pulsante, le cifre', function () {
    $s = rdaViaVerdi($this);
    rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    (new ReflectionProperty(ConguaglioDelRendiconto::class, 'soglia'))->setValue(null, 0);

    $c = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['confronto'];
    expect($c)->toBe(['automatico' => false, 'fatto' => false, 'approvata' => null, 'presentate' => [], 'giornale_cambiato' => true]);

    $c = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s, ['confronta' => 1])['confronto'];
    expect($c['fatto'])->toBeTrue()->and($c['automatico'])->toBeFalse()
        ->and($c['approvata']['frasi'])->toContain('il conguaglio è cambiato per 4 persone su 4');
});

it('sopra la soglia, una versione solo fermata: la finestra dell\'approvazione sa se il giornale è cambiato, e con il pulsante ha le cifre', function () {
    $s = rdaViaVerdi($this);
    rdaGiorno($this, '2027-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
    $v = RendicontoVersione::where('esercizio_id', $s['e']->id)->firstOrFail();
    rdaGiorno($this, '2027-03-05');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);
    (new ReflectionProperty(ConguaglioDelRendiconto::class, 'soglia'))->setValue(null, 0);

    $c = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['confronto'];
    expect($c)->toMatchArray(['automatico' => false, 'fatto' => false, 'approvata' => null, 'giornale_cambiato' => null])
        ->and($c['presentate'])->toBe([['versione_id' => $v->id, 'numero' => 1, 'giornale_cambiato' => true]]);
    $c = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s, ['confronta' => 1])['confronto'];
    expect($c['presentate'][0]['frase'])->toStartWith('Dal 01/03/2027, il giorno della versione 1, sono cambiati i conti dell\'esercizio: nel giornale 1 registrazione nuova');
});

it('la finestra dell\'approvazione: dal giorno della versione fermata le cifre sono cambiate, e lo dice con le due cifre', function () {
    $s = rdaViaVerdi($this);
    rdaGiorno($this, '2027-03-01');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
    rdaGiorno($this, '2027-03-05');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);

    $c = rdaPagina($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s)['confronto'];
    expect($c['approvata'])->toBeNull()
        ->and($c['presentate'][0]['frase'])->toStartWith('Dal 01/03/2027, il giorno della versione 1, sono cambiati i conti dell\'esercizio: nel giornale 1 registrazione nuova; '
            .'le spese dell\'esercizio erano '.rdaE('4.200,00').', oggi sono '.rdaE('4.260,00'));
});

it('il file del PDF della versione tolto o cambiato: la versione lo dice, con «Carica il PDF che hai mandato»', function () {
    $s = rdaViaVerdi($this);
    $v = rdaApprovata($this, $s);
    $doc = \App\Models\Documento::findOrFail($v->pdf['documenti'][0]['documento_id']);
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['pdf_frase'])->toBeNull();

    Storage::disk('local')->put($doc->path, '%PDF-1.4 un altro file');
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['pdf_frase'])->toBe('Il file del PDF «'.$doc->name.'» non è quello conservato: la sua impronta non è quella scritta '
        .'sulla versione. Carica il PDF che hai mandato ai condòmini.');

    Storage::disk('local')->delete($doc->path);
    expect(VersioniDelRendiconto::elenco($s['e'])[0]['pdf_frase'])->toBe('Il file del PDF «'.$doc->name.'» non c\'è più sul disco dei documenti. Carica il PDF che hai '
        .'mandato ai condòmini.');
});

it('il contratto con la chiusura: dalla versione approvata il conguaglio per persona, unità e gestione e il sì sul riparto, non la lettura di oggi', function () {
    $s = rdaViaVerdi($this);
    expect(VersioniDelRendiconto::perLaChiusura($s['e']))->toBeNull();
    $v = rdaApprovata($this, $s);
    rdaGiorno($this, '2027-03-20');
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 6000, []);

    $k = VersioniDelRendiconto::perLaChiusura($s['e']);
    expect($k)->toMatchArray(['versione_id' => $v->id, 'numero' => 1, 'forma' => RendicontoVersione::FORMA, 'data_delibera' => '2027-03-15', 'numero_verbale' => '2',
        'riparto_approvato' => true])
        ->and(collect($k['conguaglio']['righe'])->mapWithKeys(fn ($r) => [$r['nome'].' '.$r['unita'] => [$r['gestione_id'], $r['conguaglio']]])->sortKeys()->all())
        ->toBe(['Bianchi Int. U2' => [$s['g']->id, 2975], 'Neri Int. U1' => [$s['g']->id, 10000], 'Rossi Int. U2' => [$s['g']->id, 3025], 'Verdi Int. U3' => [$s['g']->id, 12000]])
        ->and($k['conguaglio']['gestioni'][0])->toMatchArray(['gestione_id' => $s['g']->id, 'nome' => 'Ordinaria 2026'])
        ->and($k['conguaglio']['gestioni'][0]['totali']['conguaglio'])->toBe(28000)
        ->and($k['conguaglio']['condominio']['conguaglio'])->toBe(28000)
        // La lettura di oggi dice +340,00: la chiusura legge la versione approvata, e il confronto dice che sono diverse.
        ->and(ConguaglioDelRendiconto::totaliDelCondominio(app(ConguaglioDelRendiconto::class)->leggi($s['e']))['conguaglio'])->toBe(34000);

    // Una versione nuova fermata dopo, non ancora approvata: la chiusura legge ancora quella approvata.
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s['c'], $s['e']]), ['parti' => ['riepilogo']])->assertSessionHasNoErrors();
    expect(RendicontoVersione::where('esercizio_id', $s['e']->id)->count())->toBe(2)
        ->and(VersioniDelRendiconto::perLaChiusura($s['e'])['versione_id'])->toBe($v->id)
        ->and(VersioniDelRendiconto::perLaChiusura($s['e'])['conguaglio']['condominio']['conguaglio'])->toBe(28000);

    // «Riparto: no»: il conguaglio resta una proposta, e il contratto lo dice.
    $t = rdaViaVerdi($this);
    rdaApprovata($this, $t, '0');
    expect(VersioniDelRendiconto::perLaChiusura($t['e'])['riparto_approvato'])->toBeFalse();
});

it('i testi: «già rendicontato» non c\'è più; le tre frasi del conguaglio non promettono che cosa farà il programma o l\'amministratore', function () {
    $s = rdaViaVerdi($this);
    $s['e']->update(['stato' => 'chiuso']);
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('esercizio_id', $s['e']->id)->firstOrFail();
    expect($fattura->motivoBloccoEliminazione())->toStartWith('La fattura appartiene a un esercizio chiuso: le sue registrazioni non si riscrivono. Usa lo Storno');

    // In nessun file del prodotto: le parole del blocco e le promesse del conguaglio.
    $vietate = ['già rendicontato', 'diventa una richiesta', 'che il conguaglio chiede', 'l\'amministratore completerà', 'l\'amministratore le completerà',
        'l\'amministratore lo completerà', 'le manderà la cifra', 'la scrittura del conguaglio', 'appena possibile', 'l\'amministratore le scriverà'];
    $trovate = [];
    foreach (['app', 'resources/views', 'resources/js'] as $cartella) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($cartella), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! preg_match('/\.(php|vue|ts)$/', $file->getFilename()) || str_ends_with($file->getFilename(), '.test.ts')) {
                continue;
            }
            $testo = (string) file_get_contents($file->getPathname());
            foreach ($vietate as $v) {
                if (str_contains($testo, $v) || str_contains($testo, str_replace('\'', '\\\'', $v))) {
                    $trovate[] = $v.' in '.$file->getPathname();
                }
            }
        }
    }
    expect($trovate)->toBe([])
        ->and(ConguaglioDelRendiconto::FRASE_PROPOSTA)->toBe('Proposta di conguaglio, da approvare dall\'assemblea: il programma la calcola e la stampa, non la registra.');
});
