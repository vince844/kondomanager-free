<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Database di prova: un nome per checkout
|--------------------------------------------------------------------------
|
| Alcuni test del backup e del ripristino creano e distruggono database MySQL **veri**:
| `phpunit.xml` forza `sqlite :memory:` solo per la connessione predefinita, non per quelli.
| Finché i loro nomi erano costanti, lanciare la suite nei due checkout del progetto — TEST e
| ufficiale, che condividono lo stesso server MySQL — faceva sì che chi arrivava al `DROP` per
| primo sfilasse il database all'altra: `SQLSTATE[42000]: Unknown database`.
|
| Misurato il 05/09/2026 durante il port della beta.18: una prima coppia in parallelo ha dato
| `1 failed`, una seconda **12 falliti in TEST e 13 in ufficiale**, e la stessa suite da sola
| tornava verde. Un rosso che non significa niente costa quanto uno vero finché non hai capito
| che non significa niente — e nel mezzo si è tentati di dare la colpa al lavoro appena fatto.
|
| ⚠️ **Il suffisso viene dal PERCORSO del checkout, non dal processo.** `ParallelTesting::token()`
| è l'idioma già in casa ma isola i processi, e due checkout sono due percorsi, non due processi:
| da solo non chiuderebbe niente.
|
| ⚠️ **Serve `define()`, non `const`.** Una costante di file non ammette espressioni:
| `const X = 'x_' . md5(...)` dà «Constant expression contains invalid operations» — verificato
| su PHP 8.4.23. Il `defined()` davanti rende il file ricaricabile senza avvisi.
*/
function kmSuffissoDatabaseDiProva(): string
{
    // La radice del checkout: due cartelle sopra questo file. Tutti i test ne ricavano lo
    // stesso suffisso, così i database di un checkout si riconoscono a colpo d'occhio e
    // ripulire gli orfani di un'esecuzione interrotta è una query sola.
    return substr(md5(dirname(__DIR__)), 0, 8);
}

/** Nome di un database di prova, unico per checkout. */
function kmDatabaseDiProva(string $base): string
{
    return $base.'_'.kmSuffissoDatabaseDiProva();
}
