<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\DataTransferObjects\FatturaElettronica\FatturaPaCassaPrevidenziale;
use App\DataTransferObjects\FatturaElettronica\FatturaPaFattura;
use App\Enums\MetodoPagamento;
use App\Exceptions\FatturaElettronica\FatturaPaParseException;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\ImportaFatturaXmlRequest;
use App\Models\Condominio;
use App\Services\FatturaElettronica\FatturaPaParser;
use App\Services\FatturaElettronica\RicercaFornitoreXml;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Legge un XML (o `.p7m`) e restituisce i dati per precompilare il modulo di
 * registrazione — beta.14, decisione 1 di apertura («due porte, una stanza»,
 * `docs/lettura_xml_fatture_passive.md`). Non crea nessuna fattura: il salvataggio
 * resta `FatturaPassivaController::store()`, invariato.
 *
 * ⚠️ **Il confine centesimi/euro si chiude qui, non nel parser né nel servizio**
 * (decisione 5 della stessa apertura). Il parser lavora in centesimi;
 * `FatturaPassivaService::registraFattura()` riceve euro e moltiplica lui per 100 — è
 * la classe di difetto del ×100 costato la beta.32. `MoneyHelper::fromCents()` converte
 * ogni valore letto dal parser **prima** che lasci questo controller: il servizio non
 * sa mai che l'origine del dato era un file.
 */
class ImportaFatturaXmlController extends Controller
{
    public function __invoke(
        Condominio $condominio,
        ImportaFatturaXmlRequest $request,
        FatturaPaParser $parser,
        RicercaFornitoreXml $ricercaFornitore,
    ): JsonResponse {
        try {
            $fatture = $parser->parse(
                $request->file('file')->get(),
                $request->file('file')->getClientOriginalName(),
            );
        } catch (FatturaPaParseException $e) {
            // ⚠️ **Il dettaglio tecnico si scrive qui, non si mostra là.** Il messaggio
            // dell'eccezione va a schermo tale e quale ed è scritto per un amministratore
            // di condominio; il gergo del parser (`Specification mandates value for
            // attribute…`) serve a noi quando qualcuno segnala «non riesce a caricare la
            // fattura», e senza questa riga andrebbe perso. Il log è il posto dove lo
            // leggiamo noi — vedi il docblock di `FatturaPaParseException`.
            if ($e->dettaglioTecnico !== null) {
                Log::warning('FatturaPA: file non leggibile', [
                    'condominio_id' => $condominio->id,
                    'file' => $request->file('file')?->getClientOriginalName(),
                    'dettaglio' => $e->dettaglioTecnico,
                    'riga' => $e->riga,
                ]);
            }

            return response()->json(['errore' => $e->getMessage()], 422);
        }

        // ⚠️ Un lotto (più `FatturaElettronicaBody`) è ammesso dallo schema, ma questa
        // porta ne precompila **una sola**: il modulo di registrazione è per un
        // documento alla volta. Il resto del lotto non si perde silenziosamente — va
        // dichiarato, non scartato senza dirlo (Coda: import multiplo è la beta.16).
        $fattura = $fatture[0];

        // ⚠️ **Decisione del 02/09/2026**: l'importazione resta dentro un condominio
        // solo (Vincenzo: «rimarrei nello stesso condominio per non complicarci la
        // vita»), scartata l'alternativa di smistare fra più condomìni per codice
        // fiscale del `CessionarioCommittente` — quella resta la beta.16. Ma «resta
        // dentro un condominio» deve **dirlo**, non ignorarlo: un file emesso per un
        // altro condominio si rifiuta spiegando perché, con lo stesso 422 usato per un
        // file malformato — non un avviso che si può scavalcare, il fornitore
        // sbagliato imputato al condominio sbagliato è il tipo di errore che il
        // rendiconto non perdona.
        //
        // Rifiuta **solo** quando entrambi i codici fiscali sono noti e diversi: un
        // file che non dichiara CessionarioCommittente (raro, ma il parser non lo
        // richiede — vedi FatturaPaFattura::$cessionarioCodiceFiscale) o un
        // condominio senza codice fiscale in anagrafica non bastano a escludere
        // nulla, e bloccare su un'assenza sarebbe rifiutare file legittimi per una
        // domanda a cui il sistema stesso non sa rispondere.
        if (
            $fattura->cessionarioCodiceFiscale !== null
            && $condominio->codice_fiscale !== null
            && mb_strtoupper(trim($fattura->cessionarioCodiceFiscale)) !== mb_strtoupper(trim($condominio->codice_fiscale))
        ) {
            return response()->json([
                'errore' => sprintf(
                    'Questo file è intestato a "%s" (codice fiscale %s), non a %s: non è stato importato.',
                    $fattura->cessionarioDenominazione ?? 'un altro destinatario',
                    $fattura->cessionarioCodiceFiscale,
                    $condominio->nome,
                ),
            ], 422);
        }

        return response()->json([
            'documento' => $this->mappaDocumento($fattura),
            'righe' => $this->mappaRighe($fattura),
            'fornitore' => $this->mappaFornitore($fattura, $ricercaFornitore),
            'ritenuta' => $this->mappaRitenuta($fattura),
            'avvisi' => [
                'lotto_con_altri_documenti' => count($fatture) - 1,
                'righe_non_quadrano_col_riepilogo' => $fattura->righeNonQuadranoColRiepilogo(),
                'scarto_righe_riepilogo_cents' => $fattura->scartoRigheRiepilogoCents(),
                // I tipi previdenziali dichiarati dal file (RT03…RT06). Non li trattiamo —
                // li versa il fornitore al proprio ente, non il condominio — ma tacerli
                // sarebbe peggio: chi confronta il totale del file con quello a schermo
                // deve poter capire da dove viene la differenza.
                'contributi_previdenziali_dichiarati' => $this->contributiPrevidenzialiDichiarati($fattura),
            ],
        ]);
    }

    private function mappaDocumento(FatturaPaFattura $fattura): array
    {
        $primaScadenza = $fattura->scadenze[0] ?? null;

        return [
            'tipo_documento' => $fattura->isNotaCredito() ? 'nota_credito' : 'fattura',
            'numero_documento' => $fattura->numeroDocumento,
            'data_documento' => $fattura->dataDocumento,
            'data_scadenza' => $primaScadenza?->data,
            'modalita_pagamento' => $this->mappaModalitaPagamento($primaScadenza?->modalitaPagamento),
            'iban_fornitore' => $primaScadenza?->iban,
        ];
    }

    /**
     * @return array<int, array{descrizione: string, importo_imponibile: float, aliquota_iva: float}>
     *
     * ⚠️ **Il segno si normalizza qui, guardando il tipo di documento — non prima.**
     * Trovato dalla revisione avversariale della beta.14: lo schema ammette una nota di
     * credito TD04 con importi negativi nel file (è la forma della fixture gemella
     * `sintetica_nota_credito_importi_negativi.xml`), e `FatturaPassivaService::registraFattura()`
     * si aspetta SEMPRE una magnitudine positiva in `righe.*.importo_imponibile`: è lui che
     * applica `$moltiplicatore = -1` quando `tipo_documento === 'nota_credito'`
     * (`app/Services/Gestionale/FatturaPassivaService.php:36`). Passare la riga così com'è
     * quando il file la dichiara già negativa fa applicare due segni meno: il credito
     * torna positivo e si registra come debito.
     *
     * Per una fattura ordinaria (TD01) `abs()` **non va applicato**: il file 06 del
     * collaudo sugli undici XML veri (docs/lettura_xml_fatture_passive.md) ha una riga
     * legittimamente negativa — un conguaglio sugli oneri di sistema dentro una bolletta
     * gas — e forzarla positiva raddoppierebbe l'aggiustamento invece di annullarlo.
     */
    private function mappaRighe(FatturaPaFattura $fattura): array
    {
        $righe = array_map(
            fn ($riga) => [
                'descrizione' => $riga->descrizione,
                'importo_imponibile' => MoneyHelper::fromCents(
                    $fattura->isNotaCredito() ? abs($riga->importoImponibileCents) : $riga->importoImponibileCents
                ),
                'aliquota_iva' => $riga->aliquotaIva,
            ],
            $fattura->righe,
        );

        return array_merge($righe, $this->righeDaContributiCassa($fattura));
    }

    /**
     * Il contributo cassa previdenziale, come riga di spesa.
     *
     * ⚠️ **Senza questo, la fattura si registrava in difetto e in silenzio.** Il
     * contributo vive in `DatiGeneraliDocumento`, non nelle `DettaglioLinee`: mappando
     * solo le righe, su una parcella di geometra da € 4.099,20 se ne registravano
     * € 3.904,00 — € 195,20 in meno, senza nessun avviso, perché la spia «le righe non
     * quadrano» era stata spenta il giorno prima sottraendo il contributo dallo scarto
     * (Fase 1-bis della beta.14, reperto 1, misurato sull'endpoint vero).
     *
     * Perché una riga e non un campo a sé sta nel docblock di
     * `FatturaPaCassaPrevidenziale`: in due parole, non è una partita di giro come la
     * ritenuta ma **costo del servizio**, concorre alla base IVA e aumenta il netto da
     * pagare invece di ridurlo.
     *
     * ## ⚠️ `concorre_base_ritenuta` è la riga che tiene in piedi l'F24
     *
     * Il default di quel campo è `true` **ovunque** (qui a valle
     * `RitenutaService::sommaBase()` con `?? true`, e nel frontend con `!== false`).
     * Lasciandolo al default, il contributo entrerebbe nella base della ritenuta: su
     * quella parcella la base salirebbe da € 3.200,00 a € 3.360,00, quindi **si
     * verserebbe all'Erario più del dovuto e si pagherebbe al professionista di meno**,
     * con l'F24 sbagliato di conseguenza. Prima di questa correzione l'F24 era giusto
     * per omissione — il contributo non entrava proprio nel sistema.
     *
     * Il valore si **legge dal file** (`<Ritenuta>` dentro il blocco cassa) invece di
     * dedurlo dal `TipoCassa`: il contributo integrativo di regola non è soggetto a
     * ritenuta, ma esistono casi in cui lo è (la rivalsa di chi non ha una cassa), e
     * chi lo sa è il documento — non una tabella nostra da tenere aggiornata.
     *
     * ## L'aliquota IVA è quella del blocco, non quella delle righe
     *
     * Lo schema mette un'`AliquotaIVA` dentro `DatiCassaPrevidenziale` proprio perché
     * può differire da quella delle prestazioni. Il ripiego sull'aliquota della prima
     * riga serve solo ai file che la omettono: senza, la riga nascerebbe con aliquota
     * nulla e il totale non tornerebbe con il riepilogo del documento.
     *
     * @return array<int, array<string, mixed>>
     */
    private function righeDaContributiCassa(FatturaPaFattura $fattura): array
    {
        $aliquotaDiRipiego = $fattura->righe[0]->aliquotaIva ?? 0.0;

        // ⚠️ **Un contributo a zero non diventa una riga.** Esiste: la fixture
        // `reale_anonimizzata_cassa_previdenziale.xml` dichiara il blocco con
        // `ImportoContributoCassa` a 0,00 (un professionista che quel mese non lo
        // addebita). Generarla comunque aggiungerebbe all'amministratore una riga da
        // € 0,00 **che deve pure assegnare a un capitolo**, perché la validazione lo
        // pretende per ogni riga non contrassegnata come fuori preventivo: lavoro in
        // più per un importo che non esiste. È lo stesso difetto che la Fase 1-bis ha
        // segnalato per le righe descrittive a zero (reperto 3), e non lo si aggiunge
        // di nuovo qui.
        $conImporto = array_filter(
            $fattura->cassePrevidenziali,
            fn ($cassa) => $cassa->importoContributoCents !== 0,
        );

        return array_map(
            fn ($cassa) => [
                'descrizione' => $this->descrizioneContributoCassa($cassa),
                'importo_imponibile' => MoneyHelper::fromCents(
                    $fattura->isNotaCredito() ? abs($cassa->importoContributoCents) : $cassa->importoContributoCents
                ),
                'aliquota_iva' => $cassa->aliquotaIva ?? $aliquotaDiRipiego,
                'concorre_base_ritenuta' => $cassa->soggettaRitenuta,
            ],
            $conImporto,
        );
    }

    /**
     * «Contributo cassa previdenziale 5% (TC03)» — la percentuale e la cassa vengono
     * dal file, così l'amministratore riconosce la riga e sa da dove esce invece di
     * trovarsi un importo senza nome in mezzo alle altre.
     */
    private function descrizioneContributoCassa(FatturaPaCassaPrevidenziale $cassa): string
    {
        $pezzi = ['Contributo cassa previdenziale'];

        if ($cassa->aliquotaContributo !== null) {
            $pezzi[] = rtrim(rtrim(number_format($cassa->aliquotaContributo, 2, ',', ''), '0'), ',').'%';
        }

        if ($cassa->tipoCassa !== null) {
            $pezzi[] = '('.$cassa->tipoCassa.')';
        }

        return implode(' ', $pezzi);
    }

    /**
     * ⚠️ **Trovato dalla revisione avversariale della beta.14, non ancora corretto fino ad
     * ora**: il parser legge `<DatiRitenuta>` (`FatturaPaParser::parseRitenute()`) ma questo
     * controller non lo esponeva mai — la ritenuta dichiarata dal fornitore spariva in
     * silenzio, e chi registrava a mano doveva riscoprirla da capo leggendo l'allegato.
     *
     * Restituisce solo **ciò che il file dichiara di sé**: non decide se applicarla (quello
     * dipende da `Fornitore::soggetto_ritenuta`, una proprietà dell'anagrafica che l'XML non
     * conosce — vedi `StoreFatturaRequest::withValidator()`), non calcola l'importo. È
     * un'informazione da mostrare per il confronto, non un valore da scrivere a occhi chiusi:
     * la scelta di applicarla resta all'amministratore, come per ogni altro campo importato.
     *
     * Un documento senza `<DatiRitenuta>` restituisce `null`, non un blocco a zero: zero
     * ritenuta e ritenuta-non-dichiarata sono due fatti diversi.
     */
    /**
     * I `TipoRitenuta` previdenziali dichiarati dal documento, senza ripetizioni.
     *
     * @return array<int, string>
     */
    private function contributiPrevidenzialiDichiarati(FatturaPaFattura $fattura): array
    {
        $tipi = [];

        foreach ($fattura->ritenute as $blocco) {
            if ($blocco->isRitenutaAcconto()) {
                continue;
            }

            // Il tipo assente finisce qui insieme ai previdenziali — `isRitenutaAcconto()`
            // lo esclude apposta — ma un `null` non si può nominare a schermo: si conta
            // come «non determinato» e lo si lascia fuori dall'elenco.
            $tipo = $blocco->tipoRitenuta !== null ? strtoupper($blocco->tipoRitenuta) : null;
            if ($tipo !== null && ! in_array($tipo, $tipi, true)) {
                $tipi[] = $tipo;
            }
        }

        return $tipi;
    }

    private function mappaRitenuta(FatturaPaFattura $fattura): ?array
    {
        // ⚠️ **La PRIMA ritenuta d'acconto, non il primo blocco.** `<DatiRitenuta>` è
        // ripetibile (`maxOccurs="unbounded"`) e lo schema non impone nessun ordine: un
        // documento può portare un contributo previdenziale prima della ritenuta vera.
        // Prendere `ritenute[0]` era una scelta arbitraria travestita da indice — con un
        // ENASARCO in testa si esponeva un contributo del fornitore come denaro da
        // trattenere e versare all'Erario. Il filtro sta qui, dove si assegna il
        // significato, e non nel parser, che deve continuare a leggere il file per intero.
        $ritenuta = null;
        foreach ($fattura->ritenute as $blocco) {
            if ($blocco->isRitenutaAcconto()) {
                $ritenuta = $blocco;
                break;
            }
        }

        if ($ritenuta === null) {
            return null;
        }

        return [
            'tipo' => $ritenuta->tipoRitenuta,
            'importo' => MoneyHelper::fromCents($ritenuta->importoCents),
            'aliquota' => $ritenuta->aliquota,
            'causale_pagamento' => $ritenuta->causalePagamento,
        ];
    }

    private function mappaFornitore(FatturaPaFattura $fattura, RicercaFornitoreXml $ricerca): array
    {
        $candidati = $ricerca->cerca(
            $fattura->fornitorePartitaIva,
            $fattura->fornitorePartitaIvaPaese,
            $fattura->fornitoreCodiceFiscale,
        );

        return [
            // ⚠️ Decisione 3: mai creazione qui, solo aggancio. `esito` guida il
            // frontend fra i tre stati (`docs/lettura_xml_fatture_passive.md`).
            'esito' => match (true) {
                $candidati->count() === 1 => 'trovato',
                $candidati->count() > 1 => 'ambiguo',
                default => 'non_trovato',
            },
            'candidati' => $candidati->map(fn ($f) => [
                'id' => $f->id,
                'ragione_sociale' => $f->ragione_sociale,
            ])->values(),
            // ⚠️ Aggiunto aprendo la riprogettazione della UI (02/09/2026): fin qui questo
            // blocco esponeva solo i tre campi usati per l'aggancio. Il parser legge da
            // tempo anche indirizzo/comune/email/regime fiscale del cedente (servivano già
            // a `RicercaFornitoreXml`? no: servono alla creazione, che prima non c'era) —
            // mancavano solo nella risposta. `regime_forfetario` è un'inferenza dichiarata,
            // non un campo del file: FatturaPA usa RF19 per il regime forfetario, e
            // `fornitori.regime_forfetario` è l'unico posto in anagrafica dove quel fatto
            // vive. Tutto il resto del regime fiscale (RF01, RF18…) non ha una colonna
            // corrispondente e non si inventa.
            'letto_da_xml' => [
                'denominazione' => $fattura->fornitoreDenominazione,
                'partita_iva' => $fattura->fornitorePartitaIva,
                'partita_iva_paese' => $fattura->fornitorePartitaIvaPaese,
                'codice_fiscale' => $fattura->fornitoreCodiceFiscale,
                'indirizzo' => $fattura->fornitoreIndirizzo,
                'cap' => $fattura->fornitoreCap,
                'comune' => $fattura->fornitoreComune,
                'provincia' => $fattura->fornitoreProvincia,
                'nazione' => $fattura->fornitoreNazione,
                'email' => $fattura->fornitoreEmail,
                'regime_forfetario' => $fattura->fornitoreRegimeFiscale === 'RF19',
            ],
        ];
    }

    /**
     * ⚠️ **Solo i tre codici di cui questo commento può rispondere con certezza.**
     * FatturaPA ne definisce 23 (MP01-MP23); mappare gli altri a memoria rischierebbe di
     * scrivere un valore sbagliato in un campo che sembra compilato correttamente — peggio
     * di lasciarlo vuoto. Il default del form resta `bonifico`, e il campo è sempre
     * modificabile a mano: coerente con «tutti i campi comunque modificabili manualmente»
     * (sezione 2 di questo documento).
     */
    private function mappaModalitaPagamento(?string $codice): ?string
    {
        return match ($codice) {
            'MP01' => MetodoPagamento::CONTANTI->value,
            'MP02' => MetodoPagamento::ASSEGNO->value,
            'MP05' => MetodoPagamento::BONIFICO->value,
            default => null,
        };
    }
}
