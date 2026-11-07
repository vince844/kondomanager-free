<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Un file caricato che dice di essere un PDF, un JPG o un PNG deve esserlo davvero: il contenuto (letto da `finfo`) è quello del nome. Per gli
 * altri tipi ammessi da chi chiama (un `.eml`, un `.p7m`, che `finfo` non riconosce) vale il nome. Il controllo si aggiunge alla regola
 * `extensions:`, che guarda solo il nome; sul disco il file si salva poi con l'estensione dichiarata
 * ({@see \App\Services\Documenti\ArchivioDocumenti::salvaConLEstensioneDelNome()}), non con quella che il contenuto fa indovinare.
 */
final class FileComeIlNome
{
    /** Le estensioni che `finfo` riconosce, con i tipi di contenuto che le corrispondono. */
    public const CONTENUTO_DELL_ESTENSIONE = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
    ];

    public const FRASE = 'Il file non è quello che il nome dice: un PDF, un JPG o un PNG devono esserlo davvero.';

    public static function vero(mixed $file): bool
    {
        if (! $file instanceof UploadedFile) {
            return false;
        }
        $attesi = self::CONTENUTO_DELL_ESTENSIONE[strtolower($file->getClientOriginalExtension())] ?? null;

        return $attesi === null || in_array($file->getMimeType(), $attesi, true);
    }

    /** Il nome e l'estensione di un tipo che `finfo` riconosce, per dire che cosa è davvero un file con l'estensione sbagliata. */
    private const NOME_DEL_CONTENUTO = ['application/pdf' => ['PDF', 'pdf'], 'image/jpeg' => ['JPG', 'jpg'], 'image/png' => ['PNG', 'png']];

    /**
     * La regola di validazione, da mettere accanto a `extensions:`. Un PDF, un JPG o un PNG veri con l'estensione di un altro dei tre (uno scanner
     * che salva un PNG come `.jpg`) hanno la frase che dice che cosa sono e come rinominarli; tutto il resto la frase di serie.
     */
    public static function regola(): \Closure
    {
        return function (string $campo, mixed $file, \Closure $fail): void {
            if (self::vero($file)) {
                return;
            }
            $vero = $file instanceof UploadedFile ? (self::NOME_DEL_CONTENUTO[(string) $file->getMimeType()] ?? null) : null;
            $fail($vero === null ? self::FRASE
                : 'Il file «' . $file->getClientOriginalName() . '» è un ' . $vero[0] . ': rinominalo con l\'estensione .' . $vero[1] . ' e caricalo di nuovo.');
        };
    }
}
