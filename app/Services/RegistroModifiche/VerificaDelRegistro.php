<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Verifica l'integrità» (§3, scena 5 della bozza; P13): sigilla, poi rilegge la catena per `sequenza`.
 *
 * Per ogni riga: le sequenze sono consecutive (un buco è una riga tolta); l'impronta di prima è quella della riga prima; l'impronta dei valori torna; per
 * una riga oscurata, `oscurata_da` è una riga «oscuramento» firmata che la nomina, e i valori di adesso tornano con l'impronta che quella riga ha scritto
 * per lei (i valori dopo l'oscuramento: importi, date e altre persone restano protetti); l'impronta della riga torna; la firma di nascita torna
 * ({@see FirmaDelRegistro}). La prima riga, se non è la prima di sempre, parte dall'impronta scritta nella riga «conservazione» che ha tolto le righe prima,
 * e solo se quella riga è firmata. L'ultima deve essere quella di `registro_modifiche_catena`. Una riga che non torna non ferma la lettura: si continua
 * dalla sua impronta, così le altre righe dicono se tornano. Le righe rimaste senza sequenza dopo il sigillo: con la firma sono «da sigillare», senza sono
 * «aggiunte fuori dal programma».
 *
 * La catena e la firma rendono visibile una modifica fatta fuori dal programma; non la impediscono (la bozza lo dice): chi ha anche la chiave del server
 * può riscrivere tutto.
 *
 * Forme che legge, scritte da altri servizi:
 *  - la riga «oscuramento»: `lotto.righe` = gli id delle righe oscurate, `lotto.impronte` = id => impronta dei valori dopo l'oscuramento;
 *  - la riga «conservazione»: `lotto.ultima_sequenza_tolta`, `lotto.ultima_impronta_tolta`.
 */
final class VerificaDelRegistro
{
    public static function verifica(): EsitoDellaVerifica
    {
        try {
            SigilloDelRegistro::sigilla();
        } catch (Throwable $e) {
            report($e);
        }

        $conservazioni = self::conservazioni();
        $oscuramenti = [];
        $nonTornano = [];
        $segna = function (?int $id, ?int $sequenza, string $motivo) use (&$nonTornano): void {
            if ($id !== null && isset($nonTornano[$id])) {
                return;
            }
            $nonTornano[$id ?? 'fine'] = ['riga_id' => $id, 'sequenza' => $sequenza, 'motivo' => $motivo];
        };

        $righe = 0;
        $dal = null;
        $precedente = null;
        $sequenzaPrima = null;

        DB::table('registro_modifiche')->whereNotNull('sequenza')
            ->chunkById(SigilloDelRegistro::BLOCCO, function ($blocco) use (&$righe, &$dal, &$precedente, &$sequenzaPrima, &$oscuramenti, $conservazioni, $segna) {
                $ids = $blocco->pluck('id')->map(fn ($id) => (int) $id)->all();
                $condomini = SigilloDelRegistro::condominiDelle($ids);
                $soggetti = SigilloDelRegistro::soggettiDelle($ids);

                foreach ($blocco as $riga) {
                    $id = (int) $riga->id;
                    $sequenza = (int) $riga->sequenza;
                    $righe++;
                    $dal ??= Carbon::parse((string) $riga->avvenuta_il, 'UTC');

                    if ($sequenzaPrima === null) {
                        if ($riga->impronta_precedente !== null) {
                            if (($conservazioni[$sequenza - 1] ?? null) !== $riga->impronta_precedente) {
                                $segna($id, $sequenza, 'inizio');
                            }
                        } elseif ($sequenza !== 1) {
                            $segna($id, $sequenza, 'inizio');
                        }
                    } else {
                        if ($sequenza !== $sequenzaPrima + 1) {
                            $segna($id, $sequenza, 'mancante');
                        }
                        if ($riga->impronta_precedente !== $precedente) {
                            $segna($id, $sequenza, 'catena');
                        }
                    }

                    if ($riga->oscurata_il === null) {
                        if (ImprontaDelRegistro::deiValori($riga) !== $riga->impronta_valori) {
                            $segna($id, $sequenza, 'valori');
                        }
                    } else {
                        $impronte = self::oscuramento($riga->oscurata_da === null ? null : (int) $riga->oscurata_da, $oscuramenti);
                        if ($impronte === null || ! in_array($id, $impronte['righe'], true)) {
                            $segna($id, $sequenza, 'oscuramento');
                        } elseif (($impronte['impronte'][$id] ?? null) !== ImprontaDelRegistro::deiValori($riga)) {
                            // I valori dopo l'oscuramento sono cambiati: l'impronta scritta dall'oscuramento non torna più.
                            $segna($id, $sequenza, 'valori');
                        }
                    }

                    $testa = ImprontaDelRegistro::intestazione($riga, $sequenza, $condomini[$id] ?? [], $soggetti[$id] ?? []);
                    if (ImprontaDelRegistro::dellaRiga($riga->impronta_precedente, $testa, (string) $riga->impronta_valori) !== $riga->impronta) {
                        $segna($id, $sequenza, 'impronta');
                    }
                    if (! FirmaDelRegistro::torna($riga, $condomini[$id] ?? [], $soggetti[$id] ?? [])) {
                        // Le impronte tornano ma la firma no: la catena è stata ricalcolata da chi non ha la chiave del server.
                        $segna($id, $sequenza, 'firma');
                    }

                    $precedente = $riga->impronta;
                    $sequenzaPrima = $sequenza;
                }
            }, 'sequenza');

        $catena = DB::table('registro_modifiche_catena')->where('id', 1)->first();
        if ($catena !== null && ((int) $catena->ultima_sequenza !== (int) ($sequenzaPrima ?? 0) || $catena->ultima_impronta !== $precedente)) {
            $segna(null, (int) $catena->ultima_sequenza, 'fine');
        }

        // Le righe che il sigillo ha lasciato fuori: senza la firma del programma sono aggiunte fuori dal programma; con la firma, solo da sigillare (una
        // modifica confermata mentre la verifica leggeva, o un sigillo che non è andato).
        $daSigillare = 0;
        DB::table('registro_modifiche')->whereNull('sequenza')->chunkById(SigilloDelRegistro::BLOCCO, function ($blocco) use (&$daSigillare, $segna) {
            $ids = $blocco->pluck('id')->map(fn ($id) => (int) $id)->all();
            $condomini = SigilloDelRegistro::condominiDelle($ids);
            $soggetti = SigilloDelRegistro::soggettiDelle($ids);
            foreach ($blocco as $riga) {
                if (FirmaDelRegistro::torna($riga, $condomini[(int) $riga->id] ?? [], $soggetti[(int) $riga->id] ?? [])) {
                    $daSigillare++;
                } else {
                    $segna((int) $riga->id, null, 'aggiunta');
                }
            }
        });

        $esito = new EsitoDellaVerifica(
            integro: $nonTornano === [],
            righe: $righe,
            dal: $dal,
            nonTornano: array_values($nonTornano),
            verificataIl: now()->utc()->startOfSecond(),
            daSigillare: $daSigillare,
        );
        self::ricorda($esito);

        return $esito;
    }

    /** L'ultima verifica, per la card «Integrità» (P20: nella riga della catena, non in un'impostazione). @return array<string, mixed>|null */
    public static function ultima(): ?array
    {
        $catena = DB::table('registro_modifiche_catena')->where('id', 1)->first();
        if ($catena === null || $catena->verificata_il === null) {
            return null;
        }

        return [
            'verificata_il' => Carbon::parse((string) $catena->verificata_il, 'UTC')->toIso8601String(),
            'integro' => (bool) $catena->verifica_integro,
            'righe' => (int) $catena->verifica_righe,
            'dal' => $catena->verifica_dal === null ? null : Carbon::parse((string) $catena->verifica_dal, 'UTC')->toIso8601String(),
            'riga_id' => $catena->verifica_riga_id === null ? null : (int) $catena->verifica_riga_id,
            'righe_che_non_tornano' => (int) $catena->verifica_righe_che_non_tornano,
        ];
    }

    private static function ricorda(EsitoDellaVerifica $esito): void
    {
        DB::table('registro_modifiche_catena')->where('id', 1)->update([
            'verificata_il' => $esito->verificataIl->format('Y-m-d H:i:s'),
            'verifica_integro' => $esito->integro,
            'verifica_righe' => $esito->righe,
            'verifica_dal' => $esito->dal?->format('Y-m-d H:i:s'),
            'verifica_riga_id' => $esito->nonTornano[0]['riga_id'] ?? null,
            'verifica_righe_che_non_tornano' => count($esito->nonTornano),
        ]);
    }

    /**
     * Le righe «conservazione» **firmate**: l'ultima sequenza tolta => l'ultima impronta tolta. Una riga «conservazione» scritta con il database non conta:
     * altrimenti una cancellazione di righe di ieri passerebbe per conservazione.
     *
     * @return array<int, string>
     */
    private static function conservazioni(): array
    {
        $righe = DB::table('registro_modifiche')->where('azione', 'conservazione')->whereNotNull('lotto')->get();
        $ids = $righe->pluck('id')->map(fn ($id) => (int) $id)->all();
        $condomini = SigilloDelRegistro::condominiDelle($ids);
        $soggetti = SigilloDelRegistro::soggettiDelle($ids);
        $per = [];
        foreach ($righe as $r) {
            if (! FirmaDelRegistro::torna($r, $condomini[(int) $r->id] ?? [], $soggetti[(int) $r->id] ?? [])) {
                continue;
            }
            $lotto = json_decode((string) $r->lotto, true);
            if (isset($lotto['ultima_sequenza_tolta'], $lotto['ultima_impronta_tolta'])) {
                $per[(int) $lotto['ultima_sequenza_tolta']] = (string) $lotto['ultima_impronta_tolta'];
            }
        }

        return $per;
    }

    /**
     * La riga «oscuramento» che ha oscurato una riga, se c'è ed è firmata: le righe che nomina e le impronte dei loro valori dopo l'oscuramento.
     *
     * @param array<int, array{righe: list<int>, impronte: array<int, string>}|null> $oscuramenti la cache delle righe «oscuramento» lette
     * @return array{righe: list<int>, impronte: array<int, string>}|null
     */
    private static function oscuramento(?int $oscurataDa, array &$oscuramenti): ?array
    {
        if ($oscurataDa === null) {
            return null;
        }
        if (! array_key_exists($oscurataDa, $oscuramenti)) {
            $riga = DB::table('registro_modifiche')->where('id', $oscurataDa)->where('azione', 'oscuramento')->first();
            $lotto = $riga === null ? null : json_decode((string) $riga->lotto, true);
            $firmata = $riga !== null && FirmaDelRegistro::torna($riga, SigilloDelRegistro::condominiDelle([$oscurataDa])[$oscurataDa] ?? [],
                SigilloDelRegistro::soggettiDelle([$oscurataDa])[$oscurataDa] ?? []);
            $oscuramenti[$oscurataDa] = $firmata && is_array($lotto['righe'] ?? null) ? [
                'righe' => array_map('intval', $lotto['righe']),
                'impronte' => collect(is_array($lotto['impronte'] ?? null) ? $lotto['impronte'] : [])
                    ->mapWithKeys(fn ($impronta, $id) => [(int) $id => (string) $impronta])->all(),
            ] : null;
        }

        return $oscuramenti[$oscurataDa];
    }
}
