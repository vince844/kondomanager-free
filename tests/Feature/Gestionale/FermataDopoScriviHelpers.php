<?php

/**
 * 1.11.0-beta.50 — gli aiuti dei test della fermata dopo «Scrivi il conguaglio» (decisioni 77.3, 79 e 80).
 *
 * Incluso da:
 *   - tests/Feature/Gestionale/FermataDopoScriviTest.php
 *   - tests/Feature/Gestionale/InizioDopoScriviTest.php
 *
 * NON contiene nessun it() o test — solo funzioni di supporto, tutte con il prefisso `fds50` (i nomi delle funzioni di Pest
 * sono globali: `flf`, `ilpf`, `rlc` e gli altri prefissi dei file vicini restano liberi).
 *
 * Lo scenario è quello delle misure della .50 e di `FineLocazioneFrasiTest`: un'unità da 1000 millesimi su 1000, «Spese
 * generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo
 * 2026» in 12 rate mensili dal 5/1 (3000 a rata per l'inquilino, 7000 per il proprietario); Ines inquilina dal 2019. Il 2026
 * non è bisestile: 365 giorni.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Lo scenario: i titolari dati (nome, ruolo, quota) dal 2019, Ines inquilina al 100 % dal 2019, e le persone del condominio
 * che non stanno sull'unità (Elsa, Luca, Anna, Bruno). Il piano è approvato e **non generato**.
 *
 * @param list<array{0: string, 1: string, 2: float|int}> $titolari
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, piano: PianoRate, persone: array<string, Anagrafica>, conto: Conto}
 */
function fds50Scenario(array $titolari = [['Ugo', 'proprietario', 100]]): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "FDS50-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    // I conti per emettere, come in `InizioLocazionePianoFermoTest`.
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);

    $persone = [];
    $nuova = function (string $nome) use (&$persone, $c, $seq): Anagrafica {
        if (! isset($persone[$nome])) {
            $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-fds{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 4), 4, 'X')) . 'FDS' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT) . str_pad((string) count($persone), 4, '0', STR_PAD_LEFT)]);
            $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        }

        return $persone[$nome];
    };
    foreach ([...$titolari, ['Ines', 'inquilino', 100]] as [$nome, $ruolo, $quota]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nuova($nome)->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['Elsa', 'Luca', 'Anna', 'Bruno'] as $nome) {
        $nuova($nome);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);

    return compact('c', 'e', 'g', 'unita', 'piano', 'persone', 'conto');
}

/** La generazione del piano, con la presa d'atto del cancello come la dà l'amministratore. */
function fds50Genera(array $s, ?PianoRate $piano = null): void
{
    app(GeneratePianoRateAction::class)->execute(($piano ?? $s['piano'])->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
}

/** L'emissione vera, dalla rotta della pagina del piano, delle rate con scadenza fino al giorno dato. */
function fds50Emetti($test, array $s, string $fino, ?PianoRate $piano = null): void
{
    $piano ??= $s['piano'];
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', 'bozza')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
        'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
}

/** La riga aperta della persona con quel ruolo sull'unità. */
function fds50Riga(array $s, string $nome, string $ruolo): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone'][$nome]->id)
        ->where('tipologia', $ruolo)->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/** L'anteprima dalla rotta vera; la risposta deve essere 200, e se non lo è il messaggio dice perché. */
function fds50Anteprima($test, array $s, array $dati): array
{
    $r = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
    expect($r->status())->toBe(200, sprintf('L\'anteprima del passaggio «%s» non è accettata (%d): %s', $dati['tipo'], $r->status(), json_encode($r->json('errors') ?? $r->json('message'), JSON_UNESCAPED_UNICODE)));

    return $r->json();
}

/**
 * Il passaggio dalle rotte vere: l'anteprima, poi la registrazione con la presa d'atto solo se il pannello la chiede e con
 * l'impronta delle voci che il pannello ha mostrato (costituzione e riserva). Restituisce l'anteprima e il passaggio registrato.
 *
 * @return array{anteprima: array, subentro: Subentro}
 */
function fds50Registra($test, array $s, array $dati): array
{
    $anteprima = fds50Anteprima($test, $s, $dati);
    if ($anteprima['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Atto letto, pannello controllato riga per riga'];
    }
    if (($anteprima['ordinaria']['impronta'] ?? null) !== null) {
        $dati += ['ordinaria_impronta' => $anteprima['ordinaria']['impronta']];
    }
    $r = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
    // Registrato, la rotta rimanda alla pagina (302, niente JSON): il messaggio si costruisce solo sul rifiuto.
    if ($r->status() >= 400) {
        expect($r->status())->toBeLessThan(400, sprintf('La registrazione del passaggio «%s» è rifiutata (%d): %s', $dati['tipo'], $r->status(), json_encode($r->json('errors') ?? $r->json('message'), JSON_UNESCAPED_UNICODE)));
    }

    return ['anteprima' => $anteprima, 'subentro' => Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->whereNull('annullato_il')->latest('id')->firstOrFail()];
}

/** Il modulo della fine locazione di chi esce, senza nuovo inquilino, con la scelta della decisione 77 (`scrivi` / `non_scrivere`). */
function fds50ModuloFine(array $s, ?string $scelta, string $il = '2026-07-01', string $chi = 'Ines'): array
{
    return ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => fds50Riga($s, $chi, 'inquilino'), 'anagrafica_entrante_id' => null,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => []]
        + ($scelta !== null ? ['conguaglio' => $scelta] : []);
}

/**
 * La fine locazione di Ines il 1/7 con la scelta data, registrata. Controlla che la scelta resti nel registro (decisione 77,
 * campo `conguaglio` come nella successione della .48): senza, ogni cifra dopo misurerebbe un'altra cosa.
 */
function fds50Fine($test, array $s, string $scelta, string $il = '2026-07-01'): Subentro
{
    $fine = fds50Registra($test, $s, fds50ModuloFine($s, $scelta, $il))['subentro'];
    expect($fine->registro['conguaglio']['scelta'] ?? null)->toBe($scelta === Subentro::SCRIVI_IL_CONGUAGLIO ? Subentro::CONGUAGLIO_SCRITTO : Subentro::CONGUAGLIO_NON_SCRITTO);

    return $fine;
}

/**
 * Il modulo della vendita di tutta la quota di chi vende (decisione 37: il passaggio porta tutta la quota di chi esce, quindi
 * la quota del modulo è quella della sua riga: 100 sull'unità piena, 50 per un comproprietario al 50 %).
 */
function fds50ModuloVendita(array $s, string $vende, string $compra, string $il, float|int $quota = 100): array
{
    return ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, $vende, 'proprietario'), 'anagrafica_entrante_id' => $s['persone'][$compra]->id,
        'decorrenza' => $il, 'quota' => $quota, 'copia_autentica' => false, 'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []];
}

/** La vendita della nuda con riserva d'usufrutto: chi vende resta usufruttuario; l'ordinaria come dice la legge (decisione 31.5). */
function fds50ModuloRiserva(array $s, string $vende, string $compra, string $il): array
{
    return ['tipo' => 'vendita', 'sottotipo' => Subentro::RISERVA_USUFRUTTO, 'tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, $vende, 'proprietario'),
        'anagrafica_entrante_id' => $s['persone'][$compra]->id, 'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'rogito notaio Verdi, rep. 4322',
        'pertinenze' => [], 'ordinaria_dopo_atto' => Subentro::ORDINARIA_ALL_USUFRUTTUARIO, 'voci_da_tenere' => []];
}

/** La costituzione d'usufrutto a favore di chi entra; l'ordinaria come dice la legge (decisione 31.5). */
function fds50ModuloCostituzione(array $s, string $da, string $a, string $il): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario', 'riga_uscente_id' => fds50Riga($s, $da, 'proprietario'),
        'anagrafica_entrante_id' => $s['persone'][$a]->id, 'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [],
        'ordinaria_dopo_atto' => Subentro::ORDINARIA_ALL_USUFRUTTUARIO, 'voci_da_tenere' => []];
}

/** L'estinzione dell'usufrutto di chi esce: il nudo proprietario torna pieno. */
function fds50ModuloEstinzione(array $s, string $usufruttuario, string $il): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, $usufruttuario, 'usufruttuario'),
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => []];
}

/** La successione di Ugo ad Anna e Bruno al 50 %, Anna erede di riferimento; con l'arretrato al defunto anche la scelta del conguaglio. */
function fds50ModuloSuccessione(array $s, string $arretrato, ?string $conguaglio, string $il): array
{
    return ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'proprietario'), 'decorrenza' => $il, 'quota' => 100,
        'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => 50]],
        'arretrato' => $arretrato, 'erede_di_riferimento' => $s['persone']['Anna']->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 77', 'pertinenze' => []]
        + ($conguaglio !== null ? ['conguaglio' => $conguaglio] : []);
}

/** L'inizio di una locazione di chi entra, al 100 %. */
function fds50ModuloInizio(array $s, string $chi, string $il): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => 100,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
}

/**
 * Quanto paga ogni persona sull'unità e sulla gestione, per nome: le quote pure del piano (senza i saldi pregressi) più tutte le
 * righe di saldo con una persona — le coppie dei passaggi e i saldi scritti a mano dal Wallet. Senza gli zeri. Centesimi, segno
 * positivo = debito.
 *
 * @return array<string, int>
 */
function fds50Netti(array $s): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
        ->where('piani_rate.gestione_id', $s['g']->id)->where('rate_quote.immobile_id', $s['unita']->id)->where('rate_quote.stato', '!=', 'annullata')
        ->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $chi = $nomi[(int) $q->anagrafica_id] ?? "anagrafica {$q->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) (json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? 0);
    }
    foreach (DB::table('saldi')->whereNotNull('anagrafica_id')->where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']->id)->get(['anagrafica_id', 'saldo_iniziale']) as $x) {
        $chi = $nomi[(int) $x->anagrafica_id] ?? "anagrafica {$x->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $x->saldo_iniziale;
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/**
 * Le righe di saldo di un passaggio (le sue coppie), per nome: [nome => importo]. Le righe della stessa persona si sommano (con
 * due controparti chi esce ha una riga per ciascuna: prima l'ultima copriva le altre).
 */
function fds50Coppia(array $s, Subentro $passaggio): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();
    $out = [];
    foreach (DB::table('saldi')->where('subentro_id', $passaggio->id)->get(['anagrafica_id', 'saldo_iniziale']) as $x) {
        $chi = $nomi[(int) $x->anagrafica_id] ?? "anagrafica {$x->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $x->saldo_iniziale;
    }
    ksort($out);

    return $out;
}

/** Tutte le stringhe di una risposta (anteprima, voce dello storico), in un elenco piatto: le frasi, i motivi, le informazioni. */
function fds50Testi(array $dati): array
{
    $out = [];
    array_walk_recursive($dati, function ($v) use (&$out) {
        if (is_string($v) && $v !== '') {
            $out[] = $v;
        }
    });

    return $out;
}

/** Le stringhe che contengono il frammento dato (la cifra di una fermata, di solito). */
function fds50Con(array $dati, string $frammento): array
{
    return array_values(array_filter(fds50Testi($dati), fn (string $t) => str_contains($t, $frammento)));
}

/** Le fermate dopo «Scrivi» che il registro del passaggio conserva (chiave scelta nelle misure della .50). */
function fds50Fermate(Subentro $passaggio): array
{
    return Subentro::conAnnullati()->findOrFail($passaggio->id)->registro['fermata_dopo_scrivi'] ?? [];
}

/** La voce del passaggio nello storico dell'unità, come la calcola il server per la pagina. */
function fds50Storico(array $s, Subentro $passaggio): array
{
    return collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id) ?? [];
}
