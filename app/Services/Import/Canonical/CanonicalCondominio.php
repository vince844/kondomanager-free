<?php

namespace App\Services\Import\Canonical;

/**
 * Il condominio come lo intende Kondomanager, ricavato dalla testata di una stampa.
 *
 * Il formato canonico si progetta a partire dal **nostro** dominio, mai dall'export altrui
 * (§6): questa classe non ha campi «perché Danea li ha», ha i campi che servono a creare un
 * condominio qui.
 */
final readonly class CanonicalCondominio
{
    public function __construct(
        public string $nome,
        public ?string $codiceFiscale = null,
        public ?string $indirizzo = null,
        public ?string $cap = null,
        public ?string $comune = null,
        public ?string $provincia = null,
        /**
         * L'id della riga scelta a mano come destinazione, quando c'è.
         *
         * ⚠️ **Senza questo campo l'id si perdeva per strada.** La destinazione si sceglie da una
         * tendina — cioè indicando una riga precisa dell'archivio col dito — ma il canonico
         * portava solo nome e codice fiscale, e al commit `RicercaEsistenti` lo **ricercava**.
         * Con due condomìni omonimi **senza codice fiscale** la ricerca ritrovava il primo:
         * misurato, scegliendo il secondo tutto finiva nel primo. Non è un caso di laboratorio —
         * il codice fiscale del condominio è facoltativo, e chi amministra più stabili della
         * stessa proprietà ha nomi ripetuti.
         *
         * Resta `null` per i condomìni che arrivano dalla testata di una stampa: quelli non
         * indicano nessuna riga, e vanno cercati.
         */
        public ?int $idScelto = null,
    ) {}

    /**
     * La chiave con cui i livelli successivi ritrovano questo condominio (§8.2).
     *
     * Il codice fiscale quando c'è: è un dato che l'amministratore possiede già e che non deve
     * inventare. Il nome è il ripiego, ed è un ripiego debole — due condomìni «Residenza
     * Aurora» in due comuni diversi esistono davvero.
     */
    public function chiave(): string
    {
        // L'id quando c'è: è l'unica cosa che distingue due omonimi senza codice fiscale, e la
        // chiave serve proprio a non confonderli. Senza, la decisione presa su uno rispondeva
        // anche per l'altro.
        if ($this->idScelto !== null) {
            return '#'.$this->idScelto;
        }

        return $this->codiceFiscale ?? mb_strtolower(trim($this->nome));
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'codice_fiscale' => $this->codiceFiscale,
            'indirizzo' => $this->indirizzo,
            'cap' => $this->cap,
            'comune' => $this->comune,
            'provincia' => $this->provincia,
        ];
    }
}
