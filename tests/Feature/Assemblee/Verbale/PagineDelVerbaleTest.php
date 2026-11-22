<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\RigaDiNomina;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Comunicazione;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Ciò che le pagine del verbale leggono dal server (la pagina «Il verbale», il passo 5 della pagina dell'assemblea, la sala, la finestra «Sospendi»):
 *
 * - la pagina ha i punti della riunione anche prima che qualcuno scriva (il verbale nasce con la prima discussione);
 * - il passo 5 si apre con `?scheda=verbale` e legge le riunioni con il loro verbale quando si apre;
 * - al voto di un punto di nomina la sala chiede il nome del nuovo amministratore, e la riga proposta del registro lo porta (decisione 615 (2));
 * - alla sospensione, con il promemoria acceso, l'avviso in bacheca si pubblica subito o resta in bozza (decisione 615 (5));
 * - dopo la trasmissione «Sostituisci la copia» dice chi ha la copia di prima, e «rimandala» la manda di nuovo solo a loro (decisione 615 (6));
 * - i comproprietari sono una testa, con il nome di chi c'era come rappresentante (art. 67 c. 2 disp. att. c.c., decisione 615 (8)).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    Notification::fake();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La seconda convocazione aperta il 15/12 con il punto 1 votato, e la prosecuzione fissata il 17/12 alle 18:30. */
function pdvSospendibile($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
    VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, 1, '2026-12-15 19:00');

    return [$s, $a, $seduta, SalaDiViaRoma12::seduta($a, 'seconda', 2)];
}

it('la pagina «Il verbale» ha i punti della riunione anche prima che qualcuno scriva', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Verbale')
            ->where('verbale.verbale', null)
            ->where('verbale.seduta_id', $seduta->id)
            ->has('verbale.punti', 6)
            ->where('verbale.punti.3.numero', 4)
            ->where('verbale.punti.3.votazioni.0.riga', fn ($riga) => str_contains((string) $riga, ' favorevoli per ')));
});

it('il passo 5 si apre con `?scheda=verbale` e legge le riunioni con il loro verbale quando si apre', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'verbale']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('scheda', 'verbale')->missing('verbale')
            ->reloadOnly('verbale', fn (AssertableInertia $r) => $r->where('verbale.riunioni.0.verbale.id', $verbale->id)
                ->where('verbale.riunioni.0.verbale.stato', 'bozza')
                ->where('verbale.rotte.pagina', route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))));
});

it('al voto di un punto di nomina la sala chiede il nome, e la riga proposta del registro lo porta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Nomina dell\'amministratore', 'materia' => 'nomina']]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'bianchi', ...SalaDiViaRoma12::altri()]);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 1)],
        '2026-12-15 19:00'));
    $id = (int) $r['votazione_id'];
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], '2026-12-15 19:00'));
    VerbaleDiViaRoma12::conferma($this, $s, $a, $id, '2026-12-15 19:01', null, ['amministratore' => '  Studio Marchetti  ']);
    expect(VotazioneDelPunto::query()->findOrFail($id)->scelte['nomina']['amministratore'] ?? null)->toBe('Studio Marchetti');

    // La sala lo rilegge per la finestra della conferma.
    $sala = app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect(collect($sala['punti'])->firstWhere('numero', 1)['votazioni'][0]['scelte']['nomina']['amministratore'])->toBe('Studio Marchetti');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a->refresh());
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    expect(RigaDiNomina::query()->where('stato', 'proposta')->firstOrFail()->amministratore)->toBe('Studio Marchetti');
});

it('alla sospensione con il promemoria acceso, l\'avviso in bacheca si pubblica subito o resta in bozza', function (string $bacheca, bool $pubblicata) {
    [$s, $a, $seduta, $prosegue] = pdvSospendibile($this);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id,
        'promemoria' => true, 'bacheca' => $bacheca], '2026-12-15 20:30'));
    $c = Comunicazione::query()->latest('id')->firstOrFail();
    expect((bool) $c->is_published)->toBe($pubblicata)->and($c->subject)->toBe('Assemblea ordinaria: prosegue giovedì 17/12 alle 18:30');
})->with([['pubblica', true], ['bozza', false]]);

it('dopo la trasmissione «Sostituisci la copia» dice chi ha la copia di prima, e la PEC si rimanda solo a loro', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $persona = collect(app(\App\Services\Assemblee\Verbale\DestinatariDelVerbale::class)->per($verbale->refresh())['persone'])
        ->first(fn ($p) => $p['riceve'] && $p['mezzo']['mezzo'] === 'raccomandata');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$persona['anagrafica_id']]])->assertOk();
    $invio = RegistroInvio::query()->where('tipo', 'verbale')->firstOrFail();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$invio->id], 'canale' => 'raccomandata',
        'data' => now()->format('Y-m-d')])->assertSessionHasNoErrors();

    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(5));
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.copia', [$s->c, $a, $verbale]), ['copia' => VerbaleDiViaRoma12::copia('completo.pdf'),
        'perche' => 'Mancava la pagina 4.'])->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('verbale.verbale.copia.sostituita_il', fn ($il) => is_string($il))
            ->where('verbale.verbale.trasmissione.persone', fn ($persone) => collect($persone)->filter(fn ($x) => ($x['invio']['creato_il'] ?? null) !== null)->count() === 1));

    // «Rimandala»: le lettere di nuovo solo a chi ha la copia di prima; senza `di_nuovo` non riparte niente.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$persona['anagrafica_id']]])
        ->assertSessionHasErrors('trasmissione');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$persona['anagrafica_id']],
        'di_nuovo' => true])->assertOk();
    expect(RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $persona['anagrafica_id'])->count())->toBe(2);
});

it('i comproprietari sono una testa, con il nome di chi c\'era come rappresentante', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $parte = \App\Models\Assemblee\ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();
    $voto = StrutturaDelVerbale::blocchi((array) $parte->documento)['voto:' . VotazioneDelPunto::query()->where('punto_id', VerbaleDiViaRoma12::punto($a, 2))->value('id')];
    expect($voto)->toContain('Russo Marco, rappresentante anche di Russo Anna')
        ->not->toContain('Russo Anna e Russo Marco, per i quali vota');
});

it('la pagina del verbale dà il ritorno da dove si è venuti: la sala, il registro, altrimenti l\'assemblea; una parola sconosciuta non porta fuori (decisione 659)', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $ritorno = fn (array $query) => $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a] + $query))->assertOk()
        ->viewData('page')['props']['ritorno'];
    $assemblea = ['url' => route('admin.gestionale.assemblee.show', [$s->c, $a]), 'testo' => 'L\'assemblea'];

    expect($ritorno(['da' => 'sala']))->toBe(['url' => route('admin.gestionale.assemblee.sala', [$s->c, $a]), 'testo' => 'La sala'])
        ->and($ritorno(['da' => 'registro']))->toBe(['url' => route('admin.gestionale.assemblee.registro', [$s->c]), 'testo' => 'Il registro'])
        ->and($ritorno([]))->toBe($assemblea)
        ->and($ritorno(['da' => 'https://esempio.test/fuori']))->toBe($assemblea)
        ->and($ritorno(['da' => 'altrove']))->toBe($assemblea);
});
