<?php

namespace App\Services\Gestionale;

use App\Enums\Fiscale\StatoDelegaF24;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\DelegaF24;

/**
 * Il quadro del modello F24, pronto da stampare.
 *
 * ## Perché esiste, e perché solo adesso
 *
 * Fino alla beta.38 la delega produceva un **prospetto**: i campi nell'ordine del modello, da
 * trascrivere nell'home banking. Il modello ministeriale vero era stato rimandato dicendo che
 * servivano campi che il design ha spostato alla v1.11 — firmatario, codice carica,
 * intermediario, IBAN di addebito.
 *
 * Guardando il modello vero quella motivazione non regge: **quei campi sul foglio non ci
 * sono**. Appartengono al tracciato telematico e ai dichiarativi. Sull'F24 Ordinario l'unico
 * IBAN è la casella facoltativa «Autorizzo addebito su conto corrente», che il contribuente
 * compila a mano se sceglie l'addebito, e l'intera sezione «Estremi del versamento» porta
 * scritto sopra *«da compilare a cura di banca/poste/agente della riscossione»*.
 *
 * Quel che il modello chiede davvero lo abbiamo già: codice fiscale e denominazione sono
 * congelati sulla delega alla generazione, la sezione Erario è esattamente `righe_f24`, e il
 * domicilio fiscale sta in anagrafica condominio. Nessuna colonna nuova, nessuna migrazione.
 *
 * ## Il perimetro
 *
 * Si compila **solo la sezione Erario**. INPS, Regioni, IMU e altri enti restano vuote perché
 * un condominio che versa ritenute d'acconto non le usa, e riempirle richiederebbe dati che
 * il gestionale non ha. Non si compilano nemmeno «codice ufficio» e «codice atto»: le
 * avvertenze li vogliono solo per le somme dovute a seguito di accertamento, che non è questo
 * caso.
 *
 * Non si compila la colonna dei crediti compensati, e di conseguenza TOTALE B resta **vuoto**
 * invece che a zero: è la forma con cui un F24 senza compensazioni si presenta allo sportello,
 * e un `0,00` scritto lì dichiarerebbe una compensazione da zero euro che non esiste.
 */
class ModelloF24Service
{
    /**
     * Le righe della sezione Erario sul foglio: sei, contate sul modello.
     *
     * È lo stesso numero di `GeneraDelegheF24Action::MAX_RIGHE_PER_DELEGA`, e non è una
     * coincidenza: la delega si spezza a sei proprio perché a sei righe il foglio finisce.
     */
    public const RIGHE_SEZIONE_ERARIO = 6;

    /** Le caselle del codice fiscale sul modello: sedici, anche per chi ne usa undici. */
    public const CASELLE_CODICE_FISCALE = 16;

    /**
     * I tre esemplari, con il piede di pagina che li distingue.
     *
     * Solo il primo porta la casella di autorizzazione all'addebito in conto: sul modello
     * ministeriale la sezione IBAN compare sulla sola copia che resta alla banca.
     *
     * @return list<array{titolo: string, iban: bool}>
     */
    public function copie(): array
    {
        return [
            ['titolo' => '1ª COPIA PER LA BANCA/POSTE/AGENTE DELLA RISCOSSIONE', 'iban' => true],
            ['titolo' => '2ª COPIA PER LA BANCA/POSTE/AGENTE DELLA RISCOSSIONE', 'iban' => false],
            ['titolo' => 'COPIA PER IL SOGGETTO CHE EFFETTUA IL VERSAMENTO', 'iban' => false],
        ];
    }

    /**
     * Da una delega al foglio compilato.
     *
     * @return array{
     *     contribuente: array{codice_fiscale: list<string>, denominazione: string, comune: string, provincia: string, via: string},
     *     erario: list<array{codice_tributo: ?string, rateazione: ?string, anno: ?string, debito: ?array{euro: string, centesimi: string}}>,
     *     totale_a: array{euro: string, centesimi: string},
     *     saldo: array{euro: string, centesimi: string},
     *     mancanti: list<string>
     * }
     */
    public function quadro(DelegaF24 $delega): array
    {
        $condominio = $delega->condominio;

        // Il totale si somma dalle righe, non si legge dalla testata. Sul foglio TOTALE A è
        // per definizione la somma della colonna che ha sopra: se il campo `totale_debito`
        // divergesse, stampare quello scriverebbe un totale che non torna con i suoi addendi
        // — e chi lo controlla allo sportello se ne accorge prima di noi.
        $totale = (int) $delega->righe->sum('importo_debito');

        return [
            'contribuente' => [
                // Il codice fiscale del condominio ne ha undici, la griglia ne ospita sedici:
                // si incolonna da sinistra e le ultime cinque restano vuote, come si compila
                // a penna.
                'codice_fiscale' => $this->caselle(
                    $delega->cf_contribuente ?? $condominio?->codice_fiscale
                ),
                'denominazione' => $delega->denominazione_contribuente ?? $condominio?->nome ?? '',
                // Il domicilio fiscale non è congelato sulla delega: non lo è perché non è un
                // dato del versamento ma dell'ente, e se il condominio cambia sede la delega
                // ristampata deve riportare quella nuova.
                'comune' => $condominio?->comune ?? '',
                'provincia' => strtoupper($condominio?->provincia ?? ''),
                'via' => $condominio?->indirizzo ?? '',
            ],
            'erario' => $this->sezioneErario($delega),
            'totale_a' => MoneyHelper::perModelloF24($totale),
            // Senza crediti compensati il saldo coincide con il totale a debito. Resta un
            // campo suo perché sul modello lo è, e perché il giorno che la Fase 2 porterà la
            // compensazione dei crediti 1627/1628 i due numeri smetteranno di coincidere.
            'saldo' => MoneyHelper::perModelloF24($totale),
            'mancanti' => $this->campiMancanti($delega),
        ];
    }

    /**
     * I campi che il modello vuole e che il gestionale non sa.
     *
     * Non bloccano la stampa: un amministratore può volere il foglio da completare a penna, e
     * la decisione se un documento è pronto resta sua. Servono a dirglielo **prima** che lo
     * porti allo sportello, che è l'unico momento in cui l'informazione vale qualcosa.
     *
     * @return list<string>
     */
    public function campiMancanti(DelegaF24 $delega): array
    {
        $condominio = $delega->condominio;

        $attesi = [
            'Codice fiscale del condominio' => $delega->cf_contribuente ?? $condominio?->codice_fiscale,
            'Denominazione del condominio' => $delega->denominazione_contribuente ?? $condominio?->nome,
            'Comune del domicilio fiscale' => $condominio?->comune,
            'Provincia del domicilio fiscale' => $condominio?->provincia,
            'Via e numero civico del domicilio fiscale' => $condominio?->indirizzo,
        ];

        return array_values(array_keys(array_filter(
            $attesi,
            fn ($valore) => blank($valore)
        )));
    }

    /**
     * La filigrana, quando la delega non rappresenta più qualcosa da pagare.
     *
     * Una delega stornata o annullata resta consultabile e quindi ristampabile — è un
     * documento da conservare dieci anni. Ma stampata senza avvisi diventa indistinguibile da
     * una viva, e pagarla due volte è un errore che il gestionale avrebbe potuto evitare
     * scrivendoci sopra una parola.
     */
    public function filigrana(DelegaF24 $delega): ?string
    {
        return match ($delega->stato) {
            StatoDelegaF24::ANNULLATA => 'ANNULLATA',
            StatoDelegaF24::STORNATA => 'STORNATA',
            default => null,
        };
    }

    /**
     * Il nome del file scaricato.
     *
     * La stampa del prospetto passa dal dialogo del browser, che battezza il PDF con il titolo
     * della finestra: da lì nomi che non dicono niente. Qui il file esce dal server, quindi il
     * nome lo decidiamo noi — e in una cartella di adempimenti il protocollo è l'unica cosa
     * che rende un F24 ritrovabile.
     *
     * ⚠️ **Oggi `protocollo` è sempre nullo.** La colonna esiste in `deleghe_f24` ma **nessuno
     * la valorizza**: verificato con `grep` su tutto `app/` e a database su tutte le deleghe
     * esistenti. È prevista dalla Fase 2 del design, che non è stata costruita. Il ramo resta
     * perché il giorno che il protocollo arriverà sarà il nome giusto — ma finché è nullo, il
     * nome **lo dà sempre la scadenza**, e nessun testo deve raccontare il contrario.
     *
     * La scadenza da sola però non basta a distinguere: due deleghe possono legittimamente
     * condividerla — i due plafond maturano insieme il 16 dicembre, e una delega di più di sei
     * righe si spezza in due modelli per la **stessa** data. Scaricandole si sovrascriverebbero
     * a vicenda nella cartella dei download, che su un adempimento fiscale è il modo più
     * silenzioso di perdere un documento. Per questo in coda va l'id.
     */
    public function nomeFile(DelegaF24 $delega): string
    {
        $riferimento = $delega->protocollo
            ?: 'scad-'.($delega->data_scadenza?->format('Y-m-d') ?? 'senza-data').'-'.$delega->id;

        return 'F24-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $riferimento).'.pdf';
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * Le sei righe del foglio: quelle vere, e le vuote che restano a completare la griglia.
     *
     * Le righe non usate non si omettono — sul modello prestampato ci sono comunque, e una
     * griglia che si accorcia a seconda del contenuto non è più il modello.
     */
    private function sezioneErario(DelegaF24 $delega): array
    {
        $righe = $delega->righe
            ->sortBy('ordine')
            ->take(self::RIGHE_SEZIONE_ERARIO)
            ->map(fn ($riga) => [
                'codice_tributo' => $riga->codice_tributo,
                'rateazione' => $riga->rateazione_mese_rif,
                'anno' => $riga->anno_riferimento,
                'debito' => MoneyHelper::perModelloF24((int) $riga->importo_debito),
            ])
            ->values()
            ->all();

        $vuota = ['codice_tributo' => null, 'rateazione' => null, 'anno' => null, 'debito' => null];

        return array_pad($righe, self::RIGHE_SEZIONE_ERARIO, $vuota);
    }

    /**
     * Una stringa spalmata sulle caselle della griglia, in maiuscolo e senza spazi.
     *
     * @return list<string>
     */
    private function caselle(?string $valore): array
    {
        $pulito = strtoupper(preg_replace('/\s+/', '', $valore ?? ''));

        // `str_split('')` non risponde allo stesso modo su tutte le versioni di PHP — prima
        // della 8.2 restituisce un array con dentro una stringa vuota, dalla 8.2 un array
        // vuoto. Il caso della stringa vuota si tratta a parte, così la griglia esce di sedici
        // caselle sia con il codice fiscale sia senza.
        $caratteri = $pulito === '' ? [] : str_split($pulito);

        return array_slice(
            array_pad($caratteri, self::CASELLE_CODICE_FISCALE, ''),
            0,
            self::CASELLE_CODICE_FISCALE
        );
    }
}
