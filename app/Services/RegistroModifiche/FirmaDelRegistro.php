<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use Illuminate\Encryption\MissingAppKeyException;

/**
 * La firma di nascita di una riga del registro: un HMAC-SHA256 dell'intestazione (senza la sequenza, che dà il sigillo), dei condomini, dei soggetti e
 * dell'impronta dei valori, con una chiave che non sta nel database.
 *
 * Perché: l'algoritmo delle impronte è nel codice pubblico, e con la sola sha256 chi ha il database può aggiungere una riga senza sequenza con l'impronta
 * giusta (il sigillo la metterebbe in catena come se l'avesse scritta il programma), far passare una cancellazione per conservazione, o cambiare un valore
 * e ricalcolare la catena. Con la firma il programma riconosce le righe nate da {@see RegistroModifiche::scrivi()}: il sigillo non mette in catena una riga
 * con la firma sbagliata, la verifica la segna «aggiunta fuori dal programma», e una riga «conservazione» vale solo se firmata.
 *
 * **La chiave** è un HMAC di una costante del registro con `APP_KEY`, mai `APP_KEY` stessa: il programma ne dipende già (la password della posta e la
 * doppia autenticazione sono cifrate con lei, e il ripristino sa adottare quella della copia). Le chiavi di prima (`config('app.previous_keys')`, cioè
 * `APP_PREVIOUS_KEYS`) valgono per le righe firmate con loro: si cambia chiave senza perdere le righe di prima.
 *
 * **Che cosa non fa**: chi ha anche la chiave del server (il file `.env`) può riscrivere tutto, firme comprese. La catena e la firma rendono visibile una
 * modifica fatta fuori dal programma; non la impediscono.
 */
final class FirmaDelRegistro
{
    /** La costante da cui nasce la chiave del registro: cambiarla rende «aggiunte fuori dal programma» tutte le righe di prima. */
    private const COSTANTE = 'KondoManager · registro delle modifiche · firma delle righe · 1';

    /** Le chiavi derivate già calcolate, per chiave dell'applicazione. @var array<string, string> */
    private static array $derivate = [];

    /**
     * La firma della riga con la chiave di adesso.
     *
     * @param array<string, mixed>|object $riga una riga del registro con l'`id` e l'`impronta_valori`
     * @param array<int, string> $condomini id => nome
     * @param list<array{0: string, 1: int}> $soggetti
     */
    public static function firma(array|object $riga, array $condomini, array $soggetti): string
    {
        return self::conLaChiave($riga, $condomini, $soggetti, self::chiave((string) config('app.key')));
    }

    /**
     * Se la firma della riga torna, con la chiave di adesso o con una di prima.
     *
     * @param array<string, mixed>|object $riga
     * @param array<int, string> $condomini
     * @param list<array{0: string, 1: int}> $soggetti
     */
    public static function torna(array|object $riga, array $condomini, array $soggetti): bool
    {
        $firma = ((array) $riga)['firma'] ?? null;
        if (! is_string($firma) || strlen($firma) !== 64) {
            return false;
        }
        foreach (self::chiavi() as $chiave) {
            if (hash_equals(self::conLaChiave($riga, $condomini, $soggetti, $chiave), $firma)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Se la firma torna, per tante righe (una pagina del registro, una «Storia», una stampa): i condomini e i soggetti letti una volta per tutte, mai una
     * query per riga. I condomini si possono passare, se chi chiama li ha già letti.
     *
     * @param iterable<\App\Models\RegistroModifica> $righe
     * @param array<int, array<int, string>>|null $condomini riga => [condominio => nome]
     * @return array<int, bool> riga => firmata
     */
    public static function delleRighe(iterable $righe, ?array $condomini = null): array
    {
        $righe = collect($righe);
        $ids = $righe->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $condomini ??= SigilloDelRegistro::condominiDelle($ids);
        $soggetti = SigilloDelRegistro::soggettiDelle($ids);

        return $righe->mapWithKeys(fn (\App\Models\RegistroModifica $r) => [(int) $r->id => self::torna($r->getAttributes(), $condomini[(int) $r->id] ?? [],
            $soggetti[(int) $r->id] ?? [])])->all();
    }

    /** Le chiavi del registro con cui una firma si controlla: quella di adesso, poi quelle di prima. @return list<string> */
    private static function chiavi(): array
    {
        $chiavi = [self::chiave((string) config('app.key'))];
        foreach ((array) config('app.previous_keys', []) as $prima) {
            if (is_string($prima) && trim($prima) !== '') {
                $chiavi[] = self::chiave($prima);
            }
        }

        return array_values(array_unique($chiavi));
    }

    /** La chiave del registro da una chiave dell'applicazione («base64:…» come la scrive Laravel, o il testo). */
    private static function chiave(string $chiaveDellApplicazione): string
    {
        if (isset(self::$derivate[$chiaveDellApplicazione])) {
            return self::$derivate[$chiaveDellApplicazione];
        }
        $grezza = str_starts_with($chiaveDellApplicazione, 'base64:') ? base64_decode(substr($chiaveDellApplicazione, 7), true) : $chiaveDellApplicazione;
        if (! is_string($grezza) || $grezza === '') {
            // Come la cifratura di Laravel: senza la chiave il programma non scrive (e le pagine non si aprono comunque).
            throw new MissingAppKeyException();
        }

        return self::$derivate[$chiaveDellApplicazione] = hash_hmac('sha256', self::COSTANTE, $grezza, true);
    }

    /**
     * @param array<string, mixed>|object $riga
     * @param array<int, string> $condomini
     * @param list<array{0: string, 1: int}> $soggetti
     */
    private static function conLaChiave(array|object $riga, array $condomini, array $soggetti, string $chiave): string
    {
        $testa = ImprontaDelRegistro::intestazione($riga, 0, $condomini, $soggetti);
        unset($testa['sequenza']);
        $testa['impronta_valori'] = (string) (((array) $riga)['impronta_valori'] ?? '');

        return hash_hmac('sha256', ImprontaDelRegistro::canonico($testa), $chiave);
    }
}
