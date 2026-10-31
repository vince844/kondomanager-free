<?php

/**
 * Coda 313 (1.11.0-beta.59): la quota del solo pregresso che un passaggio ha lasciato a chi
 * esce (`RegistraSubentroAction::riassegnaBozze`, la «gemella», `regole_calcolo.divisa_da`) scritta prima che la chiave
 * `importi.addebito_diretto` ci fosse. Il ripiego dal registro (`RataQuote::partiAdPersonam`) legge il registro di chi esce per la
 * quota passata a chi entra (`riassegnazione.righe_di`) e, senza questa guardia, anche per la gemella, che porta con sé il numero della
 * rata e delle rate: la stessa parte ad personam si chiudeva due volte, all'emissione e nel rilevamento.
 *
 * Le cifre in centesimi, rifatte a mano dalla regola (decisione 153.1) nel commento di ogni test.
 */

use App\Models\Anagrafica;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** Il piano di E:300 (€ 1.200,00 ad personam su Interno 1 in 12 rate, il pregresso di Rossi di € 180,00 spalmato) e Anna Neri. */
function b59apgPianoEAnna($test, string $suff): array
{
    $s = b59apBase(tipoGestione: 'ordinaria', saldi: ['mario' => 18000]);
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => "b59apg-anna{$suff}@test.it", 'indirizzo' => 'Via Po 2',
        'codice_fiscale' => strtoupper(str_pad("B59APGANNA{$suff}", 16, 'X'))]);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $piano = b59apPianoConSaldi($s, b59apFattura($test, $s, [b59apRigaAdPersonam($s, 1200.00, 0)]), 12, 'tutte_rate');

    return [$s, $anna, $piano];
}

function b59apgVendi($test, array $s, Anagrafica $anna): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
}

test('Coda 313 — la gemella del pregresso di un passaggio registrato prima dell\'aggiornamento non chiude una seconda volta l\'anticipazione', function () {
    // Il passaggio come lo ha scritto la .58: nessuna quota ha la chiave (né quella di Anna né la gemella di Rossi). A mano: nelle rate
    // 5–12 la parte ad personam è della sola quota di Anna (10000); la gemella ha il solo pregresso (1500), nessuna spesa dell'anno.
    // «Crediti» di Interno 1 dopo le emissioni 5–12 = 120000 + 4 × 11500 − 4 × 10000 + 8 × 10000 + 8 × 1500 − 8 × 10000 = 138000;
    // «Gestione rate» 0. Senza la guardia: due chiusure da 10000 per rata, 138000 − 80000 = 58000 e «Gestione rate» − 80000.
    [$s, $anna, $piano] = b59apgPianoEAnna($this, '1');
    b59apEmetti($this, $s, $piano, [1, 2, 3, 4], '2026-01-05')->assertSessionHas('message.type', 'success');
    b59apgVendi($this, $s, $anna);
    b59apSenzaChiave($piano);
    $gemella = b59apQuota($piano, 5, $s['persone']['mario']);
    expect((int) $gemella->importo)->toBe(1500)->and(isset($gemella->regole_calcolo['divisa_da']))->toBeTrue();

    b59apEmetti($this, $s, $piano, range(5, 12), '2026-05-05')->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(138000)
        ->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — il rilevamento non conta due volte la parte ad personam per la gemella del pregresso scritta prima dell\'aggiornamento', function () {
    // Tutto come lo ha scritto la .58: emissioni 1–4, passaggio, emissioni 5–12. A mano: per rata e su Interno 1 il doppio è la sola
    // quota con la spesa dell'anno, 10000 (Rossi nelle rate 1–4, Anna nelle 5–12): 12 × 10000 = 120000, quanto «Gestione rate» ha di
    // troppo. La gemella la .58 l'ha chiusa su «Passate gestioni»: non è nel doppio. Senza la guardia: 20000 nelle rate 5–12, 200000.
    [$s, $anna, $piano] = b59apgPianoEAnna($this, '2');
    b59apSenzaChiave($piano);
    foreach (range(1, 4) as $n) {
        b59apEmettiComePrima($s, $piano, $n, '2026-01-05');
    }
    b59apgVendi($this, $s, $anna);
    b59apSenzaChiave($piano);
    foreach (range(5, 12) as $n) {
        b59apEmettiComePrima($s, $piano, $n, '2026-05-05');
    }
    expect(b59apGestioneRate($s))->toBe(120000);

    $card = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
    expect(collect($card['ad_personam'])->map(fn ($v) => [$v['numero_rata'], $v['importo']])->all())->toBe(array_map(fn ($n) => [$n, 10000], range(1, 12)));
});
