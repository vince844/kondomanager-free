<?php

/**
 * 1.11.0-beta.55, Coda 245 — la fine di una locazione con un coinquilino che resta, dall'anteprima alla registrazione.
 *
 * Con un coinquilino che resta la scelta del conguaglio c'è, come nelle altre fini, senza preselezione (decisioni 72 e 77): «Scrivi il
 * conguaglio» scrive le righe della regola del piano (ognuno alla sua parte, `Beta55RegolaDelPianoTest`), «Non scriverlo» nessuna, e le
 * parti regolano fra loro con il prospetto degli oneri accessori. La scelta è una sola per l'unità e le pertinenze spuntate, anche quando
 * una unità ha il coinquilino e l'altra no. Il registro porta la fotografia della regola del piano (`conguaglio.piano`) e, per i giorni che
 * nessun conduttore copre, la parte del ripiego nei periodi della coppia (`conguaglio.periodi`); non porta più la fermata del coinquilino.
 *
 * I CONTI A MANO (centesimi; le cifre della regola sono quelle di `Beta55RegolaDelPianoTest`):
 * - C1: Ines − 6066, Dora + 6066. Il piano dopo: Ines 11934, Dora 24066, Ugo 84000.
 * - B6 (Dora chiusa il 30/9): Ines − 9861, Dora + 787, Ugo + 9074, la parte di Ugo sui 92 giorni dal 1/10.
 * - R20: Ines − 6066, Dora + 3033, Pia + 3033. E1: Ines + 8934 (debito), Dora − 8934.
 * - C7 (Interno 1 900/1000 con Ines e Dora, Box 12 100/1000 di Rita con Ines sola): sull'Interno 1 l'anello è 120000 × 0,9 × 30 % = 32400,
 *   congelato 16200 e 16200, dopo 32400 × 9050/27300 = 10740,66 → Ines 10741, Dora 21659: ∓ 5459. Sul box la fine senza coinquilini: la
 *   parte di Ines dopo l'uscita, 3600 × 184/365 = 1814,8 → 1815, a Rita.
 * - C7-bis (Interno 1 con Ines sola, il box con Ines e Dora): sull'Interno 1 32400 × 184/365 = 16333,15 → 16333 a Ugo; sul box l'anello
 *   3600, congelato 1800 e 1800, dopo 3600 × 9050/27300 = 1193,4 → Ines 1193, Dora 2407: ∓ 607.
 * - Il coinquilino senza quote: Ines 50 e Dora 30 (congelato 22500 e 13500), e Luca entra il 1/12 al 20 % su un piano che non si ricalcola
 *   più. Dopo l'uscita di Ines i pesi sono Ines 50 × 181 = 9050, Dora 30 × 365 = 10950, Luca 20 × 31 = 620 (somma 20620): 15800,19,
 *   19117,36, 1082,44 → 15800 + 19117 + 1082 = 35999, il centesimo al resto più grande (Luca, ,44) → Ines 15800, Dora 19117, Luca 1083.
 *   Differenze: Ines − 6700, Dora + 5617, Luca + 1083. Luca non ha quote del piano, e nei saldi non ha righe: la differenza di Ines va a
 *   chi la riceve e ha quote, Dora (+ 6700), e il resto (Dora € 10,83 sopra il piano, Luca € 10,83 sotto) lo regolano le parti.
 *
 * Cosa NON copre: i passaggi dopo questa fine (la fine di Dora, il cambio, l'inizio, la vendita sul ripiego); il prospetto; lo storico e
 * l'annullamento (in `Beta55CoinquilinoRegistriVecchiTest`); la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Le righe in saldi scritte dal passaggio, per persona e unità: «unità|nome» → centesimi. */
function ccpRighe(array $s, Subentro $fine): array
{
    $nomi = fclNomi($s);
    $out = [];
    foreach (DB::table('saldi')->whereIn('subentro_id', Subentro::where('id', $fine->id)->orWhere('subentro_padre_id', $fine->id)->pluck('id'))->get() as $r) {
        $chiave = ((int) $r->immobile_id === (int) $s['unita']->id ? 'unita' : 'box') . '|' . ($nomi[(int) $r->anagrafica_id] ?? '?');
        $out[$chiave] = ($out[$chiave] ?? 0) + (int) $r->saldo_iniziale;
    }
    ksort($out);

    return $out;
}

it('Coda 245 (coinquilino) — l\'anteprima propone la coppia della regola del piano e chiede la scelta; senza la scelta la registrazione si ferma sul campo', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(rdpCoppia($a))->toBe(['Dora' => 6066, 'Ines' => -6066])
        ->and($a['rate'])->not->toHaveKey('fermata_coinquilino');

    $errore = (string) (fclStore($this, $s, fclModulo($s))->getSession()->get('errors')?->first('conguaglio') ?? '');
    expect($errore)->toBe('Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.')
        ->and(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

it('Coda 245 (coinquilino) — il pannello dice la regola del piano con le cifre per persona, le due strade e chi rimborsa chi, senza la fermata del coinquilino', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    $frasi = fclFrasi($a);
    expect($a['rate']['frasi'][0])->toBe('Le rate già emesse non si toccano. La locazione continua con Dora: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno, come in un piano generato dopo l\'uscita. Sulla gestione Ordinaria 2026 Ines € 119,34 per i suoi 181 giorni e Dora € 240,66 per i suoi 365 giorni; nelle loro rate Ines ne ha € 180,00 e Dora € 180,00. Se scrivi il conguaglio, le righe nei saldi sono € 60,66 a credito di Ines e € 60,66 a debito di Dora. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e il prospetto degli oneri accessori dice che Dora rimborsa a Ines € 60,66.')
        ->and($a['rate']['frasi_del_conguaglio'])->toContain('Sulla gestione Ordinaria 2026, con la regola del piano: € 60,66 a credito di Ines e € 60,66 a debito di Dora.')
        ->and($frasi)->not->toContain('€ 90,74')
        ->and($frasi)->not->toContain('lo decidono le parti')
        ->and($frasi)->not->toContain('non propone il conguaglio')
        ->and($frasi)->not->toContain('€ -')
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('coinquilino');
});

it('Coda 245 (coinquilino) — C1 con «Scrivi»: le righe Ines − € 60,66 e Dora + € 60,66, il registro con la regola del piano e il piano preso', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();

    expect(ccpRighe($s, $fine))->toBe(['unita|Dora' => 6066, 'unita|Ines' => -6066])
        ->and(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000])
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and($fine->registro)->not->toHaveKey('fermata_coinquilino')
        ->and($fine->registro['piani_presi'])->toBe([(int) $s['piano']->id])
        ->and($fine->registro['coinquilini_letti'])->toBe([['immobile_id' => (int) $s['unita']->id, 'restano' => ['Dora']]]);
    $piano = $fine->registro['conguaglio']['piano'];
    $ines = (int) $s['persone']['Ines']->id;
    $dora = (int) $s['persone']['Dora']->id;
    expect($piano)->toHaveCount(1)
        ->and($piano[0])->toMatchArray(['gestione_id' => (int) $s['g']->id, 'immobile_id' => (int) $s['unita']->id, 'esercizio_id' => (int) $s['e']->id,
            'uscente_id' => $ines, 'coinquilini' => ['Dora'], 'segue_fine_id' => null, 'parti' => [$ines => -6066, $dora => 6066], 'resto' => [], 'ripiego' => []])
        ->and($piano[0]['voci'])->toHaveCount(1)
        ->and($piano[0]['voci'][0])->toMatchArray(['conto' => 'Spese generali', 'anello' => 36000, 'prima' => [$ines => 18000, $dora => 18000], 'dopo' => [$ines => 11934, $dora => 24066]])
        // Nessun giorno che nessun conduttore copre: i periodi della coppia non ci sono.
        ->and($fine->registro['conguaglio'])->not->toHaveKey('periodi');
});

it('Coda 245 (coinquilino) — B6 con «Scrivi»: tre righe, e nei periodi della coppia solo la parte del ripiego, di Ugo, sui giorni dal 1/10', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();

    expect(ccpRighe($s, $fine))->toBe(['unita|Dora' => 787, 'unita|Ines' => -9861, 'unita|Ugo' => 9074])
        ->and($fine->registro['conguaglio']['periodi'])->toBe([['gestione_id' => (int) $s['g']->id, 'immobile_id' => (int) $s['unita']->id, 'esercizio_id' => (int) $s['e']->id,
            'importo' => 9074, 'periodi' => [['tratti' => [['dal' => '2026-10-01', 'al' => '2026-12-31']], 'importo' => 9074]]]])
        ->and($fine->registro['conguaglio']['piano'][0]['ripiego'])->toBe([(int) $s['persone']['Ugo']->id]);
});

it('Coda 245 (coinquilino) — R20 ed E1 con «Scrivi»: due coinquiline; e un\'entrata che dà a chi esce un debito', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 25], ['Pia', 25]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(ccpRighe($s, $fine))->toBe(['unita|Dora' => 3033, 'unita|Ines' => -6066, 'unita|Pia' => 3033]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31']]]);
    $a = fclAnteprima($this, $s, fclModulo($s));
    // Il debito di chi esce si dice con le parole, mai con il meno.
    expect($a['rate']['frasi'][0])->toContain('le righe nei saldi sono € 89,34 a debito di Ines e € 89,34 a credito di Dora')
        ->and($a['rate']['frasi'][0])->toContain('il prospetto degli oneri accessori dice che Ines rimborsa a Dora € 89,34')
        ->and(fclFrasi($a))->not->toContain('€ -');
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(ccpRighe($s, $fine))->toBe(['unita|Dora' => -8934, 'unita|Ines' => 8934]);
});

it('Coda 245 (coinquilino) — C1 con «Non scriverlo»: nessuna riga, e ciò che le parti regolano fra loro per persona', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000])
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        ->and($fine->registro['conguaglio']['piano'][0]['parti'])->toBe([(int) $s['persone']['Ines']->id => -6066, (int) $s['persone']['Dora']->id => 6066])
        ->and($fine->registro['regolato_fuori'])->toBe([['gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'importo' => 6066, 'persone' => [
            ['anagrafica_id' => (int) $s['persone']['Ines']->id, 'nome' => 'Ines', 'importo' => -6066],
            ['anagrafica_id' => (int) $s['persone']['Dora']->id, 'nome' => 'Dora', 'importo' => 6066],
        ]]]);
});

it('Coda 245 (coinquilino) — C1 con «Scrivi» e poi «Annulla il conguaglio»: fuori dal cambio d\'inquilino ciò che le parti regolano fra loro resta per gestione e per persona, com\'era', function () {
    // Le cifre unità per unità (`per_unita`) le scrive solo il cambio d'inquilino, l'unico che il prospetto degli oneri accessori legge dal registro
    // (`RegolaDelPiano::regolatiDelCambio`): la fine di Ines conserva la forma della decisione 46, come con «Non scriverlo», Ines − 6066 e Dora + 6066.
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])
        ->assertStatus(302);

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and($fine->fresh()->registro['regolato_fuori'])->toBe([['gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'importo' => 6066, 'persone' => [
            ['anagrafica_id' => (int) $s['persone']['Ines']->id, 'nome' => 'Ines', 'importo' => -6066],
            ['anagrafica_id' => (int) $s['persone']['Dora']->id, 'nome' => 'Dora', 'importo' => 6066],
        ]]]);
});

it('Coda 245 (coinquilino) — C7: il coinquilino sull\'Interno 1 e il box di Rita con Ines sola; una scelta sola, due regole', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);

    expect(rdpCoppia($a, 'Ines', (int) $s['unita']->id))->toBe(['Dora' => 5459, 'Ines' => -5459])
        ->and(rdpCoppia($a, 'Ines', (int) $s['box']->id))->toBe(['Ines' => -1815, 'Rita' => 1815])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();
    // Le due frasi: quella dei giorni dopo l'uscita per il box, sulla sua sola cifra, e quella della regola del piano per l'Interno 1.
    expect($a['rate']['frasi'][0])->toContain('Se scrivi il conguaglio, i giorni dopo l\'uscita di Ines (€ 18,15) passano a chi paga al posto dell\'inquilino, Rita')
        ->and($a['rate']['frasi'][0])->toContain('Nell\'unità «Interno 1» la locazione continua con Dora');

    $fine = fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);
    expect(ccpRighe($s, $fine))->toBe(['box|Ines' => -1815, 'box|Rita' => 1815, 'unita|Dora' => 5459, 'unita|Ines' => -5459]);
});

it('Coda 245 (coinquilino) — C7-bis: il coinquilino solo sul box; sull\'Interno 1 la coppia dei giorni a Ugo, sul box quella della regola del piano', function () {
    $s = rdpScenario($this, [['Ines', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 50], ['Dora', 'inquilino', 50]]]);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);

    expect(rdpCoppia($a, 'Ines', (int) $s['unita']->id))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(rdpCoppia($a, 'Ines', (int) $s['box']->id))->toBe(['Dora' => 607, 'Ines' => -607])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();

    $fine = fclRegistra($this, $s, $modulo + ['conguaglio' => 'non_scrivere']);
    expect(fclRigheDeiPassaggi($s))->toBe(0);
    $figlio = Subentro::where('subentro_padre_id', $fine->id)->firstOrFail();
    // Il box legge la scelta dal padre (decisione 77); la regola del piano del box sta nel registro del padre, con l'unità.
    expect(collect($fine->fresh()->registro['conguaglio']['piano'])->pluck('immobile_id')->all())->toBe([(int) $s['box']->id])
        ->and($figlio->capo()->id)->toBe($fine->id);
});

it('Coda 245 (coinquilino) — il coinquilino senza quote: Luca, entrato su un piano che non si ricalcola più, non ha righe; la differenza di Ines va a Dora, e il resto lo regolano le parti', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 30]]);
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-12-01',
        'quota' => 20, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe(['Dora' => 6700, 'Ines' => -6700]);
    $luca = (int) $s['persone']['Luca']->id;
    $dora = (int) $s['persone']['Dora']->id;
    expect($a['rate']['conguaglio']['per_gestione'][0]['piano']['resto'])->toBe([$dora => -1083, $luca => 1083])
        ->and($a['rate']['frasi'][0])->toContain('Luca non ha quote del piano, e nei saldi non ha righe: con la regola del piano Dora pagherebbe € 10,83 in meno e Luca pagherebbe € 10,83 in più; la differenza la regolano le parti con il prospetto degli oneri accessori.');

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();
    expect(ccpRighe($s, $fine))->toBe(['unita|Dora' => 6700, 'unita|Ines' => -6700])
        ->and($fine->registro['conguaglio']['piano'][0]['resto'])->toBe([$dora => -1083, $luca => 1083]);
});

/** La voce dello storico dell'Interno 1 per un passaggio. */
function ccpStorico(array $s, Subentro $passaggio): array
{
    return collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
}

it('Coda 245 (coinquilino) — lo storico: con «Scrivi» il conguaglio è «proposto», € 60,66 a debito di Dora; con «Non scriverlo» «non scritto»; e la locazione continua con Dora', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $voce = ccpStorico($s, $fine);
    expect($voce['conguaglio']['stato'])->toBe('proposto')
        ->and($voce['conguaglio']['per_entrante'])->toBe(['€ 60,66 a debito di Dora'])
        ->and($voce['fermate'])->toBe([])
        ->and(implode(' ', $voce['obbligati']))->toContain('la locazione continua con Dora');

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    expect(ccpStorico($s, $fine)['conguaglio']['stato'])->toBe('non_scritto');
});

it('Coda 245 (coinquilino) — l\'annullamento: le righe si tolgono e i netti tornano 18000 e 18000; con «Non scriverlo», e con il conguaglio annullato, l\'avviso dice a ciascun conduttore la sua parte, per quota e per giorni', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000]);
    fclAnnulla($this, $s, $fine)->assertStatus(302);
    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    $avvisi = implode(' ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($fine->fresh()));
    expect($avvisi)->toContain('Il conguaglio della fine locazione di Ines non è stato scritto (€ 60,66 sulla gestione Ordinaria 2026): se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni.')
        ->and($avvisi)->not->toContain('a ciascuno i suoi giorni');

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])
        ->assertStatus(302)->assertSessionHas('message', fn ($m) => str_contains((string) $m['message'], 'Allora il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni'));
    $avvisi = implode(' ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($fine->fresh()));
    expect($avvisi)->toContain('il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni, e quell\'accordo va rifatto fra le parti')
        ->and($avvisi)->not->toContain('a chi paga al posto dell\'inquilino');
});

it('Coda 245 (coinquilino) — le bozze del piano che non si ricalcola più: «Scrivi il conguaglio» le ridivide con la regola del piano, e il cancello le dice comprese nel conguaglio, se lo scrivi', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(implode(' ', $a['obbligati']['frasi']))->toContain('Nel piano «Preventivo 2026», che non si ricalcola più, restano a Ines anche le 6 quote non ancora emesse: chi paga la parte dell\'inquilino dopo l\'uscita lo decide l\'amministratore con la scelta sul conguaglio («Scrivi il conguaglio» la ridivide con la regola del piano fra chi è stato conduttore, «Non scriverlo» la lascia nelle rate di Ines).')
        ->and(implode(' ', $a['cancello']['motivi']))->toContain('restano sue e sono comprese nel conguaglio, se lo scrivi')
        ->and(fclFrasi($a))->not->toContain('lo decidono le parti');
});

it('Coda 245 (coinquilino) — il piano preso da una fine con «Non scriverlo»: il rifiuto del ricalcolo dice che ricalcolando il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    $frase = (string) $s['piano']->fresh()->fraseConguagliato('Ricalcolarlo');

    expect($frase)->toContain('Il conguaglio della fine locazione di Ines non è stato scritto (€ 60,66 sulla gestione Ordinaria 2026): ricalcolando, il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni, e la posizione lasciata com\'era cambia.')
        ->and($frase)->not->toContain('a ciascuno i suoi giorni');
});

it('Coda 245 (coinquilino) — il coinquilino senza quote con due che ricevono: la differenza di Ines si divide fra Dora e Pia in proporzione alle loro differenze', function () {
    // Ines 40, Dora 30, Pia 10 (congelato 18000, 13500, 4500), e Luca entra il 1/12 al 20 % su un piano che non si ricalcola più. Dopo l'uscita i
    // pesi sono Ines 40 × 181 = 7240, Dora 30 × 365 = 10950, Pia 10 × 365 = 3650, Luca 20 × 31 = 620 (somma 22460): 11604,63, 17551,20, 5850,40,
    // 993,77 → 11604 + 17551 + 5850 + 993 = 35998, i due centesimi ai resti più grandi (Luca ,77 e Ines ,63) → Ines 11605, Dora 17551, Pia 5850,
    // Luca 994. Differenze: Ines − 6395, Dora + 4051, Pia + 1350, Luca + 994. I 6395 di Ines si dividono fra Dora e Pia in proporzione a 4051 e
    // 1350 (somma 5401): 4796,55 e 1598,45 → 4796 + 1598 = 6394, il centesimo a Dora (,55) → Dora + 4797, Pia + 1598. Il resto: Dora − 746, Pia
    // − 248, Luca + 994.
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 10]]);
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-12-01',
        'quota' => 20, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe(['Dora' => 4797, 'Ines' => -6395, 'Pia' => 1598])
        ->and($a['rate']['conguaglio']['per_gestione'][0]['piano']['resto'])->toBe([(int) $s['persone']['Dora']->id => -746, (int) $s['persone']['Pia']->id => -248, (int) $s['persone']['Luca']->id => 994]);
});
