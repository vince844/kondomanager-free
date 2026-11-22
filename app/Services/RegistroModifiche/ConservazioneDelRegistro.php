<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\RegistroModifica;
use App\Settings\RegistroModificheSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La conservazione del registro delle modifiche (§11 del progetto, P13, decisioni 518 (1), 549 e 550): per quanto tempo si tengono le righe (di serie 10
 * anni, come le scritture e i documenti giustificativi del condominio, art. 1130-bis c.c.; scelte 10, 15, 20) e il lavoro di ogni notte che toglie quelle più
 * vecchie. Il registro non si spegne: un registro spento manca proprio il giorno in cui serve.
 *
 * **Un prefisso per `sequenza`, mai un buco in mezzo** (P13): si tolgono le righe dalla prima della catena fino a quella prima della prima riga che ha il
 * giorno del gesto dentro la durata. La catena resta intera: la prima riga rimasta porta l'impronta dell'ultima tolta, che la riga «conservazione» scrive
 * (`lotto.ultima_sequenza_tolta`, `lotto.ultima_impronta_tolta`), e da lì riparte «Verifica l'integrità». `registro_modifiche_catena` non si tocca.
 *
 * **I condomini sospesi** (decisione 550): il prefisso si ferma anche alla prima riga di un condominio con la cancellazione sospesa
 * ({@see SospensioneDellaCancellazione}). Per non fare buchi, le righe che vengono dopo restano anche se sono di un altro condominio: la riga «conservazione»
 * lo dice, e la volta dopo che la sospensione è tolta si tolgono.
 *
 * È uno dei servizi che scrivono le tabelle del registro fuori dalla nascita di una riga (con `DB::table`: il modello rifiuta la cancellazione).
 */
final class ConservazioneDelRegistro
{
    /** Le durate fra cui si sceglie, in anni. */
    public const DURATE = [10, 15, 20];

    public const DI_SERIE = 10;

    /** Quante righe al più per volta: un cambio da 20 a 10 anni non toglie tutto in una transazione sola; il resto la notte dopo. */
    public const MASSIMO_PER_VOLTA = 50000;

    /** Il nome del comando, come lo dice la riga: «Il programma, lavoro pianificato: conservazione del registro delle modifiche». */
    public const NOME_DEL_LAVORO = 'conservazione del registro delle modifiche';

    public static function anni(): int
    {
        try {
            $anni = (int) app(RegistroModificheSettings::class)->anni_di_conservazione;
        } catch (Throwable) {
            return self::DI_SERIE;
        }

        return in_array($anni, self::DURATE, true) ? $anni : self::DI_SERIE;
    }

    /** «10 anni». */
    public static function testoDegliAnni(int $anni): string
    {
        return $anni . ' anni';
    }

    /** «le righe del 2026 si tolgono da sole nel 2036»: l'anno di oggi, nel fuso di Roma. */
    public static function frase(int $anni, ?Carbon $oggi = null): string
    {
        $anno = (int) ($oggi ?? now())->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y');

        return 'le righe del ' . $anno . ' si tolgono da sole nel ' . ($anno + $anni);
    }

    /** Lo stato per il componente della durata. @return array<string, mixed> */
    public static function stato(bool $puoCambiare): array
    {
        $anni = self::anni();

        return ['anni' => $anni, 'scelte' => self::DURATE, 'adesso' => self::testoDegliAnni($anni), 'frase' => self::frase($anni),
            'puo_cambiare' => $puoCambiare,
            // Decisione 558: una sospensione ferma la cancellazione di tutto lo studio (il prefisso non salta le righe), e il componente lo dice.
            'sospensioni' => SospensioneDellaCancellazione::attive()];
    }

    /** Cambia la durata (solo l'amministratore: lo controlla la rotta). Il salvataggio scrive la sua riga del registro (gesto 8). */
    public static function cambia(int $anni): void
    {
        if (! in_array($anni, self::DURATE, true)) {
            throw new \InvalidArgumentException("Durata del registro non ammessa: {$anni} anni.");
        }
        $impostazioni = app(RegistroModificheSettings::class);
        $impostazioni->anni_di_conservazione = $anni;
        $impostazioni->save();
    }

    /**
     * Toglie le righe più vecchie della durata e scrive la riga «conservazione». Restituisce la riga, o `null` se non c'era niente da togliere (nessuna
     * riga: un lavoro di ogni notte che non toglie niente non riempie il registro).
     */
    public static function conserva(?Autore $autore = null): ?RegistroModifica
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        // I file delle righe rimasti da un ripristino, più vecchi di trenta giorni: hanno i dati delle persone, fuori dalla durata e dall'oscuramento.
        $file = app(\App\Services\Restore\RegistroNelRipristino::class)->togliIFileRimasti();

        $anni = self::anni();
        $limite = now()->utc()->startOfSecond()->subYears($anni)->format('Y-m-d H:i:s');
        $sospesi = SospensioneDellaCancellazione::condominiSospesi();

        $ids = [];
        $ultima = null;
        $fermataDa = [];
        DB::table('registro_modifiche')->whereNotNull('sequenza')->select(['id', 'sequenza', 'avvenuta_il', 'impronta'])
            ->chunkById(1000, function ($blocco) use ($limite, $sospesi, &$ids, &$ultima, &$fermataDa) {
                $condomini = $sospesi === [] ? [] : SigilloDelRegistro::condominiDelle($blocco->pluck('id')->map(fn ($id) => (int) $id)->all());
                foreach ($blocco as $riga) {
                    if (substr((string) $riga->avvenuta_il, 0, 19) >= $limite || count($ids) >= self::MASSIMO_PER_VOLTA) {
                        return false;
                    }
                    $suoi = array_intersect_key($sospesi, $condomini[(int) $riga->id] ?? []);
                    if ($suoi !== []) {
                        $fermataDa = $suoi;

                        return false;
                    }
                    $ids[] = (int) $riga->id;
                    $ultima = $riga;
                }

                return true;
            }, 'sequenza');

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $fraseDeiFile = self::fraseDeiFile($file, $fuso);
        $autore ??= self::autoreDelLavoro();
        if ($ids === []) {
            return $file === [] ? null : RegistroModifiche::scrivi([
                'area' => 'impostazioni', 'oggetto_tipo' => 'registro_modifiche', 'oggetto_id' => null, 'oggetto_nome' => 'Registro delle modifiche',
                'scheda_tipo' => 'impostazioni_registro_modifiche', 'azione' => 'conservazione', 'riepilogo' => $fraseDeiFile,
                'lotto' => ['file_tolti' => array_column($file, 'nome')], 'autore' => $autore,
            ]);
        }

        $finoAl = Carbon::parse((string) $ultima->avvenuta_il, 'UTC')->setTimezone($fuso)->format('d/m/Y');
        $riepilogo = (count($ids) === 1 ? 'Tolta 1 riga, quella del ' : 'Tolte ' . number_format(count($ids), 0, ',', '.') . ' righe, fino a quella del ')
            . $finoAl . ': più vecchie di ' . self::testoDegliAnni($anni) . '.';
        if ($fermataDa !== []) {
            $nomi = implode(', ', $fermataDa);
            $riepilogo .= ' Si è fermata prima: ' . $nomi . (count($fermataDa) === 1 ? ' ha' : ' hanno')
                . ' la cancellazione sospesa, e le righe dopo restano finché la sospensione non si toglie.';
        }

        if ($fraseDeiFile !== null) {
            $riepilogo .= ' ' . $fraseDeiFile;
        }

        return DB::transaction(function () use ($ids, $ultima, $riepilogo, $anni, $fermataDa, $autore, $file) {
            foreach (array_chunk($ids, 1000) as $pezzo) {
                DB::table('registro_modifiche_condomini')->whereIn('riga_id', $pezzo)->delete();
                DB::table('registro_modifiche_soggetti')->whereIn('riga_id', $pezzo)->delete();
                DB::table('registro_modifiche')->whereIn('id', $pezzo)->whereNotNull('sequenza')->delete();
            }

            return RegistroModifiche::scrivi([
                'area' => 'impostazioni',
                'oggetto_tipo' => 'registro_modifiche',
                'oggetto_id' => null,
                'oggetto_nome' => 'Registro delle modifiche',
                'scheda_tipo' => 'impostazioni_registro_modifiche',
                'azione' => 'conservazione',
                'riepilogo' => $riepilogo,
                'lotto' => array_filter([
                    'righe_tolte' => count($ids),
                    'fino_al' => substr((string) $ultima->avvenuta_il, 0, 19),
                    'ultima_sequenza_tolta' => (int) $ultima->sequenza,
                    'ultima_impronta_tolta' => (string) $ultima->impronta,
                    'anni' => $anni,
                    'fermata_da' => $fermataDa === [] ? null : $fermataDa,
                    'file_tolti' => $file === [] ? null : array_column($file, 'nome'),
                ], fn ($v) => $v !== null),
                'autore' => $autore,
            ]);
        });
    }

    /**
     * «Tolto il file delle righe del registro rimasto dal ripristino del 10/12/2026: più vecchio di 30 giorni.»
     *
     * @param list<array{nome: string, del: Carbon}> $file
     */
    private static function fraseDeiFile(array $file, string $fuso): ?string
    {
        if ($file === []) {
            return null;
        }
        $giorni = array_values(array_unique(array_map(fn ($f) => $f['del']->copy()->setTimezone($fuso)->format('d/m/Y'), $file)));
        $quanti = \App\Services\Restore\RegistroNelRipristino::GIORNI_DEL_FILE_RIMASTO;
        if (count($file) === 1) {
            return 'Tolto il file delle righe del registro rimasto dal ripristino del ' . $giorni[0] . ': più vecchio di ' . $quanti . ' giorni.';
        }

        return 'Tolti ' . count($file) . ' file delle righe del registro rimasti dai ripristini del ' . implode(', del ', $giorni) . ': più vecchi di '
            . $quanti . ' giorni.';
    }

    /** «Il programma», con l'origine del processo: un lavoro pianificato, un comando lanciato da terminale. Mai la persona in sessione. */
    private static function autoreDelLavoro(): Autore
    {
        $autore = ContestoDelRegistro::autore();

        return $autore->ePersona() ? Autore::programma('comando', self::NOME_DEL_LAVORO . ', avviata da ' . $autore->nome) : $autore;
    }
}
