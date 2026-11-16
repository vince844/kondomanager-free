<?php

/**
 * La misura del confronto del giornale nel condominio grande, prima di metterlo in testa a ogni scheda: 13.000 registrazioni nell'esercizio (12.000
 * incassi delle rate di 3.000 unità in quattro rate, 1.000 fatture con il loro pagamento), con due o tre righe ciascuna. Si misura l'impronta del
 * giornale (una lettura delle righe, nessun calcolo) e il confronto con quella fermata in una versione approvata, come lo fa la testa di ogni scheda.
 *
 * Le soglie del test stanno sopra la misura, con margine per una macchina più lenta; la misura è nel rapporto.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoVersione;
use App\Services\Gestionale\ConfrontoDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const RCM_SCRITTURE = 13000;

/** Il condominio grande: l'esercizio 2025, ancora aperto, e il 2026, ognuno con le sue registrazioni scritte a blocchi, direttamente nel giornale. */
function rcmCondominioGrande(): array
{
    $c = Condominio::factory()->create();
    $e25 = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $conto = fn (string $codice, string $tipo, string $categoria) => DB::table('conti_contabili')->insertGetId(['condominio_id' => $c->id, 'codice' => $codice, 'nome' => $codice,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now()]);
    $conti = ['banca' => $conto('10.10', 'attivo', 'liquidita'), 'crediti' => $conto('10.20', 'attivo', 'crediti'), 'costi' => $conto('50.10', 'passivo', 'debiti'),
        'debiti' => $conto('20.30', 'passivo', 'debiti')];
    rcmRegistrazioni($c->id, $e25->id, '2025', $conti);
    rcmRegistrazioni($c->id, $e->id, '2026', $conti);

    return ['c' => $c, 'e' => $e, 'e25' => $e25];
}

/** Le registrazioni di un esercizio: 12.000 incassi e 1.000 pagamenti, due o quattro righe ciascuna. */
function rcmRegistrazioni(int $condominio, int $esercizio, string $anno, array $conti): void
{
    $adesso = now()->format('Y-m-d H:i:s');
    for ($blocco = 0; $blocco < RCM_SCRITTURE; $blocco += 1000) {
        $scritture = [];
        for ($i = $blocco; $i < min(RCM_SCRITTURE, $blocco + 1000); $i++) {
            $giorno = sprintf('%s-%02d-%02d', $anno, 1 + $i % 12, 1 + $i % 28);
            $scritture[] = ['condominio_id' => $condominio, 'esercizio_id' => $esercizio, 'data_registrazione' => $giorno, 'data_competenza' => $giorno,
                'numero_protocollo' => 'M-'.$anno.'-'.$i, 'causale' => 'misura', 'tipo_movimento' => $i < 12000 ? 'incasso_rata' : 'pagamento_fornitore', 'stato' => 'registrata',
                'created_at' => $adesso, 'updated_at' => $adesso];
        }
        DB::table('scritture_contabili')->insert($scritture);
    }
    $ids = DB::table('scritture_contabili')->where('esercizio_id', $esercizio)->orderBy('id')->pluck('id')->all();
    foreach (array_chunk($ids, 500) as $pezzo) {
        $righe = [];
        foreach ($pezzo as $id) {
            $importo = 10000 + ($id % 997);
            $righe[] = ['scrittura_id' => $id, 'conto_contabile_id' => $conti['banca'], 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => $adesso, 'updated_at' => $adesso];
            $righe[] = ['scrittura_id' => $id, 'conto_contabile_id' => $conti['crediti'], 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => $adesso, 'updated_at' => $adesso];
            if ($id % 13 === 0) {
                $righe[] = ['scrittura_id' => $id, 'conto_contabile_id' => $conti['costi'], 'tipo_riga' => 'dare', 'importo' => 100, 'created_at' => $adesso, 'updated_at' => $adesso];
                $righe[] = ['scrittura_id' => $id, 'conto_contabile_id' => $conti['debiti'], 'tipo_riga' => 'avere', 'importo' => 100, 'created_at' => $adesso, 'updated_at' => $adesso];
            }
        }
        DB::table('righe_scritture')->insert($righe);
    }
}

it('l\'impronta del giornale di 13.000 registrazioni con un esercizio prima di altre 13.000, e il confronto con quella di una versione approvata, stanno sotto 1,5 s e 32 MB', function () {
    $s = rcmCondominioGrande();
    expect(DB::table('scritture_contabili')->where('esercizio_id', $s['e']->id)->count())->toBe(RCM_SCRITTURE);

    gc_collect_cycles();
    $memoria = memory_get_usage();
    memory_reset_peak_usage();
    $inizio = hrtime(true);
    $g = ConfrontoDelRendiconto::giornale($s['e']);
    $secondiImpronta = (hrtime(true) - $inizio) / 1e9;
    $piccoImpronta = (memory_get_peak_usage() - $memoria) / 1048576;

    // La versione approvata con l'impronta di quel giorno: il JSON del giornale fermato, come lo scrive «Ferma per l'assemblea».
    $v = RendicontoVersione::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'numero' => 1, 'stato' => RendicontoVersione::APPROVATA,
        'forma' => RendicontoVersione::FORMA, 'cifre' => ['forma' => RendicontoVersione::FORMA, 'giornale' => $g], 'calcolato_il' => now(), 'data_delibera' => '2027-03-15',
        'riparto_approvato' => true, 'pdf' => ['parti' => [], 'documenti' => []]]);
    $byte = strlen(json_encode($g));
    // Una registrazione cambiata dopo l'approvazione: l'importo di una riga dell'esercizio.
    $ultima = DB::table('righe_scritture')->max('id');
    $importo = DB::table('righe_scritture')->where('id', $ultima)->value('importo');
    DB::table('righe_scritture')->where('id', $ultima)->update(['importo' => 1]);

    gc_collect_cycles();
    $memoria = memory_get_usage();
    memory_reset_peak_usage();
    $inizio = hrtime(true);
    $cambiato = ConfrontoDelRendiconto::giornaleCambiato($v);
    $avviso = ConfrontoDelRendiconto::avvisoInTesta($s['e']);
    $secondiTesta = (hrtime(true) - $inizio) / 1e9;
    $piccoTesta = (memory_get_peak_usage() - $memoria) / 1048576;

    fwrite(STDERR, sprintf("\n[misura giornale] %d registrazioni: impronta %.2f s, %.1f MB; JSON fermato %.0f KB; testa (confronto + avviso) %.2f s, %.1f MB\n",
        $g['scritture'], $secondiImpronta, $piccoImpronta, $byte / 1024, $secondiTesta, $piccoTesta));

    // Rimessa com'era, il giornale è quello approvato; una riga dell'esercizio prima cambiata, i saldi prima no.
    DB::table('righe_scritture')->where('id', $ultima)->update(['importo' => $importo]);
    $tornato = ConfrontoDelRendiconto::giornaleCambiato($v);
    DB::table('righe_scritture')->where('id', DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
        ->where('sc.esercizio_id', $s['e25']->id)->max('rs.id'))->update(['importo' => 1]);

    expect($g['scritture'])->toBe(RCM_SCRITTURE)
        ->and($cambiato)->toBeTrue()->and($avviso)->toStartWith('Dopo l\'approvazione del 15/03/2027 sono cambiate registrazioni che queste schede leggono')
        ->and($tornato)->toBeFalse()->and(ConfrontoDelRendiconto::giornaleCambiato($v))->toBeTrue()
        ->and($secondiImpronta)->toBeLessThan(1.5)->and($piccoImpronta)->toBeLessThan(32)
        ->and($secondiTesta)->toBeLessThan(3.0)->and($piccoTesta)->toBeLessThan(32)
        ->and($byte)->toBeLessThan(600 * 1024);
});
