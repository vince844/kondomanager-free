<?php

use App\Support\OrdinamentoConti;

/** Oggetto minimo con i soli campi che il comparatore guarda. */
function voce(?string $codice, string $nome): object
{
    return (object) ['codice' => $codice, 'nome' => $nome];
}

function nomiOrdinati(array $voci, string $criterio): array
{
    return OrdinamentoConti::applica($voci, $criterio)->pluck('nome')->all();
}

test('per nome ordina alfabeticamente ignorando il codice', function () {
    $voci = [voce('Z.1', 'Assicurazione'), voce('A.1', 'Pulizie'), voce(null, 'Manutenzione')];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_NOME))
        ->toBe(['Assicurazione', 'Manutenzione', 'Pulizie']);
});

test('per codice ordina sul codice, non sul nome', function () {
    $voci = [voce('C.1', 'Assicurazione'), voce('A.1', 'Pulizie'), voce('B.1', 'Manutenzione')];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_CODICE))
        ->toBe(['Pulizie', 'Manutenzione', 'Assicurazione']);
});

test('il confronto è naturale: A.2 viene prima di A.10', function () {
    // Con l'ordinamento alfabetico puro "A.10" precederebbe "A.2" — il difetto che
    // fa sembrare rotta la funzione a chi usa codici numerati.
    $voci = [voce('A.10', 'Decima'), voce('A.2', 'Seconda'), voce('A.1', 'Prima')];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_CODICE))
        ->toBe(['Prima', 'Seconda', 'Decima']);
});

test('il confronto naturale vale anche sui codici puramente numerici', function () {
    $voci = [voce('1020', 'Mille venti'), voce('999', 'Novecento'), voce('101', 'Centouno')];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_CODICE))
        ->toBe(['Centouno', 'Novecento', 'Mille venti']);
});

test('le voci senza codice finiscono in fondo, ordinate per nome fra loro', function () {
    $voci = [
        voce(null, 'Zeta senza codice'),
        voce('B.1', 'Con codice B'),
        voce('', 'Alfa senza codice'),   // stringa vuota = come assente
        voce('A.1', 'Con codice A'),
        voce('   ', 'Beta con soli spazi'), // spazi = come assente
    ];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_CODICE))->toBe([
        'Con codice A',
        'Con codice B',
        'Alfa senza codice',
        'Beta con soli spazi',
        'Zeta senza codice',
    ]);
});

test('un criterio sconosciuto ricade sul nome invece di produrre un ordine arbitrario', function () {
    // Il criterio arriva da query string e da localStorage: entrambi manomettibili.
    $voci = [voce('Z.1', 'Assicurazione'), voce('A.1', 'Pulizie')];

    expect(nomiOrdinati($voci, 'colonna_inesistente'))->toBe(['Assicurazione', 'Pulizie'])
        ->and(OrdinamentoConti::criterioValido('codice'))->toBe('codice')
        ->and(OrdinamentoConti::criterioValido(null))->toBe('nome')
        ->and(OrdinamentoConti::criterioValido('DROP TABLE'))->toBe('nome');
});

test('con tutti i codici assenti l ordinamento per codice degrada a quello per nome', function () {
    // Il caso della maggioranza dei condomini: nessun codice compilato. L'elenco non
    // deve diventare arbitrario solo perché è stato scelto "codice".
    $voci = [voce(null, 'Gamma'), voce(null, 'Alfa'), voce(null, 'Beta')];

    expect(nomiOrdinati($voci, OrdinamentoConti::PER_CODICE))->toBe(['Alfa', 'Beta', 'Gamma']);
});
