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
use App\Services\Assemblee\Convocazione\AvvisoInBacheca;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Sala\ControlloDellApertura;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

require_once __DIR__ . '/Sala/SalaDiViaRoma12.php';
require_once __DIR__ . '/Verbale/VerbaleDiViaRoma12.php';

/**
 * L'assemblea dei soli condòmini di un gruppo (Coda 734, §1.1-bis del progetto della tappa 3; decisioni 697 (2) e 699; art. 1123 c. 3 c.c.): «Per chi»
 * prende `gruppo:<id della tabella>`, e l'assemblea è dei soli condòmini delle unità con una quota nella tabella segnata «di un gruppo». Il gruppo è la tabella
 * del valore (`assemblee.tabella_id`): l'avviso va a loro, la sala conta teste e millesimi fra loro, il verbale va in un registro suo, dal n. 1.
 *
 * Il caso: Via Roma 12 della sala con la tabella «Box», segnata, 8 unità a 125,00: il box 21 di Rossi (che ha anche l'Int. 3), il box 22 in usufrutto (Neri
 * Carla usufruttuaria, Neri Paolo nudo proprietario), il box 23 di Colombo Rita affittato a Ferri Luca, i box 24–28 dei condòmini 30–34. L'assemblea
 * straordinaria dei box: prima convocazione lunedì 14/12/2026 alle 8:00, seconda martedì 15/12/2026 alle 18:30; punto 1 «Sostituzione della serranda dei box»
 * (lavori, metà del valore), punto 2 «Pulizia dei box: turni e costo» (il conduttore interviene senza voto), punto 3 «Varie ed eventuali».
 *
 * 1. La preparazione dalla rotta: `ambito` gruppo, `tabella_id` il gruppo, la riga del registro con «Per chi» e la tabella; 422 con una tabella senza il
 *    segno, 404 con una tabella di un altro condominio.
 * 2. I convocati: 10 avvisi e 8 condòmini; Bianchi Anna (Int. 14, senza box) non c'è; Neri Paolo c'è con il punto straordinario e non c'è senza.
 * 3. L'avviso e la bacheca: il sottotitolo, l'apertura, la riga della riunione, la delega, la chiusura; nessuna nota sotto i punti.
 * 4. La fotografia e la sala: la costituzione su 8 teste e 1.000,00; in prima 7 su 8 costituita e il punto 1 approvato, 4 su 8 no; in seconda 3 su 8 sì.
 * 5. Il punto che non si conta sul gruppo (la scala, «Proprietà», una tabella con unità fuori) si ferma sempre; il punto di tutto il condominio (il
 *    rendiconto) si ferma con la frase del rischio e passa con «Mettilo lo stesso», con la riga del registro; il rendiconto di una gestione del gruppo passa;
 *    le proposte senza le materie di tutti.
 * 6. Il verbale: l'intestazione, la frase dopo la costituzione, il registro suo dal n. 1, il nome breve.
 * 7. Il segno della tabella non si toglie con l'assemblea aperta, e si toglie dopo la chiusura.
 * 8. Il controllo dell'apertura: un box messo nella tabella dopo l'invio, «non risulta fra i convocati».
 * 9. Il memo, il punto e la delibera a mano non accettano il gruppo.
 * 10. Il punto rinviato torna come memo del gruppo, e lo propone solo la prossima assemblea del gruppo; il rendiconto e il preventivo
 *     di una gestione del gruppo sono fra le sue proposte e dalla rotta entrano senza la frase del rischio; l'archivio e «Scegli la delibera» dicono il
 *     nome della tabella. (Il caso 6 ha l'intestazione della scena 10; la catena la pagina del libro e la lettera della trasmissione.)
 * La catena da capo a capo: la preparazione → i punti → la convocazione → l'apertura → le presenze → il voto → la chiusura → il verbale registrato → la
 * trasmissione → la delibera che si sceglie → le righe del registro delle modifiche.
 *
 * Che cosa non copre: le pagine (vitest `DateDellAssembleaGruppo`, `PreparaGruppo`, `FinestraDelPuntoGruppo`); il punto di un gruppo dentro l'assemblea di
 * tutto il condominio (`Sala/CondominioParzialeTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Via Roma 12 della sala con gli otto box e la tabella «Box», con il segno o senza. */
function adgViaRoma(TestCase $t, bool $segno = true): Beta51Scenario
{
    $s = SalaDiViaRoma12::nuovo($t);
    $s->pertinenza('22', '9')->pertinenza('23', '12');
    foreach (range(24, 28) as $i) {
        $s->pertinenza((string) $i, (string) ($i + 6));
    }
    $s->titolare('22', 'carla', 'usufruttuario')->titolare('22', 'paolo', 'nuda_proprietario')->titolare('23', 'rita')->titolare('23', 'luca', 'inquilino');
    foreach (range(24, 28) as $i) {
        $s->titolare((string) $i, 'c' . ($i + 6));
    }
    $s->tabella('Box', array_fill_keys(['21', '22', '23', '24', '25', '26', '27', '28'], 125));
    if ($segno) {
        $s->tabelle['Box']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    }

    return $s;
}

/** I tre punti del caso. @return list<array<string, mixed>> */
function adgPunti(): array
{
    return [
        ['testo' => 'Sostituzione della serranda dei box', 'testo_pubblico' => null, 'materia' => 'lavori', 'maggioranza' => 'meta_del_valore', 'conduttore' => null,
            'ambito' => 'condominio', 'palazzina_id' => null, 'scala_id' => null, 'tabella_id' => null, 'importo' => null],
        ['testo' => 'Pulizia dei box: turni e costo', 'testo_pubblico' => null, 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'conduttore' => 'interviene',
            'ambito' => 'condominio', 'palazzina_id' => null, 'scala_id' => null, 'tabella_id' => null, 'importo' => null],
    ];
}

/** «Prepara l'assemblea» dalla rotta, per i condòmini della tabella data. */
function adgPrepara(TestCase $t, Beta51Scenario $s, ?int $tabella = null, array $piu = [])
{
    return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), $piu + [
        'tipo' => 'straordinaria', 'ambito' => 'gruppo', 'tabella_id' => $tabella ?? $s->tabelle['Box']->id, 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
        'proposte' => ['varie'], 'punti' => adgPunti(),
    ]);
}

/** L'assemblea dei box preparata dalla rotta, con i documenti guardati. */
function adgAssemblea(TestCase $t, Beta51Scenario $s): Assemblea
{
    adgPrepara($t, $s)->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->latest('id')->firstOrFail();
    $a->forceFill(['documenti_visti_il' => now()])->saveQuietly();

    return $a->refresh();
}

/** La prima convocazione aperta lunedì 14/12 alle 8:05, presidente Rossi; poi l'orologio va a mezzogiorno. */
function adgApriLaPrima(TestCase $t, Beta51Scenario $s, Assemblea $a): SedutaDellAssemblea
{
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    ConvocazioneDiViaRoma12::oggi('2026-12-14 12:00');

    return SalaDiViaRoma12::seduta($a, 'prima')->refresh();
}

/** Le persone date entrano nella seduta data. @param list<string> $chi */
function adgEntrano(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, array $chi, string $alle): void
{
    foreach ($chi as $x) {
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala'], $alle));
    }
}

/** I sette proprietari di box in sala: tutti tranne i Neri del box 22. @return list<string> */
function adgISette(): array
{
    return ['rossi', 'rita', 'c30', 'c31', 'c32', 'c33', 'c34'];
}

/** «Si vota» sul punto 1 e i voti, testa per testa. @param array<string, string> $voti @return array{0: array<string, mixed>, 1: VotazioneDelPunto} */
function adgVota(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, string $alle, array $voti, int $numero = 1): array
{
    $punto = (int) $a->punti()->where('numero', $numero)->value('id');
    $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto], $alle));
    $v = VotazioneDelPunto::query()->findOrFail((int) $r['votazione_id']);
    foreach ($voti as $chi => $voto) {
        $testa = collect((array) $v->intervenuti['teste'])->first(fn ($x) => in_array((int) $s->persone[$chi]->id, array_map('intval', (array) $x['persone']), true));
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v->id, 'testa' => $testa['chiave'], 'voto' => $voto], $alle));
    }

    return [$r, $v->refresh()];
}

function adgSala(Beta51Scenario $s, Assemblea $a): array
{
    return app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
}

it('1. la preparazione dalla rotta con «Per chi» il gruppo: l\'assemblea dei condòmini della tabella «Box», e la riga del registro lo dice', function () {
    $s = adgViaRoma($this);
    $prima = ViaRoma12::ultimaRiga();
    $a = adgAssemblea($this, $s);

    expect($a->ambito?->value)->toBe('gruppo')
        ->and($a->tabella_id)->toBe($s->tabelle['Box']->id)
        ->and($a->scala_id)->toBeNull()
        ->and($a->palazzina_id)->toBeNull()
        ->and($a->punti()->orderBy('numero')->pluck('testo')->all())->toBe(['Sostituzione della serranda dei box', 'Pulizia dei box: turni e costo', 'Varie ed eventuali']);
    $riga = ViaRoma12::righe($prima, 'assemblea')->first(fn ($r) => str_contains((string) $r->riepilogo, 'preparata'));
    expect($riga)->not->toBeNull()
        ->and(collect($riga->campi)->map(fn ($c) => [$c['etichetta'], $c['dopo']])->all())->toBe([['Per chi', 'I condòmini di una tabella'], ['Tabella del valore', 'Box']]);

    // Le pagine: il perimetro e la tabella del gruppo, che copre le sue unità.
    $pagina = \App\Http\Controllers\Assemblee\PerLePagine::assemblea($a);
    expect($pagina['perimetro'])->toBe('Solo i condòmini della tabella «Box»')
        ->and($pagina['perimetro_tipo'])->toBe('gruppo')
        ->and($pagina['gruppo'])->toBe(['tabella_id' => $s->tabelle['Box']->id, 'nome' => 'Box', 'unita' => 8])
        ->and($pagina['tabella']['controllo']['frase'])->toBe('8 unità su 8 · 1.000,00 millesimi');
    $tabella = collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a))->firstWhere('chiave', 'date')['manca'];
    expect(collect($tabella)->firstWhere('chiave', 'tabella'))->toMatchArray(['fatto' => true, 'dettaglio' => '«Box» · 8 unità su 8 · la tabella del gruppo']);
});

it('1. la preparazione si ferma con una tabella senza il segno (422), e non trova la tabella di un altro condominio (404)', function () {
    $s = adgViaRoma($this, false);
    adgPrepara($this, $s)->assertSessionHasErrors(['tabella_id' => 'Scegli una tabella segnata «di un gruppo»: è lei che dice chi è convocato.']);
    adgPrepara($this, $s, null, ['tabella_id' => null])->assertSessionHasErrors(['tabella_id' => 'Scegli una tabella segnata «di un gruppo»: è lei che dice chi è convocato.']);
    expect(Assemblea::query()->where('condominio_id', $s->c->id)->count())->toBe(0);

    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Box', ['1' => 1000]);
    $altro->tabelle['Box']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    adgPrepara($this, $s, $altro->tabelle['Box']->id)->assertNotFound();
    expect(Assemblea::query()->where('condominio_id', $s->c->id)->count())->toBe(0);
});

it('1. il gruppo e la tabella non si cambiano dopo la preparazione: decidono chi è convocato', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['tabella_id' => $s->tabelle['Proprietà']->id])
        ->assertSessionHasErrors(['tabella_id' => 'Il gruppo e la tabella non si cambiano dopo la preparazione: decidono chi è convocato.']);
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['ambito' => 'condominio'])
        ->assertSessionHasErrors(['ambito' => 'Il gruppo e la tabella non si cambiano dopo la preparazione: decidono chi è convocato.']);
    expect($a->refresh()->ambito?->value)->toBe('gruppo')->and($a->tabella_id)->toBe($s->tabelle['Box']->id);
    // Le stesse cose rimandate dalla pagina (il modulo delle date le manda sempre) passano.
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['ambito' => 'gruppo', 'tabella_id' => $s->tabelle['Box']->id,
        'luogo' => 'Box 21'])->assertSessionHasNoErrors();
    expect($a->refresh()->luogo)->toBe('Box 21');
});

it('2. i convocati: 10 avvisi e 8 condòmini; Bianchi Anna non c\'è; Neri Paolo c\'è con il punto straordinario e non c\'è senza', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $convocati = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a, '2026-11-26'))->keyBy('nome');

    expect($convocati->keys()->sort()->values()->all())->toBe(['Colombo Rita', 'Condomino 30', 'Condomino 31', 'Condomino 32', 'Condomino 33', 'Condomino 34',
        'Ferri Luca', 'Neri Carla', 'Neri Paolo', 'Rossi Mario'])
        ->and($convocati['Rossi Mario']['unita_parole'])->toBe('box 21')
        ->and($convocati['Ferri Luca']['punti'])->toBe([2])
        ->and(ConvocatiDellAssemblea::descrizione($convocati->values()->all()))->toBe('8 condòmini, più l\'usufruttuario e il conduttore che il punto 2 chiama.');

    // Senza il punto straordinario il nudo proprietario del box 22 non è convocato.
    $a->punti()->where('numero', 1)->firstOrFail()->forceFill(['amministrazione' => 'ordinaria'])->saveQuietly();
    $senza = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a->refresh(), '2026-11-26'))->keyBy('nome');
    expect($senza->has('Neri Paolo'))->toBeFalse()->and($senza->has('Neri Carla'))->toBeTrue()->and($senza)->toHaveCount(9);
});

it('3. l\'avviso dice il gruppo nel sottotitolo, nell\'apertura, in una riga della riunione e nella delega; nessuna nota sotto i punti; la bacheca', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $c30 = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a, '2026-11-26'))->firstWhere('nome', 'Condomino 30');
    $avviso = EmissioneDelleConvocazioni::avviso($a, $c30, 'raccomandata');

    expect($avviso['sottotitolo'])->toBe('Condominio Via Roma 12 · i condòmini della tabella «Box»')
        ->and($avviso['apertura'])->toBe('la invito all\'assemblea straordinaria dei condòmini delle unità della tabella «Box» del Condominio Via Roma 12, che si terrà:')
        ->and($avviso['righe_della_riunione'][0])->toBe('L\'assemblea riguarda i soli condòmini delle unità della tabella «Box» (art. 1123 c. 3 c.c.): votano e si contano solo loro.')
        ->and($avviso['delega']['poteri'])->toBe('a rappresentare chi firma nell\'assemblea straordinaria dei condòmini della tabella «Box» del Condominio Via Roma 12 di '
            . 'lunedì 14/12/2026 alle 08:00 in prima convocazione e martedì 15/12/2026 alle 18:30 in seconda convocazione, con ogni potere di intervento e di voto.')
        ->and($avviso['chiusura'][0])->toBe('L\'avviso è per le unità: box 24.')
        ->and(collect($avviso['punti'])->pluck('note')->flatten()->all())->toBe([]);

    // Il conduttore del box 23: i punti che lo riguardano, nell'assemblea del gruppo.
    $luca = collect(app(ConvocatiDellAssemblea::class)->alGiorno($a, '2026-11-26'))->firstWhere('nome', 'Ferri Luca');
    expect(EmissioneDelleConvocazioni::avviso($a, $luca, 'a_mano')['apertura'])
        ->toBe('la invito all\'assemblea straordinaria dei condòmini delle unità della tabella «Box» del Condominio Via Roma 12 per i punti che la riguardano come conduttore, che si terrà:');

    $bacheca = app(AvvisoInBacheca::class)->precompilata($a);
    expect(explode("\n", $bacheca['description'])[0])->toBe('È convocata l\'assemblea straordinaria dei condòmini della tabella «Box» del Condominio Via Roma 12:')
        ->and($bacheca['description'])->not->toContain('(decidono');
});

it('4. la fotografia: la costituzione sugli 8 e sui 1.000,00 millesimi, la tabella «Box», i punti senza un perimetro loro', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $f = app(FotografiaDellaSeduta::class)->provvisoria($a);

    expect($f['costituzione']['totale_teste'])->toBe(8)
        ->and($f['costituzione']['totale_valore'])->toBe(100000000)
        ->and($f['tabella']['nome'])->toBe('Box')
        ->and(count($f['unita']))->toBe(8)
        ->and($f['avvisi'])->toBe([]);
    foreach ($f['punti'] as $p) {
        expect($p['perimetro'])->toBeNull()->and($p['gruppo'])->toBeNull()->and($p['totale_teste'])->toBe(8)->and($p['totale_valore'])->toBe(100000000);
    }
});

it('4. in prima 7 su 8 e 875,00: costituita; il punto 1, 6 favorevoli per 750,00, è approvato', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    $seduta = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $seduta, adgISette(), '2026-12-14 08:10');

    $barra = adgSala($s, $a)['barra'];
    expect($barra['costituita'])->toBeTrue()->and($barra['barra'])->toBe('7 su 8 · 875,00 su 1.000,00');
    [$r, $v] = adgVota($this, $s, $a, $seduta, '2026-12-14 08:40', array_fill_keys(['rossi', 'rita', 'c30', 'c31', 'c32', 'c33'], 'favorevole') + ['c34' => 'contrario']);
    expect($r['messaggio'])->toBeNull()
        ->and($v->intervenuti['numero'])->toBe(7)
        ->and($v->intervenuti['totale_teste'])->toBe(8)
        ->and($v->intervenuti['valore'])->toBe(87500000)
        ->and($v->intervenuti['perimetro'])->toBeNull()
        ->and($v->esito_proposto?->value)->toBe('approvata');
});

it('4. in prima con 4 su 8 e 500,00 non è costituita (servono 5 e 666,67)', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    $seduta = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $seduta, ['rossi', 'rita', 'c30', 'c31'], '2026-12-14 08:10');
    expect(adgSala($s, $a)['barra'])->toMatchArray(['costituita' => false, 'barra' => '4 su 8 · 500,00 su 1.000,00', 'servono' => 'servono 5 e 666,67']);
});

it('4. in seconda 3 su 8 e 375,00: costituita (servono 3 e 333,34)', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    $seconda = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    adgEntrano($this, $s, $a, $seconda, ['rossi', 'c30', 'c31'], '2026-12-15 18:45');
    expect(adgSala($s, $a)['barra'])->toMatchArray(['costituita' => true, 'barra' => '3 su 8 · 375,00 su 1.000,00', 'servono' => 'servono 3 e 333,34']);
});

it('5. il punto che non si conta sul gruppo si ferma sempre: la scala, la tabella «Proprietà», una tabella con unità fuori dal gruppo', function () {
    $s = adgViaRoma($this);
    $scala = Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $s->tabella('Box e cantine', ['21' => 100, '22' => 100, '40' => 800]);
    $s->tabelle['Box e cantine']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    $a = adgAssemblea($this, $s);
    $frase = 'In un\'assemblea dei condòmini della tabella «Box» un punto si vota fra loro: con la tabella «Box» o con la tabella di un gruppo che sta dentro di lei.';
    $punto = fn (array $piu) => $piu + ['testo' => 'Le luci del corsello', 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'ambito' => 'condominio'];

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $punto(['ambito' => 'scala', 'scala_id' => $scala->id]))
        ->assertSessionHasErrors(['ambito' => $frase]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $punto(['tabella_id' => $s->tabelle['Proprietà']->id]))
        ->assertSessionHasErrors(['tabella_id' => $frase]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $punto(['tabella_id' => $s->tabelle['Box e cantine']->id]))
        ->assertSessionHasErrors(['tabella_id' => $frase]);
    // «Mettilo lo stesso» non vale per il punto che non si può contare sul gruppo.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $punto(['tabella_id' => $s->tabelle['Proprietà']->id,
        'mettilo_lo_stesso' => true]))->assertSessionHasErrors(['tabella_id' => $frase]);
    // Riscritto: lo stesso fermo.
    $primo = $a->punti()->where('numero', 1)->firstOrFail();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $primo]), $punto(['ambito' => 'scala', 'scala_id' => $scala->id]))
        ->assertSessionHasErrors(['ambito' => $frase]);
    expect($a->punti()->count())->toBe(3)->and($primo->refresh()->ambito?->value)->toBe('condominio');

    // Una tabella di un gruppo che sta dentro «Box»: il punto passa, e si conta fra le sue unità (§1.1).
    $s->tabella('Box fila A', ['21' => 500, '22' => 500]);
    $s->tabelle['Box fila A']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $punto(['tabella_id' => $s->tabelle['Box fila A']->id]))
        ->assertSessionHasNoErrors();
    $f = collect(app(FotografiaDellaSeduta::class)->provvisoria($a->refresh())['punti'])->firstWhere('numero', 3);
    expect($f['totale_teste'])->toBe(2)->and($f['perimetro'])->toBe('Solo i condòmini della tabella «Box fila A»');
});

it('5. il rendiconto di tutto il condominio si ferma con la frase del rischio; «Mettilo lo stesso» lo mette, e il registro lo dice con il nome', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $rendiconto = ['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto', 'maggioranza' => 'ordinaria', 'ambito' => 'condominio'];
    $frase = 'Il rendiconto è di tutto il condominio: con i soli condòmini del gruppo la delibera è annullabile da chi non è convocato. Mettilo in un\'assemblea di tutto '
        . 'il condominio, o premi «Mettilo lo stesso».';

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $rendiconto)->assertSessionHasErrors(['materia' => $frase]);
    expect($a->punti()->count())->toBe(3);
    // Le altre materie di tutti: la stessa frase con le loro parole.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['materia' => 'nomina', 'testo' => 'Nomina dell\'amministratore']
        + $rendiconto)->assertSessionHasErrors(['materia' => 'La nomina e la revoca dell\'amministratore sono di tutto il condominio: con i soli condòmini del gruppo '
            . 'la delibera è annullabile da chi non è convocato. Mettilo in un\'assemblea di tutto il condominio, o premi «Mettilo lo stesso».']);
    // Anche dalle proposte e riscrivendo un punto.
    expect(fn () => OrdineDelGiorno::dalleProposte($a, [['chiave_fonte' => 'rendiconto:1'] + $rendiconto], $s->admin))->toThrow(ValidationException::class);
    $primo = $a->punti()->where('numero', 1)->firstOrFail();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $primo]), $rendiconto)->assertSessionHasErrors(['materia' => $frase]);

    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $rendiconto + ['mettilo_lo_stesso' => true])->assertSessionHasNoErrors();
    expect($a->punti()->count())->toBe(4)->and($a->punti()->where('numero', 3)->firstOrFail()->materia?->value)->toBe('rendiconto');
    $riga = ViaRoma12::righe($prima)->first(fn ($r) => str_contains((string) $r->riepilogo, 'messo lo stesso'));
    expect($riga)->not->toBeNull()
        ->and($riga->riepilogo)->toBe('Punto 3 — Approvazione del rendiconto 2026 e dello stato di riparto: messo lo stesso nell\'assemblea dei condòmini della tabella '
            . '«Box». Il rendiconto è di tutto il condominio: con i soli condòmini del gruppo la delibera è annullabile da chi non è convocato.')
        ->and($riga->oggetto_tipo)->toBe('assemblea')
        ->and($riga->oggetto_id)->toBe($a->id)
        ->and((int) $riga->autore_id)->toBe((int) $s->admin->id);
});

it('5. il rendiconto di una gestione che riguarda solo il gruppo passa sempre; le proposte del gruppo non hanno le materie di tutti', function () {
    $s = adgViaRoma($this);
    $s->gestione('Serranda box', 'straordinaria')->voce('Serranda box', 'Serranda', 600000, ['Box' => 100]);
    $a = adgAssemblea($this, $s);
    $gestione = (int) DB::table('gestioni')->where('nome', 'Serranda box')->value('id');
    OrdineDelGiorno::aggiungi($a, ['testo' => 'Rendiconto della gestione «Serranda box»', 'materia' => 'rendiconto', 'maggioranza' => 'ordinaria',
        'oggetti' => [['gestione', $gestione, 'parla_di']]], $s->admin);
    expect($a->punti()->where('materia', 'rendiconto')->count())->toBe(1);

    // Le materie di tutti non si propongono; il rendiconto della gestione del gruppo sì, con la sua chiave (caso 10).
    $diTutti = collect(ProposteDellOrdineDelGiorno::per($s->c, $a->refresh()))->flatMap(fn ($g) => $g['punti'])
        ->filter(fn ($p) => in_array($p['materia'], ['rendiconto', 'preventivo', 'nomina', 'dispensa', 'regole'], true))->pluck('chiave_fonte')->values()->all();
    expect($diTutti)->toBe(['rendiconto_gestione:' . $gestione]);
});

it('6. il verbale dice il gruppo nell\'intestazione e dopo la costituzione, e va nel registro del gruppo, dal n. 1', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    $seduta = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $seduta, adgISette(), '2026-12-14 08:10');
    adgVota($this, $s, $a, $seduta, '2026-12-14 08:40', array_fill_keys(['rossi', 'rita', 'c30', 'c31', 'c32', 'c33'], 'favorevole') + ['c34' => 'contrario']);

    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta->refresh(), $s->admin);
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento']);
    // L'intestazione della scena 10 approvata: «… l'assemblea straordinaria dei condòmini della tabella «Box» del Condominio Via Roma 12.»
    expect($testo)->toContain('si è riunita in prima convocazione l\'assemblea straordinaria dei condòmini della tabella «Box» del Condominio Via Roma 12.')
        ->not->toContain('limitata alle unità')
        ->toContain('L\'assemblea riguarda i soli condòmini delle unità della tabella «Box» (art. 1123 c. 3 c.c.): 8 partecipanti per 1.000,00 millesimi.')
        ->toContain('intervengono 7 condòmini per 875,00 millesimi')
        ->not->toContain('Sul punto votano');
    expect(substr_count($testo, 'L\'assemblea riguarda i soli condòmini'))->toBe(1);

    $registro = VerbaliDellAssemblea::registroDi($a);
    expect($registro)->toBe('gruppo:' . $s->tabelle['Box']->id)
        ->and(RegistroDeiVerbali::perimetro($s->c, $registro))->toMatchArray(['nome' => 'Registro dei verbali dei condòmini della tabella «Box» di Via Roma 12',
            'tabella_id' => $s->tabelle['Box']->id])
        ->and(RegistroDeiVerbali::delCondominio($s->c, $registro))->toBe($registro)
        ->and(collect(app(RegistroDeiVerbali::class)->registri($s->c))->pluck('chiave')->all())->toBe(['condominio', $registro]);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Box', ['1' => 1000]);
    expect(fn () => RegistroDeiVerbali::delCondominio($s->c, 'gruppo:' . $altro->tabelle['Box']->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('7. il segno della tabella non si toglie finché l\'assemblea del gruppo è aperta; dopo la chiusura sì', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $box = $s->tabelle['Box'];
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => false])
        ->assertSessionHasErrors(['di_un_gruppo' => 'La tabella «Box» è il gruppo dell\'assemblea del 15/12/2026: togli il segno quando è chiusa.']);
    expect($box->refresh()->di_un_gruppo)->toBeTrue();

    $a->forceFill(['stato' => 'chiusa'])->saveQuietly();
    $this->actingAs($s->admin)->post(route('admin.gestionale.tabella-di-un-gruppo', $s->c), ['tabella_id' => $box->id, 'di_un_gruppo' => false])
        ->assertSessionHasNoErrors();
    expect($box->refresh()->di_un_gruppo)->toBeFalse();
});

it('8. il controllo dell\'apertura: un box messo nella tabella dopo l\'invio è di un condomino che non risulta fra i convocati', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    // Dopo l'invio il box 29 del condomino 35 entra nella tabella «Box».
    $s->pertinenza('29', '35')->titolare('29', 'c35');
    DB::table('quote_tabella')->insert(['tabella_id' => $s->tabelle['Box']->id, 'immobile_id' => $s->unita['29']->id, 'valore' => 125, 'created_at' => now(),
        'updated_at' => now()]);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    $controllo = app(ControlloDellApertura::class)->controlla($a->refresh(), SalaDiViaRoma12::seduta($a, 'prima'));
    expect(collect($controllo['problemi'])->pluck('frase')->all())->toContain('Condomino 35 (box 29) non risulta fra i convocati.');
});

it('9. il memo, il punto e la delibera a mano non accettano «Per chi» il gruppo', function () {
    $s = adgViaRoma($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Il portone', 'materia' => 'altro']]);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'La serranda', 'ambito' => 'gruppo'])->assertJsonValidationErrors('ambito');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['testo' => 'La serranda', 'materia' => 'altro', 'ambito' => 'gruppo'])
        ->assertSessionHasErrors('ambito');
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.delibere.a-mano', $s->c), ['giorno' => '2026-10-20', 'testo' => 'La serranda', 'esito' => 'approvata',
        'ambito' => 'gruppo'])->assertJsonValidationErrors('ambito');
    expect(array_keys(\App\Enums\Assemblee\AmbitoAssemblea::parole()))->toContain('gruppo')
        ->and(\App\Enums\Assemblee\AmbitoAssemblea::valoriDeiPunti())->toBe(['condominio', 'palazzina', 'scala']);
});

it('ogni scelta su «Per chi» legge il gruppo: le pagine, il registro delle modifiche, il nome breve della stampa e del registro dei verbali', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    expect(\App\Http\Controllers\Assemblee\PerLePagine::perimetroDi('gruppo', null, null, $s->tabelle['Box']))->toBe('Solo i condòmini della tabella «Box»')
        ->and(\App\Services\RegistroModifiche\Schede\AssembleaNelRegistro::FORMATI['ambito'][1]['gruppo'])->toBe('I condòmini di una tabella')
        ->and(\App\Enums\Assemblee\AmbitoAssemblea::GRUPPO->parola())->toBe('I condòmini di una tabella')
        ->and(app(LetturaDellaSala::class)->perIlVerbale($a)['assemblea']['perimetro'])->toBe('limitata alle unità della tabella «Box»');
    // Il nome breve: nel registro dei verbali e nelle stampe.
    $parola = (new ReflectionMethod(RegistroDeiVerbali::class, 'assemblea'))->invoke(null, $a, SalaDiViaRoma12::seduta($a, 'prima'));
    expect($parola['parola'])->toBe('Straordinaria dei condòmini della tabella «Box», prima convocazione')
        ->and($parola['perimetro'])->toBe('dei condòmini della tabella «Box»');
});

it('la catena: la preparazione → i punti → la convocazione → l\'apertura → le presenze → il voto → il verbale registrato → la trasmissione → la delibera → il registro', function () {
    $s = adgViaRoma($this);
    $prima = ViaRoma12::ultimaRiga();
    // Il primo capo: «Prepara l'assemblea» per i condòmini della tabella «Box», con i due punti scritti e le varie.
    $a = adgAssemblea($this, $s);
    // Il rendiconto di tutto il condominio si ferma; messo lo stesso, poi tolto.
    $rendiconto = ['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'rendiconto', 'maggioranza' => 'ordinaria', 'ambito' => 'condominio'];
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $rendiconto)->assertSessionHasErrors('materia');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), $rendiconto + ['mettilo_lo_stesso' => true])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.punti.destroy', [$s->c, $a, $a->punti()->where('materia', 'rendiconto')->firstOrFail()]))
        ->assertSessionHasNoErrors();
    expect($a->punti()->count())->toBe(3);

    // La convocazione: 10 avvisi, nessuno fuori dal gruppo.
    $convocati = app(ConvocatiDellAssemblea::class)->alGiorno($a->refresh(), '2026-11-26');
    expect($convocati)->toHaveCount(10)->and(collect($convocati)->pluck('nome')->all())->not->toContain('Bianchi Anna')->not->toContain('Gallo Enzo');
    $a = SalaDiViaRoma12::convoca($a, $s);

    // L'apertura in prima, senza cose da scegliere; le sette presenze dai gesti; il voto del punto 1; la conferma; la chiusura.
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    expect(app(ControlloDellApertura::class)->controlla($a, SalaDiViaRoma12::seduta($a, 'prima'))['problemi'])->toBe([]);
    $seduta = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $seduta, adgISette(), '2026-12-14 08:10');
    [, $v] = adgVota($this, $s, $a, $seduta, '2026-12-14 08:40', array_fill_keys(['rossi', 'rita', 'c30', 'c31', 'c32', 'c33'], 'favorevole') + ['c34' => 'contrario']);
    expect($v->esito_proposto?->value)->toBe('approvata');
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $v->id, '2026-12-14 08:41');
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 2)],
        '2026-12-14 08:50'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $r['votazione_id']], '2026-12-14 08:50'));
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $r['votazione_id'], '2026-12-14 08:51');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 3)],
        '2026-12-14 08:55'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-14 09:00'));

    // Il verbale generato e registrato: nel registro del gruppo, n. 1.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.genera', [$s->c, $a]), ['seduta_id' => $seduta->id, 'modello_id' => null])
        ->assertSessionHasNoErrors();
    $verbale = \App\Models\Assemblee\VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->orderByDesc('id')->firstOrFail();
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toContain('L\'assemblea riguarda i soli condòmini delle unità della tabella «Box» (art. 1123 c. 3 c.c.): 8 partecipanti per 1.000,00 millesimi.');
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['partenza' => ['scelta' => 'da_uno'], 'sottoscritto_il' => '2026-12-14'])->assertSessionHasNoErrors();
    $verbale->refresh();
    expect($verbale->registro)->toBe('gruppo:' . $s->tabelle['Box']->id)->and((int) $verbale->numero)->toBe(1);
    // La pagina del libro e la lettera della trasmissione: il nome della tabella resta com'è scritto, e «dei condòmini» una volta sola.
    $stampe = app(\App\Services\Assemblee\Verbale\StampeDelVerbale::class);
    expect($stampe->datiDelVerbale($verbale, true)['titolo'])->toBe('Verbale n. 1 · assemblea straordinaria dei condòmini della tabella «Box» del 14/12/2026');
    $lettera = null;
    $pdf = Mockery::mock(\Mpdf\Mpdf::class);
    $pdf->shouldReceive('Output')->andReturn('%PDF-finto');
    $servizio = Mockery::mock(\App\Services\PDF\PdfService::class);
    $servizio->shouldReceive('generate')->andReturnUsing(function ($vista, $d) use (&$lettera, $pdf) {
        $lettera = $d;

        return $pdf;
    });
    (new \App\Services\Assemblee\Verbale\StampeDelVerbale($servizio))->lettera($verbale, ['nome' => 'Neri Carla', 'unita' => 'box 22', 'righe' => [], 'mezzo' => 'pec']);
    expect($lettera['oggetto'])->toBe('Oggetto: trasmissione del verbale n. 1 dell\'assemblea straordinaria dei condòmini della tabella «Box» del 14/12/2026')
        ->and($lettera['corpo'])->toBe('le trasmetto il verbale n. 1 dell\'assemblea straordinaria dei condòmini della tabella «Box» del 14/12/2026 del Condominio '
            . 'Via Roma 12, nella copia sottoscritta allegata a questo messaggio.');

    // La trasmissione: il verbale va ai soli aventi diritto del gruppo che non c'erano, i Neri del box 22.
    $d = app(DestinatariDelVerbale::class)->per($verbale);
    expect(collect($d['persone'])->where('riceve', true)->pluck('nome')->sort()->values()->all())->toBe(['Neri Carla', 'Neri Paolo'])
        ->and(collect($d['persone'])->pluck('nome')->all())->not->toContain('Bianchi Anna');

    // L'altro capo: la delibera del punto 1 si sceglie come le altre del condominio.
    $delibera = $a->punti()->where('numero', 1)->firstOrFail()->delibere()->firstOrFail();
    expect($delibera->esito?->value)->toBe('approvata')->and(DelibereDelCondominio::siSceglie($delibera, 'gestione'))->toBe([true, null]);

    // Il registro delle modifiche: la preparazione con «Per chi» e la tabella, il punto messo lo stesso con il nome, il verbale registrato.
    $righe = ViaRoma12::righe($prima);
    expect($righe->first(fn ($r) => str_contains((string) $r->riepilogo, 'preparata'))->campi[0])->toMatchArray(['etichetta' => 'Per chi',
        'dopo' => 'I condòmini di una tabella'])
        ->and($righe->first(fn ($r) => str_contains((string) $r->riepilogo, 'messo lo stesso')))->not->toBeNull();
});

it('10. il punto rinviato nell\'assemblea dei box torna come memo del gruppo: la prossima assemblea dei box lo propone spuntato e lo prende, quella di tutti no', function () {
    $s = adgViaRoma($this);
    $a = SalaDiViaRoma12::convoca(adgAssemblea($this, $s), $s);
    $seduta = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $seduta, adgISette(), '2026-12-14 08:10');
    [, $v] = adgVota($this, $s, $a, $seduta, '2026-12-14 08:40', array_fill_keys(['rossi', 'rita', 'c30', 'c31', 'c32', 'c33'], 'favorevole') + ['c34' => 'contrario']);
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $v->id, '2026-12-14 08:41');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 3)],
        '2026-12-14 08:55'));
    // Il punto 2 resta senza esito: la sala si chiude con «Rinviata», e il punto scritto a mano torna come memo.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-14 09:00'));
    $memo = \App\Models\Assemblee\MemoDellAssemblea::query()->where('punto_id', VerbaleDiViaRoma12::punto($a, 2))->firstOrFail();
    $chiave = 'memo:' . $memo->id;
    $box = (int) $s->tabelle['Box']->id;
    expect($memo->ambito?->value)->toBe('gruppo');

    // «Prepara l'assemblea»: il memo è del gruppo, e non parte spuntato finché «Per chi» non è il gruppo.
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    $inPrepara = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->flatMap(fn ($g) => $g['punti'])->firstWhere('chiave_fonte', $chiave);
    expect($inPrepara)->toMatchArray(['testo' => 'Pulizia dei box: turni e costo', 'gruppo_id' => $box, 'spuntato' => false])
        ->and($inPrepara['frase'])->toContain('per i condòmini della tabella «Box»');

    // L'ordinaria di tutto il condominio preparata dalla rotta con il memo spuntato a mano: non entra.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'tabella_id' => $s->tabelle['Proprietà']->id, 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2027-01-25', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2027-01-26', 'ora' => '18:30']],
        'proposte' => ['varie', $chiave], 'punti' => [],
    ])->assertSessionHasNoErrors();
    $tutti = Assemblea::query()->where('condominio_id', $s->c->id)->where('ambito', 'condominio')->latest('id')->firstOrFail();
    expect($tutti->punti()->where('chiave_fonte', $chiave)->exists())->toBeFalse()
        ->and(collect(ProposteDellOrdineDelGiorno::per($s->c, $tutti))->flatMap(fn ($g) => $g['punti'])->pluck('chiave_fonte')->all())->not->toContain($chiave);
    // Né dalle proposte della sua pagina, né con «Mettilo nell'ordine del giorno» della memoria.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.dalle-proposte', [$s->c, $tutti]), ['proposte' => [$chiave]]);
    expect($tutti->punti()->where('chiave_fonte', $chiave)->exists())->toBeFalse();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.nell-ordine', [$s->c, $memo]), ['assemblea_id' => $tutti->id])
        ->assertJsonValidationErrors(['memo' => 'Il memo è dei condòmini della tabella «Box»: lo propone la prossima assemblea dei condòmini della tabella «Box».']);
    expect($tutti->punti()->where('chiave_fonte', $chiave)->exists())->toBeFalse();

    // La prossima assemblea dei box, preparata dalla rotta: il memo è proposto spuntato, e dalla rotta delle proposte entra.
    adgPrepara($this, $s, null, ['sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2027-01-18', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2027-01-19', 'ora' => '18:30']], 'punti' => [adgPunti()[0]]])->assertSessionHasNoErrors();
    $prossima = Assemblea::query()->where('condominio_id', $s->c->id)->where('ambito', 'gruppo')->latest('id')->firstOrFail();
    // I memo di tutto il condominio: non spuntati, salvo quello scritto per questa assemblea.
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'Il cancello del cortile'])->assertSuccessful();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.memo.store', $s->c), ['testo' => 'La luce dei box', 'assemblea_id' => $prossima->id])->assertSuccessful();
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, $prossima))->flatMap(fn ($g) => $g['punti'])->keyBy('testo');
    expect($proposte['Il cancello del cortile']['spuntato'])->toBeFalse()->and($proposte['La luce dei box']['spuntato'])->toBeTrue();
    $proposta = $proposte->firstWhere('chiave_fonte', $chiave);
    expect($proposta)->not->toBeNull()->and($proposta['spuntato'])->toBeTrue();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.dalle-proposte', [$s->c, $prossima]), ['proposte' => [$chiave]])
        ->assertSessionHasNoErrors();
    expect($prossima->punti()->where('chiave_fonte', $chiave)->value('testo'))->toBe('Pulizia dei box: turni e costo')
        ->and($memo->refresh()->stato?->value)->toBe('nel_punto');

    // Tolto dall'ordine del giorno, il memo resta del gruppo: per la prossima assemblea dei box, e non per quella di tutti.
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.punti.destroy', [$s->c, $prossima, $prossima->punti()->where('chiave_fonte', $chiave)->firstOrFail()]))
        ->assertSessionHasNoErrors();
    expect(collect(ProposteDellOrdineDelGiorno::per($s->c, $prossima->refresh()))->flatMap(fn ($g) => $g['punti'])->pluck('chiave_fonte')->all())->toContain($chiave)
        ->and(collect(ProposteDellOrdineDelGiorno::per($s->c, $tutti->refresh()))->flatMap(fn ($g) => $g['punti'])->pluck('chiave_fonte')->all())->not->toContain($chiave);
});

it('10. il rendiconto e il preventivo di una gestione dei soli box sono fra le proposte dell\'assemblea dei box, e dalla rotta entrano senza la frase del rischio', function () {
    $s = adgViaRoma($this);
    $s->gestione('Serranda box', 'straordinaria')->voce('Serranda box', 'Serranda', 600000, ['Box' => 100]);
    $s->gestione('Luce dei box')->voce('Luce dei box', 'Luce', 40000, ['Box' => 100])->pianoOrdinario('Rate della luce dei box', 'Luce dei box', bozza: true);
    // Una gestione divisa anche con «Proprietà» non è del gruppo.
    $s->gestione('Facciata', 'straordinaria')->voce('Facciata', 'Ponteggio', 900000, ['Proprietà' => 100]);
    $serranda = (int) $s->gestioni['Serranda box']['g']->id;
    $luce = (int) $s->gestioni['Luce dei box']['g']->id;
    $facciata = (int) $s->gestioni['Facciata']['g']->id;
    $box = (int) $s->tabelle['Box']->id;

    // «Prepara l'assemblea»: le proposte del gruppo, non spuntate, con il gruppo; dalla rotta, con «Per chi» il gruppo, il rendiconto entra senza fermo.
    $inPrepara = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->flatMap(fn ($g) => $g['punti'])->keyBy('chiave_fonte');
    expect($inPrepara['rendiconto_gestione:' . $serranda])->toMatchArray(['gruppo_id' => $box, 'spuntato' => false, 'materia' => 'rendiconto'])
        ->and($inPrepara->has('rendiconto_gestione:' . $facciata))->toBeFalse();
    $prima = ViaRoma12::ultimaRiga();
    adgPrepara($this, $s, null, ['proposte' => ['rendiconto_gestione:' . $serranda, 'varie']])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->latest('id')->firstOrFail();
    expect($a->punti()->where('chiave_fonte', 'rendiconto_gestione:' . $serranda)->value('testo'))
        ->toBe('Approvazione del rendiconto della gestione «Serranda box» e dello stato di riparto');

    // La pagina dell'assemblea dei box: il preventivo della gestione dei box è fra le proposte, e dalla rotta entra.
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, $a->refresh()))->flatMap(fn ($g) => $g['punti'])->keyBy('chiave_fonte');
    expect($proposte['preventivo_gestione:' . $luce])->toMatchArray(['testo' => 'Approvazione del preventivo della gestione «Luce dei box» e del piano di riparto',
        'materia' => 'preventivo', 'spuntato' => false])
        ->and($proposte->has('rendiconto_gestione:' . $facciata))->toBeFalse()
        ->and($proposte->has('rendiconto_gestione:' . $serranda))->toBeFalse();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.dalle-proposte', [$s->c, $a]), ['proposte' => ['preventivo_gestione:' . $luce]])
        ->assertSessionHasNoErrors();
    expect($a->punti()->where('chiave_fonte', 'preventivo_gestione:' . $luce)->firstOrFail()->materia?->value)->toBe('preventivo')
        ->and(ViaRoma12::righe($prima)->filter(fn ($r) => str_contains((string) $r->riepilogo, 'messo lo stesso'))->all())->toBe([]);

    // L'assemblea di tutto il condominio non le propone.
    $tutti = SalaDiViaRoma12::ordinaria($s);
    expect(collect(ProposteDellOrdineDelGiorno::per($s->c, $tutti))->flatMap(fn ($g) => $g['punti'])->pluck('chiave_fonte')->filter(fn ($c) => str_contains((string) $c, '_gestione:'))->all())
        ->toBe([]);
});

it('10. l\'archivio e «Scegli la delibera» dicono il gruppo con il nome della tabella', function () {
    $this->withoutVite();
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    $righe = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.index', $s->c))->assertOk()->viewData('page')['props']['assemblee']['data'];
    expect(collect($righe)->firstWhere('id', $a->id)['perimetro'])->toBe('Solo i condòmini della tabella «Box»');

    \App\Models\Assemblee\Delibera::create(['condominio_id' => $s->c->id, 'assemblea_id' => $a->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 1), 'votazione' => 1,
        'testo' => 'Sostituzione della serranda dei box', 'esito' => 'approvata', 'deliberata_il' => '2026-12-14', 'created_by' => $s->admin->id]);
    $gruppi = $this->actingAs($s->admin)->getJson(route('admin.gestionale.delibere.scegli', ['condominio' => $s->c->id, 'ruolo' => 'delibera']))->assertOk()->json('gruppi');
    expect(collect($gruppi)->firstWhere('assemblea.id', $a->id)['assemblea']['perimetro'])->toBe('I condòmini della tabella «Box»');
});

it('10. la prosecuzione dell\'assemblea dei box apre con il gruppo, come l\'intestazione della scena 10', function () {
    $s = adgViaRoma($this);
    $a = adgAssemblea($this, $s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'prima', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a->refresh(), $s);
    $lunedi = adgApriLaPrima($this, $s, $a);
    adgEntrano($this, $s, $a, $lunedi, adgISette(), '2026-12-14 08:10');
    [, $v] = adgVota($this, $s, $a, $lunedi, '2026-12-14 08:40', array_fill_keys(adgISette(), 'favorevole'));
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $v->id, '2026-12-14 08:41');
    $giovedi = SedutaDellAssemblea::query()->where('assemblea_id', $a->id)->where('numero', 2)->firstOrFail();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $lunedi->id, 'prosegue_seduta_id' => $giovedi->id], '2026-12-14 09:30'));

    ConvocazioneDiViaRoma12::oggi('2026-12-17 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $giovedi->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:41'));
    ConvocazioneDiViaRoma12::oggi('2026-12-17 21:00');
    $verbale = VerbaliDellAssemblea::perLaSeduta($giovedi->refresh(), $s->admin);
    $testo = StrutturaDelVerbale::testo(app(ComposizioneDelVerbale::class)->componi($verbale, ModelliDelVerbale::scelto($s->c, null))['documento']);
    expect($testo)->toStartWith('Il giorno 17 dicembre 2026, alle ore 18:41, presso Sala condominiale, Via Roma 12, prosegue l\'assemblea straordinaria del 14 dicembre '
        . '2026 dei condòmini della tabella «Box» del Condominio Via Roma 12, sospesa alle ore 09:30, per i punti 2 e 3 dell\'ordine del giorno.');
});

it('Fase 1-bis — la testa della pagina e della sala di un\'assemblea di un gruppo dice il gruppo; quella di tutto il condominio non cambia', function () {
    $condominio = new \App\Models\Condominio(['nome' => 'Condominio Prova a video']);
    $gruppo = (new \App\Models\Assemblee\Assemblea())->forceFill(['ambito' => 'gruppo', 'tipo' => 'ordinaria'])->setRelation('tabella', new \App\Models\Tabella(['nome' => 'Scala B']));
    $tutti = (new \App\Models\Assemblee\Assemblea())->forceFill(['ambito' => 'condominio', 'tipo' => 'ordinaria']);

    expect(\App\Http\Controllers\Assemblee\PerLePagine::titolo($gruppo, $condominio))->toBe('Assemblea ordinaria dei condòmini della tabella «Scala B» di Condominio Prova a video')
        ->and(\App\Http\Controllers\Assemblee\PerLePagine::titolo($tutti, $condominio))->toBe('Assemblea ordinaria di Condominio Prova a video');
});
