<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\ImpostazioniCondominio;
use App\Models\User;
use App\Settings\AssembleeSettings;
use Illuminate\Validation\ValidationException;

/**
 * Le impostazioni della sala, in un posto solo.
 *
 * - **Dello studio** (`AssembleeSettings`, solo l'amministratore): `delega_oltre_il_quinto` (`rifiuta` di serie, o `decide_il_presidente`) e
 *   `voto_discorde_comproprietari` (`astenuti` di serie, o `decide_il_presidente`). Il cambio è una riga del registro, come le altre impostazioni. Valgono
 *   da quando si salvano; una sala già aperta tiene quelle della sua apertura, ferme nella fotografia della seduta ({@see dellaFotografia()}).
 * - **Del condominio** (`impostazioni_condominio.assemblee_teste`): le teste nel caso misto, `due_teste` di serie o `una_testa`. Non si cambia mentre
 *   un'assemblea del condominio è in corso (la fotografia della seduta la legge all'apertura); il cambio è una riga del registro della scheda.
 */
final class ImpostazioniDellaSala
{
    public const QUINTO = [AssembleeSettings::RIFIUTA, AssembleeSettings::DECIDE_IL_PRESIDENTE];

    public const DISCORDE = [AssembleeSettings::ASTENUTI, AssembleeSettings::DECIDE_IL_PRESIDENTE];

    public const TESTE = ImpostazioniCondominio::TESTE;

    public const FRASE_FERMA = 'Un\'assemblea del condominio è in corso: le teste si cambiano dopo la chiusura.';

    /**
     * Le tre scelte che la sala usa, per la sua pagina. Con la fotografia di una seduta aperta (o tenuta) sono quelle della sua apertura, ferme come le teste
     * (`ferme_all_apertura`); prima, quelle di adesso.
     *
     * @param ?array<string, mixed> $fotografia
     * @return array<string, mixed>
     */
    public static function perLaSala(int $condominioId, ?array $fotografia = null): array
    {
        $ferme = $fotografia !== null && ! ($fotografia['provvisoria'] ?? true) && isset($fotografia['impostazioni']);
        $scelte = self::dellaFotografia($ferme ? $fotografia : null);

        return $scelte + ['teste_caso_misto' => $ferme ? (string) $fotografia['teste_caso_misto']
            : (string) (ImpostazioniCondominio::delCondominio($condominioId)->assemblee_teste ?: ImpostazioniCondominio::DUE_TESTE), 'ferme_all_apertura' => $ferme];
    }

    /**
     * Le scelte dello studio sulla sala: quelle ferme nella fotografia dell'apertura, se ci sono, altrimenti quelle di adesso. Una sala già aperta tiene le
     * scelte della sua apertura: cambiarle a metà assemblea non cambia le regole di quella sera.
     *
     * @param ?array<string, mixed> $fotografia
     * @return array{delega_oltre_il_quinto: string, voto_discorde_comproprietari: string}
     */
    public static function dellaFotografia(?array $fotografia): array
    {
        $ferme = (array) ($fotografia['impostazioni'] ?? []);
        $studio = app(AssembleeSettings::class);

        return ['delega_oltre_il_quinto' => (string) ($ferme['delega_oltre_il_quinto'] ?? $studio->delega_oltre_il_quinto),
            'voto_discorde_comproprietari' => (string) ($ferme['voto_discorde_comproprietari'] ?? $studio->voto_discorde_comproprietari)];
    }

    /** Le scelte dello studio, per la card delle impostazioni. @return array<string, mixed> */
    public static function delloStudio(): array
    {
        $studio = app(AssembleeSettings::class);

        return ['delega_oltre_il_quinto' => $studio->delega_oltre_il_quinto, 'voto_discorde_comproprietari' => $studio->voto_discorde_comproprietari,
            'giorni_di_posta' => $studio->giorni_di_posta];
    }

    /** @param array{delega_oltre_il_quinto: string, voto_discorde_comproprietari: string, giorni_di_posta?: int} $dati */
    public static function salvaDelloStudio(array $dati): void
    {
        $studio = app(AssembleeSettings::class);
        $studio->delega_oltre_il_quinto = $dati['delega_oltre_il_quinto'];
        $studio->voto_discorde_comproprietari = $dati['voto_discorde_comproprietari'];
        if (isset($dati['giorni_di_posta'])) {
            $studio->giorni_di_posta = (int) $dati['giorni_di_posta'];
        }
        $studio->save();
    }

    public static function salvaLeTeste(Condominio $condominio, string $teste, User $utente): void
    {
        if (! in_array($teste, self::TESTE, true)) {
            throw ValidationException::withMessages(['assemblee_teste' => 'Scegli come contano le teste.']);
        }
        if (Assemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoAssemblea::IN_CORSO->value)->exists()) {
            throw ValidationException::withMessages(['assemblee_teste' => self::FRASE_FERMA]);
        }
        ImpostazioniCondominio::updateOrCreate(['condominio_id' => $condominio->id], ['assemblee_teste' => $teste, 'aggiornate_da' => $utente->id]);
    }
}
