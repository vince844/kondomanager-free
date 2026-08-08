<?php

namespace App\Services\Import\Canonical;

/**
 * Una tabella millesimale, con i valori per unità.
 *
 * ## L'assenza non è zero
 *
 * Le `quote` sono indicizzate per chiave di immobile, e **una chiave che manca significa «non
 * partecipa»**, non «partecipa con zero». È la differenza fra una tabella parziale — l'impianto
 * idraulico che serve quattro unità su sedici — e una tabella in cui dodici condòmini hanno
 * millesimo zero.
 *
 * Sui file reali questa distinzione è visibile: i quattro valori della tabella `idraulico`
 * sommano a **1000 fra loro**, cioè la tabella parziale è normalizzata sui suoi partecipanti.
 * Trattare le celle vuote come zeri lascerebbe la somma invariata ma allargherebbe la platea, e
 * il riparto uscirebbe sbagliato senza che niente lo segnali.
 */
final readonly class CanonicalTabella
{
    /**
     * @param  array<string, float>  $quote  chiave immobile (`1-A-3`) → valore
     */
    public function __construct(
        public string $nome,
        public array $quote,
    ) {}

    public function partecipanti(): int
    {
        return count($this->quote);
    }

    public function somma(): float
    {
        return array_sum($this->quote);
    }

    /**
     * Vero quando ogni partecipante ha lo stesso valore: è la tabella «a parti uguali».
     *
     * Nei file reali si presenta come una colonna di `1` — le cassette postali. Non serve un
     * trattamento speciale nel motore, che pesa comunque `valore / sommaValori`: serve però
     * saperlo per **dirlo** nell'anteprima, perché a colpo d'occhio una colonna di uni sembra
     * un errore di compilazione.
     */
    public function isPartiUguali(): bool
    {
        if ($this->quote === []) {
            return false;
        }

        return count(array_unique(array_map(fn (float $v) => (string) $v, $this->quote))) === 1;
    }

    /**
     * Quanti decimali servono davvero per non perdere niente.
     *
     * I millesimi reali arrivano con **quattro** decimali (`228.5002`), mentre `tabelle` nasce
     * con `numero_decimali = 2`. Arrotondare in ingresso è una perdita silenziosa: qui si conta
     * quanto serve e lo si dichiara, entro il limite della colonna `decimal(12,5)`.
     */
    public function decimaliNecessari(): int
    {
        $massimo = 0;

        foreach ($this->quote as $valore) {
            $testo = rtrim(rtrim(number_format($valore, 5, '.', ''), '0'), '.');
            $punto = strpos($testo, '.');
            $decimali = $punto === false ? 0 : strlen($testo) - $punto - 1;
            $massimo = max($massimo, $decimali);
        }

        return min(5, max(2, $massimo));
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'partecipanti' => $this->partecipanti(),
            'somma' => round($this->somma(), 5),
            'parti_uguali' => $this->isPartiUguali(),
            'decimali' => $this->decimaliNecessari(),
        ];
    }
}
