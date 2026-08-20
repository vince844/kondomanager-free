<?php

/**
 * Nessuna schermata congela una traduzione in una costante valutata all'avvio.
 *
 * ## Il difetto che questo test esiste per prendere
 *
 * Le traduzioni che vivono nei file PHP (`lang/it/*.php`) arrivano al browser in un pacchetto
 * **caricato in modo asincrono**. Una `const` scritta al livello del modulo — cioè direttamente
 * dentro `<script setup>` — è valutata **una volta sola, all'avvio del componente**, e può girare
 * prima che quel pacchetto sia arrivato. Le stesse chiamate `trans()` scritte nel template
 * funzionano, perché il template si ridisegna quando le traduzioni arrivano.
 *
 * ⚠️ **E il ripiego non serve a niente.** La forma che il progetto usava —
 * `trans('impostazioni.label.settings') || 'Impostazioni'` — sembra una rete e non lo è:
 * `trans()` su una chiave non ancora caricata restituisce **la chiave stessa**, che in JavaScript è
 * una stringa non vuota, quindi `truthy`. Il secondo ramo non viene preso mai.
 *
 * Il risultato a video è il nome tecnico della traduzione stampato in pagina:
 * `IMPOSTAZIONI.LABEL.SETTINGS › IMPOSTAZIONI.DIALOGS.PRINT_SETTINGS_TITLE` al posto della briciola.
 *
 * ## Come si è arrivati qui
 *
 * Trovato guardando a video la beta.60 — correggendo il testo d'aiuto della firma di stampa, che
 * aveva **lo stesso difetto** (coda ㊽), lo screenshot ha mostrato la stessa cosa due righe più su.
 * Poi **Vincenzo l'ha notato per conto suo**, su un'altra pagina, il che ha fatto cadere la
 * decisione di rimandarlo.
 *
 * ## Il rimedio, che era già in casa
 *
 * `computed(() => [...])` si rivaluta quando le traduzioni arrivano. Non è un'ipotesi: cinque
 * schermate lo usavano già **con le stesse chiavi** che altrove arrivavano tarde, ed erano pulite.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre le `const` dichiarate **dentro una funzione**: quelle sono valutate quando la funzione
 * viene chiamata, cioè a traduzioni caricate. Il controllo guarda solo il livello del modulo, ed è
 * per questo che cerca le righe che cominciano a colonna zero.
 *
 * Non copre i template: lì `trans()` funziona per costruzione.
 */

use Illuminate\Support\Facades\File;

/**
 * Le costanti dichiarate al **livello del modulo**, col loro valore.
 *
 * ⚠️ Il valore si ritaglia **bilanciando le parentesi**, non con un'espressione regolare fino alla
 * `const` successiva: la prima stesura di questa guardia usava quella forma e segnalava **85** punti
 * invece di **19**, perché si portava dietro tutto il codice fra una costante e l'altra. (Il «27» che
 * questa riga dichiarava fino al 19/08 non è mai stato misurato: rieseguendo la logica vera sull'albero
 * della .59 le costanti congelate erano 19 — 18 briciole più l'elenco dei driver di posta.) Una guardia che
 * grida troppo si spegne, ed è peggio di una che non c'è.
 *
 * Le `const` **dentro una funzione** sono indentate e non vengono raccolte: quelle sono valutate
 * alla chiamata, cioè a traduzioni già arrivate.
 *
 * @return list<array{0: string, 1: string, 2: int}> nome, valore, riga
 */
function costantiDiModulo(string $sorgente): array
{
    $costanti = [];

    if (! preg_match_all('/^const (\w+)\s*(?::[^=\n]+)?=\s*/m', $sorgente, $trovate, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($trovate as $t) {
        $nome = $t[1][0];
        $da = $t[0][1] + strlen($t[0][0]);
        $riga = substr_count(substr($sorgente, 0, $t[0][1]), "\n") + 1;

        $profondita = 0;
        $fine = $da;
        $lunghezza = strlen($sorgente);

        for ($i = $da; $i < $lunghezza; $i++) {
            $c = $sorgente[$i];

            if (in_array($c, ['[', '{', '('], true)) {
                $profondita++;
            } elseif (in_array($c, [']', '}', ')'], true)) {
                $profondita--;

                if ($profondita <= 0) {
                    $fine = $i + 1;
                    break;
                }
            } elseif ($profondita === 0 && ($c === "\n" || $c === ';')) {
                // Una costante su una riga sola, senza parentesi: finisce lì.
                $fine = $i;
                break;
            }
        }

        $costanti[] = [$nome, substr($sorgente, $da, $fine - $da), $riga];
    }

    return $costanti;
}

/**
 * Le costanti di modulo che chiamano `trans()`.
 *
 * @return list<string> «percorso:riga → const nome»
 */
function traduzioniCongelate(): array
{
    $trovate = [];

    $file = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($f) => in_array($f->getExtension(), ['vue', 'ts'], true));

    foreach ($file as $f) {
        $sorgente = $f->getContents();

        // Solo il blocco `<script setup>`: nel template `trans()` funziona sempre.
        if (str_contains($sorgente, '<template>')) {
            $sorgente = explode('<template>', $sorgente)[0];
        }

        foreach (costantiDiModulo($sorgente) as [$nome, $corpo, $riga]) {
            if (! str_contains($corpo, 'trans(')) {
                continue;
            }

            // ⚠️ `computed(...)` e le funzioni sono **il rimedio**, non il difetto: si rivalutano
            // quando le traduzioni arrivano. Segnalarli sarebbe segnalare la soluzione.
            if (preg_match('/^\s*(computed|ref|\(|function|async|.*=>)/', $corpo)) {
                continue;
            }

            $trovate[] = str_replace(resource_path('js').'/', '', $f->getPathname()).':'.$riga.' → const '.$nome;
        }
    }

    sort($trovate);

    return $trovate;
}

it('nessuna costante di modulo congela una traduzione', function () {
    expect(traduzioniCongelate())->toBe([],
        'queste costanti chiamano trans() all\'avvio del componente, cioè prima che le traduzioni '
        .'siano arrivate: il risultato a video è il nome tecnico della chiave stampato in pagina. '
        .'Si avvolgono in computed(() => …), che si rivaluta quando le traduzioni arrivano');
});

it('la guardia riconosce la forma rotta e lascia stare quella buona', function () {
    // ⚠️ Una guardia che passa perché non trova niente è indistinguibile da una rotta. Qui si prova
    // il riconoscitore sulle due forme vere, quella che il progetto aveva e quella che ha adesso.
    $rotta = <<<'JS'
const breadcrumbs: BreadcrumbItem[] = [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
]
JS;

    $buona = <<<'JS'
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
])
JS;

    $dentroUnaFunzione = <<<'JS'
function apri() {
  const titolo = [trans('impostazioni.label.settings')]
  return titolo
}
JS;

    // ⚠️ Usa la **funzione vera**, non una copia della sua espressione regolare: una guardia provata
    // su una copia prova la copia.
    $riconosce = function (string $sorgente): bool {
        foreach (costantiDiModulo($sorgente) as [$nome, $corpo, $riga]) {
            if (str_contains($corpo, 'trans(') && ! preg_match('/^\s*(computed|ref|\(|function|async|.*=>)/', $corpo)) {
                return true;
            }
        }

        return false;
    };

    expect($riconosce($rotta))->toBeTrue('la forma rotta non viene riconosciuta')
        ->and($riconosce($buona))->toBeFalse('`computed` viene segnalato per sbaglio')
        ->and($riconosce($dentroUnaFunzione))->toBeFalse('una const dentro una funzione non è congelata');
});

it('nessuna schermata usa più il ripiego che non scatta mai', function () {
    // `trans(...) || 'Qualcosa'` sembra una rete e non lo è: `trans()` su una chiave mancante
    // restituisce la chiave, che in JavaScript è truthy. Il ripiego dà l'illusione di una difesa e
    // nasconde il difetto a chi legge il codice — che è peggio del difetto.
    $illusori = [];

    $file = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($f) => in_array($f->getExtension(), ['vue', 'ts'], true));

    foreach ($file as $f) {
        foreach (explode("\n", $f->getContents()) as $i => $riga) {
            if (preg_match("/trans\([^)]*\)\s*\|\|/", $riga)) {
                $illusori[] = str_replace(resource_path('js').'/', '', $f->getPathname()).':'.($i + 1);
            }
        }
    }

    sort($illusori);

    expect($illusori)->toBe([],
        'il ripiego `trans(...) || "…"` non scatta mai, perché trans() su una chiave mancante '
        .'restituisce la chiave e in JavaScript è truthy: va tolto, non corretto');
});
