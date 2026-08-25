<?php

namespace App\Services\Import;

/**
 * L'esito del riconoscimento di un file: cosa credo che sia, quanto ci credo, e cosa ho capito
 * delle sue colonne.
 *
 * **Non è un booleano, ed è una scelta.** Un `supports(): bool` basta al codice e non basta
 * all'utente: il §6 chiede che il riconoscimento si possa **mostrare** prima della verifica,
 * perché è l'unico momento in cui l'amministratore può smentire il sistema *prima* che faccia
 * danni, invece che dopo.
 *
 * Le colonne ignote non sono un dettaglio diagnostico: vanno mostrate **anche quando è tutto
 * verde** (§14.0, decisione 2). La paura di chi migra non è «sbaglia un dato», è «perdo dei
 * dati», e questa è l'unica risposta possibile.
 */
final readonly class DetectionResult
{
    /**
     * @param  ReportType|null  $tipo  null se nessun tipo ha raggiunto la soglia
     * @param  int  $confidenza  0–100
     * @param  int|null  $rigaIntestazione  0-based; null se non trovata
     * @param  list<string>  $colonneRiconosciute  etichette del file che hanno un corrispondente
     * @param  list<string>  $colonneIgnote  etichette del file che non ne hanno
     * @param  array<string, int>  $mappaColonne  etichetta normalizzata → indice di colonna
     */
    public function __construct(
        public ?ReportType $tipo,
        public int $confidenza,
        public ?int $rigaIntestazione,
        public array $colonneRiconosciute = [],
        public array $colonneIgnote = [],
        public array $mappaColonne = [],
        public string $nomeFoglio = '',
    ) {}

    /**
     * Sotto questa soglia il file resta «non riconosciuto» e la scelta passa all'utente.
     *
     * Non è una soglia di sicurezza: è una soglia di **onestà**. Proporre un tipo con il 30% di
     * fiducia significa far confermare all'amministratore un'ipotesi che non abbiamo, e la
     * conferma di un'ipotesi debole vale meno di una domanda esplicita.
     */
    public const SOGLIA_MINIMA = 45;

    public static function nonRiconosciuto(string $nomeFoglio = ''): self
    {
        return new self(null, 0, null, nomeFoglio: $nomeFoglio);
    }

    public function riconosciuto(): bool
    {
        return $this->tipo !== null && $this->confidenza >= self::SOGLIA_MINIMA;
    }

    /**
     * Il livello di certezza in parole, per la colonna «Affidabilità» della schermata S2.
     *
     * Tre gradini e non una percentuale nuda: `96%` e `81%` dicono all'utente la stessa cosa
     * — «probabilmente ci ha preso» — mentre il colore del gradino gli dice se vale la pena
     * controllare.
     */
    public function fiducia(): string
    {
        return match (true) {
            $this->confidenza >= 85 => 'alta',
            $this->confidenza >= self::SOGLIA_MINIMA => 'media',
            default => 'nessuna',
        };
    }

    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo?->value,
            'etichetta' => $this->tipo?->cosaProduce(),
            'importabile' => $this->tipo?->importabile() ?? false,
            'confidenza' => $this->confidenza,
            'fiducia' => $this->fiducia(),
            'riga_intestazione' => $this->rigaIntestazione,
            'foglio' => $this->nomeFoglio,
            'colonne' => [
                'riconosciute' => $this->colonneRiconosciute,
                'ignorate' => $this->colonneIgnote,
            ],
        ];
    }
}
