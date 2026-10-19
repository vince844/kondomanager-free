<?php

/**
 * 1.11.0-beta.50, Fase 1-bis — i rilievi del motore: la coppia della fine e la catena dei passaggi (R1, R2, R3), l'ordine
 * dell'annullamento (R4, decisione 83.1), il prospetto dopo un cambio «regolato» (R7, decisione 83.3), la seconda vendita dopo una
 * fermata (decisione 82.2; la 82.1 è il test M69 di `MutazioniCorrezioniBeta50Test`, riscritto), e tre rilievi della ripresa: la strada
 * B del prospetto sul box senza proprietario, la frase della fermata dopo una successione con l'arretrato agli eredi, il «€ 0,01 vanno
 * restituiti a Elsa».
 *
 * Lo scenario è quello di `ScenariFineLocazione.php` e `FermataDopoScriviHelpers.php`: «Spese generali» € 1.200,00 sulla tabella
 * «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate mensili; Ines inquilina dal
 * 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181, 184] / 365 = 17852,05 / 18147,95 → la coppia
 * 18148 (€ 181,48). Per R7 lo scenario di `ScenariProspettoLocazione.php`, con gli stessi numeri e i nomi completi.
 *
 * Nati come test rossi: ognuno descrive un difetto del codice della .50, corretto prima del commit, e ha accanto un controllo (la stessa
 * scena prima del passo che la guastava, o il caso vicino che il codice faceva già giusto) che dice che il rosso non veniva dal test. Gli
 * oracoli sono fatti a mano, in frazioni esatte, con la regola della coppia (le parti intere, il centesimo al resto più grande, a pari
 * resto a chi esce).
 *
 * Le guardie accanto a R1 fissano gli anelli che la correzione deve lasciare: il cambio «Scrivi», il cambio «regolato fra le
 * parti» (la casella di prima della .50, il conguaglio annullato) e la successione «non scritta» della .48.
 *
 * Cosa NON copre:
 * - le frasi nuove parola per parola: si cercano la cifra, il nome e la parola che conta, non la frase intera, che scriverà chi corregge;
 * - nel prospetto, ciò che le decisioni non fissano: dopo R2 la voce dei giorni che nessuno copre (si fissano la voce «pagata da Ugo», che
 *   Ugo non paga di più, i 184 giorni senza conduttore, il registro della coppia e la stampa), dopo R7 le colonne del denaro di Ines e di
 *   Luca «regolato fra le parti» (si fissano Anna, i giorni senza conduttore, il totale, e per Ines e Luca le cifre e i giorni);
 * - in R4 i casi (c) e (d) con il saldo manuale dal Wallet già registrato: si fissano l'ordine dell'annullamento e l'avviso;
 * - nella successione «non scritta» della .48 seguita dalla vendita di un erede, a chi va il credito dei giorni nelle rate emesse al
 *   defunto: all'erede che vende, e il credito resta fra gli eredi, come vuole «Non scriverlo: la posizione resta com'è» (decisioni 65 e
 *   72). Non è il difetto di R1 (chi compra paga una volta sola i suoi giorni), e la guardia della successione fissa solo che la
 *   successione resta un anello;
 * - gli altri rilievi della Fase 1-bis (R5, R6, R8–R21), il pannello e il modulo nel browser, le corse fra scritture (SQLite in memoria).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/Support/ScenariProspettoLocazione.php';
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
 * Lo scenario con il piano generato e le sei rate gennaio–giugno a giornale (il piano non si ricalcola più). È `mcb50ConSeiRate` di
 * `MutazioniCorrezioniBeta50Test`, che sta in un file di test e non si può includere.
 */
function rbmConSeiRate($test, array $titolari, array $opzioni = []): array
{
    $s = fclScenario($titolari, $opzioni);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Lo scenario con la fine di Ines «Scrivi» il 1/7, registrata (la coppia Ines −18148, Ugo +18148), e le rate a giornale fino al giorno dato. */
function rbmDopoScrivi($test, string $emessoFino): array
{
    $s = rbmConSeiRate($test, [['Ugo', 'proprietario', 100]]);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    fclEmetti($test, $s, $emessoFino);

    return $s;
}

/** Il motivo per cui un passaggio non si annulla, o null: quello che legge lo storico. */
function rbmMotivo(Subentro $passaggio): ?string
{
    return app(AnnullaPassaggioAction::class)->motivoBlocco(Subentro::conAnnullati()->findOrFail($passaggio->id));
}

/**
 * L'annullamento dalla rotta dello storico: «annullato» se riesce (il redirect), altrimenti lo stato e il messaggio del rifiuto, perché
 * un test rosso dica perché.
 */
function rbmAnnulla($test, array $s, Subentro $passaggio): string
{
    $r = fclAnnulla($test, $s, $passaggio);

    return $r->status() === 302 ? 'annullato' : $r->status() . ': ' . ($r->json('errors.passaggio.0') ?? $r->json('message') ?? '');
}

/** Gli avvisi dell'annullamento di un passaggio, in un testo solo. */
function rbmAvvisi(Subentro $passaggio): string
{
    return implode(' | ', app(AnnullaPassaggioAction::class)->avvisi(Subentro::conAnnullati()->findOrFail($passaggio->id)));
}

/**
 * Il piano a capitoli del rilievo R3: «Spese generali» € 1.200,00 per tutto l'anno e «Affitto antenna», un conto di entrata, con la
 * competenza data, sulla stessa tabella e con la stessa divisione (30 % «Inquilino», 70 % «Proprietario»). Prima della generazione.
 */
function rbmConLAntenna(array $s, int $importo, string $dal, string $al): void
{
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $antenna = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $antenna->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->attach([$spese->id => ['importo' => 120000], $antenna->id => ['importo' => $importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $antenna->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
}

/** Le voci «senza conduttore» del prospetto dell'Interno 1: [pagata da, importo, giorni], in ordine. */
function rbmSenza(array $s, ?\App\Models\Immobile $unita = null): array
{
    $p = app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);

    return collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0] . '|' . $v[1])->values()->all();
}

// ---------------------------------------------------------------------------------------------------------------------
// R1: dopo un cambio d'inquilino con «Non scriverlo» chi è entrato non ha i giorni di chi è uscito
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il cambio d'inquilino di Ines a Luca il 1/7 con «Non scriverlo»: nei saldi niente, e il prospetto fa la strada A′ (Luca rimborsa a
 * Ines i giorni dal 1/7, 18148). Le cifre della seconda uscita, il 1/9:
 * - Luca 1/7–31/8, 62 giorni: 36000 × 62/365 = 6115,07 → 6115;
 * - dopo, 1/9–31/12, 122 giorni: 36000 × 122/365 = 12032,88 → 12033. I giorni dal 1/7 sono tutti nelle rate di Ines: Luca non ha
 * niente né nelle rate né nei saldi, e la sua uscita non ha niente da conguagliare. Prima della correzione
 * `ConguaglioPassaggio::anelliVerso` leggeva il cambio «non scritto» come un anello: Luca riceveva le quote di Ines, e la sua
 * uscita proponeva € 120,33 a suo credito per giorni che aveva pagato Ines.
 */
function rbmDopoIlCambioNonScritto($test): array
{
    $s = rbmConSeiRate($test, [['Ugo', 'proprietario', 100]]);
    $luca = fclPersona($s, 'Luca');
    $cambio = fclRegistra($test, $s, fclModulo($s, $luca) + ['conguaglio' => 'non_scrivere']);

    // Controlli: il cambio non ha scritto niente, e il prospetto è la strada A′.
    expect($cambio->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        ->and(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 36000, -18148, 181], 'luca' => [18148, 0, 18148, 184]]);

    return $s;
}

it('R1 (a) — dopo il cambio d\'inquilino del 1/7 con «Non scriverlo», Luca lascia l\'unità il 1/9 senza successore: i giorni sono nelle rate di Ines, la sua uscita non propone coppie, e i netti restano Ines € 360,00, Ugo € 840,00', function () {
    // Prima della correzione la coppia era Luca −12033, Ugo +12033: netti Ines 36000, Luca −12033, Ugo 96033. Con il prospetto (Luca
    // rimborsa a Ines 6115) Luca incassava € 59,18 invece di costare € 61,15, e Ugo pagava € 120,33 che il prospetto gli faceva anche
    // restituire a Ines.
    $s = rbmDopoIlCambioNonScritto($this);
    $modulo = fclModulo($s, null, '2026-09-01', [], 'Luca');

    $a = fclAnteprima($this, $s, $modulo);
    $frasi = fclFrasi($a);

    // Le tre frasi false: «la sua competenza è passata a Luca» nella riga della coppia, «la cui competenza è passata a Luca … passa
    // ancora» nel motivo del cancello, «restano nelle rate di Luca» nella scelta.
    expect(fclCoppie($a))->toBe([])
        ->and($frasi)->not->toContain('competenza è passata a Luca')
        ->and($frasi)->not->toContain('restano nelle rate di Luca');

    // Registrata (con la scelta solo se il pannello la chiede): niente nei saldi, e il prospetto dice ciò che dicono i saldi.
    fclRegistra($this, $s, $modulo + (($a['rate']['scelta_del_conguaglio'] ?? false) ? ['conguaglio' => 'scrivi'] : []));
    expect(fclSaldi($s))->toBe([])
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181], 'luca' => [6115, 0, 6115, 62]], 'senza' => [12033, 122]]);
});

it('R1 (b) — dopo il cambio d\'inquilino del 1/7 con «Non scriverlo», il cambio da Luca ad Anna il 1/9: nessuna coppia, e Anna paga i suoi giorni una volta sola, con il prospetto (€ 120,33 a Ines)', function () {
    // Prima della correzione la coppia era Luca −12033, Anna +12033, e il prospetto faceva rimborsare ad Anna gli stessi 12033 a
    // Ines: Anna pagava 24066 per i suoi 122 giorni, Luca incassava € 120,33.
    $s = rbmDopoIlCambioNonScritto($this);
    $anna = fclPersona($s, 'Anna');
    $modulo = fclModulo($s, $anna, '2026-09-01', [], 'Luca');

    $a = fclAnteprima($this, $s, $modulo);
    $frasi = fclFrasi($a);

    expect(fclCoppie($a))->toBe([])
        ->and($frasi)->not->toContain('competenza è passata a Luca')
        ->and($frasi)->not->toContain('restano nelle rate di Luca');

    fclRegistra($this, $s, $modulo + (($a['rate']['scelta_del_conguaglio'] ?? false) ? ['conguaglio' => 'scrivi'] : []));
    expect(fclSaldi($s))->toBe([])
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['anna' => [12033, 0, 12033, 122], 'ines' => [17852, 36000, -18148, 181], 'luca' => [6115, 0, 6115, 62]], 'senza' => [0, 0]]);
});

it('R1, la guardia — con «Scrivi» al cambio del 1/7 la catena è vera: la fine di Luca il 1/9 propone a Ugo i giorni dopo la sua uscita (€ 120,33), che Luca aveva preso con la coppia del cambio', function () {
    // La stessa scena di R1 (a), con il cambio scritto: Ines −18148, Luca +18148. Luca esce il 1/9: la sua coppia Luca −12033, Ugo
    // +12033. Saldi: Ines −18148, Luca 18148 − 12033 = 6115, Ugo 12033. Netti: Ines 36000 − 18148 = 17852, Luca 6115, Ugo 96033.
    $s = rbmConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $luca = fclPersona($s, 'Luca');
    fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'scrivi']);
    $modulo = fclModulo($s, null, '2026-09-01', [], 'Luca');

    expect(fclCoppie(fclAnteprima($this, $s, $modulo)))->toBe([[(int) $s['unita']->id, 'Ugo', 12033]]);
    fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);

    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Luca' => 6115, 'Ugo' => 12033])
        ->and(fclNetti($s))->toBe(['Ines' => 17852, 'Luca' => 6115, 'Ugo' => 96033])
        ->and(rbmSenza($s))->toBe([['Ugo', 12033, 122]]);
});

it('R1, la guardia della casella — il cambio d\'inquilino «regolato fra le parti» (la casella di prima della .50, o il conguaglio annullato) resta un anello: la fine di Luca il 1/9 con «Scrivi» propone a Ugo i giorni dopo la sua uscita, € 120,33', function (string $modo) {
    // R1 toglie dalla catena solo il cambio «Non scriverlo». Con la casella o con il conguaglio annullato le parti hanno regolato fuori
    // (decisioni 43 e 46): Luca ha preso i giorni di Ines, e la sua uscita ha la coppia, Luca −12033, Ugo +12033 (36000 × 122/365 =
    // 12032,88). È la scena di R7 prima dell'inizio di Anna (`rbmDopoIlCambioRegolato`). Una correzione che togliesse anche questi anelli
    // lascerebbe la coppia vuota, e i netti tornerebbero Ines € 360,00, Ugo € 840,00.
    $s = rbmDopoIlCambioRegolato($this, $modo);

    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Luca Ferri' => -12033, 'Ugo Rinaldi' => 96033]);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('R1, la guardia della successione — la successione di Ugo del 1/5 con l\'arretrato al defunto e «Non scriverlo», poi Anna vende la sua metà a Carlo il 1/6: la successione resta un anello, e Carlo paga i suoi giorni anche sulle rate emesse a Ugo, € 351,78', function () {
    // Il caso che R1 chiedeva di verificare: la successione «non scritta» della .48 seguita dalla vendita di un erede. Non ha il difetto di
    // R1, perché chi compra paga i suoi giorni una volta sola e nessun documento glieli fa pagare di nuovo. La decisione 65 vuole questo
    // anello («senza, quando un altro erede vende, le quote emesse al defunto non passavano a chi compra»), e la correzione di R1 vale solo
    // per la fine locazione con chi entra.
    // La voce tutta al «Proprietario», senza inquilino: 120000, 10000 a rata. Sei rate emesse a Ugo (60000), le sei in bozza passate ad
    // Anna, erede di riferimento (decisione 65). Carlo dal 1/6 al 31/12, 214 giorni, al 50 %: 120000 × 50 % × 214/365 = 35178,08 → 35178,
    // metà dalle rate emesse a Ugo (60000 × 50 % × 214/365 = 17589,04) e metà dalle bozze di Anna. Senza l'anello Carlo pagherebbe solo
    // la parte delle bozze, € 175,89, e i suoi giorni nelle rate di Ugo resterebbero agli eredi.
    $s = rbmConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [], 'ripartizione' => ['proprietario' => 100]]);
    foreach (['Anna', 'Bruno', 'Carlo'] as $nome) {
        fclPersona($s, $nome);
    }
    $successione = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-05-01'))['subentro'];

    // Controlli: la successione non ha scritto niente, e le bozze sono di Anna.
    expect($successione->conguaglioNonScritto())->toBeTrue()
        ->and(fclSaldi($s))->toBe([])
        ->and(fclNetti($s))->toBe(['Anna' => 60000, 'Ugo' => 60000]);

    $vendita = fds50ModuloVendita($s, 'Anna', 'Carlo', '2026-06-01', 50);
    expect(fclCoppie(fclAnteprima($this, $s, $vendita)))->toBe([[(int) $s['unita']->id, 'Carlo', 35178]]);

    fds50Registra($this, $s, $vendita);
    expect(fclSaldi($s))->toBe(['Anna' => -35178, 'Carlo' => 35178])
        ->and(fclNetti($s))->toBe(['Anna' => 24822, 'Carlo' => 35178, 'Ugo' => 60000]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R2: la coppia della fine con la titolarità scoperta per una parte dei giorni dopo l'uscita
// ---------------------------------------------------------------------------------------------------------------------

it('R2 — la riga di Ugo finisce il 30/9 e dopo non c\'è nessun proprietario: la coppia della fine dà a Ugo solo i suoi 92 giorni (€ 90,74), gli altri € 90,74 restano nelle rate di Ines e il pannello lo dice', function () {
    // Le cifre: la coppia 18148 sui 184 giorni dopo l'uscita; Ugo copre il 1/7–30/9 (31 + 31 + 30 = 92 giorni), nessuno il 1/10–31/12
    // (92). 18148 × 92/184 = 9074 esatti a Ugo; la generazione sulla stessa titolarità dà 36000 × 92/365 = 9073,97 → 9074 a Ugo e
    // lascia scoperti gli altri 9074. Prima della correzione `ChiPagaAlPostoDellInquilino::pesi` sommava solo i giorni coperti e la
    // divisione li normalizzava: Ugo prendeva tutta la coppia, € 181,48, e la stampa diceva «184 giorni € 181,48 pagata da Ugo».
    $s = rbmConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);

    // Controllo: dopo il 30/9 sull'unità non c'è nessun proprietario, e la scelta si chiede.
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('tipologia', '!=', 'inquilino')->whereNull('data_fine')->count())->toBe(0);
    $a = fclAnteprima($this, $s, fclModulo($s));
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue();
    $periodi = fclPeriodi(fclFrasi($a));

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 9074]])
        ->and(fclFrasi($a))->not->toContain('debito € 181,48 a Ugo')
        ->and(collect($periodi)->contains(fn (string $p) => str_contains($p, '€ 90,74') && str_contains($p, 'Ines') && preg_match('/nessuno/u', $p) === 1))
        ->toBeTrue('Nessuna frase dice che € 90,74 restano nelle rate di Ines perché dal 1/10 nessuno sta al posto dell\'inquilino: ' . implode(' | ', $periodi));

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    // Il registro della coppia (`conguaglio.periodi`) ha solo i tratti coperti, con il loro importo: la fermata dopo «Scrivi» ridivide la
    // coppia su quei pezzi, e un pezzo 1/7–31/12 da € 90,74 la dividerebbe anche sui giorni che nessuno copre.
    $pezzi = collect($fine->registro['conguaglio']['periodi'] ?? [])->flatMap(fn (array $v) => $v['periodi'] ?? []);

    expect(fclSaldi($s))->toBe(['Ines' => -9074, 'Ugo' => 9074])
        ->and($pezzi->sum('importo'))->toBe(9074)
        ->and($pezzi->flatMap(fn (array $p) => $p['tratti'] ?? [])->pluck('al')->max())->toBe('2026-09-30')
        ->and(collect(rbmSenza($s))->contains(fn ($v) => $v === ['Ugo', 9074, 92]))->toBeTrue('Nel prospetto la voce pagata da Ugo non è di 92 giorni, € 90,74: ' . json_encode(rbmSenza($s)))
        // Ugo non paga più di € 90,74 nel prospetto, e i 184 giorni senza conduttore ci sono tutti (gli altri 92 restano a chi li ha
        // nelle rate, con la voce che sceglierà chi corregge).
        ->and(collect(rbmSenza($s))->filter(fn ($v) => $v[0] === 'Ugo')->sum(fn ($v) => $v[1]))->toBe(9074)
        ->and(fclProspetto($s)['senza'])->toBe([18148, 184])
        ->and(fclStampa($s))->toContain('92 giorni € 90,74 pagata da Ugo')
        ->and(fclStampa($s))->not->toContain('€ 181,48 pagata da Ugo');
});

it('R2, sul box — la riga di Rita, proprietaria del «Box 12», finisce il 31/10: la coppia del box dà a Rita i suoi 123 giorni, € 12,13, non € 18,15; l\'Interno 1 di Ugo resta € 163,33', function () {
    // La stessa scena sulla pertinenza, con la riga chiusa al 31/10 perché la parte non cada a pari resto. Box 12, 100 millesimi: 120000
    // × 0,1 × 30 % = 3600; i giorni dopo l'uscita 3600 × 184/365 = 1814,79 → 1815. Rita copre il 1/7–31/10, 123 giorni su 184: 1815 ×
    // 123/184 = 1213,29 → 1213; gli altri 602 (61 giorni) restano nelle rate di Ines. La generazione sulla stessa titolarità: 3600 ×
    // 123/365 = 1213,15 → 1213 a Rita, 601,64 → 602 scoperti. L'Interno 1, 900 millesimi: 32400 × 184/365 = 16333,15 → 16333, tutta di
    // Ugo. Prima della correzione Rita prendeva tutta la coppia del box, 1815.
    $s = rbmConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Rita', 'proprietario', $s['box']))->update(['data_fine' => '2026-10-31']);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333], [(int) $s['box']->id, 'Rita', 1213]]);

    fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s, $s['box']))->toBe(['Ines' => -1213, 'Rita' => 1213])
        ->and(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R3: un capitolo di entrata nella coppia per capitolo pesa con il suo segno
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il piano a capitoli con l'«Affitto antenna» € 400,00 di entrata a competenza 1/1–30/9, generato, a giornale fino al 31/8. L'entrata
 * fa quote negative: all'inquilino −12000 sui 273 giorni della competenza, −7956 a Ines (181 giorni) e −4044 dopo l'uscita (92 giorni,
 * 1/7–30/9: 12000 × 92/273 = 4043,96). La coppia della fine è di due pezzi: le spese +18148 sul 1/7–31/12, l'entrata −4044 sul
 * 1/7–30/9; il totale 14104.
 */
function rbmConLEntrata($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    rbmConLAntenna($s, 40000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    // Controllo: le quote pure del piano, Ines 36000 − 12000 = 24000 e Ugo 84000 − 28000 = 56000.
    expect(fclNetti($s))->toBe(['Ines' => 24000, 'Ugo' => 56000]);

    return $s;
}

it('R3 (vendita registrata prima) — la vendita di Ugo a Elsa il 1/9, poi Ines esce il 1/7 con «Scrivi»: l\'entrata dell\'antenna toglie dalla parte di ciascuno con il suo segno, Ugo € 33,90 ed Elsa € 107,14, come il piano generato dopo', function () {
    // Le parti, in frazioni esatte: Ugo ha il 1/7–31/8 (62 giorni), Elsa il 1/9–31/12 (122) per le spese e il 1/9–30/9 (30) per
    // l'entrata. - Ugo = 18148 × 62/184 − 4044 × 62/92 = 6115,0870 − 2725,3043 = 3389,7826; - Elsa = 18148 × 122/184 − 4044 × 30/92 =
    // 12032,9130 − 1318,6957 = 10714,2174; 3389 + 10714 = 14103, il centesimo al resto più grande (,78) → Ugo 3390, Elsa 10714. Il piano
    // generato dopo i due passaggi (a mano): Ugo 84000 × 243/365 − 28000 × 243/273 + 3389,78 = 55923,29 − 24923,08 + 3389,78 = 34389,99 →
    // 34390; Elsa 84000 × 122/365 − 28000 × 30/273 + 10714,22 = 28076,71 − 3076,92 + 10714,22 = 35714,01 → 35714; Ines 24000 − 14104 =
    // 9896. Prima della correzione `pesiDeiPezzi` pesava i pezzi con `abs()`: l'entrata pesava come una spesa, Ugo 5618 ed Elsa 8486, €
    // 22,28 spostati da Elsa a Ugo.
    $s = rbmConLEntrata($this);
    fclEmetti($this, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclRegistra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));

    $a = fclAnteprima($this, $s, fclModulo($s));

    // Controllo: il totale della coppia è giusto, 18148 − 4044 = 14104; è la divisione fra Ugo ed Elsa che sbaglia.
    expect($a['rate']['conguaglio']['totale_entrante'])->toBe(14104);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 10714], [(int) $s['unita']->id, 'Ugo', 3390]]);

    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclNetti($s))->toBe(['Elsa' => 35714, 'Ines' => 9896, 'Ugo' => 34390]);
});

it('R3 (fine registrata prima) — Ines esce il 1/7 con «Scrivi», poi la vendita di Ugo a Elsa il 1/9: la fermata è la parte di Elsa con l\'entrata, € 107,14', function () {
    // La coppia scritta ha i due pezzi nel registro; la fermata la ridivide (`FermataDopoScrivi::pesi`) con la stessa regola della
    // coppia: la parte di Elsa 10714, come sopra. Prima della correzione lo stesso `abs()`: 8486.
    $s = rbmConLEntrata($this);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-08-31');
    fclPersona($s, 'Elsa');

    // Controlli: la coppia 14104 a Ugo, e il registro conserva i due pezzi con il loro segno.
    expect(fclSaldi($s))->toBe(['Ines' => -14104, 'Ugo' => 14104])
        ->and(collect($fine->registro['conguaglio']['periodi'][0]['periodi'] ?? [])->map(fn ($p) => [$p['tratti'][0]['dal'], $p['tratti'][0]['al'], (int) $p['importo']])->all())
        ->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-07-01', '2026-09-30', -4044]]);

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(10714)
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Elsa']);
});

it('R3, la guardia — l\'antenna di entrata € 2.000,00 a competenza 1/7–31/12, gli stessi giorni delle spese: la coppia è negativa (−41852) e si divide come oggi, Ugo € −141,02, Elsa € −277,50', function () {
    // Un totale negativo è un caso vero (`ConguaglioPassaggio` chiama la divisione con qualunque importo diverso da zero). L'entrata
    // all'inquilino dopo l'uscita: 200000 × 30 % = 60000 tutta sul 1/7–31/12; la coppia 18148 − 60000 = −41852, i due pezzi sugli
    // stessi giorni: −41852 × 62/184 = −14102,30 e × 122/184 = −27749,70 → Ugo −14102, Elsa −27750. Il piano generato dopo, a mano: Ugo
    // 55923,29 − 140000 × 62/184 − 14102,32 = −5352,94 → −5353; Elsa 28076,71 − 140000 × 122/184 − 27749,73 = −92499,11 → −92499;
    // Ines 17852. Una correzione che togliesse solo `abs()` darebbe pesi di somma negativa, e la divisione per teste: −20926 a testa.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    rbmConLAntenna($s, 200000, '2026-07-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclRegistra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));

    $a = fclAnteprima($this, $s, fclModulo($s));
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', -27750], [(int) $s['unita']->id, 'Ugo', -14102]])
        ->and(fclNetti($s))->toBe(['Elsa' => -92499, 'Ines' => 17852, 'Ugo' => -5353]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R4, decisione 83.1: si annulla per primo l'ultimo passaggio registrato, non l'ultimo per data
// ---------------------------------------------------------------------------------------------------------------------

/** Lo scenario di `FermataDopoScriviHelpers` con il piano generato e le sei rate gennaio–giugno a giornale. */
function rbmFds($test): array
{
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');

    return $s;
}

it('R4 (a) — la vendita di Ugo a Elsa del 1/10 registrata prima, la fine di Ines del 1/7 con «Scrivi» dopo: la vendita non si annulla finché c\'è la fine, che si annulla per prima; poi la vendita, e i netti tornano giusti a ogni passo', function () {
    // La fine registrata dopo ha diviso la coppia sulla titolarità di dopo la vendita: Ugo 1/7–30/9 e Elsa 1/10–31/12, 92 giorni
    // ciascuno, 18148 × 92/184 = 9074. Prima della correzione si annullava la vendita, l'ultima per data: la riga di Elsa (+9074)
    // restava nella coppia della fine, ed Elsa non era più titolare di niente. Annullata la fine resta la vendita: Ugo 84000 × 273/365
    // = 62827,40 → 62827, Elsa 84000 × 92/365 = 21172,60 → 21173, Ines 36000.
    $s = rbmFds($this);
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);

    // Controlli: la coppia della fine ha letto la vendita, e i netti sono quelli del piano generato dopo i due passaggi.
    expect(fds50Coppia($s, $fine))->toBe(['Elsa' => 9074, 'Ines' => -18148, 'Ugo' => 9074])
        ->and(fds50Netti($s))->toBe(['Elsa' => 30247, 'Ines' => 17852, 'Ugo' => 71901]);

    expect(rbmMotivo($vendita) ?? '(nessun motivo: la vendita, registrata prima, si annullerebbe lasciando la riga di Elsa nella coppia della fine)')
        ->toContain('la fine locazione di Ines')->toContain('1 luglio 2026')->toContain('registrato dopo');

    expect(rbmAnnulla($this, $s, $fine))->toBe('annullato')
        ->and(fds50Netti($s))->toBe(['Elsa' => 21173, 'Ines' => 36000, 'Ugo' => 62827]);

    expect(rbmAnnulla($this, $s, $vendita))->toBe('annullato');
    expect(fds50Netti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('R4 (b) — la fine di Ines del 1/7 con «Scrivi», poi la successione di Ugo datata 1/5 con l\'arretrato agli eredi, che ha preso la coppia fra le sue fonti: la fine non si annulla finché c\'è la successione, che si annulla per prima', function () {
    // Con l'arretrato agli eredi la successione porta ad Anna e Bruno tutta la posizione di Ugo, coppia della fine compresa: 84000 +
    // 18148 = 102148, metà a testa, 51074. Prima della correzione si annullava la fine, l'ultima per data: la riga della coppia
    // spariva ma restava fra le fonti della successione, e i netti diventavano Anna 51074, Bruno 51074, Ines 36000, Ugo −18148 (€
    // 90,74 in più a ciascun erede, un credito finto al defunto). Annullata la successione: Ines 17852, Ugo 84000 + 18148 = 102148;
    // poi la fine: 36000 e 84000.
    $s = rbmFds($this);
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    $successione = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, '2026-05-01'))['subentro'];

    // Controllo: la successione ha preso la coppia della fine.
    expect(fds50Netti($s))->toBe(['Anna' => 51074, 'Bruno' => 51074, 'Ines' => 17852]);

    expect(rbmMotivo($fine) ?? '(nessun motivo: la fine si annullerebbe sotto il passaggio che ne ha preso la coppia)')
        ->toContain('successione')->toContain('1 maggio 2026')->toContain('registrato dopo');

    expect(rbmAnnulla($this, $s, $successione))->toBe('annullato')
        ->and(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 102148]);

    expect(rbmAnnulla($this, $s, $fine))->toBe('annullato');
    expect(fds50Netti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('R4 (c) — la fine di Ines del 1/7 con «Scrivi», poi la vendita di Ugo a Elsa datata 1/5, che si è fermata su € 181,48: la fine non si annulla finché c\'è la vendita, e la vendita si annulla per prima', function () {
    // Prima della correzione la vendita, con la data prima, non si annullava («c'è un altro passaggio: la fine»), e la fine si
    // annullava da sola: la fermata restava nel registro della vendita, e il saldo manuale che il pannello aveva indicato restava
    // senza avviso.
    $s = rbmFds($this);
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'))['subentro'];

    // Controllo: la vendita si è fermata su tutta la coppia, € 181,48 verso Elsa (tutti i giorni dopo l'uscita sono suoi).
    expect(collect(fds50Fermate($vendita))->map(fn ($f) => [(int) $f['cifra'], $f['verso']])->all())->toBe([[18148, ['Elsa']]]);

    expect(rbmMotivo($fine) ?? '(nessun motivo: la fine si annullerebbe sotto la vendita che si è fermata sulla sua coppia)')
        ->toContain('la vendita da Ugo a Elsa')->toContain('1 maggio 2026')->toContain('registrato dopo');

    // Dalla 1.11.0-beta.53 senza saldi scritti dopo la vendita gli avvisi non parlano del Wallet: i saldi legati li toglie l'annullamento.
    $avvisi = rbmAvvisi($vendita);
    expect(rbmAnnulla($this, $s, $vendita))->toBe('annullato')
        ->and($avvisi)->not->toContain('Toglilo dal Wallet');

    expect(rbmAnnulla($this, $s, $fine))->toBe('annullato');
    expect(fds50Netti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('R4 (d) — la fine di Ines del 1/7 con «Scrivi», l\'inizio di Luca dal 1/11, poi la vendita di Ugo a Elsa del 1/10, fermata su € 30,58: l\'inizio non si annulla finché c\'è la vendita, che si annulla per prima', function () {
    // La fermata conta solo ottobre, perché da novembre c'è Luca: 18148 × 31/184 = 3057,54 → 3058. Prima della correzione la vendita non
    // si annullava («c'è un altro passaggio: l'inizio di Luca, dal 1 novembre»), e l'inizio sì: la vendita restava con la fermata di €
    // 30,58, che senza Luca sarebbe stata € 90,74.
    $s = rbmFds($this);
    fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];

    // Controllo: la fermata della vendita è di ottobre, € 30,58 verso Elsa.
    expect(collect(fds50Fermate($vendita))->map(fn ($f) => [(int) $f['cifra'], $f['verso']])->all())->toBe([[3058, ['Elsa']]]);

    expect(rbmMotivo($inizio) ?? '(nessun motivo: l\'inizio si annullerebbe sotto la vendita che ha contato i suoi giorni)')
        ->toContain('la vendita da Ugo a Elsa')->toContain('1 ottobre 2026')->toContain('registrato dopo');

    $avvisi = rbmAvvisi($vendita);
    expect(rbmAnnulla($this, $s, $vendita))->toBe('annullato')
        ->and($avvisi)->not->toContain('Toglilo dal Wallet');

    // Resta la fine con la sua coppia: Ines 36000 − 18148 = 17852, Ugo 84000 + 18148 = 102148.
    expect(rbmAnnulla($this, $s, $inizio))->toBe('annullato');
    expect(fds50Netti($s))->toBe(['Ines' => 17852, 'Ugo' => 102148]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R7, decisione 83.3: dopo un cambio «regolato fra le parti», la fine «Scrivi» di chi era entrato e chi entra dopo
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il cambio d'inquilino di Ines Moretti a Luca Ferri il 1/7 «regolato fra le parti», nei due modi: la casella di prima della .50
 * (registrata da un modulo rimasto aperto, poi il registro riportato com'era, senza la chiave `conguaglio`, come
 * `pciComePrimaDellaCinquanta`), o il conguaglio scritto e poi annullato. Poi la fine di Luca il 1/9 con «Scrivi».
 */
function rbmDopoIlCambioRegolato($test, string $modo): array
{
    $s = pflScenario();
    pflSeiRateEmesse($test, $s);
    if ($modo === 'casella') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', null, [], ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);
        $registro = $cambio->registro;
        unset($registro['conguaglio']);
        DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
        expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse();
    } else {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
        $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
            ['nota_annullamento_conguaglio' => 'Ines e Luca hanno regolato fra loro alla consegna delle chiavi'])->assertSessionHasNoErrors();
        expect($cambio->fresh()->conguaglioAnnullato())->toBeTrue();
    }
    // Controllo: nei saldi niente, Ines ha le 36000 nelle sue rate.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);
    pflFine($test, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi');

    return $s;
}

it('R7 — dopo il cambio d\'inquilino «regolato fra le parti», la fine di Luca il 1/9 con «Scrivi» e l\'inizio di Anna il 1/11: il prospetto segue la catena, e Anna rimborsa a Ugo i suoi giorni (€ 60,17)', function (string $modo) {
    // La coppia della fine di Luca (Luca −12033, Ugo +12033: 36000 × 122/365 = 12032,88) è coerente con la decisione 43: Luca ha
    // regolato con Ines fuori. La decisione 83.3: chi era entrato «regolato fra le parti» (Luca, 1/7–31/8, 62 giorni: 6115), poi i
    // giorni senza conduttore pagati da chi ha la coppia (Ugo, 1/9–31/10, 61 giorni), poi chi entra, che lo rimborsa (Anna, 1/11–31/12,
    // 61 giorni). 12033 × 61/122 = 6016,5 a ciascuno, a pari resto il centesimo al conduttore che viene prima → Anna 6017, Ugo 6016,
    // come nel cambio scritto (controllo sotto). Ines 1/1–30/6, 181 giorni: 17852. Totale 17852 + 6115 + 6016 + 6017 = 36000. Prima
    // della correzione `ProspettoOneriAccessori::catenaDeiConduttori` si fermava al cambio regolato: il prospetto aveva solo Ines, €
    // 360,00 nelle sue rate, e Anna non rimborsava niente; il pannello dell'inizio diceva «le parti lo hanno regolato fra loro», ma
    // Anna non era parte di quell'accordo.
    $s = rbmDopoIlCambioRegolato($this, $modo);
    $frase = implode(' ', $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => pflPersona($s, 'Anna Neri')->id, 'decorrenza' => '2026-11-01', 'quota' => 100,
        'tipologia' => 'inquilino', 'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => [],
    ])->assertOk()->json('rate.frasi'));
    pflInizio($this, $s, 'Anna Neri', '2026-11-01');
    $p = pflProspetto($s);
    $conduttori = pflConduttori($p);

    // Controllo: i saldi sono quelli della coppia della fine di Luca.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Luca Ferri' => -12033, 'Ugo Rinaldi' => 96033]);

    expect($conduttori['Anna Neri'] ?? null)->toBe([6017, 0, 6017, 61], 'Nel prospetto Anna non rimborsa i suoi giorni: ' . json_encode($conduttori, JSON_UNESCAPED_UNICODE))
        ->and(pflPagatori(pflSezione($p, 'Anna Neri')['voci'] ?? []))->toBe(['Ugo Rinaldi'])
        ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 6016, 61]])
        ->and([($conduttori['Luca Ferri'] ?? [])[0] ?? null, ($conduttori['Luca Ferri'] ?? [])[3] ?? null])->toBe([6115, 62])
        ->and([($conduttori['Ines Moretti'] ?? [])[0] ?? null, ($conduttori['Ines Moretti'] ?? [])[3] ?? null])->toBe([17852, 181])
        ->and($p['totale_inquilino'])->toBe(36000)
        ->and($frase)->not->toContain('le parti lo hanno regolato fra loro')
        ->and($frase)->toContain('a Ugo Rinaldi');
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('R7, il controllo — lo stesso con il cambio d\'inquilino scritto: il prospetto fa già la catena, Anna rimborsa a Ugo € 60,17 e i giorni senza conduttore sono di Ugo', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
    pflFine($this, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi');
    pflInizio($this, $s, 'Anna Neri', '2026-11-01');
    $p = pflProspetto($s);

    expect(pflConduttori($p))->toBe(['Anna Neri' => [6017, 0, 6017, 61], 'Ines Moretti' => [17852, 17852, 0, 181], 'Luca Ferri' => [6115, 0, 0, 62]])
        ->and(pflPagatori(pflSezione($p, 'Anna Neri')['voci']))->toBe(['Ugo Rinaldi'])
        ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 6016, 61]])
        ->and($p['totale_inquilino'])->toBe(36000);
});

// ---------------------------------------------------------------------------------------------------------------------
// Decisione 82.2 e il «€ 0,01»: la seconda vendita dopo una fermata
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La fine di Ines il 1/7 con «Scrivi», la vendita di Ugo a Elsa il 1/9 (fermata, € 120,33 verso Elsa) e quella di Elsa a Carlo il 1/11
 * (decisione 81.2, € 60,16 verso Carlo), registrate, con le rate a giornale fino al 31/10. Le cifre, a mano, sulla coppia 18148 dei 184
 * giorni dopo l'uscita: prima vendita, Ugo 62 giorni, Elsa 122 → 6115,09 / 12032,91 → Ugo 6115, Elsa 12033; seconda, Ugo 62, Elsa
 * 61, Carlo 61 → 6115,09 / 6016,46 / 6016,46, il centesimo a pari resto a chi esce (Elsa) → Ugo 6115, Elsa 6017, Carlo 6016.
 *
 * @return array{0: array, 1: Subentro, 2: Subentro}
 */
function rbmDueVendite($test): array
{
    $s = rbmDopoScrivi($test, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    $prima = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'))['subentro'];
    fclEmetti($test, $s, '2026-10-31');
    $seconda = fds50Registra($test, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'))['subentro'];

    // Controlli: le due fermate nei registri, € 120,33 verso Elsa e € 60,16 verso Carlo; lo storico della seconda dice la sua.
    expect(collect(fds50Fermate($prima))->map(fn ($f) => [(int) $f['cifra'], $f['verso']])->all())->toBe([[12033, ['Elsa']]])
        ->and(collect(fds50Fermate($seconda))->map(fn ($f) => [(int) $f['cifra'], $f['verso']])->all())->toBe([[6016, ['Carlo']]])
        ->and(implode(' ', fds50Storico($s, $seconda)['fermate'] ?? []))->toContain('€ 60,16')->toContain('Carlo');

    return [$s, $prima, $seconda];
}

it('decisione 82.2 — dopo la vendita di Elsa a Carlo il 1/11, lo storico della prima fermata (€ 120,33 verso Elsa) dice la parte passata a Carlo con la seconda vendita, € 60,16', function () {
    // «€ 120,33 verso Elsa, di cui € 60,16 passati a Carlo con la vendita del 1/11», come un nuovo inquilino riduce una fermata. Prima
    // della correzione lo storico della prima vendita diceva solo «€ 120,33 … da regolare a mano con Elsa».
    [$s, $prima] = rbmDueVendite($this);

    $storico = implode(' ', fds50Storico($s, $prima)['fermate'] ?? []);

    expect($storico)->toContain('€ 120,33')
        ->and($storico)->toContain('€ 60,16')
        ->and($storico)->toContain('Carlo')
        ->and($storico)->toContain('1 novembre 2026');
});

it('il «€ 0,01» — dopo le due vendite Luca entra il 1/12: i suoi giorni di dicembre sono di Carlo, e il pannello non dice che della fermata della prima vendita «€ 0,01 vanno restituiti a Elsa»', function () {
    // Con Luca dal 1/12 la coppia si divide su Ugo 62 giorni, Elsa 61, Carlo 30, Luca 31: 6115,09 / 6016,46 / 2958,91 / 3057,54 → 6115 +
    // 6016 + 2958 + 3057 = 18146, i due centesimi ai resti più grandi (Carlo ,91, Luca ,54) → Carlo 2959, Elsa 6016. Luca toglie solo
    // giorni di Carlo: la fermata della seconda vendita scende a € 29,59. La parte di Elsa passa da 6017 a 6016 solo perché il centesimo
    // a pari resto con Carlo non c'è più: è un resto di arrotondamento, non un giorno di Elsa. Prima della correzione la prima fermata
    // diceva «resta da regolare € 120,32 con Elsa. Se Ugo e Elsa hanno già regolato € 120,33, € 0,01 vanno restituiti a Elsa», e un
    // motivo del cancello chiedeva la spunta per quel centesimo. Il residuo 12032 stava anche nella fermata ridotta che la registrazione
    // dell'inizio conserva, e da lì lo leggeva lo storico: non bastava toglierlo dalle frasi.
    [$s] = rbmDueVendite($this);
    fclPersona($s, 'Luca');

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $ridotte = collect($a['rate']['fermate_ridotte']);
    $testo = fclFrasi($a);

    // Controllo: la fermata della seconda vendita scende a € 29,59, i giorni di novembre di Carlo.
    expect((int) ($ridotte->firstWhere('verso', ['Carlo'])['residuo'] ?? -1))->toBe(2959);

    expect($testo)->not->toContain('€ 0,01')
        ->and($testo)->not->toContain('€ 120,32')
        ->and($ridotte->pluck('residuo')->map(fn ($c) => (int) $c)->all())->not->toContain(12032);
});

// ---------------------------------------------------------------------------------------------------------------------
// La fermata dopo una successione con l'arretrato agli eredi: la coppia è passata agli eredi
// ---------------------------------------------------------------------------------------------------------------------

it('la fermata dopo una successione con l\'arretrato agli eredi — Ugo muore il 1/10, Anna vende la sua metà a Carlo il 1/11: i giorni di Carlo (€ 30,08) restano a carico di Anna, che con l\'arretrato ha la sua parte della coppia, non di Ugo', function () {
    // Con l'arretrato agli eredi tutta la posizione di Ugo, coppia della fine compresa, passa ad Anna e Bruno per quota: le righe della
    // successione tolgono a Ugo le nove rate emesse e la coppia (−63000 in saldi contro le sue quote pure). La parte di Carlo, a mano: la
    // coppia 18148 sui 184 giorni, pesi Ugo 92 × 100 (1/7–30/9), Anna 31 × 50 (1/10–31/10), Carlo 61 × 50 (1/11–31/12), Bruno 92 × 50;
    // 18148 × 3050/18400 = 3008,23 → 3008 (€ 30,08). Prima della correzione `FermataDopoScrivi::dopoUnaFermata` cercava chi ha le righe
    // della coppia della fine, trovava Ugo, e diceva «La parte di Anna non è passata con la coppia, ed è rimasta a carico di Ugo … €
    // 30,08 restano a carico di Ugo»; il motivo del cancello lo ripeteva.
    $s = rbmDopoScrivi($this, '2026-09-30');
    foreach (['Anna', 'Bruno', 'Carlo'] as $nome) {
        fclPersona($s, $nome);
    }
    fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, '2026-10-01'));
    fclEmetti($this, $s, '2026-10-31');

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Anna', 'Carlo', '2026-11-01', 50));
    $fermate = collect($a['rate']['fermate_dopo_scrivi']);
    $testo = implode(' | ', [...$a['rate']['frasi'], ...$a['cancello']['motivi']]);

    // Controlli: la posizione di Ugo è passata agli eredi (FermataDopoScriviTest, «successione il 1/10 con l'arretrato agli
    // eredi»: 51074 ciascuno, Ines 17852), e la cifra della fermata è giusta, € 30,08 verso Carlo.
    expect(fds50Netti($s))->toBe(['Anna' => 51074, 'Bruno' => 51074, 'Ines' => 17852])
        ->and($fermate->sum('cifra'))->toBe(3008)
        ->and($fermate->pluck('verso')->flatten()->all())->toBe(['Carlo']);

    expect($fermate->pluck('persona')->all())->toBe(['Anna'])
        ->and($testo)->not->toContain('a carico di Ugo')
        ->and($testo)->not->toContain('La parte di Anna non è passata con la coppia');
});

// ---------------------------------------------------------------------------------------------------------------------
// La strada B del prospetto solo con le righe della coppia: il box senza nessuno al posto dell'inquilino (punto 4 della ripresa)
// ---------------------------------------------------------------------------------------------------------------------

it('il box senza proprietario dopo una fine «Scrivi» — il prospetto e la stampa del box fanno la strada A′ con le cifre giuste: Ines € 17,85, € 18,15 da restituirle; l\'Interno 1 resta la strada B, pagata da Ugo', function () {
    // Presidio delle cifre della correzione del punto 4 della ripresa (`ProspettoOneriAccessori::uscitaDellaLocazione`, la strada B solo
    // con le righe della coppia sull'unità e sulla gestione). Sul box nessuno sta al posto dell'inquilino dopo l'uscita: la coppia non si
    // scrive, e i giorni dopo restano nelle rate di Ines, come con «Non scriverlo». Le cifre del box (100 millesimi): 3600 × [181, 184] /
    // 365 = 1785,21 / 1814,79 → Ines 1785, i giorni dopo 1815. L'Interno 1 (900 millesimi): 32400 × [181, 184] / 365 → Ines 16067, la
    // coppia 16333 a Ugo. Senza la correzione la stampa del box diceva «pagata da» senza nome e Ines con € 17,85 e niente da restituire.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ines', 'inquilino', 100]]]);
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'Box senza proprietario: la parte del proprietario resta scoperta', accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $stampa = fclStampa($s, $s['box']);

    expect(fclSaldi($s, $s['box']))->toBe([])
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => ['ines' => [1785, 3600, -1815, 181]], 'senza' => [1815, 184]])
        ->and(rbmSenza($s, $s['box']))->toBe([['Ines', 1815, 184]])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [16067, 16067, 0, 181]], 'senza' => [16333, 184]])
        ->and(rbmSenza($s))->toBe([['Ugo', 16333, 184]])
        ->and($stampa)->toContain('184 giorni € 18,15 pagata da Ines, rimasta a suo carico')
        ->and($stampa)->toContain('Totale da restituire a chi le ha pagate, fra le parti € 18,15')
        ->and($stampa)->not->toContain('pagata da con');
});
