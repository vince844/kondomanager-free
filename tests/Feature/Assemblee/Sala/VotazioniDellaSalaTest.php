<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Il voto (R8): «Si vota» ferma il momento e gli intervenuti (chi entra dopo non conta, chi è uscito o caduto nemmeno); «Tutti i presenti favorevoli» è un
 * gesto solo, poi i contrari e gli astenuti; il conflitto d'interessi con la nota, che non toglie nessuno e avvisa se è determinante; l'esito proposto
 * secondo la maggioranza del punto e la convocazione; «Conferma» scrive la delibera con il giorno della seduta; un voto si corregge fino alla chiusura con
 * il perché, e la delibera si riallinea; l'usufruttuario che vota sul punto straordinario per scelta del presidente; il punto senza voto «Trattato»; il voto
 * discorde dei comproprietari; le due votazioni del rendiconto; la dichiarazione a verbale.
 *
 * Che cosa non copre: le due versioni di un voto (`GestiDellaSalaTest`); il punto della sola scala (`CateneDellaSalaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La seconda aperta, con le persone date in sala alle 18:45. @return array{0: Beta51Scenario, 1: \App\Models\Assemblee\Assemblea, 2: \App\Models\Assemblee\SedutaDellAssemblea} */
function vdsSala($t, array $chi): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, $chi);

    return [$s, $a, $seduta];
}

function vdsSiVota($t, $s, $a, $seduta, int $numero, array $piu = [], string $alle = '2026-12-15 19:00'): array
{
    return SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', $piu + ['seduta_id' => $seduta->id,
        'punto_id' => $a->punti()->where('numero', $numero)->value('id')], $alle));
}

function vdsTesta($s, string $chi): string
{
    return 'p-' . $s->persone[$chi]->id;
}

it('«Si vota» ferma gli intervenuti: chi entra dopo non conta, chi è uscito prima nemmeno', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', 'gallo', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['gallo']->id]],
        '2026-12-15 18:55'));
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    SalaDiViaRoma12::entrano($this, $s, $a, ['c38'], '2026-12-15 19:05');

    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    expect($votazione->intervenuti['teste'])->toHaveCount(11)
        ->and($votazione->intervenuti['valore'])->toBe(44700000)
        ->and(collect($votazione->intervenuti['teste'])->pluck('chiave')->all())->not->toContain(vdsTesta($s, 'gallo'), vdsTesta($s, 'c38'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $votazione->id, 'testa' => vdsTesta($s, 'c38'),
        'voto' => 'favorevole'], '2026-12-15 19:06')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Condomino 38 non era presente quando si è votato il punto 1: il suo voto non conta.');
});

it('«Tutti i presenti favorevoli» è un gesto solo; poi i contrari e gli astenuti, e l\'esito proposto segue la maggioranza e la convocazione', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    expect($votazione->voti()->count())->toBe(11)
        ->and($votazione->conteggi['favorevoli'])->toMatchArray(['teste' => 11, 'valore' => 44700000])
        ->and($votazione->esito_proposto?->value)->toBe('approvata')
        ->and($r['versione'])->toBe(2);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $votazione->id, 'testa' => vdsTesta($s, 'rita'), 'voto' => 'contrario'],
        '2026-12-15 19:02', 1));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $votazione->id, 'testa' => vdsTesta($s, 'rossi'), 'voto' => 'astenuto'],
        '2026-12-15 19:02', 1));
    $votazione->refresh();
    expect($votazione->conteggi['favorevoli'])->toMatchArray(['teste' => 9, 'valore' => 29200000])
        ->and($votazione->conteggi['contrari'])->toMatchArray(['teste' => 1, 'valore' => 9000000])
        ->and($votazione->conteggi['astenuti'])->toMatchArray(['teste' => 1, 'valore' => 6500000])
        ->and($votazione->esito_proposto?->value)->toBe('respinta')
        ->and($votazione->conteggi['frase'])->toBe('Respinta: 9 favorevoli su 11 intervenuti, 292,00 millesimi; in seconda convocazione servono la maggioranza degli intervenuti e 333,34 millesimi.');
});

it('l\'esito per condizione arriva con i conteggi del voto: le teste bastano, il valore no, e quanto manca; la frase per il verbale resta quella', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => vdsTesta($s, 'rita'),
        'voto' => 'contrario'], '2026-12-15 19:02', 1));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => vdsTesta($s, 'rossi'),
        'voto' => 'astenuto'], '2026-12-15 19:02', 1));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);

    expect($votazione->conteggi['condizioni'])->toBe([
        ['condizione' => 'teste', 'passa' => true, 'frase' => 'Teste: 9 su 11, più della metà: sì'],
        ['condizione' => 'valore', 'passa' => false, 'frase' => 'Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34'],
    ])
        ->and($votazione->conteggi['frase'])->not->toContain('mancano');
});

it('il conflitto d\'interessi non toglie nessuno dal conto, e avvisa quando è determinante', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'paolo', 'rita', 'bianchi', 'gallo', ...SalaDiViaRoma12::altri(3)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 4);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    expect($votazione->conteggi['favorevoli'])->toMatchArray(['teste' => 9, 'valore' => 52825000])->and($votazione->esito_proposto?->value)->toBe('approvata');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conflitto', ['votazione_id' => $votazione->id, 'testa' => vdsTesta($s, 'rita'),
        'in_conflitto' => true, 'nota' => 'È la titolare dell\'impresa del portone'], '2026-12-15 19:03'));
    $votazione->refresh();
    expect($votazione->conteggi['favorevoli'])->toMatchArray(['teste' => 9, 'valore' => 52825000])
        ->and($votazione->esito_proposto?->value)->toBe('approvata')
        ->and($votazione->conflitto_determinante)->toBeTrue()
        ->and($votazione->conteggi['conflitto'])->toBe('Senza i suoi 90,00 millesimi i favorevoli sarebbero 438,25: il voto in conflitto è determinante.')
        ->and($votazione->voti()->where('in_conflitto', true)->value('nota_conflitto'))->toBe('È la titolare dell\'impresa del portone');
});

it('«Conferma» scrive la delibera con il giorno della seduta e la lega al voto; la riga del registro ha i voti nel lotto', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $riga = ViaRoma12::ultimaRiga();

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'respinta'], '2026-12-15 19:02', 2)])
        ->json('risposte.0');
    expect($r['messaggio'])->toBe('L\'esito proposto è «Approvata»: per confermare «Respinta» scrivi il motivo.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:02', 2));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    $delibera = Delibera::query()->findOrFail($votazione->delibera_id);
    $ultima = ViaRoma12::righe($riga)->last();
    expect($delibera->esito->value)->toBe('approvata')
        ->and($delibera->deliberata_il->format('Y-m-d'))->toBe('2026-12-15')
        ->and($delibera->punto_id)->toBe($votazione->punto_id)
        ->and($votazione->confermata_il)->not->toBeNull()
        ->and($ultima->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026: punto 1 approvata, 11 favorevoli con 447,00 millesimi, 0 contrari, 0 astenuti')
        ->and($ultima->lotto['voti'])->toHaveCount(11)
        ->and($ultima->lotto['voti'][0])->toHaveKeys(['nome', 'millesimi', 'voto'])
        ->and($ultima->lotto['conteggi'])->toHaveKey('delibera.creata');
});

it('un voto si corregge fino alla chiusura, con il perché; la conferma di nuovo riallinea la stessa delibera', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:02', 2));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    $delibera = $votazione->delibera_id;

    $gesto = fn (string $chi, array $piu = []) => SalaDiViaRoma12::gesto('voto', $piu + ['votazione_id' => $votazione->id, 'testa' => vdsTesta($s, $chi),
        'voto' => 'contrario'], '2026-12-15 19:20');
    expect(SalaDiViaRoma12::manda($this, $s, $a, [$gesto('rita')])->json('risposte.0.messaggio'))
        ->toBe('Il voto del punto 1 è confermato: per correggerlo scrivi il perché.');
    SalaDiViaRoma12::applica($this, $s, $a, $gesto('rita', ['perche' => 'Colombo Rita aveva detto «contraria», segnata male']));
    SalaDiViaRoma12::applica($this, $s, $a, $gesto('rossi', ['perche' => 'Rossi Mario ha cambiato il voto prima della chiusura']));
    $votazione->refresh();
    expect($votazione->confermata_il)->toBeNull()->and($votazione->esito_proposto?->value)->toBe('respinta');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $votazione->id, 'esito' => 'respinta'], '2026-12-15 19:21',
        $votazione->versione));
    expect($votazione->refresh()->delibera_id)->toBe($delibera)
        ->and(Delibera::query()->findOrFail($delibera)->esito->value)->toBe('respinta')
        ->and(Delibera::query()->where('punto_id', $votazione->punto_id)->count())->toBe(1);
});

it('sul punto straordinario vota il nudo proprietario; con la scelta del presidente l\'usufruttuaria, e il voto lo ricorda', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [
        ['testo' => 'Sostituzione del portone d\'ingresso, € 14.500,00', 'materia' => 'lavori'],
        ['testo' => 'Rifacimento del cancello carraio, € 6.000,00', 'materia' => 'lavori'],
    ]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['carla', 'rossi', ...SalaDiViaRoma12::altri(8)]);

    $senza = VotazioneDelPunto::query()->findOrFail(vdsSiVota($this, $s, $a, $seduta, 1)['votazione_id']);
    expect(collect($senza->intervenuti['teste'])->pluck('chiave')->all())->not->toContain(vdsTesta($s, 'carla'));

    $con = VotazioneDelPunto::query()->findOrFail(vdsSiVota($this, $s, $a, $seduta, 2, ['usufruttuari' => [
        ['immobile_id' => $s->unita['9']->id, 'perche' => 'art_1006']]])['votazione_id']);
    $carla = collect($con->intervenuti['teste'])->firstWhere('chiave', vdsTesta($s, 'carla'));
    expect($carla)->toMatchArray(['millesimi' => 8000000, 'chi' => ['anagrafica_id' => $s->persone['carla']->id, 'nome' => 'Neri Carla', 'ruolo' => 'usufruttuario']])
        ->and($con->scelte)->toBe(['usufruttuari' => [['immobile_id' => $s->unita['9']->id, 'unita' => 'Int. 9', 'perche' => 'art_1006',
            'frase' => 'Int. 9: vota Neri Carla, che ha l\'usufrutto, perché si avvale dell\'art. 1006 c.c.']]]);
});

it('il punto senza voto ha solo «Trattato»', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', ...SalaDiViaRoma12::altri(8)]);
    $punto = $a->punti()->where('numero', 6)->first();
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto->id], '2026-12-15 19:00')])
        ->json('risposte.0');
    expect($r['messaggio'])->toBe('Il punto 6 è senza voto: segnalo «Trattato».');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => $punto->id], '2026-12-15 20:10'));
    expect(VotazioneDelPunto::query()->where('punto_id', $punto->id)->first()->esito?->value)->toBe('senza_voto')
        ->and(Delibera::query()->where('punto_id', $punto->id)->first()->esito->value)->toBe('senza_voto');
});

it('i comproprietari che votano in modo diverso: di serie contano astenuti; con «Decide il presidente» serve la sua scelta', function () {
    [$s, $a, $seduta] = vdsSala($this, ['anna', 'marco', 'rossi', ...SalaDiViaRoma12::altri(8)]);
    $v = vdsSiVota($this, $s, $a, $seduta, 1);
    // La testa dei comproprietari dell'Int. 5 (con «due teste», di serie, l'unità in comproprietà ha la sua chiave).
    $russo = collect(VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->intervenuti['teste'])->first(fn ($t) => count($t['persone']) > 1)['chiave'];
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $russo, 'voto' => 'discorde'], '2026-12-15 19:01'));
    $votazione = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    expect($votazione->conteggi['astenuti'])->toMatchArray(['teste' => 1, 'valore' => 7000000]);

});

it('con «Decide il presidente» salvato prima di aprire, il voto discorde dei comproprietari vuole la sua scelta', function () {
    // La scelta dello studio vale dall'apertura della sala, ferma nella sua fotografia: cambiata a sala aperta non cambia la sala di quella sera
    // (`RegoleDellaSalaTest`).
    $impostazioni = app(AssembleeSettings::class);
    $impostazioni->voto_discorde_comproprietari = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $impostazioni->save();
    [$s, $a, $seduta] = vdsSala($this, ['anna', 'marco', 'rossi', ...SalaDiViaRoma12::altri(8)]);
    $votazione = VotazioneDelPunto::query()->findOrFail(vdsSiVota($this, $s, $a, $seduta, 1)['votazione_id']);
    $russo = collect($votazione->intervenuti['teste'])->first(fn ($t) => count($t['persone']) > 1)['chiave'];
    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $votazione->id, 'testa' => $russo, 'voto' => 'discorde'],
        '2026-12-15 19:02', 0)])->json('risposte.0');
    expect($r['messaggio'])->toBe('I comproprietari votano in modo diverso: decide il presidente, scegli come conta il voto.');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $votazione->id, 'testa' => $russo, 'voto' => 'discorde',
        'decisione' => 'favorevole'], '2026-12-15 19:02', 0));
    expect($votazione->refresh()->conteggi['favorevoli'])->toMatchArray(['teste' => 1, 'valore' => 7000000]);
});

it('il rendiconto ha due votazioni, il rendiconto e il riparto, con due delibere', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    foreach ([[1, 'rendiconto'], [2, 'riparto']] as [$numero, $oggetto]) {
        $v = vdsSiVota($this, $s, $a, $seduta, 1, ['numero' => $numero, 'oggetto' => $oggetto]);
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:02', 2));
    }
    expect(Delibera::query()->where('assemblea_id', $a->id)->orderBy('votazione')->get()->map(fn ($d) => [$d->votazione, $d->oggetto])->all())
        ->toBe([[1, 'rendiconto'], [2, 'riparto']]);
});

it('la dichiarazione a verbale', function () {
    [$s, $a, $seduta] = vdsSala($this, ['rossi']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 4)->value('id'),
        'anagrafica_id' => $s->persone['rossi']->id, 'testo' => 'Chiedo che si mettano a verbale i tre preventivi.'], '2026-12-15 19:30'));
    expect(DichiarazioneAVerbale::query()->first()->toArray())->toMatchArray(['nome' => 'Rossi Mario', 'testo' => 'Chiedo che si mettano a verbale i tre preventivi.']);
});
