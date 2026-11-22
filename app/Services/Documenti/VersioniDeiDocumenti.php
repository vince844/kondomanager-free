<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Documenti;

use App\Models\Documento;
use App\Models\DocumentoVersione;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Le versioni di prima dei documenti (§9 del registro delle modifiche, P21, decisione 518 (4)): un documento sostituito resta come versione di prima, in
 * sola lettura, con la sua impronta, e occupa spazio come gli altri file.
 *
 * **L'ordine della sostituzione**, dentro la transazione di chi chiama (`DocumentoController::update()` e le pagine dei documenti di persone, fornitori e
 * unità, decisione 559): prima si salva il file nuovo, poi il vecchio si **copia** nella cartella delle versioni, poi si scrivono la versione e i dati del
 * file sul documento, con la riga del registro «File». Il file vecchio si toglie dal suo posto **dopo il commit**: finché la transazione non è confermata
 * resta dov'era, così un processo che muore a metà (il limite di tempo di PHP, un riavvio) lascia il documento con il suo file, e al più una copia in
 * più fra le versioni. Se la transazione cade (qui o più avanti nel controller), una funzione legata al suo rollback toglie la copia e il file nuovo. Fino
 * alla beta.12 il file vecchio si cancellava **prima** di salvare il nuovo, e un errore dopo lo perdeva.
 *
 * **L'eliminazione** del documento scrive una riga sola, con il nome, la grandezza e l'impronta di ogni file che se ne va (il suo e quelli delle versioni), e
 * cancella i file **dopo il commit** (come `VersioniDelRendiconto::elimina`): se la transazione cade, i file restano con le loro righe.
 */
final class VersioniDeiDocumenti
{
    /** La cartella delle versioni, sul disco dei documenti. */
    public const CARTELLA = ArchivioDocumenti::CARTELLA . '/versioni';

    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    /**
     * Sostituisce il file del documento con quello caricato. Va chiamata dentro una transazione aperta: il rollback rimette i file com'erano.
     *
     * @return DocumentoVersione|null la versione di prima, o `null` se il file di prima sul disco non c'era più (la riga del registro lo dice)
     */
    public function sostituisci(Documento $documento, UploadedFile $file): ?DocumentoVersione
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La sostituzione del file di un documento va fatta dentro una transazione: il rollback rimette i file com\'erano.');
        }

        $vecchio = (string) $documento->path;
        $nomePrima = $documento->nomeDiScaricamento();
        $grandezzaPrima = (int) $documento->file_size;
        $mimePrima = (string) $documento->mime_type;

        // 1. Il file nuovo, prima di tutto: se la scrittura non va, il documento resta com'era.
        $nuovo = $this->archivio->salva($file);
        $copia = null;
        DB::afterRollBack(function () use (&$copia, $vecchio, $nuovo) {
            $this->rimettiComEra($vecchio, $copia, $nuovo);
        });

        // 2. Il file vecchio si copia fra le versioni (su S3 la copia la fa il disco), con la sua impronta letta prima; dal suo posto si toglie dopo il
        //    commit.
        $impronta = null;
        if ($vecchio !== '' && $this->archivio->esiste($vecchio)) {
            $impronta = $this->impronta($vecchio);
            $destinazione = self::CARTELLA . '/' . Str::random(40) . $this->estensione($vecchio);
            if (! $this->archivio->disco()->copy($vecchio, $destinazione)) {
                throw new \RuntimeException("Impossibile copiare il file di prima fra le versioni sul disco «{$this->archivio->nomeDisco()}».");
            }
            $copia = $destinazione;
            DB::afterCommit(function () use ($vecchio) {
                try {
                    $this->archivio->elimina($vecchio);
                } catch (Throwable $e) {
                    // La sostituzione è confermata e la versione ha la sua copia: il file rimasto occupa spazio ma non mente; lo dice il log.
                    Log::warning('Documento sostituito: il file di prima non si è potuto togliere dal suo posto', ['errore' => $e->getMessage()]);
                }
            });
        }

        $improntaNuova = $this->impronta($nuovo);
        $quando = now()->utc()->startOfSecond();
        $lavoro = function () use ($documento, $file, $nuovo, $copia, $impronta, $nomePrima, $grandezzaPrima, $mimePrima, $quando): ?DocumentoVersione {
            $versione = null;
            if ($copia !== null) {
                $versione = DocumentoVersione::query()->create([
                    'documento_id' => $documento->id,
                    'path' => $copia,
                    'nome_originale' => $nomePrima,
                    'mime_type' => $mimePrima !== '' ? $mimePrima : 'application/octet-stream',
                    'file_size' => $grandezzaPrima,
                    'impronta' => (string) $impronta,
                    'sostituita_il' => $quando,
                    'sostituita_da' => Auth::id(),
                ]);
            }
            // I dati del file sono del programma (P8): il tratto del documento non scrive una riga per loro, la scrive il gesto qui sotto.
            $documento->forceFill(['path' => $nuovo, 'mime_type' => $file->getClientMimeType(), 'file_size' => $file->getSize()])->save();

            return $versione;
        };

        $descrizione = CatalogoDelRegistro::descrizionePer($documento);
        if ($descrizione === null || ! $descrizione->registra($documento)) {
            return $lavoro();
        }

        $prima = $copia === null
            ? self::testoDelFile($nomePrima, $grandezzaPrima, null) . ' (il file non c\'era più sul disco)'
            : self::testoDelFile($nomePrima, $grandezzaPrima, $impronta);
        $dopo = self::testoDelFile((string) $file->getClientOriginalName(), (int) $file->getSize(), $improntaNuova);
        $gesto = Gesto::sulModello($documento)->azione('modificata')
            ->campo('file', 'File', $prima, $dopo)
            ->riepilogo($copia === null
                ? 'File sostituito: quello di prima non c\'era più sul disco, e non resta fra le versioni di prima.'
                : 'File sostituito: quello di prima resta fra le versioni di prima del documento, in sola lettura.')
            ->rimando(fn ($lotto, $versione) => $versione instanceof DocumentoVersione
                ? ['tipo' => 'versione_documento', 'id' => $versione->id, 'etichetta' => 'Versione di prima del ' . self::giorno($versione->sostituita_il)]
                : null);

        return RegistroModifiche::durante($gesto, fn () => $lavoro());
    }

    /**
     * Toglie il documento con le sue versioni, dentro una transazione aperta: una riga del registro con i file che se ne vanno (nome, grandezza,
     * impronta), e i file cancellati dopo il commit. Le righe delle versioni vanno con il documento (la chiave a cascata).
     */
    public function elimina(Documento $documento): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('L\'eliminazione di un documento con le sue versioni va fatta dentro una transazione: i file si tolgono dopo il commit.');
        }

        $versioni = $documento->versioni()->orderBy('sostituita_il')->orderBy('id')->get();
        $files = array_values(array_filter([(string) $documento->path, ...$versioni->pluck('path')->all()], fn ($p) => $p !== ''));
        DB::afterCommit(function () use ($files) {
            foreach ($files as $path) {
                try {
                    $this->archivio->elimina($path);
                } catch (Throwable $e) {
                    // Il documento è già tolto: un file rimasto occupa spazio ma non mente; lo dice il log.
                    Log::warning('Documento eliminato: un file non si è potuto cancellare dal disco', ['errore' => $e->getMessage()]);
                }
            }
        });

        $descrizione = CatalogoDelRegistro::descrizionePer($documento);
        if ($descrizione === null || ! $descrizione->registra($documento)) {
            $documento->delete();

            return;
        }

        $esiste = $documento->path !== null && $documento->path !== '' && $this->archivio->esiste($documento->path);
        $gesto = Gesto::sulModello($documento)->azione('eliminata')
            ->campo('file', 'File', self::testoDelFile($documento->nomeDiScaricamento(), (int) $documento->file_size,
                $esiste ? $this->impronta((string) $documento->path) : null) . ($esiste ? '' : ' (il file non c\'era più sul disco)'), null);
        foreach ($versioni as $versione) {
            $gesto->campo('versione_' . $versione->id, 'Versione di prima del ' . self::giorno($versione->sostituita_il),
                self::testoDelFile($versione->nome_originale, (int) $versione->file_size, $versione->impronta), null);
        }
        $gesto->riepilogo(match ($versioni->count()) {
            0 => 'Documento eliminato, con il suo file.',
            1 => 'Documento eliminato, con il suo file e 1 versione di prima.',
            default => 'Documento eliminato, con il suo file e ' . $versioni->count() . ' versioni di prima.',
        });

        RegistroModifiche::durante($gesto, fn () => $documento->delete());
    }

    /** Lo spazio delle versioni, in byte: conta come gli altri file (decisione 518 (4)). */
    public static function byteDelleVersioni(): int
    {
        return (int) DocumentoVersione::query()->sum('file_size');
    }

    /**
     * Le versioni di prima per la pagina di modifica: senza il percorso (P18), con chi l'ha sostituita, la grandezza e l'impronta.
     *
     * @return list<array<string, mixed>>
     */
    public static function perLaPagina(Documento $documento): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');

        return $documento->versioni()->with('sostituitaDa:id,name')->orderByDesc('sostituita_il')->orderByDesc('id')->get()
            ->map(fn (DocumentoVersione $v) => [
                'id' => $v->id,
                'nome' => $v->nome_originale,
                'sostituita_il' => $v->sostituita_il->copy()->setTimezone($fuso)->format('d/m/Y H:i'),
                'sostituita_da' => $v->sostituitaDa?->name,
                'grandezza' => (int) $v->file_size,
                'impronta' => $v->impronta,
            ])->values()->all();
    }

    /** «Verbale 12-2026.pdf · 1,2 MB · impronta 3f2a9c1b04de…». */
    public static function testoDelFile(string $nome, int $byte, ?string $impronta): string
    {
        return $nome . ' · ' . self::grandezza($byte) . ($impronta === null || $impronta === '' ? '' : ' · impronta ' . substr($impronta, 0, 12) . '…');
    }

    /** La grandezza all'italiana: «820 byte», «3 KB», «1,2 MB». */
    public static function grandezza(int $byte): string
    {
        if ($byte < 1024) {
            return $byte . ' byte';
        }
        [$valore, $unita] = $byte < 1024 * 1024 ? [$byte / 1024, 'KB'] : [$byte / (1024 * 1024), 'MB'];
        $testo = number_format($valore, 1, ',', '.');

        return (str_ends_with($testo, ',0') ? substr($testo, 0, -2) : $testo) . ' ' . $unita;
    }

    private static function giorno(\DateTimeInterface $quando): string
    {
        return \Illuminate\Support\Carbon::instance($quando)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
    }

    /** L'impronta sha256 del contenuto, letta a pezzi (un file grande su S3 non entra tutto in memoria). */
    private function impronta(string $path): ?string
    {
        $flusso = $this->archivio->disco()->readStream($path);
        if (! is_resource($flusso)) {
            return null;
        }
        $contesto = hash_init('sha256');
        hash_update_stream($contesto, $flusso);
        fclose($flusso);

        return hash_final($contesto);
    }

    private function estensione(string $path): string
    {
        $estensione = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $estensione === '' ? '' : '.' . $estensione;
    }

    /**
     * Il rollback: la copia fra le versioni e il file nuovo si tolgono; il file vecchio non si è mai mosso. Un intoppo non si propaga (il rollback è già
     * in corso): lo dice il log.
     */
    private function rimettiComEra(string $vecchio, ?string $copia, string $nuovo): void
    {
        try {
            if ($copia !== null && $copia !== $vecchio && $this->archivio->esiste($vecchio)) {
                $this->archivio->elimina($copia);
            } elseif ($copia !== null && ! $this->archivio->esiste($vecchio)) {
                // Il file di prima non c'è più al suo posto (non dovrebbe: si toglie solo dopo il commit): la copia lo rimette.
                $this->archivio->disco()->move($copia, $vecchio);
            }
            if ($nuovo !== $vecchio) {
                $this->archivio->elimina($nuovo);
            }
        } catch (Throwable $e) {
            Log::error('Sostituzione del file di un documento annullata: i file non sono tornati com\'erano', ['errore' => $e->getMessage(),
                'file_di_prima' => $vecchio, 'copia_in' => $copia]);
        }
    }
}
