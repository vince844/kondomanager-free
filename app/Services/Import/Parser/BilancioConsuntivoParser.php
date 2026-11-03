<?php

namespace App\Services\Import\Parser;

use App\Helpers\MoneyHelper;
use App\Services\Import\Canonical\CanonicalCapitolo;
use App\Services\Import\Canonical\CanonicalStrutturaSpese;
use App\Services\Import\Canonical\CanonicalVoceSpesa;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\Rilievo;

/**
 * «Bilancio consuntivo per conto» → la struttura delle spese: capitoli e voci.
 *
 * È la stampa che il §7 di `modelli_import_manuale.md` chiamava «il buco»: il livello dei
 * capitoli non esisteva, quindi questo report veniva **riconosciuto e buttato** — finiva nel ramo
 * `default` di `ImportVerificaService`, che ne legge la testata e scarta il contenuto.
 *
 * ## La forma, misurata su un export vero e non dedotta dal documento
 *
 * Due colonne senza titolo e due con: si legge per **posizione** le prime, per etichetta le altre.
 *
 * | colonna | cosa |
 * | :--- | :--- |
 * | A | il **capitolo**, su una riga sua, senza importi |
 * | B | la **voce** di spesa |
 * | C | l'importo della voce — intestata «Importo» |
 * | D | il totale del capitolo — intestata «Totale» |
 *
 * ⚠️ **Il totale del capitolo sta sulla riga dell'ULTIMA voce del gruppo**, non su quella del
 * capitolo: è il dettaglio che un parser scritto sulla descrizione invece che sul file
 * sbaglierebbe, perché la descrizione lascia intendere il contrario.
 *
 * ## Il segno, e dove si converte
 *
 * Il file stampa i costi **negativi**. In archivio `conti.importo` è il fabbisogno, cioè un numero
 * **positivo**: un capitolo da € 2.448,11 vale `244811`. L'inversione e la conversione in
 * centesimi avvengono qui, al confine, **una volta sola** — come nel riparto e per la stessa
 * ragione per cui il progetto vieta i `* 100` difensivi a valle.
 *
 * ## Cosa NON entra
 *
 * `Spese personali` è stampata come un capitolo ma non lo è: vedi la nota su
 * `CanonicalStrutturaSpese`, dove è spiegato con la misura che lo dimostra — è lo stesso denaro
 * che importiamo già dentro i saldi di apertura, e scriverlo qui lo conterebbe due volte.
 */
final class BilancioConsuntivoParser
{
    /**
     * La riga che chiude i dati: tutto ciò che viene dopo è epilogo e non è mai un capitolo.
     *
     * ⚠️ **È una regola strutturale, non una lista bianca di etichette.** Il blocco finale del
     * file — «Totale gestione», «Saldi di fine es. precedente», «Rate versate», «Saldo finale
     * (Euro)» — cambia da una stampa all'altra e da una versione all'altra del gestionale.
     * Riconoscerlo per elenco chiuso è *fail-open*: la prima etichetta non prevista diventerebbe
     * un capitolo scritto nel piano dei conti di qualcuno.
     */
    private const RIGA_TOTALE = 'totale';

    /**
     * Le spese addebitate a singoli condòmini: si leggono, non si importano.
     *
     * Il nome è quello che il file stampa in colonna A. Nel riparto della stessa esportazione la
     * colonna corrispondente si chiama «Movimenti personali» — due nomi per la stessa cosa, ed è
     * il motivo per cui il confronto è sul testo normalizzato e non sul nome esatto.
     */
    private const CAPITOLO_SPESE_PERSONALI = 'spese personali';

    /**
     * @return array{struttura: CanonicalStrutturaSpese, esito: EsitoVerifica}
     */
    public function estrai(Foglio $foglio, int $rigaIntestazione): array
    {
        $mappa = (new HeaderDetector)->mappaColonne($foglio, $rigaIntestazione);

        // Le due etichette sono entrambe nel quorum del riconoscitore, quindi se siamo qui ci
        // sono: la guardia esiste per il percorso del **tipo forzato dall'utente**, dove
        // `ImportVerificaService::foglio()` accetta l'intestazione con un quorum più basso.
        $colonnaImporto = $mappa['importo'] ?? null;
        $colonnaTotale = $mappa['totale'] ?? null;

        if ($colonnaImporto === null || $colonnaTotale === null) {
            return [
                'struttura' => new CanonicalStrutturaSpese([]),
                'esito' => new EsitoVerifica(0, [Rilievo::errore(
                    'bilancio.colonne_assenti',
                    'Nel bilancio consuntivo non trovo le colonne «Importo» e «Totale».',
                    'Sono le due colonne con i numeri della stampa «Bilancio consuntivo per conto». '
                    .'Se hai forzato il tipo di questo file a mano, controlla che sia davvero quella '
                    .'stampa: senza quelle due colonne non c\'è niente da leggere.',
                )]),
            ];
        }

        /** @var list<CanonicalCapitolo> $capitoli */
        $capitoli = [];
        $rilievi = [];
        $righeDati = 0;

        // Il capitolo in costruzione: si chiude quando ne comincia un altro o quando i dati
        // finiscono. Il totale arriva sull'ultima voce, quindi non si può scrivere prima.
        $nomeCorrente = null;
        /** @var list<CanonicalVoceSpesa> $vociCorrenti */
        $vociCorrenti = [];
        $totaleCorrente = null;

        $totaleGenerale = null;
        $spesePersonali = 0;
        $dopoIlTotale = false;

        $chiudi = function () use (&$capitoli, &$nomeCorrente, &$vociCorrenti, &$totaleCorrente): void {
            if ($nomeCorrente !== null) {
                $capitoli[] = new CanonicalCapitolo($nomeCorrente, $vociCorrenti, $totaleCorrente);
            }

            $nomeCorrente = null;
            $vociCorrenti = [];
            $totaleCorrente = null;
        };

        for ($i = $rigaIntestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);

            if ($this->vuota($riga)) {
                continue;
            }

            $capitolo = trim((string) ($riga[0] ?? ''));
            $voce = trim((string) ($riga[1] ?? ''));
            $importo = $this->cents($riga[$colonnaImporto] ?? null);
            $totale = $this->cents($riga[$colonnaTotale] ?? null);

            // ── L'epilogo: dalla riga «TOTALE» in poi non nasce più nessun capitolo ──
            if ($dopoIlTotale) {
                continue;
            }

            if (HeaderDetector::normalizza($voce) === self::RIGA_TOTALE) {
                $chiudi();
                $totaleGenerale = $totale;
                $dopoIlTotale = true;

                continue;
            }

            // ── Riga di capitolo: colonna A piena ──
            if ($capitolo !== '') {
                $chiudi();

                // «Spese personali» porta il totale sulla propria riga e non ha voci: si somma
                // alla quadratura e non diventa un capitolo. La nota lunga sta sul canonico.
                if (HeaderDetector::normalizza($capitolo) === self::CAPITOLO_SPESE_PERSONALI) {
                    $spesePersonali = $totale ?? 0;
                    $righeDati++;

                    $rilievi[] = Rilievo::avviso(
                        'capitoli.spese_personali_non_importate',
                        sprintf(
                            '«%s» (%s) non entra fra i capitoli: sono spese addebitate a singoli condòmini.',
                            $capitolo,
                            MoneyHelper::format($spesePersonali),
                        ),
                        'Le ritrovi già dentro i saldi di apertura di chi le deve — nel riparto sono '
                        .'la colonna «Movimenti personali», e sono comprese nel saldo finale di ogni '
                        .'unità. Scriverle anche qui le conterebbe due volte, e ripartite per '
                        .'millesimi le farebbe pagare a tutti.',
                        Foglio::rigaUtente($i),
                        'Totale',
                    );

                    continue;
                }

                $nomeCorrente = $capitolo;
                $totaleCorrente = $totale;
                $righeDati++;

                continue;
            }

            // ── Riga di voce: colonna B piena ──
            if ($voce !== '') {
                if ($nomeCorrente === null) {
                    // Una voce prima di qualunque capitolo: il file è stato manipolato, oppure
                    // la stampa ha una forma che non conosciamo. In entrambi i casi non si
                    // indovina un contenitore.
                    $rilievi[] = Rilievo::errore(
                        'bilancio.voce_senza_capitolo',
                        sprintf('La voce «%s» non sta sotto nessun capitolo.', $voce),
                        'Nella stampa ogni voce di spesa appartiene al capitolo scritto sopra di lei, '
                        .'nella colonna a sinistra. Se hai modificato il file dopo l\'esportazione, '
                        .'riesportalo: non posso attribuire una spesa a un capitolo che non c\'è.',
                        Foglio::rigaUtente($i),
                        'Importo',
                    );

                    continue;
                }

                if ($importo === null) {
                    $rilievi[] = Rilievo::errore(
                        'bilancio.importo_non_numerico',
                        sprintf('L\'importo di «%s» non è un numero.', $voce),
                        'Controlla quella cella nel file: se contiene testo, o è vuota, la voce non '
                        .'può entrare. Le celle vuote in questa colonna sono un caso normale solo '
                        .'sulle righe di capitolo, non su quelle di spesa.',
                        Foglio::rigaUtente($i),
                        'Importo',
                    );

                    continue;
                }

                $vociCorrenti[] = new CanonicalVoceSpesa($voce, $importo);

                // Il totale del gruppo arriva sull'ultima voce: si sovrascrive finché ce n'è uno.
                if ($totale !== null) {
                    $totaleCorrente = $totale;
                }

                $righeDati++;

                continue;
            }

            // ── La quarta forma: né capitolo, né voce, ma con un numero ──
            //
            // Senza questo ramo una riga così sparirebbe in silenzio, e l'unico effetto sarebbe
            // una quadratura che non torna senza che niente dica perché.
            if ($importo !== null || $totale !== null) {
                $rilievi[] = Rilievo::errore(
                    'bilancio.riga_senza_nome',
                    'Questa riga porta un importo ma non dice a cosa appartiene.',
                    'Non ha né un capitolo nella prima colonna né una voce nella seconda. Se l\'hai '
                    .'aggiunta a mano dopo l\'esportazione, toglila o dalle un nome: un importo senza '
                    .'nome non si può mettere da nessuna parte.',
                    Foglio::rigaUtente($i),
                    'Importo',
                );
            }
        }

        $chiudi();

        $struttura = new CanonicalStrutturaSpese($capitoli, $totaleGenerale, $spesePersonali);

        // ⚠️ `!== null && !== 0` su entrambi i lati: `null` è «non verificabile», e in PHP
        // `null !== 0` è **vero**. Confrontare solo con zero trasformerebbe l'assenza della
        // verifica nel suo fallimento — e bloccherebbe ogni stampa che il totale non lo porta.
        $scarto = $struttura->scartoCents();

        if ($scarto !== null && $scarto !== 0) {
            $rilievi[] = Rilievo::avviso(
                'bilancio.non_quadra',
                sprintf(
                    'I capitoli sommano %s, ma la stampa dichiara %s: %s di differenza.',
                    MoneyHelper::format($struttura->sommaCapitoliCents() + $spesePersonali),
                    MoneyHelper::format($totaleGenerale ?? 0),
                    MoneyHelper::format(abs($scarto)),
                ),
                'I nomi dei capitoli entrano lo stesso — sono quelli che servono a costruire il '
                .'piano dei conti — ma controlla il file: se qualcosa non è stato letto, quel '
                .'capitolo in archivio non ci sarà.',
            );
        }

        foreach ($capitoli as $c) {
            $scartoCapitolo = $c->scartoCents();

            if ($scartoCapitolo !== null && $scartoCapitolo !== 0) {
                $rilievi[] = Rilievo::avviso(
                    'capitolo.non_quadra',
                    sprintf(
                        'Le voci di «%s» sommano %s, ma il capitolo dichiara %s.',
                        $c->nome,
                        MoneyHelper::format($c->sommaVociCents()),
                        MoneyHelper::format($c->totaleDichiaratoCents ?? 0),
                    ),
                    'Il capitolo entra lo stesso con le sue voci: la differenza riguarda gli importi, '
                    .'e gli importi di un consuntivo sono una fotografia dell\'anno scorso, non il '
                    .'preventivo che scriverai.',
                    null,
                    'Totale',
                );
            }
        }

        return [
            'struttura' => $struttura,
            'esito' => new EsitoVerifica($righeDati, $rilievi),
        ];
    }

    /**
     * L'importo in centesimi, **positivo**, o `null` se la cella non porta un numero.
     *
     * Il file stampa i costi in negativo; `conti.importo` è il fabbisogno, che è positivo. Il
     * segno si inverte qui, al confine, insieme alla conversione — una volta sola.
     *
     * La guardia sulla stringa vuota viene **prima**: `MoneyHelper::toCents('')` vale `0`, e zero
     * centesimi non è «nessun valore». Sulle righe di capitolo la colonna «Importo» è vuota per
     * costruzione, quindi senza questa guardia ogni capitolo porterebbe una voce da € 0,00.
     */
    private function cents(mixed $valore): ?int
    {
        $grezzo = trim((string) ($valore ?? ''));

        if ($grezzo === '') {
            return null;
        }

        // Stesso doppio percorso di `MoneyHelper::toCents()` e dello stesso metodo in
        // `RipartoConsuntivoParser`: il punto delle migliaia si toglie **prima** della virgola
        // decimale, altrimenti «-1.202,84» diventa «-1.202.84» e `is_numeric()` lo scarta.
        $valido = is_numeric($grezzo) || is_numeric(str_replace(',', '.', str_replace('.', '', $grezzo)));

        if (! $valido) {
            return null;
        }

        return -MoneyHelper::toCents($grezzo);
    }

    private function vuota(array $riga): bool
    {
        foreach ($riga as $cella) {
            if (trim((string) ($cella ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
