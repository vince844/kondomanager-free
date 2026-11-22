<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\RegistroModifica;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le versioni del verbale (V5, §0.4 (2) del progetto): una dopo due minuti senza scrivere (il testo com'era prima della pausa, con l'ora in cui era stato
 * scritto: la riga del registro ha l'ora del gesto e l'ora di arrivo), e a ogni gesto importante (generato, rigenerato, chiusura della sala, ripristino,
 * scelta fra due versioni); «Ripristina» è una versione, e il testo di adesso resta; registrato il verbale «Ripristina» non c'è più; «Tieni questa»,
 * «Tieni quella», «Uniscile» e «Ho visto».
 *
 * Che cosa non copre: il confronto affiancato a video (la pagina del verbale).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function vdvDoc(string $testo): array
{
    return StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($testo)]);
}

it('dopo due minuti senza scrivere il testo di prima è una versione; la riga del registro ha l\'ora del gesto e l\'ora di arrivo', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:31');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', vdvDoc('Correzioni al punto 4, prima stesura.'), '2026-12-15 21:14', 1)]);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', vdvDoc('Correzioni al punto 4, ancora.'), '2026-12-15 21:15', 2)]);
    expect(VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->count())->toBe(1);
    $prima = (int) RegistroModifica::query()->max('id');

    // Dopo una pausa di cinque minuti: la stesura delle 21:15 diventa la versione 2.
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', vdvDoc('Dopo la pausa.'), '2026-12-15 21:20', 3)]);

    $v = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->orderByDesc('numero')->firstOrFail();
    $riga = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'verbale')->firstOrFail();
    expect($v->numero)->toBe(2)->and($v->perche)->toBe('correzioni')->and(StrutturaDelVerbale::testo((array) $v->documento))->toBe('Correzioni al punto 4, ancora.')
        ->and($riga->riepilogo)->toContain('versione 2, correzioni')
        ->and($riga->avvenuta_il->copy()->setTimezone('Europe/Rome')->format('H:i'))->toBe('21:15')
        ->and($riga->registrata_il->copy()->setTimezone('Europe/Rome')->format('H:i'))->toBe('21:31');
});

it('«Ripristina» rimette il testo di una versione come versione nuova, e il testo di adesso resta fra le versioni; registrato, non c\'è più', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $generato = StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento'));
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', vdvDoc('Tutto cancellato per sbaglio.'), '2026-12-15 20:59', 1)]);
    $uno = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('numero', 1)->firstOrFail();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.ripristina', [$s->c, $a, $verbale, $uno->id]))->assertSessionHasNoErrors();

    $perche = VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->orderBy('numero')->pluck('perche')->all();
    expect(StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento')))->toBe($generato)
        ->and($perche)->toBe(['generato dal modello «Testo del programma»', 'il testo prima del ripristino', 'ripristino della versione 1']);

    VerbaleDiViaRoma12::registra($this, $s, $verbale->refresh())->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.ripristina', [$s->c, $a, $verbale, $uno->id]))
        ->assertSessionHasErrors(['versione' => 'Il verbale è registrato: il testo è quello firmato, e «Ripristina» non c\'è più.']);
    expect(collect(app(\App\Services\Assemblee\Verbale\VersioniDelVerbale::class)->elenco($verbale->refresh()))->pluck('ripristina')->unique()->all())->toBe([false]);
});

it('le due versioni: «Tieni questa» mette il testo arrivato e tiene quello di prima; «Tieni quella» lascia il testo; «Uniscile» scrive il testo unito', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    $conflitto = function (string $testo) use ($s, $a, $verbale, $parte) {
        $rev = (int) (ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->value('revisione') ?? 0);
        VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, vdvDoc('Dal portatile ' . $testo), '2026-12-15 20:14', $rev)], 'portatile');

        return VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, vdvDoc('Dal tablet ' . $testo), '2026-12-15 20:15', $rev)], 'tablet')
            ->json('risposte.0.versione_id');
    };
    $testoDiAdesso = fn () => StrutturaDelVerbale::testo((array) ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', $parte)->value('documento'));
    $scegli = fn (int $id, string $scelta, ?array $doc = null) => $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.scegli',
        [$s->c, $a, $verbale, $id]), ['scelta' => $scelta, 'documento' => $doc]);

    $scegli($conflitto('uno'), 'questa')->assertSessionHasNoErrors();
    expect($testoDiAdesso())->toBe('Dal tablet uno');
    $scegli($conflitto('due'), 'quella')->assertSessionHasNoErrors();
    expect($testoDiAdesso())->toBe('Dal portatile due');
    $scegli($conflitto('tre'), 'uniscile', vdvDoc('Dal portatile tre e dal tablet tre'))->assertSessionHasNoErrors();
    expect($testoDiAdesso())->toBe('Dal portatile tre e dal tablet tre')
        ->and(VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('in_attesa', true)->whereNull('scelta_il')->count())->toBe(0)
        ->and(VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->whereNotNull('scelta')->pluck('scelta')->all())->toBe(['questa', 'quella', 'uniscile']);
});

it('la sala che chiude la riunione fa delle discussioni una versione «scritta in sala», in una riga sola', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    foreach ([3, 4] as $n) {
        VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'punto:' . VerbaleDiViaRoma12::punto($a, $n), vdvDoc('Discussione ' . $n), '2026-12-15 20:30', 0)]);
    }
    $prima = (int) RegistroModifica::query()->max('id');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));

    expect(VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->pluck('perche')->all())
        ->toBe(['scritta in sala: la discussione del punto 3', 'scritta in sala: la discussione del punto 4'])
        ->and(RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'verbale')->pluck('riepilogo')->all())
        ->toBe(['discussioni scritte in sala: i punti 3 e 4']);
});
