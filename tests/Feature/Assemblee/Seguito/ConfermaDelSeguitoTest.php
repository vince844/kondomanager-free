<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Gestionale\AccordoRientro;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * La conferma di «Che cosa cambia nel gestionale» (R1, R2, R13; decisioni 500 (3) e 622 (4)):
 *
 * - R1, mai in silenzio: la sala chiusa e il verbale registrato non cambiano nessuna scheda; prima del verbale il riepilogo è chiuso e la conferma no;
 * - R2: il server applica solo le righe che calcola lui; una transazione per riga (una che non va non ferma le altre); rifatta, la conferma non rifà
 *   niente; una riga tolta resta con «Applica»; una chiave sconosciuta non fa niente;
 * - la riga sull'assemblea nel registro delle modifiche;
 * - i permessi e gli id: «Vede» non conferma, un'assemblea o una cosa di un altro condominio è 404;
 * - la riunione su carta: il riepilogo si apre con gli esiti, «Verbale da caricare», i trenta giorni non ci sono.
 *
 * Che cosa non copre: le pagine (si guardano a video).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Via Roma 12 con la fattura n. 45 in sforo e l'accordo di Gallo Enzo, e i due punti: la ratifica e l'autorizzazione. @return array{0: Beta51Scenario, 1: \App\Models\Gestionale\FatturaPassiva, 2: AccordoRientro, 3: list<array<string, mixed>>} */
function cdsViaRoma(\Tests\TestCase $t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $f = ViaRoma12::fatturaInSforo($s);
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['gallo']->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);

    return [$s, $f, $accordo, [
        ['testo' => 'Ratifica della spesa urgente di € 1.800,00 (fattura n. 45)', 'materia' => 'ratifica', 'oggetti' => [['fattura_passiva', $f->id, 'origine']]],
        ['testo' => 'Autorizzazione del piano di rientro di Gallo Enzo', 'materia' => 'accordo', 'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]],
    ]];
}

it('R1 · la sala chiusa non apre il riepilogo e la conferma è rifiutata; registrato il verbale si apre, e nessuna scheda è cambiata', function () {
    [$s, $f, $accordo, $punti] = cdsViaRoma($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, $punti, registra: false);
    $riepilogo = SeguitoDelleDelibere::riepilogo($a);
    expect($riepilogo['aperto'])->toBeFalse()
        ->and($riepilogo['perche_chiuso'])->toBe('Si apre con il verbale registrato.')
        ->and(SeguitoDelleDelibere::perIlPasso($a)['stato'])->toBe('chiuso');
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => []])->assertStatus(422)
        ->assertJsonValidationErrors('seguito');

    SeguitoDiViaRoma12::registra($this, $s, $a);
    expect(SeguitoDelleDelibere::riepilogo($a->refresh())['aperto'])->toBeTrue()
        ->and(SeguitoDelleDelibere::perIlPasso($a)['stato'])->toBe('adesso')
        ->and(CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and(CosaDaFareDellaDelibera::query()->count())->toBe(0)
        ->and($f->fresh()->stato_approvazione)->toBe('sforo_motivato')
        ->and((bool) $accordo->fresh()->autorizzato_assemblea)->toBeFalse();
});

it('R2 · una riga che non va non ferma le altre; rifatta, la conferma non rifà niente; una chiave che il server non conosce non fa niente', function () {
    [$s, $f, $accordo, $punti] = cdsViaRoma($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, $punti);
    $chiavi = SeguitoDiViaRoma12::spuntate($a);
    $fattura = SeguitoDiViaRoma12::chiave($a, SeguitoDiViaRoma12::delibera($a, 1), 'fattura_passiva');
    $diGallo = SeguitoDiViaRoma12::chiave($a, SeguitoDiViaRoma12::delibera($a, 2), 'accordo_rientro');
    // Fra l'apertura del riepilogo e «Conferma» l'accordo è autorizzato a mano: il server ricalcola, e quella riga non si applica. La chiave è la stessa
    // (una per scheda e ruolo): la risposta dice perché, non «ricarica la pagina».
    $accordo->update(['autorizzato_assemblea' => true, 'data_autorizzazione' => '2026-12-14']);

    $r = SeguitoDiViaRoma12::conferma($this, $s, $a, [...$chiavi, '999:fattura_passiva:1:ratifica'])->assertOk();
    expect($r->json('esiti.' . $fattura . '.esito'))->toBe('applicata')
        ->and($r->json('esiti.' . $diGallo . '.esito'))->toBe('non_applicata')
        ->and($r->json('esiti.' . $diGallo . '.frase'))->toContain('già autorizzato il 14/12/2026')
        ->and($r->json('esiti.999:fattura_passiva:1:ratifica.esito'))->toBe('sconosciuta')
        ->and($f->fresh()->stato_approvazione)->toBe('approvata')
        ->and($accordo->fresh()->data_autorizzazione->format('Y-m-d'))->toBe('2026-12-14');

    $collegamenti = CollegamentoAllaDelibera::query()->count();
    $cose = CosaDaFareDellaDelibera::query()->count();
    $r = SeguitoDiViaRoma12::conferma($this, $s, $a, $chiavi)->assertOk();
    expect($r->json('esiti.' . $fattura . '.esito'))->toBe('gia_applicata')
        ->and(CollegamentoAllaDelibera::query()->count())->toBe($collegamenti)
        ->and(CosaDaFareDellaDelibera::query()->count())->toBe($cose);
});

it('R2 · una riga tolta alla conferma resta, e «Applica» la applica dopo; la riga sull\'assemblea nel registro dice il conto', function () {
    [$s, $f, $accordo, $punti] = cdsViaRoma($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, $punti);
    $diGallo = SeguitoDiViaRoma12::chiave($a, SeguitoDiViaRoma12::delibera($a, 2), 'accordo_rientro');
    $prima = ViaRoma12::ultimaRiga();
    SeguitoDiViaRoma12::conferma($this, $s, $a, array_values(array_diff(SeguitoDiViaRoma12::spuntate($a), [$diGallo])))->assertOk();
    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeFalse()
        ->and(collect(SeguitoDelleDelibere::perLaPagina($a, $s->admin)['tabella'])->firstWhere('punto', 2)['righe_da_applicare'][0]['chiave'])->toBe($diGallo);
    $riga = ViaRoma12::righe($prima, 'assemblea')->last();
    expect($riga->riepilogo)->toBe('Che cosa cambia nel gestionale: 1 cambio applicato, 0 cose da fare');

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $diGallo])->assertOk()
        ->assertJsonPath('esito', 'applicata');
    expect((bool) $accordo->fresh()->autorizzato_assemblea)->toBeTrue()
        ->and($accordo->fresh()->data_autorizzazione->format('Y-m-d'))->toBe('2026-12-15');
});

it('R13 e i permessi · chi vede soltanto non conferma; l\'assemblea e la cosa da fare di un altro condominio sono 404', function () {
    [$s, $f, $accordo, $punti] = cdsViaRoma($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, $punti);
    $vede = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE]);
    $vede->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($vede)->postJson(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]), ['chiavi' => SeguitoDiViaRoma12::spuntate($a)])
        ->assertForbidden();
    expect($f->fresh()->stato_approvazione)->toBe('sforo_motivato');

    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $this->actingAs($altro->admin)->postJson(route('admin.gestionale.assemblee.seguito.conferma', [$altro->c, $a]), ['chiavi' => []])->assertNotFound();
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['altre' => [['delibera_id' => SeguitoDiViaRoma12::delibera($a, 2)->id,
        'testo' => 'Manda la lettera a Gallo Enzo']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->firstOrFail();
    $suaAssemblea = ViaRoma12::ordinaria($altro, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $this->actingAs($altro->admin)->post(route('admin.gestionale.assemblee.seguito.cose.non-serve', [$altro->c, $suaAssemblea, $cosa->id]), ['motivo' => 'x'])
        ->assertNotFound();
    expect($cosa->fresh()->stato->value)->toBe('da_fare');
    $this->actingAs($altro->admin)->post(route('admin.gestionale.assemblee.delibere.annullata', [$altro->c, $suaAssemblea, SeguitoDiViaRoma12::delibera($a, 1)->id]),
        ['giorno' => '2026-12-15', 'provvedimento' => 'x'])->assertNotFound();
});

it('la riunione su carta (decisione 622 (4)): il riepilogo si apre appena segnati gli esiti, con «Verbale da caricare» e senza i trenta giorni', function () {
    [$s, $f, $accordo, $punti] = cdsViaRoma($this);
    $a = SalaDiViaRoma12::ordinaria($s, $punti);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $esiti = $a->punti()->orderBy('numero')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => ['esito' => 'approvata']])->all();
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, $esiti, '2026-12-15');
    $pagina = SeguitoDelleDelibere::perLaPagina($a->refresh(), $s->admin);
    expect($pagina['riepilogo']['aperto'])->toBeTrue()
        ->and($pagina['riepilogo']['su_carta'])->toBeTrue()
        ->and($pagina['riepilogo']['verbale_da_caricare'])->toBeTrue()
        ->and($pagina['trenta_giorni']['per_tutti'])->toBeNull()
        ->and($pagina['trenta_giorni']['frase'])->toBe('La data non c\'è finché il verbale non è consegnato a tutti. Resta la nullità.');
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    expect($f->fresh()->stato_approvazione)->toBe('approvata')
        ->and((bool) $accordo->fresh()->autorizzato_assemblea)->toBeTrue();
});
