<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\RegistroDelPerimetro;
use App\Services\Assemblee\Verbale\LetturaDelVerbale;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Si registra il testo firmato (la catena «la bozza stampata e firmata → Registra → il confronto»):
 *
 * - la bozza stampata alle 21:02, poi una frase in più alle 21:30: «Registra» si ferma e dice di ristamparla, e la pagina dice «Cambiato dopo la stampa»;
 * - un voto corretto in sala dopo la bozza: «Registra» si ferma, e il conto dei trenta giorni non segue una versione che nessuno ha firmato;
 * - senza la bozza stampata, «Registra» chiede prima; la stampa chiede prima anche con la sala cambiata;
 * - il registro va solo avanti: dopo il n. 45 il 43 no, il 420 solo con la conferma del salto;
 * - la copia che non si apre come PDF (un `.docx.p7m`, un'immagine rovinata) non si registra;
 * - la prima registrazione di un registro nuovo fatta due volte insieme: la seconda ha la sua frase, non un errore 500.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il testo intero di adesso e la sua revisione. @return array{0: array<string, mixed>, 1: int} */
function rtfIntero($verbale): array
{
    $p = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();

    return [(array) $p->documento, (int) $p->revisione];
}

it('il testo cambiato dopo «Stampa la bozza» non si registra: «Registra» dice di ristamparla, e la pagina dice «Cambiato dopo la stampa»', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:02');
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale)->assertOk();
    $versione = (int) $verbale->refresh()->stampata['versione'];

    [$doc, $rev] = rtfIntero($verbale);
    $doc['content'][] = StrutturaDelVerbale::paragrafo('Frase aggiunta dopo la firma: il portone costa € 9.000,00.');
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:30');
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'intero', $doc, '2026-12-15 21:30', $rev)])->assertOk();

    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasErrors(['bozza' => 'Il testo è cambiato dopo la bozza stampata alle 21:02 (versione '
        . $versione . '): ristampala e falla firmare, o ripristina la versione ' . $versione . '.']);
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA)->and($verbale->numero)->toBeNull();
    $pagina = app(LetturaDelVerbale::class)->verbale($verbale, null);
    expect($pagina['stampata']['cambiata'])->toStartWith('Il testo è cambiato dopo la bozza stampata alle 21:02');

    // Ristampata, si registra.
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale->refresh())->assertOk();
    VerbaleDiViaRoma12::registra($this, $s, $verbale->refresh())->assertSessionHasNoErrors();
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::REGISTRATO)->and($verbale->numero)->toBe(42);
});

it('un voto corretto in sala dopo la bozza stampata ferma «Registra»: il registro non tiene un voto che la copia firmata non dice', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:02');
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale)->assertOk();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:20');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v[4], 'testa' => VerbaleDiViaRoma12::testa($v[4], 'Colombo Rita'),
        'voto' => 'favorevole', 'perche' => 'letta male'], '2026-12-15 21:20', 1));
    VerbaleDiViaRoma12::conferma($this, $s, $a, $v[4], '2026-12-15 21:21');

    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $versione = (int) $verbale->refresh()->stampata['versione'];
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasErrors(['bozza' => 'I dati della sala sono cambiati dopo la bozza stampata alle 21:02 (versione '
        . $versione . '): aggiorna le parti segnate, ristampa la bozza e falla firmare.']);
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA);
});

it('senza la bozza stampata «Registra» chiede prima; «Stampa la bozza» chiede prima anche con la sala cambiata', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['senza_la_bozza' => false])
        ->assertSessionHasErrors(['senza_la_bozza' => \App\Services\Assemblee\Verbale\RegistrazioneDelVerbale::SENZA_LA_BOZZA]);

    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:20');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $v[4], 'su' => 'non_votato',
        'testo' => 'Russo Marco era uscito un momento, ed è rientrato dopo il voto.'], '2026-12-15 21:20'));
    // Una nota del presidente cambiata dopo la generazione: la sala è cambiata, anche se nessuna cifra cambia.
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale->refresh())->assertSessionHasErrors(['cifre']);
    expect($verbale->refresh()->stampata)->toBeNull();
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasErrors(['bozza']);
    VerbaleDiViaRoma12::stampaLaBozza($this, $s, $verbale, true)->assertOk();
    VerbaleDiViaRoma12::registra($this, $s, $verbale->refresh())->assertSessionHasNoErrors();
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::REGISTRATO);
});

it('il registro va solo avanti: dopo il n. 45 il 43 no; il 420 chiede la conferma del salto; un numero annullato non torna', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['numero' => 45])
        ->assertSessionHasErrors(['numero' => 'Salti i numeri 42–44? Il registro va solo avanti: un numero saltato non torna. Conferma il salto, o usa il 42.']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['numero' => 45, 'salto' => true])->assertSessionHasNoErrors();
    $r = app(RegistroDeiVerbali::class);
    expect($r->numeroUsato($s->c, 'condominio', 43))->toBe('Il registro va solo avanti: il prossimo è il 46.')
        ->and($r->numeroUsato($s->c, 'condominio', 46))->toBeNull()
        ->and(RegistroDeiVerbali::fraseDelSalto(46, 420))->toBe('Salti i numeri 46–419? Il registro va solo avanti: un numero saltato non torna. Conferma il salto, o usa il 46.')
        ->and(RegistroDeiVerbali::fraseDelSalto(46, 47))->toStartWith('Salti il numero 46?');
});

it('la copia che non si apre come PDF non si registra: il .docx.p7m e l\'immagine rovinata', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $docx = UploadedFile::fake()->createWithContent('verbale.docx.p7m', VerbaleDiViaRoma12::p7m("PK\x03\x04 un documento di Word"));
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => $docx])
        ->assertSessionHasErrors(['copia' => 'La copia firmata non contiene un PDF che si apre: carica la scansione, il PDF firmato o il suo .p7m.']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => UploadedFile::fake()->createWithContent('scansione.jpg', 'non è un\'immagine')])
        ->assertSessionHasErrors(['copia']);
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => UploadedFile::fake()->image('scansione.jpg', 600, 800)])->assertSessionHasNoErrors();
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::REGISTRATO);
});

it('la prima registrazione di un registro nuovo, fatta due volte insieme: la seconda dice di riprovare, non un errore del server', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    // Un'altra registrazione crea la riga del registro proprio mentre questa la crea: l'indice del registro ferma la seconda.
    RegistroDelPerimetro::creating(function (RegistroDelPerimetro $r) {
        static $una = false;
        if (! $una) {
            $una = true;
            DB::table('assemblee_registri_verbali')->insert(['condominio_id' => $r->condominio_id, 'registro' => $r->registro, 'partenza' => 'libro',
                'ultimo_del_libro' => 41, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    VerbaleDiViaRoma12::registra($this, $s, $verbale)
        ->assertSessionHasErrors(['numero' => 'Il numero è stato preso intanto da un\'altra registrazione: riprova, il programma propone il prossimo.']);
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA);
});
