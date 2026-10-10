<?php

/**
 * 1.11.0-beta.47, giro sulle correzioni: la successione di chi ha ancora un usufrutto in corso sull'unità il giorno del decesso.
 *
 * Da dove nasce: il rilievo alto del secondo giro (lente denaro, confermato dallo scettico) «con l'arretrato agli eredi, la
 * successione registrata prima dell'estinzione conta due volte i giorni dopo il decesso della riga dell'usufrutto». Sull'unità
 * mista (Ugo pieno al 50 % e usufruttuario dell'altro 50 %, Bice nuda di quel 50 %) Ugo muore il 1/7. Registrata per prima, la
 * successione della metà piena con l'arretrato agli eredi passa a Carla tutta la posizione di Ugo, compresi i 184 giorni dopo il
 * decesso della riga dell'usufrutto; l'estinzione registrata dopo fa pagare a Bice quegli stessi giorni e dà il credito al defunto.
 * Carla paga € 302,47 di troppo, a Ugo resta un credito di € 302,47, e il pannello aveva detto «la posizione di Venditore Ugo si
 * chiude». L'usufrutto si estingue con la morte (art. 979 c.c.): un defunto non «tiene» l'usufrutto.
 *
 * La correzione (decisioni 61 e 66.2: il programma si ferma e indica la via): la successione si ferma quando chi esce ha,
 * sull'unità o su una pertinenza spuntata, una riga «usufruttuario» in corso il giorno del decesso, qualunque sia la scelta
 * dell'arretrato. La frase, sulla pertinenza preceduta dal suo nome e «: »: «<Nome> risulta ancora usufruttuario di questa unità
 * il <giorno>: l'usufrutto si estingue con la morte (art. 979 c.c.). Registra prima «Usufrutto → estinzione» con la stessa data,
 * poi la successione.»
 *
 * Lo scenario (T3 dello scettico): «Spese generali» € 1.200,00 l'anno tutta sull'«Inquilino», dodici rate dal 5 gennaio, 365
 * giorni; la locazione del DL5 (Ines fino al 31/1, Luca dal 1/3 al 31/5), piano generato, rate emesse fino al 30/6. Il riparto
 * (pesi quota × giorni su 36500): Ines 100 × 31, Luca 100 × 92, il ripiego di chi gode l'unità, febbraio 28 + dal 1/6 al 31/12
 * 214 = 242 giorni, in due righe gemelle 50 × 242. € 1.200,00 × 3100/36500 = 10191,78; × 9200/36500 = 30246,58; × 12100/36500
 * = 39780,82 per riga gemella; resti maggiori 10192 + 30246 + 39781 + 39781 = 120000.
 *
 * Cosa presidia: la fermata nei due modi dell'arretrato (a); le cifre dell'ordine giusto, estinzione e poi successione con
 * l'arretrato agli eredi (b, verde oggi); i controlli che la fermata non scatti con l'usufrutto già chiuso il 30/6 e l'arretrato
 * al defunto, né quando l'usufrutto in corso è di un'altra persona (c, verdi oggi); la stessa fermata sulla pertinenza spuntata
 * (d), con il suo nome davanti.
 *
 * Cosa NON copre: la frase «ha già ceduto con un passaggio precedente (l'usufrutto)» dell'ordine giusto (ritocco 2 dello
 * scettico, basso: un usufrutto estinto non è «ceduto»); il prospetto degli oneri accessori con Ivo dal 1/7; la successione
 * del nudo proprietario (lì chi esce non ha l'usufrutto: la provano SuccessioneTest e EstinzioneDopoMorteDelNudoTest); la
 * riga d'usufrutto con una data di fine scritta a mano dopo il decesso; la scheda (PassaggioNew.vue) e la guida.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
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

/** La frase della fermata, per chi esce e il giorno del decesso, come la vuole la correzione. */
function scufFrase(string $nome, string $il): string
{
    return sprintf('%s risulta ancora usufruttuario di questa unità il %s: l\'usufrutto si estingue con la morte (art. 979 c.c.). Registra prima «Usufrutto → estinzione» con la stessa data, poi la successione.', $nome, $il);
}

/** Una persona del condominio dello scenario. */
function scufPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'scuf' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SCUFPERS' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità censita dal 2019, sull'unità dello scenario o sull'immobile dato. */
function scufTitolare(array $s, Anagrafica $p, string $ruolo, float $quota, ?int $immobileId = null): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $immobileId ?? $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** Un passaggio dalla rotta vera, con la spunta del cancello e la sua nota. */
function scufRegistra($test, array $s, array $dati, string $nota): Subentro
{
    return ruRegistra($test, $s, $dati + ['ho_letto' => true, 'nota_cancello' => $nota]);
}

/**
 * Lo scenario T3: `ruScenario` con la voce tutta sull'«Inquilino»; Ugo pieno al 50 %; l'usufrutto dell'altro 50 % a Ugo (l'unità
 * mista) o, con `$usufruttuario`, a quella persona; Bice nuda di quel 50 %; la locazione del DL5 dalle rotte vere (Ines censita
 * dal 2019 fino al 31/1, Luca dal 1/3 al 31/5); piano generato con la presa d'atto; rate emesse fino al 30/6.
 *
 * @return array{0: array, 1: array<string, Anagrafica>, 2: array<string, int>} lo scenario, le persone, le righe di Ugo
 */
function scufScenario($test, ?string $usufruttuario = null): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $p = ['ugo' => $s['v']];
    $chiGode = $usufruttuario === null ? $s['v'] : ($p['usufruttuario'] = scufPersona($s, $usufruttuario));
    $righe = ['pieno' => $s['rigaV'], 'usufrutto' => scufTitolare($s, $chiGode, 'usufruttuario', 50)];
    $p['bice'] = scufPersona($s, 'Bice Nuda');
    scufTitolare($s, $p['bice'], 'nuda_proprietario', 50);
    $p['ines'] = scufPersona($s, 'Ines Inquilina');
    scufTitolare($s, $p['ines'], 'inquilino', 100);
    $fine = fn (Anagrafica $chi, string $il) => scufRegistra($test, $s, ['tipo' => 'fine_locazione', 'decorrenza' => $il, 'quota' => 100, 'tipologia' => 'inquilino',
        'riga_uscente_id' => (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $chi->id)->where('tipologia', 'inquilino')->whereNull('data_fine')->value('id'),
        'anagrafica_entrante_id' => null, 'copia_autentica' => false, 'pertinenze' => []], 'Disdetta del contratto letta');
    $fine($p['ines'], '2026-02-01');
    $p['luca'] = scufPersona($s, 'Luca Secondo');
    scufRegistra($test, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $p['luca']->id, 'decorrenza' => '2026-03-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => []], 'Contratto di locazione letto');
    $fine($p['luca'], '2026-06-01');
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: inquilini cambiati nell\'anno', esercizio: $s['e']);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $p['carla'] = scufPersona($s, 'Carla Erede');

    return [$s, $p, $righe];
}

/** Il modulo della successione della metà piena di Ugo il 1/7: Carla erede unica (e di riferimento) della sua quota. */
function scufSuccessione(array $s, Anagrafica $erede, string $arretrato, array $pertinenze = [], float $quota = 50): array
{
    return [
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-07-01', 'quota' => $quota, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $erede->id, 'quota' => $quota]], 'arretrato' => $arretrato, 'erede_di_riferimento' => $erede->id,
        // 1.11.0-beta.48, decisione 72: con l'arretrato a nome del defunto il conguaglio si sceglie; qui si scrive, come prima.
        'conguaglio' => $arretrato === 'defunto' ? 'scrivi' : null,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 47', 'pertinenze' => $pertinenze,
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: Carla erede della metà piena di Ugo',
    ];
}

/** Il modulo dell'estinzione dell'usufrutto di Ugo il 1/7, lo stesso giorno del decesso. */
function scufEstinzione(int $rigaUsufrutto): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsufrutto, 'decorrenza' => '2026-07-01', 'quota' => 50,
        'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Estinzione per morte dell\'usufruttuario: certificato di morte letto'];
}

/** L'anteprima dalla rotta, senza pretendere che passi: la risposta intera. */
function scufAnteprima($test, array $s, array $dati): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** Tutti i messaggi d'errore di una risposta JSON, di qualunque campo, in un elenco. */
function scufErrori(\Illuminate\Testing\TestResponse $risposta): array
{
    return collect((array) $risposta->json('errors'))->flatten()->map(fn ($m) => (string) $m)->values()->all();
}

/** Le righe di saldo di un passaggio: nome → centesimi, sommate per persona, in ordine di nome. */
function scufSaldiDi(Subentro $passaggio): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return DB::table('saldi')->where('subentro_id', $passaggio->id)->get()->groupBy('anagrafica_id')
        ->mapWithKeys(fn ($g, $id) => [$nomi[(int) $id] ?? '(unità)' => (int) $g->sum('saldo_iniziale')])->sortKeys()->all();
}

/** Ciò che ciascuno paga del piano sull'unità: la quota pura delle sue quote più le righe dei passaggi. Senza gli zeri, per nome. */
function scufNetti(array $s): array
{
    $netti = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $netti[(int) $q->anagrafica_id] = ($netti[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $r) {
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

// ─── (a) La fermata: rossa finché il programma non è corretto ──────────────────────────────────────────────────────────────────────

// Rilievo alto «con l'arretrato agli eredi, la successione registrata prima dell'estinzione conta due volte i giorni dopo il decesso
// della riga dell'usufrutto» (scettico: confermato, peso alto), la fermata larga del ritocco 1. NON copre: la pertinenza (test d), la
// frase dell'ordine giusto, l'estinzione registrata dopo una successione già scritta (con la fermata non si arriva più lì).
it('(a) unità mista, Ugo muore il 1/7 con l\'usufrutto ancora in corso: la successione della sua metà piena a Carla si ferma e manda a registrare prima «Usufrutto → estinzione», qualunque sia l\'arretrato', function (string $arretrato) {
    [$s, $p, $righe] = scufScenario($this);

    // Lo scenario è quello giusto: Ugo con le due righe gemelle 39781 + 39781 = 79562, Ines 10192, Luca 30246;
    // 79562 + 10192 + 30246 = 120000.
    expect(scufNetti($s))->toBe(['Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 79562]);

    // Oggi l'anteprima passa (200): con l'arretrato agli eredi dà a Carla 79562, cioè la coppia 30247 più un arretrato 49315 che
    // comprende i 184 giorni dopo il decesso della riga dell'usufrutto (39781 × 184/242 = 30246,71 → 30247), che l'estinzione
    // registrata dopo fa pagare anche a Bice.
    $risposta = scufAnteprima($this, $s, scufSuccessione($s, $p['carla'], $arretrato));
    $errori = scufErrori($risposta);
    $tutti = implode(' | ', $errori);
    expect($risposta->status())->toBe(422)
        ->and($tutti)->toContain('risulta ancora usufruttuario')
        ->and($tutti)->toContain('979')
        ->and($tutti)->toContain('Usufrutto → estinzione')
        ->and($errori)->toContain(scufFrase('Venditore Ugo', '1 luglio 2026'));

    // La registrazione dalla rotta si ferma allo stesso modo, e non scrive niente: né saldi, né passaggi, né righe.
    $saldiPrima = DB::table('saldi')->count();
    $passaggiPrima = Subentro::count();
    $righePrima = DB::table('anagrafica_immobile')->orderBy('id')->get(['id', 'tipologia', 'quota', 'data_fine'])->toArray();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), scufSuccessione($s, $p['carla'], $arretrato))
        ->assertSessionHasErrors();
    $registrazione = implode(' | ', collect(session('errors')?->getBag('default')->all() ?? [])->all());
    expect($registrazione)->toContain(scufFrase('Venditore Ugo', '1 luglio 2026'))
        ->and(DB::table('saldi')->count())->toBe($saldiPrima)
        ->and(DB::table('saldi')->where('anagrafica_id', $p['carla']->id)->count())->toBe(0)
        ->and(Subentro::count())->toBe($passaggiPrima)
        ->and(DB::table('anagrafica_immobile')->orderBy('id')->get(['id', 'tipologia', 'quota', 'data_fine'])->toArray())->toEqual($righePrima);
})->with(['arretrato agli eredi' => 'eredi', 'arretrato a nome del defunto' => 'defunto']);

// ─── (b) L'ordine giusto: verde oggi, da tenere verde ──────────────────────────────────────────────────────────────────────────────

// Controllo dello stesso rilievo (verde oggi): estinzione e poi successione, entrambe il 1/7, con l'arretrato agli eredi. Le cifre
// giuste, che la fermata indica come strada. NON copre: la frase «ha già ceduto con un passaggio precedente (l'usufrutto)» (ritocco 2,
// basso), il prospetto degli oneri accessori, l'annullamento dei due passaggi.
it('(b) controllo — prima l\'estinzione dell\'usufrutto di Ugo, poi la successione della metà piena a Carla con l\'arretrato agli eredi: Bice 30247, Carla 49315, Ugo 0, e la posizione di Ugo si chiude davvero', function () {
    [$s, $p, $righe] = scufScenario($this);

    // L'estinzione: la riga dell'usufrutto dal 1/7, 39781 × 184/242 = 30246,71 → 30247, a Bice che torna piena; credito a Ugo.
    $estinzione = scufRegistra($this, $s, scufEstinzione($righe['usufrutto']), 'Estinzione per morte dell\'usufruttuario: certificato di morte letto');
    expect(scufSaldiDi($estinzione))->toBe(['Bice Nuda' => 30247, 'Venditore Ugo' => -30247]);
    $fonte = (int) DB::table('saldi')->where('subentro_id', $estinzione->id)->where('anagrafica_id', $p['ugo']->id)->value('id');

    // La successione non si ferma: l'usufrutto di Ugo è chiuso al 30/6.
    $risposta = scufAnteprima($this, $s, scufSuccessione($s, $p['carla'], 'eredi'));
    expect($risposta->json('errors'))->toBeNull();
    $an = $risposta->assertOk()->json();
    // L'arretrato: la posizione di Ugo 79562 (le sue quote, che non passano) − 30247 (il saldo dell'estinzione, la sua fonte) = 49315
    // lasciato aperto; meno la coppia della riga del pieno 30247 = 19068.
    expect($an['rate']['arretrato']['totale'])->toBe(19068)
        ->and($an['rate']['arretrato']['fonti'])->toBe([$fonte])
        ->and($an['rate']['arretrato']['frase'])->toContain('€ 190,68')
        ->and($an['rate']['arretrato']['frase'])->toContain('ha lasciato aperto, € 493,15, e la posizione di Venditore Ugo si chiude');

    // Carla: coppia 30247 + arretrato 19068 = 49315; Ugo −49315.
    $successione = scufRegistra($this, $s, scufSuccessione($s, $p['carla'], 'eredi'), 'Dichiarazione di successione letta: Carla erede della metà piena di Ugo');
    expect(scufSaldiDi($successione))->toBe(['Carla Erede' => 49315, 'Venditore Ugo' => -49315])
        // Ugo 79562 − 30247 − 49315 = 0, quindi assente. 30247 + 49315 + 10192 + 30246 = 120000.
        ->and(scufNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 49315, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246]);
});

// ─── (c) Dove la fermata non deve scattare: verdi oggi, da tenere verdi ────────────────────────────────────────────────────────────

// Controllo dello stesso rilievo (verde oggi): l'usufrutto chiuso il 30/6 dall'estinzione, e l'arretrato a nome del defunto. NON copre:
// una riga d'usufrutto chiusa a mano, senza passaggio; la stessa forma con l'arretrato agli eredi (test b).
it('(c) controllo — usufrutto di Ugo chiuso il 30/6 dall\'estinzione, poi la successione della metà piena con l\'arretrato a nome del defunto: nessuna fermata, Ugo 19068, Carla 30247, Bice 30247', function () {
    [$s, $p, $righe] = scufScenario($this);
    scufRegistra($this, $s, scufEstinzione($righe['usufrutto']), 'Estinzione per morte dell\'usufruttuario: certificato di morte letto');
    expect(DB::table('anagrafica_immobile')->where('id', $righe['usufrutto'])->value('data_fine'))->toStartWith('2026-06-30');

    $risposta = scufAnteprima($this, $s, scufSuccessione($s, $p['carla'], 'defunto'));
    expect($risposta->json('errors'))->toBeNull()
        ->and(implode(' ', scufErrori($risposta)))->not->toContain('risulta ancora usufruttuario');
    // A nome del defunto resta 79562 − 30247 (estinzione) − 30247 (coppia) = 19068.
    expect($risposta->assertOk()->json('rate.arretrato.resta'))->toBe(19068);

    $successione = scufRegistra($this, $s, scufSuccessione($s, $p['carla'], 'defunto'), 'Dichiarazione di successione letta: Carla erede della metà piena di Ugo');
    // Solo la coppia: 39781 × 184/242 = 30246,71 → 30247.
    expect(scufSaldiDi($successione))->toBe(['Carla Erede' => 30247, 'Venditore Ugo' => -30247])
        // Ugo 79562 − 30247 − 30247 = 19068; 19068 + 30247 + 30247 + 10192 + 30246 = 120000.
        ->and(scufNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 19068]);
});

// Controllo dello stesso rilievo (verde oggi): il defunto non ha l'usufrutto; sull'unità c'è un usufrutto in corso, ma di Rita. La
// fermata guarda solo chi esce. NON copre: Rita che muore (è l'estinzione), Ugo usufruttuario su un'altra unità del condominio.
it('(c) controllo — Ugo pieno al 50 %, l\'usufrutto dell\'altra metà è di Rita: la successione di Ugo con l\'arretrato agli eredi non si ferma, e Carla prende tutta la posizione di Ugo', function () {
    [$s, $p, $righe] = scufScenario($this, 'Rita Usufruttuaria');

    // Il ripiego dei 242 giorni sta sulle due righe del godimento, Ugo pieno e Rita usufruttuaria: 39781 ciascuno;
    // 39781 + 39781 + 10192 + 30246 = 120000.
    expect(scufNetti($s))->toBe(['Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Rita Usufruttuaria' => 39781, 'Venditore Ugo' => 39781]);

    $risposta = scufAnteprima($this, $s, scufSuccessione($s, $p['carla'], 'eredi'));
    expect($risposta->json('errors'))->toBeNull()
        ->and(implode(' ', scufErrori($risposta)))->not->toContain('risulta ancora usufruttuario');
    $risposta->assertOk();

    $successione = scufRegistra($this, $s, scufSuccessione($s, $p['carla'], 'eredi'), 'Dichiarazione di successione letta: Carla erede della metà piena di Ugo');
    // Qui le quote di Ugo sono sue sole (quelle di Rita stanno a parte), e le sei bozze da luglio passano a Carla: 6 × 3315 = 19890.
    // La parte di Carla sul piano, 39781 × 184/242 = 30246,71 → 30247, meno le bozze: coppia 30247 − 19890 = 10357. L'arretrato: le
    // sei quote emesse di Ugo, 3316 + 5 × 3315 = 19891, più le bozze 19890 = 39781, meno le bozze che Carla paga 19890, meno la
    // coppia 10357 = 9534. Righe di saldo: 10357 + 9534 = 19891.
    expect(scufSaldiDi($successione))->toBe(['Carla Erede' => 19891, 'Venditore Ugo' => -19891])
        // Carla 19890 (bozze) + 19891 (saldi) = 39781, tutta la posizione di Ugo; Ugo 19891 (emesse) − 19891 = 0, assente.
        // 39781 + 10192 + 30246 + 39781 = 120000.
        ->and(scufNetti($s))->toBe(['Carla Erede' => 39781, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Rita Usufruttuaria' => 39781]);
});

// ─── (d) La pertinenza spuntata: rosso finché il programma non è corretto ──────────────────────────────────────────────────────────

// Rilievo alto qui sopra, la fermata sulle pertinenze spuntate (ritocco 1: «si guardano l'unità e le pertinenze spuntate, le stesse
// che legge l'arretrato»). NON copre: le cifre sul box, la pertinenza non spuntata con l'usufrutto ancora in corso (lì resta la riga
// del box, e non la legge l'arretrato), la scheda.
it('(d) la pertinenza — Ugo pieno al 50 % dell\'appartamento e del Box 12, e sul box anche usufruttuario dell\'altra metà: la successione con il box spuntato si ferma con il nome del box davanti; senza il box non si ferma', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $bice = scufPersona($s, 'Bice Piena');
    scufTitolare($s, $bice, 'proprietario', 50);
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    scufTitolare($s, $s['v'], 'proprietario', 50, $box->id);
    scufTitolare($s, $s['v'], 'usufruttuario', 50, $box->id);
    scufTitolare($s, scufPersona($s, 'Dora Nuda'), 'nuda_proprietario', 50, $box->id);
    $carla = scufPersona($s, 'Carla Erede');

    // Controllo, verde oggi: senza il box l'appartamento non ha usufrutti, e la successione non si ferma.
    $senzaBox = scufAnteprima($this, $s, scufSuccessione($s, $carla, 'eredi'));
    expect(implode(' ', scufErrori($senzaBox)))->not->toContain('risulta ancora usufruttuario');
    $senzaBox->assertOk();

    // Con il box spuntato: oggi 200. La frase, una volta, con il nome del box; nessuna frase sull'appartamento.
    $conBox = scufAnteprima($this, $s, scufSuccessione($s, $carla, 'eredi', [$box->id]));
    $errori = scufErrori($conBox);
    expect($conBox->status())->toBe(422)
        ->and($errori)->toContain('Box 12: ' . scufFrase('Venditore Ugo', '1 luglio 2026'))
        ->and($errori)->not->toContain(scufFrase('Venditore Ugo', '1 luglio 2026'));
});
