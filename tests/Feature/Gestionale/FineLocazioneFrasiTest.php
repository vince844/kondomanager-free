<?php

/**
 * 1.11.0-beta.47 — le frasi della fine locazione: a chi «tornano» le voci a carico dell'inquilino.
 *
 * Nasce da DL2 del laboratorio (rapporto della sessione 3-bis, §5.2; la prima e la seconda forma erano già nel rapporto
 * della sessione 3). Alla fine di una locazione senza un nuovo inquilino il pannello «Cosa cambierà» (`AnteprimaPassaggio`:
 * le frasi di «Chi resta obbligato» e il blocco «Rate») e lo storico dell'unità (`StoricoTitolarita`, che chiama
 * `FrasiObbligati::daSubentro`) dicono a chi «tornano» le voci a carico dell'inquilino. Devono nominare chi le riceve
 * davvero nel riparto — il primo anello dopo l'inquilino nella catena di ripiego (l'usufruttuario, poi il proprietario, poi
 * il nudo proprietario), con il proprietario pieno accanto all'usufruttuario sull'unità mista (decisione 31.1) —, non «i
 * proprietari» di oggi. Con un nuovo inquilino le voci non tornano a nessuno: le paga chi entra.
 *
 * Chi è giusto nominare lo dice il piano generato dopo l'uscita (decisione 21: è il riparto che il programma fa con la
 * titolarità registrata). Le cinque forme d'unità sono quelle della decisione 32: piena, comproprietà, usufrutto, mista,
 * mista con due persone. Oggi il pannello sbaglia su tre (nomina la nuda proprietaria, non nomina l'usufruttuario), il
 * blocco «Rate» dice «tornano al proprietario» anche sull'unità in usufrutto, lo storico dice «tornano a al proprietario»
 * sull'unità in usufrutto e non nomina l'usufruttuario diverso dal pieno, e con un nuovo inquilino pannello e storico dicono
 * che le voci «tornano a Ugo».
 *
 * I controlli, verdi oggi, che devono restarlo dopo la correzione: le cifre del piano generato dopo l'uscita sulle cinque
 * forme (la correzione tocca le parole, non il denaro); le forme in cui la frase è già giusta (piena e comproprietà; nello
 * storico anche la mista); con il nuovo inquilino le cifre del conguaglio e la frase su chi entra.
 *
 * Cosa NON copre: la decisione 32 (chi paga le rate già emesse all'inquilino uscito, e con quale strada) — qui la fine
 * locazione con rate emesse lascia il denaro com'è oggi; DL1 e DL3–DL6 (prospetto degli oneri accessori, inizio locazione
 * su un piano a giornale, unità mista nel prospetto e nella vendita); le pertinenze che finiscono di essere locate con
 * l'unità; la pagina Vue: si guarda la risposta dell'anteprima e lo storico come li calcola il server.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Subentro\FrasiObbligati;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Le cinque forme dell'unità (le stesse della decisione 32): i titolari oltre a Ines, inquilina dal 2019; chi la frase
 * deve nominare e chi no; le cifre del piano generato dopo l'uscita di Ines il 1/7.
 *
 * € 1.200,00 di «Spese generali»: 30 % «Inquilino» = € 360,00 (36000), 70 % «Proprietario» = € 840,00 (84000).
 * Ines dal 1/1 al 30/6: 31 + 28 + 31 + 30 + 31 + 30 = 181 giorni; dopo, 365 − 181 = 184.
 * 36000 × 181/365 = 17852,05 → 17852 a Ines; 36000 × 184/365 = 18147,95 → 18148 a chi paga al posto dell'inquilino
 * (il centesimo al resto più alto: 17852 + 18148 = 36000).
 */
function flfForme(): array
{
    return [
        'piena (Ugo)' => [
            [['Ugo', 'proprietario', 100]],
            ['Ugo'], [],
            // Ugo: 84000 + 18148 = 102148.
            ['Ines' => 17852, 'Ugo' => 102148],
        ],
        'in comproprietà (Ugo 50, Rita 50)' => [
            [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]],
            ['Ugo', 'Rita'], [],
            // 18148 / 2 = 9074 e 84000 / 2 = 42000 a testa: 42000 + 9074 = 51074.
            ['Ines' => 17852, 'Rita' => 51074, 'Ugo' => 51074],
        ],
        'in usufrutto (Ugo usufruttuario, Bice nuda)' => [
            [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]],
            ['Ugo'], ['Bice'],
            // La parte dell'inquilino all'usufruttuario (art. 1004 c.c.): Ugo 18148. Il «Proprietario» al nudo, che è il solo
            // proprietario: Bice 84000. 17852 + 18148 + 84000 = 120000.
            ['Bice' => 84000, 'Ines' => 17852, 'Ugo' => 18148],
        ],
        'mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50)' => [
            [['Ugo', 'proprietario', 50], ['Ugo', 'usufruttuario', 50], ['Bice', 'nuda_proprietario', 50]],
            ['Ugo'], ['Bice'],
            // 18148 all'usufruttuario e al gemello pieno (31.1), tutti e due Ugo: 9074 + 9074 = 18148. Il «Proprietario»
            // 84000: Ugo pieno 42000, Bice nuda 42000 (il gemello del capitale). Ugo 42000 + 18148 = 60148.
            // 17852 + 60148 + 42000 = 120000.
            ['Bice' => 42000, 'Ines' => 17852, 'Ugo' => 60148],
        ],
        'mista con due persone (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50)' => [
            [['Ugo', 'proprietario', 50], ['Carlo', 'usufruttuario', 50], ['Bice', 'nuda_proprietario', 50]],
            ['Carlo', 'Ugo'], ['Bice'],
            // 18148 a Carlo usufruttuario e a Ugo pieno per quota: 9074 ciascuno. Il «Proprietario»: Ugo 42000, Bice 42000.
            // Ugo 42000 + 9074 = 51074. 17852 + 9074 + 51074 + 42000 = 120000.
            ['Bice' => 42000, 'Carlo' => 9074, 'Ines' => 17852, 'Ugo' => 51074],
        ],
    ];
}

/**
 * Un'unità da 1000 millesimi su 1000; «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» e 70 %
 * «Proprietario»; il preventivo 2026 in 12 rate mensili dal 5/1, senza saldi pregressi, **non generato**. I titolari dati
 * (nome, ruolo, quota) dal 2019, e Ines inquilina dal 2019.
 *
 * @param list<array{0: string, 1: string, 2: float|int}> $titolari
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, piano: PianoRate, persone: array<string, Anagrafica>}
 */
function flfScenario(array $titolari): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "FLF-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persone = [];
    foreach ([...$titolari, ['Ines', 'inquilino', 100]] as [$nome, $ruolo, $quota]) {
        if (! isset($persone[$nome])) {
            $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-flf{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 4), 4, 'X')) . 'FLF' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT) . str_pad((string) count($persone), 4, '0', STR_PAD_LEFT)]);
            $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        }
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);

    return compact('c', 'e', 'g', 'unita', 'piano', 'persone');
}

/** La generazione del piano, con la presa d'atto del cancello (2) come la dà l'amministratore. */
function flfGenera(array $s): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
}

/** L'emissione vera, dalla rotta della pagina del piano, delle rate con scadenza fino al giorno dato. */
function flfEmetti($test, array $s, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
    ]);
}

/** Luca, il nuovo inquilino del cambio d'inquilino: una persona del condominio, non ancora titolare dell'unità. */
function flfNuovoInquilino(array $s): Anagrafica
{
    $luca = Anagrafica::forceCreate(['nome' => 'Luca', 'email' => "luca-flf{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'LUCAFLFNUOVO' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    $luca->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $luca;
}

/** Il modulo della fine locazione di Ines, con il nuovo inquilino o senza. */
function flfModulo(array $s, ?Anagrafica $entra, string $il = '2026-07-01'): array
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ines']->id)->where('tipologia', 'inquilino')->value('id');

    return [
        'tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $entra?->id,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [],
    ];
}

function flfAnteprima($test, array $s, ?Anagrafica $entra = null): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), flfModulo($s, $entra))->assertOk()->json();
}

/** La registrazione dalla rotta vera; la presa d'atto del cancello solo quando il pannello la chiede. */
function flfRegistra($test, array $s, ?Anagrafica $entra = null): Subentro
{
    $dati = flfModulo($s, $entra);
    if (flfAnteprima($test, $s, $entra)['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Disdetta letta, rate emesse controllate'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** Le frasi «Chi resta obbligato» del passaggio nello storico dell'unità, in un testo solo. */
function flfStorico(array $s, Subentro $subentro): string
{
    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);

    return implode(' ', $voce['obbligati']);
}

/** Quanto paga ogni persona sull'unità, per nome: le quote pure del piano più le righe di conguaglio dei passaggi, senza gli zeri. */
function flfNetti(array $s): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->where('rate_quote.stato', '!=', 'annullata')->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $chi = $nomi[(int) $q->anagrafica_id] ?? "anagrafica {$q->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) (json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? 0);
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']->id)->get(['anagrafica_id', 'saldo_iniziale']) as $x) {
        $chi = $nomi[(int) $x->anagrafica_id] ?? "anagrafica {$x->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $x->saldo_iniziale;
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/** Il pezzo di frase da «tornano» al punto — «tornano a Bice e Ugo», «tornano al proprietario» —, o vuoto se non c'è. */
function flfTornano(string $testo): string
{
    return preg_match('/tornano\b[^.]*/u', $testo, $m) === 1 ? trim($m[0]) : '';
}

/** La frase «… tornano a …» nomina chi deve, in qualunque ordine, e non nomina chi non deve. */
function flfTornanoA(string $testo, array $nominati, array $esclusi): void
{
    $pezzo = flfTornano($testo);
    foreach ($nominati as $nome) {
        expect($pezzo)->toContain($nome);
    }
    foreach ($esclusi as $nome) {
        expect($pezzo)->not->toContain($nome);
    }
}

// --- Senza nuovo inquilino, piano generato dopo l'uscita ----------------------------------------------------------------

it('il denaro, controllo: il piano generato dopo la fine locazione del 1/7 dà a Ines i suoi 181 giorni e i 184 dopo a chi paga al posto dell\'inquilino, sulle cinque forme', function (array $titolari, array $nominati, array $esclusi, array $netti) {
    $s = flfScenario($titolari);
    flfRegistra($this, $s);
    flfGenera($s);

    // Le cifre a mano sono in `flfForme()`. Nessun conguaglio: il piano è generato dopo, con la titolarità registrata.
    expect(flfNetti($s))->toBe($netti)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->count())->toBe(0);
})->with(flfForme());

it('pannello, fine locazione senza nuovo inquilino: le voci a carico dell\'inquilino «tornano a» chi le riceve nel riparto, non alla nuda proprietaria', function (array $titolari, array $nominati, array $esclusi) {
    $s = flfScenario($titolari);
    $frasi = implode(' ', flfAnteprima($this, $s)['obbligati']['frasi']);

    // Chi le riceve lo dice il piano generato dopo (il test sul denaro qui sopra): Ugo sull'unità piena, sull'unità in
    // usufrutto e sulla mista; Ugo e Rita in comproprietà; Carlo e Ugo sulla mista con due persone. Bice, nuda
    // proprietaria, non riceve niente della parte dell'inquilino.
    flfTornanoA($frasi, $nominati, $esclusi);
})->with(flfForme());

it('storico, fine locazione registrata senza nuovo inquilino: la frase nomina chi paga al posto dell\'inquilino, e mai «tornano a al proprietario»', function (array $titolari, array $nominati, array $esclusi) {
    $s = flfScenario($titolari);
    $subentro = flfRegistra($this, $s);
    $storico = flfStorico($s, $subentro);

    // Gli stessi nomi del pannello: lo storico dice la stessa cosa, dai fatti registrati. Oggi sull'unità in usufrutto
    // cerca un proprietario pieno, non lo trova e scrive la clausola dentro la frase: «tornano a al proprietario».
    flfTornanoA($storico, $nominati, $esclusi);
    expect($storico)->not->toContain('tornano a al');
})->with(flfForme());

it('pannello, blocco «Rate» sull\'unità in usufrutto con sei rate emesse a Ines: dalla prossima generazione le voci dell\'inquilino tornano a Ugo, l\'usufruttuario, non «al proprietario»', function () {
    $s = flfScenario([['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]]);
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);

    $rate = implode(' ', flfAnteprima($this, $s)['rate']['frasi']);

    // Le sei quote di Ines: 36000 / 12 = 3000 a rata, 6 × 3000 = 18000 → € 180,00. Restano sue (decisione 32 non costruita).
    expect($rate)->toContain('le 6 quote intestate a Ines (€ 180,00) restano sue.')
        // Un piano generato dopo l'uscita dà la parte dell'inquilino a Ugo, l'usufruttuario (art. 1004 c.c.), non a Bice:
        // «al proprietario» su quest'unità è falso.
        ->and(flfTornano($rate))->toContain('Ugo')
        ->and($rate)->not->toContain('tornano al proprietario')
        ->and(flfTornano($rate))->not->toContain('Bice');
});

// --- Con un nuovo inquilino ---------------------------------------------------------------------------------------------

it('pannello, cambio d\'inquilino il 1/7 con sei rate emesse: le voci a carico dell\'inquilino non «tornano» a nessuno, le paga Luca', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    $luca = $s['persone']['Luca'] = flfNuovoInquilino($s);
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);
    $frasi = implode(' ', flfAnteprima($this, $s, $luca)['obbligati']['frasi']);
    flfRegistra($this, $s, $luca);

    // Il denaro, controllo: la parte dell'inquilino dal 1/7 è di Luca con il conguaglio, Ugo resta al suo «Proprietario».
    // Ines 36000 × 181/365 = 17852,05 → 17852; Luca 36000 − 17852 = 18148; Ugo 84000, invariato.
    expect(flfNetti($s))->toBe(['Ines' => 17852, 'Luca' => 18148, 'Ugo' => 84000])
        ->and($frasi)->toContain('Le rate già emesse a Ines restano sue.')
        ->and($frasi)->toContain('Luca risponde delle voci a carico dell\'inquilino dal 1 luglio 2026.')
        // Il difetto: «Dal 1 luglio 2026 le voci a carico dell'inquilino tornano a Ugo», e non tornano a lui.
        ->and(flfTornano($frasi))->toBe('');
});

it('storico, cambio d\'inquilino il 1/7 registrato con sei rate emesse: le voci a carico dell\'inquilino non «tornano» a nessuno, le paga Luca', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    $luca = flfNuovoInquilino($s);
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);
    $storico = flfStorico($s, flfRegistra($this, $s, $luca));

    expect($storico)->toContain('Le rate già emesse a Ines restano sue.')
        ->and($storico)->toContain('Luca risponde delle voci a carico dell\'inquilino dal 1 luglio 2026.')
        ->and(flfTornano($storico))->toBe('');
});

/*
 * La preposizione va con il nome (1.11.0-beta.47): prima la frase era «tornano a %s» e, quando al posto dei nomi arrivava la
 * clausola, diceva «tornano a al proprietario». Il ripiego senza nessuno registrato non ha un caso del modulo che ci arrivi: si
 * prova la funzione da sola, con righe di titolarità non salvate.
 */
it('chi riceve le voci dell\'inquilino si scrive con la sua preposizione: «al proprietario», «a Ugo», «ad Anna»', function () {
    $frasi = new FrasiObbligati();
    $riga = fn (string $nome) => tap(new TitolaritaImmobile(), fn (TitolaritaImmobile $t) => $t->setRelation('anagrafica', new Anagrafica(['nome' => $nome])));

    expect($frasi->aChi(collect()))->toBe('al proprietario')
        ->and($frasi->aChi(collect([$riga('Ugo')])))->toBe('a Ugo')
        ->and($frasi->aChi(collect([$riga('Anna'), $riga('Bruno')])))->toBe('ad Anna e Bruno')
        ->and($frasi->aChi(collect([$riga('Carlo'), $riga('Ugo'), $riga('Carlo')])))->toBe('a Carlo e Ugo');
});

// --- Senza nuovo inquilino, piano fermo (rilievo DL2 della Fase 1-bis della .47, decisione 32) --------------------------
//
// Sul piano che non si ricalcola più le quote di luglio–dicembre restano a Ines, e chi paga i giorni dopo l'uscita lo decide
// l'amministratore (lo dice il cancello dello stesso pannello). La frase degli obbligati non può dire «dal 1 luglio tornano a
// Ugo» come un fatto: vale nei piani generati o ricalcolati dopo il passaggio, e sul piano fermo si dice che cosa resta a Ines.

/** Le quote della persona sulle rate del piano non ancora emesse (in bozza): quante e per quanto, in centesimi. */
function flfNonEmesse(array $s, string $nome): array
{
    $q = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->where('rate_quote.anagrafica_id', $s['persone'][$nome]->id)->where('rate.stato', 'bozza');

    return ['quote' => (clone $q)->count(), 'importo' => (int) $q->sum('rate_quote.importo')];
}

// DL2, «tornano a Ugo» come un fatto sul piano fermo (pannello). Non copre: la decisione 32 (chi paga davvero dopo l'uscita), le altre forme dell'unità, più piani fermi, il blocco «Rate».
it('pannello, fine locazione senza nuovo inquilino sul piano fermo: le voci tornano a Ugo nei piani generati o ricalcolati dopo, e le 6 quote non ancora emesse restano a Ines finché non decide l\'amministratore', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);
    $anteprima = flfAnteprima($this, $s);
    $frasi = implode(' ', $anteprima['obbligati']['frasi']);
    $motivi = implode(' | ', $anteprima['cancello']['motivi']);

    // Il denaro, controllo: 36000 / 12 = 3000 a rata per Ines; luglio–dicembre in bozza, 6 × 3000 = 18000, restano sue.
    expect(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 6, 'importo' => 18000])
        // Il cancello dello stesso pannello, controllo: le 6 quote restano a Ines e chi paga dopo l'uscita lo decide l'amministratore.
        ->and($motivi)->toContain('il piano «Preventivo 2026» ha 6 quote non ancora emesse intestate a Ines')
        ->and($motivi)->toContain('chi paga i giorni dopo l\'uscita lo decide l\'amministratore')
        // Il difetto: oggi «Dal 1 luglio 2026 le voci a carico dell'inquilino tornano a Ugo.», al presente e senza portata.
        ->and($frasi)->toContain('nei piani generati o ricalcolati dopo il passaggio')
        ->and(flfTornano($frasi))->toContain('Ugo')
        ->and(flfTornano($frasi))->not->toContain('Ines')
        ->and($frasi)->toContain('Le rate già emesse a Ines restano sue.')
        // E sul piano fermo si dice che cosa resta a Ines, e chi decide: le 6 quote di prima, 18000.
        ->and($frasi)->toContain('restano a Ines')
        ->and($frasi)->toContain('6 quote non ancora emesse')
        ->and($frasi)->toContain('lo decide l\'amministratore');
});

// DL2, la frase del piano fermo solo dove c'è un piano fermo (pannello, piano generato e mai emesso). Non copre: il piano conguagliato da un passaggio di prima, il piano straordinario.
it('pannello, fine locazione senza nuovo inquilino sul piano generato e mai emesso: la portata «nei piani generati o ricalcolati dopo il passaggio», e nessuna quota «non ancora emessa» che resti a Ines', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    flfGenera($s);
    $anteprima = flfAnteprima($this, $s);
    $frasi = implode(' ', $anteprima['obbligati']['frasi']);

    // Controllo: nessuna rata emessa, il piano si ricalcola ancora e il cancello non lo nomina. Ines ha 12 quote in bozza,
    // 12 × 3000 = 36000, che il ricalcolo dopo il passaggio rifà per giorni (le cifre nel test sul denaro in cima al file).
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(0)
        ->and(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 12, 'importo' => 36000])
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->not->toContain('Preventivo 2026')
        ->and($frasi)->toContain('nei piani generati o ricalcolati dopo il passaggio')
        ->and(flfTornano($frasi))->toContain('Ugo')
        ->and($frasi)->not->toContain('quote non ancora emesse')
        ->and($frasi)->not->toContain('lo decide l\'amministratore');
});

// DL2, la frase del piano fermo solo con quote ancora da emettere (pannello, tutte e dodici le rate emesse). Non copre: un piano fermo con le sole quote di altri ancora in bozza.
it('pannello, fine locazione senza nuovo inquilino con tutte e dodici le rate emesse: niente resta da emettere, e la frase non conta quote «non ancora emesse»', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    flfGenera($s);
    flfEmetti($this, $s, '2026-12-31');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(12);
    $anteprima = flfAnteprima($this, $s);
    $frasi = implode(' ', $anteprima['obbligati']['frasi']);

    // Controllo: nessuna quota di Ines in bozza; le sue 12 quote emesse, 12 × 3000 = 36000 → € 360,00, restano sue.
    expect(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 0, 'importo' => 0])
        ->and(implode(' ', $anteprima['rate']['frasi']))->toContain('le 12 quote intestate a Ines (€ 360,00) restano sue.')
        ->and($frasi)->toContain('nei piani generati o ricalcolati dopo il passaggio')
        ->and(flfTornano($frasi))->toContain('Ugo')
        ->and($frasi)->toContain('Le rate già emesse a Ines restano sue.')
        ->and($frasi)->not->toContain('quote non ancora emesse')
        ->and($frasi)->not->toContain('lo decide l\'amministratore');
});

// DL2, «tornano a Ugo» come un fatto registrato (storico, piano fermo). Non copre: lo storico dopo un ricalcolo, la decisione 32.
it('storico, fine locazione registrata senza nuovo inquilino sul piano fermo: le voci tornano a Ugo nei piani generati o ricalcolati dopo il passaggio, senza il conteggio delle quote', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);
    $storico = flfStorico($s, flfRegistra($this, $s));

    // Il denaro, controllo: la registrazione lascia a Ines le 6 quote in bozza (6 × 3000 = 18000) e non scrive righe di saldo.
    expect(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 6, 'importo' => 18000])
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->count())->toBe(0)
        // Il difetto: oggi lo storico dice al presente «tornano a Ugo.», come una cosa già avvenuta su tutte le rate.
        ->and($storico)->toContain('nei piani generati o ricalcolati dopo il passaggio')
        ->and(flfTornano($storico))->toContain('Ugo')
        ->and(flfTornano($storico))->not->toContain('Ines')
        // Lo storico non conta le quote: il numero di oggi può non essere quello del giorno del passaggio.
        ->and($storico)->not->toContain('6 quote');
});

// --- Giro sulle correzioni della Fase 1-bis della .47 -------------------------------------------------------------------

/**
 * Una seconda unità nello stesso condominio dello scenario, con i millesimi dati sulla tabella «Proprietà» e i titolari dati
 * (anagrafica, ruolo, quota) dal 2019. Va chiamata prima della generazione del piano.
 *
 * @param list<array{0: Anagrafica, 1: string, 2: float|int}> $titolari
 */
function flfSecondaUnita(array $s, string $nome, float $millesimi, array $titolari): Immobile
{
    $unita = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'FLF-B-' . $s['unita']->id, 'nome' => $nome, 'interno' => 'B']);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $unita->id, 'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($titolari as [$anagrafica, $ruolo, $quota]) {
        $anagrafica->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $anagrafica->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $unita;
}

// Rilievo «Forma: le frasi nuove scrivono a davanti al nome» (basso). Non copre: il blocco «Rate» («intestate a …»), la frase dei due
// passi dell'inizio locazione (AnteprimaPassaggio, «intesterà quelle voci a …»), lo storico; il denaro non cambia.
it('pannello, fine locazione senza nuovo inquilino sul piano fermo con l\'inquilina «Anna Galli»: le frasi degli obbligati scrivono «ad Anna Galli», mai «a Anna»', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    $s['persone']['Ines']->forceFill(['nome' => 'Anna Galli'])->save();
    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);
    $frasi = implode(' ', flfAnteprima($this, $s)['obbligati']['frasi']);

    // Il denaro, controllo: 36000 / 12 = 3000 a rata; luglio–dicembre in bozza, 6 × 3000 = 18000, restano all'inquilina uscita.
    expect(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 6, 'importo' => 18000])
        ->and($frasi)->toContain('6 quote non ancora emesse')
        // Il difetto: oggi «Le rate già emesse a Anna Galli restano sue.» e «restano a Anna Galli anche le 6 quote…».
        ->and($frasi)->toContain('Le rate già emesse ad Anna Galli restano sue.')
        ->and($frasi)->toContain('restano ad Anna Galli anche le 6 quote non ancora emesse')
        ->and($frasi)->not->toContain('a Anna');
});

// Rilievo «le query che mettono nomi nelle frasi (bozzeFermeDi) non hanno un test negativo sul perimetro» (basso, sicurezza): controllo,
// verde oggi. Non copre: il ciclo dei piani fermi altrui dell'inizio locazione (InizioLocazionePianoFermoTest), lo storico, le pertinenze.
it('perimetro, controllo — Ines inquilina di A e di B nello stesso condominio e di «Scala C» in un altro: la fine su A conta le sole 6 quote di A sul «Preventivo 2026», e non nomina né B né il piano dell\'altro condominio', function () {
    $s = flfScenario([['Ugo', 'proprietario', 100]]);
    $ines = $s['persone']['Ines'];
    $olga = Anagrafica::forceCreate(['nome' => 'Olga', 'email' => "olga-flf{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'OLGAFLFUNITB' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    // A e B a 500 millesimi ciascuna: € 1.200,00 × 500/1000 = € 600,00 per unità, 30 % «Inquilino» = 18000 l'anno, 18000 / 12 = 1500 a rata.
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 500.0]);
    $b = flfSecondaUnita($s, 'Interno B', 500.0, [[$olga, 'proprietario', 100], [$ines, 'inquilino', 100]]);

    // L'altro condominio: lo scenario di sempre, con l'inquilina che è la stessa persona di A. € 1.200,00 su 1000/1000: 30 % = 36000
    // l'anno, 36000 / 12 = 3000 a rata.
    $s2 = flfScenario([['Zeno', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s2['unita']->id)->where('tipologia', 'inquilino')->update(['anagrafica_id' => $ines->id]);
    $ines->condomini()->syncWithoutDetaching([$s2['c']->id]);
    $s2['persone']['Ines'] = $ines;
    $s2['unita']->forceFill(['nome' => 'Scala C'])->save();
    $s2['piano']->forceFill(['nome' => 'Piano riservato del condominio Due'])->save();

    flfGenera($s);
    flfEmetti($this, $s, '2026-06-30');
    flfGenera($s2);
    flfEmetti($this, $s2, '2026-03-31');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6)
        ->and(DB::table('rate')->where('piano_rate_id', $s2['piano']->id)->where('stato', 'emessa')->count())->toBe(3);

    // Le bozze di Ines, a mano: su A luglio–dicembre, 6 × 1500 = 9000; su B lo stesso, 6 × 1500 = 9000 (insieme 12 quote, 18000); su
    // «Scala C» aprile–dicembre, 9 × 3000 = 27000. Solo le 6 di A sono della fine su A.
    expect(flfNonEmesse($s, 'Ines'))->toBe(['quote' => 6, 'importo' => 9000])
        ->and(flfNonEmesse(['unita' => $b] + $s, 'Ines'))->toBe(['quote' => 6, 'importo' => 9000])
        ->and(flfNonEmesse($s2, 'Ines'))->toBe(['quote' => 9, 'importo' => 27000]);

    $anteprima = flfAnteprima($this, $s);
    $frasi = implode(' ', $anteprima['obbligati']['frasi']);
    $motivi = implode(' | ', $anteprima['cancello']['motivi']);
    $json = json_encode($anteprima, JSON_UNESCAPED_UNICODE);
    $deiPiani = array_values(array_filter($anteprima['obbligati']['frasi'], fn (string $f) => str_starts_with($f, 'Nel piano «')));

    expect($deiPiani)->toHaveCount(1)
        ->and($deiPiani[0])->toContain('Nel piano «Preventivo 2026», che non si ricalcola più')
        ->and($deiPiani[0])->toContain('le 6 quote non ancora emesse')
        ->and($frasi)->not->toContain('le 12 quote')
        ->and($frasi)->not->toContain('le 9 quote')
        ->and($motivi)->toContain('il piano «Preventivo 2026» ha 6 quote non ancora emesse intestate a Ines')
        ->and($motivi)->not->toContain('12 quote')
        ->and($json)->not->toContain('riservato')
        ->and($json)->not->toContain('condominio Due')
        ->and($json)->not->toContain('Scala C')
        ->and($json)->not->toContain('Interno B')
        ->and($json)->not->toContain('Olga')
        ->and($json)->not->toContain('Zeno');
});
