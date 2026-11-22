<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * Decisione 647 (21/11/2026), punti 2 e 3, il lato del server: nella riga della tabella dei convocati il mezzo si sceglie **per questa assemblea**
 * (`assemblee_mezzi_scelti`), con «Ricordalo nella sua scheda» anche nella scheda della persona (e nel registro delle modifiche); le lettere e le PEC
 * partono solo per le persone scelte (gli id che arrivano si rileggono: solo convocati dell'assemblea, del mezzo giusto, non ancora spediti).
 *
 * Che cosa non copre: il menu e le spunte della pagina (`TabellaDeiConvocati.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function mnrRiga(array $scheda, int $anagraficaId): array
{
    return collect($scheda['convocati']['righe'])->firstWhere('anagrafica_id', $anagraficaId);
}

function mnrMezzo($t, $s, $a, string $chi, ?string $mezzo, bool $ricorda = false)
{
    return $t->actingAs($s->admin)->patchJson(route('admin.gestionale.assemblee.convocazione.mezzo', [$s->c, $a]),
        ['anagrafica_id' => $s->persone[$chi]->id, 'mezzo' => $mezzo, 'ricorda' => $ricorda]);
}

it('il mezzo per questa assemblea prende il posto della proposta, senza toccare la scheda; le lettere partono con quello', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $carla = $s->persone['carla'];
    expect($carla->mezzo_convocazione)->toBeNull();

    mnrMezzo($this, $s, $a, 'carla', 'a_mano')->assertStatus(302);

    $riga = mnrRiga(app(StatoDellaConvocazione::class)->perLaPagina($a), $carla->id);
    expect($riga['mezzo'])->toBe('a_mano')
        ->and($riga['mezzo_proposto'])->toBeFalse()
        ->and($riga['scelta'])->toBe(['si_puo' => true, 'ha_pec' => false, 'per_assemblea' => true, 'della_scheda' => null])
        ->and($carla->refresh()->mezzo_convocazione)->toBeNull()
        ->and(DB::table('assemblee_mezzi_scelti')->where('assemblea_id', $a->id)->count())->toBe(1);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf', 'anagrafiche' => [$carla->id]])
        ->assertOk()->assertHeader('X-Avvisi', '1');
    expect(ConvocazioneDiViaRoma12::invio($a, $s, 'carla')->dati['mezzo'])->toBe('a_mano');
});

it('scegliere un\'altra volta cambia la scelta; senza mezzo la toglie e torna la proposta', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    mnrMezzo($this, $s, $a, 'marco', 'a_mano');
    mnrMezzo($this, $s, $a, 'marco', 'fax');
    expect(DB::table('assemblee_mezzi_scelti')->where('assemblea_id', $a->id)->pluck('mezzo')->all())->toBe(['fax']);

    mnrMezzo($this, $s, $a, 'marco', null);
    $riga = mnrRiga(app(StatoDellaConvocazione::class)->perLaPagina($a), $s->persone['marco']->id);
    expect(DB::table('assemblee_mezzi_scelti')->count())->toBe(0)->and($riga['mezzo'])->toBe('raccomandata')->and($riga['scelta']['per_assemblea'])->toBeFalse();
});

it('«Ricordalo nella sua scheda» scrive anche la scheda, con la riga del registro delle modifiche, e la scelta per l\'assemblea non serve più', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $marco = $s->persone['marco'];
    $prima = \App\Models\RegistroModifica::query()->max('id') ?? 0;

    mnrMezzo($this, $s, $a, 'marco', 'a_mano', true)->assertSessionHasNoErrors();

    expect($marco->refresh()->mezzo_convocazione)->toBe('a_mano')
        ->and(DB::table('assemblee_mezzi_scelti')->count())->toBe(0);
    $righe = ConvocazioneDiViaRoma12::righeDelRegistro((int) $prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_tipo)->toBe('anagrafica')->and($righe[0]->oggetto_id)->toBe($marco->id)->and($righe[0]->azione)->toBe('modificata')
        ->and($righe[0]->campi)->toHaveCount(1)->and($righe[0]->campi[0]['etichetta'])->toBe('Come si convoca')->and($righe[0]->campi[0]['dopo'])->toBe('A mano');
    $riga = mnrRiga(app(StatoDellaConvocazione::class)->perLaPagina($a), $marco->id);
    expect($riga['mezzo'])->toBe('a_mano')->and($riga['scelta']['della_scheda'])->toBe('a_mano')->and($riga['scelta']['per_assemblea'])->toBeFalse();
});

it('la PEC solo a chi ha una PEC; un mezzo che non c\'è, o una persona che non è fra i convocati, no', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    mnrMezzo($this, $s, $a, 'carla', 'pec')->assertStatus(422)->assertJsonPath('errors.mezzo.0', 'La persona non ha una PEC: aggiungila nella sua scheda, o scegli un altro mezzo.');
    mnrMezzo($this, $s, $a, 'carla', 'piccione')->assertStatus(422);
    mnrMezzo($this, $s, $a, 'paolo', 'pec')->assertSessionHasNoErrors();

    $fuori = Anagrafica::factory()->create();
    $this->actingAs($s->admin)->patchJson(route('admin.gestionale.assemblee.convocazione.mezzo', [$s->c, $a]), ['anagrafica_id' => $fuori->id, 'mezzo' => 'a_mano'])
        ->assertStatus(404);
    expect(DB::table('assemblee_mezzi_scelti')->count())->toBe(1);
});

it('con l\'avviso già spedito il mezzo non si cambia; con l\'assemblea annullata nemmeno', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();

    mnrMezzo($this, $s, $a, 'rossi', 'raccomandata')->assertStatus(422)
        ->assertJsonPath('errors.mezzo.0', 'L\'avviso di questa persona è già spedito: il mezzo non si cambia.');
    $riga = mnrRiga(app(StatoDellaConvocazione::class)->perLaPagina($a), $s->persone['rossi']->id);
    expect($riga['scelta']['si_puo'])->toBeFalse();

    $a->forceFill(['stato' => StatoAssemblea::ANNULLATA])->saveQuietly();
    mnrMezzo($this, $s, $a, 'marco', 'a_mano')->assertStatus(422)->assertJsonPath('errors.mezzo.0', 'L\'assemblea non è più da convocare: il mezzo non si cambia.');
});

it('chi non gestisce le assemblee non cambia il mezzo', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $this->actingAs(ViaRoma12::utente())->patchJson(route('admin.gestionale.assemblee.convocazione.mezzo', [$s->c, $a]),
        ['anagrafica_id' => $s->persone['marco']->id, 'mezzo' => 'a_mano'])->assertStatus(403);
    expect(DB::table('assemblee_mezzi_scelti')->count())->toBe(0);
    // E la riga, per chi non gestisce, non offre la scelta.
    $riga = mnrRiga(app(StatoDellaConvocazione::class)->perLaPagina($a), $s->persone['marco']->id);
    expect($riga['scelta']['si_puo'])->toBeFalse();
});

it('le righe dicono per quale pulsante contano: le lettere per chi si stampa, le PEC per chi si manda', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a);
    $conLettere = collect($scheda['convocati']['righe'])->filter(fn ($r) => $r['per']['lettere'])->count();
    $conPec = collect($scheda['convocati']['righe'])->filter(fn ($r) => $r['per']['pec'])->count();

    expect($conLettere)->toBe($scheda['lettere']['quante'])->and($conPec)->toBe($scheda['pec']['quante']);
});

it('le PEC partono solo per le persone scelte, e solo se sono del loro mezzo e non ancora spedite; senza id partono tutte', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    // Rossi è per PEC; Carla è per raccomandata e non è del mezzo: mandata solo Rossi.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]), ['anagrafiche' => [$s->persone['rossi']->id, $s->persone['carla']->id]])
        ->assertRedirect()->assertSessionHas('message.message', '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.');
    expect(ConvocazioneDiViaRoma12::$pecMandate)->toHaveCount(1);

    // Rossi è già spedito: non parte due volte; le altre due sì.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]), ['anagrafiche' => [$s->persone['rossi']->id]])
        ->assertRedirect();
    expect(ConvocazioneDiViaRoma12::$pecMandate)->toHaveCount(1);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    expect(ConvocazioneDiViaRoma12::$pecMandate)->toHaveCount(3);
    expect(RegistroInvio::query()->where('documentabile_id', $a->id)->where('anagrafica_id', $s->persone['rossi']->id)->count())->toBe(1);
});

it('le lettere partono solo per le persone scelte; una persona di un altro condominio dà 404', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]),
        ['formato' => 'pdf', 'anagrafiche' => [$s->persone['carla']->id, $s->persone['gallo']->id, $s->persone['rossi']->id]])->assertOk()->assertHeader('X-Avvisi', '2');
    expect(RegistroInvio::query()->where('documentabile_id', $a->id)->count())->toBe(2);

    $fuori = Anagrafica::factory()->create();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf', 'anagrafiche' => [$fuori->id]])
        ->assertStatus(404);
});
