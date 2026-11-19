<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Commenti;

use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\CommentoService;
use App\Support\Commenti\RegoleCommenti;
use App\Support\Comunicazioni\ContatoriElenco;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * La pagina «Commenti» del pannello (Coda 657, decisione 442): quali commenti vede chi guarda, come si disegna una riga, e le
 * azioni in blocco.
 *
 * Le regole non sono qui: ogni permesso e ogni «si può» sono quelli di `RegoleCommenti`, gli stessi della scheda. Le azioni
 * passano dal servizio della scheda (`CommentoService`), così le notifiche sono quelle della scheda, una per commento.
 */
class CommentiPaginaService
{
    public function __construct(private readonly CommentoService $commentoService) {}

    /**
     * I commenti degli elementi che chi guarda apre nel pannello, più i filtri della pagina. Gli eliminati non ci sono
     * (`SoftDeletes`); i nascosti e gli in attesa degli altri solo per chi modera quel tipo, come nella scheda.
     *
     * @param  array<string, mixed>  $filtri
     */
    public function queryPerPagina(User $user, array $filtri = []): Builder
    {
        $morphSegnalazione = (new Segnalazione)->getMorphClass();
        $morphComunicazione = (new Comunicazione)->getMorphClass();

        // La regola di `RegoleCommenti::apreSchedaPannello`, scritta per la query: la segnalazione la apre chi vede tutte le
        // segnalazioni, o chi vede le proprie e l'ha scritta; la comunicazione la apre il pannello (`ComunicazionePolicy::show`).
        $vedeSegnalazioni = RegoleCommenti::haPermesso($user, RegoleCommenti::permesso('segnalazioni', 'visualizza'))
            && (RegoleCommenti::haPermesso($user, Permission::VIEW_SEGNALAZIONI->value)
                || RegoleCommenti::haPermesso($user, Permission::VIEW_OWN_SEGNALAZIONI->value));
        $tutteLeSegnalazioni = RegoleCommenti::haPermesso($user, Permission::VIEW_SEGNALAZIONI->value);
        $vedeComunicazioni = RegoleCommenti::haPannello($user)
            && RegoleCommenti::haPermesso($user, RegoleCommenti::permesso('comunicazioni', 'visualizza'));
        // Chi modera un tipo (`RegoleCommenti::puoModerare`: il pannello, «Approva commenti» del tipo, la scheda, che qui è già il
        // perimetro) legge anche gli in attesa degli altri e i nascosti; gli altri leggono ciò che la scheda gli mostra.
        $moderaSegnalazioni = RegoleCommenti::haPannello($user)
            && RegoleCommenti::haPermesso($user, RegoleCommenti::permesso('segnalazioni', 'approva'));
        $moderaComunicazioni = RegoleCommenti::haPannello($user)
            && RegoleCommenti::haPermesso($user, RegoleCommenti::permesso('comunicazioni', 'approva'));

        $query = Commento::query()
            ->with([
                'commentable' => fn (MorphTo $m) => $m->morphWith([
                    Segnalazione::class => ['condominio'],
                    Comunicazione::class => ['condomini', 'anagrafiche', 'createdBy.roles', 'createdBy.permissions'],
                ]),
                'autore.anagrafica',
                // Il condominio di chi scrive nella discussione unica di una comunicazione (decisione 458), per la colonna «Condominio».
                'condominio',
            ])
            ->whereHasMorph('commentable', [Segnalazione::class, Comunicazione::class])
            ->where(function (Builder $visibilita) use ($user, $vedeSegnalazioni, $vedeComunicazioni, $tutteLeSegnalazioni, $moderaSegnalazioni, $moderaComunicazioni, $morphSegnalazione, $morphComunicazione) {
                if (! $vedeSegnalazioni && ! $vedeComunicazioni) {
                    $visibilita->whereRaw('1 = 0');

                    return;
                }

                if ($vedeSegnalazioni) {
                    $visibilita->orWhere(function (Builder $q) use ($user, $tutteLeSegnalazioni, $moderaSegnalazioni, $morphSegnalazione) {
                        $q->where('commentable_type', $morphSegnalazione);
                        if (! $tutteLeSegnalazioni) {
                            $q->whereHasMorph('commentable', [Segnalazione::class], fn (Builder $s) => $s->where('created_by', $user->id));
                        }
                        if (! $moderaSegnalazioni) {
                            RegoleCommenti::soloQuelliCheLeggeChiNonModera($q, $user);
                        }
                    });
                }

                if ($vedeComunicazioni) {
                    $visibilita->orWhere(function (Builder $q) use ($user, $moderaComunicazioni, $morphComunicazione) {
                        $q->where('commentable_type', $morphComunicazione);
                        // Come la scheda e la riga dell'elenco: una persona tolta dai destinatari porta via la sua discussione, un palazzo
                        // tolto i commenti scritti da lì (decisione 459). Le azioni in blocco passano da qui: un commento che non si vede è un rifiutato.
                        RegoleCommenti::soloNelleDiscussioniCheCiSono($q);
                        if (! $moderaComunicazioni) {
                            RegoleCommenti::soloQuelliCheLeggeChiNonModera($q, $user);
                        }
                    });
                }
            });

        if (! empty($filtri['stato']) && in_array($filtri['stato'], ['in_attesa', 'pubblicato', 'nascosto'], true)) {
            $query->where('stato', $filtri['stato']);
        }

        if (! empty($filtri['tipo'])) {
            if ($filtri['tipo'] === 'segnalazioni') {
                $query->where('commentable_type', $morphSegnalazione);
            } elseif ($filtri['tipo'] === 'comunicazioni') {
                $query->where('commentable_type', $morphComunicazione);
            }
        }

        if (! empty($filtri['elemento'])) {
            $query->where('commenti.commentable_id', (int) $filtri['elemento']);
        }

        if (! empty($filtri['condominio_id'])) {
            $condominioId = (int) $filtri['condominio_id'];
            $query->whereHasMorph('commentable', [Segnalazione::class, Comunicazione::class], function (Builder $q, string $type) use ($condominioId) {
                if ($type === Segnalazione::class) {
                    $q->where('condominio_id', $condominioId);
                } else {
                    $q->whereHas('condomini', fn (Builder $c) => $c->where('condomini.id', $condominioId));
                }
            });
        }

        if (! empty($filtri['autore']) && is_string($filtri['autore'])) {
            $autore = trim($filtri['autore']);
            $query->whereHas('autore', function (Builder $u) use ($autore) {
                $u->where(function (Builder $q) use ($autore) {
                    $q->where('name', 'like', "%{$autore}%")
                        ->orWhereHas('anagrafica', fn (Builder $a) => $a->where('nome', 'like', "%{$autore}%"));
                });
            });
        }

        if (! empty($filtri['dal']) && is_string($filtri['dal']) && strtotime($filtri['dal']) !== false) {
            $query->whereDate('created_at', '>=', $filtri['dal']);
        }

        if (! empty($filtri['al']) && is_string($filtri['al']) && strtotime($filtri['al']) !== false) {
            $query->whereDate('created_at', '<=', $filtri['al']);
        }

        return $query;
    }

    /**
     * Le righe della pagina. Le persone delle discussioni si chiedono una volta sola, per tutte le righe.
     *
     * @param  Collection<int, Commento>  $commenti
     * @return array<int, array<string, mixed>>
     */
    public function righe(User $user, Collection $commenti): array
    {
        $persone = $commenti->pluck('discussione_user_id')->filter()->unique()->values()->all();
        $utenti = $persone === []
            ? collect()
            : User::query()->whereIn('id', $persone)->with('anagrafica.condomini:id,nome')->get(['id', 'name']);
        $nomiPersone = $utenti->pluck('name', 'id')->all();
        // I condomìni di ogni persona, per la colonna «Condominio» delle discussioni a persone (decisione 479 (2)).
        $condominiPersone = $utenti->mapWithKeys(fn (User $u) => [
            $u->id => $u->anagrafica?->condomini?->pluck('nome', 'id')->all() ?? [],
        ])->all();
        $autoriConPannello = [];

        return $commenti->map(fn (Commento $commento) => $this->riga($user, $commento, $nomiPersone, $condominiPersone, $autoriConPannello))->values()->all();
    }

    /**
     * @param  array<int, string>  $nomiPersone
     * @param  array<int, array<int, string>>  $condominiPersone
     * @param  array<int, bool>  $autoriConPannello
     * @return array<string, mixed>
     */
    private function riga(User $user, Commento $commento, array $nomiPersone, array $condominiPersone, array &$autoriConPannello): array
    {
        /** @var Segnalazione|Comunicazione $elemento */
        $elemento = $commento->commentable;
        $tipo = RegoleCommenti::tipo($elemento);
        $chiave = RegoleCommenti::chiaveDelCommento($commento, $elemento);

        $discussioneCon = null;
        if ($elemento instanceof Segnalazione) {
            $urlElemento = route('admin.segnalazioni.show', $elemento);
            $condominio = (string) ($elemento->condominio?->nome ?? '');
        } else {
            $urlElemento = route('admin.comunicazioni.show', $elemento);
            // La colonna dice sempre un condominio (decisione 479 (2)): quello di chi scrive (decisione 458), che lo studio senza
            // un'anagrafica fra i destinatari non ha; in una discussione a persone, se il commento non lo porta, i condomìni della persona
            // fra quelli della comunicazione (o tutti i suoi, se la comunicazione non ne ha); nella discussione unica, il palazzo se è uno.
            // La persona della discussione va sotto il titolo, «discussione con …».
            if ($commento->discussione_user_id !== null) {
                $discussioneCon = (string) ($nomiPersone[$commento->discussione_user_id] ?? '');
                $suoi = $condominiPersone[$commento->discussione_user_id] ?? [];
                $fraIDestinatari = array_intersect_key($suoi, $elemento->condomini->pluck('nome', 'id')->all());
                $condominio = $commento->condominio !== null
                    ? (string) $commento->condominio->nome
                    : implode(', ', $fraIDestinatari !== [] ? $fraIDestinatari : $suoi);
            } else {
                $condominio = match (true) {
                    $commento->condominio !== null => (string) $commento->condominio->nome,
                    $elemento->condomini->count() === 1 => (string) $elemento->condomini->first()->nome,
                    default => '',
                };
            }
        }

        $puoRispondere = $this->puoRispondere($user, $elemento, $chiave, $autoriConPannello);

        return [
            'id'             => (int) $commento->id,
            'testo'          => (string) $commento->corpo,
            'tipo'           => $tipo,
            'elemento'       => (string) $elemento->subject,
            'url_elemento'   => $urlElemento,
            'url_commenti'   => $urlElemento . '#commenti',
            'condominio'     => $condominio,
            'discussione_con' => $discussioneCon,
            'autore'         => (string) ($commento->autore?->anagrafica?->nome ?? $commento->autore?->name ?? __('commenti.autore_sconosciuto')),
            // Il giorno e l'ora di Roma, scritti dal server (`Y-m-d H:i`): il browser li mette all'italiana senza ricalcolarli nel suo fuso.
            'creato_il'      => $commento->created_at?->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d H:i'),
            'modificato'     => $commento->modificato_at !== null,
            'stato'          => (string) $commento->stato,
            'puo_rispondere' => $puoRispondere,
            'rispondi'       => $puoRispondere ? $this->doveSiRisponde($elemento, $chiave) : null,
            'puo_modificare' => RegoleCommenti::puoModificare($user, $commento),
            'puo_approvare'  => RegoleCommenti::puoApprovare($user, $commento),
            'puo_nascondere' => RegoleCommenti::puoNascondere($user, $commento),
            'puo_mostrare'   => RegoleCommenti::puoMostrareDiNuovo($user, $commento),
            'puo_eliminare'  => RegoleCommenti::puoEliminare($user, $commento),
        ];
    }

    /**
     * `RegoleCommenti::puoScrivereInDiscussione` per chi ha il pannello, calcolata senza una richiesta per riga: le discussioni
     * dell'elemento si leggono dalle sue relazioni già caricate (`ContatoriElenco::chiaviVisibili`, la stessa regola della
     * scheda). Un test confronta le due strade su ogni commento.
     *
     * @param  array<int, bool>  $autoriConPannello
     */
    private function puoRispondere(User $user, Segnalazione|Comunicazione $elemento, string $chiave, array &$autoriConPannello): bool
    {
        if (! RegoleCommenti::haPermesso($user, RegoleCommenti::permesso($elemento, 'commenta')) || ! (bool) $elemento->can_comment) {
            return false;
        }

        if (! RegoleCommenti::apreSchedaPannello($user, $elemento)) {
            return false;
        }

        if ($elemento instanceof Segnalazione) {
            return $chiave === ('palazzo:' . $elemento->condominio_id);
        }

        if (! RegoleCommenti::haPermesso($user, RegoleCommenti::permesso($elemento, 'visualizza'))) {
            return false;
        }

        return isset(ContatoriElenco::chiaviVisibili($user, $elemento, 'admin', null, $autoriConPannello)[$chiave]);
    }

    /**
     * La rotta e i campi in più con cui scrive il modulo `FormCommento` della finestra «Rispondi»: le stesse della scheda.
     *
     * @return array{url: string, dati: array<string, string>}
     */
    private function doveSiRisponde(Segnalazione|Comunicazione $elemento, string $chiave): array
    {
        return $elemento instanceof Segnalazione
            ? ['url' => route('admin.segnalazioni.commenti.store', $elemento), 'dati' => []]
            : ['url' => route('admin.comunicazioni.commenti.store', $elemento), 'dati' => ['discussione' => $chiave]];
    }

    /**
     * Un'azione su più commenti: a ognuno la regola della scheda e, se la regola concede, la stessa azione della scheda (che manda
     * le sue notifiche, una per commento, come se fossero fatte una alla volta).
     *
     * @param  'approva'|'nascondi'|'mostra'|'elimina'  $azione
     * @param  array<int, int>  $ids
     * @return array{fatti: int, rifiutati: int}
     */
    public function inBlocco(User $user, string $azione, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Fuori dalla pagina (un elemento che chi guarda non apre, un commento eliminato, un id che non c'è) conta fra i rifiutati.
        $commenti = $this->queryPerPagina($user)->whereIn('commenti.id', $ids)->get()->keyBy('id');

        $fatti = 0;
        foreach ($ids as $id) {
            /** @var Commento|null $commento */
            $commento = $commenti->get($id);
            if ($commento === null || ! $this->laRegolaConcede($user, $azione, $commento)) {
                continue;
            }

            try {
                match ($azione) {
                    'approva' => $this->commentoService->approva($commento, $user),
                    'nascondi' => $this->commentoService->modera($commento, $user),
                    'mostra' => $this->commentoService->mostraDiNuovo($commento, $user),
                    'elimina' => $this->commentoService->elimina($commento, $user),
                };
                $fatti++;
            } catch (\Throwable $e) {
                Log::error("Errore in blocco ({$azione}) sul commento ID {$id}: " . $e->getMessage());
            }
        }

        return ['fatti' => $fatti, 'rifiutati' => count($ids) - $fatti];
    }

    private function laRegolaConcede(User $user, string $azione, Commento $commento): bool
    {
        return match ($azione) {
            'approva' => RegoleCommenti::puoApprovare($user, $commento),
            'nascondi' => RegoleCommenti::puoNascondere($user, $commento),
            'mostra' => RegoleCommenti::puoMostrareDiNuovo($user, $commento),
            'elimina' => RegoleCommenti::puoEliminare($user, $commento),
            default => false,
        };
    }
}
