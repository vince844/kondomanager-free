<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore;

use App\Enums\RestoreStatus;
use App\Models\Documento;
use App\Models\User;
use App\Services\Backup\Support\StepBudget;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\Autore;
use App\Services\RegistroModifiche\FirmaDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\SigilloDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Il registro delle modifiche nel ripristino di una copia (decisione 546, §12 del progetto con P1, P2, P14, P15, P16): il ripristino ricrea tutte le
 * tabelle dal dump, e le righe del registro venute dopo la copia sparirebbero. Non spariscono: restano, segnate «annullata dal ripristino del …», e poi la
 * riga «Ripristinata la copia del …», con chi l'ha fatto.
 *
 * **Passo 1, prima del `wipe()`** ({@see salva()}): si sigilla e si scrivono tutte le righe del registro, con i loro condomini e soggetti, in un file NDJSON
 * accanto alle copie (`storage/app/backups/`, che il ripristino non tocca), a blocchi dentro il budget del passo, segnati nel checkpoint. Se la tabella non
 * c'è (il ripristino dal primo avvio), il passo si salta.
 *
 * **Passo 2, dopo `finalize()`** ({@see rimetti()}), una fase a sé, nel checkpoint, a blocchi e in transazione, che riprende senza rifare ciò che è fatto:
 *  1. le righe della copia rimaste senza sequenza che nel file ci sono uguali (stesso `id`, stessa impronta dei valori) prendono la versione sigillata del
 *     file (P1);
 *  2. la riga in comune: l'ultima sigillata della copia, se nel file c'è con lo stesso `id`, la stessa sequenza e la stessa impronta;
 *  3. con la riga in comune, le righe del file venute dopo si **rimettono** con la loro sequenza e le loro impronte (erano già legate a quella riga), e con
 *     le loro righe di condomini e soggetti **identiche** (l'impronta copre anche quelle);
 *  4. gli oscuramenti non si annullano (P2): ogni riga del file oscurata riporta i suoi valori oscurati sulla riga con lo stesso `id` e la stessa impronta;
 *  5. **senza la riga in comune** (copia di un'altra installazione, di prima del registro, storia diversa) le righe del file vanno in un PDF con le loro
 *     impronte, conservato fra i documenti dello studio (non pubblicato, senza destinatari, P18), e la riga del ripristino lo collega;
 *  6. la catena (`registro_modifiche_catena`) si ricostruisce da `MAX(sequenza)` e dalla sua impronta (P1);
 *  7. la riga `ripristino_copia`, una sola anche se il passo riprende, con l'autore copiato all'avvio (P15) e nel lotto la sequenza in comune, la prima e
 *     l'ultima rimesse e gli oscuramenti riapplicati (la forma che legge `LetturaDelRegistro::annullamento()`);
 *  8. il file si toglie. Lo toglie anche «Annulla» del ripristino; un file rimasto (un errore anche nel PDF, un ripristino lasciato a metà) lo toglie la
 *     conservazione dopo trenta giorni ({@see togliIFileRimasti()}), salvo quello di un ripristino ancora da riprendere.
 *
 * **La firma** ({@see FirmaDelRegistro}): il passo 1 controlla la firma di ogni riga con la chiave in uso prima del ripristino e lo scrive nel file; il
 * passo 2 rimette solo le righe che tornavano, e le firma con la chiave di adesso, che è quella della copia se il ripristino l'ha adottata (la riga del
 * ripristino conta le righe firmate di nuovo, `lotto.righe_rifirmate`). Le righe che non tornavano non si rimettono: vanno in un PDF a sé, e la riga lo
 * dice (`lotto.righe_senza_firma`).
 *
 * **Un errore del passo 2 non lascia il programma in manutenzione senza uscita** (P1): il ripristino finisce, le righe del file vanno nel PDF se si può, e la
 * riga del ripristino dice che cosa non è stato rimesso. Se nemmeno il PDF si può fare, il file resta nella cartella delle copie e la riga lo dice.
 *
 * È uno dei servizi che scrivono le tabelle del registro fuori dalla nascita di una riga (con `DB::table`: il modello lo rifiuta).
 */
class RegistroNelRipristino
{
    /** Quante righe per blocco, nel file e nella transazione. */
    public const BLOCCO = 500;

    /** Quante righe al più nel PDF del passo 5, per non fermare il ripristino su un registro enorme: le altre restano nel file, e la riga lo dice. */
    public const MASSIMO_NEL_PDF = 20000;

    public const FATTO = 'fatto';

    /**
     * Quanti giorni resta il file delle righe dopo un ripristino che non ha potuto rimetterle né metterle nel PDF: il tempo perché chi gestisce il server
     * le recuperi. Il trentesimo giorno dopo quello del file lo toglie la conservazione ({@see togliIFileRimasti()}): il file ha i dati delle persone, che
     * l'oscuramento e la durata delle righe non raggiungono, e la finestra dell'oscuramento lo dice finché c'è.
     */
    public const GIORNI_DEL_FILE_RIMASTO = 30;

    public function percorsoDelFile(string $uuid): string
    {
        $disco = (string) config('backup.disk', 'backups');
        $cartella = (string) config('filesystems.disks.' . $disco . '.root', storage_path('app/backups'));

        return rtrim($cartella, '/') . '/.registro-modifiche-' . $uuid . '.ndjson';
    }

    /* ------------------------------------------------------------------
     | Passo 1: prima del wipe
     | ------------------------------------------------------------------ */

    /**
     * Scrive le righe del registro nel file, a blocchi, finché il budget lo consente. Restituisce lo stato del registro per il checkpoint: `salvato` vero
     * quando il file è completo (o quando la tabella non c'è).
     *
     * @param array<string, mixed> $stato lo stato del ripristino
     * @return array<string, mixed>
     */
    public function salva(array $stato, StepBudget $budget): array
    {
        $registro = $stato['checkpoint']['registro'] ?? [];
        if ($registro['salvato'] ?? false) {
            return $registro;
        }
        if (! Schema::hasTable('registro_modifiche')) {
            return ['salvato' => true, 'file' => null, 'righe' => 0];
        }

        $percorso = $registro['file'] ?? $this->percorsoDelFile((string) $stato['uuid']);
        // Un file che non c'è più (tolto a mano fra due passi) si riscrive da capo: la tabella è ancora intera, il wipe viene dopo.
        if (! isset($registro['file']) || ! is_file($percorso)) {
            // Il primo blocco: tutte le righe al loro posto nella catena, prima di leggerle.
            SigilloDelRegistro::sigillaSenzaFermare();
            File::ensureDirectoryExists(dirname($percorso));
            File::put($percorso, '');
            $registro = ['salvato' => false, 'file' => $percorso, 'ultimo_id' => 0, 'byte' => 0, 'righe' => 0];
        }

        $flusso = fopen($percorso, 'c+');
        if ($flusso === false) {
            throw new RuntimeException('Il file delle righe del registro delle modifiche non si apre nella cartella delle copie.');
        }
        try {
            // Un passo fermato a metà di un blocco ha lasciato righe in più: si riparte dall'ultimo blocco confermato.
            ftruncate($flusso, (int) $registro['byte']);
            fseek($flusso, (int) $registro['byte']);
            do {
                $blocco = DB::table('registro_modifiche')->where('id', '>', (int) $registro['ultimo_id'])->orderBy('id')->limit(self::BLOCCO)->get();
                if ($blocco->isNotEmpty()) {
                    $ids = $blocco->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $condomini = SigilloDelRegistro::condominiDelle($ids);
                    $soggetti = SigilloDelRegistro::soggettiDelle($ids);
                    $testo = '';
                    foreach ($blocco as $riga) {
                        $suoiCondomini = $condomini[(int) $riga->id] ?? [];
                        $suoiSoggetti = $soggetti[(int) $riga->id] ?? [];
                        // La firma si controlla adesso, con la chiave in uso prima del ripristino: in finalizzazione la chiave può essere quella adottata
                        // dalla copia, e quella di prima non c'è più.
                        $testo .= json_encode(['riga' => (array) $riga, 'condomini' => $suoiCondomini, 'soggetti' => $suoiSoggetti,
                            'firma_valida' => FirmaDelRegistro::torna($riga, $suoiCondomini, $suoiSoggetti)],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    }
                    if (fwrite($flusso, $testo) !== strlen($testo)) {
                        throw new RuntimeException('Il file delle righe del registro delle modifiche non si scrive: disco pieno?');
                    }
                    fflush($flusso);
                    $registro['ultimo_id'] = (int) end($ids);
                    $registro['byte'] = (int) ftell($flusso);
                    $registro['righe'] = (int) $registro['righe'] + count($ids);
                }
                if ($blocco->count() < self::BLOCCO) {
                    $registro['salvato'] = true;
                    break;
                }
            } while (! $budget->exceeded());
        } finally {
            fclose($flusso);
        }

        return $registro;
    }

    /* ------------------------------------------------------------------
     | Passo 2: dopo finalize()
     | ------------------------------------------------------------------ */

    /**
     * Rimette le righe venute dopo la copia, a blocchi, finché il budget lo consente. Restituisce lo stato del registro per il checkpoint: `fase` vale
     * {@see FATTO} quando il passo è finito (anche dopo un errore: il ripristino finisce lo stesso), con `esito` per l'esito del ripristino.
     *
     * @param array<string, mixed> $stato lo stato del ripristino
     * @return array<string, mixed>
     */
    public function rimetti(array $stato, StepBudget $budget): array
    {
        $registro = ($stato['checkpoint']['registro'] ?? []) + ['salvato' => false, 'file' => null, 'righe' => 0];
        $registro['fase'] ??= 'analisi';
        if ($registro['fase'] === self::FATTO) {
            return $registro;
        }

        try {
            if (! Schema::hasTable('registro_modifiche')) {
                // Il database ripristinato non ha il registro (le migrazioni non l'hanno creato: un ripristino di prova): niente da fare.
                return ['fase' => self::FATTO, 'esito' => 'il registro delle modifiche non c\'è nel database ripristinato'] + $registro;
            }
            if ($registro['fase'] === 'analisi') {
                $registro = $this->analizza($registro);
            }
            while ($registro['fase'] === 'rimettere' && ! $budget->exceeded()) {
                $registro = $this->unBlocco($registro);
            }
            if ($registro['fase'] === 'chiusura') {
                $registro = $this->chiudi($stato, $registro);
            }
        } catch (Throwable $e) {
            report($e);
            $registro = $this->dopoUnErrore($stato, $registro, $e);
        }

        return $registro;
    }

    /** «Annulla» del ripristino, o un ripristino che si chiude: il file non serve più. */
    public function togliIlFile(array $stato): void
    {
        $percorso = $stato['checkpoint']['registro']['file'] ?? (isset($stato['uuid']) ? $this->percorsoDelFile((string) $stato['uuid']) : null);
        if (is_string($percorso) && is_file($percorso)) {
            @unlink($percorso);
        }
    }

    /**
     * I file delle righe rimasti nella cartella delle copie (un ripristino finito con un errore anche nel PDF, uno fermato e poi annullato a mano, un
     * processo morto), dal più vecchio, con il giorno del file e quello in cui la conservazione lo toglie ({@see GIORNI_DEL_FILE_RIMASTO} giorni dopo, nel
     * giorno di Roma); senza quello di un ripristino ancora da riprendere (fermato, non finito né annullato), che alla ripresa serve. La finestra
     * dell'oscuramento li dice.
     *
     * @return list<array{nome: string, percorso: string, del: Carbon, si_toglie_il: Carbon}>
     */
    public function fileRimasti(): array
    {
        $disco = (string) config('backup.disk', 'backups');
        $cartella = rtrim((string) config('filesystems.disks.' . $disco . '.root', storage_path('app/backups')), '/');
        $daTenere = null;
        try {
            $stato = app(RestoreState::class)->get();
        } catch (Throwable) {
            $stato = null;
        }
        if (is_array($stato) && ($stato['phase'] ?? null) !== RestoreStatus::COMPLETED->value && ! ($stato['aborted'] ?? false)) {
            $daTenere = $stato['checkpoint']['registro']['file'] ?? (isset($stato['uuid']) ? $this->percorsoDelFile((string) $stato['uuid']) : null);
        }

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $rimasti = [];
        foreach (glob($cartella . '/.registro-modifiche-*.ndjson') ?: [] as $file) {
            $ora = @filemtime($file);
            if ($file === $daTenere || $ora === false) {
                continue;
            }
            $del = Carbon::createFromTimestamp($ora, 'UTC')->setTimezone($fuso);
            $rimasti[] = ['nome' => basename($file), 'percorso' => $file, 'del' => $del,
                'si_toglie_il' => $del->copy()->startOfDay()->addDays(self::GIORNI_DEL_FILE_RIMASTO)];
        }
        usort($rimasti, fn ($a, $b) => $a['del'] <=> $b['del']);

        return $rimasti;
    }

    /**
     * Toglie i file rimasti il cui giorno è arrivato ({@see fileRimasti()}): lo fa la conservazione, ogni notte. Restituisce i file tolti, con il giorno.
     *
     * @return list<array{nome: string, del: Carbon}>
     */
    public function togliIFileRimasti(): array
    {
        $oggi = now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->startOfDay();
        $tolti = [];
        foreach ($this->fileRimasti() as $f) {
            if ($f['si_toglie_il']->greaterThan($oggi)) {
                continue;
            }
            if (@unlink($f['percorso'])) {
                $tolti[] = ['nome' => $f['nome'], 'del' => $f['del']];
            }
        }

        return $tolti;
    }

    /**
     * Le righe della copia senza sequenza che il file ha sigillate (P1), la riga in comune e il controllo che gli `id` delle righe da rimettere siano
     * liberi. Una sola lettura del file.
     *
     * @param array<string, mixed> $registro
     * @return array<string, mixed>
     */
    private function analizza(array $registro): array
    {
        $file = $registro['file'] ?? null;
        if (! is_string($file) || ! is_file($file)) {
            return ['fase' => 'chiusura', 'comune' => null, 'offset' => 0, 'rimesse' => 0, 'oscuramenti' => 0] + $registro;
        }

        // Le righe della copia ancora senza sequenza: di solito nessuna (il backup sigilla prima del dump), al più quelle scritte durante il dump.
        $senzaSequenza = DB::table('registro_modifiche')->whereNull('sequenza')->pluck('impronta_valori', 'id')->map(fn ($v) => (string) $v)->all();
        $ultima = DB::table('registro_modifiche')->whereNotNull('sequenza')->orderByDesc('sequenza')->first(['id', 'sequenza', 'impronta']);

        $daSigillare = [];
        $comune = null;
        $conflitti = 0;
        $primaSequenzaDelFile = null;
        $righeNelFile = 0;
        foreach ($this->righeDelFile($file) as [$voce]) {
            $riga = $voce['riga'];
            $id = (int) $riga['id'];
            $righeNelFile++;
            if ($riga['sequenza'] !== null) {
                $primaSequenzaDelFile = $primaSequenzaDelFile === null ? (int) $riga['sequenza'] : min($primaSequenzaDelFile, (int) $riga['sequenza']);
            }
            if (isset($senzaSequenza[$id])) {
                if ($senzaSequenza[$id] === (string) $riga['impronta_valori']) {
                    if ($riga['sequenza'] !== null) {
                        $daSigillare[$id] = $riga;
                    }
                } else {
                    // Lo stesso numero di riga con valori diversi: le due storie non sono la stessa.
                    $conflitti++;
                }
            }
        }
        DB::transaction(function () use ($daSigillare) {
            foreach ($daSigillare as $id => $riga) {
                DB::table('registro_modifiche')->where('id', $id)->whereNull('sequenza')
                    ->update(['sequenza' => (int) $riga['sequenza'], 'impronta_precedente' => $riga['impronta_precedente'], 'impronta' => $riga['impronta']]);
            }
        });

        // La riga in comune si cerca dopo: una riga appena sigillata dal file può essere l'ultima.
        if ($daSigillare !== []) {
            $ultima = DB::table('registro_modifiche')->whereNotNull('sequenza')->orderByDesc('sequenza')->first(['id', 'sequenza', 'impronta']);
        }
        if ($righeNelFile > 0 && ! DB::table('registro_modifiche')->exists()) {
            // La copia non ha righe del registro (è di prima del registro, o il registro era vuoto): niente è in contrasto, e le righe di prima tornano
            // tutte, annullate da questo ripristino, con la catena intera (coerente con la decisione 546: le righe venute dopo la copia restano). La «riga in
            // comune» è il posto prima della prima riga del file: 0, o la sequenza prima della prima rimasta dopo una conservazione.
            $comune = ['id' => null, 'sequenza' => max(0, (int) ($primaSequenzaDelFile ?? 1) - 1), 'copia_senza_registro' => true];
        } elseif ($ultima !== null && $conflitti === 0) {
            foreach ($this->righeDelFile($file) as [$voce]) {
                $riga = $voce['riga'];
                if ((int) $riga['id'] === (int) $ultima->id && (int) ($riga['sequenza'] ?? -1) === (int) $ultima->sequenza
                    && (string) $riga['impronta'] === (string) $ultima->impronta) {
                    $comune = ['id' => (int) $ultima->id, 'sequenza' => (int) $ultima->sequenza];
                    break;
                }
            }
        }

        return ['fase' => 'rimettere', 'comune' => $comune, 'offset' => 0, 'rimesse' => 0, 'prima' => null, 'ultima' => null, 'oscuramenti' => 0,
            'sigillate_dal_file' => count($daSigillare), 'conflitti' => $conflitti, 'firma_della_copia' => $this->firmaDellaCopia($ultima)] + $registro;
    }

    /**
     * Se l'ultima riga sigillata della copia ha la firma di questa installazione ({@see FirmaDelRegistro}): una copia di un'altra installazione,
     * ripristinata senza adottarne la chiave, ha righe firmate con un'altra chiave, che la verifica segnerà. Una riga sola basta a dirlo, e non costa.
     */
    private function firmaDellaCopia(?object $ultima): bool
    {
        if ($ultima === null) {
            return true;
        }
        $riga = DB::table('registro_modifiche')->where('id', $ultima->id)->first();
        $id = (int) $ultima->id;

        return $riga === null || FirmaDelRegistro::torna($riga, SigilloDelRegistro::condominiDelle([$id])[$id] ?? [],
            SigilloDelRegistro::soggettiDelle([$id])[$id] ?? []);
    }

    /**
     * Un blocco del file, in una transazione: le righe venute dopo la copia si rimettono (con la riga in comune), gli oscuramenti si riportano. Ripreso
     * dopo un'interruzione fra il commit e il checkpoint, non fa doppioni: una riga che c'è già non si rimette, un oscuramento già riportato non cambia.
     *
     * @param array<string, mixed> $registro
     * @return array<string, mixed>
     */
    private function unBlocco(array $registro): array
    {
        $comune = $registro['comune'] ?? null;
        $voci = [];
        $fine = (int) $registro['offset'];
        $finito = true;
        foreach ($this->righeDelFile((string) $registro['file'], (int) $registro['offset']) as [$voce, $dopo]) {
            $voci[] = $voce;
            $fine = $dopo;
            if (count($voci) >= self::BLOCCO) {
                $finito = false;
                break;
            }
        }
        if ($voci === []) {
            return ['fase' => 'chiusura'] + $registro;
        }

        $ids = array_map(fn ($v) => (int) $v['riga']['id'], $voci);
        $esistenti = DB::table('registro_modifiche')->whereIn('id', $ids)->get(['id', 'impronta', 'impronta_valori', 'campi', 'riepilogo', 'perche', 'oscurata_il',
            'oscurata_da'])->keyBy('id');

        [$rimesse, $prima, $ultima, $oscuramenti, $scartate, $rifirmate] = DB::transaction(function () use ($voci, $esistenti, $comune) {
            $righe = [];
            $condomini = [];
            $soggetti = [];
            $prima = null;
            $ultima = null;
            $oscuramenti = 0;
            $scartate = [];
            $rifirmate = 0;
            foreach ($voci as $voce) {
                $riga = $voce['riga'];
                $id = (int) $riga['id'];
                $qui = $esistenti->get($id);
                if ($qui !== null) {
                    if ($riga['oscurata_il'] !== null && $this->stessaRiga($qui, $riga) && $this->oscuramentoDaRiportare($qui, $riga)) {
                        DB::table('registro_modifiche')->where('id', $id)->update(['campi' => $riga['campi'], 'riepilogo' => $riga['riepilogo'],
                            'perche' => $riga['perche'], 'oscurata_il' => $riga['oscurata_il'], 'oscurata_da' => $riga['oscurata_da']]);
                        $oscuramenti++;
                    }

                    continue;
                }
                if ($comune === null || ($riga['sequenza'] !== null && (int) $riga['sequenza'] <= (int) $comune['sequenza'])) {
                    continue;
                }
                // Una riga che non tornava con la chiave di prima del ripristino non si rimette: va nel PDF. Le altre si firmano con la chiave di adesso,
                // che è quella adottata dalla copia, se il ripristino l'ha adottata (con la stessa chiave la firma non cambia).
                if (! ($voce['firma_valida'] ?? FirmaDelRegistro::torna($riga, $voce['condomini'], $voce['soggetti']))) {
                    $scartate[] = $id;

                    continue;
                }
                $firma = FirmaDelRegistro::firma($riga, $voce['condomini'], array_map(fn ($s) => [(string) $s[0], (int) $s[1]], $voce['soggetti']));
                if ($firma !== ($riga['firma'] ?? null)) {
                    $riga['firma'] = $firma;
                    $rifirmate++;
                }
                $righe[] = $riga;
                foreach ($voce['condomini'] as $condominio => $nome) {
                    $condomini[] = ['riga_id' => $id, 'condominio_id' => (int) $condominio, 'condominio_nome' => (string) $nome];
                }
                foreach ($voce['soggetti'] as [$tipo, $soggetto]) {
                    $soggetti[] = ['riga_id' => $id, 'soggetto_tipo' => (string) $tipo, 'soggetto_id' => (int) $soggetto];
                }
                if ($riga['sequenza'] !== null) {
                    $prima = $prima === null ? (int) $riga['sequenza'] : min($prima, (int) $riga['sequenza']);
                    $ultima = $ultima === null ? (int) $riga['sequenza'] : max($ultima, (int) $riga['sequenza']);
                }
            }
            foreach (array_chunk($righe, 100) as $pezzo) {
                DB::table('registro_modifiche')->insert($pezzo);
            }
            foreach (array_chunk($condomini, 500) as $pezzo) {
                DB::table('registro_modifiche_condomini')->insert($pezzo);
            }
            foreach (array_chunk($soggetti, 500) as $pezzo) {
                DB::table('registro_modifiche_soggetti')->insert($pezzo);
            }

            return [count($righe), $prima, $ultima, $oscuramenti, $scartate, $rifirmate];
        });

        $registro['offset'] = $fine;
        $registro['rimesse'] = (int) ($registro['rimesse'] ?? 0) + $rimesse;
        $registro['scartate'] = array_values(array_unique([...($registro['scartate'] ?? []), ...$scartate]));
        $registro['rifirmate'] = (int) ($registro['rifirmate'] ?? 0) + $rifirmate;
        $registro['oscuramenti'] = (int) ($registro['oscuramenti'] ?? 0) + $oscuramenti;
        if ($prima !== null) {
            $registro['prima'] = $registro['prima'] === null ? $prima : min((int) $registro['prima'], $prima);
            $registro['ultima'] = $registro['ultima'] === null ? $ultima : max((int) $registro['ultima'], $ultima);
        }
        if ($finito) {
            $registro['fase'] = 'chiusura';
        }

        return $registro;
    }

    /**
     * La chiusura: il PDF se non c'è la riga in comune, la catena ricostruita, la riga del ripristino, il file tolto.
     *
     * @param array<string, mixed> $stato
     * @param array<string, mixed> $registro
     * @return array<string, mixed>
     */
    private function chiudi(array $stato, array $registro): array
    {
        $file = $registro['file'] ?? null;
        $conFile = is_string($file) && is_file($file);
        if ($conFile && ($registro['comune'] ?? null) === null && (int) ($registro['righe'] ?? 0) > 0 && ! isset($registro['pdf'])) {
            $registro['pdf'] = $this->pdf($stato, $registro);
        }
        if ($conFile && ($registro['comune'] ?? null) !== null && ($registro['scartate'] ?? []) !== [] && ! isset($registro['pdf'])) {
            $registro['pdf'] = $this->pdf($stato, $registro, $registro['scartate']);
        }

        $this->ricostruisciLaCatena();
        $registro['riga_id'] = $this->scriviLaRiga($stato, $registro);
        $this->ricostruisciLaCatenaDopoIlSigillo();

        if ($conFile) {
            @unlink($file);
        }
        $registro['fase'] = self::FATTO;
        $registro['esito'] = match (true) {
            ($registro['comune'] ?? null) !== null => ['righe_rimesse' => (int) $registro['rimesse'], 'oscuramenti_riapplicati' => (int) $registro['oscuramenti']]
                + (($registro['comune']['copia_senza_registro'] ?? false) ? ['tutte' => true] : [])
                + (($registro['scartate'] ?? []) !== [] ? ['righe_senza_firma' => count($registro['scartate'])] : []),
            isset($registro['pdf']) => ['righe_nel_pdf' => (int) $registro['righe'], 'nome_pdf' => (string) Documento::query()->whereKey($registro['pdf'])->value('name')],
            default => ['righe_nel_pdf' => 0],
        };

        return $registro;
    }

    /**
     * Dopo un errore del passo 2: il ripristino finisce lo stesso. Le righe del file vanno nel PDF se si può (e allora il file si toglie), la catena si
     * ricostruisce, e la riga del ripristino dice che cosa non è stato rimesso.
     *
     * @param array<string, mixed> $stato
     * @param array<string, mixed> $registro
     * @return array<string, mixed>
     */
    private function dopoUnErrore(array $stato, array $registro, Throwable $errore): array
    {
        $registro['errore'] = mb_substr(MessaggiDelRipristino::senzaPercorsi($errore->getMessage()), 0, 300);
        $file = $registro['file'] ?? null;
        try {
            if (is_string($file) && is_file($file) && ! isset($registro['pdf']) && (int) ($registro['righe'] ?? 0) > 0) {
                $registro['pdf'] = $this->pdf($stato, $registro);
            }
        } catch (Throwable $e) {
            report($e);
        }
        try {
            $this->ricostruisciLaCatena();
            $registro['riga_id'] = $this->scriviLaRiga($stato, $registro);
            $this->ricostruisciLaCatenaDopoIlSigillo();
        } catch (Throwable $e) {
            report($e);
            $registro['riga_non_scritta'] = true;
        }
        if (isset($registro['pdf']) && is_string($file) && is_file($file)) {
            @unlink($file);
        }
        $registro['fase'] = self::FATTO;
        $registro['esito'] = array_filter(['errore' => $registro['errore'], 'righe_rimesse' => (int) ($registro['rimesse'] ?? 0),
            'nome_pdf' => isset($registro['pdf']) ? (string) Documento::query()->whereKey($registro['pdf'])->value('name') : null,
            'file_rimasto' => is_string($file) && is_file($file) ? basename($file) : null], fn ($v) => $v !== null);

        return $registro;
    }

    /** La catena da `MAX(sequenza)` e dalla sua impronta (P1): il sigillo riparte da lì. */
    private function ricostruisciLaCatena(): void
    {
        DB::table('registro_modifiche_catena')->insertOrIgnore(['id' => 1, 'ultima_sequenza' => 0, 'ultima_impronta' => null]);
        $ultima = DB::table('registro_modifiche')->whereNotNull('sequenza')->orderByDesc('sequenza')->first(['sequenza', 'impronta']);
        DB::table('registro_modifiche_catena')->where('id', 1)->update([
            'ultima_sequenza' => $ultima === null ? 0 : (int) $ultima->sequenza,
            'ultima_impronta' => $ultima?->impronta,
        ]);
    }

    /** Dopo la riga del ripristino il sigillo l'ha già messa in catena; se non ha potuto, la sigillerà il comando pianificato. */
    private function ricostruisciLaCatenaDopoIlSigillo(): void
    {
        SigilloDelRegistro::sigillaSenzaFermare();
    }

    /**
     * La riga `ripristino_copia`, una sola anche se la chiusura si ripete: si cerca quella di questo ripristino prima di scriverla.
     *
     * @param array<string, mixed> $stato
     * @param array<string, mixed> $registro
     */
    private function scriviLaRiga(array $stato, array $registro): int
    {
        $uuid = (string) ($stato['uuid'] ?? '');
        $gia = DB::table('registro_modifiche')->where('azione', 'ripristino_copia')->orderByDesc('id')->limit(20)->get(['id', 'lotto'])
            ->first(fn ($r) => (json_decode((string) $r->lotto, true)['ripristino'] ?? null) === $uuid);
        if ($gia !== null) {
            return (int) $gia->id;
        }

        $copiaDel = $this->copiaDel($stato);
        $comune = $registro['comune'] ?? null;
        $rimesse = (int) ($registro['rimesse'] ?? 0);
        $oscuramenti = (int) ($registro['oscuramenti'] ?? 0);
        $pdf = isset($registro['pdf']) ? Documento::query()->find($registro['pdf']) : null;

        $riepilogo = 'Ripristinata la copia del ' . ($copiaDel['testo'] ?? 'giorno non letto') . '.';
        if ($comune !== null && ($comune['copia_senza_registro'] ?? false)) {
            $riepilogo .= $rimesse === 1
                ? ' La copia non aveva righe del registro: la riga di prima torna nel registro, annullata da questo ripristino.'
                : ' La copia non aveva righe del registro: le ' . number_format($rimesse, 0, ',', '.') . ' righe di prima tornano tutte nel registro, annullate da questo ripristino.';
        } elseif ($comune !== null) {
            $riepilogo .= match (true) {
                $rimesse === 0 => ' Nessuna riga del registro era venuta dopo la copia.',
                $rimesse === 1 => ' La riga venuta dopo la copia resta nel registro, annullata da questo ripristino.',
                default => ' Le ' . number_format($rimesse, 0, ',', '.') . ' righe venute dopo la copia restano nel registro, annullate da questo ripristino.',
            };
        } elseif ((int) ($registro['righe'] ?? 0) > 0 && ! isset($registro['errore'])) {
            $riepilogo .= ' Il registro della copia non ha righe in comune con quello di prima: le ' . number_format((int) $registro['righe'], 0, ',', '.')
                . ' righe di prima sono ' . ($pdf !== null ? 'nel PDF «' . $pdf->name . '», con le impronte.' : 'rimaste fuori: il PDF non si è potuto fare.');
        }
        if ($oscuramenti > 0) {
            $riepilogo .= $oscuramenti === 1 ? ' 1 oscuramento riapplicato.' : " {$oscuramenti} oscuramenti riapplicati.";
        }
        $rifirmate = (int) ($registro['rifirmate'] ?? 0);
        if ($comune !== null && $rifirmate > 0) {
            $riepilogo .= $rifirmate === 1 ? ' La chiave della copia è stata adottata: la riga rimessa è firmata di nuovo con lei.'
                : ' La chiave della copia è stata adottata: le ' . number_format($rifirmate, 0, ',', '.') . ' righe rimesse sono firmate di nuovo con lei.';
        }
        $scartate = count($registro['scartate'] ?? []);
        if ($comune !== null && $scartate > 0) {
            $dove = $pdf !== null ? 'nel PDF «' . $pdf->name . '»' : 'nel file della cartella delle copie (il PDF non si è potuto fare)';
            $riepilogo .= $scartate === 1 ? ' 1 riga del file non aveva la firma del programma e non è stata rimessa: è ' . $dove . '.'
                : ' ' . number_format($scartate, 0, ',', '.') . ' righe del file non avevano la firma del programma e non sono state rimesse: sono ' . $dove . '.';
        }
        $altraFirma = ($registro['firma_della_copia'] ?? true) === false;
        if ($altraFirma) {
            $riepilogo .= ' Le righe della copia non hanno la firma di questa installazione: «Verifica l\'integrità» le segnerà finché chi gestisce il server'
                . ' non aggiunge la chiave di quella installazione fra le chiavi di prima.';
        }
        if (isset($registro['errore'])) {
            $riepilogo .= ' Non tutte le righe venute dopo la copia sono state rimesse (' . $rimesse . ' su ' . (int) ($registro['righe'] ?? 0) . ' nel file'
                . ($pdf !== null ? '; tutte sono nel PDF «' . $pdf->name . '»' : '; il file con le righe resta nella cartella delle copie')
                . '): ' . $registro['errore'];
        }

        $gesto = Gesto::su('impostazioni', 'copia', null, 'Copia del ' . ($copiaDel['testo'] ?? 'giorno non letto'))
            ->azione('ripristino_copia')
            ->scheda('impostazioni_backup', null)
            ->riepilogo($riepilogo)
            ->lotto(array_filter([
                'ripristino' => $uuid,
                'copia_del' => $copiaDel['data'] ?? null,
                'sequenza_comune' => $comune['sequenza'] ?? null,
                'copia_senza_registro' => ($comune['copia_senza_registro'] ?? false) ? true : null,
                'prima_sequenza_rimessa' => $registro['prima'] ?? null,
                'ultima_sequenza_rimessa' => $registro['ultima'] ?? null,
                'righe_rimesse' => $comune !== null ? $rimesse : null,
                'righe_rifirmate' => $comune !== null ? $rifirmate : null,
                'righe_senza_firma' => $scartate > 0 ? $scartate : null,
                'righe_nel_file' => (int) ($registro['righe'] ?? 0),
                'oscuramenti_riapplicati' => $oscuramenti,
                'documento_pdf' => $pdf?->id,
                'firma_di_un_altra_installazione' => $altraFirma ? true : null,
                'errore' => $registro['errore'] ?? null,
            ], fn ($v) => $v !== null));
        if ($pdf !== null) {
            $gesto->rimando('documento', (int) $pdf->id, (string) $pdf->name);
        }

        $riga = RegistroModifiche::comeAutore($this->autore($stato, $copiaDel['testo'] ?? null), fn () => RegistroModifiche::scrivi($gesto->perLaRiga()));

        return (int) $riga->id;
    }

    /** L'autore del ripristino, copiato nello stato all'avvio (P15): durante l'import la tabella degli utenti è a metà. */
    private function autore(array $stato, ?string $copiaDel): Autore
    {
        $dettaglio = 'ripristino della copia' . ($copiaDel === null ? '' : ' del ' . $copiaDel);
        $nome = $stato['created_by_nome'] ?? null;
        if (! is_string($nome) || $nome === '') {
            return Autore::programma('ripristino', $dettaglio);
        }

        return Autore::personaCopiata(isset($stato['created_by']) ? (int) $stato['created_by'] : null, $nome,
            isset($stato['created_by_ruolo']) ? (string) $stato['created_by_ruolo'] : null, 'ripristino', $dettaglio);
    }

    /** Il giorno della copia, dal manifesto: `['data' => '2026-12-10', 'testo' => '10/12/2026']`. @return array{data: string, testo: string}|array{} */
    private function copiaDel(array $stato): array
    {
        $creata = $stato['manifest']['created_at'] ?? null;
        if (! is_string($creata) || $creata === '') {
            return [];
        }
        try {
            $giorno = Carbon::parse($creata)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));
        } catch (Throwable) {
            return [];
        }

        return ['data' => $giorno->format('Y-m-d'), 'testo' => $giorno->format('d/m/Y')];
    }

    /**
     * Il PDF delle righe del file, con le impronte, fra i documenti dello studio: non pubblicato, senza destinatari (P18).
     *
     * @param array<string, mixed> $stato
     * @param array<string, mixed> $registro
     */
    private function pdf(array $stato, array $registro, ?array $soloQueste = null): int
    {
        $righe = [];
        $oltre = 0;
        $soloQueste = $soloQueste === null ? null : array_flip(array_map('intval', $soloQueste));
        foreach ($this->righeDelFile((string) $registro['file']) as [$voce]) {
            if ($soloQueste !== null && ! isset($soloQueste[(int) $voce['riga']['id']])) {
                continue;
            }
            if (count($righe) >= self::MASSIMO_NEL_PDF) {
                $oltre++;

                continue;
            }
            $righe[] = $voce['riga'];
        }

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $oggi = now()->setTimezone($fuso)->format('d/m/Y');
        $copiaDel = $this->copiaDel($stato)['testo'] ?? null;
        $mpdf = app(PdfService::class)->generate('pdf.registro_modifiche.prima_del_ripristino', [
            'gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI,
            'senza_firma' => true,
            'titolo_stampa' => ($soloQueste === null ? 'Registro delle modifiche prima del ripristino del ' : 'Righe senza la firma del programma, ripristino del ')
                . $oggi,
            'senza_firma' => $soloQueste !== null,
            'righe' => $righe,
            'oltre' => $oltre,
            'copia_del' => $copiaDel,
            'ripristinata_il' => $oggi,
            'chi' => is_string($stato['created_by_nome'] ?? null) ? $stato['created_by_nome'] : null,
            'fuso' => $fuso,
        ], ['orientation' => 'L']);
        $contenuto = $mpdf->Output('', 'S');

        $archivio = app(ArchivioDocumenti::class);
        $percorso = $archivio->salvaContenuto($contenuto, 'pdf');
        $autore = isset($stato['created_by']) ? User::query()->find((int) $stato['created_by']) : null;
        $autoreUguale = $autore !== null && $autore->name === ($stato['created_by_nome'] ?? null);

        try {
            // Il documento nasce con la sua riga del registro («creata»), firmata da chi ha avviato il ripristino, come la riga del ripristino.
            $documento = RegistroModifiche::comeAutore($this->autore($stato, $copiaDel), fn () => Documento::query()->create([
                'name' => ($soloQueste === null ? 'Registro delle modifiche prima del ripristino del ' : 'Righe del registro senza la firma del programma, ripristino del ')
                    . $oggi,
                'description' => ($soloQueste === null
                    ? 'Le righe del registro delle modifiche che c\'erano prima del ripristino della copia' . ($copiaDel === null ? '' : ' del ' . $copiaDel)
                        . ', con le impronte: la copia non aveva righe in comune con quel registro.'
                    : 'Le righe del registro delle modifiche di prima del ripristino della copia' . ($copiaDel === null ? '' : ' del ' . $copiaDel)
                        . ' che non avevano la firma del programma, con le impronte: il ripristino non le ha rimesse.')
                    . ' Impronta del file: ' . hash('sha256', $contenuto) . '.',
                'created_by' => $autoreUguale ? $autore->id : null,
                'is_published' => false,
                'is_approved' => true,
                'path' => $percorso,
                'mime_type' => 'application/pdf',
                'file_size' => strlen($contenuto),
            ]));
        } catch (Throwable $e) {
            $archivio->elimina($percorso);

            throw $e;
        }

        return (int) $documento->id;
    }

    /** Due righe sono la stessa riga: lo stesso `id` e la stessa impronta (o, se una non è sigillata, la stessa impronta dei valori). */
    private function stessaRiga(object $qui, array $delFile): bool
    {
        if ($qui->impronta !== null && $delFile['impronta'] !== null) {
            return (string) $qui->impronta === (string) $delFile['impronta'];
        }

        return (string) $qui->impronta_valori === (string) $delFile['impronta_valori'];
    }

    /** L'oscuramento del file non c'è ancora sulla riga (o c'è con valori diversi). */
    private function oscuramentoDaRiportare(object $qui, array $delFile): bool
    {
        return $qui->oscurata_il === null || (int) $qui->oscurata_da !== (int) $delFile['oscurata_da']
            || json_decode((string) $qui->campi, true) !== json_decode((string) $delFile['campi'], true)
            || $qui->riepilogo !== $delFile['riepilogo'] || $qui->perche !== $delFile['perche'];
    }

    /**
     * Le righe del file, una per una, dal byte dato: `[voce, byte dopo la riga]`. Una riga a metà (il file troncato) si salta.
     *
     * @return \Generator<int, array{0: array{riga: array<string, mixed>, condomini: array<int|string, string>, soggetti: list<array{0: string, 1: int}>}, 1: int}>
     */
    private function righeDelFile(string $file, int $dal = 0): \Generator
    {
        $flusso = fopen($file, 'r');
        if ($flusso === false) {
            throw new RuntimeException('Il file delle righe del registro delle modifiche non si legge.');
        }
        try {
            fseek($flusso, $dal);
            while (($linea = fgets($flusso)) !== false) {
                if (! str_ends_with($linea, "\n")) {
                    break;
                }
                $voce = json_decode($linea, true);
                if (! is_array($voce) || ! isset($voce['riga']['id'])) {
                    Log::warning('Ripristino: una riga del file del registro delle modifiche non si legge e si salta.');

                    continue;
                }
                yield [$voce, (int) ftell($flusso)];
            }
        } finally {
            fclose($flusso);
        }
    }
}
