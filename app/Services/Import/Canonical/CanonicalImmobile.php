<?php

namespace App\Services\Import\Canonical;

/**
 * Un'unità immobiliare.
 *
 * ## La chiave composita, e perché non è l'ordine di riga
 *
 * Danea identifica l'unità con la terna **palazzina · gruppo · progressivo** (`1-A-3`), e la
 * ripete su ogni riga: quando un'unità ha un proprietario e un inquilino, quella terna compare
 * due volte. È la chiave con cui si raggruppano le righe in un'unica unità con N titolarità.
 *
 * Il `gruppo` arriva come lettera in un export e come numero in un altro (`A` in
 * `anagrafica.xls`, `0` in `giada mill.xls`): si tiene **come stringa**, sempre. E il
 * `progressivo` arriva zero-riempito nel riparto (`01`, `016`): trattarlo da intero
 * fonderebbe unità diverse.
 */
final readonly class CanonicalImmobile
{
    public function __construct(
        public string $palazzina,
        public string $gruppo,
        public string $progressivo,
        public ?string $piano = null,
        public ?string $interno = null,
        public ?string $tipo = null,
        public ?string $datiCatastali = null,
        public ?string $note = null,
    ) {}

    /**
     * `1-A-3` — la chiave di join fra i livelli (§8.2): un dato che l'utente possiede già,
     * mai un id nostro.
     */
    public function chiave(): string
    {
        return implode('-', [$this->palazzina, $this->gruppo, $this->progressivo]);
    }

    /**
     * Il nome da mostrare, quando l'export non ne porta uno.
     *
     * `Interno 3` è più leggibile di `1-A-3` per chi guarda l'elenco delle unità, e resta
     * ricostruibile: il progressivo c'è dentro.
     */
    public function denominazione(): string
    {
        return trim(($this->tipo ?? 'Unità').' '.$this->progressivo);
    }

    /**
     * La descrizione, che nel nostro schema è **obbligatoria** e che l'export di Danea non ha.
     *
     * Si costruisce dai dati che ci sono — palazzina, gruppo, progressivo — invece di scrivere
     * una stringa vuota: un campo obbligatorio riempito col nulla è un campo che nessuno
     * correggerà, perché niente lo segnala. Così almeno dice da dove viene l'unità.
     */
    public function descrizione(): string
    {
        $pezzi = ['Palazzina '.$this->palazzina];

        if (trim($this->gruppo) !== '' && $this->gruppo !== '0') {
            $pezzi[] = 'gruppo '.$this->gruppo;
        }

        if ($this->piano !== null && trim($this->piano) !== '') {
            $pezzi[] = 'piano '.$this->piano;
        }

        $pezzi[] = 'n. '.$this->progressivo;

        return implode(' · ', $pezzi);
    }

    public function toArray(): array
    {
        return [
            'chiave' => $this->chiave(),
            'palazzina' => $this->palazzina,
            'gruppo' => $this->gruppo,
            'progressivo' => $this->progressivo,
            'piano' => $this->piano,
            'interno' => $this->interno,
            'tipo' => $this->tipo,
            'dati_catastali' => $this->datiCatastali,
            'note' => $this->note,
        ];
    }
}
