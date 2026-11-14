<?php

/**
 * Le frasi che promettevano un conguaglio chiesto da solo «a chiusura esercizio». Il conguaglio per persona è calcolato nel rendiconto, come proposta da
 * approvare dall'assemblea: il programma non chiede niente da solo, non scrive rate né saldi, e non ha la chiusura dell'esercizio. Le frasi dicono dove si
 * legge, e non promettono ciò che il programma farà.
 *
 * La strategia «Attesa conguaglio» di uno sforo non ha un ramo che scriva qualcosa: nessuna copertura, nessuna rata, nessun saldo. La fattura entra nella spesa
 * dell'esercizio, e da lì nel conguaglio per persona: Pulizie con il preventivo di € 3.000,00 chiesto dal piano, una fattura di € 3.300,00, due unità da 500
 * millesimi: la spesa di ognuno è 1.650,00, le quote 1.500,00, il conguaglio +150,00 a testa, +300,00 in tutto, quanto lo sforo.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Le righe dei file del prodotto (codice, viste, pagine, testi delle lingue), senza i test: «file:riga» → testo della riga. */
function fcpRighe(): array
{
    static $letti = null;
    if ($letti !== null) {
        return $letti;
    }
    $radice = dirname(__DIR__, 3);
    $righe = [];
    foreach (['app', 'resources/js', 'resources/views', 'lang'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $nome = $f->getPathname();
            if (preg_match('/\.(php|vue|ts)$/', $nome) && ! preg_match('/\.test\.ts$/', $nome)) {
                foreach (explode("\n", (string) file_get_contents($nome)) as $i => $riga) {
                    $righe[substr($nome, strlen($radice) + 1).':'.($i + 1)] = $riga;
                }
            }
        }
    }

    return $letti = $righe;
}

/** I «file:riga» del prodotto che contengono il testo (regex). */
function fcpCerca(string $regex): array
{
    return array_keys(array_filter(fcpRighe(), fn ($riga) => preg_match($regex, $riga) === 1));
}

/** Il testo di un file del prodotto, con gli spazi in fila (anche gli a capo) uno solo. */
function fcpFile(string $percorso): string
{
    return preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3).'/'.$percorso));
}

it('nessun testo promette più un conguaglio chiesto da solo a fine anno, o dice che il conguaglio per persona non c\'è', function () {
    expect(fcpCerca('/automaticamente a chiusura esercizio|si regolerà tutto a chiusura|ai condòmini a chiusura esercizio|calcolata automaticamente a fine esercizio/'))->toBe([])
        ->and(fcpCerca('/conguaglio di fine gestione|il conguaglio per persona non è ancora nel programma|stanziato per il conguaglio di fine anno/'))->toBe([])
        ->and(fcpCerca('/gestite automaticamente nel conguaglio|automatically managed in the year-end|ajustadas automaticamente no acerto/'))->toBe([]);
});

it('le frasi della strategia «Attesa conguaglio» dicono che la differenza entra nel conguaglio per persona, calcolato nel rendiconto, e che il programma non la chiede', function () {
    $modale = fcpFile('resources/js/components/gestionale/movimenti/fatture/ModalOverrideBudget.vue');
    $imprevista = fcpFile('resources/js/components/gestionale/movimenti/fatture/ModalSpesaImprevista.vue');
    $guida = fcpFile('resources/js/components/guides/FatturaRegistrazioneGuide.vue');
    $conti = fcpFile('resources/js/components/guides/OperazioniContiGuide.vue');
    $cruscotto = fcpFile('resources/js/pages/gestionale/dashboard/Dashboard.vue');

    // Con l'inciso: una voce senza tabella non la divide nessuno, e una straordinaria propone quando l'opera è finita.
    expect($modale)->toContain('Registra lo sforo senza una rata. La differenza entra nel conguaglio per persona, calcolato nel rendiconto, se la voce ha una tabella (in una gestione straordinaria, quando l\'opera è finita): è una proposta per l\'assemblea, il programma non la chiede da solo.')
        ->and($imprevista)->toContain('Registra la spesa senza una rata. Entra nel conguaglio per persona, calcolato nel rendiconto, se la voce ha una tabella (in una gestione straordinaria, quando l\'opera è finita): è una proposta per l\'assemblea, il programma non la chiede da solo.')
        ->and($guida)->toContain('<strong>Attesa conguaglio</strong> — registri lo sforo senza una rata: la differenza entra nel conguaglio per persona, calcolato nel rendiconto come proposta per l\'assemblea. Il programma non la chiede da solo.')
        ->and($conti)->toContain('<strong>A consuntivo</strong> — la differenza entra nel conguaglio per persona, calcolato nel rendiconto come proposta per l\'assemblea.')
        ->and($cruscotto)->toContain('Sfori senza una rata: coperti da un fondo di riserva, o lasciati al conguaglio per persona, calcolato nel rendiconto.')
        ->toContain('\'Entra nel conguaglio per persona, calcolato nel rendiconto\'')
        // Il cruscotto dice dove si legge.
        ->toContain('La differenza entra nel conguaglio per persona, se la voce ha una tabella (in una gestione straordinaria, quando l\'opera è finita): si legge nella scheda «Conguaglio» del rendiconto, come proposta da approvare dall\'assemblea.');
    // Nessuna di queste frasi dice «dovuto», «chiesto automaticamente» o «a chiusura esercizio».
    foreach ([$modale, $imprevista, $guida, $conti, $cruscotto] as $testo) {
        expect($testo)->not->toMatch('/conguaglio[^.]{0,80}\bdovut|chiest[oi] automaticamente|a chiusura esercizio/u');
    }
});

it('la stringa che prometteva il conguaglio di fine anno, e che nessuna pagina usa, non c\'è più in nessuna lingua', function () {
    expect(fcpCerca('/how_it_works_body_2/'))->toBe([]);
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(\Illuminate\Support\Arr::has(require lang_path("{$lingua}/gestionale.php"), 'piani_rate.move_modal.how_it_works_body_2'))->toBeFalse()
            ->and(\Illuminate\Support\Arr::has(require lang_path("{$lingua}/gestionale.php"), 'piani_rate.move_modal.how_it_works_body_1'))->toBeTrue();
    }
});

it('le frasi già giuste restano: «niente conguaglio di fine anno» dei piani, il conguaglio del consuntivo della nota di credito, il vincolo per chi costruirà la chiusura', function () {
    expect(fcpFile('resources/js/pages/gestionale/pianiRate/PianiRateShow.vue'))->toContain('niente «addebito manuale», niente «conguaglio di fine anno».')
        ->and(fcpFile('resources/js/components/guides/PianoRateDettaglioGuide.vue'))->toContain('nessun «conguaglio di fine anno» la sistema')
        ->and(fcpFile('app/Models/Gestionale/FatturaPassiva.php'))->toContain('di solito nel conguaglio del consuntivo')
        ->and(fcpFile('app/Services/Gestionale/FatturaPassivaService.php'))->toContain('NON C\'È UN RAMO per strategia_rientro === \'conguaglio_fine_anno\'')
        ->toContain('TODO(chiusura esercizio, non ancora costruita)');
});

/** Un condominio con la sola voce Pulizie (preventivo € 3.000,00), due unità da 500 millesimi, il piano approvato ed emesso il 31/01. */
function fcpPulizie($test): array
{
    vrmGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['pc'] = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '3.000,00');
    $s['piano'] = be52PianoDallaRotta($test, $s);
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-01-31');
    $ids = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), ['rate_ids' => $ids, 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])->assertSessionHasNoErrors();
    $s['fornitore'] = be52Fornitore();

    return $s;
}

it('«Attesa conguaglio» non chiede niente da solo: nessuna copertura, nessuna rata, nessun saldo; la fattura entra nella spesa, e il conguaglio per persona la propone', function () {
    $s = fcpPulizie($this);
    $prima = [DB::table('piani_rate')->count(), DB::table('rate')->count(), DB::table('rate_quote')->count(), DB::table('saldi')->count()];

    vrmGiorno($this, '2026-04-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-SF', [[$s['pulizie']->id, 3300.00]], '2026-04-10', ['dati_extra' => ['fiscal' => [], 'competenza' => null,
        'override_budget' => ['motivazione' => 'Pulizie straordinarie dopo i lavori del cortile', 'importo_sforo' => 30000, 'strategia_rientro' => 'conguaglio_fine_anno']]]);

    // La fattura è uno sforo motivato, e la strategia non scrive niente: né una copertura, né un piano, né una rata, né un saldo.
    expect($ft->stato_approvazione)->toBe('sforo_motivato')
        ->and($ft->coperture()->count())->toBe(0)
        ->and([DB::table('piani_rate')->count(), DB::table('rate')->count(), DB::table('rate_quote')->count(), DB::table('saldi')->count()])->toBe($prima);

    // Nel conguaglio per persona: spesa 3.300,00 / 2 = 1.650,00 a testa, quote 3.000,00 / 2 = 1.500,00, conguaglio +150,00 ciascuno; in tutto +300,00, lo sforo.
    $lettura = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $conguaglio = fn (string $chi) => array_sum(array_map(fn ($u) => (int) $u['conguaglio'], $lettura['persone'][$s['g']->id][$s['persone'][$chi]->id] ?? []));
    expect([$conguaglio('ugo'), $conguaglio('bice')])->toBe([15000, 15000]);
});
