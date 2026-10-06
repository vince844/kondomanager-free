<?php

/**
 * Fase 1-ter della 1.11.0-beta.41: nel conguaglio di un passaggio una riga di riparto passa solo se è **della quota che esce**.
 *
 * Fino alla .41 il conguaglio spostava tutte le righe di riparto di chi esce, anche quelle di un'altra quota che lui tiene o
 * che non gli è mai arrivata. Chi entrava pagava i giorni dell'unità intera invece di quelli della sua quota.
 *
 * Cosa copre, con le cifre fatte a mano:
 * - **l'unità mista S3** (Ugo proprietario pieno di metà e usufruttuario dell'altra, Bice nuda proprietaria della seconda):
 *   la costituzione dell'usufrutto e la vendita della metà piena non fanno passare l'usufrutto che Ugo tiene;
 *   l'estinzione dell'usufrutto non sposta la metà piena;
 * - **dal predecessore**: le righe di un ruolo che il predecessore non ha mai ceduto a chi esce non passano;
 * - **un ruolo già ceduto**: l'usufrutto estinto prima, con il piano non più ricalcolabile, non torna nella vendita dopo;
 * - **la vendita della nuda proprietà** dopo una costituzione con la legge e il piano ricalcolato: le righe che il motore dà
 *   al nudo proprietario passano a chi compra la nuda proprietà; le due vendite della nuda dopo una costituzione «come la
 *   voce»; l'estinzione di un usufrutto che non ha mai pagato niente;
 * - **la riga di ripiego**: la parte «Inquilino» di una voce, ricaduta sul proprietario dopo la fine della locazione, si
 *   divide sui suoi giorni e non trascina con sé la parte del «Proprietario»;
 * - le frasi: la voce che resta perché è di un'altra quota, la gestione senza conguaglio, le bozze che restano.
 *
 * Cosa NON copre: le griglie dei passaggi (`InvariantiPassaggiTest`, che con le righe ha l'oracolo della quota che esce), la
 * scelta sull'ordinaria e le voci da spostare (`OrdinariaDopoAttoTest`).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
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
function qcePersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . "{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'QCEPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità dal 2019 (come i titolari «da sempre» degli scenari). */
function qceTitolare(array $s, Anagrafica $p, string $tipologia, float $quota): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $tipologia, 'quota' => $quota,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

function qceGenera(array $s): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
}

/**
 * L'unità S3: Ugo (`v`) proprietario pieno al 50 % e usufruttuario al 50 %, Bice nuda proprietaria al 50 %, dal 2019. Voce
 * ordinaria € 1.200,00 sul ruolo dato, piano di 12 rate generato a gennaio, emesse le tre fino al 31/3.
 */
function qceS3(string $soggetto): array
{
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    qceTitolare($s, $s['v'], 'usufruttuario', 50);
    $s['bice'] = qcePersona($s, 'Nuda Bice');
    qceTitolare($s, $s['bice'], 'nuda_proprietario', 50);
    qceGenera($s);
    ruEmetti($s, '2026-03-31');

    return $s;
}

/** La riga aperta di una persona con un ruolo, il giorno dato. */
function qceRiga(array $s, Anagrafica $p, string $tipologia, string $il): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $p->id)->where('tipologia', $tipologia)
        ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $il))->orderByDesc('id')->value('id');
}

/**
 * Un passaggio come lo fa il modulo: l'anteprima, poi la registrazione con la spunta del cancello e l'impronta delle voci.
 * Restituisce l'anteprima. Con `$registra` falso, solo l'anteprima.
 */
function qcePassa($test, array $s, string $tipo, ?Anagrafica $esce, ?Anagrafica $entra, string $il, array $extra = [], bool $registra = true): array
{
    $ruolo = match ($tipo) {
        'fine_locazione' => 'inquilino',
        'inizio_locazione' => null,
        default => ruRuoloUscente($tipo),
    };
    $riga = $ruolo !== null ? qceRiga($s, $esce, $ruolo, $il) : null;
    $quota = $riga !== null ? (float) DB::table('anagrafica_immobile')->where('id', $riga)->value('quota') : 100.0;
    $dati = match ($tipo) {
        'inizio_locazione' => ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'regime_contratto' => 'abitativo', 'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [], 'anagrafica_entrante_id' => $entra->id],
        'fine_locazione' => ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => []],
        default => ruPassaggio($tipo, $riga, $entra, $il, $quota),
    };
    $dati = array_merge($dati, ['ho_letto' => true, 'nota_cancello' => 'Atto letto'], $extra);
    $anteprima = ruAnteprima($test, $s, $dati);
    if (! $registra) {
        return $anteprima;
    }
    if (($anteprima['ordinaria']['impronta'] ?? null) !== null) {
        $dati['ordinaria_impronta'] = $anteprima['ordinaria']['impronta'];
    }
    ruRegistra($test, $s, $dati);

    return $anteprima;
}

/**
 * Il conguaglio dell'anteprima è fermo con la ragione data (`più strade` per le catene, `dopo la generazione` per i piani
 * senza righe), e non propone cifre.
 */
function qceFermo(array $anteprima, string $ragione): bool
{
    $c = $anteprima['rate']['conguaglio'] ?? [];
    $testo = ['più strade' => 'sono passate per più strade', 'dopo la generazione' => 'è cambiata con un passaggio dopo la generazione'][$ragione];

    return collect($c['non_risolte'] ?? [])->contains(fn ($n) => str_contains((string) $n['motivo'], $testo))
        && collect($c['coppie'] ?? [])->every(fn ($x) => (int) $x['importo'] === 0);
}

/**
 * Il registro di un passaggio come lo scrivevano le versioni precedenti (seconda revisione della Fase 1-ter, M2-3):
 * `nuovo` com'è, `beta36` senza registro, `beta37` con le righe ma senza il sottotipo.
 */
function qceInvecchia(Subentro $subentro, string $forma): void
{
    $registro = $subentro->registro ?? [];
    match ($forma) {
        'beta36' => DB::table('subentri')->where('id', $subentro->id)->update(['registro' => null]),
        'beta37' => DB::table('subentri')->where('id', $subentro->id)->update(['registro' => json_encode(array_diff_key($registro, ['sottotipo' => true]))]),
        default => null,
    };
}

/** Quanto paga ogni persona per la gestione sull'unità: le quote del piano più le coppie dei passaggi. @return array<int, int> */
function qceNetti(array $s): array
{
    $netti = ruPerPersona($s['piano']);
    foreach (Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $x) {
        $netti[(int) $x->anagrafica_id] = ($netti[(int) $x->anagrafica_id] ?? 0) + (int) $x->saldo_iniziale;
    }

    $netti = array_filter($netti, fn ($n) => $n !== 0);
    ksort($netti);

    return $netti;
}

/** Le frasi del conguaglio di un'anteprima, in un testo solo. */
function qceFrasi(array $anteprima): string
{
    $frasi = [];
    array_walk_recursive($anteprima, function () {});
    $cerca = function ($x) use (&$cerca, &$frasi) {
        if (! is_array($x)) {
            return;
        }
        if (isset($x['conguaglio']['frasi'])) {
            $frasi = $x['conguaglio']['frasi'];

            return;
        }
        foreach ($x as $v) {
            $cerca($v);
        }
    };
    $cerca($anteprima);

    return implode("\n", $frasi);
}

// --- L'unità S3 -----------------------------------------------------------------------------------------------------------

it('S3, costituzione — Ugo costituisce il 1/4 l\'usufrutto della sua metà piena a Elsa: a Elsa i giorni della sola metà (€ 452,05), non quelli dell\'unità intera; l\'usufrutto dell\'altra metà resta a Ugo, e la frase lo dice', function (string $scelta) {
    $s = qceS3('usufruttuario');
    $anteprima = qcePassa($this, $s, 'costituzione', $s['v'], $s['a'], '2026-04-01', ['ordinaria_dopo_atto' => $scelta]);

    // Il riparto: due righe di Ugo, € 600,00 da usufruttuario e € 600,00 da proprietario pieno (gemelli nel godimento). Dal
    // 1/4 Elsa ha l'usufrutto della metà piena: 60000 × 275/365 = 45205,48 → 45205. L'altra metà è di Ugo tutto l'anno.
    expect(qceNetti($s))->toBe([$s['v']->id => 74795, $s['a']->id => 45205])
        ->and(qceFrasi($anteprima))->toContain('resta a Venditore Ugo: è della parte dell\'unità che Venditore Ugo tiene (l\'usufrutto), e non passa.');
})->with(['con la legge' => ['usufruttuario'], 'come dice la voce' => ['voce']]);

it('S3, vendita — Ugo vende la metà piena a Elsa il 1/4: le bozze restano a Ugo, perché ogni rata porta anche il suo usufrutto, e la coppia dà a Elsa la sola metà (€ 452,05)', function () {
    $s = qceS3('usufruttuario');
    $anteprima = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-04-01');

    // Elsa compra la metà piena: per l'«Usufruttuario» le spetta il godimento di quella metà dal 1/4, 45205. Ugo resta
    // usufruttuario dell'altra metà: 60000, più i 90 giorni della metà piena (14795).
    expect(qceNetti($s))->toBe([$s['v']->id => 74795, $s['a']->id => 45205])
        ->and(DB::table('rate_quote')->where('anagrafica_id', $s['a']->id)->count())->toBe(0)
        ->and(qceFrasi($anteprima))
        ->toContain('ogni quota comprende anche la spesa della parte dell\'unità che Venditore Ugo tiene, e la quota non passa intera, quindi resteranno intestate a Venditore Ugo e la parte che passa si conguaglia qui.')
        ->toContain('90 giorni a Venditore Ugo, 275 a Acquirente Elsa → € 452,05 a chi entra.');
});

it('vendita della nuda su un\'unità mista — Ugo proprietario pieno di metà e nudo dell\'altra, Rita usufruttuaria; voce sull\'«Usufruttuario»; Ugo vende la nuda a Fede il 1/4: nessun conguaglio, e le bozze di Ugo restano sue perché sono della metà che tiene, non per l\'art. 1004', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    qceTitolare($s, $s['v'], 'nuda_proprietario', 50);
    $rita = qcePersona($s, 'Usufruttuaria Rita');
    qceTitolare($s, $rita, 'usufruttuario', 50);
    $fede = qcePersona($s, 'Compratrice Fede');
    qceGenera($s);
    ruEmetti($s, '2026-03-31');
    $anteprima = qcePassa($this, $s, 'nuda', $s['v'], $fede, '2026-04-01');

    // A mano: la voce sull'«Usufruttuario» va a chi gode. Ugo gode della sua metà piena (€ 600,00), Rita dell'altra
    // (€ 600,00); la nuda proprietà non paga niente, e a Fede non passa niente.
    // Rilievo T-B3 della revisione della 1-ter: le bozze di Ugo hanno il motivo della metà che tiene, non quello dell'art. 1004.
    expect(qceNetti($s))->toBe([$s['v']->id => 60000, $rita->id => 60000])
        ->and(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->where('intestatario', 'Venditore Ugo')->pluck('motivo')->unique()->values()->all())->toBe(['altra_quota'])
        ->and(qceFrasi($anteprima))
        ->toContain('resteranno intestate a Venditore Ugo: sono della parte dell\'unità che Venditore Ugo tiene, e non passano.')
        ->not->toContain('1004');
});

it('S3, estinzione con la voce sul «Proprietario» — si estingue il 1/4 l\'usufrutto di Ugo: la metà piena di Ugo non c\'entra, nessun conguaglio, e la frase dice perché', function () {
    $s = qceS3('proprietario');
    $rigaUsufrutto = qceRiga($s, $s['v'], 'usufruttuario', '2026-04-01');
    $anteprima = ruAnteprima($this, $s, ruPassaggio('estinzione', $rigaUsufrutto, null, '2026-04-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']);
    ruRegistra($this, $s, ruPassaggio('estinzione', $rigaUsufrutto, null, '2026-04-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']);

    // Il riparto: Ugo € 600,00 da proprietario pieno della sua metà, Bice € 600,00 da nuda proprietaria dell'altra (il capitale).
    // L'estinzione rende Bice piena della sua metà, che già pagava: con la voce sul «Proprietario» non cambia niente.
    expect(qceNetti($s))->toBe([$s['v']->id => 60000, $s['bice']->id => 60000])
        ->and(qceFrasi($anteprima))
        ->toContain('Sulla gestione Ordinaria 2026: nessun conguaglio — le quote emesse a Venditore Ugo (€ 600,00) sono della parte dell\'unità che Venditore Ugo tiene (la piena proprietà): non passano a Nuda Bice.')
        ->toContain('Le 9 quote del piano «Preventivo 2026» non ancora emesse resteranno intestate a Venditore Ugo: sono della parte dell\'unità che Venditore Ugo tiene, e non passano.');
});

it('S3, un ruolo già ceduto — l\'usufrutto di Ugo si estingue il 10/3 col piano già a giornale, poi Ugo vende la metà piena il 17/4: l\'usufrutto, regolato con l\'estinzione, non passa a chi compra, anche se l\'estinzione è stata registrata da una versione precedente', function (string $registro) {
    $s = qceS3('usufruttuario');
    $rigaUsufrutto = qceRiga($s, $s['v'], 'usufruttuario', '2026-03-10');
    qceInvecchia(ruRegistra($this, $s, ruPassaggio('estinzione', $rigaUsufrutto, null, '2026-03-10', 50) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']), $registro);
    qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-04-17');

    // Le righe di Ugo restano congelate sull'anno intero (il piano ha rate emesse). Usufrutto: a Bice dal 10/3, 297 giorni,
    // 60000 × 297/365 = 48821,92 → 48822, con l'estinzione. Metà piena: a Elsa dal 17/4, 259 giorni, 60000 × 259/365 =
    // 42575,34 → 42575. Ugo il resto: 120000 − 48822 − 42575 = 28603. Prima Elsa riceveva anche l'usufrutto. Con il registro
    // delle versioni precedenti l'estinzione passava per una costituzione (M2-3), e l'usufrutto passava di nuovo.
    expect(qceNetti($s))->toBe([$s['v']->id => 28603, $s['a']->id => 42575, $s['bice']->id => 48822]);
})->with(['registro di oggi' => ['nuovo'], 'senza registro (fino alla beta.36)' => ['beta36'], 'registro senza sottotipo' => ['beta37']]);

it('S3, dal predecessore — l\'usufrutto di Ugo si estingue il 7/1, il piano si ricalcola, Bice vende la sua metà a Elsa il 25/3: Elsa paga la sola metà di Bice, e la quota emessa a Ugo resta fuori', function (string $soggetto, array $netti) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaUsufrutto = qceTitolare($s, $s['v'], 'usufruttuario', 50);
    $s['bice'] = qcePersona($s, 'Nuda Bice');
    qceTitolare($s, $s['bice'], 'nuda_proprietario', 50);
    qceGenera($s);
    ruRegistra($this, $s, ruPassaggio('estinzione', $rigaUsufrutto, null, '2026-01-07', 50) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']);
    ruRicalcola($s);
    ruEmetti($s, '2026-02-01');
    $anteprima = qcePassa($this, $s, 'vendita', $s['bice'], $s['a'], '2026-03-25');

    // Sul «Proprietario», dopo il ricalcolo: Ugo 60000 (la sua metà piena), Bice 60000 (la sua metà, nuda fino al 6/1 e
    // piena dopo). Elsa compra la metà di Bice: 60000 × 282/365 = 46356,16 → 46356; Bice 13644. La metà di Ugo non c'entra.
    // Sull'«Usufruttuario» Ugo ha in più l'usufrutto dei primi 6 giorni, 60000 × 6/365 = 986, e Bice 59014: la riga di Ugo
    // finisce prima dell'atto e non passa niente (rilievo T-B4: non fa più passare le sue quote per «toccate»).
    expect(qceNetti($s))->toBe([$s['v']->id => $netti[0], $s['a']->id => 46356, $s['bice']->id => $netti[1]]);
    // Le quote di Ugo, emesse e in bozza, sono della sua metà, che a Bice non è mai passata: il passaggio non le tocca.
    expect(qceFrasi($anteprima))->not->toContain('a Acquirente Elsa → € 0,00')
        ->toContain('Le 11 quote del piano «Preventivo 2026» non ancora emesse resteranno intestate a Venditore Ugo: sono di una parte dell\'unità che non è mai passata a Nuda Bice, e non passano.')
        ->and(implode(' | ', $anteprima['cancello']['informazioni'] ?? []))->toContain('1 quota di rata già emessa a Venditore Ugo: resta sua, questo passaggio non la tocca')
        ->and(implode(' | ', $anteprima['cancello']['motivi'] ?? []))->not->toContain('la parte che ne resta passa ancora');
})->with([
    'voce sul «Proprietario»' => ['proprietario', [60000, 13644]],
    'voce sull\'«Usufruttuario»' => ['usufruttuario', [60986, 12658]],
]);

it('S3, vendita su un piano senza righe di riparto (generato prima della beta.29) — la parte dell\'usufrutto si legge dalla ricostruzione del motore e non passa; la frase dice quanta parte si divide', function () {
    $s = qceS3('usufruttuario');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $anteprima = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-04-01');

    // Come con le righe: a Elsa la metà piena dal 1/4, 60000 × 275/365 = 45205.
    expect(qceNetti($s))->toBe([$s['v']->id => 74795, $s['a']->id => 45205])
        ->and(qceFrasi($anteprima))->toContain('Si divide solo la parte della quota che passa, € 600,00 su € 1.200,00; il resto è della parte dell\'unità che Venditore Ugo tiene (l\'usufrutto), e non passa a Acquirente Elsa.');
});

it('il nudo proprietario tornato pieno costituisce l\'usufrutto — Rita usufruttuaria, Ugo nudo; l\'usufrutto si estingue il 1/5 col piano già emesso, Ugo costituisce l\'usufrutto a Carlo il 1/7 con la legge: le righe di Ugo da nudo passano a Carlo per i giorni (€ 604,93)', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rita = qcePersona($s, 'Usufruttuaria Rita');
    $rigaRita = qceTitolare($s, $rita, 'usufruttuario', 100);
    $carlo = qcePersona($s, 'Usufruttuario Carlo');
    qceGenera($s);
    ruEmetti($s, '2026-04-30');
    ruRegistra($this, $s, ruPassaggio('estinzione', $rigaRita, null, '2026-05-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']);
    qcePassa($this, $s, 'costituzione', $s['v'], $carlo, '2026-07-01');

    // La voce sul «Proprietario» con l'usufrutto di Rita scende al nudo: il piano, congelato, dà tutto l'anno a Ugo da nudo
    // proprietario. Con la legge l'ordinaria è di Carlo dal 1/7: 120000 × 184/365 = 60493,15 → 60493. Quelle righe vengono da
    // quando Ugo era nudo, e sono la quota che esce: non «la nuda proprietà che la costituzione gli lascia».
    expect(qceNetti($s))->toBe([$s['v']->id => 59507, $carlo->id => 60493]);
});

// --- La vendita della nuda proprietà ---------------------------------------------------------------------------------------

it('nuda proprietà dopo una costituzione con la legge e il piano ricalcolato — le righe che il motore dà a Ugo nudo proprietario passano a chi compra la nuda proprietà (€ 805,48); quella di gennaio, quando Ugo era pieno, resta sua', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    qceGenera($s);
    $carlo = qcePersona($s, 'Usufruttuario Carlo');
    $fede = qcePersona($s, 'Compratrice Fede');
    qcePassa($this, $s, 'costituzione', $s['v'], $carlo, '2026-02-01');
    ruRicalcola($s);
    ruEmetti($s, '2026-04-30');
    $anteprima = qcePassa($this, $s, 'nuda', $s['v'], $fede, '2026-05-01');

    // La voce sul «Proprietario» è bloccata dal piano approvato (31.8 e 31.9): ricalcolato, il piano dà a Ugo gennaio da
    // proprietario (€ 101,92) e il resto da nudo proprietario (€ 1.098,08). La nuda proprietà passa a Fede dal 1/5: 120000 ×
    // 245/365 = 80547,95 → 80548. Prima nulla passava a Fede, e Ugo pagava 245 giorni senza titolo.
    expect(qceNetti($s))->toBe([$s['v']->id => 39452, $fede->id => 80548])
        ->and(qceFrasi($anteprima))->toContain('Spese generali (per la piena proprietà): € 101,92, competenza 1 gennaio 2026–31 gennaio 2026 — resta a Venditore Ugo, la competenza finisce prima del 1 maggio 2026.')
        // Seconda revisione della Fase 1-ter (B9): le due righe della stessa voce dicono di quale parte dell'unità sono.
        ->toContain('Spese generali (per la nuda proprietà): € 1.098,08');
});

it('nuda proprietà, due vendite dopo una costituzione «come la voce» — Rita vende la nuda a Dora il 24/8, Dora la rivende a Fede il 7/9: Dora paga i suoi 14 giorni, non 130', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    qceGenera($s);
    ruEmetti($s, '2026-05-05');
    $carlo = qcePersona($s, 'Usufruttuario Carlo');
    $dora = qcePersona($s, 'Nuda Dora');
    $fede = qcePersona($s, 'Nuda Fede');
    qcePassa($this, $s, 'costituzione', $s['v'], $carlo, '2026-07-16', ['ordinaria_dopo_atto' => 'voce']);
    qcePassa($this, $s, 'nuda', $s['v'], $dora, '2026-08-24');
    qcePassa($this, $s, 'nuda', $dora, $fede, '2026-09-07');

    // La voce sul «Proprietario» resta al nudo proprietario (la scelta). Dora è nuda dal 24/8 al 6/9: 14 giorni, 120000 × 14/365
    // = 4602,74 → 4603; Fede dal 7/9: 116 giorni, 38136,99 → 38137. Prima Dora pagava 130 giorni e a Fede non passava niente.
    $netti = qceNetti($s);
    expect([$netti[$dora->id] ?? 0, $netti[$fede->id] ?? 0])->toBe([4603, 38137]);
});

it('estinzione di un usufrutto che non ha mai pagato niente — Luca e Mara al 50 %, Luca costituisce l\'usufrutto a Elsa il 31/1 con la legge (voce bloccata, piano ricalcolato), l\'usufrutto si estingue l\'11/3: nessun conguaglio', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $mara = qcePersona($s, 'Comproprietaria Mara');
    qceTitolare($s, $mara, 'proprietario', 50);
    qceGenera($s);
    qcePassa($this, $s, 'costituzione', $s['v'], $s['a'], '2026-01-31');
    ruRicalcola($s);
    ruEmetti($s, '2026-02-16');
    qcePassa($this, $s, 'estinzione', $s['a'], null, '2026-03-11');

    // Con la voce sul «Proprietario» bloccata, il piano ricalcolato dà la metà di Luca a lui, da pieno e poi da nudo: Elsa non
    // ha quote, e non c'è niente da conguagliare. Prima Elsa riceveva un credito di € 486,57.
    expect(qceNetti($s))->toBe([$s['v']->id => 60000, $mara->id => 60000]);
});

// --- La riga di ripiego -----------------------------------------------------------------------------------------------------

it('la riga di ripiego — voce 30 % «Inquilino» e 70 % «Proprietario»; Dora inquilina dal 26/3, Ines compra da Ugo il 18/5, la locazione finisce il 7/6, Ines vende a Zeta l\'8/8: a Zeta i 146 giorni delle due parti (€ 480,00)', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $ctm = (int) DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
        ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conto_tabella_millesimale.id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 70]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 30, 'created_at' => now(), 'updated_at' => now()]);
    qceGenera($s);
    $dora = qcePersona($s, 'Inquilina Dora');
    $ines = qcePersona($s, 'Compratrice Ines');
    $zeta = qcePersona($s, 'Compratrice Zeta');
    qcePassa($this, $s, 'inizio_locazione', null, $dora, '2026-03-26');
    qcePassa($this, $s, 'vendita', $s['v'], $ines, '2026-05-18');
    qcePassa($this, $s, 'fine_locazione', $dora, null, '2026-06-07');
    ruRicalcola($s);
    ruEmetti($s, '2026-06-23');
    qcePassa($this, $s, 'vendita', $ines, $zeta, '2026-08-08');

    // Zeta dall'8/8: 146 giorni. «Proprietario» 84000 × 146/365 = 33600; «Inquilino», senza inquilino dal 7/6 e quindi sul
    // proprietario, 36000 × 146/365 = 14400. Totale 48000. Le due righe di Ines (la parte del «Proprietario» dal 18/5, 228
    // giorni, 52471; quella dell'«Inquilino» ricaduta su di lei dal 7/6, 208 giorni, 20515) si dividono ognuna sui suoi
    // giorni: prima tutta la quota andava sui 208 giorni della seconda, 72986 × 146/208, e Zeta pagava € 512,30.
    expect(qceNetti($s)[$zeta->id] ?? 0)->toBe(48000);
});

// --- L'estinzione con più nudi proprietari ---------------------------------------------------------------------------------

it('estinzione con due nudi proprietari, poi uno dei due vende — Rita usufruttuaria dell\'intera unità, Nora e Bice nude al 50 %; l\'usufrutto si estingue il 1/4 col piano già a giornale, una delle due vende a Carlo il 1/6: Carlo paga il godimento della sola metà (€ 351,78), chiunque venda', function (string $chiVende, array $attesi, string $registro) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $nora = qcePersona($s, 'Nuda Nora');
    $bice = qcePersona($s, 'Nuda Bice');
    qceTitolare($s, $nora, 'nuda_proprietario', 50);
    qceTitolare($s, $bice, 'nuda_proprietario', 50);
    qceGenera($s);
    ruEmetti($s, '2026-03-31');
    qceInvecchia(ruRegistra($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Atto letto']), $registro);
    $anteprima = qcePassa($this, $s, 'vendita', $chiVende === 'nora' ? $nora : $bice, $s['a'], '2026-06-01');

    // Rita paga il godimento fino al 31/3: 120000 × 90/365 = 29589. L'estinzione dà a ciascuna nuda la sua metà dal 1/4
    // (275 giorni, 45205 e 45206 al centesimo). La vendita del 1/6 passa a Carlo il godimento della sola metà venduta:
    // 60000 × 214/365 = 35178,08 → 35178. Prima l'estinzione si registrava come passata alla prima nuda soltanto: se vendeva
    // lei, Carlo pagava il godimento dell'unità intera (€ 703,56); se vendeva l'altra, niente. Lo stesso con il registro delle
    // versioni precedenti (M2-3): i nudi si ricostruiscono dalle righe chiuse e riaperte il giorno dell'atto.
    $netti = qceNetti($s);
    expect([$netti[$s['v']->id] ?? 0, $netti[$nora->id] ?? 0, $netti[$bice->id] ?? 0, $netti[$s['a']->id] ?? 0])->toBe($attesi)
        // Rilievo T-B8: la parte delle righe di Rita che non passa non è «l'usufrutto» (lo è anche quella che passa), ma la
        // metà che l'estinzione ha dato all'altra nuda.
        ->and(qceFrasi($anteprima))->toContain('la parte dell\'usufrutto che l\'estinzione ha riunito alle altre quote di nuda proprietà')->not->toContain('(l\'usufrutto)');
})->with([
    'vende la nuda scritta per prima' => ['nora', [29589, 10028, 45205, 35178]],
    'vende l\'altra' => ['bice', [29589, 45206, 10027, 35178]],
])->with(['registro di oggi' => ['nuovo'], 'senza registro (fino alla beta.36)' => ['beta36'], 'registro senza sottotipo' => ['beta37']]);

// --- Le catene in cui la quota è passata per più strade ------------------------------------------------------------------
//
// Quando una persona ha avuto più quote sulla stessa unità, per più strade, il ruolo della riga di riparto non dice di quale
// quota è. Nella .41 e nella .42 il conguaglio si fermava e lo diceva; dalla 1.11.0-beta.43 ogni riga di riparto sa da quale
// riga di titolarità viene, e il conguaglio la segue nei passaggi (decisione 56): ognuna delle sette forme, che prima si
// fermava, ha le cifre fatte a mano. Le stesse forme, con i due versi della scelta sull'ordinaria, sono in
// `CateneDelConguaglioTest`; si ferma ancora il piano senza righe di riparto (qui sotto).

it('catena — il predecessore aveva ceduto un ruolo per un\'altra strada: Ugo vende la metà piena a Elsa, il suo usufrutto si estingue, Bice vende a Carlo: a Carlo la sola metà dell\'usufrutto (€ 200,55)', function () {
    $s = qceS3('usufruttuario');
    $carlo = qcePersona($s, 'Compratore Carlo');
    qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-04-01');
    qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-06-01');
    $anteprima = qcePassa($this, $s, 'vendita', $s['bice'], $carlo, '2026-09-01');

    // A mano: la metà piena va a Elsa il 1/4 (60000 × 275/365 = 45205); l'usufrutto a Bice il 1/6 (214 giorni, 35178) e a Carlo
    // il 1/9: 60000 × 122/365 = € 200,55. Ugo 120000 − 45205 − 35178 = 39617, Bice 35178 − 20055 = 15123. Nella .41 e nella .42
    // il conguaglio si fermava; prima ancora Carlo riceveva anche la metà piena, già di Elsa (€ 401,10).
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe([$s['v']->id => 39617, $s['a']->id => 45205, $s['bice']->id => 15123, $carlo->id => 20055]);
});

it('catena — si estingue l\'usufrutto costituito da un nudo tornato pieno, e lo stesso nudo vende la nuda: all\'estinzione la voce torna a Ugo, alla vendita della nuda a Fede non passa niente', function (string $dopo) {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rita = qcePersona($s, 'Usufruttuaria Rita');
    qceTitolare($s, $rita, 'usufruttuario', 100);
    $carlo = qcePersona($s, 'Usufruttuario Carlo');
    qceGenera($s);
    ruEmetti($s, '2026-04-30');
    qcePassa($this, $s, 'estinzione', $rita, null, '2026-05-01');
    qcePassa($this, $s, 'costituzione', $s['v'], $carlo, '2026-07-01');

    // A mano: con la legge la voce è di Carlo dal 1/7, 184 giorni, 60493. All'estinzione del 1/10 torna a Ugo per 92 giorni:
    // 120000 × 92/365 = € 302,47 (Ugo 89754, Carlo 30246). Alla vendita della nuda del 1/9 a Fede non passa niente (Ugo 59507,
    // Carlo 60493). Nella .41 e nella .42 il conguaglio si fermava in tutti e due; prima ancora all'estinzione nessun
    // conguaglio, e alla vendita della nuda Fede € 401,10.
    $fede = qcePersona($s, 'Compratrice Fede');
    $anteprima = $dopo === 'estinzione'
        ? qcePassa($this, $s, 'estinzione', $carlo, null, '2026-10-01')
        : qcePassa($this, $s, 'nuda', $s['v'], $fede, '2026-09-01');
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe($dopo === 'estinzione' ? [$s['v']->id => 89754, $carlo->id => 30246] : [$s['v']->id => 59507, $carlo->id => 60493]);
})->with(['estinzione', 'vendita della nuda']);

it('catena — due nudi, uno compra la metà dell\'altro e rivende tutto: a Carlo le due metà (€ 401,10)', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $nora = qcePersona($s, 'Nuda Nora');
    $bice = qcePersona($s, 'Nuda Bice');
    qceTitolare($s, $nora, 'nuda_proprietario', 50);
    qceTitolare($s, $bice, 'nuda_proprietario', 50);
    qceGenera($s);
    ruEmetti($s, '2026-03-31');
    qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-04-01');
    qcePassa($this, $s, 'vendita', $bice, $nora, '2026-06-01');
    $carlo = qcePersona($s, 'Compratore Carlo');
    $anteprima = qcePassa($this, $s, 'vendita', $nora, $carlo, '2026-09-01');

    // A mano: l'estinzione dà a ciascuna nuda la sua metà dal 1/4 (45206 a Nora, scritta per prima, e 45205 a Bice); il 1/6 Nora
    // compra la metà di Bice (214 giorni, 35178); il 1/9 Nora vende tutto: 120000 × 122/365 = € 401,10. Ugo 29589, Nora 45206 +
    // 35178 − 40110 = 40274, Bice 10027. Nella .41 e nella .42 il conguaglio si fermava; prima ancora Carlo pagava una metà sola.
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe([$s['v']->id => 29589, $nora->id => 40274, $bice->id => 10027, $carlo->id => 40110]);
});

it('catena — un ruolo ceduto che torna a chi esce: l\'usufrutto di Ugo si estingue, Ugo ricompra da Bice e rivende: a Elsa le due metà (€ 401,10)', function () {
    $s = qceS3('usufruttuario');
    qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-04-01');
    qcePassa($this, $s, 'vendita', $s['bice'], $s['v'], '2026-05-01');
    $anteprima = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-09-01');

    // A mano: l'usufrutto va a Bice il 1/4 (45205) e torna a Ugo il 1/5 (245 giorni, 40274); il 1/9 Ugo vende tutto: 120000 ×
    // 122/365 = € 401,10, di cui € 400,00 con le quattro bozze dal 5/9. Ugo 120000 − 45205 + 40274 − 40110 = 74959, Bice 4931.
    // Nella .41 e nella .42 il conguaglio si fermava; prima ancora Elsa pagava la sola metà piena (€ 200,55).
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe([$s['v']->id => 74959, $s['a']->id => 40110, $s['bice']->id => 4931]);
});

it('catena — riserva d\'usufrutto sull\'unità mista, poi l\'usufrutto si chiude con due nudi: all\'estinzione la riga di Ugo torna a Nora, alla vendita dopo Carlo paga la metà di Bice', function () {
    $s = qceS3('proprietario');
    $nora = qcePersona($s, 'Nuda Nora');
    qcePassa($this, $s, 'riserva', $s['v'], $nora, '2026-04-01');
    $estinzione = qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-09-01');
    $netti = qceNetti($s);
    $carlo = qcePersona($s, 'Compratore Carlo');
    $vendita = qcePassa($this, $s, 'vendita', $s['bice'], $carlo, '2026-10-01');

    // A mano, all'estinzione: la riga di Ugo (la metà piena, € 600,00) è passata con la riserva al suo usufrutto, legata alla nuda
    // di Nora: torna a Nora per 122 giorni, 60000 × 122/365 = € 200,55; la riga di Bice era già sua. Alla vendita di Bice: 92
    // giorni della sua metà, 60000 × 92/365 = € 151,23 (€ 150,00 con le tre bozze dal 5/10 e € 1,23 con la coppia). Nella .41 e
    // nella .42 il conguaglio si fermava; prima ancora divideva per quota (€ 100,27 a Nora e € 100,28 a Bice) e poi € 302,46.
    expect(qceFermo($estinzione, 'più strade'))->toBeFalse()
        ->and(qceFermo($vendita, 'più strade'))->toBeFalse()
        ->and($netti)->toBe([$s['v']->id => 39945, $s['bice']->id => 60000, $nora->id => 20055])
        ->and(qceNetti($s))->toBe([$s['v']->id => 39945, $s['bice']->id => 44877, $nora->id => 20055, $carlo->id => 15123]);
});

it('piano senza righe di riparto (generato prima della beta.29) sull\'unità mista: il primo passaggio dopo la generazione divide sulla ricostruzione; il secondo si ferma, perché la ricostruzione non descrive più la quota emessa', function (string $primo) {
    $s = qceS3('usufruttuario');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    if ($primo === 'estinzione') {
        // A mano: l'estinzione dà a Bice 60000 × 275/365 = € 452,05; poi Elsa deve 60000 × 214/365 = € 351,78 (prima del fermo € 0,00).
        $prima = qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-04-01');
        $dopo = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-06-01', registra: false);
    } else {
        // A mano: la vendita dà a Elsa € 452,05; poi l'estinzione dà a Bice € 351,78 (prima del fermo € 703,56).
        $prima = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-04-01');
        $dopo = qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-06-01', registra: false);
    }
    expect(qceFermo($prima, 'dopo la generazione'))->toBeFalse()
        ->and(collect($prima['rate']['conguaglio']['coppie'])->sum('importo'))->toBe(45205)
        ->and(qceFermo($dopo, 'dopo la generazione'))->toBeTrue()
        // Il cancello chiede la spunta: la frase non dice che le quote «restano sue» (la parte dell'usufrutto estinto non lo è).
        ->and($dopo['cancello']['richiesto'] ?? false)->toBeTrue();
})->with(['estinzione', 'vendita']);

it('beta.44 — piano senza righe di riparto sull\'unità mista: la successione della nuda proprietà registrata dopo la generazione cambia la quota come una vendita, e il passaggio dopo si ferma', function () {
    $s = qceS3('usufruttuario');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $nino = qcePersona($s, 'Nino Erede');
    // Bice, nuda proprietaria della metà, muore il 1/4: la nuda passa a Nino.
    ruRegistra($this, $s, ['tipo' => 'successione', 'riga_uscente_id' => qceRiga($s, $s['bice'], 'nuda_proprietario', '2026-04-01'), 'decorrenza' => '2026-04-01', 'quota' => 50,
        'tipologia' => 'nuda_proprietario', 'eredi' => [['anagrafica_id' => $nino->id, 'quota' => 50]], 'arretrato' => 'eredi', 'copia_autentica' => false, 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    // Prima la successione non contava fra i passaggi dopo la generazione: la vendita di Ugo divideva sulla ricostruzione.
    $dopo = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-06-01', registra: false);
    expect(qceFermo($dopo, 'dopo la generazione'))->toBeTrue();
});

it('beta.44 — la catena con un erede che non è chi entra scritto sul passaggio: Bice vende la sua metà a Dino, poi eredita un quarto da Ugo insieme a Mara e lo vende a Elio. Con le righe di riparto la genealogia decide; senza, il conguaglio si ferma e lo dice', function (bool $senzaRighe) {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $bice = qcePersona($s, 'Bice Prima');
    qceTitolare($s, $bice, 'proprietario', 50);
    qceGenera($s);
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s, '2026-12-31');
    [$dino, $mara, $elio] = [qcePersona($s, 'Dino Compra'), qcePersona($s, 'Mara Erede'), qcePersona($s, 'Elio Compra')];
    qcePassa($this, $s, 'vendita', $bice, $dino, '2026-02-01');
    // Bice è la seconda erede: il passaggio nomina Mara come chi entra.
    ruRegistra($this, $s, ['tipo' => 'successione', 'riga_uscente_id' => qceRiga($s, $s['v'], 'proprietario', '2026-05-01'), 'decorrenza' => '2026-05-01', 'quota' => 50,
        'tipologia' => 'proprietario', 'eredi' => [['anagrafica_id' => $mara->id, 'quota' => 25], ['anagrafica_id' => $bice->id, 'quota' => 25]], 'arretrato' => 'eredi',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Successione letta']);
    $c = qcePassa($this, $s, 'vendita', $bice, $elio, '2026-09-01', registra: false)['rate']['conguaglio'];

    if (! $senzaRighe) {
        // A mano: il quarto ereditato, € 300,00 × 122/365 = € 100,27.
        expect(array_column($c['coppie'], 'importo'))->toBe([10027])->and($c['non_risolte'])->toBe([]);

        return;
    }
    // Prima l'acquisto per successione contava solo per chi entra scritto sul passaggio: senza righe il conguaglio dava a Elio i giorni di
    // tre quarti dell'unità (€ 300,82), anche della metà che Bice aveva venduto a Dino.
    expect(collect($c['coppie'])->every(fn ($x) => (int) $x['importo'] === 0))->toBeTrue()
        ->and(collect($c['non_risolte'])->pluck('motivo')->implode(' | '))->toContain('Bice Prima ha ceduto una quota dell\'unità con un passaggio e ne ha avuta un\'altra con un passaggio successivo');
})->with(['con le righe di riparto' => [false], 'senza, dalla ricostruzione del motore' => [true]]);

// --- Le quote che il passaggio tocca o non tocca: il cancello -------------------------------------------------------------

it('una voce interamente coperta dal già versato di chi esce sposta comunque la parte di chi entra: S3, «Acqua anticipata» sull\'«Usufruttuario» già versata da Ugo, l\'usufrutto si estingue il 1/4: coppia € 75,34 e la spunta è richiesta', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $pcId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('piani_conti.id');
    $acqua = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pcId, 'nome' => 'Acqua anticipata', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $acqua->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'usufruttuario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    qceTitolare($s, $s['v'], 'usufruttuario', 50);
    $s['bice'] = qcePersona($s, 'Nuda Bice');
    qceTitolare($s, $s['bice'], 'nuda_proprietario', 50);
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $acqua->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => $s['v']->id, 'importo_cents' => 20000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    qceGenera($s);
    ruEmetti($s, '2026-03-31');
    $anteprima = qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-04-01');

    // A mano: la metà dell'acqua in usufrutto di Ugo (€ 100,00) passa a Bice per i giorni dal 1/4: 10000 × 275/365 = 7534,25
    // → € 75,34. Le spese generali sul «Proprietario» non c'entrano (la metà piena di Ugo resta sua). Netti: Ugo 60000 +
    // 20000 − 20000 versati − 7534 = 52466; Bice 60000 + 7534 = 67534. Il conto dell'acqua ha importo netto zero (il versato
    // lo copre), ma sposta denaro: prima la quota passava per «non toccata» e la spunta non si chiedeva.
    expect(qceNetti($s))->toBe([$s['v']->id => 52466, $s['bice']->id => 67534])
        ->and($anteprima['cancello']['richiesto'] ?? false)->toBeTrue()
        ->and(implode(' | ', $anteprima['cancello']['informazioni'] ?? []))->not->toContain('questo passaggio non le tocca');
});

// --- Seconda revisione della Fase 1-ter -----------------------------------------------------------------------------------

it('M2-1 — chi esce tiene una quota che gli è arrivata da un predecessore mentre ne cede un\'altra: passa solo la quota che esce', function (string $forma) {
    $s = ruScenario('prima_rata', 0, soggetto: $forma === 'A' ? 'proprietario' : 'usufruttuario', genera: false);
    if ($forma === 'A') {
        // Ugo pieno 50; Rita usufruttuaria e Bice nuda dell'altra metà. Il 1/3 Bice vende la nuda a Ugo; il 1/6 Ugo vende la
        // sua metà piena a Elsa.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
        qceTitolare($s, qcePersona($s, 'Usufruttuaria Rita'), 'usufruttuario', 50);
        $bice = qcePersona($s, 'Nuda Bice');
        qceTitolare($s, $bice, 'nuda_proprietario', 50);
        qceGenera($s);
        ruEmetti($s, '2026-02-28');
        qcePassa($this, $s, 'nuda', $bice, $s['v'], '2026-03-01');
        $anteprima = qcePassa($this, $s, 'vendita', $s['v'], $s['a'], '2026-06-01');
        $chiEntra = $s['a'];
    } else {
        // Ugo usufruttuario di metà (Nora nuda), Bice piena dell'altra. Il 1/3 Bice vende a Ugo; il 1/6 si estingue l'usufrutto
        // di Ugo.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
        $chiEntra = qcePersona($s, 'Nuda Nora');
        qceTitolare($s, $chiEntra, 'nuda_proprietario', 50);
        $bice = qcePersona($s, 'Piena Bice');
        qceTitolare($s, $bice, 'proprietario', 50);
        qceGenera($s);
        ruEmetti($s, '2026-02-28');
        qcePassa($this, $s, 'vendita', $bice, $s['v'], '2026-03-01');
        $anteprima = qcePassa($this, $s, 'estinzione', $s['v'], null, '2026-06-01');
    }

    // A mano, in tutte e due le forme: la metà di Bice passa a Ugo il 1/3 (306 giorni, 60000 × 306/365 = 50301,37 → 50301, di cui
    // € 500,00 con le dieci bozze di Bice e € 3,01 con la coppia); Bice 60000 − 50000 − 301 = 9699. Il 1/6 Ugo cede la sua metà:
    // a Elsa (A) o a Nora (B) 214 giorni, 60000 × 214/365 = € 351,78; la metà comprata da Bice resta a Ugo. Ugo 60000 + 50000 +
    // 301 − 35178 = 75123. Nella .41 e nella .42 il conguaglio si fermava; senza fermata prendeva anche la metà di Bice (€ 703,56).
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe($forma === 'A' ? [$s['v']->id => 75123, $s['a']->id => 35178, $bice->id => 9699] : [$s['v']->id => 75123, $chiEntra->id => 35178, $bice->id => 9699]);
})->with(['vende la metà piena tenendo la nuda comprata' => ['A'], 'estinzione tenendo la piena comprata' => ['B']]);

it('M2-2 — costituzione, fine dello stesso usufrutto, poi vendita: la piena proprietà tornata è la stessa quota, e il conguaglio non si ferma', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $carlo = qcePersona($s, 'Usufruttuario Carlo');
    $zeta = qcePersona($s, 'Compratore Zeta');
    qceGenera($s);
    ruEmetti($s, '2026-02-28');
    qcePassa($this, $s, 'costituzione', $s['v'], $carlo, '2026-03-01');
    qcePassa($this, $s, 'estinzione', $carlo, null, '2026-06-01');
    $anteprima = qcePassa($this, $s, 'vendita', $s['v'], $zeta, '2026-09-01');

    // A mano: Zeta dal 1/9, 122 giorni: 120000 × 122/365 = 40109,59 → 40110; Carlo 92 giorni: 30246,58 → 30247; Ugo il
    // resto, 49643. Prima la catena si fermava («lo stesso ruolo in due periodi separati») e servivano un saldo a mano e le
    // bozze da settembre.
    expect(qceFermo($anteprima, 'più strade'))->toBeFalse()
        ->and(qceNetti($s))->toBe([$s['v']->id => 49643, $carlo->id => 30247, $zeta->id => 40110]);
});
