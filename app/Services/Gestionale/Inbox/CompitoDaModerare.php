<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Helpers\DateHelper;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use App\Support\Allegati\RegoleAllegati;
use App\Support\Commenti\RegoleCommenti;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Il compito «da moderare» dell'Action inbox (decisioni 445 e 449): ciò che aspetta un'approvazione, **un compito per elemento**, la regola in un posto solo.
 *
 * - Una segnalazione o una comunicazione con commenti o allegati in attesa ha un compito solo, che dice i numeri veri («2 commenti in attesa», «1 commento e
 *   2 allegati in attesa», «3 allegati in attesa»), letti dal database a ogni cambiamento e mai riscritti da una descrizione. Una comunicazione in attesa lo
 *   dice anche di sé («Comunicazione da approvare, 1 commento in attesa»); la segnalazione che aspetta l'approvazione ha già il suo compito fra le
 *   segnalazioni aperte ({@see CompitoDellaSegnalazione}), e qui conta solo ciò che le sta attorno.
 * - Un documento dell'archivio (senza un proprietario: un allegato non lo è) o un evento dell'agenda in attesa hanno il loro compito («Documento da approvare —
 *   <nome>», «Evento da approvare — <titolo>»).
 *
 * Il compito è legato all'elemento come `eventable` (`meta.type` «da_moderare»), nasce quando arriva la prima cosa in attesa, si aggiorna a ogni cambiamento
 * (un commento o un allegato approvato, nascosto o eliminato, da qualunque pagina: la scheda, la pagina «Commenti», la pagina «Allegati», le azioni in
 * blocco) e **si chiude da solo** quando non resta niente in attesa. Se dopo una risposta («Fatto», «Non serve») arriva qualcosa di nuovo, ne nasce uno nuovo;
 * se non arriva niente di nuovo, no. Nessun elemento ha due compiti di moderazione aperti: i compiti di prima («Nuovo commento — …», «Allegati da approvare —
 * …») confluiscono qui e si chiudono.
 *
 * Il giorno è quello del primo commento o allegato ancora in attesa (per l'elemento in attesa, quello in cui è nato): da quanto aspetta, non una scadenza. Un
 * commento aspetta dall'ultima volta che è stato scritto: un pubblicato corretto dal suo autore torna in attesa quel giorno, ed è una cosa nuova anche dopo
 * una risposta (Fase 1-bis della 1.12.0-beta.11). Contano solo i commenti che la scheda mostra: una persona tolta dai destinatari porta via i suoi, un
 * palazzo tolto quelli scritti da lì (la 459 del registro). Il tipo sta a parte in {@see FasceDeiCompiti} come le segnalazioni aperte e i bonifici segnalati.
 *
 * Il compito è di chi ha creato l'elemento, non di chi ha scritto il commento: eliminare quel condòmino (e i suoi commenti, con la chiave esterna) non
 * porta via il compito di ciò che aspetta ancora ({@see ModerazioneNellInbox}).
 *
 * **Chi lo legge**: ogni compito segue la regola dell'elemento che modera ({@see chiVede()}, {@see aggiungiLaRegola()}): chi non apre la segnalazione, la
 * comunicazione, l'archivio o l'agenda non ne legge né ne conta il compito. E lo legge, e lo chiude, solo chi sa fare la sua prossima azione (la 454 del registro delle decisioni):
 * «Modera i commenti» chi modera i commenti del tipo dalla pagina «Commenti», «Modera gli allegati» chi modera gli allegati, «Approva la comunicazione»,
 * «Approva il documento», «Approva l'evento» chi approva quel tipo. Così nessuno trova un compito che non può chiudere, o un collegamento che gli risponde 403.
 * Quando ciò che aspetta cambia (approvato il commento, restano gli allegati) cambia la prossima azione, e con lei chi legge il compito.
 */
final class CompitoDaModerare
{
    public const TIPO = 'da_moderare';

    /** I tipi di compito di prima, che qui confluiscono. */
    public const DI_PRIMA = ['commento', 'allegato'];

    public const PROSSIMA_AZIONE = [
        'commenti' => 'Modera i commenti',
        'allegati' => 'Modera gli allegati',
        'proprio_comunicazione' => 'Approva la comunicazione',
        'proprio_documento' => 'Approva il documento',
        'proprio_evento' => 'Approva l\'evento',
    ];

    /** @var array<string, array{0: class-string<Model>, 1: string}> la regola di visibilità per tipo di elemento: la classe e la tabella. */
    private const ELEMENTI_DELLA_REGOLA = [
        'comunicazioni' => [Comunicazione::class, 'comunicazioni'],
        'documenti' => [Documento::class, 'documenti'],
        'eventi' => [Evento::class, 'eventi'],
    ];

    /**
     * Quali compiti da moderare vede un utente, oltre a quelli delle segnalazioni (che seguono {@see CompitoDellaSegnalazione::chiVede()}): quelli delle
     * comunicazioni con «Visualizza comunicazioni», dei documenti con «Visualizza documenti archivio», degli eventi con «Visualizza scadenze in agenda».
     * In `azioni`, per tipo di elemento, le prossime azioni che sa fare (la 454 del registro delle decisioni): un compito si legge solo se la sua è fra queste.
     *
     * @return array{comunicazioni: bool, documenti: bool, eventi: bool, azioni: array{segnalazioni: list<string>, comunicazioni: list<string>, documenti: list<string>, eventi: list<string>}}
     */
    public static function chiVede(?User $utente): array
    {
        if ($utente === null) {
            return ['comunicazioni' => false, 'documenti' => false, 'eventi' => false, 'azioni' => self::azioniCheSaFare(null)];
        }

        return [
            'comunicazioni' => (bool) $utente->checkPermissionTo(Permission::VIEW_COMUNICAZIONI->value),
            'documenti' => (bool) $utente->checkPermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value),
            'eventi' => (bool) $utente->checkPermissionTo(Permission::VIEW_EVENTS->value),
            'azioni' => self::azioniCheSaFare($utente),
        ];
    }

    /**
     * Le prossime azioni dei compiti da moderare che l'utente sa fare, per tipo di elemento (la 454 del registro delle decisioni). Le regole dei commenti e degli allegati sono
     * le loro (`RegoleCommenti`, `RegoleAllegati`); la comunicazione, il documento e l'evento si approvano dal pannello con il permesso del loro tipo.
     *
     * @return array{segnalazioni: list<string>, comunicazioni: list<string>, documenti: list<string>, eventi: list<string>}
     */
    public static function azioniCheSaFare(?User $utente): array
    {
        $nessuna = ['segnalazioni' => [], 'comunicazioni' => [], 'documenti' => [], 'eventi' => []];
        if ($utente === null || ! RegoleCommenti::haPannello($utente)) {
            return $nessuna;
        }
        $approva = fn (Permission $permesso) => RegoleCommenti::haPermesso($utente, $permesso->value);

        foreach (['segnalazioni', 'comunicazioni'] as $tipo) {
            if (RegoleCommenti::puoModerareDallaPagina($utente, $tipo)) {
                $nessuna[$tipo][] = 'commenti';
            }
            if (RegoleAllegati::puoModerareDallaPagina($utente, $tipo)) {
                $nessuna[$tipo][] = 'allegati';
            }
        }
        if ($approva(Permission::APPROVE_COMUNICAZIONI)) {
            $nessuna['comunicazioni'][] = 'proprio_comunicazione';
        }
        if ($approva(Permission::APPROVE_ARCHIVE_DOCUMENTS)) {
            $nessuna['documenti'][] = 'proprio_documento';
        }
        if ($approva(Permission::APPROVE_EVENTS)) {
            $nessuna['eventi'][] = 'proprio_evento';
        }

        return $nessuna;
    }

    /** La prossima azione del compito in SQL, da `meta.azione` (la scrive {@see dati()}). */
    public static function sqlAzione(): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "JSON_EXTRACT(eventi.meta, '$.\"azione\"')"
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(eventi.meta, '$.\"azione\"')) USING utf8mb4)";
    }

    /**
     * Solo i compiti da moderare la cui prossima azione è fra quelle date (la 454 del registro delle decisioni); con nessuna azione, nessuno.
     *
     * @param  list<string>  $azioni
     */
    public static function soloLeAzioni(Builder $q, array $azioni): void
    {
        if ($azioni === []) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->whereRaw('COALESCE(' . self::sqlAzione() . ", '') IN (" . implode(', ', array_fill(0, count($azioni), '?')) . ')', array_values($azioni));
    }

    /**
     * Aggiunge alla regola dei compiti che si vedono ({@see CompitoDellaSegnalazione::soloQuelliCheVede()}) i compiti da moderare dell'elemento che l'utente
     * apre, se l'elemento c'è ancora. Quelli delle segnalazioni li tiene la regola delle segnalazioni.
     *
     * @param  array{comunicazioni?: bool, documenti?: bool, eventi?: bool}|null  $vede
     */
    public static function aggiungiLaRegola(Builder $q, ?array $vede): void
    {
        $tipoSql = FasceDeiCompiti::sqlTipo();
        foreach (self::ELEMENTI_DELLA_REGOLA as $chiave => [$classe, $tabella]) {
            $azioni = $vede['azioni'][$chiave] ?? [];
            if (! ($vede[$chiave] ?? false) || $azioni === []) {
                continue;
            }
            $morph = (new $classe())->getMorphClass();
            $q->orWhere(function (Builder $suo) use ($tipoSql, $morph, $tabella, $azioni) {
                $suo->whereRaw("COALESCE({$tipoSql}, '') = ?", [self::TIPO])
                    ->where('eventi.eventable_type', $morph)
                    ->whereIn('eventi.eventable_id', fn (Builder $s) => $s->select("{$tabella}.id")->from($tabella));
                self::soloLeAzioni($suo, $azioni);
            });
        }
    }

    /** I compiti da moderare di un elemento (aperti e chiusi), dal più vecchio. */
    private function compiti(Model $elemento)
    {
        return Evento::query()
            ->where('tipo', self::TIPO)
            ->where('eventable_type', $elemento->getMorphClass())
            ->where('eventable_id', $elemento->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Porta il compito dell'elemento a com'è adesso: creato se qualcosa aspetta e non c'è, aggiornato con i numeri veri, chiuso se non aspetta più niente.
     * Rieseguito sulla stessa situazione non cambia niente.
     */
    public function sincronizza(Segnalazione|Comunicazione|Documento|Evento $elemento, ?User $autore = null): void
    {
        $cosa = $this->inAttesa($elemento);
        $totale = $cosa['commenti'] + $cosa['allegati'] + ($cosa['proprio'] ? 1 : 0);
        $compiti = $this->compiti($elemento);
        $aperti = $compiti->where('is_completed', false)->values();
        $cambiato = $this->chiudiQuelliDiPrima($elemento);

        if ($totale === 0) {
            foreach ($aperti as $compito) {
                $this->chiudi($compito);
                $cambiato = true;
            }
            if ($cambiato) {
                InboxService::clearAdminCache();
            }

            return;
        }

        $compito = $aperti->first();
        // Un solo compito aperto per elemento: un secondo, se c'è, si chiude.
        foreach ($aperti->slice(1) as $doppione) {
            $this->chiudi($doppione);
        }

        if ($compito === null) {
            // Chiuso da una risposta e senza niente di nuovo da allora: resta chiuso.
            $ultimo = $compiti->last();
            if ($ultimo !== null && ! ($ultimo->meta['chiuso_dal_programma'] ?? false) && $ultimo->completed_at !== null
                && $cosa['ultimo'] !== null && Carbon::parse($cosa['ultimo'])->lessThanOrEqualTo($ultimo->completed_at)) {
                return;
            }
            $this->crea($elemento, $cosa, $totale, $autore);
        } else {
            $this->aggiorna($compito, $elemento, $cosa, $totale, $autore);
        }
        InboxService::clearAdminCache();
    }

    /** L'elemento che non c'è più: il suo compito sparisce. */
    public function togli(Model $elemento): void
    {
        $tolti = 0;
        foreach ($this->compiti($elemento) as $compito) {
            $compito->delete();
            $tolti++;
        }
        if ($tolti > 0) {
            InboxService::clearAdminCache();
        }
    }

    /**
     * Rimette in regola tutto: per la migrazione dei dati. Chiude i compiti di prima, porta al giorno d'oggi il compito di ogni elemento con qualcosa in
     * attesa, e chiude quelli degli elementi che non ci sono più o che non aspettano più niente. Rieseguita non trova niente da fare.
     *
     * @return array{creati_o_aggiornati: int, chiusi: int}
     */
    public function sincronizzaTutti(): array
    {
        $toccati = 0;
        $chiusi = Evento::query()->whereIn('tipo', self::DI_PRIMA)->where('is_completed', false)
            ->update(['is_completed' => true, 'completed_at' => now()]);

        $segnalazioni = Commento::query()->where('commentable_type', (new Segnalazione())->getMorphClass())->where('stato', 'in_attesa')->pluck('commentable_id')
            ->merge(Documento::query()->where('documentable_type', (new Segnalazione())->getMorphClass())->where('is_approved', false)->pluck('documentable_id'))
            ->unique();
        $comunicazioni = Commento::query()->where('commentable_type', (new Comunicazione())->getMorphClass())->where('stato', 'in_attesa')->pluck('commentable_id')
            ->merge(Documento::query()->where('documentable_type', (new Comunicazione())->getMorphClass())->where('is_approved', false)->pluck('documentable_id'))
            ->merge(Comunicazione::query()->where('is_approved', false)->pluck('id'))
            ->unique();
        $documenti = Documento::query()->whereNull('documentable_type')->where('is_approved', false)->pluck('id');
        $eventi = Evento::query()->where('is_approved', false)->where(fn ($q) => $q->whereNull('visibility')->orWhere('visibility', '!=', 'private'))
            ->get()->filter(fn (Evento $e) => self::eUnEventoDellAgenda($e))->pluck('id');

        $conIlCompito = [];
        foreach ([[Segnalazione::class, $segnalazioni], [Comunicazione::class, $comunicazioni], [Documento::class, $documenti], [Evento::class, $eventi]] as [$classe, $ids]) {
            foreach ($classe::query()->whereIn('id', $ids)->get() as $elemento) {
                $this->sincronizza($elemento);
                $conIlCompito[] = [$elemento->getMorphClass(), $elemento->getKey()];
                $toccati++;
            }
        }

        // I compiti aperti che non servono più: l'elemento non c'è, o non aspetta niente.
        foreach (Evento::query()->where('tipo', self::TIPO)->where('is_completed', false)->get() as $compito) {
            $classe = $compito->eventable_type === null ? null : Model::getActualClassNameForMorph($compito->eventable_type);
            $elemento = $classe !== null && is_subclass_of($classe, Model::class) ? $classe::query()->find($compito->eventable_id) : null;
            if ($elemento === null) {
                $this->chiudi($compito);
                $chiusi++;
            } elseif (! in_array([$compito->eventable_type, (int) $compito->eventable_id], array_map(fn ($c) => [$c[0], (int) $c[1]], $conIlCompito), true)) {
                $this->sincronizza($elemento);
            }
        }
        InboxService::clearAdminCache();

        return ['creati_o_aggiornati' => $toccati, 'chiusi' => $chiusi];
    }

    /** Un evento dell'agenda: né un compito né un promemoria né una scadenza di un condòmino (senza `meta.type`, tipo vuoto o «agenda»). */
    public static function eUnEventoDellAgenda(Evento $evento): bool
    {
        return in_array($evento->tipo, [null, EventoTipo::AGENDA], true)
            && data_get($evento->meta, 'type') === null
            && $evento->eventable_type === null
            && $evento->visibility !== 'private';
    }

    /**
     * Ciò che aspetta, letto dal database: i commenti in attesa e gli allegati non approvati (per una segnalazione o una comunicazione), e se l'elemento
     * stesso aspetta l'approvazione. «Primo» è il giorno da cui aspetta, «ultimo» l'arrivo più recente.
     *
     * @return array{commenti: int, allegati: int, proprio: bool, primo: ?string, ultimo: ?string}
     */
    private function inAttesa(Model $elemento): array
    {
        $commenti = 0;
        $allegati = 0;
        $c = $a = null;
        if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
            // Un commento aspetta dall'ultima volta che è stato scritto (`modificato_at`): un pubblicato corretto torna in attesa quel giorno.
            $c = $this->commentiInAttesa($elemento)
                ->toBase()->selectRaw('COUNT(*) as n, MIN(COALESCE(modificato_at, created_at)) as primo, MAX(COALESCE(modificato_at, created_at)) as ultimo')->first();
            $a = Documento::query()->where('documentable_type', $elemento->getMorphClass())->where('documentable_id', $elemento->getKey())->where('is_approved', false)
                ->toBase()->selectRaw('COUNT(*) as n, MIN(created_at) as primo, MAX(created_at) as ultimo')->first();
            $commenti = (int) ($c->n ?? 0);
            $allegati = (int) ($a->n ?? 0);
        }

        $proprio = match (true) {
            $elemento instanceof Comunicazione => ! (bool) $elemento->is_approved,
            $elemento instanceof Documento => $elemento->documentable_type === null && ! (bool) $elemento->is_approved,
            $elemento instanceof Evento => ! (bool) $elemento->is_approved && self::eUnEventoDellAgenda($elemento),
            default => false,
        };
        // Il primo: dei commenti e degli allegati; l'elemento in attesa da solo, la sua nascita.
        $primi = array_filter([$c?->primo, $a?->primo]);
        if ($primi === [] && $proprio && $elemento->created_at !== null) {
            $primi = [$elemento->created_at->format('Y-m-d H:i:s')];
        }
        $ultimi = array_filter([$c?->ultimo, $a?->ultimo]);

        return ['commenti' => $commenti, 'allegati' => $allegati, 'proprio' => $proprio,
            'primo' => $primi === [] ? null : min($primi), 'ultimo' => $ultimi === [] ? ($primi === [] ? null : max($primi)) : max($ultimi)];
    }

    /**
     * I commenti in attesa dell'elemento che la scheda mostra: per una comunicazione, solo quelli delle discussioni che ci sono ancora, e nella
     * discussione unica solo quelli che ci restano dopo che un palazzo è stato tolto dai destinatari (`RegoleCommenti::soloNelleDiscussioniCheCiSono`).
     */
    private function commentiInAttesa(Segnalazione|Comunicazione $elemento)
    {
        $q = Commento::query()->where('commentable_type', $elemento->getMorphClass())->where('commentable_id', $elemento->getKey())->where('stato', 'in_attesa');
        if ($elemento instanceof Comunicazione) {
            RegoleCommenti::soloNelleDiscussioniCheCiSono($q);
        }

        return $q;
    }

    /** I compiti di prima, aperti, dell'elemento: «Nuovo commento — …» e «Allegati da approvare — …». */
    private function chiudiQuelliDiPrima(Model $elemento): bool
    {
        return Evento::query()->whereIn('tipo', self::DI_PRIMA)->where('is_completed', false)
            ->where('eventable_type', $elemento->getMorphClass())->where('eventable_id', $elemento->getKey())
            ->update(['is_completed' => true, 'completed_at' => now()]) > 0;
    }

    private function chiudi(Evento $compito): void
    {
        $compito->forceFill(['is_completed' => true, 'completed_at' => now(), 'meta' => array_merge((array) $compito->meta, ['chiuso_dal_programma' => true])])->save();
    }

    /** Il tipo dell'elemento nelle parole del compito. */
    private function tipoDellElemento(Model $elemento): string
    {
        return match (true) {
            $elemento instanceof Segnalazione => 'segnalazione',
            $elemento instanceof Comunicazione => 'comunicazione',
            $elemento instanceof Documento => 'documento',
            default => 'evento',
        };
    }

    private function nomeDellElemento(Model $elemento): string
    {
        return (string) match (true) {
            $elemento instanceof Documento => $elemento->name,
            $elemento instanceof Evento => $elemento->title,
            default => $elemento->subject,
        };
    }

    /** @return list<int> */
    private function condomini(Model $elemento): array
    {
        if ($elemento instanceof Segnalazione) {
            return $elemento->condominio_id !== null ? [(int) $elemento->condominio_id] : [];
        }

        $destinatari = $elemento->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all();

        // Una comunicazione: i palazzi dei commenti in attesa (il commento sa in quale palazzo è scritto), solo fra i destinatari di oggi: un palazzo
        // tolto non lega il compito, nemmeno con il commento che resta perché chi l'ha scritto sta anche in un altro. Senza, i palazzi della comunicazione.
        if ($elemento instanceof Comunicazione) {
            $dei = $this->commentiInAttesa($elemento)
                ->whereNotNull('condominio_id')->distinct()->pluck('condominio_id')->map(fn ($id) => (int) $id)->all();
            $dei = array_values(array_intersect($dei, $destinatari));
            if ($dei !== []) {
                return $dei;
            }
        }

        return $destinatari;
    }

    /**
     * Ciò che il compito tiene dell'elemento: i numeri, di che cosa si tratta, la prossima azione e il suo indirizzo (il percorso, con la pagina filtrata
     * sull'elemento). Aggiornato a ogni cambiamento.
     *
     * @param  array{commenti: int, allegati: int, proprio: bool, primo: ?string, ultimo: ?string}  $cosa
     */
    private function dati(Model $elemento, array $cosa): array
    {
        $tipo = $this->tipoDellElemento($elemento);
        $nome = $this->nomeDellElemento($elemento);
        $pluraleDellaPagina = $elemento instanceof Segnalazione ? 'segnalazioni' : 'comunicazioni';
        $filtrata = ['stato' => 'in_attesa', 'tipo' => $pluraleDellaPagina, 'elemento' => $elemento->getKey()];

        if ($cosa['proprio']) {
            $azione = 'proprio_' . $tipo;
            $indirizzo = match ($tipo) {
                'comunicazione' => route('admin.comunicazioni.show', $elemento, false),
                'documento' => route('admin.documenti.index', ['search' => $nome], false),
                default => route('admin.eventi.index', ['search' => $nome], false),
            };
        } elseif ($cosa['commenti'] > 0) {
            $azione = 'commenti';
            $indirizzo = route('admin.commenti.index', $filtrata, false);
        } else {
            $azione = 'allegati';
            $indirizzo = route('admin.allegati.index', $filtrata, false);
        }

        return [
            'elemento_tipo' => $tipo,
            'elemento_nome' => $nome,
            'commenti_in_attesa' => $cosa['commenti'],
            'allegati_in_attesa' => $cosa['allegati'],
            'elemento_in_attesa' => $cosa['proprio'],
            'azione' => $azione,
            'action_url' => IndirizzoDelCompito::percorso($indirizzo),
        ];
    }

    /**
     * Di chi è il compito: di chi ha creato l'elemento, che vive quanto lui (le chiavi esterne di `created_by` sono in cascata), mai di chi ha scritto il
     * commento o caricato l'allegato. Con l'autore, eliminare quel condòmino portava via il compito anche se altro aspettava ancora.
     */
    private function creatore(Model $elemento): int
    {
        return (int) ($elemento->created_by ?? auth()->id() ?? User::query()->orderBy('id')->value('id'));
    }

    private function crea(Model $elemento, array $cosa, int $totale, ?User $autore): Evento
    {
        $dati = $this->dati($elemento, $cosa);
        $creatore = $this->creatore($elemento);
        $condomini = $this->condomini($elemento);

        $compito = InboxService::createTask(
            tipo: EventoTipo::DA_MODERARE,
            title: self::titolo($dati, 'it'),
            description: self::sotto($dati, 'it'),
            scadenza: $this->giorno($cosa['primo']),
            createdByUserId: $creatore,
            condominioId: null,
            context: ['elemento_tipo' => $dati['elemento_tipo'], 'elemento_id' => (int) $elemento->getKey()],
            actionUrl: $dati['action_url'],
            extraMeta: $dati + ['contatore' => $totale, 'ultimo_arrivo' => now()->utc()->format('Y-m-d H:i:s'), 'ultimo_autore' => $autore?->name],
            eventableType: $elemento->getMorphClass(),
            eventableId: (int) $elemento->getKey(),
            priorita: 'normale',
        );
        if ($condomini !== []) {
            $compito->condomini()->sync($condomini);
        }

        return $compito;
    }

    private function aggiorna(Evento $compito, Model $elemento, array $cosa, int $totale, ?User $autore): void
    {
        $dati = $this->dati($elemento, $cosa);
        $meta = is_array($compito->meta) ? $compito->meta : [];
        // Il momento dell'ultimo arrivo, solo quando ciò che aspetta cresce: un'approvazione lo fa scendere, e non è una cosa nuova. Lo legge la regola dei
        // compiti in attesa di «Ricordamelo» (`Evento::scopeCompitiAperti()`).
        $arrivo = $totale > (int) ($meta['contatore'] ?? 0) ? ['ultimo_arrivo' => now()->utc()->format('Y-m-d H:i:s')] : [];
        $compito->fill([
            'created_by' => $this->creatore($elemento),
            'title' => self::titolo($dati, 'it'),
            'description' => self::sotto($dati, 'it'),
            'start_time' => $this->giorno($cosa['primo']),
            'end_time' => $this->giorno($cosa['primo'])->addHour(),
            'meta' => array_merge($meta, $dati, ['contatore' => $totale], $arrivo, $autore !== null ? ['ultimo_autore' => $autore->name] : []),
        ]);
        if ($compito->isDirty()) {
            $compito->save();
        }
        $condomini = $this->condomini($elemento);
        if ($condomini !== []) {
            $compito->condomini()->sync($condomini);
        }
    }

    /** Il giorno da cui aspetta, scritto come l'agenda scrive le ore. */
    private function giorno(?string $primo): Carbon
    {
        return DateHelper::comeSiLegge($primo === null ? Carbon::now() : Carbon::parse($primo));
    }

    /**
     * Il titolo con i numeri veri, nella lingua data (o in quella di chi legge): «2 commenti in attesa», «1 commento e 2 allegati in attesa»; con l'elemento in
     * attesa prima: «Comunicazione da approvare, 1 commento in attesa», «Documento da approvare — Regolamento».
     *
     * @param  array<string, mixed>  $meta
     */
    public static function titolo(array $meta, ?string $lingua = null): string
    {
        $t = fn (string $chiave) => (string) __('inbox_moderazione.' . $chiave, [], $lingua);
        $commenti = (int) ($meta['commenti_in_attesa'] ?? 0);
        $allegati = (int) ($meta['allegati_in_attesa'] ?? 0);
        $parti = array_values(array_filter([
            $commenti > 0 ? trans_choice('inbox_moderazione.commenti', $commenti, [], $lingua) : null,
            $allegati > 0 ? trans_choice('inbox_moderazione.allegati', $allegati, [], $lingua) : null,
        ]));
        $numeri = $parti === [] ? '' : implode(' ' . $t('e') . ' ', $parti) . ' ' . $t('in_attesa');

        if (! ($meta['elemento_in_attesa'] ?? false)) {
            return $numeri;
        }
        $tipo = (string) ($meta['elemento_tipo'] ?? '');
        if ($tipo === 'comunicazione') {
            return $t('comunicazione_da_approvare') . ($numeri === '' ? '' : ', ' . $numeri);
        }

        return $t($tipo . '_da_approvare') . ' — ' . Str::limit((string) ($meta['elemento_nome'] ?? ''), 200, '…');
    }

    /**
     * La riga sotto il titolo: di che elemento si tratta, «Segnalazione «Ascensore fermo»».
     *
     * @param  array<string, mixed>  $meta
     */
    public static function sotto(array $meta, ?string $lingua = null): string
    {
        $tipo = (string) ($meta['elemento_tipo'] ?? '');
        if ($tipo === '') {
            return '';
        }

        return (string) __('inbox_moderazione.sotto.' . $tipo, ['nome' => Str::limit((string) ($meta['elemento_nome'] ?? ''), 200, '…')], $lingua);
    }

    /** La prossima azione scritta nel compito: «Modera i commenti», «Modera gli allegati», «Approva la comunicazione». */
    public static function testoDellAzione(array $meta): ?string
    {
        return self::PROSSIMA_AZIONE[(string) ($meta['azione'] ?? '')] ?? null;
    }
}
