<?php

/**
 * Coda 456 (decisione 183.4) — le note della 1.11.0 non danno «Allineato» come uno degli stati che la pagina del piano scrive.
 *
 * Un piano accettato con «Procedi comunque» perché una quota non aveva un destinatario porta nella pagina del piano il badge «Quota aperta»
 * (con la cifra, quando c'è) finché resta com'è stato accettato, e «Disallineato» se poi cambia; quando è allineato la pagina non scrive niente
 * (`PianiRateShow.vue`, il badge di «Copertura spese»). Le note dicevano che il piano «ha tre stati, «Allineato», «Quota aperta» e
 * «Disallineato»»: «Allineato» è l'etichetta di un'altra cosa, la copertura del cruscotto. Si corregge il file della 1.11.0, in tre lingue,
 * e la voce della beta.59 del changelog pubblico, senza errata (la nota della release dice già la frase giusta).
 *
 * Il primo test prova il fatto su cui la frase poggia, leggendo la pagina; gli altri fissano la frase nei quattro posti.
 */

/** La voce del piano rate delle note di una lingua: quella che nomina «Procedi comunque». */
function vocePianoRateDelleNoteDellaStabile(string $lingua): string
{
    $voci = json_decode(
        file_get_contents(resource_path("data/changelogs/{$lingua}/1.11.0.json")),
        true, 512, JSON_THROW_ON_ERROR
    )['features'];

    $trovate = array_values(array_filter($voci, fn (string $voce) => str_contains($voce, 'Procedi comunque') && str_contains($voce, 'Quota aperta')));
    expect($trovate)->toHaveCount(1);

    return $trovate[0];
}

it('Coda 456 — la pagina del piano scrive «Quota aperta» e «Disallineato», e «Allineato» non lo scrive mai', function () {
    $pagina = file_get_contents(resource_path('js/pages/gestionale/pianiRate/PianiRateShow.vue'));
    $etichette = file_get_contents(resource_path('js/lib/gestionale/pianiRate/quoteNonAssegnate.ts'));

    expect($pagina)->toContain('Disallineato')
        ->and($etichette)->toContain('Quota aperta')
        // «Allineato» con la maiuscola è l'etichetta del cruscotto (`Dashboard.vue`), non della pagina del piano.
        ->and($pagina)->not->toContain('Allineato')
        ->and(file_get_contents(resource_path('js/pages/gestionale/dashboard/Dashboard.vue')))->toContain('Allineato');
});

it('Coda 456 — le note della 1.11.0 dicono che il piano segna «Quota aperta» o «Disallineato», non che ha tre stati', function (string $lingua, array $dice, array $nonDice) {
    $voce = vocePianoRateDelleNoteDellaStabile($lingua);

    foreach ($dice as $frase) {
        expect($voce)->toContain($frase);
    }
    foreach ($nonDice as $frase) {
        expect(mb_strtolower($voce))->not->toContain(mb_strtolower($frase));
    }
})->with([
    'italiano' => ['it', [
        'segna «Quota aperta», con la cifra, quando c\'è, finché resta com\'è stato accettato, e «Disallineato» se poi cambia; un riquadro in cima alla pagina elenca le quote non assegnate raggruppate per causa',
    ], ['tre stati', '«Allineato»']],
    'inglese' => ['en', [
        'is marked «Quota aperta» (open share), with the amount, when there is one, as long as it stays as it was accepted, and «Disallineato» (misaligned) if it then changes; a box at the top of the page lists the unassigned shares grouped by cause',
    ], ['three states', '«Allineato»']],
    'portoghese' => ['pt', [
        'fica marcado «Quota aperta» (quota em aberto), com o valor, quando há, enquanto estiver como foi aceite, e «Disallineato» (desalinhado) se depois mudar; uma caixa no topo da página lista as quotas não atribuídas agrupadas por causa',
    ], ['três estados', '«Allineato»']],
]);

it('Coda 456 — il resto della voce non cambia: il piano accettato prima dell\'aggiornamento resta «Disallineato», e il «Ricalcola» lo rifà', function (string $lingua, string $frase) {
    expect(vocePianoRateDelleNoteDellaStabile($lingua))->toContain($frase);
})->with([
    'italiano' => ['it', 'Un piano accettato così prima dell\'aggiornamento non ha questo elenco e resta «Disallineato»: «Ricalcola», dove il piano si può ricalcolare, lo rifà con i dati di adesso.'],
    'inglese' => ['en', 'A plan accepted this way before the update does not have this list and stays «Disallineato»: «Ricalcola» (recalculate), where the plan can be recalculated, rebuilds it with today\'s data.'],
    'portoghese' => ['pt', 'Um plano aceite assim antes da atualização não tem esta lista e fica «Disallineato»: «Ricalcola» (recalcular), onde o plano se pode recalcular, refá-la com os dados de agora.'],
]);

it('Coda 456 — le tre lingue della 1.11.0 hanno ancora le stesse venti voci', function () {
    $conteggi = collect(['it', 'en', 'pt'])->map(fn (string $lingua) => count(
        json_decode(file_get_contents(resource_path("data/changelogs/{$lingua}/1.11.0.json")), true, 512, JSON_THROW_ON_ERROR)['features']
    ))->unique()->all();

    expect($conteggi)->toBe([20]);
});

it('Coda 456 — il changelog pubblico, alla voce della beta.59, non dà più tre stati', function () {
    $changelog = file_get_contents(base_path('docs/changelog.md'));
    $inizio = strpos($changelog, '## [1.11.0-beta.59]');
    $fine = strpos($changelog, '## [1.11.0-beta.58]');
    expect($inizio)->not->toBeFalse()->and($fine)->toBeGreaterThan($inizio);

    $voce = preg_replace('/\s+/', ' ', substr($changelog, $inizio, $fine - $inizio));

    expect($voce)
        ->toContain('**Il piano rate con una quota senza destinatario** segna «Quota aperta», con la cifra, quando c\'è, finché resta com\'è stato accettato, e «Disallineato» se poi cambia; un riquadro elenca le quote una per una, con il perché e che cosa fare.')
        ->not->toContain('ha tre stati')
        ->not->toContain('«Allineato»');
});
