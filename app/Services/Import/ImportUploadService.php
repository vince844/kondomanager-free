<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Services\Import\Parser\ModelloManualeParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Riceve i file caricati, li conserva e li fa riconoscere — la schermata S2 (§14.1).
 *
 * ## Perché i file si conservano invece di leggerli e buttarli
 *
 * Tre motivi, tutti già scritti nel documento e nessuno estetico:
 *
 * 1. **La ripresa dopo interruzione** (§7): il lotto riparte dal checkpoint, e per ripartire
 *    deve avere ancora i file.
 * 2. **Il rapporto** (§13.3) deve poter dire da quale riga di quale file viene un record. Senza
 *    il file, `riga_sorgente` è un numero senza referente.
 * 3. **Il ricaricamento identico**: l'hash riconosce lo stesso file caricato due volte, che
 *    capita di continuo quando l'amministratore corregge un errore e ricarica *l'altro* file.
 *
 * Vivono sul disco **privato**, mai in `public`: contengono codici fiscali, indirizzi ed email
 * di condòmini reali. La cartella è per lotto, così l'annullamento porta via anche quelli.
 */
final class ImportUploadService
{
    public function __construct(
        private readonly SpreadsheetReader $reader = new SpreadsheetReader,
        private readonly ReportRecognizer $riconoscitore = new ReportRecognizer,
    ) {}

    /** Il disco privato: `storage/app/private`, non servito dal web server. */
    public const DISCO = 'local';

    /**
     * @param  list<UploadedFile>  $files
     */
    public function creaLotto(array $files, ?int $userId = null, string $sorgente = 'danea'): ImportBatch
    {
        $batch = ImportBatch::create([
            'user_id' => $userId,
            'sorgente' => $sorgente,
        ]);

        foreach ($files as $file) {
            $this->aggiungi($batch, $file);
        }

        return $batch;
    }

    /**
     * Conserva un file nel lotto e ne registra il riconoscimento.
     *
     * Un file che non si riesce ad aprire **non fa fallire il caricamento degli altri**: viene
     * registrato senza tipo, e la schermata S2 lo mostra come non riconosciuto con il motivo.
     * Far saltare l'intero caricamento perché uno dei sei file è corrotto sarebbe punire
     * l'amministratore per un errore che non ha commesso lui.
     */
    public function aggiungi(ImportBatch $batch, UploadedFile $file): ImportFile
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $gia = $batch->files()->where('hash', $hash)->first();

        if ($gia !== null) {
            return $gia;
        }

        $percorso = $file->store('import/'.$batch->uuid, self::DISCO);

        if ($percorso === false) {
            throw new RuntimeException('Non riesco a salvare il file caricato.');
        }

        $riconoscimento = $this->riconosci(Storage::disk(self::DISCO)->path($percorso));

        return $batch->files()->create([
            'nome_originale' => $file->getClientOriginalName(),
            'percorso' => $percorso,
            'dimensione' => $file->getSize(),
            'hash' => $hash,
            'report_type' => $riconoscimento['tipo'],
            'confidenza' => $riconoscimento['confidenza'],
            'colonne' => $riconoscimento['colonne'],
        ]);
    }

    /**
     * @return array{tipo: string|null, confidenza: int|null, colonne: array|null, errore: string|null}
     */
    private function riconosci(string $percorso): array
    {
        try {
            $fogli = $this->reader->leggi($percorso);
        } catch (RuntimeException $e) {
            return [
                'tipo' => null,
                'confidenza' => null,
                'colonne' => ['errore' => $e->getMessage()],
                'errore' => $e->getMessage(),
            ];
        }

        // Si prende il foglio con il riconoscimento migliore, non il primo: i file di Danea
        // portano fogli vuoti in coda, e qualche amministratore aggiunge un foglio di appunti.
        $migliore = null;

        foreach ($fogli as $foglio) {
            $esito = $this->riconoscitore->riconosci($foglio);

            if ($migliore === null || $esito->confidenza > $migliore->confidenza) {
                $migliore = $esito;
            }
        }

        // ⚠️ **Il modello manuale è un report solo scritto su cinque fogli**, e qui sopra ne
        // sopravvive uno: la copertina, che di colonne ne ha tre. Il pannello della schermata di
        // riconoscimento avrebbe annunciato «3 colonne riconosciute» a chi ha appena compilato a
        // mano cinque fogli — la stessa forma di bugia già corretta una volta per l'elenco unità,
        // e per giunta sulla strada di chi si fida di meno, perché non ha un export da mostrare.
        $riconosciute = $migliore?->tipo === ReportType::ModelloManuale
            ? (new ModelloManualeParser)->colonne($fogli)
            : ($migliore?->colonneRiconosciute ?? []);

        return [
            'tipo' => $migliore?->tipo?->value,
            'confidenza' => $migliore?->confidenza,
            'colonne' => [
                'riconosciute' => $riconosciute,
                'ignorate' => $migliore?->colonneIgnote ?? [],
                'foglio' => $migliore?->nomeFoglio,
                'riga_intestazione' => $migliore?->rigaIntestazione,
            ],
            'errore' => null,
        ];
    }

    /**
     * Elimina i file di un lotto. Chiamata dall'annullamento.
     */
    public function eliminaFile(ImportBatch $batch): void
    {
        Storage::disk(self::DISCO)->deleteDirectory('import/'.$batch->uuid);
    }
}
