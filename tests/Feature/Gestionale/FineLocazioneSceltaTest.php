<?php

/**
 * 1.11.0-beta.50 — la fine di una locazione senza un nuovo inquilino, con un piano che non si riscrive più: «Scrivi il conguaglio»
 * o «Non scriverlo» (decisioni 32 e 77, punto 1; scelte tecniche del verbale `piano_esecutivo_beta50.md`).
 *
 * Prima della .50 la fine senza nuovo inquilino non aveva conguaglio per costruzione (`Subentro::tipoHaUnConguaglio`): le rate già
 * emesse e quelle ancora da emettere restavano a chi esce, e il campo `conguaglio` si rifiutava fuori dalla successione. Dalla .50,
 * quando l'anteprima propone delle coppie (un piano che non si ricalcola più: scrittura, movimento o conguaglio), l'amministratore
 * sceglie, senza niente di già scelto, con il campo `conguaglio` della .48 (`scrivi` / `non_scrivere`):
 * - «Scrivi»: la coppia porta i giorni dopo l'uscita a chi paga al posto dell'inquilino, divisa per persona e per giorno su tutto il
 *   periodo dopo l'uscita, con tutte le righe della persona (la catena del ripiego della generazione, decisioni 21 e 32);
 * - «Non scriverlo»: nessuna riga in saldi, le rate restano all'inquilino uscito (strada A′), la nota è facoltativa;
 * - la scelta resta nel registro (`conguaglio.scelta`), una sola per l'appartamento e le pertinenze spuntate.
 *
 * Le cifre, fatte a mano con la regola della coppia (ProRataTemporis e resti maggiori: a ognuno la parte intera, il centesimo che
 * avanza al resto più grande, a pari resto a chi esce). «Spese generali» € 1.200,00, 30 % «Inquilino» = 36000, 70 % «Proprietario» =
 * 84000; Ines esce il 1/7: 1/1–30/6 = 31 + 28 + 31 + 30 + 31 + 30 = 181 giorni suoi, 1/7–31/12 = 31 + 31 + 30 + 31 + 30 + 31 = 184
 * dopo. 36000 × 181/365 = 17852,05 e 36000 × 184/365 = 18147,95: parti intere 17852 + 18147 = 35999, il centesimo al resto più
 * grande (,95) → Ines 17852, la coppia 18148. Le cifre sono esatte sulla regola della coppia; rispetto al piano generato dopo la
 * fine la tolleranza dichiarata è di un centesimo per unità (il caso del centesimo e il pareggio del 2028, in fondo).
 *
 * Fissa le cose nuove della .50: il campo `conguaglio` accettato nella fine locazione (prima si rifiutava), il rifiuto senza la scelta
 * (prima la fine si registrava), la coppia proposta (prima nessuna), la frase «tornano a …» unità per unità (prima solo Ugo), il
 * prospetto delle fini senza scelta nel registro (prima Ines 365 giorni). I controlli, che il codice di prima faceva già giusti, lo
 * dicono nel nome.
 *
 * Cosa copre: il rifiuto senza la scelta, «Scrivi» sulle cinque forme dell'unità di `d32Attesi`, i pesi per persona con due righe,
 * la divisione per giorno con la vendita registrata prima (l'ordine inverso), «Non scriverlo» con e senza nota, il registro, i piani
 * presi, lo stato nello storico, l'appartamento con il box di un altro proprietario (Ugo + € 163,33, Rita + € 18,15) con una scelta
 * sola, il box che legge la scelta del padre (il suo prospetto), la frase «tornano a …» unità per unità, l'annullamento del passaggio,
 * l'avviso dell'annullamento dopo «Non scriverlo», le fini dove la scelta non si chiede (controlli), le fini registrate prima della
 * .50 (controlli), il caso del centesimo e il pareggio del 2028.
 *
 * ➕ 11/10/2026 notte, dalle mutazioni a mano della Fase 1: aggiunti la coppia fra persone con quote diverse (40 e 60: con 50 e 50 la
 *   divisione è esatta e un arrotondamento sbagliato passava), la vendita del 1/11 nella divisione per giorno (con il 1/10 i due tratti
 *   sono uguali e un giorno in meno per tratto passava), chi ha pagato i giorni dopo l'uscita unità per unità nel prospetto e nella
 *   stampa del box, «Annulla il conguaglio» dopo «Scrivi», il prospetto delle fini senza scelta nel registro (la variante larga A′), i
 *   piani presi nei controlli (decisione 47), il cambio d'inquilino nelle frasi «della successione». Il registro si controlla sulla
 *   chiave `conguaglio.scelta`, non sull'intero `conguaglio`: il verbale vuole anche i periodi della coppia nel registro.
 *
 * Cosa NON copre: la pagina Vue e il modulo (le due voci, la conferma spenta, le frasi a video); il cambio d'inquilino con un nuovo
 * inquilino (`CambioInquilinoSceltaTest`); il coinquilino che resta (dalla 1.11.0-beta.55 la regola del piano: `CoinquilinoCheRestaTest`,
 * `Beta55RegolaDelPianoTest`, `Beta55CoinquilinoCoppiaDelPianoTest`); la fermata della vendita dopo
 * «Scrivi» e l'inizio di una locazione dopo una fine «Scrivi» (area della fermata); il prospetto dell'unità principale con le due
 * strade, il cambio in due passi e le fini senza scelta nel registro nella stampa (area del prospetto); un `conguaglio` mandato a mano
 * quando l'anteprima non propone coppie; le gestioni straordinarie e gli esercizi non solari; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

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

/** La frase del rifiuto senza la scelta: la stessa della successione (decisione 77: «le voci della .48», un vocabolario solo). */
const FLS_SENZA_SCELTA = 'Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.';

/**
 * Lo scenario di riferimento con le sei rate a giornale: i titolari dati, Ines inquilina dal 2019, il preventivo generato con Ines e
 * le rate gennaio–giugno emesse dalla rotta della pagina del piano. Il piano non si ricalcola più (ha una scrittura).
 */
function flsConSeiRate($test, array $titolari, array $opzioni = []): array
{
    $s = fclScenario($titolari, $opzioni);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/**
 * Le cinque forme dell'unità della decisione 32 (`d32Attesi` del laboratorio), con le cifre fatte a mano: le righe oltre a Ines; le
 * righe in saldi con «Scrivi»; i netti con «Scrivi» (strada B); i netti con «Non scriverlo» (strada A′, le cifre verso il condominio
 * restano quelle emesse).
 */
function flsForme(): array
{
    return [
        'piena (Ugo)' => [
            [['Ugo', 'proprietario', 100]],
            // La coppia tutta a Ugo, l'unico anello dopo l'inquilino: 18148 × 100/100.
            ['Ines' => -18148, 'Ugo' => 18148],
            // Ugo 84000 + 18148 = 102148; Ines 36000 − 18148 = 17852. Somma 120000.
            ['Ines' => 17852, 'Ugo' => 102148],
            ['Ines' => 36000, 'Ugo' => 84000],
        ],
        'in comproprietà (Ugo 50, Rita 50)' => [
            [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]],
            // Pesi quota × giorni: 50 × 184 e 50 × 184 → 18148 / 2 = 9074 a testa, esatti.
            ['Ines' => -18148, 'Rita' => 9074, 'Ugo' => 9074],
            // «Proprietario» 84000 / 2 = 42000 a testa; 42000 + 9074 = 51074. 17852 + 51074 + 51074 = 120000.
            ['Ines' => 17852, 'Rita' => 51074, 'Ugo' => 51074],
            ['Ines' => 36000, 'Rita' => 42000, 'Ugo' => 42000],
        ],
        'in usufrutto (Ugo usufruttuario, Bice nuda)' => [
            [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]],
            // L'anello dopo l'inquilino è l'usufruttuario (art. 1004 c.c.), non la nuda: 18148 a Ugo.
            ['Ines' => -18148, 'Ugo' => 18148],
            // Il «Proprietario» alla nuda, che è la sola proprietaria: Bice 84000. 17852 + 18148 + 84000 = 120000.
            ['Bice' => 84000, 'Ines' => 17852, 'Ugo' => 18148],
            ['Bice' => 84000, 'Ines' => 36000],
        ],
        'mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50)' => [
            [['Ugo', 'proprietario', 50], ['Ugo', 'usufruttuario', 50], ['Bice', 'nuda_proprietario', 50]],
            // L'usufruttuario con il gemello pieno (31.1): righe 50 × 184 e 50 × 184, tutte e due di Ugo → per persona 18148.
            ['Ines' => -18148, 'Ugo' => 18148],
            // «Proprietario» 84000: Ugo pieno 42000, Bice nuda 42000 (il gemello del capitale). Ugo 42000 + 18148 = 60148.
            ['Bice' => 42000, 'Ines' => 17852, 'Ugo' => 60148],
            ['Bice' => 42000, 'Ines' => 36000, 'Ugo' => 42000],
        ],
        'mista con due persone (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50)' => [
            [['Ugo', 'proprietario', 50], ['Carlo', 'usufruttuario', 50], ['Bice', 'nuda_proprietario', 50]],
            // Carlo usufruttuario e Ugo pieno, pesi 50 × 184 ciascuno: 9074 a testa, esatti.
            ['Carlo' => 9074, 'Ines' => -18148, 'Ugo' => 9074],
            // Ugo 42000 + 9074 = 51074; Bice 42000; Carlo 9074. 17852 + 9074 + 51074 + 42000 = 120000.
            ['Bice' => 42000, 'Carlo' => 9074, 'Ines' => 17852, 'Ugo' => 51074],
            ['Bice' => 42000, 'Ines' => 36000, 'Ugo' => 42000],
        ],
    ];
}

/** Le righe in saldi di un solo passaggio (padre e figli) su un'unità: nome → centesimi, senza gli zeri. */
function flsSaldiDel(array $s, Subentro $passaggio, ?\App\Models\Immobile $unita = null): array
{
    $ids = Subentro::where('id', $passaggio->id)->orWhere('subentro_padre_id', $passaggio->id)->pluck('id');
    $nomi = fclNomi($s);
    $out = [];
    foreach (DB::table('saldi')->whereIn('subentro_id', $ids)->where('immobile_id', ($unita ?? $s['unita'])->id)->get(['anagrafica_id', 'saldo_iniziale']) as $r) {
        $chi = $nomi[(int) $r->anagrafica_id] ?? "anagrafica {$r->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $r->saldo_iniziale;
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/** La voce di un passaggio nello storico dell'Interno 1 (`StoricoTitolarita::perImmobile`), come la riceve la pagina. */
function flsVoceStorico(array $s, Subentro $passaggio): array
{
    return collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id) ?? [];
}

/** I periodi delle frasi che contengono «tornano»: le frasi che dicono a chi vanno le voci dell'inquilino dopo l'uscita. */
function flsTornano(string $testo): array
{
    return array_values(array_filter(fclPeriodi($testo), fn (string $p) => preg_match('/\btornano\b/u', $p) === 1));
}

// ---------------------------------------------------------------------------------------------------------------------
// La scelta: senza, si rifiuta; l'anteprima propone la coppia
// ---------------------------------------------------------------------------------------------------------------------

it('senza la scelta la fine locazione con sei rate a giornale si ferma sul campo «conguaglio», e non scrive niente', function () {
    // Decisione 77 (1): «nessuna delle due già scelta».
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    fclStore($this, $s, fclModulo($s))->assertSessionHasErrors(['conguaglio' => FLS_SENZA_SCELTA]);

    // Niente è cambiato: nessun passaggio, la riga di Ines aperta, nessuna riga in saldi, le cifre di prima (Ines 36000, Ugo 84000).
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ines'))->value('data_fine'))->toBeNull()
        ->and(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('l\'anteprima senza la scelta riesce e propone la coppia su cui si sceglie: € 181,48 di Ines verso Ugo, chi paga al posto dell\'inquilino', function () {
    // Decisione 77 (1) e condizione 1 di `decide_l_amministratore.md`: la domanda compare solo quando le due strade danno cifre
    // diverse, cioè quando l'anteprima propone una coppia.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    // La coppia: 36000 × 184/365 = 18147,95 → 18148 (vedi la testa del file), tutta a Ugo.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 18148]]);
});

// ---------------------------------------------------------------------------------------------------------------------
// «Scrivi»: la coppia per persona e per giorno
// ---------------------------------------------------------------------------------------------------------------------

it('«Scrivi» sulle cinque forme dell\'unità: la coppia porta i 184 giorni dopo l\'uscita a chi paga al posto dell\'inquilino, e i netti sono quelli del piano generato dopo (`d32Attesi`, strada B)', function (array $titolari, array $saldi, array $nettiScrivi) {
    // Decisioni 21, 32 e 77 (1). Le cifre a mano sono in `flsForme()`.
    $s = flsConSeiRate($this, $titolari);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(flsSaldiDel($s, $fine))->toBe($saldi)
        ->and(fclNetti($s))->toBe($nettiScrivi);
})->with(flsForme());

it('«Scrivi» divide la coppia per persona con tutte le sue righe, come la catena del ripiego della generazione, non una riga per persona', function (array $titolari, array $saldi, array $netti) {
    // Scelta tecnica del verbale (la coppia per persona, con tutte le righe della persona; misura degli oracoli: con due righe per
    // persona i pesi di chi sta al posto dell'inquilino sbagliavano).
    $s = flsConSeiRate($this, $titolari);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(flsSaldiDel($s, $fine))->toBe($saldi)
        ->and(fclNetti($s))->toBe($netti);
})->with([
    'Ugo con due righe da proprietario (30 e 20), Rita 50' => [
        [['Ugo', 'proprietario', 30], ['Ugo', 'proprietario', 20], ['Rita', 'proprietario', 50]],
        // Per persona, quota × giorni dopo l'uscita: Ugo (30 + 20) × 184 = 9200, Rita 50 × 184 = 9200 → 18148 / 2 = 9074 a testa,
        // esatti. (Con una riga sola per Ugo, la prima da 30: 18148 × 30/80 = 6805,5 e × 50/80 = 11342,5 → 6806 e 11342: sbagliato.)
        ['Ines' => -18148, 'Rita' => 9074, 'Ugo' => 9074],
        // «Proprietario» 84000 per quota: Ugo 30 + 20 = 50 → 42000, Rita 42000. Ugo e Rita 42000 + 9074 = 51074. Somma 120000.
        ['Ines' => 17852, 'Rita' => 51074, 'Ugo' => 51074],
    ],
    'mista con l\'usufrutto diviso (Ugo usufruttuario 30 e pieno 50, Carlo usufruttuario 20, Bice nuda 50)' => [
        [['Ugo', 'usufruttuario', 30], ['Carlo', 'usufruttuario', 20], ['Ugo', 'proprietario', 50], ['Bice', 'nuda_proprietario', 50]],
        // L'anello degli usufruttuari con il gemello pieno: Ugo 30 × 184 + 50 × 184 = 14720, Carlo 20 × 184 = 3680, su 18400.
        // 18148 × 14720/18400 = 14518,4 e 18148 × 3680/18400 = 3629,6: parti intere 14518 + 3629 = 18147, il centesimo al resto
        // più grande (,6 di Carlo) → Ugo 14518, Carlo 3630.
        ['Carlo' => 3630, 'Ines' => -18148, 'Ugo' => 14518],
        // «Proprietario» 84000: Ugo pieno 42000, Bice nuda 42000. Ugo 42000 + 14518 = 56518. 42000 + 3630 + 17852 + 56518 = 120000.
        ['Bice' => 42000, 'Carlo' => 3630, 'Ines' => 17852, 'Ugo' => 56518],
    ],
]);

it('«Scrivi» in comproprietà con quote diverse (Ugo 40, Rita 60): la coppia si divide fra le persone con i resti maggiori, il centesimo a chi ha il resto più grande', function () {
    // Regola della coppia (misure della Fase 1). Aggiunto nella revisione dei test della Fase 1: con le quote 50 e 50 la divisione fra le
    // persone è esatta, e un arrotondamento sbagliato (l'avanzo alla prima persona invece che al resto più grande) passava inosservato.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 40], ['Rita', 'proprietario', 60]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // La coppia 18148 (vedi la testa del file), per quota × giorni dopo l'uscita: 18148 × 40/100 = 7259,2 e × 60/100 = 10888,8; parti
    // intere 7259 + 10888 = 18147, il centesimo al resto più grande (,8 di Rita) → Ugo 7259, Rita 10889.
    expect(flsSaldiDel($s, $fine))->toBe(['Ines' => -18148, 'Rita' => 10889, 'Ugo' => 7259])
        // «Proprietario» 84000 × 40 % = 33600 e × 60 % = 50400, esatti. Ugo 33600 + 7259 = 40859, Rita 50400 + 10889 = 61289, Ines
        // 17852: 40859 + 61289 + 17852 = 120000. (Il piano generato dopo, un giro solo: Ugo 33600 + 36000 × 0,4 × 184/365 = 40859,18,
        // Rita 50400 + 36000 × 0,6 × 184/365 = 61288,77, Ines 17852,05 → 40859 + 61288 + 17852 = 119999, il centesimo al resto ,77 →
        // le stesse cifre.)
        ->and(fclNetti($s))->toBe(['Ines' => 17852, 'Rita' => 61289, 'Ugo' => 40859]);
});

it('«Scrivi» divide la coppia per giorno su tutto il periodo dopo l\'uscita: con la vendita di Ugo a Elsa registrata prima, con una data dopo l\'uscita, ciascuno ha i suoi giorni', function (string $vendita, string $emessoFino, int $emesse, array $saldi, array $netti, array $generato) {
    // Scelta tecnica del verbale («per persona e per giorno su tutto il periodo dopo l'uscita … chiude da sola l'ordine inverso»; S5
    // della revisione). La vendita del 1/11 l'ha aggiunta la revisione dei test della Fase 1: con quella del 1/10 i due tratti hanno
    // 92 giorni ciascuno, e un errore che toglie un giorno a ogni tratto (91 e 91) dava le stesse cifre.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    fclEmetti($this, $s, $emessoFino);
    expect(fclEmesse($s))->toBe($emesse);
    $elsa = fclPersona($s, 'Elsa');
    fclRegistra($this, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => $vendita, 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(flsSaldiDel($s, $fine))->toBe($saldi)
        ->and(fclNetti($s))->toBe($netti);
    // Il piano generato dopo i due passaggi, a mano: tolleranza dichiarata di un centesimo per persona.
    foreach ($generato as $chi => $c) {
        expect(abs(fclNetti($s)[$chi] - $c))->toBeLessThanOrEqual(1);
    }
})->with([
    'vendita del 1/10' => [
        '2026-10-01', '2026-09-30', 9,
        // I 184 giorni dopo l'uscita: Ugo 1/7–30/9 = 31 + 31 + 30 = 92, Elsa 1/10–31/12 = 31 + 30 + 31 = 92. 18148 × 92/184 = 9074 a
        // testa, esatti.
        ['Elsa' => 9074, 'Ines' => -18148, 'Ugo' => 9074],
        // I netti: «Proprietario» 84000 × [273, 92] / 365 = 62827,40 / 21172,60 → Ugo 62827, Elsa 21173 (il centesimo al resto ,60);
        // più i giorni dopo l'uscita: Ugo 62827 + 9074 = 71901, Elsa 21173 + 9074 = 30247; Ines 17852. 71901 + 30247 + 17852 = 120000.
        // (Con tutta la coppia a Ugo: Ugo 80975, Elsa 21173, cioè € 90,74 in meno a chi compra.)
        ['Elsa' => 30247, 'Ines' => 17852, 'Ugo' => 71901],
        // Il piano generato dopo, un giro solo: Ines 36000 × 181/365 = 17852,05; Ugo 120000 × (0,7 × 273 + 0,3 × 92) / 365 = 71901,37;
        // Elsa 120000 × 92/365 = 30246,58 → 17852 + 71901 + 30246 = 119999, il centesimo al resto ,58 → Elsa 30247: le stesse cifre.
        ['Elsa' => 30247, 'Ines' => 17852, 'Ugo' => 71901],
    ],
    'vendita del 1/11' => [
        '2026-11-01', '2026-10-31', 10,
        // Ugo 1/7–31/10 = 31 + 31 + 30 + 31 = 123 giorni, Elsa 1/11–31/12 = 30 + 31 = 61; 123 + 61 = 184. 18148 × 123/184 = 12131,67 e
        // × 61/184 = 6016,33: parti intere 12131 + 6016 = 18147, il centesimo al resto più grande (,67) → Ugo 12132, Elsa 6016.
        ['Elsa' => 6016, 'Ines' => -18148, 'Ugo' => 12132],
        // La vendita: «Proprietario» 84000 × [304, 61] / 365 = 69961,64 / 14038,36 → 69961 + 14038 = 83999, il centesimo al resto ,64 →
        // Ugo 69962, Elsa 14038. Più la fine: Ugo 69962 + 12132 = 82094, Elsa 14038 + 6016 = 20054; Ines 17852. 82094 + 20054 + 17852 =
        // 120000.
        ['Elsa' => 20054, 'Ines' => 17852, 'Ugo' => 82094],
        // Il piano generato dopo, un giro solo: Ugo 120000 × (0,7 × 304 + 0,3 × 123) / 365 = 82093,15; Elsa 120000 × 61/365 = 20054,79;
        // Ines 17852,05 → 82093 + 20054 + 17852 = 119999, il centesimo al resto ,79 → Elsa 20055. Un centesimo da Ugo a Elsa rispetto
        // alla coppia: la tolleranza dichiarata.
        ['Elsa' => 20055, 'Ines' => 17852, 'Ugo' => 82093],
    ],
]);

// ---------------------------------------------------------------------------------------------------------------------
// «Non scriverlo», il registro, lo storico
// ---------------------------------------------------------------------------------------------------------------------

it('«Non scriverlo» sulle cinque forme dell\'unità, senza nota: nessuna riga in saldi, e le cifre restano quelle di prima (strada A′)', function (array $titolari, array $saldi, array $nettiScrivi, array $nettiNonScrivere) {
    // Decisione 77 (1): «Non scriverlo» lascia le rate all'inquilino, e la nota è facoltativa (decisione 73.1, come la successione).
    $s = flsConSeiRate($this, $titolari);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    expect($fine->nota_conguaglio)->toBeNull()
        ->and(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe($nettiNonScrivere);
})->with(flsForme());

it('«Non scriverlo» con una nota la conserva, e non scrive righe', function () {
    // La nota è facoltativa, ma se c'è si conserva (come la successione della .48).
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Ugo e Ines regolano fra loro alla riconsegna delle chiavi']);

    expect($fine->nota_conguaglio)->toBe('Ugo e Ines regolano fra loro alla riconsegna delle chiavi')
        ->and(fclRigheDeiPassaggi($s))->toBe(0);
});

it('la scelta resta nel registro del passaggio, `conguaglio.scelta` «scritto» o «non_scritto», e il passaggio prende il piano', function (string $campo, string $registro, bool $nonScritto) {
    // Decisione 77 (1) e condizione 2 di `decide_l_amministratore.md` («ogni scelta lascia traccia»); nota datata da scrivere sulla
    // decisione 47 («la fine senza successore ha un conguaglio con la scelta»): il passaggio prende i piani, come la successione della
    // .48 con le due voci.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => $campo])->fresh();

    expect($fine->registro['conguaglio']['scelta'] ?? null)->toBe($registro)
        ->and($fine->conguaglioNonScritto())->toBe($nonScritto)
        ->and($fine->haUnConguaglio())->toBeTrue()
        ->and($fine->registro['piani_presi'] ?? null)->toBe([(int) $s['piano']->id]);
})->with([
    'Scrivi' => ['scrivi', 'scritto', false],
    'Non scriverlo' => ['non_scrivere', 'non_scritto', true],
]);

it('«Non scriverlo» conserva nel registro la cifra che resta da regolare fra le parti, per gestione: € 181,48 sulla Ordinaria 2026', function () {
    // Decisione 46, come per la successione della .48: il registro porta l'importo per gestione (`regolato_fuori`), perché il rifiuto
    // del ricalcolo e l'annullamento possano dire la cifra.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();

    $fuori = collect($fine->registro['regolato_fuori'] ?? []);
    // La coppia non scritta: 18148 (vedi la testa del file).
    expect($fuori->map(fn ($r) => [$r['gestione'] ?? null, (int) ($r['importo'] ?? 0)])->all())->toBe([['Ordinaria 2026', 18148]]);
});

it('lo storico dice lo stato del conguaglio della fine: «proposto» con le due righe dopo «Scrivi», «non scritto» dopo «Non scriverlo»', function (string $campo, string $stato) {
    // Condizione 2 (la traccia): lo storico legge il registro come per la successione della .48.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => $campo]);

    expect(flsVoceStorico($s, $fine)['conguaglio']['stato'] ?? null)->toBe($stato);
})->with([
    'Scrivi' => ['scrivi', 'proposto'],
    'Non scriverlo' => ['non_scrivere', 'non_scritto'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// L'appartamento e il box di un altro proprietario: una scelta sola, la coppia per unità
// ---------------------------------------------------------------------------------------------------------------------

/**
 * L'Interno 1 di Ugo (900 millesimi) e il Box 12 di Rita (100), sua pertinenza; Ines inquilina di tutti e due dal 2019; sei rate a
 * giornale. Interno 1: € 1.200,00 × 900/1000 = 108000, «Inquilino» 32400, «Proprietario» 75600; Box: × 100/1000 = 12000,
 * «Inquilino» 3600, «Proprietario» 8400.
 */
function flsBoxDiRita($test): array
{
    return flsConSeiRate($test, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
}

it('l\'appartamento e il box di Rita spuntato, «Scrivi»: una scelta sola, e la coppia di ogni unità va a chi sta al posto dell\'inquilino su quell\'unità (Ugo + € 163,33, Rita + € 18,15)', function () {
    // Decisione 77 (1): «una scelta sola per l'appartamento e le pertinenze spuntate, con le cifre per unità e per persona»; misura
    // degli oracoli, box di Rita.
    $s = flsBoxDiRita($this);

    // L'anteprima propone le due coppie, una per unità, con la persona di quell'unità.
    // Interno 1: 32400 × [181, 184] = 16066,85 / 16333,15 → parti intere 16066 + 16333 = 32399, il centesimo al resto più grande (,85,
    // di Ines) → Ines 16067, la coppia 16333 a Ugo. Box: 3600 × [181, 184] = 1785,21 / 1814,79 → 1785 + 1814 = 3599, il centesimo a ,79
    // → Ines 1785, la coppia 1815 a Rita.
    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333], [(int) $s['box']->id, 'Rita', 1815]]);

    // Un campo solo vale per tutte e due le unità.
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);

    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Ugo' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Rita' => 1815])
        // I netti, uguali al piano generato dopo (misura degli oracoli): Interno 1 Ines 16067, Ugo 75600 + 16333 = 91933 (somma
        // 108000); Box Ines 1785, Rita 8400 + 1815 = 10215 (somma 12000).
        ->and(fclNetti($s))->toBe(['Ines' => 16067, 'Ugo' => 91933])
        ->and(fclNetti($s, $s['box']))->toBe(['Ines' => 1785, 'Rita' => 10215]);
});

it('l\'appartamento e il box di Rita spuntato, «Non scriverlo»: nessuna riga né sull\'unità né sul box', function () {
    // La stessa scelta sola, l'altra voce.
    $s = flsBoxDiRita($this);

    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'non_scrivere']);

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        // Le cifre di prima: Interno 1 Ines 32400, Ugo 75600; Box Ines 3600, Rita 8400.
        ->and(fclNetti($s))->toBe(['Ines' => 32400, 'Ugo' => 75600])
        ->and(fclNetti($s, $s['box']))->toBe(['Ines' => 3600, 'Rita' => 8400]);
});

it('il box legge la scelta nel registro del padre: dopo «Scrivi» la fine del box ha un conguaglio, e il suo prospetto dice i 181 giorni di Ines e i 184 pagati con la coppia, come i netti', function () {
    // Scelta tecnica del verbale («il box legge la scelta nel registro del padre»): senza, il prospetto del box resta a 365 giorni e
    // contraddice i netti (misura del prospetto).
    $s = flsBoxDiRita($this);

    $fine = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $figlio = Subentro::where('subentro_padre_id', $fine->id)->where('immobile_id', $s['box']->id)->firstOrFail();

    expect($figlio->haUnConguaglio())->toBeTrue()
        // Box: Ines 1785 per i suoi 181 giorni, nelle sue rate (3600 − 1815 della coppia); i 184 giorni dopo l'uscita, 1815, senza
        // conduttore (li ha pagati Rita con la coppia). 1785 + 1815 = 3600.
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => ['ines' => [1785, 1785, 0, 181]], 'senza' => [1815, 184]]);
});

it('il box legge la scelta nel registro del padre: dopo «Non scriverlo» il prospetto del box dice i 184 giorni dopo l\'uscita da restituire a Ines (strada A′)', function () {
    // Scelte tecniche del verbale: «Non scriverlo» è la strada A′, e il box legge la scelta del padre.
    $s = flsBoxDiRita($this);

    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'non_scrivere']);

    // Box: la parte di Ines è quella dei suoi 181 giorni, 1785; nelle sue rate ne ha 3600; da rimborsare −1815, cioè 1815 da
    // restituirle (il segno del prospetto: positivo = il conduttore deve, negativo = gli si deve). I 184 giorni dopo l'uscita, 1815,
    // sono senza conduttore.
    expect(fclProspetto($s, $s['box']))->toBe(['conduttori' => ['ines' => [1785, 3600, -1815, 181]], 'senza' => [1815, 184]]);
});

it('il prospetto dopo «Scrivi» con il box di Rita dice chi ha pagato i giorni dopo l\'uscita unità per unità: Ugo sull\'Interno 1, Rita sul Box 12, e la stampa del box non nomina Ugo', function () {
    // Decisione 77 («unità per unità») e scelta tecnica «il box legge la scelta nel registro del padre». Aggiunto nella revisione dei
    // test della Fase 1: i test del box guardano solo le cifre, e una lettura sbagliata di chi ha ricevuto la coppia (la riga a debito
    // invece di quella a credito, o le righe di tutte le unità del passaggio) passava. Le parole della stampa non sono decise: si guarda
    // il nome accanto a «pagata da», che è il vocabolario già in uso del ripiego.
    $s = flsBoxDiRita($this);

    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $pagatoDa = fn (\App\Models\Immobile $u) => collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($u->fresh(), $s['e'])['senza_conduttore']['voci'])
        ->pluck('pagato_da')->unique()->values()->all();
    $stampaBox = fclStampa($s, $s['box']);

    // Interno 1: la coppia 16333 è di Ugo; Box: la coppia 1815 è di Rita (vedi sopra).
    expect($pagatoDa($s['unita']))->toBe(['Ugo'])
        ->and($pagatoDa($s['box']))->toBe(['Rita'])
        ->and($stampaBox)->toContain('pagata da Rita')
        ->and($stampaBox)->not->toContain('Ugo');
});

// ---------------------------------------------------------------------------------------------------------------------
// La frase «tornano a …», unità per unità
// ---------------------------------------------------------------------------------------------------------------------

it('pannello con il box di Rita: le voci a carico dell\'inquilino «tornano» a Ugo sull\'Interno 1 e a Rita sul Box 12, e la riga del conguaglio del box dice Rita', function () {
    // Decisione 77: «la frase "tornano a …" unità per unità»; misura degli oracoli: sul box il motore la sbaglia due volte (dice
    // «tornano a Ugo» una volta sola, e «debito € 18,15 a Ugo» mentre i soldi vanno a Rita). Il pannello prima della scelta, come lo
    // vede l'amministratore.
    $s = flsBoxDiRita($this);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']])));
    $tornano = flsTornano($frasi);

    // Almeno una frase dice che tornano a Rita, e ogni frase che lo dice nomina il box; ogni frase che nomina il box con «tornano»
    // nomina Rita; Ugo resta nominato per l'Interno 1.
    expect(collect($tornano)->contains(fn ($p) => str_contains($p, 'Rita')))->toBeTrue('nessuna frase dice che sul box le voci tornano a Rita: ' . implode(' | ', $tornano))
        ->and(collect($tornano)->filter(fn ($p) => str_contains($p, 'Rita'))->every(fn ($p) => str_contains($p, 'Box 12')))->toBeTrue()
        ->and(collect($tornano)->filter(fn ($p) => str_contains($p, 'Box 12'))->every(fn ($p) => str_contains($p, 'Rita')))->toBeTrue()
        ->and(collect($tornano)->contains(fn ($p) => str_contains($p, 'Ugo')))->toBeTrue();
    // La riga del conguaglio del box: € 18,15 a debito di Rita, non di Ugo (1815, vedi sopra).
    $delBox = array_values(array_filter(fclPeriodi($frasi), fn ($p) => str_contains($p, '€ 18,15')));
    expect($delBox)->not->toBe([])
        ->and(collect($delBox)->every(fn ($p) => str_contains($p, 'Rita') && ! str_contains($p, 'a Ugo')))->toBeTrue(implode(' | ', $delBox));
});

it('storico con il box di Rita: dopo la fine registrata la frase «tornano» nomina Rita per il Box 12 e Ugo per l\'Interno 1', function () {
    // La stessa frase, dai fatti registrati (`FrasiObbligati::daSubentro`). Il piano è generato e non emesso: nessuna coppia, nessuna
    // scelta, e la fine si registra come prima della .50 e come dopo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclGenera($s);

    $fine = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));
    $tornano = flsTornano(fclStorico($s, $fine));

    expect(collect($tornano)->contains(fn ($p) => str_contains($p, 'Rita') && str_contains($p, 'Box 12')))->toBeTrue(implode(' | ', $tornano))
        ->and(collect($tornano)->filter(fn ($p) => str_contains($p, 'Box 12'))->every(fn ($p) => str_contains($p, 'Rita')))->toBeTrue()
        ->and(collect($tornano)->contains(fn ($p) => str_contains($p, 'Ugo')))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// L'annullamento
// ---------------------------------------------------------------------------------------------------------------------

it('l\'annullamento di una fine «Scrivi» toglie le due righe e riapre la riga di Ines: le cifre tornano quelle di prima', function () {
    // Decisione 27 e oracolo di progetto di `Decisione32Test` («l'annullamento rimette la strada A»).
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);

    fclAnnulla($this, $s, $fine)->assertStatus(302);

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ines'))->value('data_fine'))->toBeNull()
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
});

it('«Annulla il conguaglio» dopo una fine «Scrivi»: le due righe si tolgono, il registro conserva € 181,48 regolati fra le parti, il piano resta fermo, e il prospetto torna com\'era (decisioni 43 e 46)', function () {
    // Scelte tecniche del verbale: «43 e 46 restano per la casella di prima e per "Annulla il conguaglio"»; misura del prospetto: dopo
    // «Annulla il conguaglio» su una fine senza successore il prospetto torna a 365 giorni (le parti hanno regolato), non alla strada A′.
    // Aggiunto nella revisione dei test della Fase 1: nessun test della fine senza nuovo inquilino passava da questa rotta.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]),
        ['nota_annullamento_conguaglio' => 'Ugo e Ines hanno regolato fra loro alla riconsegna delle chiavi'])->assertSessionHasNoErrors();
    $fine = $fine->fresh();

    // Le cifre di prima della fine: Ines 36000, Ugo 84000; la coppia tolta, 18148, resta nel registro come cifra regolata fra le parti.
    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000])
        ->and($fine->conguaglioAnnullato())->toBeTrue()
        ->and(collect($fine->registro['regolato_fuori'] ?? [])->map(fn ($r) => [$r['gestione'] ?? null, (int) ($r['importo'] ?? 0)])->all())->toBe([['Ordinaria 2026', 18148]])
        // Decisione 43: il piano preso resta fermo, anche per il conguaglio.
        ->and($s['piano']->fresh()->ragioniDelFermo())->toContain('conguaglio')
        // Il prospetto com'era: Ines 365 giorni, 36000 nelle sue rate, niente da rimborsare, nessun giorno senza conduttore.
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]]);
});

it('l\'avviso dell\'annullamento e il rifiuto del ricalcolo dopo «Non scriverlo» dicono il conguaglio della locazione, non «della successione», con la cifra', function (bool $conLuca) {
    // Correzione (6) della revisione nella Fase 1 della .50: le frasi del «non scritto» (`AnnullaPassaggioAction::avvisi`,
    // `PianoRate::fraseRegolatoFuori`) sono fisse sulla successione. La revisione dei test della Fase 1 ha aggiunto il cambio
    // d'inquilino: con la .50 anche lì «Non scriverlo» è un «non scritto», e la frase sarebbe la stessa.
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $luca = $conLuca ? fclPersona($s, 'Luca') : null;
    $fine = fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'non_scrivere']);

    $avvisi = implode(' | ', flsVoceStorico($s, $fine)['annullabile']['avvisi'] ?? []);
    $piano = (string) $s['piano']->fresh()->fraseRegolatoFuori();

    // La cifra: 18148 → € 181,48 (vedi la testa del file).
    expect($avvisi)->toContain('€ 181,48')
        ->and($avvisi)->not->toContain('della successione')
        ->and($piano)->toContain('€ 181,48')
        ->and($piano)->not->toContain('della successione');
})->with(['fine senza nuovo inquilino' => [false], 'cambio d\'inquilino (Luca)' => [true]]);

// ---------------------------------------------------------------------------------------------------------------------
// Dove la scelta non si chiede (controlli: il comportamento era già giusto prima della .50, e deve restarlo)
// ---------------------------------------------------------------------------------------------------------------------

it('controllo — quando le due strade danno le stesse cifre la scelta non si chiede: nessun piano, un piano generato e mai emesso, una voce tutta sul proprietario', function (string $caso) {
    // Condizione 1 di `decide_l_amministratore.md` e verbale della .50 («quando l'anteprima propone coppie, cioè con un piano che non si
    // riscrive più»). Controllo: già giusto prima della .50.
    $s = fclScenario([['Ugo', 'proprietario', 100]], $caso === 'voce tutta sul proprietario' ? ['ripartizione' => ['proprietario' => 100]] : []);
    if ($caso !== 'nessun piano generato') {
        fclGenera($s);
    }
    if ($caso === 'voce tutta sul proprietario') {
        fclEmetti($this, $s, '2026-06-30');
        expect(fclEmesse($s))->toBe(6);
    }

    expect(fclCoppie(fclAnteprima($this, $s, fclModulo($s))))->toBe([]);
    $fine = fclRegistra($this, $s, fclModulo($s))->fresh();

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and($fine->registro)->not->toHaveKey('conguaglio')
        // Decisione 47: senza conguaglio la fine non prende nessun piano, nemmeno quello a giornale della voce tutta sul proprietario
        // (aggiunto nella revisione dei test della Fase 1: una fine che prendesse sempre i piani passava).
        ->and($fine->registro['piani_presi'] ?? null)->toBe([]);
})->with(['nessun piano generato', 'piano generato e mai emesso', 'voce tutta sul proprietario']);

// ---------------------------------------------------------------------------------------------------------------------
// Le fini registrate prima della .50 restano come sono (controlli)
// ---------------------------------------------------------------------------------------------------------------------

it('controllo — una fine senza scelta nel registro (registrata prima della .50, o prima dell\'emissione) non ha un conguaglio e non prende il piano emesso dopo, anche senza `piani_presi`', function (bool $primaDellaQuarantadue) {
    // Verbale della .50, Fase 0.2 («regola di lettura sicura: una fine senza nuovo inquilino ha un conguaglio solo se il suo registro
    // porta `conguaglio.scelta`»): allargare `haUnConguaglio()` a ogni fine locazione farebbe cadere i passaggi senza `piani_presi`
    // nella regola della .41 di `presoNelConguaglioDa` (le quote c'erano e una scrittura entro l'ora: qui l'emissione subito dopo).
    // Controllo: già giusto prima della .50.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $fine = fclRegistra($this, $s, fclModulo($s));
    fclEmetti($this, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    if ($primaDellaQuarantadue) {
        $registro = $fine->fresh()->registro;
        unset($registro['piani_presi']);
        $fine->update(['registro' => array_replace($registro, ['versione' => 1])]);
    }
    $fine = $fine->fresh();
    $piano = $s['piano']->fresh();

    expect($fine->registro)->not->toHaveKey('conguaglio')
        ->and($fine->haUnConguaglio())->toBeFalse()
        ->and($piano->passaggiCheLoHannoConguagliato())->toBe([])
        ->and($piano->ragioniDelFermo())->toBe(['scrittura'])
        // Il denaro non cambia: emesso senza ricalcolo, Ines resta con l'anno intero (decisione 40), nessuna riga in saldi.
        ->and(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000]);
})->with(['registrata con la .42 e dopo' => [false], 'registrata prima della .42, senza `piani_presi`' => [true]]);

it('il prospetto di una fine senza scelta nel registro (registrata prima dell\'emissione, poi emessa senza ricalcolo) dice la strada A′: Ines i suoi 181 giorni, e € 181,48 da restituirle', function (bool $primaDellaQuarantadue) {
    // Scelte tecniche del verbale: «il prospetto delle fini senza scelta nel registro (prima della .50) usa la strada A′ ("nessun
    // conguaglio"), tranne con un coinquilino che resta»; decisione 40 (chi emette senza ricalcolare ha scelto la strada A); la 77 chiude
    // la scheda a 365 giorni dopo una fine senza successore. Aggiunto nella revisione dei test della Fase 1: il ramo della variante larga
    // non lo guardava nessun test dell'area (la mutazione «stretta» sopravviveva). È anche dell'area del prospetto.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $fine = fclRegistra($this, $s, fclModulo($s));
    fclEmetti($this, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    if ($primaDellaQuarantadue) {
        $registro = $fine->fresh()->registro;
        unset($registro['piani_presi']);
        $fine->update(['registro' => array_replace($registro, ['versione' => 1])]);
    }

    // 36000 × [181, 184] = 17852,05 / 18147,95 → 17852 e 18148 (vedi la testa del file). Ines ha nelle sue rate l'anno intero, 36000;
    // i suoi giorni valgono 17852, le si devono 18148 (il segno del prospetto: negativo = le si deve); i 184 giorni dopo l'uscita sono
    // senza conduttore.
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181]], 'senza' => [18148, 184]]);
})->with(['registrata con la .42 e dopo' => [false], 'registrata prima della .42, senza `piani_presi`' => [true]]);

// ---------------------------------------------------------------------------------------------------------------------
// La tolleranza dichiarata rispetto al piano generato dopo
// ---------------------------------------------------------------------------------------------------------------------

it('il caso del centesimo — voce € 858,35 al 24 % sull\'inquilino: «Scrivi» dà le cifre esatte della coppia, che differiscono di un centesimo dal piano generato dopo (tolleranza dichiarata)', function () {
    // Scelta tecnica del verbale: uguaglianza esatta con la regola della coppia, un centesimo di tolleranza per unità rispetto al piano
    // generato dopo (misura degli oracoli: 13 % dei casi sull'unità sola).
    $s = flsConSeiRate($this, [['Ugo', 'proprietario', 100]], ['importo' => 85835, 'ripartizione' => ['inquilino' => 24, 'proprietario' => 76]]);
    // Il piano con Ines tutto l'anno, un giro solo di resti maggiori sulla voce: 85835 × 24 % = 20600,40 e × 76 % = 65234,60 → parti
    // intere 20600 + 65234 = 85834, il centesimo al resto ,60 → Ines 20600, Ugo 65235.
    expect(fclNetti($s))->toBe(['Ines' => 20600, 'Ugo' => 65235]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // La coppia: 20600 × [181, 184] = 10215,34 / 10384,66 → parti intere 10215 + 10384 = 20599, il centesimo al resto ,66 → la coppia
    // 10385. Ines 20600 − 10385 = 10215; Ugo 65235 + 10385 = 75620.
    expect(flsSaldiDel($s, $fine))->toBe(['Ines' => -10385, 'Ugo' => 10385])
        ->and(fclNetti($s))->toBe(['Ines' => 10215, 'Ugo' => 75620]);
    // Il piano generato dopo, a mano: un giro solo sulla voce, pesi Ines 0,24 × 181/365, Ugo 0,76 + 0,24 × 184/365: Ines 85835 × 0,24
    // × 181/365 = 10215,53, Ugo 75619,47 → parti intere 10215 + 75619 = 85834, il centesimo al resto ,53 → Ines 10216, Ugo 75619. Un
    // centesimo per unità: dichiarato.
    foreach (['Ines' => 10216, 'Ugo' => 75619] as $chi => $generato) {
        expect(abs(fclNetti($s)[$chi] - $generato))->toBeLessThanOrEqual(1);
    }
});

it('il pareggio del 2028 — 366 giorni, uscita il 2/7, voce € 360,01 tutta sull\'inquilino: la coppia dà il centesimo del pareggio a chi esce (dichiarato: il piano generato dopo lo dà al ripiego)', function () {
    // Scelta tecnica del verbale («il pareggio del 2028» dichiarato) e misura degli oracoli (7).
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['anno' => 2028, 'importo' => 36001, 'ripartizione' => ['inquilino' => 100]]);
    fclGenera($s);
    fclEmetti($this, $s, '2028-06-30');
    expect(fclEmesse($s))->toBe(6);

    $fine = fclRegistra($this, $s, fclModulo($s, null, '2028-07-02') + ['conguaglio' => 'scrivi']);

    // Ines 1/1–1/7/2028 = 31 + 29 + 31 + 30 + 31 + 30 + 1 = 183 giorni; dopo, 2/7–31/12 = 30 + 31 + 30 + 31 + 30 + 31 = 183. 36001 ×
    // [183, 183] = 18000,5 / 18000,5: parti intere 36000, il centesimo a pari resto va a chi esce → Ines 18001, la coppia 18000 a Ugo.
    expect(flsSaldiDel($s, $fine))->toBe(['Ines' => -18000, 'Ugo' => 18000])
        ->and(fclNetti($s))->toBe(['Ines' => 18001, 'Ugo' => 18000]);
    // Il piano generato dopo dà il centesimo al ripiego (la sua chiave entra prima): Ines 18000, Ugo 18001. Un centesimo: dichiarato.
    foreach (['Ines' => 18000, 'Ugo' => 18001] as $chi => $generato) {
        expect(abs(fclNetti($s)[$chi] - $generato))->toBeLessThanOrEqual(1);
    }
});
