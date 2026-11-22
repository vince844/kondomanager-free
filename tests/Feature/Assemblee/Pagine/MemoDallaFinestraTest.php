<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Http\Controllers\Dashboard\ActionInboxController;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Evento;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §3 P8 e P10 del progetto della tappa 2; decisioni 499 e 501): il memo dalla finestra veloce e il suo compito dall'Action inbox.
 * La proposta della finestra (il punto dal contesto, l'origine con il nome e «Apri», «il programma propone già …»); **«Ricordamelo dal» non accetta un
 * giorno già passato**; senza «Ricordamelo» il giorno non si salva e il compito non nasce; il compito porta «Apri la fattura n. 45»; **«Fatto» sul compito
 * del memo la rotta lo rifiuta**, dalla risposta e dal «Segna come fatto»; «Non serve più» chiude anche il memo.
 *
 * Che cosa non copre: la finestra e il menu a video (hanno i loro test vitest); «Ricordamelo» dal menu del compito (lo provano i test del motore).
 */

beforeEach(function () {
    $this->withoutVite();
    Permission::firstOrCreate(['name' => Permesso::EDIT_EVENTS->value, 'guard_name' => 'web']);
});

it('la proposta della finestra: il punto della fattura in sforo, l\'origine con «Apri», «il programma propone già …»', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);

    $r = $this->actingAs($s->admin)->getJson(route('admin.gestionale.memo.proposta', [$s->c, 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id]))
        ->assertOk();
    expect($r->json('testo'))->toBe('Ratifica della spesa oltre il preventivo di € 1.800,00 per riparazione della colonna dell\'acqua (fattura n. 45 di Idraulica Bianchi)')
        ->and($r->json('origine.nome'))->toBe('Fattura n. 45 · Idraulica Bianchi · € 1.800,00')
        ->and($r->json('origine.apri'))->toBe(route('admin.gestionale.fatture.show', [$s->c, $fattura], false))
        ->and($r->json('gia_proposto'))->toStartWith('Il programma propone già questo punto')
        ->and($r->json('condominio'))->toBe('Via Roma 12');
});

it('«Ricordamelo dal» non accetta un giorno già passato; senza «Ricordamelo» il giorno non si salva e il compito non nasce', function () {
    $s = ViaRoma12::nuovo($this);
    $ieri = now()->setTimezone('Europe/Rome')->subDay()->format('Y-m-d');

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'Il portone', 'ricordami' => true, 'ricordami_dal' => $ieri])
        ->assertStatus(422)->assertJsonValidationErrors(['ricordami_dal' => 'Il giorno di «Ricordamelo» è già passato: scegli oggi o un giorno dopo.']);
    expect(MemoDellAssemblea::query()->count())->toBe(0);

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'Il portone', 'ricordami' => false,
        'ricordami_dal' => now()->addDays(3)->format('Y-m-d')])->assertCreated();
    $memo = MemoDellAssemblea::query()->findOrFail($r->json('memo.id'));
    expect($memo->ricordami_dal)->toBeNull()->and($memo->evento_id)->toBeNull()
        ->and($r->json('frase'))->toBe('Adesso: lo trovi proposto quando prepari la prossima assemblea di Via Roma 12.');
});

it('il compito del memo: «Apri la fattura n. 45»; «Fatto» rifiutato dalla risposta e da «Segna come fatto»; «Non serve più» chiude anche il memo', function () {
    $s = ViaRoma12::nuovo($this);
    $s->admin->givePermissionTo(Permesso::EDIT_EVENTS->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $fattura = ViaRoma12::fatturaInSforo($s);
    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'Ratifica della spesa urgente di € 1.800,00',
        'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id, 'ricordami' => true, 'ricordami_dal' => now()->setTimezone('Europe/Rome')->format('Y-m-d'),
        'a_chi' => 'me'])->assertCreated();
    $memo = MemoDellAssemblea::query()->findOrFail($r->json('memo.id'));
    $compito = Evento::query()->findOrFail($memo->evento_id);
    expect($compito->meta['apri'])->toBe('Apri la fattura n. 45')
        ->and($compito->meta['action_url'])->toBe(route('admin.gestionale.fatture.show', [$s->c, $fattura], false));

    $this->actingAs($s->admin)->from('/admin/inbox')->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])
        ->assertSessionHasErrors(['tipo' => ActionInboxController::FRASE_MEMO_SENZA_FATTO]);
    $this->actingAs($s->admin)->from('/admin/inbox')->patch(route('admin.inbox.complete', $compito))
        ->assertSessionHasErrors(['tipo' => ActionInboxController::FRASE_MEMO_SENZA_FATTO]);
    expect($compito->fresh()->is_completed)->toBeFalsy()->and($memo->fresh()->stato->value)->toBe('in_attesa');

    $this->actingAs($s->admin)->from('/admin/inbox')->post(route('admin.inbox.risposta', $compito), ['tipo' => 'non_serve', 'motivo' => 'Ne abbiamo parlato a voce'])
        ->assertSessionHasNoErrors();
    expect($compito->fresh()->is_completed)->toBeTruthy()->and($memo->fresh()->stato->value)->toBe('non_serve');
});

/** Una lettura della nota sintetica che conta le chiamate: la sola parte cara dell'ordine del giorno proposto. */
function mdfLettureContate(): object
{
    $finta = new class {
        public int $chiamate = 0;

        public function letture(...$argomenti): array
        {
            $this->chiamate++;

            return [];
        }
    };
    app()->instance(\App\Services\Gestionale\NotaSinteticaDelRendiconto::class, $finta);

    return $finta;
}

it('la finestra del memo non rilegge la nota sintetica: le stesse query con la nota e senza', function () {
    $s = ViaRoma12::conFacciata($this)->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizie', 300000, ['Proprietà' => 100])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', 12, '2026-01-05', 'prima_rata')->genera('Preventivo 2026');
    $fattura = ViaRoma12::fatturaInSforo($s);
    $misura = function () use ($s, $fattura) {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($s->admin)->getJson(route('admin.gestionale.memo.proposta', ['condominio' => $s->c->id, 'origine_tipo' => 'fattura_passiva',
            'origine_id' => $fattura->id]))->assertOk();
        $q = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $q;
    };
    $misura();
    $senza = $misura();
    \Illuminate\Support\Facades\DB::table('note_sintetiche')->insert(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id,
        'contenuto' => json_encode(['da_decidere' => ['Riparto del portone']]), 'created_at' => now(), 'updated_at' => now()]);
    $con = $misura();
    $letture = mdfLettureContate();
    $misura();

    expect($con)->toBe($senza)->and($letture->chiamate)->toBe(0);
});

it('la card «Per la prossima assemblea» non legge i fatti della nota sintetica; «Prepara l\'assemblea» sì', function () {
    $s = ViaRoma12::nuovo($this);
    \Illuminate\Support\Facades\DB::table('note_sintetiche')->insert(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id,
        'contenuto' => json_encode(['da_decidere' => ['Riparto del portone']]), 'created_at' => now(), 'updated_at' => now()]);
    $letture = mdfLettureContate();

    $card = \App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c);
    expect($letture->chiamate)->toBe(0)->and(array_column($card['punti'], 'testo'))->toContain('Riparto del portone');

    \App\Services\Assemblee\ProposteDellOrdineDelGiorno::per($s->c, null);
    expect($letture->chiamate)->toBe(1);
});

it('«A un collaboratore» offre solo chi è dello studio, attivo e vede le assemblee, con poche query; il server rifiuta gli altri', function () {
    $s = ViaRoma12::nuovo($this);
    $sospeso = \App\Models\User::factory()->create(['name' => 'Marco Sospeso', 'suspended_at' => now()]);
    $senzaRuolo = \App\Models\User::factory()->create(['name' => 'Senza Ruolo']);
    $lucia = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE]);
    $lucia->update(['name' => 'Lucia Collaboratrice']);
    $utenteRuolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'utente', 'guard_name' => 'web']);
    foreach (range(1, 40) as $i) {
        \App\Models\User::factory()->create()->assignRole($utenteRuolo);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    \App\Http\Controllers\Assemblee\PerLePagine::collaboratori();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $lista = \App\Http\Controllers\Assemblee\PerLePagine::collaboratori();
    $query = count(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();
    $nomi = array_column($lista, 'nome');

    expect($nomi)->not->toContain('Marco Sospeso')->not->toContain('Senza Ruolo')->toContain('Lucia Collaboratrice')
        ->and($query)->toBeLessThan(6);
    foreach ([$sospeso, $senzaRuolo] as $chi) {
        expect(fn () => \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'ricordami_dal' => '2026-12-01',
            'a_chi' => 'collaboratore', 'assegnato_a' => $chi->id], $s->admin))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
});

it('la card della dashboard: ogni punto dice dove si apre la sua scheda e, se è un memo, porta il memo per «Modifica»', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $libero = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone', 'nota' => 'Il preventivo di Rossi', 'ricordami_dal' => now()->addDays(5)->format('Y-m-d')], $s->admin);
    $compito = Evento::create(['title' => 'Deficit', 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
        'meta' => ['type' => 'pianifica_ripianamento_deficit', 'requires_action' => true, 'importo_buco' => 50000, 'titolo_azione' => 'Prepara il piano di ripianamento',
            'action_url' => '/admin/gestionale/' . $s->c->id . '/esercizi/' . $s->e->id . '/piani-rate/create?tipo=straordinario']]);
    $compito->condomini()->attach($s->c->id);

    $punti = collect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['punti'])->keyBy('chiave_fonte');

    expect($punti['fattura:' . $fattura->id]['apri'])->toBe(['voce' => 'Apri la fattura n. 45', 'url' => route('admin.gestionale.fatture.show', [$s->c, $fattura], false)])
        ->and($punti['fattura:' . $fattura->id]['memo'])->toBeNull()
        ->and($punti['memo:' . $libero->id]['apri'])->toBeNull()
        ->and($punti['memo:' . $libero->id]['memo'])->toMatchArray(['id' => $libero->id, 'testo' => 'Il portone', 'nota' => 'Il preventivo di Rossi', 'a_chi' => 'me',
            'ricordami_dal' => now()->addDays(5)->format('Y-m-d')])
        ->and($punti['compito:' . $compito->id]['apri'])->toBe(['voce' => 'Prepara il piano di ripianamento',
            'url' => '/admin/gestionale/' . $s->c->id . '/esercizi/' . $s->e->id . '/piani-rate/create?tipo=straordinario']);

    // Il memo sulla fattura prende il posto dello sforo: «Apri la fattura n. 45» resta, e c'è il memo.
    $sulla = \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    $punto = collect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['punti'])->firstWhere('chiave_fonte', 'fattura:' . $fattura->id);
    expect($punto['apri']['voce'])->toBe('Apri la fattura n. 45')->and($punto['memo']['id'])->toBe($sulla->id);
});

/** Un memo con la sua origine, come lo legge il menu ⋯ della card (non serve salvarlo). */
function mdfMemoDa($s, string $tipo, int $id): MemoDellAssemblea
{
    return new MemoDellAssemblea(['condominio_id' => $s->c->id, 'origine_tipo' => $tipo, 'origine_id' => $id, 'testo' => 'Un punto']);
}

it('«Apri …» per un memo nato da una gestione, un fondo, un accordo, una dispensa, una regola o una segnalazione: l\'indirizzo vero della sua pagina', function (string $tipo) {
    $s = ViaRoma12::conFacciata($this);
    $c = $s->c;
    [$id, $atteso] = match ($tipo) {
        'gestione' => [$g = $s->gestioni['Facciata 2026']['g']->id,
            ['voce' => 'Apri la gestione «Facciata 2026»', 'url' => route('admin.gestionale.esercizi.gestioni.edit', [$c, $s->e, $g], false)]],
        'cassa' => [$f = \App\Models\Gestionale\Cassa::create(['condominio_id' => $c->id, 'nome' => 'Fondo facciata', 'tipo' => 'fondo', 'saldo_iniziale' => 0, 'attiva' => true,
            'conto_contabile_id' => \Illuminate\Support\Facades\DB::table('conti_contabili')->insertGetId(['condominio_id' => $c->id, 'ruolo' => 'fondo', 'codice' => '2010.F',
                'nome' => 'Fondo facciata', 'tipo' => 'passivo', 'categoria' => 'fondi', 'created_at' => now(), 'updated_at' => now()])])->id,
            ['voce' => 'Apri il fondo «Fondo facciata»', 'url' => route('admin.gestionale.casse.edit', [$c, $f], false)]],
        'accordo_rientro' => [\Illuminate\Support\Facades\DB::table('accordi_rientro')->insertGetId(['condominio_id' => $c->id, 'anagrafica_id' => $s->persona('ugo')->id,
            'data_accordo' => '2026-10-01', 'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false, 'created_at' => now(), 'updated_at' => now()]),
            ['voce' => 'Apri le rate scadute di ' . $s->persona('ugo')->nome, 'url' => route('admin.gestionale.rate-scadute.index', ['condominio' => $c->id,
                'anagrafica_id' => $s->persona('ugo')->id], false)]],
        'dispensa_sei_mesi' => [\App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $c->id, 'data_delibera' => '2025-05-14', 'tutte_le_persone' => true])->id,
            ['voce' => 'Apri le dispense dei sei mesi', 'url' => route('admin.gestionale.dispense.index', [$c], false)]],
        'regola_recupero' => [\Illuminate\Support\Facades\DB::table('regole_recupero')->insertGetId(['condominio_id' => $c->id, 'dal' => '2026-01-01', 'tasso_tipo' => 'legale',
            'created_at' => now(), 'updated_at' => now()]),
            ['voce' => 'Apri il recupero dei crediti', 'url' => route('admin.gestionale.recupero-crediti.index', [$c], false)]],
        'segnalazione' => [$sg = \App\Models\Segnalazione::factory()->create(['condominio_id' => $c->id])->id,
            ['voce' => 'Apri la segnalazione', 'url' => route('admin.segnalazioni.show', [$sg], false)]],
    };

    $memo = mdfMemoDa($s, $tipo, $id);
    expect(\App\Services\Assemblee\MemoriaDelCondominio::apriDelPunto($c, \App\Services\Assemblee\MemoriaDelCondominio::chiaveDelMemo($memo), $memo))->toBe($atteso);
})->with(['gestione', 'cassa', 'accordo_rientro', 'dispensa_sei_mesi', 'regola_recupero', 'segnalazione']);

it('la card sa se la nota sintetica può aggiungere punti, senza ricalcolarla: c\'è una nota di un esercizio con il rendiconto da approvare', function () {
    $s = ViaRoma12::nuovo($this);
    \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    expect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['con_la_nota'])->toBeFalse();

    \Illuminate\Support\Facades\DB::table('note_sintetiche')->insert(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'contenuto' => json_encode([]),
        'created_at' => now(), 'updated_at' => now()]);
    $letture = mdfLettureContate();
    expect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['con_la_nota'])->toBeTrue()->and($letture->chiamate)->toBe(0);

    \Illuminate\Support\Facades\DB::table('rendiconto_versioni')->insert(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'numero' => 1, 'stato' => 'approvata',
        'forma' => 1, 'cifre' => '{}', 'calcolato_il' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['con_la_nota'])->toBeFalse();
});
