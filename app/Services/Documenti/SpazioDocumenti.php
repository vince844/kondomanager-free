<?php

namespace App\Services\Documenti;

use App\Models\Documento;
use App\Settings\PrintSettings;

/**
 * Quanto spazio occupano i documenti, e quanto ne è concesso (1.11.0-beta.33).
 *
 * L'uso si somma dalla colonna `documenti.file_size`, non contando byte sul disco o su S3: la
 * roadmap (Coda 55, 21/08/2026) ha misurato che disco e database coincidono e che la colonna non
 * è mai nulla — e una somma costa una query, un `ListObjects` su un bucket no. La firma delle
 * stampe è l'unico file fuori dalla tabella e si aggiunge dal disco.
 *
 * Il limite (`LIMITE_SPAZIO_MB`) si mostra e non blocca: chi ospita misura e avvisa da fuori.
 */
final class SpazioDocumenti
{
    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    public function usatoByte(): int
    {
        $byte = (int) Documento::query()->sum('file_size');

        // La firma sta fuori dalla tabella: si aggiunge dal disco. Su S3 giù `exists()`/`size()`
        // sollevano: la somma della colonna resta esatta e la firma, pochi KB, si salta.
        try {
            $firma = app(PrintSettings::class)->firma_stampe_path;
            if ($firma && $this->archivio->esistePubblico($firma)) {
                $byte += (int) $this->archivio->discoPubblici()->size($firma);
            }
        } catch (\Throwable) {
            // vedi sopra
        }

        return $byte;
    }

    /** Il tetto in byte, o null se non c'è (0 o assente = illimitato). */
    public function limiteByte(): ?int
    {
        $mb = (int) config('kondomanager.limite_spazio_mb', 0);

        return $mb > 0 ? $mb * 1024 * 1024 : null;
    }
}
