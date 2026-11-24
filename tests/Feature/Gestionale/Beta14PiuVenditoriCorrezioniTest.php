<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, decisione 705: il giro di correzioni dei passaggi registrati insieme (più persone che escono con lo stesso atto), dopo lo scettico della
 * tappa 7. Una prova per rilievo, dalle rotte dove si può.
 *
 * - **Rilievo 1.** Gli id di chi esce scritti a mano («+12», un booleano, 12.0) passavano le regole e non `insieme()`: si registrava una vendita senza chi
 *   esce. Ora la richiesta li legge in un modo solo e li rifiuta (422 sulla riga), e il motore non scrive mai una vendita senza chi esce.
 * - **Rilievo 2.** Il messaggio dopo «Annulla i passaggi» diceva «Le quote di 2 rate» (una rata con due quote) e «scritte dal passaggio».
 * - **Rilievo 5.** Con più persone che escono, le frasi di chi resta obbligato erano al singolare («Cecilia Angeli e Marta Acciaioli è liberato»), anche
 *   nella riserva d'usufrutto.
 * - **Rilievo 6.** La finestra «A chi va» della issue 58 diceva l'insieme in due righe sul box in comproprietà.
 * - **Gli allineamenti.** Le fermate dopo «Scrivi» nello storico solo per persona; la finestra «Annulla i passaggi» con la frase della bozza approvata
 *   («tornano titolari al 50 % senza data di fine; Luca Toscani esce dall'unità»); i lock di tutti prima di ogni lettura semplice nella registrazione.
 *
 * Il caso è quello della bozza approvata (`docs/design/beta14_piu_venditori_bozza.html`): Cecilia Angeli e Marta Acciaioli, al 50 % dell'Int. 1, vendono
 * a Luca Toscani con l'atto del 16 novembre 2026; il «Preventivo 2026» da € 960,00 in dodici rate da € 80,00 (€ 40,00 a testa), emesse fino a novembre.
 *
 * **Che cosa non copre.** MySQL: la prova dei lock guarda l'ordine delle istruzioni nella transazione (su SQLite il `FOR UPDATE` non si scrive), non
 * un'altra scheda in volo.
 */

use App\Actions\Subentro\RegistraSubentroAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Subentro\StoricoTitolarita;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    foreach (['Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    // Il giorno di oggi del caso: la settimana dopo l'atto del 16 novembre.
    $this->travelTo('2026-11-23 10:00:00');
});

/*
|--------------------------------------------------------------------------
| Helper (prefisso `pvco`: Pest carica tutti i file nello stesso processo)
|--------------------------------------------------------------------------
*/

/**
 * L'Int. 1 di Cecilia Angeli e Marta Acciaioli al 50 % dal 3 marzo 2019, il «Preventivo 2026» generato a gennaio con loro due, Luca Toscani del
 * condominio. Con `$terza` la sorella Tina al 33,33 % accanto alle due (33,34 % e 33,33 %).
 *
 * @return array<string, mixed>
 */
function pvcoScenario(bool $conPiano = true, bool $terza = false): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 96000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PVCO-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "pvco-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PVCO' . strtoupper($sigla) . 'PERSON' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $cecilia = $persona('Cecilia Angeli', 'c');
    $marta = $persona('Marta Acciaioli', 'm');
    $luca = $persona('Luca Toscani', 'l');
    $riga = fn (Anagrafica $p, float $quota) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario',
        'quota' => $quota, 'attivo' => true, 'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $tina = null;
    $rigaT = null;
    if ($terza) {
        $rigaC = $riga($cecilia, 33.34);
        $rigaM = $riga($marta, 33.33);
        $tina = $persona('Tina Angeli', 't');
        $rigaT = $riga($tina, 33.33);
    } else {
        $rigaC = $riga($cecilia, 50);
        $rigaM = $riga($marta, 50);
    }

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
            'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        ]);
        app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'cecilia', 'marta', 'luca', 'rigaC', 'rigaM', 'piano', 'tina', 'rigaT', 'tabella');
}

/** Emette a giornale le rate del piano con scadenza fino al giorno dato (di norma da gennaio a novembre). */
function pvcoEmetti(array $s, string $fino = '2026-11-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-PVCO-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/** Il modulo di «Registra passaggio» con più persone che escono: le righe nell'ordine, la quota è la somma. @param list<int> $righe */
function pvcoModulo(array $s, array $righe, ?Anagrafica $entrante = null, array $extra = []): array
{
    $quota = round((float) DB::table('anagrafica_immobile')->whereIn('id', $righe)->sum('quota'), 2);

    return array_merge([
        'tipo' => 'vendita', 'righe_uscenti' => $righe, 'riga_uscente_id' => null, 'anagrafica_entrante_id' => ($entrante ?? $s['luca'])->id,
        'decorrenza' => '2026-11-16', 'quota' => $quota, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-11-16',
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'pertinenze' => [],
    ], $extra);
}

function pvcoAnteprima($test, array $s, array $dati)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** La registrazione come la fa la pagina: l'impronta del pannello, la spunta se il pannello la chiede, l'impronta dell'ordinaria con la riserva. */
function pvcoRegistra($test, array $s, array $dati, ?array $pannello = null)
{
    $pannello ??= pvcoAnteprima($test, $s, $dati)->assertOk()->json();
    $dati += ['impronta_del_gruppo' => $pannello['impronta'] ?? null];
    if ($pannello['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.'];
    }
    if (($pannello['ordinaria']['impronta'] ?? null) !== null) {
        $dati += ['ordinaria_impronta' => $pannello['ordinaria']['impronta']];
    }

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
}

function pvcoPassaggi(array $s): \Illuminate\Support\Collection
{
    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->orderBy('id')->get();
}

/** Tutto ciò che una registrazione fermata non deve lasciare. */
function pvcoConteggi(): array
{
    return [
        'titolarita' => DB::table('anagrafica_immobile')->count(), 'aperte' => DB::table('anagrafica_immobile')->whereNull('data_fine')->count(),
        'subentri' => DB::table('subentri')->count(), 'saldi' => DB::table('saldi')->count(), 'quote' => DB::table('rate_quote')->count(),
        'registro' => DB::table('registro_modifiche')->count(), 'documenti' => DB::table('documenti')->count(),
    ];
}

/** Il box dell'Int. 1, con le righe date (persona, quota) e i suoi millesimi. @param list<array{0: Anagrafica, 1: float}> $titolari */
function pvcoBox(array $s, array $titolari, string $nome = 'Box 1'): Immobile
{
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => $nome, 'descrizione' => 'Box', 'interno' => 'B' . $s['unita']->id, 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('quote_tabella')->insert(['tabella_id' => $s['tabella']->id, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($titolari as [$p, $quota]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => $quota, 'attivo' => true,
            'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $box;
}

/** La voce dell'insieme nello storico dell'unità. */
function pvcoVoce(array $s): ?array
{
    return collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->first(fn (array $v) => ! empty($v['insieme']));
}

/*
|--------------------------------------------------------------------------
| Rilievo 1: gli id di chi esce, letti in un modo solo
|--------------------------------------------------------------------------
*/

/** Una richiesta JSON scritta a mano, con i numeri come li scrive chi la compone (12.0 resta 12.0: `postJson` lo scriverebbe 12). */
function pvcoJsonAMano($test, string $rotta, array $s, array $dati)
{
    return $test->actingAs($test->user)->call('POST', route($rotta, [$s['c'], $s['unita']]), [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($dati, JSON_PRESERVE_ZERO_FRACTION));
}

it('rilievo 1 — gli id di chi esce scritti a mano non accendono una vendita senza chi esce: 422 sulla riga dall\'anteprima e dalla registrazione, e niente resta', function (string $forma) {
    $s = pvcoScenario(conPiano: false);
    // Il caso dello scettico: Cecilia al 50 %, la riga di Marta chiusa a mano alla fine del 2025.
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['data_fine' => '2025-12-31']);
    $righe = match ($forma) {
        'con il segno' => ['+' . $s['rigaC'], '+' . $s['rigaM']],
        'con la virgola' => [(float) $s['rigaC'], (float) $s['rigaM']],
    };
    $dati = ['tipo' => 'vendita', 'righe_uscenti' => $righe, 'anagrafica_entrante_id' => $s['luca']->id, 'decorrenza' => '2026-11-16', 'quota' => 50,
        'tipologia' => 'proprietario', 'copia_autentica' => false, 'estremi_titolo' => 'Rogito del notaio Verdi', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi letto'];
    $prima = pvcoConteggi();
    $attesi = ['righe_uscenti.0' => ['La riga di chi esce non si riconosce: ricarica la pagina e spunta di nuovo chi esce.'],
        'righe_uscenti.1' => ['La riga di chi esce non si riconosce: ricarica la pagina e spunta di nuovo chi esce.']];

    $anteprima = pvcoJsonAMano($this, 'admin.gestionale.immobili.passaggi.anteprima', $s, $dati)->assertUnprocessable();
    $registra = pvcoJsonAMano($this, 'admin.gestionale.immobili.passaggi.store', $s, $dati)->assertUnprocessable();

    expect(array_intersect_key($anteprima->json('errors'), $attesi))->toBe($attesi)
        ->and(array_intersect_key($registra->json('errors'), $attesi))->toBe($attesi)
        ->and(pvcoConteggi())->toBe($prima)
        ->and(pvcoPassaggi($s))->toHaveCount(0)
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $s['luca']->id)->count())->toBe(0);
})->with(['con il segno', 'con la virgola']);

it('rilievo 1 — la rete nel motore: una vendita senza chi esce si ferma in RegistraSubentroAction, prima di ogni scrittura', function () {
    $s = pvcoScenario(conPiano: false);
    // Come nel caso dello scettico: la riga di Marta chiusa a mano, così Luca al 50 % non sfora le quote e niente altro ferma il passaggio.
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['data_fine' => '2025-12-31']);
    $prima = pvcoConteggi();
    $dati = [
        'tipo' => 'vendita', 'sottotipo' => null, 'riga_uscente' => null, 'entrante' => $s['luca'], 'decorrenza' => CarbonImmutable::parse('2026-11-16'),
        'quota' => 50.0, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'copia_autentica_il' => null, 'estremi_titolo' => 'Rogito del notaio Verdi',
        'nota' => null, 'data_fine_locazione' => null, 'regime_contratto' => null, 'pertinenze' => [], 'nudi_che_tornano' => null, 'nudi_per_quota' => false,
        'accrescimento' => false, 'ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi letto', 'allegato_titolo' => null, 'promemoria_scadenza' => false,
        'promemoria_giorni' => null, 'rinuncia_conguaglio' => false, 'nota_conguaglio' => null, 'conguaglio' => null, 'ordinaria_dopo_atto' => null,
        'voci_da_tenere' => [], 'ordinaria_impronta' => null, 'eredi' => [], 'legato' => false, 'arretrato' => null, 'erede_di_riferimento' => null,
        'accessori_segnalati_agli_eredi' => [], 'accordo_del_defunto' => null, 'interessi_del_defunto' => null, 'interessi_eredi' => null,
    ];

    $errori = null;
    try {
        app(RegistraSubentroAction::class)->execute($s['c'], $s['unita'], $dati, $this->user);
    } catch (ValidationException $e) {
        $errori = $e->errors();
    }

    expect($errori)->toBe(['riga_uscente_id' => ['Manca chi esce: scegli la riga in «Chi esce» e ricalcola il pannello.']])
        ->and(pvcoConteggi())->toBe($prima);
});

/*
|--------------------------------------------------------------------------
| Rilievo 2: il messaggio dopo «Annulla i passaggi»
|--------------------------------------------------------------------------
*/

it('rilievo 2 — il messaggio dopo «Annulla i passaggi» dice le frasi dell\'insieme: una rata per persona, le righe «scritte dai passaggi», le 4 righe del conguaglio', function () {
    $s = pvcoScenario();
    pvcoEmetti($s);
    pvcoRegistra($this, $s, pvcoModulo($s, [$s['rigaC'], $s['rigaM']]))->assertSessionHasNoErrors();
    $passaggi = pvcoPassaggi($s);
    // Una rata sola (la bozza del 5 dicembre), con due quote di Luca: una da ciascuna.
    expect(DB::table('rate_quote')->where('anagrafica_id', $s['luca']->id)->pluck('rata_id')->unique())->toHaveCount(1);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $passaggi[0]]), ['nota_annullamento' => 'Atto registrato con la data sbagliata: lo rifaccio.'])
        ->assertSessionHasNoErrors();

    expect(session('message')['message'])->toBe('2 passaggi annullati insieme. Le righe di titolarità scritte dai passaggi sono tornate come prima. '
        . 'La quota di una rata passata a Luca Toscani è tornata a Cecilia Angeli, con le regole di prima. '
        . 'La quota di una rata passata a Luca Toscani è tornata a Marta Acciaioli, con le regole di prima. '
        . 'Le 4 righe del conguaglio sono state tolte dai saldi della gestione. Restano nello storico, con la tua nota.');
});

/*
|--------------------------------------------------------------------------
| Rilievo 5: chi resta obbligato, con più persone che escono
|--------------------------------------------------------------------------
*/

it('rilievo 5 — la vendita piena: dopo la copia autentica la liberazione «vale per Cecilia Angeli e Marta Acciaioli», e il regresso è verso chi ha venduto', function () {
    $s = pvcoScenario(conPiano: false);
    pvcoRegistra($this, $s, pvcoModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['copia_autentica' => false, 'copia_autentica_il' => null]))->assertSessionHasNoErrors();
    $passaggi = pvcoPassaggi($s);
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], $passaggi[1]]), ['copia_autentica_il' => '2026-11-20'])
        ->assertSessionHasNoErrors();

    $obbligati = pvcoVoce($s)['obbligati'];
    expect($obbligati[1])->toBe('Copia autentica del titolo ricevuta il 20 novembre 2026: da quel giorno la liberazione verso il condominio per i contributi successivi vale per '
        . 'Cecilia Angeli e Marta Acciaioli (art. 63 co. 5 disp. att. c.c.). La solidarietà del comma 4 sui contributi dell\'esercizio in corso e del precedente non dipende dalla copia e resta.')
        ->and($obbligati[0])->toContain('Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli')
        ->toContain('ha regresso verso chi ha venduto per quanto ha pagato al condominio')
        ->and(implode(' ', $obbligati))->not->toContain('è liberato')->not->toContain('il venditore');
});

it('rilievo 5 — la riserva d\'usufrutto: nel pannello e nello storico chi conserva l\'usufrutto si dice al plurale, senza «usufruttuario, rispondono» né «resta usufruttuario»', function () {
    $s = pvcoScenario(conPiano: false);
    $modulo = pvcoModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['sottotipo' => Subentro::RISERVA_USUFRUTTO, 'tipologia' => 'nuda_proprietario',
        'ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE, 'copia_autentica' => false, 'copia_autentica_il' => null]);
    $p = pvcoAnteprima($this, $s, $modulo)->assertOk()->json();
    expect($p['obbligati']['frasi'][0])->toBe('Dal 16 novembre 2026 Luca Toscani, nudo proprietario, risponde in solido verso il condominio con Cecilia Angeli e Marta Acciaioli, '
        . 'che conservano l\'usufrutto (art. 67 ult. co. disp. att. c.c.); fra di loro le spese ordinarie sono dell\'usufruttuario (art. 1004 c.c.), quelle straordinarie del nudo '
        . 'proprietario (art. 1005 c.c.). Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».')
        ->and($p['obbligati']['frasi'][2])->toBe('La copia autentica del titolo si registra come per ogni vendita, ma non libera Cecilia Angeli e Marta Acciaioli: conservano l\'usufrutto, '
            . 'e per i contributi successivi rispondono in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.).');

    pvcoRegistra($this, $s, $modulo, $p)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], pvcoPassaggi($s)[0]]), ['copia_autentica_il' => '2026-11-20'])
        ->assertSessionHasNoErrors();
    $obbligati = pvcoVoce($s)['obbligati'];
    expect($obbligati[2])->toBe('Copia autentica del titolo ricevuta il 20 novembre 2026: per i contributi successivi la liberazione non vale per Cecilia Angeli e Marta Acciaioli, '
        . 'perché conservano l\'usufrutto e rispondono in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.).')
        ->and(implode(' ', $obbligati))->not->toContain('è liberato')->not->toContain('resta usufruttuario')->not->toContain(', usufruttuario, rispondono');
});

/*
|--------------------------------------------------------------------------
| Rilievo 6: la finestra «A chi va» del box in comproprietà
|--------------------------------------------------------------------------
*/

it('rilievo 6 — la finestra «A chi va» della issue 58 sul box in comproprietà dice l\'insieme in una riga, come sull\'unità', function () {
    $s = pvcoScenario(conPiano: false);
    $box = pvcoBox($s, [[$s['cecilia'], 50], [$s['marta'], 50]]);
    foreach ([$s['unita'], $box] as $u) {
        $this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => null, 'immobile_id' => $u->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000,
        ])->assertSessionHasNoErrors();
    }
    pvcoRegistra($this, $s, pvcoModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['pertinenze' => [$box->id]]))->assertSessionHasNoErrors();

    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-saldi-analitici', [$s['c'], $s['e'], $s['g']]))->assertOk()->json();
    $passaggiDi = fn (Immobile $u) => collect($righe)->firstWhere('id', Saldo::where('immobile_id', $u->id)->whereNull('anagrafica_id')->value('id'))['riparto_previsto']['passaggi'];
    $attesa = [['data' => '2026-11-16', 'tipo' => 'vendita', 'etichetta' => 'Vendita', 'riserva' => false, 'uscenti' => ['Cecilia Angeli', 'Marta Acciaioli'], 'entranti' => ['Luca Toscani']]];

    expect($passaggiDi($s['unita']))->toBe($attesa)
        ->and($passaggiDi($box))->toBe($attesa);
});

/*
|--------------------------------------------------------------------------
| Gli allineamenti: le fermate per persona, la finestra «Annulla i passaggi», i lock
|--------------------------------------------------------------------------
*/

it('le fermate dopo «Scrivi» nello storico dell\'insieme stanno nella parte di ciascuna persona, non anche in cima', function () {
    // Lo scenario O-2F della prova 2 di `Beta14PiuVenditoriTest`: Ugo e Anna al 50 %, la fine di Ines il 24/1 con «Scrivi», le due vendite a Elsa l'1/4.
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $modulo = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'righe_uscenti' => [fds50Riga($s, 'Ugo', 'proprietario'), fds50Riga($s, 'Anna', 'proprietario')],
        'anagrafica_entrante_id' => $s['persone']['Elsa']->id, 'decorrenza' => '2026-04-01', 'quota' => 100, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];
    pvcoRegistra($this, $s, $modulo)->assertSessionHasNoErrors();

    $voce = pvcoVoce($s);
    $parti = $voce['insieme']['passaggi'];
    expect($voce['fermate'])->toBe([])
        ->and($parti[0]['uscente'])->toBe('Ugo')->and($parti[0]['fermate'])->toHaveCount(1)
        ->and($parti[1]['uscente'])->toBe('Anna')->and($parti[1]['fermate'])->toHaveCount(1)
        ->and($parti[0]['fermate'][0])->not->toBe($parti[1]['fermate'][0]);
});

it('la finestra «Annulla i passaggi» dice chi torna titolare, con la quota, e chi esce, come la bozza approvata', function (string $caso) {
    $s = pvcoScenario(conPiano: false, terza: $caso === 'i tre fratelli');
    $extra = match ($caso) {
        'la riserva' => ['sottotipo' => Subentro::RISERVA_USUFRUTTO, 'tipologia' => 'nuda_proprietario', 'ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE],
        'il box di Marta' => ['pertinenze' => [pvcoBox($s, [[$s['marta'], 100]])->id]],
        default => [],
    };
    pvcoRegistra($this, $s, pvcoModulo($s, [$s['rigaC'], $s['rigaM']], $caso === 'i tre fratelli' ? $s['tina'] : null, $extra))->assertSessionHasNoErrors();

    // Il numero e il segno di percentuale stanno insieme (lo spazio non separabile di `NudiDellEstinzione::percentuale`): qui si leggono con uno spazio.
    $effetti = array_map(fn (string $f) => str_replace("\u{00A0}", ' ', $f), pvcoVoce($s)['annullabile']['effetti']);
    $attese = match ($caso) {
        'la vendita' => ['Cecilia Angeli e Marta Acciaioli tornano titolari al 50 % senza data di fine; Luca Toscani esce dall\'unità.'],
        'i tre fratelli' => ['Cecilia Angeli al 33,34 % e Marta Acciaioli al 33,33 % tornano titolari senza data di fine; Tina Angeli torna al 33,33 %.'],
        'la riserva' => ['Cecilia Angeli e Marta Acciaioli tornano titolari della proprietà piena al 50 % senza data di fine, e l\'usufrutto tenuto con l\'atto si toglie; Luca Toscani esce dall\'unità.'],
        'il box di Marta' => ['Cecilia Angeli e Marta Acciaioli tornano titolari al 50 % senza data di fine; Luca Toscani esce dall\'unità.',
            'Su Box 1 le righe di titolarità scritte dai passaggi tornano come prima.'],
    };
    expect(array_slice($effetti, 0, count($attese)))->toBe($attese)
        ->and($effetti)->not->toContain('Le righe di titolarità scritte dai passaggi tornano come prima.');
})->with(['la vendita', 'i tre fratelli', 'la riserva', 'il box di Marta']);

it('i lock: la registrazione dell\'insieme blocca l\'unità e le pertinenze di tutti prima di ogni lettura, anche il box della sola Marta', function () {
    $s = pvcoScenario(conPiano: false);
    $box = pvcoBox($s, [[$s['marta'], 100]]);
    $modulo = pvcoModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['pertinenze' => [$box->id]]);
    $pannello = pvcoAnteprima($this, $s, $modulo)->assertOk()->json();

    // Le istruzioni della registrazione, dalla prima transazione in poi: la prima deve bloccare l'unità e il box (su MySQL `FOR UPDATE`; SQLite non lo
    // scrive, e la forma della richiesta è quella di `Immobile::bloccaPerScrivere`).
    $eventi = [];
    Event::listen(TransactionBeginning::class, function () use (&$eventi) {
        $eventi[] = ['transazione'];
    });
    DB::listen(function (QueryExecuted $q) use (&$eventi) {
        $eventi[] = ['query', $q->sql, $q->bindings];
    });
    pvcoRegistra($this, $s, $modulo, $pannello)->assertSessionHasNoErrors();

    $dopo = array_slice($eventi, (int) array_search(['transazione'], $eventi, true) + 1);
    $prima = collect($dopo)->first(fn (array $e) => $e[0] === 'query');
    expect($prima[1])->toBe('select "id" from "immobili" where "id" in (?, ?)')
        ->and(array_map('intval', $prima[2]))->toBe([(int) $s['unita']->id, (int) $box->id])
        ->and(pvcoPassaggi($s))->toHaveCount(2);
});
