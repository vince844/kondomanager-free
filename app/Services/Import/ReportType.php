<?php

namespace App\Services\Import;

/**
 * I tipi di report che sappiamo riconoscere, con i segnali che li identificano.
 *
 * Non è un elenco di formati di file: è un elenco di **stampe** di Danea. Lo stesso gestionale
 * produce report con intestazioni in posizioni diverse, numeri di colonne diversi e informazioni
 * diverse — ed è questa la variazione che conta per l'import, non quella fra gestionali (§17).
 *
 * Ogni caso dichiara due segnali indipendenti:
 *
 * - il **titolo del banner**, cioè la prima riga della stampa (`Movimenti`, `Elenco rate
 *   versate`, …). Forte quando c'è, ma i due report senza banner non ce l'hanno;
 * - le **etichette caratteristiche** delle colonne, cioè quelle che distinguono questo report
 *   dagli altri. Non tutte le colonne: solo quelle discriminanti.
 *
 * Il secondo segnale è quello che separa i due report compatti, che a prima vista si somigliano:
 * `elenco_unita` ha `Ruolo`, `CodFisc` e `Saldo prec`; `anagrafica_millesimi` non ha nessuno dei
 * tre e chiama `Proprietario` la colonna che l'altro chiama `Denominazione` (§17.2).
 */
enum ReportType: string
{
    case ElencoUnita = 'elenco_unita';
    case AnagraficaMillesimi = 'anagrafica_millesimi';
    case RipartoConsuntivo = 'riparto_consuntivo';
    case BilancioConsuntivo = 'bilancio_consuntivo';
    case Movimenti = 'movimenti';
    case RateVersate = 'rate_versate';
    case ElencoAttivita = 'elenco_attivita';

    /**
     * Il titolo che la stampa porta in testa, quando ce l'ha.
     *
     * Confrontato normalizzato, e solo sulle prime righe: è la riga 0 in tutti i file reali,
     * ma non si assume la riga 0 nemmeno qui.
     */
    public function titoloBanner(): ?string
    {
        return match ($this) {
            self::RipartoConsuntivo => 'Consuntivo ripartizioni per unità / anagrafica',
            self::BilancioConsuntivo => 'Bilancio consuntivo per conto',
            self::Movimenti => 'Movimenti',
            self::RateVersate => 'Elenco rate versate',
            self::ElencoAttivita => 'Attività chiuse',
            // I due compatti escono senza testata: iniziano direttamente dall'intestazione.
            self::ElencoUnita, self::AnagraficaMillesimi => null,
        };
    }

    /**
     * Le etichette di colonna che identificano questo report.
     *
     * @return list<string>
     */
    public function etichette(): array
    {
        return match ($this) {
            self::ElencoUnita => [
                'Palazzina', 'Gruppo unità', 'Progressivo', 'Tipo', 'Ruolo', 'Saldo prec',
                'Denominazione', 'CodFisc', 'Spedizioni: Denominazione', 'Sub. cat.',
            ],
            self::AnagraficaMillesimi => [
                'Palazzina', 'Gruppo', 'Progressivo', 'Proprietario',
            ],
            self::RipartoConsuntivo => [
                'Movimenti personali', 'Totale gestione', 'Saldi di fine Es. prec.',
                'Rate versate', 'Saldo finale', 'mill.',
            ],
            self::BilancioConsuntivo => [
                'Importo', 'Totale',
            ],
            self::Movimenti => [
                'Protoc.', 'Dt.compet.', 'Dt.pagam.', 'Dt.fattur.', 'Descrizione', 'Importo',
                'Fornitore', 'Conto', 'Documento', 'Risorsa', 'Imprevisto', 'Riconciliato',
            ],
            self::RateVersate => [
                'Prot.', 'Data comp.', 'Data pag.', 'Importo', 'Anagrafica', 'Descr.',
                'Rif. pagamento', 'Risorsa',
            ],
            self::ElencoAttivita => [
                'Nr. Pratica', 'Condominio', 'Tipologia', 'Priorità', 'Operatore', 'Titolo',
            ],
        };
    }

    /**
     * **Tutte** le colonne che il parser legge davvero — non solo quelle che servono a
     * riconoscere il report.
     *
     * ## Perché è un elenco diverso da `etichette()`
     *
     * `etichette()` contiene le colonne **discriminanti**: quelle che distinguono questo report
     * dagli altri. Sono poche per costruzione, perché per riconoscere un file non serve
     * guardarlo tutto.
     *
     * Usarle anche per dire all'utente cosa leggiamo produce una bugia, ed è successo: il
     * pannello delle colonne ignorate dell'elenco unità mostrava fra le «ignorate» indirizzo,
     * email, telefoni, PEC e note — che il parser legge tutte — con sotto la frase «non hanno
     * un corrispondente in Kondomanager». All'amministratore diceva che stavamo buttando via i
     * recapiti dei suoi condòmini.
     *
     * Il pannello esiste per rispondere a «ma le mie note dove sono finite?»: se risponde male,
     * fa più danni di quanti ne eviti. Trovato guardando la schermata, non dai test — nessuna
     * asserzione poteva accorgersene, perché il dato era coerente e il significato sbagliato.
     *
     * @return list<string>
     */
    public function colonneLette(): array
    {
        return match ($this) {
            self::ElencoUnita => [
                ...$this->etichette(),
                'Piano', 'Interno', 'Saldo prec', 'PartIva', 'Indirizzo', 'CAP', 'Città', 'Prov',
                'Email', 'Pec', 'Tel1', 'Tel2', 'Tel3', 'Fax', 'Note',
                'Spedizioni: Indirizzo', 'Spedizioni: CAP', 'Spedizioni: Città', 'Spedizioni: Prov',
            ],
            self::RipartoConsuntivo => [
                ...$this->etichette(),
                // Le prime tre colonne del riparto non hanno titolo: unità, ruolo e
                // denominazione. Si leggono per posizione, e vanno dette come lette.
            ],
            // Nel report compatto **ogni colonna oltre le quattro fisse è una tabella
            // millesimale**: sono tutte lette, e l'elenco non si può scrivere in anticipo
            // perché i nomi li decide l'amministratore.
            self::AnagraficaMillesimi => ['*'],
            self::Movimenti, self::RateVersate => ['*'],
            default => $this->etichette(),
        };
    }

    /**
     * Quante etichette devono comparire sulla stessa riga perché sia l'intestazione.
     *
     * Tre è il minimo sensato: sotto, una riga di dati con due parole comuni potrebbe passare.
     * `anagrafica_millesimi` ne dichiara quattro in tutto, quindi il suo quorum è tre — e per
     * `bilancio_consuntivo`, che di etichette ne ha due, è due.
     */
    public function quorum(): int
    {
        return match ($this) {
            self::BilancioConsuntivo => 2,
            default => 3,
        };
    }

    /**
     * Cosa questo report porta in Kondomanager, in parole da mostrare all'amministratore.
     *
     * È la colonna «Cosa ne ricavo» della schermata S2 (§14.1): l'utente non deve dedurre a
     * cosa serve il file che ha appena trascinato.
     */
    public function cosaProduce(): string
    {
        return match ($this) {
            self::ElencoUnita => 'Persone · Unità · Titolarità · Saldo precedente',
            self::AnagraficaMillesimi => 'Persone · Unità · Tabelle millesimali',
            self::RipartoConsuntivo => 'Saldi di apertura, con il totale su cui quadrarli',
            self::BilancioConsuntivo => 'Struttura dei capitoli di spesa',
            // L'archivio storico è rimandato alla 1.10.1. Il file resta utile — la testata
            // porta condominio ed esercizio — ma promettere «archivio storico dei movimenti»
            // e poi non parlarne più né in conferma né in esito è il modo più diretto di far
            // credere a chi migra di aver perso dei pezzi.
            self::Movimenti => 'Solo condominio ed esercizio: lo storico non entra nella 1.10',
            self::RateVersate => 'Solo condominio ed esercizio: lo storico non entra nella 1.10',
            self::ElencoAttivita => 'Niente: Kondomanager non importa le pratiche',
        };
    }

    /**
     * Il nome del report **come lo chiamerebbe un amministratore**.
     *
     * Serve perché la colonna «Tipo riconosciuto» della S2 mostrava il valore dell'enum con gli
     * underscore tolti — «elenco unita», «riparto consuntivo» — minuscolo e senza accenti. È la
     * schermata che esiste apposta perché l'utente possa **smentire** il riconoscimento, e
     * smentire un identificatore interno è più difficile che smentire un nome.
     */
    public function etichettaUmana(): string
    {
        return match ($this) {
            self::ElencoUnita => 'Elenco unità',
            self::AnagraficaMillesimi => 'Anagrafica + millesimi',
            self::RipartoConsuntivo => 'Riparto consuntivo',
            self::BilancioConsuntivo => 'Bilancio consuntivo',
            self::Movimenti => 'Movimenti',
            self::RateVersate => 'Rate versate',
            self::ElencoAttivita => 'Attività / pratiche',
        };
    }

    /**
     * Vero se il report entra davvero in archivio.
     *
     * `elenco_attivita` si riconosce **apposta** per poterlo escludere dicendo perché, invece
     * di lasciarlo fra i «non riconosciuti» dove sembrerebbe un difetto del sistema.
     */
    public function importabile(): bool
    {
        return $this !== self::ElencoAttivita;
    }
}
