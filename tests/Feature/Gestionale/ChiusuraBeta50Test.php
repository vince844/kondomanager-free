<?php

/**
 * 1.11.0-beta.50, le ultime correzioni: i quattro punti rimasti dalla revisione finale dell'ultimo giro (verbale
 * `piano_esecutivo_beta50.md`, «L'ultimo giro», «Restano»; decisione 77 per il punto 2): 1. la coppia tutta a credito di chi sta al posto
 * dell'inquilino (un'entrata che nei giorni dopo l'uscita supera la spesa): con il totale delle righe non a debito
 * `FermataDopoScrivi::coppia` restituiva null, e con lei sparivano la parte di chi entra (`partiDiChiEntra`, `rientro`) e la fermata
 * (`per`, `fermateRidotte`). Il prospetto era già giusto (Ugo restituisce € 209,26 a Luca); il blocco «Rate» dell'inizio diceva «li
 * rimborsa Luca a Ugo», il rientro «è già nelle rate di Ines», e la vendita non si fermava: € 209,26 restavano a Ugo in silenzio. La parte
 * di chi entra e la fermata devono funzionare anche così, con il verso giusto; 2. decisione 77: con «Non scriverlo» una voce con la
 * competenza tutta dopo l'uscita prende la strada A′, come le voci che comprendono l'uscita (prima restava «nelle rate» di chi è uscito
 * senza la restituzione: `ProspettoOneriAccessori::fineScrittaPrimaDellaVoce` cercava la fine solo con la strada B), e il blocco «Rate»
 * dice il verso della parte di chi entra, che con l'entrata è un credito; 3. i rami senza test: il ripiego su chi pagava prima in
 * `ProspettoOneriAccessori::pagatoriDopoLUscita`; il primo e il terzo dei tre rami del credito in `AnteprimaPassaggio::dopoLUscitaDiPrima`;
 * il ramo «misto» del blocco «Rate», che non ha il verso del credito (un difetto: diceva «li rimborsa Luca» anche quando gli si
 * restituisce); la guardia della strada B in `fineScrittaPrimaDellaVoce`, che con la correzione è diventata `finePrimaDellaVoce`
 * (sotto); 4. la frase del blocco «Rate» dell'inizio con la coppia di segno misto diceva «i giorni dopo la sua uscita li ha presi con il
 * conguaglio Elsa», mentre dal 1/7 al 31/8 li ha presi Ugo. Deve nominare chi li ha presi per i giorni che dice.
 *
 * La guardia della strada B non era un ramo già giusto: toglierla era la strada A′ del punto 2 (provato in una copia del codice di prima, una
 * mutazione alla volta: senza la guardia il test «punto 2, il prospetto» passava, e quello del blocco «Rate» no, perché è una frase). Il suo
 * test è quindi quello del punto 2. Dopo la correzione resta un confine: con un coinquilino che resta (strada C, decisione 79) la voce tutta dopo
 * l'uscita resta nelle rate di chi è uscito, senza restituzione e senza nessuno che la paghi: lo tiene «punto 3, il confine della strada
 * A′», verde, che torna rosso se la strada A′ prende anche il coinquilino (provato con quella mutazione; togliere la sola guardia non lo
 * cambia, perché la strada C lascia il denaro dov'è e aggiunge solo la nota).
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`, `ScenariProspettoLocazione.php` e `FermataDopoScriviHelpers.php`: «Spese generali»
 * € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate
 * mensili; Ugo proprietario e Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181,
 * 184] / 365 = 17852,05 / 18147,95 → 17852 e la coppia delle spese 18148 (€ 181,48). I capitoli in più sono sulla stessa tabella con la
 * stessa divisione: un'entrata di € 400,00 dà all'inquilino 40000 × 30 % = 12000 di credito, una di € 1.000,00 30000. I giorni: 1/7–30/9 e
 * 1/10–31/12 92 ciascuno, 1/12–31/12 31, 1/10–30/11 61, 1/7–31/8 62, 1/9–31/12 122, 1/9–30/9 30. Gli oracoli sono fatti a mano, in frazioni
 * esatte, accanto a ogni test: la coppia con la sua regola (le parti intere, il centesimo al resto più grande, a pari resto a chi esce), il
 * prospetto voce per voce, ogni voce divisa per giorni con i resti maggiori (`MoneyHelper::ripartisciPerQuote`: a pari resto vince chi viene
 * prima, i giorni del conduttore prima di quelli senza).
 *
 * Nati come test rossi di questo giro: ognuno descrive un punto corretto prima del commit, con accanto un controllo (la stessa scena nei
 * pezzi che il codice faceva già giusti, o il caso vicino) che dice che il rosso non veniva dal test. Le guardie del punto 3 tengono un
 * ramo già giusto che nessun test teneva, e tornano rosse togliendo quel ramo (provato con una mutazione per ramo, una alla volta, in una
 * copia).
 *
 * Che cosa NON copre:
 * - le frasi parola per parola: si cercano la cifra, il nome e il verso (chi entra riceve: «a Luca», «Luca li riceve», «a credito di Luca»,
 *   «gli restituisce»; chi entra non rimborsa: niente «li rimborsa Luca», «Luca glieli rimborsa»), e che non ci sia «€ -»; la frase intera la
 *   scrive chi corregge. Del blocco «Rate» si leggono le frasi dei giorni, senza la prima («restano intestate a Ines e Ugo») e l'ultima («Un
 *   piano generato dopo intesterà quelle voci a …»), che nominano le persone per un'altra ragione; nel ramo «misto» con le due parti di segno
 *   diverso si cerca che ci siano tutti e due i versi, e che chi restituisce non sia fra quelli che Luca rimborsa, non a quale parte la frase
 *   leghi ciascun verso;
 * - punto 1: la stampa del prospetto (si legge il servizio); la coppia tutta a credito nelle fermate degli altri passaggi (la costituzione e
 *   l'estinzione dell'usufrutto, la successione con l'arretrato al defunto, la seconda vendita della 81.2 e della 82.1), nella fermata del
 *   coinquilino, sul box e nel cambio d'inquilino in un passo; il cancello e lo storico del rientro;
 * - punto 2: la fine senza la scelta (registrata prima della .50) e il piano che la coppia non ha preso, che passano dalla stessa guardia e
 *   per le decisioni prese hanno la strada A′ (misurati: con l'«Affitto antenna» € 400,00 a competenza 1/10–31/12 e una fine senza la scelta
 *   Ines [5852, 24000, −18148] senza la restituzione dell'entrata, come «Non scriverlo»; con il «Riscaldamento 2026» generato prima della fine
 *   e non preso dalla coppia, «Combustibile» € 730,00 a competenza 1/10–31/12 resta nelle rate di Ines, 73000 per 92 giorni, senza
 *   restituzione); il rientro di chi è uscito con «Non scriverlo»; il cambio d'inquilino in un passo con «Non scriverlo»;
 * - punto 3: il terzo ramo del credito esce solo senza il nome di chi ha la coppia (un'anagrafica senza nome, costruita apposta): con dati
 *   validi chi ha preso la coppia ha sempre un nome; il prospetto del caso del primo ramo (il cambio d'inquilino in un passo prima dei giorni
 *   della voce), che lascia l'entrata nelle rate di Ines (misurato: Ines [−3123, −3123, 0], Luca [9074, 0, 9074] invece di [−2926, 0, −2926])
 *   ed è fuori da questo giro;
 * - punto 4: lo stesso nome nel secondo ramo del credito («i giorni dopo la sua uscita li ha presi con il conguaglio …; dal giorno
 *   dell'inizio …»), che con una coppia di segno misto avrebbe la stessa imprecisione; una frase che dica chi rimborsa prima di chi li ha
 *   presi (il predicato legge il pezzo prima del rimborso, come le altre frasi);
 * - il centesimo fra la fermata ridotta (coppia intera) e il prospetto (voce per voce), dentro la tolleranza dichiarata di un centesimo per
 *   unità; G20, il piano della 1.10, le pagine Vue, i PDF resi da browser, MySQL.
 */

use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
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
 * Un capitolo del piano a capitoli, sulla stessa tabella e con la stessa divisione delle «Spese generali» (30 % «Inquilino», 70 %
 * «Proprietario»), con la competenza data; le «Spese generali» € 1.200,00 per tutto l'anno restano un capitolo. Prima della generazione.
 * È `ugbCapitolo` di `UltimoGiroBeta50Test`: sta in un file di test e non si può includere.
 */
function cb50Capitolo(array $s, string $nome, string $tipo, int $importo, string $dal, string $al): void
{
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $conto->id => ['importo' => $importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $conto->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
}

/**
 * La fine di Ines del 1/7 con la scelta data (di partenza «Scrivi»): il piano con il capitolo dato (di partenza l'«Affitto antenna»
 * € 1.000,00 di entrata a competenza 1/10–31/12, tutta dopo l'uscita; senza capitolo il piano di sempre), generato, sei rate a giornale prima
 * della fine e poi le rate a giornale fino al 30/9.
 *
 * @param array{0: string, 1: string, 2: int, 3: string, 4: string}|null $capitolo [nome, tipo, importo, dal, al]
 */
function cb50DopoLaFine($test, ?array $capitolo = ['Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31'], string $scelta = 'scrivi'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    if ($capitolo !== null) {
        cb50Capitolo($s, ...$capitolo);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => $scelta]);
    fclEmetti($test, $s, '2026-09-30');

    return $s;
}

/** I pezzi della coppia nel registro della fine: [dal, al, importo], uno per voce. */
function cb50Pezzi(Subentro $fine): array
{
    return collect($fine->fresh()->registro['conguaglio']['periodi'][0]['periodi'] ?? [])->map(fn ($p) => [$p['tratti'][0]['dal'], $p['tratti'][0]['al'], (int) $p['importo']])->all();
}

/** Le voci di un conduttore nel prospetto, senza quelle a zero: [voce, modo, pagata da, importo, giorni], in ordine. */
function cb50Voci(array $p, string $nome): array
{
    return collect(pflSezione($p, $nome)['voci'] ?? [])->filter(fn ($v) => (int) $v['importo'] !== 0)
        ->map(fn ($v) => [(string) $v['conto'], (string) $v['modo'], $v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => $v[0] . '|' . $v[1] . '|' . $v[3])->values()->all();
}

/** Le voci di un conduttore di una sola voce del piano. */
function cb50VociDi(array $p, string $nome, string $voce): array
{
    return array_values(array_filter(cb50Voci($p, $nome), fn (array $v) => $v[0] === $voce));
}

/** I totali della sezione di un conduttore: [totale, nelle sue rate, da rimborsare]. Positivo: il conduttore deve; negativo: gli si deve. */
function cb50Totali(array $p, string $nome): ?array
{
    $c = pflSezione($p, $nome);

    return $c === null ? null : [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare']];
}

/** Le voci «senza conduttore», senza quelle a zero: [pagata da, voce, importo, giorni], in ordine. */
function cb50Senza(array $p): array
{
    return collect($p['senza_conduttore']['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)
        ->map(fn ($v) => [$v['pagato_da'] ?? null, (string) $v['conto'], (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0] . '|' . $v[1] . '|' . $v[2])->values()->all();
}

/** Le fermate dopo «Scrivi» di un'anteprima o di un registro, in breve: [cifra, verso]. */
function cb50Fermate(array $fermate): array
{
    return collect($fermate)->map(fn ($f) => [(int) $f['cifra'], array_values((array) $f['verso'])])->all();
}

/** Le frasi di un testo divise al punto seguito da una maiuscola (non ai due punti: una frase con il suo verso resta intera). */
function cb50Periodi(string $testo): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=\.)\s+(?=[A-ZÈ«])/u', $testo)), fn ($p) => $p !== ''));
}

/** La frase dice che chi entra paga i giorni a chi li ha presi: «li rimborsa Luca», «Luca glieli rimborsa», «Ines gliela rimborsa». */
function cb50ChiEntraRimborsa(string $t, string $chi): bool
{
    $c = preg_quote($chi, '/');

    return preg_match('/li rimborsa ' . $c . '\b|\b' . $c . ' (?:glieli |gliela |gli |li |la )?rimborsa\b/u', $t) === 1;
}

/**
 * La frase dice che chi entra riceve: «… a Luca», «Luca li riceve», «riceve Luca», «a credito di Luca», «a favore di Luca», «gli
 * restituisce». Si cerca il verso, non le parole della frase.
 */
function cb50ChiEntraRiceve(string $t, string $chi): bool
{
    $c = preg_quote($chi, '/');

    return preg_match('/\bad? ' . $c . '\b|\b' . $c . ' (?:li |lo |gli |ne )?ricev|\bricev\w* ' . $c . '\b|\bcredito di ' . $c . '\b|\ba favore di ' . $c . '\b|\bgli (?:si )?restituisc|\bgli (?:va|vanno|spetta|spettano)\b/u', $t) === 1;
}

/**
 * Il blocco «Rate» del pannello dell'inizio sui piani fermi, senza la frase finale («Un piano generato dopo intesterà quelle voci a …»), che
 * nomina chi entra per un'altra ragione, e senza la prima («Le … quote già emesse … restano intestate a Ines e Ugo»), che nomina chi ha le
 * quote: «a Ines» lì direbbe che Ines riceve anche quando la frase dei suoi giorni non lo dice.
 */
function cb50BloccoRate(array $anteprima): ?string
{
    $blocco = collect($anteprima['rate']['frasi'] ?? [])->first(fn (string $f) => str_contains($f, 'La parte a carico dell\'inquilino dal'));

    return $blocco === null ? null : implode(' ', array_filter(cb50Periodi($blocco),
        fn (string $p) => ! str_starts_with($p, 'Un piano generato dopo') && ! str_contains($p, 'quote già emesse')));
}

/**
 * Del blocco «Rate», la sola frase che dice di chi è la parte a carico dell'inquilino dal giorno dell'inizio («La parte a carico
 * dell'inquilino dal … resta nelle rate di …: …»), senza la prima, che elenca a chi sono intestate le quote emesse.
 */
function cb50FraseDelleRate(array $anteprima): ?string
{
    return collect(cb50Periodi((string) cb50BloccoRate($anteprima)))->first(fn (string $p) => str_starts_with($p, 'La parte a carico dell\'inquilino dal'));
}

/** L'informazione del cancello che ripete il blocco «Rate» del piano fermo, o null. */
function cb50Informazione(array $anteprima): ?string
{
    return collect($anteprima['cancello']['informazioni'] ?? [])->first(fn ($t) => is_string($t) && str_contains($t, 'la parte a carico dell\'inquilino dal'));
}

/** Il primo testo che contiene il frammento (la cifra di una fermata, di solito), o null. */
function cb50Con(array $testi, string $frammento): ?string
{
    return collect($testi)->first(fn ($t) => is_string($t) && str_contains($t, $frammento));
}

/**
 * Il saldo manuale dal Wallet che le fermate di un passaggio indicano, scritto come lo scrive il Wallet: a credito di chi resta a carico
 * per la cifra e a debito di ciascuno che riceve per la sua parte, con il segno della cifra. Poi i saldi manuali per persona.
 *
 * @return array<string, int>
 */
function cb50WalletDelleFermate(array $s, Subentro $passaggio): array
{
    foreach (fds50Fermate($passaggio) as $f) {
        fclRiporto($s, (string) $f['persona'], -(int) $f['cifra']);
        foreach ($f['parti'] as $p) {
            fclRiporto($s, (string) $p['nome'], (int) $p['cifra']);
        }
    }
    $nomi = fclNomi($s);
    $out = [];
    foreach (Saldo::whereNull('subentro_id')->where('origine', 'manuale')->where('immobile_id', $s['unita']->id)->get() as $x) {
        $chi = $nomi[(int) $x->anagrafica_id] ?? "anagrafica {$x->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $x->saldo_iniziale;
    }
    ksort($out);

    return $out;
}

/**
 * Punto 4: la frase nomina chi ha preso con il conguaglio i giorni che dice. Si legge il pezzo prima di chi rimborsa o restituisce (la
 * frase dice prima chi li ha presi, poi chi li rimborsa, come tutte quelle del blocco): il nome di chi riceve il rimborso non vale come nome
 * di chi li ha presi («li ha presi con il conguaglio Ugo, e li rimborsa Luca a Elsa» non nomina Elsa fra chi li ha presi). Se parla dei
 * giorni dopo l'uscita, nomina tutti quelli che li hanno presi (`$dopoLUscita`); se parla solo dei giorni dall'inizio della locazione, chi
 * ha preso quelli (`$dallInizio`).
 *
 * @param list<string> $dopoLUscita
 * @param list<string> $dallInizio
 */
function cb50NominaChiLiHaPresi(string $frase, array $dopoLUscita, array $dallInizio): bool
{
    $presi = preg_split('/\brimborsa|\brestituisc/u', $frase, 2)[0];
    $nomi = preg_match('/dopo (?:la sua|la loro) uscita|dopo l\'uscita/u', $presi) === 1 ? $dopoLUscita : $dallInizio;

    return collect($nomi)->every(fn (string $n) => preg_match('/\b' . preg_quote($n, '/') . '\b/u', $presi) === 1);
}

/** Chi la frase fa rimborsare a Luca: i nomi dopo «li rimborsa Luca a», fino alla virgola o al punto. */
function cb50RimborsatiDaLuca(string $frase): array
{
    preg_match_all('/li rimborsa Luca ad? ([^,.;:]+)/u', $frase, $m);

    return array_values(array_unique(array_map('trim', $m[1])));
}

/**
 * Lo scenario di G2 (è `ugbCoppiaDiSegnoMisto`): il piano a capitoli con l'«Affitto antenna» € 1.000,00 di entrata a competenza 1/1–30/9, a
 * giornale fino al 31/8; la vendita di Ugo a Elsa del 1/9 registrata prima, poi la fine di Ines del 1/7 con «Scrivi». I pezzi della coppia:
 * le spese 18148 sul 1/7–31/12; l'entrata all'inquilino 100000 × 30 % = 30000 sui 273 giorni della competenza, di cui 92 dopo l'uscita:
 * 30000 × 92/273 = 10109,89 → −10110 sul 1/7–30/9. Le parti: Ugo, 1/7–31/8 (62 giorni dei due pezzi), 18148 × 62/184 − 10110 × 62/92 =
 * 6115,09 − 6813,26 = −698,17; Elsa, 1/9–31/12 delle spese (122) e 1/9–30/9 dell'entrata (30), 12032,91 − 3296,74 = 8736,17.
 */
function cb50CoppiaDiSegnoMisto($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    cb50Capitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-09-30');

    return $s;
}

/** La coppia di segno misto, poi la vendita di Elsa a Carlo il 1/11 (fermata), le rate a giornale fino al 30/11, e Luca nel condominio. */
function cb50ElsaVendeACarlo($test): array
{
    $s = cb50CoppiaDiSegnoMisto($test);
    fclPersona($s, 'Carlo');
    fclPersona($s, 'Luca');
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'))['subentro'];
    fclEmetti($test, $s, '2026-11-30');

    return $s;
}

/**
 * Lo scenario del ramo «misto»: il piano con il capitolo dato (senza capitolo il piano di sempre), generato, sei rate a giornale, la riga di
 * Ugo chiusa al 30/9, la fine di Ines del 1/7 con «Scrivi», le rate a giornale fino al 31/8, e Luca nel condominio. La coppia copre i giorni
 * in cui Ugo sta al posto dell'inquilino, 1/7–30/9 (R2); dal 1/10 nessuno, e quei giorni restano nelle rate di Ines.
 *
 * @param array{0: string, 1: string, 2: int, 3: string, 4: string}|null $capitolo [nome, tipo, importo, dal, al]
 */
function cb50UgoFinoAl30Settembre($test, ?array $capitolo): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    if ($capitolo !== null) {
        cb50Capitolo($s, ...$capitolo);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Luca');

    return $s;
}

// ---------------------------------------------------------------------------------------------------------------------
// Punto 1: la coppia tutta a credito di chi sta al posto dell'inquilino
// ---------------------------------------------------------------------------------------------------------------------

it('punto 1, il nuovo conduttore — l\'«Affitto antenna» € 1.000,00 di entrata a competenza 1/10–31/12 e la fine di Ines del 1/7 con «Scrivi» danno a Ugo una coppia tutta a credito; Luca entra il 1/10: il prospetto dice che Ugo gli restituisce € 209,26, e il blocco «Rate» deve dire lo stesso verso, non «li rimborsa Luca a Ugo»', function () {
    // La coppia, pezzo per pezzo: le spese 18148 sul 1/7–31/12; l'entrata all'inquilino, tutta dopo l'uscita, −30000 sul 1/10–31/12. A Ugo
    // 18148 − 30000 = −11852: un credito (ha preso con la coppia anche l'entrata, che supera la spesa), e Ines +11852. Luca dal 1/10, 92
    // giorni: le spese 18148 × 92/184 = 9074, l'entrata −30000 (tutti i suoi giorni): 9074 − 30000 = −20926, un credito di Luca, che Ugo
    // gli restituisce. Il prospetto lo diceva già (misura S6). Prima della correzione `FermataDopoScrivi::coppia` scartava una coppia con
    // il totale delle righe non a debito: `partiDiChiEntra` non dava la parte di Luca, e il blocco «Rate» prendeva il verso della spesa.
    $s = cb50DopoLaFine($this);
    fclPersona($s, 'Luca');
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $rate = cb50BloccoRate($a);
    $informazione = cb50Informazione($a);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: la coppia e i suoi pezzi; il prospetto, che dice già il credito di Luca; il blocco e l'informazione del cancello che ci
    // sono e nominano chi ha la coppia; il caso vicino con l'antenna € 400,00 (coppia a debito di Ugo, 18148 − 12000 = 6148, e la parte di Luca
    // 9074 − 12000 = −2926), dove lo stesso blocco dice già che Luca riceve.
    expect(fclSaldi($s))->toBe(['Ines' => 11852, 'Ugo' => -11852])
        ->and(cb50Pezzi($s['fine']))->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-10-01', '2026-12-31', -30000]])
        ->and(cb50Voci($p, 'Luca'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -30000, 92], ['Spese generali', 'proprietario', 'Ugo', 9074, 92]])
        ->and(cb50Totali($p, 'Luca'))->toBe([-20926, 0, -20926])
        ->and(cb50Voci($p, 'Ines'))->toBe([['Spese generali', 'rate', null, 17852, 181]])
        ->and(cb50Senza($p))->toBe([['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(6000)
        ->and([$rate, $informazione])->each->not->toBeNull()
        ->and((string) $rate)->toContain('Ugo');
    $vicino = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31']);
    fclPersona($vicino, 'Luca');
    $rateVicino = (string) cb50BloccoRate(fclAnteprima($this, $vicino, fds50ModuloInizio($vicino, 'Luca', '2026-10-01')));
    expect(fclSaldi($vicino))->toBe(['Ines' => -6148, 'Ugo' => 6148])
        ->and(cb50ChiEntraRiceve($rateVicino, 'Luca'))->toBeTrue('Il caso vicino non dice che Luca riceve: ' . $rateVicino)
        ->and(cb50ChiEntraRimborsa($rateVicino, 'Luca'))->toBeFalse();

    foreach (['il blocco «Rate»' => $rate, 'l\'informazione del cancello' => $informazione] as $dove => $t) {
        expect(cb50ChiEntraRimborsa((string) $t, 'Luca'))->toBeFalse(sprintf('In %s Luca rimborsa a Ugo un suo credito: %s', $dove, $t))
            ->and(cb50ChiEntraRiceve((string) $t, 'Luca'))->toBeTrue(sprintf('In %s non si dice che Luca riceve: %s', $dove, $t))
            ->and((string) $t)->not->toContain('€ -');
    }
});

it('punto 1, il rientro — la stessa coppia tutta a credito, e Ines rientra il 1/10: il prospetto dice che Ugo le restituisce € 209,26, e il pannello deve dirlo, non che la parte dal 1/10 «è già nelle rate di Ines»', function () {
    // I giorni di Ines dal 1/10 sono quelli di Luca nel test sopra: 9074 − 30000 = −20926. Il prospetto: Ines totale 17852 + 9074 − 30000 =
    // −3074; nelle sue rate 17852; da rimborsare −20926: Ugo le restituisce € 209,26. Prima della correzione `rientro` passava da `coppia`,
    // che scartava la coppia: `rinnovo_dopo_scrivi` era vuoto, e il blocco diceva «La parte a carico dell'inquilino dal 1 ottobre 2026 è
    // già nelle rate di Ines», che era falso: la coppia quei giorni li ha portati a Ugo, entrata compresa.
    $s = cb50DopoLaFine($this);
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $rate = (string) cb50BloccoRate($a);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: il prospetto del rientro; il caso vicino con l'antenna € 400,00, dove il rientro dice già il credito di Ines (G1):
    // [−2926, Ugo], e il blocco dice che Ugo le restituisce la differenza.
    expect(cb50Voci($p, 'Ines'))->toBe([
        ['Affitto antenna', 'proprietario', 'Ugo', -30000, 92], ['Spese generali', 'proprietario', 'Ugo', 9074, 92], ['Spese generali', 'rate', null, 17852, 181],
    ])
        ->and(cb50Totali($p, 'Ines'))->toBe([-3074, 17852, -20926])
        ->and($rate)->not->toBe('');
    $vicino = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31']);
    $aVicino = fclAnteprima($this, $vicino, fds50ModuloInizio($vicino, 'Ines', '2026-10-01'));
    $rateVicino = (string) cb50BloccoRate($aVicino);
    expect(collect($aVicino['rate']['rinnovo_dopo_scrivi'])->map(fn ($f) => [(int) $f['cifra'], $f['pagato_da']])->all())->toBe([[-2926, ['Ugo']]])
        ->and(cb50ChiEntraRiceve($rateVicino, 'Ines'))->toBeTrue('Il caso vicino non dice che Ines riceve: ' . $rateVicino)
        ->and(cb50ChiEntraRimborsa($rateVicino, 'Ines'))->toBeFalse();

    expect(collect($a['rate']['rinnovo_dopo_scrivi'])->map(fn ($f) => [(int) $f['cifra'], $f['pagato_da']])->all())
        ->toBe([[-20926, ['Ugo']]], 'Il rientro non dice il credito di Ines, € 209,26 da Ugo: ' . json_encode($a['rate']['rinnovo_dopo_scrivi'], JSON_UNESCAPED_UNICODE))
        ->and($rate)->not->toContain('è già nelle rate di Ines')
        ->and(cb50ChiEntraRiceve($rate, 'Ines'))->toBeTrue('Il blocco «Rate» non dice che Ines riceve: ' . $rate)
        ->and(cb50ChiEntraRimborsa($rate, 'Ines'))->toBeFalse('Il blocco «Rate» fa rimborsare a Ines un suo credito: ' . $rate)
        ->and($rate)->toContain('Ugo')
        ->and($rate)->not->toContain('€ -');
});

it('punto 1, la fermata — la stessa coppia tutta a credito, e Ugo vende a Elsa il 1/10: la vendita si ferma su € 209,26 di credito rimasti a Ugo, verso Elsa, con il saldo manuale a debito di Ugo e a credito di Elsa', function () {
    // Elsa dal 1/10 sta al posto dell'inquilino nei giorni che nel test sopra erano di Luca: 9074 − 30000 = −20926; Ugo resta con il
    // 1/7–30/9, 18148 × 92/184 = 9074. La coppia, −11852, si divide esatta: Ugo 9074, Elsa −20926. La coppia non si sposta (decisione
    // 77.3): il credito di Elsa resta a Ugo, e la vendita lo dice. Prima della correzione `per` passava da `coppieScritte` e da `coppia`,
    // che scartava la coppia: nessuna fermata, € 209,26 fra Ugo ed Elsa in silenzio.
    $s = cb50DopoLaFine($this);
    fclPersona($s, 'Elsa');
    $vendita = fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');
    $a = fclAnteprima($this, $s, $vendita);
    $f = $a['rate']['fermate_dopo_scrivi'];

    // Controlli: la coppia; il caso vicino con l'antenna € 400,00, dove la vendita si ferma già su −€ 29,26 verso Elsa (G1), con la
    // frase e il saldo manuale nel verso del credito.
    expect(fclSaldi($s))->toBe(['Ines' => 11852, 'Ugo' => -11852]);
    $vicino = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31']);
    fclPersona($vicino, 'Elsa');
    $aVicino = fclAnteprima($this, $vicino, fds50ModuloVendita($vicino, 'Ugo', 'Elsa', '2026-10-01'));
    $fraseVicino = (string) cb50Con($aVicino['rate']['frasi'], '€ 29,26');
    expect(cb50Fermate($aVicino['rate']['fermate_dopo_scrivi']))->toBe([[-2926, ['Elsa']]])
        ->and($fraseVicino)->toContain('a debito di Ugo')
        ->and($fraseVicino)->toContain('a credito di Elsa');
    $passaggioVicino = fds50Registra($this, $vicino, fds50ModuloVendita($vicino, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(cb50WalletDelleFermate($vicino, $passaggioVicino))->toBe(['Elsa' => -2926, 'Ugo' => 2926]);

    expect(cb50Fermate($f))->toBe([[-20926, ['Elsa']]], 'La vendita non si ferma sul credito di Elsa, −€ 209,26: ' . json_encode($f, JSON_UNESCAPED_UNICODE))
        ->and([$f[0]['persona'] ?? null, (int) ($f[0]['coppia'] ?? 0)])->toBe(['Ugo', -11852]);

    // Il pannello e il cancello lo dicono, con il verso del credito.
    $frase = (string) cb50Con($a['rate']['frasi'], '€ 209,26');
    expect($frase)->toContain('a debito di Ugo')
        ->and($frase)->toContain('a credito di Elsa')
        ->and($frase)->not->toContain('€ -')
        ->and((string) cb50Con($a['cancello']['motivi'], '€ 209,26'))->toContain('Elsa');

    // Registrata: il registro conserva la fermata, lo storico la dice, e il saldo manuale che indica porta a Elsa il suo credito.
    $passaggio = fds50Registra($this, $s, $vendita)['subentro'];
    expect(cb50Fermate(fds50Fermate($passaggio)))->toBe([[-20926, ['Elsa']]])
        ->and((string) cb50Con(fds50Storico($s, $passaggio)['fermate'] ?? [], '€ 209,26'))->toContain('Elsa')
        ->and(cb50WalletDelleFermate($s, $passaggio))->toBe(['Elsa' => -20926, 'Ugo' => 20926]);
});

it('punto 1, la fermata ridotta — dopo la vendita fermata su −€ 209,26 verso Elsa, Luca entra il 1/12: ne restano da regolare con Elsa € 138,75 di credito, e la frase del pannello, il motivo del cancello, lo storico e il blocco «Rate» dicono che Luca riceve', function () {
    // Prima di Luca le parti della coppia sono Ugo 9074 ed Elsa −20926 (test sopra). Luca dal 1/12, 31 giorni: le spese 18148 × 31/184 =
    // 562588/184 = 3057,54, l'entrata −30000 × 31/92 = −930000/92 = −10108,70: −7051,15. Elsa resta con il 1/10–30/11, 61 giorni:
    // 1107028/184 − 1830000/92 = 6016,46 − 19891,30 = −13874,85. La divisione di −11852 sui pesi 9074 / −13874,85 / −7051,15: le parti
    // intere 9074 / −13874 / −7051 sommano −11851, e il centesimo che manca va al resto più grande (Elsa, ,85) → Elsa −13875, Luca −7051.
    // La fermata scende da −20926 a −13875 (€ 138,75 di credito con Elsa), e i giorni di Luca, −7051, sono un suo credito: Ugo (o Elsa, se
    // ha già regolato con lui) gli restituisce la differenza. Il prospetto (controllo): Luca 3058 − 10109 = −7051 (le spese 3057,54 → 3058,
    // il centesimo al resto più grande, ,54 contro ,46 dei giorni senza conduttore; l'entrata −10108,70 → −10109, ,70 contro ,30).
    $s = cb50DopoLaFine($this);
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    fclEmetti($this, $s, '2026-11-30');
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'))['subentro'];
    $storico = fds50Storico($s, $inizio);
    $p = pflProspetto($s);

    // Controlli: il prospetto di Luca, che dice già il suo credito, e il blocco «Rate» che c'è e nomina chi ha la coppia.
    expect(cb50Voci($p, 'Luca'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -10109, 31], ['Spese generali', 'proprietario', 'Ugo', 3058, 31]])
        ->and(cb50Totali($p, 'Luca'))->toBe([-7051, 0, -7051])
        ->and((string) cb50BloccoRate($a))->toContain('Ugo');

    expect(collect($a['rate']['fermate_ridotte'])->map(fn ($f) => [(int) $f['cifra'], (int) $f['residuo']])->all())
        ->toBe([[-20926, -13875]], 'La fermata della vendita non si riduce con l\'inizio di Luca: ' . json_encode($a['rate']['fermate_ridotte'], JSON_UNESCAPED_UNICODE));
    foreach ([
        'la frase del pannello' => cb50Con($a['rate']['frasi'], '€ 138,75'), 'il motivo del cancello' => cb50Con($a['cancello']['motivi'], '€ 138,75'),
        'lo storico' => cb50Con($storico['fermate'] ?? [], '€ 138,75'), 'il blocco «Rate»' => cb50BloccoRate($a),
    ] as $dove => $t) {
        expect($t)->not->toBeNull(sprintf('Manca %s', $dove))
            ->and(cb50ChiEntraRimborsa((string) $t, 'Luca'))->toBeFalse(sprintf('In %s Luca rimborsa i giorni che sono un suo credito: %s', $dove, $t))
            ->and(cb50ChiEntraRiceve((string) $t, 'Luca'))->toBeTrue(sprintf('In %s non si dice che Luca riceve: %s', $dove, $t))
            ->and((string) $t)->not->toContain('€ -');
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 2: «Non scriverlo» con una voce tutta dopo l'uscita, la strada A′ (decisione 77)
// ---------------------------------------------------------------------------------------------------------------------

it('punto 2, il prospetto — con «Non scriverlo» l\'«Affitto antenna» € 400,00 di entrata a competenza 1/10–31/12 prende la strada A′ come le spese: Ines la restituisce, e con Luca dal 1/10 Ines gli restituisce € 29,26 invece di farsi rimborsare € 90,74', function () {
    // «Non scriverlo»: nessuna coppia, le rate restano a Ines, e i giorni dopo l'uscita si regolano con il prospetto (strada A′). Le spese
    // lo fanno già: 18148 nelle rate di Ines dopo l'uscita, e la restituzione −18148. L'entrata all'inquilino, −12000, è tutta dopo
    // l'uscita: nelle rate di Ines, e da restituire anche lei, +12000 (il credito non è suo). Ines da sola: totale 17852 (le spese dei suoi
    // 181 giorni; l'entrata non è sua); nelle sue rate 17852 + 18148 − 12000 = 24000; da rimborsare −18148 + 12000 = −6148. Senza
    // conduttore, da restituire fra le parti: le spese 18148 per 184 giorni e l'entrata −12000 per 92, pagate da Ines. Luca dal 1/10: le
    // spese 18148 × 92/184 = 9074 e l'entrata −12000, tutte e due nelle rate di Ines: −2926, che Ines gli restituisce. Prima della
    // correzione `fineScrittaPrimaDellaVoce` cercava la fine solo con la strada B: l'entrata restava «nelle rate» di Ines, −12000 per 92
    // giorni, senza la restituzione; Ines [5852, 24000, −18148] tratteneva € 120,00 di credito dei giorni dopo l'uscita, e Luca [9074, 0,
    // 9074] rimborsava € 90,74.
    $s = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'], 'non_scrivere');
    $prima = pflProspetto($s);
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $p = pflProspetto($s);
    $modi = fn (array $p) => array_map(fn (array $v) => [$v[1], $v[3]], cb50VociDi($p, 'Ines', 'Affitto antenna'));

    // Controlli: nessuna coppia, la scelta nel registro; le spese, che prendono già la strada A′; il totale del ruolo inquilino; le
    // spese di Luca, pagate da Ines; il caso vicino con l'antenna a competenza 1/1–31/12, che comprende l'uscita, e prende già la strada A′:
    // −12000 × 181/365 = −5950,68 → −5951 nelle rate di Ines prima dell'uscita, −6049 dopo, da restituire; Luca ne ha metà, 6049 / 2 =
    // 3024,5, a pari resto il centesimo ai giorni del conduttore → −3025, senza conduttore −3024; Luca 9074 − 3025 = 6049.
    expect(fclSaldi($s))->toBe([])
        ->and($s['fine']->fresh()->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        ->and(cb50VociDi($prima, 'Ines', 'Spese generali'))->toBe([['Spese generali', 'rate', null, 17852, 181], ['Spese generali', 'rate', null, 18148, null], ['Spese generali', 'restituzione', null, -18148, null]])
        ->and((int) $p['totale_inquilino'])->toBe(24000)
        ->and(cb50VociDi($p, 'Luca', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ines', 9074, 92]]);
    $vicino = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-01-01', '2026-12-31'], 'non_scrivere');
    fclPersona($vicino, 'Luca');
    fds50Registra($this, $vicino, fds50ModuloInizio($vicino, 'Luca', '2026-10-01'));
    $pVicino = pflProspetto($vicino);
    expect($modi($pVicino))->toBe([['rate', -5951], ['rate', -6049], ['restituzione', 6049]])
        ->and(cb50VociDi($pVicino, 'Luca', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ines', -3025, 92]])
        ->and(cb50Totali($pVicino, 'Luca'))->toBe([6049, 0, 6049])
        ->and(cb50Totali($pVicino, 'Ines'))->toBe([11901, 24000, -12099]);

    // Ines da sola, prima di Luca.
    expect($modi($prima))->toBe([['rate', -12000], ['restituzione', 12000]], 'L\'entrata dei giorni dopo l\'uscita resta nelle rate di Ines senza la restituzione: ' . json_encode(cb50Voci($prima, 'Ines'), JSON_UNESCAPED_UNICODE))
        ->and(cb50Totali($prima, 'Ines'))->toBe([17852, 24000, -6148])
        ->and(cb50Senza($prima))->toBe([['Ines', 'Affitto antenna', -12000, 92], ['Ines', 'Spese generali', 18148, 184]]);

    // Con Luca.
    expect(cb50VociDi($p, 'Luca', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ines', -12000, 92]], 'L\'entrata dei giorni di Luca non è nella sua sezione: ' . json_encode(['Luca' => cb50Voci($p, 'Luca'), 'Ines' => cb50Voci($p, 'Ines')], JSON_UNESCAPED_UNICODE))
        ->and(cb50Totali($p, 'Luca'))->toBe([-2926, 0, -2926])
        ->and($modi($p))->toBe([['rate', -12000], ['restituzione', 12000]])
        ->and(cb50Totali($p, 'Ines'))->toBe([17852, 24000, -6148])
        ->and(cb50Senza($p))->toBe([['Ines', 'Spese generali', 9074, 92]]);
});

it('punto 2, il blocco «Rate» — lo stesso caso, l\'anteprima dell\'inizio di Luca il 1/10: i giorni di Luca sono un suo credito (−€ 29,26), e il pannello non dice «li rimborsa Luca a Ines», ma che Ines gli restituisce la differenza', function () {
    // Il ramo «non scritto» di `AnteprimaPassaggio::dopoLUscitaDiPrima` ha un verso solo: «i giorni dopo la sua uscita li rimborsa Luca a
    // Ines». Con l'antenna a competenza 1/10–31/12 la parte di Luca è un credito, 9074 − 12000 = −2926 (test sopra). I due controlli hanno
    // la parte di Luca a debito, e la frase è giusta: l'antenna a competenza 1/1–31/12, 9074 − 3025 = 6049; l'antenna € 200,00 a competenza
    // 1/10–31/12, tutta dopo l'uscita come nel test, all'inquilino 20000 × 30 % = 6000, con la strada A′ 9074 − 6000 = 3074. Il secondo dice
    // che il verso viene dalla cifra dei giorni di Luca, non dall'entrata tutta dopo l'uscita.
    $s = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'], 'non_scrivere');
    fclPersona($s, 'Luca');
    $rate = (string) cb50BloccoRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')));

    // Controlli: il blocco c'è e nomina Ines; i due casi a debito dicono già che Luca rimborsa Ines.
    expect($rate)->toContain('Ines');
    foreach ([['Affitto antenna', 'entrata', 40000, '2026-01-01', '2026-12-31'], ['Affitto antenna', 'entrata', 20000, '2026-10-01', '2026-12-31']] as $capitolo) {
        $vicino = cb50DopoLaFine($this, $capitolo, 'non_scrivere');
        fclPersona($vicino, 'Luca');
        $rateVicino = (string) cb50BloccoRate(fclAnteprima($this, $vicino, fds50ModuloInizio($vicino, 'Luca', '2026-10-01')));
        expect(cb50ChiEntraRimborsa($rateVicino, 'Luca'))->toBeTrue('Il caso a debito non fa rimborsare Luca: ' . $rateVicino)
            ->and(cb50ChiEntraRiceve($rateVicino, 'Luca'))->toBeFalse('Il caso a debito dice che Luca riceve: ' . $rateVicino)
            ->and($rateVicino)->toContain('Ines');
    }

    expect(cb50ChiEntraRimborsa($rate, 'Luca'))->toBeFalse('Il blocco «Rate» fa rimborsare a Luca un suo credito: ' . $rate)
        ->and(cb50ChiEntraRiceve($rate, 'Luca'))->toBeTrue('Il blocco «Rate» non dice che Luca riceve: ' . $rate)
        ->and($rate)->not->toContain('€ -');
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 3: i rami senza test
// ---------------------------------------------------------------------------------------------------------------------

it('punto 3, il ripiego su chi pagava prima — la coppia di segno misto, Elsa vende a Carlo il 1/11 (fermata di € 60,16 verso Carlo), Luca entra il 1/12: i giorni di Luca li ha pagati Elsa, € 30,57, e il pannello glieli fa rimborsare a lei, non a «Ugo e Elsa» né a nessuno', function () {
    // La misura S8. La vendita: Carlo dal 1/11, 61 giorni delle sole spese (l'entrata finisce il 30/9), 18148 × 61/184 = 6016,46; Elsa
    // resta con il 1/9–31/10, 6016,46 − 3296,74 = 2719,72; Ugo −698,17. La divisione di 8038: Carlo 6016, Elsa 2720, Ugo −698 → la fermata
    // 8736 − 2720 = 6016 verso Carlo (€ 60,16). `pagatoriDopoLUscita` taglia i giorni dopo l'uscita dove cambia chi sta al posto
    // dell'inquilino: Ugo fino al 31/8, Elsa dal 1/9, Carlo dal 1/11. Carlo non ha una riga nella coppia (la vendita non l'ha spostata):
    // dal 1/11 i giorni restano a chi li pagava prima, Elsa, con la fermata («oppure» Carlo). Le spese, 18148 sui tre pezzi di 62, 61 e 61
    // giorni: 6115,09 / 6016,46 / 6016,46 → 6115 / 6016 / 6016, il centesimo che manca al resto più grande, a pari resto al primo → 6115 /
    // 6017 / 6016. Il pezzo dal 1/11, 6016 su 61 giorni: Luca 31, 6016 × 31/61 = 186496/61 = 3057,31 → 3057; senza conduttore 30, 2958,69 →
    // 2959 (il centesimo al resto più grande). L'entrata, −10110 sul 1/7–30/9: Ugo 62 giorni −6813,26 → −6813, Elsa 30 −3296,74 → −3297,
    // tutta senza conduttore. Senza il ripiego («Ugo e Elsa», tutti quelli della coppia) Luca rimborserebbe chi non ha pagato i suoi
    // giorni; prendendo i pesi anche quando nominano chi non ha una riga, il pezzo non avrebbe un nome.
    $s = cb50ElsaVendeACarlo($this);
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $rate = (string) cb50BloccoRate($a);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $p = pflProspetto($s);
    $voce = (pflSezione($p, 'Luca')['voci'] ?? [])[0] ?? [];

    expect(fds50Coppia($s, $s['fine']))->toBe(['Elsa' => 8736, 'Ines' => -8038, 'Ugo' => -698])
        ->and(cb50Fermate(fds50Fermate($s['vendita'])))->toBe([[6016, ['Carlo']]])
        ->and(cb50Voci($p, 'Luca'))->toBe([['Spese generali', 'proprietario', 'Elsa', 3057, 31]])
        ->and((string) ($voce['oppure'] ?? ''))->toContain('Carlo')
        ->and(cb50Totali($p, 'Luca'))->toBe([3057, 0, 3057])
        ->and(cb50Senza($p))->toBe([
            ['Elsa', 'Affitto antenna', -3297, 30], ['Elsa', 'Spese generali', 2959, 30], ['Elsa', 'Spese generali', 6017, 61],
            ['Ugo', 'Affitto antenna', -6813, 62], ['Ugo', 'Spese generali', 6115, 62],
        ])
        ->and(cb50RimborsatiDaLuca($rate))->toBe(['Elsa'], 'Il pannello non fa rimborsare a Luca solo Elsa: ' . $rate);
});

it('punto 3, il confine della strada A′ — Ines e Dora coinquiline al 50 %, l\'«Affitto antenna» € 400,00 a competenza 1/10–31/12, Ines esce il 1/7 e Dora resta: la voce tutta dopo l\'uscita resta nelle rate di Ines, senza restituzione e senza nessuno che la paghi', function () {
    // Decisione 79: con un coinquilino che resta la scelta del conguaglio non c'è, i giorni dopo l'uscita restano nelle rate di chi esce, e
    // chi li paga lo decidono le parti. La parte di Ines è metà: le spese 18000, prima dell'uscita 18000 × 181/365 = 8926,03 → 8926, dopo
    // 9074; l'antenna 12000 / 2 = 6000, tutta dopo l'uscita. Ines: totale e nelle sue rate 8926 + 9074 − 6000 = 12000, da rimborsare 0; Dora
    // 18000 − 6000 = 12000. È il confine della correzione del punto 2: la strada A′ per la voce tutta dopo l'uscita non vale per il
    // coinquilino (strada C), che lascia il denaro dov'è.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    cb50Capitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $s['fine'] = fclRegistra($this, $s, fclModulo($s));
    fclEmetti($this, $s, '2026-09-30');
    $p = pflProspetto($s);

    expect(fclSaldi($s))->toBe([])
        ->and(cb50Voci($p, 'Ines'))->toBe([['Affitto antenna', 'rate', null, -6000, 92], ['Spese generali', 'rate', null, 8926, 181], ['Spese generali', 'rate', null, 9074, 184]])
        ->and(cb50Totali($p, 'Ines'))->toBe([12000, 12000, 0])
        ->and(cb50Totali($p, 'Dora'))->toBe([12000, 12000, 0])
        ->and(cb50Senza($p))->toBe([]);
});

it('punto 3, il primo ramo del credito — il cambio d\'inquilino in un passo da Ines a Marco il 1/4 e la fine di Marco il 1/7, tutti e due con «Scrivi», l\'antenna € 400,00 a competenza 1/10–31/12, Luca dal 1/10: il pannello dice che quei giorni li ha presi Ugo con la fine del 1 luglio 2026, e che Ugo restituisce la differenza a Luca', function () {
    // Le righe del riparto sono di Ines; la coppia dei giorni dopo il 1/7 l'ha scritta la fine di Marco, non l'uscita di Ines: il pannello
    // nomina quella fine (`usciti_fini`, G18). Le coppie: il cambio, Marco dal 1/4, 275 giorni, 36000 × 275/365 = 27123,29 → 27123, meno
    // l'entrata −12000 (tutta nei giorni di Marco) → Ines −15123, Marco +15123; la fine di Marco, le spese 18148 sul 1/7–31/12 e l'entrata
    // −12000 sul 1/10–31/12 → Marco −6148, Ugo +6148. Luca dal 1/10: 9074 − 12000 = −2926, un credito. Senza l'antenna (controllo) la parte di
    // Luca è 9074, un debito: «quei giorni li ha presi Ugo con il conguaglio della fine locazione del 1 luglio 2026, e li rimborsa Luca a
    // Ugo». Togliendo il primo ramo del credito, la frase passerebbe al secondo, «i giorni dopo la sua uscita li ha presi con il conguaglio
    // Ugo», che attribuisce la coppia all'uscita di Ines (1/4). Il prospetto di questo caso non si legge qui (vedi la testata).
    $caso = function (bool $antenna) {
        $s = fclScenario([['Ugo', 'proprietario', 100]]);
        if ($antenna) {
            cb50Capitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
        }
        fclGenera($s);
        fclEmetti($this, $s, '2026-03-31');
        $marco = fclPersona($s, 'Marco');
        $s['cambio'] = fclRegistra($this, $s, fclModulo($s, $marco, '2026-04-01') + ['conguaglio' => 'scrivi']);
        fclEmetti($this, $s, '2026-06-30');
        $s['fine'] = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [], 'Marco') + ['conguaglio' => 'scrivi']);
        fclEmetti($this, $s, '2026-09-30');
        fclPersona($s, 'Luca');

        return [$s, (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')))];
    };

    [, $senzaAntenna] = $caso(false);
    expect($senzaAntenna)->toContain('1 luglio 2026')
        ->and(cb50RimborsatiDaLuca($senzaAntenna))->toBe(['Ugo']);

    [$s, $frase] = $caso(true);
    expect(fds50Coppia($s, $s['cambio']))->toBe(['Ines' => -15123, 'Marco' => 15123])
        ->and(fds50Coppia($s, $s['fine']))->toBe(['Marco' => -6148, 'Ugo' => 6148])
        ->and($frase)->toContain('Ugo')
        ->and($frase)->toContain('fine locazione del 1 luglio 2026')
        ->and($frase)->not->toContain('dopo la sua uscita')
        ->and(cb50ChiEntraRiceve($frase, 'Luca'))->toBeTrue('Il pannello non dice che Luca riceve: ' . $frase)
        ->and(cb50ChiEntraRimborsa($frase, 'Luca'))->toBeFalse('Il pannello fa rimborsare a Luca un suo credito: ' . $frase)
        ->and($frase)->not->toContain('€ -');
});

it('punto 3, il terzo ramo del credito — la fine di Ines con «Scrivi» e l\'antenna € 400,00 a competenza 1/10–31/12, Luca dal 1/10, e chi ha la coppia senza nome: il pannello dice che i giorni li ha presi chi paga al posto dell\'inquilino, e che la differenza la riceve Luca', function () {
    // La parte di Luca è −2926 (9074 − 12000), un credito. Il terzo ramo è quello senza nomi: esce solo se chi ha preso la coppia non ha un
    // nome, e con dati validi non succede (un'anagrafica ha sempre il nome); qui il nome di Ugo è tolto apposta, dopo la fine. Togliendo il
    // ramo, la frase passerebbe a quella senza nomi del debito, «e Luca glieli rimborsa».
    $s = cb50DopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31']);
    fclPersona($s, 'Luca');
    DB::table('anagrafiche')->where('id', $s['persone']['Ugo']->id)->update(['nome' => '']);
    $frase = (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')));

    expect(fclSaldi($s))->toBe(['Ines' => -6148, 'Ugo' => 6148])
        ->and($frase)->toContain('chi paga al posto dell\'inquilino')
        ->and(cb50ChiEntraRiceve($frase, 'Luca'))->toBeTrue('Il pannello non dice che Luca riceve: ' . $frase)
        ->and(cb50ChiEntraRimborsa($frase, 'Luca'))->toBeFalse('Il pannello fa rimborsare a Luca un suo credito: ' . $frase)
        ->and($frase)->not->toContain('€ -');
});

it('punto 3, il ramo «misto» con il credito — la riga di Ugo chiusa al 30/9, la fine di Ines con «Scrivi», l\'«Affitto antenna» € 1.000,00 di entrata a competenza 1/9–31/12, Luca dal 1/9: Ugo gli restituisce € 44,18 e Ines € 135,49, e il pannello non dice «li rimborsa Luca a Ugo … e a Ines»', function () {
    // La coppia copre i giorni in cui Ugo sta al posto dell'inquilino, 1/7–30/9 (R2); dal 1/10 nessuno, e quei giorni restano nelle rate di
    // Ines. Le spese: 18148 sui 184 giorni dopo l'uscita, 92 coperti, 18148 × 92/184 = 9074, e 9074 scoperti. L'entrata all'inquilino,
    // 100000 × 30 % = 30000 sul 1/9–31/12, 122 giorni, tutta dopo l'uscita: coperti 30 (1/9–30/9), 30000 × 30/122 = 7377,05 → −7377;
    // scoperti 92, −22622,95 → −22623 (il centesimo al resto più grande). La coppia: Ugo 9074 − 7377 = 1697, Ines −1697.
    // Luca dal 1/9, 122 giorni: dei giorni di Ugo le spese 9074 × 30/92 = 2958,91 → 2959 (senza conduttore il 1/7–31/8, 6115,09 → 6115) e
    // l'entrata −7377, tutta sua: verso Ugo 2959 − 7377 = −4418; dei giorni scoperti, nelle rate di Ines, le spese 9074 e l'entrata −22623:
    // verso Ines 9074 − 22623 = −13549. Tutte e due le parti sono un credito di Luca: Ugo gli restituisce € 44,18, Ines € 135,49. Il ramo
    // «misto» di `dopoLUscitaDiPrima` dice sempre «li rimborsa Luca a Ugo, che li ha presi con il conguaglio, e a Ines per quelli in cui
    // nessuno stava al posto dell'inquilino»: senza l'antenna (controllo) è giusto (2959 a Ugo, 9074 a Ines), con l'antenna no.
    $caso = function (bool $antenna) {
        $s = cb50UgoFinoAl30Settembre($this, $antenna ? ['Affitto antenna', 'entrata', 100000, '2026-09-01', '2026-12-31'] : null);

        return [$s, (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01')))];
    };

    // Controlli: senza l'antenna il ramo «misto» fa rimborsare a Luca Ugo e Ines, ed è giusto.
    [, $senzaAntenna] = $caso(false);
    expect(cb50ChiEntraRimborsa($senzaAntenna, 'Luca'))->toBeTrue('Il caso vicino non fa rimborsare a Luca Ugo e Ines: ' . $senzaAntenna)
        ->and($senzaAntenna)->toContain('Ugo')
        ->and($senzaAntenna)->toContain('Ines');

    // Con l'antenna: la coppia, i giorni scoperti, il prospetto di Luca.
    [$s, $frase] = $caso(true);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'));
    $p = pflProspetto($s);
    expect(fclSaldi($s))->toBe(['Ines' => -1697, 'Ugo' => 1697])
        ->and(cb50Pezzi($s['fine']))->toBe([['2026-07-01', '2026-09-30', 9074], ['2026-09-01', '2026-09-30', -7377]])
        ->and($s['fine']->fresh()->registro['conguaglio']['periodi'][0]['scoperti'] ?? null)->toBe([['dal' => '2026-10-01', 'al' => '2026-12-31']])
        ->and(cb50Voci($p, 'Luca'))->toBe([
            ['Affitto antenna', 'proprietario', 'Ines', -22623, 92], ['Affitto antenna', 'proprietario', 'Ugo', -7377, 30],
            ['Spese generali', 'proprietario', 'Ugo', 2959, 30], ['Spese generali', 'proprietario', 'Ines', 9074, 92],
        ])
        ->and(cb50Totali($p, 'Luca'))->toBe([-17967, 0, -17967])
        ->and($frase)->toContain('Ugo')
        ->and($frase)->toContain('Ines');

    expect(cb50ChiEntraRimborsa($frase, 'Luca'))->toBeFalse('Il ramo «misto» fa rimborsare a Luca i giorni che sono un suo credito: ' . $frase)
        ->and(cb50ChiEntraRiceve($frase, 'Luca'))->toBeTrue('Il ramo «misto» non dice che Luca riceve: ' . $frase)
        ->and($frase)->not->toContain('€ -');
});

it('punto 3, il ramo «misto» con le due parti di segno diverso — la riga di Ugo chiusa al 30/9, la fine di Ines con «Scrivi», Luca dal 1/9, e l\'«Affitto antenna» di entrata nei giorni di una parte sola: una parte è un debito di Luca, l\'altra un suo credito, e il pannello dice tutti e due i versi', function (array $capitolo, array $pezzi, array $voci, array $totali, string $restituisce) {
    // Le spese, come nel test sopra: 9074 nei giorni di Ugo (1/7–30/9), di cui a Luca 9074 × 30/92 = 2958,91 → 2959, e 9074 nei giorni
    // scoperti (1/10–31/12), tutti di Luca, nelle rate di Ines.
    // - L'antenna € 200,00 sul 1/9–30/9, nei giorni di Ugo: all'inquilino 20000 × 30 % = 6000, tutta nella coppia e tutta nei giorni di
    //   Luca. La coppia: Ugo 9074 − 6000 = 3074, Ines −3074. Luca: verso Ugo 2959 − 6000 = −3041, un credito (Ugo gli restituisce € 30,41);
    //   verso Ines 9074, un debito (Luca le rimborsa € 90,74); totale 6033.
    // - L'antenna € 400,00 sul 1/10–31/12, nei giorni scoperti: all'inquilino 12000, fuori dalla coppia, nelle rate di Ines. La coppia: Ugo
    //   9074, Ines −9074. Luca: verso Ugo 2959, un debito (Luca gli rimborsa € 29,59); verso Ines 9074 − 12000 = −2926, un credito (Ines
    //   gli restituisce € 29,26); totale 33.
    // Un verso solo per tutta la frase (quello della parte nella coppia, o del totale) sbaglia una delle due parti; il test sopra, con tutte
    // e due a credito, non lo distingue. Chi rimborsa e chi restituisce si cercano con il verso, non con le parole: la frase deve dire che
    // Luca rimborsa e che riceve, e non deve fargli rimborsare chi gli restituisce.
    $s = cb50UgoFinoAl30Settembre($this, $capitolo);
    $frase = (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01')));
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'));
    $p = pflProspetto($s);

    // Controlli: la coppia, i suoi pezzi e il prospetto di Luca, voce per voce.
    $coppia = (int) array_sum(array_column($pezzi, 2));
    expect(fclSaldi($s))->toBe(['Ines' => -$coppia, 'Ugo' => $coppia])
        ->and(cb50Pezzi($s['fine']))->toBe($pezzi)
        ->and(cb50Voci($p, 'Luca'))->toBe($voci)
        ->and(cb50Totali($p, 'Luca'))->toBe($totali)
        ->and($frase)->toContain('Ugo')
        ->and($frase)->toContain('Ines');

    expect(cb50ChiEntraRimborsa($frase, 'Luca'))->toBeTrue('Il ramo «misto» non dice la parte che Luca rimborsa: ' . $frase)
        ->and(cb50ChiEntraRiceve($frase, 'Luca'))->toBeTrue(sprintf('Il ramo «misto» non dice che %s restituisce a Luca: %s', $restituisce, $frase))
        ->and(collect(cb50RimborsatiDaLuca($frase))->every(fn (string $n) => ! str_contains($n, $restituisce)))->toBeTrue(sprintf('Il ramo «misto» fa rimborsare a Luca %s, che gli restituisce: %s', $restituisce, $frase))
        ->and($frase)->not->toContain('€ -');
})->with([
    'l\'antenna € 200,00 sul 1/9–30/9, nei giorni di Ugo' => [
        ['Affitto antenna', 'entrata', 20000, '2026-09-01', '2026-09-30'],
        [['2026-07-01', '2026-09-30', 9074], ['2026-09-01', '2026-09-30', -6000]],
        [['Affitto antenna', 'proprietario', 'Ugo', -6000, 30], ['Spese generali', 'proprietario', 'Ugo', 2959, 30], ['Spese generali', 'proprietario', 'Ines', 9074, 92]],
        [6033, 0, 6033],
        'Ugo',
    ],
    'l\'antenna € 400,00 sul 1/10–31/12, nei giorni scoperti' => [
        ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'],
        [['2026-07-01', '2026-09-30', 9074]],
        [['Affitto antenna', 'proprietario', 'Ines', -12000, 92], ['Spese generali', 'proprietario', 'Ugo', 2959, 30], ['Spese generali', 'proprietario', 'Ines', 9074, 92]],
        [33, 0, 33],
        'Ines',
    ],
]);

// ---------------------------------------------------------------------------------------------------------------------
// Punto 4: il blocco «Rate» nomina chi ha preso i giorni che dice
// ---------------------------------------------------------------------------------------------------------------------

it('punto 4 — la coppia di segno misto (Ugo per il 1/7–31/8, Elsa dal 1/9): il blocco «Rate» dell\'inizio di Luca non dice che i giorni dopo l\'uscita di Ines li ha presi con il conguaglio solo Elsa', function (bool $conLaVendita) {
    // La coppia: Ugo −698 per il 1/7–31/8, Elsa 8736 per il 1/9–31/12 (`cb50CoppiaDiSegnoMisto`). I giorni dopo l'uscita di Ines (1/7) li
    // hanno presi con il conguaglio Ugo, fino al 31/8, ed Elsa, dal 1/9; quelli di Luca (dal 1/10, o dal 1/12 dopo la vendita di Elsa a
    // Carlo, che non ha spostato la coppia) solo Elsa. Prima della correzione la frase diceva «i giorni dopo la sua uscita li ha presi con
    // il conguaglio Elsa»: il nome era quello di chi ha pagato i giorni dall'inizio, e i giorni erano tutti quelli dopo l'uscita. La frase
    // giusta dice i giorni di Luca e Elsa, o i giorni dopo l'uscita e tutti e due. Il controllo: con un solo pagatore, Ugo, la frase dice
    // già «i giorni dopo la sua uscita li ha presi con il conguaglio Ugo», ed è vera; e in tutti e due i casi Luca rimborsa solo Elsa
    // (punto 5 dell'ultimo giro).
    if ($conLaVendita) {
        $s = cb50ElsaVendeACarlo($this);
        $frase = (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01')));
    } else {
        $s = cb50CoppiaDiSegnoMisto($this);
        fclPersona($s, 'Luca');
        $frase = (string) cb50FraseDelleRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')));
    }
    $solo = cb50DopoLaFine($this, null);
    fclPersona($solo, 'Luca');
    $fraseSolo = (string) cb50FraseDelleRate(fclAnteprima($this, $solo, fds50ModuloInizio($solo, 'Luca', '2026-10-01')));

    // Controlli. Del caso con un solo pagatore non si fissano le parole («dopo la sua uscita»): la correzione può dire i giorni
    // dall'inizio anche lì.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Elsa' => 8736, 'Ines' => -8038, 'Ugo' => -698])
        ->and(cb50RimborsatiDaLuca($frase))->toBe(['Elsa'])
        ->and(cb50NominaChiLiHaPresi($fraseSolo, ['Ugo'], ['Ugo']))->toBeTrue('Il controllo non nomina chi ha preso i giorni: ' . $fraseSolo);

    expect(cb50NominaChiLiHaPresi($frase, ['Ugo', 'Elsa'], ['Elsa']))->toBeTrue('La frase non nomina chi ha preso i giorni che dice: ' . $frase);
})->with([
    'Luca dal 1/10' => [false],
    'dopo la vendita di Elsa a Carlo, Luca dal 1/12' => [true],
]);
