<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use Illuminate\Validation\ValidationException;

/**
 * La struttura del testo del verbale (V1): i nodi della barra dell'editor e la parte «Dati della sala», nient'altro. Il file dei casi è in comune con
 * `resources/js/lib/assemblee/verbale/struttura.test.ts` (e la sua forma canonica, il testo semplice e l'impronta li ha scritti un terzo conto, indipendente):
 * le regole del server e quelle del browser sono le stesse, e i casi ostili (un'immagine, un attributo onclick, un collegamento, l'HTML al posto della
 * struttura) si rifiutano con la stessa frase.
 *
 * Che cosa non copre: la stampa del testo nel PDF (la prova la vista del verbale, con i casi «ostile» dello stesso file).
 */
function sdvCasi(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/verbale/struttura.json')), true, 512, JSON_THROW_ON_ERROR);
}

it('i limiti sono quelli del file dei casi', function () {
    $casi = sdvCasi();
    expect(StrutturaDelVerbale::BYTE_MASSIMI)->toBe($casi['limiti']['byte'])
        ->and(StrutturaDelVerbale::PROFONDITA_MASSIMA)->toBe($casi['limiti']['profondita'])
        ->and(StrutturaDelVerbale::NODI_MASSIMI)->toBe($casi['limiti']['nodi']);
});

it('ogni caso valido dà la sua forma canonica, il testo semplice, i caratteri e l\'impronta', function () {
    foreach (sdvCasi()['validi'] as $caso) {
        $documento = StrutturaDelVerbale::valida($caso['documento']);
        expect(StrutturaDelVerbale::canonico($documento))->toBe($caso['canonico'], $caso['nome'])
            ->and(StrutturaDelVerbale::testo($documento))->toBe($caso['testo'], $caso['nome'])
            ->and(StrutturaDelVerbale::caratteri($documento))->toBe($caso['caratteri'], $caso['nome'])
            ->and(StrutturaDelVerbale::impronta($documento))->toBe($caso['impronta'], $caso['nome'])
            // La forma canonica è un punto fermo: validata di nuovo resta la stessa.
            ->and(StrutturaDelVerbale::canonico(StrutturaDelVerbale::valida($documento)))->toBe($caso['canonico'], $caso['nome']);
    }
});

it('ogni caso rifiutato dà la sua frase, e i casi ostili ci sono tutti', function () {
    $nomi = [];
    foreach (sdvCasi()['rifiutati'] as $caso) {
        expect(StrutturaDelVerbale::errore($caso['documento']))->toBe($caso['errore'], $caso['nome']);
        try {
            StrutturaDelVerbale::valida($caso['documento']);
            $this->fail('Accettato: ' . $caso['nome']);
        } catch (ValidationException $e) {
            expect($e->errors()['documento'][0])->toBe($caso['errore']);
        }
        $nomi[] = $caso['nome'];
    }
    expect($nomi)->toContain('ostile: un\'immagine', 'ostile: un attributo onclick', 'ostile: un collegamento', 'ostile: l\'HTML al posto della struttura');
    expect(collect(sdvCasi()['validi'])->pluck('nome')->all())->toContain('ostile: <script> nel testo resta testo');
});

it('un testo oltre 1 MB si rifiuta', function () {
    $lungo = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', StrutturaDelVerbale::BYTE_MASSIMI)]]]]];
    expect(StrutturaDelVerbale::errore($lungo))->toBe('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
});

it('i mattoni del programma sono già nella forma canonica: il paragrafo, il titolo, la parte «Dati della sala»', function () {
    $doc = StrutturaDelVerbale::documento([
        StrutturaDelVerbale::titolo('4. Sostituzione del portone'),
        StrutturaDelVerbale::paragrafo('Il sig. Marino dichiara di essere il titolare della prima impresa.'),
        StrutturaDelVerbale::datiDellaSala('voto:7', ['Favorevoli: 9 condòmini per 506,40 millesimi.', 'Contrari: 2 condòmini per 14,60 millesimi.']),
        StrutturaDelVerbale::paragrafo(''),
    ]);
    expect(StrutturaDelVerbale::canonico(StrutturaDelVerbale::valida($doc)))->toBe(StrutturaDelVerbale::canonico($doc))
        ->and(StrutturaDelVerbale::testo($doc))->toBe("4. Sostituzione del portone\nIl sig. Marino dichiara di essere il titolare della prima impresa.\n"
            . "Favorevoli: 9 condòmini per 506,40 millesimi.\nContrari: 2 condòmini per 14,60 millesimi.\n")
        ->and(StrutturaDelVerbale::blocchi($doc))->toBe(['voto:7' => "Favorevoli: 9 condòmini per 506,40 millesimi.\nContrari: 2 condòmini per 14,60 millesimi."]);
});
