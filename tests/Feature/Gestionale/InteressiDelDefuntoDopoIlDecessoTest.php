<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisioni 479 (3) e 483 (Coda 663): con la successione registrata, gli interessi del defunto dopo il decesso. Nella pagina della successione, sotto la
 * scelta dell'arretrato, «si fermano al decesso» (di serie con l'arretrato agli eredi) o «continuano a suo nome» (di serie con l'arretrato a nome del
 * defunto). Con la prima, nella proposta degli interessi del defunto l'ultimo giorno contato, sulle rate delle unità del passaggio, è il giorno prima del
 * decesso; con la seconda, come prima. La scelta si salva con il passaggio (la colonna `subentri.interessi_del_defunto` e il registro), lo storico la dice,
 * l'annullamento la toglie; le successioni già registrate prendono il di serie della loro strada.
 *
 * Il caso della bozza approvata: Ettore Fabbri (Neri di Via Verdi, `AccessoriScenario.php`, rinominato) non ha pagato la rata di aprile, € 300,00, scaduta
 * il 30/04/2026 (la rata 1 del piano, spostata dal 31/03 al 30/04); muore il 17/11/2026, e la successione di U1 passa ai figli Elena e Marco al 50 %. Una
 * regola del recupero del condominio con un tasso scritto del 2 % l'anno dal 01/01/2026, senza giorni di decorrenza: gli interessi corrono dal 01/05. Le
 * cifre sono quelle del motore (`InteressiDiMora`: capitale × tasso × giorni / 365, arrotondato al centesimo alla fine): al 31/12 senza fermarsi
 * 30.000 × 2 % × 245 giorni (01/05–31/12) / 365 = 402,739726 → € 4,03; fermandosi al giorno prima del decesso 30.000 × 2 % × 200 giorni (01/05–16/11) / 365
 * = 328,767123 → € 3,29.
 *
 * Cosa NON copre: le prove con l'accordo di rientro in regola e con «vale come una decadenza» (decisione 454), che stanno in
 * `AccordoDelDefuntoInRegolaTest.php`; la card della pagina (vitest `InteressiDelDefunto.test.ts` e `PassaggioNewInteressiDelDefunto.test.ts`); una pertinenza
 * spuntata registrata dalla pagina (la scelta sul suo passaggio la prova solo la migrazione, con una riga figlia scritta a mano); la proposta degli interessi
 * della posizione degli eredi oltre alla cifra al 31/12 (decisione 489: le prove stanno in `InteressiSulDebitoEreditatoTest.php`).
 */

use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Via Verdi con Ettore Fabbri (Neri) e la sua rata 1 di € 300,00 scaduta il 30/04/2026, non pagata; il tasso scritto del 2 % dal 01/01/2026. */
function iddCaso($test): array
{
    $s = accViaVerdi($test);
    $s['persone']['Neri']->forceFill(['nome' => 'Ettore Fabbri'])->save();
    $rata = lcdRata($s, 1);
    DB::table('rate')->where('id', $rata)->update(['data_scadenza' => '2026-04-30']);
    DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => '2026-04-30']);
    RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2026-01-01', 'tasso_tipo' => RegolaRecupero::TASSO_SCRITTO, 'tasso_annuo' => '2.0000',
        'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'giorni_decorrenza' => 0]);

    return $s;
}

function iddPersona(array $s, string $nome): \App\Models\Anagrafica
{
    $email = strtolower($nome) . '-idd-' . $s['c']->id . '@test.it';
    if (($gia = \App\Models\Anagrafica::where('email', $email)->first()) !== null) {
        return $gia;
    }
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => $email, 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'IDD' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * La successione di chi esce (di serie Ettore su U1) dal 17/11/2026 a due eredi al 50 %, con la strada dell'arretrato data e, se data, la scelta sugli
 * interessi. Con l'arretrato a nome del defunto «Scrivi il conguaglio», come la vuole la pagina quando il pannello propone le coppie.
 */
function iddSuccessione(array $s, string $arretrato, ?string $interessi = null, string $chi = 'Neri', array $eredi = ['Elena', 'Marco']): array
{
    [$a, $b] = array_map(fn (string $n) => iddPersona($s, $n), $eredi);

    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe'][$chi], 'decorrenza' => '2026-11-17', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $a->id, 'quota' => 50], ['anagrafica_id' => $b->id, 'quota' => 50]], 'arretrato' => $arretrato, 'erede_di_riferimento' => $a->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta']
        + ($arretrato === Subentro::ARRETRATO_AL_DEFUNTO ? ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO] : [])
        + ($interessi !== null ? ['interessi_del_defunto' => $interessi] : []);
}

/** Registra la successione il 20/11/2026, dall'unità data (di serie U1), e la restituisce. */
function iddRegistra($test, array $s, array $dati, string $unita = 'U1'): Subentro
{
    $test->travelTo('2026-11-20 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita'][$unita]]), $dati)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita'][$unita]->id)->latest('id')->firstOrFail();
}

/** La riga della rata 1 di chi è dato (di serie Ettore) nella proposta degli interessi al 31/12/2026: [interessi, ultimo giorno]; null senza riga. */
function iddRiga($test, array $s, string $chi = 'Neri'): ?array
{
    $test->travelTo('2026-12-31 10:00:00');
    $p = accDi(accProposta($test, $s, [$chi], '2026-12-31')->assertOk()->json(), $s, $chi);
    $r = collect($p['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));

    return $r === null ? null : [$r['interessi'], $r['al']];
}

/** L'anteprima della successione il 20/11/2026: l'arretrato del pannello. */
function iddAnteprima($test, array $s, array $dati)
{
    $test->travelTo('2026-11-20 09:00:00');

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati);
}

it('prima della successione la proposta di Ettore conta tutti i giorni: € 4,03 al 31/12', function () {
    // La guardia del caso: senza successione la regola nuova non tocca niente (verde anche con il codice di prima).
    $s = iddCaso($this);
    expect(iddRiga($this, $s))->toBe([403, '2026-12-31']);
});

it('(a) arretrato agli eredi, la scelta di serie «si fermano al decesso»: la proposta di Ettore finisce il 16/11, € 3,29; la posizione degli eredi conta il debito ereditato dal decesso (decisione 489)', function () {
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI));
    expect($passaggio->interessi_del_defunto)->toBe(Subentro::INTERESSI_AL_DECESSO)
        ->and($passaggio->registro['arretrato']['interessi_del_defunto'])->toBe(Subentro::INTERESSI_AL_DECESSO);
    // Con il codice di prima: [403, '2026-12-31'].
    expect(iddRiga($this, $s))->toBe([329, '2026-11-16']);
    // Decisione 489 (1.12.0-beta.12, dopo la Coda 663): il debito ereditato sta nelle righe di saldo di Elena e Marco, fuori dal giornale, e la loro proposta
    // lo conta dal decesso, ciascuno per la sua parte: € 150,00 dal 17/11 al 31/12, € 0,37 a testa. Le prove sono in `InteressiSulDebitoEreditatoTest.php`.
    $this->travelTo('2026-12-31 10:00:00');
    $perEredi = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.interessi.proposta', [$s['c']]) . '?' . http_build_query([
        'alla' => '2026-12-31', 'persone' => [iddPersona($s, 'Elena')->id, iddPersona($s, 'Marco')->id]]))->assertOk()->json('persone');
    expect(collect($perEredi)->whereIn('anagrafica_id', [iddPersona($s, 'Elena')->id, iddPersona($s, 'Marco')->id])->pluck('totale')->all())->toBe([37, 37]);
});

it('(b) arretrato agli eredi con «continuano a suo nome»: la proposta di Ettore conta tutti i giorni, come prima, € 4,03', function () {
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, Subentro::INTERESSI_CONTINUANO));
    expect($passaggio->interessi_del_defunto)->toBe(Subentro::INTERESSI_CONTINUANO)
        ->and(iddRiga($this, $s))->toBe([403, '2026-12-31']);
});

it('(c) arretrato a nome del defunto, la scelta di serie «continuano a suo nome»: come prima, € 4,03', function () {
    // Guardia: con il codice di prima è già così (verde anche prima); qui si prova che la scelta di serie con questa strada non cambia niente.
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO));
    expect($passaggio->interessi_del_defunto)->toBe(Subentro::INTERESSI_CONTINUANO)
        ->and($passaggio->registro['arretrato']['interessi_del_defunto'])->toBe(Subentro::INTERESSI_CONTINUANO)
        ->and(iddRiga($this, $s))->toBe([403, '2026-12-31']);
});

it('(d) arretrato a nome del defunto con «si fermano al decesso»: la proposta di Ettore si ferma al 16/11, € 3,29', function () {
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::INTERESSI_AL_DECESSO));
    expect($passaggio->interessi_del_defunto)->toBe(Subentro::INTERESSI_AL_DECESSO)
        // Con il codice di prima: [403, '2026-12-31'].
        ->and(iddRiga($this, $s))->toBe([329, '2026-11-16']);
});

it('l\'anteprima dice la scelta che vale, di serie quella della strada, con la frase della bozza approvata', function () {
    $s = iddCaso($this);
    $arretrato = fn (string $strada, ?string $interessi = null) => iddAnteprima($this, $s, iddSuccessione($s, $strada, $interessi))->assertOk()->json('rate.arretrato');

    $eredi = $arretrato(Subentro::ARRETRATO_AGLI_EREDI);
    expect($eredi['interessi_del_defunto'])->toBe('al_decesso')
        ->and($eredi['frase_interessi_del_defunto'])->toBe('Dal 17/11/2026 la proposta degli interessi di Ettore Fabbri non conta altri giorni: il debito è passato a Elena e '
            . 'Marco, e gli interessi li propone la loro posizione, sulle loro rate.');
    expect($arretrato(Subentro::ARRETRATO_AGLI_EREDI, 'continuano')['frase_interessi_del_defunto'])
        ->toBe('La proposta degli interessi di Ettore Fabbri continua a contare i giorni anche dopo il decesso, il 17/11/2026, sulle sue rate non pagate: gli interessi '
            . 'restano a suo nome, anche se il debito è passato a Elena e Marco.');
    $defunto = $arretrato(Subentro::ARRETRATO_AL_DEFUNTO);
    expect($defunto['interessi_del_defunto'])->toBe('continuano')
        ->and($defunto['frase_interessi_del_defunto'])->toBe('La proposta degli interessi di Ettore Fabbri continua a contare i giorni anche dopo il decesso, il 17/11/2026, '
            . 'sulle sue rate non pagate: l\'arretrato e i suoi interessi restano a suo nome («eredi di Ettore Fabbri»).');
    expect($arretrato(Subentro::ARRETRATO_AL_DEFUNTO, 'al_decesso')['frase_interessi_del_defunto'])
        ->toBe('Dal 17/11/2026 la proposta degli interessi di Ettore Fabbri non conta altri giorni: l\'arretrato resta a suo nome («eredi di Ettore Fabbri»), senza '
            . 'interessi dopo il decesso.');
});

it('(h) lo storico dice la scelta; l\'annullamento la toglie, e la proposta di Ettore torna a contare tutti i giorni', function () {
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI));
    $storico = fn () => collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    expect((string) $storico()['arretrato']['frase'])->toContain('Gli interessi di Ettore Fabbri si fermano al decesso: la sua proposta degli interessi conta i giorni fino al 16/11/2026.');

    $this->travelTo('2026-11-21 10:00:00');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $passaggio = Subentro::conAnnullati()->findOrFail($passaggio->id);
    expect($passaggio->annullato())->toBeTrue()
        ->and($passaggio->interessi_del_defunto)->toBeNull()
        // Il registro ricorda che cosa era stato scelto.
        ->and($passaggio->registro['arretrato']['interessi_del_defunto'])->toBe(Subentro::INTERESSI_AL_DECESSO)
        ->and((string) ($storico()['arretrato']['frase'] ?? ''))->not->toContain('si fermano al decesso')
        ->and(iddRiga($this, $s))->toBe([403, '2026-12-31']);
});

it('(h2) lo storico con l\'arretrato a nome del defunto e «si fermano al decesso» dice anche che dopo il decesso nessuno conta interessi su quel debito (Coda 709); con l\'arretrato agli eredi niente cambia', function () {
    $s = iddCaso($this);
    $storico = fn (Subentro $p) => collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $p->id);

    $alDefunto = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::INTERESSI_AL_DECESSO));
    expect((string) $storico($alDefunto)['arretrato']['frase'])
        ->toContain('Gli interessi di Ettore Fabbri si fermano al decesso: la sua proposta degli interessi conta i giorni fino al 16/11/2026: dopo il decesso nessuno conta interessi su questo debito.');

    // Con l'arretrato agli eredi li contano gli eredi: la frase è quella di sempre, senza l'aggiunta.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $alDefunto]), ['nota_annullamento' => 'Prova di annullamento'])->assertRedirect();
    $agliEredi = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI));
    $frase = (string) $storico($agliEredi)['arretrato']['frase'];
    expect($frase)->toContain('Gli interessi di Ettore Fabbri si fermano al decesso: la sua proposta degli interessi conta i giorni fino al 16/11/2026.')
        ->and($frase)->not->toContain('nessuno conta interessi');
});

it('lo storico con «continuano a suo nome» lo dice con il giorno del decesso', function () {
    $s = iddCaso($this);
    $passaggio = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO));
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    expect((string) $storico['arretrato']['frase'])->toContain('Gli interessi di Ettore Fabbri continuano a suo nome dopo il decesso: la sua proposta degli interessi conta i '
        . 'giorni anche dopo il 17/11/2026.');
});

it('(i) il server rifiuta una scelta fuori dalle due voci, e la scelta in un passaggio che non è una successione', function () {
    $s = iddCaso($this);
    iddAnteprima($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, 'forse'))->assertStatus(422)
        ->assertJsonValidationErrors(['interessi_del_defunto' => 'Per gli interessi del defunto dopo il decesso scegli «si fermano al decesso» o «continuano a suo nome».']);
    $this->travelTo('2026-11-20 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]),
        iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, 'forse'))->assertSessionHasErrors(['interessi_del_defunto']);
    expect(Subentro::where('immobile_id', $s['unita']['U1']->id)->exists())->toBeFalse();

    $compratore = iddPersona($s, 'Gino');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righe']['Neri'], 'anagrafica_entrante_id' => $compratore->id, 'decorrenza' => '2026-11-17', 'quota' => 100,
        'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'interessi_del_defunto' => 'al_decesso',
    ])->assertStatus(422)->assertJsonValidationErrors(['interessi_del_defunto' => 'La scelta sugli interessi del defunto dopo il decesso c\'è solo nella successione.']);
});

it('(g) le successioni già registrate prendono il di serie della loro strada: agli eredi «si fermano al decesso», a nome del defunto «continuano»; l\'annullata no; rilanciata non cambia niente', function () {
    $s = iddCaso($this);
    // Ettore su U1 con l'arretrato agli eredi; Bianchi su U2 a nome del defunto; Verdi su U3 agli eredi, poi annullata.
    $agliEredi = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI));
    $alDefunto = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, null, 'Bianchi', ['Paola', 'Sara']), 'U2');
    $annullata = iddRegistra($this, $s, iddSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, 'Verdi', ['Ugo', 'Ivo']), 'U3');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U3'], $annullata]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    // La riga di una pertinenza spuntata con la successione di Ettore, scritta a mano: la migrazione la riallinea con il passaggio dell'unità.
    $figlia = DB::table('subentri')->insertGetId(collect((array) DB::table('subentri')->where('id', $agliEredi->id)->first())
        ->except(['id', 'registro'])->merge(['subentro_padre_id' => $agliEredi->id, 'created_at' => now(), 'updated_at' => now()])->all());

    // Lo stato di chi aggiorna: le successioni registrate prima, senza la scelta. La proposta di Ettore conta tutti i giorni.
    DB::table('subentri')->update(['interessi_del_defunto' => null]);
    expect(iddRiga($this, $s))->toBe([403, '2026-12-31']);

    $migrazione = require database_path('migrations/2026_11_18_170100_riallinea_gli_interessi_delle_successioni_registrate.php');
    $migrazione->up();
    $scelte = fn () => DB::table('subentri')->whereIn('id', [$agliEredi->id, $figlia, $alDefunto->id, $annullata->id])->orderBy('id')->pluck('interessi_del_defunto', 'id')->all();
    $attese = [$agliEredi->id => 'al_decesso', $alDefunto->id => 'continuano', $annullata->id => null, $figlia => 'al_decesso'];
    ksort($attese);
    expect($scelte())->toBe($attese)
        // Il cambio per chi aggiorna: con l'arretrato agli eredi la proposta del defunto, anche per una successione di prima, si ferma al decesso.
        ->and(iddRiga($this, $s))->toBe([329, '2026-11-16']);

    $migrazione->up();
    expect($scelte())->toBe($attese);
});
