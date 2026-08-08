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
