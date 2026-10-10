<?php

/**
 * 1.11.0-beta.44: la successione come tipo di passaggio (decisioni 64, 65 e 66 del registro del subentro), con le cifre fatte a mano.
 *
 * Muore un proprietario, pieno o comproprietario, o un nudo proprietario; gli eredi entrano nello stesso ruolo, ciascuno per la sua
 * quota, dal giorno del decesso (artt. 456 e 459 c.c.), e il defunto resta titolare fino al giorno prima. Il conguaglio per giorni è
 * quello della vendita, con il debito di chi entra diviso fra gli eredi per quota; le bozze di un piano fermo vanno all'erede di
 * riferimento, e la sua parte si toglie solo a lui. L'arretrato del defunto (art. 754 c.c.) passa agli eredi per quota come posizione
 * netta dopo il conguaglio, con un solo arrotondamento per erede; oppure resta a nome del defunto («eredi di …»), e sempre con il
 * legatario.
 *
 * Le cifre: € 1.200,00 l'anno su una voce del «Proprietario», dodici rate da € 100,00, divisione per giorni su 365.
 * - Decesso il 1° maggio, quattro rate emesse e non pagate, otto bozze: i giorni dopo il decesso sono 245, € 1.200,00 × 245 / 365 =
 *   € 805,48; per quota 60/40 € 483,29 e € 322,19. Le otto bozze (€ 800,00) vanno al primo erede, la cui coppia si rovescia: € 483,29 −
 *   € 800,00 = € 316,71 a credito; il secondo erede ha € 322,19 a debito; il defunto € 5,48 a credito.
 * - Con l'arretrato agli eredi la posizione del defunto prima della coppia è € 400,00 (le quattro rate) e le bozze passate € 800,00:
 *   per erede € 1.200,00 × 60 % − € 800,00 = − € 80,00 e € 1.200,00 × 40 % = € 480,00; l'arretrato è la differenza con la coppia:
 *   € 236,71 e € 157,81 (somma € 394,52 = € 400,00 − € 5,48). Alla fine ciascun erede paga la sua quota dell'anno intero (€ 720,00 e
 *   € 480,00) e il defunto chiude a zero.
 */

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
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

/** Un erede del condominio dello scenario. */
function sucErede(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'erede' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SUCEREDE' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * Il corpo del modulo per la successione del venditore dello scenario (`v`, proprietario al 100 %).
 *
 * @param  array<int, array{0: Anagrafica, 1: float}>  $eredi
 */
function sucCorpo(array $s, array $eredi, string $decesso = '2026-05-01', string $arretrato = 'eredi', ?Anagrafica $riferimento = null, array $extra = []): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => $decesso, 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => array_map(fn ($e) => ['anagrafica_id' => $e[0]->id, 'quota' => $e[1]], $eredi),
        'arretrato' => $arretrato, 'erede_di_riferimento' => $riferimento?->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra)
        // 1.11.0-beta.48, decisione 72: con l'arretrato a nome del defunto il conguaglio si sceglie; questi test lo scrivono, come
        // prima, salvo quelli che mandano la scelta o la casella di prima.
        + ($arretrato === 'defunto' && ! array_key_exists('conguaglio', $extra) && empty($extra['rinuncia_conguaglio']) ? ['conguaglio' => 'scrivi'] : []);
}

/** Il saldo di ciascuno dalle righe dei passaggi (conguaglio e arretrato), in centesimi: nome → importo, senza gli zeri. */
function sucSaldi(array $s): array
{
    $out = [];
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $r) {
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

/** Le quote pure del piano a nome di ciascuno: nome → centesimi. */
function sucQuote(array $s): array
{
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $out[(int) $q->anagrafica_id] = ($out[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
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

it('due eredi al 60/40 su un piano fermo con le bozze, arretrato agli eredi: coppie per quota con le bozze tolte al riferimento, e ciascun erede paga la sua quota dell\'anno intero', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    $anteprima = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $coppie = collect($anteprima['rate']['conguaglio']['coppie'])->mapWithKeys(fn ($c) => [$c['entrante_nome'] => (int) $c['importo']])->all();
    expect($coppie)->toBe(['Anna Erede' => -31671, 'Bruno Erede' => 32219]);
    $arretrato = collect($anteprima['rate']['arretrato']['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => (int) $e['importo']])->all();
    expect($arretrato)->toBe(['Anna Erede' => 23671, 'Bruno Erede' => 15781]);

    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));

    // Le righe: il defunto fino al giorno prima, gli eredi dal giorno del decesso, nello stesso ruolo.
    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 100.0, '2019-01-01', '2026-04-30'],
        [$anna->id, 'proprietario', 60.0, '2026-05-01', null],
        [$bruno->id, 'proprietario', 40.0, '2026-05-01', null],
    ]);
    // Le otto bozze vanno ad Anna, il riferimento, con lo stesso importo.
    expect(sucQuote($s))->toBe(['Anna Erede' => 80000, 'Venditore Ugo' => 40000]);
    // Conguaglio e arretrato: Anna −316,71 + 236,71; Bruno 322,19 + 157,81; il defunto −5,48 − 394,52.
    expect(sucSaldi($s))->toBe(['Anna Erede' => -8000, 'Bruno Erede' => 48000, 'Venditore Ugo' => -40000]);
    // Netto: ciascun erede la sua quota dell'anno intero, il defunto zero.
    expect(80000 - 8000)->toBe(72000);
});

it('arretrato a nome del defunto: solo le coppie per giorni, e il defunto resta debitore dei suoi giorni («eredi di …»)', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: 'defunto', riferimento: $anna));

    expect(sucSaldi($s))->toBe(['Anna Erede' => -31671, 'Bruno Erede' => 32219, 'Venditore Ugo' => -548]);
    // Il defunto: € 400,00 emessi meno € 5,48 = € 394,52, i 120 giorni fino al 30 aprile; gli eredi i loro 245 giorni per quota.
    expect(40000 - 548)->toBe((int) round(120000 * 120 / 365));
});

it('con un erede solo non serve un erede di riferimento, e le bozze vanno a lui', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');

    ruRegistra($this, $s, sucCorpo($s, [[$anna, 100]]));

    expect(sucQuote($s))->toBe(['Anna Erede' => 80000, 'Venditore Ugo' => 40000]);
    // Coppia € 5,48 e arretrato € 394,52: Anna paga l'anno intero, il defunto zero.
    expect(sucSaldi($s))->toBe(['Anna Erede' => 40000, 'Venditore Ugo' => -40000]);
});

it('con più eredi e bozze su un piano fermo il server chiede l\'erede di riferimento, senza sceglierlo al posto dell\'amministratore', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 60], [$bruno, 40]]))
        ->assertStatus(422)->assertJsonValidationErrors(['erede_di_riferimento']);
    // Un erede di riferimento che non è fra gli eredi si rifiuta.
    $altro = sucErede($s, 'Carla Estranea');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $altro))
        ->assertStatus(422)->assertJsonValidationErrors(['erede_di_riferimento']);
});

it('il legatario: l\'arretrato resta per forza a nome del defunto', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = sucErede($s, 'Leo Legatario');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$leo, 100]], extra: ['sottotipo' => 'legato']))
        ->assertStatus(422)->assertJsonValidationErrors(['arretrato']);

    ruRegistra($this, $s, sucCorpo($s, [[$leo, 100]], arretrato: 'defunto', extra: ['sottotipo' => 'legato']));
    // Come una vendita: le bozze al legatario, la coppia per giorni, il defunto resta debitore dei suoi giorni.
    expect(sucQuote($s))->toBe(['Leo Legatario' => 80000, 'Venditore Ugo' => 40000]);
    expect(sucSaldi($s))->toBe(['Leo Legatario' => 548, 'Venditore Ugo' => -548]);
    expect(Subentro::where('immobile_id', $s['unita']->id)->latest('id')->first()->registro['sottotipo'] ?? null)->toBe('legato');
});

it('la richiesta rifiuta le successioni che non tornano', function (callable $corpo, string $chiave) {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo($s, $anna, $bruno))
        ->assertStatus(422)->assertJsonValidationErrors([$chiave]);
})->with([
    'le quote degli eredi non fanno quella del defunto' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 60], [$b, 30]], riferimento: $a), 'eredi'],
    'la stessa persona due volte' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 50], [$a, 50]], riferimento: $a), 'eredi.1.anagrafica_id'],
    'il defunto fra gli eredi' => [fn ($s, $a, $b) => sucCorpo($s, [[$s['v'], 50], [$a, 50]], riferimento: $a), 'eredi'],
    'nessun erede' => [fn ($s, $a, $b) => sucCorpo($s, []), 'eredi'],
    'la data del decesso nel futuro' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 100]], decesso: now()->addMonth()->toDateString()), 'decorrenza'],
    'senza la scelta sull\'arretrato' => [fn ($s, $a, $b) => array_diff_key(sucCorpo($s, [[$a, 100]]), ['arretrato' => 1]), 'arretrato'],
    'la copia autentica' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 100]], extra: ['copia_autentica' => true, 'copia_autentica_il' => '2026-05-06']), 'copia_autentica'],
    'un ruolo diverso da quello del defunto' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 100]], extra: ['tipologia' => 'nuda_proprietario']), 'tipologia'],
    // Rilievo X12 della Fase 1-bis: una quota sotto lo 0,01 % diventava zero, e l'erede restava con le bozze senza una riga.
    'una quota sotto lo 0,01 %' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 100], [$b, 0.001]], riferimento: $b), 'eredi.1.quota'],
    'una quota con tre decimali' => [fn ($s, $a, $b) => sucCorpo($s, [[$a, 33.333], [$b, 66.667]], riferimento: $a), 'eredi.0.quota'],
    'un erede di un altro condominio' => [function ($s, $a, $b) {
        $fuori = Anagrafica::forceCreate(['nome' => 'Fuori Condominio', 'email' => 'fuori' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SUCFUORI' . str_pad((string) $s['unita']->id, 8, '0', STR_PAD_LEFT)]);

        return sucCorpo($s, [[$fuori, 100]]);
    }, 'eredi.0.anagrafica_id'],
]);

it('rilievo L18: nella vendita chi entra dev\'essere una persona del condominio', function () {
    $s = ruScenario('prima_rata', 0);
    $estraneo = Anagrafica::forceCreate(['nome' => 'Persona Estranea', 'email' => 'estranea-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Altrove 1', 'codice_fiscale' => 'ESTRANEA' . str_pad((string) $s['unita']->id, 8, '0', STR_PAD_LEFT)]);
    $corpo = ruPassaggio('vendita', $s['rigaV'], $estraneo, '2026-05-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'];

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors(['anagrafica_entrante_id']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasErrors('anagrafica_entrante_id');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->value('data_fine'))->toBeNull();
});

it('un usufruttuario non ha una successione: la sua morte è l\'estinzione dell\'usufrutto', function () {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 100]], extra: ['tipologia' => 'usufruttuario']))
        ->assertStatus(422)->assertJsonValidationErrors(['riga_uscente_id']);
});

it('la catena: Ugo muore, eredi Mara e Bice al 50 %; poi Bice vende a Dino e Mara a Elio: ognuno paga i suoi giorni, e il centesimo dei due eredi va alla prima', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $dino = sucErede($s, 'Dino Compra');
    $elio = sucErede($s, 'Elio Compra');

    // Decesso il 1° aprile: 275 giorni, € 1.200,00 × 275 / 365 = € 904,11, per metà € 452,06 a Mara (il centesimo alla prima) e
    // € 452,05 a Bice; tutte le dodici rate emesse e non pagate: l'arretrato per erede è € 600,00 meno la sua coppia.
    ruRegistra($this, $s, sucCorpo($s, [[$mara, 50], [$bice, 50]], decesso: '2026-04-01'));
    expect(sucSaldi($s))->toBe(['Bice Erede' => 60000, 'Mara Erede' => 60000, 'Venditore Ugo' => -120000]);

    // Bice vende la sua metà a Dino il 1° settembre: 122 giorni di metà voce, € 600,00 × 122 / 365 = € 200,55. Bice non è chi entra
    // scritto sul passaggio della successione: il defunto è suo predecessore come di Mara.
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => cdcRigaSuc($s, $bice), 'anagrafica_entrante_id' => $dino->id, 'decorrenza' => '2026-09-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto: Bice vende a Dino',
    ])->assertSessionHasNoErrors();
    // Mara vende la sua metà a Elio il 1° ottobre: 92 giorni, € 600,00 × 92 / 365 = € 151,23.
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => cdcRigaSuc($s, $mara), 'anagrafica_entrante_id' => $elio->id, 'decorrenza' => '2026-10-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto: Mara vende a Elio',
    ])->assertSessionHasNoErrors();

    $netti = sucSaldi($s);
    $netti['Venditore Ugo'] += 120000;
    expect(array_filter($netti))->toBe(['Bice Erede' => 60000 - 20055, 'Dino Compra' => 20055, 'Elio Compra' => 15123, 'Mara Erede' => 60000 - 15123]);
});

/** Ugo paga le sei rate emesse fino al 30 giugno e muore il 1° luglio: eredi Mara 60 e Bice 40, riferimento Bice, che riceve le bozze 7–12. */
function sucBozzeABice($test, string $arretrato): array
{
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-06-30');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->pluck('id'))
        ->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    ruRegistra($test, $s, sucCorpo($s, [[$mara, 60], [$bice, 40]], decesso: '2026-07-01', arretrato: $arretrato, riferimento: $bice));
    expect(sucQuote($s))->toBe(['Bice Erede' => 60000, 'Venditore Ugo' => 60000]);

    return [$s, $mara, $bice, sucErede($s, 'Dino Compratore')];
}

it('decisione 67 (1), rilievo X3: le bozze passate al riferimento entrano nel conguaglio dell\'altro erede che vende, con la sua quota, e restano al riferimento', function (string $arretrato, bool $emesse) {
    [$s, $mara, $bice, $dino] = sucBozzeABice($this, $arretrato);
    if ($emesse) {
        ruEmettiBozze($s, $s['piano'], '2026-09-30');
    }
    $rigaMara = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $mara->id)->whereNull('data_fine')->value('id');
    $corpo = ruPassaggio('vendita', $rigaMara, $dino, '2026-10-01', 60) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'];

    // Mara vende il suo 60 % a Dino il 1° ottobre: € 1.200,00 × 60 % × 92/365 = € 181,48, metà dalle quote emesse a Ugo e metà dalle
    // bozze passate a Bice. Prima entravano solo le quote di Ugo, € 90,74, e Mara pagava per Dino l'altra metà dei suoi giorni.
    $a = ruAnteprima($this, $s, $corpo);
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBe(18148)
        ->and($a['rate']['conguaglio']['bozze_riassegnate'])->toBe([])
        // Le frasi: le bozze restano a Bice, e la parte che non passa è quella degli altri eredi, non dell'usufrutto.
        ->and(implode(' ', $a['rate']['frasi']))->toContain('intestate a Bice Erede')->toContain('emesse a Bice Erede, erede di riferimento di Venditore Ugo')
            ->toContain('(la parte che la successione ha dato agli altri eredi)')->not->toContain('l\'estinzione ha riunito');
    ruRegistra($this, $s, $corpo);
    expect(sucQuote($s))->toBe(['Bice Erede' => 60000, 'Venditore Ugo' => 60000])
        ->and(sucSaldi($s)['Dino Compratore'])->toBe(18148);
})->with([
    'arretrato agli eredi' => ['eredi', false],
    'arretrato agli eredi, bozze emesse a Bice prima della vendita' => ['eredi', true],
    'arretrato a nome del defunto' => ['defunto', false],
]);

it('giro sulle correzioni, G13: con una catena prima della successione le bozze del riferimento si leggono con il defunto, non con chi aveva il piano', function (bool $senzaRighe) {
    // Il piano 2026 è di Ugo; il 1° marzo Ugo vende a Mario (le bozze passano a Mario); il 1° giugno muore Mario, eredi Bice
    // (riferimento) e Anna al 50 %, con l'arretrato agli eredi (le bozze passano a Bice); il 1° settembre Anna vende a Carlo.
    $s = ruScenario('prima_rata', 0);
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    $paga = fn () => DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->pluck('id'))
        ->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $riga = fn (Anagrafica $p) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');
    $mario = sucErede($s, 'Mario Defunto');
    $bice = sucErede($s, 'Bice Erede');
    $anna = sucErede($s, 'Anna Erede');
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'];
    ruEmetti($s, '2026-02-28');
    $paga();
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $mario, '2026-03-01', 100) + $letto);
    ruEmettiBozze($s, $s['piano'], '2026-05-31');
    $paga();
    ruRegistra($this, $s, sucCorpo(['rigaV' => $riga($mario)] + $s, [[$bice, 50], [$anna, 50]], decesso: '2026-06-01', riferimento: $bice));
    $corpo = ruPassaggio('vendita', $riga($anna), sucErede($s, 'Carlo Compratore'), '2026-09-01', 50) + $letto;

    $a = ruAnteprima($this, $s, $corpo);
    // Le sette bozze di Bice (€ 700,00) vengono da Mario, da cui Anna ha la metà: € 700,00 × 50 % × 122/365 ≈ € 116,99. Lette con Ugo,
    // da cui Mario aveva tutto, senza le righe valevano il doppio.
    $diBice = (int) collect($a['rate']['conguaglio']['quote'])->where('intestatario_id', $bice->id)->sum('entrante');
    expect(implode(' ', $a['rate']['frasi']))->toContain('erede di riferimento di Mario Defunto')->not->toContain('erede di riferimento di Venditore Ugo')
        ->and($diBice)->toBeLessThanOrEqual(11699)->toBeGreaterThanOrEqual(11698);
    // Il totale è quello per competenza, € 1.200,00 × 50 % × 122/365 = € 200,55: con la genealogia, e senza le righe con la parte arrivata
    // ad Anna anche sulle quote di Ugo, che prima contavano per intero (G26 del giro sulle correzioni: € 233,97, poi € 350,96 con G13).
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBeGreaterThanOrEqual(20054)->toBeLessThanOrEqual(20056);
})->with(['con le righe di riparto' => [false], 'senza le righe di riparto' => [true]]);

it('ultima revisione, UD3: senza le righe di riparto, l\'usufrutto tornato tutto al nudo e poi diviso fra i suoi eredi arriva per metà a chi vende', function () {
    // Ugo usufruttuario al 100 %, Mario nudo al 100 %; dodici rate da € 100,00 emesse a Ugo, piano fermo, senza righe di riparto. Il 1°
    // marzo muore Ugo (Mario pieno); il 1° giugno muore Mario, eredi Anna e Bice al 50 %; il 1° settembre Anna vende a Carlo.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $mario = sucErede($s, 'Mario Nudo');
    sucRiga($s, $mario, 'nuda_proprietario', 100, '2019-01-01');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    ruEmetti($s, '2026-12-31');
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    ruRegistra($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-03-01', 100) + $letto);
    $anna = sucErede($s, 'Anna Erede');
    $bice = sucErede($s, 'Bice Erede');
    $riga = fn (Anagrafica $p) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, sucCorpo(['rigaV' => $riga($mario)] + $s, [[$anna, 50], [$bice, 50]], decesso: '2026-06-01'));

    // Per competenza Carlo deve € 1.200,00 × 50 % × 122/365 = € 200,55; prima le quote di Ugo contavano per intero, € 401,10.
    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $riga($anna), sucErede($s, 'Carlo Compratore'), '2026-09-01', 50) + $letto);
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBeGreaterThanOrEqual(20054)->toBeLessThanOrEqual(20056);
});

it('decisione 67 (1), controllo: quando vende il riferimento le sue bozze contano già, e la coppia non cambia', function () {
    [$s, $mara, $bice, $dino] = sucBozzeABice($this, 'eredi');
    $rigaBice = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $bice->id)->whereNull('data_fine')->value('id');
    // € 1.200,00 × 40 % × 92/365 = € 120,99.
    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $rigaBice, $dino, '2026-10-01', 40) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti']);
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBe(12099)
        // Rilievo X3: non «emessa a Bice Erede … mai passata a Bice Erede», né «la parte che Bice Erede tiene».
        ->and(implode(' ', $a['rate']['frasi']))->toContain('a Bice Erede non è mai passata (la parte che la successione ha dato agli altri eredi)')
            ->not->toContain('che Bice Erede tiene')->not->toContain('emessa a Bice Erede per una parte');
});

it('un piano che si ricalcola ancora: la successione non lo prende, il piano la deve seguire e il ricalcolo divide i giorni degli eredi per quota', function () {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    // Nessuna rata emessa: niente bozze da spostare, quindi nessun erede di riferimento da chiedere.
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]]));
    expect(sucSaldi($s))->toBe([]);
    $piano = $s['piano']->fresh();
    expect(PianoRate::vociDeiPassaggi($piano->passaggiDaSeguire()))->toBe(['successione di Venditore Ugo, ' . $s['unita']->nome . ', dal 1 maggio 2026']);

    // Il ricalcolo: € 1.200,00 × 245 / 365 = € 805,48 agli eredi, € 483,29 e € 322,19; € 394,52 al defunto per i 120 giorni.
    ruRicalcola($s);
    expect(sucQuote($s))->toBe(['Anna Erede' => 48329, 'Bruno Erede' => 32219, 'Venditore Ugo' => 39452]);
    expect($piano->fresh()->passaggiDaSeguire())->toBe([]);
});

it('la successione si dice per quello che è, anche nella nuda proprietà e per legato', function () {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]]));

    expect(AnnullaPassaggioAction::descrivi($sub))->toBe('la successione di Venditore Ugo');
    $sub->tipologia = 'nuda_proprietario';
    $sub->registro = ['sottotipo' => Subentro::LEGATO] + $sub->registro;
    expect(AnnullaPassaggioAction::descrivi($sub))->toBe('la successione nella nuda proprietà di Venditore Ugo, per legato');
});

it('l\'anagrafica di un erede che il passaggio non nomina come chi entra non si elimina: il registro e l\'arretrato la nominano', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    expect((int) $sub->anagrafica_entrante_id)->toBe($anna->id);
    $bruno->condomini()->detach();

    $risposta = $this->actingAs($this->user)->delete(route('admin.anagrafiche.destroy', ['anagrafica' => $bruno->id]));
    expect($risposta->getSession()->get('message')['type'])->toBe('error')
        ->and(Anagrafica::find($bruno->id))->not->toBeNull()
        ->and(sucSaldi($s))->toBe(['Anna Erede' => -8000, 'Bruno Erede' => 48000, 'Venditore Ugo' => -40000]);
});

it('con l\'arretrato agli eredi il conguaglio da solo non si rinuncia e non si annulla: coppia e arretrato fanno un conto solo', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $rinuncia = ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro'];
    // La premessa del rilievo X11: anche con l'arretrato agli eredi l'anteprima propone le coppie, e la spunta rimasta da «a nome del
    // defunto» non deve partire col modulo (`rinunciaEffettiva` in resources/js/lib/gestionale/passaggi/rinunciaConguaglio.ts).
    expect(ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna))['rate']['conguaglio']['coppie'])->toHaveCount(2);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna, extra: $rinuncia))
        ->assertStatus(422)->assertJsonValidationErrors(['rinuncia_conguaglio']);
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    expect($sub->fresh()->arretratoAgliEredi())->toBeTrue();

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasErrors('conguaglio');
    expect(sucSaldi($s))->toBe(['Anna Erede' => -8000, 'Bruno Erede' => 48000, 'Venditore Ugo' => -40000])
        ->and($sub->fresh()->conguaglio_annullato_il)->toBeNull();
});

it('con l\'arretrato a nome del defunto il conguaglio si annulla, e ciò che gli eredi hanno regolato fra loro resta nel registro', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: 'defunto', riferimento: $anna));

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();
    // Le due coppie: − € 316,71 e € 322,19, cioè € 5,48 dalla parte degli eredi.
    expect(sucSaldi($s))->toBe([])
        ->and(array_column($sub->fresh()->registro['regolato_fuori'], 'importo'))->toBe([548]);
});

it('le righe dell\'arretrato nel Wallet: non si modificano e non si cancellano da sole, e lo dicono con le loro parole', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $riga = \App\Models\Saldo::whereIn('id', $sub->fresh()->saldiDellArretrato())->where('anagrafica_id', $bruno->id)->firstOrFail();
    expect((int) $riga->saldo_iniziale)->toBe(15781);

    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $riga->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])->assertSessionHasErrors('saldo');
    expect(session('errors')->first('saldo'))->toBe(\App\Models\Saldo::FRASE_ARRETRATO);
    $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $riga->id]))->assertSessionHasErrors('saldo');
    expect((int) $riga->fresh()->saldo_iniziale)->toBe(15781);

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']->id]))->assertOk()->viewData('page')['props'];
    $righe = collect($pagina['immobili'])->flatMap(fn ($i) => $i['saldi'])->where('subentro_id', $sub->id);
    expect($righe->where('e_arretrato', true)->count())->toBe(4)->and($righe->where('e_arretrato', false)->count())->toBe(4)
        // Giro sulle correzioni (GC8): le gambe della coppia lo sanno, e la modale manda all'annullamento della successione.
        ->and($righe->where('e_conguaglio_con_arretrato', true)->count())->toBe(4);
});

it('l\'estratto conto del defunto non propone di rimborsare l\'arretrato: lì il suo credito pareggia le rate che non ha pagato', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));

    // Rilievo X10 della Fase 1-bis: con l'arretrato agli eredi la posizione del defunto si chiude a zero per costruzione (coppia − € 5,48,
    // arretrato − € 394,52, rate non pagate € 400,00): non c'è niente di suo da rimborsare. Prima: € 5,48, già dentro l'arretrato che
    // pagano gli eredi; e prima ancora € 716,71, le righe a credito una per una.
    expect(DB::table('saldi')->where('anagrafica_id', $s['v']->id)->where('saldo_iniziale', '<', 0)->sum('saldo_iniziale'))->toBe(-71671);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['v']]))->assertOk()->viewData('page')['props'];
    expect($pagina['rimborso']['credito_in_saldi'])->toBe(0);
});

it('rilievo X10: il defunto che aveva pagato tutto non ha credito da rimborsare, perché con l\'arretrato agli eredi è passato a loro; a nome del defunto sì', function (string $arretrato, int $credito) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: $arretrato));

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['v']]))->assertOk()->viewData('page')['props'];
    expect($pagina['rimborso']['credito_in_saldi'])->toBe($credito);
})->with(['agli eredi' => ['eredi', 0], 'a nome del defunto' => ['defunto', 80548]]);

it('rilievo L1: un credito del defunto passato agli eredi si dice credito, senza «€ -» e senza l\'art. 754; il Wallet rimanda all\'annullamento del passaggio', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $corpo = sucCorpo($s, [[$anna, 60], [$bruno, 40]]);

    // Ugo aveva pagato l'anno intero: € 1.200,00 × 245/365 = € 805,48 a credito, per quota € 483,29 e € 322,19.
    $a = ruAnteprima($this, $s, $corpo);
    expect($a['rate']['arretrato']['frase'])->toBe('Il credito di Venditore Ugo al netto del conguaglio, € 805,48, passa agli eredi per quota: € 483,29 a credito di Anna Erede, € 322,19 a credito di Bruno Erede. Con il conguaglio la posizione di Venditore Ugo si chiude: per ogni erede la parte dei giorni dal decesso e la quota del credito si compensano, e su questi piani nessun erede ha niente da pagare o da ricevere.')
        ->and(array_column($a['rate']['arretrato']['eredi'], 'importo_formattato'))->toBe(['€ 483,29 a credito', '€ 322,19 a credito']);
    $sub = ruRegistra($this, $s, $corpo);
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect($storico['subentri'][0]['arretrato']['frase'])->toBe('Credito di Venditore Ugo passato agli eredi per quota, con righe nei saldi della gestione: € 483,29 a credito di Anna Erede, € 322,19 a credito di Bruno Erede.');

    // Una gamba della coppia: il Wallet non rimanda all'annullamento del solo conguaglio, che con l'arretrato agli eredi si rifiuta.
    $coppia = \App\Models\Saldo::where('subentro_id', $sub->id)->whereNotIn('id', $sub->fresh()->saldiDellArretrato())->where('anagrafica_id', $anna->id)->firstOrFail();
    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $coppia->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])->assertSessionHasErrors('saldo');
    expect(session('errors')->first('saldo'))->toBe(\App\Models\Saldo::FRASE_CONGUAGLIO_CON_ARRETRATO);
});

it('giro sulle correzioni, GC1 e GC2: con le rate pagate fino al decesso l\'arretrato è un piccolo credito, ma con il conguaglio gli eredi pagano ciò che resta aperto', function (string $arretrato, string $frase) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    // Ugo paga gennaio–aprile (€ 400,00) e muore il 1° maggio: restano aperti € 800,00; la parte degli eredi è € 805,48 (€ 483,29 e
    // € 322,19). Arretrato agli eredi: € 480,00 − € 483,29 = − € 3,29 e € 320,00 − € 322,19 = − € 2,19.
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id', 'numero_rata');
    DB::table('rate_quote')->whereIn('rata_id', $rate->only([1, 2, 3, 4])->values())->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    expect(ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: $arretrato))['rate']['arretrato']['frase'])->toBe($frase);
})->with([
    'agli eredi' => ['eredi', 'Il credito di Venditore Ugo al netto del conguaglio, € 5,48, passa agli eredi per quota: € 3,29 a credito di Anna Erede, € 2,19 a credito di Bruno Erede. Con il conguaglio, ogni erede paga la sua quota di ciò che Venditore Ugo ha lasciato aperto, € 800,00, e la posizione di Venditore Ugo si chiude.'],
    'a nome del defunto' => ['defunto', '€ 5,48 a credito resta a nome di Venditore Ugo («eredi di Venditore Ugo»): è un credito dell\'eredità, che lo studio regola con gli eredi.'],
]);

it('giro sulle correzioni, GB5 e GB6: dopo la registrazione il messaggio dice ogni erede, anche in pari, e l\'arretrato solo se è passato agli eredi', function (string $arretrato, string $testa, array $perErede) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    // Ugo aveva pagato l'anno intero: con l'arretrato agli eredi coppia e arretrato si compensano; a nome del defunto restano le coppie.
    $flash = $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: $arretrato))
        ->assertSessionHasNoErrors()->getSession()->get('passaggio_registrato');
    expect($flash['per_erede_testa'])->toBe($testa)->and($flash['per_erede'])->toBe($perErede);
})->with([
    'agli eredi' => ['eredi', 'Scritto in saldi, per erede, con il conguaglio e l\'arretrato insieme', ['Anna Erede in pari', 'Bruno Erede in pari']],
    'a nome del defunto' => ['defunto', 'Conguaglio scritto in saldi, per erede', ['€ 483,29 a debito di Anna Erede', '€ 322,19 a debito di Bruno Erede']],
]);

it('giro sulle correzioni, G4: l\'estratto conto del defunto non conta le righe di passaggi precedenti che l\'arretrato agli eredi ha già passato', function (string $prima) {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    if ($prima === 'acquisto') {
        // Rossi (`v`) vende a Ugo (`a`) il 4 aprile: le nove bozze da aprile (€ 900,00) passano a Ugo, che per i suoi 272 giorni deve
        // € 894,25: la coppia si rovescia, € 5,75 a credito di Ugo. Poi Ugo muore il 1° luglio.
        ruEmetti($s, '2026-03-31');
        ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-04-04', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti']);
        $defunto = $s['a'];
        $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $defunto->id)->whereNull('data_fine')->value('id');
        $corpo = sucCorpo(['rigaV' => $riga] + $s, [[$anna, 60], [$bruno, 40]], decesso: '2026-07-01', riferimento: $anna);
    } else {
        // Ugo, con le prime sei rate pagate, costituisce l'usufrutto ad Elsa il 1° aprile (€ 904,11 a credito di Ugo); poi Ugo, nudo
        // proprietario, muore il 1° luglio con le rate da luglio non pagate.
        ruEmetti($s, '2026-12-31');
        $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id', 'numero_rata');
        DB::table('rate_quote')->whereIn('rata_id', $rate->only([1, 2, 3, 4, 5, 6])->values())->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
        ruRegistra($this, $s, ruPassaggio('costituzione', $s['rigaV'], $s['a'], '2026-04-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto']);
        $defunto = $s['v'];
        $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $defunto->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
        $corpo = sucCorpo(['rigaV' => $riga] + $s, [[$anna, 60], [$bruno, 40]], decesso: '2026-07-01', extra: ['tipologia' => 'nuda_proprietario']);
    }
    $sub = ruRegistra($this, $s, $corpo);
    expect($sub->fresh()->arretratoAgliEredi())->toBeTrue()
        ->and($sub->fresh()->fontiDellArretrato())->not->toBe([])
        ->and(sucAperto($s, $defunto))->toBe(0);

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $defunto]))->assertOk()->viewData('page')['props'];
    expect($pagina['rimborso']['credito_in_saldi'])->toBe(0);
    // Ultima revisione (UE7): nel Wallet quelle righe sanno di essere fonti dell'arretrato, e la modale lo dice.
    $wallet = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']->id]))->assertOk()->viewData('page')['props'];
    expect(collect($wallet['immobili'])->flatMap(fn ($i) => $i['saldi'])->where('e_fonte_arretrato', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect($sub->fresh()->fontiDellArretrato())->sort()->values()->all());
})->with(['un acquisto con la coppia rovesciata' => ['acquisto'], 'una costituzione dell\'usufrutto' => ['costituzione']]);

it('rilievo X2: l\'arretrato agli eredi chiude il defunto anche dove ha solo la coppia (Rossi vende a Ugo con la rinuncia, poi Ugo muore)', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 100) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti', 'ho_letto' => true, 'nota_cancello' => 'Rogito letto']);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $corpo = sucCorpo(['rigaV' => $rigaA] + $s, [[$mara, 60], [$bice, 40]], decesso: '2026-07-01');

    // € 1.200,00 × 184/365 = € 604,93: coppie € 362,96 e € 241,97; il defunto non ha quote né saldi suoi, l'arretrato è − la coppia.
    $a = ruAnteprima($this, $s, $corpo);
    expect(collect($a['rate']['arretrato']['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => $e['importo']])->sortKeys()->all())->toBe(['Bice Erede' => -24197, 'Mara Erede' => -36296]);
    $sub = ruRegistra($this, $s, $corpo);
    expect(sucSaldi($s))->toBe([])
        ->and($sub->fresh()->arretratoAgliEredi())->toBeTrue();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['a']]))->assertOk()->viewData('page')['props'];
    expect($pagina['rimborso']['credito_in_saldi'])->toBe(0);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasErrors('conguaglio');
});

it('rilievo X2: con l\'arretrato a nome del defunto la cifra che resta dice anche il credito della sola coppia', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 100) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti', 'ho_letto' => true, 'nota_cancello' => 'Rogito letto']);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $sub = ruRegistra($this, $s, sucCorpo(['rigaV' => $rigaA] + $s, [[$mara, 60], [$bice, 40]], decesso: '2026-07-01', arretrato: 'defunto'));
    expect($sub->fresh()->registro['arretrato']['resta'])->toBe(-60493);
});

/** La posizione aperta di una persona sull'unità dello scenario: quote non pagate più saldi non applicati, in centesimi. */
function sucAperto(array $s, Anagrafica $p): int
{
    $quote = (int) DB::table('rate_quote')->where('anagrafica_id', $p->id)->where('immobile_id', $s['unita']->id)->sum(DB::raw('importo - importo_pagato'));

    return $quote + (int) DB::table('saldi')->where('anagrafica_id', $p->id)->where('immobile_id', $s['unita']->id)->where('is_applicato', false)->sum('saldo_iniziale');
}

it('rilievo X8: con l\'arretrato a nome del defunto e la rinuncia al conguaglio, a suo nome resta tutta la sua posizione', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $corpo = sucCorpo($s, [[$mara, 60], [$bice, 40]], decesso: '2026-07-01', arretrato: 'defunto', extra: ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro']);

    // Dodici rate da € 100,00 emesse e non pagate. Dal 1° luglio 184 giorni: € 1.200,00 × 184/365 = € 604,93 (coppie € 362,96 e € 241,97).
    // Con la coppia a Ugo restano € 1.200,00 − € 604,93 = € 595,07; con la rinuncia la coppia non si scrive, e restano tutti i € 1.200,00.
    $a = ruAnteprima($this, $s, $corpo);
    expect($a['rate']['arretrato']['resta'])->toBe(59507)
        ->and($a['rate']['arretrato']['resta_senza_conguaglio'])->toBe(120000)
        ->and($a['rate']['arretrato']['frase_senza_conguaglio'])->toStartWith('€ 1.200,00 resta a nome di Venditore Ugo')
        // 1.11.0-beta.48 (P6 e Fase 1-bis, R1): prima la cifra «senza conguaglio», e niente «rinunciano» né «come emesso».
        ->and(implode(' | ', $a['cancello']['informazioni']))->toContain('€ 1.200,00 senza conguaglio, € 595,07 se scrivi il conguaglio');
    expect(implode(' | ', $a['cancello']['informazioni']))->not->toContain('come emesso');
    $sub = ruRegistra($this, $s, $corpo);
    expect(sucSaldi($s))->toBe([])
        ->and($sub->fresh()->registro['arretrato']['resta'])->toBe(120000)
        ->and(sucAperto($s, $s['v']))->toBe(120000)
        ->and(array_column($sub->fresh()->registro['regolato_fuori'], 'importo'))->toBe([60493]);
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect($storico['subentri'][0]['arretrato']['frase'])->toContain('€ 1.200,00')->not->toContain('€ 595,07');
});

it('rilievo X8: con l\'arretrato a nome del defunto, annullato il conguaglio, a suo nome torna la posizione senza la coppia', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$mara, 60], [$bice, 40]], decesso: '2026-07-01', arretrato: 'defunto'));
    expect($sub->fresh()->registro['arretrato']['resta'])->toBe(59507)
        ->and(sucAperto($s, $s['v']))->toBe(59507);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();
    expect($sub->fresh()->registro['arretrato']['resta'])->toBe(120000)
        ->and(sucAperto($s, $s['v']))->toBe(120000);
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect($storico['subentri'][0]['arretrato']['frase'])->toContain('€ 1.200,00')->not->toContain('€ 595,07');
});

/** Ugo paga € 595,07 sulle sue quote in ordine: le prime cinque intere e € 95,07 della sesta. A lui restano aperti € 604,93. */
function sucUgoPagaInOrdine(array $s): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id', 'numero_rata');
    DB::table('rate_quote')->whereIn('rata_id', $rate->only([1, 2, 3, 4, 5])->values())->where('anagrafica_id', $s['v']->id)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    DB::table('rate_quote')->where('rata_id', $rate[6])->where('anagrafica_id', $s['v']->id)->update(['importo_pagato' => 9507, 'stato' => 'parzialmente_pagata']);
}

it('rilievo X9: con la posizione del defunto in pari l\'arretrato agli eredi non ha righe, e il conguaglio si annulla come con l\'arretrato a nome del defunto', function (string $arretrato) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    sucUgoPagaInOrdine($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 50], [$bruno, 50]], decesso: '2026-07-01', arretrato: $arretrato));
    // Le coppie: € 604,93 per metà, € 302,47 e € 302,46; aperti a Ugo € 604,93: l'arretrato agli eredi è zero per ciascuno.
    expect(sucSaldi($s))->toBe(['Anna Erede' => 30247, 'Bruno Erede' => 30246, 'Venditore Ugo' => -60493])
        ->and($sub->fresh()->saldiDellArretrato())->toBe([]);
    if ($arretrato === 'eredi') {
        // Rilievo L9: senza righe, «chi resta obbligato» non dice «ha intestato a ogni erede … con righe nei saldi».
        expect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['obbligati'][1])
            ->toBe('Il giorno della registrazione non c\'era niente di non pagato da intestare agli eredi.');
    }

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();
    expect(sucSaldi($s))->toBe([])
        ->and(sucAperto($s, $s['v']))->toBe(60493)
        ->and(array_column($sub->fresh()->registro['regolato_fuori'], 'importo'))->toBe([60493]);
})->with(['agli eredi' => ['eredi'], 'a nome del defunto' => ['defunto']]);

it('rilievo X9: l\'arretrato che non trova un esercizio su cui scriversi resta a nome del defunto, e lo storico dice la cifra invece di «in pari»', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    sucUgoPagaInOrdine($s);
    // Una straordinaria su una gestione che, dopo, non è legata a nessun esercizio: sei rate da € 200,00, emesse e non pagate.
    $facciata = ruAggiungiStraordinaria($s, '2026-03-01');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($facciata, forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmettiBozze($s, $facciata, '2026-12-31');
    $facciata->update(['esercizio_id' => null]);
    DB::table('esercizio_gestione')->where('gestione_id', $facciata->gestione_id)->delete();
    $s['e']->update(['stato' => 'chiuso']);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $corpo = sucCorpo($s, [[$anna, 50], [$bruno, 50]], decesso: '2026-07-01');

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasNoErrors();
    expect(implode(' | ', $risposta->getSession()->get('passaggio_registrato')['avvisi']))->toContain('Nessun esercizio su cui scrivere l\'arretrato della gestione «Facciata»');
    $sub = Subentro::where('immobile_id', $s['unita']->id)->latest('id')->firstOrFail();
    expect($sub->registro['arretrato']['totale'])->toBe(120000)
        ->and($sub->registro['arretrato']['non_scritto'])->toBe(120000)
        ->and($sub->saldiDellArretrato())->toBe([]);
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0];
    expect($storico['arretrato']['frase'])->not->toContain('in pari')->toContain('€ 1.200,00')
        // Rilievo L9: «chi resta obbligato» non promette righe che non ci sono.
        ->and($storico['obbligati'][1])->toBe('Il programma non ha potuto intestare a ogni erede la sua parte della posizione di Venditore Ugo: mancava un esercizio su cui scriverla, e resta a nome di Venditore Ugo.');
});

it('rilievo L10: l\'annullamento fermato da righe dell\'arretrato già assorbite da un piano le chiama con il loro nome', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    // Un secondo piano della stessa gestione assorbe i saldi, comprese le righe dell'arretrato.
    $secondo = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Conguagli 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($secondo, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Gli eredi al posto del defunto', esercizio: $s['e']);
    expect(\App\Models\Saldo::whereIn('id', $sub->fresh()->saldiDellArretrato())->where('is_applicato', true)->exists())->toBeTrue();

    $annullabile = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['annullabile'];
    expect($annullabile['si'])->toBeFalse()
        ->and($annullabile['motivo'])->toStartWith('Le righe dell\'arretrato del defunto di questo passaggio sono già state assorbite da un piano rate');
});

it('la riga di un erede che il passaggio non nomina come chi entra ha una storia: non si dissocia', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $riga = cdcRigaSuc($s, $bruno);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$s['c'], $s['unita'], $riga]))
        ->assertStatus(422)->assertJsonValidationErrors(['titolarita']);
    expect(DB::table('anagrafica_immobile')->where('id', $riga)->exists())->toBeTrue();
});

it('i testi della successione: il pannello, il messaggio e lo storico dicono gli eredi, l\'arretrato e l\'art. 754, senza l\'art. 63 co. 4 e senza sentenze', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $corpo = sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna);

    $a = ruAnteprima($this, $s, $corpo);
    expect($a['riferimento']['frase'])->toBe('Venditore Ugo risulterà titolare fino al 30 aprile 2026 compreso, il giorno prima del decesso. Anna Erede (60 %) e Bruno Erede (40 %) dal 1 maggio 2026.')
        ->and($a['anagrafica']['frasi'][1])->toBe('Dal 1 maggio 2026, come proprietario, Anna Erede (60 %) e Bruno Erede (40 %): ogni erede per la sua quota, in comunione.')
        ->and($a['obbligati']['frasi'])->toBe([
            'Dei contributi maturati fino al 30 aprile 2026 rispondono gli eredi di Venditore Ugo, ogni erede in proporzione della sua quota ereditaria (art. 754 c.c.).',
            'Il programma intesta a ogni erede, con righe nei saldi della gestione, la sua parte della posizione di Venditore Ugo al netto del conguaglio.',
            'Dal 1 maggio 2026 Anna Erede (60 %) e Bruno Erede (40 %) rispondono dei contributi dell\'unità come comproprietari, divisi fra loro per quota.',
        ])
        ->and($a['obbligati']['copia_autentica_mancante'])->toBeFalse()
        ->and(implode(' | ', $a['cancello']['motivi']))->toContain('l\'arretrato di Venditore Ugo al netto del conguaglio, € 394,52, passa agli eredi con righe di saldo sulla stessa gestione: € 236,71 ad Anna Erede, € 157,81 a Bruno Erede');
    // Rilievo L2: la frase del calcolo non tratta gli eredi come una persona sola.
    expect(implode(' ', $a['rate']['frasi']))->toContain('la parte degli eredi sull\'intero piano è € 805,48; € 800,00 sono già nelle rate in bozza che passano ad Anna Erede')
        ->not->toContain('debito € 5,48 ad Anna Erede e Bruno Erede');
    $tutto = json_encode([$a['riferimento'], $a['anagrafica'], $a['rate']['frasi'], $a['obbligati'], $a['invarianti'], $a['cancello']], JSON_UNESCAPED_UNICODE);
    expect($tutto)->not->toContain('63 co. 4')->not->toContain('Cass.')->not->toContain('copia autentica');

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasNoErrors();
    $flash = $risposta->getSession()->get('passaggio_registrato');
    expect($flash['frase'])->toBe('Venditore Ugo risulta titolare fino al 30 aprile 2026 compreso, il giorno prima del decesso. Anna Erede (60 %) e Bruno Erede (40 %) dal 1 maggio 2026.')
        ->and(array_column($flash['eredi'], 'nome'))->toBe(['Anna Erede', 'Bruno Erede'])
        // Rilievo L2: il netto per erede di conguaglio e arretrato, non «2 coppie, € 5,48 a debito di chi entra».
        ->and($flash['per_erede'])->toBe(['€ 80,00 a credito di Anna Erede', '€ 480,00 a debito di Bruno Erede']);

    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    $p = $storico['subentri'][0];
    expect($p['tipo_passaggio'])->toBe('successione')
        ->and($p['entrante'])->toBe('Anna Erede (60 %) e Bruno Erede (40 %)')
        // La somma delle due coppie (− € 316,71 + € 322,19), senza l'arretrato.
        ->and($p['conguaglio']['importo'])->toBe(548)
        ->and($p['conguaglio']['annullabile'])->toBeFalse()
        ->and($p['arretrato']['frase'])->toBe('Arretrato di Venditore Ugo passato agli eredi per quota (art. 754 c.c.), con righe nei saldi della gestione: € 236,71 ad Anna Erede, € 157,81 a Bruno Erede.')
        ->and($p['obbligati'][1])->toBe('Il programma ha intestato a ogni erede, con righe nei saldi della gestione, la sua parte della posizione di Venditore Ugo al netto del conguaglio.');
    $rigaBruno = collect($storico['righe'])->firstWhere('anagrafica.nome', 'Bruno Erede');
    expect($rigaBruno['subentro']['ruolo_nel_passaggio'])->toBe('entrante');
});

it('rilievo L3: nella successione anche le frasi della straordinaria non citano sentenze né l\'art. 63', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    // La facciata deliberata il 1° giugno, dopo il decesso: la spesa è degli eredi. Sei rate da € 200,00, tutte emesse a Ugo.
    $facciata = ruAggiungiStraordinaria($s, '2026-06-01');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($facciata, forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmettiBozze($s, $facciata, '2026-12-31');
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');

    $testo = implode(' ', ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna))['rate']['frasi']);
    expect($testo)->toContain('(straordinaria)')->not->toContain('Cass.')->not->toContain('art. 63')->not->toContain('chi compra');
});

it('decisione 66 (1): con un piano che si ricalcola ancora e l\'arretrato agli eredi, il pannello avvisa che i giorni del defunto restano a suo nome dopo il ricalcolo', function () {
    $s = ruScenario('prima_rata', 0);
    $anna = sucErede($s, 'Anna Erede');
    $a = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 100]]));
    expect(implode(' | ', $a['cancello']['avvisi']))->toContain('il piano «' . $s['piano']->nome . '» si ricalcola ancora: ricalcolato, darà agli eredi i giorni dal 1 maggio 2026, divisi per quota, e lascerà a Venditore Ugo le quote dei giorni fino al 30 aprile 2026');
    // Decisione 69 (2): anche con l'arretrato a nome del defunto il pannello lo dice, perché le quote nascono a suo nome.
    $b = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 100]], arretrato: 'defunto'));
    expect(implode(' | ', $b['cancello']['avvisi']))->toContain('il piano «' . $s['piano']->nome . '» si ricalcola ancora: ricalcolato, darà agli eredi i giorni dal 1 maggio 2026, divisi per quota, e lascerà a Venditore Ugo le quote dei giorni fino al 30 aprile 2026: con l\'arretrato a suo nome restano intestate a Venditore Ugo («eredi di Venditore Ugo»), e il ricalcolo ne mette una parte in ogni rata del piano, anche in quelle che scadono dopo il decesso')
        ->not->toContain('registra un saldo manuale');
    // Con il legato le quote dei giorni dal decesso vanno a chi riceve l'unità, non «agli eredi».
    $c = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 100]], arretrato: 'defunto', extra: ['sottotipo' => 'legato']));
    expect(implode(' | ', $c['cancello']['avvisi']))->toContain('darà a chi riceve l\'unità per legato i giorni dal 1 maggio 2026');
    // Giro sulla decisione 69 (S8): senza piani che si ricalcolano, nessun avviso, con tutte e due le scelte.
    $f = ruScenario('prima_rata', 0);
    ruEmetti($f);
    $erede = sucErede($f, 'Anna Erede');
    foreach (['defunto', 'eredi'] as $scelta) {
        expect(implode(' | ', ruAnteprima($this, $f, sucCorpo($f, [[$erede, 100]], arretrato: $scelta))['cancello']['avvisi']))->not->toContain('si ricalcola ancora');
    }
});

it('il box con lo stesso atto: la quota che il defunto aveva sul box va agli eredi in proporzione, e il passaggio del box conosce gli eredi', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $carla = sucErede($s, 'Carla Comproprietaria');
    // Sul box Ugo ha la metà, Carla l'altra.
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    foreach ([[$s['v'], 50], [$carla, 50]] as [$p, $q]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => $q, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna, extra: ['pertinenze' => [$box->id]]));

    // La metà di Ugo, al 60/40: 30 e 20.
    expect(ruRighe($box->id))->toBe([
        [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$carla->id, 'proprietario', 50.0, '2019-01-01', null],
        [$anna->id, 'proprietario', 30.0, '2026-05-01', null],
        [$bruno->id, 'proprietario', 20.0, '2026-05-01', null],
    ]);
    $figlio = Subentro::where('subentro_padre_id', $sub->id)->firstOrFail();
    expect($figlio->successione())->toBeTrue()
        ->and(array_column($figlio->eredi(), 'quota'))->toBe([30.0, 20.0])
        ->and(array_column($figlio->eredi(), 'anagrafica_id'))->toBe([$anna->id, $bruno->id]);
});

it('un erede già comproprietario: la quota ereditata si somma alla sua riga, e il registro dice la quota ereditata, non la somma', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    // Ugo e Bruno al 50 %; Ugo muore, eredi Anna e Bruno a metà della sua metà.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $bruno->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $a = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 25], [$bruno, 25]], riferimento: $anna, extra: ['quota' => 50]));
    expect($a['anagrafica']['frasi'])->toContain('Bruno Erede è già proprietario di questa unità: la quota ereditata si somma alla sua.')
        ->and($a['obbligati']['frasi'][2])->toBe('Dal 1 maggio 2026 Anna Erede (25 %) e Bruno Erede (25 %) rispondono dei contributi dell\'unità per la parte che era di Venditore Ugo (50 %) come comproprietari, divisi fra loro per quota.');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 25], [$bruno, 25]], riferimento: $anna, extra: ['quota' => 50]));

    $inCorso = collect(ruRighe($s['unita']->id))->filter(fn ($r) => $r[4] === null)->map(fn ($r) => [$r[0], $r[2]])->values()->all();
    expect($inCorso)->toContain([$anna->id, 25.0])->toContain([$bruno->id, 75.0]);
    expect(array_column($sub->fresh()->eredi(), 'quota'))->toBe([25.0, 25.0]);
});

it('decisione 66 (3): la nuda di un nudo proprietario che muore va agli eredi per quota, e all\'estinzione dell\'usufrutto tornano pieni loro', function () {
    // Ugo e Rita al 50 %; ognuno vende con riserva la nuda della sua metà: Ugo a Elsa, Rita a Nino. Due usufrutti in corso.
    [$s, $madre, $rigaM] = ruDueGenitori();
    $nino = sucErede($s, 'Nino Nudo');
    ruRegistra($this, $s, ruRiserva($s + ['rigaV' => $s['rigaV']], '2026-02-01', ['quota' => 50]));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM, 'v' => $madre, 'a' => $nino] + $s, '2026-02-01', ['quota' => 50]));
    // Elsa, nuda proprietaria della metà di Ugo, muore il 1° maggio: eredi Mara e Bice al 60/40 della sua metà.
    $mara = sucErede($s, 'Mara Erede');
    $bice = sucErede($s, 'Bice Erede');
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, sucCorpo(['rigaV' => $rigaElsa] + $s, [[$mara, 30], [$bice, 20]], extra: ['quota' => 50, 'tipologia' => 'nuda_proprietario']));

    // Ugo muore il 1° settembre: si estingue il suo usufrutto, e la nuda della sua metà è ora di Mara e Bice.
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $estinzione = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUgo, 'decorrenza' => '2026-09-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta'];
    $a = ruAnteprima($this, $s, $estinzione);
    // Prima la nuda si fermava alla riga chiusa di Elsa, e il programma chiedeva all'amministratore quali nudi tornassero pieni.
    expect($a['nudi']['da'])->toBe('registro')
        ->and($a['riferimento']['frase'])->toBe('Venditore Ugo risulterà usufruttuario fino al 31 agosto 2026 compreso. Dal 1 settembre 2026 Mara Erede (30 %) e Bice Erede (20 %) tornano proprietari pieni.');
    ruRegistra($this, $s, $estinzione);

    $inCorso = collect(ruRighe($s['unita']->id))->filter(fn ($r) => $r[4] === null)->map(fn ($r) => [$r[0], $r[1], $r[2]])->values()->all();
    expect($inCorso)->toEqualCanonicalizing([
        [$madre->id, 'usufruttuario', 50.0], [$nino->id, 'nuda_proprietario', 50.0],
        [$mara->id, 'proprietario', 30.0], [$bice->id, 'proprietario', 20.0],
    ]);
});

it('decisione 66 (3), donazione congiunta: la nuda di Elsa a tre eredi per un terzo torna piena per il registro, e le parti sommano all\'usufrutto (rilievo X13)', function () {
    // Ugo e Rita al 50 % donano a Elsa la nuda della loro metà, con la riserva: Elsa nuda al 100 % su una riga sola.
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, '2026-02-01', ['quota' => 50]));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM, 'v' => $madre] + $s, '2026-02-01', ['quota' => 50]));
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $carla = sucErede($s, 'Carla Erede');
    ruRegistra($this, $s, sucCorpo(['rigaV' => $rigaElsa] + $s, [[$anna, 33.34], [$bruno, 33.33], [$carla, 33.33]], extra: ['tipologia' => 'nuda_proprietario']));

    // Ugo muore: la sua metà torna piena agli eredi per quota. 50 × 0,3334 = 16,67; 50 × 0,3333 = 16,665 per due: arrotondati uno per
    // uno davano 16,67 tre volte, 50,01 contro un usufrutto di 50, e il programma chiedeva all'amministratore. In centesimi di punto:
    // 1667, 1666 e 1666 più il centesimo del pareggio al primo dei due a parità di resto, Bruno.
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $estinzione = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUgo, 'decorrenza' => '2026-09-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta'];
    expect(ruAnteprima($this, $s, $estinzione)['nudi']['da'])->toBe('registro');
    ruRegistra($this, $s, $estinzione);

    $inCorso = collect(ruRighe($s['unita']->id))->filter(fn ($r) => $r[4] === null)->map(fn ($r) => [$r[0], $r[1], $r[2]])->values()->all();
    expect($inCorso)->toEqualCanonicalizing([
        [$madre->id, 'usufruttuario', 50.0],
        [$anna->id, 'proprietario', 16.67], [$anna->id, 'nuda_proprietario', 16.67],
        [$bruno->id, 'proprietario', 16.67], [$bruno->id, 'nuda_proprietario', 16.66],
        [$carla->id, 'proprietario', 16.66], [$carla->id, 'nuda_proprietario', 16.67],
    ]);
});

it('registrata, annullata, registrata: l\'annullamento rimette tutto com\'era (righe, bozze, coppie e arretrato), e la seconda registrazione scrive le stesse cose', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $unita = [(int) $s['unita']->id];
    $prima = ruFoto($unita, (int) $s['c']->id);

    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $registrata = ruFotoNormalizzata($unita, (int) $s['c']->id);
    expect(sucSaldi($s))->toBe(['Anna Erede' => -8000, 'Bruno Erede' => 48000, 'Venditore Ugo' => -40000]);

    // L'annullamento dice che se ne va anche l'arretrato.
    expect(app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->effetti($sub->fresh()))->toContain('Il conguaglio e l\'arretrato del defunto si tolgono dai saldi della gestione.');
    ruAnnulla($this, $s, $sub)->assertSessionHasNoErrors();
    expect(ruFoto($unita, (int) $s['c']->id))->toEqual($prima)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0);

    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    expect(ruFotoNormalizzata($unita, (int) $s['c']->id))->toEqual($registrata);
});

/**
 * Ugo (`v`) e Rita usufruttuari al 50 % ciascuno, Elsa (`a`) nuda proprietaria al 100 % (la donazione dei genitori con riserva
 * d'usufrutto congiunto). Voce ordinaria € 1.200,00 sull'«Usufruttuario», dodici rate tutte emesse: € 600,00 a Ugo, € 600,00 a Rita.
 */
function sucCongiunto(): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $rita = sucErede($s, 'Rita Usufruttuaria');
    foreach ([[$rita, 'usufruttuario', 50], [$s['a'], 'nuda_proprietario', 100]] as [$p, $ruolo, $q]) {
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $q, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-12-31');

    return [$s, $rita];
}

function sucEstinzione(array $s, array $extra = []): array
{
    return array_merge(['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta'], $extra);
}

it('l\'accrescimento: l\'usufrutto di chi muore va all\'altro usufruttuario, la nuda resta nuda, e il conguaglio dell\'ordinaria va a chi lo riceve', function () {
    [$s, $rita] = sucCongiunto();

    $a = ruAnteprima($this, $s, sucEstinzione($s, ['accrescimento' => true]));
    expect($a['riferimento']['frase'])->toBe('Venditore Ugo risulterà usufruttuario fino al 30 aprile 2026 compreso. Dal 1 maggio 2026 il suo usufrutto si accresce a Rita Usufruttuaria, e la nuda proprietà resta nuda.')
        ->and($a['anagrafica']['frasi'])->toContain('Rita Usufruttuaria risulterà usufruttuario dal 1 maggio 2026 al 100 %, con la sua parte dell\'usufrutto di Venditore Ugo.')
        // € 600,00 × 245 / 365 = € 402,74.
        ->and(array_column($a['rate']['conguaglio']['coppie'], 'importo'))->toBe([40274])
        ->and(array_column($a['rate']['conguaglio']['coppie'], 'entrante_nome'))->toBe(['Rita Usufruttuaria']);

    $sub = ruRegistra($this, $s, sucEstinzione($s, ['accrescimento' => true]));
    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'usufruttuario', 50.0, '2019-01-01', '2026-04-30'],
        [$rita->id, 'usufruttuario', 50.0, '2019-01-01', '2026-04-30'],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2019-01-01', null],
        [$rita->id, 'usufruttuario', 100.0, '2026-05-01', null],
    ]);
    expect(sucSaldi($s))->toBe(['Rita Usufruttuaria' => 40274, 'Venditore Ugo' => -40274])
        ->and($sub->fresh()->conAccrescimento())->toBeTrue()
        ->and(array_column($sub->fresh()->accrescimento(), 'quota'))->toBe([50.0]);

    // L'annullamento rimette le righe com'erano.
    ruAnnulla($this, $s, $sub)->assertSessionHasNoErrors();
    expect(collect(ruRighe($s['unita']->id))->filter(fn ($r) => $r[4] === null)->count())->toBe(3)
        ->and(sucSaldi($s))->toBe([]);
});

it('la catena dopo l\'accrescimento: muore anche Rita, e la nuda di Elsa torna piena; il conguaglio porta a Elsa i giorni dell\'intero usufrutto, anche la parte arrivata da Ugo', function () {
    [$s, $rita] = sucCongiunto();
    ruRegistra($this, $s, sucEstinzione($s, ['accrescimento' => true]));
    $rigaRita = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $rita->id)->whereNull('data_fine')->value('id');

    // € 1.200,00 × 122 / 365 = € 401,10: le quote di Rita e quelle di Ugo che dal 1° maggio sono sue.
    $a = ruAnteprima($this, $s, sucEstinzione(['rigaV' => $rigaRita] + $s, ['decorrenza' => '2026-09-01', 'quota' => 100]));
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBe(40110)
        ->and(array_unique(array_column($a['rate']['conguaglio']['coppie'], 'entrante_nome')))->toBe([$s['a']->nome])
        ->and($a['rate']['conguaglio']['non_risolte'])->toBe([]);
    ruRegistra($this, $s, sucEstinzione(['rigaV' => $rigaRita] + $s, ['decorrenza' => '2026-09-01', 'quota' => 100]));
    expect(sucSaldi($s))->toBe([$s['a']->nome => 40110, 'Rita Usufruttuaria' => 40274 - 40110, 'Venditore Ugo' => -40274]);
});

it('senza la casella resta il consolidamento di legge: la nuda di Elsa torna piena per la parte dell\'usufrutto che finisce, e il conguaglio va a lei', function () {
    [$s, $rita] = sucCongiunto();

    $a = ruAnteprima($this, $s, sucEstinzione($s));
    expect(array_column($a['rate']['conguaglio']['coppie'], 'entrante_nome'))->toBe([$s['a']->nome])
        ->and(array_column($a['rate']['conguaglio']['coppie'], 'importo'))->toBe([40274])
        ->and(implode(' ', $a['anagrafica']['frasi']))->toContain('spunta «L\'usufrutto si accresce all\'altro usufruttuario»');
});

it('la richiesta: l\'accrescimento solo all\'estinzione e con un altro usufruttuario; l\'estinzione senza nessun nudo proprietario si rifiuta', function () {
    $s = ruScenario('prima_rata', 0);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    // Nessun nudo e nessun altro usufruttuario.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucEstinzione($s, ['quota' => 100]))
        ->assertStatus(422)->assertJsonValidationErrors(['estinzione']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucEstinzione($s, ['quota' => 100, 'accrescimento' => true]))
        ->assertStatus(422)->assertJsonValidationErrors(['accrescimento']);
    $anna = sucErede($s, 'Anna Erede');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'proprietario']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucCorpo($s, [[$anna, 100]], extra: ['accrescimento' => true]))
        ->assertStatus(422)->assertJsonValidationErrors(['accrescimento']);
});

it('rilievo X1: l\'accrescimento a un usufruttuario la cui riga è già chiusa si ferma, e dice la strada', function (bool $aMano) {
    [$s, $rita] = sucCongiunto();
    $rigaRita = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $rita->id)->value('id');
    if ($aMano) {
        DB::table('anagrafica_immobile')->where('id', $rigaRita)->update(['data_fine' => '2026-08-31']);
    } else {
        // Rita muore il 1° settembre, senza accrescimento: la sua metà torna alla nuda di Elsa.
        ruRegistra($this, $s, sucEstinzione(['rigaV' => $rigaRita] + $s, ['decorrenza' => '2026-09-01']));
    }
    $prima = ruRighe($s['unita']->id);

    // Poi si registra la morte di Ugo, il 1° maggio, con l'accrescimento: prima la riga di Rita passava al 30 aprile e nasceva Rita
    // usufruttuaria al 100 % senza fine, accanto alla piena di Elsa dal 1° settembre.
    $errori = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), sucEstinzione($s, ['accrescimento' => true]))
        ->assertStatus(422)->assertJsonValidationErrors(['decorrenza'])->json('errors.decorrenza');
    expect(implode(' ', $errori))->toContain('Rita Usufruttuaria era usufruttuario di questa unità il 1 maggio 2026')
        ->toContain($aMano ? 'Correggi le righe a mano da «Modifica associazione»' : 'Annulla prima dallo storico dell\'unità i passaggi successivi');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), sucEstinzione($s, ['accrescimento' => true]))
        ->assertSessionHasErrors('decorrenza');
    expect(ruRighe($s['unita']->id))->toBe($prima);
})->with(['chiusa da un passaggio dopo' => [false], 'chiusa a mano' => [true]]);

it('rilievo X1: l\'erede già titolare la cui riga un passaggio registrato dopo ha chiuso si ferma, e così chi compra', function (string $tipo) {
    // Ugo e Rita proprietari al 50 %. Rita vende la nuda della sua metà a Elsa il 1° settembre, con la riserva; poi si registra un
    // passaggio di Ugo del 1° maggio a Rita. Prima la riga piena di Rita passava al 30 aprile e nasceva Rita piena al 100 % senza fine,
    // accanto al suo usufrutto e alla nuda di Elsa dal 1° settembre.
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM, 'v' => $madre] + $s, '2026-09-01', ['quota' => 50]));
    $prima = ruRighe($s['unita']->id);
    $corpo = $tipo === 'successione'
        ? sucCorpo($s, [[$madre, 50]], extra: ['quota' => 50])
        : ruPassaggio('vendita', $s['rigaV'], $madre, '2026-05-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'];
    $campo = $tipo === 'successione' ? 'eredi' : 'anagrafica_entrante_id';

    $errori = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors([$campo])->json("errors.{$campo}");
    expect(implode(' ', $errori))->toContain('Madre Rita è già proprietario di questa unità, e con un passaggio registrato dopo ha ceduto quella riga dal 1 settembre 2026');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasErrors($campo);
    expect(ruRighe($s['unita']->id))->toBe($prima);
})->with(['successione' => ['successione'], 'vendita' => ['vendita']]);

it('decisione 67 (2), rilievo X4: l\'accrescimento con la nuda di due nudi proprietari diversi si ferma con la via a mano', function () {
    // Ugo 25, Carlo 25 e Bruno 50 proprietari dal 2019; il 1° giugno 2025 tre riserve: Ugo e Carlo a Dora, Bruno a Ezio. Dora nuda 50,
    // Ezio nudo 50; Ugo e Carlo usufruttuari 25 sopra la nuda di Dora, Bruno 50 sopra quella di Ezio. Prima, alla morte di Ugo, la sua
    // parte andava a Carlo e a Bruno per quota (8,33 e 16,67), e Bruno finiva con l'usufrutto al 66,67 % sopra una nuda del 50 %.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 25]);
    $persone = [];
    foreach (['Carlo Usufruttuario' => 25, 'Bruno Usufruttuario' => 50] as $nome => $q) {
        $persone[$nome] = sucErede($s, $nome);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => $q, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $dora = sucErede($s, 'Dora Nuda');
    $ezio = sucErede($s, 'Ezio Nudo');
    $riga = fn (Anagrafica $p, string $ruolo) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', $ruolo)->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruRiserva(['a' => $dora] + $s, '2025-06-01', ['quota' => 25]));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $riga($persone['Carlo Usufruttuario'], 'proprietario'), 'v' => $persone['Carlo Usufruttuario'], 'a' => $dora] + $s, '2025-06-01', ['quota' => 25]));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $riga($persone['Bruno Usufruttuario'], 'proprietario'), 'v' => $persone['Bruno Usufruttuario'], 'a' => $ezio] + $s, '2025-06-01', ['quota' => 50]));
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, '2026-12-31');
    $prima = ruRighe($s['unita']->id);
    $corpo = sucEstinzione(['rigaV' => $riga($s['v'], 'usufruttuario')] + $s, ['decorrenza' => '2026-07-01', 'quota' => 25, 'accrescimento' => true]);

    $errori = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors(['accrescimento'])->json('errors.accrescimento');
    expect(implode(' ', $errori))->toContain('la nuda proprietà di questa unità è di più nudi proprietari (Dora Nuda e Ezio Nudo)')
        ->toContain('togli la spunta dell\'accrescimento')->toContain('«Modifica associazione»')->toContain('legato a più persone insieme');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasErrors('accrescimento');
    expect(ruRighe($s['unita']->id))->toBe($prima)
        ->and(sucSaldi($s))->toBe([]);

    // Senza la casella: la regola di legge, la parte di Ugo torna piena alla nuda che sta sotto il suo usufrutto. Fase 5 della .44: il
    // pannello non dice di spuntare una casella che con più nudi il modulo non mostra, e indica la strada a mano.
    $frasi = implode(' ', ruAnteprima($this, $s, array_merge($corpo, ['accrescimento' => false]))['anagrafica']['frasi']);
    expect($frasi)->toContain('con la nuda di più nudi proprietari non si registra da qui, e le righe si correggono a mano da «Modifica associazione»')
        ->not->toContain('spunta «L\'usufrutto si accresce');
});

it('rilievo X5: l\'usufrutto accresciuto conosce la sua origine, e l\'estinzione dopo tiene la scelta «come dice ogni voce»', function () {
    // Ugo e Rita al 50 %; il 1° marzo ognuno dona a Elsa la nuda della sua metà, con la riserva e l'ordinaria «come dice ogni voce».
    // Il 1° maggio muore Ugo, e il suo usufrutto si accresce a Rita: la sua riga al 100 % nasce dall'accrescimento.
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, '2026-03-01', ['quota' => 50, 'ordinaria_dopo_atto' => 'voce']));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM, 'v' => $madre] + $s, '2026-03-01', ['quota' => 50, 'ordinaria_dopo_atto' => 'voce']));
    $usufrutto = fn (Anagrafica $p) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, sucEstinzione(['rigaV' => $usufrutto($s['v'])] + $s, ['decorrenza' => '2026-05-01', 'quota' => 50, 'accrescimento' => true]));
    $rigaRita = $usufrutto($madre);
    expect((float) DB::table('anagrafica_immobile')->where('id', $rigaRita)->value('quota'))->toBe(100.0);

    // Prima l'origine si fermava al passaggio dell'accrescimento: null, e l'estinzione dopo perdeva la scelta.
    expect(Subentro::origineDellUsufrutto($rigaRita, $s['unita']->id)?->ordinariaComeLaVoce())->toBeTrue();
    $sub = ruRegistra($this, $s, sucEstinzione(['rigaV' => $rigaRita] + $s, ['decorrenza' => '2026-09-01', 'quota' => 100]));
    expect($sub->registro['ordinaria_dopo_atto'] ?? null)->toBe('voce');
});

it('decisione 67 (3), rilievo X6: un saldo del defunto che compone l\'arretrato agli eredi non si modifica né si cancella dal Wallet', function (string $arretrato, bool $bloccato) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $fonte = \App\Models\Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['v']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 30000, 'origine' => 'manuale', 'is_applicato' => false]);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $sub = ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: $arretrato, riferimento: $anna));

    // Con l'arretrato agli eredi i € 300,00 di Ugo sono dentro le righe degli eredi (€ 1.500,00 per quota); corretti a € 30,00 dal
    // Wallet, Ugo restava con € 270,00 di credito finto e gli eredi con il debito di prima.
    $modifica = $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $fonte->id]), ['saldo_iniziale' => 3000, 'gestione_id' => $s['g']->id]);
    if (! $bloccato) {
        $modifica->assertSessionHasNoErrors();
        expect((int) $fonte->fresh()->saldo_iniziale)->toBe(3000)
            ->and($sub->fresh()->registro['arretrato'])->not->toHaveKey('fonti');

        return;
    }
    $modifica->assertSessionHasErrors('saldo');
    expect(session('errors')->first('saldo'))->toContain('è fra le cifre da cui è calcolato l\'arretrato di Venditore Ugo')->toContain('annulla la successione')
        ->and($sub->fresh()->registro['arretrato']['fonti'])->toBe([$fonte->id]);
    $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $fonte->id]))->assertSessionHasErrors('saldo');
    expect((int) $fonte->fresh()->saldo_iniziale)->toBe(30000);

    // Annullata la successione, il saldo torna libero.
    ruAnnulla($this, $s, $sub)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $fonte->id]), ['saldo_iniziale' => 3000, 'gestione_id' => $s['g']->id])->assertSessionHasNoErrors();
})->with(['agli eredi' => ['eredi', true], 'a nome del defunto' => ['defunto', false]]);

it('decisione 67 (3), rilievo X6: il conguaglio di una vendita che compone l\'arretrato di una successione dopo non si annulla', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $vendita = ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti']);
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    ruRegistra($this, $s, sucCorpo(['rigaV' => $rigaElsa] + $s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $righe = DB::table('saldi')->where('subentro_id', $vendita->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($c) => (int) $c)->all();

    // La coppia della vendita è dentro la posizione netta di Elsa, e quindi nelle righe degli eredi: toglierla lasciava a Elsa un credito
    // finto, e agli eredi un debito calcolato su righe che non c'erano più.
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $vendita]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])
        ->assertSessionHasErrors('conguaglio');
    expect(session('errors')->first('conguaglio'))->toContain('è fra le cifre da cui è calcolato l\'arretrato di Acquirente Elsa')
        ->and(DB::table('saldi')->where('subentro_id', $vendita->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($c) => (int) $c)->all())->toBe($righe)
        ->and($vendita->fresh()->conguaglio_annullato_il)->toBeNull();
});

/** Una riga di titolarità scritta a mano sull'unità dello scenario. */
function sucRiga(array $s, Anagrafica $p, string $ruolo, float $quota, string $dal, ?string $al = null): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota,
        'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
}

/** Anteprima e registrazione dello stesso corpo: 422 sul campo, la frase, e le righe che non cambiano. */
function sucRifiuto($test, array $s, array $corpo, string $campo): string
{
    $prima = ruRighe($s['unita']->id);
    $errori = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors([$campo])->json("errors.{$campo}");
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasErrors($campo);
    expect(ruRighe($s['unita']->id))->toBe($prima);

    return implode(' ', $errori);
}

it('giro sulle correzioni, G1: la richiesta dice prima ciò che la rete ferma, per il nudo che torna pieno, per chi si riserva l\'usufrutto e per chi costituisce', function (string $caso) {
    $s = ruScenario('prima_rata', 0, genera: false);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    $dino = sucErede($s, 'Dino Nudo');
    if ($caso === 'estinzione') {
        // Elsa piena 50 e nuda 50 sotto l'usufrutto di Ugo; il 1° settembre Elsa vende con riserva la metà piena a Dino; poi la morte di
        // Ugo del 1° maggio: la nuda di Elsa torna piena e si sommerebbe alla piena che la riserva ha già chiuso.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
        $pienaElsa = sucRiga($s, $s['a'], 'proprietario', 50, '2019-01-01');
        sucRiga($s, $s['a'], 'nuda_proprietario', 50, '2019-01-01');
        ruRegistra($this, $s, ruPassaggio('riserva', $pienaElsa, $dino, '2026-09-01', 50) + $letto);
        $corpo = ruPassaggio('estinzione', $s['rigaV'], null, '2026-05-01', 50) + $letto;
    } elseif ($caso === 'riserva') {
        // Ugo piena 50 e usufruttuario 50 sopra la nuda di Elsa; il 1° settembre l'usufrutto di Ugo si estingue; poi la riserva di Ugo
        // del 1° maggio: l'usufrutto riservato si sommerebbe a quello che l'estinzione ha già chiuso.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
        $usufrutto = sucRiga($s, $s['v'], 'usufruttuario', 50, '2024-01-01');
        sucRiga($s, $s['a'], 'nuda_proprietario', 50, '2024-01-01');
        ruRegistra($this, $s, ruPassaggio('estinzione', $usufrutto, null, '2026-09-01', 50) + $letto);
        $corpo = ruPassaggio('riserva', $s['rigaV'], $dino, '2026-05-01', 50) + $letto;
    } else {
        // Elsa piena 50 e nuda 50 sotto Ugo; il 1° settembre Elsa vende la nuda a Dino; poi la costituzione del 1° maggio di Elsa a
        // Franco sulla metà piena: la nuda che resta a Elsa si sommerebbe a quella che la vendita ha già chiuso.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
        $pienaElsa = sucRiga($s, $s['a'], 'proprietario', 50, '2019-01-01');
        $nudaElsa = sucRiga($s, $s['a'], 'nuda_proprietario', 50, '2019-01-01');
        ruRegistra($this, $s, ruPassaggio('nuda', $nudaElsa, $dino, '2026-09-01', 50) + $letto);
        $corpo = ruPassaggio('costituzione', $pienaElsa, sucErede($s, 'Franco Usufruttuario'), '2026-05-01', 50) + $letto;
    }

    // Ultima revisione (UE3): quando il passaggio dopo è un atto fra vivi che ha ceduto proprio quella riga, rifarlo porterebbe tutta la
    // riga sommata, e la strada è a mano; con l'estinzione (la riserva) si rifanno i passaggi in ordine.
    expect(sucRifiuto($this, $s, $corpo, 'decorrenza'))->toContain($caso === 'riserva'
        ? 'un passaggio registrato dopo ha chiuso quella riga dal 1 settembre 2026'
        : 'con un passaggio registrato dopo ha ceduto quella riga dal 1 settembre 2026');
})->with(['estinzione', 'riserva', 'costituzione']);

it('giro sulle correzioni, G2: il nudo nato il giorno dell\'estinzione non riapre senza fine la sua piena che una riserva dopo ha chiuso', function () {
    // Elsa piena 50 (metà A) e Franco nudo 50 (metà B) sotto Ugo; il 1° maggio Franco vende la nuda a Elsa; il 1° settembre Elsa vende
    // con riserva la metà A a Dino. Poi la morte di Ugo, il 1° maggio: la nuda di Elsa nata quel giorno torna piena e chiudeva la piena.
    $s = ruScenario('prima_rata', 0, genera: false);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $pienaElsa = sucRiga($s, $s['a'], 'proprietario', 50, '2019-01-01');
    $franco = sucErede($s, 'Franco Nudo');
    $nudaFranco = sucRiga($s, $franco, 'nuda_proprietario', 50, '2019-01-01');
    ruRegistra($this, $s, ruPassaggio('nuda', $nudaFranco, $s['a'], '2026-05-01', 50) + $letto);
    ruRegistra($this, $s, ruPassaggio('riserva', $pienaElsa, sucErede($s, 'Dino Nudo'), '2026-09-01', 50) + $letto);

    expect(sucRifiuto($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-05-01', 50) + $letto, 'decorrenza'))
        ->toContain('con un passaggio registrato dopo ha ceduto quella riga dal 1 settembre 2026');
});

it('giro sulle correzioni, G25: la riga di chi esce che un passaggio registrato dopo ha chiuso sommandola non si richiude', function () {
    // Elsa e Franco pieni al 50 %; il 1° settembre Franco vende a Elsa (la riga di Elsa si chiude il 31 agosto e ne nasce una al 100 %);
    // poi la riserva del 1° maggio di Elsa, dalla riga di prima: la registrazione la richiudeva al 30 aprile, sotto il 100 % di dopo.
    $s = ruScenario('prima_rata', 0, genera: false);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaElsa = sucRiga($s, $s['a'], 'proprietario', 50, '2019-01-01');
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-09-01', 50) + $letto);

    expect(sucRifiuto($this, $s, ruPassaggio('riserva', $rigaElsa, sucErede($s, 'Dino Nudo'), '2026-05-01', 50) + $letto, 'riga_uscente_id'))
        ->toContain('La riga di Acquirente Elsa è stata chiusa dal 1 settembre 2026 da un passaggio registrato dopo');
});

it('ultima revisione, UD1: anche sulla pertinenza la riga di chi esce chiusa da un passaggio registrato dopo si rifiuta nell\'anteprima', function () {
    // Ugo ed Elsa al 50 % sull'appartamento e sul box. Il 1° settembre, dal box, Ugo vende a Elsa la sua metà del box (la riga di Elsa
    // sul box si chiude il 31 agosto). Poi la riserva del 1° maggio di Elsa sull'appartamento, con il box spuntato.
    $s = ruScenario('prima_rata', 0, genera: false);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaElsa = sucRiga($s, $s['a'], 'proprietario', 50, '2019-01-01');
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $sulBox = fn (Anagrafica $p) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $boxUgo = $sulBox($s['v']);
    $sulBox($s['a']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $box]), ruPassaggio('vendita', $boxUgo, $s['a'], '2026-09-01', 50) + $letto)
        ->assertSessionHasNoErrors();

    expect(sucRifiuto($this, $s, ruPassaggio('riserva', $rigaElsa, sucErede($s, 'Dino Nudo'), '2026-05-01', 50, [$box->id]) + $letto, 'pertinenze'))
        ->toContain('Box 12: la riga di Acquirente Elsa è stata chiusa dal 1 settembre 2026 da un passaggio registrato dopo');
});

it('ultima revisione, UD2: il nudo nato prima che torna pieno non si somma alla piena nata il giorno dell\'estinzione e chiusa dopo', function () {
    // Ugo usufruttuario 50 sulla nuda di Elsa (2019); il 1° maggio Franco vende a Elsa la sua metà piena (Elsa piena 50 dal 1° maggio);
    // il 1° settembre Elsa vende con riserva quella metà a Dino. Poi la morte di Ugo del 1° maggio.
    $s = ruScenario('prima_rata', 0, genera: false);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    sucRiga($s, $s['a'], 'nuda_proprietario', 50, '2019-01-01');
    $franco = sucErede($s, 'Franco Pieno');
    ruRegistra($this, $s, ruPassaggio('vendita', sucRiga($s, $franco, 'proprietario', 50, '2019-01-01'), $s['a'], '2026-05-01', 50) + $letto);
    $pienaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('riserva', $pienaElsa, sucErede($s, 'Dino Nudo'), '2026-09-01', 50) + $letto);

    expect(sucRifiuto($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-05-01', 50) + $letto, 'decorrenza'))
        ->toContain('Acquirente Elsa è già proprietario di questa unità');
});

it('giro sulle correzioni, G3: l\'erede la cui riga una somma registrata dopo ha chiuso sente la strada dei passaggi, non quella a mano', function () {
    // Ugo 25, Rita 25, Carla 50. Carla muore il 1° settembre, erede Rita: la riga di Rita si chiude il 31 agosto e ne nasce una al 75 %.
    // Poi Ugo, morto il 1° maggio, erede Rita: prima la frase diceva «una data di fine scritta a mano».
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 25]);
    $rita = sucErede($s, 'Rita Erede');
    $carla = sucErede($s, 'Carla Sorella');
    sucRiga($s, $rita, 'proprietario', 25, '2019-01-01');
    $rigaCarla = sucRiga($s, $carla, 'proprietario', 50, '2019-01-01');
    ruRegistra($this, $s, sucCorpo(['rigaV' => $rigaCarla] + $s, [[$rita, 50]], decesso: '2026-09-01', extra: ['quota' => 50]));

    expect(sucRifiuto($this, $s, sucCorpo($s, [[$rita, 25]], extra: ['quota' => 25]), 'eredi'))
        ->toContain('Rita Erede è già proprietario di questa unità, e un passaggio registrato dopo ha chiuso quella riga dal 1 settembre 2026')
        ->not->toContain('scritta a mano');
});

it('decisione 68 (1): l\'usufrutto accresciuto tiene la scelta del suo, e il pannello dice quando la parte arrivata era nata con l\'altra', function () {
    // Ugo e Rita al 50 %; il 1° marzo donano a Elsa: Ugo «come dice ogni voce», Rita con la regola di legge. Il 1° maggio muore Ugo, con
    // l'accrescimento: Rita usufruttuaria al 100 %. All'estinzione di Rita vale la sua scelta, e il pannello lo dice.
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, '2026-03-01', ['quota' => 50, 'ordinaria_dopo_atto' => 'voce']));
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM, 'v' => $madre] + $s, '2026-03-01', ['quota' => 50, 'ordinaria_dopo_atto' => 'usufruttuario']));
    $usufrutto = fn (Anagrafica $p) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, sucEstinzione(['rigaV' => $usufrutto($s['v'])] + $s, ['decorrenza' => '2026-05-01', 'quota' => 50, 'accrescimento' => true]));

    $a = ruAnteprima($this, $s, sucEstinzione(['rigaV' => $usufrutto($madre)] + $s, ['decorrenza' => '2026-09-01', 'quota' => 100]));
    expect(implode(' | ', $a['cancello']['avvisi']))->toContain('per l\'ordinaria vale la scelta dell\'usufrutto di Madre Rita, la regola di legge, anche per la parte arrivata il 1 maggio 2026 da Venditore Ugo, che era nata «come dice ogni voce»');
});

it('decisione 68 (2): senza le righe di riparto, la catena nonno → figli → nipoti porta a chi vende la parte moltiplicata', function () {
    // Ugo proprietario al 100 %, dodici rate emesse, senza righe di riparto. Il 1° marzo muore Ugo: eredi Mario e Zia al 50 %. Il 1° maggio
    // Mario vende la sua metà a Pino. Il 1° luglio muore Pino: eredi Anna e Bice al 50 % della sua metà. Il 1° settembre Anna vende a Carlo.
    $s = ruScenario('prima_rata', 0);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    ruEmetti($s, '2026-12-31');
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Atto letto dalle parti'];
    $riga = fn (Anagrafica $p) => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');
    [$mario, $zia, $pino, $anna, $bice] = array_map(fn ($n) => sucErede($s, $n), ['Mario Figlio', 'Zia Figlia', 'Pino Compratore', 'Anna Nipote', 'Bice Nipote']);
    ruRegistra($this, $s, sucCorpo($s, [[$mario, 50], [$zia, 50]], decesso: '2026-03-01'));
    ruRegistra($this, $s, ruPassaggio('vendita', $riga($mario), $pino, '2026-05-01', 50) + $letto);
    ruRegistra($this, $s, sucCorpo(['rigaV' => $riga($pino)] + $s, [[$anna, 25], [$bice, 25]], decesso: '2026-07-01', extra: ['quota' => 50]));

    // Per competenza: € 1.200,00 × 25 % × 122/365 = € 100,27. Prima le quote di Ugo contavano per la metà (0,5 invece di 0,25).
    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $riga($anna), sucErede($s, 'Carlo Compratore'), '2026-09-01', 25) + $letto);
    expect(array_sum(array_column($a['rate']['conguaglio']['coppie'], 'importo')))->toBeGreaterThanOrEqual(10026)->toBeLessThanOrEqual(10028);
});

it('rilievo X7: con l\'arretrato agli eredi una bozza già pagata in parte resta al defunto, e la sua parte non versata entra nell\'arretrato', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    // La rata 5 (5 maggio), ancora in bozza, con € 50,00 versati da Ugo.
    $rata5 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 5)->value('id');
    DB::table('rate_quote')->where('rata_id', $rata5)->where('anagrafica_id', $s['v']->id)->update(['importo_pagato' => 5000, 'stato' => 'parzialmente_pagata']);
    $corpo = sucCorpo($s, [[$anna, 60], [$bruno, 40]], decesso: '2026-07-01', riferimento: $anna);

    // Prima: la bozza passava, e la registrazione si fermava ogni volta con «non è più una bozza».
    $a = ruAnteprima($this, $s, $corpo);
    expect($a['rate']['conguaglio']['bozze_riassegnate'])->toHaveCount(7)
        ->and(collect($a['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->all())->toBe(['pagata']);
    // P = € 400,00 + € 50,00, B = € 700,00: per erede € 1.150,00 × 60 % − € 700,00 = − € 10,00 e € 460,00; la coppia − € 337,04 e
    // € 241,97; l'arretrato € 327,04 e € 218,03.
    expect(collect($a['rate']['arretrato']['eredi'])->mapWithKeys(fn ($e) => [$e['nome'] => $e['importo']])->all())->toBe(['Anna Erede' => 32704, 'Bruno Erede' => 21803]);
    ruRegistra($this, $s, $corpo);
    expect((int) DB::table('rate_quote')->where('rata_id', $rata5)->where('anagrafica_id', $s['v']->id)->value('importo_pagato'))->toBe(5000)
        ->and(sucSaldi($s))->toBe(['Anna Erede' => -1000, 'Bruno Erede' => 46000, 'Venditore Ugo' => -45000]);
});

it('rilievo X7: con l\'arretrato agli eredi una bozza con un pagamento segnalato dal portale resta al defunto, e la segnalazione non si perde', function () {
    $s = ruScenario('prima_rata', 0);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $rata6 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 6)->value('id');
    $evento = \App\Models\Evento::whereJsonContains('meta->context->rata_id', $rata6)->where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->firstOrFail();
    $meta = $evento->meta;
    $meta['status'] = 'reported';
    $evento->meta = $meta;
    $evento->save();
    $corpo = sucCorpo($s, [[$anna, 60], [$bruno, 40]], decesso: '2026-07-01', riferimento: $anna);

    $a = ruAnteprima($this, $s, $corpo);
    expect(collect($a['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->all())->toBe(['segnalata']);
    ruRegistra($this, $s, $corpo);
    expect((int) DB::table('rate_quote')->where('rata_id', $rata6)->value('anagrafica_id'))->toBe($s['v']->id)
        ->and($evento->fresh()->meta['status'] ?? null)->toBe('reported');
});

/** La riga in corso di un erede sull'unità dello scenario. */
function cdcRigaSuc(array $s, Anagrafica $p, string $ruolo = 'proprietario'): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $p->id)->where('tipologia', $ruolo)
        ->whereNull('data_fine')->orderByDesc('id')->value('id');
}

it('decisione 69 (2): con l\'arretrato agli eredi la rinuncia si rifiuta senza mandare alla rinuncia, e il pannello avvisa che le rate emesse al defunto non vanno incassate', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $rinuncia = ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro'];
    $frase = sucRifiuto($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna, extra: $rinuncia), 'rinuncia_conguaglio');
    // 1.11.0-beta.48 (decisione 72): la strada per non scrivere il conguaglio è l'arretrato a nome del defunto.
    expect($frase)->toContain('per non scrivere il conguaglio lascia l\'arretrato a nome del defunto');
    expect($frase)->not->toContain('rinuncia al conguaglio');
    expect($frase)->not->toContain('regolato');

    // Le quattro rate emesse a Ugo restano a suo nome, da pagare: il pannello lo dice prima, con la cifra.
    $a = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    expect(implode(' | ', $a['cancello']['avvisi']))->toContain('le rate di Venditore Ugo non pagate che restano a suo nome (€ 400,00) hanno il loro importo già nelle righe di saldo degli eredi, del conguaglio e dell\'arretrato: un pagamento su quelle rate, anche registrato con «Versato da», va a credito di Venditore Ugo e non riduce il debito di chi versa');
    // Con l'arretrato a nome del defunto è proprio lì che si incassa: nessun avviso.
    $b = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], arretrato: 'defunto', riferimento: $anna));
    expect(implode(' | ', $b['cancello']['avvisi']))->not->toContain('non riduce il debito di chi versa');

    // Il fatto su cui l'avviso si regge: dopo la registrazione le quote di Ugo restano da pagare (€ 400,00) e le sue righe in saldi
    // sono un credito della stessa cifra. Un incasso su quelle quote lo lascerebbe a credito.
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    $aperte = (int) DB::table('rate_quote')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['v']->id)
        ->whereIn('stato', ['da_pagare', 'parzialmente_pagata'])->sum(DB::raw('importo - importo_pagato'));
    expect($aperte)->toBe(40000)->and(sucSaldi($s)['Venditore Ugo'])->toBe(-40000);
});

it('decisione 69 (2): con un solo erede e l\'arretrato agli eredi, il pannello avvisa che le quote sull\'unità possono non essere quelle dell\'eredità', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    $a = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 100]]));
    expect(implode(' | ', $a['cancello']['avvisi']))->toContain('l\'arretrato va tutto ad Anna Erede, l\'unico erede registrato su questa unità. Se l\'eredità ha altri eredi (un testamento o una divisione che assegna l\'unità a un erede solo), di quel debito risponde ogni erede per la sua quota ereditaria (art. 754 c.c.): in quel caso lascialo a nome di Venditore Ugo');
    // Con due eredi le quote sull'unità sono di solito quelle dell'eredità: nessun avviso.
    $b = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));
    expect(implode(' | ', $b['cancello']['avvisi']))->not->toContain('l\'unico erede registrato');
    // A nome del defunto non c'è niente che vada all'erede.
    $c = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 100]], arretrato: 'defunto'));
    expect(implode(' | ', $c['cancello']['avvisi']))->not->toContain('l\'unico erede registrato');

    // Giro sulla decisione 69 (S3): a zero nessun avviso (il piano che si ricalcola ancora, nessuna quota ferma)…
    $z = ruScenario('prima_rata', 0);
    $erede = sucErede($z, 'Erede Unico');
    expect(implode(' | ', ruAnteprima($this, $z, sucCorpo($z, [[$erede, 100]]))['cancello']['avvisi']))->not->toContain('l\'unico erede registrato');
    // …e con un credito la frase non dice che se ne «risponde» (GC1: Ugo paga fino al decesso, credito di € 5,48).
    $k = ruScenario('prima_rata', 0);
    ruEmetti($k, '2026-12-31');
    $rate = DB::table('rate')->where('piano_rate_id', $k['piano']->id)->orderBy('numero_rata')->pluck('id', 'numero_rata');
    DB::table('rate_quote')->whereIn('rata_id', $rate->only([1, 2, 3, 4])->values())->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    $unica = sucErede($k, 'Erede Unica');
    $avvisi = implode(' | ', ruAnteprima($this, $k, sucCorpo($k, [[$unica, 100]]))['cancello']['avvisi']);
    expect($avvisi)->toContain('il credito di Venditore Ugo va tutto a Erede Unica, l\'unico erede registrato su questa unità')
        ->toContain('il credito è dell\'eredità, e si divide con le quote ereditarie')
        ->not->toContain('di quel debito risponde');
    // Lo stesso caso per l'avviso sulle rate aperte: gli € 800,00 non pagati passano con il conguaglio, non con l'arretrato (S1).
    expect($avvisi)->toContain('le rate di Venditore Ugo non pagate che restano a suo nome (€ 800,00) hanno il loro importo già nelle righe di saldo degli eredi, del conguaglio e dell\'arretrato');
});

it('giro sulla decisione 69 (S4): la cifra delle rate aperte del defunto è quella dell\'arretrato, anche con una bozza pagata in parte che resta a suo nome', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    // Lo scenario di X7: la rata 5 in bozza con € 50,00 versati resta a Ugo; a suo nome restano aperti € 400,00 + € 50,00.
    $rata5 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 5)->value('id');
    DB::table('rate_quote')->where('rata_id', $rata5)->where('anagrafica_id', $s['v']->id)->update(['importo_pagato' => 5000, 'stato' => 'parzialmente_pagata']);
    $a = ruAnteprima($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], decesso: '2026-07-01', riferimento: $anna));
    expect(implode(' | ', $a['cancello']['avvisi']))->toContain('le rate di Venditore Ugo non pagate che restano a suo nome (€ 450,00)');
});

it('Fase 5 della .44: con più eredi lo storico dà il conguaglio di ciascuno, non la somma, e quello di chi esce', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sucErede($s, 'Anna Erede');
    $bruno = sucErede($s, 'Bruno Erede');
    ruRegistra($this, $s, sucCorpo($s, [[$anna, 60], [$bruno, 40]], riferimento: $anna));

    $c = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['conguaglio'];
    // Le coppie: Anna − € 316,71 (le bozze che riceve superano la sua parte), Bruno € 322,19; la somma, € 5,48, è il credito del defunto.
    expect($c['per_entrante'])->toBe(['€ 316,71 a credito di Anna Erede', '€ 322,19 a debito di Bruno Erede'])
        ->and($c['importo'])->toBe(548);

    // Con una persona sola che entra la riga di ciascuno non serve.
    $v = ruScenario('prima_rata', 0);
    ruEmetti($v);
    ruRegistra($this, $v, ruPassaggio('vendita', $v['rigaV'], $v['a'], '2026-05-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto']);
    expect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($v['unita']->fresh())['subentri'][0]['conguaglio']['per_entrante'])->toBe([]);
});
