<?php

/**
 * 1.11.0-beta.53 (decisione 100, punto 3) — la preposizione «a» davanti a un nome o a un articolo.
 *
 * `FrasiObbligati::a()` è l'unica copia della regola: «ad» davanti a un nome che comincia per A, «a» davanti a ogni altra iniziale, e davanti a un
 * articolo in minuscolo (i ripieghi senza nome: «il nudo proprietario», «l'erede di riferimento») la preposizione articolata: «al», «all'», «alla»,
 * «allo», «ai», «agli», «alle». Un cognome che comincia come un articolo («Lo Presti», «La Rosa», «Il Girasole») è un nome, e resta «a Lo Presti».
 *
 * La frase delle quote ferme dell'emissione («Il credito resta ad Anna, da usare o rimborsare») e quella del piano da ricalcolare («lasciano quelle
 * quote ad Aldo Venditore», in `StatoDelPianoDopoPassaggioTest`) usano la stessa funzione.
 *
 * Fonte dell'atteso: l'italiano scritto a mano nel test.
 *
 * Che cosa NON copre: la regola nel frontend (`preposizione.test.ts`).
 */

use App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController;
use App\Services\Subentro\FrasiObbligati;

it('la preposizione davanti a un nome: «ad» davanti alla A, «a» davanti alle altre iniziali', function (string $nome, string $atteso) {
    expect(FrasiObbligati::a($nome))->toBe($atteso);
})->with([
    ['Anna', 'ad Anna'], ['Àlex', 'ad Àlex'], ['aldo', 'ad aldo'], ['Ugo', 'a Ugo'], ['Elsa', 'a Elsa'], ['chi esce', 'a chi esce'], ['altri', 'ad altri'],
    ['Anna e Bruno', 'ad Anna e Bruno'], ['Bruno e Anna', 'a Bruno e Anna'],
]);

it('la preposizione davanti a un articolo: la preposizione articolata, e un cognome come «Lo Presti» resta un nome', function (string $nome, string $atteso) {
    expect(FrasiObbligati::a($nome))->toBe($atteso);
})->with([
    ['il nudo proprietario', 'al nudo proprietario'], ['l\'erede di riferimento', 'all\'erede di riferimento'], ['lo zio di Anna', 'allo zio di Anna'],
    ['la figlia', 'alla figlia'], ['i nudi proprietari', 'ai nudi proprietari'], ['gli eredi', 'agli eredi'], ['le parti', 'alle parti'],
    ['Lo Presti', 'a Lo Presti'], ['La Rosa', 'a La Rosa'], ['Il Girasole', 'a Il Girasole'], ['Ilaria', 'a Ilaria'], ['Lia', 'a Lia'], ['lia', 'a lia'],
]);

it('il ripiego senza nome di una frase dei passaggi non dice «a il» né «a l\'»', function () {
    expect(FrasiObbligati::a('il nudo proprietario'))->not->toContain('a il ')
        ->and(FrasiObbligati::a('l\'erede di riferimento'))->not->toContain('a l\'');
});

it('le quote ferme dell\'emissione: «Il credito resta ad Anna», «a Ugo e Rita», «ad Anna e Bruno»', function (array $nomi, string $atteso) {
    $quote = array_map(fn (string $nome) => ['nome' => $nome, 'rata' => 1, 'zero' => false], $nomi);
    $frase = (new ReflectionMethod(EmissioneRateController::class, 'fraseQuoteFerme'))->invoke(null, $quote);

    expect($frase)->toContain($atteso);
})->with([
    [['Anna'], 'Il credito resta ad Anna, da usare o rimborsare.'],
    [['Ugo', 'Rita'], 'Il credito resta a Ugo e Rita, da usare o rimborsare.'],
    [['Anna', 'Bruno'], 'Il credito resta ad Anna e Bruno, da usare o rimborsare.'],
]);
