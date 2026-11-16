<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il fascicolo per il legale (decisione 382 (2), progetto D2 § 2.5): un archivio .zip per una persona con l'indice in PDF, il prospetto delle rate non versate,
 * gli estratti conto, le lettere conservate con le prove, il rendiconto approvato con il verbale e la scansione dell'accordo. L'indice dice che cosa c'è e che
 * cosa manca, mai che basta. Qui: i nomi nell'archivio (due diffide nello stesso giorno sono due file), l'indice con le due cifre e le loro etichette, i quattro
 * stati delle impronte, ogni frase di «Che cosa manca» nel suo caso, i fatti, la frase in fondo, le parole, il 404 per la persona di un altro condominio, la riga
 * `fascicolo` nel registro della persona con l'indice e il prospetto conservati (fuori dall'archivio e dal portale), l'anteprima che non scrive niente, nessun
 * file nella cartella temporanea, e la riga del riquadro dei sei mesi dopo.
 */

use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/FascicoloLegaleScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

const FSL_PAROLE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut', 'termine scaduto',
    'consegnata', 'recapitata', 'notificata', 'valore legale', 'transazione', 'errore', 'in ritardo', 'deve agire contro', 'basta per agire.'];

function fslTemporanei(): array
{
    return array_merge(glob(sys_get_temp_dir() . '/km_pezzo_*') ?: [], glob(sys_get_temp_dir() . '/km_archivio_*') ?: []);
}

/** Le frasi di un elenco dell'anteprima (i fatti, le mancanze). */
function fslFrasi(array $anteprima, string $chiave): array
{
    return array_column($anteprima[$chiave], 'frase');
}

it('i nomi nell\'archivio: il numero della parte, il tipo, il giorno, l\'ora e la riga del registro delle lettere; le prove con il loro numero', function () {
    $s = fslScenario($this);
    $diffida = fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    $prova = fslProva($diffida, 'cartolina.pdf', 'la cartolina firmata');
    $sollecito = fslLettera($s, 'rossi', 'sollecito', 'inviata', '2026-02-01 09:00', [1, 2, 3]);
    fslAccordo($s, 'rossi', [3]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA);

    [$r, $file] = fslScarica($this, $s, 'rossi');

    expect($r->getStatusCode())->toBe(200)
        ->and($r->headers->get('Content-Type'))->toBe('application/zip')
        ->and(array_keys($file))->toBe([
            '00 Indice.pdf',
            '01 Rate non versate.pdf',
            '02 Estratto conto 2026.pdf',
            "03 Diffida 2026-03-02 1015 r{$diffida->id} - prova 1.pdf",
            "03 Diffida 2026-03-02 1015 r{$diffida->id}.pdf",
            "03 Sollecito 2026-02-01 0900 r{$sollecito->id}.pdf",
            '04 Rendiconto 2025 - verbale.pdf',
            '04 Rendiconto 2025 approvato.pdf',
            '05 Accordo di rientro 2026-01-10.pdf',
        ])
        ->and($file["03 Diffida 2026-03-02 1015 r{$diffida->id}.pdf"])->toBe(fslPdf('diffida 2026-03-02 10:15 rossi'))
        ->and($file["03 Diffida 2026-03-02 1015 r{$diffida->id} - prova 1.pdf"])->toBe('la cartolina firmata')
        ->and($prova->documentable_id)->toBe($diffida->id);
});

it('due diffide nello stesso giorno, alla stessa ora (stampata e rifatta), sono due file', function () {
    $s = fslScenario($this);
    $prima = fslLettera($s, 'rossi', 'diffida', 'stampata', '2026-03-02 10:15', [1, 2], ['pdf' => fslPdf('la prima')]);
    $seconda = fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2], ['pdf' => fslPdf('la seconda')]);

    [, $file] = fslScarica($this, $s, 'rossi');

    expect($file["03 Diffida 2026-03-02 1015 r{$prima->id}.pdf"])->toBe(fslPdf('la prima'))
        ->and($file["03 Diffida 2026-03-02 1015 r{$seconda->id}.pdf"])->toBe(fslPdf('la seconda'));
});

it('l\'indice: in testa il condominio, la persona, il giorno e chi l\'ha preparato; le due cifre con le loro etichette; l\'elenco dei file; la frase in fondo', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);

    [, $file] = fslScarica($this, $s, 'rossi');
    $indice = fslTesto($file['00 Indice.pdf']);

    expect($indice)->toContain('Fascicolo per il legale')
        ->toContain('Via Verdi 12')
        ->toContain('Rossi')
        ->toContain('Preparato il 20/05/2026 da Vincenzo')
        ->toContain('Le rate scadute in questo fascicolo, al 20/05/2026: € 500,00')
        ->toContain('di cui dell\'esercizio 2025: € 200,00 (data dei sei mesi: 30/06/2026)')
        ->toContain('di cui dell\'esercizio 2026: € 300,00 (data dei sei mesi: 30/06/2027)')
        ->toContain('01 Rate non versate.pdf')
        ->toContain('02 Estratto conto 2026.pdf')
        ->toContain('Il saldo dell\'estratto conto comprende anche le rate non ancora scadute e il credito: non è lo scaduto.')
        ->toContain('Questo indice dice quali documenti lo studio conserva per questa persona e quali mancano. Se bastano per agire lo valuta il legale.')
        ->not->toContain('programma')
        ->toContain('Contiene dati personali di una persona: si consegna a chi la segue per il condominio.');
});

it('i quattro stati delle impronte: uguale, diversa, il file che non c\'è più, nessuna impronta conservata', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);
    $uguale = fslLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-02 09:00', [1, 2]);
    $diversa = fslLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-12 09:00', [1, 2], ['diverso' => true]);
    $sparita = fslLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-22 09:00', [1, 2], ['sparito' => true]);
    $senza = fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2], ['impronta' => false]);

    $a = fslAnteprima($this, $s, 'rossi');
    $stati = collect($a['pezzi'])->filter(fn ($p) => $p['tipo'] === 'lettera')->mapWithKeys(fn ($p) => [$p['invio_id'] => $p['impronta']])->all();

    expect($stati[$uguale->id]['stato'])->toBe('uguale')
        ->and($stati[$uguale->id]['frase'])->toBe('Il file è quello conservato il 02/02/2026: l\'impronta è la stessa.')
        ->and($stati[$uguale->id]['cifre'])->toBe(substr(hash('sha256', fslPdf('sollecito 2026-02-02 09:00 rossi')), 0, 12))
        ->and($stati[$diversa->id]['stato'])->toBe('diversa')
        ->and($stati[$diversa->id]['frase'])->toBe('Il file non è quello conservato il 12/02/2026: la sua impronta non è quella scritta nel registro.')
        ->and($stati[$sparita->id]['stato'])->toBe('sparito')
        ->and($stati[$sparita->id]['frase'])->toBe('Il file non c\'è più sul disco dei documenti: resta la riga del registro.')
        ->and($stati[$senza->id]['stato'])->toBe('senza')
        ->and($stati[$senza->id]['frase'])->toBe('Nessuna impronta conservata: il file non si può confrontare.');

    [, $file] = fslScarica($this, $s, 'rossi');
    $indice = fslTesto($file['00 Indice.pdf']);
    // Il file che non c'è più non entra nell'archivio; gli altri sì, anche quello diverso (è quello che c'è sul disco), con la sua frase.
    expect(array_keys($file))->not->toContain("03 Sollecito 2026-02-22 0900 r{$sparita->id}.pdf")
        ->toContain("03 Sollecito 2026-02-12 0900 r{$diversa->id}.pdf")
        ->and($indice)->toContain('Il file è quello conservato il 02/02/2026: l\'impronta è la stessa.')
        ->toContain('Il file non è quello conservato il 12/02/2026: la sua impronta non è quella scritta nel registro.')
        ->toContain('Il file non c\'è più sul disco dei documenti: resta la riga del registro.')
        ->toContain('Nessuna impronta conservata: il file non si può confrontare.');
});

it('«Che cosa manca»: ogni frase nel suo caso, con dove si aggiunge', function () {
    $s = fslScenario($this);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    fslLettera($s, 'rossi', 'sollecito', 'stampata', '2026-04-10 09:00', [1, 2, 3, 4, 5]);
    fslLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-12 09:00', [1, 2], ['sparito' => true]);
    $conProva = fslLettera($s, 'rossi', 'sollecito', 'spedita', '2026-02-02 09:00', [1, 2]);
    fslProva($conProva, 'cartolina.pdf', null);

    $mancanze = fslFrasi(fslAnteprima($this, $s, 'rossi'), 'mancanze');

    expect($mancanze)->toContain('La diffida del 02/03/2026 non ha una prova di spedizione: allegala dal registro delle lettere, nell\'estratto conto della persona.')
        ->toContain('Il sollecito del 10/04/2026 è stampato, ma la spedizione non è registrata.')
        ->toContain('Il file del PDF del sollecito del 12/02/2026 non c\'è più sul disco dei documenti: resta la riga del registro.')
        ->toContain('Il file della prova «cartolina.pdf» del sollecito del 02/02/2026 non c\'è più sul disco dei documenti.')
        // Il piano senza la data della delibera, e il rendiconto dell'esercizio 2025 (finito, delle rate 1 e 2) che è ancora una bozza.
        ->toContain('Il piano «Preventivo 2026» non ha la data della delibera: scrivila nel piano rate.')
        ->toContain('Il rendiconto dell\'esercizio 2025 non è approvato nel programma: è ancora una bozza.');

    // I collegamenti: la prova si allega dall'estratto conto della persona, il piano rate dalla sua pagina.
    $a = fslAnteprima($this, $s, 'rossi');
    $prova = collect($a['mancanze'])->firstWhere('frase', 'La diffida del 02/03/2026 non ha una prova di spedizione: allegala dal registro delle lettere, nell\'estratto conto della persona.');
    expect($prova['azione']['etichetta'])->toBe('Allega la prova')
        ->and($prova['azione']['url'])->toContain('/anagrafiche/' . $s['persone']['rossi']->id . '/estratto-conto')->toContain('scheda=corrispondenza')
        ->and(collect($a['mancanze'])->firstWhere('frase', 'Il piano «Preventivo 2026» non ha la data della delibera: scrivila nel piano rate.')['azione']['etichetta'])
        ->toBe('Apri il piano rate');
});

it('«Che cosa manca» del rendiconto: fermato, approvato senza verbale, approvato senza il PDF; per un esercizio non finito non si cerca', function () {
    $s = fslScenario($this);
    $v = fslRendiconto($s, $s['e2025'], RendicontoVersione::PRESENTATA);
    expect(fslFrasi(fslAnteprima($this, $s, 'rossi'), 'mancanze'))
        ->toContain('Il rendiconto dell\'esercizio 2025 non è approvato nel programma: c\'è la versione fermata per l\'assemblea il 10/04/2026.');

    $v->update(['stato' => RendicontoVersione::SOSTITUITA]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA, ['verbale' => null]);
    $a = fslAnteprima($this, $s, 'rossi');
    expect(fslFrasi($a, 'mancanze'))->toContain('Il rendiconto dell\'esercizio 2025 è approvato il 20/04/2026, ma il verbale non è caricato.')
        ->and(fslFrasi($a, 'fatti'))->toContain('Esercizio 2026: non ancora finito, non ha un rendiconto.')
        ->and(implode(' ', fslFrasi($a, 'mancanze')))->not->toContain('esercizio 2026');

    RendicontoVersione::where('esercizio_id', $s['e2025']->id)->update(['stato' => RendicontoVersione::SOSTITUITA]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA, ['pdf' => null]);
    expect(fslFrasi(fslAnteprima($this, $s, 'rossi'), 'mancanze'))
        ->toContain('Il PDF del rendiconto 2025 approvato non è conservato: caricalo dalla Sintesi del rendiconto.');
});

it('i fatti: la delibera del piano, «Nessuna lettera a questa persona nel registro.» (non è una mancanza), la riga sull\'estratto conto', function () {
    $s = fslScenario($this);
    $s['piano']->update(['data_delibera_assemblea' => '2025-12-12', 'numero_verbale' => '4']);

    $a = fslAnteprima($this, $s, 'rossi');

    expect(fslFrasi($a, 'fatti'))->toContain('Nessuna lettera a questa persona nel registro.')
        ->toContain('Piano «Preventivo 2026»: delibera del 12/12/2025 (verbale n. 4).')
        ->toContain('Il saldo dell\'estratto conto comprende anche le rate non ancora scadute e il credito: non è lo scaduto.')
        ->and(implode(' ', fslFrasi($a, 'mancanze')))->not->toContain('Nessuna lettera')->not->toContain('delibera');
});

it('nessuna parola vietata nell\'anteprima e nell\'indice, e mai che il fascicolo basta per agire', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);
    $d = fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    fslProva($d, 'cartolina.pdf', null);
    fslLettera($s, 'rossi', 'sollecito', 'stampata', '2026-04-10 09:00', [1, 2]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA, ['verbale' => null]);

    $testo = mb_strtolower(json_encode(fslAnteprima($this, $s, 'rossi'), JSON_UNESCAPED_UNICODE) . ' ' . fslTesto(fslScarica($this, $s, 'rossi')[1]['00 Indice.pdf']));

    expect(array_values(array_filter(FSL_PAROLE, fn ($p) => str_contains(str_replace('se basta per agire lo valuta il legale.', ' ', $testo), $p))))->toBe([]);
});

it('la persona di un altro condominio: 404 per l\'anteprima, per il fascicolo e per i suoi documenti', function () {
    $s = fslScenario($this);
    $altro = smaViaVerdi($this);
    $estranea = $altro['persone']['rossi'];
    $rotta = fn (string $nome) => route('admin.gestionale.' . $nome, ['condominio' => $s['c']->id, 'anagrafica' => $estranea->id]);

    $this->actingAs($this->user)->getJson($rotta('anagrafiche.fascicolo-legale'))->assertNotFound();
    $this->actingAs($this->user)->post($rotta('anagrafiche.fascicolo-legale.prepara'), ['rate' => fslChiavi($altro, 'rossi', [1]), 'parti' => []])->assertNotFound();

    // Un documento del fascicolo di una persona, chiesto con un'altra persona o un altro condominio: 404.
    fslScarica($this, $altro, 'rossi');
    $documento = Documento::where('documentable_type', RispostaPromemoria::class)->firstOrFail();
    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.fascicolo-legale.documento',
        ['condominio' => $s['c']->id, 'anagrafica' => $s['persone']['rossi']->id, 'documento' => $documento->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.fascicolo-legale.documento',
        ['condominio' => $altro['c']->id, 'anagrafica' => $altro['persone']['bianchi']->id, 'documento' => $documento->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.fascicolo-legale.documento',
        ['condominio' => $altro['c']->id, 'anagrafica' => $estranea->id, 'documento' => $documento->id]))->assertOk();
});

it('a ogni «Scarica il fascicolo» una riga `fascicolo` nel registro della persona, con l\'indice e il prospetto conservati fuori dall\'archivio e dal portale', function () {
    $s = fslScenario($this);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    $nellArchivio = Documento::whereNull('documentable_type')->count();

    [, $file] = fslScarica($this, $s, 'rossi');
    $this->travelTo('2026-05-21 10:00:00');
    fslScarica($this, $s, 'rossi');

    $riga = RispostaPromemoria::where('argomento', 'persona')->where('anagrafica_id', $s['persone']['rossi']->id)->sole();
    $documenti = Documento::where('documentable_type', RispostaPromemoria::class)->where('documentable_id', $riga->id)->orderBy('id')->get();
    $ultima = collect($riga->registro)->last();

    expect($riga->chiave)->toBe('persona|' . $s['c']->id . '|-|' . $s['persone']['rossi']->id)
        ->and($riga->stato)->toBeNull()
        ->and($riga->giorno->format('Y-m-d'))->toBe('2026-05-21')
        ->and($riga->registro)->toHaveCount(2)
        ->and($ultima['tipo'])->toBe('fascicolo')
        ->and($ultima['utente'])->toBe('Vincenzo')
        ->and($ultima['dati']['cifra'])->toBe(50000)
        ->and($ultima['dati']['rate'])->toHaveCount(5)
        ->and(array_column($ultima['dati']['file'], 'nome'))->toContain('00 Indice.pdf')->toContain('01 Rate non versate.pdf')
        ->and(collect($ultima['dati']['file'])->firstWhere('tipo', 'lettera')['impronta'])->toBe('uguale')
        ->and($ultima['dati']['mancanze'])->toContain('La diffida del 02/03/2026 non ha una prova di spedizione: allegala dal registro delle lettere, nell\'estratto conto della persona.')
        ->and($ultima['dati']['tolti'])->toBe(['rate' => [], 'parti' => []])
        ->and($documenti)->toHaveCount(4)
        ->and($documenti->every(fn ($d) => ! $d->is_published))->toBeTrue()
        ->and(Documento::whereNull('documentable_type')->count())->toBe($nellArchivio)
        ->and(DB::table('anagrafica_documento')->whereIn('documento_id', $documenti->pluck('id'))->count())->toBe(0)
        ->and(DB::table('condominio_documento')->whereIn('documento_id', $documenti->pluck('id'))->count())->toBe(0);

    // I due documenti del primo fascicolo sono i file dell'archivio di quel giorno.
    $primi = $documenti->take(2)->values();
    expect(hash('sha256', Storage::disk('local')->get($primi[0]->path)))->toBe(hash('sha256', $file['00 Indice.pdf']))
        ->and(hash('sha256', Storage::disk('local')->get($primi[1]->path)))->toBe(hash('sha256', $file['01 Rate non versate.pdf']));

    // Si riaprono dalla pagina della persona.
    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.fascicolo-legale.documento',
        ['condominio' => $s['c']->id, 'anagrafica' => $s['persone']['rossi']->id, 'documento' => $primi[0]->id]))->assertOk();

    // L'anteprima dice l'ultimo fascicolo.
    expect(fslAnteprima($this, $s, 'rossi')['ultimo'])->toBe(['giorno' => '2026-05-21', 'utente' => 'Vincenzo']);
});

it('l\'anteprima non scrive niente', function () {
    $s = fslScenario($this);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    $conta = fn () => [DB::table('risposte_promemoria')->count(), DB::table('documenti')->count(), DB::table('registro_invii')->count()];
    $prima = $conta();

    fslAnteprima($this, $s, 'rossi');

    expect($conta())->toBe($prima);
});

it('nessun file nella cartella temporanea dopo lo scaricamento, e dopo un problema a metà (niente nel registro, niente fra i documenti)', function () {
    $s = fslScenario($this);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    $prima = fslTemporanei();

    fslScarica($this, $s, 'rossi');
    expect(array_values(array_diff(fslTemporanei(), $prima)))->toBe([]);

    $documenti = DB::table('documenti')->count();
    $registro = RispostaPromemoria::count();
    $this->app->instance(\App\Services\PDF\PdfService::class, new class extends \App\Services\PDF\PdfService {
        private int $fatti = 0;

        public function __construct() {}

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            if (++$this->fatti === 2) {
                throw new \RuntimeException('il secondo PDF non si fa');
            }

            return parent::generate($view, $data, $config);
        }
    });
    $r = $this->actingAs($this->user)->post(fslRotta($s, 'rossi', 'anagrafiche.fascicolo-legale.prepara'),
        ['rate' => fslChiavi($s, 'rossi', [1, 2, 3, 4, 5]), 'parti' => ['lettere', 'estratti', 'rendiconto']]);

    expect($r->getStatusCode())->toBe(500)
        ->and(array_values(array_diff(fslTemporanei(), $prima)))->toBe([])
        ->and(DB::table('documenti')->count())->toBe($documenti)
        ->and(RispostaPromemoria::count())->toBe($registro);
});

it('nel riquadro dei sei mesi: la diffida con il termine passato suggerisce il fascicolo; preparato, «Fascicolo preparato il …» e «Registra che hai agito»', function () {
    $s = fslScenario($this);
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-03-01', [1, 2, 3, 4, 5]);
    $bianchi = fn () => smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), 'Bianchi')['passo'];
    expect($bianchi()['caso'])->toBe('R9')->and($bianchi()['passo'])->toBe('fascicolo');

    fslScarica($this, $s, 'bianchi');

    expect($bianchi()['caso'])->toBe('R6')
        ->and($bianchi()['passo'])->toBe('agito')
        ->and($bianchi()['frase'])->toBe('Fascicolo preparato il 20/05/2026.');

    // Una lettera dopo il fascicolo lo supera: si torna alla regola delle lettere.
    $this->travelTo('2026-05-22 10:00:00');
    smaLettera($s, 'bianchi', 'diffida', 'spedita', '2026-05-22', [1, 2, 3, 4, 5]);
    expect($bianchi()['caso'])->not->toBe('R6');
});
