<?php

namespace App\DataTransferObjects\FatturaElettronica;

/**
 * Una fattura letta da un file FatturaPA. Un file può contenerne più di
 * una (lotto, FatturaElettronicaBody ripetuto): FatturaPaParser::parse()
 * restituisce sempre un array, anche per un file con un solo documento.
 *
 * Solo lettura — nessun accesso al database, nessun side effect. Il
 * riconoscimento del fornitore (per partitaIva), la scelta del conto per
 * riga e il salvataggio restano a un servizio a valle: qui c'è solo ciò
 * che il file dichiara di sé.
 *
 * ## ⚠️ Gli importi qui sono in CENTESIMI, e chi li consuma deve saperlo
 *
 * La convenzione del progetto (CLAUDE.md) è denaro in centesimi interi,
 * convertito una volta sola al confine d'ingresso: questo parser È quel
 * confine per i file XML, quindi converte lui con MoneyHelper::toCents().
 *
 * **Ma `FatturaPassivaService::registraFattura()` — l'unico punto di
 * scrittura delle fatture passive — riceve `righe.*.importo_imponibile`
 * in EURO e converte lui con `* 100`**, perché il suo confine d'ingresso
 * storico è un modulo web. Chi raccorderà questo parser a quel servizio
 * (la beta.14 dell'arco, docs/lettura_xml_fatture_passive.md) ha quindi
 * un ×100 pronto e nessun tipo che lo fermi: entrambi i valori sono
 * numerici e plausibili. **Va convertito con MoneyHelper::fromCents()
 * prima di passarlo al servizio**, o il servizio va esteso per accettare
 * i centesimi esplicitamente. È la classe di difetto che CLAUDE.md
 * indica come «il bug del ×100 costato la beta.32».
 *
 * ## ⚠️ Il segno non è normalizzato, e non può esserlo qui
 *
 * Lo schema ammette importi negativi (`[\-]?[0-9]{1,11}\.[0-9]{2,8}`),
 * quindi una nota di credito TD04 arriva legittimamente in due forme:
 * con importi positivi (ed è il verso della fixture in casa) o con
 * importi negativi. `isNotaCredito()` è vero in entrambi i casi, e
 * questo oggetto restituisce **il segno che sta nel file**, senza
 * inventarne uno.
 *
 * Chi consuma NON può quindi fare `isNotaCredito() ? -$totale : $totale`
 * alla cieca: su una nota di credito già negativa la ribalterebbe in un
 * debito. La normalizzazione è una decisione di dominio e va presa dove
 * si registra il documento, guardando il segno effettivo.
 */
class FatturaPaFattura
{
    /**
     * @param  FatturaPaRiga[]  $righe
     * @param  FatturaPaRiepilogo[]  $riepiloghi
     * @param  FatturaPaScadenza[]  $scadenze
     * @param  FatturaPaRitenuta[]  $ritenute
     */
    public function __construct(
        public readonly string $tipoDocumento,
        public readonly string $numeroDocumento,
        public readonly string $dataDocumento,
        public readonly ?string $causale,
        public readonly ?int $importoTotaleDocumentoCents,
        public readonly ?string $fornitorePartitaIva,
        public readonly ?string $fornitoreCodiceFiscale,
        public readonly string $fornitoreDenominazione,
        public readonly ?string $fornitoreIndirizzo,
        public readonly ?string $fornitoreCap,
        public readonly ?string $fornitoreComune,
        public readonly ?string $fornitoreProvincia,
        public readonly ?string $fornitoreNazione,
        public readonly ?string $fornitoreEmail,
        public readonly ?string $fornitoreRegimeFiscale,
        public readonly array $righe,
        public readonly array $riepiloghi,
        public readonly array $scadenze,
        public readonly array $ritenute,
    ) {
    }

    public function isNotaCredito(): bool
    {
        // TD04 = nota di credito. Le altre varianti (TD02 acconto, TD03
        // acconto parcella...) restano fuori dal perimetro dichiarato in
        // docs/lettura_xml_fatture_passive.md: solo FatturaPA ordinaria.
        return $this->tipoDocumento === 'TD04';
    }

    /**
     * L'imponibile che **la fattura dichiara di sé**, sommando i blocchi
     * DatiRiepilogo. È questo il numero da usare per registrare: è ciò
     * che il fornitore afferma di chiedere.
     */
    public function imponibileDichiaratoCents(): int
    {
        return array_sum(array_map(fn (FatturaPaRiepilogo $r) => $r->imponibileCents, $this->riepiloghi));
    }

    /**
     * L'IVA che la fattura dichiara di sé, sommando i blocchi
     * DatiRiepilogo.
     */
    public function impostaDichiarataCents(): int
    {
        return array_sum(array_map(fn (FatturaPaRiepilogo $r) => $r->impostaCents, $this->riepiloghi));
    }

    /**
     * La somma delle righe di dettaglio. **Non è l'imponibile del
     * documento** e può differire da imponibileDichiaratoCents(): usare
     * questo per registrare è un difetto, non una scorciatoia.
     */
    public function sommaRigheCents(): int
    {
        return array_sum(array_map(fn (FatturaPaRiga $r) => $r->importoImponibileCents, $this->righe));
    }

    /**
     * Di quanto la somma delle righe si scosta dall'imponibile che la
     * fattura dichiara. Zero significa che quadrano.
     *
     * Stessa forma di `CanonicalCapitolo::scartoCents()` nell'importatore
     * Danea, che risolve lo stesso problema — un totale dichiarato che
     * non torna con la somma delle voci — ed è già collaudato: si espone
     * **quanto**, non solo **se**, perché è la differenza a dire
     * all'amministratore se sta guardando una causale legittima o un
     * errore. A differenza di quello, qui non esiste il caso «non
     * verificabile»: DatiRiepilogo è obbligatorio e il parser rifiuta un
     * documento che non lo porta, quindi il confronto si può sempre fare.
     *
     * **Uno scarto non è necessariamente un errore del file**: è
     * legittimo con spese accessorie, arrotondamenti, contributo cassa
     * previdenziale o sconti di documento, che vivono nel riepilogo e non
     * nelle righe. Ma non è nemmeno da nascondere — sull'esempio ufficiale
     * FPR02 dell'Agenzia le righe fanno € 25,00 e il riepilogo dichiara
     * € 27,00 senza nessuna di quelle causali.
     *
     * Serve a valle per **dirlo all'amministratore** invece di scegliere
     * un numero al posto suo: stessa filosofia della decisione D4 sui
     * duplicati, segnalare e non decidere.
     */
    public function scartoRigheRiepilogoCents(): int
    {
        return $this->imponibileDichiaratoCents() - $this->sommaRigheCents();
    }

    public function righeNonQuadranoColRiepilogo(): bool
    {
        return $this->scartoRigheRiepilogoCents() !== 0;
    }
}
