<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Commenti;

use App\Contracts\Commentable;
use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class RegoleCommenti
{
    /**
     * La chiave della discussione unica di una comunicazione ai palazzi, uno o più (decisione 458, che cambia la 261.1): tutti i
     * destinatari che aprono la comunicazione, di qualunque palazzo, e lo studio leggono e scrivono lì. Le discussioni con una
     * persona hanno la chiave `persona:<id>`; la segnalazione la sua, `palazzo:<id>`.
     */
    public const CHIAVE_UNICA = 'tutti';

    private const PERMESSI = [
        'segnalazioni' => [
            'commenta'        => Permission::COMMENT_SEGNALAZIONI->value,
            'pubblica'        => Permission::PUBLISH_COMMENTS_SEGNALAZIONI->value,
            'approva'         => Permission::APPROVE_COMMENTS_SEGNALAZIONI->value,
            'modifica'        => Permission::EDIT_COMMENTS_SEGNALAZIONI->value,
            'modifica_propri' => Permission::EDIT_OWN_COMMENTS_SEGNALAZIONI->value,
            'elimina'         => Permission::DELETE_COMMENTS_SEGNALAZIONI->value,
            'elimina_propri'  => Permission::DELETE_OWN_COMMENTS_SEGNALAZIONI->value,
            'visualizza'      => Permission::VIEW_COMMENTS_SEGNALAZIONI->value,
        ],
        'comunicazioni' => [
            'commenta'        => Permission::COMMENT_COMUNICAZIONI->value,
            'pubblica'        => Permission::PUBLISH_COMMENTS_COMUNICAZIONI->value,
            'approva'         => Permission::APPROVE_COMMENTS_COMUNICAZIONI->value,
            'modifica'        => Permission::EDIT_COMMENTS_COMUNICAZIONI->value,
            'modifica_propri' => Permission::EDIT_OWN_COMMENTS_COMUNICAZIONI->value,
            'elimina'         => Permission::DELETE_COMMENTS_COMUNICAZIONI->value,
            'elimina_propri'  => Permission::DELETE_OWN_COMMENTS_COMUNICAZIONI->value,
            'visualizza'      => Permission::VIEW_COMMENTS_COMUNICAZIONI->value,
        ],
    ];

    public static function haPermesso(User $user, string $permesso): bool
    {
        try {
            return $user->hasPermissionTo($permesso);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public static function haPannello(User $user): bool
    {
        return self::haPermesso($user, Permission::ACCESS_ADMIN_PANEL->value);
    }

    public static function tipo(Model|string $elementoOTipo): string
    {
        if (is_string($elementoOTipo)) {
            if (in_array($elementoOTipo, ['segnalazioni', 'comunicazioni'], true)) {
                return $elementoOTipo;
            }

            return $elementoOTipo === Segnalazione::class ? 'segnalazioni' : 'comunicazioni';
        }

        return $elementoOTipo instanceof Segnalazione ? 'segnalazioni' : 'comunicazioni';
    }

    public static function permesso(Model|string $elementoOTipo, string $azione): string
    {
        return self::PERMESSI[self::tipo($elementoOTipo)][$azione];
    }

    /**
     * Chi apre la pagina «Commenti» del pannello (Coda 657, decisione 442): chi ha il pannello e può leggere i commenti delle
     * segnalazioni o delle comunicazioni. Ha la stessa forma di `RegoleAllegati::puoAprirePagina`.
     */
    public static function puoAprirePagina(User $user): bool
    {
        return self::haPannello($user)
            && (self::haPermesso($user, self::permesso('segnalazioni', 'visualizza'))
                || self::haPermesso($user, self::permesso('comunicazioni', 'visualizza')));
    }

    /**
     * Chi modera i commenti di un tipo dalla pagina «Commenti» (decisione 454: il compito «Da moderare» con «Modera i commenti» lo vede solo lui): la
     * regola di {@see puoModerare()} per il tipo (il pannello e «Approva commenti»; la scheda la dice chi legge il compito) e la pagina che gli mostra i
     * commenti di quel tipo, che senza «Visualizza commenti» gli risponderebbe 403 o vuota.
     */
    public static function puoModerareDallaPagina(User $user, string $tipo): bool
    {
        return self::haPannello($user)
            && self::haPermesso($user, self::permesso($tipo, 'approva'))
            && self::haPermesso($user, self::permesso($tipo, 'visualizza'));
    }

    public static function apreSchedaPannello(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        $ability = $elemento instanceof Segnalazione ? 'view' : 'show';

        return Gate::forUser($user)->allows($ability, $elemento);
    }

    public static function apreSchedaPortale(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return Gate::forUser($user)->allows('show', $elemento);
    }

    public static function appartieneAlCondominio(User $user, ?int $condominioId): bool
    {
        if ($condominioId === null) {
            return false;
        }

        return (bool) $user->anagrafica?->condomini()->whereKey($condominioId)->exists();
    }

    public static function eAPersoneOPrivata(Comunicazione $comunicazione): bool
    {
        if ($comunicazione->is_private) {
            return true;
        }

        // Nell'elenco le anagrafiche sono già caricate (decisione 441): niente richiesta per ogni riga.
        return $comunicazione->relationLoaded('anagrafiche')
            ? $comunicazione->anagrafiche->isNotEmpty()
            : $comunicazione->anagrafiche()->exists();
    }

    /**
     * Se la comunicazione va ad almeno un palazzo. Nell'elenco i palazzi sono già caricati: niente richiesta per ogni riga.
     */
    public static function haPalazzi(Comunicazione $comunicazione): bool
    {
        return $comunicazione->relationLoaded('condomini')
            ? $comunicazione->condomini->isNotEmpty()
            : $comunicazione->condomini()->exists();
    }

    /**
     * La regola del portale su una discussione di una comunicazione, una sola per la scheda e per l'elenco (decisione 441):
     * con persone o privata, la discussione è solo quella della persona; altrimenti è la discussione unica dei palazzi
     * (decisione 458), che legge chi sta in almeno uno dei palazzi destinatari, una volta sola anche se sta in più d'uno.
     *
     * @param array<int>|null $condominiDellUtente I palazzi dell'utente, se chi chiama li ha già (l'elenco); altrimenti si chiedono.
     */
    public static function discussioneVisibileAlPortale(User $user, Comunicazione $comunicazione, string $tipo, int $id, ?array $condominiDellUtente = null): bool
    {
        if (self::eAPersoneOPrivata($comunicazione)) {
            return $tipo === 'persona' && $id === (int) $user->id;
        }

        if ($tipo !== 'tutti') {
            return false;
        }

        if ($condominiDellUtente === null) {
            return self::palazzoDellaPersona($comunicazione, $user) !== null;
        }

        // Le relazioni si leggono se l'elenco le ha caricate; altrimenti si chiedono, senza caricarle sul modello (Coda 260).
        $destinatari = ($comunicazione->relationLoaded('condomini')
            ? $comunicazione->condomini->pluck('id')
            : $comunicazione->condomini()->pluck('condomini.id'))->map(fn ($id) => (int) $id)->all();

        return array_intersect($destinatari, $condominiDellUtente) !== [];
    }

    /**
     * @return Collection<int, User>
     */
    public static function personeDestinatarie(Comunicazione $comunicazione): Collection
    {
        $persone = collect();

        foreach ($comunicazione->anagrafiche()->with('user')->get() as $anagrafica) {
            if ($anagrafica->user) {
                $persone->put($anagrafica->user->id, $anagrafica->user);
            }
        }

        if ($comunicazione->is_private && $comunicazione->createdBy) {
            if (! self::haPannello($comunicazione->createdBy)) {
                $persone->put($comunicazione->createdBy->id, $comunicazione->createdBy);
            }
        }

        return $persone->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public static function palazzoDellaPersona(Comunicazione $comunicazione, User $persona): ?Condominio
    {
        $condominioIds = $comunicazione->condomini()->pluck('condomini.id')->all();
        if ($condominioIds === []) {
            return null;
        }

        return $persona->anagrafica?->condomini()
            ->whereIn('condomini.id', $condominioIds)
            ->orderBy('nome')
            ->first();
    }

    /**
     * La discussione unica di una comunicazione ai palazzi (decisione 458): senza titolo, né un palazzo né una persona.
     *
     * @return array{chiave: string, tipo: string, id: int, titolo: string, palazzo: null, persona: null}
     */
    private static function discussioneUnica(): array
    {
        return [
            'chiave'   => self::CHIAVE_UNICA,
            'tipo'     => 'tutti',
            'id'       => 0,
            'titolo'   => '',
            'palazzo'  => null,
            'persona'  => null,
        ];
    }

    /**
     * La chiave della discussione in cui sta un commento: per una comunicazione, quella della sua persona, o la discussione unica
     * dei palazzi se non ha una persona (di qualunque palazzo, anche senza: decisione 458); per una segnalazione, la sua. Una regola
     * sola per le email, i contatori dell'elenco e la pagina «Commenti». Dice dove sta il commento, non se ci resta dopo che il suo
     * palazzo è stato tolto dai destinatari: quello lo dice {@see restaFraIDestinatari()} (decisione 459).
     */
    public static function chiaveDelCommento(Commento $commento, Segnalazione|Comunicazione|null $elemento = null): string
    {
        $elemento ??= $commento->commentable;

        if ($elemento instanceof Segnalazione) {
            return 'palazzo:' . $elemento->condominio_id;
        }

        return $commento->discussione_user_id !== null
            ? 'persona:' . (int) $commento->discussione_user_id
            : self::CHIAVE_UNICA;
    }

    /**
     * @return Collection<int, array{chiave: string, tipo: string, id: int, titolo: string, palazzo: ?Condominio, persona: ?User}>
     */
    public static function tutteDiscussioni(Segnalazione|Comunicazione $elemento): Collection
    {
        if ($elemento instanceof Segnalazione) {
            $elemento->loadMissing('condominio');

            return collect([[
                'chiave'   => 'palazzo:' . $elemento->condominio_id,
                'tipo'     => 'palazzo',
                'id'       => (int) $elemento->condominio_id,
                'titolo'   => $elemento->condominio?->nome ?? '',
                'palazzo'  => $elemento->condominio,
                'persona'  => null,
            ]]);
        }

        if (self::eAPersoneOPrivata($elemento)) {
            $persone = self::personeDestinatarie($elemento);

            return $persone->map(function (User $persona) use ($elemento) {
                return [
                    'chiave'   => 'persona:' . $persona->id,
                    'tipo'     => 'persona',
                    'id'       => $persona->id,
                    'titolo'   => $persona->name,
                    'palazzo'  => self::palazzoDellaPersona($elemento, $persona),
                    'persona'  => $persona,
                ];
            });
        }

        // Ai palazzi, uno o più: una discussione sola per tutti i destinatari e lo studio (decisione 458, che cambia la 261.1).
        return self::haPalazzi($elemento) ? collect([self::discussioneUnica()]) : collect();
    }

    /**
     * @return Collection<int, array{chiave: string, tipo: string, id: int, titolo: string, palazzo: ?Condominio, persona: ?User}>
     */
    public static function discussioniVisibili(User $user, Segnalazione|Comunicazione $elemento, string $porta = 'user'): Collection
    {
        if (! self::haPermesso($user, self::permesso($elemento, 'visualizza'))) {
            return collect();
        }

        if ($porta === 'admin') {
            if (! self::apreSchedaPannello($user, $elemento)) {
                return collect();
            }

            return self::tutteDiscussioni($elemento);
        }

        if (! self::apreSchedaPortale($user, $elemento)) {
            return collect();
        }

        if ($elemento instanceof Segnalazione) {
            return self::tutteDiscussioni($elemento);
        }

        return self::tutteDiscussioni($elemento)->filter(function (array $d) use ($user, $elemento) {
            return self::discussioneVisibileAlPortale($user, $elemento, $d['tipo'], (int) $d['id']);
        })->values();
    }

    /**
     * Dove nasce il commento di `$autore` nella discussione con la chiave data: il palazzo (`condominio_id`) e la persona
     * (`discussione_user_id`) che il commento porta. `null` se la comunicazione non ha quella discussione.
     */
    public static function risolviDiscussione(Comunicazione $comunicazione, string $chiave, ?User $autore = null): ?array
    {
        if ($chiave === self::CHIAVE_UNICA) {
            if (self::eAPersoneOPrivata($comunicazione) || ! self::haPalazzi($comunicazione)) {
                return null;
            }

            // Il palazzo di chi scrive fra i destinatari, il primo per nome; lo studio senza un'anagrafica fra i destinatari lo
            // lascia vuoto (decisione 458): il commento sta nella discussione unica lo stesso.
            $palazzo = $autore !== null ? self::palazzoDellaPersona($comunicazione, $autore) : null;

            return [
                'tipo'                => 'tutti',
                'condominio_id'       => $palazzo?->id,
                'discussione_user_id' => null,
                'palazzo'             => $palazzo,
                'persona'             => null,
            ];
        }

        if (str_starts_with($chiave, 'persona:')) {
            $id = (int) substr($chiave, 8);
            if ($id <= 0) {
                return null;
            }

            if (! self::eAPersoneOPrivata($comunicazione)) {
                return null;
            }

            $persone = self::personeDestinatarie($comunicazione);
            $persona = $persone->firstWhere('id', $id);
            if (! $persona) {
                return null;
            }

            $palazzo = self::palazzoDellaPersona($comunicazione, $persona);

            return [
                'tipo'                => 'persona',
                'condominio_id'       => $palazzo?->id,
                'discussione_user_id' => $persona->id,
                'palazzo'             => $palazzo,
                'persona'             => $persona,
            ];
        }

        return null;
    }

    public static function puoScrivereInDiscussione(User $user, Segnalazione|Comunicazione $elemento, string $chiave, ?string $porta = null): bool
    {
        if (! self::haPermesso($user, self::permesso($elemento, 'commenta'))) {
            return false;
        }

        if (! (bool) $elemento->can_comment) {
            return false;
        }

        $porta ??= self::haPannello($user) ? 'admin' : 'user';

        if ($elemento instanceof Comunicazione) {
            if ($porta === 'user' || ! self::haPannello($user)) {
                if (! ($elemento->is_published && $elemento->is_approved)) {
                    return false;
                }
            }

            $visibili = self::discussioniVisibili($user, $elemento, $porta);

            return $visibili->pluck('chiave')->contains($chiave);
        }

        if ($porta === 'user' || ! self::haPannello($user)) {
            if (! self::appartieneAlCondominio($user, $elemento->condominio_id)) {
                return false;
            }
            if (! self::apreSchedaPortale($user, $elemento)) {
                return false;
            }
        } else {
            if (! self::apreSchedaPannello($user, $elemento)) {
                return false;
            }
        }

        return $chiave === ('palazzo:' . $elemento->condominio_id);
    }

    public static function puoScrivereSegnalazione(User $user, Segnalazione $segnalazione, ?string $porta = null): bool
    {
        if (! self::haPermesso($user, Permission::COMMENT_SEGNALAZIONI->value)) {
            return false;
        }

        if (! (bool) $segnalazione->can_comment) {
            return false;
        }

        $porta ??= self::haPannello($user) ? 'admin' : 'user';

        if ($porta === 'user' || ! self::haPannello($user)) {
            return self::appartieneAlCondominio($user, $segnalazione->condominio_id)
                && self::apreSchedaPortale($user, $segnalazione);
        }

        return self::apreSchedaPannello($user, $segnalazione);
    }

    public static function puoScrivereInAlmenoUnaDiscussione(User $user, Comunicazione $comunicazione): bool
    {
        $porta = self::haPannello($user) ? 'admin' : 'user';
        $discussioni = self::discussioniVisibili($user, $comunicazione, $porta);

        foreach ($discussioni as $d) {
            if (self::puoScrivereInDiscussione($user, $comunicazione, $d['chiave'], $porta)) {
                return true;
            }
        }

        return false;
    }

    public static function statoAllaNascita(User $user, Segnalazione|Comunicazione $elemento): string
    {
        if (self::haPannello($user) && (
            self::haPermesso($user, self::permesso($elemento, 'approva')) ||
            self::haPermesso($user, self::permesso($elemento, 'pubblica'))
        )) {
            return 'pubblicato';
        }

        $defaultModerati = ($elemento instanceof Comunicazione);
        $isModerato = (bool) ($elemento->commenti_moderati ?? $defaultModerati);

        if (self::haPermesso($user, self::permesso($elemento, 'pubblica')) && ! $isModerato) {
            return 'pubblicato';
        }

        return 'in_attesa';
    }

    public static function puoModerare(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return self::haPannello($user)
            && self::haPermesso($user, self::permesso($elemento, 'approva'))
            && self::apreSchedaPannello($user, $elemento);
    }

    public static function puoApprovare(User $user, Commento $commento): bool
    {
        if ($commento->stato !== 'in_attesa') {
            return false;
        }

        $elemento = $commento->commentable;
        if (! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        return self::puoModerare($user, $elemento);
    }

    public static function puoNascondere(User $user, Commento $commento): bool
    {
        if ($commento->stato !== 'pubblicato') {
            return false;
        }

        $elemento = $commento->commentable;
        if (! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        return self::puoModerare($user, $elemento);
    }

    /**
     * «Mostra di nuovo»: riporta visibile un commento nascosto (decisione 456). Lo fa chi può nascondere, solo su un nascosto.
     */
    public static function puoMostrareDiNuovo(User $user, Commento $commento): bool
    {
        if ($commento->stato !== 'nascosto') {
            return false;
        }

        $elemento = $commento->commentable;
        if (! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        return self::puoModerare($user, $elemento);
    }

    public static function puoModificare(User $user, Commento $commento): bool
    {
        if ($commento->stato === 'nascosto') {
            return false;
        }

        $elemento = $commento->commentable;
        if (! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        if (! (bool) ($elemento->can_comment ?? true)) {
            return false;
        }

        $eAutore = (int) $commento->user_id === (int) $user->id;

        if ($eAutore && self::haPermesso($user, self::permesso($elemento, 'modifica_propri'))) {
            return self::haPannello($user)
                ? (self::apreSchedaPannello($user, $elemento) || self::apreSchedaPortale($user, $elemento))
                : self::apreSchedaPortale($user, $elemento);
        }

        if (self::haPannello($user)
            && self::haPermesso($user, self::permesso($elemento, 'modifica'))
            && self::apreSchedaPannello($user, $elemento)
        ) {
            return true;
        }

        return false;
    }

    public static function puoEliminare(User $user, Commento $commento): bool
    {
        $elemento = $commento->commentable;
        if (! ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione)) {
            return false;
        }

        $eAutore = (int) $commento->user_id === (int) $user->id;

        if ($eAutore && self::haPermesso($user, self::permesso($elemento, 'elimina_propri'))) {
            return self::haPannello($user)
                ? (self::apreSchedaPannello($user, $elemento) || self::apreSchedaPortale($user, $elemento))
                : self::apreSchedaPortale($user, $elemento);
        }

        if (self::haPannello($user)
            && self::haPermesso($user, self::permesso($elemento, 'elimina'))
            && self::apreSchedaPannello($user, $elemento)
        ) {
            return true;
        }

        return false;
    }

    public static function puoImpostare(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return self::haPannello($user)
            && Gate::forUser($user)->allows('update', $elemento);
    }

    /**
     * «Commenti in moderazione» di un elemento (Fase 1-bis della 1.12.0-beta.11): spegnerla è approvare in anticipo ciò che
     * verrà, quindi la cambia chi modifica l'elemento e lo modera. «Consenti commenti» resta a chi lo modifica ({@see puoImpostare()}).
     */
    public static function puoImpostareModerazione(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return self::puoImpostare($user, $elemento) && self::puoModerare($user, $elemento);
    }

    /**
     * La stessa scelta alla creazione, quando l'elemento non c'è ancora: il pannello e «Approva commenti» del tipo. Chi non
     * li ha non sceglie, e l'elemento nasce con il di serie delle impostazioni.
     */
    public static function puoScegliereModerazione(User $user, string $tipo): bool
    {
        return self::haPannello($user) && self::haPermesso($user, self::permesso($tipo, 'approva'));
    }

    public static function configSchedaComunicazione(User $user, Comunicazione $comunicazione): array
    {
        return [
            'can_comment'       => (bool) $comunicazione->can_comment,
            'commenti_moderati' => (bool) $comunicazione->commenti_moderati,
            'puo_impostare'     => self::puoImpostare($user, $comunicazione),
            'puo_impostare_moderazione' => self::puoImpostareModerazione($user, $comunicazione),
            'puo_moderare'      => self::puoModerare($user, $comunicazione),
        ];
    }

    public static function configSchedaSegnalazione(User $user, Segnalazione $segnalazione, string $porta = 'user'): array
    {
        $puoModerare = self::puoModerare($user, $segnalazione);

        return [
            'can_comment'       => (bool) $segnalazione->can_comment,
            'commenti_moderati' => (bool) ($segnalazione->commenti_moderati ?? false),
            'puo_impostare'     => self::puoImpostare($user, $segnalazione),
            'puo_impostare_moderazione' => self::puoImpostare($user, $segnalazione) && $puoModerare,
            'puo_moderare'      => $puoModerare,
            'can_moderate'      => $puoModerare,
            'can_create'        => self::puoScrivereSegnalazione($user, $segnalazione, $porta),
            'can_publish'       => self::haPermesso($user, self::permesso($segnalazione, 'pubblica')),
        ];
    }

    public static function discussioniInScheda(User $user, Comunicazione $comunicazione, string $porta = 'user'): array
    {
        $visibili = self::discussioniVisibili($user, $comunicazione, $porta);

        return $visibili->map(function (array $d) use ($user, $comunicazione, $porta) {
            // La discussione unica dei palazzi non ha titolo, né nel pannello né nel portale (decisione 458).
            $titolo = match (true) {
                $d['tipo'] === 'tutti' => '',
                $porta === 'admin'     => $d['persona']->name,
                default                => __('commenti.con_lo_studio'),
            };

            return [
                'chiave'       => $d['chiave'],
                'tipo'         => $d['tipo'],
                'titolo'       => $titolo,
                'puo_scrivere' => self::puoScrivereInDiscussione($user, $comunicazione, $d['chiave'], $porta),
                'commenti'     => self::commentiInDiscussione($user, $comunicazione, $d, $porta),
            ];
        })->values()->all();
    }

    /**
     * Quali commenti legge chi non modera l'elemento: i pubblicati e i propri in attesa. Una regola sola per la scheda
     * ({@see commentiInDiscussione()}) e per la pagina «Commenti» del pannello (`CommentiPaginaService`, Fase 1-bis della
     * 1.12.0-beta.11): gli in attesa degli altri e i nascosti li legge solo chi modera.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $commenti  Una richiesta sulla tabella `commenti`.
     */
    public static function soloQuelliCheLeggeChiNonModera($commenti, User $user): void
    {
        $commenti->where(function ($q) use ($user) {
            $q->where('commenti.stato', 'pubblicato')
              ->orWhere(function ($q2) use ($user) {
                  $q2->where('commenti.stato', 'in_attesa')->where('commenti.user_id', $user->id);
              });
        });
    }

    /**
     * I commenti delle comunicazioni che stanno in una discussione che c'è ancora ({@see tutteDiscussioni()}), scritto per una
     * richiesta sulla tabella `commenti`: la discussione unica di una comunicazione che va ancora ai palazzi (senza persona: decisione
     * 458), con i soli commenti che ci restano ({@see soloQuelliCheRestanoFraIDestinatari()}: decisione 459), o quella di una persona
     * che è fra i destinatari (o del condòmino che ha scritto la privata). Una persona tolta dai destinatari porta via la sua
     * discussione dalla scheda e dalla riga dell'elenco (`ContatoriElenco`); un palazzo tolto, i commenti scritti da lì. Con questa
     * regola lo stesso vale per il compito «Da moderare» e per la pagina «Commenti» (Fase 1-bis della 1.12.0-beta.11). Le righe delle
     * segnalazioni non vanno messe qui: hanno una discussione sola, quella del loro palazzo.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $commenti  Una richiesta sulla tabella `commenti`, ristretta alle comunicazioni.
     */
    public static function soloNelleDiscussioniCheCiSono($commenti): void
    {
        try {
            $conPannello = User::permission(Permission::ACCESS_ADMIN_PANEL->value)->select('users.id');
        } catch (PermissionDoesNotExist) {
            $conPannello = [];
        }

        $commenti->where(function ($q) use ($conPannello) {
            // La discussione unica: la comunicazione va ancora ad almeno un palazzo, e né privata né a persone; il commento ci resta
            // finché il palazzo da cui è scritto è fra i destinatari, o non ne ha uno, o chi l'ha scritto ci sta ancora (decisione 459).
            $q->where(function ($palazzo) {
                $palazzo->whereNull('commenti.discussione_user_id')
                    ->whereExists(fn ($s) => $s->selectRaw('1')->from('comunicazione_condominio')
                        ->whereColumn('comunicazione_condominio.comunicazione_id', 'commenti.commentable_id'))
                    ->whereExists(fn ($s) => $s->selectRaw('1')->from('comunicazioni')
                        ->whereColumn('comunicazioni.id', 'commenti.commentable_id')
                        ->where('comunicazioni.is_private', false))
                    ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('anagrafica_comunicazione')
                        ->whereColumn('anagrafica_comunicazione.comunicazione_id', 'commenti.commentable_id'));
                self::soloQuelliCheRestanoFraIDestinatari($palazzo);
            })
            // Con una persona: è fra i destinatari, o è il condòmino (senza il pannello) che ha scritto la privata.
            ->orWhere(function ($persona) use ($conPannello) {
                $persona->whereNotNull('commenti.discussione_user_id')
                    ->where(function ($chi) use ($conPannello) {
                        $chi->whereExists(fn ($s) => $s->selectRaw('1')->from('anagrafica_comunicazione')
                            ->join('anagrafiche', 'anagrafiche.id', '=', 'anagrafica_comunicazione.anagrafica_id')
                            ->whereColumn('anagrafica_comunicazione.comunicazione_id', 'commenti.commentable_id')
                            ->whereColumn('anagrafiche.user_id', 'commenti.discussione_user_id'))
                        ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('comunicazioni')
                            ->whereColumn('comunicazioni.id', 'commenti.commentable_id')
                            ->where('comunicazioni.is_private', true)
                            ->whereColumn('comunicazioni.created_by', 'commenti.discussione_user_id')
                            ->whereNotIn('comunicazioni.created_by', $conPannello));
                    });
            });
        });
    }

    /**
     * Se un commento resta nella discussione unica di una comunicazione ai palazzi (decisione 459): finché il palazzo da cui è
     * scritto è fra i destinatari, **oppure** non ha un palazzo (lo studio senza un'anagrafica fra i destinatari), **oppure** chi
     * l'ha scritto ha ancora un'anagrafica in un palazzo destinatario (chi sta in due palazzi vede ancora la comunicazione: il suo
     * commento resta, e lui lo corregge e lo elimina come prima). Altrimenti non si vede, per nessuno, e non si cancella: rimesso
     * il palazzo fra i destinatari, torna con la sua data. Un commento con una persona, o di una segnalazione, non passa di qui.
     *
     * È la regola per un commento solo (la scheda, le email che partono dopo); per una richiesta è
     * {@see soloQuelliCheRestanoFraIDestinatari()}, e una prova le confronta su ogni commento.
     *
     * @param  array<int>|null  $destinatari  I palazzi della comunicazione, se chi chiama li ha già.
     */
    public static function restaFraIDestinatari(Commento $commento, Segnalazione|Comunicazione|null $elemento = null, ?array $destinatari = null): bool
    {
        $elemento ??= $commento->commentable;
        if (! $elemento instanceof Comunicazione || $commento->discussione_user_id !== null || $commento->condominio_id === null) {
            return true;
        }

        $destinatari ??= ($elemento->relationLoaded('condomini')
            ? $elemento->condomini->pluck('id')
            : $elemento->condomini()->pluck('condomini.id'))->map(fn ($id) => (int) $id)->all();
        if (in_array((int) $commento->condominio_id, $destinatari, true)) {
            return true;
        }
        if ($destinatari === [] || $commento->user_id === null) {
            return false;
        }

        // Tutte le anagrafiche di chi ha scritto, come nella richiesta: non solo la prima.
        return Anagrafica::query()
            ->where('user_id', $commento->user_id)
            ->whereHas('condomini', fn ($q) => $q->whereIn('condomini.id', $destinatari))
            ->exists();
    }

    /**
     * La stessa regola di {@see restaFraIDestinatari()}, scritta per una richiesta sulla tabella `commenti` ristretta alle
     * comunicazioni: gli elenchi (i contatori, la pagina «Commenti», il compito «Da moderare», chi partecipa per le email). I commenti
     * con una persona passano: la loro discussione la decide {@see soloNelleDiscussioniCheCiSono()}.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $commenti  Una richiesta sulla tabella `commenti`, ristretta alle comunicazioni.
     */
    public static function soloQuelliCheRestanoFraIDestinatari($commenti): void
    {
        $commenti->where(function ($q) {
            $q->whereNotNull('commenti.discussione_user_id')
                ->orWhereNull('commenti.condominio_id')
                // Il palazzo da cui è scritto è ancora fra i destinatari.
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('comunicazione_condominio')
                    ->whereColumn('comunicazione_condominio.comunicazione_id', 'commenti.commentable_id')
                    ->whereColumn('comunicazione_condominio.condominio_id', 'commenti.condominio_id'))
                // Chi l'ha scritto ha ancora un'anagrafica in un palazzo destinatario.
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('anagrafiche')
                    ->join('anagrafica_condominio', 'anagrafica_condominio.anagrafica_id', '=', 'anagrafiche.id')
                    ->join('comunicazione_condominio', 'comunicazione_condominio.condominio_id', '=', 'anagrafica_condominio.condominio_id')
                    ->whereColumn('anagrafiche.user_id', 'commenti.user_id')
                    ->whereColumn('comunicazione_condominio.comunicazione_id', 'commenti.commentable_id'));
        });
    }

    /**
     * I commenti di una discussione che legge chi guarda, con le azioni che le regole gli lasciano fare. Nel pannello, nella
     * discussione unica di una comunicazione a più palazzi, ogni commento porta anche `condomini`: il condominio di chi scrive (decisione 458 (b)).
     * Nella discussione unica ci sono solo i commenti che ci restano ({@see restaFraIDestinatari()}, decisione 459), per chiunque guardi.
     */
    public static function commentiInDiscussione(User $user, Comunicazione $comunicazione, array $discussione, string $porta = 'user'): array
    {
        $query = Commento::query()
            ->where('commentable_type', $comunicazione->getMorphClass())
            ->where('commentable_id', $comunicazione->id)
            ->with(['autore.anagrafica']);

        if ($discussione['tipo'] === 'tutti') {
            // Tutti i commenti senza una persona, di qualunque palazzo (decisione 458), che ci restano (decisione 459): sotto.
            $query->whereNull('discussione_user_id');
        } else {
            $query->where('discussione_user_id', $discussione['id']);
        }

        $destinatari = $discussione['tipo'] === 'tutti'
            ? $comunicazione->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all()
            : [];
        // Il condominio di chi scrive lo vede solo lo studio, e solo quando i palazzi sono più di uno: il portale non lo riceve.
        $conCondominio = $porta === 'admin' && count($destinatari) > 1;
        if ($conCondominio) {
            $query->with(['condominio', 'autore.anagrafica.condomini' => fn ($q) => $q->orderBy('nome')]);
        }

        if (self::puoModerare($user, $comunicazione)) {
            // Chi modera vede anche i nascosti: è da lì che li rimette visibili («Mostra di nuovo», decisione 456).
            $query->whereIn('stato', ['pubblicato', 'in_attesa', 'nascosto']);
        } else {
            self::soloQuelliCheLeggeChiNonModera($query, $user);
        }

        $commenti = $query->orderBy('id', 'asc')->get();
        if ($discussione['tipo'] === 'tutti') {
            // Un palazzo tolto dai destinatari porta via i commenti scritti da lì (decisione 459).
            $commenti = $commenti->filter(fn (Commento $commento) => self::restaFraIDestinatari($commento, $comunicazione, $destinatari));
        }

        return $commenti->map(function (Commento $commento) use ($user, $conCondominio, $destinatari) {
            $riga = [
                'id'         => $commento->id,
                'corpo'      => $commento->corpo,
                'stato'      => $commento->stato,
                'autore'     => [
                    'id'   => $commento->autore?->id,
                    'nome' => $commento->autore?->anagrafica?->nome ?? $commento->autore?->name ?? 'Utente sconosciuto',
                    'name' => $commento->autore?->name ?? 'Utente sconosciuto',
                ],
                'created_at' => $commento->created_at?->toISOString(),
                'modificato' => $commento->modificato_at !== null,
                'modificato_at' => $commento->modificato_at?->toISOString(),
                'azioni'     => [
                    'modifica' => self::puoModificare($user, $commento),
                    'elimina'  => self::puoEliminare($user, $commento),
                    'approva'  => self::puoApprovare($user, $commento),
                    'nascondi' => self::puoNascondere($user, $commento),
                    'mostra'   => self::puoMostrareDiNuovo($user, $commento),
                ],
            ];

            if ($conCondominio) {
                $riga['condomini'] = self::condominiDiChiScrive($commento, $destinatari);
            }

            return $riga;
        })->values()->all();
    }

    /**
     * Il condominio di chi scrive, per lo studio (decisione 458 (b)): per primo quello del commento, poi, per nome, gli altri
     * condomìni destinatari in cui l'autore ha un'anagrafica (chi ne ha più d'uno: «Mario Rossi · Via Roma +1»). Vuoto se il
     * commento non ha un condominio (lo studio senza un'anagrafica fra i destinatari).
     *
     * @param  array<int>  $destinatari
     * @return list<string>
     */
    private static function condominiDiChiScrive(Commento $commento, array $destinatari): array
    {
        $primo = $commento->condominio_id !== null ? $commento->condominio?->nome : null;
        if ($primo === null) {
            return [];
        }

        $altri = ($commento->autore?->anagrafica?->condomini ?? collect())
            ->unique('id')
            ->filter(fn (Condominio $k) => (int) $k->id !== (int) $commento->condominio_id && in_array((int) $k->id, $destinatari, true))
            ->pluck('nome')
            ->all();

        return array_values(array_merge([(string) $primo], array_map('strval', $altri)));
    }
}
