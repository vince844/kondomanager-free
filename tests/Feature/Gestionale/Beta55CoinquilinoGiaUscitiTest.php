<?php

/**
 * 1.11.0-beta.55, Coda 245 — la prima fine con la regola del piano quando un conduttore era già uscito senza una coppia della regola del piano.
 *
 * Nella prima fine con un coinquilino che resta la regola del piano ridivide la parte «Inquilino» congelata dal piano. Chi era già uscito (la
 * sua riga d'inquilino chiusa prima della generazione del piano, dalla pagina «Registra passaggio» o da «Modifica associazione», o con un
 * registro delle beta che ha la fermata del coinquilino) ha quote del piano, ma non riceve i giorni di chi esce: la sua parte non si tocca,
 * perché la sua locazione è già finita. Se la regola del piano gliela cambia, si scrive solo la differenza di chi esce, divisa fra chi riceve i
 * suoi giorni in proporzione alle loro differenze di verso opposto, e il resto si dice con la persona e la cifra, con la spunta e la strada
 * del Wallet (`resto_del_piano`), come nei passaggi dopo una fine con la regola del piano. Se chi era già uscito non cambia, si scrivono le
 * differenze di tutti, come prima.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni; «Spese generali» € 1.200,00 al 30 % all'inquilino: l'anello dell'Interno 1 è 36000; Ines 40,
 * Dora 30, Pia 30; Ugo proprietario; la fine di Dora il 1/10, 273 giorni, e Pia resta; i pesi sono quota × giorni, e Pia copre tutto l'anno):
 * - Ines chiusa il 30/6 (181 giorni) prima della generazione: congelato 40 × 181 = 7240, 30 × 365 = 10950 e 10950 (somma 29140): 8944,41,
 *   13527,80, 13527,80 → Ines 8944, Dora 13528, Pia 13528 (i due centesimi a Dora e a Pia, ,80 contro ,41). Dopo la fine di Dora: 7240, 30 × 273
 *   = 8190, 10950 (somma 26380): 9880,21, 11176,65, 14943,14 → Ines 9880, Dora 11177 (il centesimo a lei, ,65), Pia 14943. Differenze: Ines + 936,
 *   Dora − 2351, Pia + 1415. Ines è già uscita e cambia: si scrive la differenza di Dora, − 2351, verso chi riceve i suoi giorni con una
 *   differenza di verso opposto, Pia (+ 1415): Dora − 2351, Pia + 2351. Resto: Ines + 936 (pagherebbe € 9,36 in più), Pia 1415 − 2351 = − 936.
 *   Netti dopo «Scrivi»: Ines 8944, Dora 13528 − 2351 = 11177, Pia 13528 + 2351 = 15879.
 * - Ines chiusa il 31/3 (90 giorni): congelato 40 × 90 = 3600, 10950, 10950 (somma 25500): 5082,35, 15458,82, 15458,82 → 5082, 15459, 15459. Dopo:
 *   3600, 8190, 10950 (somma 22740): 5699,21, 12965,70, 17335,09 → Ines 5699, Dora 12966 (,70), Pia 17335. Differenze: Ines + 617, Dora − 2493,
 *   Pia + 1876: Dora − 2493, Pia + 2493; resto Ines + 617, Pia − 617.
 * - La fine di Ines registrata da una beta con la fermata del coinquilino (nessuna coppia: le rate restano quelle del piano, 40 × 365, 30 × 365,
 *   30 × 365 → 14400, 10800, 10800). Dopo la fine di Dora: 9880, 11177, 14943, come sopra. Differenze: Ines − 4520, Dora + 377, Pia + 4143. La
 *   differenza di Dora è un debito, e chi riceve i suoi giorni, Pia, ha anche lei un debito: nessuno la riceve, nessuna riga. Il resto è tutto:
 *   Dora + 377, Ines − 4520, Pia + 4143.
 * - Il controllo, la fine di Ines registrata con la regola del piano e poi quella di Dora, è C3-Dora (`Beta55CoinquilinoDopoLaCoppiaTest`): le stesse
 *   cifre del primo caso, Dora − 2351 → Pia e il resto Ines + 936 / Pia − 936.
 *
 * Cosa NON copre: la pagina Vue (la voce «Scrivi» per `piano_coppia` è in `AnteprimaPassaggioCoinquilinoPiano.test.ts`); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';
require_once __DIR__.'/Beta53SaldoLegatoScenari.php';

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

/** Il modulo della fine della locazione di `$chi` il giorno dato, con la quota della sua riga. */
function pfgModuloFine(array $s, string $chi, string $il): array
{
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->value('quota');

    return ['quota' => $quota] + fclModulo($s, null, $il, [], $chi);
}

/**
 * Ines 40, Dora 30, Pia 30: la fine di Ines il 1/7 registrata dalla pagina «Registra passaggio» prima che il preventivo sia generato (nessun piano da
 * conguagliare: la riga di Ines si chiude al 30/6), poi la generazione e le rate da gennaio a giugno a giornale.
 */
function pfgInesUscitaPrima($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 40], ['Dora', 30], ['Pia', 30]]]);
    $fine = fclRegistra($test, $s, pfgModuloFine($s, 'Ines', '2026-07-01'));
    expect($fine->registro)->not->toHaveKey('conguaglio')
        ->and(DB::table('anagrafica_immobile')->where('id', $fine->riga_uscente_id)->value('data_fine'))->toStartWith('2026-06-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Il resto della regola del piano dell'anteprima, in breve: nome → cifra con il segno dei saldi, gruppo per gruppo. */
function pfgResto(array $anteprima): array
{
    return array_map(fn (array $r) => collect($r['parti'])->mapWithKeys(fn (array $p) => [(string) $p['nome'] => (int) $p['cifra']])->sortKeys()->all(),
        $anteprima['rate']['resto_del_piano'] ?? []);
}

/** La sezione di un conduttore nel prospetto degli oneri accessori: [totale, nelle sue rate, con il conguaglio, da rimborsare], e chi rimborsa chi. */
function pfgProspetto(array $s, string $nome): array
{
    $c = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);
    $rimborsi = [];
    foreach ($c['regola_del_piano']['rimborsi'] ?? [] as $r) {
        $rimborsi[$r['verso'] . ' ' . $r['nome']] = (int) $r['importo'];
    }

    return [[(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['con_il_conguaglio'], (int) $c['da_rimborsare']], $rimborsi];
}

it('Coda 245 (chi era già uscito) — Ines chiusa il 30/6 dalla pagina prima della generazione: la fine di Dora scrive solo la sua coppia, − € 23,51 a Pia; la parte di Ines non si tocca, e il resto si dice con la spunta e la strada del Wallet', function () {
    $s = pfgInesUscitaPrima($this);
    $ines = (int) $s['persone']['Ines']->id;
    $dora = (int) $s['persone']['Dora']->id;
    $pia = (int) $s['persone']['Pia']->id;
    expect(fclNetti($s))->toBe(['Dora' => 13528, 'Ines' => 8944, 'Pia' => 13528, 'Ugo' => 84000]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        // Era: Dora − 2351, Ines + 936, Pia + 1415 (un debito nuovo a chi aveva già finito la locazione, in silenzio).
        ->and(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -2351, 'Pia' => 2351])
        ->and($a['rate']['conguaglio']['regola'])->toBe('piano_coppia')
        ->and(pfgResto($a))->toBe([['Ines' => 936, 'Pia' => -936]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => null, 'persona' => 'Dora', 'persona_id' => $dora, 'immobile_id' => (int) $s['unita']->id,
            'gestione_id' => (int) $s['g']->id, 'esercizio_id' => (int) $s['e']->id, 'dal' => '2026-10-01', 'cifra' => 936, 'gia_usciti' => ['Ines'],
            'wallet' => true, 'strada' => FermataDopoScrivi::STRADA_LEGATA])
        ->and($a['rate']['frasi'][0])->toContain('nelle loro rate Dora ne ha € 135,28, Ines € 89,44 e Pia € 135,28. Se scrivi il conguaglio, il programma scrive solo la coppia di Dora: € 23,51 a credito di Dora e € 23,51 a debito di Pia. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 9,36 in più, e Pia € 9,36 in meno. Se le parti la vogliono al piano, registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 9,36 a debito di Ines e € 9,36 a credito di Pia. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and(fclFrasi($a))->not->toContain('Ines non ha quote del piano')
        ->and($a['cancello']['motivi'])->toContain('se scrivi il conguaglio, € 9,36 della regola del piano restano a debito di Ines e a credito di Pia (gestione Ordinaria 2026): la fine della locazione di Dora scrive solo la sua coppia');

    // Con «Non scriverlo» nei saldi non si scrive niente: il resto non è un motivo del cancello, e non va nel registro.
    $n = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'non_scrivere']);
    expect($n['rate']['resto_del_piano'])->toBe([])
        ->and(implode(' ', $n['cancello']['motivi']))->not->toContain('€ 9,36');

    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fds50Coppia($s, $fine))->toBe(['Dora' => -2351, 'Pia' => 2351])
        ->and(fclNetti($s))->toBe(['Dora' => 11177, 'Ines' => 8944, 'Pia' => 15879, 'Ugo' => 84000])
        ->and($fine->registro['conguaglio']['piano'][0])->toMatchArray(['segue_fine_id' => null, 'uscente_id' => $dora,
            'parti' => [$dora => -2351, $pia => 2351], 'resto' => [$ines => 936, $pia => -936], 'gia_usciti' => [$ines]])
        ->and($fine->registro['resto_del_piano'])->toHaveCount(1)
        ->and(collect($fine->registro['resto_del_piano'][0]['parti'])->pluck('cifra', 'nome')->all())->toBe(['Ines' => 936, 'Pia' => -936]);
});

it('Coda 245 (chi era già uscito) — Ines chiusa il 31/3 a mano prima della generazione: Dora − € 24,93 a Pia, e Ines pagherebbe € 6,17 in più', function () {
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]], ['fini' => ['Ines' => '2026-03-31']]);
    expect(fclNetti($s))->toBe(['Dora' => 15459, 'Ines' => 5082, 'Pia' => 15459, 'Ugo' => 84000]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -2493, 'Pia' => 2493])
        ->and(pfgResto($a))->toBe([['Ines' => 617, 'Pia' => -617]])
        ->and($a['rate']['frasi'][0])->toContain('La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 6,17 in più, e Pia € 6,17 in meno.');

    fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi']);
    expect(fclNetti($s))->toBe(['Dora' => 12966, 'Ines' => 5082, 'Pia' => 17952, 'Ugo' => 84000]);
});

it('Coda 245 (chi era già uscito) — i lettori del resto della prima fine: lo storico, il Wallet, l\'annullamento, «Annulla il conguaglio» e il prospetto degli oneri accessori', function () {
    $s = pfgInesUscitaPrima($this);
    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();

    $frase = 'Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Dora € 111,77, Ines € 98,80 e Pia € 149,43: il conguaglio ha scritto solo la coppia di Dora, € 23,51 a credito di Dora e € 23,51 a debito di Pia, e la parte di Ines è rimasta € 9,36 sotto il piano, quella di Pia € 9,36 sopra. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 9,36 a debito di Ines e € 9,36 a credito di Pia.';
    expect(fds50Storico($s, $fine)['fermate'])->toBe([$frase])
        ->and(fds50Storico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 9,36 a debito di Ines e € 9,36 a credito di Pia: annullandolo quella parte non c\'è più; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.');

    // Il prospetto: Ines è € 9,36 sotto il piano e li rimborsa a Pia; la coppia di Dora è il conguaglio.
    expect(pfgProspetto($s, 'Ines'))->toBe([[9880, 8944, 0, 936], ['a Pia' => 936]])
        ->and(pfgProspetto($s, 'Dora'))->toBe([[11177, 13528, -2351, 0], []])
        ->and(pfgProspetto($s, 'Pia'))->toBe([[14943, 13528, 2351, -936], ['da Ines' => 936]]);

    // La pagina dei saldi segna la fine di Dora con una parte da regolare a mano; la strada del Wallet, seguita, è accettata.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeTrue()
        ->and(sl53Errori(sl53Posta($this, $s, 'Ines', 936, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Pia', -936, (int) $fine->id)))->toBe([]);
    expect(fds50Storico($s, $fine)['fermate'])->toBe([$frase . ' I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 9,36 a debito di Ines e € 9,36 a credito di Pia: sono già il resto della regola del piano.'])
        ->and(fds50Storico($s, $fine)['conguaglio']['avvisi'])->toBe(['Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 9,36 a debito di Ines e € 9,36 a credito di Pia: sono già il resto della regola del piano di questo passaggio.'])
        ->and(fds50Storico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 9,36 a debito di Ines e € 9,36 a credito di Pia: annullandolo quella parte non c\'è più, e i saldi del Wallet legati a questo passaggio si tolgono con lui.');

    // «Annulla il conguaglio» della fine di Dora: le parti hanno regolato fra loro la sua coppia (Dora − 2351, Pia + 2351), e il resto, che non è nella coppia,
    // resta da regolare: Ines 8944 contro 9880 deve ancora € 9,36 a Pia. Cambiato con la correzione del resto dopo «Annulla il conguaglio»: prima lo storico
    // diceva che il resto «non vale più», e la pagina dei saldi non segnava più la fine di Dora, mentre il prospetto lo fa ancora rimborsare.
    sl53AnnullaConguaglio($this, $s, $fine)->assertStatus(302);
    expect(fds50Storico($s, $fine->fresh())['fermate'])->toBe(['Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Dora € 111,77, Ines € 98,80 e Pia € 149,43: il conguaglio ha scritto solo la coppia di Dora, € 23,51 a credito di Dora e € 23,51 a debito di Pia, e la parte di Ines è rimasta € 9,36 sotto il piano, quella di Pia € 9,36 sopra. Il conguaglio di questo passaggio è stato annullato: la coppia di Dora l\'hanno regolata fra loro le parti, e ciò che la regola del piano ha lasciato fuori resta da regolare. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 9,36 a debito di Ines e € 9,36 a credito di Pia. I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 9,36 a debito di Ines e € 9,36 a credito di Pia: sono già il resto della regola del piano.'])
        ->and(fds50Storico($s, $fine->fresh())['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 9,36 a debito di Ines e € 9,36 a credito di Pia: annullandolo quella parte non c\'è più, e i saldi del Wallet legati a questo passaggio si tolgono con lui.')
        ->and(pfgProspetto($s, 'Ines'))->toBe([[9880, 8944, 0, 936], ['a Pia' => 936]]);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeTrue();
});

/**
 * La fine di Ines il 1/7 con il registro come lo scrivevano le beta .50–.53: la fermata del coinquilino (la parte di Ines dopo l'uscita per giorni, `$cifra`), nessuna
 * coppia, e le rate restano quelle del piano. Dalla rotta vera, poi il registro riscritto com'era.
 */
function pfgFineDelleBeta($test, array $s, int $cifra): Subentro
{
    $vecchia = fclRegistra($test, $s, pfgModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'non_scrivere'])->fresh();
    $registro = $vecchia->registro;
    unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti']);
    $registro['piani_presi'] = [];
    $registro['fermata_coinquilino'] = [[
        'immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'esercizio_id' => (int) $s['e']->id,
        'cifra' => $cifra, 'persona_id' => (int) $s['persone']['Ines']->id, 'persona' => 'Ines', 'restano' => ['Dora', 'Pia'], 'al_posto' => ['Ugo'], 'wallet' => true, 'strada' => 'legata',
    ]];
    $vecchia->registro = $registro;
    $vecchia->save();

    return $vecchia;
}

/** La frase della decisione 111.2 per chi era già uscito con una fine delle beta: nel pannello, prima di registrare la fine di Dora. */
const PFG_BETA_PANNELLO = 'La fine della locazione di Ines del 1 luglio 2026 è stata registrata da una versione precedente con la fermata del coinquilino: i giorni dopo la sua uscita sono rimasti nelle sue rate, e chi li paga lo decidono le parti. Per avere la regola del piano anche lì, annulla dallo storico dell\'unità la fine di Ines e registrala di nuovo, prima di questa; altrimenti la differenza la regolano le parti';

it('Coda 245 (chi era già uscito) — la fine di Ines registrata da una beta con la fermata del coinquilino: la fine di Dora non scrive niente, perché chi riceve i suoi giorni pagherebbe di più come lei; il resto si dice tutto, senza la strada del Wallet, con la strada della decisione 111.2', function () {
    // Cambiato con la decisione 115.2: prima il pannello offriva la strada del Wallet («Se le parti la vogliono al piano, registra dal Wallet i saldi legati a
    // questo passaggio …»), il registro la conservava (`wallet` vero, strada legata) e la pagina dei saldi segnava il passaggio, mentre la fermata del
    // coinquilino della fine di Ines (€ 72,59, lo decidono le parti) parla degli stessi giorni. Ora la frase dice il resto con le persone e le cifre e che per
    // la regola del piano si annulla la fine di Ines e la si registra di nuovo; la spunta resta, come presa d'atto.
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]]);
    // 14400 × 184 / 365 = 7259,18 → 7259.
    $vecchia = pfgFineDelleBeta($this, $s, 7259);
    expect(fclNetti($s))->toBe(['Dora' => 10800, 'Ines' => 14400, 'Pia' => 10800, 'Ugo' => 84000]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    // Era: Dora + 377, Ines − 4520, Pia + 4143 (un credito di € 45,20 a chi era uscito, pagato da Pia e da Dora).
    expect(fclCoppie($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(pfgResto($a))->toBe([['Dora' => 377, 'Ines' => -4520, 'Pia' => 4143]])
        ->and($a['rate']['frasi'][0])->toContain('Nei saldi il programma non scrive niente: con la regola del piano anche Pia, che riceve i giorni di Dora, pagherebbe di più, e fra loro non c\'è una coppia da scrivere. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 45,20 in meno, Dora € 3,77 in più, e Pia € 41,43 in più. ' . PFG_BETA_PANNELLO . '.')
        ->and(fclFrasi($a))->not->toContain('Wallet')
        ->and(fclFrasi($a))->not->toContain('Se scrivi il conguaglio')
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['gia_usciti' => ['Ines'], 'wallet' => false, 'cifra' => 4520,
            'fini_delle_beta' => [['id' => (int) $vecchia->id, 'persona' => 'Ines', 'il' => '2026-07-01']]])
        ->and($a['rate']['resto_del_piano'][0])->not->toHaveKey('strada')
        // La spunta resta: ogni fermata la chiede.
        ->and($a['cancello']['motivi'])->toContain('con la regola del piano la fine della locazione di Dora non scrive righe nei saldi, e restano da regolare a mano € 45,20 a credito di Ines, € 3,77 a debito di Dora e € 41,43 a debito di Pia (gestione Ordinaria 2026)');

    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh();
    expect(fds50Coppia($s, $fine))->toBe([])
        ->and(fclNetti($s))->toBe(['Dora' => 10800, 'Ines' => 14400, 'Pia' => 10800, 'Ugo' => 84000])
        ->and(collect($fine->registro['resto_del_piano'][0]['parti'])->pluck('cifra', 'nome')->all())->toBe(['Ines' => -4520, 'Dora' => 377, 'Pia' => 4143])
        ->and($fine->registro['resto_del_piano'][0]['wallet'])->toBeFalse()
        ->and($fine->registro['resto_del_piano'][0])->not->toHaveKey('strada')
        ->and($fine->registro['resto_del_piano'][0]['fini_delle_beta'])->toBe([['id' => (int) $vecchia->id, 'persona' => 'Ines', 'il' => '2026-07-01']])
        // Dopo una fine delle beta con la fermata del coinquilino di chi era già uscito il resto si dice e basta: la regola del piano non va nel registro, e i
        // passaggi dopo non la seguono (la fermata di Ines e il resto parlano degli stessi giorni).
        ->and($fine->registro)->not->toHaveKey('conguaglio')
        ->and(fds50Storico($s, $fine)['fermate'])->toBe(['Con questa fine, sulla gestione Ordinaria 2026 (Interno 1), nei saldi non è stato scritto niente, e la parte di Ines è rimasta € 45,20 sopra il piano, quella di Dora € 3,77 sotto, quella di Pia € 41,43 sotto. La fine della locazione di Ines del 1 luglio 2026 è stata registrata da una versione precedente con la fermata del coinquilino: per avere la regola del piano anche lì, annulla dallo storico dell\'unità prima questa fine, poi quella di Ines, e registrale di nuovo; altrimenti la differenza la regolano le parti.'])
        ->and(implode(' ', fds50Storico($s, $fine)['annullabile']['avvisi']))->not->toContain('€ 45,20');
    // La pagina dei saldi non segna la fine di Dora con una parte da regolare a mano: la strada non è il Wallet.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeFalse();
});

it('Coda 245 (chi era già uscito) — dopo la fine delle beta, la strada della decisione 111.2 si fa dalla pagina: annullate la fine di Dora e quella di Ines, la fine di Ines registrata di nuovo ha la regola del piano', function () {
    // C3: Ines 40 esce il 1/7, Dora e Pia restano: 7240 / 10950 / 10950 su 29140: 8944,41, 13527,80, 13527,80 → 8944, 13528, 13528 (i centesimi a Dora e a Pia):
    // Ines − 5456, Dora + 2728, Pia + 2728. Poi la fine di Dora è un passaggio dopo sull'anello (C3-Dora): Dora − 2351 → Pia, il resto Ines + 936, Pia − 936.
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]]);
    $vecchia = pfgFineDelleBeta($this, $s, 7259);
    $dora = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh();

    fclAnnulla($this, $s, $dora)->assertSessionHasNoErrors();
    fclAnnulla($this, $s, $vecchia)->assertSessionHasNoErrors();
    expect(Subentro::whereKey($vecchia->id)->exists())->toBeFalse()
        ->and(Subentro::whereKey($dora->id)->exists())->toBeFalse();

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Ines', '2026-07-01'));
    expect(rdpCoppia($a))->toBe(['Dora' => 2728, 'Ines' => -5456, 'Pia' => 2728])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();
    fclRegistra($this, $s, pfgModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi']);
    $b = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($b, 'Dora'))->toBe(['Dora' => -2351, 'Pia' => 2351])
        ->and(pfgResto($b))->toBe([['Ines' => 936, 'Pia' => -936]]);
});

it('Coda 245 (chi era già uscito) — dopo la fine delle beta, anche dove ci sarebbe una coppia (Dora − € 9,91 a Pia) la fine di Dora non scrive niente: il resto si dice tutto, e il prospetto resta alla regola dei giorni, con i saldi legati alla fine delle beta', function () {
    // Ines 20, Dora 30, Pia 50: le rate 7200, 10800, 18000; la fermata delle beta di Ines 7200 × 184 / 365 = 3629,59 → 3630. La fine di Dora il 1/10 (273 giorni):
    // 20 × 181 = 3620, 30 × 273 = 8190, 50 × 365 = 18250 (somma 30060): 4335,33, 9808,38, 21856,29 → Ines 4335, Dora 9809 (il centesimo a lei, ,38), Pia 21856.
    // Differenze: Ines − 2865, Dora − 991, Pia + 3856, tutte resto. Cambiato (decisione 111.2: dopo una fine delle beta con la fermata del coinquilino «il prospetto resta a
    // giorni per Ines, un modello solo sull'unità, com'era registrato»): prima la fine di Dora scriveva la coppia Dora − 991 → Pia e la regola del piano nel registro, e il
    // prospetto leggeva tutto il piano con la regola del piano, Ines compresa: «da restituire a Ines da Pia € 28,65», per gli stessi giorni della fermata delle beta (€ 36,30),
    // senza più dire i suoi saldi legati; seguita la strada delle beta (i saldi legati Ines − 3630, Dora + 1361, Pia + 2269: 3630 × 30/80 = 1361,25, × 50/80 = 2268,75), Pia
    // pagava € 28,65 non dovuti. Ora il prospetto resta alla regola dei giorni: Ines [7200, 7200, 0, 0] e i suoi saldi legati, Dora e Pia con le loro rate.
    foreach ([false, true] as $conLegate) {
        $s = rdpScenario($this, [['Ines', 20], ['Dora', 30], ['Pia', 50]]);
        $vecchia = pfgFineDelleBeta($this, $s, 3630);
        if ($conLegate) {
            foreach (['Ines' => -3630, 'Dora' => 1361, 'Pia' => 2269] as $chi => $cifra) {
                expect(sl53Errori(sl53Posta($this, $s, $chi, $cifra, (int) $vecchia->id)))->toBe([]);
            }
        }

        $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
        expect(fclCoppie($a))->toBe([], $conLegate ? 'con le legate' : 'senza')
            ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
            ->and(pfgResto($a))->toBe([['Dora' => -991, 'Ines' => -2865, 'Pia' => 3856]])
            ->and($a['rate']['frasi'][0])->toContain('Nei saldi il programma non scrive niente: dopo una fine registrata da una versione precedente con la fermata del coinquilino la regola del piano si dice, e non si scrive. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 28,65 in meno, Dora € 9,91 in meno, e Pia € 38,56 in più. ' . PFG_BETA_PANNELLO . '.')
            ->and($a['rate']['frasi'][0])->not->toContain('Wallet')->and($a['rate']['frasi'][0])->not->toContain('chi riceve i giorni di Dora')
            ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['wallet' => false, 'fini_delle_beta' => [['id' => (int) $vecchia->id, 'persona' => 'Ines', 'il' => '2026-07-01']]])
            ->and($a['cancello']['motivi'])->toContain('con la regola del piano la fine della locazione di Dora non scrive righe nei saldi, e restano da regolare a mano € 28,65 a credito di Ines, € 9,91 a credito di Dora e € 38,56 a debito di Pia (gestione Ordinaria 2026)');

        $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh();
        expect(fds50Coppia($s, $fine))->toBe([])
            ->and($fine->registro)->not->toHaveKey('conguaglio')
            ->and($fine->registro['resto_del_piano'][0]['wallet'])->toBeFalse()
            ->and(implode(' ', fds50Storico($s, $fine)['fermate']))->toEndWith('La fine della locazione di Ines del 1 luglio 2026 è stata registrata da una versione precedente con la fermata del coinquilino: per avere la regola del piano anche lì, annulla dallo storico dell\'unità prima questa fine, poi quella di Ines, e registrale di nuovo; altrimenti la differenza la regolano le parti.');
        expect(pfgProspetto($s, 'Ines'))->toBe([[7200, 7200, 0, 0], []])
            ->and(pfgProspetto($s, 'Dora'))->toBe([[10800, 10800, 0, 0], []])
            ->and(pfgProspetto($s, 'Pia'))->toBe([[18000, 18000, 0, 0], []]);
        $stampa = fclStampa($s);
        expect($stampa)->not->toContain('28,65');
        if ($conLegate) {
            expect($stampa)->toContain('saldi del Wallet legati a quella fine: € 36,30 a credito di Ines, € 13,61 a debito di Dora e € 22,69 a debito di Pia');
        }
        $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
        expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeFalse();
    }
});

it('Coda 245 (chi era già uscito) — due fini delle beta con la fermata del coinquilino, di Ines e di Pia: la fine di Dora le nomina tutte e due, e la strada della decisione 111.2 le annulla tutte e due', function () {
    // Scritto dopo il codice (il rosso sono le mutazioni delle frasi al plurale). Ines, Pia, Dora ed Eva al 25 %: le rate 9000 ciascuna. Ines esce il 1/7 e Pia il 1/8
    // con le fini delle beta (le fermate 9000 × 184 / 365 = 4536,99 → 4537 e 9000 × 153 / 365 = 3772,60 → 3773). La fine di Dora il 1/10 con Eva che resta:
    // 25 × 181 = 4525, 25 × 212 = 5300, 25 × 273 = 6825, 25 × 365 = 9125 (somma 25775): 6320,08, 7402,52, 9532,49, 12744,91 → Ines 6320, Pia 7403, Dora 9532,
    // Eva 12745 (i due centesimi a Eva, ,91, e a Pia, ,52). Differenze: Ines − 2680, Pia − 1597, Dora + 532, Eva + 3745: Eva, che riceve i giorni di Dora,
    // pagherebbe di più come lei, e non c'è una coppia; il resto è tutto.
    $s = rdpScenario($this, [['Ines', 25], ['Pia', 25], ['Dora', 25], ['Eva', 25]]);
    $beta = function (string $chi, string $il, int $cifra, array $restano) use ($s): Subentro {
        $f = fclRegistra($this, $s, pfgModuloFine($s, $chi, $il) + ['conguaglio' => 'non_scrivere'])->fresh();
        $registro = $f->registro;
        unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti'], $registro['resto_del_piano']);
        $registro['piani_presi'] = [];
        $registro['fermata_coinquilino'] = [[
            'immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'esercizio_id' => (int) $s['e']->id,
            'cifra' => $cifra, 'persona_id' => (int) $s['persone'][$chi]->id, 'persona' => $chi, 'restano' => $restano, 'al_posto' => ['Ugo'], 'wallet' => true, 'strada' => 'legata',
        ]];
        $f->registro = $registro;
        $f->save();

        return $f;
    };
    $ines = $beta('Ines', '2026-07-01', 4537, ['Pia', 'Dora', 'Eva']);
    $pia = $beta('Pia', '2026-08-01', 3773, ['Dora', 'Eva']);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect(fclCoppie($a))->toBe([])
        ->and(pfgResto($a))->toBe([['Dora' => 532, 'Eva' => 3745, 'Ines' => -2680, 'Pia' => -1597]])
        ->and($a['rate']['frasi'][0])->toContain('Le fini della locazione di Ines del 1 luglio 2026 e di Pia del 1 agosto 2026 sono state registrate da una versione precedente con la fermata del coinquilino: i giorni dopo la loro uscita sono rimasti nelle loro rate, e chi li paga lo decidono le parti. Per avere la regola del piano anche lì, annulla dallo storico dell\'unità, dall\'ultima, le fini di Pia e di Ines e registrale di nuovo, prima di questa; altrimenti la differenza la regolano le parti.')
        ->and($a['rate']['resto_del_piano'][0]['fini_delle_beta'])->toBe([['id' => (int) $ines->id, 'persona' => 'Ines', 'il' => '2026-07-01'], ['id' => (int) $pia->id, 'persona' => 'Pia', 'il' => '2026-08-01']]);
    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh();
    expect(implode(' ', fds50Storico($s, $fine)['fermate']))->toEndWith('Le fini della locazione di Ines del 1 luglio 2026 e di Pia del 1 agosto 2026 sono state registrate da una versione precedente con la fermata del coinquilino: per avere la regola del piano anche lì, annulla dallo storico dell\'unità prima questa fine, poi quelle di Pia e di Ines, dall\'ultima, e registrale di nuovo; altrimenti la differenza la regolano le parti.');
});

it('Coda 245 (chi era già uscito) — Ines chiusa prima della generazione e Luca entrato su un piano che non si ricalcola più: la fine di Dora non scrive niente, perché chi riceve i suoi giorni non ha quote del piano, e non dà a Ines la sua differenza', function () {
    // Ines 40 chiusa il 30/6, Dora 60: congelato 40 × 181 = 7240 e 60 × 365 = 21900 (somma 29140): 8944,41 e 27055,59 → Ines 8944, Dora 27056. Luca entra
    // il 1/9 al 40 % (122 giorni), senza quote. Dora esce il 1/10 (273 giorni) e Luca resta: 7240, 60 × 273 = 16380, 40 × 122 = 4880 (somma 28500):
    // 9145,26, 20690,53, 6164,21 → Ines 9145, Dora 20691 (il centesimo a lei), Luca 6164. Differenze: Ines + 201, Dora − 6365, Luca + 6164. Chi riceve i
    // giorni di Dora è Luca, che non ha quote: nessuna riga, e il resto si dice, con il prospetto. Prima: Dora − 6365 → Ines + 6365, a chi era uscito.
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 60]], ['fini' => ['Ines' => '2026-06-30']]);
    fclPersona($s, 'Luca');
    expect(fclNetti($s))->toBe(['Dora' => 27056, 'Ines' => 8944, 'Ugo' => 84000]);
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-09-01', 'quota' => 40,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect(fclCoppie($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(pfgResto($a))->toBe([['Dora' => -6365, 'Ines' => 201, 'Luca' => 6164]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['gia_usciti' => ['Ines'], 'wallet' => false])
        ->and($a['rate']['frasi'][0])->toContain('Nei saldi il programma non scrive niente: chi riceve i giorni di Dora, Luca, non ha quote del piano. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 2,01 in più, Dora € 63,65 in meno, e Luca € 61,64 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.')
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('€ 63,65');

    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh();
    // Cambiato con la regola del piano nel registro anche senza la coppia: prima il registro non l'aveva, il prospetto restava alla strada dei giorni («lo decidono
    // le parti») e le frasi non rimandavano al prospetto. Ora la fine è l'anello dei passaggi dopo, e il prospetto la legge: Ines 9145 contro 8944 nelle sue rate
    // rimborsa a Dora € 2,01, Luca (6164, nessuna rata) € 61,64; Dora 20691 contro 27056: € 63,65 da Ines e da Luca.
    expect(fds50Coppia($s, $fine))->toBe([])
        ->and($fine->registro['conguaglio'])->not->toHaveKey('scelta')
        ->and($fine->registro['conguaglio']['piano'][0])->toMatchArray(['segue_fine_id' => null, 'parti' => [],
            'resto' => [(int) $s['persone']['Dora']->id => -6365, (int) $s['persone']['Ines']->id => 201, (int) $s['persone']['Luca']->id => 6164]])
        ->and($fine->registro['resto_del_piano'][0]['wallet'])->toBeFalse()
        ->and(fds50Storico($s, $fine)['fermate'])->toBe(['Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Dora € 206,91, Ines € 91,45 e Luca € 61,64: nei saldi non è stato scritto niente, e la parte di Ines è rimasta € 2,01 sotto il piano, quella di Dora € 63,65 sopra, quella di Luca € 61,64 sotto. La differenza la regolano le parti con il prospetto degli oneri accessori.'])
        ->and(pfgProspetto($s, 'Ines'))->toBe([[9145, 8944, 0, 201], ['a Dora' => 201]])
        ->and(pfgProspetto($s, 'Dora'))->toBe([[20691, 27056, 0, -6365], ['da Ines' => 201, 'da Luca' => 6164]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[6164, 0, 0, 6164], ['a Dora' => 6164]]);
});

it('Coda 245 (chi era già uscito) — il coinquilino senza quote nella prima fine: anche il suo resto si conserva nel registro, e lo storico lo ricorda; la strada è il prospetto, non il Wallet', function () {
    // Il caso di `Beta55CoinquilinoCoppiaDelPianoTest` («il coinquilino senza quote»): Ines 50, Dora 30, Luca entra il 1/12 al 20 % su un piano che non si
    // ricalcola più. La riga di Luca comincia dopo la fine di Ines: riceve una parte dei suoi giorni, e non è «già uscito». Ines − 6700 → Dora; il resto
    // Dora − 1083, Luca + 1083 (Ines € 158,00, Dora € 191,17, Luca € 10,83 con la regola del piano).
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 30]]);
    fclPersona($s, 'Luca');
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-12-01',
        'quota' => 20, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(pfgResto($a))->toBe([['Dora' => -1083, 'Luca' => 1083]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => null, 'gia_usciti' => [], 'wallet' => false])
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('€ 10,83');

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();
    expect(fds50Storico($s, $fine)['fermate'])->toBe(['Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 158,00, Dora € 191,17 e Luca € 10,83: il conguaglio ha scritto solo la coppia di Ines, € 67,00 a credito di Ines e € 67,00 a debito di Dora, e la parte di Dora è rimasta € 10,83 sopra il piano, quella di Luca € 10,83 sotto. La differenza la regolano le parti con il prospetto degli oneri accessori.'])
        ->and(implode(' ', fds50Storico($s, $fine)['annullabile']['avvisi']))->not->toContain('€ 10,83');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeFalse();
});

// --- La prima fine senza coppia: la regola del piano nel registro -------------------------------------------------------------------------

/** La riga d'inquilino chiusa dalla pagina «Modifica associazione» (la «Data fine competenza»), come la scrive l'amministratore. */
function pfgChiudiAMano($test, array $s, string $chi, string $al): void
{
    $riga = DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->first();
    $r = $test->actingAs($test->user)->from('/x')->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $riga->id]), [
        'anagrafica_id' => $riga->anagrafica_id, 'tipologia' => 'inquilino', 'quota' => (float) $riga->quota,
        'data_inizio' => (string) substr((string) $riga->data_inizio, 0, 10), 'data_fine' => $al, 'note' => null,
    ]);
    expect(sl53Errori($r))->toBe([])
        ->and(substr((string) DB::table('anagrafica_immobile')->where('id', $riga->id)->value('data_fine'), 0, 10))->toBe($al);
}

/**
 * Ines 40, Dora 30, Pia 30, il piano generato con le tre righe aperte e le rate da gennaio a giugno a giornale; la riga di Ines chiusa al 30/6 dopo la
 * generazione da «Modifica associazione»; la fine di Dora il 1/10 con Pia che resta, senza coppia (nessuna scelta): la fine e lo scenario.
 */
function pfgFineSenzaCoppia($test): array
{
    $s = rdpScenario($test, [['Ines', 40], ['Dora', 30], ['Pia', 30]]);
    fclPersona($s, 'Luca');
    expect(fclNetti($s))->toBe(['Dora' => 10800, 'Ines' => 14400, 'Pia' => 10800, 'Ugo' => 84000]);
    pfgChiudiAMano($test, $s, 'Ines', '2026-06-30');
    $a = fclAnteprima($test, $s, pfgModuloFine($s, 'Dora', '2026-10-01'));
    expect(fclCoppie($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(pfgResto($a))->toBe([['Dora' => 377, 'Ines' => -4520, 'Pia' => 4143]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => null, 'gia_usciti' => ['Ines'], 'wallet' => true]);

    return [$s, fclRegistra($test, $s, pfgModuloFine($s, 'Dora', '2026-10-01'))->fresh()];
}

it('Coda 245 (chi era già uscito) — Ines chiusa da «Modifica associazione» dopo la generazione: la fine di Dora senza coppia registra la regola del piano, e la fine di Pia la segue, Pia − € 38,04 a Ugo, con il Wallet seguito e senza', function () {
    // Congelato 14400 / 10800 / 10800 (le tre righe aperte alla generazione). La fine di Dora il 1/10: Ines 40 × 181 = 7240, Dora 30 × 273 = 8190, Pia 30 × 365 = 10950
    // (somma 26380): 9880,21, 11176,65, 14943,14 → Ines 9880, Dora 11177, Pia 14943: Ines − 4520, Dora + 377, Pia + 4143. Dora e Pia, che riceve i suoi giorni,
    // pagherebbero tutte e due di più: nessuna coppia, il resto con il Wallet. La fine di Pia il 1/11 (304 giorni; dal 1/11 nessun conduttore, Ugo 61 giorni):
    // Ugo 36000 × 61/365 = 6016,44, i conduttori 36000 × 304/365 per peso (7240, 8190, 9120 su 24550): Ines 8842,40, Dora 10002,66, Pia 11138,50 → 8842 / 10003 /
    // 11139 / 6016. La differenza del passaggio: Pia − 3804, Ines − 1038, Dora − 1174, Ugo + 6016; la coppia Pia − 3804 → Ugo, il resto Ines − 1038, Dora − 1174,
    // Ugo + 2212. Era (senza la regola del piano nel registro): la fine di Pia con la regola dei giorni, 10800 × 61/365 = 1804,93 → Pia − 1805 → Ugo, e alla fine, con
    // il Wallet seguito, Pia € 19,99 sopra il piano e Ugo € 42,11 sotto, senza che nessuna frase lo dicesse.
    foreach ([true, false] as $wallet) {
        [$s, $fine] = pfgFineSenzaCoppia($this);
        $dora = (int) $s['persone']['Dora']->id;
        expect($fine->registro['conguaglio'] ?? null)->not->toHaveKey('scelta')
            ->and($fine->registro['conguaglio']['piano'][0] ?? null)->toMatchArray(['segue_fine_id' => null, 'uscente_id' => $dora, 'parti' => [], 'gia_usciti' => [(int) $s['persone']['Ines']->id]])
            ->and(fds50Coppia($s, $fine))->toBe([]);
        if ($wallet) {
            foreach ($fine->registro['resto_del_piano'][0]['parti'] as $p) {
                expect(sl53Errori(sl53Posta($this, $s, $p['nome'], (int) $p['cifra'], (int) $fine->id)))->toBe([]);
            }
            expect(fds50Netti($s))->toBe(['Dora' => 11177, 'Ines' => 9880, 'Pia' => 14943, 'Ugo' => 84000]);
        }

        $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Pia', '2026-11-01'));
        expect(rdpCoppia($a, 'Pia'))->toBe(['Pia' => -3804, 'Ugo' => 3804], json_encode($wallet))
            ->and($a['rate']['conguaglio']['regola'])->toBe('piano_coppia')
            ->and(pfgResto($a))->toBe([['Dora' => -1174, 'Ines' => -1038, 'Ugo' => 2212]])
            ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => (int) $fine->id, 'wallet' => true])
            ->and($a['rate']['frasi'][0])->toContain('La fine locazione di Dora del 1 ottobre 2026 non ha scritto righe nei saldi: la regola del piano ha lasciato da regolare a mano € 45,20 a credito di Ines, € 3,77 a debito di Dora e € 41,43 a debito di Pia. Con la regola del piano, sulla gestione Ordinaria 2026, Dora € 111,77, Ines € 98,80 e Pia € 149,43.')
            ->and(fclFrasi($a))->not->toContain(', ,')
            ->and(fclFrasi($a))->not->toContain('non ha scritto il conguaglio');
        $pia = fclRegistra($this, $s, pfgModuloFine($s, 'Pia', '2026-11-01') + ['conguaglio' => 'scrivi'])->fresh();
        expect(fds50Coppia($s, $pia))->toBe(['Pia' => -3804, 'Ugo' => 3804])
            ->and($pia->registro['conguaglio']['piano'][0]['segue_fine_id'])->toBe((int) $fine->id);
        expect(fds50Netti($s))->toBe($wallet ? ['Dora' => 11177, 'Ines' => 9880, 'Pia' => 11139, 'Ugo' => 87804] : ['Dora' => 10800, 'Ines' => 14400, 'Pia' => 6996, 'Ugo' => 87804]);

        // Il prospetto legge l'anello della fine di Dora: le parti di oggi 8842 / 10003 / 11139 / 6016; il resto della fine di Dora e quello della fine di Pia, che
        // il Wallet seguito non cambia (le righe legate non entrano nei conti del piano: la frase ne dice i fatti).
        expect(pfgProspetto($s, 'Ines'))->toBe([[8842, 14400, 0, -5558], ['da Dora' => 377, 'da Pia' => 4143, 'da Ugo' => 1038]])
            ->and(pfgProspetto($s, 'Dora'))->toBe([[10003, 10800, 0, -797], ['a Ines' => 377, 'da Ugo' => 1174]])
            ->and(pfgProspetto($s, 'Pia'))->toBe([[11139, 10800, -3804, 4143], ['a Ines' => 4143]]);
        $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
        expect([(int) $p['senza_conduttore']['totale'], (int) $p['senza_conduttore']['regola_del_piano']['da_rimborsare']])->toBe([6016, 2212])
            ->and(implode(' | ', $p['intestazione']))->toContain('Alla fine della locazione del 01/10/2026 la locazione è continuata con Pia, e nei saldi non è stato scritto niente: con la regola del piano')
            ->and(collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['regola_del_piano']['rimborsi'])->firstWhere('nome', 'Dora')['perche'])->toBe('la fine della locazione del 01/10/2026, che nei saldi non ha scritto niente');
    }
});

it('Coda 245 (chi era già uscito) — dopo la fine di Dora senza coppia, l\'inizio di Luca il 1/12 al 30 %: la regola del piano lo dice, e dice che la fine di Dora non ha scritto righe', function () {
    // Prima dell'inizio la regola del piano della fine di Dora: Dora 11177, Ines 9880, Pia 14943. Con Luca dal 1/12 (31 giorni; Pia copre tutto l'anno): pesi 7240,
    // 8190, 10950, 30 × 31 = 930 (somma 27310): 9543,76, 10796,05, 14434,27, 1225,92 → Ines 9544, Dora 10796, Pia 14434, Luca 1226 (i centesimi a Luca, ,92, e a
    // Ines, ,76). Luca + 1226, Pia − 509, Dora − 381, Ines − 336: Luca è il solo che pagherebbe di più.
    [$s, $fine] = pfgFineSenzaCoppia($this);
    $a = fclAnteprima($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-12-01', 'quota' => 30,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
    expect(collect($a['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', (int) ($g['fine_id'] ?? 0), collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
        ->toBe([['piano', (int) $fine->id, ['Luca' => 1226, 'Pia' => -509, 'Ines' => -336, 'Dora' => -381]]])
        ->and($a['rate']['frasi'][0])->toContain('La fine locazione di Dora del 1 ottobre 2026 ha diviso la parte dell\'inquilino con la regola del piano (Dora € 111,77, Ines € 98,80 e Pia € 149,43), senza righe nei saldi: ha lasciato da regolare a mano € 45,20 a credito di Ines, € 3,77 a debito di Dora e € 41,43 a debito di Pia. Con Luca dal 1 dicembre 2026 al 30 % la regola del piano darebbe Luca € 12,26, Pia € 144,34, Dora € 107,96 e Ines € 95,44: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 5,09 a Pia, € 3,81 a Dora e € 3,36 a Ines.')
        ->and(fclFrasi($a))->not->toContain('non ha scritto il conguaglio');
});

it('Coda 245 (chi era già uscito) — Ines 80 con Luca entrato al 20 % su un piano che non si ricalcola più: la fine di Ines non scrive niente, registra la regola del piano, e la differenza la regolano le parti con il prospetto', function () {
    // Il congelato dà a Ines tutta la parte dell'inquilino, 36000 (la sola riga d'inquilino alla generazione). Luca entra il 1/9 al 20 % (122 giorni), senza quote.
    // Ines esce il 1/12 (334 giorni) e Luca resta: 80 × 334 = 26720 e 20 × 122 = 2440 (somma 29160, tutto l'anno coperto): 32987,65 e 3012,35 → Ines 32988, Luca
    // 3012. Chi riceve i giorni di Ines è Luca, che non ha quote: nessuna riga, nessuna scelta; il resto Ines − 3012, Luca + 3012, e il prospetto lo fa rimborsare.
    $s = rdpScenario($this, [['Ines', 80]], ['titolari' => [['Ugo', 'proprietario', 100]]]);
    fclPersona($s, 'Luca');
    expect(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Luca']->id, 'decorrenza' => '2026-09-01', 'quota' => 20,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Ines', '2026-12-01'));
    expect(fclCoppie($a))->toBe([])
        ->and(pfgResto($a))->toBe([['Ines' => -3012, 'Luca' => 3012]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => null, 'gia_usciti' => [], 'wallet' => false])
        ->and($a['rate']['frasi'][0])->toContain('Luca non ha quote del piano, e nei saldi non ha righe: con la regola del piano Ines pagherebbe € 30,12 in meno e Luca pagherebbe € 30,12 in più; la differenza la regolano le parti con il prospetto degli oneri accessori.');
    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Ines', '2026-12-01'))->fresh();
    expect($fine->registro['conguaglio']['piano'][0] ?? null)->toMatchArray(['segue_fine_id' => null, 'parti' => []])
        ->and(fds50Storico($s, $fine)['fermate'])->toBe(['Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 329,88 e Luca € 30,12: nei saldi non è stato scritto niente, e la parte di Ines è rimasta € 30,12 sopra il piano, quella di Luca € 30,12 sotto. La differenza la regolano le parti con il prospetto degli oneri accessori.'])
        ->and(pfgProspetto($s, 'Ines'))->toBe([[32988, 36000, 0, -3012], ['da Luca' => 3012]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[3012, 0, 0, 3012], ['a Ines' => 3012]]);
});

// --- La prima fine dopo un cambio d'inquilino della .50 ------------------------------------------------------------------------------------

it('Coda 245 (chi era già uscito) — dopo il cambio Ines → Luca del 1/7 con «Scrivi» e Dora che resta, la fine di Luca il 1/10 parte da ciò che il cambio ha scritto: Luca − € 38,83 a Dora, e Ines pagherebbe € 12,87 in più; il prospetto conta anche il conguaglio del cambio', function () {
    // Il cambio (la .50): Luca prende la quota di Ines dal 1/7 (decisione 37), e la coppia è Ines − 9074 → Luca + 9074 (18000 × 184/365 = 9073,97): la stessa del piano,
    // pesi Ines 50 × 181 = 9050, Luca 50 × 184 = 9200, Dora 50 × 365 = 18250 su 36500: 8926,03 / 9073,97 / 18000 → Ines 8926, Luca 9074, Dora 18000. La fine di Luca il
    // 1/10 (92 giorni): pesi 9050, 4600, 18250 su 31900 (tutto l'anno coperto): 10213,17, 5191,22, 20595,61 → Ines 10213, Luca 5191, Dora 20596 (il centesimo a Dora,
    // ,61). La differenza del passaggio, da ciò che il cambio ha scritto: Luca − 3883, Dora + 2596, Ines + 1287. Ines è già uscita: si scrive la differenza di Luca, tutta,
    // verso chi riceve i suoi giorni, Dora (P2, come nella fine di Dora con Pia che resta): Luca − 3883, Dora + 3883; il resto Ines + 1287, Dora − 1287. Era: la differenza
    // dall'anello congelato (Ines − 7787, Luca + 5191, Dora + 2596), nessuna coppia, e al Wallet € 77,87 a credito di Ines, che dal cambio ne ha già € 90,74.
    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
        fclPersona($s, 'Luca');
        $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
        expect(fds50Coppia($s, $cambio))->toBe(['Ines' => -9074, 'Luca' => 9074])
            ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 8926, 'Luca' => 9074, 'Ugo' => 84000]);

        $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));
        expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
            ->and(rdpCoppia($a, 'Luca'))->toBe(['Dora' => 3883, 'Luca' => -3883])
            ->and(pfgResto($a))->toBe([['Dora' => -1287, 'Ines' => 1287]])
            ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => null, 'gia_usciti' => ['Ines'], 'wallet' => true])
            ->and($a['rate']['frasi'][0])->toContain('con il cambio d\'inquilino del 1 luglio 2026, che ha scritto la sua coppia, Luca € 90,74, Ines € 89,26 e Dora € 180,00. Se scrivi il conguaglio, il programma scrive solo la coppia di Luca: € 38,83 a credito di Luca e € 38,83 a debito di Dora. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 12,87 in più, e Dora € 12,87 in meno.')
            ->and($a['rate']['frasi'][0])->not->toContain('nelle loro rate');
        $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01') + ['conguaglio' => $scelta])->fresh();

        // Il prospetto: le parti di oggi 10213 / 5191 / 20596; nelle rate Ines 18000, Dora 18000, Luca niente; il conguaglio del cambio (Ines − 9074, Luca + 9074) e,
        // con «Scrivi», quello della fine di Luca (Luca − 3883, Dora + 3883). Ines 10213 − 18000 + 9074 = 1287, a Dora; Luca 5191 − 9074 + 3883 = 0; Dora 20596 − 18000 −
        // 3883 = − 1287. Con «Non scriverlo» la coppia resta fra le parti: Dora 20596 − 18000 = 2596 (3883 a Luca, 1287 da Ines), Luca 5191 − 9074 = − 3883.
        expect(pfgProspetto($s, 'Ines'))->toBe([[10213, 18000, -9074, 1287], ['a Dora' => 1287]], $scelta)
            ->and(pfgProspetto($s, 'Luca'))->toBe($scelta === 'scrivi' ? [[5191, 0, 5191, 0], []] : [[5191, 0, 9074, -3883], ['da Dora' => 3883]])
            ->and(pfgProspetto($s, 'Dora'))->toBe($scelta === 'scrivi' ? [[20596, 18000, 3883, -1287], ['da Ines' => 1287]] : [[20596, 18000, 0, 2596], ['a Luca' => 3883, 'da Ines' => 1287]]);
        if ($scelta === 'scrivi') {
            expect(fds50Coppia($s, $fine))->toBe(['Dora' => 3883, 'Luca' => -3883])
                ->and(fclNetti($s))->toBe(['Dora' => 21883, 'Ines' => 8926, 'Luca' => 5191, 'Ugo' => 84000])
                ->and(fclStampa($s))->toContain('con il conguaglio del cambio d\'inquilino del 01/07/2026, a suo debito € 90,74');
            // «Annulla il conguaglio» del cambio dopo la fine di Luca: le parti hanno regolato fra loro i 9074; il prospetto li dice regolati, e le cifre restano.
            sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
            $ines = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
            expect(pfgProspetto($s, 'Ines'))->toBe([[10213, 18000, 0, 1287], ['a Dora' => 1287]])
                ->and((int) $ines['regolato_fra_le_parti'])->toBe(-9074)
                ->and(pfgProspetto($s, 'Luca'))->toBe([[5191, 0, -3883, 0], []]);
            // Un registro del cambio annullato scritto prima di questa versione, senza le cifre per unità: senza pertinenze la cifra della gestione è quella dell'unità.
            $registro = $cambio->fresh()->registro;
            $registro['regolato_fuori'] = array_map(fn (array $g) => array_diff_key($g, ['per_unita' => true]), $registro['regolato_fuori']);
            DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
            expect(pfgProspetto($s, 'Ines'))->toBe([[10213, 18000, 0, 1287], ['a Dora' => 1287]])
                ->and(pfgProspetto($s, 'Luca'))->toBe([[5191, 0, -3883, 0], []]);
        }
    }
});

/**
 * Ines e Dora al 50 %, Ugo proprietario; il «Preventivo 2026» creato dalla pagina «Nuovo piano rate» con «Spese generali» (€ 1.200,00) e «Verde» (€ 400,00), tutte e due al 30 %
 * all'inquilino, e la competenza della «Verde» dal 1/1 al giorno dato (il campo delle competenze del capitolo); approvato dalla pagina, generato, rate da gennaio a giugno a giornale.
 */
function pfgConLaVerde($test, string $al): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $verde = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Verde', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 40000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $verde->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    // Il piano dello scenario è creato dal modello: si toglie, e si crea dalla pagina, con la competenza della voce.
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => 'Preventivo 2026', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => [['id' => $spese->id, 'importo' => '1.200,00', 'note' => ''], ['id' => $verde->id, 'importo' => '400,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $verde->id, 'tratti' => [['dal' => '2026-01-01', 'al' => $al]]]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->latest('id')->firstOrFail();
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), [
        'approvato' => true, 'data_delibera_assemblea' => '2025-12-15',
    ])->assertSessionHasNoErrors();
    $s['piano'] = $s['piano']->fresh();
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    fclPersona($s, 'Luca');

    return $s;
}

/** Le voci di un conduttore nel prospetto, per conto: [la sua parte, nelle sue rate]. */
function pfgVoci(array $s, string $nome): array
{
    $c = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);

    return collect($c['voci'] ?? [])->mapWithKeys(fn (array $v) => [(string) $v['conto'] => [(int) $v['importo'], (int) ($v['nelle_rate'] ?? 0)]])->sortKeys()->all();
}

it('Coda 245 (chi era già uscito) — dopo il cambio Ines → Luca del 1/7 con «Scrivi», una voce che la fine di Luca non tocca («Verde», competenza 1/1–30/9) si legge con la regola del piano anche nel prospetto: Ines € 12,87 da rimborsare a Dora, Luca niente, con le due scelte e dopo «Annulla il conguaglio» del cambio', function () {
    // Anelli: «Spese generali» 30 % di 120000 = 36000, «Verde» 30 % di 40000 = 12000 sui 273 giorni della sua competenza (1/1–30/9); nelle rate Ines e Dora 18000 + 6000 = 24000 ciascuna.
    // Il cambio il 1/7 (Ines 181 giorni, Luca 184 sull'anno e 92 sulla Verde): Spese generali 18000 × 184/365 = 9073,97 → 9074; Verde 6000 × 92/273 = 2021,98 → 2022; la coppia
    // Ines − 11096 → Luca. La fine di Luca il 1/10, Dora resta. Spese generali: prima (le righe di prima della fine, 9050 / 9200 / 18250 su 36500) 8926 / 9074 / 18000, dopo (9050 / 4600 /
    // 18250 su 31900) 10213,17 / 5191,22 / 20595,61 → 10213 / 5191 / 20596; coppia Luca − 3883 → Dora, resto Ines + 1287 / Dora − 1287. Verde, prima e dopo (9050 / 4600 / 13650 su 27300):
    // 3978,02 / 2021,98 / 6000 → 3978 / 2022 / 6000 (il centesimo a Luca, ,98): la fine non la tocca, perché i giorni di Luca sulla Verde finiscono il 30/9. Il prospetto: Ines 10213 + 3978 =
    // 14191, nelle rate 24000, con il conguaglio − 11096 → 1287 da rimborsare a Dora; Luca 5191 + 2022 = 7213, con il conguaglio + 11096 − 3883 = 7213 → 0; Dora 20596 + 6000 = 26596,
    // 24000, + 3883 → − 1287. Era: la Verde di Ines alle rate (6000) e niente a Luca, con il conguaglio del cambio contato tutto: Ines 3309, Luca − 2022 («da rimborsare a chi le ha pagate
    // € 20,22» e «da ricevere da chi ha pagato i suoi giorni € 20,22»).
    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $s = pfgConLaVerde($this, '2026-09-30');
        $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
        expect(fds50Coppia($s, $cambio))->toBe(['Ines' => -11096, 'Luca' => 11096]);
        fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01') + ['conguaglio' => $scelta]);

        expect(pfgProspetto($s, 'Ines'))->toBe([[14191, 24000, -11096, 1287], ['a Dora' => 1287]], $scelta)
            ->and(pfgVoci($s, 'Ines'))->toBe(['Spese generali' => [10213, 18000], 'Verde' => [3978, 6000]])
            ->and(pfgVoci($s, 'Luca'))->toBe(['Spese generali' => [5191, 0], 'Verde' => [2022, 0]]);
        if ($scelta === 'scrivi') {
            expect(pfgProspetto($s, 'Luca'))->toBe([[7213, 0, 7213, 0], []])
                ->and(pfgProspetto($s, 'Dora'))->toBe([[26596, 24000, 3883, -1287], ['da Ines' => 1287]]);
            $stampa = fclStampa($s);
            expect($stampa)->toContain('Totale a carico di Ines € 141,91')->and($stampa)->toContain('Totale a carico di Luca € 72,13')
                ->and($stampa)->not->toContain('da rimborsare a chi le ha pagate')->and($stampa)->not->toContain('da ricevere da chi ha pagato i suoi giorni');
            // «Annulla il conguaglio» del cambio: le parti hanno regolato fra loro i 11096, e chi rimborsa chi non cambia.
            sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
            $ines = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
            expect(pfgProspetto($s, 'Ines'))->toBe([[14191, 24000, 0, 1287], ['a Dora' => 1287]])
                ->and((int) $ines['regolato_fra_le_parti'])->toBe(-11096)
                ->and(pfgProspetto($s, 'Luca'))->toBe([[7213, 0, -3883, 0], []]);
        } else {
            // Con «Non scriverlo» alla fine la coppia di Luca resta fra le parti: Luca 7213 − 11096 = − 3883 da Dora; Dora 26596 − 24000 = 2596 (3883 a Luca, 1287 da Ines).
            expect(pfgProspetto($s, 'Luca'))->toBe([[7213, 0, 11096, -3883], ['da Dora' => 3883]])
                ->and(pfgProspetto($s, 'Dora'))->toBe([[26596, 24000, 0, 2596], ['a Luca' => 3883, 'da Ines' => 1287]]);
        }
    }
});

it('Coda 245 (chi era già uscito) — la guardia: con la «Verde» finita il 31/5, prima del cambio, il cambio non la sposta, e la voce resta alle rate; Ines € 12,87 da rimborsare a Dora, Luca niente', function () {
    // Verde 1/1–31/5 (151 giorni): il cambio del 1/7 non la tocca, la coppia è la sola Spese generali, Ines − 9074 → Luca. La Verde con le righe di oggi: Ines e Dora 50 × 151 ciascuna,
    // 6000 / 6000, la stessa cifra delle rate: nessun centesimo si sposta. Ines 10213 + 6000 = 16213, nelle rate 24000, con il conguaglio − 9074 → 1287; Luca 5191, con il conguaglio
    // 9074 − 3883 = 5191 → 0.
    $s = pfgConLaVerde($this, '2026-05-31');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fds50Coppia($s, $cambio))->toBe(['Ines' => -9074, 'Luca' => 9074]);
    fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01') + ['conguaglio' => 'scrivi']);

    expect(pfgProspetto($s, 'Ines'))->toBe([[16213, 24000, -9074, 1287], ['a Dora' => 1287]])
        ->and(pfgVoci($s, 'Ines'))->toBe(['Spese generali' => [10213, 18000], 'Verde' => [6000, 6000]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[5191, 0, 5191, 0], []])
        ->and(pfgVoci($s, 'Luca'))->toBe(['Spese generali' => [5191, 0]]);
});

it('Coda 245 (chi era già uscito) — il cambio Ines → Luca del 1/7 con il box locato insieme e «Scrivi», la fine di Luca il 1/10, «Annulla il conguaglio» del cambio dopo e prima della fine: nel prospetto dell\'Interno 1 è regolata fra le parti la parte dell\'Interno 1, € 81,67, non quella della gestione con il box, € 99,82', function () {
    // L'Interno 1 900/1000: l'anello 120000 × 0,9 × 30 % = 32400, Ines e Dora 16200; il Box 12 100/1000: 3600, di Ines. Il cambio il 1/7 con il box: Interno 1 16200 × 184/365 = 8166,58 →
    // 8167, box 3600 × 184/365 = 1814,79 → 1815; «Annulla il conguaglio» scrive per la gestione 9982 (le due unità insieme). La fine di Luca il 1/10, Dora resta sull'Interno 1: prima (le
    // righe di prima, 9050 / 9200 / 18250 su 36500) 8033 / 8167 / 16200, dopo (9050 / 4600 / 18250 su 31900) 9191,85 / 4672,10 / 18536,05 → 9192 / 4672 / 18536; coppia Luca − 3495 → Dora,
    // resto Ines + 1159 / Dora − 1159. Il prospetto dell'Interno 1 dopo l'annullamento: Ines 9192 − 16200 + 8167 regolati = 1159, a Dora; Luca 4672 + 3495 − 8167 = 0; Dora 18536 − 16200 −
    // 3495 = − 1159: chi rimborsa chi non cambia con l'annullamento. Era: i regolati della gestione, 9982, con la parte del box contata anche qui (Ines 2974, Luca − 1815: «da rimborsare
    // a chi le ha pagate € 18,15» e «da ricevere da chi ha pagato i suoi giorni € 18,15»). Il box: Ines 3600 × 181/365 = 1785,21 → 1785 nelle sue rate; Luca 1815 − 907 (la coppia della sua
    // fine verso Ugo, 3600 × 92/365 = 907,40) = 908, regolati fra le parti; da rimborsare 0 tutti e due.
    foreach (['dopo', 'prima'] as $quando) {
        $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
        fclPersona($s, 'Luca');
        $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
        expect(fclSaldi($s))->toBe(['Ines' => -8167, 'Luca' => 8167])->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Luca' => 1815]);
        if ($quando === 'prima') {
            sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
        }
        $fine = ['quota' => 50] + fclModulo($s, null, '2026-10-01', [$s['box']], 'Luca');
        fclRegistra($this, $s, $fine + ['conguaglio' => 'scrivi']);
        if ($quando === 'dopo') {
            expect(pfgProspetto($s, 'Ines'))->toBe([[9192, 16200, -8167, 1159], ['a Dora' => 1159]]);
            sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
        }
        $ines = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
        $luca = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');
        expect(pfgProspetto($s, 'Ines'))->toBe([[9192, 16200, 0, 1159], ['a Dora' => 1159]], $quando)
            ->and((int) $ines['regolato_fra_le_parti'])->toBe(-8167)
            ->and(pfgProspetto($s, 'Luca'))->toBe([[4672, 0, -3495, 0], []])
            ->and((int) $luca['regolato_fra_le_parti'])->toBe(8167)
            ->and(pfgProspetto($s, 'Dora'))->toBe([[18536, 16200, 3495, -1159], ['da Ines' => 1159]]);
        $stampa = fclStampa($s);
        expect($stampa)->toContain('€ 81,67')->and($stampa)->not->toContain('€ 99,82')
            ->and($stampa)->not->toContain('da rimborsare a chi le ha pagate')->and($stampa)->not->toContain('da ricevere da chi ha pagato i suoi giorni');
        $box = collect(app(ProspettoOneriAccessori::class)->calcola($s['box']->fresh(), $s['e'])['conduttori'])->mapWithKeys(fn ($c) => [$c['nome'] => [(int) $c['totale'], (int) $c['da_rimborsare']]])->sortKeys()->all();
        expect($box)->toBe(['Ines' => [1785, 0], 'Luca' => [908, 0]]);
    }

    // Un registro del cambio annullato scritto prima di questa versione, senza le cifre per unità: la cifra della gestione si divide fra l'Interno 1 e il box come la coppia del
    // cambio, le quote congelate di Ines per i giorni dopo il cambio, 16200 × 184/365 e 3600 × 184/365: 9982 × 16200/19800 = 8167,09 → 8167, 9982 × 3600/19800 = 1814,91 → 1815.
    // Le stesse cifre.
    $registro = $cambio->fresh()->registro;
    $registro['regolato_fuori'] = array_map(fn (array $g) => array_diff_key($g, ['per_unita' => true]), $registro['regolato_fuori']);
    DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
    expect(pfgProspetto($s, 'Ines'))->toBe([[9192, 16200, 0, 1159], ['a Dora' => 1159]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[4672, 0, -3495, 0], []]);
});

it('Coda 245 (chi era già uscito) — lo stesso cambio con il box, con in più una voce del solo Interno 1 («Verde» su una tabella sua, competenza 1/1–30/9): dopo «Annulla il conguaglio» sono regolati fra le parti € 101,89 dell\'Interno 1, la cifra del cambio per l\'unità, non una parte della cifra della gestione in proporzione alle rate', function () {
    // Scritto dopo il codice (il rosso sono le mutazioni che tolgono le cifre per unità dal registro, o non le leggono). L'Interno 1 900/1000 e il Box 12 100/1000 su «Proprietà», e la «Verde»
    // (€ 400,00 al 30 %, 12000) su «Giardino», del solo Interno 1, con la competenza 1/1–30/9: Ines e Dora 6000. Il cambio il 1/7 con il box: Interno 1 16200 × 184/365 = 8166,58 e 6000 ×
    // 92/273 = 2021,98, in tutto 10188,55 → 10189; box 3600 × 184/365 = 1814,79 → 1815; per la gestione 12004. La cifra della gestione divisa come le rate di Ines sulle due unità (22200 e
    // 3600) darebbe 12004 × 22200/25800 = 10329,02: la voce del solo Interno 1 non ha giorni sul box, e la proporzione non regge. La fine di Luca: Spese generali come sopra (Luca − 3495 →
    // Dora, resto Ines + 1159 / Dora − 1159); la Verde la fine non la tocca, 3978 / 2022 / 6000. Dopo l'annullamento: Ines 9192 + 3978 = 13170, nelle rate 22200, regolati − 10189 → 1159;
    // Luca 4672 + 2022 = 6694, con il conguaglio − 3495, regolati + 10189 → 0; Dora 18536 + 6000 = 24536, 22200, + 3495 → − 1159.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]], 'box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $giardino = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Giardino', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $giardino->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $verde = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Verde', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 40000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $verde->id, 'tabella_id' => $giardino->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $verde->id => ['importo' => 40000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $verde->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-09-30', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclPersona($s, 'Luca');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fclSaldi($s))->toBe(['Ines' => -10189, 'Luca' => 10189])->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Luca' => 1815]);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [$s['box']], 'Luca') + ['conguaglio' => 'scrivi']);
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);

    $ines = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
    expect(pfgProspetto($s, 'Ines'))->toBe([[13170, 22200, 0, 1159], ['a Dora' => 1159]])
        ->and((int) $ines['regolato_fra_le_parti'])->toBe(-10189)
        ->and(pfgProspetto($s, 'Luca'))->toBe([[6694, 0, -3495, 0], []])
        ->and(pfgProspetto($s, 'Dora'))->toBe([[24536, 22200, 3495, -1159], ['da Ines' => 1159]]);

    // Un registro del cambio annullato scritto prima di questa versione, senza le cifre per unità: la cifra della gestione, 12004, si divide come la coppia del
    // cambio, le quote congelate di Ines sui giorni della competenza dopo il cambio: Interno 1 16200 × 184/365 + 6000 × 92/273 = 10188,55, box 3600 × 184/365 =
    // 1814,79; 12004 × 10188,55/12003,35 = 10189,11 → 10189, e 1814,89 → 1815. Le stesse cifre. Con le sole quote (22200 e 3600) sarebbero 10329 e 1675: Ines
    // «da rimborsare» € 12,99, di cui € 11,59 a Dora, e Luca € 1,40 da ricevere da nessuno.
    $registro = $cambio->fresh()->registro;
    $registro['regolato_fuori'] = array_map(fn (array $g) => array_diff_key($g, ['per_unita' => true]), $registro['regolato_fuori']);
    DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
    expect(pfgProspetto($s, 'Ines'))->toBe([[13170, 22200, 0, 1159], ['a Dora' => 1159]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[6694, 0, -3495, 0], []])
        ->and(pfgProspetto($s, 'Dora'))->toBe([[24536, 22200, 3495, -1159], ['da Ines' => 1159]]);
});

it('Coda 245 (chi era già uscito) — un registro del cambio con il box scritto prima di questa versione, e un piano generato dopo il cambio con una voce del solo Interno 1: la cifra della gestione si divide sui soli piani che il cambio ha preso', function () {
    // Il cambio il 1/7 con il box prende il «Preventivo 2026»: Interno 1 16200 × 184/365 = 8166,58 → 8167, box 3600 × 184/365 = 1814,79 → 1815; «Annulla il conguaglio», 9982 per
    // la gestione. Dopo, il piano «Verde 2026» (€ 400,00 al 30 % su «Giardino», del solo Interno 1): Ines 12000 × 50 % × 181/365 = 2975,34 → 2975, che il cambio non ha diviso.
    // Senza le cifre per unità la cifra della gestione si divide come la coppia del cambio, sui piani che ha preso: 8167 e 1815. Contando anche il piano dopo, l'Interno 1 peserebbe
    // 8166,58 + 2975 × 184/365 = 9666,38 contro 1814,79: 8404 e 1578.
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclPersona($s, 'Luca');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $giardino = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Giardino', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $giardino->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $verde = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Verde', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 40000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $verde->id, 'tabella_id' => $giardino->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $dopo = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Verde 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-08-05', 'metodo_distribuzione' => 'prima_rata',
        'applica_saldi' => false]);
    $dopo->capitoli()->syncWithoutDetaching([$verde->id => ['importo' => 40000]]);
    fclGenera(['piano' => $dopo] + $s);
    $nomi = fn (array $p) => collect($p)->mapWithKeys(fn ($v, $k) => [$k === (int) $s['persone']['Ines']->id ? 'Ines' : 'Luca' => $v])->all();
    $ines = (int) $s['persone']['Ines']->id;
    expect(collect(app(\App\Services\Subentro\RegolaDelPiano::class)->anelli($dopo->fresh(), (int) $s['unita']->id))->pluck('prima')->all())
        ->toBe([[$ines => 2975, (int) $s['persone']['Dora']->id => 6000, (int) $s['persone']['Luca']->id => 3025]]);

    $registro = $cambio->fresh()->registro;
    $registro['regolato_fuori'] = array_map(fn (array $g) => array_diff_key($g, ['per_unita' => true]), $registro['regolato_fuori']);
    DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
    expect($nomi(\App\Services\Subentro\RegolaDelPiano::regolatiDelCambio($cambio->fresh(), (int) $s['unita']->id, (int) $s['g']->id)))->toBe(['Ines' => -8167, 'Luca' => 8167])
        ->and($nomi(\App\Services\Subentro\RegolaDelPiano::regolatiDelCambio($cambio->fresh(), (int) $s['box']->id, (int) $s['g']->id)))->toBe(['Ines' => -1815, 'Luca' => 1815]);
});

/**
 * L'Interno 1 900/1000 e il Box 12 100/1000 su «Proprietà» («Spese generali» € 1.200,00 al 30 % all'inquilino: l'anello dell'Interno 1 32400, quello del box
 * 3600, di Ines), con in più un'entrata del solo Interno 1: la voce «Giardino», creata dalla pagina «Nuova voce» come entrata sulla tabella «Giardino» (che
 * ha il solo Interno 1), con l'importo dato al 30 % all'inquilino, tutto l'anno. Gli inquilini dati sull'Interno 1; generato, rate da gennaio a giugno a giornale.
 */
function pfgConLEntrata($test, array $inquilini, string $importo): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => $inquilini, 'box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $giardino = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Giardino', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $giardino->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Giardino', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $giardino->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $voce = \App\Models\Gestionale\Conto::where('nome', 'Giardino')->where('piano_conto_id', $pc->id)->firstOrFail();
    expect($voce->tipo)->toBe('entrata');
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $voce->id => ['importo' => (int) $voce->importo]]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Il registro di un cambio d'inquilino com'era scritto prima di questa versione: `regolato_fuori` per gestione, senza le cifre unità per unità. */
function pfgRegistroDiPrima(Subentro $cambio): void
{
    $registro = $cambio->fresh()->registro;
    expect($registro['regolato_fuori'][0])->toHaveKey('per_unita');
    $registro['regolato_fuori'] = array_map(fn (array $g) => array_diff_key($g, ['per_unita' => true]), $registro['regolato_fuori']);
    DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
}

it('Coda 245 (chi era già uscito) — un registro del cambio con il box scritto prima di questa versione, e un\'entrata del solo Interno 1 («Giardino»): «Annulla il conguaglio» del cambio, poi la fine di Luca; sull\'Interno 1 sono regolati fra le parti € 51,42, non € 59,86, e Ines rimborsa a Dora € 7,30', function () {
    // L'Interno 1: «Spese generali» 32400 (Ines e Dora 16200), «Giardino» entrata 40000 × 30 % = − 12000 (Ines e Dora − 6000): le rate 10200 e 10200; il box 3600, di Ines. Il cambio
    // Ines → Luca il 1/7 con il box (184 giorni su 365): Interno 1 16200 × 184/365 = 8166,58 → 8167 e − 6000 × 184/365 = − 3024,66 → − 3025, in tutto 5142; box 1814,79 → 1815; per la
    // gestione 6957. «Annulla il conguaglio» prima della fine, e il registro com'era scritto prima (solo la cifra della gestione, 6957). La fine di Luca il 1/10 con il box, Dora resta: spese
    // prima 8033 / 8167 / 16200 (9050 / 9200 / 18250 su 36500), dopo 9191,85 / 4672,10 / 18536,05 → 9192 / 4672 / 18536 (9050 / 4600 / 18250 su 31900); entrata prima − 2975 / − 3025 / − 6000,
    // dopo − 3404,39 / − 1730,41 / − 6865,20 → − 3404 / − 1731 / − 6865 (il centesimo a Luca, ,41); le parti da 5058 / 5142 / 10200 a 5788 / 2941 / 11671; la coppia Luca − 2201 → Dora, il resto
    // Ines + 730 / Dora − 730. La cifra della gestione divisa come la coppia del cambio per unità, con il segno (spese meno entrate): 5141,92 e 1814,79, 6957 × 5141,92/6956,71 = 5142,13 →
    // 5142 e 1814,87 → 1815. Il prospetto dell'Interno 1: Ines 5788 − 10200 + 5142 = 730, a Dora; Luca 2941 + 2201 − 5142 = 0; Dora 11671 − 10200 − 2201 = − 730. Era: i pesi in valore
    // assoluto, 8166,58 + 3024,66 = 11191,23 contro 1814,79, 6957 → 5986 e 971: Ines 1574 (regolati − 5986), Luca − 844, cioè € 8,44 «da rimborsare a chi le ha pagate» e «da ricevere da chi ha
    // pagato i suoi giorni» senza nessuno dall'altra parte.
    $s = pfgConLEntrata($this, [['Ines', 50], ['Dora', 50]], '400,00');
    fclPersona($s, 'Luca');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fclSaldi($s))->toBe(['Ines' => -5142, 'Luca' => 5142])->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Luca' => 1815]);
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302)->assertSessionHasNoErrors();
    expect($cambio->fresh()->registro['regolato_fuori'][0]['importo'])->toBe(6957);
    pfgRegistroDiPrima($cambio);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [$s['box']], 'Luca') + ['conguaglio' => 'scrivi']);

    $conduttori = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->keyBy('nome');
    expect(pfgProspetto($s, 'Ines'))->toBe([[5788, 10200, 0, 730], ['a Dora' => 730]])
        ->and((int) $conduttori['Ines']['regolato_fra_le_parti'])->toBe(-5142)
        ->and(pfgProspetto($s, 'Luca'))->toBe([[2941, 0, -2201, 0], []])
        ->and((int) $conduttori['Luca']['regolato_fra_le_parti'])->toBe(5142)
        ->and(pfgProspetto($s, 'Dora'))->toBe([[11671, 10200, 2201, -730], ['da Ines' => 730]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('€ 51,42')->and($stampa)->not->toContain('€ 59,86')->and($stampa)->not->toContain('€ 8,44')
        ->and($stampa)->not->toContain('da rimborsare a chi le ha pagate')->and($stampa)->not->toContain('da ricevere da chi ha pagato i suoi giorni');
});

it('Coda 245 (chi era già uscito) — lo stesso registro di prima con un\'entrata del solo Interno 1 più grande delle sue spese («Giardino» € 1.400,00): sull\'Interno 1 la parte del cambio è un credito di Luca, € 24,19, e il box un debito, € 18,15; il prospetto dell\'Interno 1 è quello del registro di questa versione', function () {
    // L'entrata 140000 × 30 % = − 42000 sull'Interno 1 (Ines e Dora − 21000): le rate dell'Interno 1 16200 − 21000 = − 4800 ciascuna. Il cambio il 1/7 con il box: Interno 1 8166,58 → 8167 e
    // − 21000 × 184/365 = − 10586,30 → − 10586, in tutto − 2419 (Luca a credito); box 1815; per la gestione − 604. Le coppie per unità, con il segno: − 2419,73 e 1814,79, di segno diverso:
    // la proporzione non regge (la somma, − 604,93, è un quarto della cifra dell'Interno 1). Arrotondate, − 2420 e 1815, e la differenza dalla cifra della gestione (− 604 − (− 605) = 1)
    // sull'unità del passaggio: − 2419 e 1815, le cifre che il cambio ha scritto. La fine di Luca il 1/10 con il box, Dora resta: spese come sopra; entrata prima − 10413,70 / − 10586,30 / − 21000
    // → − 10414 / − 10586 / − 21000, dopo − 11915,36 / − 6056,43 / − 24028,21 → − 11915 / − 6057 / − 24028 (il centesimo a Luca, ,43); le parti da − 2381 / − 2419 / − 4800 a − 2723 / − 1385 /
    // − 5492; la coppia Luca + 1034 → Dora, il resto Ines − 342 / Dora + 342. Il prospetto dell'Interno 1: Ines − 2723 + 4800 − 2419 = − 342, da Dora; Luca − 1385 − 1034 + 2419 = 0; Dora
    // − 5492 + 4800 + 1034 = 342. Era: i pesi in valore assoluto, 18752,88 contro 1814,79, − 604 → − 551 e − 53: Ines regolati + 551 invece di + 2419, e il box di segno rovesciato.
    $s = pfgConLEntrata($this, [['Ines', 50], ['Dora', 50]], '1.400,00');
    fclPersona($s, 'Luca');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fclSaldi($s))->toBe(['Ines' => 2419, 'Luca' => -2419])->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Luca' => 1815]);
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302)->assertSessionHasNoErrors();
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [$s['box']], 'Luca') + ['conguaglio' => 'scrivi']);

    $atteso = fn () => [pfgProspetto($s, 'Ines'), pfgProspetto($s, 'Luca'), pfgProspetto($s, 'Dora'),
        (int) collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines')['regolato_fra_le_parti']];
    $cifre = [[[-2723, -4800, 0, -342], ['da Dora' => 342]], [[-1385, 0, 1034, 0], []], [[-5492, -4800, -1034, 342], ['a Ines' => 342]], 2419];
    expect($atteso())->toBe($cifre);
    pfgRegistroDiPrima($cambio);
    expect($atteso())->toBe($cifre);
});

it('Coda 245 (chi era già uscito) — un registro di prima con le sole spese e due voci sul box («Pulizia box» del solo box): la cifra della gestione si divide in proporzione alle coppie, € 81,67 sull\'Interno 1 e € 22,69 sul box', function () {
    // Scritto dopo il codice (il rosso è la mutazione che divide sempre arrotondando le coppie). Il box ha «Spese generali» 3600 e «Pulizia box» € 30,00 al 30 %, 900,
    // di Ines. Il cambio il 1/7 con il box, voce per voce: Interno 1 16200 × 184/365 = 8166,58 → 8167; box 1814,79 → 1815 e 900 × 184/365 = 453,70 → 454, 2269; per la gestione 10436 (i saldi
    // del cambio dalla rotta lo dicono). Le coppie ricostruite, dello stesso segno, 8166,58 e 2268,49 (somma 10435,07): 10436 × 8166,58/10435,07 = 8167,30 e 2268,70 → 8167 e 2269 (il centesimo al
    // resto più alto). Arrotondate una per una sarebbero 8167 e 2268, e la differenza sull'Interno 1 darebbe 8168. La fine di Luca il 1/10 come nel cambio con il box sopra: coppia Luca − 3495 →
    // Dora, resto Ines + 1159 / Dora − 1159; il prospetto dell'Interno 1 Ines `[9192, 16200, 0, 1159]` con − 8167 regolati, Luca `[4672, 0, −3495, 0]`.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]], 'box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabellaBox = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaBox->id, 'immobile_id' => $s['box']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Pulizia box', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '30,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabellaBox->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $pulizia = \App\Models\Gestionale\Conto::where('nome', 'Pulizia box')->where('piano_conto_id', $pc->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $pulizia->id => ['importo' => 3000]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclPersona($s, 'Luca');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Luca'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'scrivi'])->fresh();
    expect(fclSaldi($s))->toBe(['Ines' => -8167, 'Luca' => 8167])->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -2269, 'Luca' => 2269]);
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302)->assertSessionHasNoErrors();
    pfgRegistroDiPrima($cambio);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [$s['box']], 'Luca') + ['conguaglio' => 'scrivi']);

    $ines = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
    expect(pfgProspetto($s, 'Ines'))->toBe([[9192, 16200, 0, 1159], ['a Dora' => 1159]])
        ->and((int) $ines['regolato_fra_le_parti'])->toBe(-8167)
        ->and(pfgProspetto($s, 'Luca'))->toBe([[4672, 0, -3495, 0], []]);
});

/** Il piano «Integrativa pulizie 2026» con la voce nuova «Pulizie scale» (€ 400,00, 30 % all'inquilino), dalle pagine, come in `rdpConIntegrativa`: generato, approvato, le due rate a giornale. */
function pfgIntegrativa($test, array $s): \App\Models\Gestionale\PianoRate
{
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000]]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Pulizie scale', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '400,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativa pulizie 2026', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-08-05', 'genera_subito' => true,
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del piano integrativo',
    ])->assertSessionHasNoErrors();
    $piano = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Integrativa pulizie 2026')->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-07-15'])
        ->assertSessionHasNoErrors();
    fds50Emetti($test, $s, '2026-09-30', $piano);

    return $piano->fresh();
}

it('Coda 245 (chi era già uscito) — dopo il cambio Ines → Sara del 1/7 con «Non scriverlo» e Luca che resta, la fine di Luca il 1/10 parte dalla regola del piano del cambio, come dopo «Scrivi»: Ines pagherebbe € 12,87 in più, Luca € 25,96 in meno, e Sara € 13,09 in più; i € 90,74 del cambio restano fra Ines e Sara', function () {
    // Ines e Luca al 50 %, le rate 18000 e 18000. Il cambio (decisione 37): Sara prende la quota di Ines dal 1/7; con «Non scriverlo» la coppia resta fra le parti, 18000 × 184/365 = 9073,97
    // → 9074 (Ines − 9074, Sara + 9074, `regolato_fuori`). La fine di Luca il 1/10, Sara resta: la parte di prima è la regola del piano con il cambio (pesi Ines 9050, Luca 18250, Sara 9200 su
    // 36500: 8926 / 18000 / 9074), quella di dopo (9050 / 13650 / 9200 su 31900) 10213,17 / 15404,39 / 10382,45 → 10213 / 15404 / 10383 (il centesimo a Sara, ,45). La differenza del
    // passaggio (P5, decisione 111.3): Ines + 1287, Luca − 2596, Sara + 1309, le cifre di «Scrivi» per persona. Sara non ha quote del piano né righe: nessuna coppia, è tutto resto. Il
    // prospetto: Ines 10213 − 18000 = − 7787 (da Sara 9074 per il cambio, a Luca 1287 per la fine); Luca 15404 − 18000 = − 2596 (da Ines 1287, da Sara 1309); Sara 10383 (a Ines 9074, a
    // Luca 1309). Era: la differenza dalle rate congelate, Ines − 7787, Luca − 2596, Sara + 10383, e il prospetto «Sara a Ines € 77,87» per la fine, senza il cambio.
    $s = rdpScenario($this, [['Ines', 50], ['Luca', 50]]);
    fclPersona($s, 'Sara');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Sara'], '2026-07-01', [], 'Ines') + ['conguaglio' => 'non_scrivere'])->fresh();
    expect(fclSaldi($s))->toBe([])
        ->and(collect($cambio->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Ines' => -9074, 'Sara' => 9074]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(pfgResto($a))->toBe([['Ines' => 1287, 'Luca' => -2596, 'Sara' => 1309]])
        ->and($a['rate']['frasi'][0])->toContain('con il cambio d\'inquilino del 1 luglio 2026, che non ha scritto il conguaglio (Sara rimborsa a Ines € 90,74 con il prospetto degli oneri accessori), Luca € 180,00, Ines € 89,26 e Sara € 90,74.')
        ->and($a['rate']['frasi'][0])->toContain('con la regola del piano Ines pagherebbe € 12,87 in più, Luca € 25,96 in meno, e Sara € 13,09 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.')
        ->and($a['rate']['frasi'][0])->not->toContain('che ha scritto la sua coppia')->and($a['rate']['frasi'][0])->not->toContain('nelle loro rate');
    fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));

    expect(pfgProspetto($s, 'Ines'))->toBe([[10213, 18000, 0, -7787], ['da Sara' => 9074, 'a Luca' => 1287]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[15404, 18000, 0, -2596], ['da Ines' => 1287, 'da Sara' => 1309]])
        ->and(pfgProspetto($s, 'Sara'))->toBe([[10383, 0, 0, 10383], ['a Ines' => 9074, 'a Luca' => 1309]])
        ->and(fclStampa($s))->toContain('il cambio d\'inquilino del 01/07/2026 non ha scritto il conguaglio');
});

it('Coda 245 (chi era già uscito) — lo stesso con un piano integrativo generato dopo il cambio, dove Sara ha le sue quote: la fine di Luca scrive Luca − € 34,61 a Sara, e la strada del Wallet è € 17,16 a debito di Ines e € 17,16 a credito di Sara, come dopo «Scrivi»', function () {
    // Il piano integrativo (anello 12000) generato dopo il cambio: 12000 × 9050/36500 = 2975,34 → Ines 2975, Luca 6000, Sara 3024,66 → 3025. La fine di Luca: 9050 / 13650 / 9200 su 31900 →
    // 3404,39 / 5134,80 / 3460,82 → 3404 / 5135 / 3461 (i centesimi a Sara, ,82, e a Luca, ,80): Ines + 429, Luca − 865, Sara + 436; con il preventivo Ines + 1716, Luca − 3461, Sara + 1745.
    // Sara ha quote del piano: la coppia di Luca, − 3461, va a lei; il resto Ines + 1716, Sara − 1716. Seguito il Wallet, i netti: Ines 18000 + 2975 + 1716 = 22691, che con i 9074 del cambio
    // fra le parti fanno 13617 = 10213 + 3404, il piano; Sara 3025 + 3461 − 1716 = 4770, + 9074 = 13844 = 10383 + 3461. Era: dalle rate congelate, Ines − 7358, Sara + 7358 («€ 73,58 a
    // credito di Ines»): seguito il Wallet, i € 90,74 del cambio entravano nei saldi.
    $s = rdpScenario($this, [['Ines', 50], ['Luca', 50]]);
    fclPersona($s, 'Sara');
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Sara'], '2026-07-01', [], 'Ines') + ['conguaglio' => 'non_scrivere']);
    $this->travel(1)->minutes();
    $integrativa = pfgIntegrativa($this, $s);
    $pure = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $integrativa->id)->where('rate_quote.immobile_id', $s['unita']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo'])->groupBy('anagrafica_id')
        ->map(fn ($g) => (int) $g->sum(fn ($q) => (int) (json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? 0)));
    $nomi = fclNomi($s);
    expect($pure->mapWithKeys(fn ($c, $id) => [$nomi[(int) $id] => $c])->only(['Ines', 'Luca', 'Sara'])->sortKeys()->all())->toBe(['Ines' => 2975, 'Luca' => 6000, 'Sara' => 3025]);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));
    expect(rdpCoppia($a, 'Luca'))->toBe(['Luca' => -3461, 'Sara' => 3461])
        ->and(pfgResto($a))->toBe([['Ines' => 1716, 'Sara' => -1716]])
        ->and($a['rate']['frasi'][0])->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 17,16 a debito di Ines e € 17,16 a credito di Sara.');
    $fine = fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    foreach ($fine->registro['resto_del_piano'][0]['parti'] as $p) {
        expect(sl53Errori(sl53Posta($this, $s, $p['nome'], (int) $p['cifra'], (int) $fine->id)))->toBe([]);
    }
    // I saldi dopo il Wallet: la coppia Luca − 3461, Sara + 3461 e le righe legate Ines + 1716, Sara − 1716; con le quote dei due piani i netti sono Ines 18000 + 2975 + 1716 = 22691,
    // Luca 18000 + 6000 − 3461 = 20539, Sara 3025 + 3461 − 1716 = 4770.
    expect(fclSaldi($s))->toBe(['Luca' => -3461, 'Sara' => 3461])
        ->and(sl53Legate($s, $fine))->toBe(['Ines' => 1716, 'Sara' => -1716]);
});

it('Coda 245 (chi era già uscito) — il cambio Ines → Sara del 1/7 con «Non scriverlo» e il box locato insieme, poi la fine di Luca il 1/10: sull\'Interno 1 Sara rimborsa a Ines € 81,67, la parte dell\'unità, non i € 99,82 della gestione con il box', function () {
    // L'Interno 1 900/1000 (anello 32400, Ines e Luca 16200), il Box 12 100/1000 (3600, di Ines). Il cambio con il box: Interno 1 16200 × 184/365 = 8166,58 → 8167, box 3600 × 184/365 =
    // 1814,79 → 1815; fra le parti 9982 per la gestione. La fine di Luca sull'Interno 1, Sara resta: prima 8033 / 16200 / 8167 (9050 / 18250 / 9200 su 36500), dopo 9191,85 / 13863,95 /
    // 9344,20 → 9192 / 13864 / 9344 (i due centesimi a Luca, ,95, e a Ines, ,85): Ines + 1159, Luca − 2336, Sara + 1177, tutto resto. Il prospetto dell'Interno 1: Ines 9192 − 16200 = − 7008
    // (da Sara 8167, a Luca 1159), Luca 13864 − 16200 = − 2336 (da Ines 1159, da Sara 1177), Sara 9344 (a Ines 8167, a Luca 1177).
    $s = rdpScenario($this, [['Ines', 50], ['Luca', 50]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclPersona($s, 'Sara');
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Sara'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'non_scrivere']);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));
    expect(pfgResto($a))->toBe([['Ines' => 1159, 'Luca' => -2336, 'Sara' => 1177]])
        ->and($a['rate']['frasi'][0])->toContain('che non ha scritto il conguaglio (Sara rimborsa a Ines € 81,67 con il prospetto degli oneri accessori)');
    fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));

    expect(pfgProspetto($s, 'Ines'))->toBe([[9192, 16200, 0, -7008], ['da Sara' => 8167, 'a Luca' => 1159]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[13864, 16200, 0, -2336], ['da Ines' => 1159, 'da Sara' => 1177]])
        ->and(pfgProspetto($s, 'Sara'))->toBe([[9344, 0, 0, 9344], ['a Ines' => 8167, 'a Luca' => 1177]]);
});

it('Coda 245 (chi era già uscito) — il cambio Ines → Sara del 1/7 con «Non scriverlo» e il box, scritto prima di questa versione, con un\'entrata del solo Interno 1 («Giardino»), poi la fine di Luca il 1/10: sull\'Interno 1 Sara rimborsa a Ines € 51,42, non € 59,86', function () {
    // Ines e Luca al 50 % sull'Interno 1 (le rate 16200 − 6000 = 10200 ciascuno), il box di Ines; la «Giardino» entrata del solo Interno 1, − 12000. Il cambio con il box e «Non scriverlo»:
    // Interno 1 8167 − 3025 = 5142, box 1815, fra le parti 6957 per la gestione; il registro com'era scritto prima. La fine di Luca, Sara resta: spese prima 8033 / 16200 / 8167 (9050 / 18250 / 9200
    // su 36500), dopo 9191,85 / 13863,95 / 9344,20 → 9192 / 13864 / 9344 (i centesimi a Luca, ,95, e a Ines, ,85); entrata prima − 2975 / − 6000 / − 3025, dopo − 3404,39 / − 5134,80 / − 3460,82
    // → − 3404 / − 5135 / − 3461 (i centesimi a Sara, ,82, e a Luca, ,80); le parti da 5058 / 10200 / 5142 a 5788 / 8729 / 5883: Ines + 730, Luca − 1471, Sara + 741, tutto resto (Sara non ha
    // quote né righe). La parte del cambio sull'Interno 1 dalla cifra della gestione, con il segno: 6957 → 5142 (sopra). Il prospetto: Ines 5788 − 10200 = − 4412 (da Sara 5142, a Luca 730);
    // Luca 8729 − 10200 = − 1471 (da Ines 730, da Sara 741); Sara 5883 (a Ines 5142, a Luca 741). Era: Sara a Ines 5986, e 5986 + 741 = 6727 contro i suoi 5883.
    $s = pfgConLEntrata($this, [['Ines', 50], ['Luca', 50]], '400,00');
    fclPersona($s, 'Sara');
    $cambio = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, $s['persone']['Sara'], '2026-07-01', [$s['box']], 'Ines') + ['conguaglio' => 'non_scrivere'])->fresh();
    expect(collect($cambio->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Ines' => -6957, 'Sara' => 6957]);
    pfgRegistroDiPrima($cambio);

    $a = fclAnteprima($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));
    expect(pfgResto($a))->toBe([['Ines' => 730, 'Luca' => -1471, 'Sara' => 741]])
        ->and($a['rate']['frasi'][0])->toContain('che non ha scritto il conguaglio (Sara rimborsa a Ines € 51,42 con il prospetto degli oneri accessori), Luca € 102,00, Ines € 50,58 e Sara € 51,42.')
        ->and($a['rate']['frasi'][0])->not->toContain('€ 59,86');
    fclRegistra($this, $s, pfgModuloFine($s, 'Luca', '2026-10-01'));

    expect(pfgProspetto($s, 'Ines'))->toBe([[5788, 10200, 0, -4412], ['da Sara' => 5142, 'a Luca' => 730]])
        ->and(pfgProspetto($s, 'Luca'))->toBe([[8729, 10200, 0, -1471], ['da Ines' => 730, 'da Sara' => 741]])
        ->and(pfgProspetto($s, 'Sara'))->toBe([[5883, 0, 0, 5883], ['a Ines' => 5142, 'a Luca' => 741]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('€ 51,42')->and($stampa)->not->toContain('€ 59,86')->and($stampa)->not->toContain('da rimborsare a chi le ha pagate');
});

it('Coda 245 (chi era già uscito) — l\'avviso dell\'annullamento della fine di Dora senza coppia non parla di un conguaglio: la regola del piano non ha scritto righe nei saldi, e ha lasciato da regolare a mano la parte di Ines, di Dora e di Pia', function () {
    // La stessa fine di Dora senza coppia di sopra (resto Ines − 4520, Dora + 377, Pia + 4143). Il passaggio non ha un conguaglio: «ha lasciato fuori dal conguaglio» (la forma del resto
    // di una coppia) era falso, e lo storico e il motivo del cancello dicevano già «non scrive righe nei saldi, e restano da regolare a mano».
    [$s, $fine] = pfgFineSenzaCoppia($this);
    $parte = '€ 45,20 a credito di Ines, € 3,77 a debito di Dora e € 41,43 a debito di Pia';

    // Senza i saldi del Wallet legati: se le parti l'avevano regolata fra loro, quell'accordo va rivisto.
    expect(fds50Storico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano non ha scritto righe nei saldi, e ha lasciato da regolare a mano ' . $parte
        . ': annullandolo quella parte non c\'è più; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.')
        ->and(implode(' ', fds50Storico($s, $fine)['annullabile']['avvisi']))->not->toContain('dal conguaglio');

    // Con i saldi del Wallet legati (la strada indicata, seguita): si tolgono con il passaggio.
    foreach ($fine->registro['resto_del_piano'][0]['parti'] as $p) {
        expect(sl53Errori(sl53Posta($this, $s, $p['nome'], (int) $p['cifra'], (int) $fine->id)))->toBe([]);
    }
    expect(fds50Storico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano non ha scritto righe nei saldi, e ha lasciato da regolare a mano ' . $parte
        . ': annullandolo quella parte non c\'è più, e i saldi del Wallet legati a questo passaggio si tolgono con lui.');
});
