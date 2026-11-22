<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * La catena della tenuta senza rete del verbale, dal lato del server, con il lotto che la coda del browser manda davvero quando la rete torna
 * (`tests/Fixtures/verbale/lotti_senza_rete.json`, lo stesso file che `useVerbaleSenzaRete.test.ts` fa scrivere alla coda):
 *
 * 1. **La lettera scritta senza rete → il disco → la rete che torna → la versione nel server.** Tre modifiche alla discussione del punto 4 scritte fra le
 *    20:16 e le 20:18, un gesto solo (l'ultimo testo), arrivato alle 20:31: la parte ha quel testo, la revisione 1, l'ora in cui era scritta (20:18) e il
 *    dispositivo; il libro dei gesti ha la riga con le due ore e senza il testo; la pagina del verbale lo legge. Dopo la pausa, il testo delle 20:18 diventa
 *    una versione, con l'ora in cui era scritto.
 * 2. **Il computer spento e riacceso → niente di doppio.** Il lotto rimandato com'era risponde «doppio» con la revisione di allora, e la parte non cambia.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** I lotti del file in comune, con i numeri di questo verbale al posto dei segnaposti. @param array<string, int> $numeri @return array<string, mixed> */
function csvFileInComune(array $numeri): array
{
    $testo = (string) file_get_contents(base_path('tests/Fixtures/verbale/lotti_senza_rete.json'));
    foreach ($numeri as $nome => $numero) {
        $testo = str_replace(['"{' . $nome . '}"', '{' . $nome . '}'], [(string) $numero, (string) $numero], $testo);
    }

    return json_decode($testo, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Il verbale della riunione in corso (la discussione si scrive durante l'assemblea); poi la rete del portatile cade, e torna alle 20:31. Dopo «Genera» la
 * discussione arrivata tardi non entra più nella parte del punto: è una versione «arrivata dopo la generazione» (`TestoCheNonSiPerdeTest`).
 */
function csvVerbale($t): array
{
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($t, false);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);

    return [$s, $a, $verbale, csvFileInComune(['verbale' => $verbale->id, 'punto4' => VerbaleDiViaRoma12::punto($a, 4)])];
}

it('catena 1: il testo scritto senza rete arriva quando la rete torna, con l\'ora in cui era scritto; il libro dei gesti senza il testo; poi la versione', function () {
    [$s, $a, $verbale, $file] = csvVerbale($this);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:31');
    $gesto = $file['lotti'][0]['gesti'][0];

    $r = VerbaleDiViaRoma12::manda($this, $s, $a, $file['lotti'][0]['gesti'], $file['dispositivo'])->assertOk();
    expect($r->json('risposte.0'))->toMatchArray(['gesto' => $gesto['gesto'], 'esito' => 'applicato', 'verbale_id' => $verbale->id, 'parte' => $parte, 'revisione' => 1]);

    $riga = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->firstOrFail();
    expect($riga->documento)->toBe($gesto['dati']['documento'])->and($riga->revisione)->toBe(1)->and($riga->dispositivo)->toBe($file['dispositivo'])
        ->and($riga->scritta_il->copy()->utc()->toIso8601ZuluString())->toBe('2026-12-15T19:18:00Z');

    $nelLibro = GestoDellaSala::query()->where('gesto', $gesto['gesto'])->firstOrFail();
    expect($nelLibro->tipo)->toBe('verbale_testo')->and($nelLibro->segnato_il->copy()->utc()->toIso8601ZuluString())->toBe('2026-12-15T19:18:00Z')
        ->and($nelLibro->arrivato_il->copy()->utc()->toIso8601ZuluString())->toBe('2026-12-15T19:31:00Z')
        ->and(json_encode($nelLibro->dati))->not->toContain('preventivi');

    // La pagina del verbale lo legge.
    $stato = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.verbale.stato', [$s->c, $a]))->assertOk();
    expect($stato->json('verbale.parti')[$parte]['documento'])->toBe($gesto['dati']['documento'])->and($stato->json('verbale.parti')[$parte]['revisione'])->toBe(1);

    // Alle 20:40, dopo la pausa, lo stesso portatile continua dalla revisione arrivata: il testo delle 20:18 diventa una versione, con l'ora in cui era scritto.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:40');
    $dopo = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'I preventivi sono arrivati il giorno prima. Si rinvia.']]]]];
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, $dopo, '2026-12-15 20:40', 1)], $file['dispositivo'])
        ->assertOk()->assertJsonPath('risposte.0.esito', 'applicato')->assertJsonPath('risposte.0.revisione', 2);
    $versione = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->orderByDesc('id')->firstOrFail();
    expect($versione->documento)->toBe($gesto['dati']['documento'])->and($versione->segnata_il->copy()->utc()->toIso8601ZuluString())->toBe('2026-12-15T19:18:00Z')
        ->and((bool) $versione->in_attesa)->toBeFalse();
});

it('catena 2: il computer spento e riacceso rimanda il lotto com\'era: «doppio» con la revisione di allora, e la parte non cambia', function () {
    [$s, $a, $verbale, $file] = csvVerbale($this);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:31');
    VerbaleDiViaRoma12::manda($this, $s, $a, $file['lotti'][0]['gesti'], $file['dispositivo'])->assertOk()->assertJsonPath('risposte.0.esito', 'applicato');

    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:33');
    $r = VerbaleDiViaRoma12::manda($this, $s, $a, $file['lotti'][0]['gesti'], $file['dispositivo'])->assertOk();
    expect($r->json('risposte.0'))->toMatchArray(['esito' => 'doppio', 'esito_di_allora' => 'applicato', 'revisione' => 1, 'parte' => $parte])
        ->and($r->json('risposte.0.messaggio'))->toBe('Questo salvataggio era già arrivato alle 20:31: conta una volta.')
        ->and(ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->value('revisione'))->toBe(1)
        ->and(GestoDellaSala::query()->where('tipo', 'verbale_testo')->count())->toBe(1);
});
