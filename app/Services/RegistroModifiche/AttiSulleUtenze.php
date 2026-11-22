<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Invito;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Gli atti sulle utenze (Coda ㉗, decisione 518 (4), §5 del progetto): righe `atto_utenza` dell'area «utenti», con i nomi di chi fa e di chi subisce
 * copiati, perché l'eliminazione è proprio l'atto da raccontare. `User` non ha il tratto (password, gettoni, due fattori): solo gli atti di governo, mai
 * gli accessi, e solo ciò che è cambiato. I condomini della riga sono quelli della persona dell'utenza (la bozza: «Utente di Giorgio Bianchi · Accesso:
 * nessuno → condòmino, invito mandato» nel registro del condominio); la persona è fra i soggetti (P9).
 */
final class AttiSulleUtenze
{
    /** I ruoli e i permessi di un'utenza adesso. @return array{ruoli: list<string>, permessi: list<string>} */
    public static function fotografia(User $utente): array
    {
        $utente->unsetRelation('roles')->unsetRelation('permissions');
        $ruoli = $utente->roles()->pluck('name')->map(fn ($n) => (string) $n)->sort()->values()->all();
        $permessi = $utente->permissions()->pluck('name')->map(fn ($n) => (string) $n)->sort()->values()->all();

        return ['ruoli' => $ruoli, 'permessi' => $permessi];
    }

    /** I permessi di un ruolo adesso. @return list<string> */
    public static function permessiDelRuolo(Role $ruolo): array
    {
        $ruolo->unsetRelation('permissions');

        return $ruolo->permissions()->pluck('name')->map(fn ($n) => (string) $n)->sort()->values()->all();
    }

    public static function creato(User $utente): void
    {
        $dopo = self::fotografia($utente);
        self::scrivi($utente, 'Utente creato' . ($dopo['ruoli'] !== [] ? ': ' . implode(', ', $dopo['ruoli']) : ''),
            self::campiDiAccesso(['ruoli' => [], 'permessi' => []], $dopo));
    }

    /** Ruoli o permessi cambiati: niente riga se non è cambiato niente (Coda ㉗ (1)). @param array{ruoli: list<string>, permessi: list<string>} $prima */
    public static function accessoCambiato(User $utente, array $prima): void
    {
        $campi = self::campiDiAccesso($prima, self::fotografia($utente));
        if ($campi !== []) {
            self::scrivi($utente, 'Ruolo o permessi cambiati', $campi);
        }
    }

    public static function sospeso(User $utente): void
    {
        self::scrivi($utente, 'Utente sospeso');
    }

    public static function riattivato(User $utente): void
    {
        self::scrivi($utente, 'Utente riattivato');
    }

    public static function verificaDellEmail(User $utente, bool $verificata): void
    {
        self::scrivi($utente, $verificata ? 'Email verificata a mano' : 'Verifica dell\'email tolta a mano');
    }

    public static function invitoRimandato(User $utente): void
    {
        self::scrivi($utente, 'Invito rimandato');
    }

    public static function registrato(User $utente): void
    {
        self::scrivi($utente, 'Utente registrato dalla pagina di registrazione', self::campiDiAccesso(['ruoli' => [], 'permessi' => []],
            self::fotografia($utente)));
    }

    /** Prima del `delete`, nella sua transazione: dopo, il nome e la persona non ci sono più. */
    public static function eliminato(User $utente): void
    {
        self::scrivi($utente, 'Utente eliminato', self::campiDiAccesso(self::fotografia($utente), ['ruoli' => [], 'permessi' => []]));
    }

    /** L'invito a un indirizzo: non c'è ancora un'utenza. */
    public static function invito(Invito $invito, string $riepilogo): void
    {
        $codici = (array) ($invito->building_codes ?? []);
        $condomini = Condominio::query()->whereIn('codice_identificativo', $codici)->orWhereIn('id', array_filter($codici, 'is_numeric'))->get();
        RegistroModifiche::scrivi(Gesto::su('utenti', 'invito', (int) $invito->id, 'Invito a ' . $invito->email)->azione('atto_utenza')
            ->riepilogo($riepilogo)->neiCondomini($condomini)->perLaRiga());
    }

    /** Un ruolo come definizione: «Ruolo «collaboratore»: permessi cambiati», con i permessi tolti e aggiunti. @param list<string> $prima */
    public static function ruolo(Role $ruolo, string $azione, array $prima, ?array $dopo = null): void
    {
        $dopo ??= self::permessiDelRuolo($ruolo);
        $campi = self::campiDeiPermessi($prima, $dopo);
        if ($azione === 'modificata' && $campi === []) {
            return;
        }
        $parole = ['creata' => 'creato', 'modificata' => 'permessi cambiati', 'eliminata' => 'eliminato'][$azione] ?? $azione;
        RegistroModifiche::scrivi(Gesto::su('utenti', 'ruolo', (int) $ruolo->id, 'Ruolo «' . $ruolo->name . '»')->azione($azione)
            ->riepilogo('Ruolo «' . $ruolo->name . '»: ' . $parole)->campi($campi)->perLaRiga());
    }

    /**
     * @param array{ruoli: list<string>, permessi: list<string>} $prima
     * @param array{ruoli: list<string>, permessi: list<string>} $dopo
     * @return list<array<string, mixed>>
     */
    private static function campiDiAccesso(array $prima, array $dopo): array
    {
        $campi = [];
        if ($prima['ruoli'] !== $dopo['ruoli']) {
            $campi[] = Campo::nuovo('ruoli', 'Ruolo', $prima['ruoli'] === [] ? null : implode(', ', $prima['ruoli']),
                $dopo['ruoli'] === [] ? null : implode(', ', $dopo['ruoli']), $prima['ruoli'], $dopo['ruoli']);
        }

        return [...$campi, ...self::campiDeiPermessi($prima['permessi'], $dopo['permessi'])];
    }

    /** @param list<string> $prima @param list<string> $dopo @return list<array<string, mixed>> */
    private static function campiDeiPermessi(array $prima, array $dopo): array
    {
        $tolti = array_values(array_diff($prima, $dopo));
        $aggiunti = array_values(array_diff($dopo, $prima));
        $campi = [];
        if ($tolti !== []) {
            $campi[] = Campo::nuovo('permessi_tolti', 'Permessi tolti', implode(', ', $tolti), null, $tolti, null);
        }
        if ($aggiunti !== []) {
            $campi[] = Campo::nuovo('permessi_aggiunti', 'Permessi aggiunti', null, implode(', ', $aggiunti), null, $aggiunti);
        }

        return $campi;
    }

    /** @param list<array<string, mixed>> $campi */
    private static function scrivi(User $utente, string $riepilogo, array $campi = []): void
    {
        $persone = Anagrafica::query()->where('user_id', $utente->id)->get();
        $gesto = Gesto::su('utenti', 'utente', (int) $utente->id, 'Utente di ' . $utente->name)->azione('atto_utenza')->riepilogo($riepilogo)
            ->campi($campi)->soggetto('utente', (int) $utente->id)->soggettiDi('anagrafica', $persone->pluck('id'));
        foreach ($persone as $persona) {
            $gesto->neiCondomini($persona->condomini()->pluck('condomini.id'));
        }
        RegistroModifiche::scrivi($gesto->perLaRiga());
    }
}
