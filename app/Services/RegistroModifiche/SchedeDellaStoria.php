<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RuoloSpatie;

/**
 * Le «Storie» che il cassetto apre (§8, P18, P22 del progetto della tappa 1): l'**elenco chiuso** dei tipi della rotta, il titolo e la riga sotto il titolo,
 * chi le vede, le righe.
 *
 * I tipi sono quelli delle dichiarazioni delle schede ({@see CatalogoDelRegistro::dichiarazioni()}) più le «Storie» senza pagina propria che il motore scrive
 * nella colonna `scheda_tipo` (`regole_recupero` con l'id del condominio, `saldi` con l'id dell'esercizio,
 * `impostazioni_stampe` e `documenti` senza id, le impostazioni dello studio), gli atti sulle utenze (`utente`, `ruolo`: le pagine `ModificaUtente` e
 * `ModificaRuolo`), e due «Storie» di un elenco intero, per le pagine che hanno una riga per scheda e una sola intestazione: `casse` (le risorse e i fondi
 * di un condominio) e `categorie_fornitori` (dello studio). Un tipo fuori da questo elenco è un 404.
 *
 * Chi le vede, oltre al permesso «Vede la storia delle schede» ({@see AccessoAlRegistro}): le schede dello studio (utenti, ruoli, impostazioni dello
 * studio, modelli e carte delle stampe) solo l'amministratore; la scheda di un oggetto che non c'è più solo l'amministratore; per le altre, chi può vedere
 * quella scheda (la policy `view` dove c'è; per ciò che sta dentro un condominio, «Visualizza condomini»).
 */
final class SchedeDellaStoria
{
    /** Le «Storie» che non sono la dichiarazione di una scheda: tipo => [parola, titolo, l'id è di…, dello studio]. */
    public const ALTRE = [
        'regole_recupero' => ['Regole del recupero dei crediti', 'Storia delle regole del recupero dei crediti', 'condominio', false],
        'saldi' => ['Saldi iniziali', 'Storia dei saldi iniziali', 'esercizio', false],
        'casse' => ['Risorse e fondi', 'Storia delle risorse e dei fondi', 'condominio', false],
        'documenti' => ['Categorie dei documenti', 'Storia delle categorie dei documenti', null, false],
        'categorie_fornitori' => ['Categorie dei fornitori', 'Storia delle categorie dei fornitori', null, false],
        'impostazioni_stampe' => ['Impostazioni delle stampe', 'Storia delle impostazioni delle stampe', null, true],
        'impostazioni_generali' => ['Impostazioni generali', 'Storia delle impostazioni generali', null, true],
        'impostazioni_posta' => ['Configurazione email', 'Storia della configurazione email', null, true],
        'impostazioni_backup' => ['Impostazioni dei backup', 'Storia delle impostazioni dei backup', null, true],
        'impostazioni_tasso_legale' => ['Tasso legale', 'Storia del tasso legale', null, true],
        'impostazioni_assemblee' => ['Impostazioni delle assemblee', 'Storia delle impostazioni delle assemblee', null, true],
        'utente' => ['Utente', null, 'utente', true],
        'ruolo' => ['Ruolo', null, 'ruolo', true],
    ];

    /** Le «Storie» di un elenco intero: tipo => il tipo delle schede che raccoglie. */
    public const DI_UN_ELENCO = ['casse' => 'cassa', 'categorie_fornitori' => 'categoria_fornitore'];

    /** Fra le dichiarazioni, quelle dello studio (P18): i modelli e le carte delle stampe. */
    public const DICHIARAZIONI_DELLO_STUDIO = ['modello_testo', 'carta_stampa'];

    /** Il titolo per tipo, «Storia di …» con l'articolo giusto (la bozza: «Storia di Ida Romano»). */
    private const DI = [
        'condominio' => 'del condominio', 'palazzina' => 'della palazzina', 'scala' => 'della scala', 'immobile' => 'dell\'unità', 'tabella' => 'della tabella',
        'esercizio' => 'dell\'esercizio', 'gestione' => 'della gestione', 'voce' => 'della voce', 'piano_conti' => 'del piano dei conti',
        'piano_rate' => 'del piano rate', 'cassa' => 'della risorsa', 'comunicazione' => 'della comunicazione', 'segnalazione' => 'della segnalazione',
        'documento' => 'del documento', 'categoria_documento' => 'della categoria dei documenti', 'categoria_fornitore' => 'della categoria dei fornitori',
        'assemblea' => 'dell\'assemblea', 'delibera' => 'della delibera',
    ];

    /** Le schede delle assemblee: le vede chi vede le assemblee (Coda 679, §6.1 del progetto della tappa 2). */
    private const DELLE_ASSEMBLEE = ['assemblea', 'seduta', 'punto', 'delibera', 'memo_assemblea'];

    /** @return list<string> l'elenco chiuso dei tipi della rotta */
    public static function tipi(): array
    {
        return array_values(array_unique([...array_keys(CatalogoDelRegistro::dichiarazioni()), ...array_keys(self::ALTRE)]));
    }

    public static function esiste(string $tipo): bool
    {
        return in_array($tipo, self::tipi(), true);
    }

    /** Le «Storie» dello studio: solo l'amministratore (P18). */
    public static function delloStudio(string $tipo): bool
    {
        return in_array($tipo, self::DICHIARAZIONI_DELLO_STUDIO, true) || (self::ALTRE[$tipo][3] ?? false) === true;
    }

    /** La parola della pagina per il tipo («Anagrafica»). */
    public static function parola(string $tipo): string
    {
        return self::ALTRE[$tipo][0] ?? (CatalogoDelRegistro::dichiarazioni()[$tipo]['parola'] ?? ucfirst(str_replace('_', ' ', $tipo)));
    }

    /**
     * La cosa di cui si legge la storia: il modello della scheda, o il condominio, l'esercizio, l'utente, il ruolo di una «Storia» senza pagina propria;
     * `null` per ciò che è dello studio senza id, o per un oggetto che non c'è più.
     */
    public static function cosa(string $tipo, ?int $id): ?Model
    {
        if ($id === null) {
            return null;
        }
        $di = self::ALTRE[$tipo][2] ?? null;
        $classe = match ($di) {
            'condominio' => Condominio::class,
            'esercizio' => Esercizio::class,
            'utente' => User::class,
            'ruolo' => RuoloSpatie::class,
            default => ($d = CatalogoDelRegistro::descrizionePerTipo($tipo)) === null ? null : $d::MODELLO,
        };

        return $classe === null ? null : $classe::query()->find($id);
    }

    /** Se la storia chiede un id (una scheda) o no (lo studio, un elenco dello studio). */
    public static function conId(string $tipo): bool
    {
        if (array_key_exists($tipo, self::ALTRE)) {
            return self::ALTRE[$tipo][2] !== null;
        }

        return true;
    }

    /** Chi la vede, oltre al permesso della storia: la policy della scheda, o «Visualizza condomini» per ciò che sta in un condominio. */
    public static function puoVedere(User $utente, string $tipo, ?Model $cosa): bool
    {
        if (self::delloStudio($tipo)) {
            return AccessoAlRegistro::eAmministratore($utente);
        }
        if ($cosa === null) {
            // Un oggetto che non c'è più (P18), o un elenco dello studio senza id: lo vede l'amministratore; un elenco dello studio anche chi vede le
            // schede di quel tipo.
            return self::conId($tipo) ? AccessoAlRegistro::eAmministratore($utente) : self::vedeLElenco($utente, $tipo);
        }
        if (in_array($tipo, ['anagrafica', 'fornitore', 'comunicazione', 'segnalazione', 'documento', 'categoria_documento'], true)) {
            return Gate::forUser($utente)->allows('view', $cosa);
        }
        if (in_array($tipo, self::DELLE_ASSEMBLEE, true)) {
            return \App\Services\Assemblee\AccessoAlleAssemblee::vede($utente);
        }
        if (in_array($tipo, ['categoria_fornitore', 'rappresentante_fornitore'], true)) {
            return Gate::forUser($utente)->allows('viewAny', \App\Models\Fornitore::class);
        }

        // Il condominio e ciò che sta dentro un condominio: «Visualizza condomini» (la policy del condominio non guarda il modello, §1 del progetto).
        return Gate::forUser($utente)->allows('view', Condominio::class);
    }

    private static function vedeLElenco(User $utente, string $tipo): bool
    {
        return match ($tipo) {
            'categorie_fornitori' => Gate::forUser($utente)->allows('viewAny', \App\Models\CategoriaFornitore::class),
            'documenti' => Gate::forUser($utente)->allows('viewAny', \App\Models\CategoriaDocumento::class),
            default => AccessoAlRegistro::eAmministratore($utente),
        };
    }

    /** Le righe della storia, dalla più recente, senza le righe annullate da un ripristino (P14). @return Builder<RegistroModifica> */
    public static function righe(string $tipo, ?int $id): Builder
    {
        if (isset(self::DI_UN_ELENCO[$tipo])) {
            return LetturaDelRegistro::storiaDiUnElenco(self::DI_UN_ELENCO[$tipo], $tipo === 'casse' ? $id : null);
        }

        return LetturaDelRegistro::storia($tipo, $id);
    }

    /** Il titolo del cassetto: «Storia di Ida Romano», «Storia della comunicazione «Lavori al portone»». */
    public static function titolo(string $tipo, ?Model $cosa, ?string $nomeDellaRiga = null): string
    {
        if (isset(self::ALTRE[$tipo]) && self::ALTRE[$tipo][1] !== null) {
            return self::ALTRE[$tipo][1];
        }
        if ($tipo === 'utente') {
            return 'Storia dell\'utente ' . ($cosa?->getAttribute('name') ?? $nomeDellaRiga ?? '');
        }
        if ($tipo === 'ruolo') {
            return 'Storia del ruolo «' . ($cosa?->getAttribute('name') ?? $nomeDellaRiga ?? '') . '»';
        }
        $descrizione = CatalogoDelRegistro::descrizionePerTipo($tipo);
        if ($cosa === null || $descrizione === null) {
            return 'Storia: ' . ($nomeDellaRiga ?? self::parola($tipo));
        }
        if ($tipo === 'anagrafica') {
            return 'Storia di ' . $cosa->getAttribute('nome');
        }
        if ($tipo === 'fornitore') {
            return 'Storia di ' . $cosa->getAttribute('ragione_sociale');
        }
        $nome = $descrizione->nome($cosa);
        if (isset(self::DI[$tipo])) {
            // Il nome della dichiarazione comincia con la parola del tipo («Comunicazione «Lavori al portone»», «Unità Int. 7»): la parola diventa
            // l'articolo del titolo («Storia della comunicazione «Lavori al portone»»).
            $parola = $descrizione::PAROLA;
            $resto = mb_stripos($nome, $parola) === 0 ? ltrim(mb_substr($nome, mb_strlen($parola))) : $nome;

            return 'Storia ' . self::DI[$tipo] . ' ' . $resto;
        }

        return 'Storia: ' . $nome;
    }

    /** I condomini della scheda adesso, per la riga sotto il titolo: `[id => nome]`. @return array<int, string> */
    public static function condomini(string $tipo, ?Model $cosa): array
    {
        if ($cosa === null) {
            return [];
        }
        if ($cosa instanceof Condominio) {
            return [(int) $cosa->id => (string) $cosa->nome];
        }
        if ($cosa instanceof Esercizio) {
            $c = Condominio::query()->find($cosa->condominio_id);

            return $c === null ? [] : [(int) $c->id => (string) $c->nome];
        }
        $descrizione = CatalogoDelRegistro::descrizionePerTipo($tipo);

        return $descrizione === null ? [] : $descrizione->condomini($cosa);
    }
}
