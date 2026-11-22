<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\SchedeDellaStoria;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * 1.12.0-beta.13 (§8, P14, P18, P22 del progetto della tappa 1; scena 1 della bozza): la rotta JSON della «Storia» che il cassetto legge.
 *
 * - Il titolo e la riga sotto il titolo della bozza («Storia di Ida Romano», «Anagrafica · Condominio Via Roma 12 · 3 modifiche dal 16/12/2026»); le righe
 *   dalla più recente con chi, il ruolo, l'azione e i campi «com'era → com'è»; **mai `valore_*`** né le impronte (P18).
 * - Venti righe per volta: «Mostra altre» chiede la pagina dopo, e la risposta dice quante restano.
 * - Il tipo da un elenco chiuso (404 fuori); le schede dello studio e gli oggetti eliminati solo all'amministratore; il collaboratore vede le schede dei
 *   condomini (P18).
 * - Per ogni campo, se «Ripristina» si può: sì per l'email della persona, no in contabilità (e la riga dice come si cambia).
 * - Il punto di innesto dell'oscuramento: solo l'amministratore, nella «Storia» di una persona.
 *
 * Che cosa non copre: il cassetto a video (`StoriaCassetto.test.ts`); le righe annullate da un ripristino (le salta la lettura del registro, provata in
 * `LetturaDelRegistroTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-12-16 09:12:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole('amministratore');
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole('collaboratore');
    $this->actingAs($this->andrea);
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it']);
    $this->ida->condomini()->attach($this->c->id);
    $this->actingAs($this->marco);
    $this->ida->update(['email' => 'ida.romano@gmail.com', 'pec' => 'ida.romano@pec.it']);
});

function sdsStoria($test, User $chi, string $tipo, ?int $id, int $pagina = 1)
{
    return $test->actingAs($chi)->getJson(route('admin.registro-modifiche.storia', array_filter(['tipo' => $tipo, 'id' => $id, 'pagina' => $pagina > 1 ? $pagina : null])));
}

it('il titolo, la riga sotto il titolo e le righe dalla più recente, con i campi «com\'era → com\'è» e senza valori grezzi né impronte', function () {
    $r = sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id)->assertOk()->json();

    expect($r['titolo'])->toBe('Storia di Ida Romano')
        ->and($r['sottotitolo'])->toBe('Anagrafica · Condominio Via Roma 12 · 2 modifiche dal 16/12/2026')
        ->and($r['totale'])->toBe(2)
        ->and($r['altre'])->toBe(0)
        ->and(array_column($r['righe'], 'azione'))->toBe(['modificata', 'creata']);
    $modificata = $r['righe'][0];
    expect($modificata['autore'])->toMatchArray(['nome' => 'Marco Galli', 'ruolo' => 'collaboratore'])
        ->and($modificata['avvenuta_il'])->toBe('16/12/2026 09:12')
        ->and($modificata['campi'][0])->toBe(['chiave' => 'email', 'etichetta' => 'Indirizzo email primario', 'prima' => 'ida.romano@libero.it',
            'dopo' => 'ida.romano@gmail.com', 'segreto' => false])
        ->and(json_encode($r))->not->toContain('valore_prima', 'valore_dopo', 'impronta', 'sequenza');
});

it('«Mostra altre»: venti righe per volta, la risposta dice quante restano, la pagina dopo le dà', function () {
    for ($i = 1; $i <= 22; $i++) {
        $this->ida->update(['telefono' => '333 000 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }

    $prima = sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id)->assertOk()->json();
    $seconda = sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id, 2)->assertOk()->json();

    expect($prima['totale'])->toBe(24)
        ->and($prima['righe'])->toHaveCount(20)
        ->and($prima['altre'])->toBe(4)
        ->and($seconda['righe'])->toHaveCount(4)
        ->and($seconda['altre'])->toBe(0)
        ->and(end($seconda['righe'])['azione'])->toBe('creata');
});

it('il tipo viene da un elenco chiuso: fuori dall\'elenco è un 404, e una storia di una scheda senza il suo id anche', function () {
    sdsStoria($this, $this->andrea, 'users', 1)->assertNotFound();
    sdsStoria($this, $this->andrea, 'registro_modifiche', 1)->assertNotFound();
    $this->actingAs($this->andrea)->getJson('/admin/registro-modifiche/storia/anagrafica')->assertNotFound();

    expect(SchedeDellaStoria::tipi())->toContain('anagrafica', 'fornitore', 'condominio', 'immobile', 'piano_rate', 'regole_recupero', 'saldi',
        'impostazioni_stampe', 'documenti', 'utente', 'ruolo', 'casse', 'categorie_fornitori');
});

it('le schede dello studio e gli oggetti che non ci sono più: l\'amministratore sì, il collaboratore no (403)', function () {
    sdsStoria($this, $this->andrea, 'impostazioni_generali', null)->assertOk();
    sdsStoria($this, $this->marco, 'impostazioni_generali', null)->assertForbidden();
    sdsStoria($this, $this->marco, 'utente', $this->andrea->id)->assertForbidden();

    $this->actingAs($this->andrea);
    $carlo = Anagrafica::query()->create(['nome' => 'Carlo Fontana', 'indirizzo' => 'Via Roma 12']);
    $id = $carlo->id;
    $carlo->delete();

    $r = sdsStoria($this, $this->andrea, 'anagrafica', $id)->assertOk()->json();
    expect(array_column($r['righe'], 'azione'))->toBe(['eliminata', 'creata'])
        ->and($r['titolo'])->toBe('Storia: Anagrafica di Carlo Fontana');
    sdsStoria($this, $this->marco, 'anagrafica', $id)->assertForbidden();
});

it('«Ripristina» per campo: sì sull\'email della persona per chi la può modificare; in contabilità no, e la riga dice come si cambia', function () {
    $r = sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id)->json();
    expect($r['righe'][0]['ripristina'])->toBe(['email' => true, 'pec' => true])
        ->and($r['righe'][1]['ripristina'])->toBe(['nome' => false, 'indirizzo' => false, 'email' => false]);

    RegistroModifiche::scrivi(Gesto::su('contabilita', 'fattura_passiva', 88, 'Fattura n. 88 di Serramenti Marino')->azione('modificata')
        ->campo('totale', 'Totale', '€ 14.000,00', '€ 14.500,00', 1400000, 1450000)->nelCondominio($this->c)->perLaRiga());
    $f = sdsStoria($this, $this->andrea, 'fattura_passiva', 88)->assertOk()->json();
    expect($f['righe'][0]['ripristina'])->toBe(['totale' => false])
        ->and($f['righe'][0]['nota'])->toBe('In contabilità non si ripristina: si storna, dalla fattura.');
});

it('il punto di innesto dell\'oscuramento: nella «Storia» di una persona solo per l\'amministratore', function () {
    expect(sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id)->json('oscuramento'))
        ->toBe(['soggetto_tipo' => 'anagrafica', 'soggetto_id' => $this->ida->id, 'nome' => 'Ida Romano'])
        ->and(sdsStoria($this, $this->marco, 'anagrafica', $this->ida->id)->json('oscuramento'))->toBeNull()
        ->and(sdsStoria($this, $this->andrea, 'condominio', $this->c->id)->json('oscuramento'))->toBeNull();
});

it('il collaboratore apre la «Storia» delle schede dei condomini: la persona, il condominio', function () {
    sdsStoria($this, $this->marco, 'anagrafica', $this->ida->id)->assertOk();
    $r = sdsStoria($this, $this->marco, 'condominio', $this->c->id)->assertOk()->json();
    expect($r['titolo'])->toBe('Storia del condominio Via Roma 12');
});

it('le righe annullate da un ripristino non compaiono, e la riga che non si annulla resta (P14)', function () {
    $comune = (int) RegistroModifica::query()->max('sequenza');
    $this->ida->update(['telefono' => '347 880 1290']);
    RegistroModifiche::scrivi(['area' => 'impostazioni', 'oggetto_tipo' => 'registro', 'oggetto_id' => null, 'oggetto_nome' => 'Copia del 10/12/2026',
        'azione' => 'ripristino_copia', 'riepilogo' => 'Ripristinata la copia', 'lotto' => ['sequenza_comune' => $comune, 'prima_sequenza_rimessa' => $comune + 1,
            'ultima_sequenza_rimessa' => $comune + 1, 'copia_del' => '2026-12-10', 'oscuramenti_riapplicati' => 0]]);

    $r = sdsStoria($this, $this->andrea, 'anagrafica', $this->ida->id)->json();
    expect(collect($r['righe'])->pluck('campi.*.chiave')->flatten()->all())->not->toContain('telefono');
});

it('la «Storia» di un elenco intero: le categorie dei fornitori (dello studio, senza id) e le risorse di un condominio', function () {
    $this->actingAs($this->andrea);
    \App\Models\CategoriaFornitore::query()->create(['name' => 'Vetraio di prova']);
    $conto = \Illuminate\Support\Facades\DB::table('conti_contabili')->insertGetId(['condominio_id' => $this->c->id, 'ruolo' => 'cassa', 'codice' => '10.20',
        'nome' => 'Cassa contanti', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $cassa = \App\Models\Gestionale\Cassa::query()->create(['condominio_id' => $this->c->id, 'conto_contabile_id' => $conto, 'nome' => 'Cassa contanti',
        'tipo' => 'contanti', 'attiva' => true, 'saldo_iniziale' => 0]);
    $cassa->update(['nome' => 'Cassa contanti dello stabile']);

    $categorie = sdsStoria($this, $this->marco, 'categorie_fornitori', null)->assertOk()->json();
    $casse = sdsStoria($this, $this->andrea, 'casse', $this->c->id)->assertOk()->json();

    expect($categorie['titolo'])->toBe('Storia delle categorie dei fornitori')
        ->and(collect($categorie['righe'])->pluck('oggetto.nome')->all())->toContain('Categoria dei fornitori «Vetraio di prova»')
        ->and($casse['titolo'])->toBe('Storia delle risorse e dei fondi')
        ->and($casse['sottotitolo'])->toStartWith('Risorse e fondi · Condominio Via Roma 12 · ')
        ->and(collect($casse['righe'])->pluck('azione')->take(2)->all())->toBe(['modificata', 'creata']);
});
