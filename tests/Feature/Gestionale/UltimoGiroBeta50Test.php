<?php

/**
 * 1.11.0-beta.50, ultimo giro piccolo sulle correzioni del giro stretto: i cinque punti rimasti dalla revisione e dal correttore (verbale
 * `piano_esecutivo_beta50.md`, «Le correzioni del giro stretto», «Restano»): 1. il prospetto degli oneri accessori con una voce la cui
 * competenza comincia dopo l'uscita, dopo una fine «Scrivi», nel rientro e con un nuovo conduttore: la coppia l'ha portata a chi paga al
 * posto dell'inquilino, e il prospetto la lasciava «nelle rate» di chi è uscito (`ProspettoOneriAccessori::catenaDeiConduttori` cercava la
 * fine solo con la decorrenza dopo il primo giorno della voce: non la vedeva quando la voce comincia il giorno dell'uscita o dopo). Ogni
 * pezzo della coppia, l'entrata compresa, deve prendere la strada B, come le spese di tutto l'anno. Lo stesso difetto c'era con una spesa a
 * competenza dopo l'uscita (trovato scrivendo questi test: le «Pulizie scale», € 120,00 lasciati nelle rate di Ines); 2. le frasi della
 * fermata ridotta (`FermataDopoScrivi::fraseRidotta`, `motivoRidotta`, `fraseStoricoRidotta`) e del blocco «Rate»
 * (`AnteprimaPassaggio::dopoLUscitaDiPrima`) quando la parte di chi entra è un credito: non «li rimborsa Luca», ma il verso giusto. Il
 * verso è quello della parte di chi entra, non della coppia né delle sue voci: con una parte a debito resta «li rimborsa», anche quando nei
 * suoi giorni c'è un'entrata (il bordo del punto 1); 3. le due righe di G2 in `FermataDopoScrivi` che decidono se c'è una fermata e non
 * avevano un test: `coppieScritte`, che riconosce la parte di chi sta al posto dell'inquilino da chi è uscito con la fine e non dal segno
 * della riga, e il salto di `dopoUnaFermata` per chi ha una riga nella coppia (`isset($c['righe'][$chi])`); 4. il ramo per periodo voce per
 * voce di R2 in `ConguaglioPassaggio` (le righe di `frasiPerCapitolo` con i giorni coperti, la testa con la parte dei giorni coperti, il
 * centesimo alla voce più grande in `copertiDelGruppo`), e la sua testa che dice un importo negativo con il segno meno; 5. i pagatori dopo
 * l'uscita (`ProspettoOneriAccessori::pagatoriDopoLUscita`) con una coppia di segno misto: si tagliano per giorni, e chi entra rimborsa
 * solo chi ha pagato i suoi giorni.
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`, `ScenariProspettoLocazione.php` e `FermataDopoScriviHelpers.php`: «Spese generali»
 * € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate
 * mensili; Ugo proprietario e Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181,
 * 184] / 365 = 17852,05 / 18147,95 → la coppia delle spese 18148 (€ 181,48). Il capitolo in più è sulla stessa tabella con la stessa divisione:
 * l'«Affitto antenna» € 400,00 di entrata dà all'inquilino 40000 × 30 % = 12000 di credito. Gli oracoli sono fatti a mano, in frazioni
 * esatte, accanto a ogni test: la coppia con la sua regola (le parti intere, il centesimo al resto più grande, a pari resto a chi esce), il
 * prospetto voce per voce, ogni voce divisa per giorni con i resti maggiori (`MoneyHelper::ripartisciPerQuote`: a pari resto vince chi
 * viene prima, i giorni del conduttore prima di quelli senza).
 *
 * Nati come test rossi: ognuno descrive un punto di questo giro, corretto prima del commit, con accanto un controllo (la stessa scena nei
 * pezzi che il codice faceva già giusti, o il caso vicino) che dice che il rosso non veniva dal test. Gli altri sono i controlli dei punti
 * 1 e 2 (il caso vicino che il codice faceva già giusto, e che una correzione non deve toccare) e le guardie dei punti 3 e 4: tengono un
 * pezzo di correzione già giusto che nessun test teneva, e tornano rossi togliendo quel pezzo (provato con una mutazione per pezzo, una
 * alla volta, in una copia).
 *
 * Che cosa NON copre:
 * - le frasi parola per parola: si cercano la cifra, il nome e il verso (chi entra riceve: «a Luca», «Luca li riceve», «a credito di Luca»,
 *   «gli restituisce»; chi entra non rimborsa: niente «li rimborsa Luca»), e che non ci sia «€ -»; la frase intera la scrive chi corregge;
 * - punto 1: la stampa del prospetto (si legge il servizio, che la stampa ripete); le frasi in testa al prospetto; il centesimo fra la
 *   fermata e il prospetto, che con due voci non sono la stessa divisione (nel caso misurato la fermata ridivide la coppia intera, −985,93 →
 *   −986, € 9,86; il prospetto arrotonda voce per voce, 3058 − 4043 = −985); il cambio d'inquilino in un passo registrato prima dei giorni
 *   della voce, che la catena cerca allo stesso modo (non misurato);
 * - punto 2: la cifra di chi entra nelle frasi (la frase può dirla o no); il rientro, dove `fraseRientro` e il blocco «Rate» dicono già il
 *   credito (G1); `fraseStoricoRidottaAnnullata` e gli avvisi di «Annulla il conguaglio»;
 * - punto 3: i netti dopo la seconda vendita (le frasi e la coppia della vendita stessa con un'entrata hanno i difetti della .49 delle Code
 *   248, 249 e 251, che vanno nella .51); la coppia tutta a credito, il limite dichiarato di G2;
 * - punto 4: la riga della voce «Affitto antenna: € -120,00 …» e la stessa testa senza giorni scoperti («→ € -60,49 a chi entra»), frasi con
 *   «€ -» che c'erano già nella .49 (Coda 251, nella .51); la testa «da quote emesse a intestatari diversi» con due piani sulla stessa
 *   gestione;
 * - punto 5: il pannello e il prospetto di un conduttore che entra prima della vendita (i suoi giorni pagati da Ugo e da Elsa, in due
 *   pezzi); i pagatori dopo una fermata, dove la coppia non si è spostata e la voce nomina anche chi è entrato («oppure»); la fermata e il
 *   rientro con la coppia di segno misto;
 * - G20, il piano della 1.10, le pagine Vue, i PDF resi da browser, MySQL.
 */

use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
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
 * È `sbmConLAntenna` di `StrettoBeta50MotoreTest` con il nome e il tipo del conto: sta in un file di test e non si può includere.
 */
function ugbCapitolo(array $s, string $nome, string $tipo, int $importo, string $dal, string $al, ?PianoRate $piano = null): void
{
    $piano ??= $s['piano'];
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $capitoli = [$conto->id => ['importo' => $importo]];
    if ($piano->is($s['piano'])) {
        $spese = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
        $capitoli = [$spese->id => ['importo' => 120000]] + $capitoli;
    }
    $piano->capitoli()->syncWithoutDetaching($capitoli);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $conto->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
}

/**
 * La fine di Ines del 1/7 con «Scrivi»: il piano con il capitolo dato (di partenza l'«Affitto antenna» € 400,00 di entrata a competenza
 * 1/10–31/12, tutta dopo l'uscita; senza capitolo il piano di sempre), generato, sei rate a giornale prima della fine e poi le rate a
 * giornale fino al giorno dato. La coppia ha un pezzo per voce: le spese 18148 sul 1/7–31/12, e il capitolo all'inquilino sui suoi giorni
 * dopo l'uscita.
 *
 * @param array{0: string, 1: string, 2: int, 3: string, 4: string}|null $capitolo [nome, tipo, importo, dal, al]
 */
function ugbDopoLaFine($test, ?array $capitolo = ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'], string $emessoFino = '2026-09-30'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    if ($capitolo !== null) {
        ugbCapitolo($s, ...$capitolo);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, $emessoFino);

    return $s;
}

/** I pezzi della coppia nel registro della fine: [dal, al, importo], uno per voce. */
function ugbPezzi(Subentro $fine): array
{
    return collect($fine->fresh()->registro['conguaglio']['periodi'][0]['periodi'] ?? [])->map(fn ($p) => [$p['tratti'][0]['dal'], $p['tratti'][0]['al'], (int) $p['importo']])->all();
}

/** Le voci di un conduttore nel prospetto, senza quelle a zero: [voce, modo, pagata da, importo, giorni], in ordine. */
function ugbVoci(array $p, string $nome): array
{
    return collect(pflSezione($p, $nome)['voci'] ?? [])->filter(fn ($v) => (int) $v['importo'] !== 0)
        ->map(fn ($v) => [(string) $v['conto'], (string) $v['modo'], $v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => $v[0] . '|' . $v[1] . '|' . $v[3])->values()->all();
}

/** Le voci di un conduttore di una sola voce del piano. */
function ugbVociDi(array $p, string $nome, string $voce): array
{
    return array_values(array_filter(ugbVoci($p, $nome), fn (array $v) => $v[0] === $voce));
}

/** I totali della sezione di un conduttore: [totale, nelle sue rate, da rimborsare]. Positivo: il conduttore deve; negativo: gli si deve. */
function ugbTotali(array $p, string $nome): ?array
{
    $c = pflSezione($p, $nome);

    return $c === null ? null : [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare']];
}

/** Le voci «senza conduttore», senza quelle a zero: [pagata da, voce, importo, giorni], in ordine. */
function ugbSenza(array $p): array
{
    return collect($p['senza_conduttore']['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)
        ->map(fn ($v) => [$v['pagato_da'] ?? null, (string) $v['conto'], (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0] . '|' . $v[1] . '|' . $v[2])->values()->all();
}

/** Le fermate dopo «Scrivi» di un'anteprima o di un registro, in breve: [cifra, verso]. */
function ugbFermate(array $fermate): array
{
    return collect($fermate)->map(fn ($f) => [(int) $f['cifra'], array_values((array) $f['verso'])])->all();
}

/** Le frasi di un testo divise al punto seguito da una maiuscola (non ai due punti: una frase con il suo verso resta intera). */
function ugbPeriodi(string $testo): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=\.)\s+(?=[A-ZÈ«])/u', $testo)), fn ($p) => $p !== ''));
}

/** La frase dice che chi entra paga i giorni a chi li ha presi: «li rimborsa Luca», «Luca glieli rimborsa». */
function ugbChiEntraRimborsa(string $t, string $chi): bool
{
    $c = preg_quote($chi, '/');

    return preg_match('/li rimborsa ' . $c . '\b|\b' . $c . ' (?:glieli |gli |li )?rimborsa\b/u', $t) === 1;
}

/**
 * La frase dice che chi entra riceve: «… a Luca», «Luca li riceve», «riceve Luca», «a credito di Luca», «a favore di Luca», «gli
 * restituisce». Si cerca il verso, non le parole della frase.
 */
function ugbChiEntraRiceve(string $t, string $chi): bool
{
    $c = preg_quote($chi, '/');

    return preg_match('/\bad? ' . $c . '\b|\b' . $c . ' (?:li |lo |gli |ne )?ricev|\bricev\w* ' . $c . '\b|\bcredito di ' . $c . '\b|\ba favore di ' . $c . '\b|\bgli (?:si )?restituisc|\bgli (?:va|vanno|spetta|spettano)\b/u', $t) === 1;
}

/**
 * Il blocco «Rate» del pannello dell'inizio sui piani fermi, senza la frase finale («Un piano generato dopo intesterà quelle voci a …»), che
 * nomina chi entra per un'altra ragione.
 */
function ugbBloccoRate(array $anteprima): ?string
{
    $blocco = collect($anteprima['rate']['frasi'] ?? [])->first(fn (string $f) => str_contains($f, 'La parte a carico dell\'inquilino dal'));

    return $blocco === null ? null : implode(' ', array_filter(ugbPeriodi($blocco), fn (string $p) => ! str_starts_with($p, 'Un piano generato dopo')));
}

/** Il primo testo che contiene il frammento (la cifra di una fermata, di solito), o null. */
function ugbCon(array $testi, string $frammento): ?string
{
    return collect($testi)->first(fn ($t) => is_string($t) && str_contains($t, $frammento));
}

/**
 * Il saldo manuale dal Wallet che le fermate di un passaggio indicano, scritto come lo scrive il Wallet: a credito di chi resta a carico
 * per la cifra e a debito di ciascuno che riceve per la sua parte, con il segno della cifra. Poi i saldi manuali per persona.
 *
 * @return array<string, int>
 */
function ugbWalletDelleFermate(array $s, Subentro $passaggio): array
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

// ---------------------------------------------------------------------------------------------------------------------
// Punto 1: il prospetto con una voce la cui competenza comincia dopo l'uscita, dopo una fine «Scrivi»
// ---------------------------------------------------------------------------------------------------------------------

it('punto 1, il rientro — dopo la fine di Ines del 1/7 con «Scrivi» e l\'«Affitto antenna» di entrata a competenza 1/10–31/12, Ines rientra il 1/10: nel prospetto l\'entrata l\'ha presa Ugo con la coppia, e Ugo restituisce a Ines € 29,26 invece di farsi rimborsare € 90,74', function () {
    // La coppia: le spese dopo l'uscita 18148 sul 1/7–31/12, l'entrata all'inquilino tutta dopo l'uscita, −12000 sul 1/10–31/12; a Ugo
    // 18148 − 12000 = 6148. Il prospetto, voce per voce: - le spese: 36000 × 181/365 = 17852,05 → 17852 nelle rate di Ines; i 184 giorni
    // dopo, 18148, li ha pagati Ugo con la coppia; Ines conduttrice dal 1/10 ne ha 92: 18148 × 92/184 = 9074, da rimborsare a Ugo; gli
    // altri 92, 9074, senza conduttore, pagati da Ugo; - l'entrata: −12000, tutta dopo l'uscita, presa da Ugo con la coppia; i suoi 92
    // giorni sono tutti di Ines: −12000, che Ugo le deve. Ines: totale 17852 + 9074 − 12000 = 14926; nelle sue rate 17852; da rimborsare
    // 9074 − 12000 = −2926: Ugo le restituisce € 29,26, come dice il pannello del rientro (G1). Prima della correzione
    // `catenaDeiConduttori` cercava la fine solo dopo il primo giorno della voce (1/10): la fine del 1/7 non la vedeva, e l'entrata restava
    // «nelle rate» di Ines, −12000 per 92 giorni. Ines [14926, 5852, 9074]: avrebbe rimborsato a Ugo € 90,74 invece di ricevere € 29,26, €
    // 120,00 a suo danno.
    $s = ugbDopoLaFine($this);
    $rientro = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: la coppia e i suoi due pezzi; il pannello del rientro, che dice già il credito di Ines (G1); le voci delle spese,
    // che prendono già la strada B; il totale del ruolo inquilino, 36000 − 12000.
    expect(fclSaldi($s))->toBe(['Ines' => -6148, 'Ugo' => 6148])
        ->and(ugbPezzi($s['fine']))->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-10-01', '2026-12-31', -12000]])
        ->and(collect($rientro['rate']['rinnovo_dopo_scrivi'])->map(fn ($f) => [(int) $f['cifra'], $f['pagato_da']])->all())->toBe([[-2926, ['Ugo']]])
        ->and(ugbVociDi($p, 'Ines', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ugo', 9074, 92], ['Spese generali', 'rate', null, 17852, 181]])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(24000);

    expect(ugbVociDi($p, 'Ines', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -12000, 92]], 'L\'entrata dei giorni dopo l\'uscita resta nelle rate di Ines: ' . json_encode(ugbVoci($p, 'Ines'), JSON_UNESCAPED_UNICODE))
        ->and(ugbTotali($p, 'Ines'))->toBe([14926, 17852, -2926]);
});

it('punto 1, il rientro con una spesa — lo stesso con «Pulizie scale» € 400,00 di spesa a competenza 1/10–31/12: la spesa dei giorni dopo l\'uscita l\'ha pagata Ugo con la coppia, e Ines gliela rimborsa', function () {
    // Lo stesso difetto con il segno opposto: «a ogni pezzo», non solo all'entrata. La coppia: 18148 delle spese generali e 40000 × 30 % =
    // 12000 delle pulizie, tutte dopo l'uscita: a Ugo 30148. Ines conduttrice dal 1/10 ha tutti i 92 giorni delle pulizie: 12000, da
    // rimborsare a Ugo. Ines: totale 17852 + 9074 + 12000 = 38926; nelle sue rate 17852; da rimborsare 9074 + 12000 = 21074. Prima della
    // correzione le pulizie restavano «nelle rate» di Ines (12000 per 92 giorni), che la coppia le aveva già tolte: Ugo aveva pagato €
    // 120,00 che il prospetto non gli faceva rimborsare.
    $s = ugbDopoLaFine($this, ['Pulizie scale', 'spesa', 40000, '2026-10-01', '2026-12-31']);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: la coppia e i suoi pezzi, le voci delle spese generali, il totale del ruolo inquilino (36000 + 12000).
    expect(fclSaldi($s))->toBe(['Ines' => -30148, 'Ugo' => 30148])
        ->and(ugbPezzi($s['fine']))->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-10-01', '2026-12-31', 12000]])
        ->and(ugbVociDi($p, 'Ines', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ugo', 9074, 92], ['Spese generali', 'rate', null, 17852, 181]])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(48000);

    expect(ugbVociDi($p, 'Ines', 'Pulizie scale'))->toBe([['Pulizie scale', 'proprietario', 'Ugo', 12000, 92]], 'La spesa dei giorni dopo l\'uscita resta nelle rate di Ines: ' . json_encode(ugbVoci($p, 'Ines'), JSON_UNESCAPED_UNICODE))
        ->and(ugbTotali($p, 'Ines'))->toBe([38926, 17852, 21074]);
});

it('punto 1, il nuovo conduttore — dopo la fine di Ines del 1/7 con «Scrivi» e l\'antenna a competenza 1/10–31/12, Luca entra il 1/10: Ugo gli restituisce € 29,26, e l\'entrata non resta nelle rate di Ines', function () {
    // Luca dal 1/10: le spese, 18148 × 92/184 = 9074, pagate da Ugo con la coppia; l'entrata, −12000, tutta nei suoi giorni, presa da Ugo.
    // Luca: totale e da rimborsare 9074 − 12000 = −2926. Ines: solo le spese dei suoi 181 giorni, 17852, nelle sue rate. Prima della
    // correzione Luca rimborsava a Ugo € 90,74 (le sole spese) e Ines aveva l'entrata nelle sue rate: [5852, 5852, 0].
    $s = ugbDopoLaFine($this);
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: la voce delle spese di Luca e i giorni senza conduttore delle spese, pagati da Ugo.
    expect(ugbVociDi($p, 'Luca', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ugo', 9074, 92]])
        ->and(ugbVociDi($p, 'Ines', 'Spese generali'))->toBe([['Spese generali', 'rate', null, 17852, 181]])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(24000);

    expect(ugbVociDi($p, 'Luca', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -12000, 92]], 'L\'entrata dei giorni di Luca non è nella sua sezione: ' . json_encode(['Luca' => ugbVoci($p, 'Luca'), 'Ines' => ugbVoci($p, 'Ines')], JSON_UNESCAPED_UNICODE))
        ->and(ugbTotali($p, 'Luca'))->toBe([-2926, 0, -2926])
        ->and(ugbVociDi($p, 'Ines', 'Affitto antenna'))->toBe([])
        ->and(ugbTotali($p, 'Ines'))->toBe([17852, 17852, 0]);
});

it('punto 1, il nuovo conduttore dopo una fermata — dopo la vendita di Ugo a Elsa del 1/10 fermata su −€ 29,26 verso Elsa, Luca entra il 1/12: Luca riceve € 9,85 invece di rimborsare € 30,58, e l\'entrata non resta nelle rate di Ines', function () {
    // Il caso misurato dalla revisione. La fermata non ha spostato la coppia: i giorni dopo l'uscita li ha pagati Ugo, con Elsa nella nota
    // («oppure»). Luca dal 1/12, 31 giorni: - le spese: il pezzo di Ugo dal 1/10 (dopo la fermata) è 18148 × 92/184 = 9074; Luca ne ha 31
    // su 92: 9074 × 31/92 = 281294/92 = 3057,54 → 3058 (il centesimo al resto più grande, ,54 contro ,46 dei giorni senza conduttore);
    // senza conduttore 6016, 61 giorni; - l'entrata: −12000 sul 1/10–31/12; Luca 12000 × 31/92 = 372000/92 = 4043,48 → −4043 (,48 contro
    // ,52 dei giorni senza conduttore, che prendono il centesimo: −7957, 61 giorni). Luca: 3058 − 4043 = −985, € 9,85 che gli si devono. La
    // fermata ridivide la coppia intera (−985,93 → −986, il «€ 9,86» del rilievo); il prospetto arrotonda voce per voce. Prima della
    // correzione Luca [3058, 0, 3058]: rimborsava € 30,58; Ines aveva l'entrata nelle rate, [5852, 5852, 0].
    $s = ugbDopoLaFine($this);
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Luca');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    fclEmetti($this, $s, '2026-11-30');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $p = pflProspetto($s);

    // Controlli: la fermata della vendita (G1), la voce delle spese di Luca e i giorni senza conduttore delle spese.
    expect(ugbFermate(fds50Fermate($vendita)))->toBe([[-2926, ['Elsa']]])
        ->and(ugbVociDi($p, 'Luca', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ugo', 3058, 31]])
        ->and(array_values(array_filter(ugbSenza($p), fn (array $v) => $v[1] === 'Spese generali')))->toBe([['Ugo', 'Spese generali', 6016, 61], ['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(24000);

    expect(ugbVociDi($p, 'Luca', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -4043, 31]], 'L\'entrata dei giorni di Luca non è nella sua sezione: ' . json_encode(['Luca' => ugbVoci($p, 'Luca'), 'Ines' => ugbVoci($p, 'Ines')], JSON_UNESCAPED_UNICODE))
        ->and(ugbTotali($p, 'Luca'))->toBe([-985, 0, -985])
        ->and(ugbVoci($p, 'Ines'))->toBe([['Spese generali', 'rate', null, 17852, 181]])
        ->and(ugbTotali($p, 'Ines'))->toBe([17852, 17852, 0])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Affitto antenna', -7957, 61], ['Ugo', 'Spese generali', 6016, 61], ['Ugo', 'Spese generali', 9074, 92]]);
});

it('punto 1, il bordo — l\'antenna a competenza dal giorno dell\'uscita, 1/7–31/12, e Luca dal 1/10: l\'entrata l\'ha presa Ugo con la coppia, e Luca, con metà dell\'entrata nei suoi giorni, rimborsa ancora a Ugo € 30,74', function () {
    // La voce comincia il giorno dell'uscita: la fine del 1/7 non è dopo il primo giorno della voce, e prima della correzione
    // `catenaDeiConduttori` non la vedeva, come quella del 1/10. Una correzione che cercasse la fine solo prima della voce (e non anche nel
    // suo primo giorno) lascerebbe questo caso com'è. La coppia: le spese 18148 e l'entrata all'inquilino −12000, tutte e due sul
    // 1/7–31/12: a Ugo 6148. Luca dal 1/10 ha 92 dei 184 giorni di tutte e due: le spese 18148 × 92/184 = 9074, l'entrata −12000 × 92/184 =
    // −6000, pagate da Ugo con la coppia; gli altri 92 giorni senza conduttore, pagati da Ugo, 9074 e −6000. Luca: totale e da rimborsare
    // 9074 − 6000 = 3074, un debito. Ines: le spese dei suoi 181 giorni, 17852. Prima della correzione l'entrata restava nelle rate di
    // Ines, −12000 per 184 giorni: Ines [5852, 5852, 0]. Punto 2: il blocco «Rate» dell'inizio dice il verso della parte di Luca, che qui è
    // un debito anche se nei suoi giorni c'è un'entrata: «li rimborsa Luca a Ugo» resta. Una correzione che guardasse se la coppia ha un
    // pezzo a credito, o se Luca ha una voce a credito, e non la sua parte, lo girerebbe.
    $s = ugbDopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-07-01', '2026-12-31']);
    fclPersona($s, 'Luca');
    $rate = ugbBloccoRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01')));
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $p = pflProspetto($s);

    // Controlli: la coppia e i suoi pezzi; il blocco «Rate» con il debito di Luca; le spese di Luca e di Ines; il totale.
    expect(fclSaldi($s))->toBe(['Ines' => -6148, 'Ugo' => 6148])
        ->and(ugbPezzi($s['fine']))->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-07-01', '2026-12-31', -12000]])
        ->and((string) $rate)->toContain('li rimborsa Luca a Ugo')
        ->and(ugbVociDi($p, 'Luca', 'Spese generali'))->toBe([['Spese generali', 'proprietario', 'Ugo', 9074, 92]])
        ->and(ugbVociDi($p, 'Ines', 'Spese generali'))->toBe([['Spese generali', 'rate', null, 17852, 181]])
        ->and((int) $p['totale_inquilino'])->toBe(24000);

    expect(ugbVociDi($p, 'Luca', 'Affitto antenna'))->toBe([['Affitto antenna', 'proprietario', 'Ugo', -6000, 92]], 'L\'entrata dei giorni di Luca non è nella sua sezione: ' . json_encode(['Luca' => ugbVoci($p, 'Luca'), 'Ines' => ugbVoci($p, 'Ines')], JSON_UNESCAPED_UNICODE))
        ->and(ugbTotali($p, 'Luca'))->toBe([3074, 0, 3074])
        ->and(ugbVoci($p, 'Ines'))->toBe([['Spese generali', 'rate', null, 17852, 181]])
        ->and(ugbTotali($p, 'Ines'))->toBe([17852, 17852, 0])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Affitto antenna', -6000, 92], ['Ugo', 'Spese generali', 9074, 92]]);
});

it('punto 1, il controllo — l\'antenna a competenza 1/1–31/12, che comprende l\'uscita: nel rientro di Ines il prospetto le dà già la strada B, e Ugo le restituisce la parte dei suoi giorni', function () {
    // Il caso vicino che il codice fa già giusto: la fine del 1/7 cade dentro i giorni della voce. L'entrata all'inquilino, −12000 sui 365
    // giorni: a Ines prima dell'uscita 12000 × 181/365 = 5950,68 → −5951 nelle sue rate; dopo −6049, presa da Ugo con la coppia (18148 −
    // 6049 = 12099). Ines conduttrice dal 1/10 ne ha 92 su 184: 6049 / 2 = 3024,5 per parte, a pari resto il centesimo ai giorni del
    // conduttore, che vengono prima → Ines −3025, senza conduttore −3024. Ines: totale 17852 − 5951 + 9074 − 3025 = 17950; nelle sue rate
    // 17852 − 5951 = 11901; da rimborsare 9074 − 3025 = 6049.
    $s = ugbDopoLaFine($this, ['Affitto antenna', 'entrata', 40000, '2026-01-01', '2026-12-31']);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $p = pflProspetto($s);

    expect(fclSaldi($s))->toBe(['Ines' => -12099, 'Ugo' => 12099])
        ->and(ugbVoci($p, 'Ines'))->toBe([
            ['Affitto antenna', 'proprietario', 'Ugo', -3025, 92], ['Affitto antenna', 'rate', null, -5951, 181],
            ['Spese generali', 'proprietario', 'Ugo', 9074, 92], ['Spese generali', 'rate', null, 17852, 181],
        ])
        ->and(ugbTotali($p, 'Ines'))->toBe([17950, 11901, 6049])
        ->and(ugbSenza($p))->toBe([['Ugo', 'Affitto antenna', -3024, 92], ['Ugo', 'Spese generali', 9074, 92]])
        ->and((int) $p['totale_inquilino'])->toBe(24000);
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 2: le frasi della fermata ridotta e del blocco «Rate» quando la parte di chi entra è un credito
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La fine di Ines del 1/7 con «Scrivi» (con l'antenna di entrata a competenza 1/10–31/12, o senza), la vendita di Ugo a Elsa del 1/10
 * fermata, le rate a giornale fino al 30/11, e l'inizio di Luca il 1/12: l'anteprima e, registrato, la voce dello storico dell'inizio.
 *
 * @return array{0: array, 1: array, 2: Subentro}
 */
function ugbLucaDopoLaFermata($test, bool $conLAntenna): array
{
    $s = ugbDopoLaFine($test, $conLAntenna ? ['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'] : null);
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Luca');
    $vendita = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    fclEmetti($test, $s, '2026-11-30');
    $a = fclAnteprima($test, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $inizio = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'))['subentro'];

    return [$a, fds50Storico($s, $inizio), $vendita];
}

it('punto 2, la fermata ridotta con il credito — dopo la vendita fermata su −€ 29,26 verso Elsa, Luca entra il 1/12: la frase del pannello, il motivo del cancello, lo storico e il blocco «Rate» non dicono che Luca rimborsa, ma che riceve', function () {
    // I giorni di Luca, 1/12–31/12: le spese 18148 × 31/184 = 3057,54, l'entrata −12000 × 31/92 = −4043,48: −985,93, un credito di Luca
    // (nel prospetto, voce per voce, 3058 − 4043 = −985: punto 1). Chi ha la coppia, Ugo (o Elsa, se ha già regolato la fermata con
    // lui), gli restituisce la differenza. Prima della correzione `fraseRidotta`, `motivoRidotta` e `fraseStoricoRidotta` dicevano «i
    // giorni dal 1 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori», e il blocco «Rate» (`dopoLUscitaDiPrima`) «li
    // rimborsa Luca a Ugo».
    [$a, $storico, $vendita] = ugbLucaDopoLaFermata($this, true);
    $ridotta = ugbCon($a['rate']['frasi'], '€ 19,40');
    $motivo = ugbCon($a['cancello']['motivi'], '€ 19,40');
    $nelloStorico = ugbCon($storico['fermate'] ?? [], '€ 19,40');
    $rate = ugbBloccoRate($a);

    // Controlli: la fermata della vendita, la riduzione a −€ 19,40 con Elsa, e le quattro frasi che ci sono.
    expect(ugbFermate(fds50Fermate($vendita)))->toBe([[-2926, ['Elsa']]])
        ->and(collect($a['rate']['fermate_ridotte'])->map(fn ($f) => [(int) $f['cifra'], (int) $f['residuo']])->all())->toBe([[-2926, -1940]])
        ->and([$ridotta, $motivo, $nelloStorico, $rate])->each->not->toBeNull()
        ->and((string) $rate)->toContain('Ugo');

    foreach (['la frase del pannello' => $ridotta, 'il motivo del cancello' => $motivo, 'lo storico' => $nelloStorico, 'il blocco «Rate»' => $rate] as $dove => $t) {
        expect(ugbChiEntraRimborsa((string) $t, 'Luca'))->toBeFalse(sprintf('In %s Luca rimborsa i giorni che sono un suo credito: %s', $dove, $t))
            ->and(ugbChiEntraRiceve((string) $t, 'Luca'))->toBeTrue(sprintf('In %s non si dice che Luca riceve: %s', $dove, $t))
            ->and((string) $t)->not->toContain('€ -');
    }
});

it('punto 2, il blocco «Rate» con il credito — dopo la fine di Ines del 1/7 con «Scrivi» e l\'antenna a competenza 1/10–31/12, Luca entra il 1/10: il pannello non dice «li rimborsa Luca a Ugo», ma che Ugo gli restituisce la differenza', function () {
    // I giorni di Luca, 1/10–31/12: le spese 9074, l'entrata −12000: −2926, un credito di Luca (punto 1). Nel rientro di Ines lo stesso
    // blocco lo dice già (`presaConLaFine`: «in quei giorni l'entrata supera la spesa: Ugo restituisce la differenza a Ines»); con un nuovo
    // conduttore no: «li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo».
    $s = ugbDopoLaFine($this);
    fclPersona($s, 'Luca');
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $rate = ugbBloccoRate($a);

    // Controlli: nessuna fermata da ridurre, il blocco c'è e nomina chi ha la coppia.
    expect($a['rate']['fermate_ridotte'])->toBe([])
        ->and($rate)->not->toBeNull()
        ->and((string) $rate)->toContain('Ugo');

    expect(ugbChiEntraRimborsa((string) $rate, 'Luca'))->toBeFalse('Il blocco «Rate» fa rimborsare a Luca un suo credito: ' . $rate)
        ->and(ugbChiEntraRiceve((string) $rate, 'Luca'))->toBeTrue('Il blocco «Rate» non dice che Luca riceve: ' . $rate)
        ->and((string) $rate)->not->toContain('€ -');
});

it('punto 2, il controllo — senza l\'antenna la parte di Luca è un debito: dopo la fermata (€ 90,74 verso Elsa) e senza fermata, le frasi dicono già che Luca rimborsa, e così deve restare', function () {
    // Con le sole spese i giorni di Luca sono una spesa: la fermata ridotta (dal 1/12, € 90,74 → € 60,16 con Elsa) e il blocco «Rate» dicono
    // «li rimborsa Luca», ed è il verso giusto. Una correzione del punto 2 non lo tocca.
    [$a, $storico] = ugbLucaDopoLaFermata($this, false);
    expect(collect($a['rate']['fermate_ridotte'])->map(fn ($f) => [(int) $f['cifra'], (int) $f['residuo']])->all())->toBe([[9074, 6016]]);
    foreach ([ugbCon($a['rate']['frasi'], '€ 60,16'), ugbCon($a['cancello']['motivi'], '€ 60,16'), ugbCon($storico['fermate'] ?? [], '€ 60,16')] as $t) {
        expect(ugbChiEntraRimborsa((string) $t, 'Luca'))->toBeTrue('La frase non dice che Luca rimborsa: ' . $t);
    }
    expect((string) ugbBloccoRate($a))->toContain('li rimborsa Luca a Ugo');

    $s = ugbDopoLaFine($this, null);
    fclPersona($s, 'Luca');
    expect((string) ugbBloccoRate(fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))))->toContain('li rimborsa Luca a Ugo');
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 3: le due righe di G2 in `FermataDopoScrivi` che decidono se c'è una fermata
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La misura della revisione delle correzioni del giro stretto: il piano a capitoli con l'«Affitto antenna» € 400,00 di entrata a
 * competenza 1/10–31/12, a giornale fino al 30/9; la vendita di Ugo a Elsa del 1/10 registrata prima, poi la fine di Ines del 1/7 con
 * «Scrivi»; le rate a giornale fino al 31/10, e Carlo. La coppia della fine divide i due pezzi con il loro segno: Ugo (1/7–30/9: 92 giorni
 * delle spese, niente dell'entrata) 18148 × 92/184 = 9074; Elsa (1/10–31/12: 92 giorni delle spese e tutta l'entrata) 9074 − 12000 =
 * −2926; Ines −6148. Elsa sta al posto dell'inquilino con una riga a credito.
 */
function ugbElsaConLaRigaACredito($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    ugbCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($test, $s, '2026-09-30');
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-10-31');

    // Controllo: la coppia della fine, con le parti di segno diverso.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Elsa' => -2926, 'Ines' => -6148, 'Ugo' => 9074]);

    return $s;
}

it('punto 3, `coppieScritte` — Elsa, con la riga −€ 29,26 nella coppia, vende a Carlo il 1/11: la vendita si ferma sulla parte di Carlo, −€ 19,40, dalla riga di Elsa, con il saldo manuale a debito di Elsa e a credito di Carlo', function () {
    // Carlo dal 1/11: 61 giorni delle spese, 18148 × 61/184 = 6016,46, e 61 dei 92 dell'entrata, −12000 × 61/92 = −7956,52: 61 × (18148 −
    // 24000)/184 = −356972/184 = −1940,07. Elsa resta con il 1/10–31/10: −181412/184 = −985,93; Ugo 9074. La divisione di 6148 sui pesi
    // 9074 / −985,93 / −1940,07: le parti intere 9074 / −986 / −1941 sommano 6147, il centesimo al resto più grande (Carlo, ,93) → Carlo
    // −1940, Elsa −986. Prima della vendita Elsa −2926: a Carlo passano −1940 − 0.
    // La fermata è di Elsa, che ha una riga nella coppia: la trova `coppieScritte`, che riconosce la parte di chi sta al posto dell'inquilino
    // da chi è uscito con la fine, non dal segno della riga. Con il segno (le righe > 0) Elsa non avrebbe una coppia, e `dopoUnaFermata` la
    // salta perché ha una riga: nessuna fermata, € 19,40 in silenzio.
    $s = ugbElsaConLaRigaACredito($this);
    $vendita = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01');
    $a = fclAnteprima($this, $s, $vendita);
    $f = $a['rate']['fermate_dopo_scrivi'];

    expect(ugbFermate($f))->toBe([[-1940, ['Carlo']]], 'La vendita di Elsa non si ferma sulla parte di Carlo, −€ 19,40: ' . json_encode($f, JSON_UNESCAPED_UNICODE))
        ->and([$f[0]['persona'] ?? null, (int) ($f[0]['coppia'] ?? 0), $f[0]['wallet'] ?? null, array_key_exists('uscente_id', $f[0] ?? [])])->toBe(['Elsa', -2926, true, false]);

    // Il pannello e il cancello lo dicono, con il verso del credito: il saldo manuale a debito di Elsa e a credito di Carlo.
    $frase = (string) ugbCon($a['rate']['frasi'], '€ 19,40');
    expect($frase)->toContain('a debito di Elsa')
        ->and($frase)->toContain('a credito di Carlo')
        ->and($frase)->not->toContain('€ -')
        ->and((string) ugbCon($a['cancello']['motivi'], '€ 19,40'))->toContain('Elsa');

    // Registrata: il registro conserva la fermata, lo storico la dice, e il saldo manuale che indica porta a Carlo il suo credito.
    $passaggio = fds50Registra($this, $s, $vendita)['subentro'];
    expect(ugbFermate(fds50Fermate($passaggio)))->toBe([[-1940, ['Carlo']]])
        ->and((string) ugbCon(fds50Storico($s, $passaggio)['fermate'] ?? [], '€ 19,40'))->toContain('Elsa')
        ->and(ugbWalletDelleFermate($s, $passaggio))->toBe(['Carlo' => -1940, 'Elsa' => 1940]);
});

it('punto 3, il salto di `dopoUnaFermata` — la stessa vendita di Elsa a Carlo: la fermata è una sola, nel pannello, nel cancello, nel registro e nello storico, e il saldo manuale si scrive una volta', function () {
    // `per` dice già la fermata di chi ha una riga nella coppia; `dopoUnaFermata` salta Elsa perché ha una riga (`isset($c['righe'][$chi])`).
    // Senza il salto, la riga di Elsa la fa leggere come la parte di un erede (`chiHaLaCoppia`), e la stessa fermata si dice due volte:
    // [−1940, −1940], due motivi del cancello, e due saldi manuali dal Wallet che darebbero a Carlo € 38,80 di credito invece di € 19,40.
    $s = ugbElsaConLaRigaACredito($this);
    $vendita = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01');
    $a = fclAnteprima($this, $s, $vendita);

    expect(ugbFermate($a['rate']['fermate_dopo_scrivi']))->toBe([[-1940, ['Carlo']]], 'La fermata non è una sola: ' . json_encode(ugbFermate($a['rate']['fermate_dopo_scrivi'])))
        ->and(count(array_filter($a['rate']['frasi'], fn (string $t) => str_contains($t, '€ 19,40'))))->toBe(1)
        ->and(count(array_filter($a['cancello']['motivi'], fn (string $t) => str_contains($t, '€ 19,40'))))->toBe(1);

    $passaggio = fds50Registra($this, $s, $vendita)['subentro'];
    expect(count(fds50Fermate($passaggio)))->toBe(1)
        ->and(count(array_filter(fds50Storico($s, $passaggio)['fermate'] ?? [], fn (string $t) => str_contains($t, '€ 19,40'))))->toBe(1)
        ->and(ugbWalletDelleFermate($s, $passaggio))->toBe(['Carlo' => -1940, 'Elsa' => 1940]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 4: R2 nel ramo per periodo voce per voce di `ConguaglioPassaggio`
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La misura R2p della revisione: il «Preventivo 2026» (generato) e un secondo piano sulla stessa gestione con la sola «Affitto antenna»
 * € 400,00 di entrata a competenza 1/1–31/12, a capitoli, generato; le rate dei due fino al 30/6 a giornale; la riga di Ugo chiusa il
 * 30/9 (dal 1/10 nessuno sta al posto dell'inquilino). Le frasi del calcolo della fine di Ines del 1/7.
 *
 * @return array{0: array, 1: list<string>}
 */
function ugbDuePianiConLAntenna($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $secondo = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Secondo piano 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    ugbCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-01-01', '2026-12-31', $secondo);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($secondo->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del secondo piano', esercizio: $s['e']);
    fclEmetti($test, $s, '2026-06-30');
    $rate = DB::table('rate')->where('piano_rate_id', $secondo->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', '2026-06-30 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $secondo]), ['rate_ids' => $rate, 'data_emissione' => '2026-06-30', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);
    $a = fclAnteprima($test, $s, fclModulo($s));

    // Controlli: la coppia, a Ugo, e la frase dei giorni scoperti.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 6049]])
        ->and(implode(' | ', $a['rate']['conguaglio']['frasi']))->toContain('la parte di quei giorni, € 60,50, resta nelle rate di Ines');

    return [$s, $a['rate']['conguaglio']['frasi']];
}

/**
 * La parte a chi entra di una riga per periodo («— …»), con il segno: la cifra dopo la freccia, negativa se ha il segno meno o se la riga la
 * dice «a credito». Null se la riga non la dice.
 */
function ugbAChiEntraDellaRiga(string $riga): ?int
{
    if (preg_match('/→\s*(.*)$/u', $riga, $m) !== 1 || preg_match('/€ (-?)([\d.]+,\d{2})/u', $m[1], $n) !== 1) {
        return null;
    }
    $cent = (int) str_replace(['.', ','], '', $n[2]);

    return $n[1] === '-' || str_contains($m[1], 'credito') ? -$cent : $cent;
}

it('punto 4, R2 per periodo voce per voce — il «Preventivo 2026» e un secondo piano con la sola antenna di entrata, la riga di Ugo chiusa il 30/9: la riga dell\'antenna dice i 92 giorni di Ugo e € 30,24 a credito di chi entra, la sua testa la parte dei giorni coperti, e le righe sommano alla coppia, € 60,49', function () {
    // Il preventivo: dopo l'uscita 36000 × 184/365 = 18147,95 → 18148. L'antenna: all'inquilino −12000 sui 365 giorni, dopo l'uscita
    // 12000 × 184/365 = 6049,32 → −6049. La coppia: Ugo copre il 1/7–30/9, 92 dei 184 giorni: (18148 − 6049) × 92/184 = 6049,5 → 6049 (a
    // pari resto il centesimo a chi esce); la parte scoperta 12099 − 6049 = 6050.
    // `copertiDelGruppo`: la parte scoperta di ogni riga per i suoi giorni, arrotondata: il preventivo 18148 × 92/184 = 9074, l'antenna
    // −6049 × 92/184 = −3024,5 → −3025; sommano 6049, un centesimo meno della parte scoperta, 6050: il centesimo va alla voce più grande, il
    // preventivo → 9075. Le parti coperte: il preventivo 18148 − 9075 = 9073 (€ 90,73), l'antenna −6049 + 3025 = −3024 (€ 30,24 a credito):
    // sommano 6049, la coppia. Senza il centesimo il preventivo direbbe € 90,74 e le righe € 60,50; senza i giorni coperti la riga
    // dell'antenna direbbe «184 a Ugo → € -60,49 a chi entra», e la testa € 60,49.
    [, $frasi] = ugbDuePianiConLAntenna($this);
    $righe = array_values(array_filter($frasi, fn (string $f) => str_starts_with($f, '— ')));
    $preventivo = (string) ugbCon($righe, '€ 360,00');
    $testa = (string) ugbCon($righe, 'divisi voce per voce');
    $antenna = (string) ugbCon($frasi, '· Affitto antenna');

    expect($preventivo)->toContain('184 dopo l\'uscita, di cui 92 a Ugo')
        ->and($preventivo)->toContain('€ 90,73 a chi entra')
        ->and($antenna)->toContain('184 dopo l\'uscita, di cui 92 a Ugo')
        ->and($antenna)->toContain('€ 30,24 a credito di chi entra')
        ->and($antenna)->not->toContain('184 a Ugo')
        ->and($testa)->toContain('30,24')
        ->and($testa)->not->toContain('60,49')
        ->and(array_map('ugbAChiEntraDellaRiga', $righe))->toBe([9073, -3024], 'Le righe per periodo non dicono le parti dei giorni coperti: ' . implode(' | ', $righe))
        ->and(array_sum(array_map('ugbAChiEntraDellaRiga', $righe)))->toBe(6049);
});

it('punto 4, la testa — la stessa riga per periodo voce per voce dice l\'importo dell\'entrata e la parte di chi entra con le parole, non «€ -120,00» e «€ -30,24»', function () {
    // L'importo della riga è l'entrata all'inquilino, −12000; la parte dei giorni coperti −3024. Prima della correzione la testa
    // scriveva tutti e due con il segno meno («— € -120,00 (12 quote) divisi voce per voce, … → € -30,24 a chi entra:»), mentre la riga
    // della voce sotto diceva già il credito con le parole.
    [, $frasi] = ugbDuePianiConLAntenna($this);
    $righe = array_values(array_filter($frasi, fn (string $f) => str_starts_with($f, '— ')));
    $testa = (string) ugbCon($righe, 'divisi voce per voce');
    $primaDellaFreccia = (string) strstr($testa, '→', true);

    // Controlli: la riga della voce sotto la testa dice già il credito con le parole; la riga del preventivo non ha il segno meno.
    expect((string) ugbCon($frasi, '· Affitto antenna'))->toContain('€ 30,24 a credito di chi entra')
        ->and((string) ugbCon($righe, '€ 360,00'))->not->toContain('€ -')
        ->and($testa)->not->toBe('');

    expect(str_contains($testa, '€ -'))->toBeFalse('La testa dice una cifra negativa con il segno meno: ' . $testa)
        ->and($testa)->toContain('€ 120,00')
        ->and(preg_match('/entrat|credit/u', $primaDellaFreccia))->toBe(1, 'La testa non dice che € 120,00 sono un\'entrata: ' . $testa)
        ->and(preg_match('/→[^:]*€ 30,24[^:]*credito/u', $testa))->toBe(1, 'La testa non dice € 30,24 a credito di chi entra: ' . $testa);
});

// ---------------------------------------------------------------------------------------------------------------------
// Punto 5: i pagatori dopo l'uscita tagliati per giorni, con una coppia di segno misto
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Lo scenario di G2: il piano a capitoli con l'«Affitto antenna» € 1.000,00 di entrata a competenza 1/1–30/9, a giornale fino al 31/8; la
 * vendita di Ugo a Elsa del 1/9 registrata prima, poi la fine di Ines del 1/7 con «Scrivi». I pezzi della coppia: le spese 18148 sul
 * 1/7–31/12; l'entrata all'inquilino 100000 × 30 % = 30000 sui 273 giorni della competenza, di cui 92 dopo l'uscita: 30000 × 92/273 =
 * 10109,89 → −10110 sul 1/7–30/9. Le parti: Ugo, 1/7–31/8 (62 giorni dei due pezzi), 18148 × 62/184 − 10110 × 62/92 = 6115,09 − 6813,26 =
 * −698,17; Elsa, 1/9–31/12 delle spese (122) e 1/9–30/9 dell'entrata (30), 12032,91 − 3296,74 = 8736,17.
 */
function ugbCoppiaDiSegnoMisto($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    ugbCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-09-30');

    return $s;
}

it('punto 5 — la coppia di segno misto (Ugo −€ 6,98 per il 1/7–31/8, Elsa € 87,36 per il 1/9–31/12) e l\'inizio di Luca il 1/10: i giorni di Luca li ha pagati con la coppia solo Elsa, e il pannello e il prospetto glieli fanno rimborsare a lei, non a Ugo e Elsa', function () {
    // Chi ha pagato i giorni dopo l'uscita, con la coppia, giorno per giorno: Ugo il 1/7–31/8, Elsa dal 1/9 (la vendita registrata prima
    // della fine ha diviso la coppia così). Il prospetto, voce per voce, con i pagatori tagliati per giorni: - le spese, 18148 sui 184
    // giorni: Ugo 62, 18148 × 62/184 = 1125176/184 = 6115,09 → 6115; Elsa 122, 2214056/184 = 12032,91 → 12033 (il centesimo al resto più
    // grande). Il pezzo di Elsa sui conduttori: Luca 1/10–31/12, 92 dei 122 giorni, 12033 × 92/122 = 1107036/122 = 9074,07 → 9074, pagato
    // da Elsa; il 1/9–30/9 senza conduttore, 2958,93 → 2959; - l'entrata, −10110 sui 92 giorni 1/7–30/9: Ugo 62, 10110 × 62/92 = 6813,26 →
    // −6813; Elsa 30, 3296,74 → −3297 (resto più grande): Luca non ha giorni lì, tutto senza conduttore. Le parti tornano con la coppia:
    // Ugo 6115 − 6813 = −698, Elsa 12033 − 3297 = 8736. Prima della correzione `pagatoriDopoLUscita` metteva tutti quelli con una riga
    // nella coppia in un pezzo solo per tutti i giorni: il pannello diceva «li rimborsa Luca a Ugo e Elsa», il prospetto «pagata da Ugo e
    // Elsa». (Il test «G2, il prospetto» di `StrettoBeta50MotoreTest` voleva ogni voce senza conduttore pagata da Ugo ed Elsa insieme,
    // scritto sui pagatori non tagliati: è stato riscritto.)
    $s = ugbCoppiaDiSegnoMisto($this);
    fclPersona($s, 'Luca');
    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))['rate']['frasi']);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $p = pflProspetto($s);
    preg_match_all('/li rimborsa Luca ad? ([^,.;:]+)/u', $frasi, $m);

    // Controlli: la coppia; la cifra di Luca, € 90,74, che non cambia; il pannello che non fa rimborsare Ines (G2); il totale.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Elsa' => 8736, 'Ines' => -8038, 'Ugo' => -698])
        ->and(pflConduttori($p)['Luca'] ?? null)->toBe([9074, 0, 9074, 92])
        ->and($m[1])->not->toBe([])
        ->and($frasi)->not->toMatch('/li rimborsa Luca a [^.;]*Ines/u')
        ->and((int) $p['totale_inquilino'])->toBe(6000);

    expect(array_values(array_unique(array_map('trim', $m[1]))))->toBe(['Elsa'], 'Il pannello dell\'inizio fa rimborsare a Luca chi non ha pagato i suoi giorni: ' . $frasi)
        ->and(pflPagatori(pflSezione($p, 'Luca')['voci'] ?? []))->toBe(['Elsa'])
        ->and(ugbSenza($p))->toBe([
            ['Elsa', 'Affitto antenna', -3297, 30], ['Elsa', 'Spese generali', 2959, 30],
            ['Ugo', 'Affitto antenna', -6813, 62], ['Ugo', 'Spese generali', 6115, 62],
        ]);
});
