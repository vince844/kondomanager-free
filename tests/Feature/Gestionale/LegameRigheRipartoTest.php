<?php

/**
 * Decisione 55 (1.11.0-beta.43): ogni riga di riparto sa da quale riga di titolarità viene (`righe_riparto.anagrafica_immobile_id`).
 *
 * Cosa copre:
 * - il riparto per tabella: ogni riga legata alla riga di titolarità della sua persona e del suo ruolo;
 * - la stessa persona con due righe dello stesso ruolo nel periodo (chi vende e ricompra): due righe di riparto, due legami;
 * - l'addebito diretto all'unità: legato quando la persona ha una riga sola; nullo nel calcolo per persona quando ne ha due;
 * - il dettaglio ricostruito (piani senza righe di riparto): sempre senza legame, perché usa la titolarità di oggi;
 * - una riga di titolarità cancellata: il legame diventa nullo, la riga di riparto resta (`nullOnDelete`).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Riparto\DettaglioRiparto;
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

/** Le righe di riparto del piano: [tipo, persona, ruolo, legame]. */
function lrrRighe(array $s): array
{
    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->orderBy('id')->get(['tipo', 'anagrafica_id', 'ruolo_risolto', 'anagrafica_immobile_id'])
        ->map(fn ($r) => [$r->tipo, $r->anagrafica_id === null ? null : (int) $r->anagrafica_id, $r->ruolo_risolto, $r->anagrafica_immobile_id === null ? null : (int) $r->anagrafica_immobile_id])->all();
}

it('decisione 55 — il riparto per tabella: la riga di riparto di Ugo è legata alla sua riga di titolarità', function () {
    $s = ruScenario('prima_rata', 0);

    expect(lrrRighe($s))->toBe([['riparto', $s['v']->id, 'proprietario', $s['rigaV']]]);
});

it('decisione 55 — chi vende e ricompra nel periodo ha due righe di titolarità dello stesso ruolo: due righe di riparto, ciascuna con il suo legame', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['data_fine' => '2026-02-28']);
    $bice = \App\Models\Anagrafica::forceCreate(['nome' => 'Bice Intermedia', 'email' => "lrr-b{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'LRRBICE' . str_pad((string) $s['unita']->id, 9, '0', STR_PAD_LEFT)]);
    $bice->condomini()->syncWithoutDetaching([$s['c']->id]);
    $riga = fn (int $chi, string $dal, ?string $al) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $chi, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    $rigaBice = $riga($bice->id, '2026-03-01', '2026-04-30');
    $rigaUgo2 = $riga($s['v']->id, '2026-05-01', null);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Titolari cambiati nel periodo, di prova', esercizio: $s['e']);

    $legami = collect(lrrRighe($s))->map(fn ($r) => [$r[1], $r[3]])->sortBy(fn ($r) => $r[1])->values()->all();
    expect($legami)->toBe([[$s['v']->id, $s['rigaV']], [$bice->id, $rigaBice], [$s['v']->id, $rigaUgo2]]);
});

it('decisione 55 — l\'addebito diretto all\'unità: legato alla riga di titolarità della persona; nullo quando la stessa persona ha due righe e il calcolo è per persona', function (bool $dueRighe) {
    $s = ruScenario('prima_rata', 0, genera: false);
    if ($dueRighe) {
        // Piano senza competenza (atemporale): l'addebito si divide per persona, e la persona ha due righe sull'unità.
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $conto = Conto::whereHas('pianoConto', fn ($q) => $q->where('gestione_id', $s['g']->id))->firstOrFail();
    $conto->update(['importo' => 0]);
    $s['piano']->update(['tipo' => 'straordinario', 'nome' => 'Spese da finanziare']);
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fattura = FatturaPassiva::create([
        'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-LRR{$s['unita']->id}",
        'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 36500, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 36500, 'netto_a_pagare' => 36500, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ] + ($dueRighe ? [] : ['competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31']));
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $s['unita']->id, 'descrizione' => 'Riparazione citofono interno 1', 'aliquota_iva' => 0,
        'importo_imponibile' => 36500, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $s['piano']->fatture()->attach($fattura->id, ['importo_collegato' => 36500]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Piano di prova da fatture', esercizio: $s['e']);

    $addebiti = collect(lrrRighe($s))->where(0, 'ad_personam')->values()->all();
    expect($addebiti)->not->toBe([])
        ->and(collect($addebiti)->pluck(3)->unique()->values()->all())->toBe($dueRighe ? [null] : [$s['rigaV']]);
})->with(['una riga' => false, 'due righe, calcolo per persona' => true]);

it('decisione 55 — il dettaglio ricostruito (piano senza righe di riparto) non porta legami: usa la titolarità di oggi', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    $dettaglio = DettaglioRiparto::perPiano($s['piano']->fresh());

    expect($dettaglio['fonte']['tipo'])->toBe(DettaglioRiparto::RICOSTRUITO)
        ->and(collect($dettaglio['righe'])->pluck('anagrafica_immobile_id')->filter()->all())->toBe([]);
});

it('decisione 55 — una riga di titolarità cancellata lascia la riga di riparto, senza legame', function () {
    $s = ruScenario('prima_rata', 0);
    // La riga di riparto legata proprio alla riga che si cancella (rilievo T15: con la prima riga del piano, non legata o tolta da
    // un `cascade`, il test passava lo stesso).
    $rigaRiparto = (int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('anagrafica_immobile_id', $s['rigaV'])->value('id');
    $piano = fn () => [DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count(), (int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->sum('importo')];
    $prima = $piano();

    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->delete();

    expect($rigaRiparto)->toBeGreaterThan(0)
        ->and(DB::table('righe_riparto')->where('id', $rigaRiparto)->exists())->toBeTrue()
        ->and(DB::table('righe_riparto')->where('id', $rigaRiparto)->value('anagrafica_immobile_id'))->toBeNull()
        ->and($piano())->toBe($prima);
});
