<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Scala;
use App\Models\Tabella;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\Assemblee\Sala\VotazioniDellaSala;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Il condominio parziale (Coda 734, decisione 641 (1); art. 1123 c. 3 c.c.): la tabella segnata «di un gruppo» dall'amministratore fa del punto votato con
 * lei un punto dei soli condòmini delle sue unità. Le unità del punto sono quelle del suo perimetro con una quota maggiore di zero nella tabella del punto;
 * teste, millesimi, costituzione del gruppo e maggioranza si contano fra loro. Il segno non lo deduce mai il programma dagli zeri della tabella.
 *
 * Il caso: Via Roma 12 della sala (26 teste, 1.000,00 millesimi di proprietà) con la tabella «Box», 8 unità a 125,00 (il box 21 di Rossi e le unità 30–36).
 * Il punto 2, «Sostituzione della serranda dei box», maggioranza ordinaria, tabella «Box».
 *
 * 1. In prima con 12 in sala (7 con il box, 6 favorevoli e 1 contrario; 5 senza box, astenuti): con il segno 7 intervenuti su 8, approvata; senza il segno
 *    come oggi, 12 intervenuti, respinta.
 * 2. In seconda 3 proprietari di box favorevoli: approvata; in prima 4: l'avviso della parte con le cifre.
 * 3. Rossi (Int. 3 e box 21) una testa con 125,00; chi ha due box una testa con 250,00.
 * 4. Il punto della scala B con una tabella di un gruppo dentro la scala: le unità della scala con la quota.
 * 5. Il messaggio di «Si vota» nei quattro casi, per il gruppo e per la scala.
 * 6. La fotografia della beta.13, senza le chiavi nuove: sala e verbale come prima.
 * 9. La rotta del segno: la riga del registro sulla tabella; 403 a chi non gestisce le assemblee; 404 per la tabella di un altro condominio.
 * 10. Il segno messo o tolto dopo l'invio dell'avviso: il controllo dell'apertura lo dice, fra le cose da guardare; com'era all'invio, niente.
 * La catena da capo a capo: il segno dalla rotta → il punto dalla finestra → l'avviso → la sala → il verbale → la delibera nel piano dei box.
 *
 * Che cosa non copre: la convocazione dei conduttori e del nudo proprietario e la riga dell'avviso (`EmissioneDelleConvocazioniTest`); la frase del
 * verbale per la sola scala e quella dei comproprietari (`PartiDellaSalaNelTestoTest`); le pagine (vitest).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le otto unità della tabella «Box»: il box 21 di Rossi e le unità 30–36. @return list<string> */
function cpzDelBox(): array
{
    return ['21', '30', '31', '32', '33', '34', '35', '36'];
}

/** Via Roma 12 della sala con la tabella «Box», con il segno o senza. */
function cpzViaRoma(TestCase $t, bool $segno = true): Beta51Scenario
{
    $s = SalaDiViaRoma12::nuovo($t);
    $s->tabella('Box', array_fill_keys(cpzDelBox(), 125));
    if ($segno) {
        $s->tabelle['Box']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    }

    return $s;
}

/** L'ordinaria con il rendiconto e la serranda dei box (punto 2, maggioranza ordinaria, tabella «Box»), convocata. */
function cpzAssemblea(Beta51Scenario $s, ?array $punto = null): Assemblea
{
    return SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [
        ['testo' => 'Approvazione del rendiconto 2025', 'materia' => 'rendiconto'],
        $punto ?? ['testo' => 'Sostituzione della serranda dei box', 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'tabella_id' => $s->tabelle['Box']->id],
    ]), $s);
}

/** La prima convocazione aperta lunedì 14/12 alle 8:05, presidente Rossi; poi l'orologio va a mezzogiorno. */
function cpzApriLaPrima(TestCase $t, Beta51Scenario $s, Assemblea $a): SedutaDellAssemblea
{
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    ConvocazioneDiViaRoma12::oggi('2026-12-14 12:00');

    return SalaDiViaRoma12::seduta($a, 'prima')->refresh();
}

/** Le persone date entrano nella seduta data. @param list<string> $chi */
function cpzEntrano(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, array $chi, string $alle): void
{
    foreach ($chi as $x) {
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala'], $alle));
    }
}

/**
 * «Si vota» sul punto dato e i voti, testa per testa (chi non è fra le teste del punto non vota): rende la risposta del «Si vota» e la votazione.
 *
 * @param array<string, string> $voti persona => favorevole, contrario, astenuto
 * @return array{0: array<string, mixed>, 1: VotazioneDelPunto}
 */
function cpzVota(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, string $alle, array $voti, int $numero = 2): array
{
    $punto = (int) $a->punti()->where('numero', $numero)->value('id');
    $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto], $alle));
    $v = VotazioneDelPunto::query()->findOrFail((int) $r['votazione_id']);
    foreach ($voti as $chi => $voto) {
        $testa = collect((array) $v->intervenuti['teste'])->first(fn ($x) => in_array((int) $s->persone[$chi]->id, array_map('intval', (array) $x['persone']), true));
        if ($testa !== null) {
            SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v->id, 'testa' => $testa['chiave'], 'voto' => $voto], $alle));
        }
    }

    return [$r, $v->refresh()];
}

/** I sette proprietari di box in sala e i cinque senza box. @return array{0: list<string>, 1: list<string>} */
function cpzLeDodici(): array
{
    return [['rossi', 'c30', 'c31', 'c32', 'c33', 'c34', 'c35'], ['marco', 'rita', 'bianchi', 'gallo', 'c40']];
}

it('1. il caso di Via Roma 12 in prima: con il segno 7 intervenuti su 8 e 1.000,00, approvata; senza il segno com\'è oggi, 12 intervenuti, respinta', function (bool $segno) {
    $s = cpzViaRoma($this, $segno);
    $a = cpzAssemblea($s);
    $seduta = cpzApriLaPrima($this, $s, $a);
    [$box, $senza] = cpzLeDodici();
    cpzEntrano($this, $s, $a, $seduta, [...$box, ...$senza], '2026-12-14 08:10');
    $voti = array_fill_keys(['rossi', 'c30', 'c31', 'c32', 'c33', 'c34'], 'favorevole') + ['c35' => 'contrario'] + array_fill_keys($senza, 'astenuto');
    [, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-14 08:30', $voti);

    if ($segno) {
        expect($v->intervenuti['numero'])->toBe(7)
            ->and($v->intervenuti['totale_teste'])->toBe(8)
            ->and($v->intervenuti['totale_valore'])->toBe(100000000)
            ->and($v->intervenuti['valore'])->toBe(87500000)
            ->and($v->esito_proposto?->value)->toBe('approvata')
            ->and($v->intervenuti['perimetro'])->toMatchArray(['nome' => 'Solo i condòmini della tabella «Box»', 'gruppo' => 'Box',
                'chi_vota' => 'Votano solo i condòmini della tabella «Box»', 'avviso' => null]);
    } else {
        // La controprova: senza il segno il programma non deduce niente dagli zeri della tabella.
        expect($v->intervenuti['numero'])->toBe(12)
            ->and($v->intervenuti['totale_teste'])->toBe(26)
            ->and($v->esito_proposto?->value)->toBe('respinta')
            ->and($v->intervenuti['perimetro'])->toBeNull();
    }
})->with(['con il segno' => true, 'senza il segno' => false]);

it('2. in seconda 3 proprietari di box, tutti favorevoli: il gruppo basta e il punto è approvato, anche con l\'assemblea di tutti non costituita', function () {
    $s = cpzViaRoma($this);
    $a = cpzAssemblea($s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    cpzEntrano($this, $s, $a, $seduta, ['rossi', 'c30', 'c31'], '2026-12-15 18:45');
    [$r, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-15 19:00', array_fill_keys(['rossi', 'c30', 'c31'], 'favorevole'));
    expect($v->intervenuti['numero'])->toBe(3)
        ->and($v->esito_proposto?->value)->toBe('approvata')
        ->and($v->intervenuti['perimetro']['costituzione']['costituita'])->toBeTrue()
        ->and($r['messaggio'])->toBe('Punto 2: il gruppo della tabella «Box» basta (3 su 8 · 375,00 su 1.000,00); l\'assemblea di tutti non risulta costituita '
            . '(3 su 26 · 120,50 su 1.000,00; servono 9 e 333,34). Decide il presidente.');
});

it('2. in prima con 4 proprietari di box il gruppo non basta: l\'avviso della parte lo dice con le cifre, e il messaggio dice le due cose', function () {
    $s = cpzViaRoma($this);
    $a = cpzAssemblea($s);
    $seduta = cpzApriLaPrima($this, $s, $a);
    cpzEntrano($this, $s, $a, $seduta, ['rossi', 'c30', 'c31', 'c32'], '2026-12-14 08:10');
    [$r, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-14 08:30', array_fill_keys(['rossi', 'c30', 'c31', 'c32'], 'favorevole'));
    expect($v->intervenuti['perimetro']['avviso'])->toBe('Solo i condòmini della tabella «Box»: 4 su 8 · 500,00 su 1.000,00; servono 5 e 666,67. Il conto '
        . 'della parte non basta: decide il presidente, e il verbale lo scrive.')
        ->and($r['messaggio'])->toBe('Punto 2: l\'assemblea non risulta costituita (4 su 26 · 148,25 su 1.000,00; servono 14 e 666,67), e nemmeno il gruppo '
            . 'della tabella «Box» (4 su 8 · 500,00 su 1.000,00; servono 5 e 666,67). Decide il presidente.');
});

it('3. Rossi, con l\'Int. 3 e il box 21, sul punto dei box è una testa con i soli 125,00 del box; chi ha due box è una testa con 250,00', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->pertinenza('22', '30')->pertinenza('23', '30')->titolare('22', 'c30')->titolare('23', 'c30');
    $s->tabella('Box', ['21' => 125, '22' => 125, '23' => 125]);
    $s->tabelle['Box']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    $a = cpzAssemblea($s);

    $p = collect(app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a, 'prima'))['punti'])->firstWhere('numero', 2);
    $testaDi = fn (string $chi) => collect($p['teste'])->first(fn ($x) => in_array((int) $s->persone[$chi]->id, array_map('intval', $x['persone']), true));
    expect($p['totale_teste'])->toBe(2)
        ->and($p['totale_valore'])->toBe(37500000)
        ->and($testaDi('rossi')['millesimi'])->toBe(12500000)
        ->and($testaDi('c30')['millesimi'])->toBe(25000000)
        ->and($testaDi('bianchi'))->toBeNull()
        ->and($p['gruppo'])->toBe(['tabella_id' => $s->tabelle['Box']->id, 'nome' => 'Box'])
        ->and($p['perimetro'])->toBe('Solo i condòmini della tabella «Box»');
});

it('4. il punto della scala B con una tabella di un gruppo dentro la scala: le unità del punto sono quelle della scala con la quota', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $scala = Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    foreach (range(30, 39) as $i) {
        $s->unita[(string) $i]->forceFill(['scala_id' => $scala->id])->saveQuietly();
    }
    // L'ascensore della scala B: sei unità della scala con la quota, e una fuori dalla scala (la 40) che il punto della scala non prende.
    $s->tabella('Ascensore B', ['30' => 150, '31' => 150, '32' => 150, '33' => 150, '34' => 200, '35' => 200, '40' => 50]);
    $s->tabelle['Ascensore B']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    $a = cpzAssemblea($s, ['testo' => 'Funi dell\'ascensore della scala B', 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'ambito' => 'scala',
        'scala_id' => $scala->id, 'tabella_id' => $s->tabelle['Ascensore B']->id]);

    $p = collect(app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a))['punti'])->firstWhere('numero', 2);
    expect($p['totale_teste'])->toBe(6)
        ->and($p['totale_valore'])->toBe(100000000)
        ->and(array_map('intval', array_keys($p['millesimi'])))->toEqualCanonicalizing(array_map(fn ($u) => (int) $s->unita[$u]->id, ['30', '31', '32', '33', '34', '35']))
        ->and($p['perimetro'])->toBe('Solo la scala B, i condòmini della tabella «Ascensore B»')
        ->and($p['parte'])->toBe('la scala B')
        ->and($p['gruppo']['nome'])->toBe('Ascensore B');
});

it('5. il messaggio di «Si vota» con l\'assemblea costituita e il gruppo che non basta: l\'avviso della parte', function () {
    // Assemblea sì, gruppo no: 18 in sala, 4 con il box.
    $s = cpzViaRoma($this);
    $a = cpzAssemblea($s);
    $seduta = cpzApriLaPrima($this, $s, $a);
    cpzEntrano($this, $s, $a, $seduta, ['rossi', 'c30', 'c31', 'c32', 'marco', 'rita', 'bianchi', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(37, 46))],
        '2026-12-14 08:10');
    [$r] = cpzVota($this, $s, $a, $seduta, '2026-12-14 08:30', []);
    expect($r['messaggio'])->toBe('Solo i condòmini della tabella «Box»: 4 su 8 · 500,00 su 1.000,00; servono 5 e 666,67. Il conto della parte non basta: decide '
        . 'il presidente, e il verbale lo scrive.');
});

it('5. il messaggio di «Si vota» con l\'assemblea costituita e il gruppo che basta: niente', function () {
    // Assemblea sì, gruppo sì: 21 in sala, 7 con il box.
    $s = cpzViaRoma($this);
    $a = cpzAssemblea($s);
    $seduta = cpzApriLaPrima($this, $s, $a);
    cpzEntrano($this, $s, $a, $seduta, [...cpzLeDodici()[0], 'marco', 'rita', 'bianchi', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(37, 46))],
        '2026-12-14 08:10');
    [$r] = cpzVota($this, $s, $a, $seduta, '2026-12-14 08:30', []);
    expect($r['messaggio'])->toBeNull();
});

/** Via Roma 12 con la scala B (unità 30–39) e la tabella «Ascensore B» senza il segno; il punto 2 della sola scala; la seconda aperta. @return array{0: Beta51Scenario, 1: Assemblea, 2: SedutaDellAssemblea} */
function cpzScalaB(TestCase $t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $scala = Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $ascensore = Tabella::create(['condominio_id' => $s->c->id, 'nome' => 'Ascensore B', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (range(30, 39) as $i) {
        $s->unita[(string) $i]->forceFill(['scala_id' => $scala->id])->saveQuietly();
        DB::table('quote_tabella')->insert(['tabella_id' => $ascensore->id, 'immobile_id' => $s->unita[(string) $i]->id, 'valore' => 100, 'created_at' => now(),
            'updated_at' => now()]);
    }
    $a = cpzAssemblea($s, ['testo' => 'Funi dell\'ascensore della scala B', 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'ambito' => 'scala',
        'scala_id' => $scala->id, 'tabella_id' => $ascensore->id]);

    return [$s, $a, SalaDiViaRoma12::apriLaSeconda($t, $s, $a)];
}

it('5. il messaggio di «Si vota» per il punto della sola scala: la scala basta e l\'assemblea no', function () {
    [$s, $a, $seduta] = cpzScalaB($this);
    cpzEntrano($this, $s, $a, $seduta, ['c30', 'c31', 'c32', 'c33'], '2026-12-15 18:45');
    [$r, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-15 19:00', []);
    expect($r['messaggio'])->toBe('Punto 2: la scala B basta (4 su 10 · 400,00 su 1.000,00); l\'assemblea di tutti non risulta costituita (4 su 26 · 111,00 su '
        . '1.000,00; servono 9 e 333,34). Decide il presidente.')
        ->and($v->intervenuti['perimetro']['chi_vota'])->toBe('Votano solo i condòmini della scala B');
});

it('5. il messaggio di «Si vota» per il punto della sola scala: né l\'assemblea né la scala', function () {
    [$s, $a, $seduta] = cpzScalaB($this);
    cpzEntrano($this, $s, $a, $seduta, ['c30', 'c31', 'c32'], '2026-12-15 18:45');
    [$r] = cpzVota($this, $s, $a, $seduta, '2026-12-15 19:00', []);
    expect($r['messaggio'])->toBe('Punto 2: l\'assemblea non risulta costituita (3 su 26 · 83,25 su 1.000,00; servono 9 e 333,34), e nemmeno la scala B (3 su 10 · '
        . '300,00 su 1.000,00; servono 4 e 333,34). Decide il presidente.');
});

it('6. la fotografia fermata nella beta.13, senza le chiavi nuove: la sala e il verbale leggono come prima', function () {
    [$s, $a, $seduta] = cpzScalaB($this);
    // La fotografia com'era scritta nella beta.13: i punti senza «gruppo» e senza «parte».
    $f = (array) $seduta->fotografia;
    $f['punti'] = array_map(fn ($p) => array_diff_key((array) $p, ['gruppo' => true, 'parte' => true]), (array) $f['punti']);
    $seduta->forceFill(['fotografia' => $f])->saveQuietly();

    cpzEntrano($this, $s, $a, $seduta, ['c30', 'c31', 'c32', 'c33'], '2026-12-15 18:45');
    [$r, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-15 19:00', array_fill_keys(['c30', 'c31', 'c32', 'c33'], 'favorevole'));
    expect($r['messaggio'])->toBe('Punto 2: l\'assemblea non risulta costituita (4 su 26 · 111,00 su 1.000,00; servono 9 e 333,34). Decide il presidente.')
        ->and($v->intervenuti['perimetro'])->toMatchArray(['nome' => 'Solo la scala B', 'chi_vota' => null, 'avviso' => null])
        ->and($v->intervenuti['numero'])->toBe(4);

    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toContain('Si vota alle ore 19:00: intervengono 4 condòmini per 400,00 millesimi')
        ->not->toContain('Sul punto votano');
});

it('9. la rotta del segno: la riga del registro sulla tabella e il messaggio; 403 a chi non gestisce le assemblee; 404 per una tabella di un altro condominio', function () {
    $s = cpzViaRoma($this, false);
    $box = $s->tabelle['Box'];
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->from('/indietro')->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => true])
        ->assertRedirect('/indietro')->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', '«Box» è la tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue 8 unità.');
    expect($box->refresh()->di_un_gruppo)->toBeTrue();
    $riga = ViaRoma12::righe($prima, 'tabella')->last();
    expect($riga)->not->toBeNull()
        ->and($riga->oggetto_id)->toBe($box->id)
        ->and($riga->campi[0])->toMatchArray(['etichetta' => 'Tabella di un gruppo (assemblee)', 'prima' => 'no', 'dopo' => 'sì']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => false])
        ->assertSessionHas('message.message', 'Tolto il segno: sui punti con «Box» vota tutto il perimetro del punto.');
    expect($box->refresh()->di_un_gruppo)->toBeFalse();

    // Con l'accesso al pannello, così il 403 è delle assemblee: vede le assemblee e non le gestisce.
    $chiNonGestisce = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE]);
    $chiNonGestisce->assignRole('admin');
    $this->actingAs($chiNonGestisce)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => true])
        ->assertForbidden();
    expect($box->refresh()->di_un_gruppo)->toBeFalse();

    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Box', ['1' => 1000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $altro->tabelle['Box']->id, 'di_un_gruppo' => true])
        ->assertNotFound();
    expect($altro->tabelle['Box']->refresh()->di_un_gruppo)->toBeFalse();
});

/** Le cose da guardare del controllo dell'apertura della prima convocazione (lunedì 14/12 alle 8:05), dalla rotta dello stato della sala. @return list<string> */
function cpzDaGuardare(TestCase $t, Beta51Scenario $s, Assemblea $a): array
{
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    $controllo = $t->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $a]))->assertOk()->json('controllo');

    return collect($controllo['da_guardare'] ?? [])->pluck('frase')->all();
}

it('10. il segno messo dopo l\'invio dell\'avviso: il controllo dell\'apertura lo dice; tolto di nuovo, com\'era all\'invio, non dice niente', function () {
    $s = cpzViaRoma($this, false);
    $box = $s->tabelle['Box'];
    // L'ordinaria convocata il 26/11 con il punto 2 sulla tabella «Box» senza il segno: l'avviso dice che sul punto votano tutti.
    $a = cpzAssemblea($s);

    // Il 30/11 il segno dalla pagina della tabella: la rotta lo lascia mettere, e all'apertura il presidente lo legge.
    ConvocazioneDiViaRoma12::oggi('2026-11-30 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => true])
        ->assertSessionHasNoErrors();
    expect(cpzDaGuardare($this, $s, $a))->toContain('La tabella «Box» è segnata «di un gruppo» dal 30/11/2026, dopo l\'invio dell\'avviso: sul punto 2 votano '
        . 'solo i condòmini delle sue unità, e l\'avviso non lo dice.');

    // Il 01/12 tolto di nuovo: è com'era all'invio.
    ConvocazioneDiViaRoma12::oggi('2026-12-01 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => false])
        ->assertSessionHasNoErrors();
    expect(collect(cpzDaGuardare($this, $s, $a))->filter(fn ($f) => str_contains($f, '«di un gruppo»'))->all())->toBe([]);
});

it('10. il segno tolto dopo l\'invio dell\'avviso: il controllo dell\'apertura lo dice; messo prima dell\'invio non dice niente', function () {
    $s = cpzViaRoma($this, false);
    $box = $s->tabelle['Box'];
    // Il segno messo dalla rotta il 25/11, prima dell'invio: l'avviso lo dice («votano solo loro»), e all'apertura non c'è niente da guardare.
    ConvocazioneDiViaRoma12::oggi('2026-11-25 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => true])
        ->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi();
    $a = cpzAssemblea($s);
    expect(collect(cpzDaGuardare($this, $s, $a))->filter(fn ($f) => str_contains($f, '«di un gruppo»'))->all())->toBe([]);

    // Il 30/11 il segno tolto: la rotta lo lascia togliere (l'assemblea è di tutto il condominio), e all'apertura il presidente lo legge.
    ConvocazioneDiViaRoma12::oggi('2026-11-30 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => false])
        ->assertSessionHasNoErrors();
    expect(cpzDaGuardare($this, $s, $a))->toContain('La tabella «Box» non è più segnata «di un gruppo» dal 30/11/2026, dopo l\'invio dell\'avviso: sul punto 2 '
        . 'l\'avviso dice che votano solo i condòmini delle sue unità, e in sala vota tutto il perimetro del punto.');
});

it('la catena: il segno dalla rotta → il punto dalla finestra → l\'avviso → la sala → il verbale con la frase del gruppo → la delibera che si sceglie', function () {
    $s = cpzViaRoma($this, false);
    $box = $s->tabelle['Box'];
    $a = SalaDiViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2025', 'materia' => 'rendiconto']]);

    // Il primo capo: la tabella senza il segno ha 8 unità su 27; il segno dalla rotta della pagina della tabella (e della finestra del punto).
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => true])
        ->assertSessionHasNoErrors();
    expect(collect(\App\Http\Controllers\Assemblee\PerLePagine::scelte($s->c)['tabelle'])->firstWhere('nome', 'Box'))
        ->toMatchArray(['di_un_gruppo' => true, 'unita' => 8]);

    // Il punto dalla rotta della finestra del punto, con la tabella «Box».
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['testo' => 'Sostituzione della serranda dei box',
        'materia' => 'lavori', 'maggioranza' => 'ordinaria', 'ambito' => 'condominio', 'tabella_id' => $box->id])->assertSessionHasNoErrors();
    $punto = $a->punti()->where('numero', 2)->firstOrFail();
    expect(collect(\App\Http\Controllers\Assemblee\PerLePagine::ordine($a->refresh()))->firstWhere('numero', 2)['gruppo_parola'])
        ->toBe('Fra i condòmini della tabella «Box»');

    // L'avviso: va a tutti, con la riga sotto il punto; Neri Paolo (Int. 9, fuori dal gruppo) non è convocato per il punto straordinario dei box.
    $convocati = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a->refresh(), '2026-11-26'))->keyBy('nome');
    expect($convocati->has('Bianchi Anna'))->toBeTrue()->and($convocati->has('Neri Paolo'))->toBeFalse();
    $avviso = EmissioneDelleConvocazioni::avviso($a, $convocati['Rossi Mario'], 'pec');
    expect(collect($avviso['punti'])->firstWhere('numero', 2)['note'])->toContain('Riguarda i soli condòmini delle unità della tabella «Box»: votano solo loro.');

    // La sala: in seconda i dodici, 6 favorevoli e 1 contrario fra i proprietari dei box, gli altri astenuti; la conferma.
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    [$conBox, $senza] = cpzLeDodici();
    cpzEntrano($this, $s, $a, $seduta, [...$conBox, ...$senza], '2026-12-15 18:45');
    $voti = array_fill_keys(['rossi', 'c30', 'c31', 'c32', 'c33', 'c34'], 'favorevole') + ['c35' => 'contrario'] + array_fill_keys($senza, 'astenuto');
    [, $v] = cpzVota($this, $s, $a, $seduta, '2026-12-15 19:10', $voti);
    expect($v->esito_proposto?->value)->toBe('approvata')->and($v->intervenuti['numero'])->toBe(7);
    $lettura = collect(app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['punti'])->firstWhere('numero', 2);
    expect($lettura['perimetro']['chi_vota'])->toBe('Votano solo i condòmini della tabella «Box»');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v->id, 'esito' => 'approvata'], '2026-12-15 19:12',
        (int) $v->versione));

    // L'altro capo: il verbale con la frase del gruppo e i conti del gruppo; la delibera si sceglie per il piano dei box.
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toContain('Sul punto votano i soli condòmini delle unità della tabella «Box» (art. 1123 c. 3 c.c.): 8 partecipanti per 1.000,00 millesimi.')
        ->toContain('Si vota alle ore 19:10: intervengono 7 condòmini per 875,00 millesimi');
    $delibera = $punto->refresh()->delibere()->firstOrFail();
    expect($delibera->esito?->value)->toBe('approvata')
        ->and(DelibereDelCondominio::siSceglie($delibera, 'delibera'))->toBe([true, null]);
});
