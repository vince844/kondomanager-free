<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 599, 1.12.0-beta.14: i comproprietari senza quota entravano a 100 ciascuno.
 *
 * Danea non dà mai la quota, e `LivelloTitolarita` scriveva 100 a ogni riga senza: due proprietari dello stesso interno facevano 200. Il riparto non
 * cambiava (il motore divide per la somma), ma la guardia delle quote rifiutava ogni correzione dalla scheda dell'unità: portare Ugo a 50 faceva 150.
 *
 * Ora: le persone dello stesso ruolo sulla stessa unità, senza nessuna quota nel file, entrano in parti uguali che fanno 100 (`PartiUguali`, l'ultima prende
 * il resto: 33,33 + 33,33 + 33,34); con alcune quote scritte, le righe vuote si dividono il resto fino a 100; se le quote scritte fanno già 100, l'unità si
 * salta e l'avviso dice di correggere il file. La divisione di un nome doppio usa le stesse parti. E dalla scheda una correzione che non peggiora la somma
 * passa anche se la somma resta sopra 100: si scende un passo alla volta.
 *
 * Che cosa non copre: le quote a 200 già importate, che non si riscrivono (le elenca `kondomanager:verifica-importazioni`); l'unità che il file divide in due
 * righe di persone diverse per errore (entra a 50 e 50: è il file).
 */

use App\Enums\RuoloAnagraficaImmobile as Ruolo;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\ModelloManualeWriter;
use App\Services\Import\PartiUguali;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

afterEach(function () {
    foreach (b14qTemporanei() as $percorso) {
        @unlink($percorso);
    }
});

/** I file d'esempio scritti dal caso, cancellati alla fine: con un percorso lo aggiunge, senza li restituisce e li dimentica. */
function b14qTemporanei(?string $percorso = null): array
{
    static $percorsi = [];
    if ($percorso !== null) {
        $percorsi[] = $percorso;

        return [];
    }
    [$tutti, $percorsi] = [$percorsi, []];

    return $tutti;
}

function b14qUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }
    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/**
 * Le righe di «chi possiede cosa» scritte dal livello su un'unità «Int. 3», con le persone date per nome.
 *
 * @param  list<array{0: string, 1: Ruolo, 2?: float|null}>  $righe  persona, ruolo, quota (null = il file non la dice)
 * @return array{0: Immobile, 1: \App\Services\Import\EsitoCommit}
 */
function b14qScriviTitolarita(array $righe): array
{
    $condominio = Condominio::create(['nome' => 'Via Prova 1', 'indirizzo' => 'Via Prova 1']);
    $immobile = Immobile::create(['condominio_id' => $condominio->id, 'nome' => 'Int. 3', 'descrizione' => 'Int. 3', 'interno' => '3']);
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    $persone = [];
    foreach ($righe as [$nome]) {
        $persone[mb_strtolower($nome)] ??= Anagrafica::create(['nome' => $nome, 'indirizzo' => 'Via Prova 1']);
    }

    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
    $ctx->risolvi(LivelloCondominio::CHIAVE, $condominio);
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $esercizio);
    $ctx->risolviMolti(LivelloUnita::CHIAVE, ['u3' => $immobile]);
    $ctx->risolviMolti(LivelloSoggetti::CHIAVE, $persone);
    $ctx->conCanonico(LivelloTitolarita::CHIAVE, array_map(
        fn (array $r) => new CanonicalTitolarita(immobileRef: 'u3', soggettoRef: mb_strtolower($r[0]), ruolo: $r[1], quota: $r[2] ?? null),
        $righe,
    ));

    return [$immobile, (new LivelloTitolarita)->commit($ctx)];
}

/** @return array<string, float> persona => quota, per un ruolo dell'unità */
function b14qQuote(Immobile $immobile, Ruolo $ruolo): array
{
    return DB::table('anagrafica_immobile as ai')->join('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
        ->where('ai.immobile_id', $immobile->id)->where('ai.tipologia', $ruolo->value)
        ->orderBy('ai.id')->pluck('ai.quota', 'a.nome')->map(fn ($q) => (float) $q)->all();
}

/** L'elenco unità di Danea della prova, con le celle cambiate: un file d'esempio fatto nel test, con i nomi inventati della prova. */
function b14qElencoDanea(array $celle): string
{
    $libro = IOFactory::load(base_path('tests/Fixtures/import/danea/elenco_unita.xls'));
    foreach ($celle as $cella => $valore) {
        $libro->getActiveSheet()->setCellValue($cella, $valore);
    }
    $percorso = sys_get_temp_dir().'/b14q_elenco_'.uniqid().'.xls';
    (new Xls($libro))->save($percorso);
    $libro->disconnectWorksheets();
    b14qTemporanei($percorso);

    return $percorso;
}

/** Il modello di KondoManager compilato con una sola unità «B1/2» e le persone date. */
function b14qModello(array $persone): string
{
    $percorso = sys_get_temp_dir().'/b14q_modello_'.uniqid().'.xlsx';
    (new ModelloManualeWriter)->scriviSu($percorso);
    $libro = IOFactory::load($percorso);
    $svuota = function (string $foglio, int $da) use ($libro) {
        $f = $libro->getSheetByName($foglio);
        for ($r = $da; $r <= $da + 40; $r++) {
            foreach (range('A', 'J') as $c) {
                $f->setCellValue($c.$r, null);
            }
        }

        return $f;
    };
    $copertina = $svuota('0 copertina', 6);
    $riga = 6;
    foreach (['condominio' => 'CONDOMINIO DELLA PROVA', 'codice_fiscale' => '97555000188', 'indirizzo' => 'Via del Modello 10, Roma', 'esercizio' => '2026',
        'data_inizio' => '01/01/2026', 'data_fine' => '31/12/2026'] as $campo => $valore) {
        $copertina->setCellValue('A'.$riga, $campo);
        $copertina->setCellValue('B'.$riga, $valore);
        $riga++;
    }
    $svuota('1 unita', 6)->fromArray([['B1/1', '1', 'A', '1', 'T', 'appartamento'], ['B1/2', '1', 'A', '2', '1', 'appartamento']], null, 'A6');
    $svuota('2 persone', 6)->fromArray([['B1/1', 'ROSSI MARIO', '', 'Via Roma 1, Roma', 'proprietario', 100, '', ''], ...$persone], null, 'A6');
    $svuota('3 tabelle', 5);
    $svuota('4 saldi', 6);
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();
    b14qTemporanei($percorso);

    return $percorso;
}

function b14qConfermaModello(User $utente, string $percorso): ImportBatch
{
    test()->actingAs($utente)->post(route('import.store'), ['file' => [new UploadedFile($percorso, 'modello.xlsx', null, test: true)]]);
    $batch = ImportBatch::query()->orderByDesc('id')->first();
    test()->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    return $batch->fresh();
}

/** I codici degli avvisi del rapporto, di tutti i livelli. */
function b14qAvvisi(ImportBatch $batch): array
{
    return collect($batch->rapporto['livelli'])->flatMap(fn ($l) => $l['avvisi'])->all();
}

it('le parti uguali fanno 100, e l\'ultima prende il resto', function () {
    expect(PartiUguali::di(1))->toBe([100.0])
        ->and(PartiUguali::di(2))->toBe([50.0, 50.0])
        ->and(PartiUguali::di(3))->toBe([33.33, 33.33, 33.34])
        ->and(PartiUguali::di(6))->toBe([16.66, 16.66, 16.66, 16.66, 16.66, 16.7])
        ->and(array_sum(PartiUguali::di(7)))->toEqualWithDelta(100.0, 0.0001)
        ->and(PartiUguali::di(2, 40.0))->toBe([20.0, 20.0])
        ->and(PartiUguali::di(3, 40.0))->toBe([13.33, 13.33, 13.34]);
});

it('due proprietari senza quota entrano a 50 e 50, non a 100 e 100', function () {
    [$immobile, $esito] = b14qScriviTitolarita([['UGO', Ruolo::PROPRIETARIO], ['ELSA', Ruolo::PROPRIETARIO]]);

    expect($esito->riuscito())->toBeTrue()
        ->and(b14qQuote($immobile, Ruolo::PROPRIETARIO))->toBe(['UGO' => 50.0, 'ELSA' => 50.0]);
});

it('tre proprietari senza quota entrano a 33,33, 33,33 e 33,34', function () {
    [$immobile] = b14qScriviTitolarita([['UGO', Ruolo::PROPRIETARIO], ['ELSA', Ruolo::PROPRIETARIO], ['LIA', Ruolo::PROPRIETARIO]]);

    expect(b14qQuote($immobile, Ruolo::PROPRIETARIO))->toBe(['UGO' => 33.33, 'ELSA' => 33.33, 'LIA' => 33.34]);
});

it('ruoli diversi non si dividono: il proprietario e l\'inquilino senza quota restano a 100 ciascuno', function () {
    [$immobile] = b14qScriviTitolarita([['UGO', Ruolo::PROPRIETARIO], ['ELSA', Ruolo::INQUILINO]]);

    expect(b14qQuote($immobile, Ruolo::PROPRIETARIO))->toBe(['UGO' => 100.0])
        ->and(b14qQuote($immobile, Ruolo::INQUILINO))->toBe(['ELSA' => 100.0]);
});

it('la stessa persona scritta due volte nello stesso ruolo conta una volta sola', function () {
    [$immobile] = b14qScriviTitolarita([['UGO', Ruolo::PROPRIETARIO], ['ELSA', Ruolo::PROPRIETARIO], ['UGO', Ruolo::PROPRIETARIO]]);

    expect(b14qQuote($immobile, Ruolo::PROPRIETARIO))->toBe(['UGO' => 50.0, 'ELSA' => 50.0]);
});

it('il modello con 60 e una riga senza quota: la riga vuota prende il resto, 40, e l\'avviso delle quote che non fanno 100 resta', function () {
    $batch = b14qConfermaModello(b14qUtente(), b14qModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', '', '', ''],
    ]));

    $unita = Immobile::query()->where('interno', '2')->firstOrFail();
    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(b14qQuote($unita, Ruolo::PROPRIETARIO))->toBe(['BIANCHI ANNA' => 60.0, 'VERDI LUCA' => 40.0])
        ->and(collect(b14qAvvisi($batch))->pluck('codice')->all())->toContain('modello.quote_non_fanno_cento');
});

it('il modello con le quote scritte che fanno 100 resta com\'è', function () {
    $batch = b14qConfermaModello(b14qUtente(), b14qModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 40, '', ''],
    ]));

    $unita = Immobile::query()->where('interno', '2')->firstOrFail();
    expect(b14qQuote($unita, Ruolo::PROPRIETARIO))->toBe(['BIANCHI ANNA' => 60.0, 'VERDI LUCA' => 40.0])
        ->and(collect(b14qAvvisi($batch))->pluck('codice')->all())->not->toContain('titolarita.quote_senza_resto');
});

/*
|--------------------------------------------------------------------------
| Le frasi dell'importazione che parlano delle quote dicono il vero
|--------------------------------------------------------------------------
*/

/** Gli avvisi che il parser del modello scrive per un'unità «B1/2» con le persone date, letti dal file senza passare dal database. */
function b14qAvvisiDelModello(array $persone, string $codice): array
{
    $letto = (new \App\Services\Import\Parser\ModelloManualeParser)->estrai((new \App\Services\Import\SpreadsheetReader)->leggi(b14qModello($persone)));

    return collect($letto['esiti'])->flatMap(fn ($esito) => $esito->rilievi)->filter(fn ($r) => $r->codice === $codice)->values()->all();
}

it('il modello con 60 e una riga senza quota: l\'avviso delle quote che non fanno 100 dice che la riga vuota prende il resto', function () {
    $avvisi = b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', '', '', ''],
    ], 'modello.quote_non_fanno_cento');

    expect($avvisi)->toHaveCount(1)
        ->and($avvisi[0]->rimedio)->toBe('Le righe senza quota prendono il resto fino a 100, in parti uguali fra le persone dello stesso ruolo; se le '
            .'quote scritte di quel ruolo fanno già 100, le sue righe non entrano. Se le quote vere sono diverse, scrivile nella colonna «quota_pct».')
        ->and($avvisi[0]->rimedio)->not->toContain('come sono');
});

it('il modello con tutte le quote scritte che fanno 90: l\'avviso dice ancora che le importa come sono', function () {
    $avvisi = b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 30, '', ''],
    ], 'modello.quote_non_fanno_cento');

    expect($avvisi)->toHaveCount(1)
        ->and($avvisi[0]->rimedio)->toStartWith('Le importo come sono:');
});

it('il modello con un proprietario al 100 e un inquilino al 100: nessun avviso, le quote si sommano per ruolo', function () {
    $avvisi = b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 100, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'inquilino', 100, '', ''],
    ], 'modello.quote_non_fanno_cento');

    expect($avvisi)->toBe([]);
});

it('il modello con i proprietari a 150 e l\'inquilino al 100: un avviso solo, che nomina il ruolo', function () {
    $avvisi = b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 100, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 50, '', ''],
        ['B1/2', 'NERI PAOLA', '', 'Via Roma 2, Roma', 'inquilino', 100, '', ''],
    ], 'modello.quote_non_fanno_cento');

    expect($avvisi)->toHaveCount(1)
        ->and($avvisi[0]->messaggio)->toMatch('/^Le quote dei proprietari dell\'unità «.+» sommano a 150 invece che a 100\.$/u')
        ->and($avvisi[0]->rimedio)->toStartWith('Le importo come sono:');
});

it('il modello con le nude proprietà a 50 e gli usufruttuari a 100: l\'avviso è sui nudi proprietari, e la riga senza quota di un altro ruolo non cambia il rimedio', function () {
    $avvisi = b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'nudo proprietario', 50, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'usufruttuario', 100, '', ''],
        ['B1/2', 'NERI PAOLA', '', 'Via Roma 2, Roma', 'inquilino', '', '', ''],
    ], 'modello.quote_non_fanno_cento');

    expect($avvisi)->toHaveCount(1)
        ->and($avvisi[0]->messaggio)->toContain('Le quote dei nudi proprietari dell\'unità')
        ->and($avvisi[0]->messaggio)->toContain('sommano a 50 invece che a 100')
        ->and($avvisi[0]->rimedio)->toStartWith('Le importo come sono:');
});

it('il modello con gli inquilini a 80 e gli usufruttuari a 60: un avviso per ruolo', function () {
    $messaggi = collect(b14qAvvisiDelModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'inquilino', 40, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'inquilino', 40, '', ''],
        ['B1/2', 'NERI PAOLA', '', 'Via Roma 2, Roma', 'usufruttuario', 60, '', ''],
    ], 'modello.quote_non_fanno_cento'))->pluck('messaggio')->all();

    expect($messaggi)->toHaveCount(2)
        ->and($messaggi[0])->toStartWith('Le quote degli inquilini dell\'unità')
        ->and($messaggi[1])->toStartWith('Le quote degli usufruttuari dell\'unità');
});

it('l\'avviso delle quote scritte nelle note di Danea non promette più il 100 % al primo intestatario', function () {
    $fogli = (new \App\Services\Import\SpreadsheetReader)->leggi(base_path('tests/Fixtures/import/danea/elenco_unita.xls'));
    $riconosciuto = (new \App\Services\Import\ReportRecognizer)->riconosci($fogli[0]);
    $letto = (new \App\Services\Import\Parser\ElencoUnitaParser)->estrai($fogli[0], $riconosciuto->rigaIntestazione);
    $avviso = collect($letto['esito']->rilievi)->firstWhere('codice', 'titolarita.quota_solo_nelle_note');

    expect($avviso)->not->toBeNull()
        ->and($avviso->rimedio)->toBe('Il tracciato di Danea non ha un campo per le quote di comproprietà: se l\'unità ha più persone nello stesso '
            .'ruolo le importo in parti uguali, se ne ha una sola la importo al 100%. Correggi le quote dopo, sulla scheda dell\'unità.');
});

it('il modello con 100 e una riga senza quota: l\'unità si salta, e l\'avviso dice di correggere il file', function () {
    $batch = b14qConfermaModello(b14qUtente(), b14qModello([
        ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 100, '', ''],
        ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', '', '', ''],
    ]));

    $unita = Immobile::query()->where('interno', '2')->firstOrFail();
    $altra = Immobile::query()->where('interno', '1')->firstOrFail();
    $avviso = collect(b14qAvvisi($batch))->firstWhere('codice', 'titolarita.quote_senza_resto');

    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(b14qQuote($unita, Ruolo::PROPRIETARIO))->toBe([])
        ->and(b14qQuote($altra, Ruolo::PROPRIETARIO))->toBe(['ROSSI MARIO' => 100.0])
        ->and($avviso)->not->toBeNull()
        ->and($avviso['messaggio'])->toContain('B1/2')
        ->and($avviso['messaggio'])->toContain('(proprietario)')
        ->and($avviso['rimedio'])->toContain('nel file')
        ->and(collect($batch->rapporto['livelli'])->firstWhere('livello', 'titolarita')['saltati'])->toBe(2);
});

it('la catena dai file di Danea: due proprietari dello stesso interno a 50 e 50, il nome triplo diviso a 33,33, 33,33 e 33,34, nessuna unità sopra 100', function () {
    $utente = b14qUtente();
    // Nell'interno 3 la seconda persona diventa proprietaria; nell'interno 7 il nome doppio diventa triplo.
    $files = [new UploadedFile(b14qElencoDanea(['H4' => 'Pr', 'J7' => 'RUSSO LUCA / COSTA SILVIA / ESPOSITO MARCO']), 'elenco_unita.xls', 'application/vnd.ms-excel', test: true)];
    foreach (['riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls'] as $n) {
        $files[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$n), $n, 'application/vnd.ms-excel', test: true);
    }
    $this->actingAs($utente)->post(route('import.store'), ['file' => $files]);
    $batch = ImportBatch::query()->orderByDesc('id')->first();

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'], 'scelta' => str_contains($d['chiave'], 'esposito') ? 'dividi' : 'lascia',
        ]);
    }
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(b14qQuote(Immobile::query()->where('interno', '3')->sole(), Ruolo::PROPRIETARIO))->toBe(['BIANCHI PAOLO' => 50.0, 'VERDI ANNA' => 50.0])
        ->and(b14qQuote(Immobile::query()->where('interno', '7')->sole(), Ruolo::PROPRIETARIO))
            ->toBe(['RUSSO LUCA' => 33.33, 'COSTA SILVIA' => 33.33, 'ESPOSITO MARCO' => 33.34])
        ->and(DB::table('anagrafica_immobile')->selectRaw('immobile_id, tipologia, sum(quota) as somma')->groupBy('immobile_id', 'tipologia')
            ->havingRaw('sum(quota) > 100.001')->count())->toBe(0);

    // E dalla scheda dell'interno 3 le quote si correggono come quelle vere: Verdi a 40, poi Bianchi a 60; Bianchi a 70 no.
    $admin = b14qAmministratore();
    $interno3 = Immobile::query()->where('interno', '3')->sole();
    $riga = fn (string $nome) => (int) DB::table('anagrafica_immobile as ai')->join('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
        ->where('ai.immobile_id', $interno3->id)->where('a.nome', $nome)->value('ai.id');
    $persona = fn (string $nome) => Anagrafica::query()->where('nome', $nome)->sole();
    $dal = (string) DB::table('anagrafica_immobile')->where('id', $riga('VERDI ANNA'))->value('data_inizio');
    $condominio = Condominio::query()->sole();

    b14qModifica($admin, $condominio, $interno3, $riga('VERDI ANNA'), $persona('VERDI ANNA'), 40, ['data_inizio' => $dal])->assertSessionHasNoErrors();
    b14qModifica($admin, $condominio, $interno3, $riga('BIANCHI PAOLO'), $persona('BIANCHI PAOLO'), 70, ['data_inizio' => $dal])->assertSessionHasErrors(['quota']);
    b14qModifica($admin, $condominio, $interno3, $riga('BIANCHI PAOLO'), $persona('BIANCHI PAOLO'), 60, ['data_inizio' => $dal])->assertSessionHasNoErrors();
    expect(b14qQuote($interno3, Ruolo::PROPRIETARIO))->toBe(['BIANCHI PAOLO' => 60.0, 'VERDI ANNA' => 40.0]);
});

/*
|--------------------------------------------------------------------------
| La scheda dell'unità: una correzione che non peggiora passa
|--------------------------------------------------------------------------
*/

function b14qAmministratore(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    return $utente;
}

function b14qScheda(): array
{
    $utente = b14qAmministratore();

    $condominio = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $immobile = Immobile::forceCreate(['condominio_id' => $condominio->id, 'nome' => 'Interno 3', 'descrizione' => 'Appartamento', 'interno' => '3']);

    return [$utente, $condominio, $immobile];
}

function b14qPersona(Condominio $condominio, string $nome): Anagrafica
{
    $a = Anagrafica::forceCreate(['nome' => $nome, 'indirizzo' => 'Via Prova 1']);
    $a->condomini()->syncWithoutDetaching([$condominio->id]);

    return $a;
}

function b14qRiga(Immobile $immobile, Anagrafica $persona, float $quota, string $dal = '2026-01-01', ?string $al = null, string $ruolo = 'proprietario'): int
{
    return (int) DB::table('anagrafica_immobile')->insertGetId([
        'immobile_id' => $immobile->id, 'anagrafica_id' => $persona->id, 'tipologia' => $ruolo, 'quota' => $quota,
        'data_inizio' => $dal, 'data_fine' => $al, 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function b14qModifica(User $utente, Condominio $condominio, Immobile $immobile, int $riga, Anagrafica $persona, float $quota, array $altro = [])
{
    return test()->actingAs($utente)->put(route('admin.gestionale.immobili.anagrafiche.update', [$condominio, $immobile, $riga]), array_merge([
        'anagrafica_id' => $persona->id, 'tipologia' => 'proprietario', 'quota' => $quota, 'data_inizio' => '2026-01-01', 'data_fine' => null,
    ], $altro));
}

it('dalla scheda un\'unità a 100 + 100 si corregge un passo alla volta: Ugo a 50 passa, Elsa a 50 passa, Ugo a 80 no', function () {
    [$utente, $condominio, $immobile] = b14qScheda();
    $ugo = b14qPersona($condominio, 'Ugo Bianchi');
    $elsa = b14qPersona($condominio, 'Elsa Verdi');
    $rigaUgo = b14qRiga($immobile, $ugo, 100);
    $rigaElsa = b14qRiga($immobile, $elsa, 100);

    // 150 non peggiora 200.
    b14qModifica($utente, $condominio, $immobile, $rigaUgo, $ugo, 50)->assertSessionHasNoErrors()->assertRedirect();
    expect((float) DB::table('anagrafica_immobile')->where('id', $rigaUgo)->value('quota'))->toBe(50.0);

    // 100 non peggiora 150.
    b14qModifica($utente, $condominio, $immobile, $rigaElsa, $elsa, 50)->assertSessionHasNoErrors()->assertRedirect();
    expect((float) DB::table('anagrafica_immobile')->where('id', $rigaElsa)->value('quota'))->toBe(50.0);

    // 130 peggiora 100: la guardia di sempre.
    b14qModifica($utente, $condominio, $immobile, $rigaUgo, $ugo, 80)->assertSessionHasErrors(['quota']);
    expect((float) DB::table('anagrafica_immobile')->where('id', $rigaUgo)->value('quota'))->toBe(50.0);
});

it('dalla scheda, su un\'unità a 200, si corregge una nota senza toccare le quote; alzare una quota resta vietato', function () {
    [$utente, $condominio, $immobile] = b14qScheda();
    $ugo = b14qPersona($condominio, 'Ugo Bianchi');
    $elsa = b14qPersona($condominio, 'Elsa Verdi');
    $rigaUgo = b14qRiga($immobile, $ugo, 100);
    b14qRiga($immobile, $elsa, 100);

    b14qModifica($utente, $condominio, $immobile, $rigaUgo, $ugo, 100, ['note' => 'comproprietà con la moglie'])->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaUgo)->value('note'))->toBe('comproprietà con la moglie');
});

it('una correzione che sposta la quota in un periodo dove prima la somma era più bassa si rifiuta', function () {
    // Ugo era proprietario fino al 30/06 e Elsa lo è da sempre: dal 01/07 la somma era 100. Portare Ugo a 50 senza data di fine fa 150 dal 01/07:
    // peggiora quel giorno, anche se il 01/01 (150 contro 200) migliora.
    [$utente, $condominio, $immobile] = b14qScheda();
    $ugo = b14qPersona($condominio, 'Ugo Bianchi');
    $elsa = b14qPersona($condominio, 'Elsa Verdi');
    $rigaUgo = b14qRiga($immobile, $ugo, 100, '2026-01-01', '2026-06-30');
    b14qRiga($immobile, $elsa, 100);

    b14qModifica($utente, $condominio, $immobile, $rigaUgo, $ugo, 50)->assertSessionHasErrors(['quota']);
    expect(DB::table('anagrafica_immobile')->where('id', $rigaUgo)->value('data_fine'))->not->toBeNull();
});

it('«Associa soggetto» resta come prima: su un\'unità a 200 una persona in più si rifiuta', function () {
    [$utente, $condominio, $immobile] = b14qScheda();
    $ugo = b14qPersona($condominio, 'Ugo Bianchi');
    $elsa = b14qPersona($condominio, 'Elsa Verdi');
    $lia = b14qPersona($condominio, 'Lia Neri');
    b14qRiga($immobile, $ugo, 100);
    b14qRiga($immobile, $elsa, 100);

    $this->actingAs($utente)->post(route('admin.gestionale.immobili.anagrafiche.store', [$condominio, $immobile]), [
        'anagrafica_id' => $lia->id, 'condominio_id' => $condominio->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 0,
        'data_inizio' => '2026-01-01',
    ])->assertSessionHasErrors(['quota']);
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $lia->id)->exists())->toBeFalse();
});
