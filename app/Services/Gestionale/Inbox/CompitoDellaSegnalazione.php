<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Helpers\DateHelper;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use App\Support\Segnalazioni\AssegnazioneSegnalazioni;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Il compito dell'Inbox di una segnalazione di guasto: la regola in un posto solo. Ogni segnalazione aperta o in lavorazione ha il suo compito
 * (`meta.type` «segnalazione_guasto», `meta.context.segnalazione_id`, legato alla segnalazione come `eventable`), nell'Action inbox e nell'Inbox del
 * condominio. Nasce con la segnalazione (dallo studio o dal portale del condòmino), si chiude da solo quando la segnalazione si chiude, torna quando la si
 * riapre, sparisce quando la si cancella. Lo chiamano gli eventi del modello (`Segnalazione::booted()`) e la migrazione dei dati per le segnalazioni già
 * aperte: rieseguito non fa doppioni.
 *
 * Il giorno del compito è quello della segnalazione, nel giorno di Roma e scritto come l'agenda scrive le ore: «da quanto aspetta», «aperta il». Il compito
 * tiene in `meta` lo stato dell'approvazione (la prossima azione: «Approva o rifiuta» finché aspetta l'approvazione, poi «Apri la segnalazione») e la priorità
 * (il badge «urgente» o «alta» sulla riga); si aggiornano a ogni salvataggio. Il giorno non si riscrive dopo la nascita: «Ricordamelo» scrive un promemoria a
 * parte ({@see \App\Services\Gestionale\RispostePromemoria::COMPITI_CON_IL_PROMEMORIA_A_PARTE}), e un salvataggio della segnalazione non lo tocca.
 *
 * Il menu del compito ha solo «Ricordamelo»: «Fatto» e «Non serve» nasconderebbero una segnalazione ancora aperta (`ActionInboxController` li rifiuta).
 *
 * **Chi lo legge.** Il compito porta il titolo e la descrizione della segnalazione, e le due Inbox e il numero del menu lo leggono con la regola della pagina
 * delle segnalazioni ({@see chiVede()}, {@see soloQuelliCheVede()}): un compito di una segnalazione che l'utente non può aprire non si mostra e non si conta, e
 * nemmeno quello di una segnalazione che non c'è più (tolta con la chiave esterna del condominio o dell'autore, senza gli eventi del modello). Eliminato un
 * condominio, i compiti delle segnalazioni che se ne sono andate con lui si chiudono ({@see chiudiQuelliSenzaSegnalazione()}).
 */
final class CompitoDellaSegnalazione
{
    public const TIPO = 'segnalazione_guasto';

    /** Gli stati in cui la segnalazione ha il compito aperto. */
    public const APERTE = ['aperta', 'in lavorazione'];

    public const FRASE_SOLO_RICORDAMELO = 'Una segnalazione si chiude dalla sua pagina: qui puoi solo fartela ricordare.';

    /** {@see chiVede()}: tutte le segnalazioni, o nessuna; un intero è l'utente che vede solo quelle che ha scritto. */
    public const TUTTE = true;

    public const NESSUNA = false;

    /**
     * Quali compiti delle segnalazioni vede un utente: la regola della pagina delle segnalazioni del pannello (`SegnalazionePolicy::view`), private comprese.
     * Con «Visualizza segnalazioni» tutte; con «Visualizza proprie segnalazioni» quelle che ha scritto (l'id dell'utente); senza i due permessi, o senza un
     * utente, nessuna. Il valore va nei filtri delle due Inbox e nel numero del menu ({@see soloQuelliCheVede()}).
     */
    public static function chiVede(?User $utente): bool|int
    {
        if ($utente === null) {
            return self::NESSUNA;
        }
        if ($utente->checkPermissionTo(Permission::VIEW_SEGNALAZIONI->value)) {
            return self::TUTTE;
        }

        return $utente->checkPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value) ? (int) $utente->id : self::NESSUNA;
    }

    /**
     * Una lettura dei compiti con solo i compiti delle segnalazioni che l'utente vede ({@see chiVede()}) e che ci sono ancora; gli altri compiti restano tutti.
     * Il compito è di una segnalazione per il tipo (`meta.type` o la colonna `tipo`), e la segnalazione è il suo `eventable`. I compiti da moderare
     * ({@see CompitoDaModerare}) hanno la regola dell'elemento che moderano: quelli delle segnalazioni la stessa di qui, gli altri da `$moderare`
     * ({@see CompitoDaModerare::chiVede()}; senza, nessuno); e tutti solo se chi legge sa fare la loro prossima azione (`$moderare['azioni']`,
     * la 454 del registro delle decisioni).
     *
     * @param  array{comunicazioni?: bool, documenti?: bool, eventi?: bool, azioni?: array<string, list<string>>}|null  $moderare
     */
    public static function soloQuelliCheVede(Builder $query, bool|int $vede, ?array $moderare = null): Builder
    {
        $tipoSql = FasceDeiCompiti::sqlTipo();
        $segnalazione = (new Segnalazione())->getMorphClass();
        // Gli avvisi degli errori (1.12.0-beta.14) hanno la loro regola: solo chi ha «Gestisci impostazioni generali» ({@see CompitoDellErrore}).
        $speciali = [self::TIPO, CompitoDaModerare::TIPO, CompitoDellErrore::TIPO];
        $segnaposti = implode(', ', array_fill(0, count($speciali), '?'));

        return $query->where(function (Builder $q) use ($tipoSql, $vede, $segnalazione, $moderare, $speciali, $segnaposti) {
            // Non è il compito di una segnalazione, né uno da moderare (che ha la sua regola, per tipo di elemento), né l'avviso di un errore.
            $q->where(fn (Builder $altro) => $altro->whereRaw("COALESCE({$tipoSql}, '') NOT IN ({$segnaposti})", $speciali)
                ->where(fn (Builder $colonna) => $colonna->whereNull('eventi.tipo')->orWhereNotIn('eventi.tipo', $speciali)));
            if ($vede !== self::NESSUNA) {
                // O lo è, e la segnalazione c'è e si apre (il compito da moderare di una segnalazione segue la stessa regola, e in più chi legge sa
                // fare la sua prossima azione: la 454 del registro delle decisioni).
                $azioni = $moderare['azioni']['segnalazioni'] ?? [];
                $q->orWhere(fn (Builder $suo) => $suo->where('eventi.eventable_type', $segnalazione)
                    ->whereIn('eventi.eventable_id', fn (Builder $s) => $s->select('segnalazioni.id')->from('segnalazioni')
                        ->when(is_int($vede), fn (Builder $proprie) => $proprie->where('segnalazioni.created_by', $vede)))
                    ->where(fn (Builder $quale) => $quale->whereRaw("COALESCE({$tipoSql}, '') != ?", [CompitoDaModerare::TIPO])
                        ->orWhere(fn (Builder $daModerare) => CompitoDaModerare::soloLeAzioni($daModerare, $azioni))));
            }
            // I compiti da moderare delle comunicazioni, dei documenti e degli eventi che chi legge apre.
            CompitoDaModerare::aggiungiLaRegola($q, $moderare);
            // Gli avvisi degli errori, a chi ha «Gestisci impostazioni generali» (`$moderare['errori']`, da `CompitoDaModerare::chiVede()`).
            CompitoDellErrore::aggiungiLaRegola($q, $moderare);
        });
    }

    /**
     * Chi segue le segnalazioni dei compiti di una pagina (decisioni 486 e 487): per ogni id di segnalazione il nome di chi la segue (null se nessuno),
     * se è chi guarda, e se può ancora riceverla (ha il pannello, vede le segnalazioni e non è sospeso: la regola di {@see AssegnazioneSegnalazioni::puoRicevere()}).
     * Si legge dalla segnalazione, non da una copia nel compito, con due letture per tutta la pagina: le segnalazioni con il nome, e chi fra
     * loro ha il pannello e vede le segnalazioni. Un permesso che a database non c'è vale «non attivo», come nella scheda.
     *
     * @param  iterable<int>  $idSegnalazioni
     * @return array<int, array{nome: ?string, te: bool, attivo: bool}>
     */
    public static function chiLeSegue(iterable $idSegnalazioni, ?int $chiGuarda): array
    {
        $ids = collect($idSegnalazioni)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $righe = DB::table('segnalazioni')->leftJoin('users', 'users.id', '=', 'segnalazioni.assigned_to')
            ->whereIn('segnalazioni.id', $ids)
            ->get(['segnalazioni.id', 'segnalazioni.assigned_to', 'users.name', 'users.suspended_at']);
        $assegnatari = $righe->pluck('assigned_to')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $conIlPannello = [];
        if ($assegnatari !== []) {
            try {
                $conIlPannello = User::permission(Permission::ACCESS_ADMIN_PANEL->value)->permission(Permission::VIEW_SEGNALAZIONI->value)
                    ->whereIn('users.id', $assegnatari)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            } catch (PermissionDoesNotExist) {
                $conIlPannello = [];
            }
        }

        return $righe->mapWithKeys(function ($r) use ($chiGuarda, $conIlPannello) {
            $assegnata = $r->assigned_to !== null ? (int) $r->assigned_to : null;

            return [(int) $r->id => [
                'nome' => $assegnata !== null ? (string) $r->name : null,
                'te' => $assegnata !== null && $assegnata === $chiGuarda,
                'attivo' => $assegnata === null || (in_array($assegnata, $conIlPannello, true) && $r->suspended_at === null),
            ]];
        })->all();
    }

    /**
     * Chiude i compiti aperti delle segnalazioni che non ci sono più: quelle tolte con la chiave esterna, senza gli eventi del modello (il condominio eliminato).
     * Rieseguito non trova niente.
     */
    public static function chiudiQuelliSenzaSegnalazione(): int
    {
        $chiusi = Evento::query()
            ->where('tipo', self::TIPO)
            ->where('is_completed', false)
            ->where(fn ($q) => $q->whereNull('eventable_id')->orWhereNotIn('eventable_id', Segnalazione::query()->select('id')))
            ->update(['is_completed' => true, 'completed_at' => now()]);
        if ($chiusi > 0) {
            InboxService::clearAdminCache();
        }

        return $chiusi;
    }

    /** Il compito della segnalazione, aperto o chiuso; null se non c'è. */
    public function compito(Segnalazione $segnalazione): ?Evento
    {
        return Evento::query()
            ->where('eventable_type', $segnalazione->getMorphClass())
            ->where('eventable_id', $segnalazione->getKey())
            ->where('tipo', EventoTipo::SEGNALAZIONE_GUASTO->value)
            ->orderBy('id')
            ->first();
    }

    /** Il compito com'è la segnalazione adesso: aperto se è aperta o in lavorazione, chiuso se è chiusa; creato se non c'è e serve. */
    public function sincronizza(Segnalazione $segnalazione): void
    {
        $aperta = in_array((string) $segnalazione->stato, self::APERTE, true);
        $compito = $this->compito($segnalazione);
        if ($compito === null && ! $aperta) {
            return;
        }
        if ($compito === null) {
            $this->crea($segnalazione);

            return;
        }

        $meta = is_array($compito->meta) ? $compito->meta : [];
        $nuovo = array_merge($meta, $this->datiDellaSegnalazione($segnalazione));
        $cambia = [
            'title' => $this->titolo($segnalazione),
            'description' => $this->descrizione($segnalazione),
            'priorita' => $segnalazione->prioritaCommento(),
            'meta' => $nuovo,
            'is_completed' => ! $aperta,
            'completed_at' => $aperta ? null : ($compito->completed_at ?? now()),
        ];
        $compito->fill($cambia);
        if ($compito->isDirty()) {
            $compito->save();
            InboxService::clearAdminCache();
        }
        if ($segnalazione->condominio_id !== null) {
            $compito->condomini()->sync([(int) $segnalazione->condominio_id]);
        }
    }

    /** La segnalazione cancellata: il suo compito non ha più una pagina, e sparisce. */
    public function togli(Segnalazione $segnalazione): void
    {
        $compito = $this->compito($segnalazione);
        if ($compito !== null) {
            $compito->delete();
            InboxService::clearAdminCache();
        }
    }

    /** I compiti delle segnalazioni aperte o in lavorazione che non ce l'hanno: per la migrazione dei dati. Rieseguita non trova niente da fare. */
    public function creaPerLeAperte(): int
    {
        $create = 0;
        Segnalazione::query()->whereIn('stato', self::APERTE)->orderBy('id')->chunkById(200, function ($blocco) use (&$create) {
            foreach ($blocco as $segnalazione) {
                if ($this->compito($segnalazione) === null) {
                    $this->crea($segnalazione);
                    $create++;
                }
            }
        });

        return $create;
    }

    private function crea(Segnalazione $segnalazione): Evento
    {
        // Il giorno della segnalazione nel giorno di Roma, come l'agenda scrive le ore.
        $giorno = DateHelper::comeSiLegge(Carbon::parse($segnalazione->created_at ?? now()));

        return InboxService::createTask(
            tipo: EventoTipo::SEGNALAZIONE_GUASTO,
            title: $this->titolo($segnalazione),
            description: $this->descrizione($segnalazione),
            scadenza: $giorno,
            createdByUserId: (int) ($segnalazione->created_by ?? auth()->id() ?? \App\Models\User::query()->orderBy('id')->value('id')),
            condominioId: $segnalazione->condominio_id !== null ? (int) $segnalazione->condominio_id : null,
            context: ['segnalazione_id' => (int) $segnalazione->id],
            actionUrl: route('admin.segnalazioni.show', $segnalazione, false),
            extraMeta: $this->datiDellaSegnalazione($segnalazione),
            eventableType: $segnalazione->getMorphClass(),
            eventableId: (int) $segnalazione->id,
            priorita: $segnalazione->prioritaCommento(),
        );
    }

    /** Ciò che il compito tiene della segnalazione, aggiornato a ogni salvataggio. */
    private function datiDellaSegnalazione(Segnalazione $segnalazione): array
    {
        return [
            'priority' => (string) $segnalazione->priority,
            'is_approved' => (bool) $segnalazione->is_approved,
            'stato_segnalazione' => (string) $segnalazione->stato,
            'condominio_nome' => $segnalazione->condominio_id !== null ? (string) \App\Models\Condominio::whereKey($segnalazione->condominio_id)->value('nome') : null,
        ];
    }

    private function titolo(Segnalazione $segnalazione): string
    {
        return Str::limit($segnalazione->titoloInbox(), 250, '…');
    }

    private function descrizione(Segnalazione $segnalazione): string
    {
        return Str::limit(trim((string) $segnalazione->description), 500, '…');
    }
}
