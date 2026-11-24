<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, decisione 705: più persone che escono in un passaggio solo. Cecilia Angeli e Marta Acciaioli, in comproprietà al 50 % dell'Int. 1,
 * vendono a Luca Toscani con lo stesso atto: il programma registra un passaggio per ciascuna, con la stessa data e lo stesso atto, nella stessa
 * transazione e sotto un gesto solo del registro delle modifiche (`RegistraPassaggiInsiemeAction`), tutti o nessuno; il legame è `subentri.insieme_id`.
 *
 * Il caso è quello della bozza approvata (`docs/design/beta14_piu_venditori_bozza.html`): il «Preventivo 2026» da € 960,00 in dodici rate da € 80,00
 * (€ 40,00 a testa), emesse fino a novembre; l'atto del 16 novembre 2026.
 *
 * **Che cosa non copre**: MySQL (la suite gira su SQLite: i lock dell'unità durante la prova a secco, con una registrazione in volo, sono dello
 * scettico); l'annullamento, lo storico e le catene dei conguagli sono in `Beta14PiuVenditoriAnnullamentoTest` e `Beta14PiuVenditoriCateneTest`.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\Subentro\RegistraPassaggiInsiemeAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta51PassaggiScenari.php';
require_once __DIR__.'/../Assemblee/Convocazione/ConvocazioneDiViaRoma12.php';

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
| Helper (prefisso `pv`: Pest carica tutti i file nello stesso processo)
|--------------------------------------------------------------------------
*/

/**
 * L'Int. 1 di Cecilia Angeli e Marta Acciaioli al 50 % dal 3 marzo 2019, il «Preventivo 2026» generato a gennaio con loro due, Luca Toscani del
 * condominio. Con `$terza` la sorella Tina al 33,33 % accanto alle due (33,34 % e 33,33 %).
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, cecilia: Anagrafica, marta: Anagrafica, luca: Anagrafica, rigaC: int, rigaM: int, piano: ?PianoRate, tina: ?Anagrafica, rigaT: ?int, conto: Conto}
 */
function pvScenario(bool $conPiano = true, bool $terza = false, string $ruolo = 'proprietario', array $ripartizione = ['proprietario' => 100], bool $lucaInt2 = false): array
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
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PV-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "pv-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PV' . strtoupper($sigla) . 'PERSONA' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $cecilia = $persona('Cecilia Angeli', 'c');
    $marta = $persona('Marta Acciaioli', 'm');
    $luca = $persona('Luca Toscani', 'l');
    $riga = fn (Anagrafica $p, float $quota) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo,
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

    // L'Int. 2 di Luca Toscani: chi compra ha già un'altra unità (le teste in assemblea).
    if ($lucaInt2) {
        $unita2 = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PV2-{$seq}", 'nome' => 'Interno 2', 'interno' => '2']);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita2->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $luca->id, 'immobile_id' => $unita2->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
            'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        ]);
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'cecilia', 'marta', 'luca', 'rigaC', 'rigaM', 'piano', 'tina', 'rigaT', 'conto');
}

/** Emette a giornale le rate del piano con scadenza fino al giorno dato (di norma da gennaio a novembre). */
function pvEmetti(array $s, string $fino = '2026-11-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-PV-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/**
 * I dati di una persona dell'insieme nella forma di `AnteprimaPassaggioRequest::datiPassaggio()` (le prove dell'azione la chiamano senza la richiesta,
 * come `AnnullaPassaggioTest` A1).
 *
 * @return array<string, mixed>
 */
function pvMembro(int $riga, Anagrafica $entrante, string $decorrenza = '2026-11-16', array $extra = []): array
{
    $uscente = TitolaritaImmobile::with('anagrafica')->findOrFail($riga);

    return array_merge([
        'tipo' => 'vendita', 'sottotipo' => null, 'riga_uscente' => $uscente, 'entrante' => $entrante, 'decorrenza' => CarbonImmutable::parse($decorrenza)->startOfDay(),
        'quota' => (float) $uscente->quota, 'tipologia' => (string) $uscente->tipologia, 'copia_autentica' => true, 'copia_autentica_il' => CarbonImmutable::parse($decorrenza),
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'nota' => null, 'data_fine_locazione' => null, 'regime_contratto' => null, 'pertinenze' => [],
        'nudi_che_tornano' => null, 'nudi_per_quota' => false, 'accrescimento' => false, 'ho_letto' => true,
        'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.', 'allegato_titolo' => null, 'promemoria_scadenza' => false, 'promemoria_giorni' => null,
        'rinuncia_conguaglio' => false, 'nota_conguaglio' => null, 'conguaglio' => null, 'ordinaria_dopo_atto' => null, 'voci_da_tenere' => [], 'ordinaria_impronta' => null,
        'eredi' => [], 'legato' => false, 'arretrato' => null, 'erede_di_riferimento' => null, 'accessori_segnalati_agli_eredi' => [], 'accordo_del_defunto' => null,
        'interessi_del_defunto' => null, 'interessi_eredi' => null,
    ], $extra);
}

/** I dati dell'insieme per l'azione: quelli comuni (del primo) e `membri`, uno per riga. @param list<int> $righe */
function pvInsieme(array $righe, Anagrafica $entrante, string $decorrenza = '2026-11-16', array $extra = []): array
{
    $membri = array_map(fn (int $r) => pvMembro($r, $entrante, $decorrenza, $extra), $righe);

    return $membri[0] + ['membri' => $membri, 'impronta_del_gruppo' => $extra['impronta_del_gruppo'] ?? null];
}

/** Ciò che una registrazione fallita non deve lasciare: righe, passaggi, saldi, quote e righe del registro, contati. */
function pvConteggi(): array
{
    return [
        'titolarita' => DB::table('anagrafica_immobile')->count(), 'aperte' => DB::table('anagrafica_immobile')->whereNull('data_fine')->count(),
        'subentri' => DB::table('subentri')->count(), 'saldi' => DB::table('saldi')->count(), 'quote' => DB::table('rate_quote')->count(),
        'intestatari_quote' => (int) DB::table('rate_quote')->sum('anagrafica_id'), 'registro' => DB::table('registro_modifiche')->count(),
        'documenti' => DB::table('documenti')->count(),
    ];
}

/*
|--------------------------------------------------------------------------
| La prima parte: l'azione dell'insieme e il suo legame
|--------------------------------------------------------------------------
*/

it('l\'azione dell\'insieme: due passaggi con lo stesso insieme_id (l\'id del primo), Luca su una riga sola al 100 %, le coppie di ciascuna, una riga sola del registro delle modifiche', function () {
    $s = pvScenario();
    pvEmetti($s);
    $righePrima = DB::table('registro_modifiche')->count();

    $esito = app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], pvInsieme([$s['rigaC'], $s['rigaM']], $s['luca']), $this->user);

    $passaggi = Subentro::whereNull('subentro_padre_id')->orderBy('id')->get();
    expect($passaggi)->toHaveCount(2)
        ->and($passaggi->pluck('insieme_id')->unique()->all())->toBe([$passaggi[0]->id])
        ->and($passaggi->pluck('anagrafica_uscente_id')->all())->toBe([$s['cecilia']->id, $s['marta']->id])
        ->and($passaggi->pluck('anagrafica_entrante_id')->unique()->all())->toBe([$s['luca']->id])
        ->and(collect($esito['passaggi'])->pluck('id')->all())->toBe($passaggi->pluck('id')->all());

    // Luca: una riga sola, nata il 16/11 al 100 %; le due sorelle chiuse il 15/11.
    $luca = DB::table('anagrafica_immobile')->where('anagrafica_id', $s['luca']->id)->get();
    expect($luca)->toHaveCount(1)
        ->and((float) $luca[0]->quota)->toBe(100.0)
        ->and((string) $luca[0]->data_inizio)->toStartWith('2026-11-16')
        ->and(DB::table('anagrafica_immobile')->whereIn('id', [$s['rigaC'], $s['rigaM']])->pluck('data_fine')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all())->toBe(['2026-11-15']);

    // Le coppie di ciascuna: quelle del suo pannello, con il suo `subentro_id`. € 20,49 a credito di ciascuna, € 40,98 a debito di Luca (la bozza).
    foreach ($esito['esiti'] as $i => $e) {
        $attese = collect($e['anteprima']['rate']['conguaglio']['coppie'])->sum('importo');
        expect((int) Saldo::where('subentro_id', $passaggi[$i]->id)->where('anagrafica_id', $s['luca']->id)->sum('saldo_iniziale'))->toBe((int) $attese);
    }
    expect((int) Saldo::where('subentro_id', $passaggi[0]->id)->where('anagrafica_id', $s['cecilia']->id)->sum('saldo_iniziale'))->toBe(-2049)
        ->and((int) Saldo::where('subentro_id', $passaggi[1]->id)->where('anagrafica_id', $s['marta']->id)->sum('saldo_iniziale'))->toBe(-2049)
        ->and((int) Saldo::where('anagrafica_id', $s['luca']->id)->sum('saldo_iniziale'))->toBe(4098);

    // Una riga sola del registro, con i nomi di tutte, i soggetti e il rimando al primo passaggio.
    expect(DB::table('registro_modifiche')->count())->toBe($righePrima + 1);
    $riga = DB::table('registro_modifiche')->orderByDesc('id')->first();
    expect($riga->riepilogo)->toBe('Passaggi registrati insieme: vendita dell\'unità Int. 1 da Cecilia Angeli e Marta Acciaioli a Luca Toscani dal 16/11/2026')
        ->and((int) $riga->oggetto_id)->toBe($passaggi[0]->id)
        ->and(json_decode($riga->rimando, true)['id'])->toBe($passaggi[0]->id)
        ->and(json_decode($riga->lotto, true)['conteggi']['passaggio.gesto'] ?? null)->toBe(2)
        ->and(DB::table('registro_modifiche_soggetti')->where('riga_id', $riga->id)->where('soggetto_tipo', 'anagrafica')->pluck('soggetto_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$s['cecilia']->id, $s['marta']->id, $s['luca']->id])->sort()->values()->all());
});

it('il legame: membriDellInsieme dà i passaggi dell\'insieme nell\'ordine da qualunque dei due, e un passaggio da solo resta senza insieme_id', function () {
    $s = pvScenario(conPiano: false);
    app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], pvInsieme([$s['rigaC'], $s['rigaM']], $s['luca']), $this->user);
    [$primo, $secondo] = Subentro::whereNull('subentro_padre_id')->orderBy('id')->get()->all();

    expect($secondo->membriDellInsieme()->pluck('id')->all())->toBe([$primo->id, $secondo->id])
        ->and($primo->membriDellInsieme()->pluck('id')->all())->toBe([$primo->id, $secondo->id])
        ->and($secondo->insieme->id)->toBe($primo->id)
        ->and($primo->nellInsieme())->toBeTrue();

    // Un'altra unità, un passaggio da solo: nessun insieme.
    $t = pvScenario(conPiano: false);
    app(\App\Actions\Subentro\RegistraSubentroAction::class)->execute($t['c'], $t['unita'], pvMembro($t['rigaC'], $t['luca']), $this->user);
    $solo = Subentro::whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    expect($solo->insieme_id)->toBeNull()
        ->and($solo->nellInsieme())->toBeFalse()
        ->and($solo->membriDellInsieme()->pluck('id')->all())->toBe([$solo->id]);
});

it('tutti o nessuno dall\'azione: il secondo si ferma, niente resta scritto, il messaggio dice di chi è, il file del titolo non resta sul disco', function () {
    Storage::fake('local');
    $s = pvScenario();
    pvEmetti($s);
    // La riga di Marta chiusa a mano prima del clic: il primo passa, il secondo si ferma sotto il lock.
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['data_fine' => '2026-03-01']);
    $prima = pvConteggi();
    $dati = pvInsieme([$s['rigaC'], $s['rigaM']], $s['luca']);
    $dati['membri'][0]['allegato_titolo'] = UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf');

    try {
        app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], $dati, $this->user);
        $this->fail('L\'insieme doveva fermarsi.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('decorrenza')
            ->and($e->errors()['decorrenza'][0])->toBe('La parte di Marta Acciaioli: questo periodo si è chiuso il 1 marzo 2026: la decorrenza non può essere successiva di più di un giorno.');
    }
    expect(pvConteggi())->toBe($prima)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('il titolo allegato è uno per tutti: il documento del primo, con i nomi di tutte, sullo stesso documento_id di ogni passaggio', function () {
    Storage::fake('local');
    $s = pvScenario(conPiano: false);
    $dati = pvInsieme([$s['rigaC'], $s['rigaM']], $s['luca']);
    $dati['membri'][0]['allegato_titolo'] = UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf');

    $esito = app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], $dati, $this->user);

    $documento = Documento::sole();
    expect(Subentro::whereNull('subentro_padre_id')->pluck('documento_id')->unique()->all())->toBe([$documento->id])
        ->and($documento->name)->toBe('Titolo di provenienza — passaggio del 16 novembre 2026 (Cecilia Angeli, Marta Acciaioli → Luca Toscani)')
        ->and($esito['documento']?->id)->toBe($documento->id)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('l\'impronta dell\'insieme: diversa da quella del pannello, l\'azione si ferma prima del commit con il nome della parte, e niente resta; uguale, registra', function () {
    $s = pvScenario();
    pvEmetti($s);
    $dati = pvInsieme([$s['rigaC'], $s['rigaM']], $s['luca']);

    // L'impronta giusta da una registrazione tolta (come la prova a secco del pannello): una parte per persona, nell'ordine.
    DB::beginTransaction();
    $giusta = app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], $dati, $this->user)['impronta'];
    DB::rollBack();
    $parti = explode('.', $giusta);
    expect($parti)->toHaveCount(2);
    $prima = pvConteggi();

    try {
        app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], ['impronta_del_gruppo' => $parti[0] . '.000000000000'] + $dati, $this->user);
        $this->fail('L\'insieme doveva fermarsi.');
    } catch (ValidationException $e) {
        expect($e->errors()['passaggio'][0])->toBe('Mentre registravi è cambiato qualcosa (la parte di Marta Acciaioli): il pannello è stato ricalcolato, ricontrolla e conferma.');
    }
    expect(pvConteggi())->toBe($prima);

    app(RegistraPassaggiInsiemeAction::class)->execute($s['c'], $s['unita'], ['impronta_del_gruppo' => $giusta] + $dati, $this->user);
    expect(Subentro::whereNull('subentro_padre_id')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| La seconda parte: dalle rotte, l'anteprima con la prova a secco e la registrazione
|--------------------------------------------------------------------------
*/

/** Il modulo di «Registra passaggio» con più persone che escono: le righe nell'ordine, la quota è la somma. @param list<int> $righe */
function pvModulo(array $s, array $righe, ?Anagrafica $entrante = null, array $extra = []): array
{
    $quota = round((float) DB::table('anagrafica_immobile')->whereIn('id', $righe)->sum('quota'), 2);

    return array_merge([
        'tipo' => 'vendita', 'righe_uscenti' => $righe, 'riga_uscente_id' => null, 'anagrafica_entrante_id' => ($entrante ?? $s['luca'])->id,
        'decorrenza' => '2026-11-16', 'quota' => $quota, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-11-16',
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'pertinenze' => [],
    ], $extra);
}

function pvAnteprima($test, array $s, array $dati)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** La registrazione come la fa la pagina: l'impronta del pannello, la spunta se il pannello la chiede, l'impronta dell'ordinaria con la riserva. */
function pvRegistra($test, array $s, array $dati, ?array $pannello = null)
{
    $pannello ??= pvAnteprima($test, $s, $dati)->assertOk()->json();
    $dati += ['impronta_del_gruppo' => $pannello['impronta']];
    if ($pannello['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.'];
    }
    if (($pannello['ordinaria']['impronta'] ?? null) !== null) {
        $dati += ['ordinaria_impronta' => $pannello['ordinaria']['impronta']];
    }

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
}

function pvPassaggi(array $s): \Illuminate\Support\Collection
{
    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->orderBy('id')->get();
}

it('1 — Cecilia e Marta vendono a Luca dalla rotta: il pannello dell\'insieme, due passaggi con lo stesso insieme_id, Luca una riga al 100 %, le coppie delle parti del pannello, una riga del registro, il riquadro verde', function () {
    $s = pvScenario();
    pvEmetti($s);
    $p = pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]))->assertOk()->json();

    // Il pannello: i fatti per tutte, una parte per ciascuna, quello che arriva a Luca in tutto (le cifre della bozza, rifatte a mano: € 480,00 a testa
    // divisi 319/46 giorni = € 60,49 per Luca, meno la bozza di dicembre da € 40,00 che passa a suo nome = € 20,49 da ciascuna).
    expect($p['insieme'])->toBeTrue()
        ->and($p['riferimento']['frase'])->toBe('Cecilia Angeli e Marta Acciaioli risulteranno titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.')
        ->and($p['anagrafica']['frasi'][0])->toBe('Cecilia Angeli e Marta Acciaioli risulteranno titolari fino al 15 novembre 2026. Luca Toscani dal 16 novembre 2026, come proprietario al 100 %.')
        ->and(collect($p['parti'])->map(fn ($x) => [$x['nome'], $x['quota'], $x['riga_id']])->all())->toBe([['Cecilia Angeli', 50, $s['rigaC']], ['Marta Acciaioli', 50, $s['rigaM']]])
        ->and(collect($p['parti'])->map(fn ($x) => $x['anteprima']['rate']['conguaglio']['totale_entrante'])->all())->toBe([2049, 2049])
        ->and($p['totale']['frase'])->toBe('In tutto a Luca Toscani: € 40,98 di conguaglio a debito (€ 20,49 da Cecilia Angeli e € 20,49 da Marta Acciaioli) e 2 quote in bozza sulla rata del 5 dicembre 2026, € 80,00 in tutto, che da ora paga a suo nome.')
        ->and($p['totale']['conguaglio_entrante'])->toBe(4098)
        ->and($p['totale']['bozze'])->toBe(2)
        ->and($p['obbligati']['frasi'][0])->toStartWith('Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli per i contributi di questa unità')
        ->and($p['cancello']['richiesto'])->toBeTrue()
        ->and(collect($p['cancello']['gruppi'])->pluck('nome')->all())->toBe(['Cecilia Angeli', 'Marta Acciaioli'])
        ->and(explode('.', $p['impronta']))->toHaveCount(2);
    $righePrima = DB::table('registro_modifiche')->count();

    pvRegistra($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]), $p)->assertSessionHasNoErrors()->assertRedirect();

    $passaggi = pvPassaggi($s);
    expect($passaggi)->toHaveCount(2)
        ->and($passaggi->pluck('insieme_id')->unique()->all())->toBe([$passaggi[0]->id])
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $s['luca']->id)->pluck('quota')->map(fn ($q) => (float) $q)->all())->toBe([100.0]);
    // Le coppie scritte sono quelle delle parti del pannello: anteprima = scrittura.
    foreach ($p['parti'] as $i => $parte) {
        $attese = collect($parte['anteprima']['rate']['conguaglio']['coppie'])->filter(fn ($c) => (int) $c['importo'] !== 0)
            ->map(fn ($c) => [(int) $c['gestione_id'], (int) $c['immobile_id'], (int) $c['importo']])->values()->all();
        $scritte = Saldo::where('subentro_id', $passaggi[$i]->id)->where('anagrafica_id', $s['luca']->id)->orderBy('id')->get()
            ->map(fn ($r) => [(int) $r->gestione_id, (int) $r->immobile_id, (int) $r->saldo_iniziale])->all();
        expect($scritte)->toBe($attese);
    }
    expect(DB::table('registro_modifiche')->count())->toBe($righePrima + 1)
        ->and(DB::table('registro_modifiche')->orderByDesc('id')->value('riepilogo'))->toBe('Passaggi registrati insieme: vendita dell\'unità Int. 1 da Cecilia Angeli e Marta Acciaioli a Luca Toscani dal 16/11/2026');

    // Il riquadro verde: la frase per tutte, la coppia di ciascuna di chi esce, il totale di Luca, le bozze passate.
    $flash = session('passaggio_registrato');
    expect(collect($flash['insieme'])->map(fn ($x) => [$x['nome'], $x['quota'], $x['frase_conguaglio']])->all())
        ->toBe([['Cecilia Angeli', 50.0, '€ 20,49 a credito di Cecilia Angeli'], ['Marta Acciaioli', 50.0, '€ 20,49 a credito di Marta Acciaioli']])
        ->and($flash['insieme'][1]['estratto_conto'])->toContain('/anagrafiche/' . $s['marta']->id)
        ->and($flash['conguaglio'])->toBe('€ 40,98')
        ->and($flash['conguaglio_rovesciato'])->toBeFalse()
        ->and($flash['subentro_id'])->toBe($passaggi[0]->id)
        ->and($flash['frase'])->toBe('Cecilia Angeli e Marta Acciaioli risultano titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.')
        ->and($flash['riassegnate'])->toBe(2)
        ->and($flash['riassegnate_frase'])->toBe('Le 2 quote in bozza della rata del 5 dicembre 2026 del piano «Preventivo 2026» sono passate a Luca Toscani: è cambiato l\'intestatario, non l\'importo.')
        ->and(session('message')['message'])->toBe('2 passaggi registrati insieme. Cecilia Angeli e Marta Acciaioli risultano titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.');
});

it('2 — anteprima = scrittura dopo una fine «Scrivi» (O-2F con le due vendite lo stesso giorno): la parte di Anna € 142,93 nel pannello e nel registro, da sola € 142,94; poi l\'inizio di Marco con un residuo solo per Elsa', function () {
    // Lo scenario della decisione 86.1: Ugo e Anna al 50 %, la fine di Ines il 24/1 con «Scrivi» (coppia 35552 a metà). Conti a mano (2026, 365 giorni):
    // dopo la vendita di Ugo i pesi sono Ugo 0,5 × 67 = 33,5, Anna 171, Elsa 0,5 × 275 = 137,5 su 342: Elsa 14293,6 → 14294 (il resto va a lei);
    // dopo le due vendite Elsa 275 su 342 = 28587,1 → 28587, e la fermata di Anna è 28587 − 14294 = 14293. Da sola, la parte di Anna sarebbe la
    // simmetrica di quella di Ugo, 14294 (sonda S10). Marco dall'1/6 (214 giorni): Elsa 61 su 342 = 6341,2 → 6341, Marco 22245,9 → 22246; residuo 6341.
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $modulo = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'righe_uscenti' => [fds50Riga($s, 'Ugo', 'proprietario'), fds50Riga($s, 'Anna', 'proprietario')],
        'anagrafica_entrante_id' => $s['persone']['Elsa']->id, 'decorrenza' => '2026-04-01', 'quota' => 100, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];
    $sola = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-04-01', 50));
    expect(b51aFermate($sola))->toBe([['Anna', 14294, ['Elsa']]]);

    $p = fclAnteprima($this, $s, $modulo);
    expect(array_map(fn ($parte) => b51aFermate($parte['anteprima']), $p['parti']))->toBe([[['Ugo', 14294, ['Elsa']]], [['Anna', 14293, ['Elsa']]]])
        ->and($p['cancello']['richiesto'])->toBeTrue();

    pvRegistra($this, $s, $modulo, $p)->assertSessionHasNoErrors()->assertRedirect();
    $passaggi = pvPassaggi($s)->where('tipo_passaggio', 'vendita')->values();
    expect($passaggi)->toHaveCount(2)
        ->and(array_map(fn ($f) => [$f['persona'], (int) $f['cifra']], fds50Fermate($passaggi[0])))->toBe([['Ugo', 14294]])
        ->and(array_map(fn ($f) => [$f['persona'], (int) $f['cifra']], fds50Fermate($passaggi[1])))->toBe([['Anna', 14293]]);

    // Il passaggio dopo legge le due fermate: un residuo solo, per Elsa, come in O-2F.
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $ridotte = $a['rate']['fermate_ridotte'];
    expect($ridotte)->toHaveCount(1)
        ->and([(int) $ridotte[0]['cifra'], (int) $ridotte[0]['prima'], (int) $ridotte[0]['residuo']])->toBe([28587, 28587, 6341])
        ->and($ridotte[0]['verso'])->toBe(['Elsa'])
        ->and(array_map('intval', $ridotte[0]['passaggi_ids'] ?? []))->toBe([(int) $passaggi[0]->id, (int) $passaggi[1]->id]);
});

it('3 — la prova a secco non lascia niente: righe, passaggi, saldi, quote, registro delle modifiche e documenti contati prima e dopo l\'anteprima', function () {
    $s = pvScenario();
    pvEmetti($s);
    $prima = pvConteggi();
    $quote = DB::table('rate_quote')->orderBy('id')->get(['id', 'anagrafica_id', 'importo', 'stato', 'regole_calcolo'])->map(fn ($q) => (array) $q)->all();

    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]))->assertOk();
    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]))->assertOk();

    expect(pvConteggi())->toBe($prima)
        ->and(DB::table('rate_quote')->orderBy('id')->get(['id', 'anagrafica_id', 'importo', 'stato', 'regole_calcolo'])->map(fn ($q) => (array) $q)->all())->toBe($quote)
        ->and(DB::table('anagrafica_immobile')->whereIn('id', [$s['rigaC'], $s['rigaM']])->whereNull('data_fine')->count())->toBe(2);
});

it('4 — tutti o nessuno dalla rotta: la riga di Marta chiusa da «Modifica associazione» fra il pannello e il clic ferma tutto con il suo nome; e se cambia mentre si registra, niente resta e il file del titolo non resta sul disco', function () {
    Storage::fake('local');
    $s = pvScenario();
    pvEmetti($s);
    $modulo = pvModulo($s, [$s['rigaC'], $s['rigaM']]);
    $p = pvAnteprima($this, $s, $modulo)->assertOk()->json();

    // (a) Fra il pannello e il clic, dalla rotta vera: la richiesta la ferma, sulla riga di Marta.
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $s['rigaM']]), [
        'anagrafica_id' => $s['marta']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'data_inizio' => '2019-03-03', 'data_fine' => '2026-10-31', 'note' => null,
    ])->assertSessionHasNoErrors();
    $prima = pvConteggi();
    pvRegistra($this, $s, $modulo + ['allegato_titolo' => UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf')], $p)
        ->assertSessionHasErrors(['righe_uscenti.1' => 'Marta Acciaioli: questo periodo si è chiuso il 31 ottobre 2026: il passaggio si registra da un titolare in corso alla data dell\'atto.']);
    expect(pvConteggi())->toBe($prima)->and(Storage::disk('local')->allFiles())->toBe([]);

    // (b) Mentre si registra: la riga di Marta si chiude dopo il primo passaggio (un'altra scheda, sotto il lock su MySQL). Il secondo si ferma, niente resta.
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['data_fine' => null]);
    $prima = pvConteggi();
    $rigaM = $s['rigaM'];
    $fatto = false;
    Subentro::created(function (Subentro $nuovo) use ($rigaM, &$fatto) {
        if (! $fatto && $nuovo->insieme_id === null) {
            $fatto = true;
            DB::table('anagrafica_immobile')->where('id', $rigaM)->update(['data_fine' => '2026-10-31']);
        }
    });
    pvRegistra($this, $s, $modulo + ['allegato_titolo' => UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf')], $p)
        ->assertSessionHasErrors(['decorrenza' => 'La parte di Marta Acciaioli: questo periodo si è chiuso il 31 ottobre 2026: la decorrenza non può essere successiva di più di un giorno.']);
    expect($fatto)->toBeTrue();
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['data_fine' => null]);
    expect(pvConteggi())->toBe($prima)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('5 — l\'impronta: una rata emessa fra il pannello e il clic ferma tutto con «Mentre registravi è cambiato qualcosa», e niente resta', function () {
    $s = pvScenario();
    pvEmetti($s);
    $modulo = pvModulo($s, [$s['rigaC'], $s['rigaM']]);
    $p = pvAnteprima($this, $s, $modulo)->assertOk()->json();
    pvEmetti($s, '2026-12-31');
    $prima = pvConteggi();

    pvRegistra($this, $s, $modulo, $p)
        ->assertSessionHasErrors(['passaggio' => 'Mentre registravi è cambiato qualcosa (la parte di Cecilia Angeli): il pannello è stato ricalcolato, ricontrolla e conferma.']);
    expect(pvConteggi())->toBe($prima);

    // Il pannello ricalcolato si registra.
    pvRegistra($this, $s, $modulo)->assertSessionHasNoErrors();
    expect(pvPassaggi($s))->toHaveCount(2);
});

it('6 — le regole: ruoli diversi, chi entra fra chi esce, l\'elenco in un altro tipo; con una riga sola è il passaggio di oggi, senza insieme_id', function () {
    $s = pvScenario(conPiano: false);
    // Ruoli diversi: Marta ha la nuda proprietà (e Tina l'usufrutto della sua metà).
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['tipologia' => 'nuda_proprietario']);
    $tina = Anagrafica::forceCreate(['nome' => 'Tina Angeli', 'email' => 'pv-tina6@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PVTINAPERSONA0006']);
    $tina->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $tina->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 50, 'attivo' => true,
        'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]))->assertUnprocessable()
        ->assertJsonPath('errors.righe_uscenti.0', 'Chi esce insieme deve avere lo stesso ruolo: Cecilia Angeli ha la proprietà piena, Marta Acciaioli la nuda proprietà. Registra due passaggi, uno per persona.');
    DB::table('anagrafica_immobile')->where('id', $s['rigaM'])->update(['tipologia' => 'proprietario']);
    DB::table('anagrafica_immobile')->where('anagrafica_id', $tina->id)->delete();

    // Chi entra è una di chi esce.
    $r = pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']], $s['marta']))->assertUnprocessable();
    expect($r->json('errors.anagrafica_entrante_id'))->toContain('Marta Acciaioli: chi entra non può essere la stessa persona che esce.');

    // L'elenco in un altro tipo, e una riga sola nell'elenco.
    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario']))
        ->assertUnprocessable()->assertJsonPath('errors.righe_uscenti.0', 'Più persone che escono insieme si registrano nella vendita o donazione: negli altri passaggi si sceglie una persona sola.');
    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC']]))->assertUnprocessable()
        ->assertJsonPath('errors.righe_uscenti.0', 'Con una persona sola che esce si registra il passaggio di oggi: scegli la riga in «Chi esce».');

    // Una riga sola con `riga_uscente_id`: il passaggio di oggi, nessun insieme, la frase di oggi nel registro.
    $oggi = ['riga_uscente_id' => $s['rigaC'], 'quota' => 50] + array_diff_key(pvModulo($s, [$s['rigaC']]), ['righe_uscenti' => true]);
    $ante = pvAnteprima($this, $s, $oggi)->assertOk()->json();
    expect($ante)->not->toHaveKey('insieme');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $oggi + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, una vendita sola'])
        ->assertSessionHasNoErrors();
    expect(pvPassaggi($s))->toHaveCount(1)
        ->and(pvPassaggi($s)[0]->insieme_id)->toBeNull()
        ->and(session('passaggio_registrato')['insieme'])->toBeNull()
        ->and(DB::table('registro_modifiche')->orderByDesc('id')->value('riepilogo'))->toStartWith('Passaggio registrato: Int. 1 da Cecilia Angeli a Luca Toscani');
});

it('7 — la riserva: i due genitori donano la nuda proprietà a Luca e tengono l\'usufrutto; la voce si sposta una volta, il registro lo dice nel primo, nessun errore d\'impronta', function () {
    $s = pvScenario(conPiano: false);
    $modulo = pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['sottotipo' => Subentro::RISERVA_USUFRUTTO, 'tipologia' => 'nuda_proprietario',
        'ordinaria_dopo_atto' => Subentro::ORDINARIA_ALL_USUFRUTTUARIO, 'voci_da_tenere' => []]);
    $p = pvAnteprima($this, $s, $modulo)->assertOk()->json();
    expect($p['riferimento']['frase'])->toBe('Cecilia Angeli e Marta Acciaioli risulteranno titolari della proprietà piena fino al 15 novembre 2026 compreso e dell\'usufrutto dal 16 novembre 2026. Luca Toscani nudo proprietario dal 16 novembre 2026.')
        ->and($p['ordinaria']['applicabile'])->toBeTrue()
        ->and(collect($p['ordinaria']['voci'])->where('spostata', true))->toHaveCount(1)
        // Il secondo pannello non ha più voci da spostare: le ha spostate il primo.
        ->and(collect($p['parti'][1]['anteprima']['ordinaria']['voci'] ?? [])->where('spostata', true))->toHaveCount(0);

    pvRegistra($this, $s, $modulo, $p)->assertSessionHasNoErrors()->assertRedirect();
    $passaggi = pvPassaggi($s);
    expect($passaggi)->toHaveCount(2)
        ->and($passaggi[0]->registro['voci_spostate'] ?? [])->toHaveCount(1)
        ->and($passaggi[1]->registro['voci_spostate'] ?? [])->toBe([])
        ->and(DB::table('conto_tabella_ripartizioni')->pluck('percentuale', 'soggetto')->map(fn ($v) => (float) $v)->all())->toBe(['usufruttuario' => 100.0])
        ->and(DB::table('anagrafica_immobile')->whereNull('data_fine')->orderBy('anagrafica_id')->get(['anagrafica_id', 'tipologia', 'quota'])
            ->map(fn ($r) => [(int) $r->anagrafica_id, $r->tipologia, (float) $r->quota])->all())
        ->toBe([[$s['cecilia']->id, 'usufruttuario', 50.0], [$s['marta']->id, 'usufruttuario', 50.0], [$s['luca']->id, 'nuda_proprietario', 100.0]]);
});

it('8 — i tre fratelli: Cecilia e Marta vendono a Tina, che passa dal 33,33 % al 100 % su una riga sola, e il pannello dice «aveva già il 33,33 %»', function () {
    $s = pvScenario(terza: true);
    pvEmetti($s);
    $modulo = pvModulo($s, [$s['rigaC'], $s['rigaM']], $s['tina']);
    expect($modulo['quota'])->toBe(66.67);
    $p = pvAnteprima($this, $s, $modulo)->assertOk()->json();
    expect($p['anagrafica']['frasi'][0])->toBe('Cecilia Angeli e Marta Acciaioli risulteranno titolari fino al 15 novembre 2026. Tina Angeli dal 16 novembre 2026, come proprietario: aveva già il 33,33 %, dopo l\'atto avrà il 100 %.');

    pvRegistra($this, $s, $modulo, $p)->assertSessionHasNoErrors();
    $tina = DB::table('anagrafica_immobile')->where('anagrafica_id', $s['tina']->id)->orderBy('id')->get();
    expect($tina->map(fn ($r) => [(float) $r->quota, substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)])->all())
        ->toBe([[33.33, '2019-03-03', '2026-11-15'], [100.0, '2026-11-16', null]]);
});

it('9 — le pertinenze: il box della sola Cecilia va con la sua parte; un box di nessuna delle due si ferma, detto per tutte', function () {
    $s = pvScenario(conPiano: false);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 1', 'descrizione' => 'Box', 'interno' => 'B1', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['cecilia']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $altro = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 2', 'descrizione' => 'Box', 'interno' => 'B2', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['luca']->id, 'immobile_id' => $altro->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['pertinenze' => [$altro->id]]))->assertUnprocessable()
        ->assertJsonPath('errors.pertinenze.0', 'Box 2: nessuna delle persone che escono (Cecilia Angeli e Marta Acciaioli) risulta proprietario alla data del passaggio. Togli la spunta, o registra il passaggio dalla pertinenza.');

    $modulo = pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['pertinenze' => [$box->id]]);
    $p = pvAnteprima($this, $s, $modulo)->assertOk()->json();
    expect(collect($p['parti'])->pluck('pertinenze')->all())->toBe([['Box 1'], []])
        ->and($p['anagrafica']['frasi'])->toContain('Il passaggio si applica anche a: Box 1, con la parte di Cecilia Angeli.');
    pvRegistra($this, $s, $modulo, $p)->assertSessionHasNoErrors();
    $passaggi = pvPassaggi($s);
    expect($passaggi[0]->pertinenze()->pluck('immobile_id')->all())->toBe([$box->id])
        ->and($passaggi[1]->pertinenze()->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $box->id)->whereNull('data_fine')->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->toBe([$s['luca']->id]);
});

it('10 — il nuovo titolare non convocato: con un\'assemblea già convocata dopo la data, Anna e Marco Russo vendono l\'Int. 5 a Verdi Sara insieme, e il compito nasce una volta', function () {
    \App\Services\RegistroModifiche\ContestoDelRegistro::azzera();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    // L'invio è la spedizione registrata: la prima raccomandata spedita fa «convocata» (come `NuovoTitolareNonConvocatoTest`).
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'gallo')->id],
        'canale' => 'raccomandata', 'data' => '2026-12-02'])->assertSessionHasNoErrors();
    $s->persona('verdi')->forceFill(['nome' => 'Verdi Sara', 'indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');

    $righe = DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['5']->id)->whereNull('data_fine')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $modulo = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'righe_uscenti' => $righe, 'anagrafica_entrante_id' => $s->persona('verdi')->id, 'decorrenza' => '2026-12-10',
        'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto della prova', 'pertinenze' => []];
    $p = $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['5']]), $modulo)->assertOk()->json();
    $this->actingAs($s->admin)->post(route('admin.gestionale.immobili.passaggi.store', [$s->c, $s->unita['5']]), $modulo + ['impronta_del_gruppo' => $p['impronta']]
        + (($p['cancello']['richiesto'] ?? false) ? ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova'] : []))->assertSessionHasNoErrors();

    expect(Subentro::where('immobile_id', $s->unita['5']->id)->whereNotNull('insieme_id')->count())->toBe(2);
    $compiti = \App\Models\Evento::query()->where('meta->type', 'nuovo_titolare_non_convocato')->where('meta->context->assemblea_id', $a->id)->get();
    expect($compiti)->toHaveCount(1)
        ->and((int) $compiti[0]->meta['context']['subentro_id'])->toBe((int) Subentro::where('immobile_id', $s->unita['5']->id)->max('id'));
    \Illuminate\Support\Carbon::setTestNow();
});

it('11 — le teste dell\'insieme: Luca ha già l\'Int. 2, e il pannello le conta una volta, «oggi 2, dopo i passaggi 1»', function () {
    $s = pvScenario(conPiano: false, lucaInt2: true);
    $p = pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']]))->assertOk()->json();

    expect($p['invarianti']['frasi'][2])->toMatch('/^Teste in assemblea, come le conta la sala con la scelta del condominio \(«(una testa|due teste)»\): oggi 2, dopo i passaggi 1\.$/')
        // Il pannello di Marta da solo, dopo quello di Cecilia, direbbe un «oggi» che non è quello di oggi: per questo si contano una volta.
        ->and($p['parti'][0]['anteprima']['invarianti']['teste'])->toMatchArray(['prima' => 2])
        ->and($p['parti'][1]['anteprima']['invarianti']['teste'])->toMatchArray(['dopo' => 1]);
});

it('la copia autentica dell\'atto è una sola: registrata dal primo passaggio dell\'insieme, vale per tutti', function () {
    $s = pvScenario(conPiano: false);
    pvRegistra($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['copia_autentica' => false, 'copia_autentica_il' => null]))->assertSessionHasNoErrors();
    $passaggi = pvPassaggi($s);
    expect($passaggi->pluck('copia_autentica_il')->filter()->all())->toBe([]);

    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], $passaggi[0]]), ['copia_autentica_il' => '2026-11-20'])
        ->assertSessionHasNoErrors();
    expect(pvPassaggi($s)->map(fn ($p) => $p->copia_autentica_il?->toDateString())->all())->toBe(['2026-11-20', '2026-11-20'])
        ->and(session('message')['message'])->toBe('Copia autentica registrata per i 2 passaggi registrati insieme: dal 20 novembre 2026 la liberazione verso il condominio per i contributi successivi vale per Cecilia Angeli e Marta Acciaioli (art. 63 co. 5 disp. att. c.c.).');
});


it('11 bis — le teste con l\'atto registrato prima del giorno in cui vale: «dopo i passaggi» conta tutti i passaggi, anche se oggi le righe nuove non sono ancora in corso', function () {
    $s = pvScenario(conPiano: false, lucaInt2: true);
    $p = pvAnteprima($this, $s, pvModulo($s, [$s['rigaC'], $s['rigaM']], extra: ['decorrenza' => '2026-12-01', 'copia_autentica' => false, 'copia_autentica_il' => null]))->assertOk()->json();

    expect($p['invarianti']['frasi'][2])->toEndWith('oggi 2, dopo i passaggi 1.');
});
