<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le frasi della nota che dicono una cifra con il suo nome: le spese del riepilogo finanziario non sono le spese dell'esercizio; la spesa oltre il
 * preventivo è il totale della fattura, lo sforo la sua parte oltre; la percentuale delle quote non versate è sulle rate già scadute; l'ordine dei
 * versamenti è il criterio della legge, e i versamenti fuori ordine non dichiarati non sono una scelta dell'amministratore; un condominio senza niente da
 * dividere non ha i centesimi divisi né un conguaglio da votare.
 */

use App\Models\User;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

function nsfrE(string $cifra): string
{
    return '€'."\u{a0}".$cifra;
}

/** Via Verdi al 01/03/2027, con le spese, il passaggio e la spesa personale. */
function nsfrViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

/** I fatti proposti oggi, per chiave. */
function nsfrFatti(array $s): array
{
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);

    return ['fatti' => collect(app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture)['fatti'])->keyBy('chiave')->all(), 'letture' => $letture];
}

it('G3 dice le spese delle voci del riepilogo finanziario, la cifra che il frontespizio chiama così; con una straordinaria, delle gestioni ordinarie', function () {
    $s = vrmScenario($this);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);
    $r = $letture['controlli']['riepilogo'];
    // L'anno prima, messo nel riepilogo: ogni voce ha speso quanto quest'anno.
    $r['anno_prima'] = ['id' => 1, 'nome' => 'Esercizio 2025'];
    foreach ($r['blocchi'] as $bi => $b) {
        foreach ($b['spese'] as $ci => $cap) {
            if (isset($cap['voci'])) {
                foreach ($cap['voci'] as $vi => $v) {
                    $r['blocchi'][$bi]['spese'][$ci]['voci'][$vi]['anno_prima'] = (int) ($v['consuntivo'] ?? 0);
                }
            } else {
                $r['blocchi'][$bi]['spese'][$ci]['anno_prima'] = (int) ($cap['consuntivo'] ?? 0);
            }
        }
    }
    [$g3] = FattiDellaNota::g3($r);
    $g1 = FattiDellaNota::g1($letture['sintesi']['prospetto']);
    $delRiepilogo = (int) array_sum(array_map(fn ($b) => (int) ($b['totale_spese']['consuntivo'] ?? 0), $r['blocchi']));

    // G1 dice le spese dell'esercizio (i costi); G3 le spese del riepilogo: due cifre, due nomi.
    expect($g1['frase'])->toStartWith('Le spese dell\'esercizio sono '.nsfrE('4.131,00'))
        ->and($g3['cifre']['ora'])->toBe($delRiepilogo)->not->toBe($g1['cifre']['costi'])
        ->and(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $letture['sintesi']['prospetto']['spese'])))
        ->toContain('Spese del riepilogo finanziario € '.number_format($delRiepilogo / 100, 2, ',', '.'))
        ->and($g3['frase'])->toBe('Le spese delle voci del riepilogo finanziario sono '.FattiDellaNota::euro($delRiepilogo).', uguali a quelle dell\'esercizio prima ('
            .FattiDellaNota::euro($delRiepilogo).').');
    // Con una gestione straordinaria nell'esercizio, la frase dice di quali gestioni sono le voci.
    $straordinaria = $r['blocchi'][0];
    $straordinaria['gestione']['tipo'] = 'straordinaria';
    $r['blocchi'][] = $straordinaria;
    expect(FattiDellaNota::g3($r)[0]['frase'])->toStartWith('Le spese delle voci del riepilogo finanziario delle gestioni ordinarie sono '.FattiDellaNota::euro($delRiepilogo));
});

it('Q5: la spesa oltre il preventivo è il totale della fattura, e lo sforo la parte oltre; la riga da decidere dice tutte e due le cifre', function () {
    $s = nsfrViaVerdi($this);
    $fattura = cpmSpesa($s, $s['giornale'], $s['pulizie'], 150000, ['tipo' => 'fattura']);
    DB::table('fatture_passive')->where('id', $fattura)->update(['stato_approvazione' => 'sforo_motivato',
        'dati_extra' => json_encode(['override_budget' => ['importo_sforo' => 120000, 'motivazione' => 'Un guasto alla pompa']])]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura, 'conto_id' => $s['pulizie']->id, 'descrizione' => 'Intervento', 'importo_imponibile' => 150000,
        'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $q5 = FattiDellaNota::q5($s['e']);

    expect($q5['frase'])->toBe('Una spesa di '.nsfrE('1.500,00').' ha superato di '.nsfrE('1.200,00').' il preventivo della voce '.$s['pulizie']->nome
        .'; la ratifica dell\'assemblea è da chiedere. La motivazione scritta alla registrazione: «Un guasto alla pompa».')
        ->and($q5['voto'])->toBe('La ratifica della spesa di '.nsfrE('1.500,00').', '.nsfrE('1.200,00').' oltre il preventivo della voce '.$s['pulizie']->nome.'.');
});

it('N1: la percentuale delle quote non versate è sulle rate già scadute; a esercizio aperto una rata non ancora scaduta non conta', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], []);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    $this->travelTo(\Carbon\Carbon::parse('2026-03-20 10:00', 'Europe/Rome'));
    ['fatti' => $f, 'letture' => $l] = nsfrFatti($s);
    // Le quote delle rate scadute prima del 20/03/2026, con il credito dell'anno prima nella prima rata: la stessa somma delle quote del piano.
    $scadute = (int) DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->where('r.piano_rate_id', $s['piano']->id)
        ->whereDate('r.data_scadenza', '<', '2026-03-20')->sum('q.importo');
    $p = $l['sintesi']['prospetto'];
    $cifra = FattiDellaNota::euro($scadute);

    expect($scadute)->toBeGreaterThan(0)->toBeLessThan((int) $p['quote_piani'])
        ->and([$p['quote_scadute'], $p['quote_scadute_non_pagate']])->toBe([$scadute, $scadute])
        ->and($f['Q1']['frase'])->toContain('Delle quote dei piani dell\'esercizio già scadute, '.$cifra.', non sono state versate '.$cifra.': il 100'."\u{00A0}".'%.')
        ->not->toContain(FattiDellaNota::euro((int) $p['quote_piani']).', non sono');
});

it('K4: il criterio dell\'art. 1193 c.c. detto come criterio; i versamenti fuori ordine non dichiarati non sono una scelta dell\'amministratore', function () {
    $s = nsfrViaVerdi($this);
    $incassi = DB::table('scritture_contabili')->where('esercizio_id', $s['e']->id)->where('tipo_movimento', 'incasso_rata')->orderBy('id')->pluck('id')->all();
    expect(count($incassi))->toBeGreaterThanOrEqual(2);
    DB::table('scritture_contabili')->where('id', $incassi[0])->update(['imputazione' => OrdineImputazione::FUORI_ORDINE]);
    DB::table('scritture_contabili')->where('id', $incassi[1])->update(['imputazione' => OrdineImputazione::SCELTA_AMMINISTRATORE]);

    expect(FattiDellaNota::K4)->toContain('il criterio dell\'art. 1193 c.c.')->not->toContain('(art. 1193')
        ->and(FattiDellaNota::k4($s['e'])['frase'])->toBe(FattiDellaNota::K4.' Nell\'esercizio 2 versamenti sono stati imputati in un altro modo: 1 per scelta dell\'amministratore e 1 senza una ragione scritta.');
    DB::table('scritture_contabili')->where('id', $incassi[1])->update(['imputazione' => OrdineImputazione::FUORI_ORDINE]);
    expect(FattiDellaNota::k4($s['e'])['frase'])->toEndWith('2 versamenti sono stati imputati in un altro modo, senza una ragione scritta.');
});

it('un condominio senza niente da dividere: niente centesimi divisi e niente conguaglio da votare; la scheda e la stampa rispondono', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-14 10:00', 'Europe/Rome'));
    $c = \App\Models\Condominio::factory()->create();
    $e = \App\Models\Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $f = app(FattiDellaNota::class)->perEsercizio($c, $e, $nota->letture($c, $e));
    NotaSinteticaDelRendiconto::salva($e, NotaSinteticaDelRendiconto::normalizza(['fatti' => array_fill_keys(array_column($f['fatti'], 'chiave'), ['stato' => 'tenuto'])]), $this->user);
    $stampa = $nota->perLaStampa($c, $e, $nota->letture($c, $e));

    expect(array_column($f['fatti'], 'chiave'))->not->toContain('K3')
        ->and(array_column($f['non_proposti'], 'perche', 'chiave')['K3'] ?? null)->toBe('Nell\'esercizio nessuna spesa è divisa fra le persone.')
        ->and(collect($f['fatti'])->pluck('voto')->filter()->all())->toBe([])
        ->and(collect($stampa['parti'])->flatMap(fn ($p) => $p['da_decidere'] ?? [])->all())->toBe([]);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota', [$c, $e]))->assertOk();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.nota.stampa', [$c, $e]))->assertOk();
});
