<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as CollezioneDiEventi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'elenco dei compiti dell'Action inbox: i compiti aperti ({@see Evento::scopeCompitiAperti()}) con i filtri dell'indirizzo, che si sommano: il condominio,
 * il tipo, la ricerca («Cerca un compito, un condominio, una persona»: il titolo, la descrizione, il nome del condominio, il nome della persona) e la data (una
 * scheda o una barra del grafico, con la regola di {@see FasceDeiCompiti}).
 *
 * L'ordine: prima gli scaduti, poi le segnalazioni aperte, i bonifici da verificare, gli altri; dentro ogni gruppo i più vecchi prima (di serie), i più
 * recenti prima, o per condominio.
 *
 * I compiti delle segnalazioni sono solo quelli che chi legge può aprire, con la regola della pagina delle segnalazioni
 * ({@see CompitoDellaSegnalazione::soloQuelliCheVede()}): la regola viene da chi legge (`filtri()`), mai dall'indirizzo, e vale per l'elenco, l'anteprima, la
 * ricerca e i numeri.
 *
 * Due forme. Le schede «Scaduti» e «In arrivo», e «Tutti» quando c'è un gruppo solo, mostrano l'elenco 15 alla volta («Mostra altri 15»). «Tutti» con più
 * gruppi mostra l'anteprima di ogni gruppo con la regola delle 8 (fino a 8 righe tutte, oltre le prime 5 e «e altri N»), e il clic su «e altri N» apre la
 * scheda del gruppo. I numeri delle schede e dei gruppi vengono da una lettura contata per fascia, con gli stessi filtri: mai dalla pagina caricata.
 */
final class ElencoDellInbox
{
    public const PER_PAGINA = 15;

    public const TUTTE_FINO_A = 8;

    public const MOSTRATE_OLTRE = 5;

    /** Gli ordini dell'elenco: la chiave nell'indirizzo; il nome lo scrive la pagina. */
    public const ORDINI = ['vecchi', 'recenti', 'condominio'];

    /** La ricerca più lunga che si legge. */
    public const CERCA_FINO_A = 100;

    public function __construct(private readonly FasceDeiCompiti $fasce) {}

    /**
     * I filtri dell'indirizzo, letti: un valore che non c'è non filtra. Le segnalazioni che si vedono vengono da chi legge ({@see CompitoDellaSegnalazione::chiVede()}).
     *
     * @return array{quando: ?string, tipo: ?string, cerca: ?string, ordine: string, condominio_id: ?int, segnalazioni: bool|int}
     */
    public static function filtri(array $dati, ?User $chiLegge): array
    {
        $quando = is_string($dati['quando'] ?? null) && array_key_exists($dati['quando'], FasceDeiCompiti::QUANDO) ? $dati['quando'] : null;
        $tipo = is_string($dati['tipo'] ?? null) && array_key_exists($dati['tipo'], TipiDeiCompiti::TIPI) ? TipiDeiCompiti::canonico($dati['tipo']) : null;
        $cerca = is_string($dati['cerca'] ?? null) ? mb_substr(trim($dati['cerca']), 0, self::CERCA_FINO_A) : '';
        $ordine = is_string($dati['ordine'] ?? null) && in_array($dati['ordine'], self::ORDINI, true) ? $dati['ordine'] : 'vecchi';
        $condominioId = filter_var($dati['condominio_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        return ['quando' => $quando, 'tipo' => $tipo, 'cerca' => $cerca === '' ? null : $cerca, 'ordine' => $ordine, 'condominio_id' => $condominioId,
            'segnalazioni' => CompitoDellaSegnalazione::chiVede($chiLegge)];
    }

    /** I compiti aperti con il condominio, il tipo e la ricerca; con `$conLaData` anche la data. */
    public function query(array $filtri, bool $conLaData = true)
    {
        $tipoSql = FasceDeiCompiti::sqlTipo();
        $query = Evento::query()
            ->compitiAperti()
            ->when($filtri['condominio_id'] !== null, fn ($q) => $q->whereHas('condomini', fn ($c) => $c->where('condomini.id', $filtri['condominio_id'])))
            // Il tipo (con i suoi sinonimi) con la stessa espressione della lettura aggregata: le righe e i numeri dicono le stesse cose.
            ->when($filtri['tipo'] !== null, function ($q) use ($tipoSql, $filtri) {
                $famiglia = TipiDeiCompiti::famiglia($filtri['tipo']);
                $q->whereRaw("{$tipoSql} IN (" . implode(', ', array_fill(0, count($famiglia), '?')) . ')', $famiglia);
            });
        // Le segnalazioni che chi legge non apre non ci sono, e nemmeno quelle che non ci sono più; senza la regola nei filtri, nessuna.
        CompitoDellaSegnalazione::soloQuelliCheVede($query, $filtri['segnalazioni'] ?? CompitoDellaSegnalazione::NESSUNA);
        if ($filtri['cerca'] !== null) {
            $this->cerca($query, $filtri['cerca']);
        }
        if ($conLaData && $filtri['quando'] !== null) {
            $this->fasce->filtraQuando($query, $tipoSql, $filtri['quando']);
        }

        return $query;
    }

    /**
     * Quanti compiti per fascia, con il condominio, il tipo e la ricerca (non la data: i numeri delle schede valgono per tutta la scheda).
     *
     * @return array<string, int>
     */
    public function perFascia(array $filtri): array
    {
        [$fasciaSql, $valori] = $this->fasce->sqlFascia(FasceDeiCompiti::sqlTipo());

        return $this->query($filtri, false)->toBase()
            ->selectRaw("{$fasciaSql} as fascia_compito, COUNT(*) as quanti", $valori)
            ->groupByRaw('fascia_compito')
            ->pluck('quanti', 'fascia_compito')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Il numero di ogni scheda: «Tutti» conta anche le segnalazioni e i bonifici, che non sono né scaduti né in arrivo.
     *
     * @return array{tutti: int, scaduti: int, in_arrivo: int}
     */
    public static function schede(array $perFascia): array
    {
        $somma = fn (array $fasce) => array_sum(array_intersect_key($perFascia, array_flip($fasce)));

        return ['tutti' => array_sum($perFascia), 'scaduti' => $somma(FasceDeiCompiti::SCADUTE), 'in_arrivo' => $somma(FasceDeiCompiti::IN_ARRIVO)];
    }

    /**
     * Il numero di ogni gruppo con tutti i filtri, data compresa.
     *
     * @return array{scaduti: int, segnalazioni: int, bonifici: int, avanti: int}
     */
    public static function gruppi(array $perFascia, ?string $quando): array
    {
        $gruppi = array_fill_keys(FasceDeiCompiti::GRUPPI, 0);
        $fasce = $quando === null ? null : (FasceDeiCompiti::QUANDO[$quando] ?? null);
        foreach ($perFascia as $fascia => $n) {
            if ($fasce === null || in_array($fascia, $fasce, true)) {
                $gruppi[FasceDeiCompiti::gruppo((string) $fascia)] += $n;
            }
        }

        return $gruppi;
    }

    /** La forma dell'elenco: l'anteprima dei gruppi con «Tutti» e più di un gruppo; altrimenti l'elenco 15 alla volta. */
    public static function modo(?string $quando, array $gruppi): string
    {
        return $quando === null && count(array_filter($gruppi)) > 1 ? 'anteprima' : 'elenco';
    }

    /** L'elenco 15 alla volta, nell'ordine scelto. */
    public function pagina(array $filtri): LengthAwarePaginator
    {
        $pagina = $this->nellOrdineScelto($this->query($filtri), $filtri['ordine'])->paginate(self::PER_PAGINA)->withQueryString();
        $righe = $this->righe($pagina->getCollection());

        return $pagina->setCollection($righe);
    }

    /**
     * L'anteprima di ogni gruppo con almeno un compito: fino a 8 tutti, oltre i primi 5; quanti restano li dice la pagina («e altri N»).
     *
     * @return list<array{gruppo: string, numero: int, righe: list<array<string, mixed>>}>
     */
    public function anteprima(array $filtri, array $gruppi): array
    {
        $tipoSql = FasceDeiCompiti::sqlTipo();
        $esito = [];
        foreach (FasceDeiCompiti::GRUPPI as $gruppo) {
            $numero = $gruppi[$gruppo] ?? 0;
            if ($numero === 0) {
                continue;
            }
            $query = $this->query($filtri, false);
            match ($gruppo) {
                'bonifici' => $query->whereRaw("{$tipoSql} = ?", [FasceDeiCompiti::BONIFICO]),
                'segnalazioni' => $query->whereRaw("{$tipoSql} = ?", [FasceDeiCompiti::SEGNALAZIONE]),
                'scaduti' => $this->fasce->filtraQuando($query, $tipoSql, 'scaduti'),
                default => $this->fasce->filtraQuando($query, $tipoSql, 'in_arrivo'),
            };
            $quante = $numero <= self::TUTTE_FINO_A ? $numero : self::MOSTRATE_OLTRE;
            $esito[] = ['gruppo' => $gruppo, 'numero' => $numero, 'righe' => $this->righe($this->nellOrdineScelto($query, $filtri['ordine'])->limit($quante)->get())->all()];
        }

        return $esito;
    }

    /**
     * L'ordine: i gruppi, poi i più vecchi (di serie), i più recenti o il condominio; a pari giorno le segnalazioni urgenti, poi quelle con priorità alta;
     * poi l'ora e l'id.
     */
    private function nellOrdineScelto($query, string $ordine)
    {
        [$gruppi, $valori] = $this->fasce->sqlOrdineDeiGruppi(FasceDeiCompiti::sqlTipo());
        $verso = $ordine === 'recenti' ? 'desc' : 'asc';
        $query->orderByRaw($gruppi, $valori);
        if ($ordine === 'condominio') {
            // Il nome del primo condominio del compito; i compiti senza condominio («Generale») per primi.
            $query->orderByRaw('(SELECT MIN(c.nome) FROM condomini c JOIN condominio_evento ce ON ce.condominio_id = c.id WHERE ce.evento_id = eventi.id)');
        }

        return $query->orderByRaw("DATE(eventi.start_time) {$verso}")
            ->orderByRaw('(CASE ' . FasceDeiCompiti::sqlPriorita() . " WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 ELSE 2 END)")
            ->orderBy('eventi.start_time', $verso)->orderBy('eventi.id', $verso);
    }

    /** La ricerca nel titolo, nella descrizione, nel nome del condominio e della persona; «%» e «_» si cercano come sono. */
    private function cerca($query, string $cerca): void
    {
        $simile = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $cerca) . '%';
        $persona = DB::connection()->getDriverName() === 'sqlite'
            ? "JSON_EXTRACT(eventi.meta, '$.context.anagrafica_id')"
            : "JSON_UNQUOTE(JSON_EXTRACT(eventi.meta, '$.context.anagrafica_id'))";
        $query->where(fn (Builder $q) => $q
            ->whereRaw("eventi.title LIKE ? ESCAPE '!'", [$simile])
            ->orWhereRaw("eventi.description LIKE ? ESCAPE '!'", [$simile])
            ->orWhereHas('condomini', fn ($c) => $c->whereRaw("condomini.nome LIKE ? ESCAPE '!'", [$simile]))
            ->orWhereHas('anagrafiche', fn ($a) => $a->whereRaw("anagrafiche.nome LIKE ? ESCAPE '!'", [$simile]))
            ->orWhereRaw("{$persona} IN (SELECT a.id FROM anagrafiche a WHERE a.nome LIKE ? ESCAPE '!')", [$simile]));
    }

    /**
     * Le righe dell'elenco: il titolo a video, la riga sotto (e se è la prima riga della descrizione), il giorno, la fascia e il gruppo, il condominio, la
     * prossima azione e le risposte. Le risposte e i nomi delle persone con una lettura sola ciascuno.
     *
     * @param  CollezioneDiEventi<int, Evento>  $compiti
     * @return Collection<int, array<string, mixed>>
     */
    public function righe(CollezioneDiEventi $compiti): Collection
    {
        $compiti->loadMissing(['condomini:id,nome', 'anagrafiche:id,nome']);
        $risposte = RispostePromemoria::perLeRighe($compiti);
        $daCercare = $compiti->filter(fn ($t) => $t->anagrafiche->isEmpty() && ! empty($t->meta['context']['anagrafica_id']))
            ->map(fn ($t) => (int) $t->meta['context']['anagrafica_id'])->unique()->values()->all();
        $persone = $daCercare === [] ? collect() : Anagrafica::whereIn('id', $daCercare)->pluck('nome', 'id');

        return $compiti->map(function (Evento $task) use ($risposte, $persone) {
            $condominio = $task->condomini->sortBy('id')->first();
            $persona = $task->anagrafiche->first()?->nome ?? $persone[(int) ($task->meta['context']['anagrafica_id'] ?? 0)] ?? null;
            $tipo = $task->meta['type'] ?? $task->tipo?->value;
            $giorno = $task->start_time->format('Y-m-d');
            $fascia = $this->fasce->fascia($giorno, $tipo);
            $daiDati = TipiDeiCompiti::sottoDaiDati($tipo, $task, $persona);
            $sotto = $daiDati !== '' ? $daiDati : TipiDeiCompiti::primaRigaDellaDescrizione($task->description);

            return [
                'id' => $task->id,
                'title' => $task->title,
                'titolo' => TipiDeiCompiti::titolo((string) $task->title, [$condominio?->nome, $task->meta['condominio_nome'] ?? null]),
                'sotto' => $sotto,
                // La riga sotto è la prima riga della descrizione (il tipo non ha dati da dire): chi mostra anche la descrizione non la scrive due volte.
                'sotto_dalla_descrizione' => $sotto !== '' && $daiDati === '',
                'description' => $task->description,
                'date' => $task->start_time->toISOString(),
                'giorno' => $giorno,
                'giorni' => $this->fasce->giorni($giorno),
                'fascia' => $fascia,
                'gruppo' => FasceDeiCompiti::gruppo($fascia),
                'condominio' => $condominio?->nome ?? RiepilogoDellInbox::GENERALE,
                'condominio_id' => $condominio?->id,
                'type' => $tipo ?? 'generic',
                'azione' => TipiDeiCompiti::azione($tipo, $task),
                // La priorità della segnalazione, per il badge della riga: solo «urgente» e «alta».
                'priorita' => in_array($task->meta['priority'] ?? null, ['urgente', 'alta'], true) ? $task->meta['priority'] : null,
                'context' => [
                    'anagrafica_nome' => $persona,
                    'action_url' => IndirizzoDelCompito::perIlLink($task->meta['action_url'] ?? null),
                    'related_id' => $task->meta['context']['related_event_id'] ?? null,
                ],
            ] + $risposte[$task->id];
        })->values();
    }
}
