<?php

/**
 * 1.11.0-beta.47 — la vendita della metà piena sull'unità mista, quando la parte dell'inquilino ricade per ripiego in due
 * tratti sul godimento dell'unità.
 *
 * Da dove nasce: **DL6** del rapporto della sessione 3-bis del laboratorio (la locazione sulla 1.11.0-beta.45, §5.5). Su
 * un'unità mista — Ugo proprietario pieno di una metà e usufruttuario dell'altra, Bice nuda proprietaria — la voce
 * dell'«Inquilino» che nessun inquilino copre va all'usufruttuario e al suo gemello pieno (decisione 31.1): due righe di
 * riparto con lo stesso tratto e gli stessi giorni, una per ruolo. Quando i giorni senza inquilino sono due buchi (febbraio,
 * e dal 1/6) il tratto congelato è l'estensione, e il conguaglio ricostruisce i giorni veri togliendo i tratti delle righe
 * risolte su un altro ruolo (`PeriodoDellaRiga::senzaGliAltri`). Le due righe gemelle hanno ruoli diversi e lo stesso
 * tratto: ciascuna toglie all'altra tutti i giorni, la riga resta «non risolta» e alla vendita della metà piena non passa
 * niente — chi compra non paga i suoi giorni, chi vende li paga, in silenzio. Il pannello dà una ragione falsa: «la
 * competenza delle quote emesse … finisce prima del 1 luglio».
 *
 * Cosa presidia:
 * - la vendita della metà piena sull'unità mista con la stessa persona sui due ruoli del godimento (Ugo pieno e
 *   usufruttuario): Elsa paga i suoi 184 dei 242 giorni del ripiego, e il pannello non dice «finisce prima»;
 * - la stessa vendita con due persone sul godimento (Ugo pieno, Carlo usufruttuario): passa solo la riga di Ugo, quella di
 *   Carlo resta sua;
 * - la terza forma, trovata scrivendo questi test: sull'unità mista con due persone l'usufrutto di Carlo si estingue a metà
 *   anno, prima della generazione. Le due righe gemelle allora non hanno lo stesso tratto (quella di Carlo finisce il 31/8,
 *   quella di Ugo arriva al 31/12), ma pagano gli stessi giorni finché l'usufrutto dura: togliere il tratto di Carlo da
 *   quello di Ugo toglie giorni che Ugo paga, e la vendita della metà piena non conguaglia niente, come nelle prime due;
 * - due controlli verdi già oggi, che la correzione non deve toccare: l'unità piena con lo stesso ripiego in due tratti, e
 *   un usufrutto estinto a metà anno sull'unità in usufrutto, dove la riga di ripiego dell'usufruttuario e quella della
 *   nuda proprietaria tornata piena hanno tratti diversi e si tolgono ancora l'una dall'altra.
 *
 * Il netto di una persona sull'unità è la quota pura delle quote a suo nome più le righe dei conguagli: è ciò che paga
 * dell'anno. Le cifre attese sono quelle del piano generato dopo la vendita (decisione 21), salvo il centesimo che il
 * conguaglio, che divide la riga già arrotondata, può spostare rispetto al riparto di un piano rigenerato.
 *
 * Cosa NON copre: il prospetto degli oneri accessori sulla stessa forma (DL5, e i giorni del ripiego contati due volte,
 * DL4); la frase che il pannello dovrebbe dire quando una riga di ripiego davvero non si ricostruisce (oggi la sceglie il
 * ramo «finisce prima»: qui si controlla solo che non la dica dove la riga si ricostruisce); la decisione 32; la vendita
 * sull'unità mista fuori da queste forme (ripiego in un tratto solo, nel conguaglio; nel prospetto lo provano T12 e T13 di
 * ProspettoCambioInquilinoTest; vendita della nuda); il pannello nella seconda e
 * nella terza forma, dove si guardano solo i netti.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Una persona del condominio dello scenario. */
function umrvPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'umrv-' . strtolower(str_replace(' ', '.', $nome)) . "{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'UMRVPERS' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità censita dal 2019. */
function umrvTitolare(array $s, Anagrafica $p, string $ruolo, float $quota): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** La riga in corso di una persona sull'unità, in un ruolo. */
function umrvRiga(array $s, Anagrafica $p, string $ruolo): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $p->id)->where('tipologia', $ruolo)
        ->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/** Registra un passaggio dalla rotta vera, con il cancello letto. */
function umrvPassa($test, array $s, array $dati): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati + [
        'ho_letto' => true, 'nota_cancello' => 'Passaggio di prova del ripiego in due tratti, letto',
    ])->assertSessionHasNoErrors();
}

/** Il modulo di una fine locazione senza nuovo inquilino. */
function umrvFineLocazione(array $s, Anagrafica $esce, string $il): array
{
    return ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => umrvRiga($s, $esce, 'inquilino'), 'decorrenza' => $il, 'quota' => 100,
        'copia_autentica' => false, 'estremi_titolo' => 'disdetta del contratto', 'pertinenze' => []];
}

/** Il modulo di un inizio locazione. */
function umrvInizioLocazione(Anagrafica $entra, string $il): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'regime_contratto' => 'abitativo', 'anagrafica_entrante_id' => $entra->id, 'decorrenza' => $il, 'quota' => 100,
        'copia_autentica' => false, 'estremi_titolo' => 'contratto registrato', 'pertinenze' => []];
}

/**
 * La locazione dello scenario di DL6, registrata prima della generazione: Ines (inquilina censita) esce il 1/2 senza nessuno
 * dopo, Luca entra il 1/3 ed esce il 1/6 senza nessuno dopo. I giorni senza inquilino sono due buchi: febbraio (28) e dal
 * 1/6 al 31/12 (214), 242 in tutto. Poi il piano si genera e si emettono le sei rate fino al 30/6.
 */
function umrvRipiegoInDueTratti($test, array $s, Anagrafica $ines): Anagrafica
{
    $luca = umrvPersona($s, 'Luca Inquilino');
    umrvPassa($test, $s, umrvFineLocazione($s, $ines, '2026-02-01'));
    umrvPassa($test, $s, umrvInizioLocazione($luca, '2026-03-01'));
    umrvPassa($test, $s, umrvFineLocazione($s, $luca, '2026-06-01'));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Locazione con due vuoti nell\'anno, letta', esercizio: $s['e']);
    ruEmetti($s, '2026-06-30');

    return $luca;
}

/**
 * Il netto di ciascuno sull'unità: la quota pura delle quote a suo nome (non annullate), più le righe dei conguagli.
 *
 * @return array<string, int> nome → centesimi, senza gli zeri
 */
function umrvNetti(array $s): array
{
    $netti = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->where('rate_quote.stato', '!=', 'annullata')->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $netti[(int) $q->anagrafica_id] = ($netti[(int) $q->anagrafica_id] ?? 0) + (int) (json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? 0);
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']->id)->get() as $r) {
        $netti[(int) $r->anagrafica_id] = ($netti[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($netti))->pluck('nome', 'id');
    $out = [];
    foreach ($netti as $id => $c) {
        if ($c !== 0) {
            $out[$nomi[$id]] = $c;
        }
    }
    ksort($out);

    return $out;
}

/**
 * Le righe di ripiego del piano (la parte dell'«Inquilino» risolta su un altro ruolo): chi, con quale ruolo, quanto, su
 * quale tratto congelato e per quanti giorni veri.
 *
 * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string, 5: int}>
 */
function umrvRipiego(array $s): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->where('immobile_id', $s['unita']->id)
        ->where('ruolo_richiesto', 'inquilino')->where('ruolo_risolto', '!=', 'inquilino')->orderBy('ruolo_risolto')->orderBy('id')
        ->get(['anagrafica_id', 'ruolo_risolto', 'importo', 'titolarita_dal', 'titolarita_al', 'giorni_titolarita'])
        ->map(fn ($r) => [$nomi[(int) $r->anagrafica_id], $r->ruolo_risolto, (int) $r->importo, substr((string) $r->titolarita_dal, 0, 10), substr((string) $r->titolarita_al, 0, 10), (int) $r->giorni_titolarita])
        ->all();
}

it('DL6 — unità mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50), ripiego in due tratti (febbraio e dal 1/6), sei rate emesse: Ugo vende la metà piena a Elsa il 1/7, Elsa paga i suoi 184 giorni del ripiego (€ 302,47) e il pannello non dice che la competenza «finisce prima»', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    // Voce «Spese generali» € 1.200,00 tutta sull'«Inquilino», 12 rate dal 5/1. Ugo pieno della sua metà (la riga dello
    // scenario, ridotta al 50 %) e usufruttuario dell'altra; Bice nuda di quella; Ines inquilina. Tutti censiti dal 2019.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    umrvTitolare($s, $ugo, 'usufruttuario', 50);
    umrvTitolare($s, umrvPersona($s, 'Bice Nuda'), 'nuda_proprietario', 50);
    $ines = umrvPersona($s, 'Ines Inquilina');
    umrvTitolare($s, $ines, 'inquilino', 100);
    umrvRipiegoInDueTratti($this, $s, $ines);

    // Il riparto, giusto già oggi: i 242 giorni senza inquilino, € 1.200,00 × 242/365 = 79561,64, vanno a Ugo in due righe
    // gemelle (usufruttuario e proprietario pieno, decisione 31.1) con lo stesso tratto congelato, l'estensione 1/2–31/12.
    // Ognuna 79561,64 / 2 = 39780,82; con Ines 120000 × 31/365 = 10191,78 e Luca 120000 × 92/365 = 30246,58 i resti
    // maggiori (,82 ,82 ,78) danno 39781, 39781 e 10192; Luca 30246. Totale 39781 + 39781 + 10192 + 30246 = 120000.
    expect(umrvRipiego($s))->toBe([
        ['Venditore Ugo', 'proprietario', 39781, '2026-02-01', '2026-12-31', 242],
        ['Venditore Ugo', 'usufruttuario', 39781, '2026-02-01', '2026-12-31', 242],
    ]);

    $vendita = ruPassaggio('vendita', $s['rigaV'], $elsa, '2026-07-01', 50);
    $frasi = implode("\n", ruAnteprima($this, $s, $vendita + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto'])['rate']['frasi']);
    umrvPassa($this, $s, $vendita);

    // Elsa compra la metà piena: la riga del pieno dal 1/7 è sua. I giorni veri della riga sono febbraio (28) e dal 1/6
    // (214): prima dell'atto 28 + 30 di giugno = 58, dopo 184 (1/7–31/12). 39781 × 184/242 = 30246,71 e 39781 × 58/242 =
    // 9534,29: il resto maggiore dà Elsa 30247 e Ugo 9534 (30247 + 9534 = 39781). La riga dell'usufruttuario resta di Ugo:
    // Ugo 39781 + 9534 = 49315. Ines 10192 e Luca 30246 non cambiano. Sono le cifre del piano generato dopo la vendita.
    // Il pannello: oggi dice «la competenza delle quote emesse a Venditore Ugo (€ 795,62) finisce prima del 1 luglio 2026»,
    // e la competenza della riga arriva al 31/12. Le sei bozze restano a Ugo (la quota comprende la parte che tiene), e il
    // conguaglio è la parte di Elsa intera: € 302,47.
    expect(umrvNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Ines Inquilina' => 10192, 'Luca Inquilino' => 30246, 'Venditore Ugo' => 49315])
        ->and($frasi)->not->toContain('finisce prima')
        ->and($frasi)->toContain('€ 302,47');
});

it('DL6, con due persone sul godimento (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50), stesso ripiego in due tratti: Ugo vende la metà piena a Elsa il 1/7, Elsa paga 184 dei 242 giorni della riga di Ugo (€ 302,47) e la riga di Carlo resta sua', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $elsa = $s['a'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    umrvTitolare($s, umrvPersona($s, 'Carlo Usufruttuario'), 'usufruttuario', 50);
    umrvTitolare($s, umrvPersona($s, 'Bice Nuda'), 'nuda_proprietario', 50);
    $ines = umrvPersona($s, 'Ines Inquilina');
    umrvTitolare($s, $ines, 'inquilino', 100);
    umrvRipiegoInDueTratti($this, $s, $ines);

    // Il riparto: le stesse due righe gemelle da 39781 (120000 × 242/365 = 79561,64, metà 39780,82, resti maggiori come
    // sopra), una all'usufruttuario Carlo e una al gemello pieno Ugo, per quota (50 e 50), con lo stesso tratto.
    expect(umrvRipiego($s))->toBe([
        ['Venditore Ugo', 'proprietario', 39781, '2026-02-01', '2026-12-31', 242],
        ['Carlo Usufruttuario', 'usufruttuario', 39781, '2026-02-01', '2026-12-31', 242],
    ]);

    umrvPassa($this, $s, ruPassaggio('vendita', $s['rigaV'], $elsa, '2026-07-01', 50));

    // La riga di Ugo: 39781 × 184/242 = 30246,71 → Elsa 30247; 39781 × 58/242 = 9534,29 → Ugo 9534 (30247 + 9534 = 39781).
    // Carlo resta usufruttuario: la sua riga, 39781, non passa. Ines 10192, Luca 30246.
    expect(umrvNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Carlo Usufruttuario' => 39781, 'Ines Inquilina' => 10192, 'Luca Inquilino' => 30246, 'Venditore Ugo' => 9534]);
});

it('DL6, terza forma — unità mista con due persone sul godimento, l\'usufrutto di Carlo si estingue il 1/9 prima della generazione (Bice torna piena della sua metà): le righe gemelle hanno tratti diversi (1/2–31/8 e 1/2–31/12) ma pagano gli stessi giorni; Ugo vende la metà piena a Elsa il 1/10, Elsa paga 92 dei 242 giorni della riga di Ugo (€ 151,23)', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $carlo = umrvPersona($s, 'Carlo Usufruttuario');
    umrvTitolare($s, $carlo, 'usufruttuario', 50);
    umrvTitolare($s, umrvPersona($s, 'Bice Nuda'), 'nuda_proprietario', 50);
    $ines = umrvPersona($s, 'Ines Inquilina');
    umrvTitolare($s, $ines, 'inquilino', 100);
    $luca = umrvPersona($s, 'Luca Inquilino');
    // La locazione di DL6 (Ines fino al 31/1, Luca dal 1/3 al 31/5), e l'usufrutto di Carlo che si estingue il 1/9.
    umrvPassa($this, $s, umrvFineLocazione($s, $ines, '2026-02-01'));
    umrvPassa($this, $s, umrvInizioLocazione($luca, '2026-03-01'));
    umrvPassa($this, $s, umrvFineLocazione($s, $luca, '2026-06-01'));
    umrvPassa($this, $s, ruPassaggio('estinzione', umrvRiga($s, $carlo, 'usufruttuario'), null, '2026-09-01', 50));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Locazione con due vuoti e usufrutto estinto, letti', esercizio: $s['e']);
    ruEmetti($s, '2026-09-30');

    // Il riparto, giusto già oggi. I 242 giorni senza inquilino: dal 1/2 al 31/8 senza Luca (28 + 92 = 120) all'usufruttuario
    // Carlo e al gemello pieno Ugo, metà ciascuno; dal 1/9 (122) ai proprietari Ugo e Bice, metà ciascuno. In giorni pesati:
    // Carlo 120 × 50 % = 60, Ugo (120 + 122) × 50 % = 121, Bice 122 × 50 % = 61. In centesimi: Carlo 120000 × 60/365 =
    // 19726,03, Ugo 120000 × 121/365 = 39780,82, Bice 120000 × 61/365 = 20054,79; con Ines 10191,78 e Luca 30246,58 i resti
    // maggiori (,82 ,79 ,78) danno Ugo 39781, Bice 20055, Ines 10192; Carlo 19726, Luca 30246. Totale 120000.
    expect(umrvRipiego($s))->toBe([
        ['Venditore Ugo', 'proprietario', 39781, '2026-02-01', '2026-12-31', 242],
        ['Bice Nuda', 'proprietario', 20055, '2026-09-01', '2026-12-31', 122],
        ['Carlo Usufruttuario', 'usufruttuario', 19726, '2026-02-01', '2026-08-31', 120],
    ]);

    umrvPassa($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-10-01', 50));

    // La riga di Ugo, 39781 sui suoi 242 giorni (febbraio e dal 1/6), sempre al 50 %: prima dell'atto 28 + 30 + 31 + 31 + 30
    // = 150 (febbraio, giugno–settembre), dopo 92 (1/10–31/12). 39781 × 92/242 = 15123,36 → Elsa 15123; 39781 × 150/242 =
    // 24657,64 → Ugo 24658 (15123 + 24658 = 39781). Il piano generato dopo la vendita dà Elsa 15123 e Ugo 24657: il
    // centesimo di Ugo è il resto maggiore del riparto rigenerato, che lo dà a Luca (30247), non un errore del conguaglio.
    expect(umrvNetti($s))->toBe(['Acquirente Elsa' => 15123, 'Bice Nuda' => 20055, 'Carlo Usufruttuario' => 19726, 'Ines Inquilina' => 10192, 'Luca Inquilino' => 30246, 'Venditore Ugo' => 24658]);
});

it('controllo — unità piena (Ugo proprietario 100) con lo stesso ripiego in due tratti: la vendita a Elsa il 1/7 conguaglia già giusto, 184 dei 242 giorni (€ 604,93)', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $ines = umrvPersona($s, 'Ines Inquilina');
    umrvTitolare($s, $ines, 'inquilino', 100);
    umrvRipiegoInDueTratti($this, $s, $ines);

    // Una riga sola di ripiego: 120000 × 242/365 = 79561,64; con Ines 10191,78 e Luca 30246,58 i resti maggiori (,78 ,64)
    // danno 79562 e 10192; Luca 30246. Totale 79562 + 10192 + 30246 = 120000.
    expect(umrvRipiego($s))->toBe([['Venditore Ugo', 'proprietario', 79562, '2026-02-01', '2026-12-31', 242]]);

    $vendita = ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 100);
    $frasi = implode("\n", ruAnteprima($this, $s, $vendita + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto'])['rate']['frasi']);
    umrvPassa($this, $s, $vendita);

    // 79562 × 184/242 = 60493,42 → Elsa 60493; 79562 × 58/242 = 19068,58 → Ugo 19069 (60493 + 19069 = 79562).
    expect(umrvNetti($s))->toBe(['Acquirente Elsa' => 60493, 'Ines Inquilina' => 10192, 'Luca Inquilino' => 30246, 'Venditore Ugo' => 19069])
        ->and($frasi)->not->toContain('finisce prima');
});

it('controllo — usufrutto estinto il 1/3, prima della generazione: la riga di ripiego dell\'usufruttuario (febbraio) e quella della nuda proprietaria tornata piena (1/3–31/12, 183 giorni in due tratti) hanno tratti diversi e si tolgono ancora l\'una dall\'altra; Bice vende a Elsa il 1/10, Elsa paga 92 dei 183 giorni di Bice', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $ugo = $s['v'];
    // Ugo usufruttuario (la riga dello scenario), Bice nuda proprietaria, Ines inquilina: tutti censiti dal 2019.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $bice = umrvPersona($s, 'Bice Nuda');
    umrvTitolare($s, $bice, 'nuda_proprietario', 100);
    $ines = umrvPersona($s, 'Ines Inquilina');
    umrvTitolare($s, $ines, 'inquilino', 100);
    $luca = umrvPersona($s, 'Luca Inquilino');
    // Ines esce il 1/2; l'usufrutto di Ugo si estingue il 1/3 e Bice torna piena; Luca è inquilino dal 1/5 al 31/8.
    umrvPassa($this, $s, umrvFineLocazione($s, $ines, '2026-02-01'));
    umrvPassa($this, $s, ruPassaggio('estinzione', umrvRiga($s, $ugo, 'usufruttuario'), null, '2026-03-01', 100));
    umrvPassa($this, $s, umrvInizioLocazione($luca, '2026-05-01'));
    umrvPassa($this, $s, umrvFineLocazione($s, $luca, '2026-09-01'));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Usufrutto estinto e locazione con due vuoti, letti', esercizio: $s['e']);
    ruEmetti($s, '2026-09-30');

    // Il riparto: Ines 1/1–31/1, 120000 × 31/365 = 10191,78; Ugo usufruttuario per ripiego in febbraio, 120000 × 28/365 =
    // 9205,48; Bice tornata piena per ripiego dal 1/3 al 30/4 (61) e dal 1/9 al 31/12 (122), 183 giorni sul tratto
    // congelato 1/3–31/12, 120000 × 183/365 = 60164,38; Luca 1/5–31/8, 120000 × 123/365 = 40438,36. I resti maggiori
    // (,78 ,48) danno 10192 e 9206; Bice 60164, Luca 40438. Totale 10192 + 9206 + 60164 + 40438 = 120000.
    expect(umrvRipiego($s))->toBe([
        ['Bice Nuda', 'proprietario', 60164, '2026-03-01', '2026-12-31', 183],
        ['Venditore Ugo', 'usufruttuario', 9206, '2026-02-01', '2026-02-28', 28],
    ]);

    umrvPassa($this, $s, ruPassaggio('vendita', umrvRiga($s, $bice, 'proprietario'), $s['a'], '2026-10-01', 100));

    // La riga di Bice si ricostruisce togliendo il tratto di Luca (e quello di Ugo, che non la tocca): 61 + 122 = 183 giorni.
    // Prima dell'atto 61 + 30 di settembre = 91, dopo 92 (1/10–31/12). 60164 × 92/183 = 30246,38 → Elsa 30246;
    // 60164 × 91/183 = 29917,62 → Bice 29918 (30246 + 29918 = 60164). Gli altri non cambiano.
    expect(umrvNetti($s))->toBe(['Acquirente Elsa' => 30246, 'Bice Nuda' => 29918, 'Ines Inquilina' => 10192, 'Luca Inquilino' => 40438, 'Venditore Ugo' => 9206]);
});
