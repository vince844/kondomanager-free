<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§4.1 del progetto della tappa 2; decisione 492 (13); §13 rilievo 26; legge §12): il passaggio dell'Int. 7 da Gallo Enzo a Verdi Sara dopo
 * l'invio, dalla rotta vera. Con la decorrenza non dopo il giorno della riunione nasce il compito e la scheda mostra la nota arancio; con la decorrenza dopo
 * no; il passaggio annullato chiude il compito; la convocazione mandata a Verdi Sara lo chiude.
 *
 * Che cosa non copre: la successione con più eredi (stessa strada, un compito per erede); la seconda convocazione che rilegge l'elenco (tappa 3).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le raccomandate stampate delle persone date, registrate spedite: la prima spedizione registrata fa «convocata». @param list<string> $chi */
function convNuovoSpedisci($t, Beta51Scenario $s, $a, array $chi, string $giorno = '2026-12-02'): void
{
    $t->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => array_map(fn ($x) => ConvocazioneDiViaRoma12::invio($a, $s, $x)->id, $chi),
        'canale' => 'raccomandata', 'data' => $giorno])->assertSessionHasNoErrors();
}

function convCompitiDelNuovo(int $assemblea)
{
    return Evento::query()->where('meta->type', 'nuovo_titolare_non_convocato')->where('meta->context->assemblea_id', $assemblea)->orderBy('id')->get();
}

it('venduto dopo l\'invio con la decorrenza prima della riunione: il compito, la nota nella scheda; annullato il passaggio, il compito si chiude', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    // L'invio è la spedizione registrata: la stampa da sola non fa «convocata».
    convNuovoSpedisci($this, $s, $a, ['gallo']);
    $s->persona('verdi')->forceFill(['nome' => 'Verdi Sara', 'indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');

    $s->passaggio('vendita', '7', 'gallo', 'verdi', '2026-12-10');
    $c = convCompitiDelNuovo($a->id);
    expect($c)->toHaveCount(1)
        ->and($c[0]->title)->toBe('Verdi Sara (Int. 7) non ha ricevuto la convocazione del 15/12/2026, e all\'assemblea vota Verdi Sara');
    $nota = app(StatoDellaConvocazione::class)->perLaPagina($a)['venduti'][0];
    expect($nota['titolo'])->toBe('Dopo l\'invio, l\'Int. 7 è stato venduto.')
        ->and($nota['frase'])->toStartWith('Il passaggio da Gallo Enzo a Verdi Sara è registrato con decorrenza 10/12.');

    $s->annulla('7')->assertRedirect();
    expect($c[0]->refresh()->is_completed)->toBeTrue();
});

it('con la decorrenza dopo il giorno della riunione vota ancora chi esce: nessun compito; mandata la convocazione al nuovo, il compito si chiude', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    convNuovoSpedisci($this, $s, $a, ['gallo']);
    $s->persona('verdi')->forceFill(['nome' => 'Verdi Sara', 'indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $s->passaggio('vendita', '7', 'gallo', 'verdi', '2026-12-16');
    expect(convCompitiDelNuovo($a->id))->toHaveCount(0);

    $s->annulla('7');
    $s->passaggio('vendita', '7', 'gallo', 'verdi', '2026-12-09');
    expect(convCompitiDelNuovo($a->id)->where('is_completed', false))->toHaveCount(1);
    // La lettera stampata non basta: il compito si chiude quando la sua raccomandata è registrata spedita.
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf', 'anagrafiche' => [$s->persone['verdi']->id]])->assertOk();
    expect(convCompitiDelNuovo($a->id)->where('is_completed', false))->toHaveCount(1);
    convNuovoSpedisci($this, $s, $a, ['verdi'], '2026-12-10');
    expect(convCompitiDelNuovo($a->id)->where('is_completed', false))->toHaveCount(0);
});
