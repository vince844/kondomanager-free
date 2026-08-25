<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciò che un livello sa mentre viene importato: il lotto, i dati canonici letti dai file, e le
 * entità che i livelli precedenti hanno già risolto.
 *
 * ## La distinzione che questa classe esiste per tenere
 *
 * `canonico()` è **ciò che il file dice**. `risolto()` è **ciò che esiste davvero in archivio**.
 * Sono due cose diverse, e confonderle è il difetto del §0.3-C: il livello delle fatture aveva
 * il piano dei conti *nel file* e non *nel database*, e nessuno ha controllato la seconda.
 *
 * Da qui la regola, che vale per ogni `verificaPrerequisiti()`: **si interroga `risolto()` e il
 * database, mai `canonico()`.** Un prerequisito soddisfatto sulla carta non è un prerequisito.
 *
 * Le decisioni dell'utente sui duplicati vivono qui perché arrivano dalla schermata di anteprima
 * e servono al committer: senza, un duplicato non si risolve e il livello non si conferma.
 */
final class ImportContext
{
    /** @var array<string, mixed> livello → dati canonici letti dai file */
    private array $canonici = [];

    /** @var array<string, Model> livello → entità realmente presente in archivio */
    private array $risolti = [];

    /** @var array<string, string> chiave decisione → 'unisci' | 'salta' | 'crea_nuovo' */
    private array $decisioni = [];

    public function __construct(
        public readonly ImportBatch $batch,
    ) {}

    public function conCanonico(string $livello, mixed $dati): self
    {
        $this->canonici[$livello] = $dati;

        return $this;
    }

    public function canonico(string $livello): mixed
    {
        return $this->canonici[$livello] ?? null;
    }

    /** Un livello ha prodotto (o ritrovato) l'entità reale: da qui in poi è disponibile. */
    public function risolvi(string $livello, Model $entita): self
    {
        $this->risolti[$livello] = $entita;

        return $this;
    }

    public function risolto(string $livello): ?Model
    {
        $valore = $this->risolti[$livello] ?? null;

        return $valore instanceof Model ? $valore : null;
    }

    /**
     * Un livello che produce **molte** entità: persone, unità, titolarità.
     *
     * Serve accanto a `risolvi()` e non al suo posto: «il condominio» è un'entità sola e
     * chiederla come collezione renderebbe goffo ogni chiamante. Sono due forme diverse dello
     * stesso concetto — *cosa esiste adesso in archivio grazie a questo livello*.
     *
     * @param  array<string, Model>  $entita  chiave canonica → entità reale
     */
    public function risolviMolti(string $livello, array $entita): self
    {
        $this->risolti[$livello] = $entita;

        return $this;
    }

    /**
     * @return array<string, Model>
     */
    public function risoltiMolti(string $livello): array
    {
        $valore = $this->risolti[$livello] ?? null;

        return is_array($valore) ? $valore : [];
    }

    /**
     * Il livello ha prodotto qualcosa, in una qualsiasi delle due forme.
     *
     * È la domanda che il controllo d'ordine deve fare. Chiedere `risolto()` e basta faceva
     * fallire i livelli che dipendono da una collezione — con un'eccezione che accusava
     * l'ordine dei livelli di un difetto che era invece nel modo di interrogare il contesto.
     */
    public function haRisolto(string $livello): bool
    {
        $valore = $this->risolti[$livello] ?? null;

        return $valore instanceof Model || (is_array($valore) && $valore !== []);
    }

    /**
     * @param  array<string, string>  $decisioni
     */
    public function conDecisioni(array $decisioni): self
    {
        $this->decisioni = [...$this->decisioni, ...$decisioni];

        return $this;
    }

    public function decisione(string $chiave): ?string
    {
        return $this->decisioni[$chiave] ?? null;
    }

    /**
     * Annota nel lotto che questa entità è stata toccata, e come.
     *
     * È l'unico posto da cui nascono le righe di `import_batch_items`. Passa da qui anche il
     * caso `saltato`, che non ha un'entità dietro: il rapporto deve poter dire *«questa riga
     * non è entrata, e l'hai deciso tu»*, altrimenti l'assenza di un dato diventa un mistero.
     */
    public function registra(
        string $livello,
        string $azione,
        ?Model $entita = null,
        ?int $rigaSorgente = null,
        ?int $importFileId = null,
    ): void {
        $this->batch->items()->create([
            'import_file_id' => $importFileId,
            'livello' => $livello,
            'importabile_type' => $entita ? $entita::class : null,
            'importabile_id' => $entita?->getKey(),
            'azione' => $azione,
            'riga_sorgente' => $rigaSorgente,
        ]);
    }
}
