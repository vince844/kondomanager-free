<?php

/**
 * 1.11.0-beta.55, Coda 245 — i passaggi dopo una fine con il coinquilino che resta, sullo stesso inquilinato (decisione 95.6 b).
 *
 * La fine di Ines con Dora che resta ha diviso la parte «Inquilino» del piano con la regola del piano (`Beta55CoinquilinoCoppiaDelPianoTest`).
 * I passaggi dopo sullo stesso inquilinato (unità, gestione ed esercizio) leggono quella regola: ognuno scrive e dice la differenza del piano
 * del passaggio, la parte di ognuno con le righe di dopo meno quella con le righe di prima (decisione 111.3, «Differenza del piano»). La fine
 * di chi resta scrive solo la sua coppia verso chi riceve i suoi giorni; ciò che la regola del piano cambierebbe a chi era già uscito resta
 * fuori, detto con la persona e la cifra, con la spunta e la strada del Wallet (`resto_del_piano`). Il cambio d'inquilino dà la parte di chi
 * esce a chi entra; l'inizio di un conduttore e la fine di chi non ha quote del piano non scrivono niente: il pannello dice le cifre, il
 * prospetto chi rimborsa chi. Dalla parte del proprietario conta solo la parte dei giorni che nessun conduttore copre (il ripiego), con la
 * macchina della fermata dopo «Scrivi». Dopo una fine registrata da una beta con la fermata del coinquilino resta la regola di oggi
 * (decisione 111.2). La fine della 245 è registrata dalla rotta, mai scritta a mano.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni; «Spese generali» € 1.200,00 al 30 % all'inquilino, l'anello dell'Interno 1 è 36000;
 * Ines e Dora al 50 %, congelate 18000 e 18000; Ugo proprietario):
 * - Dopo la fine di Ines il 1/7 (181 giorni): pesi Ines 50 × 181 = 9050, Dora 50 × 365 = 18250, somma 27300; 36000 × 9050 / 27300 =
 *   11934,07 → Ines 11934, Dora 24066 (,93). È la parte di prima di ogni passaggio dopo (la coppia l'ha portata lì, o la dice il piano).
 * - C4, Dora esce il 1/10 (273 giorni; dal 1/10 al 31/12, 92 giorni, nessun inquilino: il ripiego, Ugo): i conduttori coprono 273 giorni,
 *   Ines 9050 / 22700 × 273 = 108,84 e Dora 13650 / 22700 × 273 = 164,16 giorni, Ugo 92; 36000 × 108,84 / 365 = 10734,85, Dora 16191,18,
 *   Ugo 9073,97 → 10734 + 16191 + 9073 = 35998, i due centesimi a Ugo (,97) e a Ines (,85): Ines 10735, Dora 16191, Ugo 9074. Differenze
 *   del passaggio: Ines − 1199, Dora − 7875, Ugo + 9074. Ines è già uscita: si scrive la differenza di Dora verso chi riceve i suoi giorni,
 *   Ugo (− 7875 / + 7875); restano fuori Ines − 1199 e Ugo + 1199 (Ines € 11,99 sopra il piano, Ugo € 11,99 sotto). Con «Non scriverlo» alla
 *   fine di Ines (C4-ns) le cifre sono le stesse: la differenza del passaggio non dipende dalla scelta di prima.
 * - C6.5a, Dora esce anche lei il 1/7: dopo Ines 9050 e Dora 50 × 181 = 9050, coperti 181 giorni, Ugo 184: Ines 8926 (36000 × 90,5 / 365 =
 *   8926,03), Dora 8926, Ugo 18148 (18147,95); differenze Ines − 3008, Dora − 15140, Ugo + 18148: Dora − 15140 → Ugo, fuori Ines − 3008 e
 *   Ugo + 3008.
 * - C3-Dora (Ines 40, Dora 30, Pia 30): dopo la fine di Ines i pesi 40 × 181 = 7240, 30 × 365 = 10950 e 10950 (somma 29140) danno 8944,41,
 *   13527,79 e 13527,79 → 8944 / 13528 / 13528 (i due centesimi a Dora e Pia). Dora esce il 1/10 e Pia resta: 7240, 30 × 273 = 8190, 10950
 *   (somma 26380): 9880,21, 11176,65, 14943,14 → Ines 9880, Dora 11177, Pia 14943; differenze Ines + 936, Dora − 2351, Pia + 1415: Dora − 2351
 *   → Pia, fuori Ines + 936 (sotto il piano: pagherebbe € 9,36 in più) e Pia − 936.
 * - OM27: Dora esce il 1/4 (90 giorni) e Ines resta (la 245: 50 × 90 = 4500 e 18250 danno 7120,88 e 28879,12 → Dora 7121, Ines 28879: Dora
 *   − 10879, Ines + 10879); poi Ines esce il 1/7: coperti 181 giorni (Dora 4500 / 13550 × 181, Ines 9050 / 13550 × 181), Ugo 184:
 *   5928,73, 11923,33, 18147,95 → Dora 5929, Ines 11923, Ugo 18148; differenze Dora − 1192, Ines − 16956, Ugo + 18148: Ines − 16956 → Ugo,
 *   fuori Dora − 1192 e Ugo + 1192.
 * - E1-Dora («Affitto antenna» € 1.000,00 di entrata dal 1/10 al 31/12, al 30 %: l'anello dell'entrata è − 30000; la 245 di Ines + 8934,
 *   Dora − 8934). Dora esce il 1/11 (304 giorni): spese 36000 con Ines 9050 / 24250 × 304, Dora 15200 / 24250 × 304, Ugo 61 → 11189,74,
 *   18793,82, 6016,44 → Ines 11190, Dora 18794, Ugo 6016; l'entrata (92 giorni) Dora 31, Ugo 61 → − 10108,70 e − 19891,30 → Dora − 10109,
 *   Ugo − 19891. Differenze: Ines − 744; Dora − 5272 + 19891 = + 14619; Ugo + 6016 − 19891 = − 13875. La differenza di Dora è un debito, e
 *   chi riceve ha quella di segno opposto, Ugo: Dora + 14619, Ugo − 14619 (un credito); fuori Ines − 744 e Ugo + 744.
 *
 * - B6 (Dora con la riga chiusa al 30/9; dal 1/10 al 31/12 nessun inquilino, il ripiego, Ugo): la 245 di Ines − 9861, Dora + 787, Ugo + 9074
 *   (la parte del ripiego, i 92 giorni dal 1/10). La vendita di Ugo a Elsa il 1/11 si ferma sulla sola parte del ripiego: 9074 × 61 / 92 =
 *   6016,46 → Elsa 6016 (prima, con le righe di Dora: € 32,69). Poi Luca entra il 1/12 al 50 % (31 giorni): la fermata scende ai 30 giorni
 *   di novembre, 9074 × 30 / 92 = 2958,91 → 2959; l'anello con Luca: coperti 273 + 31 = 304 giorni, Ines 9050 / 24250 × 304, Dora 13650 /
 *   24250 × 304, Luca 1550 / 24250 × 304, Ugo 31, Elsa 30 → 11189,74, 16877,35, 1916,48, 3057,53, 2958,90 → Ines 11190, Dora 16877, Luca 1916,
 *   Ugo 3058, Elsa 2959 (prima: 10735, 16191, 0, 3058, 6016): Luca + 1916, Ines + 455, Dora + 686, Elsa − 3057.
 * - C5a, Luca dal 1/9 al 50 % (122 giorni): Luca 6100, Ines 9050, Dora 18250 (somma 33400): 6574,85, 9754,49, 19670,66 → Luca 6575, Ines
 *   9754, Dora 19671: Luca + 6575, Dora − 4395, Ines − 2180. C5c, dal 1/7 (184 giorni): Luca 9074, Ines 8926, Dora 18000. C5a-Luca, Luca esce
 *   il 1/12 (91 giorni): Luca 4550 → 5142,86, Ines 10229,20, Dora 20627,94 → Luca 5143, Ines 10229, Dora 20628: Luca − 1432, Dora + 957,
 *   Ines + 475. C5d, il cambio Dora → Luca il 1/10: Dora 50 × 273 = 13650, Luca 50 × 92 = 4600, Ines 9050 (somma 27300, come prima):
 *   Ines 11934, Dora 18000, Luca 6066 (6065,93): Dora − 6066 → Luca, la parte di Ines non cambia.
 *
 * Cosa NON copre: il prospetto degli oneri accessori e la stampa (la strada della regola del piano, `Beta55CoinquilinoProspettoTest`); la
 * pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
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

/**
 * Lo scenario della regola del piano con la fine di Ines del 1/7 registrata dalla rotta, con la scelta data (`scrivi` o `non_scrivere`), e le
 * persone che i passaggi dopo useranno (Luca, Elsa). Opzioni: quelle di `rdpScenario`, e `esce` (chi esce per primo, di partenza Ines) e `il`.
 */
function cdcDopoLaFine($test, string $scelta = 'scrivi', array $inquilini = [['Ines', 50], ['Dora', 50]], array $opz = []): array
{
    $s = rdpScenario($test, $inquilini, $opz);
    foreach (['Luca', 'Elsa'] as $n) {
        fclPersona($s, $n);
    }
    $esce = $opz['esce'] ?? 'Ines';
    $s['fine_prima'] = fclRegistra($test, $s, cdcModuloFine($s, $esce, $opz['il'] ?? '2026-07-01') + ['conguaglio' => $scelta])->fresh();

    return $s;
}

/** Il modulo della fine della locazione di `$chi` il giorno dato, con la quota della sua riga (e chi entra, nel cambio d'inquilino). */
function cdcModuloFine(array $s, string $chi, string $il, ?string $entra = null): array
{
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->value('quota');

    return ['quota' => $quota] + fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, [], $chi);
}

/** L'inizio della locazione di `$chi` il giorno dato, alla quota data. */
function cdcModuloInizio(array $s, string $chi, string $il, float|int $quota = 50): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
}

/** Il resto della regola del piano dell'anteprima, in breve: nome → cifra con il segno dei saldi, gruppo per gruppo. */
function cdcResto(array $anteprima): array
{
    return array_map(fn (array $r) => collect($r['parti'])->mapWithKeys(fn (array $p) => [(string) $p['nome'] => (int) $p['cifra']])->sortKeys()->all(),
        $anteprima['rate']['resto_del_piano'] ?? []);
}

/** Le righe in saldi scritte da un passaggio (padre e pertinenze), per nome. */
function cdcRighe(array $s, Subentro $passaggio): array
{
    return fds50Coppia($s, $passaggio);
}

/** La voce dello storico dell'Interno 1 per un passaggio. */
function cdcStorico(array $s, Subentro $passaggio): array
{
    return fds50Storico($s, $passaggio);
}

// --- La fine del coinquilino dopo la 245 -------------------------------------------------------------------------------------------------

it('Coda 245 (dopo la coppia) — C4: la fine di Dora dopo la fine di Ines con «Scrivi» scrive solo la sua coppia, Dora − € 78,75 a Ugo; il resto, Ines € 11,99 sopra il piano, si dice e chiede la spunta; nessuna fermata', function () {
    $s = cdcDopoLaFine($this);
    $ines = (int) $s['persone']['Ines']->id;
    $ugo = (int) $s['persone']['Ugo']->id;

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        // Le righe non portano ognuno alla sua parte (resta fuori la parte di Ines): la voce «Scrivi» del pannello lo dice (`piano_coppia`).
        ->and($a['rate']['conguaglio']['regola'])->toBe('piano_coppia')
        // La coppia della fine di Ines non è una coppia dei giorni dopo l'uscita: nessuna fermata (era «€ 30,33 … restano a carico di Dora»).
        ->and($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and(cdcResto($a))->toBe([['Ines' => -1199, 'Ugo' => 1199]])
        ->and($a['rate']['resto_del_piano'][0])->toMatchArray(['fine_id' => (int) $s['fine_prima']->id, 'fine_il' => '2026-07-01', 'persona' => 'Dora',
            'immobile_id' => (int) $s['unita']->id, 'gestione_id' => (int) $s['g']->id, 'esercizio_id' => (int) $s['e']->id, 'dal' => '2026-10-01', 'cifra' => 1199,
            'wallet' => true, 'strada' => FermataDopoScrivi::STRADA_LEGATA])
        ->and($a['rate']['frasi'][0])->toBe('Le rate già emesse non si toccano. La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio con la regola del piano: sulla gestione Ordinaria 2026 Ines € 119,34 e Dora € 240,66. Con la fine della locazione di Dora i giorni dal 1 ottobre 2026 vanno a chi paga al posto dell\'inquilino, Ugo, e la regola del piano ridivide la voce: Ines € 107,35, Dora € 161,91 e Ugo € 90,74. Se scrivi il conguaglio, il programma scrive solo la coppia di Dora: € 78,75 a credito di Dora e € 78,75 a debito di Ugo. La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 11,99 in meno, e Ugo € 11,99 in più. Se le parti la vogliono al piano, registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 11,99 a credito di Ines e € 11,99 a debito di Ugo. Se non scrivi il conguaglio, nei saldi non si scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and($a['cancello']['motivi'])->toContain('se scrivi il conguaglio, € 11,99 della regola del piano restano a credito di Ines e a debito di Ugo (gestione Ordinaria 2026): la fine della locazione di Dora scrive solo la sua coppia')
        ->and($a['cancello']['richiesto'])->toBeTrue()
        ->and(fclFrasi($a))->not->toContain('€ 30,33')
        ->and(fclFrasi($a))->not->toContain('€ 45,37')
        ->and(fclFrasi($a))->not->toContain('lo decidono le parti');

    // Con «Non scriverlo» nei saldi non si scrive niente: il resto non è un motivo del cancello.
    $n = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'non_scrivere']);
    expect(implode(' ', $n['cancello']['motivi']))->not->toContain('€ 11,99')
        ->and($n['rate']['resto_del_piano'])->toBe([]);

    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(cdcRighe($s, $fine))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(fclNetti($s))->toBe(['Dora' => 16191, 'Ines' => 11934, 'Ugo' => 91875])
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and($fine->registro)->not->toHaveKey('fermata_dopo_scrivi')
        ->and($fine->registro['conguaglio']['piano'][0])->toMatchArray(['segue_fine_id' => (int) $s['fine_prima']->id, 'uscente_id' => (int) $s['persone']['Dora']->id,
            'parti' => [(int) $s['persone']['Dora']->id => -7875, $ugo => 7875], 'resto' => [$ines => -1199, $ugo => 1199], 'ripiego' => [$ugo]])
        ->and($fine->registro['resto_del_piano'])->toHaveCount(1)
        ->and(collect($fine->registro['resto_del_piano'][0]['parti'])->pluck('cifra', 'nome')->all())->toBe(['Ines' => -1199, 'Ugo' => 1199])
        // Dalla parte del proprietario la coppia di Dora è dei giorni dal 1/10, che nessun conduttore copre: i periodi portano la parte scritta a Ugo,
        // € 78,75, non la sua differenza del piano (€ 90,74, di cui € 11,99 restano fuori).
        ->and($fine->registro['conguaglio']['periodi'])->toBe([['gestione_id' => (int) $s['g']->id, 'immobile_id' => (int) $s['unita']->id, 'esercizio_id' => (int) $s['e']->id,
            'importo' => 7875, 'periodi' => [['tratti' => [['dal' => '2026-10-01', 'al' => '2026-12-31']], 'importo' => 7875]]]]);
});

it('Coda 245 (dopo la coppia) — C4 su un piano senza riparto registrato (generato dalla 1.10): le stesse cifre, e il pannello rimanda al prospetto, che legge il piano con la regola del piano', function () {
    // Il ramo dei piani senza riparto (`RegolaDelPiano::anelli`): l'anello sono le quote pure degli inquilini, 18000 + 18000, sulla competenza del piano;
    // le cifre di C4 non cambiano (Dora − 7875 → Ugo, fuori Ines − 1199 e Ugo + 1199). Cambiato con la decisione 115.1: il prospetto degli oneri accessori
    // legge anche quel piano con la regola del piano (`Beta55CoinquilinoProspettoTest`, C4 sul piano della 1.10), e la frase lo dice; prima «chi rimborsa chi
    // lo regolano le parti: il prospetto degli oneri accessori lascia fuori il piano».
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $s['fine_prima'] = fclRegistra($this, $s, cdcModuloFine($s, 'Ines', '2026-07-01') + ['conguaglio' => 'scrivi'])->fresh();

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(cdcResto($a))->toBe([['Ines' => -1199, 'Ugo' => 1199]])
        ->and($a['rate']['frasi'][0])->toEndWith('Se non scrivi il conguaglio, nei saldi non si scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and($a['rate']['frasi'][0])->not->toContain('lascia fuori il piano');
});

it('Coda 245 (dopo la coppia) — C4-ns: dopo la fine di Ines con «Non scriverlo» la fine di Dora ha le stesse cifre, Dora − € 78,75 a Ugo e Ines € 11,99 sopra il piano; i € 60,66 di quella fine restano fra Dora e Ines', function () {
    $s = cdcDopoLaFine($this, 'non_scrivere');

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(cdcResto($a))->toBe([['Ines' => -1199, 'Ugo' => 1199]])
        ->and($a['rate']['frasi'][0])->toStartWith('Le rate già emesse non si toccano. La fine locazione di Ines del 1 luglio 2026 non ha scritto il conguaglio: con la regola del piano sulla gestione Ordinaria 2026 Ines € 119,34 e Dora € 240,66, e la differenza di quella fine, € 60,66 a credito di Ines e € 60,66 a debito di Dora, resta fra le parti, con il prospetto degli oneri accessori. Con la fine della locazione di Dora i giorni dal 1 ottobre 2026 vanno a chi paga al posto dell\'inquilino, Ugo')
        ->and($a['rate']['frasi'][0])->toContain('il programma scrive solo la coppia di Dora: € 78,75 a credito di Dora e € 78,75 a debito di Ugo.');

    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi']);
    expect(cdcRighe($s, $fine))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(fclNetti($s))->toBe(['Dora' => 10125, 'Ines' => 18000, 'Ugo' => 91875]);
});

it('Coda 245 (dopo la coppia) — dopo «Annulla il conguaglio» della fine di Ines l\'anello resta della regola del piano: la fine di Dora ha le stesse cifre, e la frase dice che le parti hanno regolato fra loro la differenza di quella fine', function () {
    $s = cdcDopoLaFine($this);
    sl53AnnullaConguaglio($this, $s, $s['fine_prima'])->assertStatus(302);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(cdcResto($a))->toBe([['Ines' => -1199, 'Ugo' => 1199]])
        ->and($a['rate']['frasi'][0])->toStartWith('Le rate già emesse non si toccano. La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio con la regola del piano, e il conguaglio è stato annullato: le parti hanno regolato fra loro la differenza di quella fine, € 60,66 a credito di Ines e € 60,66 a debito di Dora. Con la regola del piano, sulla gestione Ordinaria 2026, Ines € 119,34 e Dora € 240,66.');
});

it('Coda 245 (dopo la coppia) — a pari resto la parte di prima è quella che la fine con la regola del piano ha scritto: Dora esce il 31/7, poi Ines il 1/10', function () {
    // Dora esce il 31/7 (211 giorni) e Ines resta: 36000 × 10550 / 28800 = 13187,5 e 22812,5, a pari resto il centesimo a chi esce, Dora: Dora 13188,
    // Ines 22812 (Dora − 4812, Ines + 4812). Ines esce il 1/10: coperti 273 giorni, Ines 13650 / 24200 × 273, Dora 10550 / 24200 × 273, Ugo 92 →
    // 15187,61, 11738,41, 9073,97 → Ines 15188, Dora 11738, Ugo 9074. Contro la parte di prima del passaggio, ridivisa con il centesimo a chi era uscito
    // con quella fine (Dora 13188, Ines 22812): Ines − 7624 → Ugo, e restano fuori Dora − 1450 e Ugo + 1450; scritta la coppia, Ines è al piano al
    // centesimo (22812 − 7624 = 15188). Con il centesimo dato in ordine di riga (a Ines) sarebbero Ines − 7625 e Dora − 1449: Ines un centesimo sotto.
    $s = cdcDopoLaFine($this, opz: ['esce' => 'Dora', 'il' => '2026-07-31']);
    expect(fds50Coppia($s, $s['fine_prima']))->toBe(['Dora' => -4812, 'Ines' => 4812]);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Ines', '2026-10-01'));
    expect(rdpCoppia($a, 'Ines'))->toBe(['Ines' => -7624, 'Ugo' => 7624])
        ->and(cdcResto($a))->toBe([['Dora' => -1450, 'Ugo' => 1450]]);

    fclRegistra($this, $s, cdcModuloFine($s, 'Ines', '2026-10-01') + ['conguaglio' => 'scrivi']);
    expect(fclNetti($s))->toBe(['Dora' => 13188, 'Ines' => 15188, 'Ugo' => 91624]);
});

it('Coda 245 (dopo la coppia) — C6.5a e C6.5a-ns: Dora esce lo stesso giorno di Ines; la sua coppia è − € 151,40 a Ugo, e Ines resta € 30,08 sopra il piano, con le due scelte della fine di Ines', function () {
    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $s = cdcDopoLaFine($this, $scelta);
        $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-07-01'));
        expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -15140, 'Ugo' => 15140], $scelta)
            ->and(cdcResto($a))->toBe([['Ines' => -3008, 'Ugo' => 3008]], $scelta)
            ->and($a['rate']['fermate_dopo_scrivi'])->toBe([]);
    }
});

it('Coda 245 (dopo la coppia) — C3-Dora: con Pia che resta la fine di Dora dà a Pia la sua differenza, € 23,51; Ines pagherebbe € 9,36 in più e Pia € 9,36 in meno', function () {
    $s = cdcDopoLaFine($this, 'scrivi', [['Ines', 40], ['Dora', 30], ['Pia', 30]]);
    expect(fds50Coppia($s, $s['fine_prima']))->toBe(['Dora' => 2728, 'Ines' => -5456, 'Pia' => 2728]);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -2351, 'Pia' => 2351])
        ->and(cdcResto($a))->toBe([['Ines' => 936, 'Pia' => -936]])
        ->and($a['rate']['frasi'][0])->toContain('Con la fine della locazione di Dora la locazione continua con Pia, e la regola del piano ridivide la voce: Ines € 98,80, Dora € 111,77 e Pia € 149,43.')
        ->and($a['rate']['frasi'][0])->toContain('La parte di Ines non si tocca, perché la sua locazione è già finita: con la regola del piano Ines pagherebbe € 9,36 in più, e Pia € 9,36 in meno. Se le parti la vogliono al piano, registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 9,36 a debito di Ines e € 9,36 a credito di Pia.')
        ->and($a['cancello']['motivi'])->toContain('se scrivi il conguaglio, € 9,36 della regola del piano restano a debito di Ines e a credito di Pia (gestione Ordinaria 2026): la fine della locazione di Dora scrive solo la sua coppia');
});

it('Coda 245 (dopo la coppia) — OM27: Dora esce il 1/4 con Ines che resta, poi Ines il 1/7: la fine di Ines scrive − € 169,56 a Ugo, e Dora resta € 11,92 sopra il piano', function () {
    $s = cdcDopoLaFine($this, 'scrivi', opz: ['esce' => 'Dora', 'il' => '2026-04-01']);
    expect(fds50Coppia($s, $s['fine_prima']))->toBe(['Dora' => -10879, 'Ines' => 10879]);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Ines', '2026-07-01'));
    expect(rdpCoppia($a, 'Ines'))->toBe(['Ines' => -16956, 'Ugo' => 16956])
        ->and(cdcResto($a))->toBe([['Dora' => -1192, 'Ugo' => 1192]]);
});

it('Coda 245 (dopo la coppia) — E1-Dora: con l\'entrata dal 1/10 la differenza di Dora è un debito, € 146,19, e Ugo riceve un credito; Ines resta € 7,44 sopra il piano', function () {
    $s = cdcDopoLaFine($this, 'scrivi', opz: ['capitoli' => [['Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31']]]);
    expect(fds50Coppia($s, $s['fine_prima']))->toBe(['Dora' => -8934, 'Ines' => 8934]);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-11-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => 14619, 'Ugo' => -14619])
        ->and(cdcResto($a))->toBe([['Ines' => -744, 'Ugo' => 744]])
        ->and($a['rate']['frasi'][0])->toContain('il programma scrive solo la coppia di Dora: € 146,19 a debito di Dora e € 146,19 a credito di Ugo.')
        ->and(fclFrasi($a))->not->toContain('€ -');

    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-11-01') + ['conguaglio' => 'scrivi']);
    expect(cdcRighe($s, $fine))->toBe(['Dora' => 14619, 'Ugo' => -14619]);
});

it('Coda 245 (dopo la coppia) — C4, i lettori del resto: lo storico della fine di Dora lo dice, con la strada del Wallet; il Wallet segna il passaggio con una parte da regolare a mano; con le righe legate lo storico ne dice i fatti', function () {
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();

    $frase = 'Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 107,35, Dora € 161,91 e Ugo € 90,74: il conguaglio ha scritto solo la coppia di Dora, € 78,75 a credito di Dora e € 78,75 a debito di Ugo, e la parte di Ines è rimasta € 11,99 sopra il piano, quella di Ugo € 11,99 sotto. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 11,99 a credito di Ines e € 11,99 a debito di Ugo.';
    expect(cdcStorico($s, $fine)['fermate'])->toBe([$frase]);

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    $unita = collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id);
    expect(collect($unita['passaggi_legabili'])->mapWithKeys(fn ($p) => [$p['id'] => $p['con_parte_a_mano']])->all())
        ->toBe([(int) $fine->id => true, (int) $s['fine_prima']->id => false]);

    // La strada del Wallet, seguita: le due righe legate alla fine di Dora.
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);
    expect(cdcStorico($s, $fine)['fermate'])->toBe([$frase . ' I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano.']);
});

it('Coda 245 (dopo la coppia) — C4, «Annulla il conguaglio» della fine di Dora con le righe legate del resto: l\'avviso dice che sono già il resto della regola del piano di questo passaggio', function () {
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);

    expect(cdcStorico($s, $fine)['conguaglio']['avvisi'])->toBe(['Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano di questo passaggio.']);
});

// --- Il cambio e l'inizio sull'anello di una 245 -----------------------------------------------------------------------------------------

it('Coda 245 (dopo la coppia) — C5d: il cambio Dora → Luca il 1/10 dà a Luca la differenza di Dora, € 60,66; la parte di Ines non cambia, e Luca non ha giorni da rimborsare', function () {
    $s = cdcDopoLaFine($this);

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01', 'Luca'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -6066, 'Luca' => 6066])
        // La regola del cambio sull'anello è `piano`: le due voci della scelta nel pannello dicono la regola del piano, non i giorni (vitest della pagina).
        ->and($a['rate']['conguaglio']['regola'])->toBe('piano')
        ->and($a['rate']['conguaglio']['per_gestione'][0]['regola'])->toBe('piano')
        ->and($a['rate']['resto_del_piano'])->toBe([])
        // Prima i € 30,33 della coppia della fine di Ines erano «giorni del conduttore pagati da Dora».
        ->and($a['rate']['giorni_del_conduttore'] ?? [])->toBe([])
        ->and($a['rate']['frasi'][0])->toBe('Le rate già emesse non si toccano. Se scrivi il conguaglio, le sue righe fra Dora e Luca, che entra come inquilino, entrano nei saldi e sommano a zero: con la regola del piano la parte di Ines non cambia, e € 60,66 della parte di Dora passano a Luca. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e Luca rimborsa a Dora € 60,66 con il prospetto degli oneri accessori.')
        ->and(fclFrasi($a))->not->toContain('€ 45,37')
        ->and(fclFrasi($a))->not->toContain('€ 30,33');

    $cambio = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01', 'Luca') + ['conguaglio' => 'scrivi'])->fresh();
    expect(cdcRighe($s, $cambio))->toBe(['Dora' => -6066, 'Luca' => 6066])
        ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 11934, 'Luca' => 6066, 'Ugo' => 84000])
        ->and($cambio->registro['conguaglio']['piano'][0])->toMatchArray(['segue_fine_id' => (int) $s['fine_prima']->id, 'entra' => (int) $s['persone']['Luca']->id, 'resto' => []])
        ->and($cambio->registro)->not->toHaveKey('giorni_del_conduttore');
});

it('Coda 245 (dopo la coppia) — C5a: l\'inizio di Luca dal 1/9 al 50 % è un\'informazione con le cifre del piano, senza la spunta; il registro conserva i giorni del conduttore con la regola del piano', function () {
    $s = cdcDopoLaFine($this);

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
    expect($a['rate']['frasi'][0])->toContain('Il piano «Preventivo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha. La fine locazione di Ines del 1 luglio 2026 ha diviso la parte dell\'inquilino con la regola del piano (Ines € 119,34 e Dora € 240,66). Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 65,75, Dora € 196,71 e Ines € 97,54: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 43,95 a Dora e € 21,80 a Ines. Un piano generato dopo dividerà quelle voci fra Dora e Luca, per quote e giorni.')
        ->and(fclFrasi($a))->not->toContain('lo decidono le parti')
        ->and(fclFrasi($a))->not->toContain('€ 24,18')
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('€ 65,75')
        ->and($a['rate']['giorni_del_conduttore'])->toHaveCount(1)
        ->and($a['rate']['giorni_del_conduttore'][0])->toMatchArray(['fine_id' => (int) $s['fine_prima']->id, 'conduttore' => 'Luca', 'dal' => '2026-09-01', 'cifra' => 6575,
            'pagato_da' => ['Dora', 'Ines'], 'regola' => 'piano'])
        ->and(collect($a['rate']['giorni_del_conduttore'][0]['parti'])->pluck('cifra', 'nome')->all())->toBe(['Luca' => 6575, 'Dora' => -4395, 'Ines' => -2180]);

    $inizio = fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'))->fresh();
    expect($inizio->registro['giorni_del_conduttore'][0]['regola'] ?? null)->toBe('piano')
        ->and(fclRigheDeiPassaggi($s))->toBe(2);

    // Dopo la fine di Ines con «Non scriverlo» le cifre sono le stesse (la differenza del passaggio), e la frase dice la scelta di quella fine.
    $s = cdcDopoLaFine($this, 'non_scrivere');
    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
    expect($a['rate']['frasi'][0])->toContain('La fine locazione di Ines del 1 luglio 2026 non ha scritto il conguaglio della regola del piano (Ines € 119,34 e Dora € 240,66): la differenza di quella fine resta fra le parti, con il prospetto degli oneri accessori. Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 65,75, Dora € 196,71 e Ines € 97,54')
        ->and(collect($a['rate']['giorni_del_conduttore'][0]['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Luca' => 6575, 'Dora' => -4395, 'Ines' => -2180]);
});

it('Coda 245 (dopo la coppia) — C5c: l\'inizio di Luca dal 1/7, lo stesso giorno della fine di Ines (il cambio in due passi): Luca € 90,74, a Dora € 60,66 e a Ines € 30,08', function () {
    $s = cdcDopoLaFine($this);

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-07-01'));
    expect($a['rate']['frasi'][0])->toContain('Con Luca dal 1 luglio 2026 al 50 % la regola del piano darebbe Luca € 90,74, Dora € 180,00 e Ines € 89,26: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 60,66 a Dora e € 30,08 a Ines.')
        ->and(collect($a['rate']['giorni_del_conduttore'][0]['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Luca' => 9074, 'Dora' => -6066, 'Ines' => -3008]);
});

it('Coda 245 (dopo la coppia) — C5a-Luca: la fine di Luca, che non ha quote del piano, è un\'informazione: Luca − € 14,32, Dora + € 9,57, Ines + € 4,75, da regolare con il prospetto', function () {
    $s = cdcDopoLaFine($this);
    fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Luca', '2026-12-01'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(cdcResto($a))->toBe([['Dora' => 957, 'Ines' => 475, 'Luca' => -1432]])
        ->and($a['rate']['resto_del_piano'][0]['wallet'])->toBeFalse()
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('€ 14,32')
        ->and(fclFrasi($a))->toContain('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio con la regola del piano. Prima di questo passaggio, con la regola del piano, sulla gestione Ordinaria 2026 Ines € 97,54, Dora € 196,71 e Luca € 65,75. Con la fine della locazione di Luca la locazione continua con Dora, e la regola del piano ridivide la voce: Ines € 102,29, Dora € 206,28 e Luca € 51,43. Luca non ha quote del piano, e nei saldi non ha righe: il programma non scrive niente. Con la regola del piano Luca pagherebbe € 14,32 in meno, Dora € 9,57 in più, e Ines € 4,75 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.');

    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Luca', '2026-12-01'))->fresh();
    expect(fclRigheDeiPassaggi($s))->toBe(2)
        ->and($fine->registro['resto_del_piano'][0]['wallet'] ?? null)->toBeFalse();
    // La strada è il prospetto, non il Wallet: la pagina dei saldi non segna la fine di Luca con una parte da regolare a mano.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'] ?? null)->toBeFalse();
});

it('Coda 245 (dopo la coppia) — dopo l\'inizio di Luca, la fine di Dora con Luca che resta: Luca non ha quote del piano e non riceve una riga, la fine di Dora non scrive niente e il pannello lo dice', function () {
    // Dopo C5a (Ines 9754, Dora 19671, Luca 6575) Dora esce il 1/10: Ines 9050, Dora 50 × 273 = 13650, Luca 6100 (somma 28800) danno 11312,5, 17062,5 e
    // 7625: a pari resto il centesimo a chi esce, Dora 17063, Ines 11312, Luca 7625. Differenze Dora − 2608, Ines + 1558, Luca + 1050. Chi riceve i giorni di
    // Dora è Luca, che non ha quote del piano: nessuna riga, nessuna scelta, e tutto si dice, con il prospetto (decisione 45).
    $s = cdcDopoLaFine($this);
    fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));

    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(fclCoppie($a))->toBe([])
        ->and(cdcResto($a))->toBe([['Dora' => -2608, 'Ines' => 1558, 'Luca' => 1050]])
        ->and($a['rate']['resto_del_piano'][0]['wallet'])->toBeFalse()
        ->and($a['rate']['frasi'][0])->toContain('Con la fine della locazione di Dora la locazione continua con Luca, e la regola del piano ridivide la voce: Ines € 113,12, Dora € 170,63 e Luca € 76,25. Nei saldi il programma non scrive niente: chi riceve i giorni di Dora non ha quote del piano. Con la regola del piano Dora pagherebbe € 26,08 in meno, Luca € 10,50 in più, e Ines € 15,58 in più. La differenza la regolano le parti con il prospetto degli oneri accessori.')
        ->and(implode(' ', $a['cancello']['motivi']))->not->toContain('€ 26,08');
});

// --- Il proprietario sull'anello: la parte del ripiego -----------------------------------------------------------------------------------

it('Coda 245 (dopo la coppia) — B6-vendita: dopo la fine di Ines con Dora già chiusa al 30/9, la vendita di Ugo a Elsa il 1/11 si ferma sulla parte del ripiego, € 60,16, non su € 32,69; dopo C1 la vendita non ha fermate', function () {
    $s = cdcDopoLaFine($this, 'scrivi', opz: ['fini' => ['Dora' => '2026-09-30']]);
    expect(fds50Coppia($s, $s['fine_prima']))->toBe(['Dora' => 787, 'Ines' => -9861, 'Ugo' => 9074]);

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    expect(b51aFermate($a))->toBe([['Ugo', 6016, ['Elsa']]])
        ->and(fclFrasi($a))->not->toContain('€ 32,69');

    // Dopo C1 (nessun giorno senza conduttore) la coppia della regola del piano non ha una parte del ripiego: nessuna fermata.
    $s = cdcDopoLaFine($this);
    expect(b51aFermate(fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'))))->toBe([]);
});

it('Coda 245 (dopo la coppia) — B6-vendita-Luca: poi Luca entra il 1/12 al 50 %: la fermata della vendita scende a € 29,59, con la spunta; l\'inizio dice la regola del piano, e chi rimborsa chi lo dice il prospetto', function () {
    $s = cdcDopoLaFine($this, 'scrivi', opz: ['fini' => ['Dora' => '2026-09-30']]);
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-12-01'));
    expect(collect($a['rate']['fermate_ridotte'])->map(fn ($f) => [$f['prima'], $f['residuo'], collect($f['parti'])->pluck('cifra', 'nome')->all()])->all())->toBe([[6016, 2959, ['Elsa' => 2959]]])
        ->and(implode(' ', $a['cancello']['motivi']))->toContain('€ 29,59')
        ->and($a['cancello']['richiesto'])->toBeTrue()
        ->and(collect($a['rate']['giorni_del_conduttore'])->where('regola', 'piano')->map(fn ($g) => collect($g['parti'])->pluck('cifra', 'nome')->all())->values()->all())
            ->toBe([['Luca' => 1916, 'Ines' => 455, 'Dora' => 686, 'Elsa' => -3057]])
        ->and($a['rate']['frasi'][0])->toContain('La fine locazione di Ines del 1 luglio 2026 ha diviso la parte dell\'inquilino con la regola del piano. Prima di questo inizio la regola del piano dava Ines € 107,35, Dora € 161,91, Ugo € 30,58 e Elsa € 60,16. Con Luca dal 1 dicembre 2026 al 50 % la regola del piano darebbe Luca € 19,16, Ines € 111,90, Dora € 168,77, Ugo € 30,58 e Elsa € 29,59: il programma non scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.');
});

it('Coda 245 (dopo la coppia) — B6-Luca: senza la vendita, Luca entra il 1/11 al 50 %: la regola del piano lo dice, non la parte dei giorni «da rimborsare a Ugo»; lo stesso se rientra Ines', function () {
    // B6 (Ines 10735, Dora 16191, Ugo 9074, la parte di Ugo sui 92 giorni dal 1/10). Luca dal 1/11 (61 giorni): coperti 273 + 61 = 334, Ines 9050, Dora 13650,
    // Luca 3050 (somma 25750) normalizzati sui 334, Ugo 31: 11577,84, 17462,71, 3901,92, 3057,53 → Luca 3902, Ines 11578, Dora 17463, Ugo 3057: Luca
    // + 3902, Ines + 843, Dora + 1272, Ugo − 6017. Con la regola dei giorni (la coppia della fine sui giorni dal 1/10) sarebbe «Luca rimborsa a Ugo € 60,16»:
    // non si dice, e i giorni del conduttore sono quelli della regola del piano. Se rientra Ines (il 1/11 al 50 %): Ines 9050 + 3050 = 12100 → Ines
    // 15479,76 → 15480, Dora 17463, Ugo 3057: Ines + 4745, Dora + 1272, Ugo − 6017, e nessun rinnovo «dopo Scrivi».
    $s = cdcDopoLaFine($this, 'scrivi', opz: ['fini' => ['Dora' => '2026-09-30']]);

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-11-01'));
    expect(collect($a['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
        ->toBe([['piano', ['Luca' => 3902, 'Ines' => 843, 'Dora' => 1272, 'Ugo' => -6017]]])
        ->and($a['rate']['frasi'][0])->toContain('Con Luca dal 1 novembre 2026 al 50 % la regola del piano darebbe Luca € 39,02, Ines € 115,78, Dora € 174,63 e Ugo € 30,57: il programma non scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and(fclFrasi($a))->not->toContain('€ 60,16');

    $r = fclAnteprima($this, $s, cdcModuloInizio($s, 'Ines', '2026-11-01'));
    expect($r['rate']['rinnovo_dopo_scrivi'])->toBe([])
        ->and(collect($r['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
            ->toBe([['piano', ['Ines' => 4745, 'Dora' => 1272, 'Ugo' => -6017]]]);
});

// --- Dopo una fermata del coinquilino delle beta, la regola di oggi (decisione 111.2) ----------------------------------------------------

/**
 * La fine di Ines con Dora che resta, registrata dalla rotta e poi con il registro riscritto come lo scrivevano le beta .50–.53 (come in
 * `Beta55CoinquilinoRegistriVecchiTest`): niente scelta, niente regola del piano, la fermata del coinquilino.
 */
function cdcFineVecchia($test, array $s): Subentro
{
    $fine = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    $registro = $fine->registro;
    unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti']);
    $registro['piani_presi'] = [];
    $registro['fermata_coinquilino'] = [[
        'immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'esercizio_id' => (int) $s['e']->id,
        'cifra' => 9074, 'persona_id' => (int) $s['persone']['Ines']->id, 'persona' => 'Ines', 'restano' => ['Dora'], 'al_posto' => ['Ugo'], 'wallet' => true, 'strada' => 'legata',
    ]];
    $fine->registro = $registro;
    $fine->save();

    return $fine->fresh();
}

it('Coda 245 (dopo la coppia) — decisione 111.2: dopo una fine registrata da una beta con la fermata del coinquilino, la fine di Dora resta quella di oggi, Dora → Ugo € 45,37, senza resto', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    cdcFineVecchia($this, $s);

    // 18000 × 92 / 365 = 4536,99 → 4537: la parte di Dora dei giorni dopo la sua uscita, dalle sue rate (la regola della fine senza coinquilini).
    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(rdpCoppia($a, 'Dora'))->toBe(['Dora' => -4537, 'Ugo' => 4537])
        ->and($a['rate']['resto_del_piano'])->toBe([])
        ->and($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and(fclFrasi($a))->not->toContain('regola del piano');

    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(cdcRighe($s, $fine))->toBe(['Dora' => -4537, 'Ugo' => 4537])
        ->and($fine->registro['conguaglio'])->not->toHaveKey('piano')
        ->and($fine->registro)->not->toHaveKey('resto_del_piano');
});

// --- Gli annullamenti e «Annulla il conguaglio» ------------------------------------------------------------------------------------------

it('Coda 245 (dopo la coppia) — C10: la fine di Ines non si annulla prima di quella di Dora; annullate Dora e poi Ines, i netti tornano 11934 / 24066 e poi 18000 / 18000', function () {
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi']);

    fclAnnulla($this, $s, $s['fine_prima'])->assertStatus(422);
    fclAnnulla($this, $s, $fine)->assertStatus(302);
    expect(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000]);
    fclAnnulla($this, $s, $s['fine_prima']->fresh())->assertStatus(302);
    expect(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);
});

it('Coda 245 (dopo la coppia) — l\'annullamento della fine di Dora con il resto avvisa che la parte lasciata fuori non c\'è più; le righe legate del resto si tolgono con lei', function () {
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(cdcStorico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo: annullandolo quella parte non c\'è più; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.');

    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);
    expect(cdcStorico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo: annullandolo quella parte non c\'è più, e i saldi del Wallet legati a questo passaggio si tolgono con lui.');

    fclAnnulla($this, $s, $fine)->assertStatus(302);
    expect(sl53Legate($s, $fine))->toBe([])
        ->and(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000]);
});

it('Coda 245 (dopo la coppia) — «Annulla il conguaglio» della fine di Ines dopo la fine di Dora avvisa, non rifiuta; la coppia di Dora e il resto restano, e lo storico, il Wallet, gli avvisi e il prospetto dicono la stessa cifra', function () {
    // Cambiato con la correzione del resto dopo «Annulla il conguaglio»: prima l'avviso diceva «se le parti avevano regolato fra loro quella parte, l'accordo va
    // rivisto», e lo storico della fine di Dora che il resto «non vale più», mentre il prospetto lo fa ancora restituire. Il resto è la differenza del passaggio
    // (decisione 111.3): annullato il conguaglio di Ines, le parti hanno regolato fra loro i 6066 (Ines 18000 − 6066 = 11934, Dora 24066), e con la coppia di Dora
    // Dora è al piano (24066 − 7875 = 16191), Ugo a 7875 contro 9074, Ines a 11934 contro 10735: Ines € 11,99 sopra il piano, Ugo € 11,99 sotto, come prima.
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);

    // L'avviso apre con il complemento di tempo («Dopo questo conguaglio, la fine …»), non fra il verbo e il suo oggetto: le cifre sono quelle di prima (coppia di Dora 7875, resto 1199).
    expect(cdcStorico($s, $s['fine_prima'])['conguaglio']['avvisi'])->toBe(['Dopo questo conguaglio, la fine della locazione di Dora del 1 ottobre 2026 ha scritto la sua coppia con la regola del piano (€ 78,75 a credito di Dora e € 78,75 a debito di Ugo), e la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo. Annullato questo conguaglio, la coppia di Dora e la parte lasciata fuori dal conguaglio restano come sono, e il prospetto degli oneri accessori dice le cifre del piano di oggi. I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo. L\'annullamento del conguaglio non li cambia: se non sono il resto della regola del piano, correggi dal Wallet i saldi che si modificano.']);

    sl53AnnullaConguaglio($this, $s, $s['fine_prima'])->assertStatus(302);
    expect($s['fine_prima']->fresh()->conguaglioAnnullato())->toBeTrue()
        ->and(fclNetti($s))->toBe(['Dora' => 10125, 'Ines' => 18000, 'Ugo' => 91875])
        ->and(cdcStorico($s, $fine)['fermate'])->toBe(['Il conguaglio della fine locazione di Ines del 1 luglio 2026 è stato annullato: le parti hanno regolato fra loro la differenza di quella fine, e ciò che la regola del piano ha lasciato fuori da questo passaggio non cambia. Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 107,35, Dora € 161,91 e Ugo € 90,74: il conguaglio ha scritto solo la coppia di Dora, € 78,75 a credito di Dora e € 78,75 a debito di Ugo, e la parte di Ines è rimasta € 11,99 sopra il piano, quella di Ugo € 11,99 sotto. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 11,99 a credito di Ines e € 11,99 a debito di Ugo. I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano.'])
        // «Annulla il conguaglio» della fine di Dora: le righe legate sono il resto, non una parte a zero da correggere.
        ->and(cdcStorico($s, $fine)['conguaglio']['avvisi'])->toBe(['Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano di questo passaggio.'])
        ->and(cdcStorico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo: annullandolo quella parte non c\'è più, e i saldi del Wallet legati a questo passaggio si tolgono con lui.');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->mapWithKeys(fn ($p) => [$p['id'] => $p['con_parte_a_mano']])->all())
        ->toBe([(int) $fine->id => true, (int) $s['fine_prima']->id => false]);
    $ines = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
    expect([(int) $ines['totale'], (int) $ines['nelle_sue_rate'], (int) $ines['da_rimborsare']])->toBe([10735, 18000, -1199])
        ->and(collect($ines['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all())->toBe([['da', 'Ugo', 1199]]);
});

it('Coda 245 (dopo la coppia) — «Annulla il conguaglio» della fine di Ines prima della fine di Dora: il resto della fine di Dora vale, con la strada del Wallet, e lo storico, il Wallet, gli avvisi e il prospetto lo dicono', function () {
    // Le stesse cifre del caso dopo: annullato il conguaglio di Ines, le parti hanno regolato i 6066 fra loro, e la fine di Dora scrive Dora − 7875 → Ugo e lascia
    // fuori Ines − 1199, Ugo + 1199 (il pannello lo dice, con la spunta). Seguita la strada del Wallet: Ines 18000 − 6066 − 1199 = 10735, Ugo 7875 + 1199 = 9074, Dora
    // 18000 + 6066 − 7875 = 16191: tutti al piano. Era: lo storico diceva, subito dopo, che il resto «non vale più», «annullato dopo questo passaggio» (era prima), il
    // Wallet non segnava la fine di Dora, e «Annulla il conguaglio» della fine di Dora diceva che il resto è € 0,00 e di correggere le righe legate.
    $s = cdcDopoLaFine($this);
    sl53AnnullaConguaglio($this, $s, $s['fine_prima'])->assertStatus(302);
    $a = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
    expect(cdcResto($a))->toBe([['Ines' => -1199, 'Ugo' => 1199]])
        ->and($a['rate']['resto_del_piano'][0]['wallet'])->toBeTrue();
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();

    $frase = 'Il conguaglio della fine locazione di Ines del 1 luglio 2026 è stato annullato: le parti hanno regolato fra loro la differenza di quella fine, e ciò che la regola del piano ha lasciato fuori da questo passaggio non cambia. Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 107,35, Dora € 161,91 e Ugo € 90,74: il conguaglio ha scritto solo la coppia di Dora, € 78,75 a credito di Dora e € 78,75 a debito di Ugo, e la parte di Ines è rimasta € 11,99 sopra il piano, quella di Ugo € 11,99 sotto. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 11,99 a credito di Ines e € 11,99 a debito di Ugo.';
    expect(cdcStorico($s, $fine)['fermate'])->toBe([$frase])
        ->and(cdcStorico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo: annullandolo quella parte non c\'è più; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeTrue();

    expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);
    expect(fds50Netti($s))->toBe(['Dora' => 10125, 'Ines' => 16801, 'Ugo' => 93074])
        ->and(cdcStorico($s, $fine)['fermate'])->toBe([$frase . ' I saldi del Wallet legati alla fine della locazione di Dora del 1 ottobre 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano.'])
        ->and(cdcStorico($s, $fine)['conguaglio']['avvisi'])->toBe(['Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. I saldi del Wallet legati a questo passaggio sulla gestione Ordinaria 2026 sommano oggi € 11,99 a credito di Ines e € 11,99 a debito di Ugo: sono già il resto della regola del piano di questo passaggio.']);
    $ines = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
    expect([(int) $ines['totale'], (int) $ines['nelle_sue_rate'], (int) $ines['da_rimborsare']])->toBe([10735, 18000, -1199])
        ->and(collect($ines['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all())->toBe([['da', 'Ugo', 1199]]);
});

it('Coda 245 (dopo la coppia) — «Annulla il conguaglio» della fine di Dora stessa: le parti hanno regolato la sua coppia, € 78,75, e il resto fra Ines e Ugo resta da regolare; lo storico, il Wallet, l\'annullamento e il prospetto lo dicono', function () {
    // «Annulla il conguaglio» toglie le righe della coppia di Dora e le scrive regolate fra le parti (`regolato_fuori`: Dora − 7875, Ugo + 7875); il resto, Ines − 1199
    // e Ugo + 1199, non è nella coppia: Ines resta a 11934 contro 10735, e nessuno ha regolato quei € 11,99. Era: lo storico diceva che il resto «non vale più», il
    // Wallet non segnava il passaggio, e l'annullamento della fine di Dora non diceva più il resto.
    $s = cdcDopoLaFine($this);
    $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
    sl53AnnullaConguaglio($this, $s, $fine)->assertStatus(302);
    $fine = $fine->fresh();

    expect(collect($fine->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Dora' => -7875, 'Ugo' => 7875])
        ->and(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000])
        ->and(cdcStorico($s, $fine)['fermate'])->toBe(['Con questa fine la regola del piano dava, sulla gestione Ordinaria 2026 (Interno 1), Ines € 107,35, Dora € 161,91 e Ugo € 90,74: il conguaglio ha scritto solo la coppia di Dora, € 78,75 a credito di Dora e € 78,75 a debito di Ugo, e la parte di Ines è rimasta € 11,99 sopra il piano, quella di Ugo € 11,99 sotto. Il conguaglio di questo passaggio è stato annullato: la coppia di Dora l\'hanno regolata fra loro le parti, e ciò che la regola del piano ha lasciato fuori resta da regolare. La strada indicata erano i saldi del Wallet legati a questo passaggio: € 11,99 a credito di Ines e € 11,99 a debito di Ugo.'])
        ->and(cdcStorico($s, $fine)['annullabile']['avvisi'])->toContain('Con questo passaggio la regola del piano ha lasciato fuori dal conguaglio € 11,99 a credito di Ines e € 11,99 a debito di Ugo: annullandolo quella parte non c\'è più; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $fine->id)['con_parte_a_mano'])->toBeTrue();
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $ines = collect($p['conduttori'])->firstWhere('nome', 'Ines');
    $dora = collect($p['conduttori'])->firstWhere('nome', 'Dora');
    expect([(int) $ines['totale'], (int) $ines['nelle_sue_rate'], (int) $ines['da_rimborsare']])->toBe([10735, 18000, -1199])
        ->and(collect($ines['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all())->toBe([['da', 'Ugo', 1199]])
        ->and([(int) $dora['totale'], (int) $dora['nelle_sue_rate'], (int) $dora['da_rimborsare']])->toBe([16191, 18000, 0])
        ->and(collect($dora['regola_del_piano']['regolati'])->pluck('importo')->all())->toBe([-7875]);
});

it('Coda 245 (dopo la coppia) — C4 e poi la vendita di Ugo a Elsa il 1/11: la fermata è sulla parte del piano di Ugo dei giorni dal 1/10, € 90,74, non sulla sola parte scritta, € 78,75; con o senza le righe legate del resto', function () {
    // Dopo C4 Ugo ha la coppia di Dora, 7875 sui giorni dal 1/10, e con la regola del piano la sua parte di quei giorni è 9074: i 1199 rimasti fuori dal conguaglio li
    // restituisce a Ines (il resto, con il Wallet o con il prospetto, che li dice «da restituire»). La vendita a Elsa il 1/11 le dà 61 dei 92 giorni: 9074 × 61/92 = 6016,46
    // e 3057,54 → Elsa 6016 (il centesimo a Ugo, ,54). Prima la fermata era 7875 × 61/92 = 5221,47 → 5221: seguita con il prospetto, Ugo restituiva a Ines € 11,99 e da Elsa
    // riaveva € 52,21, restando a pagare € 7,95 dei giorni di Elsa. Lo stesso con le righe legate del resto (Ines − 1199, Ugo + 1199), che prima la fermata non contava.
    foreach ([false, true] as $legate) {
        $s = cdcDopoLaFine($this);
        $fine = fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi'])->fresh();
        if ($legate) {
            expect(sl53Errori(sl53Posta($this, $s, 'Ines', -1199, (int) $fine->id)))->toBe([])
                ->and(sl53Errori(sl53Posta($this, $s, 'Ugo', 1199, (int) $fine->id)))->toBe([]);
        }
        $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
        $f = $a['rate']['fermate_dopo_scrivi'];
        expect(array_map(fn ($x) => [$x['persona'], (int) $x['cifra'], collect($x['parti'])->pluck('cifra', 'nome')->all()], $f))->toBe([['Ugo', 6016, ['Elsa' => 6016]]], json_encode($legate))
            ->and(fds50Con($a, 'restano a carico di Ugo'))->toContain('La fine locazione di Dora del 1 ottobre 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 78,75 sulla gestione Ordinaria 2026 (Interno 1); con la regola del piano la sua parte è € 90,74, e € 11,99 sono rimasti fuori dal conguaglio, da regolare con Ines. Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Elsa, ma il programma non la sposta: € 60,16 restano a carico di Ugo. Se vanno a Elsa, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa. Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    }
});

// --- Un piano della stessa gestione generato dopo la fine ---------------------------------------------------------------------------------

it('Coda 245 (dopo la coppia) — un piano integrativo generato dopo la fine di Ines, poi la fine di Dora: l\'inizio di Luca il 1/11 dice la regola del piano sui due piani, e il prospetto la legge', function () {
    // Lo scenario di `rdpConIntegrativa`: dopo la fine di Dora il preventivo dà Ines 10735, Dora 16191, Ugo 9074, il piano integrativo Ines 3578, Dora 5397, Ugo 3025.
    // Luca dal 1/11 al 50 % (61 giorni): coperti 273 + 61 = 334, Ines 9050, Dora 13650, Luca 3050 (somma 25750) normalizzati sui 334, Ugo 31 (ottobre). Il preventivo
    // (36000): 11577,84, 17462,71, 3901,92, 3057,53 → Ines 11578, Dora 17463, Luca 3902, Ugo 3057 (Luca + 3902, Ines + 843, Dora + 1272, Ugo − 6017); il piano
    // integrativo (12000): 3859,28, 5820,90, 1300,64, 1019,18 → Ines 3859, Dora 5821, Luca 1301, Ugo 1019 (Luca + 1301, Ines + 281, Dora + 424, Ugo − 2006). In
    // tutto Luca + 5203, Ines + 1124, Dora + 1696, Ugo − 8023. Era: la regola del piano sul solo preventivo (Luca € 39,02), e per il piano integrativo la frase dei
    // piani fermi («resta nelle rate di Dora»).
    $s = rdpConIntegrativa($this);
    fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01') + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-11-01'));
    expect(collect($a['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', (int) $g['cifra'], collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
        ->toBe([['piano', 5203, ['Luca' => 5203, 'Ines' => 1124, 'Dora' => 1696, 'Ugo' => -8023]]])
        ->and($a['rate']['frasi'][0])->toContain('I piani «Preventivo 2026» e «Integrativa pulizie 2026» non si ricalcolano più: anche le rate ancora da emettere restano a chi le ha.')
        ->and($a['rate']['frasi'][0])->toContain('Prima di questo inizio la regola del piano dava Ines € 143,13, Dora € 215,88 e Ugo € 120,99. Con Luca dal 1 novembre 2026 al 50 % la regola del piano darebbe Luca € 52,03, Ines € 154,37, Dora € 232,84 e Ugo € 40,76: il programma non scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.')
        ->and(fclFrasi($a))->not->toContain('resta nelle rate di Dora');

    fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-11-01'));
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $sezione = fn (string $nome) => collect($p['conduttori'])->firstWhere('nome', $nome);
    $rimborsi = fn (?array $x) => collect($x['regola_del_piano']['rimborsi'] ?? [])->mapWithKeys(fn ($r) => [$r['verso'] . ' ' . $r['nome'] => (int) $r['importo']])->all();
    // Luca: 5203 da rimborsare a Ugo. Ines: 11578 + 3859 = 15437, nelle rate 21978, conguaglio − 6066: − 475 (1599 di resto della fine di Dora meno 1124 dell'inizio),
    // da Ugo. Dora: 17463 + 5821 = 23284, nelle rate 26022, conguagli − 4434: 1696 a Ugo. Senza conduttore, Ugo: 3057 + 1019 = 4076, con il conguaglio 10500: − 6424.
    expect(fclProspetto($s)['conduttori'])->toMatchArray(['luca' => [5203, 0, 5203, 122], 'ines' => [15437, 21978, -475, 362], 'dora' => [23284, 26022, 1696, 546]])
        ->and($rimborsi($sezione('Luca')))->toBe(['a Ugo' => 5203])
        ->and($rimborsi($sezione('Ines')))->toBe(['da Ugo' => 475])
        ->and((int) $p['senza_conduttore']['totale'])->toBe(4076)
        ->and((int) $p['senza_conduttore']['regola_del_piano']['da_rimborsare'])->toBe(-6424)
        ->and($p['totale_inquilino'])->toBe(48000);
});

/** Il prospetto dell'Interno 1, per conduttore: [totale, nelle sue rate, con il conguaglio, da rimborsare] e chi rimborsa chi. */
function cdcProspetto(array $s): array
{
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = [[(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) ($c['con_il_conguaglio'] ?? 0), (int) $c['da_rimborsare']],
            collect($c['regola_del_piano']['rimborsi'] ?? [])->mapWithKeys(fn ($r) => [$r['verso'] . ' ' . $r['nome'] => (int) $r['importo']])->sortKeys()->all()];
    }
    ksort($out);

    return $out;
}

it('Coda 245 (dopo la coppia) — un piano integrativo generato dopo la fine di Ines, poi l\'inizio di Luca il 1/9 e la fine di Dora il 1/10 senza coppia: il piano integrativo è sull\'anello anche se nessuna voce lo nomina, e il prospetto lo legge con la regola del piano, come se fosse stato generato prima', function () {
    // Il piano integrativo generato dopo la fine di Ines: 12000 × 9050/27300 = 3978,02 → Ines 3978, Dora 8022. L'inizio di Luca dal 1/9 al 50 % (122 giorni; pesi Luca 6100, Ines 9050,
    // Dora 18250 su 33400): il preventivo 6574,85 / 9754,49 / 19670,66 → 6575 / 9754 / 19671, il piano integrativo 2191,62 / 3251,50 / 6556,89 → 2192 / 3251 / 6557 (i centesimi ai
    // resti più alti, Dora ,89 e Luca ,62). I giorni del conduttore sui due piani: Luca 6575 + 2192 = 8767; Dora 19671 − 24066 + 6557 − 8022 = − 5860; Ines 9754 − 11934 + 3251 − 3978 =
    // − 2907. La fine di Dora il 1/10 (pesi Dora 13650, Luca 6100, Ines 9050 su 28800, a pari resto il centesimo a chi esce): il preventivo 17062,5 / 7625 / 11312,5 → 17063 / 7625 / 11312,
    // il piano integrativo 5687,5 / 2541,67 / 3770,83 → 5687 / 2542 / 3771. Nessuna coppia (Luca non ha quote): il resto sui due piani, Dora − 3478, Luca + 1400, Ines + 2078. Il prospetto:
    // Ines 11312 + 3771 = 15083, nelle rate 18000 + 3978 = 21978, con il conguaglio − 6066 → − 829; Dora 17063 + 5687 = 22750, nelle rate 18000 + 8022 = 26022, con il conguaglio
    // + 6066 → − 9338; Luca 7625 + 2542 = 10167. Con il piano integrativo generato prima le rate sono 24000 e 24000, e la coppia di Ines − 8088 (6066 sul preventivo e 6000 − 3978 = 2022 sul piano integrativo).
    // Chi rimborsa chi: l'inizio, Luca a Dora 5860 e a Ines 2907; il resto della fine, Ines a Dora 2078 e Luca a Dora 1400: Luca a Dora 7260, a Ines 2907; Ines a Dora 2078. Le stesse cifre
    // del piano integrativo generato prima della fine di Ines (il controllo, sotto). Era: l'inizio sul solo preventivo (Luca € 65,75), il piano integrativo letto dalle rate (Ines 15290,
    // Dora 25085, Luca 7625) e tre righe senza nessuno dall'altra parte (€ 5,20, € 3,50, € 8,70).
    foreach ([true, false] as $dopo) {
        $s = rdpConIntegrativa($this, dopo: $dopo);
        $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
        expect(collect($a['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
            ->toBe([['piano', ['Luca' => 8767, 'Dora' => -5860, 'Ines' => -2907]]], $dopo ? 'dopo' : 'prima')
            ->and($a['rate']['frasi'][0])->toContain('Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 87,67, Dora € 262,28 e Ines € 130,05: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 58,60 a Dora e € 29,07 a Ines.');
        fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
        $b = fclAnteprima($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));
        expect($b['rate']['scelta_del_conguaglio'] ?? false)->toBeFalse()
            ->and(cdcResto($b))->toBe([['Dora' => -3478, 'Ines' => 2078, 'Luca' => 1400]]);
        fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01'));

        expect(cdcProspetto($s))->toBe([
            'Dora' => [[22750, $dopo ? 26022 : 24000, $dopo ? 6066 : 8088, -9338], ['da Ines' => 2078, 'da Luca' => 7260]],
            'Ines' => [[15083, $dopo ? 21978 : 24000, $dopo ? -6066 : -8088, -829], ['a Dora' => 2078, 'da Luca' => 2907]],
            'Luca' => [[10167, 0, 0, 10167], ['a Dora' => 7260, 'a Ines' => 2907]],
        ], $dopo ? 'dopo' : 'prima');
        $stampa = fclStampa($s);
        expect($stampa)->not->toContain('da ricevere da chi ha pagato i suoi giorni')->and($stampa)->not->toContain('da rimborsare a chi le ha pagate')
            ->and($stampa)->not->toContain('lo decidono le parti')->and($stampa)->toContain('Totale a carico di Luca € 101,67');
    }
});

it('Coda 245 (dopo la coppia) — il piano integrativo generato dopo la fine di Ines, l\'inizio di Luca il 1/9 e poi il cambio Dora → Elsa il 1/10 con «Scrivi»: chi rimborsa chi conta i due piani, Luca a Dora € 58,60 e a Ines € 29,07, e nessuna riga resta senza l\'altra parte', function () {
    // Il cambio Dora → Elsa: Elsa prende la quota di Dora dal 1/10 (pesi Ines 9050, Dora 50 × 273 = 13650, Luca 6100, Elsa 50 × 92 = 4600 su 33400): il preventivo 9754 / 14713 / 6575 /
    // 4958, il piano integrativo 3251 / 4904 / 2192 / 1653. La coppia del cambio è la differenza di Dora verso Elsa sui due piani, e le parti di Ines e di Luca non cambiano: chi rimborsa
    // chi per l'inizio resta Luca a Dora 5860 e a Ines 2907. Era: l'inizio sul solo preventivo, e righe senza controparte («da rimborsare a chi le ha pagate € 21,92»).
    $s = rdpConIntegrativa($this);
    fclRegistra($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
    fclRegistra($this, $s, cdcModuloFine($s, 'Dora', '2026-10-01', 'Elsa') + ['conguaglio' => 'scrivi']);

    $p = cdcProspetto($s);
    expect($p['Luca'])->toBe([[8767, 0, 0, 8767], ['a Dora' => 5860, 'a Ines' => 2907]])
        ->and($p['Ines'][0][3])->toBe(-2907)
        ->and(fclStampa($s))->not->toContain('a chi le ha pagate')->and(fclStampa($s))->not->toContain('da chi ha pagato i suoi giorni');
});

it('Coda 245 (dopo la coppia) — il piano dell\'esercizio dopo, sulla stessa gestione ordinaria (la pagina lega la gestione a ogni esercizio nuovo): non è sull\'anello della fine di Ines del 2026, e l\'inizio di Luca nel 2027 non lo divide con la regola del piano', function () {
    // Scritto dopo il codice (il rosso è la mutazione che toglie l'esercizio dalla scelta dei piani generati dopo la fine). Il «Preventivo 2027» è generato dopo la fine di Ines (le sue
    // quote vengono dopo), sulla stessa gestione: l'anello della fine di Ines è dell'esercizio 2026, e nel 2027 la regola del piano non c'entra (nessuna fine del 2027 con un coinquilino).
    $s = cdcDopoLaFine($this);
    $e2027 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['data_fine' => '2027-12-31']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $e2027]), [
        'nome' => 'Preventivo 2027', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [],
    ])->assertSessionHasNoErrors();
    $piano2027 = \App\Models\Gestionale\PianoRate::where('nome', 'Preventivo 2027')->firstOrFail();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $e2027, $piano2027]), ['approvato' => true, 'data_delibera_assemblea' => '2026-12-15'])
        ->assertSessionHasNoErrors();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano2027->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del preventivo', esercizio: $e2027);
    // Le prime rate del 2027 a giornale: il piano non si ricalcola più, come il preventivo del 2026 quando la fine di Ines l'ha preso.
    fds50Emetti($this, $s, '2027-02-28', $piano2027->fresh());
    expect(DB::table('rate')->where('piano_rate_id', $piano2027->id)->where('stato', 'emessa')->count())->toBeGreaterThan(0)
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $piano2027->id)->where('immobile_id', $s['unita']->id)->where('ruolo_richiesto', 'inquilino')->exists())->toBeTrue()
        ->and(\App\Services\Subentro\RegolaDelPiano::sullAnello((int) $s['unita']->id, (int) $s['g']->id, [(int) $piano2027->id]))->toBeNull()
        ->and(\App\Services\Subentro\RegolaDelPiano::sullAnello((int) $s['unita']->id, (int) $s['g']->id, [(int) $s['piano']->id])['piani'])->toBe([(int) $s['piano']->id]);

    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2027-03-01'));
    expect(collect($a['rate']['giorni_del_conduttore'] ?? [])->filter(fn ($g) => ($g['regola'] ?? null) === 'piano')->all())->toBe([]);
});

it('Coda 245 (dopo la coppia) — il piano integrativo generato dopo la fine di Ines che si ricalcola ancora (le rate in bozza): non è sull\'anello, e l\'inizio di Luca il 1/9 divide con la regola del piano il solo preventivo', function () {
    // Scritto dopo il codice (il rosso è la mutazione che mette sull'anello anche i piani che si ricalcolano). C5a sul preventivo: pesi Luca 6100, Ines 9050, Dora 18250 su 33400 → 6575 / 9754
    // / 19671: Luca + 6575, Dora − 4395, Ines − 2180. Il piano integrativo, con le rate ancora in bozza, si ricalcola con Luca: non ha giorni del conduttore della regola del piano.
    $s = rdpConIntegrativa($this, emetti: false);
    $a = fclAnteprima($this, $s, cdcModuloInizio($s, 'Luca', '2026-09-01'));
    expect(collect($a['rate']['giorni_del_conduttore'])->map(fn ($g) => [$g['regola'] ?? 'giorni', collect($g['parti'] ?? [])->pluck('cifra', 'nome')->all()])->all())
        ->toBe([['piano', ['Luca' => 6575, 'Dora' => -4395, 'Ines' => -2180]]])
        ->and($a['rate']['frasi'][0])->toContain('Con Luca dal 1 settembre 2026 al 50 % la regola del piano darebbe Luca € 65,75, Dora € 196,71 e Ines € 97,54');
});

it('Coda 245 (dopo la coppia) — il piano integrativo generato prima della fine di Ines e ancora in bozza, che la fine non ha preso, ed emesso dopo senza ricalcolo: resta con le sue quote e non è sull\'anello', function () {
    // Scritto dopo il codice (il rosso è la mutazione che mette sull'anello anche i piani con le quote di prima della fine). Il piano integrativo generato prima della fine, Ines e Dora 6000 e
    // 6000; la fine di Ines prende solo il preventivo (l'integrativo allora si ricalcolava), e l'integrativo è emesso dopo senza ricalcolo (decisione 40): le sue quote sono quelle di prima. Sull'
    // anello la regola del piano gli darebbe Ines 12000 × 9050/27300 = 3978 senza nessuna coppia che le restituisca i 2022: una riga «da ricevere» che nessuno deve. Il prospetto lo legge per
    // giorni: Ines 11934 + 6000 = 17934 contro 18000 + 6000 nelle rate, con il conguaglio − 6066 → 0; Dora 24066 + 6000 = 30066, + 6066 → 0.
    $s = rdpConIntegrativa($this, dopo: false, emetti: false);
    expect(collect($s['fine_ines']->registro['conguaglio']['piano'][0]['voci'])->pluck('piano_rate_id')->unique()->values()->all())->toBe([(int) $s['piano']->id]);
    fds50Emetti($this, $s, '2026-09-30', $s['integrativa']);

    expect(cdcProspetto($s))->toMatchArray(['Dora' => [[30066, 24000, 6066, 0], []], 'Ines' => [[17934, 24000, -6066, 0], []]])
        ->and(collect(collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines')['voci'])
            ->where('conto', 'Pulizie scale')->where('modo', 'piano')->all())->toBe([]);
});
