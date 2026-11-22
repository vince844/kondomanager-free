<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Condominio;
use App\Models\RegistroModifica;
use Illuminate\Support\Facades\DB;

/**
 * «Sospendi la cancellazione» per condominio (decisione 550): con una causa aperta («causa Rossi, tribunale di …») le righe del registro di quel condominio
 * restano oltre la durata finché l'amministratore non toglie la sospensione. È l'eccezione della lettera e) dell'art. 17 par. 3 del regolamento (UE)
 * 2016/679, la difesa in giudizio.
 *
 * **Lo stato sta nel registro stesso**, nelle sue righe: la sospensione e la sua fine sono righe (`oggetto_tipo` = `sospensione_cancellazione`, `oggetto_id`
 * = il condominio, `lotto.sospesa`), e vale l'ultima. Così:
 *  - la conservazione non può togliere la riga che sospende (è una riga del condominio sospeso, e il prefisso si ferma alla prima di quelle);
 *  - un ripristino di una copia non toglie una sospensione decisa dopo la copia: le righe venute dopo restano (decisione 546) e qui contano anche quelle
 *    annullate dal ripristino (una causa non finisce perché si è ripristinata una copia).
 *
 * Solo l'amministratore, con il motivo obbligatorio, anche per togliere la sospensione.
 */
final class SospensioneDellaCancellazione
{
    public const TIPO = 'sospensione_cancellazione';

    /**
     * Lo stato del condominio: `null` se la cancellazione non è sospesa, altrimenti il giorno, il motivo e chi l'ha sospesa.
     *
     * @return array{dal: string, motivo: ?string, da: string, riga_id: int}|null
     */
    public static function stato(Condominio|int $condominio): ?array
    {
        $id = $condominio instanceof Condominio ? (int) $condominio->getKey() : $condominio;
        $ultima = RegistroModifica::query()->where('oggetto_tipo', self::TIPO)->where('oggetto_id', $id)->orderByDesc('id')->first();
        if ($ultima === null || ! ($ultima->lotto['sospesa'] ?? false)) {
            return null;
        }

        return [
            'dal' => $ultima->avvenuta_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y'),
            'motivo' => $ultima->perche,
            'da' => $ultima->autore_nome,
            'riga_id' => $ultima->id,
        ];
    }

    /** I condomini con la cancellazione sospesa adesso: `[id => nome]`. @return array<int, string> */
    public static function condominiSospesi(): array
    {
        $ultime = DB::table('registro_modifiche')->where('oggetto_tipo', self::TIPO)->whereNotNull('oggetto_id')
            ->orderBy('id')->get(['id', 'oggetto_id', 'oggetto_nome', 'lotto']);
        $stato = [];
        foreach ($ultime as $riga) {
            $lotto = json_decode((string) $riga->lotto, true) ?: [];
            $stato[(int) $riga->oggetto_id] = ($lotto['sospesa'] ?? false) ? (string) ($lotto['condominio'] ?? $riga->oggetto_nome) : null;
        }

        return array_filter($stato, fn ($nome) => $nome !== null);
    }

    /**
     * Le sospensioni attive di tutto lo studio, dalla più vecchia: con il nome copiato nella riga (il condominio può non esserci più), il giorno, il
     * motivo, chi, e se il condominio è stato eliminato (allora si toglie dal registro dello studio, scelta del 19/11/2026: la sua pagina non c'è più).
     *
     * @return list<array{condominio_id: int, nome: string, dal: string, motivo: ?string, da: string, eliminato: bool}>
     */
    public static function attive(): array
    {
        $ultime = [];
        foreach (RegistroModifica::query()->where('oggetto_tipo', self::TIPO)->whereNotNull('oggetto_id')->orderBy('id')->get() as $riga) {
            $ultime[(int) $riga->oggetto_id] = $riga;
        }
        $ultime = array_filter($ultime, fn (RegistroModifica $r) => (bool) ($r->lotto['sospesa'] ?? false));
        $esistono = Condominio::query()->whereIn('id', array_keys($ultime))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        uasort($ultime, fn ($a, $b) => $a->id <=> $b->id);

        return array_values(array_map(fn (RegistroModifica $r) => [
            'condominio_id' => (int) $r->oggetto_id,
            'nome' => (string) ($r->lotto['condominio'] ?? $r->oggetto_nome),
            'dal' => $r->avvenuta_il->copy()->setTimezone($fuso)->format('d/m/Y'),
            'motivo' => $r->perche,
            'da' => $r->autore_nome,
            'eliminato' => ! in_array((int) $r->oggetto_id, $esistono, true),
        ], $ultime));
    }

    /**
     * Toglie la sospensione di un condominio per numero: anche di un condominio eliminato, con il nome copiato dalla riga che l'ha sospesa.
     *
     * @throws \Illuminate\Validation\ValidationException se la cancellazione di quel condominio non è sospesa
     */
    public static function togliPerNumero(int $condominioId, string $motivo): RegistroModifica
    {
        $condominio = Condominio::query()->find($condominioId);
        if ($condominio !== null) {
            if (self::stato($condominio) === null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['motivo' => 'La cancellazione di questo condominio non è sospesa.']);
            }

            return self::togli($condominio, $motivo);
        }
        $sospensione = collect(self::attive())->firstWhere('condominio_id', $condominioId);
        if ($sospensione === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['motivo' => 'La cancellazione di questo condominio non è sospesa.']);
        }

        return DB::transaction(fn () => RegistroModifiche::scrivi(Gesto::su('condominio', self::TIPO, $condominioId,
            'Cancellazione automatica del registro, condominio ' . $sospensione['nome'])
            ->scheda('condominio', $condominioId)
            ->nelCondominio($condominioId, $sospensione['nome'])
            ->riepilogo('Sospensione della cancellazione automatica tolta.')
            ->perche(trim($motivo))
            ->lotto(['sospesa' => false, 'condominio' => $sospensione['nome'], 'condominio_eliminato' => true])
            ->perLaRiga()));
    }

    /** Sospende la cancellazione: una riga del registro del condominio, con il motivo. */
    public static function sospendi(Condominio $condominio, string $motivo): RegistroModifica
    {
        return DB::transaction(function () use ($condominio, $motivo) {
            return self::riga($condominio, true, $motivo, 'Cancellazione automatica del registro sospesa per questo condominio.');
        });
    }

    /** Toglie la sospensione: un'altra riga, con il motivo. */
    public static function togli(Condominio $condominio, string $motivo): RegistroModifica
    {
        return DB::transaction(function () use ($condominio, $motivo) {
            return self::riga($condominio, false, $motivo, 'Sospensione della cancellazione automatica tolta.');
        });
    }

    private static function riga(Condominio $condominio, bool $sospesa, string $motivo, string $riepilogo): RegistroModifica
    {
        return RegistroModifiche::scrivi(Gesto::su('condominio', self::TIPO, (int) $condominio->getKey(),
            'Cancellazione automatica del registro, condominio ' . $condominio->nome)
            ->scheda('condominio', (int) $condominio->getKey())
            ->nelCondominio($condominio)
            ->riepilogo($riepilogo)
            ->perche(trim($motivo))
            ->lotto(['sospesa' => $sospesa, 'condominio' => (string) $condominio->nome])
            ->perLaRiga());
    }

    /** Lo stato per i componenti: sospesa o no, dal, motivo, chi. @return array<string, mixed> */
    public static function perIlComponente(Condominio $condominio, bool $puoCambiare): array
    {
        $stato = self::stato($condominio);

        return ['sospesa' => $stato !== null, 'dal' => $stato['dal'] ?? null, 'motivo' => $stato['motivo'] ?? null, 'da' => $stato['da'] ?? null,
            'puo_cambiare' => $puoCambiare];
    }
}
