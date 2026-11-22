<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use Illuminate\Database\Eloquent\Model;

/**
 * La dichiarazione di una scheda nel registro delle modifiche (§4 del progetto): una per modello, in un posto solo.
 *
 * Dice l'area e il tipo (un nome stabile, mai la classe PHP), il nome nella riga («Anagrafica di Ida Romano»), i condomini, la «Storia» in cui compare
 * (P22), le etichette dei campi **con le parole della pagina** (mai il nome della colonna), come si scrive ogni valore, i campi esclusi, quelli che scrive
 * il programma (P8: un salvataggio che cambia solo quelli non scrive niente), i segreti, i soggetti (P9), i campi che sono dati della persona (P6, per
 * l'oscuramento) e quelli che «Ripristina» può rimettere (§8, P3).
 *
 * Ogni colonna della tabella deve stare in `ETICHETTE`, in `ESCLUSI` o in `DEL_PROGRAMMA`, oppure fra gli esclusi di serie: lo controlla
 * `tests/Feature/RegistroModifiche/ClassificazioneDeiModelliTest.php`, così una colonna nuova senza parola fa rosso.
 */
abstract class SchedaNelRegistro
{
    /** Esclusi sempre (§4): le chiavi, le date tecniche, chi ha creato e modificato. */
    public const ESCLUSI_DI_SERIE = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    /** @var class-string<Model> */
    public const MODELLO = Model::class;

    public const AREA = '';

    public const TIPO = '';

    /** La parola della pagina per il tipo, per i filtri e per la testata della «Storia» («Anagrafica»). */
    public const PAROLA = '';

    /** @var array<string, string> colonna => etichetta della pagina */
    public const ETICHETTE = [];

    /** @var array<string, string|array> colonna => formato ({@see \App\Services\RegistroModifiche\FormatoDelValore}) */
    public const FORMATI = [];

    /** @var list<string> le colonne che non si registrano, oltre a quelle di serie (non in pagina, tecniche) */
    public const ESCLUSI = [];

    /** @var list<string> le colonne che scrive il programma (P8): non si registrano, e da sole non fanno una riga */
    public const DEL_PROGRAMMA = [];

    /** @var list<string> le colonne segrete: «cambiata», senza valori */
    public const SEGRETI = [];

    /** @var list<string> le colonne che sono dati della persona (P6): nome, recapiti, codice fiscale, documento, IBAN, indirizzo, note */
    public const DATI_DELLA_PERSONA = [];

    /** @var list<string> le colonne che «Ripristina» può rimettere (§8, P3) */
    public const RIPRISTINABILI = [];

    /** Una riga di un legame (titolarità, rappresentante) compare anche nella «Storia» delle persone fra i soggetti. */
    public const NELLA_STORIA_DEI_SOGGETTI = false;

    abstract public function nome(Model $modello): string;

    /** I condomini della scheda adesso: `[id => nome]`, vuoto per ciò che è dello studio. @return array<int, string> */
    public function condomini(Model $modello): array
    {
        $id = $modello->getAttribute('condominio_id');
        if ($id === null) {
            return [];
        }

        return [(int) $id => (string) (\App\Models\Condominio::query()->whereKey($id)->value('nome') ?? '')];
    }

    /** In quale «Storia» compare la riga (P22): di serie la scheda stessa. @return array{0: string, 1: ?int} */
    public function scheda(Model $modello): array
    {
        return [static::TIPO, $modello->getKey() === null ? null : (int) $modello->getKey()];
    }

    /** Le persone di cui la riga contiene dati (P9). @return list<array{0: string, 1: int}> */
    public function soggetti(Model $modello): array
    {
        return [];
    }

    /** Se questa riga del modello va registrata (P8: il tratto su `Documento` vale solo per l'archivio e per i caricati da una persona). */
    public function registra(Model $modello): bool
    {
        return true;
    }

    /** Il riepilogo di una riga «creata» o di un legame («All'unità Int. 7: proprietario al 100 % dal 01/01/2019»), se la scheda ne ha uno suo. */
    public function riepilogo(Model $modello, string $azione): ?string
    {
        return null;
    }

    /** L'azione di una creazione e di un'eliminazione: un legame è «collegata» e «scollegata». */
    public function azioneDi(string $evento): string
    {
        return $evento;
    }

    public function area(): string
    {
        return static::AREA;
    }

    /** L'area di questa riga: di serie quella del tipo (un conto corrente è delle persone per un fornitore, della contabilità per una cassa). */
    public function areaDi(Model $modello): string
    {
        return static::AREA;
    }

    public function tipo(): string
    {
        return static::TIPO;
    }

    public function etichetta(string $colonna): ?string
    {
        return static::ETICHETTE[$colonna] ?? null;
    }

    public function formato(string $colonna): string|array
    {
        return static::FORMATI[$colonna] ?? 'testo';
    }

    /** Se la colonna si registra: ha un'etichetta e non è esclusa né del programma. */
    public function registrata(string $colonna, Model $modello): bool
    {
        if (in_array($colonna, self::ESCLUSI_DI_SERIE, true) || in_array($colonna, $modello->getHidden(), true)
            || in_array($colonna, static::ESCLUSI, true) || in_array($colonna, static::DEL_PROGRAMMA, true)) {
            return false;
        }
        // P8: le colonne tecniche `*_at` e `*_da` sono escluse di serie, salvo che la scheda le riprenda con un'etichetta.
        if ((str_ends_with($colonna, '_at') || str_ends_with($colonna, '_da')) && ! isset(static::ETICHETTE[$colonna])) {
            return false;
        }

        return true;
    }

    public function segreto(string $colonna): bool
    {
        return in_array($colonna, static::SEGRETI, true);
    }

    /** La dichiarazione per le pagine e per l'oscuramento: etichette, campi ripristinabili, dati della persona. @return array<string, mixed> */
    public function dichiarazione(): array
    {
        return [
            'tipo' => static::TIPO,
            'area' => static::AREA,
            'parola' => static::PAROLA,
            'etichette' => static::ETICHETTE,
            'ripristinabili' => static::RIPRISTINABILI,
            'dati_della_persona' => static::DATI_DELLA_PERSONA,
            'segreti' => static::SEGRETI,
            'nella_storia_dei_soggetti' => static::NELLA_STORIA_DEI_SOGGETTI,
        ];
    }
}
