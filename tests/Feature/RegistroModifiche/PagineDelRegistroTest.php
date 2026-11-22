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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (§14 del progetto della tappa 1; scene 2 e 5 della bozza): il registro del condominio e quello dello studio, la stampa, «Verifica
 * l'integrità».
 *
 * - La pagina del condominio: solo le righe di quel condominio, dalla più recente; il periodo di serie è il mese di oggi; la card del mese («Dicembre 2026 ·
 *   3 modifiche · da 2 persone e dal programma»), la card dell'integrità; le scelte dei menu (chi: le persone e «Il programma»; le aree; le azioni; i mesi).
 * - **I filtri senza ricaricare**: una visita parziale chiede solo `righe` e `riepilogo`, e le righe sono quelle del filtro.
 * - **Le pagine delle altre tabelle**: venti righe di serie, 50 o 100 a scelta, il totale e il numero delle pagine; la pagina cambia con una visita
 *   parziale delle sole righe, con i filtri.
 * - La riga annullata da un ripristino resta, con la scritta «Annullata dal ripristino del …».
 * - La pagina dello studio: tutti i condomini e ciò che è dello studio, con il condominio fra i filtri.
 * - La stampa: un PDF, con l'impronta della stampa (sha256 delle righe stampate) nell'intestazione della risposta; il periodo dal … al … e il conto
 *   della finestra in `StampaDelRegistroTest`.
 * - La verifica: «Il registro è integro.»; una riga cambiata con `DB::table` fa «Una riga non torna.» e il rapporto si scarica.
 *
 * Che cosa non copre: la pagina a video (`RegistroDelleModifiche.test.ts`); il contenuto del PDF pagina per pagina (mPDF); la conservazione e la sospensione
 * (`ConservazioneESospensioneTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-12-16 10:00:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole('amministratore');
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole('collaboratore');
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $this->actingAs($this->andrea);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida@libero.it']);
    $this->ida->condomini()->attach($this->c->id);
    $this->actingAs($this->marco);
    $this->ida->update(['email' => 'ida@gmail.com', 'pec' => 'ida@pec.it']);
    // Il programma, nel condominio: una pubblicazione programmata.
    RegistroModifiche::comeAutore(\App\Services\RegistroModifiche\Autore::programma('pianificato', 'pubblicazione delle comunicazioni programmate'),
        fn () => RegistroModifiche::scrivi(Gesto::su('comunicazioni', 'comunicazione', 9, 'Comunicazione «Lavori al portone»')->azione('gesto')
            ->riepilogo('Pubblicata in bacheca')->nelCondominio($this->c)->perLaRiga()));
    // Un'altra persona nell'altro condominio: non è nel registro di Via Roma 12.
    $this->actingAs($this->andrea);
    $luca = Anagrafica::query()->create(['nome' => 'Luca Esposito', 'indirizzo' => 'Corso Italia 3']);
    $luca->condomini()->attach($this->altro->id);
    $luca->update(['telefono' => '333 120 4455']);
});

function pdrPagina($test, User $chi, ?Condominio $c, array $filtri = [], array $intestazioni = []): array
{
    $url = $c === null ? route('impostazioni.registro-modifiche', $filtri) : route('admin.gestionale.registro-modifiche.index', ['condominio' => $c->id] + $filtri);

    return $test->actingAs($chi)->get($url, $intestazioni)->assertOk()->viewData('page');
}

it('la pagina del condominio: solo le sue righe, il mese di oggi, le due card e le scelte dei menu', function () {
    $pagina = pdrPagina($this, $this->andrea, $this->c);
    $p = $pagina['props'];

    expect($pagina['component'])->toBe('gestionale/registroModifiche/RegistroModifiche')
        ->and($p['filtri']['mese'])->toBe('2026-12')
        ->and(collect($p['righe']['data'])->pluck('oggetto.nome')->all())->toContain('Comunicazione «Lavori al portone»', 'Anagrafica di Ida Romano')
        ->and(collect($p['righe']['data'])->pluck('oggetto.nome')->all())->not->toContain('Anagrafica di Luca Esposito');
    $ida = collect($p['righe']['data'])->firstWhere('oggetto.nome', 'Anagrafica di Ida Romano');
    $programma = collect($p['righe']['data'])->firstWhere('oggetto.nome', 'Comunicazione «Lavori al portone»');
    expect($ida['cambiato'])->toBe('Indirizzo email primario e Indirizzo email PEC (2 dati)')
        ->and($ida['storia'])->toBe(['tipo' => 'anagrafica', 'id' => $this->ida->id])
        ->and($ida['autore'])->toMatchArray(['nome' => 'Marco Galli', 'ruolo' => 'collaboratore'])
        ->and($programma['autore']['tipo'])->toBe('programma')
        ->and($programma['apri'])->toBe(route('admin.comunicazioni.show', 9))
        ->and($p['riepilogo']['mese'])->toBe(['nome' => 'Dicembre 2026', 'modifiche' => count($p['righe']['data']), 'frase' => 'da 1 persona e dal programma'])
        ->and($p['riepilogo']['integrita']['stato'])->toBe('mai')
        ->and(collect($p['scelte']['chi'])->pluck('nome')->all())->toBe(['Tutti', 'Marco Galli', 'Il programma'])
        ->and(collect($p['scelte']['mesi'])->pluck('nome')->all())->toBe(['Dicembre 2026', 'Tutti i mesi'])
        ->and(collect($p['scelte']['aree'])->pluck('nome')->first())->toBe('Tutto')
        ->and(json_encode($p['righe']))->not->toContain('valore_prima', 'impronta');
});

it('i filtri senza ricaricare: la visita parziale chiede solo le righe e il riepilogo, e le righe sono quelle del filtro', function () {
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $parziale = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione,
        'X-Inertia-Partial-Component' => 'gestionale/registroModifiche/RegistroModifiche', 'X-Inertia-Partial-Data' => 'righe,riepilogo'];

    $r = $this->actingAs($this->andrea)->get(route('admin.gestionale.registro-modifiche.index', ['condominio' => $this->c->id, 'chi' => 'programma']), $parziale)
        ->assertOk()->json();

    expect(array_keys($r['props']))->not->toContain('scelte')
        ->and(collect($r['props']['righe']['data'])->pluck('autore.nome')->unique()->values()->all())->toBe(['Il programma'])
        ->and(collect(pdrPagina($this, $this->andrea, $this->c, ['area' => 'persone'])['props']['righe']['data'])->pluck('area')->unique()->all())->toBe(['persone'])
        ->and(pdrPagina($this, $this->andrea, $this->c, ['cerca' => 'gmail'])['props']['righe']['data'])->toHaveCount(1)
        ->and(pdrPagina($this, $this->andrea, $this->c, ['mese' => '2026-11'])['props']['righe']['data'])->toHaveCount(0);
});

it('le pagine delle altre tabelle: venti righe di serie, 50 o 100 a scelta (un altro numero torna a venti), il totale e «Pagina 2 di N»', function () {
    $prima = pdrPagina($this, $this->andrea, $this->c)['props']['riepilogo']['mese']['modifiche'];
    $this->actingAs($this->marco);
    for ($i = 1; $i <= 22; $i++) {
        $this->ida->update(['telefono' => '333 000 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }
    $totale = $prima + 22;
    $breve = fn (array $r) => ['righe' => count($r['data']), 'pagina' => $r['current_page'], 'pagine' => $r['last_page'], 'per_pagina' => $r['per_page'],
        'totale' => $r['total']];

    expect($breve(pdrPagina($this, $this->andrea, $this->c)['props']['righe']))
        ->toBe(['righe' => 20, 'pagina' => 1, 'pagine' => 2, 'per_pagina' => 20, 'totale' => $totale])
        ->and($breve(pdrPagina($this, $this->andrea, $this->c, ['page' => 2])['props']['righe']))
        ->toBe(['righe' => $totale - 20, 'pagina' => 2, 'pagine' => 2, 'per_pagina' => 20, 'totale' => $totale])
        ->and($breve(pdrPagina($this, $this->andrea, $this->c, ['per_page' => 50])['props']['righe']))
        ->toBe(['righe' => $totale, 'pagina' => 1, 'pagine' => 1, 'per_pagina' => 50, 'totale' => $totale])
        ->and(pdrPagina($this, $this->andrea, $this->c, ['per_page' => 100])['props']['righe']['per_page'])->toBe(100)
        ->and(pdrPagina($this, $this->andrea, $this->c, ['per_page' => 37])['props']['righe']['per_page'])->toBe(20)
        ->and(pdrPagina($this, $this->andrea, null, ['per_page' => 50])['props']['righe']['per_page'])->toBe(50);
});

it('la pagina cambia con una visita parziale delle sole righe, con i filtri e le righe per pagina', function () {
    $this->actingAs($this->marco);
    for ($i = 1; $i <= 22; $i++) {
        $this->ida->update(['telefono' => '333 000 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $parziale = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione,
        'X-Inertia-Partial-Component' => 'gestionale/registroModifiche/RegistroModifiche', 'X-Inertia-Partial-Data' => 'righe'];

    $visita = fn (array $parametri) => $this->actingAs($this->andrea)->get(route('admin.gestionale.registro-modifiche.index',
        ['condominio' => $this->c->id, 'area' => 'persone'] + $parametri), $parziale)->assertOk()->json();
    $seconda = $visita(['page' => 2]);
    $da50 = $visita(['per_page' => 50]);
    $persone = $da50['props']['righe']['total'];

    expect(array_keys($seconda['props']))->not->toContain('scelte', 'riepilogo')
        ->and($seconda['props']['righe']['current_page'])->toBe(2)
        ->and(collect($seconda['props']['righe']['data'])->pluck('area')->unique()->values()->all())->toBe(['persone'])
        ->and($seconda['props']['righe']['prev_page_url'])->toContain('area=persone')
        ->and($persone)->toBeGreaterThan(22)
        ->and($da50['props']['righe']['per_page'])->toBe(50)
        ->and($da50['props']['righe']['data'])->toHaveCount($persone)
        ->and($da50['props']['righe']['last_page'])->toBe(1);
});

it('la riga annullata da un ripristino resta nella pagina, con la scritta', function () {
    $comune = (int) RegistroModifica::query()->max('sequenza');
    $this->actingAs($this->marco);
    $this->ida->update(['telefono' => '347 880 1290']);
    RegistroModifiche::scrivi(['area' => 'impostazioni', 'oggetto_tipo' => 'registro', 'oggetto_id' => null, 'oggetto_nome' => 'Copia del 10/12/2026',
        'azione' => 'ripristino_copia', 'riepilogo' => 'Ripristinata la copia', 'lotto' => ['sequenza_comune' => $comune, 'prima_sequenza_rimessa' => $comune + 1,
            'ultima_sequenza_rimessa' => $comune + 1, 'copia_del' => '2026-12-10', 'oscuramenti_riapplicati' => 0]]);

    $telefono = collect(pdrPagina($this, $this->andrea, $this->c)['props']['righe']['data'])->first(fn ($r) => str_starts_with($r['cambiato'], 'Telefono'));
    expect($telefono['annullata_dal_ripristino_del'])->toBe('16/12/2026');
});

it('la pagina dello studio: tutti i condomini, con il condominio fra i filtri', function () {
    $p = pdrPagina($this, $this->andrea, null)['props'];
    expect(collect($p['righe']['data'])->pluck('oggetto.nome')->all())->toContain('Anagrafica di Ida Romano', 'Anagrafica di Luca Esposito')
        ->and(collect($p['scelte']['condomini'])->pluck('nome')->all())->toBe(['Tutti i condomini', 'Corso Italia 3', 'Via Roma 12'])
        ->and($p['dello_studio'])->toBeTrue();

    $solo = pdrPagina($this, $this->andrea, null, ['condominio' => $this->altro->id])['props'];
    expect(collect($solo['righe']['data'])->pluck('oggetto.nome')->all())->toContain('Anagrafica di Luca Esposito')
        ->and(collect($solo['righe']['data'])->pluck('oggetto.nome')->all())->not->toContain('Anagrafica di Ida Romano');
});

it('la stampa: un PDF dei filtri scelti, con l\'impronta della stampa', function () {
    $r = $this->actingAs($this->andrea)->get(route('admin.gestionale.registro-modifiche.stampa', ['condominio' => $this->c->id]))->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($r->headers->get('X-Impronta-Della-Stampa'))->toMatch('/^[0-9a-f]{64}$/')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');

    $studio = $this->actingAs($this->andrea)->get(route('impostazioni.registro-modifiche.stampa', ['condominio' => $this->altro->id]))->assertOk();
    expect($studio->headers->get('X-Impronta-Della-Stampa'))->not->toBe($r->headers->get('X-Impronta-Della-Stampa'));
});

it('«Verifica l\'integrità»: integro; una riga cambiata fuori dal programma non torna, e il rapporto si scarica', function () {
    $ok = $this->actingAs($this->andrea)->postJson(route('admin.registro-modifiche.verifica'))->assertOk()->json();
    expect($ok['integro'])->toBeTrue()
        ->and($ok['titolo'])->toBe('Il registro è integro.')
        ->and($ok['frase'])->toStartWith('Il registro è integro. ')
        ->and($ok['integrita']['parola'])->toBe('Integro');

    $riga = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('azione', 'modificata')->firstOrFail();
    DB::table('registro_modifiche')->where('id', $riga->id)->update(['riepilogo' => 'Cambiata a mano']);

    $ko = $this->actingAs($this->andrea)->postJson(route('admin.registro-modifiche.verifica'))->assertOk()->json();
    expect($ko['integro'])->toBeFalse()
        ->and($ko['titolo'])->toBe('Una riga non torna.')
        ->and($ko['frase'])->toContain('è stata cambiata fuori dal programma')
        ->and(pdrPagina($this, $this->andrea, $this->c)['props']['riepilogo']['integrita']['parola'])->toBe('Una riga non torna');

    $rapporto = $this->actingAs($this->andrea)->get(route('admin.registro-modifiche.verifica.rapporto'))->assertOk();
    expect($rapporto->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($rapporto->headers->get('Content-Disposition'))->toContain('attachment; filename="rapporto-verifica-registro-');
});

it('la stampa legge i condomini e le pagine da aprire per tipo, una volta: le query non crescono con le righe', function () {
    // Le righe di contabilità rimandano a una fattura (qui tolta: «Apri» non c'è, ma va cercata) e quelle della persona hanno il condominio.
    $piu = function (int $quante) {
        foreach (range(1, $quante) as $i) {
            RegistroModifiche::scrivi(Gesto::su('contabilita', 'fattura_passiva', 900 + $i, 'Fattura n. ' . $i)->azione('gesto')
                ->riepilogo('Registrata, € 120,00')->nelCondominio($this->c)->perLaRiga());
            RegistroModifiche::scrivi(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => $this->ida->id, 'oggetto_nome' => 'Anagrafica di Ida Romano',
                'azione' => 'modificata', 'campi' => [\App\Services\RegistroModifiche\Campo::nuovo('telefono', 'Telefono', '1', '2', '1', '2')],
                'condomini' => [$this->c->id => 'Via Roma 12']]);
        }
    };
    $conta = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->andrea)->get(route('admin.gestionale.registro-modifiche.stampa', ['condominio' => $this->c->id]))->assertOk();
        $quante = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $quante;
    };

    // La prima stampa prepara ciò che serve una volta sola (l'esercizio, i conti di sistema): non conta.
    $conta();
    $piu(5);
    $conCinque = $conta();
    $piu(20);
    $conVenticinque = $conta();

    expect($conVenticinque)->toBe($conCinque);
});

it('il tetto della stampa è misurato con mPDF su questo modello: 2.351 righe con 128M, 5.000 al più (il tempo di una stampa sola); oltre, la pagina lo dice', function () {
    $tetto = fn (string $limite) => \App\Http\Controllers\RegistroModifiche\RegistroController::righeStampabiliDelRegistroCon($limite);

    expect($tetto('128M'))->toBe(2351)
        ->and($tetto('256M'))->toBe(5000)
        ->and($tetto('-1'))->toBe(5000)
        ->and($tetto('64M'))->toBe(200);

    // Con un limite di memoria che regge meno righe di quelle filtrate, la stampa non parte e la pagina dice di restringere.
    $limite = new ReflectionProperty(\App\Http\Controllers\RegistroModifiche\RegistroController::class, 'limiteMemoria');
    $limite->setValue(null, '1M');
    try {
        foreach (range(1, 201) as $i) {
            RegistroModifiche::scrivi(Gesto::su('comunicazioni', 'comunicazione', $i, 'Comunicazione n. ' . $i)->azione('gesto')->riepilogo('Pubblicata')
                ->nelCondominio($this->c)->perLaRiga());
        }
        $this->actingAs($this->andrea)->from(route('admin.gestionale.registro-modifiche.index', ['condominio' => $this->c->id]))
            ->get(route('admin.gestionale.registro-modifiche.stampa', ['condominio' => $this->c->id]))
            ->assertRedirect()->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'troppe per una stampa sola'));
    } finally {
        $limite->setValue(null, null);
    }
});

it('una riga senza la firma del programma ha il segno «Aggiunta fuori dal programma» nella pagina, nella «Storia» e nella stampa; le altre no', function () {
    // Fuori dal programma, con l'algoritmo pubblico: una riga «modificata» sull'anagrafica di Ida, nel registro di Via Roma 12.
    $valori = ['campi' => [['chiave' => 'iban', 'etichetta' => 'IBAN', 'prima' => 'IT00A', 'dopo' => 'IT99Z', 'valore_prima' => 'IT00A', 'valore_dopo' => 'IT99Z',
        'segreto' => false]], 'riepilogo' => null, 'perche' => null, 'rimando' => null, 'lotto' => null];
    $finta = DB::table('registro_modifiche')->insertGetId(['avvenuta_il' => '2026-12-01 07:00:00', 'registrata_il' => '2026-12-01 07:00:00',
        'autore_tipo' => 'persona', 'autore_id' => $this->marco->id, 'autore_nome' => 'Marco Galli', 'autore_ruolo' => 'collaboratore', 'origine' => 'pagina',
        'area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => $this->ida->id, 'oggetto_nome' => 'Anagrafica di Ida Romano', 'azione' => 'modificata',
        'campi' => json_encode($valori['campi']), 'impronta_valori' => \App\Services\RegistroModifiche\ImprontaDelRegistro::deiValori($valori)]);
    DB::table('registro_modifiche_condomini')->insert(['riga_id' => $finta, 'condominio_id' => $this->c->id, 'condominio_nome' => 'Via Roma 12']);

    $righe = collect(pdrPagina($this, $this->andrea, $this->c)['props']['righe']['data']);
    $storia = collect($this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'anagrafica', 'id' => $this->ida->id]))
        ->assertOk()->json('righe'));
    $pdf = (new ReflectionMethod(\App\Services\RegistroModifiche\RegistroPerLaPagina::class, 'righe'))->invoke(null,
        RegistroModifica::query()->whereIn('id', $righe->pluck('id'))->get());

    expect($righe->firstWhere('id', $finta)['fuori_dal_programma'])->toBeTrue()
        ->and($righe->where('id', '!=', $finta)->pluck('fuori_dal_programma')->unique()->values()->all())->toBe([false])
        ->and($storia->firstWhere('id', $finta)['fuori_dal_programma'])->toBeTrue()
        ->and($storia->where('id', '!=', $finta)->pluck('fuori_dal_programma')->unique()->values()->all())->toBe([false])
        ->and(collect($pdf)->firstWhere('id', $finta)['fuori_dal_programma'])->toBeTrue();
});
