<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\Evento;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * Il testo del verbale non si perde e non si sovrascrive sul server (V4, decisioni 514 (5), 519):
 *
 * - **dopo «due versioni»** il portatile che continua a scrivere non cancella il testo del tablet: finché la scelta è aperta ciò che manda va nella sua versione
 *   in attesa, e la risposta in conflitto non dà una revisione da cui ripartire;
 * - **due schede dello stesso browser** sono due scrittori: la scheda ferma al testo delle 21:00 non cancella la frase scritta alle 21:02 nell'altra;
 * - **la discussione arrivata dopo «Genera»** (il tablet rimasto senza rete) non è «applicata» dove il verbale non la legge: è una versione «arrivata dopo la
 *   generazione», nel compito dell'Action inbox con il suo nome, e «Mettila nel punto 4 del verbale» la mette al suo posto;
 * - **due prime discussioni insieme** non fanno due verbali della stessa riunione: la seduta si blocca prima di cercare il verbale.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function tnpDoc(string $testo): array
{
    return StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($testo)]);
}

/** Il testo di una parte e le sue versioni, «… [in attesa]». @return array{parte: string, versioni: list<string>} */
function tnpTesti(int $verbale, string $parte): array
{
    return [
        'parte' => StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale)->where('parte', $parte)->value('documento')),
        'versioni' => VersioneDelVerbale::query()->where('verbale_id', $verbale)->where('parte', $parte)->orderBy('numero')->get()
            ->map(fn ($v) => StrutturaDelVerbale::testo((array) $v->documento) . ($v->in_attesa && $v->scelta_il === null ? ' [in attesa]' : ''))->all(),
    ];
}

/** Un gesto del testo con la scheda del browser. */
function tnpConScheda(array $gesto, string $scheda): array
{
    $gesto['dati']['scheda'] = $scheda;

    return $gesto;
}

it('dopo «due versioni» il portatile che continua a scrivere non cancella il testo del tablet: va nella sua versione in attesa', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $id = (int) VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin)->id;
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, tnpDoc('Dal tablet: Rossi chiede tre preventivi.'), '2026-12-15 20:30:00', 0)],
        'tablet di Marco');
    $r = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, tnpDoc('Dal portatile: il portone costa troppo.'), '2026-12-15 20:29:50', 0)],
        'portatile di Andrea')->json('risposte.0');
    expect($r['esito'])->toBe('in_conflitto')->and($r['revisione'])->toBeNull();

    // Una pagina che ripartisse dalla revisione di adesso (1): il testo del tablet resta, e il portatile scrive nella sua versione.
    $r2 = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($id, $parte, tnpDoc('Dal portatile: il portone costa troppo. Si vota.'),
        '2026-12-15 20:31:20', 1)], 'portatile di Andrea')->json('risposte.0');
    expect($r2['esito'])->toBe('in_conflitto')->and($r2['versione_id'])->toBe($r['versione_id'])->and($r2['revisione'])->toBeNull()
        ->and(tnpTesti($id, $parte))->toBe(['parte' => 'Dal tablet: Rossi chiede tre preventivi.',
            'versioni' => ['Dal portatile: il portone costa troppo. Si vota. [in attesa]']]);

    // «Tieni questa»: il testo del portatile diventa il testo; quello del tablet resta fra le versioni.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.scegli', [$s->c, $a, $id, $r['versione_id']]), ['scelta' => 'questa'])
        ->assertSessionHasNoErrors();
    $dopo = tnpTesti($id, $parte);
    expect($dopo['parte'])->toBe('Dal portatile: il portone costa troppo. Si vota.')->and($dopo['versioni'])->toContain('Dal tablet: Rossi chiede tre preventivi.');
});

it('due schede dello stesso browser sono due scrittori: la scheda ferma alle 21:00 non cancella la frase delle 21:02', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $id = (int) $verbale->id;
    $rev = (int) ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', 'intero')->value('revisione');
    $intero = (array) ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', 'intero')->value('documento');
    $conFrase = $intero;
    $conFrase['content'][] = StrutturaDelVerbale::paragrafo('La frase sul portone, scritta alle 21:02 nella scheda 1.');
    $conRefuso = $intero;
    $conRefuso['content'][] = StrutturaDelVerbale::paragrafo('Un refuso corretto alle 21:03 nella scheda 2.');

    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:02');
    $r1 = VerbaleDiViaRoma12::manda($this, $s, $a, [tnpConScheda(VerbaleDiViaRoma12::testo($id, 'intero', $conFrase, '2026-12-15 21:02', $rev), 'scheda-uno')],
        'computer di Andrea Costa#a1b2c3d4')->json('risposte.0');
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:03');
    $r2 = VerbaleDiViaRoma12::manda($this, $s, $a, [tnpConScheda(VerbaleDiViaRoma12::testo($id, 'intero', $conRefuso, '2026-12-15 21:03', $rev), 'scheda-due')],
        'computer di Andrea Costa#a1b2c3d4')->json('risposte.0');

    expect($r1['esito'])->toBe('applicato')->and($r2['esito'])->toBe('in_conflitto')
        ->and(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $id)->where('parte', 'intero')->value('documento')))
        ->toContain('La frase sul portone, scritta alle 21:02 nella scheda 1.');
    // La stessa scheda che continua non è mai in conflitto con sé stessa.
    $conFrase['content'][] = StrutturaDelVerbale::paragrafo('E un\'altra frase.');
    $r3 = VerbaleDiViaRoma12::manda($this, $s, $a, [tnpConScheda(VerbaleDiViaRoma12::testo($id, 'intero', $conFrase, '2026-12-15 21:04', $rev), 'scheda-uno')],
        'computer di Andrea Costa#a1b2c3d4')->json('risposte.0');
    expect($r3['esito'])->toBe('applicato');
});

it('la discussione del punto 4 arrivata dal tablet dopo «Genera» non è «applicata»: resta da parte, nel compito, e «Mettila nel punto 4» la mette nel verbale', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $punto4 = VerbaleDiViaRoma12::punto($a, 4);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:10');
    $r = VerbaleDiViaRoma12::manda($this, $s, $a, [['gesto' => (string) \Illuminate\Support\Str::uuid(), 'tipo' => 'testo',
        'dati' => ['seduta_id' => $seduta->id, 'parte' => 'punto:' . $punto4, 'documento' => tnpDoc('Dal tablet: Rossi chiede tre preventivi.')], 'base' => 0,
        'orologio' => '2026-12-15T19:16:00Z', 'segnato_il' => '2026-12-15T19:16:00Z']], 'tablet del segretario')->json('risposte.0');

    $attesa = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('in_attesa', true)->firstOrFail();
    expect($r['esito'])->toBe('in_conflitto')->and($r['revisione'])->toBeNull()
        ->and($r['messaggio'])->toBe('Il verbale è già generato: la discussione del punto 4 arrivata adesso non è nel testo. Resta da parte, fra le versioni: nella '
            . 'pagina del verbale, «Mettila nel punto 4 del verbale».')
        ->and($attesa->attesa)->toBe(VersioneDelVerbale::DOPO_LA_GENERAZIONE)
        ->and(ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'punto:' . $punto4)->exists())->toBeFalse();
    $compito = Evento::query()->where('meta->type', 'due_versioni')->where('meta->context->assemblea_id', $a->id)->orderByDesc('id')->first();
    expect($compito?->description)->toBe('1 discussione arrivata dopo la generazione, da mettere nel verbale.');
    // La bozza non si stampa con la discussione da parte.
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale->refresh(), true)->assertSessionHasErrors(['bozza']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.scegli', [$s->c, $a, $verbale, $attesa]), ['scelta' => 'metti'])->assertSessionHasNoErrors();
    $intero = (array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento');
    $testo = StrutturaDelVerbale::testo($intero);
    $dopoIlTitolo = strpos($testo, '4. ');
    expect($testo)->toContain('Dal tablet: Rossi chiede tre preventivi.')
        ->and(strpos($testo, 'Dal tablet'))->toBeGreaterThan($dopoIlTitolo)
        ->and(strpos($testo, 'Dal tablet'))->toBeLessThan(strpos($testo, '5. '))
        ->and($attesa->refresh()->scelta)->toBe('metti')
        ->and(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'punto:' . $punto4)->value('documento')))
        ->toBe('Dal tablet: Rossi chiede tre preventivi.');
    // Nel testo, prima della parte con i voti del punto 4.
    $chiavi = array_values(array_map(fn ($n) => ($n['type'] ?? '') === 'datiDellaSala' ? (string) $n['attrs']['chiave'] : StrutturaDelVerbale::testo((array) $n),
        (array) $intero['content']));
    $qui = array_search('Dal tablet: Rossi chiede tre preventivi.', $chiavi, true);
    expect($chiavi[$qui + 1] ?? null)->toStartWith('voto:');
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale->refresh(), true)->assertOk();
});

it('la catena: la discussione scritta in sala senza rete arriva dalla coda della sala dopo «Genera» → resta da parte → «Mettila nel punto 4» → nel verbale stampato', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    // Il tablet in sala ha scritto alle 20:16 senza rete, con la seduta (non conosceva il verbale) e la sua scheda; la rete torna alle 21:10.
    $gesto = VerbaleDiViaRoma12::testo(0, $parte, tnpDoc('Dal tablet in sala: Rossi chiede tre preventivi.'), '2026-12-15 20:16', 0);
    $gesto['dati'] = ['seduta_id' => $seduta->id, 'parte' => $parte, 'documento' => $gesto['dati']['documento'], 'scheda' => 'tablet01'];
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:10');
    $r = SalaDiViaRoma12::manda($this, $s, $a, [$gesto])->assertOk()->json('risposte.0');
    expect($r['esito'])->toBe('in_conflitto')->and($r['messaggio'])->toContain('Mettila nel punto 4 del verbale');

    $attesa = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('attesa', VersioneDelVerbale::DOPO_LA_GENERAZIONE)->firstOrFail();
    $pagina = app(\App\Services\Assemblee\Verbale\LetturaDelVerbale::class)->verbale($verbale->refresh(), null);
    expect(collect($pagina['in_attesa'])->firstWhere('id', $attesa->id))->toMatchArray(['attesa' => 'dopo_la_generazione', 'nome_della_parte' => 'la discussione del punto 4']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.scegli', [$s->c, $a, $verbale, $attesa]), ['scelta' => 'metti'])->assertSessionHasNoErrors();
    $spia = cdsSpia();
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale->refresh(), true)->assertOk();
    expect(json_encode(collect($spia->stampe)->firstWhere('vista', 'pdf.gestionale.assemblea_verbale')['dati'] ?? [], JSON_UNESCAPED_UNICODE))
        ->toContain('Dal tablet in sala: Rossi chiede tre preventivi.');
});

it('la prima discussione si scrive con la seduta bloccata: due richieste insieme trovano lo stesso verbale', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $bloccata = false;
    DB::listen(function ($q) use (&$bloccata) {
        if (DB::transactionLevel() > 0 && preg_match('/from "assemblee_sedute" where "assemblee_sedute"\."id" = \?/', $q->sql) === 1) {
            $bloccata = true;
        }
    });
    $primo = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    expect($bloccata)->toBeTrue()
        ->and(VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin)->id)->toBe($primo->id)
        ->and(VerbaleDellAssemblea::query()->where('seduta_id', $seduta->id)->count())->toBe(1);
});
