<?php

/**
 * Gli scenari della tesoreria a 30 giorni: un condominio con le sue casse (una banca, i contanti, una cassa virtuale, i fondi di ogni tipo) e le registrazioni
 * scritte come le scrivono le azioni (due righe, la cassa sulle righe di cassa). Oggi è il 15/11/2026, dentro l'esercizio 2026.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use Illuminate\Support\Facades\DB;

function tsrConto(int $condominioId, string $ruolo, string $codice, string $tipo, string $categoria, ?string $nome = null): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => $nome ?? $codice.' '.$ruolo,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tsrCassa(int $condominioId, string $tipo, string $nome, string $codice, bool $attiva = true, array $altro = []): Cassa
{
    $conto = tsrConto($condominioId, 'conto_'.$tipo.'_'.$codice, $codice, 'attivo', 'liquidita', $nome);

    return Cassa::create($altro + ['condominio_id' => $condominioId, 'nome' => $nome, 'tipo' => $tipo, 'conto_contabile_id' => $conto, 'saldo_iniziale' => 0, 'attiva' => $attiva]);
}

/** Il tipo di un fondo scritto com'è nella tabella (anche senza tipo, come un fondo di prima che il tipo esistesse). */
function tsrTipo(Cassa $fondo, ?string $sottotipo, ?string $vincolo = null, bool $sbloccato = false, ?string $motivazione = null): void
{
    DB::table('casse')->where('id', $fondo->id)->update(['sottotipo_fondo' => $sottotipo, 'vincolo_descrizione' => $vincolo, 'is_override_assemblea' => $sbloccato,
        'motivazione_override' => $motivazione]);
}

/** Una registrazione a due righe, dare e avere, con la cassa sulle righe di cassa. */
function tsrScrittura(array $s, string $data, string $tipo, int $contoDare, int $contoAvere, int $importo, ?string $causale = null): int
{
    $cassaDi = fn (int $conto) => DB::table('casse')->where('conto_contabile_id', $conto)->value('id');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => now()->format('Y-m-d'), 'data_competenza' => $data, 'numero_protocollo' => 'TSR-'.uniqid(), 'causale' => $causale ?? $tipo.' '.$data,
        'tipo_movimento' => $tipo, 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoDare, 'cassa_id' => $cassaDi($contoDare), 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoAvere, 'cassa_id' => $cassaDi($contoAvere), 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $id;
}

/** Il condominio con i conti di contropartita e l'esercizio 2026 aperto; oggi il 15/11/2026. */
function tsrBase($test, string $oggi = '2026-11-15 10:00:00'): array
{
    $test->travelTo($oggi);
    $c = Condominio::factory()->create(['nome' => 'Via Verdi 12']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id]);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);

    return ['c' => $c, 'g' => $g, 'e' => $e,
        'crediti' => tsrConto($c->id, 'crediti_condomini', '1101', 'attivo', 'crediti'),
        'debiti' => tsrConto($c->id, 'debiti_fornitori', '2201', 'passivo', 'debiti'),
        'passate' => tsrConto($c->id, 'passate_gestioni', '3002', 'passivo', 'fondi'),
        'costi' => tsrConto($c->id, 'costi_servizi', '5001', 'costo', 'costi')];
}

/**
 * Il condominio con ogni voce della liquidità: la banca con € 10.000,00 di apertura, i contanti con € 50,00, la cassa virtuale dei pagamenti online con € 80,00, un
 * conto di liquidità senza cassa con € 30,00, e sette fondi, uno per tipo, con un accantonamento ciascuno dalla banca:
 *   generico € 100,00 (si può usare); vincolato ai lavori € 200,00; vincolato senza descrizione € 250,00; TFR € 300,00; morosità € 400,00; sbloccato
 *   dall'assemblea € 500,00 (si può usare); senza tipo € 600,00.
 * La banca resta con € 10.000,00 − € 2.350,00 − € 50,00 − € 80,00 = € 7.520,00. Tutto il denaro € 10.000,00 + € 30,00 = € 10.030,00. I fondi che non si
 * possono usare: € 1.750,00. Disponibile oggi € 8.280,00.
 */
function tsrTuttiITipi($test): array
{
    $s = tsrBase($test);
    $cid = $s['c']->id;
    $s['banca'] = tsrCassa($cid, 'banca', 'Banca Intesa', '1010');
    $s['contanti'] = tsrCassa($cid, 'contanti', 'Cassa contanti', '1011');
    $s['online'] = tsrCassa($cid, 'virtuale', 'PayPal', '1012');
    $s['senzaCassa'] = tsrConto($cid, 'banca_posta', '1020', 'attivo', 'liquidita', 'Banca Posta');
    tsrScrittura($s, '2026-01-01', 'apertura', $s['banca']->conto_contabile_id, $s['passate'], 1000000);
    tsrScrittura($s, '2026-01-01', 'apertura', $s['senzaCassa'], $s['passate'], 3000);
    tsrScrittura($s, '2026-02-01', 'giroconto', $s['contanti']->conto_contabile_id, $s['banca']->conto_contabile_id, 5000);
    tsrScrittura($s, '2026-02-02', 'giroconto', $s['online']->conto_contabile_id, $s['banca']->conto_contabile_id, 8000);
    $s['fondi'] = [];
    foreach ([['Fondo spese generiche', 10000, 'generico'], ['Fondo facciata', 20000, 'vincolato_lavori', 'rifacimento della facciata'], ['Fondo lavori', 25000, 'vincolato_lavori'],
        ['TFR portiere', 30000, 'tfr'], ['Fondo morosità', 40000, 'morosita'], ['Fondo tetto', 50000, 'vincolato_lavori', 'tetto', true, 'urgenza approvata il 10/03'],
        ['Fondo di riserva', 60000, null]] as $i => $f) {
        $s['fondi'][$f[0]] = tsrCassa($cid, 'fondo', $f[0], (string) (1300 + $i));
        tsrScrittura($s, '2026-03-01', 'giroconto', $s['fondi'][$f[0]]->conto_contabile_id, $s['banca']->conto_contabile_id, $f[1]);
        tsrTipo($s['fondi'][$f[0]], $f[2], $f[3] ?? null, $f[4] ?? false, $f[5] ?? null);
    }

    return $s;
}
