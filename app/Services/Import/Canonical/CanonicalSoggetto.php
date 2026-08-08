<?php

namespace App\Services\Import\Canonical;

/**
 * Una persona (o una società) come la intende Kondomanager.
 *
 * ## Una divergenza dal documento, verificata sullo schema
 *
 * `docs/import_migrazione_dati.md` §9.1 prevede `nome` e `cognome` separati, e dedica un
 * paragrafo allo split della stringa unica che gli export forniscono. **Nel nostro schema quel
 * problema non esiste**: `anagrafiche` ha una colonna `nome` sola, e nessun `cognome`
 * (verificato il 06/08/2026). La stringa di Danea entra così com'è, e lo split — che è un
 * indovinello, perché «DE LUCA MARIA GRAZIA» si può tagliare in tre punti — semplicemente non
 * si fa.
 *
 * ## I telefoni sono una lista, l'indirizzo di spedizione è a parte
 *
 * L'export reale porta `Tel1`, `Tel2`, `Tel3` e `Fax`, più un blocco `Spedizioni:` di sei
 * colonne che è il **domicilio per le comunicazioni**, diverso dalla residenza. Perderlo
 * significa mandare le convocazioni all'indirizzo sbagliato (§17.4).
 */
final readonly class CanonicalSoggetto
{
    /**
     * @param  list<string>  $telefoni  come arrivano: non normalizzati, perché `331 / 1053945` e
     *                                  `377-19.17.332` sono entrambi numeri veri scritti male, e
     *                                  normalizzarli qui significherebbe inventare un formato
     * @param  array<string, string|null>|null  $recapitoSpedizioni
     */
    public function __construct(
        public string $nome,
        public ?string $codiceFiscale = null,
        public ?string $partitaIva = null,
        public ?string $indirizzo = null,
        public ?string $cap = null,
        public ?string $comune = null,
        public ?string $provincia = null,
        public ?string $email = null,
        public ?string $pec = null,
        public array $telefoni = [],
        public ?array $recapitoSpedizioni = null,
        public ?string $note = null,
        public ?string $refEsterno = null,
    ) {}

    /**
     * La chiave di de-duplicazione.
     *
     * Il codice fiscale quando c'è — è l'unica chiave affidabile, ed è ciò che permette di
     * riconoscere lo stesso proprietario che compare su cinque unità. Il nome è il ripiego, e
     * va usato sapendo che è debole (§9.1).
     */
    public function chiave(): string
    {
        return $this->codiceFiscale ?? 'nome:'.mb_strtolower(trim($this->nome));
    }

    public function haCodiceFiscale(): bool
    {
        return $this->codiceFiscale !== null && $this->codiceFiscale !== '';
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'codice_fiscale' => $this->codiceFiscale,
            'partita_iva' => $this->partitaIva,
            'indirizzo' => $this->indirizzo,
            'cap' => $this->cap,
            'comune' => $this->comune,
            'provincia' => $this->provincia,
            'email' => $this->email,
            'pec' => $this->pec,
            'telefoni' => $this->telefoni,
            'recapito_spedizioni' => $this->recapitoSpedizioni,
            'note' => $this->note,
        ];
    }
}
