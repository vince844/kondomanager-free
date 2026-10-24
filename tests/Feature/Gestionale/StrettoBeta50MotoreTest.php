<?php

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — l'area del motore: la fermata dopo «Scrivi» con una parte negativa
 * (G1) e con una coppia di parti di segno diverso (G2), la catena del prospetto dopo un cambio «regolato» sulla pertinenza (G3), il
 * coinquilino della fine letto com'era alla registrazione senza appoggiarsi all'ordine degli id delle righe (G4), il già versato del
 * pezzo «regolato» (decisione 84.1), e i pezzi delle correzioni di R2 e R8 che nessun test teneva o che restavano sbagliati: il già versato
 * «spostato» e l'importo del gruppo di R2, la frase della quota divisa per giorni con i giorni scoperti (il ramo semplice, quello voce per
 * voce e quello per periodo), il ripiego di R8 per il piano della 1.10.
 *
 * Lo scenario è quello di `ScenariFineLocazione.php` e di `ScenariProspettoLocazione.php`: «Spese generali» € 1.200,00 sulla tabella
 * «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate mensili; Ines inquilina dal
 * 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181, 184] / 365 = 17852,05 / 18147,95 → la coppia
 * 18148 (€ 181,48). Gli oracoli sono fatti a mano, in frazioni esatte, con la regola della coppia (le parti intere, il centesimo al resto
 * più grande, a pari resto a chi esce).
 *
 * Nati come test rossi: ognuno descrive un rilievo del giro stretto, corretto prima del commit, e ha accanto un controllo (la stessa
 * scena prima del passo che la guastava, o il caso vicino che il codice faceva già giusto). Gli altri sono guardie: tengono un pezzo di
 * correzione già giusto che nessun test teneva, e tornano rossi togliendo quel pezzo.
 *
 * Il segno della fermata con una parte negativa (G1) è quello della correzione verificata del giro stretto: la cifra è la parte di
 * chi entra con il suo segno («dopo − prima»), e il saldo manuale dal Wallet che la fermata indica è a credito di chi resta a carico per
 * la cifra e a debito di chi riceve per la cifra, con il segno (per una cifra negativa: a debito del primo, a credito del secondo).
 *
 * Che cosa NON copre:
 * - le frasi nuove parola per parola: si cercano la cifra, il nome e la parola che conta, che le frasi di una fermata non dicano la cifra
 *   con il segno meno, e per una cifra negativa il verso (il saldo manuale a credito di chi entra, niente «restano a carico di», chi
 *   restituisce a chi), non la frase intera, che scriverà chi corregge; la frase del piano fermo nel rientro («e Ines gliela rimborsa»,
 *   senza la cifra) resta fuori;
 * - G1: lo storico (`fraseStorico`, `fraseStoricoRidotta`) e gli avvisi di «Annulla il conguaglio» con una fermata negativa (si fissano il
 *   pannello, il cancello, il registro e il denaro); il prospetto del rientro con l'entrata (Ines € 120,00 a suo danno, trovato nella
 *   verifica di G1 e non provato con una mutazione: da aprire a parte); le frasi del calcolo del conguaglio della vendita stessa con
 *   l'entrata («€ -280,00 a chi entra», «la parte di Elsa sull'intero piano è € -68,27»), che c'erano prima della .50;
 * - G2: la coppia tutta negativa (l'antenna che supera le spese in tutti i giorni dopo l'uscita), che la correzione lascia senza fermata
 *   (limite da dichiarare, punto 4 della correzione verificata); il ripiego di `comeSonoUsciti` con una coppia di segno misto, che
 *   sul piano della 1.10 non si costruisce;
 * - G3: la stampa del box, e la fine «Scrivi» di Luca che sul box (o su un piano) non scrive la coppia: senza quel caso il test non
 *   distingue le righe del capo su quell'unità e gestione da una lettura senza il filtro sull'unità o senza le righe, e il caso non ha
 *   ancora un oracolo deciso; G4: i passaggi registrati prima della .50 (senza la scelta nel registro), che la correzione può lasciare
 *   alle righe, e la lettura dell'anteprima dell'inizio retrodatato;
 * - la frase voce per voce: l'«Affitto antenna: € -120,00 … → € -40,44 a chi entra» della stessa frase, che c'era già prima della .50, e la
 *   testa «da quote emesse a intestatari diversi» della frase per periodo con due piani sulla stessa gestione;
 * - il ripiego di R8: il nome di chi è uscito con «Non scriverlo» (`$pagatoDa[] = …`), che la frase non distingue da chi è uscito
 *   (`dopoLUscitaDiPrima` ripiega sui nomi degli usciti); si tengono il ripiego intero, `presoNelConguaglioDa` e il filtro sulla gestione
 *   ciascuno da solo, e «scritto» solo con le righe della coppia sull'unità;
 * - le pagine Vue, i PDF resi da browser, MySQL (l'id ripreso di G4 si riproduce sul contatore di SQLite).
 */

use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
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
 * Il piano a capitoli: «Spese generali» € 1.200,00 per tutto l'anno e «Affitto antenna», un conto di entrata, con la competenza data, sulla
 * stessa tabella e con la stessa divisione (30 % «Inquilino», 70 % «Proprietario»). Prima della generazione. È `rbmConLAntenna` di
 * `RevisioneBeta50MotoreTest`, che sta in un file di test e non si può includere.
 */
function sbmConLAntenna(array $s, int $importo, string $dal, string $al): void
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

/** Le voci «senza conduttore» del prospetto di un'unità (l'Interno 1 se non si dice): [pagata da, importo, giorni], in ordine. */
function sbmSenza(array $s, ?Immobile $unita = null): array
{
    $p = app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);

    return collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0] . '|' . $v[1])->values()->all();
}

/**
 * Le frasi del pannello e i motivi del cancello che dicono una cifra (le frasi di una fermata), e fra queste quelle che la dicono con il
 * segno meno («€ -29,26»): una cifra negativa si dice con le parole. Le altre frasi non si guardano: quelle del calcolo del conguaglio
 * della vendita stessa dicono già «€ -280,00» per l'entrata dell'antenna (c'era prima della .50, fuori da questi rilievi).
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function sbmSullaCifra(array $a, string $cifra): array
{
    $con = array_values(array_filter([...($a['rate']['frasi'] ?? []), ...($a['cancello']['motivi'] ?? [])], fn (string $t) => str_contains($t, $cifra)));

    return [$con, array_values(array_filter($con, fn (string $t) => str_contains($t, '€ -') || str_contains($t, '-' . $cifra)))];
}

/** Le fermate dopo «Scrivi» di un'anteprima o di un registro, in breve: [cifra, verso]. */
function sbmFermate(array $fermate): array
{
    return collect($fermate)->map(fn ($f) => [(int) $f['cifra'], array_values((array) $f['verso'])])->all();
}

/**
 * Il saldo manuale dal Wallet che le fermate di un passaggio indicano, scritto come lo scrive il Wallet: a credito di chi resta a carico
 * per la cifra e a debito di ciascuno che riceve per la sua parte, con il segno della cifra. Solo dove la fermata indica il Wallet.
 */
function sbmWalletDelleFermate(array $s, Subentro $passaggio): void
{
    foreach (fds50Fermate($passaggio) as $f) {
        expect($f['wallet'] ?? false)->toBeTrue('La fermata non indica il saldo manuale dal Wallet: ' . json_encode($f, JSON_UNESCAPED_UNICODE));
        fclRiporto($s, (string) $f['persona'], -(int) $f['cifra']);
        foreach ($f['parti'] as $p) {
            fclRiporto($s, (string) $p['nome'], (int) $p['cifra']);
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// G1: con i pezzi con il segno (R3) la parte di chi entra dopo l'uscita può essere negativa
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il piano a capitoli con l'«Affitto antenna» € 400,00 di entrata a competenza 1/10–31/12, generato, sei rate a giornale, e la fine di Ines
 * il 1/7 con «Scrivi». La coppia è di due pezzi: le spese 18148 sul 1/7–31/12, l'entrata all'inquilino 40000 × 30 % = 12000, tutta sul
 * 1/10–31/12 (92 giorni, tutti dopo l'uscita), −12000. Il totale 6148, a Ugo. Poi le rate a giornale fino al giorno dato.
 */
function sbmAntennaDopoLUscita($test, string $emessoFino = '2026-09-30'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    sbmConLAntenna($s, 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // Controlli: la coppia e i due pezzi del registro, con il loro segno.
    expect(fclSaldi($s))->toBe(['Ines' => -6148, 'Ugo' => 6148])
        ->and(collect($s['fine']->registro['conguaglio']['periodi'][0]['periodi'] ?? [])->map(fn ($p) => [$p['tratti'][0]['dal'], $p['tratti'][0]['al'], (int) $p['importo']])->all())
        ->toBe([['2026-07-01', '2026-12-31', 18148], ['2026-10-01', '2026-12-31', -12000]]);
    fclEmetti($test, $s, $emessoFino);

    return $s;
}

it('G1 — dopo la fine di Ines del 1/7 con «Scrivi», la vendita di Ugo a Elsa il 1/10 con l\'antenna di entrata nei giorni di Elsa: la vendita si ferma su € 29,26 a credito di Elsa, e con il saldo manuale indicato i netti sono quelli fatti a mano (Ugo € 719,01, Elsa −€ 97,53)', function () {
    // Le parti dei giorni dopo l'uscita, sui due pezzi della coppia: Ugo ha il 1/7–30/9 (92 giorni) delle spese, 18148 × 92/184 = 9074, e
    // niente dell'entrata; Elsa ha il 1/10–31/12 (92) delle spese, 9074, e tutta l'entrata, −12000: 9074 − 12000 = −2926. Prima della
    // vendita Ugo aveva 6148; dopo dovrebbe avere 9074, Elsa −2926: la parte di Elsa (€ 29,26 a suo credito) non passa da sola. Il piano
    // generato dopo, a mano: Ines 36000 × 181/365 = 17852,05 → 17852; Ugo 84000 × 273/365 + 36000 × 92/365 = 26244000/365 = 71901,37 →
    // 71901; Elsa (84000 + 36000) × 92/365 − 40000 = 11040000/365 − 40000 = −9753,42 → −9753. Somma 80000 = 120000 − 40000. Prima della
    // correzione `FermataDopoScrivi::per` saltava la fermata: il peso di Ugo saliva (da 6148 a 9074) invece di calare, e la cifra era ≤
    // 0. Nessuna frase e nessun motivo del cancello: Ugo pagava € 29,26 in meno ed Elsa riceveva € 29,26 in meno, in silenzio.
    $s = sbmAntennaDopoLUscita($this);
    fclPersona($s, 'Elsa');
    $vendita = fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');

    $a = fclAnteprima($this, $s, $vendita);
    [, $colMeno] = sbmSullaCifra($a, '29,26');

    expect(sbmFermate($a['rate']['fermate_dopo_scrivi']))->toBe([[-2926, ['Elsa']]], 'La vendita non si ferma sulla parte di Elsa, € 29,26 a suo credito: ' . json_encode($a['rate']['fermate_dopo_scrivi']))
        ->and(collect($a['cancello']['motivi'])->contains(fn (string $m) => str_contains($m, '€ 29,26')))->toBeTrue('Nessun motivo del cancello dice € 29,26: ' . implode(' | ', $a['cancello']['motivi']))
        ->and(collect($a['rate']['frasi'])->contains(fn (string $f) => str_contains($f, '€ 29,26') && str_contains($f, 'Elsa') && str_contains($f, 'Ugo')))->toBeTrue('Nessuna frase del pannello dice € 29,26 fra Ugo ed Elsa: ' . implode(' | ', $a['rate']['frasi']))
        ->and($colMeno)->toBe([]);

    // Il verso, nelle frasi che dicono la cifra: il saldo manuale è a debito di Ugo e a credito di Elsa, e nessuna frase dice che la cifra
    // resta «a carico» di Ugo. Le frasi con il solo valore assoluto e il verso della cifra positiva («€ 29,26 restano a carico di Ugo …
    // € 29,26 a credito di Ugo e € 29,26 a debito di Elsa») porterebbero il saldo manuale nel verso sbagliato.
    [$conLaCifra] = sbmSullaCifra($a, '29,26');
    expect(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'a credito di Elsa')))->toBeTrue('Nessuna frase indica il saldo manuale a credito di Elsa: ' . implode(' | ', $conLaCifra))
        ->and(array_values(array_filter($conLaCifra, fn (string $t) => str_contains($t, 'a debito di Elsa') || str_contains($t, 'restano a carico di Ugo'))))->toBe([]);

    // Registrata, con il saldo manuale dal Wallet che la fermata indica: i netti del piano generato dopo.
    $passaggio = fds50Registra($this, $s, $vendita)['subentro'];
    expect(sbmFermate(fds50Fermate($passaggio)))->toBe([[-2926, ['Elsa']]]);
    sbmWalletDelleFermate($s, $passaggio);
    expect(fds50Netti($s))->toBe(['Elsa' => -9753, 'Ines' => 17852, 'Ugo' => 71901]);
});

it('G1, il controllo — la vendita di Ugo a Elsa del 1/10 registrata prima, poi la fine di Ines del 1/7 con «Scrivi»: la coppia divide i due pezzi con il loro segno (Ugo € 90,74, Elsa −€ 29,26), e i netti sono quelli fatti a mano', function () {
    // L'oracolo del test sopra, dato dalla coppia quando la vendita è già nei dati: Ugo 9074, Elsa −2926, Ines −6148.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    sbmConLAntenna($s, 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Elsa');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(fds50Coppia($s, $fine))->toBe(['Elsa' => -2926, 'Ines' => -6148, 'Ugo' => 9074])
        ->and(fds50Netti($s))->toBe(['Elsa' => -9753, 'Ines' => 17852, 'Ugo' => 71901]);
});

it('G1, il rientro — dopo la fine di Ines del 1/7 con «Scrivi», Ines rientra il 1/10: la sua parte dei giorni della coppia è −€ 29,26, e il pannello dice che è Ugo a doverle € 29,26, invece di tacere', function () {
    // Ines conduttrice dal 1/10: sulle spese ha il 1/10–31/12, 18148 × 92/184 = 9074; sull'entrata tutto il pezzo, −12000: 9074 − 12000 =
    // −2926. Ugo ha preso con la coppia i giorni dal 1/7 delle spese e l'entrata di ottobre–dicembre: nei giorni di Ines l'entrata supera
    // la spesa, e la differenza la deve lui a lei. Prima della correzione `FermataDopoScrivi::rientro` scartava la cifra ≤ 0: nessuna
    // frase.
    $s = sbmAntennaDopoLUscita($this);

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));
    $rientro = collect($a['rate']['rinnovo_dopo_scrivi']);

    expect($rientro->map(fn ($f) => [(int) $f['cifra'], $f['pagato_da']])->all())->toBe([[-2926, ['Ugo']]], 'Il rientro non dice la parte di Ines, −€ 29,26: ' . json_encode($rientro))
        ->and(collect($a['rate']['frasi'])->contains(fn (string $f) => str_contains($f, '€ 29,26') && str_contains($f, 'Ugo') && str_contains($f, 'Ines')))->toBeTrue('Nessuna frase dice € 29,26 fra Ugo e Ines: ' . implode(' | ', $a['rate']['frasi']))
        ->and(sbmSullaCifra($a, '29,26')[1])->toBe([]);

    // Il verso: è Ugo che restituisce € 29,26 a Ines. La frase con il solo valore assoluto direbbe «e Ines glieli rimborsa».
    expect(array_values(array_filter(sbmSullaCifra($a, '29,26')[0], fn (string $t) => preg_match('/Ines (?:glieli |gliela |li )?rimborsa/u', $t) === 1)))->toBe([]);
});

it('G1, la seconda vendita — dopo la vendita fermata su −€ 29,26 verso Elsa, Elsa vende a Carlo il 1/11: la seconda vendita dice la parte di Carlo, −€ 19,40, come la prima', function () {
    // Decisione 81.2 con una parte negativa. Dal 1/11 Carlo ha 61 giorni delle spese, 18148 × 61/184 = 6016,46, e 61 dei 92 dell'entrata,
    // −12000 × 61/92 = −7956,52: 61 × (18148 − 24000)/184 = −356972/184 = −1940,07. Elsa resta con 31 giorni: −181412/184 = −985,93. Ugo
    // 9074. La divisione di 6148 sui pesi 9074 / −985,93 / −1940,07: le parti intere 9074 / −986 / −1941 sommano 6147, il centesimo al
    // resto più grande (Carlo, ,93; Elsa ,07) → Carlo −1940, Elsa −986. Prima della correzione la guardia «chi esce non ha un peso
    // positivo» (`dopoUnaFermata`) saltava Elsa, che pesava −2926.
    $s = sbmAntennaDopoLUscita($this);
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    $prima = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    fclEmetti($this, $s, '2026-10-31');

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'));

    expect(sbmFermate(fds50Fermate($prima)))->toBe([[-2926, ['Elsa']]], 'La prima vendita non si è fermata su −€ 29,26 verso Elsa (G1): ' . json_encode(fds50Fermate($prima)))
        ->and(sbmFermate($a['rate']['fermate_dopo_scrivi']))->toBe([[-1940, ['Carlo']]], 'La seconda vendita non dice la parte di Carlo, −€ 19,40: ' . json_encode($a['rate']['fermate_dopo_scrivi']))
        ->and(collect($a['cancello']['motivi'])->contains(fn (string $m) => str_contains($m, '€ 19,40')))->toBeTrue('Nessun motivo del cancello dice € 19,40: ' . implode(' | ', $a['cancello']['motivi']))
        ->and(sbmSullaCifra($a, '19,40')[1])->toBe([]);

    // Il verso: la cifra è un credito rimasto a Ugo (o a Elsa), non una parte che «resta a carico».
    expect(array_values(array_filter(sbmSullaCifra($a, '19,40')[0], fn (string $t) => str_contains($t, 'restano a carico di'))))->toBe([]);
});

it('G1, la fermata ridotta — dopo la vendita fermata su −€ 29,26 verso Elsa, Luca entra il 1/12: dei giorni di Elsa restano da regolare −€ 19,40, e il pannello lo dice', function () {
    // Luca toglie a Elsa dicembre (31 giorni): sulle spese 18148 × 31/184 = 3057,54, sull'entrata −12000 × 31/92 = −4043,48. Alla parte di
    // Elsa restano il 1/10–30/11: 18148 × 61/184 − 12000 × 61/92 = −356972/184 = −1940,07; la divisione di 6148 sui pesi Ugo 9074, Elsa
    // −1940,07, Luca −181412/184 = −985,93 dà Elsa −1940 (le parti intere 9074 / −1941 / −986 sommano 6147, il centesimo al resto più
    // grande, Elsa ,93). Prima della correzione `fermateRidotte` confrontava il peso di Elsa con quello di prima solo verso il basso: −1940
    // «non calava» rispetto a −2926, e la fermata non si riduceva (`max(0, …)` avrebbe comunque portato a zero una cifra negativa).
    $s = sbmAntennaDopoLUscita($this);
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Luca');
    $prima = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    fclEmetti($this, $s, '2026-11-30');

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    $ridotte = collect($a['rate']['fermate_ridotte']);

    expect(sbmFermate(fds50Fermate($prima)))->toBe([[-2926, ['Elsa']]], 'La prima vendita non si è fermata su −€ 29,26 verso Elsa (G1): ' . json_encode(fds50Fermate($prima)))
        ->and($ridotte->map(fn ($f) => [(int) $f['cifra'], (int) $f['residuo']])->all())->toBe([[-2926, -1940]], 'La fermata non si riduce a −€ 19,40: ' . json_encode($ridotte))
        ->and(collect($a['rate']['frasi'])->contains(fn (string $f) => str_contains($f, '€ 19,40') && str_contains($f, 'Elsa')))->toBeTrue('Nessuna frase dice € 19,40 con Elsa: ' . implode(' | ', $a['rate']['frasi']))
        ->and(sbmSullaCifra($a, '19,40')[1])->toBe([]);

    // Il verso: se Ugo ed Elsa hanno già regolato € 29,26 (a credito di Elsa), è Elsa che restituisce a Ugo € 9,86 (2926 − 1940), non
    // il contrario come per una fermata positiva.
    expect(array_values(array_filter(sbmSullaCifra($a, '19,40')[0], fn (string $t) => str_contains($t, 'restituiti a Elsa'))))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// G2: una coppia con parti di segno diverso (nuova con R3)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il piano a capitoli con l'«Affitto antenna» € 1.000,00 di entrata a competenza 1/1–30/9, a giornale fino al 31/8; la vendita di Ugo a
 * Elsa del 1/9 registrata prima, poi la fine di Ines del 1/7 con «Scrivi». I pezzi della coppia: le spese 18148 sul 1/7–31/12, l'entrata
 * all'inquilino 100000 × 30 % = 30000 sui 273 giorni della competenza, di cui 92 dopo l'uscita: 30000 × 92/273 = 10109,89 → 10110, sul
 * 1/7–30/9. Le parti:
 * - Ugo (1/7–31/8, 62 giorni di tutti e due i pezzi): 18148 × 62/184 − 10110 × 62/92 = 6115,09 − 6813,26 = −698,17;
 * - Elsa (1/9–31/12 delle spese, 122 giorni; 1/9–30/9 dell'entrata, 30): 18148 × 122/184 − 10110 × 30/92 = 12032,91 − 3296,74 = 8736,17;
 * la coppia netta 18148 − 10110 = 8038 = −698 + 8736. Le righe di Ines sono +698 (contro Ugo) e −8736 (contro Elsa).
 */
function sbmCoppiaDiSegnoMisto($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    sbmConLAntenna($s, 100000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // Controllo: la coppia della fine è giusta, con le parti di segno diverso (i saldi hanno anche la coppia della vendita).
    expect(fds50Coppia($s, $s['fine']))->toBe(['Elsa' => 8736, 'Ines' => -8038, 'Ugo' => -698]);

    return $s;
}

it('G2 — la coppia Ugo −€ 6,98, Elsa € 87,36: Elsa vende a Carlo il 1/11, e la fermata è la parte di Carlo, € 60,16, non € 70,61 contata sulle righe positive (compresa quella a debito di Ines)', function () {
    // Carlo ha il 1/11–31/12, 61 giorni di sole spese: 18148 × 61/184 = 6016,46. Sul totale netto 8038, con i pesi Ugo −698,17, Elsa
    // 2719,72 e Carlo 6016,46, a Carlo vanno 6016. Prima della correzione `FermataDopoScrivi::coppia` prendeva il totale dalle righe con
    // saldo > 0: 8736 di Elsa + 698 della riga a debito di Ines = 9434, e la parte di Carlo diventava 9434 × 6016,46/8038 = 7061,36 → 7061:
    // seguendo il pannello Carlo pagava € 10,45 di troppo.
    $s = sbmCoppiaDiSegnoMisto($this);
    fclPersona($s, 'Carlo');

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'));

    expect(sbmFermate($a['rate']['fermate_dopo_scrivi']))->toBe([[6016, ['Carlo']]], 'La fermata della vendita di Elsa a Carlo non è € 60,16: ' . json_encode($a['rate']['fermate_dopo_scrivi']))
        ->and(fds50Con($a, '€ 60,16'))->not->toBe([])
        ->and(fds50Con($a, '€ 70,61'))->toBe([]);
});

it('G2, il prospetto — i giorni senza conduttore dopo l\'uscita di Ines li hanno pagati con la coppia Ugo ed Elsa: il prospetto non dice «pagata da Ines», che è uscita, e nomina Ugo', function () {
    // Le voci «senza conduttore» della strada B: le spese 18148 sui 184 giorni e l'entrata −10110 sui 92, pagate da chi ha la coppia, Ugo
    // ed Elsa. Prima della correzione `ProspettoOneriAccessori::pagatoriDopoLUscita` prendeva i nomi dalle righe con saldo > 0, compresa
    // la riga a debito di Ines (+698), e lasciava fuori Ugo, che ha la riga a credito: «pagata da Ines e Elsa». Un conduttore che
    // entrasse avrebbe rimborsato Ines. Le voci sono tagliate per giorni fra chi le ha pagate con la coppia (UltimoGiroBeta50Test, punto
    // 5): l'entrata −10110 sui 92 giorni 1/7–30/9 è di Ugo per 62 (−6813,26 → −6813) e di Elsa per 30 (−3296,74 → −3297); le spese 18148
    // sui 184 giorni sono di Ugo per 62 (6115,09 → 6115) e di Elsa per 122 (12032,91 → 12033). Sommano 8038, la coppia.
    $s = sbmCoppiaDiSegnoMisto($this);
    $senza = sbmSenza($s);

    $chi = fn ($v) => str_contains((string) $v[0], 'Ugo') && ! str_contains((string) $v[0], 'Elsa') ? 'Ugo'
        : (str_contains((string) $v[0], 'Elsa') && ! str_contains((string) $v[0], 'Ugo') ? 'Elsa' : (string) $v[0]);
    expect(array_map(fn ($v) => [$chi($v), $v[1], $v[2]], $senza))
        ->toEqualCanonicalizing([['Elsa', -3297, 30], ['Elsa', 12033, 122], ['Ugo', -6813, 62], ['Ugo', 6115, 62]]);

    foreach ($senza as [$pagataDa]) {
        expect((string) $pagataDa)->not->toContain('Ines');
    }
});

it('G2, il rientro — Ines rientra il 1/10: la sua parte dei giorni della coppia è € 90,74, non € 106,50 contata sulle righe positive', function () {
    // Ines conduttrice dal 1/10: sulle spese 18148 × 92/184 = 9074, sull'entrata (finisce il 30/9) niente: 9074. Prima della correzione
    // `rientro` passava da `coppia` con lo stesso totale sbagliato, 9434: 9434 × 9074/8038 = 10649,93 → 10650.
    $s = sbmCoppiaDiSegnoMisto($this);
    fclEmetti($this, $s, '2026-09-30');

    $rientro = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Ines', '2026-10-01'));

    expect(collect($rientro['rate']['rinnovo_dopo_scrivi'])->sum('cifra'))->toBe(9074, 'Il rientro di Ines non dice € 90,74: ' . json_encode($rientro['rate']['rinnovo_dopo_scrivi']))
        ->and(fds50Con($rientro, '€ 90,74'))->not->toBe([])
        ->and(fds50Con($rientro, '€ 106,50'))->toBe([]);
});

it('G2, l\'inizio di Luca — Luca entra il 1/10: il pannello gli fa rimborsare chi ha pagato con la coppia, non Ines, che è uscita ed è a credito', function () {
    // I giorni dopo l'uscita li ha presi con la coppia chi sta al posto dell'inquilino, Ugo ed Elsa (le righe della coppia con il loro
    // segno, senza quelle di Ines). Prima della correzione il pannello leggeva chi ha pagato dal prospetto (`pagatoriDopoLUscita`, le righe
    // con saldo > 0, compresa la riga a debito di Ines): «li ha presi con il conguaglio Ines e Elsa, e li rimborsa Luca a Ines e Elsa».
    $s = sbmCoppiaDiSegnoMisto($this);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Luca');

    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))['rate']['frasi']);

    expect($frasi)->toContain('li rimborsa Luca a')
        ->and(preg_match('/li rimborsa Luca a [^.;]*Ines/u', $frasi))->toBe(0, 'Il pannello dell\'inizio fa rimborsare Ines: ' . $frasi);
});

// ---------------------------------------------------------------------------------------------------------------------
// G3: R7 sulla pertinenza — dopo un cambio «regolato fra le parti» la catena del prospetto continua anche sul box
// ---------------------------------------------------------------------------------------------------------------------

/**
 * L'Interno 1 (900 millesimi) e il «Box 12» (100), di Ugo Rinaldi, con Ines Moretti inquilina di tutti e due; sei rate a giornale. Il cambio
 * d'inquilino di Ines a Luca Ferri il 1/7 con il box spuntato, nei tre modi: «regolato» con la casella di prima della .50 (il registro
 * senza la chiave `conguaglio`, sul padre e sul figlio), «regolato» con il conguaglio scritto e poi annullato, o scritto. Poi la fine di
 * Luca il 1/9 con «Scrivi» e il box.
 */
function sbmCambioConIlBox($test, string $modo): array
{
    $s = pflScenario([['Ugo Rinaldi', 'proprietario', 100]], [['Ines Moretti', 100]], true);
    pflSeiRateEmesse($test, $s);
    if ($modo === 'casella') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', null, [$s['box']], ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);
        foreach (Subentro::where('id', $cambio->id)->orWhere('subentro_padre_id', $cambio->id)->get() as $x) {
            $registro = $x->registro ?? [];
            unset($registro['conguaglio']);
            DB::table('subentri')->where('id', $x->id)->update(['registro' => json_encode($registro)]);
        }
        expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse();
    } elseif ($modo === 'annullato') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi', [$s['box']]);
        $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
            ['nota_annullamento_conguaglio' => 'Ines e Luca hanno regolato fra loro alla consegna delle chiavi'])->assertSessionHasNoErrors();
        expect($cambio->fresh()->conguaglioAnnullato())->toBeTrue();
    } else {
        pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi', [$s['box']]);
    }
    pflFine($test, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi', [$s['box']]);

    return $s;
}

/** L'inizio di Anna Neri il 1/11 con il box spuntato: l'anteprima (le frasi del blocco «Rate») e la registrazione, dalle rotte vere. */
function sbmInizioDiAnnaConIlBox($test, array &$s): string
{
    $dati = ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => pflPersona($s, 'Anna Neri')->id, 'decorrenza' => '2026-11-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => [(int) $s['box']->id]];
    $frasi = implode(' ', $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertOk()->json('rate.frasi'));
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati + ['ho_letto' => true, 'nota_cancello' => 'Contratto di locazione letto'])
        ->assertSessionHasNoErrors()->assertRedirect();

    return $frasi;
}

/**
 * «li rimborsa X a Y» unità per unità: ogni rimborso va con l'ultima etichetta che lo precede nel testo (le unità stanno nella stessa
 * frase). I nomi possono avere due parole.
 *
 * @return array<string, list<string>>
 */
function sbmRimborsiPer(string $testo, array $etichette): array
{
    $dove = [];
    foreach ($etichette as $e) {
        preg_match_all('/' . preg_quote($e, '/') . '/u', $testo, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $o]) {
            $dove[$o] = $e;
        }
    }
    ksort($dove);
    $nome = '\p{Lu}\p{L}*(?: \p{Lu}\p{L}*)?';
    preg_match_all('/li rimborsa ' . $nome . ' a (' . $nome . ')/u', $testo, $r, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($r[1] as [$a, $o]) {
        $prima = '?';
        foreach ($dove as $p => $e) {
            if ($p < $o) {
                $prima = $e;
            }
        }
        $out[$prima][] = $a;
    }
    ksort($out);

    return array_map(fn (array $l) => array_values(array_unique($l)), $out);
}

it('G3 — dopo il cambio d\'inquilino «regolato fra le parti» con il box, la fine di Luca il 1/9 con «Scrivi» e l\'inizio di Anna il 1/11 con il box: anche sul box il prospetto segue la catena, e Anna rimborsa a Ugo € 6,02', function (string $modo) {
    // Il box (100 millesimi): la parte dell'inquilino 120000 × 100/1000 × 30 % = 3600. La coppia della fine di Luca sul box, 1/9–31/12, 122
    // giorni: 3600 × 122/365 = 1203,29 → 1203 a Ugo. Ugo dal 1/9 al 31/10 (61 giorni) e Anna dal 1/11 al 31/12 (61): 1203 × 61/122 = 601,5
    // ciascuno, a pari resto il centesimo al conduttore (come nel cambio scritto, sotto): Anna 602, Ugo 601. La decisione 83.3 vale per
    // l'appartamento e per la pertinenza spuntata (77 punto 1: una scelta sola). Prima della correzione
    // `ProspettoOneriAccessori::regolatoConUnaFineDopo` cercava le righe della coppia con l'id della fine sul box (il figlio), mentre le
    // righe della coppia hanno l'id del padre (`RegistraSubentroAction::scriviCoppie`): il prospetto del box aveva solo Ines [3600, 3600,
    // 0, 365], e il pannello dell'inizio diceva per il box «le parti lo hanno regolato fra loro».
    $s = sbmCambioConIlBox($this, $modo);
    $frasi = sbmInizioDiAnnaConIlBox($this, $s);
    $p = pflProspetto($s, $s['box']);
    $conduttori = pflConduttori($p);

    // Controlli: la coppia della fine di Luca sul box (Ugo 120000 × 100/1000 × 70 % = 8400 del proprietario, + 1203); sull'Interno 1
    // la catena c'è già, Anna rimborsa a Ugo 32400 × 122/365 = 10829,59 → 10830, metà, 5415.
    expect(pflNetti($s, $s['box']))->toBe(['Ines Moretti' => 3600, 'Luca Ferri' => -1203, 'Ugo Rinaldi' => 9603])
        ->and(pflConduttori(pflProspetto($s))['Anna Neri'] ?? null)->toBe([5415, 0, 5415, 61]);

    expect($conduttori['Anna Neri'] ?? null)->toBe([602, 0, 602, 61], 'Nel prospetto del box Anna non rimborsa i suoi giorni: ' . json_encode($conduttori, JSON_UNESCAPED_UNICODE))
        ->and(pflPagatori(pflSezione($p, 'Anna Neri')['voci'] ?? []))->toBe(['Ugo Rinaldi'])
        ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 601, 61]])
        ->and($p['totale_inquilino'])->toBe(3600)
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and($frasi)->toContain('li rimborsa Anna Neri a Ugo Rinaldi')
        ->and(preg_match('/li rimborsa Anna Neri a (?!Ugo Rinaldi)/u', $frasi))->toBe(0, 'Il pannello dell\'inizio fa rimborsare ad Anna qualcuno che non è Ugo: ' . $frasi);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('G3, il controllo — lo stesso con il cambio d\'inquilino scritto: sul box il prospetto fa già la catena, Anna rimborsa a Ugo € 6,02 e i giorni senza conduttore sono di Ugo', function () {
    // Ines 3600 × 181/365 = 1785,21 → 1785; Luca, 62 giorni, 611,51 → 612; 1785 + 612 + 1203 = 3600.
    $s = sbmCambioConIlBox($this, 'scritto');
    $frasi = sbmInizioDiAnnaConIlBox($this, $s);
    $p = pflProspetto($s, $s['box']);

    expect(pflConduttori($p))->toBe(['Anna Neri' => [602, 0, 602, 61], 'Ines Moretti' => [1785, 1785, 0, 181], 'Luca Ferri' => [612, 0, 0, 62]])
        ->and(pflPagatori(pflSezione($p, 'Anna Neri')['voci']))->toBe(['Ugo Rinaldi'])
        ->and(pflSenza($p))->toBe([['Ugo Rinaldi', 601, 61]])
        ->and($p['totale_inquilino'])->toBe(3600)
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and($frasi)->toContain('li rimborsa Anna Neri a Ugo Rinaldi')
        ->and(preg_match('/li rimborsa Anna Neri a (?!Ugo Rinaldi)/u', $frasi))->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// G4: il coinquilino della fine com'era alla registrazione, senza appoggiarsi all'ordine degli id delle righe
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Ines unica inquilina dell'Interno 1 al 50 %, il piano generato e le sei rate a giornale (come R6 di `RevisioneBeta50FrasiTest`); l'Interno
 * 2, un'altra unità dello stesso condominio, senza millesimi sul piano.
 */
function sbmInesAlCinquanta($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50]]]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['interno2'] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SBM-I2-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);

    return $s;
}

/** L'oracolo di G4: la fine già scritta si legge com'era (Ines 181 giorni nelle sue rate, «tornano a Ugo»), con i saldi della coppia. */
function sbmComeAllaRegistrazione(array $s, Subentro $fine): void
{
    $p = fclProspetto($s);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($p['conduttori']['ines'] ?? null)->toBe([17852, 17852, 0, 181], 'Il prospetto rilegge la fine con un coinquilino che allora non c\'era: ' . json_encode($p, JSON_UNESCAPED_UNICODE))
        ->and(fclStorico($s, $fine))->toContain('tornano a Ugo')
        ->and(fclStorico($s, $fine))->not->toContain('la locazione continua con Dora')
        ->and(fclStampa($s))->not->toContain('Totale a carico di Ines € 360,00');
}

it('G4 (a) — un id di riga ripreso: l\'inizio di Pia sull\'Interno 2, la fine di Ines del 1/7 con «Scrivi», l\'inizio di Pia annullato e il contatore che riparte (MySQL 5.7 dopo un riavvio), poi Dora dal 1/3 con l\'id che era di Pia: la fine si legge com\'era', function () {
    // Le cifre di R6: Ines è la sola inquilina alla fine, 36000 × 181/365 = 17852,05 → 17852; 36000 × 184/365 = 18147,95 → 18148, e Dora,
    // dimenticata e registrata dopo, rimborsa a Ugo i giorni dopo l'uscita: [18148, 0, 18148, 184]. Su MySQL 5.7 il contatore delle righe
    // riparte da MAX(id) + 1 dopo un riavvio: qui lo si riproduce sul contatore di SQLite (`sqlite_sequence`). I passi sono a mezz'ora
    // l'uno dall'altro, come in un ufficio: la riga di Dora nasce dopo la fine. Prima della correzione
    // `ChiPagaAlPostoDellInquilino::coinquiliniDelPassaggio` leggeva le righe con id ≤ `riga_max_id`: la riga di Dora aveva l'id di Pia,
    // che c'era alla registrazione, e la fine diventava «la locazione continua con Dora» con Ines a 365 giorni.
    $s = sbmInesAlCinquanta($this);
    $pia = fclPersona($s, 'Pia');
    $inizioDiPia = ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $pia->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-04-30', 'regime_contratto' => 'abitativo', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Contratto di Pia letto'];
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['interno2']]), $inizioDiPia)->assertSessionHasNoErrors();
    $passaggioDiPia = Subentro::where('immobile_id', $s['interno2']->id)->latest('id')->firstOrFail();
    $rigaDiPia = (int) $passaggioDiPia->riga_entrante_id;

    $this->travel(30)->minutes();
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect((int) $fine->registro['riga_max_id'])->toBe($rigaDiPia);

    $this->travel(30)->minutes();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['interno2'], $passaggioDiPia]), ['nota_annullamento' => 'Pia non è mai entrata'])->assertStatus(302);
    expect(DB::table('anagrafica_immobile')->where('id', $rigaDiPia)->exists())->toBeFalse();
    DB::statement("UPDATE sqlite_sequence SET seq = (SELECT MAX(id) FROM anagrafica_immobile) WHERE name = 'anagrafica_immobile'");

    // Controllo: prima dell'inizio di Dora la fine si legge com'era.
    sbmComeAllaRegistrazione($s, $fine);

    $this->travel(30)->minutes();
    fclPersona($s, 'Dora');
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Dora']->id, 'decorrenza' => '2026-03-01', 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
    // Controllo: la riga di Dora ha preso l'id che era di Pia, che c'era alla registrazione della fine.
    expect(fclRiga($s, 'Dora'))->toBe($rigaDiPia);

    sbmComeAllaRegistrazione($s, $fine);
    expect(fclProspetto($s)['conduttori']['dora'] ?? null)->toBe([18148, 0, 18148, 184]);
});

it('G4 (b) — Dora associata dal 1/3 al 30/6 prima della fine, la fine di Ines del 1/7 con «Scrivi», poi «Modifica associazione» toglie la data di fine a Dora: la fine si legge com\'era, senza un coinquilino che alla registrazione non restava', function () {
    // La stessa lettura di R6, senza id ripresi e su qualunque database: la riga di Dora c'era già alla registrazione (id ≤ `riga_max_id`,
    // e creata prima), ma finiva il 30/6, e la fine ha scritto la coppia a Ugo (Ines −18148, Ugo +18148). Una correzione sulla data di fine
    // fatta dopo non cambia la coppia già scritta, e non deve cambiare la lettura: né l'ordine degli id né la data di creazione la
    // distinguono, serve ciò che la registrazione ha letto. Prima della correzione il prospetto passava alla strada C (Ines [36000, 36000,
    // 0, 365]) e lo storico diceva «la locazione continua con Dora».
    $s = sbmInesAlCinquanta($this);
    $dora = fclPersona($s, 'Dora');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $dora->id, 'tipologia' => 'inquilino', 'quota' => 50, 'data_inizio' => '2026-03-01', 'data_fine' => '2026-06-30',
    ])->assertSessionHasNoErrors();
    $rigaDiDora = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $dora->id)->value('id');

    $this->travel(30)->minutes();
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // Controlli: alla registrazione Dora non restava, e la fine si legge così. Dalla 1.11.0-beta.55 la fermata del coinquilino non si scrive più (`fermata_coinquilino`
    // non c'è mai, e il controllo di prima era sempre vero): chi restava lo dicono `coinquilini_letti` (qui l'unità letta, e nessuno che restava) e la regola del piano di `conguaglio.piano`
    // (assente).
    expect($fine->registro['coinquilini_letti'] ?? null)->toBe([['immobile_id' => (int) $s['unita']->id, 'restano' => []]])
        ->and($fine->registro['conguaglio'] ?? [])->not->toHaveKey('piano')
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and((int) $fine->registro['riga_max_id'])->toBeGreaterThanOrEqual($rigaDiDora);
    sbmComeAllaRegistrazione($s, $fine);

    $this->travel(30)->minutes();
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $rigaDiDora]), [
        'anagrafica_id' => $dora->id, 'tipologia' => 'inquilino', 'quota' => 50, 'data_inizio' => '2026-03-01', 'data_fine' => null, 'note' => null,
    ])->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaDiDora)->value('data_fine'))->toBeNull();

    sbmComeAllaRegistrazione($s, $fine);
});

// ---------------------------------------------------------------------------------------------------------------------
// Decisione 84.1: nella catena della 83.3 il già versato dell'unità segue i giorni, come nel cambio scritto
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Lo scenario di R7 con il già versato «dell'unità» € 120,00 sulla voce, prima della generazione: la parte dell'inquilino al netto è
 * 36000 − 12000 × 30 % = 32400. Il cambio d'inquilino di Ines a Luca il 1/7 (nel modo dato), la fine di Luca il 1/9 con «Scrivi», l'inizio
 * di Anna il 1/11.
 */
function sbmCatenaConIlVersato($test, string $modo): array
{
    $s = pflScenario();
    pflVersatoDellUnita($s, 12000);
    pflSeiRateEmesse($test, $s);
    if ($modo === 'casella') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', null, [], ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);
        $registro = $cambio->registro;
        unset($registro['conguaglio']);
        DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
        expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue();
    } elseif ($modo === 'annullato') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
        $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
            ['nota_annullamento_conguaglio' => 'Ines e Luca hanno regolato fra loro alla consegna delle chiavi'])->assertSessionHasNoErrors();
        expect($cambio->fresh()->conguaglioAnnullato())->toBeTrue();
    } else {
        pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
    }
    pflFine($test, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi');
    pflInizio($test, $s, 'Anna Neri', '2026-11-01');

    return $s;
}

/** Il già versato di ciascun conduttore nel prospetto (le voci «— già versato» della sua sezione), per nome. */
function sbmVersatoPer(array $p): array
{
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = (int) collect($c['voci'])->filter(fn ($v) => str_ends_with((string) ($v['conto'] ?? ''), '— già versato'))->sum('importo');
    }
    ksort($out);

    return $out;
}

it('decisione 84.1 — dopo il cambio d\'inquilino «regolato fra le parti» con il già versato dell\'unità, la parte del versato dei giorni di Luca va con il suo pezzo: Ines € 160,67, Luca € 55,04 al netto, come nel cambio scritto', function (string $modo) {
    // Al netto del già versato: Ines 32400 × 181/365 = 16066,85 → 16067; Luca 32400 × 62/365 = 5503,56 → 5504. Al lordo Luca 36000 × 62/365
    // = 6115,07 → 6115: la sua parte del versato è 6115 − 5504 = 611. Prima della correzione il pezzo «regolato» non si portava il versato
    // (`ProspettoOneriAccessori::dividiLeRate`, `$pz['modo'] !== 'regolato'`): Ines 15456 (i € 6,11 dei giorni di Luca restavano a lei) e
    // Luca € 61,15 al lordo.
    $s = sbmCatenaConIlVersato($this, $modo);
    $p = pflProspetto($s);
    $c = pflConduttori($p);

    // Controllo: i netti, al netto del versato (Ines 36000 − 3600, Ugo 84000 − 8400), con la coppia della fine di Luca: 32400 ×
    // 122/365 = 10829,59 → 10830.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 32400, 'Luca Ferri' => -10830, 'Ugo Rinaldi' => 86430]);

    expect([($c['Ines Moretti'] ?? [])[0] ?? null, ($c['Ines Moretti'] ?? [])[3] ?? null])->toBe([16067, 181], 'Il versato dei giorni di Luca resta a Ines: ' . json_encode($c, JSON_UNESCAPED_UNICODE))
        ->and([($c['Luca Ferri'] ?? [])[0] ?? null, ($c['Luca Ferri'] ?? [])[3] ?? null])->toBe([5504, 62])
        ->and(sbmVersatoPer($p)['Luca Ferri'] ?? 0)->toBe(-611)
        ->and($p['totale_inquilino'])->toBe(32400);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('decisione 84.1, il controllo — lo stesso con il cambio d\'inquilino scritto: Ines € 160,67, Luca € 55,04 al netto (il suo versato € 6,11), Anna € 54,15 a Ugo', function () {
    // Anna, 61 giorni, e Ugo, 61, dividono la coppia della fine di Luca al netto, 10830: 5415 ciascuno.
    $s = sbmCatenaConIlVersato($this, 'scritto');
    $p = pflProspetto($s);
    $c = pflConduttori($p);

    expect([$c['Ines Moretti'][0], $c['Ines Moretti'][3]])->toBe([16067, 181])
        ->and([$c['Luca Ferri'][0], $c['Luca Ferri'][3]])->toBe([5504, 62])
        ->and(sbmVersatoPer($p)['Luca Ferri'] ?? 0)->toBe(-611)
        ->and($c['Anna Neri'])->toBe([5415, 0, 5415, 61])
        ->and($p['totale_inquilino'])->toBe(32400);
});

// ---------------------------------------------------------------------------------------------------------------------
// R2: i pezzi della correzione che nessun test teneva (il già versato «spostato», l'importo del gruppo, le frasi)
// ---------------------------------------------------------------------------------------------------------------------

/** La riga di Ugo chiusa il 30/9: dal 1/10 sull'Interno 1 non c'è nessun proprietario (lo scenario di R2), sei rate a giornale. */
function sbmUgoFinoAlTrenta($test, array $s): array
{
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);

    return $s;
}

it('R2, il già versato — con il versato dell\'unità € 120,00 e la riga di Ugo chiusa il 30/9, il versato dei giorni che nessuno copre resta a Ines: Ines [€ 160,67, € 242,34, −€ 81,67], totale € 324,00', function () {
    // Al netto del versato la parte dell'inquilino è 32400, i giorni dopo l'uscita 32400 × 184/365 = 16333,15 → 16333; Ugo ne copre 92 su
    // 184: 8166,5 a testa, a pari resto il centesimo a chi esce → Ugo 8166, scoperti 8167. Ines: 32400 × 181/365 = 16066,85 → 16067; nelle
    // sue rate 32400 − 8166 = 24234 (la coppia le toglie la parte di Ugo); da restituirle i giorni scoperti, −8167. Il pezzo
    // (`ProspettoOneriAccessori::dividiLeRate`, «R2: dei giorni che nessuno copriva il già versato resta a chi esce») passa a chi ha la coppia
    // solo il versato dei giorni coperti; senza, passa anche quello dei giorni scoperti: Ines [16974, 25141, −8167], totale 33307.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    pflVersatoDellUnita($s, 12000);
    $s = sbmUgoFinoAlTrenta($this, $s);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);

    expect(fclSaldi($s))->toBe(['Ines' => -8166, 'Ugo' => 8166])
        ->and(fclProspetto($s)['conduttori']['ines'])->toBe([16067, 24234, -8167, 181])
        ->and((int) $p['totale_inquilino'])->toBe(32400);
});

it('R2, l\'importo del gruppo — con la riga di Ugo chiusa il 30/9 la tabella per gestione del pannello dice la coppia, € 90,74, non la parte di tutti i giorni dopo l\'uscita, € 181,48', function () {
    // `ConguaglioPassaggio`: l'importo del gruppo è la parte di chi entra meno le bozze passate meno la parte scoperta («− $scoperta»):
    // 18148 − 9074 = 9074. La tabella per gestione del pannello (`per_gestione.importo`) la mostra accanto alla coppia.
    $s = sbmUgoFinoAlTrenta($this, fclScenario([['Ugo', 'proprietario', 100]]));

    $a = fclAnteprima($this, $s, fclModulo($s));
    $g = collect($a['rate']['conguaglio']['per_gestione'])->firstWhere('immobile_id', $s['unita']->id);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 9074]])
        ->and([(int) $g['importo'], (int) $g['importo_lordo'], (int) $g['scoperta']])->toBe([9074, 9074, 9074])
        ->and((int) $a['rate']['conguaglio']['totale_entrante'])->toBe(9074);
});

it('R2, la frase della quota divisa per giorni — con la riga di Ugo chiusa il 30/9 il calcolo dice «184 dopo l\'uscita, di cui 92 a Ugo», non «184 a Ugo»', function () {
    // Il ramo semplice di `ConguaglioPassaggio` (un periodo solo, senza capitoli): Ugo copre il 1/7–30/9, 92 dei 184 giorni dopo l'uscita.
    $s = sbmUgoFinoAlTrenta($this, fclScenario([['Ugo', 'proprietario', 100]]));

    $frasi = implode(' | ', fclAnteprima($this, $s, fclModulo($s))['rate']['conguaglio']['frasi']);

    expect($frasi)->toContain('181 a Ines, 184 dopo l\'uscita, di cui 92 a Ugo')
        ->and($frasi)->not->toContain('184 a Ugo');
});

it('R2, la frase voce per voce — il piano a capitoli con l\'antenna fino al 30/9 e la riga di Ugo chiusa il 30/9: la riga delle «Spese generali» dice i 92 giorni di Ugo e € 90,74 a chi entra, non «184 a Ugo → € 181,48»', function () {
    // La coppia: le spese dei giorni coperti da Ugo, 18148 × 92/184 = 9074, e l'entrata dell'antenna dopo l'uscita (1/7–30/9, tutti di
    // Ugo): 40000 × 30 % = 12000 sui 273 giorni, 12000 × 92/273 = 4043,96 → −4044. La coppia 9074 − 4044 = 5030 (€ 50,30), come dice la
    // testa della stessa frase. Prima della correzione `ConguaglioPassaggio::frasiPerCapitolo` scriveva «— 181 giorni a Ines, 184 a Ugo → €
    // 181,48 a chi entra»: la correzione di R2 c'era solo nel ramo semplice (sopra), e accanto la frase dei giorni scoperti diceva che €
    // 90,74 restavano a Ines.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    sbmConLAntenna($s, 40000, '2026-01-01', '2026-09-30');
    $s = sbmUgoFinoAlTrenta($this, $s);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $frasi = $a['rate']['conguaglio']['frasi'];
    $spese = collect($frasi)->first(fn (string $f) => str_contains($f, '· Spese generali'));

    // Controlli: la coppia e la frase dei giorni scoperti.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 5030]])
        ->and(implode(' | ', $frasi))->toContain('credito € 50,30 a Ines, debito € 50,30 a Ugo')
        ->and(implode(' | ', $frasi))->toContain('la parte di quei giorni, € 90,74, resta nelle rate di Ines');

    expect($spese)->not->toBeNull()
        ->and($spese)->not->toContain('184 a Ugo')
        ->and($spese)->not->toContain('€ 181,48')
        ->and($spese)->toContain('€ 90,74')
        ->and(preg_match('/92[^·]*Ugo/u', (string) $spese))->toBe(1, 'La riga delle «Spese generali» non dice i 92 giorni di Ugo: ' . $spese);
});

it('R2, la frase per periodo — il «Preventivo 2026» e un secondo piano a capitoli sulla stessa gestione, con la riga di Ugo chiusa il 30/9: la riga del preventivo dice i 92 giorni di Ugo e € 90,74 a chi entra, e le righe sommano alla coppia, € 131,18', function () {
    // Due piani con competenze diverse sulla stessa gestione: il calcolo fa una riga per periodo (`ConguaglioPassaggio`, «— … divisi per
    // giorni: %d a %s, %d a %s → %s a chi entra»), la stessa riga della frase voce per voce. Il preventivo: 18148 dei giorni dopo l'uscita,
    // di cui a Ugo i 92 coperti, 9074. Il secondo piano, «Spese generali» € 400,00 a competenza 1/1–30/9: all'inquilino 40000 × 30 % =
    // 12000 sui 273 giorni, dopo l'uscita 92, tutti di Ugo: 12000 × 92/273 = 4043,96 → 4044. La coppia 9074 + 4044 = 13118 (€ 131,18).
    // Prima della correzione la riga del preventivo diceva «181 a Ines, 184 a Ugo → € 181,48 a chi entra», e le righe sommavano a € 221,92.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $secondo = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Secondo piano 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    $spese = Conto::where('nome', 'Spese generali')->where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    $secondo->capitoli()->attach([$spese->id => ['importo' => 40000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $secondo->id)->where('conto_id', $spese->id)->value('id');
    CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-09-30', 'ordine' => 0]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($secondo->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del secondo piano', esercizio: $s['e']);
    fclEmetti($this, $s, '2026-06-30');
    $rate = DB::table('rate')->where('piano_rate_id', $secondo->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', '2026-06-30 23:59:59')->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $secondo]), ['rate_ids' => $rate, 'data_emissione' => '2026-06-30', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $frasi = $a['rate']['conguaglio']['frasi'];
    $righe = array_values(array_filter($frasi, fn (string $f) => str_starts_with($f, '— ')));
    $preventivo = collect($righe)->first(fn (string $f) => str_contains($f, '€ 360,00'));
    $aChiEntra = array_sum(array_map(fn (string $f) => preg_match('/→ € ([\d.]+,\d{2}) a chi entra/u', $f, $m) === 1 ? (int) str_replace([',', '.'], '', $m[1]) : 0, $righe));

    // Controlli: la coppia, la riga del secondo piano (i suoi 92 giorni sono tutti di Ugo) e la frase dei giorni scoperti.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 13118]])
        ->and(implode(' | ', $frasi))->toContain('credito € 131,18 a Ines, debito € 131,18 a Ugo')
        ->and(implode(' | ', $frasi))->toContain('Spese generali: € 120,00, competenza 1 gennaio 2026–30 settembre 2026 — 181 giorni a Ines, 92 a Ugo → € 40,44 a chi entra')
        ->and(implode(' | ', $frasi))->toContain('la parte di quei giorni, € 90,74, resta nelle rate di Ines');

    expect($preventivo)->not->toBeNull()
        ->and($preventivo)->not->toContain('184 a Ugo')
        ->and($preventivo)->not->toContain('€ 181,48')
        ->and($preventivo)->toContain('€ 90,74')
        ->and(preg_match('/92[^→]*Ugo/u', (string) $preventivo))->toBe(1, 'La riga del preventivo non dice i 92 giorni di Ugo: ' . $preventivo)
        ->and($aChiEntra)->toBe(13118, 'Le righe per periodo non sommano alla coppia: ' . implode(' | ', $righe));
});

// ---------------------------------------------------------------------------------------------------------------------
// R8: il ripiego di `AnteprimaPassaggio::comeSonoUsciti` per il piano della 1.10, senza riparto registrato
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La gestione «Riscaldamento 2026» con «Combustibile» € 730,00 tutto all'inquilino, e il suo piano in dodici rate mensili dal 5/1, approvato
 * e generato. È `rbfRiscaldamento` di `RevisioneBeta50FrasiTest`: generato prima della fine e non emesso, la coppia della fine non lo prende.
 */
function sbmRiscaldamento(array $s): \App\Models\Gestionale\PianoRate
{
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 73000]);
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);

    return $piano;
}

it('R8, il ripiego del piano della 1.10 — due piani senza riparto registrato, la fine di Ines del 1/7 con «Scrivi» che prende solo il preventivo, l\'inizio di Luca il 1/9: per il preventivo «li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo», per il riscaldamento «li rimborsa Luca a Ines»', function () {
    // Il prospetto lascia fuori un piano della 1.10 dopo un passaggio con chi entra: il pannello guarda la fine sull'unità
    // (`comeSonoUsciti`, il ripiego senza i periodi del riparto). Il preventivo: la coppia l'ha preso (`presoNelConguaglioDa`), e ha le
    // righe sulla sua gestione, Ugo +18148: «scritto», pagato da Ugo. Il riscaldamento: generato e non emesso alla fine, la coppia non
    // l'ha preso, e sulla sua gestione non ha righe: «non scritto», i giorni di Luca sono nelle rate di Ines.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $riscaldamento = sbmRiscaldamento($s);
    fclEmetti($this, $s, '2026-06-30');
    DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $riscaldamento->id])->delete();
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $rate = DB::table('rate')->where('piano_rate_id', $riscaldamento->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', '2026-08-31 23:59:59')->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $riscaldamento]), ['rate_ids' => $rate, 'data_emissione' => '2026-08-31', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    fclPersona($s, 'Luca');

    // Controlli: la coppia è sul solo preventivo, e il riscaldamento la coppia non l'ha preso.
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($s['piano']->fresh()->presoNelConguaglioDa($fine))->toBeTrue()
        ->and($riscaldamento->fresh()->presoNelConguaglioDa($fine))->toBeFalse()
        ->and(DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $riscaldamento->id])->count())->toBe(0);

    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'))['rate']['frasi']);

    expect(sbmRimborsiPer($frasi, ['«Preventivo 2026»', '«Riscaldamento 2026»']))->toBe(['«Preventivo 2026»' => ['Ugo'], '«Riscaldamento 2026»' => ['Ines']], 'Il pannello non dice per il preventivo «a Ugo» e per il riscaldamento «a Ines»: ' . $frasi)
        ->and($frasi)->toContain('li ha presi con il conguaglio Ugo');
});

/**
 * Lo scenario dei piani della 1.10 per il ripiego: il «Preventivo 2026» generato con il piano dato accanto, le sei rate del preventivo a
 * giornale, tutti e due senza riparto registrato; la fine di Ines il 1/7 con «Scrivi» (la coppia Ines −18148, Ugo +18148 sulla gestione
 * del preventivo); poi le rate dell'altro piano a giornale fino al 31/8, e Luca.
 */
function sbmDuePianiDellaVersionePrecedente($test, array &$s, \App\Models\Gestionale\PianoRate $altro): Subentro
{
    fclEmetti($test, $s, '2026-06-30');
    DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $altro->id])->delete();
    $fine = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $rate = DB::table('rate')->where('piano_rate_id', $altro->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', '2026-08-31 23:59:59')->pluck('id')->all();
    if ($rate !== []) {
        $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $altro]), ['rate_ids' => $rate, 'data_emissione' => '2026-08-31', 'invia_notifiche' => false])
            ->assertSessionHasNoErrors();
    }
    fclPersona($s, 'Luca');

    // Controlli: la coppia è sulla sola gestione del preventivo, e i due piani sono senza riparto.
    expect(DB::table('saldi')->where('subentro_id', $fine->id)->pluck('gestione_id')->unique()->map(fn ($g) => (int) $g)->values()->all())->toBe([(int) $s['g']->id])
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $altro->id])->count())->toBe(0);

    return $fine;
}

it('R8, il ripiego del piano della 1.10 — un secondo piano sulla stessa gestione del preventivo, generato prima della fine e non preso dalla coppia: per lui «li rimborsa Luca a Ines», anche se sulla gestione la coppia ha le righe di Ugo', function () {
    // Il pezzo `presoNelConguaglioDa` del ripiego, da solo: le righe della coppia sulla gestione ci sono (quelle del preventivo), e il secondo
    // piano non le ha prese (generato e non emesso alla fine: non è nei `piani_presi`). I suoi giorni di Luca sono nelle rate di Ines.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $secondo = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Secondo piano 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($secondo->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del secondo piano', esercizio: $s['e']);
    $fine = sbmDuePianiDellaVersionePrecedente($this, $s, $secondo);

    // Controllo: la coppia ha preso il preventivo, non il secondo piano.
    expect($s['piano']->fresh()->presoNelConguaglioDa($fine))->toBeTrue()
        ->and($secondo->fresh()->presoNelConguaglioDa($fine))->toBeFalse();

    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'))['rate']['frasi']);

    expect(sbmRimborsiPer($frasi, ['«Preventivo 2026»', '«Secondo piano 2026»']))->toBe(['«Preventivo 2026»' => ['Ugo'], '«Secondo piano 2026»' => ['Ines']], 'Il pannello non dice per il secondo piano «li rimborsa Luca a Ines»: ' . $frasi);
});

it('R8, il ripiego del piano della 1.10 — il riscaldamento su una gestione finita il 30/4, preso dalla coppia ma senza righe sulla sua gestione: per lui il pannello non fa rimborsare Ugo', function () {
    // Il pezzo del filtro sulla gestione, da solo: la coppia della fine ha preso il riscaldamento (tutto emesso prima della fine), ma sulla
    // sua gestione non ha scritto niente (la gestione finisce il 30/4, prima dell'uscita). Le righe di Ugo sono sulla gestione del
    // preventivo: senza il filtro il riscaldamento diventerebbe «li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo».
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-04-30']);
    legaAEsercizio($s['e'], $g->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 40000]);
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $riscaldamento = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($riscaldamento->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);
    $rate = DB::table('rate')->where('piano_rate_id', $riscaldamento->id)->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $riscaldamento]), ['rate_ids' => $rate, 'data_emissione' => '2026-04-30', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    $fine = sbmDuePianiDellaVersionePrecedente($this, $s, $riscaldamento);

    // Controllo: la coppia ha preso il riscaldamento, e sulla sua gestione non ha righe.
    expect($riscaldamento->fresh()->presoNelConguaglioDa($fine))->toBeTrue()
        ->and(DB::table('saldi')->where('subentro_id', $fine->id)->where('gestione_id', $g->id)->exists())->toBeFalse();

    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'))['rate']['frasi']);
    $rimborsi = sbmRimborsiPer($frasi, ['«Preventivo 2026»', '«Riscaldamento 2026»']);

    expect(in_array('Ugo', $rimborsi['«Riscaldamento 2026»'] ?? [], true))->toBeFalse('Il pannello fa rimborsare a Ugo il riscaldamento, sulla cui gestione la coppia non ha righe: ' . $frasi)
        ->and($rimborsi['«Preventivo 2026»'] ?? null)->toBe(['Ugo']);
});

it('R8, il ripiego del piano della 1.10 sul box senza nessuno — la fine di Ines con «Scrivi» e il box spuntato, l\'inizio di Luca il 1/9 con il box: sul box, dove la coppia non si è scritta, «li rimborsa Luca a Ines», non «li ha presi con il conguaglio»', function () {
    // Il box non ha un proprietario: la coppia non si scrive sul box (punto 4 della ripresa), e i giorni dopo l'uscita restano nelle rate di
    // Ines. Il ripiego dice «scritto» solo con le righe della coppia su quell'unità: sul box «non scritto», pagato da Ines; sull'Interno 1
    // la coppia c'è, pagata da Ugo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ines', 'inquilino', 100]]]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'Box senza proprietario: la parte del proprietario resta scoperta', accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
    fclEmetti($this, $s, '2026-06-30');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    fclPersona($s, 'Luca');

    // Controlli: la coppia c'è sull'Interno 1, non sul box.
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe([]);

    $frasi = implode(' ', fclAnteprima($this, $s, ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloInizio($s, 'Luca', '2026-09-01'))['rate']['frasi']);

    expect($frasi)->not->toContain('li ha presi con il conguaglio chi paga al posto dell\'inquilino')
        ->and(sbmRimborsiPer($frasi, ['Interno 1', 'Box 12']))->toBe(['Box 12' => ['Ines'], 'Interno 1' => ['Ugo']], 'Il pannello non dice per il box «li rimborsa Luca a Ines» e per l\'Interno 1 «a Ugo»: ' . $frasi);
});
