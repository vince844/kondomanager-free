<?php

/**
 * Cosa resta scoperto: le gestioni straordinarie (una voce di entrata non si associa a una gestione straordinaria dall'interfaccia) e i passaggi
 * diversi dalla riserva e dalla costituzione dell'usufrutto: nessuno scenario di questo file li costruisce.
 *
 * Gli scenari dell'art. 984 c.c. e delle voci di entrata sull'unità in usufrutto, condivisi dai file `Beta52Usufrutto*Test.php`.
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie. Lo caricano con `require_once` i file che lo usano, così
 * ognuno passa anche lanciato da solo. Le funzioni hanno il prefisso `b52Us`: i nomi delle funzioni di Pest sono globali.
 *
 * Si appoggia agli aiuti che la suite ha già (`ru*`, `fds50*`, `b51a*`).
 *
 * LO SCENARIO DEL PANNELLO (`b52UsScenarioPannello`): Ugo proprietario al 100 %, «Spese generali» € 1.200,00 (120000) sulla tabella
 * «Proprietà» e «Affitto antenna» € 240,00 (24000, entrata), tutte e due sul «Proprietario», il piano in bozza; la costituzione o la riserva
 * dell'usufrutto è il 1/5/2026.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

/** La tabella «Proprietà» dello scenario: quella su cui sta la voce di spesa. */
function b52UsTabella(array $s): int
{
    return (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->value('id');
}

/** Una voce sulla tabella data (di partenza «Proprietà») con le sue ripartizioni per soggetto; restituisce l'associazione voce × tabella. */
function b52UsVoce(array $s, string $nome, string $tipo, int $importo, array $ripartizioni, ?int $tabellaId = null): int
{
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabellaId ?? b52UsTabella($s), 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizioni as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $ctm;
}

/** Lo scenario del pannello: Ugo proprietario, «Spese generali» € 1.200,00 e «Affitto antenna» € 240,00 (entrata) sul «Proprietario», il piano in bozza. */
function b52UsScenarioPannello(array $ripartizioneEntrata = ['proprietario' => 100]): array
{
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $s['ctmEntrata'] = b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, $ripartizioneEntrata);

    return $s;
}

/** L'associazione della voce di spesa «Spese generali» dello scenario. */
function b52UsAssociazioneSpese(array $s): int
{
    return (int) DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')->where('conti.nome', 'Spese generali')
        ->where('conti.piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->value('conto_tabella_millesimale.id');
}

/** Il totale dell'anno per persona (centesimi), dalle quote del piano: negativo = credito. */
function b52UsPerPersona(array $s): array
{
    $nomi = Anagrafica::pluck('nome', 'id');
    $per = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.importo'])->groupBy('anagrafica_id')
        ->map(fn ($q) => (int) $q->sum('importo'))->mapWithKeys(fn ($v, $k) => [$nomi[$k] ?? $k => $v])->all();
    ksort($per);

    return $per;
}

/** Le righe di titolarità dell'unità del piano: [nome, ruolo, quota, dal, al] per ognuna; le persone non ancora create si creano. */
function b52UsTitolari(array $s, array $righe): void
{
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->delete();
    foreach ($righe as [$nome, $ruolo, $quota, $dal, $al]) {
        $p = $s['persone'][$nome] ?? null;
        if ($p === null) {
            $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-b52us@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 4), 4, 'X')) . 'B52US' . random_int(1000000, 9999999)]);
            $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        }
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
}

/** Il piano di un'unità con la voce di spesa e quella di entrata, ripartite come dato, generato; restituisce i totali per persona. */
function b52UsMisura(array $titolari, array $ripSpesa, array $ripEntrata): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('tipologia', 'inquilino')->delete();
    b52UsTitolari($s, $titolari);
    $ctmSpese = (int) DB::table('conto_tabella_millesimale')->where('conto_id', $s['conto']->id)->value('id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctmSpese)->delete();
    foreach ($ripSpesa as $soggetto => $perc) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctmSpese, 'soggetto' => $soggetto, 'percentuale' => $perc, 'created_at' => now(), 'updated_at' => now()]);
    }
    $ctmEntrata = b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, $ripEntrata);
    $s['piano']->capitoli()->syncWithoutDetaching([$s['conto']->id => ['importo' => 120000], (int) DB::table('conto_tabella_millesimale')->where('id', $ctmEntrata)->value('conto_id') => ['importo' => 24000]]);
    fds50Genera($s);

    return b52UsPerPersona($s);
}

/** Un'altra unità della tabella data, in usufrutto (usufruttuario e nudo proprietario al 100 %) dal 2019, con il valore dato sulla tabella. */
function b52UsAltraUnita(array $s, int $tabellaId, string $nome, string $usufruttuario, string $nudo, int $valore = 500): Immobile
{
    static $n = 0;
    $n++;
    $u = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "B52US-{$n}", 'nome' => $nome, 'interno' => (string) ($n + 1)]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $u->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['usufruttuario' => $usufruttuario, 'nuda_proprietario' => $nudo] as $ruolo => $persona) {
        $p = Anagrafica::forceCreate(['nome' => $persona, 'email' => "b52us-{$ruolo}-{$u->id}@test.it", 'indirizzo' => 'Via Roma 2',
            'codice_fiscale' => strtoupper(substr($ruolo, 0, 6)) . 'B52US' . str_pad((string) $u->id, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $u;
}

/** Una seconda tabella, «Box», con l'unità del passaggio al valore dato. */
function b52UsSecondaTabella(array $s, int $valore = 500): int
{
    $tabella = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita']->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);

    return (int) $tabella->id;
}

/** Le frasi dell'ordinaria nell'anteprima di una riserva d'usufrutto (o del passaggio dato), una per riga. */
function b52UsFrasi($test, array $s, ?array $dati = null): array
{
    return ruAnteprima($test, $s, $dati ?? ruRiserva($s))['ordinaria']['frasi'];
}

/** Ogni frase attesa è fra le frasi del pannello, intera e non un pezzo; il messaggio dice quale manca e quali ci sono. */
function b52UsDicono(array $frasi, string ...$attese): void
{
    foreach ($attese as $attesa) {
        expect(in_array($attesa, $frasi, true))->toBeTrue('Manca la frase «' . $attesa . "» fra:\n" . implode("\n", $frasi));
    }
}

/** Il modulo della costituzione dell'usufrutto a favore di Elsa. */
function b52UsCostituzione(array $s, array $extra = []): array
{
    return array_merge(['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta',
        'ordinaria_dopo_atto' => 'usufruttuario'], $extra);
}

/**
 * Ugo costituisce l'usufrutto a Elsa il 1/2 con la scelta data, l'usufrutto si estingue il 1/5; resta pronta una nuova costituzione, a Dora.
 * `$tieni` dice a quali voci («spese», «entrata») la costituzione del 1/2 toglie la spunta: restano sul «Proprietario».
 *
 * @param list<string> $tieni
 * @return array{0: array, 1: Anagrafica, 2: int, 3: int} lo scenario, Dora, la riga di Ugo proprietario, l'associazione di «Spese generali»
 */
function b52UsDueUsufrutti($test, string $primaScelta, array $tieni = []): array
{
    $s = b52UsScenarioPannello();
    $ctmSpese = b52UsAssociazioneSpese($s);
    $associazioni = ['spese' => $ctmSpese, 'entrata' => $s['ctmEntrata']];
    ruRegistra($test, $s, b52UsCostituzione($s, ['decorrenza' => '2026-02-01', 'ordinaria_dopo_atto' => $primaScelta, 'voci_da_tenere' => array_map(fn (string $voce) => $associazioni[$voce], $tieni)]));
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->value('id');
    ruRegistra($test, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaElsa, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per rinuncia, letta']);
    $dora = Anagrafica::forceCreate(['nome' => 'Dora Seconda', 'email' => 'b52us-dora@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'B52USDORASECOND1']);
    $dora->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');

    return [$s, $dora, $rigaUgo, $ctmSpese];
}
