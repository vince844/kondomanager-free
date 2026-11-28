<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La riga di titolarità chiusa a mano si corregge dallo storico dell'unità
 *
 * Fino alla 1.11.0-beta.30 il programma non accettava due proprietari al 100 % sulla stessa unità, anche in periodi diversi: per registrare chi era
 * subentrato si abbassava a 0 % chi aveva venduto. Quella riga, chiusa a mano, sta nello storico «Chi ha avuto questa unità» senza nessuna azione, e la
 * tabella dei soggetti mostra solo i titolari di oggi: nessuna strada la raggiunge, e nel suo periodo nessuno paga per l'unità.
 *
 * Il caso di Carmen: Interno 5, il costruttore proprietario dal 01/01 al 15/09/2026 con quota 0 %, il nuovo proprietario dal 16/09. Il preventivo è di
 * € 1.000,00 su due unità da 500 millesimi: all'Interno 5 toccano € 500,00, e con la quota a 0 % € 353,42 (258 giorni su 365) non li paga nessuno.
 *
 * Lo storico porta per ogni riga `correggibile` (chiusa, senza un passaggio che la apre o la chiude, non riga di un erede) e `url_correggi` (la pagina di
 * modifica di sempre), e per la riga di proprietà a quota 0 i `giorni_scoperti`. La persona non si cambia, come prima. La catena: storico → modifica della
 * riga → titolarità → motore del riparto → piano in bozza (ricalcolo). Le rate già emesse non cambiano da sole.
 *
 * Cosa NON copre: i piani già emessi e il loro ricalcolo (l'elenco dei piani che cambiano); «Registralo come passaggio»; il pannello a video (le prove
 * del componente stanno in `TitolaritaSheet.correggi.test.ts`).
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/LetturaDebitiProva.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole(Role::AMMINISTRATORE->value);
    $this->user = $this->user->fresh();
});

/**
 * Carmen: Interno 5 (costruttore a 0 % dal 01/01 al 15/09, il nuovo proprietario dal 16/09), Interno 6 (500 millesimi) e Interno 7 (Ugo, che vende a Elsa con
 * un passaggio registrato). Preventivo di € 1.000,00 sulle prime due unità; il piano è rigenerato con le righe di oggi.
 */
function srcCarmen($test): array
{
    $test->travelTo('2026-11-24 10:00:00');
    $s = lcdScenario(['5' => ['edil', 500], '6' => ['bice', 500], '7' => ['ugo', 0]], 12, 'prima_rata', [], 100000);
    $mario = Anagrafica::forceCreate(['nome' => 'Mario', 'email' => 'mario-src@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'MARIOXSRC000001']);
    $mario->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['persone']['mario'] = $mario;
    $edil = $s['persone']['edil'];
    $rigaEdil = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $edil->id)->where('immobile_id', $s['immobili']['5']->id)->value('id');
    DB::table('anagrafica_immobile')->where('id', $rigaEdil)->update(['quota' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-09-15']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $mario->id, 'immobile_id' => $s['immobili']['5']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-09-16', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $s['riga_edil'] = $rigaEdil;

    return $s;
}

function srcRicalcola($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
}

/** Quanto chiede il piano a ogni persona, in centesimi, per nome. */
function srcQuote(array $s): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
        ->where('rate.piano_rate_id', $s['piano']->id)->groupBy('anagrafiche.nome')->selectRaw('anagrafiche.nome as nome, sum(rate_quote.importo) as totale')
        ->pluck('totale', 'nome')->map(fn ($v) => (int) $v)->all();
}

function srcStorico($test, array $s, string $unita): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili'][$unita]]))
        ->assertOk()->viewData('page')['props']['storico'];
}

/** La riga dello storico di una persona, per nome. */
function srcRiga(array $storico, string $nome): array
{
    return collect($storico['righe'])->first(fn ($r) => $r['anagrafica']['nome'] === $nome);
}

test('lo storico porta, sulla riga chiusa a mano, `correggibile`, l\'indirizzo della modifica e i giorni che nessuno paga; sulla riga in corso no', function () {
    $s = srcCarmen($this);

    $storico = srcStorico($this, $s, '5');
    $edil = srcRiga($storico, 'Edil');
    $mario = srcRiga($storico, 'Mario');

    expect($edil['correggibile'])->toBeTrue()
        ->and($edil['url_correggi'])->toBe(route('admin.gestionale.immobili.anagrafiche.edit', [$s['c'], $s['immobili']['5'], $s['riga_edil']]))
        ->and($edil['giorni_scoperti'])->toBe(258)
        ->and($mario['correggibile'])->toBeFalse()
        ->and($mario['url_correggi'])->toBeNull()
        ->and($mario['giorni_scoperti'])->toBe(0);
});

test('la riga chiusa da un passaggio registrato non è correggibile, e nemmeno quella che il passaggio ha aperto', function () {
    $s = srcCarmen($this);
    $elsa = Anagrafica::forceCreate(['nome' => 'Elsa', 'email' => 'elsa-src@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ELSAXSRC0000001']);
    $elsa->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Ugo era proprietario di Interno 7 da sempre: vende a Elsa il 01/05.
    DB::table('anagrafica_immobile')->where('immobile_id', $s['immobili']['7']->id)->update(['quota' => 100]);
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['ugo']->id)->where('immobile_id', $s['immobili']['7']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['immobili']['7']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-01', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $storico = srcStorico($this, $s, '7');
    $ugo = srcRiga($storico, 'Ugo');

    expect($ugo['data_fine'])->toBe('2026-04-30')
        ->and($ugo['correggibile'])->toBeFalse()
        ->and($ugo['url_correggi'])->toBeNull()
        ->and(srcRiga($storico, 'Elsa')['correggibile'])->toBeFalse();
});

test('la catena: dallo storico alla riga corretta, e il preventivo ricalcolato chiede € 353,42 al costruttore, € 146,58 al nuovo proprietario e € 500,00 all\'altra unità', function () {
    $s = srcCarmen($this);

    // Oggi: con il costruttore a 0 % nessuno paga i 258 giorni, € 353,42 restano senza nome.
    srcRicalcola($this, $s);
    expect(srcQuote($s))->toBe(['Bice' => 50000, 'Mario' => 14658]);

    // Dallo storico alla modifica: l'indirizzo della riga si apre, e si salva con la quota giusta (la persona non cambia).
    $edil = srcRiga(srcStorico($this, $s, '5'), 'Edil');
    $this->actingAs($this->user)->get($edil['url_correggi'])->assertOk();
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['immobili']['5'], $s['riga_edil']]), [
        'anagrafica_id' => $s['persone']['edil']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-09-15', 'note' => null,
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['immobili']['5']]));

    // La riga corretta non lascia più giorni scoperti; il piano in bozza si ricalcola con le tre cifre.
    expect(srcRiga(srcStorico($this, $s, '5'), 'Edil')['giorni_scoperti'])->toBe(0);
    srcRicalcola($this, $s);
    expect(srcQuote($s))->toBe(['Bice' => 50000, 'Edil' => 35342, 'Mario' => 14658]);
});
