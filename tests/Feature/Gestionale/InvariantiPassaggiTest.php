<?php

/**
 * 1.11.0-beta.38 — gli invarianti dei passaggi di titolarità, su una griglia di scenari fatta per crescere.
 *
 * I test di `RiservaUsufruttoTest`, `RiassegnazioneBozzeTest` e `AnnullaPassaggioTest` dicono chi paga dove la regola lo
 * stabilisce, un caso alla volta. Qui le stesse leggi girano su tutte le combinazioni che il codice distingue, scelte a
 * coppie da un generatore, e su ogni caso si controllano le proprietà che nessuna combinazione può rompere:
 *
 * - **I1, conservazione.** Il passaggio non crea e non toglie denaro: per ogni piano e unità la somma delle quote non
 *   annullate, l'importo di ogni rata e il pregresso di ogni persona (`saldo_usato`) restano quelli di prima; i saldi che
 *   non sono del passaggio non si toccano.
 * - **I2, coppie a zero, e il loro valore.** Le righe di conguaglio sommano a zero per (passaggio, gestione, unità,
 *   esercizio), e quelle del passaggio sono solo di chi esce e di chi entra. Non si asserisce che siano due: con più nudi
 *   proprietari sono di più. Il valore della coppia proposta — la parte di chi entra meno il preventivo delle bozze che da
 *   oggi paga a suo nome (decisione 25) — si confronta con un **oracolo dai dati del caso** dove l'oracolo c'è: la
 *   straordinaria che segue la delibera (I6-bis), l'ordinaria divisa per giorni nella vendita piena, nella costituzione e
 *   nell'estinzione dell'usufrutto, e zero sull'ordinaria della riserva (I6). Altrove resta un **controllo di coerenza**
 *   (`invCoerenzaDelleCoppie`): somma le coppie e le confronta con le quote dello stesso calcolo, con la formula del
 *   codice, e prende solo una coppia senza quote o una divisione fra più nudi proprietari che non conserva la somma.
 * - **I3, anteprima = scrittura.** Ciò che il pannello propone è ciò che si scrive: le coppie (niente, con la rinuncia a
 *   una coppia proposta), le bozze che passano — a chi entra, per la quota pura, con la gemella del pregresso a chi esce se
 *   e solo se c'è pregresso —, le date delle righe; ogni altra quota resta com'era. Il cancello del pannello è quello del
 *   server: se chiede la spunta, senza nota la registrazione si ferma a stato invariato; se non la chiede, passa senza nota.
 * - **I4, annullare riporta a prima.** Annullato, il passaggio lascia righe, quote, rate, saldi e storico come li aveva
 *   trovati; prima, lo storico lo diceva annullabile e senza avvisi (nessun piano è nato dopo di lui).
 * - **I5, le due forme del risolutore concordano** (griglia C): `attiviAlla` e `vincolaQuery` danno le stesse righe su
 *   ogni periodo, e il giorno prima e il giorno di ogni passaggio le righe attive sono quelle scritte.
 * - **I6, per legge una natura resta a chi la aveva.** Nella riserva l'ordinaria resta a chi vende, che resta
 *   usufruttuario (art. 1004 c.c., decisione 28): nessuna quota ordinaria passa, si divide o cambia intestatario, e nessuna
 *   coppia sull'ordinaria. Vale per le quote nate prima della riserva: nella rivendita della nuda proprietà solo per quelle
 *   con `riservata_da`. Nella costituzione dell'usufrutto lo specchio: la straordinaria resta al nudo proprietario (art. 1005).
 * - **I6-bis, la straordinaria segue la delibera** (decisione 25, testi T5). In ogni vendita, riserva compresa, la quota
 *   straordinaria di chi esce va per intero a chi entra se la delibera è del giorno dell'atto o successiva, resta a chi
 *   esce se è prima, si divide per giorni sulla competenza dichiarata sulla fattura; la bozza passa se e solo se la spesa è
 *   tutta di chi entra, la quota ha un preventivo e scade dal giorno dell'atto in poi. L'oracolo si calcola dai dati del
 *   caso — la data della delibera, la competenza dichiarata, il giorno dell'atto, il già versato di chi esce, che resta suo
 *   (decisione 17) —, mai da cifre scritte a mano. Senza la data della delibera, su un piano senza righe, niente si divide.
 *
 * Le tre griglie:
 * - **A** — un passaggio sotto esame, l'ultimo della forma, con quelli che lo precedono già registrati: I1–I4, I6 e
 *   I6-bis. Le ricette le fa `ruGriglia()` a coppie sulle dimensioni di `invDimensioni()`, più le mirate di `invMirate()`,
 *   ciascuna con ciò che deve esercitare davvero (la gemella del pregresso, le quote con `riservata_da`, l'oracolo, il
 *   già versato, una coppia, una bozza che passa): il caso che non lo esercita fallisce, invece di passare a vuoto.
 *   Fra le forme anche l'estinzione dell'usufrutto dopo la riserva: con un nudo proprietario (RE), con due che tornano
 *   pieni e il conguaglio diviso per quota (S3E), con il nudo nato lo stesso giorno per la nuda proprietà rivenduta con
 *   lo stesso atto (RVE).
 * - **B** — le catene annullate all'indietro: il primo passaggio non si annulla finché ce n'è uno dopo, e ogni passo
 *   annullato ritrova la sua fotografia; la riserva annullata e rifatta ridà lo stesso stato, a meno degli id. Ogni catena
 *   ha una rata emessa prima del primo passaggio (la straordinaria parte il 5 del mese prima), e con la straordinaria
 *   deliberata dopo l'ultimo passaggio almeno un passaggio muove denaro: il test lo controlla.
 * - **C** — il risolutore: I5.
 *
 * In fondo, fuori dalle griglie, la sezione **Coerenza**: casi singoli in cui una frase del programma diceva una cosa e il
 * programma ne faceva un'altra (decisione 28.8), ciascuno con la sua controprova.
 *
 * Un fallimento dice QUALE combinazione ha rotto QUALE invariante: il nome del caso nel dataset è la ricetta
 * («F=S3 · N=SD · E=E4 · S=T+ · …»), e le violazioni si raccolgono in un elenco invece di fermarsi alla prima; ognuna
 * comincia con l'invariante e dice dove («I2 passaggio 12, gestione 3, unità 7, esercizio 1: le righe sommano 5»).
 *
 * **Come si aggiunge un tipo di passaggio** (la successione della beta.40, per esempio):
 * 1. in `Support/ScenariPassaggi.php`, un ramo di `ruPassaggio()` che scrive il suo modulo, e in `ruRuoloUscente()` il
 *    ruolo di chi esce se non è «proprietario»;
 * 2. qui, una forma in `invForme()`: i titolari censiti e i passaggi, l'ultimo è quello sotto esame — per esempio
 *    `'SU' => ['descrizione' => 'successione', 'titolari' => [['v', 'proprietario', 100]], 'passaggi' => [['successione', 'v', 'a', '05']]]`.
 *    Il generatore la combina da solo con ogni valore delle altre dimensioni, e il suo test controlla che ogni coppia
 *    sia coperta; una combinazione che non ha senso si esclude con un vincolo in `invVincoli()`, con il motivo;
 * 3. in `invRegola()`, la regola del denaro del suo tipo: quale natura resta per legge a chi esce (I6), se la
 *    straordinaria segue la delibera (I6-bis) e se l'ordinaria si divide per giorni (l'oracolo del valore delle coppie).
 *    I1–I4 valgono per ogni tipo senza scrivere niente;
 * 4. se il tipo fa catene, una voce in `invCatene()` (griglia B) e in `invFormeRisolutore()` (griglia C).
 *
 * **Cosa si dichiara e non si asserisce:**
 * - l'unità mista (Coda 170, chiusa nella beta.41): dalla .41 il motore la divide per le quote registrate. Nella griglia ci
 *   sono S3 e NC, miste già alla generazione di gennaio; M e MC, che lo diventano con la riserva, sempre con il piano di
 *   gennaio (G=dopo c'è solo nelle forme con più passaggi); S1 e S2 con G=dopo, generate dopo la prima riserva, quando
 *   l'altra metà è ancora piena. Gli invarianti sono relativi alle quote che il motore ha dato (I1–I4, e I6 e I6-bis sulle
 *   quote di chi esce), e nessuno dice chi paga in assoluto: lo dicono `RiservaUsufruttoTest` («Coda 170 (decisione
 *   31.1)») e `tests/Feature/Riparto/UnitaMistaTest.php`;
 * - la scelta su chi paga l'ordinaria (Coda 171, chiusa nella beta.41): la griglia manda sempre la legge, la proposta
 *   (`ruRiserva`, `ruPassaggio`). Con la legge la riserva lascia l'ordinaria a chi vende anche nel conguaglio, ed è I6; le
 *   voci sul «Proprietario» passano all'«Usufruttuario», quindi un piano generato dopo dà l'ordinaria all'usufruttuario.
 *   I6, nella rivendita della nuda proprietà con un piano generato dopo la riserva e la voce sul «Proprietario» (la
 *   ricetta mirata F=RV · G=dopo), guarda solo le quote con `riservata_da`. «Come dice ogni voce» non è nella griglia: lo
 *   prova `OrdinariaDopoAttoTest`;
 * - la nota di solidarietà che non legge la coppia (Coda 172): la nota non è nella griglia;
 * - S1E, i due genitori che donano con riserva e poi muore uno dei due (griglia B): valgono solo I1–I4. A chi va
 *   l'usufrutto del genitore morto dipende dall'eventuale accrescimento, ed è materia della successione (beta.42);
 * - la parte di chi entra (I6-bis, I2) quando chi esce tiene un'altra quota sulla stessa unità (la forma S3) e il piano non ha
 *   righe di riparto: la parte della quota che esce si legge dalla ricostruzione del motore, e l'oracolo non la rifà. Con le
 *   righe la ricostruisce (`invQuotaCheEsce`); i casi senza righe li prova `QuotaCheEsceTest`;
 * - l'oracolo del risolutore sulla costituzione (forma CPC della griglia C): dalla beta.41 la riga di nuda proprietà di chi
 *   costituisce vale dal giorno dell'atto, riconosciuta dalla tripla dell'usufrutto (rilievo D1 della Fase 1-bis); il giorno
 *   prima vale la riga di proprietà piena. Lì si confrontano solo le due forme;
 * - l'oracolo della parte di chi entra (I6-bis e il valore delle coppie) su una quota di chi esce con più tratti di
 *   titolarità nel riparto (un piano generato dopo un passaggio che gli ha cambiato il ruolo nel periodo): non ricostruisce
 *   la divisione tratto per tratto, e la quota si salta;
 * - lo stesso oracolo sulle quote di un predecessore o passate a chi esce con un passaggio precedente (S8-3, R11): la
 *   regola è quella della catena, con la data in cui la competenza è passata, e l'oracolo non la ricostruisce. Così nelle
 *   catene VR e RV, dove chi esce è entrato con un passaggio precedente, il valore delle coppie resta alla coerenza e la
 *   straordinaria entra con I1–I4, non con I6-bis; e la gemella del pregresso lì non si prova, perché chi esce un
 *   pregresso suo non ce l'ha;
 * - l'oracolo sull'ordinaria nella vendita della sola nuda proprietà (RV, CPVN: chi la paga dipende da quando è nato
 *   l'usufrutto, R4) e sull'ordinaria con un già versato della persona (S=VE senza straordinaria): lì il valore delle
 *   coppie resta alla coerenza. Nella forma S3E (la riserva sull'unità mista, poi l'usufrutto che si chiude con due nudi
 *   proprietari) dalla Fase 1-ter della beta.41 il conguaglio si ferma e lo dice: non si sa a quale nudo torna ogni parte;
 * - un saldo intestato all'unità (S=UN) entra nella griglia con I1–I4: nessun invariante dice a chi va. Lo dice, com'è, la
 *   sentinella della Coda 174 nella sezione «Coerenza», qui sotto;
 * - «il destinatario cambierebbe» quando paga sempre la stessa persona — nella costituzione con la voce sul
 *   «Proprietario», e nella riserva con la voce sull'«Usufruttuario» o sull'«Inquilino» senza inquilino (rilievo R1): non
 *   è un difetto. Per il motore il destinatario è la risoluzione per periodo, e il ricalcolo chiede davvero la presa
 *   d'atto del cancello (2), come asseriscono i test «rilievo B4» e «Coda 173» di `RiservaUsufruttoTest`; ma confonde, e
 *   il testo si chiarisce nella Coda 173 (decisione 28.8). Qui non si asserisce. Le altre incoerenze fra ciò che il
 *   programma dice e ciò che fa, trovate dalla critica del piano insieme a questa, sono corrette nei testi (decisione 28.8,
 *   cantieri C7 e C8) e provate in fondo al file, nella sezione «Coerenza»: con un saldo intestato all'unità, o al solo box
 *   passato con lo stesso atto, la frase «Il programma non intesta nulla a …» dell'anteprima e dello storico e la sua
 *   gemella «Nessuna quota è intestata a …» della nota di solidarietà nominano l'eccezione, senza dire a chi vada il saldo;
 *   la nota della vendita della sola nuda proprietà nomina l'usufruttuario solo quando l'usufrutto grava con certezza
 *   sulla quota venduta; le quote di una straordinaria tutta di chi vende, in bozza e già emesse, vanno fra le
 *   informazioni; la finestra della nota senza l'esercizio precedente. Lì il denaro del saldo intestato all'unità in un
 *   piano straordinario generato dopo il passaggio è fissato com'è, come sentinella della Coda 174: nella riserva tutto a
 *   chi compra, nella vendita piena metà a chi compra e metà a chi vende.
 *
 * **Cosa resta scoperto** (regola «ogni test dichiara cosa NON copre»): l'emissione è simulata a giornale, non passa da
 * `EmissioneRateController`; nessun pagamento e nessuna segnalazione dal portale (i rami «pagata» e «segnalata» sono gli
 * stessi della vendita, provati in `RiassegnazioneBozzeTest`); nessun ricalcolo fra le fotografie — I1 non vale su un
 * ricalcolo, per disegno (rilievo B1); la corsa fra anteprima e registrazione (SQLite serializza chi scrive); MySQL: la
 * condizione JSON della riserva gira su sqlite, dove le due forme del risolutore la condividono e sbaglierebbero insieme;
 * l'addebito diretto all'unità e i coefficienti misti; la gestione senza esercizio (cambia solo una frase); le frasi del
 * pannello, che I3 non confronta (dicono, non scrivono), e la nota di solidarietà, fuori dai casi della sezione
 * «Coerenza»; il contenuto delle righe scritte, che I3
 * confronta con il pannello solo nelle date — che siano quelle giuste lo dicono i test di `RiservaUsufruttoTest`, e qui
 * I4 dice che l'annullamento le rimette tutte.
 */

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// --- L'elenco degli scenari ------------------------------------------------------------------------------------------------

/**
 * Le forme: il passaggio sotto esame e ciò che lo precede. I titolari sono censiti dal 2019 e il primo è sempre Ugo
 * proprietario; i passaggi sono [tipo, chi esce, chi entra, quando] e l'ultimo è quello sotto esame (vedi `ruCaso`). Le
 * persone: `v` Ugo, `a` Elsa, `rita`, `bice`, `nora`, `zeta`, `carlo`, `ursula`. Un mese («'05'») è il primo del mese o,
 * con D=5, il 5, il giorno di una scadenza; `'esame'` è il giorno del passaggio sotto esame.
 *
 * @return array<string, array{descrizione: string, titolari: list<array{0: string, 1: string, 2: int}>, passaggi: list<array{0: string, 1: string, 2: ?string, 3: string}>}>
 */
function invForme(): array
{
    $soloUgo = [['v', 'proprietario', 100]];
    $dueGenitori = [['v', 'proprietario', 50], ['rita', 'proprietario', 50]];
    $conBice = [['v', 'proprietario', 50], ['bice', 'proprietario', 50]];

    return [
        'R' => ['descrizione' => 'riserva sull\'unità intera', 'titolari' => $soloUgo, 'passaggi' => [['riserva', 'v', 'a', '05']]],
        'S1' => ['descrizione' => 'i due genitori donano al figlio lo stesso giorno: la seconda riserva si somma', 'titolari' => $dueGenitori,
            'passaggi' => [['riserva', 'v', 'a', 'esame'], ['riserva', 'rita', 'a', '05']]],
        'S2' => ['descrizione' => 'la seconda riserva un mese dopo', 'titolari' => $dueGenitori, 'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['riserva', 'rita', 'a', '06']]],
        'S3' => ['descrizione' => 'chi vende è già usufruttuario dell\'altra metà', 'titolari' => [['v', 'proprietario', 50], ['v', 'usufruttuario', 50], ['nora', 'nuda_proprietario', 50]],
            'passaggi' => [['riserva', 'v', 'a', '05']]],
        'M' => ['descrizione' => 'riserva sulla quota di un comproprietario: l\'unità resta mista', 'titolari' => $conBice, 'passaggi' => [['riserva', 'v', 'a', '05']]],
        'MC' => ['descrizione' => 'riserva all\'altro comproprietario pieno', 'titolari' => $conBice, 'passaggi' => [['riserva', 'v', 'bice', '05']]],
        'NC' => ['descrizione' => 'chi compra è già nudo proprietario dell\'altra metà', 'titolari' => [['v', 'proprietario', 50], ['a', 'nuda_proprietario', 50], ['rita', 'usufruttuario', 50]],
            'passaggi' => [['riserva', 'v', 'a', '05']]],
        'VR' => ['descrizione' => 'vendita piena, poi riserva', 'titolari' => $soloUgo, 'passaggi' => [['vendita', 'v', 'zeta', '2026-03-01'], ['riserva', 'zeta', 'a', '05']]],
        'RV' => ['descrizione' => 'riserva, poi rivendita della nuda proprietà', 'titolari' => $soloUgo, 'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['nuda', 'a', 'carlo', '09']]],
        'RE' => ['descrizione' => 'riserva, poi estinzione dell\'usufrutto: chi aveva comprato la nuda proprietà torna pieno', 'titolari' => $soloUgo,
            'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['estinzione', 'v', null, '09']]],
        'S3E' => ['descrizione' => 'l\'usufrutto sommato di S3 si estingue: due nudi proprietari tornano pieni, e il conguaglio si divide per quota', 'titolari' => [['v', 'proprietario', 50], ['v', 'usufruttuario', 50], ['nora', 'nuda_proprietario', 50]],
            'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['estinzione', 'v', null, '09']]],
        'RVE' => ['descrizione' => 'riserva; poi, con lo stesso atto, la nuda proprietà rivenduta e l\'usufrutto estinto: il nudo nato quel giorno diventa pieno sulla sua riga', 'titolari' => $soloUgo,
            'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['nuda', 'a', 'carlo', 'esame'], ['estinzione', 'v', null, '09']]],
        'CPV' => ['descrizione' => 'controprova: vendita piena', 'titolari' => $soloUgo, 'passaggi' => [['vendita', 'v', 'a', '05']]],
        'CPC' => ['descrizione' => 'controprova: costituzione dell\'usufrutto', 'titolari' => $soloUgo, 'passaggi' => [['costituzione', 'v', 'a', '05']]],
        'CPVN' => ['descrizione' => 'controprova: costituzione, poi vendita della sola nuda proprietà', 'titolari' => $soloUgo,
            'passaggi' => [['costituzione', 'v', 'ursula', '2026-05-01'], ['nuda', 'v', 'carlo', '09']]],
    ];
}

/**
 * La regola del denaro per tipo di passaggio sotto esame. `resta`: la natura che per legge resta a chi la aveva (I6), e
 * con `solo_riservate` solo per le quote nate prima di una riserva; `delibera`: la straordinaria segue la delibera (I6-bis);
 * `ordinaria_per_giorni`: l'ordinaria di chi esce si divide per giorni dal giorno dell'atto, e l'oracolo la ricostruisce
 * (I2). Nella vendita della sola nuda proprietà no: chi paga l'ordinaria dipende da quando è nato l'usufrutto (R4).
 *
 * @return array{resta: ?string, solo_riservate: bool, delibera: bool, ordinaria_per_giorni: bool}
 */
function invRegola(string $tipo): array
{
    return match ($tipo) {
        'riserva' => ['resta' => 'ordinaria', 'solo_riservate' => false, 'delibera' => true, 'ordinaria_per_giorni' => false],
        'nuda' => ['resta' => 'ordinaria', 'solo_riservate' => true, 'delibera' => true, 'ordinaria_per_giorni' => false],
        'vendita' => ['resta' => null, 'solo_riservate' => false, 'delibera' => true, 'ordinaria_per_giorni' => true],
        'costituzione', 'estinzione' => ['resta' => 'straordinaria', 'solo_riservate' => false, 'delibera' => false, 'ordinaria_per_giorni' => true],
    };
}

/**
 * Le dimensioni della griglia A, ciascuna con i valori che il codice distingue (il primo è quello di tutti i giorni):
 *
 * - F, la forma (`invForme()`);
 * - N, natura e date rispetto al passaggio sotto esame: O ordinaria; straordinaria deliberata prima (SP, 47 giorni), il
 *   giorno stesso (SG), dopo (SD, 19 giorni), senza data della delibera (SN), con la competenza dichiarata sulla fattura
 *   da due mesi prima a due mesi dopo (SC); OS, ordinaria e straordinaria deliberata dopo, insieme sulla stessa unità;
 * - E, l'emissione: E4 in parte, seguendo il tempo (prima di ogni passaggio si emette ciò che scade prima); NE nessuna;
 *   E12 tutto, a gennaio; SR come E4 su un piano senza righe di riparto (anteriore alla beta.29);
 * - S, i saldi: 0; T+ e T− debito o credito spalmati su tutte le rate; Z+ rata zero; VE già versato della persona
 *   (€ 300,00, sulla straordinaria se c'è) — della persona che esce nel passaggio sotto esame se è fra i titolari censiti
 *   (Ugo, o Rita nelle seconde riserve di S1 e S2), altrimenti di Ugo; UN un saldo intestato all'unità, senza persona;
 * - V, il ruolo della voce: P «Proprietario», U «Usufruttuario»;
 * - D, il giorno dell'atto: 1 (il primo del mese) o 5 (il giorno di una scadenza: le bozze dicono `<`, la nota `>=`);
 * - P, la pertinenza: nessuna, o un box con la sua quota nel piano, che segue ogni passaggio;
 * - K, la rinuncia al conguaglio;
 * - G, la generazione del piano: a gennaio, prima di ogni passaggio, o dopo i passaggi che precedono quello sotto esame.
 *
 * @return array<string, list<string>>
 */
function invDimensioni(): array
{
    return [
        'F' => array_keys(invForme()),
        'N' => ['O', 'SD', 'SP', 'SG', 'SN', 'SC', 'OS'],
        'E' => ['E4', 'NE', 'E12', 'SR'],
        'S' => ['0', 'T+', 'T-', 'Z+', 'VE', 'UN'],
        'V' => ['P', 'U'],
        'D' => ['1', '5'],
        'P' => ['no', 'box'],
        'K' => ['no', 'si'],
        'G' => ['prima', 'dopo'],
    ];
}

/**
 * Le coppie che non hanno senso, con il motivo: [dimensione, valore, altra dimensione, valori ammessi, motivo]. Il test del
 * generatore le vuole tutte motivate; una ricetta non ne contiene nessuna.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string}>
 */
function invVincoli(): array
{
    $catene = array_keys(array_filter(invForme(), fn (array $f) => count($f['passaggi']) > 1));

    return [
        ['N', 'SN', 'E', ['SR'], 'SN si ottiene solo togliendo la data della delibera dopo la generazione, che la pretende; con le righe di riparto il conguaglio legge la competenza congelata e SN sarebbe SP o SD con un\'altra etichetta. Solo su un piano senza righe (SR) il conguaglio la vede davvero senza delibera'],
        ['N', 'SC', 'E', ['E4', 'E12'], 'la competenza dichiarata vive nelle righe di riparto: su un piano senza righe (SR) si perde, e senza emissione (NE) non c\'è conguaglio che la legga'],
        ['E', 'NE', 'N', ['O', 'SD', 'OS'], 'senza rate emesse non c\'è conguaglio: la data della delibera non entra in nessun conto, e SP, SG, SN e SC sarebbero SD con un\'altra etichetta'],
        ['G', 'dopo', 'F', $catene, 'la generazione dopo i passaggi che precedono ha senso solo nelle forme che ne hanno: nelle altre coincide con la generazione a gennaio'],
    ];
}

/**
 * Le ricette mirate: combinazioni di tre o più valori che la griglia a coppie non garantisce. Il generatore le completa
 * come le altre. Ciascuna dice che cosa deve esercitare davvero (`esercita`, vedi `invEsercizi()`): ogni caso che contiene
 * i suoi valori lo controlla, e fallisce se non succede — così una mirata che il completamento svuota non passa a vuoto
 * (verdetto dello scettico sulla griglia: la mirata S1, completata con il piano generato dopo, non aveva conguaglio).
 *
 * @return list<array{ricetta: array<string, string>, esercita: list<string>}>
 */
function invMirate(): array
{
    $mirate = [];
    // La gemella del pregresso dentro la riserva: la bozza straordinaria che passa con il debito di chi esce, scritta e
    // annullata. Chi esce ha un pregresso suo — Ugo, o Rita nelle seconde riserve di S1 e S2 (vedi S in `invDimensioni`) —;
    // il piano è quello di gennaio e la voce è sul «Proprietario», perché con il piano generato dopo la prima riserva chi
    // esce può non avere quote. Nelle catene VR e RV chi esce è entrato con un passaggio precedente e un pregresso suo non
    // ce l'ha: lì la gemella non si prova.
    foreach (['R', 'S1', 'S2', 'S3', 'M', 'MC', 'NC'] as $f) {
        $mirate[] = ['ricetta' => ['F' => $f, 'N' => 'SD', 'E' => 'E4', 'S' => 'T+', 'V' => 'P', 'G' => 'prima'], 'esercita' => ['gemella', 'valore']];
    }

    return [
        ...$mirate,
        ['ricetta' => ['F' => 'R', 'N' => 'SC', 'E' => 'E4', 'S' => 'T+'], 'esercita' => ['i6bis', 'valore']],
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'E4', 'P' => 'box'], 'esercita' => ['i6bis', 'valore']],
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'E4', 'D' => '5'], 'esercita' => ['i6bis', 'bozze']],
        ['ricetta' => ['F' => 'R', 'N' => 'OS', 'E' => 'E4', 'S' => 'T+'], 'esercita' => ['i6bis']],
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'E4', 'K' => 'si'], 'esercita' => ['coppia']],
        ['ricetta' => ['F' => 'CPV', 'N' => 'O', 'E' => 'E4', 'K' => 'si'], 'esercita' => ['coppia']],
        // Il nudo proprietario che rivende, con il piano di gennaio (il caso tipico) e con il piano generato dopo la riserva e
        // la voce sul «Proprietario» (Coda 171): I6 guarda davvero le quote con `riservata_da`.
        ['ricetta' => ['F' => 'RV', 'N' => 'O', 'E' => 'E4', 'V' => 'P', 'G' => 'prima'], 'esercita' => ['riservate']],
        ['ricetta' => ['F' => 'RV', 'N' => 'O', 'E' => 'E4', 'V' => 'P', 'G' => 'dopo'], 'esercita' => ['riservate']],
        ['ricetta' => ['F' => 'NC', 'N' => 'OS', 'E' => 'E4', 'S' => 'VE', 'V' => 'P'], 'esercita' => ['i6bis']],
        // Il già versato di chi vende sulla straordinaria che passa (decisione 17): resta suo, e a chi entra va la spesa al lordo.
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'E4', 'S' => 'VE', 'V' => 'P'], 'esercita' => ['versato', 'valore']],
        ['ricetta' => ['F' => 'CPV', 'N' => 'SC', 'E' => 'E12', 'S' => 'VE', 'V' => 'P'], 'esercita' => ['versato', 'valore']],
        // La rata che scade il giorno dell'atto passa, e le bozze coprono più dei giorni di chi compra: la coppia si rovescia.
        ['ricetta' => ['F' => 'CPV', 'N' => 'O', 'E' => 'E4', 'D' => '5'], 'esercita' => ['bozze', 'rovescia', 'valore']],
        // Tutto emesso a gennaio, e il piano senza righe di riparto, con la straordinaria nel conguaglio della riserva
        // sull'unità intera: le combinazioni di tre valori che la griglia a coppie non garantisce (SC su SR è escluso: senza
        // righe la competenza dichiarata si perde). Nelle altre forme della riserva E12 e SR si incontrano con la
        // straordinaria solo dove il generatore le mette.
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'E12'], 'esercita' => ['i6bis', 'valore']],
        ['ricetta' => ['F' => 'R', 'N' => 'SC', 'E' => 'E12'], 'esercita' => ['i6bis', 'valore']],
        ['ricetta' => ['F' => 'R', 'N' => 'SD', 'E' => 'SR'], 'esercita' => ['i6bis', 'valore']],
        // Il piano generato dopo i passaggi che precedono, con qualcosa di emesso: la griglia a coppie non lo garantisce, e
        // prima di queste mirate RE e CPVN con G=dopo capitavano solo senza emissione (verdetto dello scettico).
        ['ricetta' => ['F' => 'RE', 'N' => 'O', 'E' => 'E4', 'V' => 'U', 'G' => 'dopo'], 'esercita' => ['coppia']],
        ['ricetta' => ['F' => 'CPVN', 'N' => 'SD', 'E' => 'E4', 'G' => 'dopo'], 'esercita' => ['denaro']],
    ];
}

/** Che cosa una mirata può dover esercitare, e il messaggio se non succede. @return array<string, string> */
function invEsercizi(): array
{
    return [
        'gemella' => 'nessuna bozza passa con la gemella del pregresso',
        'riservate' => 'nessuna quota con `riservata_da`: I6 della rivendita non guarda niente',
        'i6bis' => 'l\'oracolo della straordinaria (I6-bis) non ha ricostruito nessuna quota',
        'valore' => 'il valore di nessuna coppia è stato confrontato con l\'oracolo',
        'versato' => 'l\'oracolo non ha contato nessun già versato',
        'coppia' => 'nessuna coppia proposta',
        'rovescia' => 'nessuna coppia rovesciata (chi entra a credito)',
        'bozze' => 'nessuna bozza passa',
        'denaro' => 'nessuna coppia proposta e nessuna bozza che passa',
    ];
}

/** La griglia A, una volta sola per file. @return array{casi: array<string, array<string, string>>, escluse: array<string, string>} */
function invGrigliaA(): array
{
    static $griglia = null;

    return $griglia ??= ruGriglia(invDimensioni(), invVincoli(), array_column(invMirate(), 'ricetta'));
}

/** Le catene della griglia B: forme con più passaggi, annullate all'indietro. */
function invCatene(): array
{
    $soloUgo = [['v', 'proprietario', 100]];
    $dueGenitori = [['v', 'proprietario', 50], ['rita', 'proprietario', 50]];
    $forme = invForme();

    return [
        'VR' => $forme['VR'],
        'RV' => $forme['RV'],
        'RE' => ['descrizione' => 'riserva, poi estinzione dell\'usufrutto', 'titolari' => $soloUgo, 'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['estinzione', 'v', null, '2026-09-01']]],
        'REV' => ['descrizione' => 'riserva, estinzione, vendita piena', 'titolari' => $soloUgo,
            'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['estinzione', 'v', null, '2026-09-01'], ['vendita', 'a', 'carlo', '2026-11-01']]],
        'S1' => ['descrizione' => 'i due genitori, lo stesso giorno', 'titolari' => $dueGenitori, 'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['riserva', 'rita', 'a', '2026-05-01']]],
        'S2' => $forme['S2'],
        'S3E' => $forme['S3E'],
        'RVE' => $forme['RVE'],
        'CPC→CPVN' => $forme['CPVN'],
        'VR+RV' => ['descrizione' => 'vendita piena, riserva, rivendita della nuda proprietà', 'titolari' => $soloUgo,
            'passaggi' => [['vendita', 'v', 'zeta', '2026-03-01'], ['riserva', 'zeta', 'a', '2026-05-01'], ['nuda', 'a', 'carlo', '2026-09-01']]],
        // S1E: solo I1–I4. A chi va l'usufrutto del padre è materia della successione (beta.42), e qui non si asserisce.
        'S1E' => ['descrizione' => 'i due genitori donano con riserva, poi muore il padre', 'titolari' => $dueGenitori,
            'passaggi' => [['riserva', 'v', 'a', '2026-05-01'], ['riserva', 'rita', 'a', '2026-05-01'], ['estinzione', 'v', null, '2026-09-01']]],
    ];
}

/** Le forme della griglia C, con le loro opzioni: annullare l'ultimo passaggio, rifarlo, il box, niente oracolo. */
function invFormeRisolutore(): array
{
    $forme = invForme();

    return [
        'R' => [$forme['R'], []],
        'S1' => [$forme['S1'], []],
        'S2' => [$forme['S2'], []],
        'S3' => [$forme['S3'], []],
        'M' => [$forme['M'], []],
        'MC' => [$forme['MC'], []],
        'NC' => [$forme['NC'], []],
        'VR' => [$forme['VR'], []],
        'RV' => [$forme['RV'], []],
        'RE' => [invCatene()['RE'], []],
        'S3E' => [$forme['S3E'], []],
        'RVE' => [$forme['RVE'], []],
        'RA, la riserva annullata' => [$forme['R'], ['annulla' => true]],
        'RAR, la riserva annullata e rifatta' => [$forme['R'], ['annulla' => true, 'rifai' => true]],
        'la riserva con il box' => [$forme['R'], ['P' => 'box']],
        // Con l'oracolo dalla beta.41: la nuda proprietà di chi costituisce vale dal giorno dell'atto (rilievo D1 della
        // Fase 1-bis). Fino alla .40 valeva da sempre, e la forma era esclusa dall'oracolo.
        'CPC' => [$forme['CPC'], []],
    ];
}

// --- I controlli -----------------------------------------------------------------------------------------------------------

/** I giorni da `$dal` ad `$al` compresi. */
function invGiorni(string $dal, string $al): int
{
    return intdiv(CarbonImmutable::parse($al, 'UTC')->getTimestamp() - CarbonImmutable::parse($dal, 'UTC')->getTimestamp(), 86400) + 1;
}

/** I campi di due righe che non coincidono, per dire che cosa è cambiato. */
function invCampi(?array $prima, ?array $dopo): string
{
    if ($prima === null || $dopo === null) {
        return $prima === null ? 'nuova: ' . json_encode(array_diff_key($dopo ?? [], ['regole_calcolo' => true]), JSON_UNESCAPED_UNICODE) : 'sparita';
    }
    $campi = [];
    foreach (array_keys($prima + $dopo) as $campo) {
        if (($prima[$campo] ?? null) !== ($dopo[$campo] ?? null)) {
            $campi[] = $campo === 'regole_calcolo' ? 'regole_calcolo' : sprintf('%s %s → %s', $campo, json_encode($prima[$campo] ?? null), json_encode($dopo[$campo] ?? null));
        }
    }

    return 'cambiano ' . implode(', ', $campi);
}

/** Il primo punto in cui due strutture differiscono, come percorso di chiavi. */
function invPrimaDifferenza(mixed $a, mixed $b, string $percorso = ''): string
{
    if (is_array($a) && is_array($b)) {
        foreach (array_keys($a + $b) as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return invPrimaDifferenza($a[$k] ?? null, $b[$k] ?? null, "{$percorso}.{$k}");
            }
        }

        return $percorso;
    }

    return sprintf('%s: %s → %s', ltrim($percorso, '.'), mb_substr((string) json_encode($a, JSON_UNESCAPED_UNICODE), 0, 160), mb_substr((string) json_encode($b, JSON_UNESCAPED_UNICODE), 0, 160));
}

/** Le differenze fra due fotografie, sezione per sezione e riga per riga (al più cinque per sezione). @return list<string> */
function invDifferenze(string $inv, array $prima, array $dopo): array
{
    $v = [];
    foreach (['righe' => 'riga', 'quote' => 'quota', 'rate' => 'rata', 'saldi' => 'saldo'] as $sezione => $nome) {
        $diverse = [];
        foreach (array_keys(($prima[$sezione] ?? []) + ($dopo[$sezione] ?? [])) as $id) {
            if (($prima[$sezione][$id] ?? null) !== ($dopo[$sezione][$id] ?? null)) {
                $diverse[] = sprintf('%s %s %d: %s', $inv, $nome, $id, invCampi($prima[$sezione][$id] ?? null, $dopo[$sezione][$id] ?? null));
            }
        }
        array_push($v, ...array_slice($diverse, 0, 5));
        if (count($diverse) > 5) {
            $v[] = sprintf('%s … e altre %d differenze fra le %s', $inv, count($diverse) - 5, $sezione);
        }
    }
    foreach ($prima['storico'] ?? [] as $u => $storico) {
        if (($dopo['storico'][$u] ?? null) !== $storico) {
            $v[] = sprintf('%s storico dell\'unità %d: %s', $inv, $u, invPrimaDifferenza($storico, $dopo['storico'][$u] ?? null));
        }
    }

    return $v;
}

/** I1: il denaro prima e dopo. @return list<string> */
function invConservazione(array $prima, array $dopo): array
{
    $v = [];
    $perPiano = fn (array $foto) => collect($foto['quote'])->filter(fn (array $q) => $q['stato'] !== 'annullata')
        ->groupBy(fn (array $q) => ($foto['rate'][$q['rata_id']]['piano_rate_id'] ?? '?') . '|' . $q['immobile_id'])->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all();
    [$pa, $pd] = [$perPiano($prima), $perPiano($dopo)];
    foreach (array_keys($pa + $pd) as $chiave) {
        if (($pa[$chiave] ?? null) !== ($pd[$chiave] ?? null)) {
            [$piano, $unita] = explode('|', (string) $chiave);
            $v[] = sprintf('I1 piano %s, unità %s: le quote sommano %s prima e %s dopo', $piano, $unita, $pa[$chiave] ?? '—', $pd[$chiave] ?? '—');
        }
    }
    foreach ($prima['rate'] as $id => $rata) {
        if (($dopo['rate'][$id]['importo_totale'] ?? null) !== $rata['importo_totale']) {
            $v[] = sprintf('I1 rata %d: importo %d prima, %s dopo', $id, $rata['importo_totale'], $dopo['rate'][$id]['importo_totale'] ?? 'sparita');
        }
    }
    $pregresso = fn (array $foto) => collect($foto['quote'])->groupBy('anagrafica_id')
        ->map(fn ($g) => (int) $g->sum(fn (array $q) => (int) (json_decode((string) $q['regole_calcolo'], true)['importi']['saldo_usato'] ?? 0)))->filter()->sortKeys()->all();
    [$ga, $gd] = [$pregresso($prima), $pregresso($dopo)];
    if ($ga !== $gd) {
        $v[] = sprintf('I1 pregresso per persona (saldo_usato): %s prima, %s dopo', json_encode($ga), json_encode($gd));
    }
    $fuori = fn (array $foto) => array_filter($foto['saldi'], fn (array $x) => $x['subentro_id'] === null);
    if ($fuori($prima) !== $fuori($dopo)) {
        $v[] = 'I1 i saldi che non sono di un passaggio sono cambiati: ' . invPrimaDifferenza($fuori($prima), $fuori($dopo));
    }

    return $v;
}

/** I2: le righe di conguaglio di ogni passaggio sommano a zero; quelle della famiglia sono solo delle parti. @return list<string> */
function invCoppieAZero(array $foto, array $famiglia = [], array $parti = []): array
{
    $v = [];
    $righe = collect($foto['saldi'])->filter(fn (array $x) => $x['subentro_id'] !== null);
    foreach ($righe->groupBy(fn (array $x) => "{$x['subentro_id']}|{$x['gestione_id']}|{$x['immobile_id']}|{$x['esercizio_id']}") as $chiave => $g) {
        if ((int) $g->sum('saldo_iniziale') !== 0) {
            [$sub, $gestione, $unita, $esercizio] = explode('|', (string) $chiave);
            $v[] = sprintf('I2 passaggio %s, gestione %s, unità %s, esercizio %s: le righe sommano %d', $sub, $gestione, $unita, $esercizio, (int) $g->sum('saldo_iniziale'));
        }
    }
    foreach ($righe->filter(fn (array $x) => in_array($x['subentro_id'], $famiglia, true)) as $id => $x) {
        if (! in_array($x['anagrafica_id'], $parti, true)) {
            $v[] = sprintf('I2 saldo %d del passaggio %d: intestato a %s, che non è una parte del conguaglio (%s)', $id, $x['subentro_id'], json_encode($x['anagrafica_id']), json_encode($parti));
        }
    }

    return $v;
}

/**
 * I2, coerenza delle coppie con le quote dello stesso calcolo — **non è un oracolo del valore**. Per (gestione, unità,
 * esercizio) somma le coppie proposte e le confronta con la parte di chi entra meno il preventivo delle bozze che passano,
 * lette dagli stessi campi dell'anteprima (`entrante`, `passa`, `quota_pura`): è la formula del codice
 * (`ConguaglioPassaggio`, `importo = lordo − passate`), e con un solo entrante non può fallire. Prende due cose sole: una
 * coppia dove non c'è nessuna quota, e una divisione fra più nudi proprietari (`AnteprimaPassaggio`, S8-30) che non
 * conserva la somma — non che la divisione segua le quote. Se il valore sia giusto lo dice l'oracolo di
 * `invRegolaDelDenaro`, dove c'è (verdetto dello scettico: prima questo controllo si presentava come il valore). @return list<string>
 */
function invCoerenzaDelleCoppie(?array $cong): array
{
    $v = [];
    $chiave = fn (array $x) => $x['gestione_id'] . '|' . $x['immobile_id'] . '|' . ($x['esercizio_id'] ?? '');
    $coppie = collect($cong['coppie'] ?? [])->groupBy($chiave)->map(fn ($g) => (int) $g->sum('importo'));
    foreach (collect($cong['quote'] ?? [])->groupBy($chiave) as $k => $g) {
        $atteso = (int) $g->sum('entrante') - (int) $g->where('passa', true)->sum('quota_pura');
        if ((int) ($coppie[$k] ?? 0) !== $atteso) {
            [$gestione, $unita, $esercizio] = explode('|', (string) $k);
            $v[] = sprintf('I2 coerenza, gestione %s, unità %s, esercizio %s: le coppie sommano %d, le quote dello stesso calcolo dicono %d', $gestione, $unita, $esercizio, (int) ($coppie[$k] ?? 0), $atteso);
        }
    }
    foreach ($coppie as $k => $importo) {
        if (! collect($cong['quote'] ?? [])->contains(fn (array $q) => $chiave($q) === $k)) {
            $v[] = sprintf('I2 coerenza: una coppia di %d su %s, dove non c\'è nessuna quota', $importo, $k);
        }
    }

    return $v;
}

/** I3: ciò che il pannello propone contro ciò che la registrazione ha scritto. @return list<string> */
function invAnteprimaScrittura(array $anteprima, array $foto0, array $foto1, Subentro $padre, array $famiglia, bool $rinunciaChiesta): array
{
    $v = [];
    $cong = $anteprima['rate']['conguaglio'] ?? null;
    $uscenteId = (int) ($cong['anagrafica_uscente_id'] ?? 0);
    $entranteId = (int) ($cong['anagrafica_entrante_id'] ?? 0);
    $ordina = function (array $x): array {
        usort($x, fn ($a, $b) => json_encode($a) <=> json_encode($b));

        return $x;
    };

    // Le coppie: quelle proposte, salvo la rinuncia a una coppia proposta (senza coppie la rinuncia non vale, R13).
    $proposte = collect($cong['coppie'] ?? [])->filter(fn (array $c) => (int) $c['importo'] !== 0)->values();
    $rinuncia = $rinunciaChiesta && $proposte->isNotEmpty();
    $attese = $rinuncia ? [] : $ordina($proposte->flatMap(fn (array $c) => [
        [(int) $c['gestione_id'], (int) $c['immobile_id'], (int) $c['esercizio_id'], $uscenteId, -(int) $c['importo']],
        [(int) $c['gestione_id'], (int) $c['immobile_id'], (int) $c['esercizio_id'], (int) ($c['anagrafica_entrante_id'] ?? $entranteId), (int) $c['importo']],
    ])->all());
    $scritte = $ordina(collect($foto1['saldi'])->filter(fn (array $x) => in_array($x['subentro_id'], $famiglia, true))
        ->map(fn (array $x) => [$x['gestione_id'], $x['immobile_id'], $x['esercizio_id'], $x['anagrafica_id'], $x['saldo_iniziale']])->values()->all());
    if ($attese !== $scritte) {
        $v[] = sprintf('I3 coppie [gestione, unità, esercizio, persona, importo]: proposte %s, scritte %s', json_encode($attese), json_encode($scritte));
    }
    if (($padre->nota_conguaglio !== null) !== $rinuncia) {
        $v[] = sprintf('I3 rinuncia: %s, e la nota del conguaglio %s', $rinuncia ? 'c\'era una coppia da rinunciare' : 'non c\'era niente da rinunciare', $padre->nota_conguaglio === null ? 'non è stata salvata' : 'è stata salvata');
    }

    // Le bozze che passano: quelle del pannello sono quelle del registro, a chi entra per la quota pura, con la gemella del
    // pregresso a chi esce se e solo se c'è pregresso.
    $bozze = collect($cong['bozze_riassegnate'] ?? [])->keyBy('rata_quote_id');
    $registro = collect($padre->registro['quote'] ?? [])->keyBy('id');
    $ids = fn ($c) => $c->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
    if ($ids($bozze) !== $ids($registro)) {
        $v[] = sprintf('I3 bozze: il pannello fa passare %s, il registro ha %s', json_encode($ids($bozze)), json_encode($ids($registro)));
    }
    $gemelle = [];
    foreach ($bozze as $id => $b) {
        $q = $foto1['quote'][(int) $id] ?? null;
        if ($q === null) {
            $v[] = sprintf('I3 bozza %d: sparita', $id);
            continue;
        }
        if ($q['anagrafica_id'] !== $entranteId) {
            $v[] = sprintf('I3 bozza %d: attesa a %d (chi entra), trovata a %d', $id, $entranteId, $q['anagrafica_id']);
        }
        if ($q['importo'] !== (int) $b['quota_pura']) {
            $v[] = sprintf('I3 bozza %d: importo %d, ma la quota pura è %d', $id, $q['importo'], (int) $b['quota_pura']);
        }
        $gemellaId = $registro[$id]['gemella_id'] ?? null;
        if (((int) $b['pregresso'] !== 0) !== ($gemellaId !== null)) {
            $v[] = sprintf('I3 bozza %d: pregresso %d e gemella %s', $id, (int) $b['pregresso'], $gemellaId === null ? 'nessuna' : (string) $gemellaId);
        }
        if ($gemellaId !== null) {
            $gemelle[] = (int) $gemellaId;
            $g = $foto1['quote'][(int) $gemellaId] ?? null;
            if ($g === null || $g['importo'] !== (int) $b['pregresso'] || $g['anagrafica_id'] !== $uscenteId || $g['tipo'] !== 'saldo_iniziale' || $g['rata_id'] !== $q['rata_id']) {
                $v[] = sprintf('I3 gemella %d della bozza %d: attesa di %d a %d sulla stessa rata, trovata %s', $gemellaId, $id, (int) $b['pregresso'], $uscenteId, invCampi(null, $g));
            }
        }
    }
    foreach ($foto0['quote'] as $id => $q) {
        if (! $bozze->has($id) && ($foto1['quote'][$id] ?? null) !== $q) {
            $v[] = sprintf('I3 quota %d, che non passa: %s', $id, invCampi($q, $foto1['quote'][$id] ?? null));
        }
    }
    $nuove = array_values(array_diff(array_keys($foto1['quote']), array_keys($foto0['quote'])));
    sort($nuove);
    sort($gemelle);
    if ($nuove !== $gemelle) {
        $v[] = sprintf('I3 quote nuove %s, gemelle del registro %s', json_encode($nuove), json_encode($gemelle));
    }

    // Le date: chi esce fino al giorno prima, chi entra dal giorno dell'atto, come le righe scritte.
    $rif = $anteprima['riferimento'];
    if ($rif['uscente_fino_al'] !== null && ($foto1['righe'][(int) $padre->riga_uscente_id]['data_fine'] ?? null) !== $rif['uscente_fino_al']) {
        $v[] = sprintf('I3 date: il pannello dice chi esce fino al %s, la riga %d finisce il %s', $rif['uscente_fino_al'], $padre->riga_uscente_id, $foto1['righe'][(int) $padre->riga_uscente_id]['data_fine'] ?? '—');
    }
    if (($foto1['righe'][(int) $padre->riga_entrante_id]['data_inizio'] ?? null) !== $rif['entrante_dal']) {
        $v[] = sprintf('I3 date: il pannello dice chi entra dal %s, la riga %d comincia il %s', $rif['entrante_dal'], $padre->riga_entrante_id, $foto1['righe'][(int) $padre->riga_entrante_id]['data_inizio'] ?? '—');
    }

    return $v;
}

/**
 * I6, I6-bis e I2 (il valore): la regola del denaro del tipo di passaggio, sull'esito del calcolo e sul database.
 *
 * La parte di chi entra si ricostruisce dai dati del caso — le date, la competenza, gli importi delle quote — sulle quote
 * intestate a chi esce, non di un predecessore e non ricevute con un passaggio precedente (S8-3, R11: hanno la loro storia,
 * e qui non si guardano), con un solo tratto di titolarità nel riparto:
 * - la straordinaria, dove il tipo la fa seguire alla delibera (I6-bis): tutta a chi entra, a chi esce, o divisa per
 *   giorni sulla competenza dichiarata, con il già versato di chi esce al lordo (decisione 17);
 * - l'ordinaria, dove il tipo la divide per giorni (`ordinaria_per_giorni`: vendita piena, costituzione ed estinzione
 *   dell'usufrutto), sulla competenza del riparto o, per un piano senza righe, sull'anno della gestione; le bozze passano
 *   solo nella vendita, dal giorno dell'atto in poi. Non dove c'è un già versato sulla sua voce, la cui regola l'oracolo
 *   non ricostruisce: lì il valore resta alla coerenza.
 * Poi il valore della coppia (I2), da un oracolo e non dalla formula del codice: dove il gruppo della coppia (gestione,
 * unità, esercizio) è fatto solo delle quote ricostruite, la coppia proposta vale la parte attesa di chi entra meno il
 * preventivo delle bozze che, per l'oracolo, passano. Con più nudi proprietari si confronta la somma delle coppie.
 *
 * `$conta` dice quante volte ciascun controllo ha guardato davvero qualcosa, per le mirate (`invMirate`).
 *
 * @return list<string>
 */
function invRegolaDelDenaro(array $caso, array $r, ?array $cong, array $foto0, array $foto1, array &$conta): array
{
    $v = [];
    $regola = invRegola($caso['esame']['tipo']);
    $uscenteId = (int) $caso['esame']['uscente']->id;
    $quote = collect($cong['quote'] ?? []);
    $naturaPiano = DB::table('piani_rate')->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')->where('piani_rate.condominio_id', $caso['s']['c']->id)->pluck('gestioni.tipo', 'piani_rate.id')->all();
    $naturaGestione = DB::table('gestioni')->where('condominio_id', $caso['s']['c']->id)->pluck('tipo', 'id')->all();

    // L'etichetta della ricetta è vera: SN arriva al conguaglio senza delibera, SC con la competenza dichiarata.
    if ($r['N'] === 'SN' && $quote->contains('natura', 'straordinaria') && collect($cong['non_risolte'] ?? [])->isEmpty()) {
        $v[] = 'ricetta: N=SN, ma il conguaglio ha risolto la competenza della straordinaria';
    }
    if ($r['N'] === 'SC' && $quote->contains('natura', 'straordinaria') && ! $quote->contains(fn (array $q) => $q['natura'] === 'straordinaria' && $q['gradino'] === 'dichiarata')) {
        $v[] = 'ricetta: N=SC, ma il conguaglio non ha letto la competenza dichiarata sulla fattura';
    }

    // I6: la natura che resta per legge a chi la aveva.
    if ($regola['resta'] !== null) {
        $restano = $quote->filter(fn (array $q) => $q['natura'] === $regola['resta'] && (! $regola['solo_riservate'] || ! empty($q['riservata_da'])));
        foreach ($restano as $q) {
            if ((int) $q['entrante'] !== 0 || $q['passa']) {
                $v[] = sprintf('I6 quota %d (%s, rata %d, di %d): a chi entra %d%s', $q['rata_quote_id'], $q['natura'], $q['rata'], $q['intestatario_id'], (int) $q['entrante'], $q['passa'] ? ', e la bozza passa' : '');
            }
        }
        if ($regola['solo_riservate']) {
            // Senza quote con `riservata_da` il controllo qui sotto non guarda niente (verdetto dello scettico: nella rivendita
            // passava a vuoto). Quando la nuda proprietà viene da una riserva e l'ordinaria è emessa, le quote di chi si è
            // riservato l'usufrutto nel conguaglio ci sono sempre; dopo una costituzione (CPVN) non ce ne sono, per costruzione.
            $conta['riservate'] = $restano->count();
            $daRiserva = collect(invForme()[$r['F']]['passaggi'])->contains(fn (array $p) => $p[0] === 'riserva');
            if ($daRiserva && in_array($r['N'], ['O', 'OS'], true) && $r['E'] !== 'NE' && $restano->isEmpty()) {
                $v[] = 'I6 a vuoto: nella rivendita della nuda proprietà con l\'ordinaria emessa nessuna quota del conguaglio ha `riservata_da`';
            }
        }
        $controllate = $regola['solo_riservate'] ? $restano->pluck('rata_quote_id')->map(fn ($id) => (int) $id)->all() : null;
        foreach ($foto0['quote'] as $id => $q0) {
            if (($naturaPiano[$foto0['rate'][$q0['rata_id']]['piano_rate_id'] ?? 0] ?? null) !== $regola['resta'] || ($controllate !== null && ! in_array($id, $controllate, true))) {
                continue;
            }
            if (($foto1['quote'][$id]['anagrafica_id'] ?? null) !== $q0['anagrafica_id']) {
                $v[] = sprintf('I6 quota %d (%s): intestata a %d prima, a %s dopo', $id, $regola['resta'], $q0['anagrafica_id'], json_encode($foto1['quote'][$id]['anagrafica_id'] ?? null));
            }
        }
        if (! $regola['solo_riservate']) {
            foreach ($cong['coppie'] ?? [] as $c) {
                if (($naturaGestione[(int) $c['gestione_id']] ?? null) === $regola['resta'] && (int) $c['importo'] !== 0) {
                    $v[] = sprintf('I6 coppia di %d sulla gestione %s (%s)', (int) $c['importo'], $c['gestione'] ?? $c['gestione_id'], $regola['resta']);
                }
            }
        }
    }

    // I2, il valore da un oracolo: solo dove il gruppo della coppia è fatto delle sole quote ricostruite.
    $chiaveCoppia = fn (array $x) => $x['gestione_id'] . '|' . $x['immobile_id'] . '|' . ($x['esercizio_id'] ?? '');
    $valore = function ($g, int $parteEntrante, int $passate) use (&$v, &$conta, $quote, $cong, $chiaveCoppia): void {
        $k = $chiaveCoppia($g->first());
        $ids = fn ($c) => $c->pluck('rata_quote_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($ids($quote->filter(fn (array $q) => $chiaveCoppia($q) === $k)) !== $ids($g)) {
            return; // nel gruppo della coppia ci sono quote che l'oracolo non ricostruisce
        }
        $conta['valore']++;
        $proposta = (int) collect($cong['coppie'] ?? [])->filter(fn (array $c) => $chiaveCoppia($c) === $k)->sum('importo');
        if ($proposta !== $parteEntrante - $passate) {
            [$gestione, $unita, $esercizio] = explode('|', $k);
            $v[] = sprintf('I2 gestione %s, unità %s, esercizio %s: la coppia proposta vale %d, l\'oracolo dice %d (a chi entra %d, meno le bozze che passano %d)', $gestione, $unita, $esercizio, $proposta, $parteEntrante - $passate, $parteEntrante, $passate);
        }
    };

    $gruppi = $quote->filter(fn (array $q) => (int) $q['intestatario_id'] === $uscenteId && empty($q['ereditata_da']) && empty($q['passata_il'])
            && ($q['natura'] === 'straordinaria' ? $regola['delibera'] : $regola['ordinaria_per_giorni']))
        ->groupBy(fn (array $q) => $q['piano_rate_id'] . '|' . $q['immobile_id']);
    foreach ($gruppi as $chiave => $g) {
        [$pianoId, $immobileId] = array_map('intval', explode('|', (string) $chiave));
        $straordinaria = $g->first()['natura'] === 'straordinaria';
        $sigla = $straordinaria ? 'I6-bis' : 'I2 ordinaria';
        if ($r['F'] === 'S3E') {
            // Fase 1-ter della beta.41 (decisione del 03/10/2026): chi esce era proprietario pieno di una metà e usufruttuario
            // dell'altra, e il suo usufrutto si chiude con due nudi proprietari. Non si sa a quale dei due torna ogni parte
            // delle sue righe: il conguaglio si ferma e lo dice. L'oracolo lo sa dalla forma, non dal codice.
            $conta['ordinaria']++;
            foreach ($g as $q) {
                if ((int) $q['entrante'] !== 0 || $q['passa']) {
                    $v[] = sprintf('%s quota %d, catena con più strade: a chi entra %d%s', $sigla, $q['rata_quote_id'], (int) $q['entrante'], $q['passa'] ? ', e la bozza passa' : '');
                }
            }
            if (! collect($cong['non_risolte'] ?? [])->contains(fn ($n) => str_contains((string) $n['motivo'], 'sono passate per più strade'))) {
                $v[] = 'S3E: il conguaglio non dice che le quote sono passate per più strade';
            }
            $valore($g, 0, 0);
            continue;
        }
        if ($straordinaria && $r['N'] === 'SN') {
            // Senza la data della delibera, su un piano senza righe, niente si divide e niente passa.
            $conta['i6bis']++;
            foreach ($g as $q) {
                if ((int) $q['entrante'] !== 0 || $q['passa']) {
                    $v[] = sprintf('I6-bis quota %d senza data della delibera: a chi entra %d%s', $q['rata_quote_id'], (int) $q['entrante'], $q['passa'] ? ', e la bozza passa' : '');
                }
            }
            $valore($g, 0, 0);
            continue;
        }
        $v2 = $caso['versato'];
        $versatoQui = ($v2['anagrafica_id'] ?? null) === $uscenteId && ($v2['piano_rate_id'] ?? null) === $pianoId && ($v2['immobile_id'] ?? null) === $immobileId;
        if ($straordinaria) {
            $competenza = $caso['competenza'] !== null && $r['E'] !== 'SR' ? $caso['competenza'] : [$caso['delibere'][$pianoId], $caso['delibere'][$pianoId]];
        } elseif ($versatoQui) {
            continue; // il già versato sull'ordinaria: la sua regola l'oracolo non la ricostruisce (dichiarato sopra)
        } else {
            $riparto = DB::table('righe_riparto')->where('piano_rate_id', $pianoId)->where('immobile_id', $immobileId)->where('anagrafica_id', $uscenteId)->where('tipo', 'riparto');
            $gestione = DB::table('piani_rate')->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')->where('piani_rate.id', $pianoId)->first(['gestioni.data_inizio', 'gestioni.data_fine']);
            $competenza = [substr((string) ((clone $riparto)->min('competenza_dal') ?? $gestione->data_inizio), 0, 10), substr((string) ((clone $riparto)->max('competenza_al') ?? $gestione->data_fine), 0, 10)];
        }
        if ($r['E'] !== 'SR') {
            $tratti = DB::table('righe_riparto')->where('piano_rate_id', $pianoId)->where('immobile_id', $immobileId)->where('anagrafica_id', $uscenteId)->where('tipo', 'riparto')
                ->get(['titolarita_dal', 'titolarita_al'])->map(fn ($x) => $x->titolarita_dal === null ? null : [substr((string) $x->titolarita_dal, 0, 10), substr((string) $x->titolarita_al, 0, 10)])->unique()->values();
            if ($tratti->count() > 1) {
                continue; // dichiarato nel docblock: più tratti di titolarità, l'oracolo non li ricostruisce
            }
            if (($t = $tratti->first()) !== null) {
                $competenza = [max($competenza[0], $t[0]), min($competenza[1], $t[1])];
                if ($competenza[0] > $competenza[1]) {
                    continue;
                }
            }
        }
        $conta[$straordinaria ? 'i6bis' : 'ordinaria']++;
        $giorni = invGiorni($competenza[0], $competenza[1]);
        $giorniEntrante = $caso['d'] > $competenza[1] ? 0 : invGiorni(max($competenza[0], $caso['d']), $competenza[1]);
        $versato = $straordinaria && $r['E'] !== 'SR' && $versatoQui ? (int) $v2['importo'] : 0;
        if ($versato !== 0) {
            $conta['versato']++;
        }
        $base = (int) $g->sum('quota_pura') + $versato;
        // Fase 1-ter della beta.41, 03/10/2026: se chi esce tiene un'altra quota sulla stessa unità (la forma S3, usufruttuario
        // dell'altra metà), passa solo la quota che esce: la parte delle sue righe di riparto risolte sui ruoli che restano
        // non si divide, e una bozza con quelle righe resta a lui.
        $quotaCheEsce = invQuotaCheEsce($caso, $pianoId, $immobileId, $uscenteId);
        if ($quotaCheEsce === null) {
            continue; // dichiarato nel docblock: chi esce tiene un'altra quota e il piano non ha righe di riparto
        }
        if ($quotaCheEsce < 1.0) {
            $base = (int) round($base * $quotaCheEsce);
        }
        $atteso = match (true) {
            $giorniEntrante === 0 => 0,
            $giorniEntrante === $giorni => $base,
            default => (int) MoneyHelper::ripartisciPerQuote($base, ['uscente' => $giorni - $giorniEntrante, 'entrante' => $giorniEntrante])['entrante'],
        };
        if ((int) $g->sum('entrante') !== $atteso) {
            $v[] = sprintf('%s piano %d, unità %d: competenza %s–%s, atto il %s — a chi entra attesi %d (%d giorni su %d, su %d), calcolati %d',
                $sigla, $pianoId, $immobileId, $competenza[0], $competenza[1], $caso['d'], $atteso, $giorniEntrante, $giorni, $base, (int) $g->sum('entrante'));
        }
        // Le bozze che passano (decisione 25): la straordinaria se la spesa è tutta di chi entra, l'ordinaria nella vendita
        // piena; nell'usufrutto nessuna. Sempre con un preventivo, e dal giorno dell'atto in poi.
        $tutta = $giorni > 0 && $giorniEntrante === $giorni;
        $passate = 0;
        foreach ($g->where('in_bozza', true) as $q) {
            $passa = ($straordinaria ? $tutta : $caso['esame']['tipo'] === 'vendita') && (int) $q['quota_pura'] !== 0 && $q['scadenza'] >= $caso['d'] && $quotaCheEsce === 1.0;
            $passate += $passa ? (int) $q['quota_pura'] : 0;
            if ($q['passa'] !== $passa) {
                $v[] = sprintf('%s bozza %d (rata %d, scadenza %s): %s, e doveva %s (motivo del calcolo: %s)', $sigla, $q['rata_quote_id'], $q['rata'], $q['scadenza'], $q['passa'] ? 'passa' : 'resta', $passa ? 'passare' : 'restare', $q['motivo_bozza'] ?? '—');
            }
        }
        $valore($g, $atteso, $passate);
    }

    return $v;
}

/**
 * Un caso della griglia A: costruisce lo scenario, lo fotografa, chiede l'anteprima, registra — prima senza nota, e se il
 * cancello la chiede di nuovo con la nota —, fotografa, controlla I1, I2, I3, I6 e I6-bis, annulla e controlla I4.
 *
 * @return list<string> le violazioni: vuoto se il caso regge
 */
function invControllaCaso($test, array $r): array
{
    $caso = ruCaso($test, $r, invForme()[$r['F']]);
    $s = $caso['s'];
    $cid = (int) $s['c']->id;
    $dati = $caso['esame']['dati'];
    $v = [];

    $foto0 = ruFoto($caso['unita'], $cid);
    $risposta = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
    if ($risposta->status() !== 200) {
        return [sprintf('I3 l\'anteprima risponde %d: %s', $risposta->status(), json_encode($risposta->json('errors'), JSON_UNESCAPED_UNICODE))];
    }
    $anteprima = $risposta->json();
    $cong = $anteprima['rate']['conguaglio'] ?? null;

    // Il cancello del pannello è quello del server (I3).
    $rotta = route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]);
    $errori = fn ($risposta) => $risposta->getSession()?->get('errors')?->getBag('default')->toArray() ?? [];
    $senzaNota = $errori($test->actingAs($test->user)->post($rotta, $dati));
    if ($anteprima['cancello']['richiesto']) {
        if (! isset($senzaNota['nota_cancello'])) {
            return ['I3 cancello: il pannello chiede la spunta, ma la registrazione senza nota ' . ($senzaNota === [] ? 'passa' : 'si ferma per altro: ' . json_encode($senzaNota, JSON_UNESCAPED_UNICODE))];
        }
        array_push($v, ...invDifferenze('I3 cancello (registrazione rifiutata senza nota)', $foto0, ruFoto($caso['unita'], $cid)));
        $conNota = $errori($test->actingAs($test->user)->post($rotta, array_merge($dati, ['ho_letto' => true, 'nota_cancello' => 'Letto: cosa cambierà, controllato sul rogito'])));
        if ($conNota !== []) {
            return [...$v, 'I3 l\'anteprima risponde 200, ma la registrazione con la nota si ferma: ' . json_encode($conNota, JSON_UNESCAPED_UNICODE)];
        }
    } elseif ($senzaNota !== []) {
        return ['I3 cancello: il pannello non chiede la spunta, ma la registrazione senza nota si ferma: ' . json_encode($senzaNota, JSON_UNESCAPED_UNICODE)];
    }
    $padre = Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    if (! $anteprima['cancello']['richiesto'] && $padre->nota_cancello !== null) {
        $v[] = 'I3 cancello: senza cancello la nota non si salva, e invece c\'è';
    }
    $famiglia = Subentro::where(fn ($q) => $q->whereKey($padre->id)->orWhere('subentro_padre_id', $padre->id))->pluck('id')->map(fn ($id) => (int) $id)->all();
    $parti = array_values(array_unique(array_filter([(int) ($cong['anagrafica_uscente_id'] ?? 0), ...array_map(fn (array $c) => (int) ($c['anagrafica_entrante_id'] ?? 0), $cong['coppie'] ?? [])])));
    $foto1 = ruFoto($caso['unita'], $cid);

    array_push($v, ...invConservazione($foto0, $foto1));
    array_push($v, ...invCoppieAZero($foto1, $famiglia, $parti));
    array_push($v, ...invCoerenzaDelleCoppie($cong));
    array_push($v, ...invAnteprimaScrittura($anteprima, $foto0, $foto1, $padre, $famiglia, $r['K'] === 'si'));
    $conta = array_fill_keys(['riservate', 'i6bis', 'ordinaria', 'valore', 'versato'], 0);
    array_push($v, ...invRegolaDelDenaro($caso, $r, $cong, $foto0, $foto1, $conta));

    // Le mirate che la ricetta contiene: ciascuna ha esercitato davvero ciò per cui c'è.
    $coppie = collect($cong['coppie'] ?? [])->filter(fn (array $c) => (int) $c['importo'] !== 0);
    $conta += [
        'gemella' => collect($padre->registro['quote'] ?? [])->whereNotNull('gemella_id')->count(),
        'coppia' => $coppie->count(),
        'rovescia' => $coppie->filter(fn (array $c) => (int) $c['importo'] < 0)->count(),
        'bozze' => count($cong['bozze_riassegnate'] ?? []),
    ];
    $conta['denaro'] = $conta['coppia'] + $conta['bozze'];
    foreach (invMirate() as ['ricetta' => $m, 'esercita' => $esercita]) {
        foreach (array_intersect_assoc($m, $r) === $m ? $esercita : [] as $cosa) {
            if ($conta[$cosa] === 0) {
                $v[] = sprintf('mirata %s: %s', json_encode($m), invEsercizi()[$cosa]);
            }
        }
    }

    // I4: lo storico lo dice annullabile, senza avvisi; annullato, tutto torna com'era.
    $voce = collect($foto1['storico'][(int) $s['unita']->id]['subentri'])->firstWhere('id', $padre->id);
    if (! ($voce['annullabile']['si'] ?? false)) {
        $v[] = 'I4 lo storico dice che il passaggio non si annulla: ' . ($voce['annullabile']['motivo'] ?? 'passaggio assente dallo storico');
    } else {
        // Decisione 31.7 (1.11.0-beta.41): le voci spostate all'«Usufruttuario» restano, e l'annullamento lo dice — se e solo
        // se il passaggio le ha spostate. Ogni altro avviso, senza nessun piano nato dopo, resta un difetto.
        $sulleVoci = fn (string $a) => str_contains($a, 'all\'«Usufruttuario»');
        // Decisione 46 (1.11.0-beta.42): con la rinuncia l'annullamento dice ciò che le parti hanno regolato fra loro — se e solo se
        // il registro ne conserva una cifra.
        $regolato = fn (string $a) => str_starts_with($a, 'Le parti hanno già regolato fra loro');
        if (collect($voce['annullabile']['avvisi'])->contains($regolato) !== ($padre->fresh()->regolatoFuoriInParole() !== null)) {
            $v[] = 'I4 (decisione 46) l\'avviso su ciò che le parti hanno regolato non corrisponde al registro del passaggio';
        }
        $altri = array_values(array_filter($voce['annullabile']['avvisi'], fn (string $a) => ! $sulleVoci($a) && ! $regolato($a)));
        if ($altri !== []) {
            $v[] = 'I4 avvisi senza nessun piano nato dopo il passaggio: ' . implode(' | ', $altri);
        }
        if (! empty($padre->fresh()->registro['voci_spostate']) !== collect($voce['annullabile']['avvisi'])->contains($sulleVoci)) {
            $v[] = 'I4 (decisione 31.7) l\'avviso sulle voci spostate non corrisponde al registro del passaggio';
        }
    }
    $annullo = ruAnnulla($test, $s, $padre);
    if ($annullo->status() !== 302) {
        return [...$v, sprintf('I4 l\'annullamento risponde %d: %s', $annullo->status(), json_encode($annullo->json('errors'), JSON_UNESCAPED_UNICODE))];
    }
    array_push($v, ...invDifferenze('I4', $foto0, ruFoto($caso['unita'], $cid)));

    return $v;
}

/** Lo storico di un passaggio dell'unità: la voce con `annullabile`. */
function invVoceStorico(array $s, Subentro $passaggio): ?array
{
    return collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
}

// --- Il generatore ---------------------------------------------------------------------------------------------------------

it('griglia A, il generatore — ogni coppia di valori compare in una ricetta o è esclusa con il suo motivo, nessuna ricetta contiene una coppia esclusa, e le mirate ci sono, ciascuna con ciò che deve esercitare', function () {
    $dimensioni = invDimensioni();
    $griglia = invGrigliaA();
    $nomi = array_keys($dimensioni);
    $problemi = [];

    foreach ($nomi as $i => $x) {
        foreach (array_slice($nomi, $i + 1) as $y) {
            foreach ($dimensioni[$x] as $a) {
                foreach ($dimensioni[$y] as $b) {
                    $coperta = collect($griglia['casi'])->contains(fn (array $r) => $r[$x] === $a && $r[$y] === $b);
                    $motivo = $griglia['escluse']["{$x}={$a} × {$y}={$b}"] ?? null;
                    if (! $coperta && trim((string) $motivo) === '') {
                        $problemi[] = "{$x}={$a} × {$y}={$b}: scoperta, senza motivo";
                    }
                    if ($coperta && $motivo !== null) {
                        $problemi[] = "{$x}={$a} × {$y}={$b}: esclusa, ma una ricetta la contiene";
                    }
                }
            }
        }
    }
    foreach ($griglia['casi'] as $etichetta => $r) {
        expect(array_keys($r))->toBe($nomi, $etichetta);
        foreach (invVincoli() as [$d1, $v1, $d2, $ammessi]) {
            if ($r[$d1] === $v1 && ! in_array($r[$d2], $ammessi, true)) {
                $problemi[] = "{$etichetta}: {$d1}={$v1} con {$d2}={$r[$d2]}";
            }
        }
    }
    // SN e SC non si certificano con un'etichetta: SN solo su un piano senza righe, SC mai su quello (critica, punto 5).
    expect(collect($griglia['casi'])->where('N', 'SN')->pluck('E')->unique()->values()->all())->toBe(['SR'])
        ->and(collect($griglia['casi'])->where('N', 'SC')->pluck('E')->unique()->diff(['E4', 'E12'])->all())->toBe([]);
    foreach (invMirate() as ['ricetta' => $m, 'esercita' => $esercita]) {
        if (! collect($griglia['casi'])->contains(fn (array $r) => array_intersect_assoc($m, $r) === $m)) {
            $problemi[] = 'mirata assente: ' . json_encode($m);
        }
        if ($esercita === [] || array_diff($esercita, array_keys(invEsercizi())) !== []) {
            $problemi[] = 'mirata senza uno scopo che il caso sa controllare: ' . json_encode($m);
        }
    }

    expect($problemi)->toBe([]);
});

// --- Griglia A ---------------------------------------------------------------------------------------------------------------

it('griglia A — un passaggio sotto esame: il denaro si conserva (I1), le coppie fanno zero e, dove c\'è un oracolo, valgono la parte di chi entra (I2), l\'anteprima è ciò che si scrive (I3), l\'annullamento rimette tutto (I4), l\'ordinaria resta a chi vende nella riserva (I6) e la straordinaria segue la delibera (I6-bis)', function (array $ricetta) {
    expect(invControllaCaso($this, $ricetta))->toBe([], implode(' · ', array_map(fn ($k, $x) => "{$k}={$x}", array_keys($ricetta), $ricetta)));
})->with(array_map(fn (array $r) => [$r], invGrigliaA()['casi']));

// --- Griglia B ---------------------------------------------------------------------------------------------------------------

it('griglia B — le catene si annullano all\'indietro: il primo passaggio non si annulla finché ce n\'è uno dopo, a stato invariato, e ogni passo annullato ritrova la sua fotografia; lungo la catena il denaro si conserva e le coppie fanno zero', function (string $catena, array $combinazione) {
    // La prima rata della straordinaria il 5 del mese prima del primo passaggio: così «emessa in parte prima del primo»
    // è vero anche nelle catene che cominciano il 1 marzo (VR, VR+RV), dove la rata di aprile verrebbe dopo e la catena
    // non muoverebbe denaro (verdetto dello scettico). Nelle altre è il 5 aprile, come nella griglia A.
    $primaScadenza = CarbonImmutable::parse(invCatene()[$catena]['passaggi'][0][3])->subMonthNoOverflow()->day(5)->toDateString();
    $r = $combinazione + ['F' => $catena, 'V' => 'P', 'D' => '1', 'P' => 'no', 'K' => 'no', 'G' => 'prima', 'prima_scadenza' => $primaScadenza];
    $foto = [];
    $caso = ruCaso($this, $r, invCatene()[$catena], function (array $caso) use (&$foto) {
        $foto[] = ruFoto($caso['unita'], (int) $caso['s']['c']->id);
    });
    $s = $caso['s'];
    $cid = (int) $s['c']->id;
    $foto[] = ruFoto($caso['unita'], $cid);
    $passaggi = [...$caso['passaggi'], ruRegistra($this, $s, array_merge($caso['esame']['dati'], ['ho_letto' => true, 'nota_cancello' => 'Ultimo passaggio della catena, letto']))];
    $dopo = ruFoto($caso['unita'], $cid);
    $v = [];
    foreach (array_keys($foto) as $i) {
        array_push($v, ...array_map(fn ($x) => "passo {$i}: {$x}", invConservazione($foto[$i], $foto[$i + 1] ?? $dopo)));
    }
    array_push($v, ...invCoppieAZero($dopo));
    // Le etichette delle combinazioni sono vere: prima del primo passaggio c'è una rata emessa, e con la straordinaria
    // deliberata dopo l'ultimo almeno un passaggio della catena muove denaro, con una coppia o con una bozza che passa.
    if (! collect($foto[0]['rate'])->contains('stato', 'emessa')) {
        $v[] = 'B nessuna rata emessa prima del primo passaggio';
    }
    if ($r['N'] === 'SD' && ! collect($dopo['saldi'])->contains(fn (array $x) => $x['subentro_id'] !== null && $x['saldo_iniziale'] !== 0)
        && collect($passaggi)->every(fn (Subentro $p) => empty($p->registro['quote']))) {
        $v[] = 'B nessun passaggio della catena muove denaro: né coppie né bozze passate';
    }

    // Il primo non si annulla finché c'è un passaggio dopo: lo storico lo dice, il server risponde 422 e niente cambia.
    if (($invVoce = invVoceStorico($s, $passaggi[0])) !== null && $invVoce['annullabile']['si']) {
        $v[] = 'I4 lo storico dice annullabile il primo passaggio, con altri dopo';
    }
    $risposta = ruAnnulla($this, $s, $passaggi[0]);
    if ($risposta->status() !== 422) {
        $v[] = sprintf('I4 annullare il primo passaggio risponde %d, non 422', $risposta->status());
    }
    array_push($v, ...invDifferenze('I4 (primo passaggio rifiutato)', $dopo, ruFoto($caso['unita'], $cid)));

    // All'indietro: ogni passo è annullabile quando è l'ultimo, e annullato ritrova la fotografia di prima di lui.
    for ($i = count($passaggi) - 1; $i >= 0; $i--) {
        $voce = invVoceStorico($s, $passaggi[$i]);
        if (! ($voce['annullabile']['si'] ?? false)) {
            $v[] = sprintf('I4 passo %d: lo storico non lo dice annullabile — %s', $i, $voce['annullabile']['motivo'] ?? 'assente');
            break;
        }
        $risposta = ruAnnulla($this, $s, $passaggi[$i]);
        if ($risposta->status() !== 302) {
            $v[] = sprintf('I4 passo %d: l\'annullamento risponde %d — %s', $i, $risposta->status(), json_encode($risposta->json('errors'), JSON_UNESCAPED_UNICODE));
            break;
        }
        array_push($v, ...invDifferenze("I4 passo {$i}", $foto[$i], ruFoto($caso['unita'], $cid)));
    }

    expect($v)->toBe([], "{$catena} · N={$r['N']} · E={$r['E']} · S={$r['S']}");
})->with(array_keys(invCatene()))->with([
    'ordinaria, emessa in parte prima del primo passaggio, debito spalmato' => [['N' => 'O', 'E' => 'E4', 'S' => 'T+', 'tempo' => 'inizio']],
    'straordinaria deliberata dopo l\'ultimo passaggio, emessa in parte prima del primo, debito spalmato' => [['N' => 'SD', 'E' => 'E4', 'S' => 'T+', 'tempo' => 'inizio']],
]);

it('griglia B, RAR — la riserva annullata e registrata di nuovo ridà lo stesso stato, a meno degli id, dei passaggi e delle ore (fotografia normalizzata); annullata ancora, tutto torna a prima', function (array $combinazione) {
    $r = $combinazione + ['F' => 'R', 'V' => 'P', 'D' => '1', 'P' => 'box', 'K' => 'no', 'G' => 'prima'];
    $caso = ruCaso($this, $r, invForme()['R']);
    $s = $caso['s'];
    $cid = (int) $s['c']->id;
    $dati = array_merge($caso['esame']['dati'], ['ho_letto' => true, 'nota_cancello' => 'Riserva letta, registrata']);
    $v = [];

    $prima = ruFoto($caso['unita'], $cid);
    $primo = ruRegistra($this, $s, $dati);
    $registrata = ruFotoNormalizzata($caso['unita'], $cid);
    if (($x = ruAnnulla($this, $s, $primo)->status()) !== 302) {
        $v[] = "I4 il primo annullamento risponde {$x}";
    }
    array_push($v, ...invDifferenze('I4 (primo annullamento)', $prima, ruFoto($caso['unita'], $cid)));

    $secondo = ruRegistra($this, $s, $dati);
    $rifatta = ruFotoNormalizzata($caso['unita'], $cid);
    if ($rifatta !== $registrata) {
        $v[] = 'RAR la riserva rifatta non dà lo stato della prima: ' . invPrimaDifferenza($registrata, $rifatta);
    }
    if (($x = ruAnnulla($this, $s, $secondo)->status()) !== 302) {
        $v[] = "I4 il secondo annullamento risponde {$x}";
    }
    array_push($v, ...invDifferenze('I4 (secondo annullamento)', $prima, ruFoto($caso['unita'], $cid)));

    expect($v)->toBe([]);
})->with([
    'ordinaria, quattro rate emesse, debito spalmato' => [['N' => 'O', 'E' => 'E4', 'S' => 'T+']],
    'straordinaria deliberata dopo, debito spalmato: bozze e gemelle passate e tornate' => [['N' => 'SD', 'E' => 'E4', 'S' => 'T+']],
]);

// --- Griglia C ---------------------------------------------------------------------------------------------------------------

it('griglia C — le due forme del risolutore danno le stesse righe su ogni periodo, con un\'istanza nuova a ogni lettura, e il giorno prima e il giorno di ogni passaggio le righe attive sono quelle scritte (I5)', function (string $nome) {
    [$forma, $opzioni] = invFormeRisolutore()[$nome];
    $r = ['F' => $nome, 'N' => 'O', 'E' => 'NP', 'S' => '0', 'V' => 'P', 'D' => '1', 'P' => $opzioni['P'] ?? 'no', 'K' => 'no', 'G' => 'prima'];
    $caso = ruCaso($this, $r, $forma);
    $s = $caso['s'];
    $dati = array_merge($caso['esame']['dati'], ['ho_letto' => true, 'nota_cancello' => 'Passaggio letto e controllato']);
    $ultimo = ruRegistra($this, $s, $dati);
    if ($opzioni['annulla'] ?? false) {
        ruAnnulla($this, $s, $ultimo)->assertRedirect();
        if ($opzioni['rifai'] ?? false) {
            ruRegistra($this, $s, $dati);
        }
    }

    $d = CarbonImmutable::parse($caso['d']);
    $decorrenze = collect([...$caso['passaggi'], $ultimo])->map(fn (Subentro $p) => $p->decorrenza->toDateString())->unique()->values()->all();
    $giorno = fn (string $x) => new PeriodoCompetenza($x, $x);
    $periodi = [
        '2025' => new PeriodoCompetenza('2025-01-01', '2025-12-31'),
        '2026' => new PeriodoCompetenza('2026-01-01', '2026-12-31'),
        'dal 1/1 al giorno prima dell\'atto' => new PeriodoCompetenza('2026-01-01', $d->subDay()->toDateString()),
        'dall\'atto al 31/12' => new PeriodoCompetenza($d->toDateString(), '2026-12-31'),
        'due tratti a cavallo dell\'atto' => new InsiemePeriodi(new PeriodoCompetenza($d->subDays(30)->toDateString(), $d->subDays(2)->toDateString()), new PeriodoCompetenza($d->addDays(2)->toDateString(), $d->addDays(30)->toDateString())),
    ];
    foreach ($decorrenze as $p) {
        $pp = CarbonImmutable::parse($p);
        $periodi["{$p} − 1"] = $giorno($pp->subDay()->toDateString());
        $periodi[$p] = $giorno($p);
        $periodi["{$p} + 1"] = $giorno($pp->addDay()->toDateString());
    }

    $v = [];
    foreach ($caso['unita'] as $u) {
        $unita = Immobile::findOrFail($u);
        $sql = fn ($periodo) => (new RisolutoreTitolari())->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $u), $periodo)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        foreach ($periodi as $etichetta => $periodo) {
            $collection = (new RisolutoreTitolari())->attiviAlla($unita->anagrafiche()->get(), $periodo)->map(fn ($a) => (int) $a->pivot->id)->sort()->values()->all();
            if ($collection !== $sql($periodo)) {
                $v[] = sprintf('I5 unità %d, periodo %s: collection %s, SQL %s', $u, $etichetta, json_encode($collection), json_encode($sql($periodo)));
            }
        }
        if ($opzioni['senza_oracolo'] ?? false) {
            continue;
        }
        foreach ($decorrenze as $p) {
            foreach ([CarbonImmutable::parse($p)->subDay()->toDateString(), $p] as $x) {
                $scritte = DB::table('anagrafica_immobile')->where('immobile_id', $u)->where('attivo', true)->whereDate('data_inizio', '<=', $x)
                    ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $x))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($sql($giorno($x)) !== $scritte) {
                    $v[] = sprintf('I5 unità %d, il %s: il risolutore dà %s, le righe scritte sono %s', $u, $x, json_encode($sql($giorno($x))), json_encode($scritte));
                }
            }
        }
    }

    expect($v)->toBe([], $nome);
})->with(array_keys(invFormeRisolutore()));

// --- Coerenza: ciò che il programma dice e ciò che fa (decisione 28.8) ------------------------------------------------------

// Decisione 28.8 a. Un saldo intestato all'unità (l'arretrato dell'appartamento, non di una persona) si addebita quando si
// genera il piano, ai titolari che il ramo B2 di `GenerateSaldiAction` legge in quel momento (`vincolaQuery`, senza periodo):
// nella riserva, per la straordinaria, tutto al nudo proprietario, perché la catena solidale parte da lui; nella vendita piena
// metà a chi compra e metà a chi vende, perché senza periodo `vincolaQuery` prende anche la riga chiusa di chi vende. La
// frase «Il programma non intesta nulla a chi compra» (anteprima e storico) e la sua gemella nella nota di solidarietà,
// «Nessuna quota è intestata a … per quel periodo», nella riserva e nella vendita piena (lì l'incoerenza era anteriore),
// erano false: ora nominano l'eccezione quando l'unità o una pertinenza del passaggio ha saldi intestati all'unità, con la
// stessa forma, che non dice a chi vadano (`FrasiObbligati::SALVO_SALDI_DELL_UNITA`). La nota senza il saldo la fissano per
// intero «testi T4, controprova» e le note della riserva in `RiservaUsufruttoTest`. Il denaro non cambia — se quei saldi
// debbano seguire chi era titolare nel periodo è una regola di denaro, la Coda 174 — ed è fissato com'è come sentinella
// della Coda 174: quando la regola cambierà, questo test diventa rosso e le frasi vanno rilette. Il test rosso dei test
// sulle combinazioni asseriva zero a chi compra: era la regola della Coda 174, non ancora decisa.
it('coerenza, decisione 28.8 a — con un saldo intestato all\'unità la frase «Il programma non intesta nulla a chi compra» e la nota di solidarietà nominano l\'eccezione, nella riserva e nella vendita, in anteprima, nello storico e nella nota; senza, la frase resta quella di prima. Sentinella della Coda 174: il piano straordinario generato dopo addebita oggi l\'arretrato dell\'unità tutto a chi compra nella riserva, e nella vendita piena lo divide con chi vende', function (string $forma, string $senza, string $con, array $arretrato) {
    $s = ruScenario('tutte_rate', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, genera: false);
    $dati = ($forma === 'riserva' ? ruPassaggio('riserva', $s['rigaV'], $s['a'], '2026-05-01', 100) : ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100))
        + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio del 1 maggio'];
    $frasi = fn () => implode("\n", ruAnteprima($this, $s, $dati)['obbligati']['frasi']);
    $nota = fn () => app(\App\Services\Subentro\NotaSolidarieta::class)->per($s['c'], $s['a'])[0]['testo'] ?? '';
    $nellaNota = 'Nessuna quota è intestata a Acquirente Elsa per quel periodo, salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano: ';

    expect($frasi())->toContain($senza)->not->toContain('saldi intestati all\'unità');
    \App\Models\Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);
    expect($frasi())->toContain($con);
    $subentro = ruRegistra($this, $s, $dati);
    expect(implode("\n", app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro($subentro)))->toContain($con)
        ->and($nota())->toContain($nellaNota);

    // Coda 174, sentinella: il denaro di oggi, l'arretrato dell'unità per persona.
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: passaggio nel 2026', esercizio: $s['e']);
    $saldoUsato = fn (int $id) => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.anagrafica_id', $id)
        ->pluck('rate_quote.regole_calcolo')->sum(fn ($r) => (int) (json_decode((string) $r, true)['importi']['saldo_usato'] ?? 0));
    expect(['Elsa' => $saldoUsato($s['a']->id), 'Ugo' => $saldoUsato($s['v']->id)])->toBe($arretrato)
        // Dopo la generazione la nota dice lo stesso: descrive come il programma tratta quei saldi, applicati o no.
        ->and($nota())->toContain($nellaNota);
})->with([
    'vendita o donazione con riserva d\'usufrutto' => ['riserva',
        'Il programma non intesta nulla a Acquirente Elsa per quel periodo.',
        'Il programma non intesta nulla a Acquirente Elsa per quel periodo, salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano.',
        // Tutto a chi compra la nuda proprietà: la catena solidale della straordinaria parte dal nudo proprietario.
        ['Elsa' => 18000, 'Ugo' => 0]],
    'vendita piena' => ['vendita',
        'Il programma non intesta nulla a Acquirente Elsa: la nota resta qui',
        'Il programma non intesta nulla a Acquirente Elsa, salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano: la nota resta qui',
        // Metà per uno: senza periodo `vincolaQuery` prende anche la riga chiusa di chi vende, e le due righe «proprietario» al
        // 100 % si dividono l'arretrato. Per questo la frase non dice a chi vada.
        ['Elsa' => 9000, 'Ugo' => 9000]],
]);

// Decisione 28.8 a con le pertinenze. Il saldo intestato al solo box, venduto con lo stesso atto, fa nominare l'eccezione come
// quello dell'unità: in anteprima (le unità spuntate nel modulo), nello storico e nella nota di solidarietà (l'unità del
// passaggio e le sue pertinenze, `FrasiObbligati::immobiliDelPassaggio`; la nota ha una voce per l'unità e una per il box, e
// tutte e due guardano il box). Controprova sullo stesso passaggio: tolto il saldo, storico e nota tornano senza eccezione.
// Il denaro non si guarda: il box qui non ha quote nel piano, e a chi vada il saldo è la Coda 174.
it('coerenza, decisione 28.8 a — un saldo intestato al solo box, pertinenza venduta con lo stesso atto, fa nominare l\'eccezione in anteprima, nello storico e in tutte e due le voci della nota di solidarietà; tolto il saldo, storico e nota tornano senza', function () {
    $s = ruScenario('tutte_rate', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, genera: false);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $dati = ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100, [$box->id]) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio del 1 maggio'];
    $salvo = 'Il programma non intesta nulla a Acquirente Elsa, salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano: la nota resta qui';
    $nellaNota = 'Nessuna quota è intestata a Acquirente Elsa per quel periodo, salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano: ';

    expect(implode("\n", ruAnteprima($this, $s, $dati)['obbligati']['frasi']))->not->toContain('saldi intestati all\'unità');
    $saldo = \App\Models\Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $box->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);
    expect(implode("\n", ruAnteprima($this, $s, $dati)['obbligati']['frasi']))->toContain($salvo);

    $subentro = ruRegistra($this, $s, $dati);
    $storico = fn () => implode("\n", app(\App\Services\Subentro\FrasiObbligati::class)->daSubentro($subentro));
    $note = fn () => collect(app(\App\Services\Subentro\NotaSolidarieta::class)->per($s['c'], $s['a']))->pluck('testo', 'immobile');
    expect($storico())->toContain($salvo)
        ->and($note()->keys()->sort()->values()->all())->toBe(['Box 12 (Int. B12)', 'Interno 1 (Int. 1)'])
        ->and($note()->every(fn ($testo) => str_contains($testo, $nellaNota)))->toBeTrue();

    $saldo->delete();
    expect($storico())->not->toContain('saldi intestati all\'unità')
        ->and($note()->contains(fn ($testo) => str_contains($testo, 'saldi intestati all\'unità')))->toBeFalse();
});

// Decisione 28.8 b (e 28.4). Nella vendita della sola nuda proprietà la nota di solidarietà usava il testo della vendita
// piena: chi compra in solido con chi vende (art. 63 co. 4), e basta. Con un usufruttuario sull'unità alla data dell'atto,
// dal giorno dell'atto chi compra la nuda proprietà risponde in solido anche con lui (art. 67 ult. co.), e la nota ora lo
// dice. Due strade per arrivarci: la rivendita della nuda proprietà nata da una riserva, e la vendita della nuda proprietà
// nata da una costituzione (anteriore alla beta). Le righe non dicono su quale quota stia ciascun usufrutto (sonda B1 dello
// scettico: la nota nominava anche l'usufruttuaria dell'altra metà): la nota nomina le persone solo quando le quote degli
// usufrutti sommano quelle delle nude proprietà e l'usufruttuario è uno solo (grava su tutte) o il nudo proprietario è uno
// solo, chi compra (tutte gravano sulla sua); altrimenti dice «l'usufruttuario della quota acquistata», senza nomi. Con due
// usufruttuari certi la forma è «in solido con gli usufruttuari …». La nota della vendita piena non cambia: la fissa per
// intero «testi T4, controprova» in `RiservaUsufruttoTest`.
it('coerenza, decisione 28.8 b — la nota di solidarietà della vendita della sola nuda proprietà dice la solidarietà dell\'art. 67 ult. co. con l\'usufruttuario, dopo la riserva e dopo la costituzione dell\'usufrutto; nomina gli usufruttuari solo quando l\'usufrutto grava con certezza sulla quota venduta, altrimenti non nomina persone; la parte dell\'art. 63 co. 4 resta quella della vendita', function (string $origine, string $venditore, string $finale) {
    if ($origine === 'due_genitori') {
        // Ugo e Rita, proprietari al 50 %, donano a Elsa con riserva la nuda proprietà delle loro metà: Elsa nuda al 100 %.
        [$s, $madre, $rigaM] = ruDueGenitori();
        ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
        ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM] + $s, extra: ['quota' => 50]));
    } elseif (in_array($origine, ['altra_meta_usufrutto', 'altra_meta_senza_usufrutto'], true)) {
        // Nora ha la nuda proprietà dell'altra metà: con Rita usufruttuaria (sonda B1), o senza un usufrutto registrato.
        $s = ruScenario('prima_rata', 0);
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
        $nora = \App\Models\Anagrafica::forceCreate(['nome' => 'Figlia Nora', 'email' => "inv-n{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'INVNORAFIGL' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
        $nora->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nora->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        if ($origine === 'altra_meta_usufrutto') {
            $rita = \App\Models\Anagrafica::forceCreate(['nome' => 'Madre Rita', 'email' => "inv-r{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'INVRITAMADR' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
            $rita->condomini()->syncWithoutDetaching([$s['c']->id]);
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $rita->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
    } else {
        $s = ruScenario('prima_rata', 0);
        ruEmetti($s);
        if ($origine !== 'costituzione') {
            ruRegistra($this, $s, ruRiserva($s));
        }
        if ($origine === 'meta_rivenduta') {
            // La nuda proprietà sotto l'usufrutto di Ugo è per metà di Elsa e per metà di Nora (righe scritte a mano): Elsa
            // vende la sua metà. La vendita chiude la riga di chi vende e apre quella di chi compra alla quota del modulo.
            DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('tipologia', 'nuda_proprietario')->update(['quota' => 50]);
            $nora = \App\Models\Anagrafica::forceCreate(['nome' => 'Figlia Nora', 'email' => "inv-n{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'INVNORAFIGL' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
            $nora->condomini()->syncWithoutDetaching([$s['c']->id]);
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nora->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    if ($origine === 'costituzione') {
        ruRegistra($this, $s, ruPassaggio('costituzione', $s['rigaV'], $s['a'], '2026-05-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']);
        $carlo = \App\Models\Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => "inv-c{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'INVCOMPRATO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
        $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
        $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
        $vendita = ruPassaggio('nuda', $rigaNuda, $carlo, '2026-09-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Vendita della nuda proprietà, letta'];
    } else {
        [$carlo, $vendita] = ruRivendita($s);
        // Elsa vende la sua nuda proprietà: tutta, o la sua metà quando l'altra è di Nora.
        $vendita['quota'] = match ($origine) {
            'meta_rivenduta', 'altra_meta_usufrutto', 'altra_meta_senza_usufrutto' => 50,
            default => 100,
        };
    }
    ruRegistra($this, $s, $vendita);

    $note = app(\App\Services\Subentro\NotaSolidarieta::class)->per($s['c'], $carlo);
    expect($note)->toHaveCount(1)
        ->and($note[0]['testo'])->toStartWith("Compratore Carlo risponde in solido con {$venditore} per i contributi di Interno 1 (Int. 1) relativi agli esercizi 2026 e 2025 (art. 63 co. 4 disp. att. c.c.).")
        ->toEndWith("Chi paga in forza della solidarietà ha regresso verso il venditore, salvo diverso accordo (Cass. 11199/2021). Dal 1 settembre 2026 Compratore Carlo, nudo proprietario, {$finale} per i contributi di Interno 1 (Int. 1) (art. 67 ult. co. disp. att. c.c.).");
})->with([
    // Un nudo proprietario e un usufruttuario, sulla stessa quota.
    'rivendita della nuda proprietà nata da una riserva' => ['riserva', 'Acquirente Elsa', 'e Venditore Ugo, usufruttuario, rispondono in solido'],
    'vendita della nuda proprietà nata da una costituzione' => ['costituzione', 'Venditore Ugo', 'e Acquirente Elsa, usufruttuario, rispondono in solido'],
    // Un solo nudo proprietario, chi compra, e due usufruttuari che insieme hanno quanto lui: gravano tutti e due sulla sua quota.
    'due genitori donano con riserva, poi la nuda proprietà si rivende: due usufruttuari certi' => ['due_genitori', 'Acquirente Elsa', 'risponde in solido con gli usufruttuari Venditore Ugo e Madre Rita'],
    // Due nudi proprietari (Nora e Carlo) e un solo usufruttuario, su tutta la nuda proprietà: grava anche sulla metà venduta.
    'due nudi proprietari e un solo usufrutto su tutta: la metà venduta ne è gravata' => ['meta_rivenduta', 'Acquirente Elsa', 'e Venditore Ugo, usufruttuario, rispondono in solido'],
    // Sonda B1: due nudi proprietari e due usufruttuari, e le righe non dicono chi è su quale metà.
    'l\'altra metà ha un altro usufrutto: nessun nome' => ['altra_meta_usufrutto', 'Acquirente Elsa', 'risponde in solido con l\'usufruttuario della quota acquistata'],
    // Un solo usufruttuario, ma le quote non tornano: la nuda proprietà di Nora ha un usufrutto non registrato, e dalle righe
    // quello di Ugo potrebbe essere il suo.
    'l\'altra metà è nuda senza un usufrutto registrato: nessun nome' => ['altra_meta_senza_usufrutto', 'Acquirente Elsa', 'risponde in solido con l\'usufruttuario della quota acquistata'],
]);

// Decisione 28.8 c fuori dalla riserva (la 28.7 «vale ovunque»). Nella vendita piena le bozze di una straordinaria
// deliberata prima dell'atto restano a chi vende e la parte di chi compra è zero (`straordinaria_di_chi_esce`): nessuna
// cambia persona, e la frase del piano va fra le informazioni — prima era fra i motivi, «restano sue e sono comprese nel
// conguaglio». Lo stesso per le quote già emesse dello stesso piano: il conguaglio dà zero a chi compra per costruzione, con
// lo stesso criterio delle bozze, e senza altri motivi la spunta non serve (prima la chiedevano; primo dubbio del cantiere C7).
// Le bozze di un piano senza data della delibera e senza righe di riparto (`non_risolta`) restano a chi vende perché il
// programma non sa dividerle: restano un motivo, ma la frase dice «senza conguaglio», come il blocco 2, e non «comprese nel
// conguaglio»; e le emesse di quel piano restano fra i motivi, perché il programma non sa se cambino persona. La voce da
// fattura con la competenza dichiarata prima dell'atto (decisione 26) è in `RiassegnazioneBozzeTest`, «pregressa 2025».
it('coerenza, decisione 28.8 c — nella vendita piena le quote di una straordinaria tutta di chi vende, in bozza e già emesse, vanno fra le informazioni e la spunta non serve; quelle di un piano con la competenza da determinare restano fra i motivi, le bozze «senza conguaglio» e non «comprese nel conguaglio»', function (string $caso, string $motivo, string $frase, string $dove, string $emesse, bool $richiesto) {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-04-05', 6);
    if ($caso === 'SN') {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
        DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => null]);
    }
    ruEmetti($s);
    $anteprima = ruAnteprima($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100) + ['ho_letto' => false, 'nota_cancello' => null]);
    $cancello = $anteprima['cancello'];

    expect(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe([$motivo])
        ->and($cancello[$dove])->toContain($frase)
        ->and($cancello[$dove === 'motivi' ? 'informazioni' : 'motivi'])->not->toContain($frase)
        ->and($cancello[$dove])->toContain($emesse)
        ->and($cancello['richiesto'])->toBe($richiesto)
        ->and(implode(' | ', [...$cancello['motivi'], ...$cancello['informazioni']]))->not->toContain('comprese nel conguaglio');
    if (! $richiesto) {
        expect($cancello['motivi'])->toBe([]);
    }
})->with([
    'straordinaria deliberata prima dell\'atto' => ['SP', 'straordinaria_di_chi_esce',
        'il piano «Rifacimento facciata» ha 5 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca', 'informazioni',
        '1 quota di rata già emessa a Venditore Ugo su questa unità: resta sua, questo passaggio non la tocca', false],
    'straordinaria senza data della delibera, su un piano senza righe di riparto' => ['SN', 'non_risolta',
        'il piano «Rifacimento facciata» ha 5 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue, senza conguaglio: la competenza del piano non si può determinare', 'motivi',
        '1 quota di rata già emessa a Venditore Ugo su questa unità', true],
]);

// Decisione 28.8 d. Senza l'esercizio precedente registrato `NotaSolidarieta::inizioFinestra` tagliava all'inizio di quello
// aperto, mentre il suo docblock diceva «la finestra non taglia»: la vendita datata nell'anno prima non aveva la nota. Ora
// il precedente si deduce, un anno prima dell'esercizio aperto, come fa `FrasiObbligati::esercizi` per le frasi (che per
// quella vendita dicono gli esercizi 2025 e 2024). Controprova: una vendita di due anni prima resta fuori dalla finestra.
it('coerenza, decisione 28.8 d — senza l\'esercizio precedente registrato la finestra della solidarietà comincia un anno prima di quello aperto: con il solo esercizio 2026, la vendita del 1 dicembre 2025 ha la sua nota, quella del 1 dicembre 2024 no', function (string $decorrenza, array $esercizi) {
    $s = ruScenario('prima_rata', 0, genera: false);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], $decorrenza, 100) + ['ho_letto' => true, 'nota_cancello' => 'Vendita dell\'anno prima, letta']);

    expect(collect(app(\App\Services\Subentro\NotaSolidarieta::class)->per($s['c'], $s['a']))->pluck('esercizi')->all())->toBe($esercizi);
})->with([
    'vendita del 1 dicembre 2025: nell\'esercizio precedente, dedotto' => ['2025-12-01', ['2025 e 2024']],
    'vendita del 1 dicembre 2024: prima della finestra' => ['2024-12-01', []],
]);

/**
 * La parte delle righe di riparto di chi esce, su (piano, unità), che è della quota che esce: esclusi i ruoli che chi esce tiene
 * accanto — le sue altre righe in vigore il giorno dell'atto con un'altra tipologia, e la quota che il passaggio gli lascia
 * (nuda proprietà dopo una costituzione, usufrutto dopo una riserva). 1.0 se non tiene niente accanto; null se tiene qualcosa
 * accanto e il piano non ha righe di riparto (la parte si legge solo dalla ricostruzione del motore, che l'oracolo non rifà).
 */
function invQuotaCheEsce(array $caso, int $pianoId, int $immobileId, int $uscenteId): ?float
{
    $rigaId = (int) $caso['esame']['dati']['riga_uscente_id'];
    $riga = DB::table('anagrafica_immobile')->where('id', $rigaId)->first(['tipologia']);
    // Le altre quote che chi esce teneva il giorno prima dell'atto (dopo la registrazione la riserva può averle fuse con la
    // riga che apre, quindi si guarda il giorno prima, non il giorno dell'atto).
    $vigilia = CarbonImmutable::parse($caso['d'])->subDay()->toDateString();
    $accanto = DB::table('anagrafica_immobile')->where('anagrafica_id', $uscenteId)->where('immobile_id', $immobileId)
        ->where('id', '!=', $rigaId)->where('tipologia', '!=', $riga->tipologia)
        ->where(fn ($q) => $q->whereNull('data_inizio')->orWhereDate('data_inizio', '<=', $vigilia))
        ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $vigilia))
        ->pluck('tipologia')->all();
    $restano = $accanto;
    $dati = $caso['esame']['dati'];
    if (($dati['tipo'] ?? null) === 'usufrutto' && ($dati['sottotipo'] ?? null) === 'costituzione') {
        $restano[] = 'nuda_proprietario';
    } elseif (($dati['tipo'] ?? null) === 'vendita' && ($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO) {
        $restano[] = 'usufruttuario';
    }
    $righe = DB::table('righe_riparto')->where('piano_rate_id', $pianoId)->where('immobile_id', $immobileId)->where('anagrafica_id', $uscenteId)
        ->whereIn('tipo', ['riparto', 'ad_personam'])->get(['ruolo_risolto', 'importo']);
    $totale = (int) $righe->sum('importo');
    if ($totale === 0) {
        // Senza righe: con un'altra quota accanto la parte si legge solo dalla ricostruzione; senza, passa tutta.
        return $accanto === [] ? 1.0 : null;
    }

    return (int) $righe->reject(fn ($r) => in_array($r->ruolo_risolto, $restano, true))->sum('importo') / $totale;
}
