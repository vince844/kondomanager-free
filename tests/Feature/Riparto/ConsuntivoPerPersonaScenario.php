<?php

/**
 * Via Verdi per il consuntivo per persona: il caso e i suoi aiuti, condivisi dalle prove della porta del motore e del servizio.
 *
 * Esercizio 2026 (365 giorni): U1 di Neri, 500 millesimi; U2, 300 millesimi, di Bianchi fino al 30/06 e di Rossi dal 01/07 (181 e 184
 * giorni); U3 di Verdi, 200 millesimi; la tabella «Generali». Le spese si scrivono sul giornale come le scrive una fattura: DARE sulla
 * voce, AVERE sui debiti verso i fornitori (una nota di credito al contrario), con il legame alla fattura.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

/** Via Verdi: condominio, esercizio 2026, una gestione del tipo dato agganciata all'esercizio, la tabella «Generali». */
function cpmViaVerdi(string $tipo = 'ordinaria'): array
{
    static $seq = 0;
    $seq++;
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    // Una straordinaria fatta dalla pagina ha la data di fine (la pagina la chiede): qui l'opera finisce con l'esercizio, e il conguaglio la propone.
    $gestione = Gestione::create(['condominio_id' => $condominio->id, 'nome' => $tipo === 'ordinaria' ? 'Ordinaria 2026' : 'Facciata', 'tipo' => $tipo, 'descrizione' => 'Via Verdi']
        + ($tipo === 'straordinaria' ? ['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31'] : []));
    legaAEsercizio($esercizio, $gestione->id);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);

    $unita = [];
    foreach (['U1', 'U2', 'U3'] as $n) {
        $unita[$n] = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'codice_immobile' => "CPM{$seq}-{$n}", 'nome' => $n, 'interno' => $n]);
    }
    $persone = [];
    foreach ([['Neri', 'U1', '2020-01-01', null], ['Bianchi', 'U2', '2020-01-01', '2026-06-30'], ['Rossi', 'U2', '2026-07-01', null], ['Verdi', 'U3', '2020-01-01', null]] as [$nome, $u, $dal, $al]) {
        $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "{$seq}@test.it", 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'CPM' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $seq, 9, '0', STR_PAD_LEFT)]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s = compact('condominio', 'esercizio', 'gestione', 'pianoConto', 'unita', 'persone');
    $s['generali'] = cpmTabella($s, 'Generali', ['U1' => 500, 'U2' => 300, 'U3' => 200]);

    return $s;
}

/** @param array<string,float> $millesimi unità => valore */
function cpmTabella(array $s, string $nome, array $millesimi): Tabella
{
    $tabella = Tabella::create(['condominio_id' => $s['condominio']->id, 'nome' => $nome, 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach ($millesimi as $u => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita'][$u]->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $tabella;
}

/** @param list<array{0:Tabella, 1:float}> $tabelle tabella e coefficiente */
function cpmVoce(array $s, string $nome, array $tabelle, string $tipo = 'spesa', int $importo = 0, ?int $padre = null): Conto
{
    $conto = Conto::create(['piano_conto_id' => $s['pianoConto']->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre]);
    foreach ($tabelle as [$tabella, $coefficiente]) {
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => $coefficiente, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $conto;
}

function cpmPorta(array $s, array $cifre, array $opzioni = []): array
{
    return (new CalcoloQuoteService())->consuntivoPerGestione($s['gestione']->fresh(), $s['esercizio'], $cifre, $opzioni);
}

/** La spesa di una persona su una voce, sommata sulle sue unità; 0 se non ne ha. */
function cpmDi(array $esito, Anagrafica $persona, Conto $voce): int
{
    $totale = 0;
    foreach ($esito['persone'][$persona->id] ?? [] as $perVoce) {
        $totale += $perVoce[$voce->id] ?? 0;
    }

    return $totale;
}

/** La somma delle persone su una voce. */
function cpmSommaVoce(array $esito, Conto $voce): int
{
    $totale = 0;
    foreach ($esito['persone'] as $perUnita) {
        foreach ($perUnita as $perVoce) {
            $totale += $perVoce[$voce->id] ?? 0;
        }
    }

    return $totale;
}

/**
 * Un piano della gestione con le righe dei capitoli (`conto_id => importo`), nell'esercizio dato (quello di Via Verdi se nullo).
 * Restituisce il piano e la mappa `conto_id => id della riga`, quella che il servizio passa alla porta.
 *
 * @return array{0: PianoRate, 1: array<int,int>}
 */
function cpmPiano(array $s, array $righe, ?string $delibera = null, ?Esercizio $esercizio = null): array
{
    $piano = PianoRate::create(['gestione_id' => $s['gestione']->id, 'condominio_id' => $s['condominio']->id, 'nome' => 'Piano ' . (DB::table('piani_rate')->count() + 1), 'stato' => 'bozza',
        'tipo' => $s['gestione']->tipo === 'straordinaria' ? 'straordinario' : 'ordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => $delibera]);
    DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => ($esercizio ?? $s['esercizio'])->id]);
    $capitoli = [];
    foreach ($righe as $contoId => $importo) {
        $capitoli[$contoId] = DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $piano->id, 'conto_id' => $contoId, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$piano->fresh(), $capitoli];
}

/** Il preventivo del motore con il piano dato: le righe `riparto` per persona, unità e conto, e gli scoperti. */
function cpmPreventivo(array $s, PianoRate $piano): array
{
    $motore = new CalcoloQuoteService();
    $motore->calcolaPerGestione($s['gestione']->fresh(), $piano, true, app(CompetenzaDelPiano::class)->perPiano($piano, $s['esercizio']));
    $persone = [];
    foreach ($motore->getRigheDettaglio() as $riga) {
        if ($riga['tipo'] === 'riparto') {
            $persone[$riga['anagrafica_id']][$riga['immobile_id']][$riga['conto_id']] = ($persone[$riga['anagrafica_id']][$riga['immobile_id']][$riga['conto_id']] ?? 0) + $riga['importo'];
        }
    }
    $scoperti = [];
    foreach ($motore->getScoperti() as $sc) {
        $scoperti[$sc['conto_id']][$sc['motivo']] = ($scoperti[$sc['conto_id']][$sc['motivo']] ?? 0) + $sc['importo'];
    }

    return ['persone' => $persone, 'scoperti' => $scoperti];
}

/** I conti del giornale che le spese di Via Verdi usano: un costo e i debiti verso i fornitori, e un fornitore. */
function cpmGiornale(array $s): array
{
    $conti = [];
    foreach (['costi' => 'attivo', 'debiti_fornitori' => 'passivo'] as $ruolo => $tipo) {
        $conti[$ruolo] = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['condominio']->id, 'ruolo' => $ruolo === 'costi' ? null : $ruolo, 'codice' => strtoupper($ruolo) . $s['condominio']->id,
            'nome' => $ruolo, 'tipo' => $tipo, 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()]);
    }
    $conti['fornitore'] = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Impresa Facciate Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return $conti;
}

/**
 * Una spesa sul giornale dell'esercizio: positiva DARE sulla voce, negativa AVERE (una nota di credito). Con `$documento` nasce anche la
 * fattura (o la nota, con `rettifica` e `tipo => nota_credito`), con la sua competenza, legata alla scrittura. Restituisce l'id del
 * documento, o null.
 *
 * @param array{tipo?:string, rettifica?:?int, competenza_dal?:?string, competenza_al?:?string}|null $documento
 */
function cpmSpesa(array $s, array $giornale, Conto $voce, int $importo, ?array $documento = null): ?int
{
    static $n = 0;
    $n++;
    $fatturaId = null;
    if ($documento !== null) {
        $fatturaId = DB::table('fatture_passive')->insertGetId(['condominio_id' => $s['condominio']->id, 'esercizio_id' => $s['esercizio']->id, 'fornitore_id' => $giornale['fornitore'],
            'tipo_documento' => $documento['tipo'] ?? 'fattura', 'fattura_rettificata_id' => $documento['rettifica'] ?? null, 'numero_documento' => "CPM-{$n}", 'data_documento' => '2026-10-01',
            'data_scadenza' => '2026-10-31', 'importo_imponibile' => abs($importo), 'importo_iva' => 0, 'totale_documento' => abs($importo), 'netto_a_pagare' => abs($importo),
            'stato_approvazione' => 'approvata', 'competenza_dal' => $documento['competenza_dal'] ?? null, 'competenza_al' => $documento['competenza_al'] ?? null,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['condominio']->id, 'gestione_id' => $s['gestione']->id, 'esercizio_id' => $s['esercizio']->id,
        'data_registrazione' => '2026-10-01', 'data_competenza' => '2026-10-01', 'numero_protocollo' => "CPM-{$n}", 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    [$voceVerso, $debitiVerso] = $importo >= 0 ? ['dare', 'avere'] : ['avere', 'dare'];
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $scrittura, 'conto_contabile_id' => $giornale['costi'], 'voce_spesa_id' => $voce->id, 'tipo_riga' => $voceVerso, 'importo' => abs($importo), 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $scrittura, 'conto_contabile_id' => $giornale['debiti_fornitori'], 'voce_spesa_id' => null, 'tipo_riga' => $debitiVerso, 'importo' => abs($importo), 'created_at' => now(), 'updated_at' => now()],
    ]);
    if ($fatturaId !== null) {
        DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $fatturaId, 'scrittura_contabile_id' => $scrittura, 'importo_allocato' => abs($importo), 'tipo' => 'competenza', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $fatturaId;
}
