<?php

/**
 * 1.11.0-beta.53 (Coda 244) — i lettori dei saldi che devono tenere fuori, o dentro, le righe legate a un passaggio.
 *
 * - La provvista della Rata Zero (il widget della pagina della fattura) e il «buco» che il listener calcola per un debito verso un
 *   fornitore coperto dalla Rata Zero escludono le righe scritte da un passaggio, perché la coppia somma zero; le righe legate, che come la
 *   strada a mano della fermata sono una coppia a somma zero, restano fuori allo stesso modo. Le cifre (dallo scenario del debito ereditato):
 *   il riporto vero di Ugo € 500,00 è la provvista (50000), non 59074; il buco è € 122,00 (12200), non 3126.
 * - La frase del prospetto per una fine di prima della .50 («un saldo manuale scritto dal Wallet sulla stessa gestione… quei giorni sono già
 *   regolati») non conta una riga legata a un altro passaggio; una riga legata a quella fine sì.
 * - Un saldo legato del defunto resta una fonte dell'arretrato agli eredi, come ogni saldo libero: non si modifica, e il passaggio a cui è
 *   legato non si annulla prima della successione.
 * - `FermataDopoScrivi::conUnSaldo` non conta le righe legate: chi ha solo righe legate non ha un saldo che occupi il posto.
 *
 * Che cosa NON copre: l'importatore (conta anche le righe legate come saldo già presente: Coda 282, nella 1.12); la stampa e la situazione
 * debitoria (sommano ogni saldo della persona, righe legate comprese, come oggi); il primo saldo della persona nella scheda dell'unità; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\Riparto\ProspettoOneriAccessori;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/GestionaleTestHelpers.php';
require_once __DIR__ . '/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/**
 * Il condominio della Rata Zero: il riporto di Ugo (€ 500,00) e la coppia della strada a mano (Ugo −9074, Elsa +9074) legata a una vendita
 * registrata, senza `subentro_id`. Con `$riporto` falso il riporto non c'è (per il buco del listener).
 *
 * @return array{0: \App\Models\Condominio, 1: object, 2: int}
 */
function sl53lProvvista(bool $riporto = true): array
{
    [$condominio, $esercizio, $gestione, $fornitore, , , $immobileId] = setupContabile();
    $ugo = \App\Models\Anagrafica::factory()->create();
    $elsa = \App\Models\Anagrafica::factory()->create();
    $comune = ['esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'immobile_id' => $immobileId, 'origine' => 'manuale', 'is_applicato' => false];
    if ($riporto) {
        Saldo::create($comune + ['anagrafica_id' => $ugo->id, 'saldo_iniziale' => 50000]);
    }
    $vendita = Subentro::create([
        'condominio_id' => $condominio->id, 'immobile_id' => $immobileId, 'anagrafica_uscente_id' => $ugo->id, 'anagrafica_entrante_id' => $elsa->id,
        'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-10-01', 'utente_id' => \App\Models\User::factory()->create()->id,
    ]);
    foreach ([[$ugo->id, -9074], [$elsa->id, 9074]] as [$aid, $cents]) {
        Saldo::create(['anagrafica_id' => $aid, 'saldo_iniziale' => $cents, 'passaggio_id' => $vendita->id] + $comune);
    }

    return [$condominio, (object) compact('esercizio', 'gestione', 'fornitore'), $immobileId];
}

it('la provvista della Rata Zero non conta le righe legate: € 500,00, il solo riporto', function () {
    [$condominio] = sl53lProvvista();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', $condominio->id))->assertOk()->viewData('page')['props'];

    expect((int) $props['capienza_rata_zero'])->toBe(50000);
});

it('il buco del listener per un debito verso un fornitore coperto dalla Rata Zero non si chiude con le righe legate: € 122,00', function () {
    [$condominio, $c] = sl53lProvvista(riporto: false);
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'test']);
    $saldoFornitoreId = DB::table('saldi')->insertGetId([
        'condominio_id' => $condominio->id, 'esercizio_id' => $c->esercizio->id, 'gestione_id' => $c->gestione->id, 'fornitore_id' => $c->fornitore->id,
        'saldo_iniziale' => -12200, 'is_applicato' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $data = datiBase([$condominio, $c->esercizio, $c->gestione, $c->fornitore], [
        'data_documento' => '2025-12-01', 'data_scadenza' => '2025-12-31', 'is_pregresso' => true,
        'imponibile_pregresso' => 100.00, 'aliquota_iva_pregressa' => 22, 'saldo_patrimoniale_id' => $saldoFornitoreId, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 122.00, 'fonte_id' => $saldoFornitoreId]],
    ]);
    $fattura = (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura($data, $condominio->id);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new \App\Events\Gestionale\FatturaRegistrata($fattura, $this->user->id));

    $evento = \App\Models\Evento::where('meta->type', 'pianifica_ripianamento_deficit')->where('meta->context->saldo_id', $saldoFornitoreId)->first();

    expect($evento)->not->toBeNull()
        ->and((int) $evento->meta['importo_buco'])->toBe(12200);
});

/**
 * La fine di Ines del 1/7 come una fine di prima della .50 (registro senza la scelta), e la vendita di Ugo a Elsa il 1/10 registrata dopo.
 * Le due righe della strada a mano (Ines −18148, Ugo +18148), legate al passaggio dato (`fine` o `vendita`).
 */
function sl53lFineDiPrima($test, string $legataA): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Elsa');
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    foreach (Subentro::where('id', $s['fine']->id)->orWhere('subentro_padre_id', $s['fine']->id)->get() as $x) {
        $registro = $x->registro ?? [];
        unset($registro['conguaglio'], $registro['regolato_fuori']);
        $registro['piani_presi'] = [];
        DB::table('subentri')->where('id', $x->id)->update(['registro' => json_encode($registro), 'nota_conguaglio' => null]);
    }
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $legame = (int) $s[$legataA]->id;
    foreach ([['Ines', -18148], ['Ugo', 18148]] as [$chi, $cents]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id,
            'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'manuale', 'is_applicato' => false, 'passaggio_id' => $legame]);
    }

    return $s;
}

it('la frase del prospetto per una fine di prima della .50 non conta le righe legate a un altro passaggio', function () {
    $s = sl53lFineDiPrima($this, 'vendita');

    $intestazione = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'];

    expect(collect($intestazione)->contains(fn (string $f) => str_contains($f, 'quei giorni sono già regolati')))->toBeFalse(implode(' | ', $intestazione));
});

it('controprova — con le righe legate a quella fine la frase c\'è (verde anche prima: le righe della strada a mano senza legame)', function () {
    $s = sl53lFineDiPrima($this, 'fine');

    $intestazione = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'];

    expect(collect($intestazione)->contains(fn (string $f) => str_contains($f, 'quei giorni sono già regolati')))->toBeTrue(implode(' | ', $intestazione));
});

it('un saldo legato di chi muore dopo è una fonte dell\'arretrato agli eredi: non si modifica, e la vendita a cui è legato non si annulla prima', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->firstOrFail();
    expect($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);
    // La successione di Elsa del 1/12: l'orologio va al 15/12, perché la data di un decesso non può essere nel futuro.
    $this->travelTo(now()->setDate(2026, 12, 15));
    fds50Registra($this, $s, ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Elsa', 'proprietario'), 'decorrenza' => '2026-12-01', 'quota' => 100,
        'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => 50]],
        'arretrato' => 'eredi', 'erede_di_riferimento' => $s['persone']['Anna']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 78', 'pertinenze' => []]);

    $frase = $riga->fresh()->fraseFonteDellArretrato();
    expect($frase)->not->toBeNull();
    $patch = $this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 5000, 'gestione_id' => $s['g']->id]);
    expect(sl53Errori($patch)['saldo'][0] ?? null)->toBe($frase)
        ->and((int) $riga->fresh()->saldo_iniziale)->toBe(6016);

    $rifiuto = (string) (sl53Errori(sl53Annulla($this, $s, $s['vendita']))['passaggio'][0] ?? '');
    expect($rifiuto)->toStartWith('Dopo questo, su Interno 1, c\'è un altro passaggio');
});

it('conUnSaldo non conta le righe legate: con le sole due righe della fermata nessuno ha un saldo che occupi il posto', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);

    expect(FermataDopoScrivi::conUnSaldo([(int) $s['persone']['Ugo']->id, (int) $s['persone']['Elsa']->id], (int) $s['g']->id, (int) $s['unita']->id, (int) $s['e']->id))->toBe([]);
});
