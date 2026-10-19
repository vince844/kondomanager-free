<?php

/**
 * Le strade che il fermo e l'avviso indicano portano dove dicono, e dicono quello che costano.
 *
 * - Un sottoconto tolto dal piano non si riaggiunge con «Sincronizza»: la sincronizzazione riaggiunge solo i capitoli di primo livello. Per una voce con un padre il
 *   rifiuto e l'avviso dicono di eliminare il piano e ricrearlo dopo aver corretto la voce; per una voce di primo livello la strada resta togliere e riaggiungere.
 * - Eliminare il piano cancella anche gli spostamenti di budget fra le voci (`budget_movements`, onDelete cascade) e gli importi scelti per le voci (la copia in
 *   `piano_rate_capitoli`): la frase che dice che cosa si perde li nomina.
 * - La strada del saldo dice dove il saldo entra: un saldo scritto dopo la generazione non entra in questo piano ma nel prossimo piano della gestione che applica i
 *   saldi, o nell'esercizio dopo; dove la persona ha già un saldo si corregge quello finché nessun piano l'ha assorbito, perché il Wallet ne accetta uno solo per
 *   persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio.
 * - Una voce già corretta con la copia ancora negativa è «già corretta» anche nella testa del rifiuto, come nell'avviso.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spese generali € 1.200,00, Ascensore € 600,00, Rimborso € 100,00 corretto: € 1.900,00), le regole di
 * `CreateSaldoRequest` e `UpdateSaldoRequest` e i messaggi di `SyncOrphanChaptersAction`/`PianoRateGenerationController`.
 *
 * Cosa resta scoperto: la guida (`PianoRateDettaglioGuideSpesaNegativa.test.ts`) e la schermata (`PianiRateShowAvvisoSpesaNegativa.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che le frasi non devono cambiare: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use App\Models\Saldo;
use App\Services\Riparto\AvvisoSpeseNegative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const BE52_STR_COSTO = 'Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.';
const BE52_STR_SOTTOCONTO = 'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia; un sottoconto tolto dal piano non si riaggiunge con «Sincronizza», che riaggiunge solo i capitoli: elimina il piano e ricrealo dopo aver corretto la voce. '.BE52_STR_COSTO;

function be52StrRicalcola($test, array $s, $piano, array $orfani = []): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano->fresh()]), [
        'orphan_ids' => $orfani, 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    return session('message');
}

/** Il piano «lascia vuoto» con «Spese generali», e il capitolo «Manutenzione» con «Ascensore» € 600,00 e «Rimborso» scritto con il meno e con la sua copia nel piano. */
function be52StrPianoConSottoconto($test): array
{
    $s = b52tScenario($test, entrata: '0,00');
    $s['entrata']->delete();
    $padre = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($test, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $padre->id, ['isSottoConto' => true]);
    $rimborso = b52tVoce($test, $s, 'Rimborso', 'spesa', '100,00', $s['tabella'], $padre->id, ['isSottoConto' => true]);
    $piano = b52tPiano($test, $s);
    // il dato della 1.10: il sottoconto scritto con il meno e la sua copia nel piano
    DB::table('conti')->where('id', $rimborso->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $rimborso->id)->update(['importo' => -10000]);

    return [$s, $piano, $padre, $rimborso];
}

// ─── Un sottoconto tolto dal piano non si riaggiunge con «Sincronizza» ──────────────────────────────────────────

it('il rifiuto per un sottoconto negativo con la copia dice di eliminare il piano e ricrearlo, e che cosa si perde', function () {
    [$s, $piano] = be52StrPianoConSottoconto($this);

    $esito = be52StrRicalcola($this, $s, $piano);

    expect($esito)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00.'
        .' Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.'
        .' '.BE52_STR_SOTTOCONTO]);
});

it('l\'avviso per un sottoconto negativo con la copia dice la stessa strada del rifiuto', function () {
    [$s, $piano] = be52StrPianoConSottoconto($this);

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($piano->fresh());

    expect($avviso['strade'])->toBe([
        'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.',
        BE52_STR_SOTTOCONTO,
    ]);
});

it('controprova — la strada «togli e riaggiungi» per un sottoconto non esiste: «Sincronizza» rifiuta la voce che la pagina offre e il piano resta com\'è', function () {
    [$s, $piano, $padre, $rimborso] = be52StrPianoConSottoconto($this);
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $rimborso]), [
        'nome' => 'Rimborso', 'codice' => '', 'tipo' => 'spesa', 'importo' => '100,00', 'descrizione' => '', 'note' => '', 'parent_id' => $padre->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $rimborso->id]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova']);
    $offerte = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]))->viewData('page')['props']['copertura']['orfani'] ?? [];

    $esito = be52StrRicalcola($this, $s, $piano, collect($offerte)->pluck('id')->all());

    // a mano: dopo la rimozione il piano chiede € 1.200,00 + € 600,00; il sottoconto corretto (€ 100,00) tornerebbe solo ricreando il piano
    expect(collect($offerte)->pluck('nome')->all())->toBe(['— Rimborso'])
        ->and($esito['type'] ?? null)->toBe('error')
        ->and($esito['message'])->toContain('Per includere un sotto-conto, elimina il piano e ricrealo selezionando la voce.')
        ->and(b52tQuoteDelMotore($piano->fresh()))->toBe(180000);
});

it('controprova — per una voce di primo livello la strada resta togliere e riaggiungere con «Sincronizza»', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);

    be52Ricalcola($this, $s, $piano);

    expect(be52Flash()['message'])->toContain('togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza».')
        ->and(be52Flash()['message'])->not->toContain('elimina il piano');
});

// ─── Che cosa si perde eliminando il piano ──────────────────────────────────────────────────────────────────────

it('eliminare il piano cancella gli spostamenti di budget fra le voci e gli importi scelti per le voci, e la frase li dice', function () {
    [$s, $piano, $a] = be52PianoVecchio($this);
    $b = be52Voce($this, $s, $s['pc'], 'Rimborso B', 'spesa', '100,00');
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $b->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('budget_movements')->insert(['piano_rate_id' => $piano->id, 'source_conto_id' => $a->id, 'destination_conto_id' => $b->id, 'amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('budget_movements')->where('piano_rate_id', $piano->id)->count())->toBe(1);

    be52Ricalcola($this, $s, $piano);
    $frase = be52Flash()['message'];
    DB::table('piani_rate')->where('id', $piano->id)->delete();

    expect($frase)->toContain('Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.')
        ->and(DB::table('budget_movements')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->count())->toBe(0);
});

// ─── La strada del saldo ────────────────────────────────────────────────────────────────────────────────────────

/** Un piano emesso con la spesa negativa del passato (la copia −€ 240,00 nel piano) e Ugo che ha già il saldo d'apertura sull'unità A. */
function be52StrPianoEmessoConSaldo($test): array
{
    $s = b52tScenario($test, entrata: '0,00');
    $s['entrata']->delete();
    $saldoDUgo = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    $piano = b52tPiano($test, $s);
    $rimborso = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Rimborso assicurazione', 'tipo' => 'spesa', 'importo' => -24000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $rimborso->id, 'importo' => -24000, 'created_at' => now(), 'updated_at' => now()]);
    $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05',
        'data_competenza' => '2026-01-05', 'numero_protocollo' => 'EMI-STRADE', 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now()]);
    $rata = DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', '>', 0)->orderBy('numero_rata')->value('id');
    DB::table('rate_quote')->where('rata_id', $rata)->update(['scrittura_contabile_id' => $scrittura]);

    return [$s, $piano->fresh(), $saldoDUgo];
}

function be52StrNuovoSaldo($test, array $s, string $chi, int $centesimi)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', $s['c']), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']['A']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi,
    ]);
}

it('la strada del saldo dice dove entra e che cosa fare dove la persona ha già un saldo', function () {
    [$s, $piano] = be52StrPianoEmessoConSaldo($this);

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($piano);

    expect(end($avviso['strade']))->toBe('Se il piano non si può più riscrivere (incassi o crediti usati da tenere, o un passaggio di titolarità che l\'ha conguagliato), regola la differenza con un saldo sulle unità, da «Saldi iniziali»: '
        .'questo piano non lo legge più, entra nel prossimo piano della gestione che applica i saldi, o nell\'esercizio dopo. '
        .'Il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio: dove la persona ne ha già uno, si corregge quello, se nessun piano l\'ha ancora assorbito o se il piano che l\'ha assorbito si può ancora riscrivere; se l\'ha già assorbito un piano che non si riscrive più, il Wallet non accetta né un secondo saldo né la correzione, e per quella persona la differenza va nell\'esercizio dopo. Se il saldo è fra quelli da cui una successione ha calcolato l\'arretrato agli eredi, il Wallet lo dice e indica la strada.');
});

it('controprova — dove la persona ha già un saldo, quello nuovo si rifiuta, e se un piano l\'ha assorbito anche quello vecchio non si modifica', function () {
    [$s, $piano, $saldoDUgo] = be52StrPianoEmessoConSaldo($this);

    be52StrNuovoSaldo($this, $s, 'ugo', -12000);
    $rifiutoDelNuovo = session('errors')?->toArray();
    $this->actingAs($this->user)->put(route('admin.gestionale.saldi.update', [$s['c'], $saldoDUgo]), [
        'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000 - 12000,
    ]);
    $rifiutoDellaModifica = session('errors')?->toArray();

    // il saldo d'apertura di Ugo è già nel piano emesso: non si modifica, e il saldo nuovo non ha posto
    expect($rifiutoDelNuovo)->toBe(['anagrafica_id' => ['Esiste già un saldo per questa anagrafica in questa gestione.']])
        ->and($rifiutoDellaModifica['saldo'][0])->toStartWith('Non puoi modificare questo saldo: è incluso nel piano rate «Preventivo 2026»')
        ->and(Saldo::where('anagrafica_id', $s['persone']['ugo']->id)->pluck('saldo_iniziale')->map(fn ($v) => (int) $v)->all())->toBe([5000]);
});

it('controprova — il saldo che nessun piano ha assorbito si corregge: quello nuovo si rifiuta, la correzione passa', function () {
    [$s, $piano] = be52StrPianoEmessoConSaldo($this);
    be52StrNuovoSaldo($this, $s, 'bice', 5000);
    $esistente = Saldo::where('anagrafica_id', $s['persone']['bice']->id)->firstOrFail();

    be52StrNuovoSaldo($this, $s, 'bice', -12000);
    $rifiuto = session('errors')?->toArray();
    $this->actingAs($this->user)->put(route('admin.gestionale.saldi.update', [$s['c'], $esistente]), [
        'anagrafica_id' => $s['persone']['bice']->id, 'immobile_id' => $s['unita']['A']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000 - 12000,
    ])->assertSessionHasNoErrors();

    expect($rifiuto)->toBe(['anagrafica_id' => ['Esiste già un saldo per questa anagrafica in questa gestione.']])
        ->and(Saldo::where('anagrafica_id', $s['persone']['bice']->id)->pluck('saldo_iniziale')->map(fn ($v) => (int) $v)->all())->toBe([-7000]);
});

it('controprova — il saldo scritto da un passaggio non occupa il posto: la persona può avere il suo accanto', function () {
    [$s, $piano] = be52StrPianoEmessoConSaldo($this);
    $bice = $s['persone']['bice'];
    $passaggio = \App\Models\Gestionale\Subentro::create([
        'condominio_id' => $s['c']->id, 'immobile_id' => $s['unita']['A']->id, 'anagrafica_uscente_id' => $s['persone']['ugo']->id, 'anagrafica_entrante_id' => $bice->id,
        'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-05-01', 'utente_id' => $this->user->id,
    ]);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $bice->id, 'immobile_id' => $s['unita']['A']->id, 'gestione_id' => $s['g']->id,
        'saldo_iniziale' => 3000, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $passaggio->id]);

    be52StrNuovoSaldo($this, $s, 'bice', -12000);

    expect(session('errors'))->toBeNull()
        ->and(Saldo::where('anagrafica_id', $bice->id)->count())->toBe(2);
});

it('controprova — un saldo scritto dopo la generazione non cambia questo piano: resta da assorbire dal prossimo', function () {
    [$s, $piano] = be52StrPianoEmessoConSaldo($this);
    $quote = b52tSommeQuote($piano);

    be52StrNuovoSaldo($this, $s, 'bice', -12000);
    $nuovo = Saldo::where('anagrafica_id', $s['persone']['bice']->id)->firstOrFail();

    expect(session('errors'))->toBeNull()
        ->and(b52tSommeQuote($piano->fresh()))->toBe($quote)
        ->and([$nuovo->is_applicato, $nuovo->piano_rate_id])->toBe([false, null]);
});

// ─── Una voce già corretta con la copia ancora negativa, nella testa del rifiuto ────────────────────────────────

it('con due voci, una già corretta con la copia negativa e una ancora negativa, il rifiuto dice quale delle due è già corretta', function () {
    [$s, $piano, $a] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $a, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();
    $b = be52Voce($this, $s, $s['pc'], 'Rimborso B', 'spesa', '100,00');
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $b->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['message'])->toStartWith('Il piano non si ricalcola: 2 voci di spesa hanno un importo o una copia nel piano negativi e il piano le addebiterebbe in positivo: '
        .'«Rimborso» (già corretta, ma con la copia a meno € 240,00), «Rimborso B» (meno € 100,00).');
});

it('controprova — con due voci ancora negative la testa del rifiuto è quella di sempre', function () {
    [$s, $piano] = be52PianoVecchio($this);
    $b = be52Voce($this, $s, $s['pc'], 'Rimborso B', 'spesa', '100,00');
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $b->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['message'])->toStartWith('Il piano non si ricalcola: 2 voci di spesa hanno un importo negativo e il piano le addebiterebbe in positivo: «Rimborso» (meno € 240,00), «Rimborso B» (meno € 100,00).');
});
