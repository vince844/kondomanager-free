<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Tabella;
use App\Services\RipartoCapitoliService;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Le due stampe del riparto **leggono** il dettaglio registrato (1.11.0-beta.29): Code 77 e 78.
 *
 * Lo scenario è quello misurato dalla revisione della beta.76 (Coda 77): una fattura da € 4.400,00
 * con € 4.000,00 di sopravvenienza sul capitolo e € 400,00 ad personam per il balcone dell'unità 1,
 * che aveva già versato € 500,00 sul capitolo. Fino alla .28 la stampa per tabella sommava il
 * residuo del netting sulla cella degli addebiti diretti e stampava «Addebito diretto −€ 100,00»;
 * quella per capitolo deduceva il netting per differenza e metteva i resti in «Fuori riparto».
 */
function scenarioCoda77(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobile1] = setupContabile();
    // Il budget del capitolo è la fattura: il già versato si applica per intero, non pro quota
    // (il motore lo scala quando il piano finanzia solo una parte del capitolo — acconto e saldo).
    $capitolo->update(['importo' => 400000]);
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $immobile2 = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '2', 'nome' => 'App 2', 'descrizione' => 'Test']);
    foreach ([$immobile1 => 600, $immobile2->id => 400] as $iid => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $iid, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
    }
    $p1 = Anagrafica::factory()->create(['nome' => 'Proprietario uno']);
    $p2 = Anagrafica::factory()->create(['nome' => 'Proprietario due']);
    DB::table('anagrafica_immobile')->insert([
        ['anagrafica_id' => $p1->id, 'immobile_id' => $immobile1, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()],
        ['anagrafica_id' => $p2->id, 'immobile_id' => $immobile2->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()],
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tab->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => Conto::class, 'target_id' => $capitolo->id, 'immobile_id' => $immobile1, 'importo_cents' => 50000, 'natura' => 'ordinaria', 'origine' => 'manuale', 'descrizione' => 'acconto']);

    $fattura = FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-77', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'), 'is_pregresso' => false, 'importo_imponibile' => 0, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 0, 'netto_a_pagare' => 0, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert([
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0, 'importo_imponibile' => 400000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => $immobile1, 'descrizione' => 'Balcone interno 1', 'aliquota_iva' => 0, 'importo_imponibile' => 40000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Straordinario 77', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 440000]);
    app(GeneratePianoRateAction::class)->execute($piano);

    return ['piano' => $piano->fresh(), 'tabella' => $tab, 'capitolo' => $capitolo, 'immobile1' => $immobile1, 'p1' => $p1, 'p2' => $p2];
}

it('Coda 77: «Addebito diretto» vale solo la spesa ad personam, e il già versato ha la sua colonna', function () {
    $s = scenarioCoda77();
    $m = (new RipartoTabelleService())->buildMatrice($s['piano']);
    $cella = $m['righe'][$s['immobile1']]['soggetti'][$s['p1']->id]['per_tabella'];

    expect($m['fonte']['tipo'])->toBe('registrato')
        ->and($cella[RipartoTabelleService::COLONNA_DIRETTO]['importo'])->toBe(40000)
        ->and($cella[RipartoTabelleService::COLONNA_GIA_VERSATO]['importo'])->toBe(-50000)
        ->and($cella[$s['tabella']->id]['importo'])->toBe(240000)
        // La riga vale la quota: 240.000 + 40.000 − 50.000.
        ->and($m['righe'][$s['immobile1']]['soggetti'][$s['p1']->id]['totale'])->toBe(230000)
        ->and($m['tot_per_tabella'][$s['tabella']->id])->toBe(400000)
        ->and($m['tot_per_tabella'][RipartoTabelleService::COLONNA_DIRETTO])->toBe(40000)
        ->and($m['tot_per_tabella'][RipartoTabelleService::COLONNA_GIA_VERSATO])->toBe(-50000)
        ->and(array_key_exists(RipartoTabelleService::COLONNA_FUORI_RIPARTO, $m['tabelle']))->toBeFalse()
        ->and($m['gran_totale'])->toBe(390000);
});

it('Coda 78: la stampa per capitolo legge lo stesso dettaglio — stesse celle, nessun «Fuori riparto», nessuna deduzione', function () {
    $s = scenarioCoda77();
    $m = (new RipartoCapitoliService())->buildMatrice($s['piano']);
    $cella = $m['righe'][$s['immobile1']]['soggetti'][$s['p1']->id]['per_capitolo'];

    expect($m['fonte']['tipo'])->toBe('registrato')
        ->and($cella[$s['capitolo']->id]['importo'])->toBe(240000)
        ->and($cella[RipartoCapitoliService::COLONNA_DIRETTO]['importo'])->toBe(40000)
        ->and($cella[RipartoCapitoliService::COLONNA_GIA_VERSATO]['importo'])->toBe(-50000)
        ->and(array_key_exists(RipartoCapitoliService::COLONNA_FUORI_RIPARTO, $m['capitoli']))->toBeFalse()
        ->and($m['gran_totale'])->toBe(390000)
        ->and($m['tot_per_capitolo'][$s['capitolo']->id])->toBe(400000);
});

it('un contributo registrato dopo la generazione non cambia il documento registrato, e cambia quello ricostruito — che lo dichiara', function () {
    $s = scenarioCoda77();
    // Il contributo cresce dopo l'emissione (la riga è unica per unità e capitolo: si aggiorna).
    ContributoVersato::where('target_type', Conto::class)->where('target_id', $s['capitolo']->id)->where('immobile_id', $s['immobile1'])->update(['importo_cents' => 60000]);

    $registrato = (new RipartoTabelleService())->buildMatrice($s['piano']->fresh());
    expect($registrato['fonte']['tipo'])->toBe('registrato')
        ->and($registrato['fonte']['generato_il'])->not->toBeNull()
        ->and($registrato['tot_per_tabella'][RipartoTabelleService::COLONNA_GIA_VERSATO])->toBe(-50000);

    // Senza dettaglio (piano generato prima della beta.29) la stampa ricalcola e lo dice.
    RigaRiparto::where('piano_rate_id', $s['piano']->id)->delete();
    $ricostruito = (new RipartoTabelleService())->buildMatrice($s['piano']->fresh());
    expect($ricostruito['fonte']['tipo'])->toBe('ricostruito')
        ->and($ricostruito['fonte']['generato_il'])->toBeNull()
        // Il motore di oggi vede € 600,00 versati; le quote restano quelle emesse (€ 500,00 scontati):
        // i € 100,00 di differenza sono un residuo dichiarato, non uno sconto inventato.
        ->and($ricostruito['tot_per_tabella'][RipartoTabelleService::COLONNA_GIA_VERSATO])->toBe(-60000)
        ->and($ricostruito['tot_per_tabella'][RipartoTabelleService::COLONNA_FUORI_RIPARTO] ?? 0)->toBe(10000)
        ->and($ricostruito['gran_totale'])->toBe(390000);
});

it('un piano mai generato stampa un\'anteprima, con i totali dalle righe', function () {
    $s = scenarioCoda77();
    $s['piano']->rate()->delete();
    RigaRiparto::where('piano_rate_id', $s['piano']->id)->delete();

    foreach ([new RipartoTabelleService(), new RipartoCapitoliService()] as $servizio) {
        $m = $servizio->buildMatrice($s['piano']->fresh());
        expect($m['fonte']['tipo'])->toBe('anteprima')
            ->and($m['gran_totale'])->toBe(390000)
            ->and($m['righe'][$s['immobile1']]['soggetti'][$s['p1']->id]['totale'])->toBe(230000);
    }
});

it('il documento dichiara la sua fonte in legenda e nella nota legale: registrato con la data, ricostruito, anteprima', function () {
    $s = scenarioCoda77();
    $rendi = function (PianoRate $piano) {
        $matrice = (new RipartoTabelleService())->buildMatrice($piano);
        $html = view('pdf.gestionale.riparto_tabelle', [
            'condominio' => $piano->condominio, 'esercizio' => $piano->condominio->esercizi()->firstOrFail(), 'pianoRate' => $piano,
            'matrice' => $matrice, 'nTabelle' => count($matrice['tabelle']), 'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
        ])->render();

        return preg_replace('/\s+/', ' ', strip_tags($html));
    };

    $registrato = $rendi($s['piano']->fresh());
    $oggi = now()->format('d/m/Y');
    expect($registrato)->toContain("Riparto registrato alla generazione del {$oggi}")
        ->toContain("in uso al momento della generazione ({$oggi})")
        ->not->toContain('ricostruito');

    RigaRiparto::where('piano_rate_id', $s['piano']->id)->delete();
    $ricostruito = $rendi($s['piano']->fresh());
    expect($ricostruito)->toContain('Riparto ricostruito dai dati attuali, non registrato')
        ->toContain('approvate in uso per l\'esercizio indicato')
        ->not->toContain('registrato alla generazione');

    $s['piano']->rate()->delete();
    $anteprima = $rendi($s['piano']->fresh());
    expect($anteprima)->toContain('Anteprima — piano non ancora generato');
});

it('le pseudo-colonne stanno sempre in coda, dopo le tabelle e dopo i capitoli', function () {
    // Nascono nell'ordine in cui il motore scrive le righe (l'ad personam prima del millesimale,
    // il già versato subito dopo il suo conto): senza il riordino «Addebito diretto» apriva il
    // documento e «Già versato» finiva fra due tabelle, come non erano mai state.
    $s = scenarioCoda77();
    $mt = (new RipartoTabelleService())->buildMatrice($s['piano']);
    $mc = (new RipartoCapitoliService())->buildMatrice($s['piano']);

    expect(array_keys($mt['tabelle']))->toBe([$s['tabella']->id, RipartoTabelleService::COLONNA_DIRETTO, RipartoTabelleService::COLONNA_GIA_VERSATO])
        ->and(array_keys($mc['capitoli']))->toBe([$s['capitolo']->id, RipartoCapitoliService::COLONNA_DIRETTO, RipartoCapitoliService::COLONNA_GIA_VERSATO]);
});

/**
 * Un piano ordinario con un capitolo padre e due voci figlie, più un capitolo semplice con un
 * contributo già versato: la forma che prova la radice congelata e la sopravvivenza delle colonne
 * alla cancellazione di un conto o di una tabella.
 */
function scenarioRadice(): array
{
    [$condominio, , $gestione, , , , $u1] = setupContabile();
    $pianoConto = \App\Models\Gestionale\PianoConto::firstOrCreate(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id], ['nome' => 'PC']);
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    $u2 = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '2', 'nome' => 'App 2', 'descrizione' => 'Test']);
    foreach ([$u1 => 600, $u2->id => 400] as $iid => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $iid, 'valore' => $valore, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $iid, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    }
    $padre = Conto::create(['piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'is_capitolo' => true, 'nome' => 'Amministrative', 'tipo' => 'spesa', 'importo' => 0]);
    $f1 = Conto::create(['piano_conto_id' => $pianoConto->id, 'parent_id' => $padre->id, 'nome' => 'Compenso', 'tipo' => 'spesa', 'importo' => 30000]);
    $f2 = Conto::create(['piano_conto_id' => $pianoConto->id, 'parent_id' => $padre->id, 'nome' => 'Cancelleria', 'tipo' => 'spesa', 'importo' => 40000]);
    $pulizie = Conto::create(['piano_conto_id' => $pianoConto->id, 'parent_id' => null, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'importo' => 100000]);
    foreach ([$f1, $f2, $pulizie] as $conto) {
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tab->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => Conto::class, 'target_id' => $pulizie->id, 'immobile_id' => $u1, 'importo_cents' => 10000, 'natura' => 'ordinaria', 'origine' => 'manuale', 'descrizione' => 'acconto']);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Ordinario', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    $piano->capitoli()->sync([$f1->id => ['importo' => 30000], $f2->id => ['importo' => 40000], $pulizie->id => ['importo' => 100000]]);
    app(GeneratePianoRateAction::class)->execute($piano);

    return ['piano' => $piano->fresh(), 'tabella' => $tab, 'padre' => $padre, 'f1' => $f1, 'f2' => $f2, 'pulizie' => $pulizie, 'u1' => $u1, 'u2' => $u2];
}

it('la radice del conto è congelata alla generazione: spostare una voce dopo l\'assemblea non sposta una colonna', function () {
    $s = scenarioRadice();
    $righe = $s['piano']->righeRiparto()->where('tipo', 'riparto')->get();

    expect($righe)->toHaveCount(6) // tre voci × due unità
        ->and($righe->where('conto_id', $s['f1']->id)->first()->conto_radice_id)->toBe($s['padre']->id)
        ->and($righe->where('conto_id', $s['f1']->id)->first()->conto_radice_nome)->toBe('Amministrative')
        ->and($righe->where('conto_id', $s['pulizie']->id)->first()->conto_radice_id)->toBe($s['pulizie']->id);

    $attese = function (array $m) use ($s) {
        expect($m['fonte']['tipo'])->toBe('registrato')
            ->and($m['capitoli'])->toHaveKey($s['padre']->id)->not->toHaveKey($s['f1']->id)->not->toHaveKey($s['f2']->id)
            ->and($m['tot_per_capitolo'][$s['padre']->id])->toBe(70000)
            ->and($m['tot_per_capitolo'][$s['pulizie']->id])->toBe(100000)
            ->and($m['tot_per_capitolo'][RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-10000)
            ->and($m['capitoli'])->not->toHaveKey(RipartoCapitoliService::COLONNA_FUORI_RIPARTO)
            ->and($m['gran_totale'])->toBe(160000);
    };
    $attese((new RipartoCapitoliService())->buildMatrice($s['piano']));

    // La voce «Cancelleria» viene spostata sotto «Pulizie» dopo la generazione: il documento non cambia.
    $s['f2']->update(['parent_id' => $s['pulizie']->id]);
    $attese((new RipartoCapitoliService())->buildMatrice($s['piano']->fresh()));
});

it('un conto di primo livello cancellato dopo la generazione tiene la sua colonna, con il nome di allora e i millesimi', function () {
    $s = scenarioRadice();
    \Illuminate\Support\Facades\Log::spy();
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['pulizie']->id)->delete();
    $s['pulizie']->delete();

    $m = (new RipartoCapitoliService())->buildMatrice($s['piano']->fresh());
    $colonnaPulizie = array_values(array_filter(array_keys($m['capitoli']), fn ($k) => ($m['capitoli'][$k]['nome'] ?? null) === 'Pulizie'));

    expect($m['fonte']['tipo'])->toBe('registrato')
        ->and($colonnaPulizie)->toHaveCount(1)
        ->and($m['tot_per_capitolo'][$colonnaPulizie[0]])->toBe(100000)
        ->and($m['righe'][$s['u1']]['soggetti'])->not->toBeEmpty()
        ->and(array_values($m['righe'][$s['u1']]['soggetti'])[0]['per_capitolo'][$colonnaPulizie[0]]['quota'])->toEqual(600.0)
        ->and($m['capitoli'])->not->toHaveKey(RipartoCapitoliService::COLONNA_FUORI_RIPARTO)
        ->and($m['gran_totale'])->toBe(160000);
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
});

it('una tabella cancellata dopo la generazione tiene la sua colonna e i suoi millesimi congelati', function () {
    $s = scenarioRadice();
    \Illuminate\Support\Facades\Log::spy();
    DB::table('conto_tabella_millesimale')->where('tabella_id', $s['tabella']->id)->delete();
    $s['tabella']->delete();

    $mt = (new RipartoTabelleService())->buildMatrice($s['piano']->fresh());
    $colonneVere = array_values(array_filter(array_keys($mt['tabelle']), fn ($k) => !in_array($k, [RipartoTabelleService::COLONNA_GIA_VERSATO, RipartoTabelleService::COLONNA_DIRETTO, RipartoTabelleService::COLONNA_PREGRESSO, RipartoTabelleService::COLONNA_FUORI_RIPARTO], true)));

    expect($mt['fonte']['tipo'])->toBe('registrato')
        ->and($colonneVere)->toHaveCount(1)
        ->and($mt['tabelle'][$colonneVere[0]]['nome'])->toBe('GEN')
        ->and($mt['tot_per_tabella'][$colonneVere[0]])->toBe(170000)
        ->and($mt['tot_quota_per_tabella'][$colonneVere[0]])->toEqual(1000.0)
        ->and($mt['tabelle'])->not->toHaveKey(RipartoTabelleService::COLONNA_FUORI_RIPARTO)
        ->and($mt['gran_totale'])->toBe(160000);

    $mc = (new RipartoCapitoliService())->buildMatrice($s['piano']->fresh());
    expect(array_values($mc['righe'][$s['u1']]['soggetti'])[0]['per_capitolo'][$s['padre']->id]['quota'])->toEqual(600.0);
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
});

it('le tabelle escono nell\'ordine delle righe di riparto anche quando la seconda ha un\'unità a zero', function () {
    [$condominio, , $gestione, , , , $u1] = setupContabile();
    $pianoConto = \App\Models\Gestionale\PianoConto::firstOrCreate(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id], ['nome' => 'PC']);
    $t1 = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GENERALE', 'quota' => 'millesimi']);
    $t2 = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'SCALE', 'quota' => 'millesimi']);
    $negozio = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'negozio', 'interno' => '9', 'nome' => 'Negozio', 'descrizione' => 'Test']);
    foreach ([$u1 => [600, 1000], $negozio->id => [400, 0]] as $iid => [$v1, $v2]) {
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $t1->id, 'immobile_id' => $iid, 'valore' => $v1, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $t2->id, 'immobile_id' => $iid, 'valore' => $v2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $iid, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    }
    $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => 'Riscaldamento', 'tipo' => 'spesa', 'importo' => 300000]);
    foreach ([$t1, $t2] as $t) {
        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $t->id, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Ordinario', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    $piano->capitoli()->sync([$conto->id => ['importo' => 300000]]);

    // Anteprima (nessuna quota) e registrato: stesso ordine, quello delle tabelle del conto.
    $anteprima = (new RipartoTabelleService())->buildMatrice($piano);
    app(GeneratePianoRateAction::class)->execute($piano);
    $registrato = (new RipartoTabelleService())->buildMatrice($piano->fresh());

    foreach ([$anteprima, $registrato] as $m) {
        expect(array_keys($m['tabelle']))->toBe([$t1->id, $t2->id]);
        $cellaNegozio = array_values($m['righe'][$negozio->id]['soggetti'])[0]['per_tabella'][$t2->id];
        expect($cellaNegozio['quota'])->not->toBeNull()->and((float) $cellaNegozio['quota'])->toBe(0.0)->and($cellaNegozio['importo'])->toBe(0);
    }
});
