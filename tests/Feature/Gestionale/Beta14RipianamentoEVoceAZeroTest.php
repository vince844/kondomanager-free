<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, tappa 4: le Code 742 (la voce a € 0 fuori dal preventivo) e 743 (il piano di ripianamento del debito ereditato), riprese dal lavoro tenuto
 * fuori dalla beta.13. Ogni prova è un difetto trovato da chi ha rivisto quel lavoro, dalle rotte, con i numeri controllati a mano.
 *
 * Il caso della 743 è quello di Idraulica Bianchi: il debito ereditato di € 2.000,00, i pregressi chiesti ai condòmini € 800,00 (Ugo € 500,00, Bice
 * € 300,00), la fattura pregressa sul debito con la copertura «rata 0» di € 2.000,00, il compito «Deficit da recuperare» con € 1.200,00 scoperti. Le
 * tabelle: «Proprietà» 600/400 e «Scale» 500/500. Il caso della 742 è la fattura di € 1.400,00 sulla voce «Manutenzione tetto», che nel preventivo vale
 * € 0, con «Pulizie» a € 1.000,00 sulla tabella «Proprietà» 600/400.
 *
 * 1. Il rendiconto dice «da pagare» il ripianamento finché il fornitore non è pagato (prima: «pagato» alla data della rettifica).
 * 2. Non si ripiana più dello scoperto (prima: € 2.000,00 per uno scoperto di € 1.200,00, € 2.800,00 chiesti in tutto).
 * 3. Un ripianamento parziale lascia aperto il compito del deficit con il resto (prima: chiuso, e il resto non lo ricordava nessuno).
 * 4. Due ripianamenti con due tabelle non si mescolano; la voce tolta si riusa; il compito chiuso dall'amministratore resta chiuso; la scrittura annullata
 *    è uno storno del ripianamento, non una rettifica; una tabella non attiva si rifiuta.
 * 5. Il piano delle fatture approvato tiene ferma la voce a € 0 (prima: si cambiava, e il preventivo la chiedeva una seconda volta).
 * 6. «Dalle voci» non chiede di nuovo una voce a € 0 già nel piano delle fatture (prima: la richiesta passava).
 * 7. La nota di credito collegata riduce la fattura sulla voce a € 0 (prima: «annullata per intero dalla nota» con € 1.000,00 ancora da pagare).
 * 8. Le quote di un piano già fatto su una fattura mista non cambiano al «Ricalcola» (prima: un condòmino pagava una parte della riga dell'unità di un altro).
 * 9. La voce a € 0 coperta dal fondo di riserva non si offre a rate (prima: il carrello la offriva, e la spesa si chiedeva al fondo e ai condòmini).
 *
 * Cosa resta scoperto: le pagine (i vitest `PianiRateNewRipianamento.test.ts`, `ripianamento.test.ts`); i database diversi da sqlite.
 */

use App\Events\Gestionale\FatturaRegistrata;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Evento;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Saldo;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\RipianamentoDelDeficit;
use App\Services\Gestionale\SpesaDaMettereARate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

// --- Coda 743: il debito ereditato verso Idraulica Bianchi ---------------------------------------------------------------------------------------------

/** Il condominio con il debito ereditato, i pregressi dei condòmini, la pregressa e il compito del deficit. */
function b14t4Debito($t): array
{
    Event::fake([FatturaRegistrata::class]);
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500])
        ->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $debito = Saldo::forceCreate(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'fornitore_id' => $fornitoreId,
        'saldo_iniziale' => -200000, 'is_applicato' => false, 'descrizione' => 'Riparazione della colonna non pagata']);
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 50000, true);
    $s->saldo('bice', 'B', 'Ordinaria 2026', 30000, true);

    $t->actingAs($s->admin);
    $fattura = (new FatturaPassivaService())->registraFattura([
        'fornitore_id' => $fornitoreId, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-77',
        'data_documento' => '2025-11-20', 'data_scadenza' => '2025-12-20', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => 2000.00,
        'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito->id, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 2000.00, 'fonte_id' => $debito->id]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $s->c->id);
    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $s->admin->id));
    $compito = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('meta->context->saldo_id', $debito->id)->sole();

    return compact('s', 'g', 'fattura', 'compito', 'debito', 'fornitoreId');
}

/** Il corpo del nuovo piano come lo manda la pagina, con il debito da ripianare. */
function b14t4CorpoRipianamento(array $r, array $piu = [], array $ripianamento = []): array
{
    return array_merge([
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Ripianamento Idraulica Bianchi', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 3',
        'fatture_config' => [], 'genera_subito' => 1, 'accetta_destinatari' => 1, 'nota_destinatari' => 'Letto: il ripianamento del debito ereditato',
        'ripianamento' => array_merge(['compito_id' => $r['compito']->id, 'importo' => '1.200,00', 'tabella_id' => $r['s']->tabelle['Proprietà']->id], $ripianamento),
    ], $piu);
}

function b14t4Ripiana($t, array $r, array $piu = [], array $ripianamento = []): PianoRate
{
    $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), b14t4CorpoRipianamento($r, $piu, $ripianamento))
        ->assertSessionHasNoErrors();

    return PianoRate::query()->where('condominio_id', $r['s']->c->id)->where('nome', $piu['nome'] ?? 'Ripianamento Idraulica Bianchi')->sole();
}

/** Il piano tolto dalla sua pagina: prima riportato in bozza, poi eliminato. */
function b14t4Elimina($t, array $r, PianoRate $piano): void
{
    $s = $r['s'];
    $t->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $t->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]))->assertSessionHasNoErrors();
}

/** Quanto il piano chiede a ogni persona, dalle quote salvate (nome => centesimi). */
function b14t4PerPersona(Beta51Scenario $s, PianoRate $piano): array
{
    $esito = [];
    foreach (['ugo', 'bice'] as $chi) {
        $esito[$chi] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    }

    return $esito;
}

/** La riga di una voce nel riepilogo per competenza del rendiconto, cercata per nome fra i capitoli e le loro voci. */
function b14t4RigaDelRendiconto(Beta51Scenario $s, string $nome): ?array
{
    $riepilogo = app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s->c, $s->e->fresh());
    foreach ($riepilogo['blocchi'] as $blocco) {
        foreach ($blocco['spese'] as $capitolo) {
            foreach ([$capitolo, ...($capitolo['voci'] ?? [])] as $riga) {
                if (($riga['nome'] ?? null) === $nome) {
                    return $riga;
                }
            }
        }
    }

    return null;
}

// --- Coda 742: la voce «Manutenzione tetto» a € 0 nel preventivo --------------------------------------------------------------------------------------

/** Via Roma 12 con «Manutenzione tetto» (l'importo dato, € 0 se non detto) e «Pulizie» a € 1.000,00, dalla rotta vera, sulla tabella «Proprietà». */
function b14t4Tetto($t, int $tetto = 0): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    foreach (['Manutenzione tetto' => $tetto, 'Pulizie' => 100000] as $nome => $importo) {
        $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $s->gestioni['Ordinaria 2026']['pc']]), [
            'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => number_format($importo / 100, 2, ',', '.'),
            'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $conto = Conto::query()->where('piano_conto_id', $s->gestioni['Ordinaria 2026']['pc']->id)->where('nome', $nome)->sole();
        $s->voci[$nome] = ['conto' => $conto, 'gestione' => 'Ordinaria 2026', 'importo' => $importo];
    }
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pc' => $s->gestioni['Ordinaria 2026']['pc'], 'fornitoreId' => $fornitoreId];
}

/**
 * La fattura dalla rotta vera, con le righe date (`[voce, euro, unità o null]`: la riga di un'unità porta la voce, come la pagina); sopra il preventivo è uno
 * sforo con lo sforo e la strategia dati (di serie il conguaglio di fine anno).
 */
function b14t4Fattura($t, array $r, array $righe, string $numero = 'ZM0001', int $sforo = 0, array $strategia = ['strategia_rientro' => 'conguaglio_fine_anno', 'fondo_patrimoniale_id' => null]): FatturaPassiva
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => $sforo > 0 ? 'sforo_motivato' : 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => $sforo > 0 ? ['motivazione' => 'Infiltrazione dal tetto, riparata subito',
            'importo_sforo' => $sforo] + $strategia : null],
        'righe' => array_map(fn ($riga) => ['descrizione' => $riga[2] === null ? 'Riparazione del manto' : 'Sostituzione del rubinetto', 'importo_imponibile' => $riga[1],
            'aliquota_iva' => 0, 'conto_id' => $s->voci[$riga[0]]['conto']->id, 'immobile_id' => $riga[2] === null ? null : $s->unita[$riga[2]]->id,
            'is_sopravvenienza' => false], $righe),
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::query()->where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
}

function b14t4Carrello($t, array $r): \Illuminate\Support\Collection
{
    return collect($t->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json());
}

/** Il piano «Spese fuori preventivo» con la fattura per l'importo dato, dalla rotta (la risposta, per leggere gli errori). */
function b14t4PianoDelleFatture($t, array $r, FatturaPassiva $f, string $importo, string $nome = 'Tetto a rate', bool $json = false)
{
    $corpo = [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $f->id, 'importo' => $importo]], 'genera_subito' => 1,
    ];
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]);

    return $json ? $t->actingAs($r['s']->admin)->postJson($rotta, $corpo) : $t->actingAs($r['s']->admin)->post($rotta, $corpo);
}

/** La modifica di una voce dal piano dei conti, come la manda la sua scheda. */
function b14t4ModificaVoce($t, array $r, Conto $voce, array $campi = [])
{
    session()->forget('errors');
    $voce = $voce->fresh();

    return $t->actingAs($r['s']->admin)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$r['s']->c, $r['s']->e, $r['pc'], $voce]), array_merge([
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $voce->tipo, 'importo' => number_format($voce->importo / 100, 2, ',', '.'), 'descrizione' => '', 'note' => '',
        'parent_id' => $voce->parent_id, 'tabella_millesimale_id' => $r['s']->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $campi));
}

/** Il fondo di riserva libero da € 5.000,00, con il suo conto. */
function b14t4Fondo(array $r): Cassa
{
    $s = $r['s'];
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo_riserva', 'codice' => '1010.T4', 'nome' => 'Fondo di riserva',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create(['condominio_id' => $s->c->id, 'nome' => 'Fondo di riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => 'generico', 'conto_contabile_id' => $conto,
        'saldo_iniziale' => 500000, 'attiva' => true]);
}

// =====================================================================================================================================================
// Coda 743
// =====================================================================================================================================================

it('743 (1), il rendiconto: il ripianamento con la fattura ancora da pagare è «da pagare», non «pagato»', function () {
    $r = b14t4Debito($this);
    b14t4Ripiana($this, $r);

    // A mano: la voce del ripianamento costa € 1.200,00 quest'anno, e il fornitore non ha ricevuto niente. La stessa cifra scritta alla registrazione
    // (la sopravvenienza) dà pagato € 0,00 e da pagare € 1.200,00: il ripianamento è la stessa spesa, scritta dopo.
    $riga = b14t4RigaDelRendiconto($r['s'], 'Ripianamento del debito verso Idraulica Bianchi');
    expect($riga)->not->toBeNull()
        ->and([$riga['consuntivo'], $riga['pagato'], $riga['da_pagare']])->toBe([120000, 0, 120000]);
});

it('743 (2), lo scoperto: non si ripiana più dello scoperto del compito, e la pagina lo sa', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    // La proposta: lo scoperto è € 1.200,00, la rata 0 della pregressa € 2.000,00; il massimo è lo scoperto (gli altri € 800,00 li chiedono già i pregressi).
    $proposta = RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id);
    expect([$proposta['scoperto'], $proposta['massimo'], $proposta['proposto']])->toBe([120000, 120000, 120000]);

    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4CorpoRipianamento($r, [], ['importo' => '2.000,00']));
    $risposta->assertUnprocessable();
    expect($risposta->json('errors')['ripianamento.importo'][0])->toContain('€ 1.200,00')
        ->and(PianoRate::query()->where('condominio_id', $s->c->id)->count())->toBe(0)
        ->and(FatturaCopertura::query()->where('fattura_passiva_id', $r['fattura']->id)->pluck('importo', 'tipo_copertura')->map(fn ($v) => (int) $v)->all())
        ->toBe(['rata_0' => 200000]);
});

it('743 (3), il ripianamento parziale: il compito del deficit resta aperto con il resto, e il secondo piano lo chiude', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    b14t4Ripiana($this, $r, ['nome' => 'Prima metà'], ['importo' => '600,00']);
    expect((bool) $r['compito']->fresh()->is_completed)->toBeFalse();
    $proposta = RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id);
    expect([$proposta['scoperto'], $proposta['massimo'], $proposta['proposto']])->toBe([60000, 60000, 60000])
        // L'Action inbox lo dice: resta da ripianare € 600,00, e «Prepara il piano di ripianamento» porta al resto.
        ->and(\App\Services\Gestionale\Inbox\TipiDeiCompiti::sottoDaiDati('pianifica_ripianamento_deficit', $r['compito']->fresh(), null))->toBe('Scoperto di € 600,00')
        ->and(\App\Services\Gestionale\Inbox\TipiDeiCompiti::azione('pianifica_ripianamento_deficit', $r['compito']->fresh())['url'])->toContain('ripianamento=' . $r['compito']->id);

    b14t4Ripiana($this, $r, ['nome' => 'Seconda metà'], ['importo' => '600,00']);
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue()
        ->and(RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id))->toBeNull();
});

it('743 (4), due ripianamenti con due tabelle: ogni piano chiede la sua parte con la sua tabella, e il ricalcolo non le mescola', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    $primo = b14t4Ripiana($this, $r, ['nome' => 'Sulla proprietà'], ['importo' => '600,00']);
    $secondo = b14t4Ripiana($this, $r, ['nome' => 'Sulle scale'], ['importo' => '600,00', 'tabella_id' => $s->tabelle['Scale']->id]);

    // A mano: € 600,00 a 600/400 = € 360,00 e € 240,00; € 600,00 a 500/500 = € 300,00 e € 300,00.
    expect(b14t4PerPersona($s, $primo))->toBe(['ugo' => 36000, 'bice' => 24000])
        ->and(b14t4PerPersona($s, $secondo))->toBe(['ugo' => 30000, 'bice' => 30000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $primo]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo'])->assertSessionHasNoErrors();
    expect(b14t4PerPersona($s, $primo))->toBe(['ugo' => 36000, 'bice' => 24000]);
});

it('743 (4), la voce del ripianamento tolto si riusa: un nuovo ripianamento non ne crea una seconda', function () {
    $r = b14t4Debito($this);

    b14t4Elimina($this, $r, b14t4Ripiana($this, $r, ['genera_subito' => 0]));
    b14t4Ripiana($this, $r, ['nome' => 'Di nuovo', 'genera_subito' => 0]);

    expect(Conto::query()->where('nome', 'like', 'Ripianamento del debito verso Idraulica Bianchi%')->pluck('importo')->map(fn ($v) => (int) $v)->all())->toBe([120000]);
});

it('743 (4), la voce rimasta libera si riusa anche con un\'altra tabella: prende la tabella nuova, e il piano divide con quella', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    b14t4Elimina($this, $r, b14t4Ripiana($this, $r, ['genera_subito' => 0]));
    $piano = b14t4Ripiana($this, $r, ['nome' => 'Sulle scale'], ['tabella_id' => $s->tabelle['Scale']->id]);

    $voci = Conto::query()->where('nome', 'like', 'Ripianamento del debito verso Idraulica Bianchi%')->get();
    expect($voci)->toHaveCount(1)
        ->and(DB::table('conto_tabella_millesimale')->where('conto_id', $voci[0]->id)->pluck('tabella_id')->map(fn ($v) => (int) $v)->all())->toBe([$s->tabelle['Scale']->id])
        // A mano: € 1.200,00 a 500/500.
        ->and(b14t4PerPersona($s, $piano))->toBe(['ugo' => 60000, 'bice' => 60000]);
});

it('743 (4), il compito chiuso a mano dall\'amministratore resta chiuso quando il piano si elimina', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    $piano = b14t4Ripiana($this, $r, ['genera_subito' => 0], ['importo' => '600,00']);
    // L'amministratore decide che il resto lo paga la banca: «Fatto» sul compito.
    app(\App\Services\Gestionale\RispostePromemoria::class)->fattoDelCompito($r['compito']->fresh(), 'Il resto lo paga il conto ordinario', $s->admin);
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue();

    b14t4Elimina($this, $r, $piano);
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue();
});

it('743 (4), le scritture: il ripianamento ha il suo tipo, e l\'annullamento è il suo storno (non una rettifica, che il programma legge come lo storno di un incasso)', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];

    b14t4Elimina($this, $r, b14t4Ripiana($this, $r, ['genera_subito' => 0]));
    $scritture = ScritturaContabile::query()->where('condominio_id', $s->c->id)->whereNotIn('tipo_movimento', ['fattura_acquisto'])->orderBy('id')->get();
    expect($scritture->map(fn ($x) => $x->tipo_movimento->value)->all())->toBe(['ripianamento_debito', 'storno_ripianamento_debito'])
        ->and((int) $scritture[1]->scrittura_padre_id)->toBe($scritture[0]->id);
    // Nel mastrino delle sopravvenienze il ripianamento si legge «stornato», con il nome del movimento.
    $mastrino = app(\App\Services\Gestionale\RegistroContabilitaService::class)->mastrino($s->e->fresh(),
        ContoContabile::query()->where('condominio_id', $s->c->id)->where('ruolo', 'sopravvenienze_passive')->sole());
    $righe = collect($mastrino['righe']);
    expect($righe->firstWhere('scrittura_id', $scritture[0]->id)['stornata'])->toBeTrue()
        ->and($righe->firstWhere('scrittura_id', $scritture[0]->id)['tipo_movimento_label'])->toBe('Ripianamento del debito ereditato');
});

it('743 (4), una tabella non attiva non divide il debito ereditato', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];
    $s->tabelle['Scale']->update(['attiva' => false]);

    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4CorpoRipianamento($r, [], ['tabella_id' => $s->tabelle['Scale']->id]));
    $risposta->assertUnprocessable();
    expect($risposta->json('errors'))->toHaveKey('ripianamento.tabella_id')
        ->and(PianoRate::query()->where('condominio_id', $s->c->id)->count())->toBe(0);
});

it('743, la catena da capo a capo: la pregressa, il ripianamento, l\'emissione, l\'incasso, il rendiconto «da pagare» e poi «pagato», il compito chiuso', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];
    $piano = b14t4Ripiana($this, $r);

    // L'emissione della prima rata (€ 600,00: Ugo € 360,00, Bice € 240,00) e l'incasso della quota di Ugo.
    $rata1 = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.emetti', [$s->c, $piano]), ['rate_ids' => [$rata1->id], 'data_emissione' => '2026-11-30',
        'invia_notifiche' => false])->assertSessionHasNoErrors();
    $quota = DB::table('rate_quote')->where('rata_id', $rata1->id)->where('anagrafica_id', $s->persona('ugo')->id)->first();
    expect((int) $quota->importo)->toBe(36000);
    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona('ugo')->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-12-01', 'importo_totale' => '360.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $r['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '360.00']],
    ])->assertSessionHasNoErrors();

    // Il compito: niente più da ripianare, chiuso, e l'Action inbox non lo mostra con uno scoperto.
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue()
        ->and((int) $r['compito']->fresh()->meta['importo_buco'])->toBe(0)
        ->and((int) $r['compito']->fresh()->meta['importo_buco_ereditato'])->toBe(120000);

    // Il rendiconto con il fornitore ancora da pagare: la voce costa € 1.200,00, da pagare.
    $riga = b14t4RigaDelRendiconto($s, 'Ripianamento del debito verso Idraulica Bianchi');
    expect([$riga['consuntivo'], $riga['pagato'], $riga['da_pagare']])->toBe([120000, 0, 120000]);

    // Il fornitore pagato per intero dalla banca: la voce è pagata (€ 1.200,00 dei € 2.000,00 della fattura sono suoi).
    $this->actingAs($s->admin)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'conto_corrente_id' => $s->cassa->conto_contabile_id, 'data_pagamento' => '2026-12-04',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $r['fattura']->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 200000]],
    ])->assertSessionHasNoErrors();
    $riga = b14t4RigaDelRendiconto($s, 'Ripianamento del debito verso Idraulica Bianchi');
    expect([$riga['consuntivo'], $riga['pagato'], $riga['da_pagare']])->toBe([120000, 120000, 0]);
});

// =====================================================================================================================================================
// Coda 742
// =====================================================================================================================================================

it('742 (5), il piano delle fatture approvato tiene ferma la voce a € 0: l\'importo non cambia, e il preventivo non la chiede di nuovo', function () {
    $r = b14t4Tetto($this);
    $s = $r['s'];
    $f = b14t4Fattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4PianoDelleFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('condominio_id', $s->c->id)->sole();
    $this->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-11-15'])
        ->assertSessionHasNoErrors();

    $tetto = $s->voci['Manutenzione tetto']['conto'];
    b14t4ModificaVoce($this, $r, $tetto, ['importo' => '1.400,00'])->assertSessionHasErrors();
    expect((int) $tetto->fresh()->importo)->toBe(0);

    // Il preventivo «Dalle voci» senza sceglierle chiede le sole pulizie: € 1.000,00, non € 2.400,00.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    expect(array_sum(b14t4PerPersona($s, $preventivo)))->toBe(100000);
});

it('742 (6), «Dalle voci» non chiede una voce a € 0 che il piano delle fatture chiede già', function () {
    $r = b14t4Tetto($this);
    $s = $r['s'];
    $f = b14t4Fattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4PianoDelleFatture($this, $r, $f, '1.400,00', 'Tetto a rate')->assertSessionHasNoErrors();
    $tetto = $s->voci['Manutenzione tetto']['conto'];

    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto dalle voci', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'capitoli_ids' => [$tetto->id],
        'capitoli_config' => [['id' => $tetto->id, 'importo' => '1.400,00']], 'genera_subito' => 0,
    ]);
    $risposta->assertUnprocessable();
    expect($risposta->json('errors')['capitoli_config.0.importo'][0])->toContain('«Tetto a rate»')
        ->and(PianoRate::query()->where('nome', 'Tetto dalle voci')->exists())->toBeFalse();
});

it('742 (7), la nota di credito collegata sulla voce a € 0 riduce la fattura: si mettono a rate € 1.000,00', function () {
    $r = b14t4Tetto($this);
    $s = $r['s'];
    $f = b14t4Fattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-1',
        'data_documento' => '2026-11-02', 'data_scadenza' => '2026-11-30', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'fattura_rettificata_id' => $f->id,
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Sconto sul manto', 'importo_imponibile' => 400.00, 'aliquota_iva' => 0, 'conto_id' => $s->voci['Manutenzione tetto']['conto']->id,
            'immobile_id' => null, 'is_sopravvenienza' => false]],
    ])->assertSessionHasNoErrors();

    $riga = b14t4Carrello($this, $r)->firstWhere('id', $f->id);
    expect($riga)->not->toBeNull()->and((float) $riga['residuo_da_finanziare'])->toBe(1000.0);
    b14t4PianoDelleFatture($this, $r, $f, '1.000,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('condominio_id', $s->c->id)->sole();
    expect(b14t4PerPersona($s, $piano))->toBe(['ugo' => 60000, 'bice' => 40000]);
});

it('742 (8), la fattura mista: il piano già fatto sulla riga dell\'unità non cambia al «Ricalcola», e la riga sulla voce a € 0 resta al conguaglio', function () {
    // Il piano è nato quando «Manutenzione tetto» aveva il suo importo: la riga sul tetto era a preventivo, e il piano prendeva la sola riga dell'unità.
    $r = b14t4Tetto($this, 50000);
    $s = $r['s'];
    $f = b14t4Fattura($this, $r, [['Manutenzione tetto', 70.00, null], ['Pulizie', 100.00, 'A']]);
    b14t4PianoDelleFatture($this, $r, $f, '100,00', 'Rubinetto di Ugo')->assertSessionHasNoErrors();
    $vecchio = PianoRate::query()->where('nome', 'Rubinetto di Ugo')->sole();
    expect(b14t4PerPersona($s, $vecchio))->toBe(['ugo' => 10000, 'bice' => 0]);

    // Poi la voce scende a € 0 (come una voce a € 0 della beta.13 che aveva già un piano), e il piano si ricalcola: a mano, € 100,00 tutti a Ugo, come
    // prima. Il piano dice € 100,00 della fattura, non quali righe: diviso anche sulla riga del tetto, Bice pagava € 16,47 della riga di Ugo.
    b14t4ModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $vecchio]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo'])->assertSessionHasNoErrors();
    expect(b14t4PerPersona($s, $vecchio))->toBe(['ugo' => 10000, 'bice' => 0]);

    // La riga sul tetto non entra in un piano delle fatture: il carrello non offre la fattura di nuovo, e «Le rate» dice dove va la riga.
    expect(b14t4Carrello($this, $r)->firstWhere('id', $f->id))->toBeNull()
        ->and(SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin)['frase'])
        ->toBe('Nei piani: le righe da mettere a rate sono già in un piano rate; la riga sulla voce «Manutenzione tetto», che non è nel preventivo, resta al conguaglio del rendiconto');
});

it('742 e tappa 6, la voce a € 0 coperta dal fondo di riserva non si mette a rate: il carrello non la offre, e «Le rate» dice il fondo', function () {
    $r = b14t4Tetto($this);
    $s = $r['s'];
    $fondo = b14t4Fondo($r);
    $f = b14t4Fattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000,
        ['strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $fondo->conto_contabile_id]);
    expect($f->coperture()->pluck('tipo_copertura')->all())->toBe(['fondo_riserva']);

    expect(b14t4Carrello($this, $r)->firstWhere('id', $f->id))->toBeNull();
    $rate = SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin);
    expect($rate['url'])->toBeNull()
        ->and($rate['frase'])->toBe('La voce «Manutenzione tetto» non è nel preventivo: la spesa la copre il fondo «Fondo di riserva», non si mette a rate');
    b14t4PianoDelleFatture($this, $r, $f, '1.400,00', 'Tetto a rate', true)->assertUnprocessable();
});

it('742 e tappa 6, controprova: la pregressa coperta dal fondo (la voce tecnica a € 0) non si offre a rate e non si ripiana', function () {
    $r = b14t4Debito($this);
    $s = $r['s'];
    $fondo = b14t4Fondo($r);
    // Un'altra pregressa dello stesso fornitore, fuori dal debito d'apertura: € 610,00 coperti dal fondo, sulla voce tecnica a € 0.
    $altra = (new FatturaPassivaService())->registraFattura([
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-90',
        'data_documento' => '2025-10-31', 'data_scadenza' => '2025-11-30', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => 610.00,
        'aliquota_iva_pregressa' => 0, 'righe' => [], 'coperture' => [], 'competenza_dal' => '2025-10-01', 'competenza_al' => '2025-10-31',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'nome_voce' => 'Debito 2025 Idraulica', 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'origine_decisionale' => 'delibera_assembleare',
            'data_assemblea' => '2026-10-15', 'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $fondo->conto_contabile_id, 'tipo_ripartizione' => 'millesimale']],
    ], $s->c->id);
    $voce = Conto::query()->where('nome', 'Debito 2025 Idraulica')->sole();
    expect($altra->coperture()->pluck('conto_id', 'tipo_copertura')->all())->toBe(['fondo_riserva' => $voce->id])->and((int) $voce->importo)->toBe(0);

    expect(b14t4Carrello($this, $r)->firstWhere('id', $altra->id))->toBeNull()
        ->and(SpesaDaMettereARate::perLaFattura($altra->fresh(), $s->admin))->toBeNull()
        ->and(\App\Services\Gestionale\VoceFuoriDalPreventivo::idDellaFattura((int) $altra->id))->toBe([]);
    // Il ripianamento del debito d'apertura non tocca la pregressa coperta dal fondo.
    expect(collect(RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id)['fatture'])->pluck('id')->all())->toBe([$r['fattura']->id]);
});
