<?php

/**
 * 1.11.0-beta.53 — gli aiuti dei test del saldo del Wallet legato a un passaggio (Coda 244) e dei saldi a mano scritti prima.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto, tutte con il prefisso `sl53` (i nomi delle funzioni di Pest sono globali).
 *
 * Lo scenario di base è quello dei test della fermata dopo «Scrivi il conguaglio» (`fds50Scenario`): Ugo proprietario al 100 %, Ines
 * inquilina dal 2019, «Spese generali» € 1.200,00 al 30 % all'inquilino, il «Preventivo 2026» in 12 rate dal 5/1 generato ed emesso fino al
 * 30/6; la fine della locazione di Ines il 1/7 con «Scrivi» (coppia: Ugo € 181,48 a debito, Ines € 181,48 a credito); le rate emesse fino al
 * 30/9; la vendita di Ugo a Elsa il 1/11. La vendita si ferma sulla coppia della fine: € 60,16 dei giorni dal 1/11 restano a carico di Ugo,
 * da passare a Elsa a mano. Fra un passo e l'altro l'orologio avanza di un minuto: i saldi scritti dopo la registrazione della vendita hanno
 * una data di creazione successiva alla sua.
 *
 * La colonna del legame (`saldi.passaggio_id`) si legge come attributo del model, mai con una clausola SQL: finché la colonna non c'è,
 * l'attributo vale null e l'asserzione che lo chiede fallisce per quello che dice, non per un errore della query.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/FermataDopoScriviHelpers.php';

/** L'amministratore che opera, in `$test->user`. */
function sl53Amministratore($test): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $test->user = User::factory()->create();
    $test->user->assignRole($ruolo);

    return $test->user;
}

/**
 * Lo scenario di base, fino alla vendita registrata. `$riporto`: i saldi a mano scritti prima di tutto, per nome (`['Ugo' => 12345]`).
 * `$vendita`: campi in più del modulo della vendita (la rinuncia alla coppia, per esempio). Con `$box` l'Interno 1 ha il suo box (Box 1, di Ugo),
 * che passa con la vendita come pertinenza: `$s['box']`.
 *
 * @return array{c: \App\Models\Condominio, e: \App\Models\Esercizio, g: \App\Models\Gestione, unita: \App\Models\Immobile, piano: \App\Models\Gestionale\PianoRate, persone: array<string, Anagrafica>, conto: \App\Models\Gestionale\Conto, fine: Subentro, vendita: Subentro, anteprima: array, cifra: int}
 */
function sl53Scenario($test, array $riporto = [], array $vendita = [], bool $box = false): array
{
    $s = fds50Scenario();
    if ($box) {
        // Il box dell'Interno 1, di Ugo dal 2019, senza millesimi: passa con la vendita come pertinenza.
        $s['box'] = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'descrizione' => 'Box', 'codice_immobile' => 'SL53-BOX-' . $s['unita']->id, 'nome' => 'Box 1',
            'interno' => 'B1', 'pertinenza_di_immobile_id' => $s['unita']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Ugo']->id, 'immobile_id' => $s['box']->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        $vendita += ['pertinenze' => [(int) $s['box']->id]];
    }
    foreach ($riporto as $chi => $cents) {
        sl53SaldoDiPrima($s, $chi, $cents);
    }
    $test->travel(1)->minutes();
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    $r = fds50Registra($test, $s, array_replace(fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'), $vendita));
    $s['vendita'] = $r['subentro'];
    $s['anteprima'] = $r['anteprima'];
    $s['cifra'] = (int) (fds50Fermate($r['subentro'])[0]['cifra'] ?? 0);
    $test->travel(1)->minutes();

    return $s;
}

/** Un saldo a mano scritto senza passare dalla porta (un dato di prima della beta, o un riporto): libero, origine «manuale». */
function sl53SaldoDiPrima(array $s, string $chi, int $cents, ?int $immobileId = null, ?int $gestioneId = null): Saldo
{
    return Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id,
        'immobile_id' => $immobileId ?? $s['unita']->id, 'gestione_id' => $gestioneId ?? $s['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'manuale', 'is_applicato' => false]);
}

/** Il POST del modale del Wallet, con il passaggio a cui legare la riga (o senza, `null`). `$campi` sostituisce o aggiunge campi del corpo. */
function sl53Posta($test, array $s, ?string $chi, int $cents, ?int $passaggioId, array $campi = []): TestResponse
{
    $corpo = array_replace([
        'anagrafica_id' => $chi === null ? null : $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents,
    ], $passaggioId === null ? [] : ['passaggio_id' => $passaggioId], $campi);

    return $test->actingAs($test->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), $corpo);
}

/** Le due righe della fermata legate alla vendita, dalla porta: Ugo a credito della cifra, Elsa a debito. Fallisce se una è rifiutata. */
function sl53DueLegate($test, array $s): void
{
    foreach ([['Ugo', -$s['cifra']], ['Elsa', $s['cifra']]] as [$chi, $cents]) {
        $r = sl53Posta($test, $s, $chi, $cents, (int) $s['vendita']->id);
        expect(sl53Errori($r))->toBe([], "La riga legata di {$chi} è rifiutata: " . json_encode(sl53Errori($r), JSON_UNESCAPED_UNICODE));
    }
}

/** Gli errori di campo che la risposta ha messo in sessione. @return array<string, list<string>> */
function sl53Errori(TestResponse $r): array
{
    return $r->getSession()?->get('errors')?->getBag('default')->toArray() ?? [];
}

/** Il messaggio della pagina dopo la risposta: tipo e testo. @return array{type: ?string, message: string} */
function sl53Messaggio(TestResponse $r): array
{
    $m = $r->getSession()?->get('message') ?? [];

    return ['type' => $m['type'] ?? null, 'message' => (string) ($m['message'] ?? '')];
}

/**
 * Le righe di saldo dell'unità, per id, con il legame letto dal model: [id, nome, importo, subentro_id, passaggio_id, is_applicato, piano_rate_id].
 *
 * @return list<array{0: int, 1: ?string, 2: int, 3: ?int, 4: ?int, 5: bool, 6: ?int}>
 */
function sl53Righe(array $s, ?int $immobileId = null): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();

    return Saldo::where('immobile_id', $immobileId ?? $s['unita']->id)->orderBy('id')->get()
        ->map(fn (Saldo $x) => [(int) $x->id, $nomi[(int) $x->anagrafica_id] ?? null, (int) $x->saldo_iniziale,
            $x->subentro_id === null ? null : (int) $x->subentro_id, $x->passaggio_id === null ? null : (int) $x->passaggio_id, (bool) $x->is_applicato,
            $x->piano_rate_id === null ? null : (int) $x->piano_rate_id])
        ->all();
}

/** Le righe legate al passaggio dato, per nome: [nome => importo]. Dal model, non da una clausola SQL. */
function sl53Legate(array $s, Subentro|int $passaggio): array
{
    $id = is_int($passaggio) ? $passaggio : (int) $passaggio->id;
    $nomi = collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();
    $out = [];
    foreach (Saldo::all() as $x) {
        if ($x->passaggio_id !== null && (int) $x->passaggio_id === $id) {
            $chi = $nomi[(int) $x->anagrafica_id] ?? "anagrafica {$x->anagrafica_id}";
            $out[$chi] = ($out[$chi] ?? 0) + (int) $x->saldo_iniziale;
        }
    }
    ksort($out);

    return $out;
}

/** L'annullamento del passaggio dalla rotta della pagina (risposta di pagina, non JSON: gli errori vanno in sessione). */
function sl53Annulla($test, array $s, Subentro $passaggio, string $nota = 'Rogito non stipulato, annullo la vendita'): TestResponse
{
    return $test->actingAs($test->user)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento' => $nota]);
}

/** «Annulla il conguaglio» dalla rotta della pagina. */
function sl53AnnullaConguaglio($test, array $s, Subentro $passaggio, string $nota = 'Le parti hanno regolato fra loro il conguaglio'): TestResponse
{
    return $test->actingAs($test->user)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento_conguaglio' => $nota]);
}

/** Un secondo piano della stessa gestione, in bozza, con i saldi applicati; generato subito se `$genera`. */
function sl53SecondoPiano(array $s, bool $genera = true, string $stato = 'bozza'): \App\Models\Gestionale\PianoRate
{
    $p = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Conguaglio 2026', 'stato' => $stato,
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    if ($genera) {
        fds50Genera($s, $p);
    }

    return $p;
}

/**
 * La frase dei fatti delle righe legate seguita dalle rotte del Wallet, come la legge l'amministratore: per ogni persona data, la differenza fra la
 * sua parte e quanto sommano oggi le sue righe legate al passaggio (sulla gestione e sull'unità dello scenario, in ogni esercizio); chi ha una riga
 * che si modifica (fuori da un piano che non si riscrive più, nell'esercizio aperto) la corregge, chi non ne ha una registra la differenza con un
 * saldo legato. La frase deve indicare la strada che si percorre: «correggi … dal Wallet» per una correzione, «la differenza con un saldo legato»
 * per un saldo nuovo. Restituisce gli errori delle risposte, per persona: vuoto, nessun rifiuto.
 *
 * @param array<string, int> $parte nome → la parte in centesimi (positivo = debito), come la dice la frase
 * @return array<string, array<string, list<string>>>
 */
function sl53SeguiIFatti($test, array $s, Subentro|int $passaggio, array $parte, string $frase): array
{
    $id = is_int($passaggio) ? $passaggio : (int) $passaggio->id;
    $aperto = \App\Models\Esercizio::where('condominio_id', $s['c']->id)->where('stato', 'aperto')->orderBy('id')->first();
    $errori = [];
    foreach ($parte as $chi => $cents) {
        $righe = Saldo::with('pianoRate')->where('passaggio_id', $id)->where('anagrafica_id', $s['persone'][$chi]->id)->where('gestione_id', $s['g']->id)
            ->where('immobile_id', $s['unita']->id)->orderBy('id')->get();
        $differenza = $cents - (int) $righe->sum('saldo_iniziale');
        if ($differenza === 0) {
            continue;
        }
        $libera = $righe->first(fn (Saldo $x) => ! $x->eBloccato() && $aperto !== null && (int) $x->esercizio_id === (int) $aperto->id);
        if ($libera !== null) {
            expect(preg_match('/[Cc]orreggi(lo)? dal Wallet/', $frase) === 1)->toBeTrue("La frase non dice di correggere dal Wallet la riga di {$chi}: {$frase}");
            $r = $test->actingAs($test->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $libera]),
                ['saldo_iniziale' => (int) $libera->saldo_iniziale + $differenza, 'gestione_id' => $s['g']->id]);
        } else {
            expect(str_contains($frase, 'la differenza con un saldo legato'))->toBeTrue("La frase non dice di registrare la differenza di {$chi}: {$frase}");
            $r = sl53Posta($test, $s, $chi, $differenza, $id);
        }
        if (($e = sl53Errori($r)) !== []) {
            $errori[$chi] = $e;
        }
    }

    return $errori;
}

/** Tutte le stringhe di un array, unite in un testo solo con « | » fra l'una e l'altra: per i messaggi d'errore dei test. */
function sl53Unite(array $dati): string
{
    return implode(' | ', fds50Testi($dati));
}
