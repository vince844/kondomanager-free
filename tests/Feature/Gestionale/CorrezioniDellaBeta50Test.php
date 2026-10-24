<?php

/**
 * 1.11.0-beta.50 — i casi della lista per la ripresa (verbale `piano_esecutivo_beta50.md`, «Mutazioni e laboratorio») che non avevano
 * ancora un test: il cambio in due passi nello stesso giorno non è un coinquilino (punto 3, rilievo 2), la coppia del box senza nessuno
 * al posto dell'inquilino (punto 4, rilievo 3), «l'unità resta sfitta» con un coinquilino (punto 5, rilievo 4), la fermata seguita da
 * un'altra vendita (punto 7, decisione 81.2).
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000)
 * e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate mensili; Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce
 * il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181, 184] / 365 = 17852,05 / 18147,95 → la coppia 18148 (€ 181,48).
 *
 * Nati come test rossi della ripresa: ognuno descrive un difetto che il codice della .50 aveva, corretto prima del commit, e ha accanto
 * un controllo che dice che il rosso non veniva dal test (la stessa scena prima del passo che la guastava, o il prospetto, che seguiva
 * già la regola). La pagina del modulo è in `PassaggioNewCoinquilino.test.ts`.
 */

use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';

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
 * Lo scenario con il piano generato e, se si dice, le sei rate gennaio–giugno a giornale (il piano non si ricalcola più). È
 * `mcb50ConSeiRate` di `MutazioniCorrezioniBeta50Test`, che sta in un file di test e non si può includere.
 */
function cdb50Scenario($test, array $titolari, array $opzioni = [], bool $seiRate = true): array
{
    $s = fclScenario($titolari, $opzioni);
    fclGenera($s);
    if ($seiRate) {
        fclEmetti($test, $s, '2026-06-30');
        expect(fclEmesse($s))->toBe(6);
    }

    return $s;
}

/** Il modulo dell'inizio di una locazione di una persona dello scenario (è `mcb50Inizio`). */
function cdb50Inizio(array $s, string $chi, string $il = '2026-07-01', float|int $quota = 100): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
}

/** Le voci di un conduttore nel prospetto dell'Interno 1, come lo calcola la stampa. */
function cdb50VociDelProspetto(array $s, string $nome): \Illuminate\Support\Collection
{
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);

    return collect(collect($p['conduttori'])->firstWhere('nome', $nome)['voci'] ?? []);
}

// ---------------------------------------------------------------------------------------------------------------------
// Punto 3, rilievo 2: chi entra il giorno dell'uscita non è un coinquilino che resta. Una regola sola, quella del prospetto
// (`ProspettoOneriAccessori::coinquilinoCheResta`): il registro della fine, dove c'è; se no un altro inquilino in corso il giorno
// prima dell'uscita e anche quel giorno.
// ---------------------------------------------------------------------------------------------------------------------

it('rilievo 2 — il cambio in due passi nello stesso giorno (fine di Ines il 1/7, inizio di Luca il 1/7) non è un coinquilino che resta: lo storico della fine di Ines dice ancora «tornano a Ugo», non «la locazione continua con Luca»', function (?string $scelta, bool $seiRate) {
    // Lo storico ricalcola la frase a ogni lettura (`FrasiObbligati::daSubentro` → `alPostoPerUnita` →
    // `ChiPagaAlPostoDellInquilino::coinquiliniCheRestano`, che guarda solo il giorno del passaggio): registrato l'inizio di Luca, la
    // riga di Luca è in corso il 1/7 e la fine di Ines cambia frase. Luca non c'era il 30/6: è il cambio d'inquilino in due passi, il
    // flusso normale di `InizioLocazionePianoFermoTest`, e il prospetto lo tratta così (controllo qui sotto).
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]], [], $seiRate);
    $fine = fclRegistra($this, $s, fclModulo($s) + ($scelta !== null ? ['conguaglio' => $scelta] : []));
    $prima = fclStorico($s, $fine);
    fclPersona($s, 'Luca');
    fclRegistra($this, $s, cdb50Inizio($s, 'Luca'));

    // Controllo: prima dell'inizio di Luca lo storico dice chi paga al posto dell'inquilino.
    expect($prima)->toContain('Dal 1 luglio 2026 le voci a carico dell\'inquilino tornano a Ugo');
    if ($seiRate) {
        // Controllo: il prospetto non vede un coinquilino. Luca rimborsa i giorni dopo l'uscita di Ines, 1/7–31/12, 184
        // giorni: 36000 × 184/365 = 18147,95 → 18148 (a Ugo con «Scrivi», a Ines con «Non scriverlo»), e nelle sue rate non ha niente.
        expect(fclProspetto($s)['conduttori']['luca'])->toBe([18148, 0, 18148, 184]);
    }

    $dopo = fclStorico($s, $fine);
    expect($dopo)->not->toContain('la locazione continua con Luca')
        ->and($dopo)->toBe($prima);
})->with([
    '«Scrivi il conguaglio»' => ['scrivi', true],
    '«Non scriverlo»' => ['non_scrivere', true],
    'il piano generato e non emesso, senza la scelta' => [null, false],
]);

it('rilievo 2, la guardia — un coinquilino vero resta coinquilino: Dora, inquilina con Ines dal 2019, c\'è il giorno prima dell\'uscita e il giorno stesso, e lo storico della fine di Ines dice «la locazione continua con Dora»', function (bool $seiRate) {
    // Con le sei rate il pannello propone la coppia della regola del piano e la scelta (Coda 245, 1.11.0-beta.55: fino alla .54 scriveva nel
    // registro la fermata del coinquilino), e il registro conserva i coinquilini letti; con il piano non emesso non c'è niente da conguagliare e
    // il registro non li porta: la regola guarda le righe. In tutti e due i casi Dora resta coinquilina.
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]], $seiRate);
    $fine = fclRegistra($this, $s, fclModulo($s) + ($seiRate ? ['conguaglio' => 'scrivi'] : []));

    expect(fclStorico($s, $fine))->toContain('Dal 1 luglio 2026 la locazione continua con Dora:');
    if ($seiRate) {
        // Il registro: la regola del piano (Ines − 6066, Dora + 6066), nessuna fermata del coinquilino. Il prospetto la legge con la regola del piano (Coda 245,
        // 1.11.0-beta.55): Ines 36000 × 9050/27300 = 11934,07 → 11934 per i suoi 181 giorni, con € 180,00 nelle sue rate; prima diceva la strada C della fermata del
        // coinquilino (la parte di Ines dopo l'uscita, 9074, nelle sue rate, «lo decidono le parti»).
        expect(collect($fine->registro['conguaglio']['piano'] ?? [])->pluck('immobile_id')->all())->toBe([(int) $s['unita']->id])
            ->and($fine->registro)->not->toHaveKey('fermata_coinquilino')
            ->and(collect($fine->registro['coinquilini_letti'] ?? [])->pluck('restano')->flatten()->all())->toBe(['Dora'])
            ->and(cdb50VociDelProspetto($s, 'Ines')->map(fn ($v) => [$v['modo'], $v['strada'] ?? null, (int) $v['importo'], (int) ($v['nelle_rate'] ?? 0), $v['giorni']])->values()->all())
                ->toBe([['piano', 'P', 11934, 18000, 181]]);
    }
})->with([
    'sei rate a giornale, il registro ha la fermata del coinquilino' => [true],
    'il piano generato e non emesso, si guardano le righe' => [false],
]);

it('rilievo 2, con il registro — Dora esce il 1/7 mentre Ines resta, poi Ines esce lo stesso giorno: lo storico della fine di Dora dice ancora «la locazione continua con Ines», come il registro e il prospetto (strada C), non «tornano a Ugo»', function () {
    // Lo scenario del test «Punto 3» di `MutazioniCorrezioniBeta50Test`, letto dallo storico invece che dal pannello dell'inizio. Il
    // pannello della fine di Dora ha scritto nel registro la fermata del coinquilino (Ines restava), e il prospetto la legge: Dora ha la
    // strada C. Lo storico ricalcolava sulle righe correnti: chiusa la riga di Ines al 30/6, il 1/7 Ines «non c'era» e la frase della
    // fine di Dora diventava «tornano a Ugo», il contrario di ciò che aveva detto il pannello quando la fine è stata registrata; ora
    // legge il registro.
    // Coda 245 (1.11.0-beta.55): la fine di Dora, con Ines che resta, ha la scelta (la regola del piano): qui «Scrivi», Dora − 6066 e Ines + 6066.
    // Fino alla .54 la fine di Dora non aveva scelta e scriveva nel registro la fermata del coinquilino.
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $dora = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [], 'Dora') + ['conguaglio' => 'scrivi']);
    $prima = fclStorico($s, $dora);
    // Coda 245 (1.11.0-beta.55): la fine di Ines lo stesso giorno sta sull'anello della fine di Dora (decisione 95.6 b): la differenza del piano del
    // passaggio, Dora 11934 e Ines 24066 prima, Dora 8926, Ines 8926 e Ugo 18148 dopo, cioè Ines − 15140 → Ugo, e restano fuori Dora − 3008 e Ugo + 3008
    // (C6.5a a parti scambiate). Con «Non scriverlo» nessuna riga e nessun resto nel registro: il prospetto dice chi rimborsa chi.
    $ines = fclAnteprima($this, $s, fclModulo($s));
    expect(fclCoppie($ines))->toBe([[(int) $s['unita']->id, 'Ugo', 15140]])
        ->and(collect($ines['rate']['resto_del_piano'][0]['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Dora' => -3008, 'Ugo' => 3008]);
    $fineInes = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    expect(fds50Coppia($s, $fineInes))->toBe([])
        ->and($fineInes->registro)->not->toHaveKey('resto_del_piano');

    // Controlli: prima dell'uscita di Ines lo storico dice che la locazione continua con lei; il registro della fine di Dora
    // porta i coinquilini letti (Ines) e la regola del piano. Il prospetto, registrata anche l'uscita di Ines, la legge con la regola del piano (Coda 245,
    // 1.11.0-beta.55): Dora e Ines 36000 × 90,5/365 = 8926,03 → 8926 ciascuna per i loro 181 giorni, Ugo i 184 giorni dopo, 18148; con la coppia di Dora (− 6066)
    // e la fine di Ines non scritta Ugo restituisce 3008 a Dora e 15140 a Ines. Prima dava a Dora la strada C della fermata del coinquilino (9074 nelle sue rate).
    expect($prima)->toContain('Dal 1 luglio 2026 la locazione continua con Ines:')
        ->and(collect($dora->registro['coinquilini_letti'] ?? [])->pluck('restano')->flatten()->all())->toBe(['Ines'])
        ->and(collect($dora->registro['conguaglio']['piano'][0]['parti'] ?? [])->sort()->values()->all())->toBe([-6066, 6066])
        ->and(cdb50VociDelProspetto($s, 'Dora')->map(fn ($v) => [$v['modo'], (int) $v['importo'], (int) ($v['nelle_rate'] ?? 0), $v['giorni']])->values()->all())->toBe([['piano', 8926, 18000, 181]]);
    $prospetto = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    expect(collect(collect($prospetto['conduttori'])->firstWhere('nome', 'Dora')['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all())->toBe([['da', 'Ugo', 3008]])
        ->and(collect($prospetto['senza_conduttore']['regola_del_piano']['rimborsi'])->map(fn ($r) => [$r['chi'], $r['verso'], $r['nome'], (int) $r['importo']])->sortBy(fn ($r) => $r[2])->values()->all())
            ->toBe([['Ugo', 'a', 'Dora', 3008], ['Ugo', 'a', 'Ines', 15140]])
        ->and((int) $prospetto['senza_conduttore']['totale'])->toBe(18148);

    $dopo = fclStorico($s, $dora);
    expect($dopo)->not->toContain('tornano a Ugo')
        ->and($dopo)->toBe($prima);
});

it('rilievo 2, nell\'inizio — fine di Ines di prima della .50 (senza la scelta) il 1/7, Luca entra al 50 % lo stesso giorno, Marco al 50 % il 1/9: il pannello dell\'inizio di Marco dice che Marco rimborsa Ines, come il prospetto, non che «lo decidono le parti»', function () {
    // `AnteprimaPassaggio::comeSonoUsciti`, per una fine senza la scelta nel registro, riconosce il coinquilino con
    // `coinquiliniCheRestano`: Luca, entrato il 1/7, gli sembra un coinquilino che restava, e la frase dice «lo decidono le parti». Il
    // prospetto (controllo) non lo vede: Luca non c'era il 30/6, la fine di Ines è la strada A′ («nessun conguaglio») e Marco
    // rimborsa a Ines i suoi giorni. La fine di prima della .50 si simula come in `M41`: registrata con «Non scriverlo» (nessuna riga
    // in saldi, come allora), poi tolta la chiave `conguaglio` dal registro.
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    $registro = $fine->registro;
    unset($registro['conguaglio']);
    DB::table('subentri')->where('id', $fine->id)->update(['registro' => json_encode($registro)]);
    fclPersona($s, 'Luca');
    fclPersona($s, 'Marco');

    // Controllo: all'inizio di Luca, prima che la sua riga ci sia, la frase è quella della fine senza la scelta.
    expect(implode(' ', fclAnteprima($this, $s, cdb50Inizio($s, 'Luca', '2026-07-01', 50))['rate']['frasi']))
        ->toContain('resta nelle rate di Ines: i giorni dopo la sua uscita li rimborsa Luca a Ines, con il prospetto degli oneri accessori.');
    fclRegistra($this, $s, cdb50Inizio($s, 'Luca', '2026-07-01', 50));

    $frase = implode(' ', fclAnteprima($this, $s, cdb50Inizio($s, 'Marco', '2026-09-01', 50))['rate']['frasi']);
    fclRegistra($this, $s, cdb50Inizio($s, 'Marco', '2026-09-01', 50));
    $marco = cdb50VociDelProspetto($s, 'Marco');

    // Controllo: nel prospetto Marco rimborsa Ines, con la strada A′.
    expect($marco->pluck('pagato_da')->filter()->unique()->values()->all())->toBe(['Ines'])
        ->and($marco->pluck('strada')->filter()->unique()->values()->all())->toBe(['A']);

    expect($frase)->not->toContain('lo decidono le parti')
        ->and($frase)->toContain('resta nelle rate di Ines: i giorni dopo la sua uscita li rimborsa Marco a Ines, con il prospetto degli oneri accessori.');
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 4, rilievo 3: la coppia del box senza nessuno al posto dell'inquilino dopo l'uscita
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il caso del rilievo: Ugo proprietario dell'Interno 1 (900 millesimi), il «Box 12» (100 millesimi) con Ines inquilina e nessun
 * proprietario; il piano generato con la presa d'atto delle quote scoperte, le sei rate a giornale.
 */
function cdb50BoxSenzaNessuno($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ines', 'inquilino', 100]]]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'Box senza proprietario: la parte del proprietario resta scoperta', accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
    fclEmetti($test, $s, '2026-06-30');

    return $s;
}

it('rilievo 3 — il box senza nessuno al posto dell\'inquilino dopo l\'uscita: le frasi del calcolo e il cancello non dicono un debito di € 18,15 a Ugo, che sul box non c\'è, e il pannello dice che quella parte resta nelle rate di Ines', function () {
    // `AnteprimaPassaggio::conguaglioDellaFine` scarta la coppia del box (nessuno al posto dell'inquilino, `per_persona` vuoto), ma la
    // lascia in `per_gestione` e nelle frasi del calcolo, che la danno a Ugo con le parole dell'Interno 1. Le cifre:
    // - Interno 1, 900 millesimi: 120000 × 0,9 × 30 % = 32400; 32400 × [181, 184] / 365 = 16066,85 / 16333,15 → 16066 + 16333 =
    //   32399, il centesimo al resto più grande (,85) → Ines 16067, la coppia 16333 (€ 163,33) a Ugo;
    // - Box 12, 100 millesimi: 120000 × 0,1 × 30 % = 3600; 3600 × [181, 184] / 365 = 1785,21 / 1814,79 → 1785 + 1814 = 3599, il
    //   centesimo al resto più grande (,79) → i giorni dopo l'uscita 1815 (€ 18,15), che nessuno prende: restano nelle rate di Ines.
    // Anche il cancello conta insieme le 12 quote non emesse delle due unità (6 + 6) e le dice tutte «comprese nel conguaglio, se lo
    // scrivi»; le 6 del box non lo sono.
    $s = cdb50BoxSenzaNessuno($this);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);
    $testi = [...$a['rate']['frasi'], ...$a['rate']['frasi_del_conguaglio'], ...($a['rate']['conguaglio']['frasi'] ?? []), ...$a['obbligati']['frasi'], ...$a['cancello']['motivi'], ...($a['cancello']['informazioni'] ?? [])];
    $sulBox = array_values(array_filter($testi, fn (string $t) => str_contains($t, '€ 18,15')));
    // Una frase che dice comprese nel conguaglio (o conguagliate) tutte le 12 quote non emesse, senza dire che quelle del box no.
    $tutteComprese = array_values(array_filter([...$a['rate']['frasi'], ...$a['cancello']['motivi']],
        fn (string $t) => str_contains($t, '12 quote') && preg_match('/compres|conguagli/iu', $t) === 1 && preg_match('/box/iu', $t) !== 1));

    fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);

    // Controlli: la coppia proposta è solo quella dell'Interno 1, e la scelta si chiede. La registrazione con «Scrivi» resta
    // com'è: solo le righe dell'Interno 1; sul box nessuna riga, e i suoi € 36,00 (3600 × 12/12) restano a Ines.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333]])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe([])
        ->and(fclNetti($s, $s['box']))->toBe(['Ines' => 3600]);

    expect(implode(' | ', $testi))->not->toContain('debito € 18,15 a Ugo')
        ->and(collect($sulBox)->contains(fn (string $t) => str_contains($t, 'Ugo')))->toBeFalse('Una frase con € 18,15 nomina Ugo: ' . implode(' | ', $sulBox))
        ->and($tutteComprese)->toBe([])
        ->and(collect($sulBox)->contains(fn (string $t) => str_contains($t, 'Ines') && preg_match('/nessuno/iu', $t) === 1))
        ->toBeTrue('Nessuna frase dice che € 18,15 restano nelle rate di Ines perché sul box nessuno sta al posto dell\'inquilino: ' . implode(' | ', $sulBox));
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 5, rilievo 4: «l'unità resta sfitta» con un coinquilino che resta
// ---------------------------------------------------------------------------------------------------------------------

/** La frase di riferimento e le frasi del blocco 1 («Anagrafica») del pannello, in un testo solo. */
function cdb50Blocco1(array $anteprima): string
{
    return implode(' | ', [$anteprima['riferimento']['frase'], ...$anteprima['anagrafica']['frasi']]);
}

it('rilievo 4 — con Dora che resta, la frase di riferimento e il blocco 1 non dicono «l\'unità resta sfitta»: lo stesso pannello dice che la locazione continua con Dora', function (bool $seiRate) {
    // `AnteprimaPassaggio::fraseRiferimento` (fine locazione senza chi entra: «… L'unità resta sfitta dal …») e `blocco1` («L'unità
    // resta sfitta dal …: nessun inquilino risulterà registrato.») non guardano chi resta: Dora è inquilina dal 2019 e lo resta.
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]], $seiRate);

    $a = fclAnteprima($this, $s, fclModulo($s));

    // Controlli: le date di Ines si dicono, e il pannello sa che Dora resta («Chi resta obbligato»).
    expect($a['riferimento']['frase'])->toContain('Ines risulterà inquilino fino al 30 giugno 2026 compreso.')
        ->and($a['anagrafica']['frasi'][0])->toBe('Ines risulterà inquilino fino al 30 giugno 2026.')
        ->and(implode(' ', $a['obbligati']['frasi']))->toContain('la locazione continua con Dora');

    expect(cdb50Blocco1($a))->not->toContain('sfitta')
        ->and(cdb50Blocco1($a))->not->toContain('nessun inquilino risulterà registrato');
})->with([
    'sei rate a giornale' => [true],
    'il piano generato e non emesso' => [false],
]);

it('rilievo 4, la guardia — senza un coinquilino la frase di riferimento e il blocco 1 dicono ancora che l\'unità resta sfitta dal 1 luglio 2026', function () {
    $s = cdb50Scenario($this, [['Ugo', 'proprietario', 100]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect($a['riferimento']['frase'])->toBe('Ines risulterà inquilino fino al 30 giugno 2026 compreso. L\'unità resta sfitta dal 1 luglio 2026.')
        ->and($a['anagrafica']['frasi'])->toContain('L\'unità resta sfitta dal 1 luglio 2026: nessun inquilino risulterà registrato.');
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 7, decisione 81.2: una vendita dopo una vendita già fermata dice la sua parte della fermata
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La fine di Ines il 1/7 con «Scrivi» (la coppia Ines −18148, Ugo +18148), le rate a giornale fino al 31/8, la vendita di Ugo a Elsa
 * il 1/9 registrata, le rate fino al 31/10. Carlo è la persona che compra da Elsa.
 */
function cdb50DopoLaPrimaVendita($test): array
{
    $s = cdb50Scenario($test, [['Ugo', 'proprietario', 100]]);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    $s['prima'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));
    fclEmetti($test, $s, '2026-10-31');

    return $s;
}

/**
 * Le cifre, a mano. La coppia 18148 sui 184 giorni dopo l'uscita (1/7–31/12).
 * - Prima vendita, Ugo → Elsa il 1/9: Ugo 1/7–31/8, 62 giorni; Elsa 1/9–31/12, 122 giorni. 18148 × 62/184 = 6115,09 e
 *   18148 × 122/184 = 12032,91 → 6115 + 12032 = 18147, il centesimo al resto più grande (,91) → Ugo 6115, Elsa 12033: la fermata
 *   è la parte di Elsa, € 120,33.
 * - Seconda vendita, Elsa → Carlo il 1/11: Ugo 62 giorni, Elsa 1/9–31/10, 61, Carlo 1/11–31/12, 61. 18148 × 62/184 = 6115,09 e
 *   18148 × 61/184 = 6016,46 due volte → 6115 + 6016 + 6016 = 18147, il centesimo a pari resto (,46) a chi esce, Elsa → Ugo 6115,
 *   Elsa 6017, Carlo 6016. La parte di Carlo sale da 0 a 6016: € 60,16 di quei giorni sono suoi. Nessun conduttore dopo l'uscita: i
 *   giorni senza conduttore sono tutti.
 */
it('decisione 81.2 — la vendita di Elsa a Carlo il 1/11, dopo la fermata della vendita di Ugo a Elsa (€ 120,33): il pannello dice la sua parte della fermata, € 60,16 verso Carlo, ed è un motivo del cancello', function () {
    // `FermataDopoScrivi::per` cerca le coppie in cui chi esce ha la riga a debito (`coppieScritte`, `debito: true`): Elsa non ha
    // righe nella coppia della fine (le ha Ugo), e la seconda vendita non trova niente da fermare.
    $s = cdb50DopoLaPrimaVendita($this);

    // Controllo: la prima vendita si è fermata, € 120,33 verso Elsa, e il registro lo conserva.
    expect(collect($s['prima']['anteprima']['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(12033)
        ->and(collect($s['prima']['anteprima']['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Elsa'])
        ->and(collect(fds50Fermate($s['prima']['subentro']))->sum('cifra'))->toBe(12033);

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'));
    $frasi = collect($a['rate']['frasi']);
    $motivi = collect($a['cancello']['motivi']);

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(6016)
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Carlo'])
        ->and($frasi->contains(fn (string $f) => str_contains($f, '€ 60,16') && str_contains($f, 'Carlo')))->toBeTrue('Il pannello non dice € 60,16 verso Carlo: ' . $frasi->implode(' | '))
        ->and($motivi->contains(fn (string $m) => str_contains($m, '€ 60,16')))->toBeTrue('Nessun motivo del cancello dice € 60,16: ' . $motivi->implode(' | '))
        ->and($a['cancello']['richiesto'])->toBeTrue();
});

it('decisione 81.2 — registrata la vendita di Elsa a Carlo, il registro conserva la sua parte della fermata (€ 60,16 verso Carlo) e lo storico la dice; la fermata della prima vendita resta com\'era', function () {
    $s = cdb50DopoLaPrimaVendita($this);

    $seconda = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'))['subentro'];
    $parti = collect(fds50Fermate($seconda))->pluck('parti')->flatten(1);
    $storico = fds50Storico($s, $seconda)['fermate'] ?? [];

    // Controlli: la seconda vendita è registrata, la coppia della fine non si è mossa, e la fermata della prima vendita resta
    // nel suo registro e nel suo storico, € 120,33.
    expect($seconda->tipo_passaggio)->toBe('vendita')
        ->and((int) $seconda->anagrafica_entrante_id)->toBe((int) $s['persone']['Carlo']->id)
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(collect(fds50Fermate($s['prima']['subentro']))->sum('cifra'))->toBe(12033)
        ->and(implode(' ', fds50Storico($s, $s['prima']['subentro'])['fermate'] ?? []))->toContain('€ 120,33');

    expect(collect(fds50Fermate($seconda))->sum('cifra'))->toBe(6016)
        ->and($parti->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Carlo', 6016]])
        ->and(collect($storico)->contains(fn (string $f) => str_contains($f, '€ 60,16') && str_contains($f, 'Carlo')))->toBeTrue('Lo storico della seconda vendita non dice € 60,16 verso Carlo: ' . implode(' | ', $storico));
});
