<?php

/**
 * Altri casi della voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51), oltre a quelli di totali, copertura, testi e
 * creazione.
 *
 * - Il selettore della creazione spegne la voce da sé: anche se la copertura la desse da finanziare (qui sostituita da una che lo fa, con uno speso
 *   di € 300,00 che la farebbe sembrare uno sforo), la voce resta spenta, senza sforo, con il residuo a zero e la sua nota. Oggi la copertura non la
 *   propone mai (`getCapitoliFinanziabili` la salta): il selettore è il secondo blocco, e questo file prova che regge senza il primo.
 * - Con la voce in due piani la nota del selettore e il rifiuto della creazione uniscono i nomi con «e» e parlano al plurale; il rifiuto dice che un
 *   piano nuovo la addebiterebbe «un'altra volta», non «una seconda volta» (sarebbe la terza).
 * - Il cruscotto: una voce in un piano ma scoperta (la voce riscritta a −€ 300,00 dopo che il piano ne ha copiato −€ 240,00) resta fra le voci scoperte
 *   per € 60,00, e la nota nomina il piano invece di proporre la correzione.
 * - La riga del piano dei conti con la copia nulla (il piano legge l'importo della voce): una riga del piano per € 240,00, coperta per intero, nessun
 *   «Piano rate straordinario»; `importo_raw` è il fabbisogno (€ 240,00), che la scheda della voce usa per decidere se mostrare la copertura.
 * - La voce scelta a «0,00» da una richiesta (dalla pagina è spenta): il piano nasce con la copia a zero, che non addebita niente, quindi non è un
 *   piano che la addebita; la copia nulla sì, perché lì il piano usa l'importo della voce.
 * - Le frasi rimandano solo alle pagine dei piani il cui avviso nomina la voce, con il loro nome; degli altri dicono solo che la addebitano in
 *   positivo. L'avviso della pagina del piano (`AvvisoSpeseNegative`) nomina la voce dove il piano ne tiene la copia negativa o ne legge l'importo,
 *   non dove ne tiene una copia positiva.
 *
 * Fonte dell'atteso: i conti a mano dello scenario di `Beta53SpesaNegativaScenari.php` (spese 120000 + |−30000| = 150000, entrate 24000, da ripartire
 * 126000; pianificato 120000 − 24000 + 24000 = 120000; 120000 / 126000 = 95,2 %) e le parole di `VoceDiSpesaNegativa`.
 *
 * Cosa NON copre: un piano con la copia nulla accanto a un piano con la copia scritta (`Beta53RigaPianoDeiContiCopiaNullaTest`); un sottoconto
 * negativo il cui capitolo è nel piano come capitolo (`Beta53SpesaNegativaTestiTest`, T297.24); un piano approvato con la copia a zero, che non
 * addebita la voce ma ne blocca la correzione dell'importo (`Beta53SpesaNegativaTestiTest`, T297.27); le pagine montate (i vitest di `Dashboard`, `ContiNew` e `PianiRateNew`); i database
 * diversi da sqlite.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\Riparto\VoceDiSpesaNegativa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Un testo che contiene la parte data, con un messaggio che lo mostra. */
function b53cpContiene(string $testo, string $parte): void
{
    expect(str_contains($testo, $parte))->toBeTrue("manca «{$parte}» in: {$testo}");
}

// ─── Il selettore regge da sé ───────────────────────────────────────────────────────────────────────────────────

it('T297.20 il selettore spegne la spesa negativa anche se la copertura la desse da finanziare: niente sforo, residuo zero, la sua nota', function () {
    $s = b53snV0($this);
    $rimborsoId = (int) $s['rimborso']->id;

    // Una copertura che non salta la spesa negativa, e uno speso di € 300,00 sulla voce, oltre il suo valore assoluto.
    $this->app->instance(BudgetCoverageService::class, new class extends BudgetCoverageService
    {
        public function getCapitoliFinanziabili(array $analysisReport, array $vociDaSpostaSpesa = []): array
        {
            $analysisReport['items'] = array_map(fn (array $item) => ['spesa_negativa' => false] + $item, $analysisReport['items']);

            return parent::getCapitoliFinanziabili($analysisReport, $vociDaSpostaSpesa);
        }
    });
    $this->app->instance(SpesaPerVoceService::class, new class($rimborsoId) extends SpesaPerVoceService
    {
        public function __construct(private int $voceId) {}

        public function perEsercizio(Esercizio $esercizio, ?array $vociIds = null, ?int $escludiFatturaId = null): array
        {
            return [$this->voceId => 30000];
        }
    });

    // la copertura sostituita propone davvero la voce, per lo sforo di € 300,00: senza il blocco del selettore la voce si accenderebbe
    $proposte = collect(app(BudgetCoverageService::class)->getCapitoliFinanziabili(
        app(BudgetCoverageService::class)->analyze($s['g']->fresh(), [$rimborsoId => 30000])
    ))->keyBy('id');
    expect($proposte->has($rimborsoId))->toBeTrue('la copertura sostituita non propone la voce: il test non prova niente')
        ->and($proposte[$rimborsoId]['importo_suggerito'])->toBe(30000);

    $voce = b53snSelettore($this, $s)->get($rimborsoId);

    expect([$voce['disabled'], $voce['is_sforo'], (float) $voce['residuo'], $voce['spesa_negativa'], $voce['note']])
        ->toBe([true, false, 0.0, true, B53SN_NOTA_FUORI_DAI_PIANI]);
});

// ─── Due piani: i nomi e il plurale ─────────────────────────────────────────────────────────────────────────────

it('T297.21 con la voce in due piani la nota del selettore li unisce con «e» e parla al plurale, e il rifiuto dice «un\'altra volta»', function () {
    $s = b53snV3($this);

    $nota = (string) b53snSelettore($this, $s)->get($s['rimborso']->id)['note'];
    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '240,00', 'note' => '']]], 'Terzo 2026');
    $rifiuto = (string) (b53snErrori()['capitoli_config.0.importo'][0] ?? '');

    b53cpContiene($nota, 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nei piani «Ordinario 2026» e «Integrativo 2026», che la addebitano in positivo');
    b53cpContiene($rifiuto, 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è già nei piani «Ordinario 2026» e «Integrativo 2026», che la addebitano in positivo: un piano nuovo la addebiterebbe un\'altra volta.');
    expect(str_contains($rifiuto, 'una seconda volta'))->toBeFalse('il rifiuto dice «una seconda volta» con la voce già in due piani: '.$rifiuto)
        ->and(DB::table('piani_rate')->where('gestione_id', $s['g']->id)->count())->toBe(2);
});

// ─── Il cruscotto: in un piano ma scoperta ──────────────────────────────────────────────────────────────────────

it('T297.22 una voce in un piano ma scoperta resta fra le voci scoperte del cruscotto per € 60,00, con la nota che nomina il piano', function () {
    $s = b53snV2($this);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => -30000]);

    $c = b53snCruscotto($this, $s);
    $orfani = collect($c['orfani'])->keyBy('nome');

    expect(collect($c)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all())->toEqual([
        'spese' => 150000, 'entrate' => 24000, 'da_ripartire' => 126000, 'pianificato' => 120000, 'delta' => 6000, 'percentuale' => 95.2,
    ])
        ->and($orfani->keys()->all())->toBe(['Rimborso vecchio'])
        ->and($orfani['Rimborso vecchio']['importo'])->toBe(6000)
        ->and($orfani['Rimborso vecchio']['spesa_negativa'])->toBeTrue()
        ->and($orfani['Rimborso vecchio']['nota'])->toBe(
            'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 300,00) ed è nel piano «Ordinario 2026», che la addebita in positivo: '
            .'la pagina del piano dice che cosa fare.'
        );
});

// ─── La riga del piano dei conti con la copia nulla ─────────────────────────────────────────────────────────────

it('T297.23 con la copia nulla la riga della voce dice «Ordinario 2026» per € 240,00 su € 240,00, senza un piano straordinario che non esiste', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $vuoto = fn ($voce) => ['id' => $voce->id, 'importo' => '', 'note' => ''];
    $piano = b52tPiano($this, $s, [
        'capitoli_ids' => [$s['generali']->id, $s['antenna']->id, $s['rimborso']->id],
        'capitoli_config' => [$vuoto($s['generali']), $vuoto($s['antenna']), $vuoto($s['rimborso'])],
    ], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -24000);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    // la copia è nulla: il motore legge l'importo della voce e la addebita in positivo, le quote restano 120000
    expect(b53snCopie($s, $s['rimborso']))->toBe([$piano->id => null])
        ->and(b52tQuoteDelMotore($piano))->toBe(120000)
        ->and(collect($riga)->only(['impegnato', 'importo', 'importo_raw', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
            'impegnato' => 24000, 'importo' => '€ 240,00', 'importo_raw' => 24000, 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]]);
});

it('T297.23 con la copia scritta col meno `importo_raw` della riga è il fabbisogno, € 240,00, come `importo`: la scheda della voce mostra la copertura', function () {
    $s = b53snV2($this);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect([$riga['importo_raw'], $riga['importo'], $riga['budget_originale_raw']])->toBe([24000, '€ 240,00', -24000]);
});

// ─── La copia a zero ────────────────────────────────────────────────────────────────────────────────────────────

it('T297.25 la voce scelta a «0,00» da una richiesta: la copia a zero non addebita niente, e selettore, piano dei conti e cruscotto dicono di correggerla', function () {
    $s = b53snV0($this);
    $id = (int) $s['rimborso']->id;

    b53snCrea($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '0,00', 'note' => '']]]);
    $piano = PianoRate::firstOrFail();
    $orfano = collect(b53snCruscotto($this, $s)['orfani'])->firstWhere('nome', 'Rimborso vecchio');

    expect(b53snCopie($s, $s['rimborso']))->toBe([$piano->id => 0])
        ->and(b53snRipartito($s, $piano)[$id] ?? 0)->toBe(0)
        ->and(b53snPaginaPiano($this, $s, $piano)['avviso_spesa_negativa'])->toBeNull()
        ->and(b53snSelettore($this, $s)->get($id)['note'])->toBe(B53SN_NOTA_FUORI_DAI_PIANI)
        ->and(json_decode(json_encode(b53snPianoDeiConti($this, $s)['speseNegative']), true))->toBe([['id' => $id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => false]])
        ->and($orfano['nota'] ?? null)->toBe(B53SN_NOTA_FUORI_DAI_PIANI);
});

it('T297.25 controprova — con la copia nulla il piano usa l\'importo della voce e la addebita: le frasi lo nominano', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -24000);
    $id = (int) $s['rimborso']->id;

    expect(b53snCopie($s, $s['rimborso']))->toBe([$piano->id => null])
        ->and(b53snRipartito($s, $piano)[$id] ?? 0)->toBe(24000)
        ->and(b53snSelettore($this, $s)->get($id)['note'])->toBe(B53SN_NOTA_NEL_PIANO)
        ->and(json_decode(json_encode(b53snPianoDeiConti($this, $s)['speseNegative']), true)[0]['piani'] ?? null)
        ->toBe([['id' => $piano->id, 'nome' => 'Ordinario 2026', 'avviso' => true]]);
});

// ─── Le pagine che dicono che cosa fare ─────────────────────────────────────────────────────────────────────────

it('T297.26 con la voce in due piani e l\'avviso su uno solo, la nota e il rifiuto rimandano alla pagina di quello, con il suo nome', function () {
    $s = b53snV3($this);
    $id = (int) $s['rimborso']->id;

    $nota = (string) b53snSelettore($this, $s)->get($id)['note'];
    b53snCrea($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '240,00', 'note' => '']]], 'Terzo 2026');
    $rifiuto = (string) (b53snErrori()['capitoli_config.0.importo'][0] ?? '');

    // l'avviso c'è sulla pagina di «Ordinario 2026» (copia −24000) e non su quella di «Integrativo 2026» (copia +24000)
    expect(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'])->not->toBeNull()
        ->and(b53snPaginaPiano($this, $s, $s['secondo'])['avviso_spesa_negativa'])->toBeNull()
        ->and($nota)->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nei piani «Ordinario 2026» e «Integrativo 2026», '
            .'che la addebitano in positivo: la pagina di «Ordinario 2026» dice che cosa fare.')
        ->and($rifiuto)->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è già nei piani «Ordinario 2026» e «Integrativo 2026», '
            .'che la addebitano in positivo: un piano nuovo la addebiterebbe un\'altra volta. La pagina di «Ordinario 2026» dice che cosa fare.');
});

it('T297.26 con la voce in un piano solo che non ha l\'avviso, la nota e il rifiuto dicono che la addebita, senza rimandare alla sua pagina', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $id = (int) $s['rimborso']->id;
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '240,00', 'note' => '']]], 'Integrativo 2026');
    b53snRiscrivi($s['rimborso'], -24000);

    $nota = (string) b53snSelettore($this, $s)->get($id)['note'];
    b53snCrea($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '240,00', 'note' => '']]], 'Terzo 2026');
    $rifiuto = (string) (b53snErrori()['capitoli_config.0.importo'][0] ?? '');

    expect(b53snCopie($s, $s['rimborso']))->toBe([$piano->id => 24000])
        ->and(b53snPaginaPiano($this, $s, $piano)['avviso_spesa_negativa'])->toBeNull()
        ->and($nota)->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nel piano «Integrativo 2026», che la addebita in positivo.')
        ->and($rifiuto)->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è già nel piano «Integrativo 2026», che la addebita in positivo: '
            .'un piano nuovo la addebiterebbe una seconda volta.')
        ->and(json_decode(json_encode(b53snPianoDeiConti($this, $s)['speseNegative']), true)[0]['piani'] ?? null)
        ->toBe([['id' => $piano->id, 'nome' => 'Integrativo 2026', 'avviso' => false]]);
});

it('T297.26 controprova — con l\'avviso su tutti e due i piani la coda resta «le pagine dei piani dicono che cosa fare»', function () {
    $s = b53snV3($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['secondo']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => -24000]);

    expect(b53snPaginaPiano($this, $s, $s['secondo'])['avviso_spesa_negativa'])->not->toBeNull()
        ->and((string) b53snSelettore($this, $s)->get($s['rimborso']->id)['note'])->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) '
            .'ed è nei piani «Ordinario 2026» e «Integrativo 2026», che la addebitano in positivo: le pagine dei piani dicono che cosa fare.');
});

it('T297.26 le code della nota e del rifiuto secondo quali pagine hanno l\'avviso: tutte, alcune, una, nessuna', function () {
    $a = ['id' => 1, 'nome' => 'Ordinario 2026', 'avviso' => true];
    $b = ['id' => 2, 'nome' => 'Integrativo 2026', 'avviso' => false];
    $c = ['id' => 3, 'nome' => 'Conguaglio 2026', 'avviso' => true];
    $testaNota = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nei piani ';
    $testaRifiuto = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è già nei piani ';
    $altraVolta = ', che la addebitano in positivo: un piano nuovo la addebiterebbe un\'altra volta.';

    expect(VoceDiSpesaNegativa::nota('Rimborso vecchio', -24000, [$a, $b, $c]))
        ->toBe($testaNota.'«Ordinario 2026», «Integrativo 2026» e «Conguaglio 2026», che la addebitano in positivo: le pagine di «Ordinario 2026» e «Conguaglio 2026» dicono che cosa fare.')
        ->and(VoceDiSpesaNegativa::nota('Rimborso vecchio', -24000, [$b, ['avviso' => false] + $c]))
        ->toBe($testaNota.'«Integrativo 2026» e «Conguaglio 2026», che la addebitano in positivo.')
        ->and(VoceDiSpesaNegativa::nota('Rimborso vecchio', -24000, [$a, $c]))
        ->toBe($testaNota.'«Ordinario 2026» e «Conguaglio 2026», che la addebitano in positivo: le pagine dei piani dicono che cosa fare.')
        ->and(VoceDiSpesaNegativa::rifiuto('Rimborso vecchio', -24000, [$a, $b, $c]))
        ->toBe($testaRifiuto.'«Ordinario 2026», «Integrativo 2026» e «Conguaglio 2026»'.$altraVolta.' Le pagine di «Ordinario 2026» e «Conguaglio 2026» dicono che cosa fare.')
        ->and(VoceDiSpesaNegativa::rifiuto('Rimborso vecchio', -24000, [$b, ['avviso' => false] + $c]))
        ->toBe($testaRifiuto.'«Integrativo 2026» e «Conguaglio 2026»'.$altraVolta)
        ->and(VoceDiSpesaNegativa::rifiuto('Rimborso vecchio', -24000, [$a, $c]))
        ->toBe($testaRifiuto.'«Ordinario 2026» e «Conguaglio 2026»'.$altraVolta.' Le pagine dei piani dicono che cosa fare.')
        ->and(VoceDiSpesaNegativa::rifiuto('Rimborso vecchio', -24000, [$b, $c]))
        ->toBe($testaRifiuto.'«Integrativo 2026» e «Conguaglio 2026»'.$altraVolta.' La pagina di «Conguaglio 2026» dice che cosa fare.');
});
