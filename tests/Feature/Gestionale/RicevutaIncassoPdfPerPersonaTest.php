<?php

use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * «Scarica un PDF per persona» (decisione 207.2): per chi non ha la posta del programma, un archivio .zip con un PDF per persona (le sue
 * ricevute selezionate) e un file di testo con gli indirizzi email, da mandare dal proprio programma di posta. Ogni ricevuta dell'archivio è
 * un'emissione del registro, di canale `stampa`, come la stampa, con le stesse scelte di bollo, nota e causale e lo stesso tetto.
 *
 * Che cosa NON copre: il pulsante e lo scaricamento nel browser (nei test JavaScript), l'aspetto dei PDF (verifica a video).
 */
function perPersonaScarica($test, object $s, array $campi, $utente = null)
{
    return ($test->actingAs($utente ?? amministratoreRicevute()))
        ->post(route('admin.gestionale.ricevute.pdf-per-persona', ['condominio' => $s->condominio->id]), $campi);
}

/** I file dell'archivio, per nome, con il loro contenuto: l'archivio si apre con `ZipArchive`, come lo aprirebbe chi lo scarica. */
function perPersonaFileDellArchivio(string $zip): array
{
    $percorso = tempnam(sys_get_temp_dir(), 'prova-zip');
    file_put_contents($percorso, $zip);
    $archivio = new ZipArchive;
    expect($archivio->open($percorso))->toBeTrue();
    $file = [];
    for ($i = 0; $i < $archivio->numFiles; $i++) {
        $nome = $archivio->getNameIndex($i);
        $file[$nome] = $archivio->getFromIndex($i);
    }
    $archivio->close();
    unlink($percorso);

    return $file;
}

function perPersonaPagine(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
}

test('il fatto di libreria: ZipArchive c\'è, scrive un archivio in un file temporaneo e lo rilegge con i nomi e i contenuti com\'erano', function () {
    expect(class_exists(ZipArchive::class))->toBeTrue();
    $percorso = tempnam(sys_get_temp_dir(), 'prova-zip');
    $archivio = new ZipArchive;
    // Il file temporaneo esiste ed è vuoto: si sovrascrive.
    expect($archivio->open($percorso, ZipArchive::OVERWRITE))->toBeTrue();
    $archivio->addFromString('Anna Bruni.pdf', '%PDF-1.4 uno');
    $archivio->addFromString('Indirizzi email.txt', "Anna Bruni\r\n");
    expect($archivio->close())->toBeTrue();

    $file = perPersonaFileDellArchivio(file_get_contents($percorso));
    unlink($percorso);

    expect($file)->toBe(['Anna Bruni.pdf' => '%PDF-1.4 uno', 'Indirizzi email.txt' => "Anna Bruni\r\n"]);
});

test('l\'archivio ha un PDF per persona con le sue ricevute e l\'elenco degli indirizzi; ogni ricevuta è una stampa nel registro, con le scelte e l\'impronta del suo PDF', function () {
    $s = scenarioRicevutaIncasso();
    $a1 = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $a2 = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 50.00]], '2026-03-01', 50.00);
    $u1 = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-05', 60.00);
    [$p1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);

    $risposta = perPersonaScarica($this, $s, ['incassi' => [$a1->id, $u1->id, $a2->id, $p1], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta',
        'nota' => 'Versamento ricevuto in studio', 'stampa_causale' => 1]);

    $risposta->assertOk()->assertHeader('Content-Type', 'application/zip');
    expect($risposta->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('.zip');
    $file = perPersonaFileDellArchivio($risposta->getContent());
    expect(array_keys($file))->toBe(['Anna Bruni.pdf', 'Persona 01.pdf', 'Ugo Neri.pdf', 'Indirizzi email.txt'])
        ->and(perPersonaPagine($file['Anna Bruni.pdf']))->toBe(2)
        ->and(perPersonaPagine($file['Persona 01.pdf']))->toBe(1)
        ->and(perPersonaPagine($file['Ugo Neri.pdf']))->toBe(1);

    $righe = RegistroInvio::orderBy('id')->get()->keyBy('documentabile_id');
    expect($righe)->toHaveCount(4)
        ->and($righe->pluck('canale')->unique()->all())->toBe(['stampa'])
        ->and($righe->pluck('stato')->unique()->all())->toBe(['stampata'])
        ->and($righe[$a1->id]->dati)->toMatchArray(['bollo' => 'assolta', 'soglia' => 'sopra', 'nota' => 'Versamento ricevuto in studio', 'stampa_causale' => true])
        ->and($righe[$a2->id]->dati)->toMatchArray(['bollo' => 'non_dovuta', 'soglia' => 'sotto'])
        ->and($righe[$a1->id]->anagrafica_id)->toBe($s->anna->id)
        ->and($righe[$a1->id]->impronta)->toBe(hash('sha256', $file['Anna Bruni.pdf']))
        ->and($righe[$a2->id]->impronta)->toBe(hash('sha256', $file['Anna Bruni.pdf']))
        ->and($righe[$u1->id]->impronta)->toBe(hash('sha256', $file['Ugo Neri.pdf']))
        ->and($righe[$p1]->impronta)->toBe(hash('sha256', $file['Persona 01.pdf']));

    // L'elenco: per persona i suoi indirizzi, quante ricevute e il nome del file; chi non ha email lo dice.
    $elenco = str_replace("\r\n", "\n", $file['Indirizzi email.txt']);
    expect($elenco)->toContain("Anna Bruni — 2 ricevute — Anna Bruni.pdf\n  primaria: {$s->anna->email}\n  secondaria: {$s->anna->email_secondaria}")
        ->and($elenco)->toContain("Persona 01 — 1 ricevuta — Persona 01.pdf\n  primaria: p1@prova.it\n  secondaria: p1.casa@prova.it")
        ->and($elenco)->toContain("Ugo Neri — 1 ricevuta — Ugo Neri.pdf\n  nessun indirizzo email: da stampare per la posta")
        ->and($file['Indirizzi email.txt'])->toContain("\r\n")
        // Il segno UTF-8 in testa: il Blocco note di Windows legge gli accenti dei nomi.
        ->and(str_starts_with($file['Indirizzi email.txt'], "\u{FEFF}Ricevute di pagamento — Condominio Via dei Pini"))->toBeTrue();

    // Scaricato di nuovo, l'archivio porta le copie: l'originale è il primo.
    $file2 = perPersonaFileDellArchivio(perPersonaScarica($this, $s, ['incassi' => [$a1->id], 'bollo' => 'assolta'])->assertOk()->getContent());
    expect(array_keys($file2))->toBe(['Anna Bruni.pdf', 'Indirizzi email.txt'])
        ->and(RegistroInvio::orderByDesc('id')->first()->dati)->toHaveKey('copia_del');
});

test('i nomi dei file: senza i caratteri che i sistemi rifiutano, senza accenti, e due persone con lo stesso nome hanno due file', function () {
    expect(EmissioneRicevute::nomeFileDellaPersona('Rossi/Bianchi: "Studio" <srl>?*|\\'))->toBe('Rossi Bianchi Studio srl')
        ->and(EmissioneRicevute::nomeFileDellaPersona('Nicolò D\'Amico'))->toBe('Nicolo D\'Amico')
        ->and(EmissioneRicevute::nomeFileDellaPersona('  ...  '))->toBe('Senza nome')
        ->and(EmissioneRicevute::nomeFileDellaPersona(null))->toBe('Senza nome')
        ->and(EmissioneRicevute::nomeFileDellaPersona('con'))->toBe('con_')
        ->and(EmissioneRicevute::nomeFileDellaPersona("Anna\tBruni."))->toBe('Anna Bruni')
        ->and(mb_strlen(EmissioneRicevute::nomeFileDellaPersona(str_repeat('Anna Bruni ', 20))))->toBeLessThanOrEqual(80);

    $s = scenarioRicevutaIncasso();
    $omonimo = Anagrafica::create(['nome' => 'Anna Bruni', 'email' => 'omonima@prova.it', 'indirizzo' => 'Via dei Pini 12', 'codice_fiscale' => 'OMONIMAXXXXXXXXX']);
    $s->int7->anagrafiche()->attach($omonimo->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $quota = \App\Models\Gestionale\RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $omonimo->id, 'immobile_id' => $s->int7->id,
        'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $o = incassaPerRicevuta($s, $omonimo, [[$quota, 10.00]], '2026-02-01', 10.00);

    $file = perPersonaFileDellArchivio(perPersonaScarica($this, $s, ['incassi' => [$a->id, $o->id], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])->assertOk()->getContent());

    expect(array_keys($file))->toBe(['Anna Bruni.pdf', 'Anna Bruni (2).pdf', 'Indirizzi email.txt'])
        ->and($file['Indirizzi email.txt'])->toContain('Anna Bruni — 1 ricevuta — Anna Bruni (2).pdf');
});

test('lo stesso tetto della stampa, il bollo da scegliere e il 404 per un incasso di un altro condominio: niente archivio e niente nel registro', function () {
    $s = scenarioRicevutaIncasso();
    $s2 = scenarioRicevutaIncasso('Condominio Corso Italia');
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $altro = incassaPerRicevuta($s2, $s2->anna, [[$s2->anna1, 150.00]], '2026-02-01', 150.00);

    $oltre = perPersonaScarica($this, $s, ['incassi' => range(1, EmissioneRicevute::TETTO_PDF + 1), 'bollo' => 'assolta']);
    $senzaBollo = perPersonaScarica($this, $s, ['incassi' => [$a->id]]);
    $dellAltro = perPersonaScarica($this, $s, ['incassi' => [$a->id, $altro->id], 'bollo' => 'assolta']);

    $oltre->assertStatus(422);
    expect($oltre->getContent())->toContain('al massimo ' . EmissioneRicevute::TETTO_PDF);
    $senzaBollo->assertStatus(422);
    expect($senzaBollo->getContent())->toContain('Scegli lo stato dell\'imposta di bollo');
    $dellAltro->assertNotFound();
    expect(RegistroInvio::count())->toBe(0);
});

test('«tutte le N dei filtri» vale anche per l\'archivio: il server rilegge la selezione dai filtri', function () {
    $s = scenarioRicevutaIncasso();
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-05', 60.00);
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo2, 60.00]], '2026-06-05', 60.00);

    $file = perPersonaFileDellArchivio(perPersonaScarica($this, $s, ['tutte' => 1, 'filtri' => ['data_da' => '2026-01-01', 'data_a' => '2026-03-31', 'ricevuta' => 'mai'],
        'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])->assertOk()->getContent());

    expect(array_keys($file))->toBe(['Anna Bruni.pdf', 'Ugo Neri.pdf', 'Indirizzi email.txt'])
        ->and(RegistroInvio::count())->toBe(2)
        ->and(RegistroInvio::pluck('documentabile_id')->map(fn ($id) => ScritturaContabile::find($id)->data_competenza->format('Y-m-d'))->sort()->values()->all())
        ->toBe(['2026-02-01', '2026-02-05']);
});
