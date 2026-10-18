<?php

/**
 * Ogni id che la fattura riceve dal browser sta nel condominio dell'indirizzo, in modifica come in registrazione.
 *
 * `righe.*.conto_id` e `righe.*.immobile_id` di `UpdateFatturaRequest` si validano con il condominio dell'indirizzo, come in registrazione. Senza, la modifica
 * sposta i costi dal libro di un condominio a quello di un altro (costi del primo da € 100,00 a zero, del secondo da zero a € 100,00) e con l'unità altrui scrive
 * un DARE su «Crediti verso condomini» con un'unità che non è sua. Lo stesso vale per i campi gemelli (`conto_corrente_id`,
 * `dati_extra.override_budget.fondo_patrimoniale_id`, `saldo_patrimoniale_id`, `coperture.*.fonte_id`): con un id altrui, e con `fonte_id` di una copertura per
 * sopravvenienza che con lo storno scrive DARE e AVERE sul mastro e sulla voce dell'altro condominio. È un difetto di integrità dei dati (nel pannello il
 * collaboratore vede tutti i condomini), non di riservatezza. Il fondo è un fondo (una cassa di tipo fondo del condominio, non un mastro di banca) e il debito è un
 * debito del condominio; un elenco al posto dell'id è un errore sul campo e non il testo della query.
 *
 * Il test negativo asserisce il rifiuto sul campo, la fattura com'era e i due libri: lo stato patrimoniale dei due condomini non cambia e nessuna riga di
 * giornale porta un mastro, una voce o un'unità dell'altro.
 *
 * Gli id si leggono come li legge la regola `integer`: «+5» e 5.0 valgono 5 come 5, e il perimetro li controlla come l'id intero (altrimenti la regola li accetta
 * e il controllo sul tipo o sul condominio li salta). Quando l'id non è valido il rifiuto dice quale scelta non lo è, campo per campo.
 *
 * Fonte dell'atteso: i conti a mano di ogni test e la regola sulla sicurezza del flusso (ogni id dal browser, con il perimetro).
 *
 * Gli elenchi al posto dell'id in «Sposta spesa» e nella regolazione immediata sono chiusi e provati in `Beta52ElenchiAlPostoDelIdTest`. `RimborsaCreditoRequest`,
 * `StoreGirocontoRequest` e `UpdateSaldoRequest` hanno la stessa forma ma sono chiuse a valle (controller e azione del rimborso, `RegistraGirocontoAction`,
 * `UpdateSaldoRequest::after()`).
 *
 * Cosa resta scoperto: `anagrafica_id` di `CreateSaldoRequest`, che non è ristretto al condominio (voce 274 della roadmap); la tabella millesimale dell'imprevisto
 * di una pregressa (`Beta52VoceEntrataPerimetroTabellaTest`); la schermata (`FatturaRegisterEditVoceEntrata.test.ts`, per le righe, e
 * `FatturaRegisterEditRiepilogo.test.ts`, per il riepilogo dei rifiuti).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Due condomini completi e una fattura registrata nel primo, dentro il preventivo: € 100,00 sulla spesa. */
function be52DueCondomini($test): array
{
    $s1 = be52Scenario($test);
    $s2 = be52Scenario($test);
    be52MastriPregresso($s1, $s2);
    $f = be52Fornitore();
    $ft = be52FatturaDalServizio($s1, $f, $s1['spesa']->id, 100.00, 'FT-P');

    return [$s1, $s2, $f, $ft];
}

function be52RigheDellaFattura(FatturaPassiva $ft): array
{
    return DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->orderBy('id')->get(['conto_id', 'immobile_id', 'importo_imponibile'])->map(fn ($r) => (array) $r)->all();
}

// ─── Modifica: la voce e l'unità ────────────────────────────────────────────────────────────────────────────────

it('modificare una riga con la voce di un altro condominio si rifiuta e i due libri non cambiano', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $righe = be52RigheDellaFattura($ft);
    $costi = [be52Costi($s1), be52Costi($s2)];
    expect($costi)->toBe([10000, 0]);

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [be52Riga($s2['spesa']->id, 100.00)]));
    $errori = be52Errori();

    expect(be52RigheDellaFattura($ft))->toBe($righe)
        ->and([be52Costi($s1), be52Costi($s2)])->toBe($costi)
        ->and(be52RigheAltrui($s1, $s2))->toBe(['con_mastro_altrui' => 0, 'con_voce_altrui' => 0, 'con_unita_altrui' => 0])
        ->and($errori['righe.0.conto_id'][0] ?? null)->toBe('La voce scelta non appartiene a questo condominio.');
});

it('modificare una riga con l\'unità di un altro condominio si rifiuta e nessun giornale porta l\'unità altrui', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $righe = be52RigheDellaFattura($ft);

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [
        ['descrizione' => 'Servizio', 'importo_imponibile' => 100, 'aliquota_iva' => 0, 'immobile_id' => $s2['unita']['A']->id]]));
    $errori = be52Errori();

    expect(be52RigheDellaFattura($ft))->toBe($righe)
        ->and(be52RigheAltrui($s1, $s2))->toBe(['con_mastro_altrui' => 0, 'con_voce_altrui' => 0, 'con_unita_altrui' => 0])
        ->and($errori['righe.0.immobile_id'][0] ?? null)->toBe('L\'unità scelta non appartiene a questo condominio.');
});

it('controprova — la riga si può portare su un\'altra voce di spesa dello stesso condominio', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $pulizie = be52Voce($this, $s1, $s1['pc'], 'Pulizie', 'spesa', '600,00');

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [be52Riga($pulizie->id, 100.00)]))->assertSessionHasNoErrors();

    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->value('conto_id'))->toBe($pulizie->id);
});

// ─── Un elenco al posto dell'id ─────────────────────────────────────────────────────────────────────────────────

it('una lista al posto della voce è un errore sul campo, in registrazione e in modifica, senza il testo della query', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 100.00, [
        'righe' => [be52Riga($s1['spesa']->id, 100.00, ['conto_id' => [$s1['spesa']->id]])]], 'FT-LISTA'));
    $registrazione = be52Errori();

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [be52Riga($s1['spesa']->id, 100.00, ['conto_id' => [$s1['spesa']->id, $s2['spesa']->id]])]));
    $modifica = be52Errori();

    expect(FatturaPassiva::where('numero_documento', 'FT-LISTA')->count())->toBe(0)
        ->and($registrazione['righe.0.conto_id'][0] ?? null)->toBe('La voce scelta non è valida.')
        ->and($modifica['righe.0.conto_id'][0] ?? null)->toBe('La voce scelta non è valida.')
        ->and(array_key_exists('error', $registrazione) || array_key_exists('error', $modifica))->toBeFalse()
        ->and(be52RigheAltrui($s1, $s2))->toBe(['con_mastro_altrui' => 0, 'con_voce_altrui' => 0, 'con_unita_altrui' => 0]);
});

// ─── I campi gemelli ────────────────────────────────────────────────────────────────────────────────────────────

it('il conto di addebito di un altro condominio si rifiuta, in registrazione e in modifica', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $mastroAltrui = (int) DB::table('conti_contabili')->where('condominio_id', $s2['c']->id)->value('id');

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, ['conto_corrente_id' => $mastroAltrui], 'FT-CC'));
    $registrazione = be52Errori();
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [be52Riga($s1['spesa']->id, 100.00)], ['conto_corrente_id' => $mastroAltrui]));
    $modifica = be52Errori();

    expect(FatturaPassiva::where('numero_documento', 'FT-CC')->count())->toBe(0)
        ->and($ft->fresh()->conto_corrente_id)->toBeNull()
        ->and($registrazione['conto_corrente_id'][0] ?? null)->toBe('Il conto di addebito scelto non appartiene a questo condominio.')
        ->and($modifica['conto_corrente_id'][0] ?? null)->toBe('Il conto di addebito scelto non appartiene a questo condominio.');
});

it('il fondo di riserva di un altro condominio non copre uno sforo', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $fondoAltrui = be52Fondo($s2, 'Fondo altrui');

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, [
        'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Prova con il fondo altrui', 'importo_sforo' => 5000, 'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $fondoAltrui->id]],
    ], 'FT-FONDO'));
    $errori = be52Errori();

    expect(FatturaPassiva::where('numero_documento', 'FT-FONDO')->count())->toBe(0)
        ->and(DB::table('fattura_coperture')->where('fondo_id', $fondoAltrui->id)->count())->toBe(0)
        ->and($errori['dati_extra.override_budget.fondo_patrimoniale_id'][0] ?? null)->toBe('Il fondo di riserva scelto non appartiene a questo condominio.');
});

it('il debito di un altro condominio non copre una fattura pregressa, né come saldo né come fonte', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $debitoAltrui = be52DebitoFornitore($s2, $f);
    $fondoAltrui = be52Fondo($s2, 'Fondo altrui');

    $registra = fn (array $extra, string $num) => $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, $extra, $num));

    $registra(['saldo_patrimoniale_id' => $debitoAltrui, 'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 610.00, 'fonte_id' => $debitoAltrui]]], 'PREG-SALDO');
    $saldo = be52Errori();
    $registra(['coperture' => [['tipo_copertura' => 'fondo_riserva', 'importo' => 610.00, 'fonte_id' => $fondoAltrui->id]]], 'PREG-FONDO');
    $fondo = be52Errori();

    expect(FatturaPassiva::whereIn('numero_documento', ['PREG-SALDO', 'PREG-FONDO'])->count())->toBe(0)
        ->and($saldo['saldo_patrimoniale_id'][0] ?? null)->toBe('Il debito scelto non appartiene a questo condominio.')
        ->and($saldo['coperture.0.fonte_id'][0] ?? null)->toBe('Il debito scelto non appartiene a questo condominio.')
        ->and($fondo['coperture.0.fonte_id'][0] ?? null)->toBe('Il fondo di riserva scelto non appartiene a questo condominio.');
});

it('una pregressa con la voce di un altro condominio come fonte non si registra, e nessuno storno scrive sul suo libro', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $costiAltrui = be52Costi($s2);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'sopravvenienza', 'importo' => 610.00, 'fonte_id' => $s2['spesa']->id]]], 'PREG-SOPR'));
    $errori = be52Errori();
    $pregressa = FatturaPassiva::where('numero_documento', 'PREG-SOPR')->first();
    if ($pregressa) { // com'era: la fattura nasce e lo storno scrive sul libro dell'altro
        $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s1['c'], $pregressa]));
    }

    expect($pregressa)->toBeNull()
        ->and(be52RigheAltrui($s1, $s2))->toBe(['con_mastro_altrui' => 0, 'con_voce_altrui' => 0, 'con_unita_altrui' => 0])
        ->and(be52Costi($s2))->toBe($costiAltrui)
        ->and($errori['coperture.0.fonte_id'][0] ?? null)->toBe('La voce scelta non appartiene a questo condominio.');
});

it('una copertura per sopravvenienza non si appoggia a una voce di entrata', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'sopravvenienza', 'importo' => 610.00, 'fonte_id' => $s1['entrata']->id]]], 'PREG-ENTRATA'));

    expect(FatturaPassiva::where('numero_documento', 'PREG-ENTRATA')->count())->toBe(0)
        ->and(be52Errori()['coperture.0.fonte_id'][0] ?? null)->toBe('«Affitto antenna» è una voce di entrata: una copertura per sopravvenienza si appoggia a una voce di spesa.');
});

it('controprova — gli id del proprio condominio passano: voce, unità, conto di addebito, fondo, debito e fonte', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $fondo = be52Fondo($s1, 'Fondo mio');
    $debito = be52DebitoFornitore($s1, $f);
    $mioMastro = (int) DB::table('conti_contabili')->where('condominio_id', $s1['c']->id)->value('id');

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [
        be52Riga($s1['spesa']->id, 60.00), ['descrizione' => 'Ad personam', 'importo_imponibile' => 40, 'aliquota_iva' => 0, 'immobile_id' => $s1['unita']['A']->id]], ['conto_corrente_id' => $mioMastro]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, [
        'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Prova con il fondo proprio', 'importo_sforo' => 5000, 'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $fondo->id]],
    ], 'FT-FONDO-MIO'))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['saldo_patrimoniale_id' => $debito,
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 610.00, 'fonte_id' => $debito]]], 'PREG-MIA'))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'fondo_riserva', 'importo' => 610.00, 'fonte_id' => $fondo->id]]], 'PREG-FONDO-MIO'))
        ->assertSessionHasNoErrors();

    expect(FatturaPassiva::whereIn('numero_documento', ['FT-FONDO-MIO', 'PREG-MIA', 'PREG-FONDO-MIO'])->count())->toBe(3)
        ->and($ft->fresh()->conto_corrente_id)->toBe($mioMastro);
});

// ─── Il fondo è un fondo, il debito è un debito ─────────────────────────────────────────────────────────────────

it('un mastro di banca dello stesso condominio non è un fondo di riserva: non copre uno sforo e non è la fonte di una copertura', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $mastroDiBanca = (int) $s1['cassa']->conto_contabile_id;

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, [
        'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Prova con la banca come fondo', 'importo_sforo' => 5000, 'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $mastroDiBanca]],
    ], 'FT-BANCA-FONDO'));
    $sforo = be52Errori();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'fondo_riserva', 'importo' => 610.00, 'fonte_id' => $mastroDiBanca]]], 'PREG-BANCA-FONDO'));
    $pregressa = be52Errori();

    expect(FatturaPassiva::whereIn('numero_documento', ['FT-BANCA-FONDO', 'PREG-BANCA-FONDO'])->count())->toBe(0)
        ->and(DB::table('fattura_coperture')->where('fondo_id', $mastroDiBanca)->count())->toBe(0)
        ->and($sforo['dati_extra.override_budget.fondo_patrimoniale_id'][0] ?? null)->toBe('Il fondo di riserva scelto non appartiene a questo condominio.')
        ->and($pregressa['coperture.0.fonte_id'][0] ?? null)->toBe('Il fondo di riserva scelto non appartiene a questo condominio.');
});

it('il debito di un altro condominio non è la fonte di una copertura di tipo saldo patrimoniale', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $debitoAltrui = be52DebitoFornitore($s2, $f);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'saldo_patrimoniale', 'importo' => 610.00, 'fonte_id' => $debitoAltrui]]], 'PREG-SALDO-FONTE'));
    $errori = be52Errori();

    expect(FatturaPassiva::where('numero_documento', 'PREG-SALDO-FONTE')->count())->toBe(0)
        ->and($errori['coperture.0.fonte_id'][0] ?? null)->toBe('Il debito scelto non appartiene a questo condominio.');
});

// ─── Una lista al posto dell'id, sugli altri campi ──────────────────────────────────────────────────────────────

function be52SenzaTracceTecniche(array $errori): bool
{
    $testo = json_encode($errori, JSON_UNESCAPED_UNICODE);

    return ! str_contains($testo, 'SQLSTATE') && ! str_contains($testo, 'Array to string') && ! str_contains($testo, 'insert into');
}

it('una lista al posto dell\'unità, del conto di addebito, del fondo, del debito o della fonte è un errore sul campo con il suo testo, senza il testo della query', function () {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $mioMastro = (int) DB::table('conti_contabili')->where('condominio_id', $s1['c']->id)->value('id');
    $debito = be52DebitoFornitore($s1, $f);
    $store = fn (array $payload) => $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), $payload)->getStatusCode();
    $update = fn (array $payload) => $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), $payload)->getStatusCode();

    $casi = [
        'unità (registrazione)' => [fn () => $store(be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, ['righe' => [['descrizione' => 'Servizio', 'importo_imponibile' => 50, 'aliquota_iva' => 0, 'immobile_id' => [$s1['unita']['A']->id]]]], 'FT-L-UNITA')), 'righe.0.immobile_id', 'L\'unità scelta non è valida.'],
        'unità (modifica)' => [fn () => $update(be52PayloadModifica($ft, $s1, [['descrizione' => 'Servizio', 'importo_imponibile' => 100, 'aliquota_iva' => 0, 'immobile_id' => [$s1['unita']['A']->id]]])), 'righe.0.immobile_id', 'L\'unità scelta non è valida.'],
        'conto di addebito (registrazione)' => [fn () => $store(be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, ['conto_corrente_id' => [$mioMastro]], 'FT-L-CC')), 'conto_corrente_id', 'Il conto di addebito scelto non è valido.'],
        'conto di addebito (modifica)' => [fn () => $update(be52PayloadModifica($ft, $s1, [be52Riga($s1['spesa']->id, 100.00)], ['conto_corrente_id' => [$mioMastro]])), 'conto_corrente_id', 'Il conto di addebito scelto non è valido.'],
        'fondo' => [fn () => $store(be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, ['stato_approvazione' => 'sforo_motivato',
            'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Prova con una lista', 'importo_sforo' => 5000, 'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => [$mioMastro]]]], 'FT-L-FONDO')), 'dati_extra.override_budget.fondo_patrimoniale_id', 'Il fondo di riserva scelto non è valido.'],
        'debito' => [fn () => $store(be52PayloadPregressa($s1, $f, ['saldo_patrimoniale_id' => [$debito]], 'PREG-L-SALDO')), 'saldo_patrimoniale_id', 'Il debito scelto non è valido.'],
        'fonte della copertura' => [fn () => $store(be52PayloadPregressa($s1, $f, ['coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 610.00, 'fonte_id' => [$debito]]]], 'PREG-L-FONTE')), 'coperture.0.fonte_id', 'La fonte scelta per la copertura non è valida.'],
    ];

    foreach ($casi as $nome => [$invia, $campo, $testo]) {
        $stato = $invia();
        $errori = be52Errori();
        expect($stato)->toBe(302, "{$nome}: la richiesta torna con gli errori, non con un errore di sistema")
            ->and(array_key_exists($campo, $errori))->toBeTrue("{$nome}: l'errore sta sul campo")
            ->and($errori[$campo][0] ?? null)->toBe($testo, "{$nome}: il rifiuto dice quale scelta non è valida");
        expect(array_key_exists('error', $errori))->toBeFalse("{$nome}: nessun errore generico");
        expect(be52SenzaTracceTecniche($errori))->toBeTrue("{$nome}: il rifiuto non mostra il testo della query");
    }
    expect(FatturaPassiva::whereIn('numero_documento', ['FT-L-UNITA', 'FT-L-CC', 'FT-L-FONDO', 'PREG-L-SALDO', 'PREG-L-FONTE'])->count())->toBe(0)
        ->and($ft->fresh()->conto_corrente_id)->toBeNull();
});

// ─── Gli id scritti in forme che la regola `integer` accetta ────────────────────────────────────────────────────

/** Un id nella forma che la regola `integer` accetta: «+5» e 5.0 valgono 5 come 5, e il perimetro li legge come lei. */
function be52FormaDiUnId(int $id, string $forma): int|string|float
{
    return match ($forma) {
        'con il più' => '+'.$id,
        'decimale' => (float) $id,
        default => $id,
    };
}

it('l\'id di una voce di entrata scritto «+id» o come numero decimale si rifiuta come l\'id intero, in registrazione e in modifica', function (string $forma) {
    [$s1, $s2, $f, $ft] = be52DueCondomini($this);
    $testo = '«Affitto antenna» è una voce di entrata: il capitolo di una fattura è una voce di spesa. Scegli un\'altra voce.';
    $voce = be52FormaDiUnId($s1['entrata']->id, $forma);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadFattura($s1, $f, $s1['spesa']->id, 50.00, [
        'righe' => [be52Riga($s1['spesa']->id, 50.00, ['conto_id' => $voce])]], 'FT-FORMA'));
    $registrazione = be52Errori();
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s1['c'], $ft]), be52PayloadModifica($ft, $s1, [be52Riga($s1['spesa']->id, 100.00, ['conto_id' => $voce])]));
    $modifica = be52Errori();

    expect($registrazione['righe.0.conto_id'][0] ?? null)->toBe($testo)
        ->and($modifica['righe.0.conto_id'][0] ?? null)->toBe($testo)
        ->and(FatturaPassiva::where('numero_documento', 'FT-FORMA')->count())->toBe(0)
        ->and(be52RigheDellaFattura($ft)[0]['conto_id'])->toBe($s1['spesa']->id);
})->with(['intero' => ['intero'], 'con il più' => ['con il più'], 'decimale' => ['decimale']]);

it('la fonte di una copertura di un altro condominio si rifiuta anche scritta «+id» o come numero decimale', function (string $tipo, string $forma, string $testo) {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $altrui = match ($tipo) {
        'rata_0' => be52DebitoFornitore($s2, $f),
        'fondo_riserva' => (int) be52Fondo($s2, 'Fondo altrui')->id,
        'sopravvenienza' => (int) $s2['spesa']->id,
    };

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, [
        'coperture' => [['tipo_copertura' => $tipo, 'importo' => 610.00, 'fonte_id' => be52FormaDiUnId($altrui, $forma)]]], 'PREG-FORMA'));

    expect(be52Errori()['coperture.0.fonte_id'][0] ?? null)->toBe($testo)
        ->and(FatturaPassiva::where('numero_documento', 'PREG-FORMA')->count())->toBe(0)
        ->and(DB::table('fattura_coperture')->count())->toBe(0);
})->with([
    'debito, intero' => ['rata_0', 'intero', 'Il debito scelto non appartiene a questo condominio.'],
    'debito, con il più' => ['rata_0', 'con il più', 'Il debito scelto non appartiene a questo condominio.'],
    'debito, decimale' => ['rata_0', 'decimale', 'Il debito scelto non appartiene a questo condominio.'],
    'fondo, intero' => ['fondo_riserva', 'intero', 'Il fondo di riserva scelto non appartiene a questo condominio.'],
    'fondo, con il più' => ['fondo_riserva', 'con il più', 'Il fondo di riserva scelto non appartiene a questo condominio.'],
    'fondo, decimale' => ['fondo_riserva', 'decimale', 'Il fondo di riserva scelto non appartiene a questo condominio.'],
    'voce, intero' => ['sopravvenienza', 'intero', 'La voce scelta non appartiene a questo condominio.'],
    'voce, con il più' => ['sopravvenienza', 'con il più', 'La voce scelta non appartiene a questo condominio.'],
    'voce, decimale' => ['sopravvenienza', 'decimale', 'La voce scelta non appartiene a questo condominio.'],
]);

it('controprova — la fonte di una copertura del proprio condominio scritta «+id» si registra come l\'id intero', function () {
    [$s1, $s2, $f] = be52DueCondomini($this);
    $debito = be52DebitoFornitore($s1, $f);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PayloadPregressa($s1, $f, [
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 610.00, 'fonte_id' => '+'.$debito]]], 'PREG-FORMA-MIA'))->assertSessionHasNoErrors();

    expect(FatturaPassiva::where('numero_documento', 'PREG-FORMA-MIA')->count())->toBe(1);
});
