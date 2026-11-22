<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\RestoreStatus;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\Backup\Support\StepBudget;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\Campo;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\LetturaDelRegistro;
use App\Services\RegistroModifiche\OscuramentoDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\VerificaDelRegistro;
use App\Services\Restore\DatabaseImporter;
use App\Services\Restore\RegistroNelRipristino;
use App\Services\Restore\RestoreManager;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Services\System\SystemFinalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeBackupDumper;

/**
 * 1.12.0-beta.13 (Coda 679, §12 del progetto con P1, P2, P14, P15, P16, decisione 546): il ripristino di una copia non toglie le righe del registro delle
 * modifiche. Prima del `wipe()` le righe vanno in un file NDJSON accanto alle copie; dopo `finalize()` una fase a sé, a blocchi, rimette quelle venute dopo
 * la copia con la loro sequenza e le loro impronte (con i loro condomini e soggetti), riporta gli oscuramenti, ricostruisce la catena e scrive la riga
 * «Ripristinata la copia del …» con l'autore copiato all'avvio. Senza una riga in comune le righe vanno in un PDF fra i documenti. Un errore della fase non
 * ferma il ripristino. Il file si toglie alla fine e da «Annulla». E il backup sigilla il registro prima del dump.
 *
 * **L'importatore è sostituito** (come `RestoreHttpTest.php` fa con il finalizzatore): l'importatore vero è solo MySQL (`DatabaseImporterRoundTripTest`).
 * Quello di prova toglie e rimette le sole quattro tabelle del registro, con la fotografia presa «al momento della copia»; il resto del database non cambia.
 * Che cosa non copre quindi: il dump vero di MySQL, le colonne JSON di MySQL rilette dopo l'import (l'impronta canonica le regge: l'ha provato una prova
 * a mano su MySQL), un ripristino di più ore con il budget vero dei passi, la tabella che manca prima del wipe (il ripristino dal primo
 * avvio: il passo si salta, una riga di codice), un PDF di decine di migliaia di righe.
 */

/** L'importatore di prova: la copia è una fotografia delle quattro tabelle del registro. */
class RdrImportatoreDellaCopia extends DatabaseImporter
{
    /** @var array<string, list<array<string, mixed>>> */
    public static array $copia = [];

    public const TABELLE = ['registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche', 'registro_modifiche_catena'];

    public function wipe(): void
    {
        foreach (self::TABELLE as $tabella) {
            DB::table($tabella)->delete();
        }
    }

    public function importStep(string $dumpPath, array $checkpoint, StepBudget $budget): array
    {
        foreach (self::$copia as $tabella => $righe) {
            foreach (array_chunk($righe, 200) as $pezzo) {
                DB::table($tabella)->insert($pezzo);
            }
        }

        return ['done' => true, 'checkpoint' => ['offset' => 1, 'delimiter' => ';']];
    }
}

/** La fotografia delle tabelle del registro, come la porterebbe il dump. @return array<string, list<array<string, mixed>>> */
function rdrFotografia(): array
{
    $foto = [];
    foreach (RdrImportatoreDellaCopia::TABELLE as $tabella) {
        $foto[$tabella] = DB::table($tabella)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $foto;
}

function rdrCartella(): string
{
    return storage_path('framework/testing/ripristino-registro-' . (ParallelTesting::token() ?: 'serial'));
}

/** L'archivio di una copia «solo database» del giorno dato: il manifesto e un dump che l'importatore di prova non legge. */
function rdrArchivio(string $creataIl): string
{
    $percorso = rdrCartella() . '/backups/copia.zip';
    $dump = "-- dump di prova\n";
    $zip = new ZipArchive();
    $zip->open($percorso, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
        'created_at' => $creataIl, 'app' => ['version' => config('app.version')], 'database' => ['dump_sha256' => hash('sha256', $dump)]]));
    $zip->addFromString('db/database.sql', $dump);
    $zip->close();

    return $percorso;
}

/** Il ripristino guidato come lo guida la pagina dei backup, passo dopo passo, fino alla fine. */
function rdrRipristina(User $chi, string $archivio): array
{
    $manager = app(RestoreManager::class);
    $manager->start($archivio, null, ['safety_backup' => false, 'adopt_app_key' => false], $chi);
    $passi = 0;
    do {
        $stato = $manager->runStep();
        $passi++;
    } while (RestoreStatus::from($stato['phase'])->isRunning() && $passi < 50);

    return $stato;
}

function rdrRiga(string $nome, array $sopra = []): RegistroModifica
{
    return RegistroModifiche::scrivi(array_replace(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => 900, 'oggetto_nome' => $nome,
        'azione' => 'modificata', 'campi' => [Campo::nuovo('telefono', 'Telefono', '333 000 0000', '333 111 1111', '333 000 0000', '333 111 1111')]], $sopra));
}

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    File::deleteDirectory(rdrCartella());
    File::ensureDirectoryExists(rdrCartella() . '/backups');
    config([
        'filesystems.disks.backups.root' => rdrCartella() . '/backups',
        'backup.disk' => 'backups',
        'backup.tmp_path' => rdrCartella() . '/backups/tmp',
        'backup.restore.state_file' => rdrCartella() . '/stato.json',
        'backup.restore.lock_file' => rdrCartella() . '/lock',
        'backup.restore.marker_file' => rdrCartella() . '/marker',
    ]);
    Storage::fake('local');
    app()->instance(DatabaseImporter::class, new RdrImportatoreDellaCopia());
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());
    $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
});

afterEach(function () {
    app(RestoreMode::class)->exit();
    app(RestoreState::class)->clear();
    File::deleteDirectory(rdrCartella());
});

it('le righe venute dopo la copia restano, con la loro sequenza, le impronte, i condomini e i soggetti; la catena è integra e la riga del ripristino le annulla', function () {
    $ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it']);
    rdrRiga('Anagrafica di Mario Rossi');
    $copia = rdrFotografia();
    $ultimaDellaCopia = (int) DB::table('registro_modifiche')->max('sequenza');

    $this->travelTo(Carbon::parse('2026-12-12 10:00:00', 'Europe/Rome'));
    $dopo1 = rdrRiga('Anagrafica di Ida Romano', ['oggetto_id' => $ida->id, 'condomini' => [$this->roma->id => 'Via Roma 12'], 'soggetti' => [['anagrafica', $ida->id]]]);
    $dopo2 = rdrRiga('Anagrafica di Mario Rossi');
    $primaDelRipristino = DB::table('registro_modifiche')->orderBy('id')->get()->keyBy('id');
    $condominiPrima = DB::table('registro_modifiche_condomini')->where('riga_id', $dopo1->id)->get()->map(fn ($r) => (array) $r)->all();
    RdrImportatoreDellaCopia::$copia = $copia;

    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-12-10T09:00:00+01:00'));

    expect($stato['phase'])->toBe('completed');
    foreach ([$dopo1->id, $dopo2->id] as $id) {
        $riga = DB::table('registro_modifiche')->where('id', $id)->first();
        expect((int) $riga->sequenza)->toBe((int) $primaDelRipristino[$id]->sequenza)
            ->and($riga->impronta)->toBe($primaDelRipristino[$id]->impronta)
            ->and($riga->impronta_precedente)->toBe($primaDelRipristino[$id]->impronta_precedente);
    }
    expect(DB::table('registro_modifiche_condomini')->where('riga_id', $dopo1->id)->get()->map(fn ($r) => (array) $r)->all())->toBe($condominiPrima)
        ->and(DB::table('registro_modifiche_soggetti')->where('riga_id', $dopo1->id)->pluck('soggetto_id')->all())->toBe([$ida->id]);

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    expect($ripristino->autore_nome)->toBe('Andrea Costa')
        ->and($ripristino->autore_ruolo)->toBe('amministratore')
        ->and($ripristino->origine)->toBe('ripristino')
        ->and($ripristino->oggetto_nome)->toBe('Copia del 10/12/2026')
        ->and($ripristino->riepilogo)->toBe('Ripristinata la copia del 10/12/2026. Le 2 righe venute dopo la copia restano nel registro, annullate da questo ripristino.')
        ->and($ripristino->lotto)->toMatchArray(['sequenza_comune' => $ultimaDellaCopia, 'prima_sequenza_rimessa' => $ultimaDellaCopia + 1,
            'ultima_sequenza_rimessa' => $ultimaDellaCopia + 2, 'righe_rimesse' => 2, 'copia_del' => '2026-12-10', 'oscuramenti_riapplicati' => 0])
        ->and($ripristino->sequenza)->toBe($ultimaDellaCopia + 3);

    $annullamento = LetturaDelRegistro::annullamento();
    expect(LetturaDelRegistro::perLaPagina(RegistroModifica::query()->findOrFail($dopo1->id), $annullamento)['annullata_dal_ripristino_del'])->toBe('15/12/2026')
        ->and(LetturaDelRegistro::eAnnullata(RegistroModifica::query()->where('sequenza', $ultimaDellaCopia)->sole(), $annullamento))->toBeFalse()
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue()
        ->and((int) DB::table('registro_modifiche_catena')->value('ultima_sequenza'))->toBe($ultimaDellaCopia + 3)
        ->and(glob(rdrCartella() . '/backups/.registro-modifiche-*'))->toBe([])
        ->and($stato['outcome']['registro_modifiche'] ?? null)->toBe(['righe_rimesse' => 2, 'oscuramenti_riapplicati' => 0]);
});

it('gli oscuramenti non si annullano: una riga della copia oscurata dopo torna oscurata, e la riga del ripristino lo conta', function () {
    $this->actingAs($this->andrea);
    $ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it']);
    auth()->logout();
    $creata = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('oggetto_id', $ida->id)->sole();
    $copia = rdrFotografia();

    $this->travelTo(Carbon::parse('2027-03-12 10:00:00', 'Europe/Rome'));
    OscuramentoDelRegistro::oscura('anagrafica', $ida->id, 'Richiesta scritta del 10/03/2027');
    $oscurata = DB::table('registro_modifiche')->where('id', $creata->id)->first();
    RdrImportatoreDellaCopia::$copia = $copia;

    $this->travelTo(Carbon::parse('2027-03-15 11:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-12-10T09:00:00+01:00'));

    $riga = DB::table('registro_modifiche')->where('id', $creata->id)->first();
    expect($stato['phase'])->toBe('completed')
        ->and(json_decode($riga->campi, true))->toBe(json_decode($oscurata->campi, true))
        ->and($riga->oscurata_da)->toBe($oscurata->oscurata_da)
        ->and(json_encode(json_decode($riga->campi, true)))->not->toContain('ida.romano@libero.it')
        ->and(RegistroModifica::query()->where('azione', 'ripristino_copia')->sole()->lotto['oscuramenti_riapplicati'])->toBe(1)
        ->and(RegistroModifica::query()->where('azione', 'ripristino_copia')->sole()->riepilogo)->toContain('1 oscuramento riapplicato.')
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('una riga della copia rimasta senza sequenza che nel file è sigillata prende la versione sigillata (P1)', function () {
    $a = rdrRiga('Anagrafica di Mario Rossi');
    $b = rdrRiga('Anagrafica di Luca Bianchi');
    $copia = rdrFotografia();
    // Nel dump la seconda riga non era ancora sigillata (scritta durante il dump): la copia la porta senza sequenza e con la catena alla prima.
    foreach ($copia['registro_modifiche'] as $i => $r) {
        if ((int) $r['id'] === $b->id) {
            $copia['registro_modifiche'][$i] = array_replace($r, ['sequenza' => null, 'impronta' => null, 'impronta_precedente' => null]);
        }
    }
    $copia['registro_modifiche_catena'][0] = array_replace($copia['registro_modifiche_catena'][0],
        ['ultima_sequenza' => $a->refresh()->sequenza, 'ultima_impronta' => $a->impronta]);
    $dopo = rdrRiga('Anagrafica di Ida Romano');
    RdrImportatoreDellaCopia::$copia = $copia;

    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-12-10T09:00:00+01:00'));

    expect($stato['phase'])->toBe('completed')
        ->and(DB::table('registro_modifiche')->where('id', $b->id)->value('sequenza'))->toBe($b->refresh()->sequenza)
        ->and(RegistroModifica::query()->where('azione', 'ripristino_copia')->sole()->lotto['sequenza_comune'])->toBe($b->sequenza)
        ->and(DB::table('registro_modifiche')->where('id', $dopo->id)->exists())->toBeTrue()
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

/**
 * Una copia di un'altra storia: una riga del registro con un numero che nel registro di adesso è un'altra riga. Il registro di adesso si riscrive da capo
 * (come dopo un'installazione nuova), così le sue righe non hanno niente in comune con quella della copia.
 */
function rdrCopiaDiUnAltraStoria(): array
{
    rdrRiga('Anagrafica di Chi c\'era nell\'altra installazione');
    $copia = rdrFotografia();
    foreach (['registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche'] as $t) {
        DB::table($t)->delete();
    }
    DB::table('registro_modifiche_catena')->where('id', 1)->update(['ultima_sequenza' => 0, 'ultima_impronta' => null]);

    return $copia;
}

it('una copia senza righe del registro (di prima del registro): le righe di prima tornano tutte, annullate dal ripristino, con la catena intera (risposta 6)', function () {
    $copia = rdrFotografia();
    foreach (['registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche'] as $t) {
        $copia[$t] = [];
    }
    $copia['registro_modifiche_catena'] = [['id' => 1, 'ultima_sequenza' => 0, 'ultima_impronta' => null, 'verificata_il' => null, 'verifica_integro' => null,
        'verifica_righe' => null, 'verifica_dal' => null, 'verifica_riga_id' => null, 'verifica_righe_che_non_tornano' => null]];
    rdrRiga('Anagrafica di Mario Rossi');
    rdrRiga('Anagrafica di Ida Romano');
    $primaDelRipristino = DB::table('registro_modifiche')->orderBy('id')->get(['id', 'sequenza', 'impronta'])->map(fn ($r) => (array) $r)->all();
    $nelRegistro = count($primaDelRipristino);
    RdrImportatoreDellaCopia::$copia = $copia;

    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-06-01T09:00:00+02:00'));

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    $annullamento = LetturaDelRegistro::annullamento();
    expect($stato['phase'])->toBe('completed')
        ->and(DB::table('registro_modifiche')->where('azione', '!=', 'ripristino_copia')->orderBy('id')->get(['id', 'sequenza', 'impronta'])
            ->map(fn ($r) => (array) $r)->all())->toBe($primaDelRipristino)
        ->and($ripristino->lotto)->toMatchArray(['sequenza_comune' => 0, 'copia_senza_registro' => true, 'righe_rimesse' => $nelRegistro,
            'prima_sequenza_rimessa' => 1, 'ultima_sequenza_rimessa' => $nelRegistro])
        ->and($ripristino->lotto)->not->toHaveKey('documento_pdf')
        ->and($ripristino->riepilogo)->toBe('Ripristinata la copia del 01/06/2026. La copia non aveva righe del registro: le ' . $nelRegistro
            . ' righe di prima tornano tutte nel registro, annullate da questo ripristino.')
        ->and(Documento::query()->count())->toBe(0)
        ->and(LetturaDelRegistro::eAnnullata(RegistroModifica::query()->where('sequenza', 1)->sole(), $annullamento))->toBeTrue()
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue()
        ->and($stato['outcome']['registro_modifiche'])->toBe(['righe_rimesse' => $nelRegistro, 'oscuramenti_riapplicati' => 0, 'tutte' => true])
        ->and(glob(rdrCartella() . '/backups/.registro-modifiche-*'))->toBe([]);
});

it('una copia con righe del registro ma nessuna in comune (un\'altra storia): le righe di prima vanno in un PDF fra i documenti, non pubblicato, e la riga lo collega', function () {
    $copia = rdrCopiaDiUnAltraStoria();
    rdrRiga('Anagrafica di Mario Rossi');
    rdrRiga('Anagrafica di Ida Romano');
    $nelRegistro = DB::table('registro_modifiche')->count();
    RdrImportatoreDellaCopia::$copia = $copia;

    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-06-01T09:00:00+02:00'));

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    expect($ripristino->lotto['errore'] ?? null)->toBeNull();
    $documento = Documento::query()->findOrFail($ripristino->lotto['documento_pdf']);
    expect($stato['phase'])->toBe('completed')
        // Il registro è quello della copia, più la nascita del documento del PDF e la riga del ripristino.
        ->and(RegistroModifica::query()->count())->toBe(count($copia['registro_modifiche']) + 2)
        ->and(RegistroModifica::query()->orderByDesc('id')->limit(2)->pluck('azione')->all())->toBe(['ripristino_copia', 'creata'])
        ->and(RegistroModifica::query()->where('oggetto_tipo', 'documento')->sole()->autore_nome)->toBe('Andrea Costa')
        ->and($ripristino->lotto)->not->toHaveKey('sequenza_comune')
        ->and($ripristino->rimando)->toMatchArray(['tipo' => 'documento', 'id' => $documento->id])
        ->and($ripristino->riepilogo)->toContain('non ha righe in comune con quello di prima: le ' . $nelRegistro . ' righe di prima sono nel PDF')
        ->and($documento->is_published)->toBeFalsy()
        ->and($documento->documentable_type)->toBeNull()
        ->and($documento->anagrafiche()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($documento->path))->toBeTrue()
        ->and(str_starts_with((string) Storage::disk('local')->get($documento->path), '%PDF'))->toBeTrue()
        ->and(LetturaDelRegistro::annullamento())->toBe([])
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue()
        ->and($stato['outcome']['registro_modifiche'])->toBe(['righe_nel_pdf' => $nelRegistro, 'nome_pdf' => $documento->name])
        ->and(glob(rdrCartella() . '/backups/.registro-modifiche-*'))->toBe([]);
});

it('un errore della fase non ferma il ripristino: finisce, la riga dice che cosa non è stato rimesso, e il file resta se il PDF non si fa', function () {
    $copia = rdrCopiaDiUnAltraStoria();
    rdrRiga('Anagrafica di Mario Rossi');
    RdrImportatoreDellaCopia::$copia = $copia;
    $this->mock(PdfService::class, fn ($m) => $m->shouldReceive('generate')->andThrow(new RuntimeException('mPDF non ha memoria')));

    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-06-01T09:00:00+02:00'));

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    expect($stato['phase'])->toBe('completed')
        ->and(app(RestoreMode::class)->active())->toBeFalse()
        ->and($ripristino->riepilogo)->toContain('il file con le righe resta nella cartella delle copie')
        ->and($ripristino->lotto['errore'])->toContain('mPDF non ha memoria')
        ->and($stato['outcome']['registro_modifiche']['file_rimasto'])->toStartWith('.registro-modifiche-')
        ->and(glob(rdrCartella() . '/backups/.registro-modifiche-*'))->toHaveCount(1);
});

it('ripresa senza doppioni: rifatta da capo dopo che le righe sono già state rimesse, nessuna riga doppia e una sola riga del ripristino', function () {
    rdrRiga('Anagrafica di Mario Rossi');
    $copia = rdrFotografia();
    rdrRiga('Anagrafica di Ida Romano');
    rdrRiga('Anagrafica di Luca Bianchi');
    $servizio = app(RegistroNelRipristino::class);
    $stato = ['uuid' => 'r-prova', 'manifest' => ['created_at' => '2026-12-10T09:00:00+01:00'], 'created_by' => $this->andrea->id,
        'created_by_nome' => 'Andrea Costa', 'created_by_ruolo' => 'amministratore', 'checkpoint' => []];

    $stato['checkpoint']['registro'] = $servizio->salva($stato, new StepBudget(60));
    (new RdrImportatoreDellaCopia())->wipe();
    RdrImportatoreDellaCopia::$copia = $copia;
    (new RdrImportatoreDellaCopia())->importStep('', [], new StepBudget(60));
    $finito = new StepBudget(0);
    $stato['checkpoint']['registro'] = $servizio->rimetti($stato, $finito);
    expect($stato['checkpoint']['registro']['fase'])->toBe('rimettere');
    $file = $stato['checkpoint']['registro']['file'];
    File::copy($file, $file . '.parte');

    $primaVolta = $servizio->rimetti($stato, new StepBudget(60));
    // Il processo «muore» dopo aver confermato le righe e la riga del ripristino, prima di scrivere il punto di ripresa: si riparte da quello di prima.
    File::move($file . '.parte', $file);
    $secondaVolta = $servizio->rimetti($stato, new StepBudget(60));

    expect($primaVolta['fase'])->toBe('fatto')
        ->and($secondaVolta['fase'])->toBe('fatto')
        ->and($secondaVolta)->not->toHaveKey('errore')
        ->and(DB::table('registro_modifiche')->where('azione', 'ripristino_copia')->count())->toBe(1)
        ->and(DB::table('registro_modifiche')->count())->toBe(DB::table('registro_modifiche')->distinct()->count('id'))
        ->and(DB::table('registro_modifiche_condomini')->count())->toBe(DB::table('registro_modifiche_condomini')->distinct()->count('riga_id'))
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue()
        ->and(is_file($file))->toBeFalse();
});

it('«Annulla» del ripristino toglie il file delle righe', function () {
    rdrRiga('Anagrafica di Mario Rossi');
    $servizio = app(RegistroNelRipristino::class);
    $stato = ['uuid' => 'r-annullato', 'phase' => 'importing_database', 'checkpoint' => []];
    $stato['checkpoint']['registro'] = $servizio->salva($stato, new StepBudget(60));
    app(RestoreState::class)->put($stato);
    expect(is_file($stato['checkpoint']['registro']['file']))->toBeTrue();

    app(RestoreManager::class)->abort();

    expect(is_file($stato['checkpoint']['registro']['file']))->toBeFalse();
});

it('il backup sigilla il registro prima del dump: una riga senza posto nella catena lo prende al primo passo', function () {
    Storage::fake('backups');
    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper());
    $riga = rdrRiga('Anagrafica di Mario Rossi')->refresh();
    DB::table('registro_modifiche')->where('id', $riga->id)->update(['sequenza' => null, 'impronta' => null, 'impronta_precedente' => null]);
    DB::table('registro_modifiche_catena')->where('id', 1)->update(['ultima_sequenza' => (int) $riga->sequenza - 1,
        'ultima_impronta' => $riga->impronta_precedente]);
    expect(DB::table('registro_modifiche')->where('id', $riga->id)->value('sequenza'))->toBeNull();

    $manager = app(BackupManager::class);
    $backup = $manager->start($this->andrea, ['type' => 'db_only']);
    $manager->runStep($backup);

    expect(DB::table('registro_modifiche')->where('id', $riga->id)->value('sequenza'))->toBe($riga->sequenza)
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('due ripristini: una riga annullata dal primo resta annullata dopo il ripristino di una copia fatta dopo il primo, e l\'anagrafica 57 di dopo non la vede', function () {
    // Lunedì: la riga di Ida, la copia della domenica, poi la riga di Mario Rossi sull'anagrafica 57.
    rdrRiga('Anagrafica di Ida Romano', ['oggetto_id' => 1]);
    $copiaDellaDomenica = rdrFotografia();
    $mario = rdrRiga('Anagrafica di Mario Rossi', ['oggetto_id' => 57]);
    RdrImportatoreDellaCopia::$copia = $copiaDellaDomenica;
    $this->travelTo(Carbon::parse('2026-12-14 09:00:00', 'Europe/Rome'));
    rdrRipristina($this->andrea, rdrArchivio('2026-12-13T09:00:00+01:00'));
    expect(LetturaDelRegistro::eAnnullata($mario->fresh()))->toBeTrue();

    // Martedì: il contatore riparte dalla copia, e l'anagrafica 57 è Luca Bianchi. La copia della notte porta con sé il primo ripristino.
    $this->travelTo(Carbon::parse('2026-12-15 12:00:00', 'Europe/Rome'));
    rdrRiga('Anagrafica di Luca Bianchi', ['oggetto_id' => 57]);
    $copiaDellaNotte = rdrFotografia();
    rdrRiga('Anagrafica di Luca Bianchi', ['oggetto_id' => 57]);
    RdrImportatoreDellaCopia::$copia = $copiaDellaNotte;
    $this->travelTo(Carbon::parse('2026-12-16 09:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-12-15T23:00:00+01:00'));

    $storia = LetturaDelRegistro::storia('anagrafica', 57)->pluck('oggetto_nome')->all();
    expect($stato['phase'])->toBe('completed')
        ->and(RegistroModifica::query()->where('azione', 'ripristino_copia')->count())->toBe(2)
        ->and(LetturaDelRegistro::eAnnullata($mario->fresh()))->toBeTrue()
        ->and(LetturaDelRegistro::perLaPagina($mario->fresh())['annullata_dal_ripristino_del'])->toBe('14/12/2026')
        ->and($storia)->not->toContain('Anagrafica di Mario Rossi')
        ->and($storia)->toContain('Anagrafica di Luca Bianchi')
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('una copia di un\'altra installazione, ripristinata senza la sua chiave: le righe di prima vanno nel PDF, e la riga del ripristino dice che le righe della copia hanno un\'altra firma', function () {
    $chiaveDiQui = config('app.key');
    config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
    $copia = rdrCopiaDiUnAltraStoria();
    config(['app.key' => $chiaveDiQui]);
    rdrRiga('Anagrafica di Mario Rossi');
    RdrImportatoreDellaCopia::$copia = $copia;

    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));
    $stato = rdrRipristina($this->andrea, rdrArchivio('2026-06-01T09:00:00+02:00'));

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    expect($stato['phase'])->toBe('completed')
        ->and($ripristino->lotto)->toHaveKey('documento_pdf')
        ->and($ripristino->lotto['firma_di_un_altra_installazione'] ?? null)->toBeTrue()
        ->and($ripristino->riepilogo)->toContain('Le righe della copia non hanno la firma di questa installazione: «Verifica l\'integrità» le segnerà finché chi gestisce il server non aggiunge la chiave di quella installazione fra le chiavi di prima.')
        ->and(collect(VerificaDelRegistro::verifica()->nonTornano)->pluck('motivo')->unique()->values()->all())->toBe(['firma']);
});

it('il file delle righe rimasto dopo un errore lo toglie il comando della conservazione dopo trenta giorni, e lo scrive nella sua riga; quello di un ripristino da riprendere resta', function () {
    $copia = rdrCopiaDiUnAltraStoria();
    rdrRiga('Anagrafica di Mario Rossi');
    RdrImportatoreDellaCopia::$copia = $copia;
    $this->mock(PdfService::class, fn ($m) => $m->shouldReceive('generate')->andThrow(new RuntimeException('mPDF non ha memoria')));
    rdrRipristina($this->andrea, rdrArchivio('2026-06-01T09:00:00+02:00'));
    $rimasto = glob(rdrCartella() . '/backups/.registro-modifiche-*')[0];
    // L'ora del file è quella del disco, non quella della prova: il giorno del ripristino.
    touch($rimasto, Carbon::parse('2026-12-10 09:00:00', 'Europe/Rome')->getTimestamp());
    // Il file di un altro ripristino, fermato e da riprendere: lo stato lo nomina.
    $daRiprendere = rdrCartella() . '/backups/.registro-modifiche-da-riprendere.ndjson';
    File::put($daRiprendere, "{}\n");
    touch($daRiprendere, Carbon::parse('2026-11-01')->getTimestamp());
    app(RestoreState::class)->put(['uuid' => 'da-riprendere', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'checkpoint' => ['registro' => ['file' => $daRiprendere]]]);

    $this->travelTo(Carbon::parse('2026-12-20 03:15:00', 'Europe/Rome'));
    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();
    expect(is_file($rimasto))->toBeTrue();

    $this->travelTo(Carbon::parse('2027-01-12 03:15:00', 'Europe/Rome'));
    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    $riga = RegistroModifica::query()->where('azione', 'conservazione')->latest('id')->firstOrFail();
    expect(is_file($rimasto))->toBeFalse()
        ->and(is_file($daRiprendere))->toBeTrue()
        ->and($riga->autore_nome)->toBe('Il programma')
        ->and($riga->riepilogo)->toBe('Tolto il file delle righe del registro rimasto dal ripristino del 10/12/2026: più vecchio di 30 giorni.')
        ->and($riga->lotto['file_tolti'] ?? null)->toBe([basename($rimasto)])
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

/** La fase del registro come la fa il ripristino: il file prima del wipe, la copia importata, la chiave adottata in finalizzazione, le righe rimesse. */
function rdrFaseDelRegistro(array $copia, ?string $chiaveAdottata, User $chi): array
{
    $servizio = app(RegistroNelRipristino::class);
    $stato = ['uuid' => 'r-chiave', 'manifest' => ['created_at' => '2026-06-01T09:00:00+02:00'], 'created_by' => $chi->id, 'created_by_nome' => $chi->name,
        'created_by_ruolo' => 'amministratore', 'checkpoint' => []];
    $stato['checkpoint']['registro'] = $servizio->salva($stato, new StepBudget(600));
    (new RdrImportatoreDellaCopia())->wipe();
    RdrImportatoreDellaCopia::$copia = $copia;
    (new RdrImportatoreDellaCopia())->importStep('', [], new StepBudget(60));
    if ($chiaveAdottata !== null) {
        // Come `RestoreManager::adoptAppKey()`: la chiave della copia vale da qui in poi, e quella di prima non è più fra le chiavi del server.
        config(['app.key' => $chiaveAdottata, 'app.previous_keys' => []]);
    }
    do {
        $stato['checkpoint']['registro'] = $servizio->rimetti($stato, new StepBudget(600));
    } while ($stato['checkpoint']['registro']['fase'] !== RegistroNelRipristino::FATTO);

    return $stato['checkpoint']['registro'];
}

it('la chiave della copia adottata: le righe rimesse dal file, controllate con la chiave di prima, si firmano con quella adottata (4.812 righe, la copia di giugno)', function () {
    // Il server nuovo: 4.812 righe del registro, firmate con la sua chiave. La copia di giugno del server vecchio è di prima del registro.
    $copia = rdrFotografia();
    foreach (['registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche'] as $t) {
        $copia[$t] = [];
    }
    $copia['registro_modifiche_catena'] = [['id' => 1, 'ultima_sequenza' => 0, 'ultima_impronta' => null, 'verificata_il' => null, 'verifica_integro' => null,
        'verifica_righe' => null, 'verifica_dal' => null, 'verifica_riga_id' => null, 'verifica_righe_che_non_tornano' => null]];
    DB::transaction(function () {
        foreach (range(1, 4812 - RegistroModifica::query()->count()) as $i) {
            rdrRiga('Anagrafica n. ' . $i, ['oggetto_id' => $i, 'condomini' => [$this->roma->id => 'Via Roma 12'], 'soggetti' => [['anagrafica', $i]]]);
        }
    });
    expect(RegistroModifica::query()->count())->toBe(4812);
    $chiaveDellaCopia = 'base64:' . base64_encode(random_bytes(32));

    $registro = rdrFaseDelRegistro($copia, $chiaveDellaCopia, $this->andrea);

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    expect($registro)->not->toHaveKey('errore')
        ->and($ripristino->lotto)->toMatchArray(['righe_rimesse' => 4812, 'righe_rifirmate' => 4812, 'copia_senza_registro' => true])
        ->and($ripristino->riepilogo)->toContain('La chiave della copia è stata adottata: le 4.812 righe rimesse sono firmate di nuovo con lei.')
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('una riga del file che non torna con la chiave di prima non si rimette: va nel PDF, e la riga del ripristino lo dice', function () {
    $copia = rdrFotografia();
    rdrRiga('Anagrafica di Mario Rossi');
    $finta = rdrRiga('Anagrafica di Ida Romano');
    rdrRiga('Anagrafica di Luca Bianchi');
    // Fuori dal programma, prima del ripristino: la firma della riga di Ida è cambiata.
    DB::table('registro_modifiche')->where('id', $finta->id)->update(['firma' => str_repeat('0', 64)]);

    $registro = rdrFaseDelRegistro($copia, null, $this->andrea);

    $ripristino = RegistroModifica::query()->where('azione', 'ripristino_copia')->sole();
    $documento = Documento::query()->findOrFail($ripristino->lotto['documento_pdf']);
    expect(DB::table('registro_modifiche')->where('id', $finta->id)->exists())->toBeFalse()
        ->and($ripristino->lotto)->toMatchArray(['righe_rimesse' => 2, 'righe_senza_firma' => 1, 'righe_rifirmate' => 0])
        ->and($ripristino->riepilogo)->toContain('1 riga del file non aveva la firma del programma e non è stata rimessa: è nel PDF «' . $documento->name . '».')
        ->and($documento->is_published)->toBeFalsy()
        ->and($registro['esito']['righe_senza_firma'] ?? null)->toBe(1);
});
