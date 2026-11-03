<?php

/**
 * 1.11.0-beta.51 («passaggi e prospetto») — gli scenari condivisi dei test dei passaggi.
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie. Lo caricano con `require_once` i file
 * `Beta51Passaggi*Test.php`, così ognuno passa anche lanciato da solo. Le funzioni hanno il prefisso `b51a` (passaggi e prospetto):
 * i nomi delle funzioni di Pest sono globali, e i test del piano rate (`Beta51PianoRate*`) usano `b51p`.
 *
 * Si appoggia agli aiuti che la suite ha già (`fcl*`, `fds50*`, `pfl*`), non al laboratorio: `tests/Lab/` non è tracciato.
 *
 * LO SCENARIO DEL CASO DL7 (Coda 254): «Spese generali» € 1.264,75 (126475) sulla tabella «Proprietà»,
 * 30 % «Inquilino» e 70 % «Proprietario»; «Preventivo 2026» in 12 rate dal 5/1, una sola emessa (il 7/1); Ugo proprietario e Ines
 * inquilina dal 2019. Fine di Ines il 24/1 con «Scrivi» (coppia a Ugo); inizio di Luca l'8/2 al 100 %. Poi, secondo il caso: la
 * vendita di Ugo a Elsa il 12/3 e la fine di Luca il 18/4.
 *
 * I CONTI A MANO DELLO SCENARIO (il 2026 ha 365 giorni; tutto in centesimi):
 * - parte annua dell'inquilino: 126475 × 30 % = 37942,50;
 * - coppia della fine di Ines (342 giorni, 24/1–31/12): 37942,50 × 342/365 = 35551,60 → 35552 (Ugo +35552, Ines −35552);
 * - giorni: Luca dall'8/2 = 327 (8/2–31/12 = 21+31+30+31+30+31+31+30+31+30+31 = 327); scoperti 24/1–7/2 = 15 (Ugo); Luca fino al
 *   17/4 = 69; liberi dal 18/4 = 258 (18/4–31/12).
 */

use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/Support/ScenariProspettoLocazione.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';

/** L'amministratore che opera dalle rotte vere: lo chiamano i `beforeEach` dei file `Beta51Passaggi*Test.php`. */
function b51aAccesso($test): void
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $test->user = User::factory()->create();
    $test->user->assignRole($ruolo);
}

/**
 * Una voce in più sul piano, con la competenza data, sulla tabella «Proprietà» e con la ripartizione data (di partenza 30 %
 * inquilino, 70 % proprietario). `entrata` o `spesa`. Il piano deve avere già la voce «Spese generali» (importo 120000 se non si
 * dice diversamente con `$importoSpese`).
 */
function b51aCapitolo(array $s, string $nome, string $tipo, int $importo, ?string $dal, ?string $al, array $ripartizione = ['inquilino' => 30, 'proprietario' => 70], int $importoSpese = 120000): void
{
    $pc = PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => $importoSpese], $conto->id => ['importo' => $importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $conto->id)->value('id');
    if ($dal !== null) {
        CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
    }
}

/** Le persone che il caso userà, create una volta sola (Luca, Elsa, Marco, Carlo, Dora, Anna, Bruno). */
function b51aPersone(array &$s, array $nomi): void
{
    foreach ($nomi as $nome) {
        if (! isset($s['persone'][$nome])) {
            fclPersona($s, $nome);
        }
    }
}

/**
 * Lo scenario DL7 fino all'inizio di Luca (8/2) compreso: piano generato, una rata emessa il 7/1, fine di Ines il 24/1 con
 * «Scrivi» (coppia Ugo +35552 / Ines −35552: controllata), inizio di Luca l'8/2. Con `$opzioni`:
 *  - `titolari`: le righe del proprietario (di partenza Ugo al 100 %);
 *  - `importo`: la voce in centesimi (di partenza 126475);
 *  - `senzaLuca`: non registra l'inizio di Luca (per i casi del coinquilino e del rientro).
 *
 * @return array<string, mixed>
 */
function b51aDl7($test, array $opzioni = []): array
{
    $s = fclScenario($opzioni['titolari'] ?? [['Ugo', 'proprietario', 100]], ['importo' => $opzioni['importo'] ?? 126475] + ($opzioni['scenario'] ?? []));
    b51aPersone($s, ['Luca', 'Elsa', 'Marco', 'Carlo', 'Dora', 'Anna', 'Bruno']);
    fclGenera($s);
    fclEmetti($test, $s, '2026-01-07');
    expect(fclEmesse($s))->toBe(1);
    $s['fine_ines'] = fclRegistra($test, $s, fclModulo($s, null, '2026-01-24') + ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]);
    // 126475 × 30 % × 342/365 = 35551,60 → 35552 (vedi in testa): Ines −35552, e chi paga al posto suo (Ugo, o Ugo e Anna) +35552 in tutto.
    $coppia = fds50Coppia($s, $s['fine_ines']);
    expect($coppia['Ines'] ?? null)->toBe(-35552)
        ->and(array_sum(array_filter($coppia, fn (int $c) => $c > 0)))->toBe(35552);
    if (! ($opzioni['senzaLuca'] ?? false)) {
        $inizio = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-02-08'));
        $s['inizio_luca'] = $inizio['subentro'];
        $s['anteprima_inizio_luca'] = $inizio['anteprima'];
    }

    return $s;
}

/** La vendita da `$vende` a `$compra`, registrata dalle rotte vere (con la presa d'atto solo se il pannello la chiede). */
function b51aVendi($test, array $s, string $vende, string $compra, string $il, float|int $quota = 100): Subentro
{
    return fds50Registra($test, $s, fds50ModuloVendita($s, $vende, $compra, $il, $quota))['subentro'];
}

/** DL7 fino alla vendita (12/3) compresa: pronto per la fine di Luca il 18/4. */
function b51aDl7ConVendita($test, array $opzioni = []): array
{
    $s = b51aDl7($test, $opzioni);
    $s['vendita'] = b51aVendi($test, $s, 'Ugo', 'Elsa', '2026-03-12', $opzioni['quotaVendita'] ?? 100);

    return $s;
}

/** Il modulo della fine della locazione di `$chi` il giorno dato, con un nuovo inquilino (`$entra`) o senza, e le pertinenze date. */
function b51aModuloFine(array $s, string $chi, string $il, ?string $entra = null, array $extra = [], array $pertinenze = []): array
{
    return array_replace(fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, $pertinenze, $chi), $extra);
}

/** L'anteprima della fine della locazione di `$chi`: l'array della risposta (200). */
function b51aAnteprimaFine($test, array $s, string $chi, string $il, ?string $entra = null, array $extra = [], array $pertinenze = []): array
{
    return fclAnteprima($test, $s, b51aModuloFine($s, $chi, $il, $entra, $extra, $pertinenze));
}

/** La fine della locazione di `$chi` registrata (la presa d'atto del cancello solo se il pannello la chiede); il passaggio padre. */
function b51aRegistraFine($test, array $s, string $chi, string $il, ?string $entra = null, array $extra = [], array $pertinenze = []): Subentro
{
    return fclRegistra($test, $s, b51aModuloFine($s, $chi, $il, $entra, $extra, $pertinenze));
}

/** L'inizio di una locazione, registrato. */
function b51aRegistraInizio($test, array $s, string $chi, string $il): Subentro
{
    return fds50Registra($test, $s, fds50ModuloInizio($s, $chi, $il))['subentro'];
}

/**
 * Le fermate dell'anteprima, in breve: per ognuna [persona, cifra, verso]. «persona» è chi resta a carico, «verso» chi riceve i
 * giorni (i nomi nell'ordine in cui il programma li dà).
 *
 * @return list<array{0: string, 1: int, 2: list<string>}>
 */
function b51aFermate(array $anteprima): array
{
    return array_map(fn (array $f) => [(string) $f['persona'], (int) $f['cifra'], array_values((array) $f['verso'])], $anteprima['rate']['fermate_dopo_scrivi'] ?? []);
}

/** Il registro del passaggio, anche se è annullato. */
function b51aRegistro(Subentro $passaggio): array
{
    return Subentro::conAnnullati()->findOrFail($passaggio->id)->registro ?? [];
}

/** Il testo di un passaggio nello storico, tutto in una stringa (frasi delle fermate, avvisi, effetti): per cercare cifre e parole. */
function b51aTestoStorico(array $s, Subentro $passaggio): string
{
    return implode(' | ', fds50Testi(fds50Storico($s, $passaggio)));
}

/** Tutte le stringhe dell'anteprima in una (frasi, motivi, informazioni, il resto): per cercare una cifra o una parola. */
function b51aTestoAnteprima(array $anteprima): string
{
    return implode(' | ', fds50Testi($anteprima));
}

/** Le stringhe del pannello «Rate» (frasi e fermate) che contengono la cifra data. */
function b51aFrasiConCifra(array $anteprima, string $cifra): array
{
    return array_values(array_filter(array_merge($anteprima['rate']['frasi'] ?? [], $anteprima['cancello']['motivi'] ?? [], $anteprima['cancello']['informazioni'] ?? []), fn ($t) => is_string($t) && str_contains($t, $cifra)));
}

/** Il prospetto degli oneri accessori dell'Interno 1 (array del servizio). */
function b51aProspetto(array $s): array
{
    return pflProspetto($s);
}

/** Una persona della sezione «senza conduttore» del prospetto: [pagato_da, importo, giorni, oppure] per ogni voce non a zero. */
function b51aSenzaConduttore(array $s): array
{
    return collect(b51aProspetto($s)['senza_conduttore']['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)
        ->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], (int) $v['giorni'], $v['oppure'] ?? null])->values()->all();
}

/** I conduttori del prospetto in breve: nome → [totale, nelle sue rate, da rimborsare]. */
function b51aConduttori(array $s): array
{
    $out = [];
    foreach (b51aProspetto($s)['conduttori'] as $c) {
        $out[$c['nome']] = [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare']];
    }

    return $out;
}

/** Un saldo manuale di una persona su una gestione e un'unità dello scenario (come lo scrive il Wallet). */
function b51aSaldoManuale(array $s, string $nome, int $centesimi): void
{
    fclRiporto($s, $nome, $centesimi);
}


/** Gli avvisi di «Annulla il conguaglio» di una fine, come li dà il servizio. */
function b51aAvvisiDelConguaglio(Subentro $fine): array
{
    return \App\Services\Subentro\FermataDopoScrivi::avvisiDelConguaglio($fine->fresh());
}

/**
 * Il caso INT fino all'inizio di Luca (1/11) compreso: fine di Ines l'1/7 con «Scrivi» (coppia Ugo +18148, Ines −18148), rate emesse fino
 * al 30/9, vendita Ugo → Elsa l'1/10 (fermata di 9074 verso Elsa), inizio di Luca l'1/11 (la fermata si riduce a 3058).
 */
function b51aInt($test): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Marco');
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    expect(fds50Coppia($s, $s['fine_ines']))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    fds50Emetti($test, $s, '2026-09-30');
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(collect(fds50Fermate($s['vendita']))->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['verso']])->all())->toBe([['Ugo', 9074, ['Elsa']]]);
    $s['inizio_luca'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    // La fermata si è ridotta: restano 3058 con Elsa.
    expect(collect(b51aRegistro($s['inizio_luca'])['fermata_ridotta'] ?? [])->map(fn ($r) => [(int) $r['prima'], (int) $r['residuo']])->all())->toBe([[9074, 3058]]);

    return $s;
}

/** Il modulo della fine della locazione di Luca (o di chi si dice) dell'unità del caso INT, senza nuovo inquilino. */
function b51aIntFine(array $s, string $il, string $chi = 'Luca'): array
{
    return fds50ModuloFine($s, null, $il, $chi);
}

/** Le chiavi che la cifra dei giorni del conduttore deve avere nel registro dell'inizio (oracolo O-REG-INIZIO). */
function b51aChiaviGiorni(): array
{
    return ['fine_id', 'immobile_id', 'gestione_id', 'esercizio_id', 'conduttore', 'conduttore_id', 'dal', 'cifra', 'pagato_da'];
}

/**
 * Un secondo piano «Riscaldamento 2026» sulla sua gestione, con «Combustibile» (tutto all'inquilino) alla competenza data, generato e
 * non emesso: la coppia di un passaggio registrato dopo non lo prende (`PianoRate::presoNelConguaglioDa`). Restituisce il piano.
 */
function b51aRiscaldamento(array $s, int $importo, ?string $dal, ?string $al): \App\Models\Gestionale\PianoRate
{
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $piano->capitoli()->syncWithoutDetaching([$conto->id => ['importo' => $importo]]);
    if ($dal !== null) {
        $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $conto->id)->value('id');
        \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
    }
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);

    return $piano;
}

/** Le rate di un altro piano con scadenza fino al giorno dato, emesse dalla rotta vera. */
function b51aEmettiPiano($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false])->assertSessionHasNoErrors();
}

/**
 * Il caso 252.1: una voce dopo (spesa o entrata, € 400,00, competenza 1/10–31/12) e un cambio d'inquilino Ines → Marco l'1/4 in un
 * passo con la scelta data. Con `$fineMarco`: rate fino al 30/6, fine di Marco l'1/7 con «Scrivi», rate fino al 30/9, inizio di Luca
 * l'1/10. Senza: solo il cambio.
 */
function b51aCambioMarco($test, string $tipo, string $scelta = 'scrivi', bool $fineMarco = true, int $importo = 40000): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco', 'Luca']);
    b51aCapitolo($s, 'Voce dopo', $tipo, $importo, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($test, $s, '2026-03-31');
    $s['cambio'] = fclRegistra($test, $s, fclModulo($s, $s['persone']['Marco'], '2026-04-01') + ['conguaglio' => $scelta]);
    fclEmetti($test, $s, '2026-06-30');
    if ($fineMarco) {
        $s['fine_marco'] = fclRegistra($test, $s, fclModulo($s, null, '2026-07-01', [], 'Marco') + ['conguaglio' => 'scrivi']);
        fclEmetti($test, $s, '2026-09-30');
        $s['inizio_luca'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))['subentro'];
    }

    return $s;
}

/** Il prospetto in breve per conduttore: nome → ['tot' => [totale, nelle sue rate, da rimborsare], 'voci' => [[conto, modo, pagato da, importo, giorni], …]], senza gli zeri. */
function b51aProspettoInBreve(array $s): array
{
    $out = [];
    foreach (b51aProspetto($s)['conduttori'] as $c) {
        $out[$c['nome']] = ['tot' => [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare']],
            'voci' => collect($c['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)->map(fn ($v) => [$v['conto'], $v['modo'], $v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni']])->values()->all()];
    }

    return $out;
}

/**
 * Il caso della coppia tutta a credito (Coda 252.7c): «Affitto antenna» entrata € 1.000,00 a competenza 1/10–31/12 (30 % all'inquilino =
 * 30000), rate fino al 30/6, fine di Ines l'1/7 con «Scrivi» (coppia: spese 36000 × 184/365 = 18148 e antenna −30000 → −11852 a chi paga
 * al posto dell'inquilino), rate fino al 30/9. `$titolari` come in `fclScenario`; `$opzioni` le sue.
 */
function b51aCoppiaACredito($test, array $titolari = [['Ugo', 'proprietario', 100]], array $opzioni = [], array $pertinenzeFine = []): array
{
    $s = fclScenario($titolari, $opzioni);
    b51aPersone($s, ['Luca', 'Elsa', 'Carlo', 'Anna', 'Bruno', 'Dora']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fclRegistra($test, $s, fclModulo($s, null, '2026-07-01', $pertinenzeFine) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-09-30');

    return $s;
}

/**
 * Le frasi (stringhe di almeno quattro parole: i campi con la cifra formattata da sola non contano) di un array che dicono una cifra con
 * «€ -». Coda 251-c e 252: una cifra negativa si dice con le parole.
 *
 * @return list<string>
 */
function b51aFrasiConMenoEuro(array $dati, string $origine = ''): array
{
    $out = [];
    array_walk_recursive($dati, function ($v, $k) use (&$out, $origine) {
        if (is_string($v) && str_contains($v, '€ -') && count(preg_split('/\s+/u', trim($v))) >= 4) {
            $out[] = ($origine !== '' ? "[{$origine}.{$k}] " : '') . mb_substr($v, 0, 220);
        }
    });

    return $out;
}

/**
 * La sezione di un conduttore nella stampa letta da `fclStampa` (che non applica il maiuscolo dei fogli di stile): dall'intestazione «Conduttore <nome>
 * Conduttore nell'esercizio» alla sezione dopo (un altro conduttore, i giorni senza conduttore, il piede dell'unità). Vuota se non c'è.
 */
function b51aSezioneDelConduttore(string $stampa, string $nome): string
{
    $intestazione = "Conduttore {$nome} Conduttore nell'esercizio";
    $inizio = strpos($stampa, $intestazione);
    if ($inizio === false) {
        return '';
    }
    $resto = substr($stampa, $inizio + strlen($intestazione));
    $fine = [];
    foreach (['Giorni senza conduttore', 'Totale delle voci che il riparto'] as $segno) {
        $x = strpos($resto, $segno);
        if ($x !== false) {
            $fine[] = $x;
        }
    }
    if (preg_match('/Conduttore \S+ Conduttore nell\'esercizio/u', $resto, $m, PREG_OFFSET_CAPTURE) === 1) {
        $fine[] = $m[0][1];
    }

    return substr($stampa, $inizio, strlen($intestazione) + ($fine === [] ? strlen($resto) : min($fine)));
}

/**
 * Le frasi del pannello e i motivi del cancello che parlano di una fermata (la fermata dopo «Scrivi», la parte che risale, il coinquilino che
 * resta): quelle che contengono le parole fisse delle tre famiglie. Il resto dell'anteprima (il conguaglio del passaggio stesso, Coda 251-c) è
 * un altro pezzo.
 *
 * @return list<string>
 */
function b51aTestiDelleFermate(array $anteprima): array
{
    $tutti = [...($anteprima['rate']['frasi'] ?? []), ...($anteprima['cancello']['motivi'] ?? []), ...($anteprima['cancello']['informazioni'] ?? [])];

    return array_values(array_filter($tutti, fn ($t) => is_string($t) && (str_contains($t, 'ha scritto il conguaglio') || str_contains($t, 'il conguaglio scritto alla fine locazione')
        || str_contains($t, 'il programma non propone il conguaglio') || str_contains($t, 'restano da regolare') || str_contains($t, 'risale'))));
}
