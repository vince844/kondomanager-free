<?php

namespace App\Services\Gestionale\Invii;

/**
 * Un archivio .zip scritto a pezzi: ogni file va su un file temporaneo appena è pronto, e lo .zip si scrive alla fine da quei file
 * (`ZipArchive::addFile`, che li legge dal disco quando chiude l'archivio). La memoria tiene un documento alla volta, non la somma: con la carta
 * intestata ogni PDF porta la sua copia della carta, e venti PDF da più di un MB l'uno non starebbero insieme in un server da 128 MB.
 *
 * Lo .zip resta sul disco, in un file temporaneo: chi lo manda al browser lo legge a pezzi e lo cancella dopo. I pezzi si tolgono quando lo .zip è
 * scritto, e tutto (i pezzi e lo .zip) si toglie anche dopo un errore, con {@see togli()}. Dopo un errore fatale, che non passa dai `catch` (il tempo
 * della richiesta finito, la memoria finita), i pezzi e lo .zip si tolgono alla fine della richiesta: il primo {@see aggiungi()} lo chiede con
 * `register_shutdown_function`. Lo .zip si toglie così anche quando il tempo finisce mentre si chiude. Restano nella cartella temporanea solo se il
 * processo non arriva alla fine della richiesta: chiuso da PHP qualche secondo dopo il tempo, o ucciso.
 */
final class ArchivioAPezzi
{
    /** @var array<string, string> il nome nell'archivio => il file temporaneo */
    private array $pezzi = [];

    private ?string $zip = null;

    private bool $allaFine = false;

    /** @param class-string<\Throwable> $eccezione l'eccezione di chi chiama, con la frase per l'amministratore */
    public function __construct(private readonly string $eccezione) {}

    /** Un file dell'archivio, scritto subito sul disco. */
    public function aggiungi(string $nome, string $contenuto): void
    {
        // Un errore fatale a metà (il tempo della richiesta finito, anche mentre lo .zip si chiude, la memoria) non passa dai `catch`: i pezzi già
        // scritti e lo .zip, con i dati dei condòmini, li toglie la fine della richiesta. Uno .zip già mandato al browser non c'è più: chi lo manda lo
        // cancella dopo l'invio, prima della fine della richiesta.
        if ($this->pezzi === [] && ! $this->allaFine) {
            $this->allaFine = true;
            register_shutdown_function(fn () => $this->togli());
        }
        $percorso = tempnam(sys_get_temp_dir(), 'km_pezzo_');
        if ($percorso === false) {
            $this->fallisci();
        }
        $this->pezzi[$nome] = $percorso;
        if (@file_put_contents($percorso, $contenuto) !== strlen($contenuto)) {
            $this->fallisci();
        }
    }

    /** Lo .zip, scritto dai pezzi: il percorso del file temporaneo, che chi lo manda cancella. I pezzi si tolgono. */
    public function scrivi(): string
    {
        try {
            $percorso = tempnam(sys_get_temp_dir(), 'km_archivio_');
            if ($percorso === false) {
                $this->fallisci();
            }
            $this->zip = $percorso;
            $zip = new \ZipArchive;
            if ($zip->open($percorso, \ZipArchive::OVERWRITE) !== true) {
                $this->fallisci();
            }
            foreach ($this->pezzi as $nome => $pezzo) {
                if (! $zip->addFile($pezzo, $nome)) {
                    $this->fallisci();
                }
            }
            if (! $zip->close()) {
                $this->fallisci();
            }
        } catch (\Throwable $e) {
            $this->togli();

            throw $e;
        }
        $this->togliIPezzi();

        return $percorso;
    }

    /** Toglie i pezzi e lo .zip, se c'è: dopo un errore, o quando lo .zip non serve più. */
    public function togli(): void
    {
        $this->togliIPezzi();
        if ($this->zip !== null) {
            @unlink($this->zip);
            $this->zip = null;
        }
    }

    private function togliIPezzi(): void
    {
        foreach ($this->pezzi as $pezzo) {
            @unlink($pezzo);
        }
        $this->pezzi = [];
    }

    private function fallisci(): never
    {
        throw new ($this->eccezione)(InviiPerPersona::ARCHIVIO_NON_FATTO);
    }
}
