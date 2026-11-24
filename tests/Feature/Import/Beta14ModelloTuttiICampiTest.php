<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 769, 1.12.0-beta.14: il foglio delle persone del modello ha tutti i campi dell'anagrafica.
 *
 * Il modello aveva otto colonne (unità, nome, codice fiscale, indirizzo, ruolo, quota, email, telefono): chi nell'anagrafica ha due email, la PEC, il
 * cellulare, la data di nascita o il documento li doveva scrivere a mano dopo. Ora il foglio ha anche `email_secondaria`, `pec`, `pec_comunicata_il`,
 * `mezzo_convocazione`, `cellulare`, `data_nascita`, `luogo_nascita`, `tipologia_documento`, `numero_documento`, `scadenza_documento` e `note`: tutte
 * facoltative; un modello vecchio senza queste colonne si importa come prima; un recapito scritto male dà un avviso e non entra, senza fermare il file;
 * la stessa persona su due righe con contatti diversi dà un avviso (il primo valore non vuoto entra, gli altri stanno nell'avviso).
 *
 * I file d'esempio si fanno qui, dal modello vero, con nomi inventati.
 */

use App\Models\Anagrafica;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ModelloManualeWriter;
use App\Services\Import\Parser\ModelloManualeParser;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

const B14TC_COLONNE = ['unita', 'nome', 'codice_fiscale', 'indirizzo', 'ruolo', 'quota_pct', 'email', 'telefono', 'email_secondaria', 'pec', 'pec_comunicata_il',
    'mezzo_convocazione', 'cellulare', 'data_nascita', 'luogo_nascita', 'tipologia_documento', 'numero_documento', 'scadenza_documento', 'note'];

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

afterEach(function () {
    foreach (b14tcTemporanei() as $percorso) {
        @unlink($percorso);
    }
});

function b14tcTemporanei(?string $percorso = null): array
{
    static $percorsi = [];
    if ($percorso !== null) {
        $percorsi[] = $percorso;

        return [];
    }
    [$tutti, $percorsi] = [$percorsi, []];

    return $tutti;
}

function b14tcUtente(): User
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
 * Il modello con due unità e le persone date, scritte per nome di colonna.
 *
 * @param  list<array<string, mixed>>  $persone  colonna => valore; le colonne che non ci sono restano vuote
 * @param  list<string>|null  $intestazione  le colonne da scrivere nel foglio (di solito tutte: con le sole prime otto è un modello «vecchio»)
 */
function b14tcModello(array $persone, ?array $intestazione = null): string
{
    $percorso = sys_get_temp_dir().'/b14tc_modello_'.uniqid().'.xlsx';
    (new ModelloManualeWriter)->scriviSu($percorso);
    b14tcTemporanei($percorso);
    $libro = IOFactory::load($percorso);
    $svuota = function (string $foglio, int $da) use ($libro) {
        $f = $libro->getSheetByName($foglio);
        for ($r = $da; $r <= $da + 40; $r++) {
            foreach (range('A', 'Z') as $c) {
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

    $colonne = $intestazione ?? B14TC_COLONNE;
    $f = $svuota('2 persone', 5);
    $f->fromArray($colonne, null, 'A5');
    $righe = array_map(fn (array $p) => array_map(fn (string $c) => $p[$c] ?? '', $colonne), $persone);
    $f->fromArray($righe, null, 'A6');

    $svuota('3 tabelle', 5);
    $svuota('4 saldi', 6);
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    return $percorso;
}

function b14tcLeggi(string $percorso): array
{
    return (new ModelloManualeParser)->estrai((new SpreadsheetReader)->leggi($percorso));
}

/** @return list<\App\Services\Import\Rilievo> */
function b14tcRilievi(array $letto, ?string $codice = null): array
{
    $tutti = [];
    foreach ($letto['esiti'] as $esito) {
        foreach ($esito->rilievi as $r) {
            if ($codice === null || $r->codice === $codice) {
                $tutti[] = $r;
            }
        }
    }

    return $tutti;
}

function b14tcConferma(User $utente, string $percorso): ImportBatch
{
    test()->actingAs($utente)->post(route('import.store'), ['file' => [new UploadedFile($percorso, 'modello.xlsx', null, test: true)]]);
    $batch = ImportBatch::query()->orderByDesc('id')->first();
    test()->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    return $batch->fresh();
}

$rossi = ['unita' => 'B1/1', 'nome' => 'ROSSI MARIO', 'codice_fiscale' => 'RSSMRA80A01H501U', 'indirizzo' => 'Via Roma 1, Roma', 'ruolo' => 'proprietario', 'quota_pct' => 100];

it('il foglio delle persone ha tutte le colonne dell\'anagrafica, le otto di prima per prime', function () {
    $percorso = sys_get_temp_dir().'/b14tc_vuoto_'.uniqid().'.xlsx';
    (new ModelloManualeWriter)->scriviSu($percorso);
    b14tcTemporanei($percorso);

    $f = IOFactory::load($percorso)->getSheetByName('2 persone');
    $intestazione = array_values(array_filter($f->rangeToArray('A5:Z5')[0], fn ($v) => $v !== null && $v !== ''));

    expect($intestazione)->toBe(B14TC_COLONNE);
});

it('un modello vecchio, con le sole otto colonne, si importa come prima e senza avvisi nuovi', function () use ($rossi) {
    $percorso = b14tcModello([$rossi + ['email' => 'mario.rossi@example.test', 'telefono' => '0212345678']], array_slice(B14TC_COLONNE, 0, 8));

    $letto = b14tcLeggi($percorso);
    $soggetto = $letto['soggetti']['RSSMRA80A01H501U'];

    expect($soggetto->email)->toBe('mario.rossi@example.test')
        ->and($soggetto->telefoni)->toBe(['0212345678'])
        ->and(array_map(fn ($r) => $r->codice, b14tcRilievi($letto)))->toBe([]);

    b14tcConferma(b14tcUtente(), $percorso);

    $a = Anagrafica::where('codice_fiscale', 'RSSMRA80A01H501U')->sole();
    expect($a->email)->toBe('mario.rossi@example.test')->and($a->telefono)->toBe('0212345678')
        ->and($a->email_secondaria)->toBeNull()->and($a->pec)->toBeNull()->and($a->mezzo_convocazione)->toBeNull();
});

it('un modello vecchio lascia vuoti i campi nuovi del soggetto', function () use ($rossi) {
    $soggetto = b14tcLeggi(b14tcModello([$rossi + ['email' => 'mario.rossi@example.test']], array_slice(B14TC_COLONNE, 0, 8)))['soggetti']['RSSMRA80A01H501U'];

    expect($soggetto->emailSecondaria)->toBeNull()->and($soggetto->cellulare)->toBeNull()->and($soggetto->mezzoConvocazione)->toBeNull()
        ->and($soggetto->dataNascita)->toBeNull()->and($soggetto->numeroDocumento)->toBeNull();
});

it('le colonne nuove arrivano tutte nella scheda della persona', function () use ($rossi) {
    $percorso = b14tcModello([$rossi + [
        'email' => 'mario.rossi@example.test', 'telefono' => '0212345678', 'email_secondaria' => 'mario.lavoro@example.test', 'pec' => 'mario.rossi@pec.example.test',
        'pec_comunicata_il' => '15/03/2025', 'mezzo_convocazione' => 'PEC', 'cellulare' => '3331234567', 'data_nascita' => '01/01/1980', 'luogo_nascita' => 'Roma',
        'tipologia_documento' => 'carta d\'identità', 'numero_documento' => 'AB1234567', 'scadenza_documento' => '10/06/2031', 'note' => 'Preferisce le mail al mattino',
    ]]);

    $batch = b14tcConferma(b14tcUtente(), $percorso);

    $a = Anagrafica::where('codice_fiscale', 'RSSMRA80A01H501U')->sole();
    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($a->email)->toBe('mario.rossi@example.test')
        ->and($a->email_secondaria)->toBe('mario.lavoro@example.test')
        ->and($a->pec)->toBe('mario.rossi@pec.example.test')
        ->and(substr((string) $a->pec_comunicata_il, 0, 10))->toBe('2025-03-15')
        ->and($a->mezzo_convocazione)->toBe('pec')
        ->and($a->telefono)->toBe('0212345678')
        ->and($a->cellulare)->toBe('3331234567')
        ->and(substr((string) $a->data_nascita, 0, 10))->toBe('1980-01-01')
        ->and($a->luogo_nascita)->toBe('Roma')
        ->and($a->tipologia_documento)->toBe('id_card')
        ->and($a->numero_documento)->toBe('AB1234567')
        ->and(substr((string) $a->scadenza_documento, 0, 10))->toBe('2031-06-10')
        ->and($a->note)->toBe('Preferisce le mail al mattino');
});

it('il mezzo di convocazione si scrive in parole e «a mano» vale a_mano', function () use ($rossi) {
    $letto = b14tcLeggi(b14tcModello([
        $rossi + ['mezzo_convocazione' => 'Raccomandata'],
        ['unita' => 'B1/2', 'nome' => 'BIANCHI ANNA', 'indirizzo' => 'Via Roma 2, Roma', 'ruolo' => 'proprietario', 'quota_pct' => 100, 'mezzo_convocazione' => 'a mano'],
    ]));

    expect($letto['soggetti']['RSSMRA80A01H501U']->mezzoConvocazione)->toBe('raccomandata')
        ->and($letto['soggetti']['nome:bianchi anna']->mezzoConvocazione)->toBe('a_mano')
        ->and(b14tcRilievi($letto, 'modello.mezzo_sconosciuto'))->toBe([]);
});

it('una PEC o una email scritta male, un mezzo sconosciuto e una data impossibile danno un avviso e non fermano il file', function () use ($rossi) {
    $percorso = b14tcModello([$rossi + [
        'email' => 'mario.rossi.example.test', 'pec' => 'non-una-pec', 'email_secondaria' => 'mario@', 'mezzo_convocazione' => 'piccione', 'pec_comunicata_il' => '31/02/2025',
        'data_nascita' => 'ieri', 'tipologia_documento' => 'tessera del cinema',
    ]]);

    $letto = b14tcLeggi($percorso);
    $codici = array_map(fn ($r) => $r->codice, b14tcRilievi($letto));

    expect($codici)->toContain('modello.recapito_non_valido', 'modello.mezzo_sconosciuto', 'modello.data_non_valida', 'modello.documento_sconosciuto')
        ->and(collect(b14tcRilievi($letto))->filter(fn ($r) => $r->severita->value === 'errore'))->toHaveCount(0)
        ->and(b14tcRilievi($letto, 'modello.recapito_non_valido'))->toHaveCount(3);

    $batch = b14tcConferma(b14tcUtente(), $percorso);
    $a = Anagrafica::where('codice_fiscale', 'RSSMRA80A01H501U')->sole();

    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($a->email)->toBeNull()->and($a->pec)->toBeNull()->and($a->email_secondaria)->toBeNull()
        ->and($a->mezzo_convocazione)->toBeNull()->and($a->pec_comunicata_il)->toBeNull()->and($a->data_nascita)->toBeNull()->and($a->tipologia_documento)->toBeNull();
});

it('la stessa persona su due righe con email diverse: entra la prima non vuota e l\'avviso dice anche l\'altra', function () use ($rossi) {
    $percorso = b14tcModello([
        $rossi + ['email' => 'mario.rossi@example.test', 'cellulare' => '3331234567'],
        ['unita' => 'B1/2', 'nome' => 'ROSSI MARIO', 'codice_fiscale' => 'RSSMRA80A01H501U', 'indirizzo' => 'Via Roma 1, Roma', 'ruolo' => 'proprietario', 'quota_pct' => 100,
            'email' => 'm.rossi@example.test', 'cellulare' => '3331234567', 'pec' => 'mario.rossi@pec.example.test'],
    ]);

    $letto = b14tcLeggi($percorso);
    $avvisi = b14tcRilievi($letto, 'modello.contatti_diversi');

    expect($letto['soggetti'])->toHaveCount(1)
        ->and($letto['soggetti']['RSSMRA80A01H501U']->email)->toBe('mario.rossi@example.test')
        ->and($letto['soggetti']['RSSMRA80A01H501U']->pec)->toBe('mario.rossi@pec.example.test')
        ->and($avvisi)->toHaveCount(1)
        ->and($avvisi[0]->severita->value)->toBe('avviso')
        ->and($avvisi[0]->messaggio)->toContain('ROSSI MARIO', 'email', 'm.rossi@example.test', 'mario.rossi@example.test');

    b14tcConferma(b14tcUtente(), $percorso);
    $a = Anagrafica::where('codice_fiscale', 'RSSMRA80A01H501U')->sole();
    expect($a->email)->toBe('mario.rossi@example.test')->and($a->pec)->toBe('mario.rossi@pec.example.test');
});

it('la stessa persona su due righe con gli stessi contatti non dà nessun avviso', function () use ($rossi) {
    $letto = b14tcLeggi(b14tcModello([
        $rossi + ['email' => 'Mario.Rossi@example.test'],
        ['unita' => 'B1/2'] + $rossi + ['email' => 'mario.rossi@example.test', 'unita' => 'B1/2'],
    ]));

    expect(b14tcRilievi($letto, 'modello.contatti_diversi'))->toBe([]);
});
