<?php

use App\Exceptions\FatturaElettronica\FatturaPaParseException;
use App\Services\FatturaElettronica\FatturaPaParser;

/**
 * FatturaPaParser legge un file FatturaPA (XML o .p7m) e restituisce dati
 * grezzi — nessun database, nessun riconoscimento del fornitore.
 *
 * ## Cosa questi test NON coprono
 *
 * - Il riconoscimento del fornitore per partita IVA, la scelta del conto
 *   per riga e il salvataggio: sono i passi successivi dell'arco
 *   (docs/lettura_xml_fatture_passive.md), non ancora scritti.
 * - FatturaPA **semplificata** (FSM10) e autofattura: fuori dal perimetro
 *   dichiarato. Un file semplificato oggi fallisce su DettaglioLinee, che
 *   in quel tracciato non esiste — il messaggio è comprensibile ma non
 *   dice «questo è un tracciato diverso».
 * - Una firma `.p7m` **reale**: la fixture di busta è autofirmata (vedi il
 *   test dedicato), quindi è provato il percorso di ESTRAZIONE, non la
 *   validità di una firma vera dello SdI.
 * - **La coerenza aritmetica interna del documento**: il parser non
 *   verifica che imponibile × aliquota faccia l'imposta, né che le rate
 *   sommino al totale. Espone i numeri, non li giudica.
 * - `ScontoMaggiorazione`, `DatiCassaPrevidenziale`, `DatiBollo` e
 *   `Allegati`: non vengono letti. Il loro effetto sui totali è però
 *   visibile, perché DatiRiepilogo li incorpora ed è letto.
 * - Il comportamento con file di dimensioni patologiche (centinaia di
 *   migliaia di righe): non misurato.
 */
function leggiFixtureFatturaPa(string $nome): string
{
    // Percorso relativo a __DIR__, non base_path(): questa suite Unit non
    // fa bootstrap del container (Pest.php lega Tests\TestCase solo a
    // Feature), stesso pattern di tests/Unit/Import/LetturaExportDaneaTest.php.
    return file_get_contents(__DIR__.'/../../Fixtures/fatturapa/'.$nome);
}

it('legge la fattura singola ufficiale a una riga (FPR01)', function () {
    $fatture = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR01.xml'));

    expect($fatture)->toHaveCount(1);

    $f = $fatture[0];
    expect($f->tipoDocumento)->toBe('TD01')
        ->and($f->numeroDocumento)->toBe('123')
        ->and($f->dataDocumento)->toBe('2014-12-18')
        ->and($f->fornitorePartitaIva)->toBe('01234567890')
        ->and($f->fornitorePartitaIvaPaese)->toBe('IT')
        ->and($f->fornitoreDenominazione)->toBe("SOCIETA' ALPHA SRL")
        ->and($f->fornitoreComune)->toBe('SASSARI')
        ->and($f->righe)->toHaveCount(1)
        ->and($f->righe[0]->importoImponibileCents)->toBe(500)
        ->and($f->righe[0]->aliquotaIva)->toBe(22.0)
        ->and($f->scadenze)->toHaveCount(1)
        ->and($f->scadenze[0]->data)->toBe('2015-01-30')
        ->and($f->scadenze[0]->importoCents)->toBe(610)
        ->and($f->ritenute)->toBe([])
        ->and($f->isNotaCredito())->toBeFalse();
});

it('legge fornitorePartitaIvaPaese distinto dalle cifre, per un cedente estero', function () {
    // Trovato dalla revisione avversariale della beta.14: IdFiscaleIVA è IdPaese +
    // IdCodice, e il parser leggeva solo IdCodice. Mutando la sola IdPaese del
    // CedentePrestatore (mai quella di IdTrasmittente, che è un soggetto diverso) su
    // una fixture altrimenti reale, invece di costruire un XML sintetico da zero.
    $originale = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $xml = preg_replace_callback(
        '/<CedentePrestatore>.*?<\/CedentePrestatore>/s',
        fn ($m) => str_replace('<IdPaese>IT</IdPaese>', '<IdPaese>FR</IdPaese>', $m[0]),
        $originale
    );

    // La sostituzione è avvenuta, ed è avvenuta una volta sola: se ne avesse toccate
    // altre (IdTrasmittente, o il vettore in DatiTrasporto — la fixture ne ha un
    // terzo) il test non isolerebbe più il campo giusto.
    expect($xml)->not->toBe($originale)
        ->and(substr_count($xml, '<IdPaese>FR</IdPaese>'))->toBe(1);

    $f = (new FatturaPaParser)->parse($xml)[0];

    expect($f->fornitorePartitaIva)->toBe('01234567890')
        ->and($f->fornitorePartitaIvaPaese)->toBe('FR');
});

it('legge anche CessionarioCommittente, fratello del cedente non figlio', function () {
    // Aggiunto il 02/09/2026 decidendo di restare nell'importazione XML dentro un
    // condominio solo: un file intestato a un altro va rifiutato spiegando perché,
    // non smistato — e per saperlo serve leggere CHI viene fatturato, non solo chi
    // vende. Il parser non lo leggeva affatto fino ad ora.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR01.xml'))[0];

    expect($f->cessionarioCodiceFiscale)->not->toBeNull()
        ->and($f->cessionarioDenominazione)->not->toBeNull();
});

it('un CessionarioCommittente senza denominazione né nome/cognome dà null, non un\'eccezione', function () {
    // Controprova incrociata su denominazioneOpzionale(): a differenza del cedente
    // (denominazione(), che rifiuta il file), un cessionario mal scritto non deve
    // impedire la lettura del resto — serve solo al confronto a valle, e un file
    // altrimenti valido non si scarta per un campo che nessuno chiedeva prima d'ora.
    $originale = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $xml = preg_replace(
        '#<CessionarioCommittente>.*?</CessionarioCommittente>#s',
        '<CessionarioCommittente><DatiAnagrafici><CodiceFiscale>80123450158</CodiceFiscale></DatiAnagrafici></CessionarioCommittente>',
        $originale
    );
    expect($xml)->not->toBe($originale);

    $f = (new FatturaPaParser)->parse($xml)[0];

    expect($f->cessionarioCodiceFiscale)->toBe('80123450158')
        ->and($f->cessionarioDenominazione)->toBeNull();
});

it('la Causale ripetuta si unisce, non si perde la seconda metà', function () {
    // FPR01 spezza la causale su due nodi <Causale> — è il tracciato che lo
    // impone quando supera i 200 caratteri, non un difetto del file.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR01.xml'))[0];

    expect($f->causale)
        ->toContain('LA FATTURA FA RIFERIMENTO')
        ->toContain('SEGUE DESCRIZIONE CAUSALE');
});

it('legge OGNI riga della fattura a più righe, non solo la prima (FPR02)', function () {
    // La versione precedente di questo test asseriva solo toHaveCount(2), e
    // per questo era cieca: sostituendo l'XPath relativo './PrezzoTotale' con
    // l'assoluto '//PrezzoTotale' — l'errore classico con un nodo di contesto —
    // ogni riga prendeva il primo importo del documento e i test restavano
    // tutti verdi. Ora gli importi si guardano riga per riga.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR02.xml'))[0];

    expect($f->righe)->toHaveCount(2)
        ->and($f->righe[0]->importoImponibileCents)->toBe(500)
        ->and($f->righe[1]->importoImponibileCents)->toBe(2000)
        ->and($f->righe[1]->descrizione)->toBe('FORNITURE VARIE PER UFFICIO');
});

it('legge il lotto ufficiale tenendo separati i due documenti (FPR03)', function () {
    // È la trappola esplicita del documento di progetto: FatturaElettronicaBody
    // è ripetibile, e un parser che assume un solo Body ne perde metà in
    // silenzio. Asserire i soli numeri di documento non bastava: si verificano
    // gli importi, che sono ciò che l'isolamento fra Body può rompere.
    $fatture = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR03.xml'));

    expect($fatture)->toHaveCount(2)
        ->and($fatture[0]->numeroDocumento)->toBe('123')
        ->and($fatture[0]->righe)->toHaveCount(2)
        ->and($fatture[0]->imponibileDichiaratoCents())->toBe(2700)
        ->and($fatture[1]->numeroDocumento)->toBe('456')
        ->and($fatture[1]->righe)->toHaveCount(1)
        ->and($fatture[1]->righe[0]->importoImponibileCents)->toBe(200000)
        ->and($fatture[1]->imponibileDichiaratoCents())->toBe(200000)
        ->and($fatture[1]->impostaDichiarataCents())->toBe(44000);
});

it('espone l\'imponibile che la fattura dichiara, distinto dalla somma delle righe', function () {
    // Su FPR02 — esempio UFFICIALE dell'Agenzia — le righe fanno € 25,00 e il
    // riepilogo dichiara € 27,00. Chi registra deve vedere 27,00, che è ciò
    // che il fornitore chiede; e la discordanza va segnalata, non nascosta.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR02.xml'))[0];

    expect($f->sommaRigheCents())->toBe(2500)
        ->and($f->imponibileDichiaratoCents())->toBe(2700)
        ->and($f->impostaDichiarataCents())->toBe(595)
        ->and($f->righeNonQuadranoColRiepilogo())->toBeTrue()
        // Lo scarto si espone in centesimi e non come booleano, come fa
        // CanonicalCapitolo::scartoCents() nell'importatore: è la
        // differenza a dire se è una causale legittima o un errore.
        ->and($f->scartoRigheRiepilogoCents())->toBe(200);
});

it('non segnala discordanze quando righe e riepilogo coincidono', function () {
    // Controprova del test qui sopra: la segnalazione deve distinguere, non
    // essere sempre vera.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_con_ritenuta.xml'))[0];

    expect($f->sommaRigheCents())->toBe(40000)
        ->and($f->imponibileDichiaratoCents())->toBe(40000)
        ->and($f->scartoRigheRiepilogoCents())->toBe(0)
        ->and($f->righeNonQuadranoColRiepilogo())->toBeFalse();
});

it('la cassa previdenziale spiega lo scarto, non lo nasconde da un errore vero', function () {
    // Trovato dal collaudo dal vivo sugli undici file veri del forum (02/09/2026,
    // docs/lettura_xml_fatture_passive.md): una cassa geometri al 5% (contributo
    // € 160,00) produceva «le righe non quadrano» su questa fattura, che è
    // corretta. Fixture reale, non costruita: file 10 del collaudo.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml'))[0];

    expect($f->sommaRigheCents())->toBe(320000)
        ->and($f->imponibileDichiaratoCents())->toBe(336000) // include la cassa
        ->and($f->contributoCassaPrevidenzialeCents())->toBe(16000)
        ->and($f->scartoRigheRiepilogoCents())->toBe(0)
        ->and($f->righeNonQuadranoColRiepilogo())->toBeFalse();

    // ⚠️ I campi che a valle tengono corretto l'F24: l'aliquota IVA è quella del blocco
    // (non si eredita dalle righe) e `soggettaRitenuta` dice se il contributo entra
    // nella base della ritenuta. Qui il file non dichiara `<Ritenuta>`, che significa
    // «no» — il caso normale del contributo integrativo.
    $cassa = $f->cassePrevidenziali[0];
    expect($f->cassePrevidenziali)->toHaveCount(1)
        ->and($cassa->tipoCassa)->toBe('TC03')
        ->and($cassa->aliquotaContributo)->toBe(5.0)
        ->and($cassa->importoContributoCents)->toBe(16000)
        ->and($cassa->aliquotaIva)->toBe(22.0)
        ->and($cassa->soggettaRitenuta)->toBeFalse();
});

it('il contributo cassa dichiarato soggetto a ritenuta viene letto come tale', function () {
    // ⚠️ Il caso che tiene onesto l'F24 dal lato opposto: esistono contributi soggetti a
    // ritenuta (la rivalsa di chi non ha una cassa). Si legge `<Ritenuta>` invece di
    // dedurlo dal TipoCassa, così la risposta la dà il documento e non una tabella
    // nostra da tenere aggiornata.
    $originale = leggiFixtureFatturaPa('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml');
    $xml = str_replace('</DatiCassaPrevidenziale>', '<Ritenuta>SI</Ritenuta></DatiCassaPrevidenziale>', $originale);
    // Se la sostituzione non aggancia, il test proverebbe il file originale e passerebbe
    // per il motivo sbagliato.
    expect($xml)->toContain('<Ritenuta>SI</Ritenuta>');

    $f = (new FatturaPaParser)->parse($xml)[0];

    expect($f->cassePrevidenziali[0]->soggettaRitenuta)->toBeTrue();
});

it('un documento senza cassa previdenziale non cambia: il contributo resta zero', function () {
    // Controprova incrociata: la sottrazione non deve inventare uno scarto dove
    // prima non c'era, su un documento che non dichiara nessuna cassa.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_con_ritenuta.xml'))[0];

    expect($f->contributoCassaPrevidenzialeCents())->toBe(0)
        ->and($f->scartoRigheRiepilogoCents())->toBe(0);
});

it('due blocchi DatiCassaPrevidenziale sullo stesso documento si sommano', function () {
    // Lo schema li ammette ripetuti (due casse professionali diverse sullo
    // stesso documento): un solo blocco letto invece di sommarli tutti
    // lascerebbe uno scarto residuo che l'amministratore non saprebbe spiegare.
    $originale = leggiFixtureFatturaPa('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml');
    $secondoBlocco = '<DatiCassaPrevidenziale><TipoCassa>TC03</TipoCassa><AlCassa>2.00</AlCassa>'
        .'<ImportoContributoCassa>40.00</ImportoContributoCassa><ImponibileCassa>2000.00</ImponibileCassa>'
        .'<AliquotaIVA>22.00</AliquotaIVA></DatiCassaPrevidenziale>';
    $xml = str_replace('</DatiCassaPrevidenziale>', '</DatiCassaPrevidenziale>'.$secondoBlocco, $originale, $sostituzioni);

    expect($sostituzioni ?? 1)->toBeGreaterThan(0);

    $f = (new FatturaPaParser)->parse($xml)[0];

    expect($f->contributoCassaPrevidenzialeCents())->toBe(16000 + 4000)
        ->and($f->cassePrevidenziali)->toHaveCount(2);
});

it('DatiCassaPrevidenziale senza ImportoContributoCassa rifiuta il file, non lo ignora', function () {
    $originale = leggiFixtureFatturaPa('collaudo_reali/10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml');
    $xml = preg_replace('#<ImportoContributoCassa>[^<]*</ImportoContributoCassa>#', '', $originale, 1);

    (new FatturaPaParser)->parse($xml);
})->throws(FatturaPaParseException::class, 'ImportoContributoCassa');

it('legge la ritenuta d\'acconto — il caso che nessuna fixture ufficiale copre', function () {
    // Verificato il 01/09/2026: DatiRitenuta è assente da tutti e tre gli
    // esempi dell'Agenzia. È il caso tipico del condominio (compenso al
    // professionista, appalto soggetto a ritenuta), quindi la fixture la
    // costruiamo noi — validata contro l'XSD ufficiale prima di fidarcene.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_con_ritenuta.xml'))[0];

    expect($f->ritenute)->toHaveCount(1);

    $ritenuta = $f->ritenute[0];
    expect($ritenuta->tipoRitenuta)->toBe('RT02')
        ->and($ritenuta->importoCents)->toBe(8000)
        ->and($ritenuta->aliquota)->toBe(20.0)
        ->and($ritenuta->causalePagamento)->toBe('A')
        ->and($f->fornitoreRegimeFiscale)->toBe('RF01')
        ->and($f->fornitoreEmail)->toBe('mario.rossi@example.it');
});

it('il fornitore persona fisica è Nome + Cognome, non Denominazione', function () {
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_con_ritenuta.xml'))[0];

    expect($f->fornitoreDenominazione)->toBe('MARIO ROSSI');
});

it('riconosce una nota di credito (TD04) senza toccarne il segno', function () {
    // Il segno resta quello del file: lo schema ammette entrambe le forme, e
    // normalizzarlo qui renderebbe impossibile distinguerle a valle.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_nota_credito.xml'))[0];

    expect($f->tipoDocumento)->toBe('TD04')
        ->and($f->isNotaCredito())->toBeTrue()
        ->and($f->righe[0]->importoImponibileCents)->toBe(5000)
        ->and($f->imponibileDichiaratoCents())->toBe(5000);
});

it('legge una riga esente con Natura, aliquota zero', function () {
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('sintetica_natura_esente.xml'))[0];

    expect($f->righe[0]->aliquotaIva)->toBe(0.0)
        ->and($f->righe[0]->natura)->toBe('N4')
        ->and($f->righe[0]->importoImponibileCents)->toBe(120000)
        ->and($f->riepiloghi[0]->natura)->toBe('N4')
        ->and($f->impostaDichiarataCents())->toBe(0);
});

it('estrae l\'XML da una busta .p7m — con firma autofirmata, non reale', function () {
    // ⚠️ Limite dichiarato in docs/lettura_xml_fatture_passive.md: prova il
    // percorso di ESTRAZIONE (OPENSSL_CMS_NOVERIFY non controlla la catena
    // dei certificati), non l'autenticità di una firma vera dello SdI. Un
    // .p7m reale, quando arriva, va aggiunto qui accanto.
    $busta = leggiFixtureFatturaPa('IT01234567890_FPR01.xml.p7m');

    $fatture = (new FatturaPaParser)->parse($busta, 'IT01234567890_FPR01.xml.p7m');

    expect($fatture)->toHaveCount(1)
        ->and($fatture[0]->numeroDocumento)->toBe('123')
        ->and($fatture[0]->righe[0]->importoImponibileCents)->toBe(500);
});

it('accetta un XML preceduto dal BOM UTF-8', function () {
    // Il BOM è il caso reale: molti generatori lo emettono. libxml lo gestisce
    // da sé, e il contenuto va passato INTATTO a loadXML() — il ltrim serve
    // solo a riconoscere che è XML, non a ripulirlo.
    //
    // ⚠️ Un a capo prima della dichiarazione <?xml è invece XML genuinamente
    // NON valido («XML declaration allowed only at the start of the
    // document»), e rifiutarlo è corretto: verificato il 01/09/2026 contro
    // una prima stesura di questo test che asseriva il contrario.
    $conBom = "\xEF\xBB\xBF".leggiFixtureFatturaPa('IT01234567890_FPR01.xml');

    expect((new FatturaPaParser)->parse($conBom))->toHaveCount(1);
});

// ---------------------------------------------------------------------
// Ciò che il file può avere di storto
// ---------------------------------------------------------------------

it('rifiuta un file che dichiara un DOCTYPE', function () {
    // Una FatturaPA non ha mai un DOCTYPE: lo schema non lo prevede. È anche
    // la difesa dalla bomba a entità "quadratica", che libxml NON intercetta
    // e che esplodeva alla lettura di textContent con un fatal error non
    // catturabile — un file da 49 KB bastava a uccidere il processo.
    $bomba = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY a "'.str_repeat('A', 100).'">]>'
        .'<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
        .'<FatturaElettronicaBody/></p:FatturaElettronica>';

    (new FatturaPaParser)->parse($bomba);
})->throws(FatturaPaParseException::class, 'DOCTYPE');

it('⚠️ rifiuta il DOCTYPE anche quando un commento lungo lo spinge oltre i primi 4096 byte', function () {
    // Trovato dalla revisione avversariale della beta.14, che l'ha riprodotto su
    // un file da 214 KB: la guardia leggeva `substr($xml, 0, 4096)`, quindi
    // bastava anteporre un commento XML **legale** più lungo di quella finestra
    // per farla passare. Il DOCTYPE arrivava intatto a libxml, l'espansione
    // quadratica partiva, e il worker moriva di out-of-memory NON catturabile —
    // cioè senza nemmeno un'eccezione da mostrare a chi aveva caricato il file.
    //
    // La beta.11 aveva scritto la guardia; la beta.14 l'ha resa raggiungibile
    // mettendo il parser dietro un endpoint di caricamento.
    $riempitivo = str_repeat('x', 5000);
    $bomba = '<?xml version="1.0"?>'
        ."<!-- {$riempitivo} -->"
        .'<!DOCTYPE foo [<!ENTITY a "'.str_repeat('A', 100).'">]>'
        .'<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
        .'<FatturaElettronicaBody/></p:FatturaElettronica>';

    expect(strlen($bomba))->toBeGreaterThan(5000);

    (new FatturaPaParser)->parse($bomba);
})->throws(FatturaPaParseException::class, 'DOCTYPE');

it('un DOCTYPE citato dentro un commento non fa rifiutare un file valido', function () {
    // La controprova della guardia qui sopra: allargare la ricerca non deve
    // trasformarsi in un falso positivo. Un `<!DOCTYPE` scritto dentro un
    // commento è inerte — non dichiara nessuna entità — e il file va letto.
    $originale = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $commento = '<!-- esempio nella documentazione interna: <!DOCTYPE foo> non si usa mai -->';

    // Il commento si infila DOPO la dichiarazione XML e prima dell'elemento
    // radice: è l'unico punto in cui è legale, ed è il prologo che la guardia
    // ispeziona.
    $xml = preg_replace('/(\?>)/', '$1'.$commento, $originale, 1);

    expect($xml)->toContain('<!DOCTYPE');

    $fatture = (new FatturaPaParser)->parse($xml);

    expect($fatture)->not->toBeEmpty();
});

it('un elemento presente ma VUOTO conta come mancante, non come zero', function () {
    // Il caso più insidioso di tutti, e quello che la prima stesura lasciava
    // passare: un generatore che emette <PrezzoTotale/> invece di omettere il
    // tag. Le guardie confrontano con `=== null`, quindi una stringa vuota le
    // attraversava tutte — dando una riga da 0 centesimi dove il file dice
    // € 5,00, e un numero documento vuoto. Il test precedente CANCELLAVA
    // l'elemento, che è l'unico caso in cui il difetto non si vedeva.
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $svuotato = str_replace('<PrezzoTotale>5.00</PrezzoTotale>', '<PrezzoTotale/>', $xml);

    (new FatturaPaParser)->parse($svuotato);
})->throws(FatturaPaParseException::class, 'PrezzoTotale');

it('un numero documento vuoto non produce una fattura senza identificativo', function () {
    // Con <Numero/> il parser restituiva numeroDocumento='' e proseguiva; e
    // con <Data/> la data vuota diventava a valle la data di oggi, perché
    // Carbon::parse('') restituisce adesso. Una fattura che si data da sola
    // al giorno dell'importazione è peggio di un file rifiutato.
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $svuotato = str_replace('<Numero>123</Numero>', '<Numero/>', $xml);

    (new FatturaPaParser)->parse($svuotato);
})->throws(FatturaPaParseException::class, 'dati minimi');

it('rifiuta un importo non numerico invece di leggerlo come zero', function () {
    // MoneyHelper::toCents() non solleva mai: "abc" diventa 0 e "12 EUR"
    // diventa 1200 — un numero plausibile ottenuto troncando ciò che non ha
    // capito. Su un file esterno quel silenzio non è accettabile.
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $rotto = str_replace('<PrezzoTotale>5.00</PrezzoTotale>', '<PrezzoTotale>12 EUR</PrezzoTotale>', $xml);

    (new FatturaPaParser)->parse($rotto);
})->throws(FatturaPaParseException::class, 'non contiene un numero');

it('rifiuta un\'aliquota non numerica invece di leggerla come esente', function () {
    // Qui zero non è un valore neutro: significa «operazione esente o non
    // imponibile». Un (float) nudo trasformava un'aliquota illeggibile in
    // un'esenzione dichiarata.
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $rotto = str_replace('<AliquotaIVA>22.00</AliquotaIVA>', '<AliquotaIVA>ventidue</AliquotaIVA>', $xml);

    (new FatturaPaParser)->parse($rotto);
})->throws(FatturaPaParseException::class, 'non contiene una percentuale');

it('rifiuta un documento senza DatiRiepilogo: non dichiara i propri totali', function () {
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $senza = preg_replace('#<DatiRiepilogo>.*?</DatiRiepilogo>#s', '', $xml);

    (new FatturaPaParser)->parse($senza);
})->throws(FatturaPaParseException::class, 'DatiRiepilogo');

it('rifiuta una rata di pagamento senza importo invece di scartarla in silenzio', function () {
    // Prima c'era un `continue`: la rata spariva e nessuno lo sapeva, e
    // l'amministratore si vedeva proporre un piano di pagamento incompleto.
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $rotto = str_replace('<ImportoPagamento>6.10</ImportoPagamento>', '', $xml);

    (new FatturaPaParser)->parse($rotto);
})->throws(FatturaPaParseException::class, 'ImportoPagamento');

it('rifiuta una ritenuta incompleta invece di scartarla in silenzio', function () {
    // Una ritenuta persa è denaro che l'amministratore crede di dover pagare
    // al fornitore e invece deve all'erario.
    $xml = leggiFixtureFatturaPa('sintetica_con_ritenuta.xml');
    $rotto = str_replace('<ImportoRitenuta>80.00</ImportoRitenuta>', '', $xml);

    (new FatturaPaParser)->parse($rotto);
})->throws(FatturaPaParseException::class, 'DatiRitenuta');

it('rifiuta un CedentePrestatore senza denominazione né nome', function () {
    $xml = leggiFixtureFatturaPa('IT01234567890_FPR01.xml');
    $rotto = str_replace("<Denominazione>SOCIETA' ALPHA SRL</Denominazione>", '', $xml);

    (new FatturaPaParser)->parse($rotto);
})->throws(FatturaPaParseException::class, 'Denominazione');

it('rifiuta un file che non è né XML né una busta riconoscibile', function () {
    (new FatturaPaParser)->parse('questo non è affatto un file FatturaPA', 'pasticcio.txt');
})->throws(FatturaPaParseException::class, 'pasticcio.txt');

it('rifiuta un XML ben formato ma senza FatturaElettronicaBody', function () {
    (new FatturaPaParser)->parse('<?xml version="1.0"?><NonUnaFattura><Foo>bar</Foo></NonUnaFattura>');
})->throws(FatturaPaParseException::class, 'FatturaElettronicaBody');

it('rifiuta un XML malformato con un messaggio che dice cosa non va', function () {
    // La versione precedente non guardava mai il messaggio: un'eccezione con
    // testo vuoto le passava davanti.
    expect(fn () => (new FatturaPaParser)->parse('<?xml version="1.0"?><Aperto><NonChiuso>'))
        ->toThrow(FatturaPaParseException::class, 'non è un file XML valido');
});

it('il messaggio di un file malformato non riversa a schermo il gergo di libxml', function () {
    // ⚠️ Visto eseguendo il collaudo a video del 03/09/2026: l'amministratore leggeva
    // «File XML malformato: Specification mandates value for attribute non» — il testo
    // grezzo di libxml, corretto nel merito e inservibile per chi non scrive software.
    // Il dettaglio tecnico serve, ma nel log: a schermo va detto che cosa fare.
    $messaggio = '';
    try {
        (new FatturaPaParser)->parse('<?xml version="1.0"?><Fattura non></Fattura>');
    } catch (FatturaPaParseException $e) {
        $messaggio = $e->getMessage();
    }

    expect($messaggio)->not->toBe('')
        ->and($messaggio)->not->toContain('Specification mandates')
        ->and($messaggio)->not->toContain('libxml')
        // Deve dire all'amministratore che cosa può fare, non che cosa ha capito il parser.
        ->and($messaggio)->toContain('riscaricalo');
});

it('legge l\'IBAN di DettaglioPagamento, aggiunto aprendo la beta.14', function () {
    // Nessun esempio ufficiale né fixture sintetica precedente porta un IBAN — la
    // fixture reale anonimizzata (procurata da un amministratore del forum) è la
    // prima. Zero test regrediti sull'intera suite conferma che il campo è
    // additivo: `iban` è nullable, e la mancanza non deve mai far scartare la rata.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('reale_anonimizzata_cassa_previdenziale.xml'))[0];

    expect($f->scadenze)->toHaveCount(1)
        ->and($f->scadenze[0]->iban)->toBe('IT43X0100003245100000000001')
        ->and($f->scadenze[0]->modalitaPagamento)->not->toBeNull();
});

it('un IBAN assente resta null, non una stringa vuota che sembra un dato', function () {
    // Nessuna delle fixture ufficiali dell'Agenzia porta un IBAN.
    $f = (new FatturaPaParser)->parse(leggiFixtureFatturaPa('IT01234567890_FPR01.xml'))[0];

    expect($f->scadenze)->not->toBeEmpty();
    foreach ($f->scadenze as $scadenza) {
        expect($scadenza->iban)->toBeNull();
    }
});
