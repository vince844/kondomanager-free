<?php

/**
 * Lo scenario delle lettere: Ugo (unità A, 600 millesimi) e Bice (unità B, 400), 12 rate mensili dal 05/01/2026 su € 1.200,00 l'anno, quindi
 * € 60,00 a rata per Ugo e € 40,00 per Bice; emesse le rate 1–4 il 02/01. Bice paga la rata 1 il 10/03 con € 20,00 in più (un credito). Oggi
 * 20/03/2026: Ugo ha le rate 1, 2, 3 scadute (€ 180,00), Bice le rate 2 e 3 (€ 80,00) e € 20,00 di credito; la rata 4 scade il 05/04. La
 * cassa del condominio ha un IBAN.
 */

use App\Models\Gestionale\RegistroInvio;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LetturaDebitiProva.php';

function lttScenario($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    lcdEmetti($test, $s, [1, 2, 3, 4], '2026-01-02');
    $test->travelTo('2026-03-10 10:00:00');
    lcdIncassa($test, $s, 'bice', [lcdQuota($s, 'bice', 1) => 4000], '2026-03-10', 2000);
    $test->travelTo('2026-03-20 10:00:00');
    $s['cassa']->contoCorrente()->create(['iban' => 'IT60X0542811101000000123456', 'intestatario' => 'Condominio di prova']);

    return $s;
}

/** Le scelte della finestra per Ugo e Bice, con un testo che ha i campi da riempire. */
function lttScelte(array $s, array $altro = []): array
{
    return array_merge([
        'tipo' => 'sollecito',
        'persone' => [$s['persone']['ugo']->id, $s['persone']['bice']->id],
        'testo' => "Gentile [nome],\nrisultano da versare [totale]. Le chiediamo di versare entro [termine].",
        'termine' => 15,
        'cassa_id' => $s['cassa']->id,
        'indirizzi' => ['email', 'email_secondaria'],
    ], $altro);
}

function lttRotta(array $s, string $nome, array $altro = []): string
{
    return route('admin.gestionale.lettere.' . $nome, ['condominio' => $s['c']->id] + $altro);
}

/** Le righe del registro di una persona, dei tipi delle lettere. */
function lttRighe(array $s, string $chi): \Illuminate\Support\Collection
{
    return RegistroInvio::where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone'][$chi]->id)
        ->whereIn('tipo', ['promemoria', 'sollecito', 'diffida', 'messa_in_mora'])->orderBy('id')->get();
}

/** Una riga del registro scritta a mano, per le letture: il tipo, lo stato, il canale e quanti giorni fa (alle 10:00 di Roma). */
function lttRigaScritta(array $s, string $chi, string $tipo, string $stato, int $giorniFa, string $canale = 'stampa'): RegistroInvio
{
    return RegistroInvio::create([
        'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'tipo' => $tipo, 'canale' => $canale,
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone'][$chi]->id,
        'dati' => ['lettera' => (string) \Illuminate\Support\Str::uuid(), 'persona' => ['id' => $s['persone'][$chi]->id, 'nome' => $s['persone'][$chi]->nome], 'righe' => [], 'totale' => 0],
        'stato' => $stato, 'emesso_il' => now()->subDays($giorniFa),
    ]);
}

function lttDocumentiDellaRiga(RegistroInvio $riga): \Illuminate\Support\Collection
{
    return DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $riga->id)->get();
}

/**
 * Una seconda gestione, «Riscaldamento 2026», con un piano di 2 rate su € 200,00 per millesimi: la prima (10/02) emessa e scaduta, Ugo € 60,00 e
 * Bice € 40,00. Oggi resta il 20/03/2026. Restituisce la gestione.
 */
function lttSecondaGestione($test, array $s): \App\Models\Gestione
{
    $test->travelTo('2026-02-01 10:00:00');
    $g2 = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g2->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g2->id, 'nome' => 'PC2']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Gasolio', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000]);
    $tabella = DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $g2->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-02-10', 'metodo_distribuzione' => 'prima_rata',
        'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    lcdEmetti($test, ['piano' => $piano] + $s, [1], '2026-02-01');
    $test->travelTo('2026-03-20 10:00:00');

    return $g2;
}
