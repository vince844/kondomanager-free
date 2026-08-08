<?php

namespace App\Services\Import\Parser;

use App\Helpers\MoneyHelper;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\Rilievo;

/**
 * Il riparto consuntivo → i **saldi di apertura** e il totale su cui quadrarli (livello 10).
 *
 * ## Il totale sta dentro il file, e non lo chiediamo
 *
 * L'ultima riga del riparto è `TOTALE COMPLESSIVO`, e la sua colonna `Saldo finale` è il numero
 * su cui l'intera migrazione deve tornare. Nessun altro lo legge — il concorrente chiede
 * all'amministratore di controllare i saldi con l'aiuto di un servizio di onboarding — mentre
 * qui la verifica è automatica perché il dato di riferimento è **nella stampa che l'utente ha
 * appena caricato** (§17.5).
 *
 * ## Le due righe che non sono persone
 *
 * - **`Arrotondamenti`**: importi minuscoli sparsi su più colonne, che servono a far tornare il
 *   totale. Se la si trattasse da soggetto nascerebbe un condòmino di nome «Arrotondamenti»; se
 *   la si scartasse e basta, la quadratura non tornerebbe per pochi centesimi e bloccherebbe
 *   l'import di tutti. Va **assorbita nello scarto ammesso**.
 * - **`TOTALE COMPLESSIVO`**: si usa, non si importa.
 *
 * ## L'unità è identificata solo dal progressivo
 *
 * Il riparto stampa `01`, `02`, `016` — non la terna `palazzina-gruppo-progressivo` dell'elenco
 * unità. Su un condominio con più palazzine quel numero è **ambiguo**, e il parser non lo
 * risolve da solo: espone il progressivo e il nome, e lascia la risoluzione al livello, che ha
 * davanti le unità vere. È la trappola numero 17, che i file veri hanno mostrato solo adesso.
 */
final class RipartoConsuntivoParser
{
    private const RUOLI_CESSATI = ['ex pr', 'ex co', 'ex us'];

    /**
     * Il conteggio dei giorni che Danea attacca al ruolo quando la titolarità è cambiata
     * durante l'esercizio: `ex Pr 336 gg`, `Pr 29 gg`, `ex Us 336 gg`.
     *
     * Trovato sul riparto vero di un condominio di quindici unità: il confronto era **esatto**
     * su `ex pr`, quindi `ex pr 336 gg` non risultava cessato, il suo saldo veniva cercato fra
     * le persone dell'elenco unità — dove per definizione non c'è più — e l'importazione si
     * fermava con un errore che l'amministratore non poteva risolvere in nessun modo.
     *
     * È il caso più comune che esista: **una compravendita a metà anno**. Le fixture non lo
     * avevano, il primo file vero sì.
     */
    private const SUFFISSO_GIORNI = '/\s+\d+\s*gg\.?$/u';

    private const RIGA_ARROTONDAMENTI = 'arrotondamenti';

    private const RIGA_TOTALE = 'totale complessivo';

    /**
     * @return array{
     *     saldi: list<CanonicalSaldo>,
     *     totale_riferimento_cents: int|null,
     *     arrotondamenti_cents: int,
     *     esito: EsitoVerifica
     * }
     */
    public function estrai(Foglio $foglio, int $rigaIntestazione): array
    {
        $mappa = (new HeaderDetector)->mappaColonne($foglio, $rigaIntestazione);

        $colonnaSaldo = $mappa['saldo finale'] ?? null;

        if ($colonnaSaldo === null) {
            return [
                'saldi' => [],
                'totale_riferimento_cents' => null,
                'arrotondamenti_cents' => 0,
                'esito' => new EsitoVerifica(0, [Rilievo::errore(
                    'riparto.colonna_saldo_assente',
                    'Nel riparto non trovo la colonna «Saldo finale».',
                    'È l\'ultima colonna della stampa, quella su cui si legge la posizione di ogni '
                    .'condòmino a fine esercizio. Senza, non posso ricavare i saldi di apertura.',
                )]),
            ];
        }

        $saldi = [];
        $rilievi = [];
        /** @var array<string, true> $conCambio unità con titolarità cambiata in corso d'anno */
        $conCambio = [];
        $totale = null;
        $arrotondamenti = 0;
        $righeDati = 0;

        for ($i = $rigaIntestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);

            if ($this->vuota($riga)) {
                continue;
            }

            $rigaUtente = Foglio::rigaUtente($i);
            $prima = HeaderDetector::normalizza((string) ($riga[0] ?? ''));
            $importo = $this->cents($riga[$colonnaSaldo] ?? null);

            if ($prima === self::RIGA_TOTALE) {
                $totale = $importo;

                continue;
            }

            if ($prima === self::RIGA_ARROTONDAMENTI) {
                $arrotondamenti = $importo ?? 0;

                continue;
            }

            $unita = trim((string) ($riga[0] ?? ''));
            $ruolo = HeaderDetector::normalizza((string) ($riga[1] ?? ''));
            $nome = trim((string) ($riga[2] ?? ''));

            if ($unita === '') {
                continue;
            }

            $righeDati++;

            if ($importo === null) {
                $rilievi[] = Rilievo::errore(
                    'riparto.saldo_non_numerico',
                    sprintf('Il saldo finale di «%s» non è un numero.', $nome !== '' ? $nome : $unita),
                    'Controlla la cella nel file: senza un importo leggibile non posso quadrare i saldi.',
                    $rigaUtente,
                    'Saldo finale',
                );

                continue;
            }

            // Zero non è un saldo: importarlo creerebbe righe che non dicono niente e che poi
            // qualcuno dovrà guardare per capire che non dicono niente.
            if ($importo === 0) {
                continue;
            }

            // Si toglie il conteggio dei giorni prima di confrontare: `ex pr 336 gg` è un
            // ex proprietario tanto quanto `ex pr`, e il numero riguarda il pro-rata della
            // spesa, non chi è la persona.
            $ruoloBase = trim((string) preg_replace(self::SUFFISSO_GIORNI, '', $ruolo));
            $cessato = in_array($ruoloBase, self::RUOLI_CESSATI, true);

            // Il conteggio dei giorni è **l'unico posto** in cui il file dichiara che su
            // quell'unità la titolarità è cambiata durante l'esercizio. Kondomanager non
            // modella ancora il subentro: la titolarità entra senza decorrenza e il saldo di
            // chi è uscito finisce sull'unità. È una semplificazione ragionevole, ma buttare
            // via l'informazione significherebbe non dirla — e chi deve ripartire una spesa
            // pro-rata fra i due la scoprirebbe solo sbagliando.
            if ($ruoloBase !== $ruolo) {
                $conCambio[$unita] = true;
            }

            $saldi[] = new CanonicalSaldo(
                immobileRef: $unita,
                // Il ruolo cessato non diventa una titolarità (§3), ma il suo saldo fa parte del
                // totale: diventa **solidale** sull'unità, che è il modo in cui Kondomanager
                // rappresenta un debito che segue la casa e non la persona.
                soggettoNome: $cessato ? null : ($nome !== '' ? $nome : null),
                importoCents: $importo,
                daTitolareCessato: $cessato,
                rigaSorgente: $rigaUtente,
            );
        }

        if ($totale === null && $righeDati > 0) {
            $rilievi[] = Rilievo::avviso(
                'riparto.totale_assente',
                'Il riparto non ha la riga «TOTALE COMPLESSIVO».',
                'Senza, non posso verificare da solo che i saldi quadrino: dovrai confrontarli tu '
                .'con l\'ultimo rendiconto approvato.',
            );
        }

        if ($conCambio !== []) {
            $rilievi[] = Rilievo::avviso(
                'riparto.titolarita_cambiata_in_corso_anno',
                sprintf(
                    'Su %d %s la titolarità è cambiata durante l\'esercizio (il riparto conta i giorni).',
                    count($conCambio),
                    count($conCambio) === 1 ? 'unità' : 'unità',
                ),
                'Kondomanager non gestisce ancora il subentro: importo il titolare attuale senza '
                .'decorrenza e porto il saldo di chi è uscito sull\'unità, in solido. Se una spesa '
                .'va divisa pro-rata fra vecchio e nuovo titolare, quella parte va fatta a mano.',
            );
        }

        $cessati = array_filter($saldi, fn (CanonicalSaldo $s) => $s->daTitolareCessato);

        if ($cessati !== []) {
            $somma = array_sum(array_map(fn (CanonicalSaldo $s) => $s->importoCents, $cessati));

            $rilievi[] = Rilievo::avviso(
                'riparto.saldi_di_titolari_cessati',
                sprintf(
                    '%d %s da titolari non più attuali, per %s complessivi.',
                    count($cessati),
                    count($cessati) === 1 ? 'posizione' : 'posizioni',
                    MoneyHelper::format(abs($somma)),
                ),
                'I ruoli storici non si importano, ma il loro saldo sì: lo porto sull\'unità come '
                .'saldo solidale (art. 63 disp. att. c.c.), perché è un debito che segue la casa. '
                .'Controllalo prima di emettere il primo piano rate.',
            );
        }

        return [
            'saldi' => $saldi,
            'totale_riferimento_cents' => $totale,
            'arrotondamenti_cents' => $arrotondamenti,
            'esito' => new EsitoVerifica($righeDati, $rilievi),
        ];
    }

    /**
     * L'importo in centesimi, **con il segno invertito**.
     *
     * Danea: negativo = debito. Kondomanager: positivo = debito. È l'inversione più pericolosa
     * dell'importatore, e sta qui — al confine, una volta sola.
     */
    private function cents(mixed $valore): ?int
    {
        $grezzo = trim((string) ($valore ?? ''));

        if ($grezzo === '') {
            return null;
        }

        // Stesso doppio percorso di MoneyHelper::toCents(): se il valore è già un numero
        // pulito (il caso comune, cella Excel nativa) si accetta così com'è; solo se non lo è
        // si prova la forma testuale italiana — punto delle migliaia tolto PRIMA della
        // virgola decimale, nello stesso ordine di MoneyHelper.
        //
        // Convertirli nell'ordine sbagliato (prima la virgola, come qui prima) trasformava
        // «-1.202,84» in «-1.202.84»: due punti, is_numeric() lo scartava come testo, e un
        // saldo reale sopra i mille euro spariva con l'errore «non è un numero» — insieme,
        // se capitava sulla riga del totale, alla quadratura automatica che si disattivava.
        $valido = is_numeric($grezzo) || is_numeric(str_replace(',', '.', str_replace('.', '', $grezzo)));

        if (! $valido) {
            return null;
        }

        return -MoneyHelper::toCents($grezzo);
    }

    /**
     * @param  list<mixed>  $riga
     */
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
