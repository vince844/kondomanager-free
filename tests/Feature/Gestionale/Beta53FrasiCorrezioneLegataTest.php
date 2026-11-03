<?php

/**
 * 1.11.0-beta.53 (Coda 244) — le frasi che dicono la correzione con un saldo manuale di segno opposto dicono anche di legarlo al passaggio.
 *
 * Quando le righe di un passaggio (la coppia, l'arretrato di una successione) non si tolgono più, perché un piano che non si riscrive le ha
 * assorbite o una successione dopo le ha lette, la correzione resta un saldo manuale di segno opposto sulla stessa gestione: dalla
 * 1.11.0-beta.53 lo si lega a quel passaggio dal Wallet, così non occupa il posto di un riporto. Le frasi lo dicono: il Wallet (le costanti di
 * `Saldo` e il rifiuto della modifica di una gamba), «Annulla il conguaglio» con il piano fermo e con la successione che legge le righe.
 *
 * Che cosa NON copre: i riquadri del lucchetto del pannello dei saldi (nei test del modale, `SaldiDetailPanelLucchettoLegato.test.ts`); la guida
 * (in `GuideRegolaWallet.test.ts`); il rifiuto dell'eliminazione di una gamba, che rimanda allo storico e non a un saldo di segno opposto; MySQL.
 */

use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53C_RESTA = 'resta un saldo manuale di segno opposto, legato a quel passaggio dal Wallet.';

it('le frasi del Wallet per le righe dell\'arretrato e del conguaglio con l\'arretrato dicono il saldo di segno opposto legato a quel passaggio', function () {
    expect(Saldo::FRASE_ARRETRATO)->toEndWith('se un piano l\'ha già assorbita, ' . SL53C_RESTA)
        ->and(Saldo::FRASE_CONGUAGLIO_CON_ARRETRATO)->toEndWith('se un piano l\'ha già assorbita, ' . SL53C_RESTA);
});

it('la modifica di una gamba della coppia si rifiuta, e la frase dice il saldo di segno opposto legato a quel passaggio', function () {
    $s = sl53Scenario($this);
    $gamba = Saldo::where('subentro_id', $s['vendita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->firstOrFail();

    $errore = sl53Errori($this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $gamba]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id]))['saldo'][0] ?? '';

    expect($errore)->toEndWith('se un piano le ha già emesse, ' . SL53C_RESTA)
        ->and((int) $gamba->fresh()->saldo_iniziale)->toBe(38);
});

it('«Annulla il conguaglio» con la coppia in un piano che ha emesso: la correzione è un saldo di segno opposto legato al passaggio, che non occupa il posto', function () {
    $s = sl53Scenario($this);
    $piano = sl53SecondoPiano($s, stato: 'approvato');
    fds50Emetti($this, $s, '2026-11-30', $piano);

    $errore = sl53Errori(sl53AnnullaConguaglio($this, $s, $s['vendita']))['conguaglio'][0] ?? '';

    expect($errore)->toContain('Il piano «Conguaglio 2026» ha già quote a giornale')
        ->and(str_contains($errore, 'e la correzione passa da un saldo manuale di segno opposto sulla stessa gestione, legato a quel passaggio dal Wallet: non occupa il posto di un riporto.'))->toBeTrue($errore)
        ->and($s['vendita']->fresh()->conguaglioAnnullato())->toBeFalse();
});

it('«Annulla il conguaglio» di una vendita le cui righe una successione dopo ha letto: l\'accordo diverso si scrive con un saldo manuale legato al passaggio del conguaglio, non alla successione', function () {
    // La vendita del 1/11 ha la coppia (Elsa € 0,38 a debito); Elsa muore il 1/12, eredi Anna e Bruno, arretrato agli eredi: la riga di Elsa è fra
    // le fonti dell'arretrato, e il conguaglio della vendita non si annulla.
    $s = sl53Scenario($this);
    $this->travelTo(now()->setDate(2026, 12, 15));
    fds50Registra($this, $s, ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Elsa', 'proprietario'), 'decorrenza' => '2026-12-01', 'quota' => 100,
        'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Bruno']->id, 'quota' => 50]],
        'arretrato' => 'eredi', 'erede_di_riferimento' => $s['persone']['Anna']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 78', 'pertinenze' => []]);

    $errore = sl53Errori(sl53AnnullaConguaglio($this, $s, $s['vendita']))['conguaglio'][0] ?? '';

    expect($errore)->toStartWith('Una riga di questo conguaglio è fra le cifre da cui è calcolato l\'arretrato di Elsa')
        ->and($errore)->toEndWith('il conguaglio resta, e un accordo diverso fra le parti si scrive con un saldo manuale legato al passaggio di questo conguaglio, dal Wallet.');
});
