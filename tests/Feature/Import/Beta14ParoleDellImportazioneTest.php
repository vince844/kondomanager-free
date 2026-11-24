<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Models\User;
use App\Services\Import\Controlli\CatalogoControlli;
use App\Services\Import\ModelloManualeWriter;
use App\Services\Import\ReportType;
use App\Services\Import\SpreadsheetReader;

/**
 * Le frasi dell'importazione che non dicevano il vero (Coda 759, voci 3–9).
 *
 * Ogni frase si legge dove vive: il PDF del rapporto con `pdftotext`, il foglio del modello dal file
 * generato, la descrizione del permesso dall'enum, il catalogo dei controlli dal suo metodo; le frasi
 * dei servizi che nascono solo dentro una scrittura vera (la scheda delle tabelle senza capitolo,
 * il riparto con la titolarità cambiata) si leggono dal sorgente. Le frasi delle schermate Vue sono
 * nel vitest `paroleDellImportazione.test.ts`.
 */
function paroleUtenteImport(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/** Un lotto concluso con i suoi file (tipo del report per file) e i livelli scritti nel rapporto. */
function paroleLottoConcluso(array $tipiFile, array $livelli): ImportBatch
{
    $lotto = ImportBatch::create([
        'sorgente' => 'danea',
        'stato' => ImportBatch::STATO_COMPLETATO,
        'condominio_id' => Condominio::factory()->create()->id,
        'completato_at' => now(),
        'rapporto' => [
            'livelli' => $livelli,
            'saldi' => null,
            'generato_il' => now()->toIso8601String(),
        ],
    ]);

    foreach ($tipiFile as $i => $tipo) {
        ImportFile::create([
            'import_batch_id' => $lotto->id,
            'nome_originale' => "file{$i}.xlsx",
            'percorso' => "import/file{$i}.xlsx",
            'dimensione' => 10,
            'hash' => hash('sha256', "f{$i}".$lotto->id),
            'report_type' => $tipo,
            'confidenza' => 100,
        ]);
    }

    return $lotto;
}

function paroleRigaLivello(string $chiave, string $etichetta, array $sovrascrivi = []): array
{
    return $sovrascrivi + [
        'livello' => $chiave, 'etichetta' => $etichetta,
        'creati' => 0, 'uniti' => 0, 'saltati' => 0,
        'riuscito' => true, 'gia_a_posto' => false, 'saltato' => false,
        'prerequisiti_mancanti' => [], 'rilievi' => [], 'avvisi' => [],
    ];
}

/** Il PDF del rapporto dalla sua rotta, letto con `pdftotext` e con gli spazi ridotti a uno. */
function paroleTestoDelRapporto($test, ImportBatch $lotto): string
{
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $test->markTestSkipped('pdftotext non c\'è su questa macchina.');
    }

    $pdf = $test->actingAs(paroleUtenteImport())->get(route('import.rapporto', $lotto->uuid))->assertOk()->getContent();
    $file = tempnam(sys_get_temp_dir(), 'rapp_');
    file_put_contents($file, $pdf);
    $testo = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    // pdftotext rende «fi» come la legatura «ﬁ»: si rimette in due lettere.
    return preg_replace('/\s+/', ' ', str_replace(['ﬁ', 'ﬂ'], ['fi', 'fl'], $testo));
}

it('(4) il PDF del rapporto dice «non fornito» per un livello lasciato in bianco, non «fermato»', function () {
    $lotto = paroleLottoConcluso([ReportType::ModelloManuale->value], [
        paroleRigaLivello('saldi', 'Saldi di apertura', ['riuscito' => false, 'saltato' => true]),
    ]);

    $testo = paroleTestoDelRapporto($this, $lotto);

    expect($testo)->toContain('Saldi di apertura')->toContain('non fornito')->not->toContain('fermato');
});

it('(4) il PDF dice «fermato» solo per un livello che si è fermato davvero, e «non nei tuoi file» per quello che nei file non c\'era', function () {
    $lotto = paroleLottoConcluso([ReportType::ModelloManuale->value], [
        paroleRigaLivello('tabelle', 'Tabelle millesimali', ['riuscito' => false, 'saltato' => false]),
        paroleRigaLivello('capitoli', 'Capitoli di spesa', ['gia_a_posto' => true]),
        paroleRigaLivello('unita', 'Unità', ['creati' => 6]),
        paroleRigaLivello('persone', 'Persone', ['creati' => 2, 'gia_a_posto' => true]),
    ]);

    $testo = paroleTestoDelRapporto($this, $lotto);

    expect($testo)->toContain('fermato')
        ->and($testo)->toContain('non nei tuoi file')
        ->and($testo)->toContain('era già a posto')
        ->and($testo)->toContain('completo');
});

it('(4) il PDF dice l\'origine dal tipo dei file del lotto: il modello di KondoManager non è «danea»', function () {
    $modello = paroleLottoConcluso([ReportType::ModelloManuale->value], [paroleRigaLivello('unita', 'Unità', ['creati' => 1])]);
    $danea = paroleLottoConcluso([ReportType::RipartoConsuntivo->value, ReportType::AnagraficaMillesimi->value], [paroleRigaLivello('unita', 'Unità', ['creati' => 1])]);

    $dalModello = paroleTestoDelRapporto($this, $modello);
    $daDanea = paroleTestoDelRapporto($this, $danea);

    expect($dalModello)->toContain('origine: modello di KondoManager')->not->toContain('origine: danea')
        ->and($daDanea)->toContain('origine: Danea')->not->toContain('origine: danea');
});

it('(5) l\'avviso delle tabelle senza capitolo dice «Orfana», come il badge dell\'elenco', function () {
    $sorgente = file_get_contents(base_path('app/Services/Import/Livelli/LivelloTabelle.php'));
    $badge = file_get_contents(base_path('resources/js/components/gestionale/tabelle/columns.ts'));

    expect($badge)->toContain("'Orfana'")
        ->and($sorgente)->not->toContain('«Orfane»')
        ->and($sorgente)->toContain('con il segno «Orfana»');
});

it('(6) nessuna frase dell\'importazione dice che il programma non gestisce il subentro', function () {
    $parser = file_get_contents(base_path('app/Services/Import/Parser/RipartoConsuntivoParser.php'));
    $catalogo = CatalogoControlli::per('riparto.titolarita_cambiata_in_corso_anno')->perche;

    expect($parser)->not->toContain('non gestisce ancora il subentro')
        ->and($parser)->toContain('non porta i passaggi di proprietà')
        ->and($parser)->toContain('Registra passaggio')
        ->and($catalogo)->not->toContain('non gestisce ancora il subentro')
        ->and($catalogo)->toContain('L\'importazione non porta i passaggi di proprietà')
        ->and($catalogo)->toContain('Registra passaggio');
});

it('(7) il foglio delle unità del modello dice il vero: uno spazio in più viene segnalato, non «due unità diverse»', function () {
    $percorso = tempnam(sys_get_temp_dir(), 'modello_').'.xlsx';
    (new ModelloManualeWriter)->scriviSu($percorso);
    $fogli = (new SpreadsheetReader)->leggi($percorso);
    @unlink($percorso);

    $testo = '';
    foreach ($fogli as $f) {
        if ($f->nome === '1 unita') {
            for ($i = 0; $i < 5; $i++) {
                $testo .= ' '.implode(' ', array_map('strval', $f->riga($i)));
            }
        }
    }

    expect($testo)->not->toContain('due unità diverse')
        ->and($testo)->toContain('te lo segnalo');
});

it('(8) la descrizione del permesso «Importa dati» dice che per annullare serve anche «Elimina condomini»', function () {
    $d = Permission::IMPORTA_DATI->description();

    expect($d)->toContain('Elimina condomini')->and($d)->not->toBe('Permette di importare un condominio da un altro gestionale, e di annullare l\'importazione');
});

it('il PDF dice «non importati, per tua scelta» per i saldi lasciati fuori con «Importa senza i saldi», con chi li scrive', function () {
    $lotto = paroleLottoConcluso([ReportType::RipartoConsuntivo->value], [
        paroleRigaLivello('unita', 'Unità', ['creati' => 6]),
        paroleRigaLivello('saldi', 'Saldi di apertura', [
            'riuscito' => false, 'saltato' => true, 'per_scelta' => true,
            'prerequisiti_mancanti' => [[
                'codice' => 'saldi.non_importati_per_scelta',
                'cosa_manca' => 'I saldi di apertura non li ho importati: hai scelto «Importa senza i saldi».',
                'rimedio' => 'I saldi li scrivi tu, dalla pagina «Saldi iniziali» del condominio.',
            ]],
        ]),
    ]);

    $testo = paroleTestoDelRapporto($this, $lotto);

    expect($testo)->toContain('non importati, per tua scelta')
        ->and($testo)->not->toContain('non fornito')
        ->and($testo)->toContain('I saldi li scrivi tu, dalla pagina «Saldi iniziali» del condominio.');
});
