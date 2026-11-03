<?php

/**
 * 1.11.0-beta.54 — la parola «gestione» davanti al nome di una gestione.
 *
 * «Gestione ordinaria» è il nome che il programma dà alla gestione di base di ogni condominio nuovo, e una frase che scrive «sulla gestione» davanti
 * al nome diceva «sulla gestione Gestione ordinaria». `FrasiObbligati::gestione()` è l'unica copia della regola, e ogni frase dei passaggi la chiama
 * dove nomina una gestione: il nome si scrive con la parola davanti, a meno che non cominci già per quella parola, intera e in qualunque maiuscola.
 * Il nome tiene la sua maiuscola: è un nome proprio.
 *
 * Fonte dell'atteso: l'italiano scritto a mano nel test.
 *
 * Che cosa NON copre: le frasi intere (`Beta54FraseDellaGestioneTest`, dalle rotte) né che nessun punto del prodotto componga la frase per conto suo
 * (`FraseDellaGestioneTest`, la guardia sul sorgente).
 */

use App\Services\Subentro\FrasiObbligati;

it('il nome senza la parola davanti la riceve', function (string $nome, string $atteso) {
    expect(FrasiObbligati::gestione($nome))->toBe($atteso);
})->with([
    ['Ordinaria 2026', 'gestione Ordinaria 2026'],
    ['Straordinaria tetto', 'gestione Straordinaria tetto'],
    ['Riscaldamento', 'gestione Riscaldamento'],
]);

it('il nome che comincia già per la parola «gestione» resta com\'è, con la sua maiuscola', function (string $nome, string $atteso) {
    expect(FrasiObbligati::gestione($nome))->toBe($atteso);
})->with([
    ['Gestione ordinaria', 'Gestione ordinaria'],
    ['Gestione Ordinaria', 'Gestione Ordinaria'],
    ['gestione riscaldamento 2026', 'gestione riscaldamento 2026'],
    ['GESTIONE 2026', 'GESTIONE 2026'],
    ['Gestione', 'Gestione'],
]);

it('«Gestioni» e «Gestionale» non sono la parola intera, e il nome riceve la parola davanti', function (string $nome, string $atteso) {
    expect(FrasiObbligati::gestione($nome))->toBe($atteso);
})->with([
    ['Gestioni 2026', 'gestione Gestioni 2026'],
    ['Gestionale 2026', 'gestione Gestionale 2026'],
    ['Autogestione', 'gestione Autogestione'],
]);

it('gli spazi ai lati del nome non contano', function (string $nome, string $atteso) {
    expect(FrasiObbligati::gestione($nome))->toBe($atteso);
})->with([
    [' Gestione ordinaria ', 'Gestione ordinaria'],
    ["  Ordinaria 2026\n", 'gestione Ordinaria 2026'],
]);

it('senza un nome la frase dice solo la parola', function (?string $nome) {
    expect(FrasiObbligati::gestione($nome))->toBe('gestione');
})->with([[null], [''], ['   ']]);

it('nella frase la parola non si ripete: «sulla gestione Ordinaria 2026», «sulla Gestione ordinaria»', function (string $nome, string $atteso) {
    expect(sprintf('sulla %s (Interno 1)', FrasiObbligati::gestione($nome)))->toBe($atteso);
})->with([
    ['Ordinaria 2026', 'sulla gestione Ordinaria 2026 (Interno 1)'],
    ['Gestione ordinaria', 'sulla Gestione ordinaria (Interno 1)'],
]);
