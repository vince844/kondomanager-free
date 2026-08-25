<?php

/**
 * # Il documento di processo non può restare indietro
 *
 * ## Il difetto che questa guardia esiste per prendere
 *
 * `docs/flusso_di_lavoro_rilascio.md` è l'unico documento di processo del progetto, e la sua Fase
 * 0.3 dice di rileggerlo e correggerlo **a ogni beta**, scrivendoci le lezioni di quella appena
 * chiusa. È scritto in grassetto, con una checklist di cinque punti e con la motivazione: *«se
 * scivola lui, non c'è più niente che tenga in riga gli altri»*.
 *
 * È stato saltato **due volte**: aprendo la beta.58 (recuperato due giri dopo, nella .59) e di
 * nuovo nella .62 e nella .63, scoperto aprendo la beta.64. Una regola che sta scritta, è motivata
 * e viene saltata due volte non ha un problema di formulazione: ha un problema di visibilità.
 *
 * ## Perché lo strumento che c'era non poteva vederlo
 *
 * `kondomanager:verifica-documentazione` misura già l'età dei documenti in beta e mostra i dieci
 * più vecchi. Ma li ordina per età **assoluta**, e quell'elenco è dominato da documenti a «31 beta
 * fa». Il flusso di lavoro, anche quando è indietro, sta a «1 beta fa» o «2 beta fa»: non comparirà
 * **mai** in una classifica dei dieci più vecchi.
 *
 * È l'unico documento del progetto la cui età accettabile è **zero**, misurato con il metro degli
 * altri cinquantacinque, per cui trenta beta di età sono normali. La classifica misura la cosa
 * giusta per 55 documenti su 56 e la cosa sbagliata proprio per quello che le tiene in riga tutte.
 *
 * ## Perché due controlli e non uno
 *
 * Perché l'intestazione e le sezioni sono due stati **indipendenti**, e solo il secondo è il danno
 * vero. Aprendo la beta.62 l'intestazione è stata aggiornata — nomina la .62 — ma le lezioni della
 * .62 non sono mai state scritte. Una guardia sulla sola intestazione si sarebbe quindi dichiarata
 * soddisfatta esattamente nel giro in cui il documento stava cominciando a mentire.
 *
 * ## Cosa questa guardia NON copre
 *
 * - **Non dice che le lezioni siano buone**: dice che ci sono. Una sezione con dentro una riga
 *   vuota la soddisfa. La qualità è di chi scrive e della revisione, non di un `preg_match`.
 * - **Non copre i buchi storici**: guarda solo la beta precedente a quella in sviluppo. La beta.51
 *   non ha una sezione e non l'avrà mai; sanare all'indietro non è il mestiere di questa guardia.
 * - **Non copre gli altri documenti**: per quelli l'età è una misura, non un difetto, ed è giusto
 *   che resti tale — è il comando `kondomanager:verifica-documentazione` a mostrarla.
 * - **Non gira su un clone pulito**: `docs/*` è gitignorato, quindi il documento non è nel
 *   repository. Su una macchina che non ce l'ha i test si saltano invece di fallire, come già fa
 *   `VerificaDocumentazioneCommandTest` con `roadmap.md`.
 */

use Illuminate\Support\Facades\Artisan;

/** Il documento di processo, per percorso assoluto: qui non si dipende dalla directory di lavoro. */
function documentoDiProcesso(): string
{
    return dirname(__DIR__, 3).'/docs/flusso_di_lavoro_rilascio.md';
}

/**
 * Il numero di beta della versione in sviluppo, letto da `config/app.php`.
 *
 * `null` quando la versione non è una beta — su una stabile questa guardia non ha niente da dire,
 * e pretendere una sezione «lezioni della 1.10.0» sarebbe un rilievo che nessuno chiuderebbe mai.
 */
function betaInSviluppo(): ?int
{
    return preg_match('#-beta\.(\d+)#', (string) config('app.version'), $m)
        ? (int) $m[1]
        : null;
}

/**
 * Le beta che nel documento hanno una sezione di lezioni.
 *
 * ⚠️ **Le intestazioni non hanno una forma sola**, e non si può pretenderla: trenta beta di
 * lezioni hanno prodotto «I due controlli imparati nella beta.36», «Le cinque lezioni della beta.54», «La
 * lezione dell'apertura della beta.50» e «Le lezioni della beta.57 e della beta.58 — due giri
 * arretrati». Imporre un formato unico vorrebbe dire riscrivere ventotto intestazioni per far
 * contento un test, che è il contrario del punto.
 *
 * Si riconosce quindi dal *senso*: un'intestazione di terzo livello che parla di lezioni, controlli
 * o cose imparate, e che nomina almeno una beta. Da una sola intestazione se ne estraggono anche
 * più d'una — la riga della .57 e .58 ne dichiara due.
 *
 * @return list<int>
 */
function betaConSezioneDiLezioni(string $testo): array
{
    $trovate = [];

    foreach (explode("\n", $testo) as $riga) {
        if (! str_starts_with($riga, '### ')) {
            continue;
        }

        if (! preg_match('#lezion|controll|imparat#iu', $riga)) {
            continue;
        }

        if (preg_match_all('#beta\.(\d+)#', $riga, $m)) {
            foreach ($m[1] as $n) {
                $trovate[] = (int) $n;
            }
        }
    }

    $trovate = array_values(array_unique($trovate));
    sort($trovate);

    return $trovate;
}

it('trova davvero delle sezioni di lezioni, invece di guardare il vuoto', function () {
    // ⚠️ Senza questo, il giorno che le intestazioni cambiano forma la guardia diventa verde perché
    // non riconosce più niente — la forma di guasto peggiore, perché si presenta come un successo.
    // È la lezione della beta.61: tre guardie scritte nella .60 si svuotavano tutte senza che un
    // test diventasse rosso.
    $sezioni = betaConSezioneDiLezioni(file_get_contents(documentoDiProcesso()));

    expect(count($sezioni) > 15)->toBeTrue(
        "Riconosciute solo ".count($sezioni)." sezioni di lezioni: il riconoscitore non funziona più.\n".
        "Il documento ne ha una per quasi ogni beta dalla .34 in poi. Se le intestazioni hanno\n".
        "cambiato forma, va aggiornato `betaConSezioneDiLezioni()` — non allentata questa soglia."
    );
})->skip(fn () => ! file_exists(documentoDiProcesso()), 'docs/ è gitignorato: il documento non è su questa macchina');

it('ha la sezione delle lezioni della beta precedente', function () {
    $beta = betaInSviluppo();

    if ($beta === null || $beta <= 1) {
        expect(true)->toBeTrue();

        return;
    }

    $precedente = $beta - 1;
    $sezioni = betaConSezioneDiLezioni(file_get_contents(documentoDiProcesso()));

    expect(in_array($precedente, $sezioni, true))->toBeTrue(
        "La versione in sviluppo è la beta.{$beta}, ma in `docs/flusso_di_lavoro_rilascio.md` non\n".
        "c'è nessuna sezione con le lezioni della beta.{$precedente}.\n\n".
        "È la **Fase 0.3** del processo, e non è un adempimento: è il passo che tiene vero l'unico\n".
        "documento che tiene in riga tutti gli altri. Si è già perso due volte — aprendo la .58 e\n".
        "poi nella .62 e nella .63 — e tutte e due le volte se ne è accorto qualcuno per caso.\n\n".
        "Da fare adesso, non dopo: rileggere il documento dall'inizio alla fine, scrivere le\n".
        "lezioni della beta.{$precedente} **con il perché e non solo la regola**, e ricontrollare\n".
        "una per una le cifre che il documento dichiara — non ricordarle.\n\n".
        "Sezioni presenti oggi: beta.".implode(', beta.', $sezioni)
    );
})->skip(fn () => ! file_exists(documentoDiProcesso()), 'docs/ è gitignorato: il documento non è su questa macchina');

it("l'intestazione di stato nomina la versione in sviluppo", function () {
    $beta = betaInSviluppo();

    if ($beta === null) {
        expect(true)->toBeTrue();

        return;
    }

    // Solo l'intestazione, non tutto il documento: la Fase 3 e le sezioni di lezioni nominano le
    // beta per conto loro, e cercare in tutto il file renderebbe questo controllo sempre verde.
    $testo = file_get_contents(documentoDiProcesso());
    $inizio = strpos($testo, '<!-- verifica-documentazione -->');
    $fine = strpos($testo, '<!-- /verifica-documentazione -->');

    expect($inizio !== false)->toBeTrue('Il documento ha perso i marcatori dell\'intestazione di stato');

    $intestazione = substr($testo, $inizio, $fine - $inizio);

    expect(str_contains($intestazione, "beta.{$beta}"))->toBeTrue(
        "L'intestazione di stato di `docs/flusso_di_lavoro_rilascio.md` non nomina la beta.{$beta},\n".
        "che è la versione in sviluppo secondo `config/app.php`.\n\n".
        "⚠️ Questo controllo è **il più debole dei due**, e sta qui solo per completezza: aprendo la\n".
        "beta.62 l'intestazione era stata aggiornata e le lezioni non erano state scritte. Un\n".
        "documento che dichiara di essere stato riletto senza esserlo è peggio di uno vecchio.\n\n".
        "Nell'intestazione va scritto anche **cosa è cambiato**: quali cifre sono state riverificate\n".
        "e quali erano invecchiate. Su questo documento invecchiano ogni volta, e la conta si rifà."
    );
})->skip(fn () => ! file_exists(documentoDiProcesso()), 'docs/ è gitignorato: il documento non è su questa macchina');

it('la guardia morde: una beta senza sezione viene vista', function () {
    // L'autocontrollo passa dalla **stessa funzione** del test vero — è la lezione della beta.60:
    // una guardia provata su una copia prova la copia. Qui si dà in pasto al riconoscitore un
    // documento finto con un buco noto, e si verifica che il buco si veda.
    $finto = <<<'MD'
    ### Le cinque lezioni della beta.90
    testo
    ### Le tre lezioni della beta.92
    testo
    ### Il sito parla al presente, e la beta.91 non è il presente
    testo
    MD;

    $sezioni = betaConSezioneDiLezioni($finto);

    expect($sezioni)->toContain(90)
        ->and($sezioni)->toContain(92)
        // ⚠️ La .91 è nominata, ma da un'intestazione che non è una sezione di lezioni — la forma
        // è quella vera di «### Il sito parla al presente, e la beta non è il presente». Se
        // comparisse, il riconoscitore starebbe accettando qualunque menzione e la guardia sarebbe
        // verde per sempre.
        ->and($sezioni)->not->toContain(91);
});

it('il comando che misura i documenti non sostituisce questa guardia', function () {
    // Non è un test di cortesia: documenta il **perché** questo file esiste accanto a uno strumento
    // che sembra già coprire la materia. Se un giorno il comando imparasse a segnalare da sé il
    // documento di processo, questa guardia diventerebbe ridondante — e quel giorno lo si scopre
    // da qui, non ricordandoselo.
    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect(str_contains($uscita, 'età'))->toBeTrue(
        'Il comando non parla più di età: verificare se ora presidia da sé il documento di processo.'
    );

    // L'elenco per età mostra i più vecchi in assoluto. Il documento di processo, anche quando è
    // indietro, ha un'età di una o due beta: non ci entra mai. È esattamente questo il buco.
    expect(str_contains($uscita, 'flusso_di_lavoro_rilascio.md'))->toBeFalse(
        "Il comando ora nomina da sé il documento di processo nell'elenco predefinito.\n".
        'Se lo fa perché ha imparato a presidiarlo, questa guardia va rivista.'
    );
});
