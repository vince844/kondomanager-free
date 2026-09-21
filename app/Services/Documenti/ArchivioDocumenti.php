<?php

namespace App\Services\Documenti;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dove stanno i file caricati, detto in un posto solo (1.11.0-beta.33).
 *
 * Fino alla beta.32 cinquanta punti in diciannove file nominavano il disco a mano —
 * `Storage::disk('local')` per i documenti, `'public'` per la firma delle stampe — e un
 * container senza volume perdeva tutto alla ricreazione, in silenzio. Da qui in poi il disco lo
 * decide `config('kondomanager.disco_documenti')` / `disco_pubblici`: senza `DOCUMENTI_DISK` sono
 * **gli stessi dischi e le stesse cartelle di sempre** (`storage/app/private/documenti`,
 * `storage/app/public`); con `DOCUMENTI_DISK=s3` sono un bucket S3-compatibile con un prefisso.
 *
 * Le regole che i chiamanti non devono conoscere: un download su S3 non ha un percorso
 * assoluto, si fa in streaming; un'immagine che mPDF vuole come file locale si copia in una
 * cartella temporanea; l'anteprima di un file pubblico su S3 è un URL firmato a scadenza.
 */
final class ArchivioDocumenti
{
    public const CARTELLA = 'documenti';

    public function nomeDisco(): string
    {
        return (string) config('kondomanager.disco_documenti', 'local');
    }

    public function nomeDiscoPubblici(): string
    {
        return (string) config('kondomanager.disco_pubblici', 'public');
    }

    public function disco(): Filesystem
    {
        return Storage::disk($this->nomeDisco());
    }

    public function discoPubblici(): Filesystem
    {
        return Storage::disk($this->nomeDiscoPubblici());
    }

    /** Il disco dei documenti è quello locale di sempre? (Conta per backup, download, persistenza.) */
    public function locale(): bool
    {
        return $this->nomeDisco() === 'local';
    }

    /**
     * Salva un file caricato con il nome hash di sempre e restituisce il percorso relativo al
     * disco. **Se la scrittura fallisce, solleva**: `storeAs()` torna `false` senza dirlo (i dischi
     * hanno `throw => false`), e sul locale succedeva solo a disco pieno; su S3 è il guasto normale
     * — token scaduto, bucket sbagliato, endpoint irraggiungibile — e sei controller, prima della
     * revisione della beta.33, avrebbero risposto «creato» scrivendo una riga con `path = "0"`.
     * Ogni chiamante sta in un `try` con rollback e messaggio d'errore: l'eccezione è la strada.
     *
     * @throws \RuntimeException
     */
    public function salva(UploadedFile $file, string $cartella = self::CARTELLA): string
    {
        $path = $file->storeAs($cartella, $file->hashName(), $this->nomeDisco());

        if ($path === false || $path === '') {
            throw new \RuntimeException("Impossibile scrivere il file sul disco «{$this->nomeDisco()}».");
        }

        return $path;
    }

    public function esiste(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disco()->exists($path);
    }

    /** Cancella se c'è; non solleva se non c'è. */
    public function elimina(?string $path): void
    {
        if ($this->esiste($path)) {
            $this->disco()->delete($path);
        }
    }

    /**
     * Download con il nome che l'utente vedrà. Uguale su locale e su S3 (il disco `documenti_s3`
     * ha `stream_reads`, così l'oggetto scorre verso il browser invece di essere scaricato per
     * intero sul server prima). Su S3 un file che non c'è o un bucket che non risponde sollevano:
     * i chiamanti lo catturano e rispondono con il loro messaggio.
     */
    public function scarica(string $path, string $nome): StreamedResponse
    {
        return $this->disco()->download($path, $nome);
    }

    /**
     * Il file pubblico salvato nella cartella indicata (firma delle stampe). Solleva se la
     * scrittura fallisce, per la stessa ragione di `salva()`.
     *
     * @throws \RuntimeException
     */
    public function salvaPubblico(UploadedFile $file, string $cartella): string
    {
        $path = $file->store($cartella, $this->nomeDiscoPubblici());

        if ($path === false || $path === '') {
            throw new \RuntimeException("Impossibile scrivere il file sul disco «{$this->nomeDiscoPubblici()}».");
        }

        return $path;
    }

    public function esistePubblico(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->discoPubblici()->exists($path);
    }

    public function eliminaPubblico(?string $path): void
    {
        if ($this->esistePubblico($path)) {
            $this->discoPubblici()->delete($path);
        }
    }

    /**
     * L'indirizzo a cui il browser vede un file pubblico: sul disco locale è `/storage/…`
     * (il collegamento simbolico), su S3 un URL firmato che scade — il bucket resta privato.
     */
    public function urlPubblico(string $path): string
    {
        $disco = $this->discoPubblici();

        if ($this->nomeDiscoPubblici() === 'public') {
            return $disco->url($path);
        }

        return $disco->temporaryUrl($path, now()->addMinutes(30));
    }

    /**
     * Un percorso su disco locale per chi vuole un file e non uno stream (mPDF con la firma delle
     * stampe). Sul disco locale è il file stesso; altrove è una copia in `storage/framework/cache`,
     * che si rifà a ogni chiamata: è una firma da pochi KB, non vale una cache.
     */
    public function percorsoLocalePubblico(string $path): ?string
    {
        $disco = $this->discoPubblici();

        // Su S3 `exists()` e `get()` sollevano quando il bucket non risponde (a differenza delle
        // scritture): una firma irraggiungibile non deve fermare una stampa, come sul locale una
        // firma mancante non l'ha mai fermata. Si registra e si stampa senza.
        try {
            if (! $disco->exists($path)) {
                return null;
            }

            if ($this->nomeDiscoPubblici() === 'public') {
                return $disco->path($path);
            }

            $cartella = storage_path('framework/cache/pubblici');
            if (! is_dir($cartella)) {
                mkdir($cartella, 0755, true);
            }
            $locale = $cartella.DIRECTORY_SEPARATOR.md5($path).'.'.pathinfo($path, PATHINFO_EXTENSION);

            $contenuto = $disco->get($path);
            if ($contenuto === null) {
                return null;
            }

            // Copia atomica: due stampe insieme non devono far leggere a mPDF un file a metà.
            $temporaneo = tempnam($cartella, 'firma-');
            if ($temporaneo === false || file_put_contents($temporaneo, $contenuto) === false || ! rename($temporaneo, $locale)) {
                return null;
            }

            return $locale;
        } catch (\Throwable $e) {
            Log::warning('Firma delle stampe non leggibile dal disco '.$this->nomeDiscoPubblici().': stampa senza firma.', ['errore' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Il disco S3 risponde davvero? Scrive, rilegge e cancella un oggetto di prova, e solleva al
     * primo passo che fallisce — con `throw => false` le scritture tacerebbero. La chiama
     * `kondomanager:verifica-persistenza` (e con lui l'entrypoint del container): un bucket
     * sbagliato si scopre all'avvio, non al primo documento perso.
     *
     * @throws \RuntimeException
     */
    public function sonda(): void
    {
        if ($this->locale()) {
            return;
        }

        $nome = '.kondomanager-sonda-'.bin2hex(random_bytes(6));
        $disco = $this->disco();

        try {
            if (! $disco->put($nome, 'sonda')) {
                throw new \RuntimeException('scrittura rifiutata');
            }
            if ($disco->get($nome) !== 'sonda') {
                throw new \RuntimeException('rilettura diversa da quanto scritto');
            }
            if (! $disco->delete($nome)) {
                throw new \RuntimeException('cancellazione rifiutata');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Il disco «{$this->nomeDisco()}» non è utilizzabile: {$e->getMessage()}. Controlla AWS_ENDPOINT, AWS_BUCKET, le credenziali e DOCUMENTI_PREFIX.", 0, $e);
        }
    }
}
