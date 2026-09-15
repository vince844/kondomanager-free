<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Il registro del dettaglio del riparto (1.11.0-beta.29), letto nel motore prima che esista
 * una tabella: `CalcoloQuoteService::getRigheDettaglio()`.
 *
 * Il motore accumula un intero per (anagrafica, immobile) e, a `distribuisciSuTabelle()`, fonde
 * tabella, valore del millesimo e ruolo in un peso solo: da lì in poi non esistono più. Il
 * registro nasce **accanto** ai totali, nello stesso punto in cui quei dati sono ancora in scope,
 * e deve riconciliare con i totali al centesimo. È la guardia del registro: i test del PAR e del
 * riparto esatto restano verdi senza toccare una riga, e questo file prova che il registro non è
 * una seconda aritmetica ma la stessa, riletta.
 *
 * Forma di una riga: tipo (`riparto` | `netting` | `ad_personam` | `quota_zero`), anagrafica_id,
 * immobile_id, conto_id, conto_radice_id, tabella_id, coefficiente, valore_millesimo,
 * somma_valori, ruolo_richiesto, ruolo_risolto, quota_possesso, riga_fattura_id, importo
 * (centesimi con segno).
 */
function baseRigheRiparto(): array
{
    $condominio = Condominio::factory()->create(['nome' => 'RIGHE-RIPARTO']);
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::create(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'nome' => 'Gestione Base', 'tipo' => 'ordinaria']);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);

    return [$condominio, $esercizio, $gestione, $pianoConto];
}

function collegaContoRighe(int $contoId, int $tabellaId, float $coeff, array $ripartizioni = ['proprietario' => 100]): void
{
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $tabellaId, 'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizioni as $soggetto => $percent) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto, 'percentuale' => $percent, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function unitaRigheRiparto(Condominio $condominio, int $n, array $valoriPerTabella): Immobile
{
    $immobile = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $n, 'nome' => "App $n", 'descrizione' => 'Test']);
    foreach ($valoriPerTabella as $tabellaId => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $immobile->id, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $immobile;
}

function intestaRigheRiparto(Immobile $immobile, string $ruolo, float $quota = 100): Anagrafica
{
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => now()]);

    return $anagrafica;
}

function pianoOrdinarioRighe(Condominio $condominio, Gestione $gestione): PianoRate
{
    return PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
}

/** Σ importo delle righe per chiave «aid|iid», su tutti i tipi con un soggetto. */
function sommaRighePerChiave(array $righe): array
{
    $somme = [];
    foreach ($righe as $r) {
        if ($r['anagrafica_id'] === null) continue;
        $k = $r['anagrafica_id'].'|'.$r['immobile_id'];
        $somme[$k] = ($somme[$k] ?? 0) + $r['importo'];
    }

    return $somme;
}

function totaliPerChiave(array $totali): array
{
    $out = [];
    foreach ($totali as $aid => $perImmobile) {
        foreach ($perImmobile as $iid => $importo) $out["$aid|$iid"] = $importo;
    }

    return $out;
}

it('conto su due tabelle 60/40 con comproprietari: una riga per tabella, e le righe sommano ai totali e ai conti al centesimo', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tabA = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB A', 'quota' => 'millesimi']);
    $tabB = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB B', 'quota' => 'quote']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tabA->id => 613.33, $tabB->id => 1]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tabA->id => 386.67, $tabB->id => 2]);
    $c1a = intestaRigheRiparto($u1, 'proprietario', 50);
    $c1b = intestaRigheRiparto($u1, 'proprietario', 50);
    $p2 = intestaRigheRiparto($u2, 'proprietario');
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Multi', 'tipo' => 'spesa', 'importo' => 100001]);
    collegaContoRighe($conto->id, $tabA->id, 60);
    collegaContoRighe($conto->id, $tabB->id, 40);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    // 1. Riconciliazione con i totali: la guardia del centesimo.
    expect(sommaRighePerChiave($righe))->toBe(totaliPerChiave($totali));

    // 2. Riconciliazione con il registro per conto.
    $perConto = [];
    foreach ($righe as $r) if ($r['tipo'] === 'riparto') $perConto[$r['conto_id']] = ($perConto[$r['conto_id']] ?? 0) + $r['importo'];
    expect($perConto)->toBe($motore->getImportiPerConto());

    // 3. Una riga per tabella e per soggetto, con gli stessi centesimi che la stampa per tabella
    //    calcolava dal vivo (RipartoCasiLimiteTest): 18.400 + 6.667 per ciascun comproprietario,
    //    23.200 + 26.667 per il proprietario unico; colonne 600,00 e 400,01.
    $righeCella = fn (int $aid, int $iid, int $tab) => array_filter($righe, fn ($r) => $r['tipo'] === 'riparto' && $r['anagrafica_id'] === $aid && $r['immobile_id'] === $iid && $r['tabella_id'] === $tab);
    $cella = fn (int $aid, int $iid, int $tab) => array_sum(array_map(fn ($r) => $r['importo'], $righeCella($aid, $iid, $tab)));
    // Una riga per cella, non una somma di più righe: con un solo ruolo per tabella il componente è uno.
    $conta = fn (int $aid, int $iid, int $tab) => count($righeCella($aid, $iid, $tab));
    foreach ([$c1a, $c1b] as $comp) {
        expect($cella($comp->id, $u1->id, $tabA->id))->toBe(18400)->and($cella($comp->id, $u1->id, $tabB->id))->toBe(6667)
            ->and($conta($comp->id, $u1->id, $tabA->id))->toBe(1)->and($conta($comp->id, $u1->id, $tabB->id))->toBe(1);
    }
    expect($cella($p2->id, $u2->id, $tabA->id))->toBe(23200)->and($cella($p2->id, $u2->id, $tabB->id))->toBe(26667)
        ->and($conta($p2->id, $u2->id, $tabA->id))->toBe(1);
    $colonna = fn (int $tab) => array_sum(array_map(fn ($r) => $r['importo'], array_filter($righe, fn ($r) => $r['tipo'] === 'riparto' && $r['tabella_id'] === $tab)));
    expect($colonna($tabA->id))->toBe(60000)->and($colonna($tabB->id))->toBe(40001);

    // 4. Il contesto congelato sulla riga: tabella, coefficiente, millesimo, denominatore, ruolo, quota.
    $riga = array_values(array_filter($righe, fn ($r) => $r['tipo'] === 'riparto' && $r['anagrafica_id'] === $c1a->id && $r['tabella_id'] === $tabA->id))[0];
    expect($riga['conto_id'])->toBe($conto->id)
        ->and($riga['conto_radice_id'])->toBe($conto->id)
        ->and($riga['tabella_nome'])->toBe('TAB A')
        ->and($riga['tabella_quota'])->toBe('millesimi')
        ->and((float) $riga['coefficiente'])->toBe(60.0)
        ->and((float) $riga['valore_millesimo'])->toBe(613.33)
        ->and((float) $riga['somma_valori'])->toBe(1000.0)
        ->and($riga['ruolo_richiesto'])->toBe('proprietario')
        ->and($riga['ruolo_risolto'])->toBe('proprietario')
        ->and((float) $riga['quota_possesso'])->toBe(50.0)
        ->and($riga['riga_fattura_id'])->toBeNull();
});

it('la cascata sulla stessa persona lascia due righe con ruoli richiesti diversi e lo stesso ruolo risolto, che sommano all\'intero del motore', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tab->id => 500]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tab->id => 500]);
    $p1 = intestaRigheRiparto($u1, 'proprietario');
    $i1 = intestaRigheRiparto($u1, 'inquilino');
    $p2 = intestaRigheRiparto($u2, 'proprietario');            // senza inquilino: il 40% cade a cascata su di lui
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Misto', 'tipo' => 'spesa', 'importo' => 100000]);
    collegaContoRighe($conto->id, $tab->id, 100, ['proprietario' => 60, 'inquilino' => 40]);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    expect(sommaRighePerChiave($righe))->toBe(totaliPerChiave($totali));

    $diP2 = array_values(array_filter($righe, fn ($r) => $r['anagrafica_id'] === $p2->id));
    usort($diP2, fn ($a, $b) => strcmp($a['ruolo_richiesto'], $b['ruolo_richiesto']));
    expect($diP2)->toHaveCount(2)
        ->and($diP2[0]['ruolo_richiesto'])->toBe('inquilino')->and($diP2[0]['ruolo_risolto'])->toBe('proprietario')
        ->and($diP2[1]['ruolo_richiesto'])->toBe('proprietario')->and($diP2[1]['ruolo_risolto'])->toBe('proprietario')
        ->and($diP2[0]['importo'] + $diP2[1]['importo'])->toBe($totali[$p2->id][$u2->id])
        ->and($totali[$p2->id][$u2->id])->toBe(50000)
        ->and($totali[$p1->id][$u1->id])->toBe(30000)
        ->and($totali[$i1->id][$u1->id])->toBe(20000);
});

it('il già versato è una riga negativa per conto e soggetto: riparto + netting = totale, e la somma per unità è il registro del motore', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tab->id => 500]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tab->id => 500]);
    $c1a = intestaRigheRiparto($u1, 'proprietario', 50);
    $c1b = intestaRigheRiparto($u1, 'proprietario', 50);
    $p2 = intestaRigheRiparto($u2, 'proprietario');
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Lavori', 'tipo' => 'spesa', 'importo' => 100000]);
    collegaContoRighe($conto->id, $tab->id, 100);
    // L'unità 1 ha già versato € 300,01 su questo capitolo.
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => Conto::class, 'target_id' => $conto->id, 'immobile_id' => $u1->id, 'importo_cents' => 30001, 'natura' => 'ordinaria', 'origine' => 'manuale', 'descrizione' => 'acconto']);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    expect(sommaRighePerChiave($righe))->toBe(totaliPerChiave($totali));

    $netting = array_values(array_filter($righe, fn ($r) => $r['tipo'] === 'netting'));
    expect($netting)->toHaveCount(2); // i due comproprietari dell'unità 1
    foreach ($netting as $n) {
        expect($n['importo'])->toBeLessThan(0)->and($n['conto_id'])->toBe($conto->id)->and($n['immobile_id'])->toBe($u1->id)->and($n['tabella_id'])->toBeNull();
    }
    expect(array_sum(array_column($netting, 'importo')))->toBe(-$motore->getNettingApplicato()[$conto->id][$u1->id])
        ->and(array_sum(array_column($netting, 'importo')))->toBe(-30001)
        ->and($totali[$c1a->id][$u1->id] + $totali[$c1b->id][$u1->id])->toBe(50000 - 30001)
        ->and($totali[$p2->id][$u2->id])->toBe(50000);
});

it('un soggetto azzerato dal già versato ha righe riparto e netting che sommano a zero, non nessuna riga', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tab->id => 500]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tab->id => 500]);
    $p1 = intestaRigheRiparto($u1, 'proprietario');
    intestaRigheRiparto($u2, 'proprietario');
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Lavori', 'tipo' => 'spesa', 'importo' => 100000]);
    collegaContoRighe($conto->id, $tab->id, 100);
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => Conto::class, 'target_id' => $conto->id, 'immobile_id' => $u1->id, 'importo_cents' => 50000, 'natura' => 'ordinaria', 'origine' => 'manuale', 'descrizione' => 'tutto']);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    $diP1 = array_filter($righe, fn ($r) => $r['anagrafica_id'] === $p1->id);
    expect(array_column($diP1, 'tipo'))->toEqualCanonicalizing(['riparto', 'netting'])
        ->and(array_sum(array_column($diP1, 'importo')))->toBe(0)
        ->and($totali[$p1->id][$u1->id] ?? 0)->toBe(0);
});

it('gli zeri documentati si congelano: una riga quota_zero per tabella e unità, con il valore 0 o NULL, senza soggetto e senza importo', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'ASC', 'quota' => 'millesimi']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tab->id => 1000]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tab->id => 0]);      // non partecipa
    $u3 = unitaRigheRiparto($condominio, 3, [$tab->id => null]);   // non ancora compilato
    intestaRigheRiparto($u1, 'proprietario');
    intestaRigheRiparto($u2, 'proprietario');
    intestaRigheRiparto($u3, 'proprietario');
    $contoA = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Ascensore', 'tipo' => 'spesa', 'importo' => 50000]);
    $contoB = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Manutenzione ascensore', 'tipo' => 'spesa', 'importo' => 20000]);
    collegaContoRighe($contoA->id, $tab->id, 100);
    collegaContoRighe($contoB->id, $tab->id, 100);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    $zeri = array_values(array_filter($righe, fn ($r) => $r['tipo'] === 'quota_zero'));
    usort($zeri, fn ($a, $b) => $a['immobile_id'] <=> $b['immobile_id']);
    // Una per (tabella, unità), non una per conto: due conti sulla stessa tabella non la raddoppiano.
    expect($zeri)->toHaveCount(2)
        ->and($zeri[0]['immobile_id'])->toBe($u2->id)->and((float) $zeri[0]['valore_millesimo'])->toBe(0.0)
        ->and($zeri[1]['immobile_id'])->toBe($u3->id)->and($zeri[1]['valore_millesimo'])->toBeNull();
    foreach ($zeri as $z) {
        expect($z['anagrafica_id'])->toBeNull()->and($z['conto_id'])->toBeNull()->and($z['tabella_id'])->toBe($tab->id)->and($z['importo'])->toBe(0);
    }
});

it('un conto di entrata produce righe negative e nessuna riga di netting', function () {
    [$condominio, , $gestione, $pianoConto] = baseRigheRiparto();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $u1 = unitaRigheRiparto($condominio, 1, [$tab->id => 500]);
    $u2 = unitaRigheRiparto($condominio, 2, [$tab->id => 500]);
    $p1 = intestaRigheRiparto($u1, 'proprietario');
    intestaRigheRiparto($u2, 'proprietario');
    $spesa = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Spesa', 'tipo' => 'spesa', 'importo' => 100000]);
    $entrata = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'importo' => 20000]);
    collegaContoRighe($spesa->id, $tab->id, 100);
    collegaContoRighe($entrata->id, $tab->id, 100);
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => Conto::class, 'target_id' => $entrata->id, 'immobile_id' => $u1->id, 'importo_cents' => 1000, 'natura' => 'ordinaria', 'origine' => 'manuale', 'descrizione' => 'non si applica']);
    $piano = pianoOrdinarioRighe($condominio, $gestione);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaPerGestione($gestione, $piano);
    $righe = $motore->getRigheDettaglio();

    expect(sommaRighePerChiave($righe))->toBe(totaliPerChiave($totali));
    $diEntrata = array_filter($righe, fn ($r) => $r['conto_id'] === $entrata->id);
    expect($diEntrata)->toHaveCount(2);
    foreach ($diEntrata as $r) expect($r['tipo'])->toBe('riparto')->and($r['importo'])->toBe(-10000);
    expect($totali[$p1->id][$u1->id])->toBe(40000);
});

it('un addebito ad personam di un piano straordinario porta la riga di fattura; senza titolare di diritto reale diventa uno scoperto, non un importo perso', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $immobileId, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    $proprietario = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $proprietario->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    collegaContoRighe($capitolo->id, $tab->id, 100);
    // Un'unità senza nessun titolare di diritto reale: solo un inquilino.
    $orfana = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '9', 'nome' => 'App 9', 'descrizione' => 'Senza proprietario']);
    $inquilino = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $inquilino->id, 'immobile_id' => $orfana->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);

    $fattura = FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-AP', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'), 'is_pregresso' => false, 'importo_imponibile' => 0, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 0, 'netto_a_pagare' => 0, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    // Le righe con l'immobile nascono SENZA conto: è ciò che `FatturaPassivaService` scrive
    // (azzera `conto_id` su ogni riga ad personam), quindi la fixture non ne mette uno.
    $rigaBalcone = DB::table('righe_fattura')->insertGetId(['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $immobileId, 'descrizione' => 'Balcone interno 1', 'aliquota_iva' => 0, 'importo_imponibile' => 40000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $rigaOrfana = DB::table('righe_fattura')->insertGetId(['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $orfana->id, 'descrizione' => 'Balcone interno 9', 'aliquota_iva' => 0, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Straordinario', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 150000]);

    $motore = app(CalcoloQuoteService::class);
    $totali = $motore->calcolaDaFattureStraordinarie($piano);
    $righe = $motore->getRigheDettaglio();

    expect(sommaRighePerChiave($righe))->toBe(totaliPerChiave($totali));

    $adPersonam = array_values(array_filter($righe, fn ($r) => $r['tipo'] === 'ad_personam'));
    expect($adPersonam)->toHaveCount(1)
        ->and($adPersonam[0]['anagrafica_id'])->toBe($proprietario->id)
        ->and($adPersonam[0]['immobile_id'])->toBe($immobileId)
        ->and($adPersonam[0]['riga_fattura_id'])->toBe($rigaBalcone)
        ->and($adPersonam[0]['conto_id'])->toBeNull()
        ->and($adPersonam[0]['conto_radice_id'])->toBeNull()
        ->and($adPersonam[0]['riga_descrizione'])->toBe('Balcone interno 1')
        ->and($adPersonam[0]['importo'])->toBe(40000)
        ->and($adPersonam[0]['tabella_id'])->toBeNull();

    // La riga millesimale porta il conto e nessuna riga di fattura: per il millesimale il legame con
    // la fattura passa da `piano_rate_fatture` e da `righe_fattura.conto_id`, non dalla riga.
    $millesimale = array_values(array_filter($righe, fn ($r) => $r['tipo'] === 'riparto'));
    expect($millesimale)->toHaveCount(1)->and($millesimale[0]['importo'])->toBe(100000)->and($millesimale[0]['riga_fattura_id'])->toBeNull();

    // L'unità senza titolare non perde € 100,00 in silenzio: è uno scoperto con il suo motivo,
    // senza conto (la riga non ne ha) e con la descrizione della riga per riconoscerlo a video.
    $scoperti = array_values(array_filter($motore->getScoperti(), fn ($s) => ($s['motivo'] ?? null) === 'ad_personam_senza_titolare'));
    expect($scoperti)->toHaveCount(1)
        ->and($scoperti[0]['immobile_id'])->toBe($orfana->id)
        ->and($scoperti[0]['conto_id'])->toBeNull()
        ->and($scoperti[0]['riga_descrizione'])->toBe('Balcone interno 9')
        ->and($scoperti[0]['importo'])->toBe(10000)
        ->and(array_sum(array_column($righe, 'importo')))->toBe(140000);
});
