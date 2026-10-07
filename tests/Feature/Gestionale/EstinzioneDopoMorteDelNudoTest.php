<?php

/**
 * DS1 (1.11.0-beta.47; rapporto della sessione 6 del laboratorio, §5): dopo la morte del nudo proprietario, l'estinzione di uno di
 * due usufrutti censiti chiede la scelta dei nudi, e la scelta di un erede solo gli dà tutto il conguaglio.
 *
 * La nuda di Carlo era una sola, sotto i due usufrutti di Rita e Piero: alla morte di Piero tornerebbe piena a Carlo per la metà di
 * Piero, da sé (il consolidamento di legge della nuda sola, decisione 57 e forma S1E). Con la successione di Carlo quella stessa nuda è
 * in comune fra gli eredi, per quota (decisione 66.3: «la genealogia la segue fino a ogni erede, per quota, così l'estinzione
 * dell'usufrutto che viene dopo sa a chi torna piena»): la metà di Piero torna piena agli eredi per quota, come fa oggi «tutti,
 * ciascuno per la sua quota» (decisione 62), senza che l'amministratore debba scegliere. Il programma invece vede due righe di nuda
 * censite e chiede: con gli eredi al 60/40 rifiuta («nessuna combinazione di nudi proprietari interi…»), al 50/50 propone di
 * scegliere «quali tornano proprietari pieni», e la scelta di Anna sola le dà tutto il conguaglio di Piero.
 *
 * Le cifre, fatte a mano: € 1.200,00 l'anno sull'«Usufruttuario», Rita e Piero al 50 % (€ 600,00 l'anno ciascuno), rate emesse fino
 * al 31/3. Carlo muore il 1/4, Piero il 1/5: dal 1/5 al 31/12 sono 245 giorni, e la parte di Piero che passa è
 * € 600,00 × 245/365 = € 402,739… → € 402,74.
 *
 * Cosa presidia: la prima forma (eredi 60/40), la seconda (50/50, con e senza la scelta di Anna sola, che va trattata come la scelta
 * mandata a mano nel consolidamento della nuda sola di oggi: non conta), la frase del pannello, la nuda passata per due successioni
 * e la pertinenza (sul box `AnteprimaPassaggio` e la richiesta chiamano `NudiDellEstinzione::per` senza scelta). I controlli, verdi
 * oggi e da tenere verdi: la stessa estinzione senza la successione (consolidamento di legge), la scelta a mano ignorata nel
 * consolidamento, le cifre già giuste con «tutti, ciascuno per la sua quota» spuntato a mano, e due nude censite su due metà con uno
 * solo dei nudi morto, dove la scelta resta necessaria.
 *
 * Dalla Fase 1-bis della .47, in fondo al file: il testo del rifiuto dell'accrescimento dopo la morte del nudo e la frase del
 * pannello sulla nuda ereditata, con i loro controlli; dal giro sulle correzioni, il rifiuto quando chi esce ha comprato una
 * parte della nuda (il programma si ferma anche senza la spunta), la nuda ereditata e poi venduta da un erede, e la pertinenza.
 *
 * Cosa NON copre: la decisione sull'accrescimento dopo la morte del nudo (decisione 67.2, rapporto 6 §6.4, una domanda aperta: qui
 * solo il testo del rifiuto); tre o più eredi con quote non divisibili; l'annullamento della successione dopo l'estinzione.
 */

use App\Models\Anagrafica;
use App\Models\User;
use App\Services\Subentro\NudiDellEstinzione;
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
function edmnPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'edmn' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'EDMNPERS' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità censita a mano, dal 2019. */
function edmnRiga(int $immobileId, Anagrafica $p, string $ruolo, float $quota): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $immobileId, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Rita e Piero usufruttuari al 50 %, censiti; i nudi dati (di norma Carlo al 100 %). La voce € 1.200,00 sull'«Usufruttuario», dodici
 * rate, emesse fino al 31/3. Con `$box` anche un box pertinenza con gli stessi titolari: millesimi 900 e 100.
 *
 * @param  array<string, float>  $nudi  nome → quota
 * @return array{0: array, 1: array<string, Anagrafica>, 2: array<string, int>, 3: ?\App\Models\Immobile}
 */
function edmnScenario(array $nudi = ['Carlo Nudo' => 100], bool $box = false): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    // Il venditore dello scenario è Piero, e la sua riga è l'usufrutto che finisce.
    $s['v']->forceFill(['nome' => 'Piero Usufruttuario'])->save();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $p = ['piero' => $s['v'], 'rita' => edmnPersona($s, 'Rita Usufruttuaria')];
    $righe = ['piero' => $s['rigaV'], 'rita' => edmnRiga($s['unita']->id, $p['rita'], 'usufruttuario', 50)];
    foreach ($nudi as $nome => $quota) {
        $chiave = strtolower(strtok($nome, ' '));
        $p[$chiave] = edmnPersona($s, $nome);
        $righe[$chiave] = edmnRiga($s['unita']->id, $p[$chiave], 'nuda_proprietario', $quota);
    }
    $pertinenza = null;
    if ($box) {
        $pertinenza = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
        $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
        DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $pertinenza->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
        edmnRiga($pertinenza->id, $p['piero'], 'usufruttuario', 50);
        edmnRiga($pertinenza->id, $p['rita'], 'usufruttuario', 50);
        foreach ($nudi as $nome => $quota) {
            edmnRiga($pertinenza->id, $p[strtolower(strtok($nome, ' '))], 'nuda_proprietario', $quota);
        }
    }
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    return [$s, $p, $righe, $pertinenza];
}

/**
 * Il modulo della successione di un nudo proprietario: gli eredi entrano nudi, ciascuno per la sua quota, con l'arretrato agli eredi.
 *
 * @param  array<int, array{0: Anagrafica, 1: float}>  $eredi
 */
function edmnSuccessione(int $rigaNudo, float $quota, array $eredi, Anagrafica $riferimento, string $decesso, array $pertinenze = []): array
{
    return [
        'tipo' => 'successione', 'riga_uscente_id' => $rigaNudo, 'decorrenza' => $decesso, 'quota' => $quota, 'tipologia' => 'nuda_proprietario',
        'eredi' => array_map(fn ($e) => ['anagrafica_id' => $e[0]->id, 'quota' => $e[1]], $eredi),
        'arretrato' => 'eredi', 'erede_di_riferimento' => $riferimento->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 321', 'pertinenze' => $pertinenze,
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano nudi dal decesso',
    ];
}

/** Il modulo dell'estinzione dell'usufrutto di Piero, il 1/5, senza la casella dell'accrescimento. */
function edmnEstinzione(int $rigaUsufrutto, array $extra = []): array
{
    return array_merge(['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsufrutto, 'decorrenza' => '2026-05-01', 'quota' => 50,
        'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, atto letto'], $extra);
}

/** L'anteprima dalla rotta, senza pretendere che passi: la risposta intera. */
function edmnAnteprima($test, array $s, array $dati): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** La riga di nuda in corso di una persona sull'unità. */
function edmnNuda(int $immobileId, Anagrafica $p): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->where('anagrafica_id', $p->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
}

/**
 * Quanto paga ognuno per la gestione (le quote pure del piano su tutte le unità più le righe di saldo dei passaggi), per nome, senza
 * gli zeri: il «netto» del rapporto.
 */
function edmnNetti(array $s): array
{
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $out[(int) $q->anagrafica_id] = ($out[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->get() as $r) {
        $out[(int) $r->anagrafica_id] = ($out[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($out))->pluck('nome', 'id');
    $nominati = [];
    foreach ($out as $id => $c) {
        if ($c !== 0) {
            $nominati[$nomi[$id]] = $c;
        }
    }
    ksort($nominati);

    return $nominati;
}

/** Le righe di saldo dei passaggi su un'unità, per nome. */
function edmnSaldi(int $immobileId): array
{
    $out = [];
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $immobileId)->get() as $r) {
        $out[(int) $r->anagrafica_id] = ($out[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($out))->pluck('nome', 'id');
    $nominati = [];
    foreach ($out as $id => $c) {
        if ($c !== 0) {
            $nominati[$nomi[$id]] = $c;
        }
    }
    ksort($nominati);

    return $nominati;
}

/** Le righe dell'unità in corso dopo i passaggi: [nome, ruolo, quota, dal]. */
function edmnAperte(int $immobileId): array
{
    return DB::table('anagrafica_immobile')->join('anagrafiche', 'anagrafiche.id', '=', 'anagrafica_immobile.anagrafica_id')
        ->where('anagrafica_immobile.immobile_id', $immobileId)->whereNull('anagrafica_immobile.data_fine')->orderBy('anagrafica_immobile.id')
        ->get(['anagrafiche.nome', 'anagrafica_immobile.tipologia', 'anagrafica_immobile.quota', 'anagrafica_immobile.data_inizio'])
        ->map(fn ($r) => [$r->nome, $r->tipologia, (float) $r->quota, substr((string) $r->data_inizio, 0, 10)])->all();
}

// ─── I controlli: verdi oggi, e da tenere verdi ────────────────────────────────────────────────────────────────────────────────────

it('controllo — senza la successione di Carlo l\'estinzione di Piero si registra da sola: la sua metà torna piena a Carlo (consolidamento di legge), e il conguaglio va a lui', function () {
    [$s, $p, $righe] = edmnScenario();

    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    ruRegistra($this, $s, edmnEstinzione($righe['piero']));

    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
            ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
            ['Carlo Nudo', 'proprietario', 50.0, '2026-05-01'],
            ['Carlo Nudo', 'nuda_proprietario', 50.0, '2026-05-01'],
        ])
        // € 600,00 × 245/365 = € 402,739… → 40274 a Carlo; Piero 60000 − 40274 = 19726; Rita le sue dodici rate, 12 × 5000 = 60000.
        ->and(edmnNetti($s))->toBe(['Carlo Nudo' => 40274, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('controllo — nel consolidamento della nuda sola una scelta mandata a mano non conta: Carlo spuntato (che vale il 100 %, contro un usufrutto del 50 %) non si rifiuta, e la sua metà torna piena per legge', function () {
    [$s, $p, $righe] = edmnScenario();

    // Fuori dal consolidamento la stessa scelta si rifiuterebbe («I nudi proprietari che hai indicato valgono il 100 %…»): qui
    // `NudiDellEstinzione::per` torna il consolidamento prima di guardare la scelta.
    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero'], ['nudi_che_tornano' => [$righe['carlo']]]));
    ruRegistra($this, $s, edmnEstinzione($righe['piero'], ['nudi_che_tornano' => [$righe['carlo']]]));

    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and($an['nudi']['consolida'])->toEqual([(string) $righe['carlo'] => 50])
        // Le stesse cifre del controllo senza scelta: € 600,00 × 245/365 → 40274 a Carlo.
        ->and(edmnNetti($s))->toBe(['Carlo Nudo' => 40274, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('controllo — dopo la morte di Carlo (eredi Anna 60 % e Bruno 40 %), con «tutti, ciascuno per la sua quota» spuntato a mano le cifre sono già quelle giuste: il calcolo c\'è, manca solo che il programma non chieda', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 60], [$bruno, 40]], $anna, '2026-04-01'));
    $nudaAnna = edmnNuda($s['unita']->id, $anna);
    $nudaBruno = edmnNuda($s['unita']->id, $bruno);

    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero'], ['nudi_per_quota' => true]));
    ruRegistra($this, $s, edmnEstinzione($righe['piero'], ['nudi_per_quota' => true]));

    // La metà di Piero per quota della nuda: Anna 50 × 60 % = 30, Bruno 50 × 40 % = 20.
    expect($an['nudi']['consolida'])->toEqual([(string) $nudaAnna => 30, (string) $nudaBruno => 20])
        ->and(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
            ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
            ['Anna Erede', 'proprietario', 30.0, '2026-05-01'],
            ['Anna Erede', 'nuda_proprietario', 30.0, '2026-05-01'],
            ['Bruno Erede', 'proprietario', 20.0, '2026-05-01'],
            ['Bruno Erede', 'nuda_proprietario', 20.0, '2026-05-01'],
        ])
        // 40274 per quota 30/20: Anna 40274 × 0,6 = 24164,4 → 24164; Bruno 40274 × 0,4 = 16109,6 → 16110 (il centesimo al resto
        // maggiore). Piero 60000 − 40274 = 19726, Rita 60000.
        ->and(edmnNetti($s))->toBe(['Anna Erede' => 24164, 'Bruno Erede' => 16110, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('controllo — due nude censite su due metà (Carlo e Dora al 50 %) e muore solo Carlo: il programma non sa quale nuda sta sotto l\'usufrutto di Piero, e la scelta resta necessaria', function () {
    [$s, $p, $righe] = edmnScenario(['Carlo Nudo' => 50, 'Dora Nuda' => 50]);
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 50, [[$anna, 30], [$bruno, 20]], $anna, '2026-04-01'));

    // Anna 30 + Bruno 20 e Dora 50 valgono ciascuno l'usufrutto di Piero (50): la nuda di Carlo non era una sola sotto i due usufrutti,
    // e la correzione di DS1 non deve toccare questo caso (decisione 57: sui titolari censiti a mano sceglie l'amministratore).
    edmnAnteprima($this, $s, edmnEstinzione($righe['piero']))
        ->assertStatus(422)->assertJsonPath('errors.nudi_che_tornano.0', fn ($m) => str_contains($m, 'scegli quali tornano proprietari pieni'));
    ruRegistra($this, $s, edmnEstinzione($righe['piero'], ['nudi_che_tornano' => [$righe['dora']]]));

    // Scelta Dora: tutta la metà di Piero torna a lei, € 600,00 × 245/365 → 40274.
    expect(edmnNetti($s))->toBe(['Dora Nuda' => 40274, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

// ─── DS1: rossi finché il programma non è corretto ─────────────────────────────────────────────────────────────────────────────────

it('DS1, prima forma — muore Carlo, nudo al 100 % (eredi Anna 60 % e Bruno 40 %), poi Piero: l\'estinzione si registra senza chiedere la scelta dei nudi, e la metà di Piero torna piena agli eredi per quota', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 60], [$bruno, 40]], $anna, '2026-04-01'));
    $nudaAnna = edmnNuda($s['unita']->id, $anna);
    $nudaBruno = edmnNuda($s['unita']->id, $bruno);

    // Oggi: «Su questa unità c'è un altro usufrutto in corso, e nessuna combinazione di nudi proprietari interi vale l'usufrutto di
    // Piero Usufruttuario (50 %)…».
    $risposta = edmnAnteprima($this, $s, edmnEstinzione($righe['piero']));
    expect($risposta->json('errors'))->toBeNull();
    $an = $risposta->assertOk()->json();
    ruRegistra($this, $s, edmnEstinzione($righe['piero']));

    // Tornano pieni i due eredi, ciascuno per la sua parte della metà di Piero: 50 × 60 % = 30, 50 × 40 % = 20.
    expect($an['nudi']['righe'])->toEqualCanonicalizing([$nudaAnna, $nudaBruno])
        ->and($an['nudi']['consolida'])->toEqual([(string) $nudaAnna => 30, (string) $nudaBruno => 20])
        ->and(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
            ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
            ['Anna Erede', 'proprietario', 30.0, '2026-05-01'],
            ['Anna Erede', 'nuda_proprietario', 30.0, '2026-05-01'],
            ['Bruno Erede', 'proprietario', 20.0, '2026-05-01'],
            ['Bruno Erede', 'nuda_proprietario', 20.0, '2026-05-01'],
        ])
        // € 600,00 × 245/365 = € 402,739… → 40274, per quota: Anna 40274 × 0,6 = 24164,4 → 24164 (€ 241,64); Bruno 40274 × 0,4 =
        // 16109,6 → 16110 (€ 161,10). Piero 60000 − 40274 = 19726 (€ 197,26); Rita 12 × 5000 = 60000 (€ 600,00).
        ->and(edmnNetti($s))->toBe(['Anna Erede' => 24164, 'Bruno Erede' => 16110, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('DS1, la frase del pannello — dopo la morte di Carlo, l\'anteprima dell\'estinzione di Piero dice che tornano proprietari pieni Anna e Bruno, ciascuno per la sua parte', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 60], [$bruno, 40]], $anna, '2026-04-01'));

    $risposta = edmnAnteprima($this, $s, edmnEstinzione($righe['piero']));
    expect($risposta->json('errors'))->toBeNull();
    $an = $risposta->assertOk()->json();

    // Le parti: 50 × 60 % = 30 e 50 × 40 % = 20, con lo spazio che il programma mette prima di «%» (`percentuale`).
    expect($an['riferimento']['frase'])->toContain('Anna Erede')->toContain('Bruno Erede')->toContain('proprietari pieni')
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Anna Erede per ' . NudiDellEstinzione::percentuale(30))
        ->toContain('Bruno Erede per ' . NudiDellEstinzione::percentuale(20));
});

it('DS1, seconda forma — eredi di Carlo al 50 %: l\'estinzione di Piero si registra senza chiedere «quali tornano proprietari pieni», e il conguaglio va metà ad Anna e metà a Bruno', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));

    // Oggi: «… i nudi proprietari valgono più dell'usufrutto di Piero Usufruttuario (50 %): scegli quali tornano proprietari pieni…».
    expect(edmnAnteprima($this, $s, edmnEstinzione($righe['piero']))->json('errors'))->toBeNull();
    ruRegistra($this, $s, edmnEstinzione($righe['piero']));

    expect(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
        ['Anna Erede', 'proprietario', 25.0, '2026-05-01'],
        ['Anna Erede', 'nuda_proprietario', 25.0, '2026-05-01'],
        ['Bruno Erede', 'proprietario', 25.0, '2026-05-01'],
        ['Bruno Erede', 'nuda_proprietario', 25.0, '2026-05-01'],
    ])
        // 40274 / 2 = 20137 ciascuno (€ 201,37).
        ->and(edmnNetti($s))->toBe(['Anna Erede' => 20137, 'Bruno Erede' => 20137, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('DS1, seconda forma con la scelta di Anna sola — la scelta mandata a mano non conta, come nel consolidamento della nuda sola: Anna non prende tutto il conguaglio, e Bruno non resta nudo per sempre', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));
    $soloAnna = edmnEstinzione($righe['piero'], ['nudi_che_tornano' => [edmnNuda($s['unita']->id, $anna)]]);

    expect(edmnAnteprima($this, $s, $soloAnna)->json('errors'))->toBeNull();
    ruRegistra($this, $s, $soloAnna);

    // Le cifre della seconda forma senza scelta: 40274 / 2 = 20137 ciascuno. Oggi la scelta si registra: Anna 40274, Bruno niente,
    // Anna piena al 50 % e Bruno nudo al 50 % sotto un usufrutto che non c'è più.
    expect(edmnNetti($s))->toBe(['Anna Erede' => 20137, 'Bruno Erede' => 20137, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000])
        ->and(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
            ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
            ['Anna Erede', 'proprietario', 25.0, '2026-05-01'],
            ['Anna Erede', 'nuda_proprietario', 25.0, '2026-05-01'],
            ['Bruno Erede', 'proprietario', 25.0, '2026-05-01'],
            ['Bruno Erede', 'nuda_proprietario', 25.0, '2026-05-01'],
        ]);
});

it('DS1 in cascata — la nuda passa per due successioni (Carlo il 1/3 ad Anna 60 % e Bruno 40 %, Anna il 1/4 a Dora ed Elio per metà): all\'estinzione di Piero tornano pieni Bruno, Dora ed Elio per quota, senza scelta', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    $dora = edmnPersona($s, 'Dora Erede');
    $elio = edmnPersona($s, 'Elio Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 60], [$bruno, 40]], $anna, '2026-03-01'));
    ruRegistra($this, $s, edmnSuccessione(edmnNuda($s['unita']->id, $anna), 60, [[$dora, 30], [$elio, 30]], $dora, '2026-04-01'));

    // Oggi: Bruno 40, Dora 30, Elio 30 contro un usufrutto di 50, «nessuna combinazione di nudi proprietari interi…».
    expect(edmnAnteprima($this, $s, edmnEstinzione($righe['piero']))->json('errors'))->toBeNull();
    ruRegistra($this, $s, edmnEstinzione($righe['piero']));

    // La nuda di Carlo è ora Bruno 40, Dora 30, Elio 30: della metà di Piero, Bruno 50 × 40 % = 20, Dora ed Elio 50 × 30 % = 15.
    expect(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
        ['Bruno Erede', 'proprietario', 20.0, '2026-05-01'],
        ['Bruno Erede', 'nuda_proprietario', 20.0, '2026-05-01'],
        ['Dora Erede', 'proprietario', 15.0, '2026-05-01'],
        ['Dora Erede', 'nuda_proprietario', 15.0, '2026-05-01'],
        ['Elio Erede', 'proprietario', 15.0, '2026-05-01'],
        ['Elio Erede', 'nuda_proprietario', 15.0, '2026-05-01'],
    ])
        // 40274 per quota 20/15/15: Bruno 40274 × 0,4 = 16109,6; Dora ed Elio 40274 × 0,3 = 12082,2. Le parti intere 16109 + 12082 +
        // 12082 = 40273, il centesimo che manca al resto maggiore (Bruno, 0,6): 16110, 12082, 12082.
        ->and(edmnNetti($s))->toBe(['Bruno Erede' => 16110, 'Dora Erede' => 12082, 'Elio Erede' => 12082, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

it('DS1 con il box — la successione di Carlo e l\'estinzione di Piero spuntano anche il box: sul box la nuda in comune fra gli eredi torna piena per quota senza scelta, come sull\'appartamento', function () {
    [$s, $p, $righe, $box] = edmnScenario(box: true);
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 60], [$bruno, 40]], $anna, '2026-04-01', [$box->id]));

    // Sul box la scelta non c'è (la richiesta e `AnteprimaPassaggio` chiamano `NudiDellEstinzione::per` senza): oggi «Box 12: anche
    // lì c'è un altro usufrutto in corso, e il programma non sa quali nudi proprietari tornano proprietari pieni…».
    $risposta = edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['pertinenze' => [$box->id]]));
    expect($risposta->json('errors.pertinenze'))->toBeNull()
        ->and($risposta->json('errors'))->toBeNull();
    ruRegistra($this, $s, edmnEstinzione($righe['piero'], ['pertinenze' => [$box->id]]));

    expect(edmnAperte($box->id))->toEqualCanonicalizing([
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
        ['Anna Erede', 'proprietario', 30.0, '2026-05-01'],
        ['Anna Erede', 'nuda_proprietario', 30.0, '2026-05-01'],
        ['Bruno Erede', 'proprietario', 20.0, '2026-05-01'],
        ['Bruno Erede', 'nuda_proprietario', 20.0, '2026-05-01'],
    ])
        // Appartamento (900 millesimi): € 1.080,00 × 50 % = € 540,00 l'anno a Piero; € 540,00 × 245/365 = € 362,465… → 36247, per
        // quota: Anna 36247 × 0,6 = 21748,2 → 21748, Bruno 36247 × 0,4 = 14498,8 → 14499.
        ->and(edmnSaldi($s['unita']->id))->toBe(['Anna Erede' => 21748, 'Bruno Erede' => 14499, 'Piero Usufruttuario' => -36247])
        // Box (100 millesimi): € 120,00 × 50 % = € 60,00; € 60,00 × 245/365 = € 40,273… → 4027: Anna 4027 × 0,6 = 2416,2 → 2416,
        // Bruno 4027 × 0,4 = 1610,8 → 1611.
        ->and(edmnSaldi($box->id))->toBe(['Anna Erede' => 2416, 'Bruno Erede' => 1611, 'Piero Usufruttuario' => -4027])
        // Insieme: Anna 21748 + 2416 = 24164, Bruno 14499 + 1611 = 16110, Piero 60000 − 36247 − 4027 = 19726, Rita 60000.
        ->and(edmnNetti($s))->toBe(['Anna Erede' => 24164, 'Bruno Erede' => 16110, 'Piero Usufruttuario' => 19726, 'Rita Usufruttuaria' => 60000]);
});

// ─── Fase 1-bis della .47: le frasi del caso DS1 ───────────────────────────────────────────────────────────────────────────────────

// Rilievo «DS1: il rifiuto dell'accrescimento dice che il programma non sa quale usufrutto stia sopra quale nuda, proprio dove consolida
// per quota» (medio, confermato). Non copre: lo sprintf della pertinenza (AnteprimaPassaggioRequest :326), il riquadro della scheda
// (PassaggioNew.vue) e la guida; non rimette in discussione il rifiuto (decisione 67.2), né registra.
it('DS1, il rifiuto dell\'accrescimento dopo la successione di Carlo (eredi Anna e Bruno al 50 %) — resta 422, ma la ragione è la nuda passata a più nudi proprietari con la successione, non «il programma non sa quale usufrutto stia sopra quale nuda»', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));
    $nudaAnna = edmnNuda($s['unita']->id, $anna);
    $nudaBruno = edmnNuda($s['unita']->id, $bruno);

    // Senza la casella il programma sa che la nuda è una sola, in comune, e consolida per quota: della metà di Piero (50) Anna
    // 50 × 50 % = 25 e Bruno 50 × 50 % = 25; 25 + 25 = 50, l'usufrutto che finisce.
    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and($an['nudi']['consolida'])->toEqual([(string) $nudaAnna => 25, (string) $nudaBruno => 25]);

    // Con la casella il rifiuto resta (decisione 67.2). Oggi: «Il 1 maggio 2026 la nuda proprietà di questa unità è di più nudi
    // proprietari (Anna Erede e Bruno Erede), e il programma non sa quale usufrutto stia sopra quale nuda: …».
    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->toContain('Il 1 maggio 2026')
        ->toContain('passata a più nudi proprietari con la successione')
        ->toContain('Anna Erede')->toContain('Bruno Erede')
        ->toContain('Modifica associazione')
        ->not->toContain('non sa quale usufrutto');
});

// Controllo del rilievo qui sopra (verde oggi, da tenere verde): con due nude censite di origini diverse il programma davvero non sa
// quale nuda stia sotto l'usufrutto di Piero, e la ragione di oggi è quella giusta. Non copre: la pertinenza, la scheda, la guida.
it('controllo — due nude censite su due metà (Carlo e Dora al 50 %), muore Carlo: il rifiuto dell\'accrescimento dice ancora che il programma non sa quale usufrutto stia sopra quale nuda, e l\'anteprima con la scelta di Dora non parla di una nuda sola', function () {
    [$s, $p, $righe] = edmnScenario(['Carlo Nudo' => 50, 'Dora Nuda' => 50]);
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    // La nuda di Carlo (50) agli eredi: Anna 30 + Bruno 20 = 50.
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 50, [[$anna, 30], [$bruno, 20]], $anna, '2026-04-01'));

    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->toContain('non sa quale usufrutto stia sopra quale nuda')
        ->toContain('Dora Nuda')->toContain('Anna Erede')->toContain('Bruno Erede')
        ->not->toContain('passata a più nudi proprietari con la successione');

    // Scelta Dora: la sua nuda (50) vale l'usufrutto di Piero (50), torna piena per intero, senza consolidamento parziale.
    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero'], ['nudi_che_tornano' => [$righe['dora']]]));
    expect($an['nudi']['righe'])->toEqual([$righe['dora']])
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Dora Nuda')->not->toContain('viene da una nuda sola');
});

// Rilievo «DS1: il pannello non dice perché non chiede, né la strada a mano per la nuda già divisa» (declassato a rifinitura dallo
// scettico, ma si fa). Non copre: la scheda «Chi torna proprietario pieno», un campo `origine` nel registro, il legato, la cascata di
// due successioni e il box.
it('DS1, la frase della nuda ereditata — dopo la successione di Carlo (eredi Anna e Bruno al 50 %) l\'anteprima dell\'estinzione di Piero dice che la nuda viene da una nuda sola, in comune, e la strada a mano da «Modifica associazione»', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));

    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    // Le parti, come nel rilievo sopra: 50 × 50 % = 25 ciascuno, 25 + 25 = 50.
    expect(implode(' ', $an['anagrafica']['frasi']))->toContain('Anna Erede per ' . NudiDellEstinzione::percentuale(25))
        ->toContain('Bruno Erede per ' . NudiDellEstinzione::percentuale(25));

    // Oggi «Modifica associazione» c'è già, ma nella frase sull'accrescimento («con la nuda di più nudi proprietari non si registra da
    // qui…»): si cerca la frase della nuda sola, e dentro di lei i nomi e la strada a mano.
    $frase = collect($an['anagrafica']['frasi'])->first(fn ($f) => str_contains($f, 'viene da una nuda sola'));
    expect($frase)->not->toBeNull()
        ->and((string) $frase)->toContain('Anna Erede')->toContain('Bruno Erede')
        ->toContain('passata loro con la successione')
        ->toContain('Modifica associazione');
});

// Controllo del rilievo qui sopra (verde oggi, da tenere verde): nel consolidamento della nuda sola senza successione la nuda è di Carlo
// solo, e la frase della nuda ereditata non c'è. Non copre: la nuda venduta a più compratori senza successione.
it('controllo — senza la successione di Carlo l\'anteprima dell\'estinzione di Piero non parla di una nuda passata con la successione', function () {
    [$s, $p, $righe] = edmnScenario();

    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    // La metà di Piero torna piena a Carlo: 50 della sua nuda di 100, il resto (100 − 50 = 50) resta nudo.
    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and($an['nudi']['consolida'])->toEqual([(string) $righe['carlo'] => 50])
        ->and(implode(' ', $an['anagrafica']['frasi']))->toContain('Carlo Nudo')
        ->not->toContain('viene da una nuda sola')
        ->not->toContain('passata loro con la successione');
});

// Fase 1-bis della .47, la variante della frase della nuda ereditata: un erede ha venduto la sua parte prima dell'estinzione. Non copre:
// le cifre del conguaglio (le provano i test di DS1 qui sopra), due vendite di fila, la vendita di una parte della parte.
it('DS1, la frase della nuda ereditata e poi venduta — dopo la successione di Carlo (Anna e Bruno al 50 %) Anna vende la sua nuda a Marco: l\'anteprima dell\'estinzione di Piero dice che la nuda viene da una nuda sola, passata con la successione e con le vendite registrate dopo', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    $marco = edmnPersona($s, 'Marco Compratore');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));
    ruRegistra($this, $s, ['tipologia' => 'nuda_proprietario'] + ruPassaggio('vendita', edmnNuda($s['unita']->id, $anna), $marco, '2026-04-15', 50));

    // La metà di Piero (50) torna piena per quota: Marco 50 × 50 % = 25, Bruno 50 × 50 % = 25; 25 + 25 = 50.
    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and($an['nudi']['consolida'])->toEqual([(string) edmnNuda($s['unita']->id, $marco) => 25, (string) edmnNuda($s['unita']->id, $bruno) => 25]);

    $frase = (string) collect($an['anagrafica']['frasi'])->first(fn ($f) => str_contains($f, 'viene da una nuda sola'));
    expect($frase)->toContain('Marco Compratore')->toContain('Bruno Erede')
        ->toContain('con la successione e con le vendite registrate dopo')
        ->not->toContain('Anna Erede');
});

// ─── Giro sulle correzioni della Fase 1-bis della .47 ──────────────────────────────────────────────────────────────────────────────

/** La riga di usufrutto in corso di una persona su un'unità (sul box, la sua). */
function edmnUsufrutto(int $immobileId, Anagrafica $p): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->where('anagrafica_id', $p->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
}

// Rilievo «DS1: nudaInComune guarda anche la nuda di chi esce» (basso, caso β dello scettico 8). Non copre: la scheda (PassaggioNew.vue,
// dove la casella non compare con più nudi), la guida, la pertinenza; non rimette in discussione il rifiuto (decisione 67.2), né registra
// l'estinzione.
it('DS1, il rifiuto dell\'accrescimento quando chi esce ha comprato la nuda di un erede — Anna vende a Piero la nuda ereditata da Carlo: senza la spunta il programma si ferma, e il rifiuto non promette il consolidamento per quota né dice che Piero l\'ha avuta con la successione', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));
    ruRegistra($this, $s, ruPassaggio('nuda', edmnNuda($s['unita']->id, $anna), $p['piero'], '2026-04-15', 50));

    // La nuda di Carlo (100): Bruno 50 dalla successione, Piero 50 comprato da Anna; 50 + 50 = 100. Gli usufrutti: Piero 50 + Rita 50 = 100.
    expect(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
        ['Piero Usufruttuario', 'usufruttuario', 50.0, '2019-01-01'],
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
        ['Bruno Erede', 'nuda_proprietario', 50.0, '2026-04-01'],
        ['Piero Usufruttuario', 'nuda_proprietario', 50.0, '2026-04-15'],
    ]);

    // Controllo, verde oggi: senza la spunta il programma si ferma (Piero ha anche una nuda), e manda a mano.
    $fermo = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero']))->assertStatus(422)->json('errors.estinzione.0');
    expect($fermo)->toContain('il programma non sa quale nuda proprietà torna piena')
        ->toContain('Registra l\'estinzione a mano');

    // Il difetto: oggi «… è passata a più nudi proprietari con la successione (Bruno Erede e Piero Usufruttuario): … altrimenti togli
    // la spunta dell'accrescimento, e la parte di Piero Usufruttuario torna piena ai nudi proprietari, a ciascuno per la sua quota.»
    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->not->toContain('per la sua quota')
        ->and($messaggio)->not->toContain('con la successione (Bruno Erede e Piero Usufruttuario)')
        ->and($messaggio)->not->toMatch('/con la successione \([^)]*Piero Usufruttuario/u')
        ->and($messaggio)->toContain('Modifica associazione')
        ->and($messaggio)->toContain('senza la spunta il programma si ferma');
});

// Controllo dello stesso rilievo (rosso oggi: il difetto c'era già prima della .47, fuori dalla DS1). Due nude censite senza successione,
// Dora vende la sua a Piero. Non copre: la pertinenza, la scheda, la guida; il caso del registro (riserva d'usufrutto).
it('controllo — Carlo e Dora nudi censiti al 50 %, Dora vende la sua nuda a Piero: il rifiuto dell\'accrescimento non promette che «torna alla nuda come vuole la legge», perché senza la spunta il programma si ferma', function () {
    [$s, $p, $righe] = edmnScenario(['Carlo Nudo' => 50, 'Dora Nuda' => 50]);
    ruRegistra($this, $s, ruPassaggio('nuda', $righe['dora'], $p['piero'], '2026-04-15', 50));

    // La nuda: Carlo 50 + Piero 50 = 100, sotto Piero 50 + Rita 50 = 100.
    expect(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
        ['Piero Usufruttuario', 'usufruttuario', 50.0, '2019-01-01'],
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2019-01-01'],
        ['Carlo Nudo', 'nuda_proprietario', 50.0, '2019-01-01'],
        ['Piero Usufruttuario', 'nuda_proprietario', 50.0, '2026-04-15'],
    ]);

    // Controllo, verde oggi: senza la spunta lo stesso fermo.
    expect((string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero']))->assertStatus(422)->json('errors.estinzione.0'))
        ->toContain('il programma non sa quale nuda proprietà torna piena');

    // Il difetto: oggi la frase generica «… altrimenti togli la spunta dell'accrescimento, e la parte di Piero Usufruttuario torna alla
    // nuda come vuole la legge negli atti fra vivi.»
    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->not->toContain('torna alla nuda come vuole la legge')
        ->and($messaggio)->toContain('Modifica associazione')
        ->and($messaggio)->toContain('senza la spunta il programma si ferma');
});

// Stesso rilievo, la nuda ereditata e venduta a un terzo (`ereditata` = 'successione_e_vendite' in NudiDellEstinzione::per). Non copre:
// le cifre del conguaglio (test «la frase della nuda ereditata e poi venduta»), due vendite di fila, la pertinenza.
it('DS1, il rifiuto dell\'accrescimento dopo la successione di Carlo e la vendita della nuda di Anna a Marco — la ragione dice «con la successione e con le vendite registrate dopo», non «con la successione» anche per chi ha comprato', function () {
    [$s, $p, $righe] = edmnScenario();
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    $marco = edmnPersona($s, 'Marco Compratore');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01'));
    ruRegistra($this, $s, ruPassaggio('nuda', edmnNuda($s['unita']->id, $anna), $marco, '2026-04-15', 50));

    // Controllo, verde oggi: senza la spunta la metà di Piero (50) torna piena per quota, Marco 50 × 50 % = 25 e Bruno 50 × 50 % = 25.
    $an = ruAnteprima($this, $s, edmnEstinzione($righe['piero']));
    expect($an['nudi']['da'])->toBe('consolidamento')
        ->and($an['nudi']['consolida'])->toEqual([(string) edmnNuda($s['unita']->id, $marco) => 25, (string) edmnNuda($s['unita']->id, $bruno) => 25]);

    // Il difetto: oggi «… è passata a più nudi proprietari con la successione (Bruno Erede e Marco Compratore): …».
    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->toContain('con la successione e con le vendite registrate dopo')
        ->and($messaggio)->toContain('Marco Compratore')
        ->and($messaggio)->toContain('Bruno Erede')
        ->and($messaggio)->toContain('Modifica associazione')
        ->and($messaggio)->not->toContain('senza la spunta il programma si ferma');
});

// Rilievo «DS1: la frase della pertinenza manda a registrare dalla pertinenza un accrescimento che lì viene rifiutato allo stesso modo»
// (basso, caso γ). Non copre: la frase dell'appartamento, la scheda, la guida; non registra.
it('DS1 con il box, il rifiuto dell\'accrescimento sulla pertinenza — dopo la successione di Carlo (Anna e Bruno al 50 %, box compreso) la frase del box non manda a registrare l\'estinzione dalla pertinenza, dove l\'accrescimento si rifiuta allo stesso modo', function () {
    [$s, $p, $righe, $box] = edmnScenario(box: true);
    $anna = edmnPersona($s, 'Anna Erede');
    $bruno = edmnPersona($s, 'Bruno Erede');
    ruRegistra($this, $s, edmnSuccessione($righe['carlo'], 100, [[$anna, 50], [$bruno, 50]], $anna, '2026-04-01', [$box->id]));

    // Controllo, verde oggi: dal box l'accrescimento si rifiuta con la stessa ragione; la strada che la frase indica è un giro a vuoto.
    $dalBox = (string) $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $box]),
        edmnEstinzione(edmnUsufrutto($box->id, $p['piero']), ['accrescimento' => true]))->assertStatus(422)->json('errors.accrescimento.0');
    expect($dalBox)->toContain('non si registra da qui')
        ->toContain('Modifica associazione');

    // Il difetto: oggi «Box 12: … è passata a più nudi proprietari con la successione (Anna Erede e Bruno Erede), e l'accrescimento non
    // si registra da qui: togli la spunta della pertinenza e registra la sua estinzione dalla pertinenza.»
    $pertinenze = implode(' ', (array) edmnAnteprima($this, $s, edmnEstinzione($righe['piero'], ['accrescimento' => true, 'pertinenze' => [$box->id]]))
        ->assertStatus(422)->json('errors.pertinenze'));
    expect($pertinenze)->toContain('Box 12')
        ->and($pertinenze)->not->toContain('registra la sua estinzione dalla pertinenza')
        ->and($pertinenze)->toContain('dalla pertinenza l\'accrescimento non si registra')
        ->and($pertinenze)->toContain('Modifica associazione');
});

// ─── Secondo giro sulle correzioni della .47 ───────────────────────────────────────────────────────────────────────────────────────

// Rilievo basso «Rifiuto dell'accrescimento con il registro (DA_REGISTRO) e più nudi» (secondo giro, lente testi). NON copre: la riserva
// seguita dalla successione del nudo (nudi dal registro con la consolida per quota, variante 2 della sonda), la pertinenza, la guida
// (PassaggioProprietaGuide.vue:118), la scheda (dove con più nudi la casella non c'è); non rimette in discussione il rifiuto (decisione
// 67.2), né registra l'estinzione.
it('DS1, il rifiuto dell\'accrescimento con l\'usufrutto nato da una riserva registrata — Piero e Rita pieni al 50 % riservano l\'usufrutto il 1/6/2025, donando la nuda ad Anna e a Bruno: il rifiuto non dice che il programma non sa quale usufrutto stia sopra quale nuda, e la parte di Piero torna piena ad Anna, come dice il passaggio da cui è nato il suo usufrutto', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $s['v']->forceFill(['nome' => 'Piero Usufruttuario'])->save();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rita = edmnPersona($s, 'Rita Usufruttuaria');
    $rigaRita = edmnRiga($s['unita']->id, $rita, 'proprietario', 50);
    $anna = edmnPersona($s, 'Anna Nuda');
    $bruno = edmnPersona($s, 'Bruno Nudo');
    ruRegistra($this, $s, ruPassaggio('riserva', $s['rigaV'], $anna, '2025-06-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Donazione letta: Piero dona ad Anna la nuda della sua metà, con riserva d\'usufrutto']);
    ruRegistra($this, $s, ruPassaggio('riserva', $rigaRita, $bruno, '2025-06-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Donazione letta: Rita dona a Bruno la nuda della sua metà, con riserva d\'usufrutto']);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: riserve del 2025', esercizio: $s['e']);
    ruEmetti($s, '2026-03-31');

    // Le due riserve: usufrutti 50 + 50 = 100 sopra le nude 50 + 50 = 100, ciascuna nata da un passaggio registrato da qui.
    expect(edmnAperte($s['unita']->id))->toEqualCanonicalizing([
        ['Piero Usufruttuario', 'usufruttuario', 50.0, '2025-06-01'],
        ['Rita Usufruttuaria', 'usufruttuario', 50.0, '2025-06-01'],
        ['Anna Nuda', 'nuda_proprietario', 50.0, '2025-06-01'],
        ['Bruno Nudo', 'nuda_proprietario', 50.0, '2025-06-01'],
    ]);
    $usufruttoPiero = edmnUsufrutto($s['unita']->id, $s['v']);

    // Controllo, verde oggi: senza la spunta il programma sa dal registro che sotto l'usufrutto di Piero sta la nuda di Anna.
    $an = ruAnteprima($this, $s, edmnEstinzione($usufruttoPiero));
    expect($an['nudi']['da'])->toBe(NudiDellEstinzione::DA_REGISTRO)
        ->and($an['nudi']['righe'])->toEqual([edmnNuda($s['unita']->id, $anna)]);

    // Il difetto: oggi «… è di più nudi proprietari (Anna Nuda e Bruno Nudo), e il programma non sa quale usufrutto stia sopra quale
    // nuda: … altrimenti togli la spunta dell'accrescimento, e la parte di Piero Usufruttuario torna alla nuda come vuole la legge negli
    // atti fra vivi.»
    $messaggio = (string) edmnAnteprima($this, $s, edmnEstinzione($usufruttoPiero, ['accrescimento' => true]))
        ->assertStatus(422)->json('errors.accrescimento.0');
    expect($messaggio)->not->toContain('non sa quale usufrutto')
        ->and($messaggio)->toContain('Anna Nuda')
        ->and($messaggio)->toContain('come dice il passaggio da cui è nato il suo usufrutto')
        ->and($messaggio)->toContain('Il 1 maggio 2026 la nuda proprietà di questa unità è di più nudi proprietari (Anna Nuda e Bruno Nudo), e l\'accrescimento all\'altro usufruttuario non si registra da qui. Se l\'atto prevede l\'accrescimento, o l\'usufrutto è un legato a più persone insieme (artt. 675 e 678 c.c.), correggi le righe a mano da «Modifica associazione», senza conguaglio automatico; altrimenti togli la spunta dell\'accrescimento: la parte di Piero Usufruttuario torna piena ad Anna Nuda, come dice il passaggio da cui è nato il suo usufrutto.');
});
