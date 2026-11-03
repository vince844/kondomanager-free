<?php

/**
 * 1.11.0-beta.55, Fase 2: le frasi di regola dei changelog dicono quello che il codice fa.
 *
 * Rileggendo ogni frase con un quantificatore contro la clausola del codice se ne sono trovate di più larghe del codice (casi 1–7, 10, 12 e 13
 * qui sotto). Questo file fissa le frasi corrette e vieta quelle di prima, nei tre changelog della versione
 * (italiano, inglese, portoghese) e nella voce pubblica di `docs/changelog.md`. Le clausole, in breve:
 *
 *  - chi può avere una riga in saldi è `RegolaDelPiano::scrivibili` (quote del piano sull'unità, o righe di coppie già scritte lì) più chi
 *    entra e il ripiego (`chiRiceve`): chi è entrato con un cambio d'inquilino e «Scrivi il conguaglio» non ha quote del piano e ha le righe
 *    del cambio, quindi riceve righe dalle fini dopo e la sua fine scrive la sua coppia (`dopoUnaFine`: `! isset($scrivibili[$chiEsce])`);
 *  - la fine di un altro conduttore scrive la differenza di ognuno quando tutti possono avere una riga, la sola differenza di chi esce quando
 *    qualcuno non è fra chi riceve o non può averla (`RegolaDelPiano::coppia`: `$fuori`);
 *  - la scelta si chiede se il passaggio ha coppie (`RegistraSubentroAction`), e quella dei giorni e quella del piano si uniscono;
 *  - il prospetto divide con la regola del piano la voce che un passaggio qualunque ha cambiato (`ProspettoRegolaDelPiano::cambiata`).
 *
 * Cosa NON copre: le guide in-app (`PassaggioProprietaGuide.test.ts`) e la voce interna.
 */

/** Le voci (`features`) del changelog della beta.55 in una lingua. */
function b55clVoci(string $lingua): array
{
    $json = json_decode(
        file_get_contents(resource_path("data/changelogs/{$lingua}/1.11.0-beta.55.json")),
        true, 512, JSON_THROW_ON_ERROR
    );

    return $json['features'];
}

/** La voce pubblica della beta.55 in `docs/changelog.md`, con gli spazi ridotti a uno (il file va a capo). */
function b55clVocePubblica(): string
{
    $testo = file_get_contents(base_path('docs/changelog.md'));
    preg_match('/## \[1\.11\.0-beta\.55\].*?\n---\n/s', $testo, $m);

    return preg_replace('/\s+/', ' ', $m[0] ?? '');
}

/**
 * @return array<string, array{0: string, 1: int, 2: list<string>, 3: list<string>}> lingua, numero della voce (da 1), frasi che ci devono essere, frasi che non ci devono più essere
 */
function b55clCorrezioni(): array
{
    return [
        // Caso 1: «senza quote del piano» non è «senza righe in saldi».
        'caso 1, it' => ['it', 3,
            ['Un conduttore entrato con l\'inizio di una locazione, o con un cambio d\'inquilino e «Non scriverlo», su un piano che non si ricalcola più non ha quote del piano né righe in saldi',
                'Chi è entrato con un cambio d\'inquilino e «Scrivi il conguaglio» ha le righe del cambio, e i passaggi dopo scrivono anche le sue.'],
            ['Un conduttore entrato su un piano che non si ricalcola più, senza quote del piano, non ha righe in saldi']],
        'caso 1, en' => ['en', 3,
            ['A tenant who entered with the start of a lease, or with a change of tenant and «Non scriverlo», on a plan that is no longer recalculated has no shares in the plan and no balance rows',
                'Whoever entered with a change of tenant and «Scrivi il conguaglio» has the rows of the change, and the transfers after it write theirs too.'],
            ['A tenant who entered on a plan that is no longer recalculated, and has no shares in the plan, has no balance rows']],
        'caso 1, pt' => ['pt', 3,
            ['Um inquilino que entrou com o início de um arrendamento, ou com uma troca de inquilino e «Non scriverlo», num plano que já não se recalcula não tem quotas do plano nem linhas de saldo',
                'Quem entrou com uma troca de inquilino e «Scrivi il conguaglio» tem as linhas da troca, e as passagens depois escrevem também as suas.'],
            ['Um inquilino que entrou num plano que já não se recalcula, sem quotas do plano, não tem linhas de saldo']],

        // Caso 2: la fine senza righe è quella di chi non ha né quote né righe.
        'caso 2, it' => ['it', 4,
            ['e la fine di un conduttore senza quote del piano né righe in saldi (entrato con un inizio di locazione, o con un cambio d\'inquilino e «Non scriverlo»), non scrivono niente',
                '(se una di quelle persone non ha né quote del piano né righe in saldi è solo un\'informazione, e si regola con il prospetto)'],
            ['e la fine di un conduttore senza quote del piano, non scrivono niente', 'se una di quelle persone non ha quote del piano è solo un\'informazione']],
        'caso 2, en' => ['en', 4,
            ['and the end of a tenant with no shares in the plan and no balance rows (who entered with the start of a lease, or with a change of tenant and «Non scriverlo»), write nothing',
                '(if one of those people has neither shares in the plan nor balance rows it is only information, and is settled with the statement)'],
            ['and the end of a tenant with no shares in the plan, write nothing', 'if one of those people has no shares in the plan it is only information']],
        'caso 2, pt' => ['pt', 4,
            ['e o fim de um inquilino sem quotas do plano nem linhas de saldo (que entrou com o início de um arrendamento, ou com uma troca de inquilino e «Non scriverlo»), não escrevem nada',
                '(se uma dessas pessoas não tem quotas do plano nem linhas de saldo é só uma informação, e acerta-se com o demonstrativo)'],
            ['e o fim de um inquilino sem quotas do plano, não escrevem nada', 'se uma dessas pessoas não tem quotas do plano é só uma informação']],

        // Caso 3: i passaggi già registrati non «si leggono come prima».
        'caso 3, it' => ['it', 1,
            ['quelli già registrati non le hanno, e dove servono il programma le ricava da ciò che il loro registro dice.'],
            ['si leggono come prima']],
        'caso 3, en' => ['en', 1,
            ['those already recorded do not have it, and where it is needed the program derives it from what their record says.'],
            ['are read as before']],
        'caso 3, pt' => ['pt', 1,
            ['as já registadas não a têm, e onde é precisa o programa deriva-a do que o seu registo diz.'],
            ['leem-se como antes']],

        // Caso 4: «a carico di» è chi ha i giorni nella sua riga; qui il programma dice «da regolare fra Ugo e Elsa».
        'caso 4, it' => ['it', 2,
            ['restano da regolare fra Ugo ed Elsa i giorni di Elsa fino al decesso (€ 13,81)'],
            ['restano a carico di Elsa i suoi giorni']],
        'caso 4, en' => ['en', 2,
            ['Elsa\'s days up to the death remain to be settled between Ugo and Elsa (€ 13.81)'],
            ['stay at Elsa\'s charge']],
        'caso 4, pt' => ['pt', 2,
            ['ficam por acertar entre Ugo e Elsa os dias de Elsa até à morte (€ 13,81)'],
            ['ficam a cargo de Elsa os seus dias']],

        // Caso 5: con una pertinenza a coppia dei giorni la coppia c'è anche se sull'appartamento la regola non cambia niente.
        'caso 5, it' => ['it', 3,
            ['se la regola non cambia niente, quell\'unità non ha coppia, e senza coppie non c\'è la scelta'],
            ['non c\'è coppia né scelta']],
        'caso 5, en' => ['en', 3,
            ['if the rule changes nothing, that unit has no pair, and with no pairs there is no choice'],
            ['there is no pair and no choice']],
        'caso 5, pt' => ['pt', 3,
            ['se a regra não muda nada, essa unidade não tem par, e sem pares não há escolha'],
            ['não há par nem escolha']],

        // Caso 6: la condizione per scrivere la sola differenza di chi esce.
        'caso 6, it' => ['it', 4,
            ['La fine di un altro conduttore scrive, come la prima fine, la differenza di ognuno quando tutti possono avere una riga in saldi; se qualcuno era già uscito (di norma chi è uscito con la fine di prima), o chi riceve i giorni non ha quote del piano, scrive la sola differenza di chi esce, verso chi riceve i suoi giorni, e la parte che la regola del piano cambierebbe agli altri non si scrive:'],
            ['La fine di un altro conduttore scrive la differenza di ognuno, come la prima fine; ma se qualcuno era già uscito']],
        'caso 6, en' => ['en', 4,
            ['The end of another tenant writes, like the first end, everyone\'s difference when everyone can have a balance row; if someone had already left (usually whoever left with the earlier end), or whoever receives the days has no shares in the plan, it writes only the difference of whoever leaves, towards whoever receives their days, and the part that the plan rule would change for the others is not written:'],
            ['The end of another tenant writes everyone\'s difference, like the first end; but if']],
        'caso 6, pt' => ['pt', 4,
            ['O fim de outro inquilino escreve, como o primeiro fim, a diferença de cada um quando todos podem ter uma linha de saldo; se alguém já tinha saído (normalmente quem saiu com o fim de antes), ou quem recebe os dias não tem quotas do plano, escreve só a diferença de quem sai, para quem recebe os seus dias, e a parte que a regra do plano mudaria aos outros não se escreve:'],
            ['O fim de outro inquilino escreve a diferença de cada um, como o primeiro fim; mas se']],

        // Caso 7: si ridividono le voci che un passaggio qualunque ha cambiato, non solo la fine.
        'caso 7, it' => ['it', 5,
            ['il prospetto divide con la regola del piano le voci che un passaggio ha cambiato (una voce che nessun passaggio ha cambiato resta com\'è nelle rate)'],
            ['le voci che la fine ha cambiato']],
        'caso 7, en' => ['en', 5,
            ['the statement divides with the plan rule the items that a transfer changed (an item that no transfer changed stays as it is in the instalments)'],
            ['the items that the end changed']],
        'caso 7, pt' => ['pt', 5,
            ['o demonstrativo divide com a regra do plano as rubricas que uma passagem mudou (uma rubrica que nenhuma passagem mudou fica como está nas prestações)'],
            ['as rubricas que o fim mudou']],

        // Caso 10: la guida dice la nuda tornata piena con l'estinzione, non la nuda comprata il giorno dell'estinzione.
        'caso 10, it' => ['it', 10,
            ['(la ricompera, la nuda tornata piena con l\'estinzione dell\'usufrutto, la successione di chi aveva ricevuto, le righe aperte a mano)'],
            ['la nuda comprata il giorno dell\'estinzione']],
        'caso 10, en' => ['en', 10,
            ['(the buy-back, the bare ownership that becomes full when the usufruct ends, the succession of someone who had received, rows opened by hand)'],
            ['bare ownership bought on the day of the extinction']],
        'caso 10, pt' => ['pt', 10,
            ['(a recompra, a nua-propriedade que volta plena com a extinção do usufruto, a sucessão de quem tinha recebido, as linhas abertas à mão)'],
            ['nua-propriedade comprada no dia da extinção']],

        // Caso 12: «titolari» comprende i conduttori; «owners» sono i proprietari.
        'caso 12, en' => ['en', 8,
            ['with the holders changed after generation (a change of tenant, a start)'],
            ['with the owners changed after generation']],

        // Caso 13: la stessa etichetta si traduce una volta sola.
        'caso 13, pt' => ['pt', 3,
            ['«Scrivi il conguaglio» (escreve o acerto)'],
            ['(escrever o acerto)']],
    ];
}

it('le frasi di regola dei changelog della beta.55 sono quelle corrette e non quelle di prima', function (string $lingua, int $voce, array $presenti, array $assenti) {
    $testo = b55clVoci($lingua)[$voce - 1];

    foreach ($presenti as $frase) {
        expect($testo)->toContain($frase);
    }
    foreach ($assenti as $frase) {
        expect($testo)->not->toContain($frase);
    }
})->with(b55clCorrezioni());

it('i tre changelog della beta.55 hanno le stesse undici voci', function () {
    foreach (['it', 'en', 'pt'] as $lingua) {
        expect(b55clVoci($lingua))->toHaveCount(11);
    }
});

// Caso 11: due frasi della voce pubblica erano più larghe del codice (la scelta c'è solo se la regola propone righe; il prospetto divide dove la locazione è continuata con un coinquilino).
it('la voce pubblica dice la scelta solo se la regola cambia le parti, e il prospetto solo dove la locazione è continuata con un coinquilino', function () {
    $voce = b55clVocePubblica();

    expect($voce)->toContain('ci sono anche quando esce uno solo dei conduttori e l\'altro resta, se la regola del piano cambia le parti:')
        ->and($voce)->toContain('**Dove la locazione è continuata con un coinquilino, il prospetto degli oneri accessori divide con la regola del piano le voci che i passaggi hanno cambiato,** anche sui preventivi generati dalla 1.10')
        ->and($voce)->not->toContain('e l\'altro resta: la parte')
        ->and($voce)->not->toContain('**Il prospetto degli oneri accessori divide le voci con la regola del piano,**');
});

it('la voce pubblica non ha riferimenti ai documenti né alle decisioni', function () {
    $voce = b55clVocePubblica();

    expect($voce)->not->toBe('')
        ->and(preg_match('/\.md|decision/', $voce))->toBe(0);
});
