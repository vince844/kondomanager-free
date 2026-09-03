<?php

namespace App\Services\FatturaElettronica;

use App\DataTransferObjects\FatturaElettronica\FatturaPaCassaPrevidenziale;
use App\DataTransferObjects\FatturaElettronica\FatturaPaFattura;
use App\DataTransferObjects\FatturaElettronica\FatturaPaRiepilogo;
use App\DataTransferObjects\FatturaElettronica\FatturaPaRiga;
use App\DataTransferObjects\FatturaElettronica\FatturaPaRitenuta;
use App\DataTransferObjects\FatturaElettronica\FatturaPaScadenza;
use App\Exceptions\FatturaElettronica\FatturaPaParseException;
use App\Helpers\MoneyHelper;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Legge un file FatturaPA (XML in chiaro o busta CAdES .p7m) e restituisce
 * i dati grezzi del documento. Solo lettura: nessun accesso al database,
 * nessun riconoscimento del fornitore, nessuna scrittura — quello resta a
 * un servizio a valle (docs/lettura_xml_fatture_passive.md).
 *
 * Un file può contenere più fatture (lotto, FatturaElettronicaBody
 * ripetuto): parse() restituisce sempre un array, anche per un documento
 * singolo.
 *
 * ## Due cose che questo parser NON fa, di proposito
 *
 * 1. **Non valida contro l'XSD.** L'esempio ufficiale FPR02 dell'Agenzia
 *    non valida contro l'XSD ufficiale dell'Agenzia (ordine di
 *    ContattiTrasmittente/PECDestinatario invertito, verificato il
 *    01/09/2026): un cancello XSD rifiuterebbe file che il mittente
 *    considera legittimi. Si legge per nome, mai per posizione.
 * 2. **Non decide.** Dove il file è ambiguo o incoerente — righe che non
 *    sommano al riepilogo, segno di una nota di credito — il parser
 *    espone entrambi i dati e lascia decidere a valle. Non sceglie in
 *    silenzio.
 *
 * ## Namespace
 *
 * Nella FatturaPA solo l'elemento radice <p:FatturaElettronica> vive nel
 * namespace dell'Agenzia — i suoi discendenti no (verificato sul file
 * ufficiale FPR01: //p:FatturaElettronicaHeader dà zero nodi,
 * //FatturaElettronicaHeader ne dà uno). Gli XPath qui sotto sono quindi
 * tutti senza prefisso, di proposito.
 */
class FatturaPaParser
{
    /**
     * @return FatturaPaFattura[]
     *
     * @throws FatturaPaParseException
     */
    public function parse(string $content, ?string $nomeFile = null): array
    {
        $xml = $this->estraiXml($content, $nomeFile);

        $this->rifiutaDoctype($xml);

        $dom = new DOMDocument();
        $precedente = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET: nessuna richiesta di rete durante il parse, per
        // nessuna ragione. Un file esterno non deve poter far uscire una
        // connessione da questo processo.
        $caricato = $dom->loadXML($xml, LIBXML_NONET);
        $errori = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($precedente);

        if (! $caricato) {
            // ⚠️ **Il gergo di libxml resta nel log, a schermo va che cosa fare.**
            // Fino al 03/09/2026 il messaggio dell'eccezione finiva tale e quale sotto il
            // riquadro «Allega documento» — «File XML malformato: Specification mandates
            // value for attribute non» — corretto nel merito e inservibile per un
            // amministratore di condominio, che non ha nessun modo di agire su una frase
            // così. Il dettaglio serve a noi quando ci scrivono, quindi non si butta: si
            // sposta dove lo leggiamo noi.
            // ⚠️ **Il dettaglio viaggia sull'eccezione, non in un log scritto da qui.**
            // Questo parser è senza framework di proposito — niente database, niente
            // facade — ed è ciò che lo rende collaudabile in `tests/Unit` senza avviare
            // Laravel. Un `Log::warning` qui dentro romperebbe quella proprietà: l'ho
            // provato, e i test unitari sono diventati rossi con «A facade root has not
            // been set». A scrivere nel log è il controller, che nel framework ci vive già.
            $primo = $errori[0] ?? null;

            throw new FatturaPaParseException(
                'Questo non è un file XML valido: il contenuto risulta danneggiato o incompleto. '
                .'Se lo hai scaricato dal portale Fatture e Corrispettivi o da una PEC, riscaricalo e riprova.',
                dettaglioTecnico: $primo !== null ? trim($primo->message) : 'errore XML sconosciuto',
                riga: $primo->line ?? null,
            );
        }

        $xpath = new DOMXPath($dom);

        $bodies = $xpath->query('//FatturaElettronicaBody');
        if ($bodies === false || $bodies->length === 0) {
            throw new FatturaPaParseException('Nessun FatturaElettronicaBody trovato: non sembra una FatturaPA.');
        }

        $cedente = $xpath->query('//FatturaElettronicaHeader/CedentePrestatore')->item(0);
        if (! $cedente instanceof DOMElement) {
            throw new FatturaPaParseException('Manca CedentePrestatore: non sembra una FatturaPA.');
        }

        // ⚠️ Fratello di CedentePrestatore, non figlio: stesso genitore
        // FatturaElettronicaHeader. A differenza del cedente, un cessionario mancante
        // o malformato non fa rifiutare il file — vedi il docblock di
        // FatturaPaFattura::$cessionarioCodiceFiscale.
        $cessionario = $xpath->query('//FatturaElettronicaHeader/CessionarioCommittente')->item(0);

        $fatture = [];
        foreach ($bodies as $body) {
            if ($body instanceof DOMElement) {
                $fatture[] = $this->parseBody($xpath, $cedente, $cessionario instanceof DOMElement ? $cessionario : null, $body);
            }
        }

        return $fatture;
    }

    /**
     * Una FatturaPA non ha, e non può avere, un DOCTYPE: lo schema
     * ufficiale non lo prevede. Un file che ne porta uno o è generato da
     * qualcosa che non segue il tracciato, o è un attacco — e in
     * entrambi i casi non è una fattura da leggere.
     *
     * Il controllo sta qui e non è affidato a libxml perché il rilevatore
     * di amplificazione di libxml intercetta le entità ricorsive ma NON
     * la variante "quadratica" (una sola entità grande richiamata molte
     * volte): quella esplode più tardi, alla lettura di textContent, dove
     * non c'è più nessun limite e l'esito è un fatal error NON
     * catturabile invece di un'eccezione. Verificato il 01/09/2026: un
     * file da 49 KB bastava a uccidere il processo con memory_limit a
     * 128M, e alzando il limite bastava scalare il payload.
     */
    private function rifiutaDoctype(string $xml): void
    {
        // ⚠️ **Il prologo si delimita, non si tronca a una lunghezza fissa.**
        // Fino al 02/09/2026 questa riga era `substr($xml, 0, 4096)`, e bastava
        // un commento XML legale di cinquemila caratteri prima del DOCTYPE per
        // spingerlo oltre la finestra: la guardia non lo vedeva, l'espansione
        // quadratica delle entità partiva lo stesso e il worker moriva di
        // out-of-memory non catturabile. Riprodotto dalla revisione avversariale
        // della beta.14 con un file da 214 KB, DOCTYPE al byte 5049.
        //
        // Il prologo è per definizione **tutto ciò che precede l'elemento
        // radice**: lo si trova, invece di indovinarne la lunghezza. Un
        // `<!DOCTYPE` più avanti nel documento non può dichiarare entità, quindi
        // non è la stessa cosa e non va rifiutato — è la ragione per cui la
        // finestra c'era, e resta valida.
        $primoElemento = preg_match('/<[A-Za-z_:]/', $xml, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : strlen($xml);

        // I commenti si tolgono prima di cercare: `<!-- <!DOCTYPE ... -->` nel
        // prologo è legale e inerte, e rifiutarlo sarebbe un falso positivo.
        $prologo = preg_replace('/<!--.*?-->/s', '', substr($xml, 0, $primoElemento));

        if (preg_match('/<!DOCTYPE/i', (string) $prologo) === 1) {
            throw new FatturaPaParseException(
                'Il file dichiara un DOCTYPE: una FatturaPA non ne ha mai uno, il file non è attendibile.'
            );
        }
    }

    /**
     * Se il contenuto non è XML in chiaro, prova ad aprirlo come busta
     * CAdES (.p7m). Le funzioni openssl_cms_* vogliono percorsi di file,
     * non stringhe: da qui i file temporanei.
     *
     * ⚠️ Limite dichiarato (docs/lettura_xml_fatture_passive.md): provato
     * finora solo su una busta autofirmata — OPENSSL_CMS_NOVERIFY non
     * controlla la catena dei certificati, quindi il percorso di
     * ESTRAZIONE è verificato, non la validità di una firma reale dello
     * SdI. Nessuna verifica di firma è richiesta qui: il file è già
     * passato per lo SdI quando arriva, non tocca a noi ri-attestarne
     * l'autenticità.
     */
    private function estraiXml(string $content, ?string $nomeFile): string
    {
        if ($this->sembraXml($content)) {
            return $content;
        }

        $tmpIn = tempnam(sys_get_temp_dir(), 'fpa_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'fpa_out_');

        try {
            file_put_contents($tmpIn, $content);

            $ok = @openssl_cms_verify(
                $tmpIn,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY,
                null,
                [],
                null,
                $tmpOut,
                null,
                null,
                OPENSSL_ENCODING_DER
            );

            $etichetta = $nomeFile !== null ? "\"{$nomeFile}\"" : 'il file';

            if (! $ok) {
                throw new FatturaPaParseException(
                    "Impossibile aprire {$etichetta}: non è un XML valido né una busta .p7m riconoscibile."
                );
            }

            $estratto = file_get_contents($tmpOut);

            if ($estratto === false || ! $this->sembraXml($estratto)) {
                throw new FatturaPaParseException("La busta di {$etichetta} si apre, ma il contenuto estratto non è XML.");
            }

            return $estratto;
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    /**
     * Riconosce l'XML saltando BOM e spazi iniziali — ma il chiamante
     * riceve il contenuto ORIGINALE, che è ciò che loadXML() vuole: un
     * file con un a capo davanti al prologo è XML legittimo e non va né
     * rifiutato né mutilato.
     */
    private function sembraXml(string $content): bool
    {
        return str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\n\r\0\x0B"), '<');
    }

    private function parseBody(DOMXPath $xpath, DOMElement $cedente, ?DOMElement $cessionario, DOMElement $body): FatturaPaFattura
    {
        $tipoDocumento = $this->testo($xpath, './DatiGenerali/DatiGeneraliDocumento/TipoDocumento', $body);
        $numero = $this->testo($xpath, './DatiGenerali/DatiGeneraliDocumento/Numero', $body);
        $data = $this->testo($xpath, './DatiGenerali/DatiGeneraliDocumento/Data', $body);

        if ($tipoDocumento === null || $numero === null || $data === null) {
            throw new FatturaPaParseException('Manca uno dei dati minimi del documento (tipo, numero o data).');
        }

        $causali = [];
        foreach ($xpath->query('./DatiGenerali/DatiGeneraliDocumento/Causale', $body) as $nodo) {
            $causale = trim($nodo->textContent);
            if ($causale !== '') {
                $causali[] = $causale;
            }
        }

        $totaleDocumento = $this->testo($xpath, './DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento', $body);

        return new FatturaPaFattura(
            tipoDocumento: $tipoDocumento,
            numeroDocumento: $numero,
            dataDocumento: $data,
            causale: $causali === [] ? null : implode(' ', $causali),
            importoTotaleDocumentoCents: $totaleDocumento === null
                ? null
                : $this->cents($totaleDocumento, 'ImportoTotaleDocumento'),
            fornitorePartitaIva: $this->testo($xpath, './DatiAnagrafici/IdFiscaleIVA/IdCodice', $cedente),
            fornitorePartitaIvaPaese: $this->testo($xpath, './DatiAnagrafici/IdFiscaleIVA/IdPaese', $cedente),
            fornitoreCodiceFiscale: $this->testo($xpath, './DatiAnagrafici/CodiceFiscale', $cedente),
            fornitoreDenominazione: $this->denominazione($xpath, $cedente),
            fornitoreIndirizzo: $this->testo($xpath, './Sede/Indirizzo', $cedente),
            fornitoreCap: $this->testo($xpath, './Sede/CAP', $cedente),
            fornitoreComune: $this->testo($xpath, './Sede/Comune', $cedente),
            fornitoreProvincia: $this->testo($xpath, './Sede/Provincia', $cedente),
            fornitoreNazione: $this->testo($xpath, './Sede/Nazione', $cedente),
            fornitoreEmail: $this->testo($xpath, './Contatti/Email', $cedente),
            fornitoreRegimeFiscale: $this->testo($xpath, './DatiAnagrafici/RegimeFiscale', $cedente),
            cessionarioCodiceFiscale: $cessionario === null
                ? null
                : $this->testo($xpath, './DatiAnagrafici/CodiceFiscale', $cessionario),
            cessionarioDenominazione: $cessionario === null ? null : $this->denominazioneOpzionale($xpath, $cessionario),
            cassePrevidenziali: $this->parseCassePrevidenziali($xpath, $body),
            righe: $this->parseRighe($xpath, $body),
            riepiloghi: $this->parseRiepiloghi($xpath, $body),
            scadenze: $this->parseScadenze($xpath, $body),
            ritenute: $this->parseRitenute($xpath, $body),
        );
    }

    private function denominazione(DOMXPath $xpath, DOMElement $cedente): string
    {
        $denominazione = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Denominazione', $cedente);
        if ($denominazione !== null) {
            return $denominazione;
        }

        // Persona fisica: Nome + Cognome al posto di Denominazione — lo
        // schema li rende mutuamente esclusivi, mai entrambi insieme.
        $nome = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Nome', $cedente);
        $cognome = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Cognome', $cedente);
        if ($nome !== null || $cognome !== null) {
            return trim(($nome ?? '').' '.($cognome ?? ''));
        }

        throw new FatturaPaParseException('CedentePrestatore senza Denominazione né Nome/Cognome.');
    }

    /**
     * Stessa lettura di denominazione(), ma per il cessionario: `null` invece di
     * un'eccezione quando manca. Il cedente è la porta d'ingresso del file — se non
     * si sa CHI vende non c'è fattura da leggere — il cessionario qui serve solo al
     * confronto «è per questo condominio?» (docs/lettura_xml_fatture_passive.md): un
     * file altrimenti valido non deve smettere di leggersi per una FatturaPA scritta
     * male su questo campo soltanto.
     */
    private function denominazioneOpzionale(DOMXPath $xpath, DOMElement $elemento): ?string
    {
        $denominazione = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Denominazione', $elemento);
        if ($denominazione !== null) {
            return $denominazione;
        }

        $nome = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Nome', $elemento);
        $cognome = $this->testo($xpath, './DatiAnagrafici/Anagrafica/Cognome', $elemento);
        if ($nome !== null || $cognome !== null) {
            return trim(($nome ?? '').' '.($cognome ?? ''));
        }

        return null;
    }

    /**
     * I blocchi `DatiCassaPrevidenziale` del documento.
     *
     * ⚠️ **Prima questo metodo restituiva una SOMMA, ed era la forma sbagliata.**
     * Aggiunto il 02/09/2026 dal collaudo sui file veri del forum per spegnere un falso
     * «le righe non quadrano» su una fattura corretta: il contributo vive in
     * `DatiGeneraliDocumento`, non nelle righe, quindi lo scarto lo scambiava per un
     * errore. La spia è stata spenta sottraendo il totale dallo scarto — **e il guasto
     * è rimasto**: il contributo non entrava in nessuna riga, quindi la fattura si
     * registrava in difetto di quell'importo, in silenzio. Su una parcella di geometra
     * da € 4.099,20 significava registrarne € 3.904,00 (Fase 1-bis della beta.14,
     * reperto 1, misurato sull'endpoint vero).
     *
     * La correzione è restituire i blocchi **interi**: a valle ognuno diventa una riga
     * di spesa (vedi il docblock di `FatturaPaCassaPrevidenziale` per il perché è una
     * riga e non una partita a sé), e per farlo servono aliquota IVA e assoggettamento
     * a ritenuta, che una somma butta via.
     *
     * Lo schema ammette **più** blocchi (raro ma legittimo: due casse sullo stesso
     * documento), e ognuno tiene la propria aliquota: per questo restituirli separati
     * non è pedanteria — sommarli renderebbe impossibile dare a ciascuno la sua IVA.
     *
     * @return FatturaPaCassaPrevidenziale[]
     */
    private function parseCassePrevidenziali(DOMXPath $xpath, DOMElement $body): array
    {
        $casse = [];

        foreach ($xpath->query('./DatiGenerali/DatiGeneraliDocumento/DatiCassaPrevidenziale', $body) as $nodo) {
            if (! $nodo instanceof DOMElement) {
                continue;
            }

            $importo = $this->testo($xpath, './ImportoContributoCassa', $nodo);
            if ($importo === null) {
                throw new FatturaPaParseException('DatiCassaPrevidenziale senza ImportoContributoCassa.');
            }

            $alCassa = $this->testo($xpath, './AlCassa', $nodo);
            $aliquotaIva = $this->testo($xpath, './AliquotaIVA', $nodo);

            $casse[] = new FatturaPaCassaPrevidenziale(
                tipoCassa: $this->testo($xpath, './TipoCassa', $nodo),
                aliquotaContributo: $alCassa === null ? null : (float) $alCassa,
                importoContributoCents: $this->cents($importo, 'ImportoContributoCassa'),
                aliquotaIva: $aliquotaIva === null ? null : (float) $aliquotaIva,
                // Assente significa «no», che è il caso normale del contributo
                // integrativo. Si legge invece di dedurlo dal TipoCassa: così la
                // risposta la dà il documento, non una nostra tabella da mantenere.
                soggettaRitenuta: strtoupper((string) $this->testo($xpath, './Ritenuta', $nodo)) === 'SI',
            );
        }

        return $casse;
    }

    /**
     * @return FatturaPaRiga[]
     */
    private function parseRighe(DOMXPath $xpath, DOMElement $body): array
    {
        $righe = [];
        $numero = 0;

        foreach ($xpath->query('./DatiBeniServizi/DettaglioLinee', $body) as $linea) {
            $numero++;
            $importo = $this->testo($xpath, './PrezzoTotale', $linea);
            $aliquota = $this->testo($xpath, './AliquotaIVA', $linea);

            // Entrambi minOccurs="1" nello XSD ufficiale (verificato
            // 01/09/2026): assenti o vuoti, il file è malformato — non è
            // un dato mancante da lasciare a zero.
            if ($importo === null || $aliquota === null) {
                throw new FatturaPaParseException(
                    "Una riga DettaglioLinee non ha PrezzoTotale o AliquotaIVA (riga {$numero})."
                );
            }

            $righe[] = new FatturaPaRiga(
                descrizione: $this->testo($xpath, './Descrizione', $linea) ?? '',
                importoImponibileCents: $this->cents($importo, "PrezzoTotale (riga {$numero})"),
                aliquotaIva: $this->percentuale($aliquota, "AliquotaIVA (riga {$numero})"),
                natura: $this->testo($xpath, './Natura', $linea),
            );
        }

        if ($righe === []) {
            throw new FatturaPaParseException('Nessuna riga DettaglioLinee nel documento.');
        }

        return $righe;
    }

    /**
     * @return FatturaPaRiepilogo[]
     */
    private function parseRiepiloghi(DOMXPath $xpath, DOMElement $body): array
    {
        $riepiloghi = [];
        $numero = 0;

        foreach ($xpath->query('./DatiBeniServizi/DatiRiepilogo', $body) as $r) {
            $numero++;
            $imponibile = $this->testo($xpath, './ImponibileImporto', $r);
            $imposta = $this->testo($xpath, './Imposta', $r);
            $aliquota = $this->testo($xpath, './AliquotaIVA', $r);

            // Tutti e tre minOccurs="1" nello XSD: se mancano, il
            // documento non dichiara i propri totali e non è registrabile.
            if ($imponibile === null || $imposta === null || $aliquota === null) {
                throw new FatturaPaParseException(
                    "Un blocco DatiRiepilogo è incompleto (blocco {$numero}): manca aliquota, imponibile o imposta."
                );
            }

            $riepiloghi[] = new FatturaPaRiepilogo(
                aliquotaIva: $this->percentuale($aliquota, 'AliquotaIVA di riepilogo'),
                natura: $this->testo($xpath, './Natura', $r),
                imponibileCents: $this->cents($imponibile, 'ImponibileImporto'),
                impostaCents: $this->cents($imposta, 'Imposta'),
            );
        }

        if ($riepiloghi === []) {
            throw new FatturaPaParseException('Nessun DatiRiepilogo nel documento: la fattura non dichiara i propri totali.');
        }

        return $riepiloghi;
    }

    /**
     * @return FatturaPaScadenza[]
     */
    private function parseScadenze(DOMXPath $xpath, DOMElement $body): array
    {
        $scadenze = [];
        $numero = 0;

        // DatiPagamento è 0..N e DettaglioPagamento è 1..N dentro ognuno:
        // un XPath diretto su DettaglioPagamento le prende tutte insieme,
        // a prescindere da quanti blocchi DatiPagamento le contengono.
        foreach ($xpath->query('./DatiPagamento/DettaglioPagamento', $body) as $dettaglio) {
            $numero++;
            $importo = $this->testo($xpath, './ImportoPagamento', $dettaglio);

            // minOccurs="1" nello XSD. Scartare la rata in silenzio
            // significherebbe proporre un piano di pagamento incompleto
            // senza che nessuno lo sappia: meglio rifiutare il file.
            if ($importo === null) {
                throw new FatturaPaParseException(
                    "Una rata di pagamento non ha ImportoPagamento (rata {$numero})."
                );
            }

            $scadenze[] = new FatturaPaScadenza(
                data: $this->testo($xpath, './DataScadenzaPagamento', $dettaglio),
                importoCents: $this->cents($importo, "ImportoPagamento (rata {$numero})"),
                modalitaPagamento: $this->testo($xpath, './ModalitaPagamento', $dettaglio),
                // IBAN è minOccurs="0": obbligatorio solo per i mezzi che lo richiedono
                // (bonifico), lo XSD non lo impone per contanti o assegno.
                iban: $this->testo($xpath, './IBAN', $dettaglio),
            );
        }

        return $scadenze;
    }

    /**
     * @return FatturaPaRitenuta[]
     */
    private function parseRitenute(DOMXPath $xpath, DOMElement $body): array
    {
        $ritenute = [];
        $numero = 0;

        foreach ($xpath->query('./DatiGenerali/DatiGeneraliDocumento/DatiRitenuta', $body) as $rit) {
            $numero++;
            $importo = $this->testo($xpath, './ImportoRitenuta', $rit);
            $aliquota = $this->testo($xpath, './AliquotaRitenuta', $rit);

            // I quattro campi di DatiRitenutaType sono tutti minOccurs="1".
            // Una ritenuta persa in silenzio è denaro che l'amministratore
            // crede di dover pagare al fornitore e invece deve all'erario.
            if ($importo === null || $aliquota === null) {
                throw new FatturaPaParseException(
                    "Un blocco DatiRitenuta è incompleto (blocco {$numero}): manca importo o aliquota."
                );
            }

            $ritenute[] = new FatturaPaRitenuta(
                tipoRitenuta: $this->testo($xpath, './TipoRitenuta', $rit),
                importoCents: $this->cents($importo, 'ImportoRitenuta'),
                aliquota: $this->percentuale($aliquota, 'AliquotaRitenuta'),
                causalePagamento: $this->testo($xpath, './CausalePagamento', $rit),
            );
        }

        return $ritenute;
    }

    /**
     * Converte in centesimi solo ciò che è davvero un numero.
     *
     * MoneyHelper::toCents() nasce per l'input di un modulo mascherato e
     * non solleva mai: gli si passa "abc" e restituisce 0, gli si passa
     * "12 EUR" e restituisce 1200 — un numero plausibile ottenuto
     * troncando ciò che non ha capito. Su un file che arriva da fuori
     * quel silenzio è inaccettabile, quindi il controllo sta qui e la
     * conversione resta la sua, che è l'unica del progetto.
     */
    private function cents(string $valore, string $campo): int
    {
        if (! is_numeric($valore)) {
            throw new FatturaPaParseException("Il campo {$campo} non contiene un numero: «{$valore}».");
        }

        return MoneyHelper::toCents($valore);
    }

    /**
     * Un'aliquota illeggibile non può diventare 0.0: in questo dominio
     * zero non è un valore neutro, significa «operazione esente o non
     * imponibile». Un (float) nudo le confonde.
     */
    private function percentuale(string $valore, string $campo): float
    {
        if (! is_numeric($valore)) {
            throw new FatturaPaParseException("Il campo {$campo} non contiene una percentuale: «{$valore}».");
        }

        return (float) $valore;
    }

    /**
     * Restituisce null sia quando l'elemento manca sia quando è presente
     * ma vuoto.
     *
     * La distinzione fra i due casi non serve a nessun chiamante e
     * costava cara: le guardie della classe confrontano con `=== null`, e
     * un generatore che emette <Numero/> invece di omettere il tag le
     * attraversava tutte — dando una fattura senza numero, righe da zero
     * centesimi e una data vuota che a valle Carbon interpreta come oggi.
     * È la stessa normalizzazione dei parser dell'importatore Danea.
     */
    private function testo(DOMXPath $xpath, string $query, DOMNode $contesto): ?string
    {
        $risultato = $xpath->query($query, $contesto);
        $nodo = $risultato === false ? null : $risultato->item(0);

        if ($nodo === null) {
            return null;
        }

        $testo = trim($nodo->textContent);

        return $testo === '' ? null : $testo;
    }
}
