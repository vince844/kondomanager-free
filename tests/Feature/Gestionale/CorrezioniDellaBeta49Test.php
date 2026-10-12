<?php

/**
 * 1.11.0-beta.50 — le correzioni rimaste dalla .49 che si provano in PHP (verbale `docs/piano_esecutivo_beta49.md`, «Chiusura»:
 * «Da fare nella .50»; verbale `docs/piano_esecutivo_beta50.md`, perimetro).
 *
 * Che cosa copre: la nota che il riallineamento dei crediti salva nel libro giornale, sulla scrittura d'emissione della quota a credito
 * rimasta fuori da una rata mista (`RiallineaCreditiService.php`:192), non porta più la versione («come la scrive l'emissione dalla
 * 1.11.0-beta.49»): la scrittura resta nel giornale per sempre, e una beta lì dentro invecchia il giorno dopo la stabile. Nessuna versione,
 * nemmeno senza la beta («1.11», «1.11.0»: aggiunto nella revisione dei test della Fase 1, perché una correzione a metà passava). Il
 * marcatore della causale, «Riallineamento crediti (1.11)», resta com'è: è la versione senza la beta, scelta da Vincenzo l'11/10/2026
 * (`RiallineaCreditiService::MARCATORE`). Con un controllo, già giusto prima della .50, sulle due date della scrittura: la competenza è
 * quella dell'emissione che corregge, la registrazione è il giorno in cui si scrive — è ciò che la conferma della card deve dire (il test
 * della conferma è in `IncassoRateList.test.ts`).
 *
 * Che cosa NON copre: le note già scritte dalla .49 (restano come sono, per scelta del verbale); la nota della rettifica di una
 * compensazione (`:151`, già senza versione: la copre `RiallineaCreditiTest` per il resto); il comando da console, che scrive con
 * lo stesso servizio; le altre quattro correzioni della .49 — la card «Addebiti» e la conferma del riallineamento sono in vitest
 * (`EstrattoContoAnagrafica.test.ts`, `IncassoRateList.test.ts`), la guardia delle sezioni omonime è in
 * `tests/Feature/System/FlussoDiLavoroNonRestaIndietroTest.php`, l'errata del changelog della .49 non ha un test (è un testo
 * del changelog della .50, che nessun test legge).
 *
 * Lo scenario è quello di R2 in `RiallineaCreditiTest` (prefisso `rlc`), ricostruito qui con il prefisso `c49` perché i file di
 * Pest non si includono a vicenda: due unità da 500 millesimi, € 1.200,00 tutto al proprietario in 12 rate dal 5/1, Ugo con
 * € 200,00 di credito riportato (−20000, segno negativo = credito) applicato alla prima rata; le rate 1 e 2 emesse come le
 * emetteva la 1.10.0 (la quota a credito di Ugo, 5000 − 20000 = −15000, saltata).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Gestionale\RiallineaCreditiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Lo scenario di R2 (vedi in testa), con il piano generato. */
function c49Scenario(): array
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
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);

    $persone = [];
    foreach (['A' => ['ugo', 'Ugo Uno'], 'B' => ['bice', 'Bice Due']] as $u => [$chi, $nome]) {
        $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "C49-{$seq}-{$u}", 'nome' => "Interno {$u}", 'interno' => $u]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 500.0, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] = Anagrafica::forceCreate(['nome' => $nome, 'email' => "c49-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad("C49{$chi}{$seq}", 16, 'X'))]);
        $persone[$chi]->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
        if ($chi === 'ugo') {
            // Il credito riportato di Ugo: € 200,00 (segno negativo = credito).
            Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => -20000, 'origine' => 'manuale', 'is_applicato' => false]);
        }
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'persone', 'piano');
}

function c49Conto(array $s, string $ruolo): ContoContabile
{
    return ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->firstOrFail();
}

/**
 * L'emissione della 1.10.0 riga per riga, come `rlcEmettiComeLa110` in `RiallineaCreditiTest` (v1.10.0
 * `EmissioneRateController.php`:121-268): salta le quote ≤ 0, DARE «Crediti» l'importo intero, «Gestione rate» la quota pura e
 * «Passate gestioni» la differenza, rate segnate «emesse».
 */
function c49EmettiComeLa110(array $s, array $numeri, string $data): void
{
    $crediti = c49Conto($s, 'crediti_condomini');
    $gestione = c49Conto($s, 'gestione_rate');
    $passate = c49Conto($s, 'passate_gestioni');
    DB::transaction(function () use ($s, $numeri, $data, $crediti, $gestione, $passate) {
        foreach ($numeri as $n) {
            $rata = Rata::where('piano_rate_id', $s['piano']->id)->where('numero_rata', $n)->firstOrFail()->load('rateQuote');
            $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['piano']->gestione_id,
                'data_registrazione' => now(), 'data_competenza' => $data, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
            $tg = 0;
            $tp = 0;
            foreach ($rata->rateQuote as $q) {
                $imp = (int) $q->importo;
                if ($imp <= 0) {
                    continue;
                }
                $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
                $pura = (int) ($regole['importi']['quota_pura_gestione'] ?? $imp);
                $sc->righe()->create(['conto_contabile_id' => $crediti->id, 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id, 'rata_id' => $rata->id,
                    'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
                $q->update(['scrittura_contabile_id' => $sc->id]);
                $tg += $pura;
                $tp += $imp - $pura;
            }
            foreach ([[$gestione, $tg, 'Totale emissione '], [$passate, $tp, 'Riporto esercizi precedenti — ']] as [$conto, $t, $nota]) {
                if ($t !== 0) {
                    $sc->righe()->create(['conto_contabile_id' => $conto->id, 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => $nota.$rata->descrizione]);
                }
            }
        }
        Rata::where('piano_rate_id', $s['piano']->id)->whereIn('numero_rata', $numeri)->update(['stato' => 'emessa']);
    });
}

it('la nota che il riallineamento salva sull\'emissione della quota a credito rimasta fuori non porta la versione; la competenza è quella dell\'emissione che corregge, la registrazione è di oggi', function () {
    $s = c49Scenario();
    c49EmettiComeLa110($s, [1, 2], '2026-02-28');
    $quotaUgo = RataQuote::where('rata_id', Rata::where('piano_rate_id', $s['piano']->id)->where('numero_rata', 1)->value('id'))
        ->where('anagrafica_id', $s['persone']['ugo']->id)->firstOrFail();
    // Il presupposto: la quota di Ugo sulla rata 1, 5000 di spesa − 20000 di credito = −15000, è rimasta fuori dal giornale.
    expect((int) $quotaUgo->importo)->toBe(-15000)
        ->and($quotaUgo->scrittura_contabile_id)->toBeNull();
    $ultimo = (int) ScritturaContabile::max('id');

    // La conferma della card «Crediti da riallineare», dalla rotta vera.
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHasNoErrors();
    $em = ScritturaContabile::where('id', '>', $ultimo)->where('tipo_movimento', 'emissione_rata')->sole();

    // Il marcatore della causale resta, con la versione senza la beta; la nota dice che cosa è la scrittura, senza nessuna versione.
    // Revisione dei test della Fase 1: il solo «beta» lasciava passare una nota corretta a metà, «come la scrive l'emissione dalla
    // 1.11.0» (mutazione provata): la versione nella nota invecchia lo stesso. Si cerca anche «1.11» e «1.11.0»; «€ 1.200,00» no.
    expect($em->causale)->toStartWith(RiallineaCreditiService::MARCATORE)
        ->and((string) $em->note)->toContain('credito riportato')
        ->and((string) $em->note)->not->toMatch('/beta|\b1\.\d{1,2}(\.\d+)?\b/i')
        // Controllo, già giusto prima della .50: competenza = la data dell'emissione della rata (28/02/2026), registrazione = il
        // giorno in cui si scrive. È questo che la conferma della card deve dire, non «nella data della scrittura che
        // correggono».
        ->and($em->data_competenza->toDateString())->toBe('2026-02-28')
        ->and(\Illuminate\Support\Carbon::parse($em->data_registrazione)->toDateString())->toBe(now()->toDateString())
        ->and((int) $quotaUgo->fresh()->scrittura_contabile_id)->toBe((int) $em->id);
});
