<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\F24;

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\CategoriaEventoEnum;
use App\Enums\VisibilityStatus;
use App\Helpers\MoneyHelper;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\Inbox\IndirizzoDelCompito;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\PlafondRitenuteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * I promemoria delle ritenute da versare nell'Action inbox e nell'agenda (Coda 718, decisione 675): l'unico punto che li scrive.
 *
 * Fino alla 1.12.0-beta.13 ogni pagamento con la ritenuta scriveva il suo promemoria «F24 Ritenuta» con il 16 del mese dopo, mentre la
 * delega applica il cumulo e le soglie di ogni regime (`PlafondRitenuteService`): un appalto pagato il 10/03/2026 con € 20,00 di
 * ritenuta diceva 16/04 nell'Inbox e 16/06 nella delega. E il promemoria non si chiudeva mai, né al versamento né all'annullamento.
 * Con la decisione, per ogni condominio:
 *
 * 1. **una delega aperta** (in bozza o confermata) → un promemoria, «F24 da versare · ‹condominio›», con il giorno, la cifra e le
 *    ritenute della delega; la chiave è `meta.context.delega_f24_id`, la prossima azione «Apri la delega»;
 * 2. **le ritenute che non stanno in nessuna delega viva** (`GeneraDelegheF24Action::ritenuteDaVersare()`) → **un** promemoria,
 *    «Prepara le deleghe F24 · ‹condominio›», con la prima scadenza del cumulo calcolata come la calcola il cruscotto del condominio
 *    (`raggruppaPerScadenza` sulle ritenute scoperte), la cifra e il numero delle ritenute; la chiave è `meta.context.prepara_deleghe`,
 *    la prossima azione «Prepara l'F24»;
 * 3. **ogni altro promemoria delle ritenute aperto del condominio** si chiude: quelli per pagamento delle versioni di prima, quelli
 *    delle deleghe versate, annullate o stornate, il «Prepara» quando non c'è più niente da preparare.
 *
 * **La bozza rifatta.** «Aggiorna scadenze» cancella le bozze e le rifà da capo, con un id nuovo: il promemoria della bozza cancellata
 * passa alla delega nuova che ne prende il posto (la stessa data e lo stesso regime, o altrimenti lo stesso regime, nell'ordine delle
 * date), così il compito segue la delega e porta con sé le risposte dell'amministratore; quello che non ha una delega nuova si toglie.
 *
 * **«Segna come fatto».** Un promemoria chiuso dall'amministratore non si riapre finché la sua scadenza resta la stessa: si riapre
 * se la scadenza cambia (come «La scadenza è cambiata: il compito è tornato» dei compiti rimandati). Quello che chiude il programma
 * porta `meta.chiuso_dal_programma` e si riapre appena c'è di nuovo qualcosa da fare (lo storno di un versamento rimette le ritenute
 * senza delega). Quando il programma trova che non c'è più niente da fare, anche un promemoria chiuso dall'amministratore diventa
 * «chiuso dal programma»: il suo «fatto» è consumato, e il lavoro che arriva dopo è un compito nuovo.
 *
 * **Idempotente.** Due chiamate di fila non scrivono niente la seconda volta: si salva solo ciò che cambia. Il tipo resta
 * `versamento_ritenuta` (l'Inbox, i conteggi e i filtri non cambiano); il giorno si scrive come l'agenda scrive le ore, alle 09:00.
 *
 * Lo chiamano il listener dei pagamenti (`SyncF24WithPagamento`: registrato, modificato, stornato), «Aggiorna scadenze»
 * (`GeneraDelegheF24Action::esegui()`), il versamento e il suo storno (`ConfermaVersamentoF24Action`, `StornaVersamentoF24Action`),
 * l'annullamento di una delega (`DelegaF24Controller::annulla()`) e la migrazione dei promemoria di prima. Un pagamento non si
 * elimina: si storna.
 *
 * Che cosa non fa: non manda email né notifiche (il listener non ne mandava); non tocca i compiti `f24` del condominio di prova.
 */
final class PromemoriaDelleRitenute
{
    /** Il tipo dei promemoria in `meta.type` (scritto per esteso nei dati: la guardia delle prossime azioni legge i tipi dai sorgenti). */
    public const TIPO = 'versamento_ritenuta';

    /** L'ora dei promemoria, così come si legge: le Inbox leggono il giorno dalla data di `start_time`. */
    public const ORA = '09:00:00';

    public const TITOLO_DELEGA = 'F24 da versare';

    public const TITOLO_PREPARA = 'Prepara le deleghe F24';

    public const AZIONE_DELEGA = 'Apri la delega';

    public const AZIONE_PREPARA = 'Prepara l\'F24';

    public function __construct(private PlafondRitenuteService $plafond) {}

    /** Allinea i promemoria delle ritenute del condominio alle sue deleghe e alle sue ritenute senza delega. */
    public function allinea(Condominio $condominio): void
    {
        $cambiato = DB::transaction(function () use ($condominio) {
            // Una scrittura alla volta per condominio: due pagamenti registrati insieme scriverebbero due «Prepara».
            Condominio::whereKey($condominio->id)->lockForUpdate()->first();

            return $this->allineaNellaTransazione($condominio);
        });

        if ($cambiato) {
            InboxService::clearAdminCache();
        }
    }

    /**
     * Allinea, e un errore va nel registro degli errori senza fermare chi chiama: il versamento, lo storno, l'annullamento e «Aggiorna
     * scadenze» sono già scritti quando si allineano i promemoria, e non devono tornare indietro con un messaggio d'errore per loro.
     *
     * @return bool `true` se ha allineato, `false` se è fallito (l'errore è già nel registro): la migrazione dei promemoria lo usa per non
     *              chiudere quelli di prima nel condominio che non si è allineato
     */
    public function allineaSenzaFermare(Condominio $condominio): bool
    {
        try {
            $this->allinea($condominio);

            return true;
        } catch (\Throwable $e) {
            Log::error("PromemoriaDelleRitenute: promemoria non allineati per il condominio #{$condominio->id}: ".$e->getMessage());

            return false;
        }
    }

    /** @return bool se ha scritto qualcosa */
    private function allineaNellaTransazione(Condominio $condominio): bool
    {
        $categoria = CategoriaEvento::firstOrCreate(
            ['name' => CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value],
            ['description' => 'Auto']
        );
        $scritti = 0;

        $esistenti = $this->promemoriaDelCondominio((int) $condominio->id);
        $perDelega = [];
        $prepara = [];
        $altri = [];
        foreach ($esistenti as $evento) {
            $contesto = is_array($evento->meta['context'] ?? null) ? $evento->meta['context'] : [];
            if (isset($contesto['delega_f24_id'])) {
                $perDelega[(int) $contesto['delega_f24_id']][] = $evento;
            } elseif (($contesto['prepara_deleghe'] ?? false) === true) {
                $prepara[] = $evento;
            } else {
                $altri[] = $evento;
            }
        }

        $aperte = DelegaF24::delCondominio((int) $condominio->id)->aperte()->orderBy('data_scadenza')->orderBy('id')->get();
        $idAperte = $aperte->pluck('id')->map(fn ($id) => (int) $id)->all();
        $esistonoAncora = $perDelega === [] ? [] : array_flip(DelegaF24::whereIn('id', array_keys($perDelega))->pluck('id')->map(fn ($id) => (int) $id)->all());

        // 1. I promemoria delle deleghe: quello della delega aperta resta suo; quello della delega versata, annullata o stornata si
        // chiude; quello della bozza cancellata da «Aggiorna scadenze» aspetta la delega nuova che ne prende il posto.
        $assegnati = [];
        $orfani = collect();
        foreach ($perDelega as $delegaId => $eventi) {
            $tieni = array_shift($eventi);
            foreach ($eventi as $doppio) {
                $doppio->delete();
                $scritti++;
            }
            if (in_array($delegaId, $idAperte, true)) {
                $assegnati[$delegaId] = $tieni;
            } elseif (isset($esistonoAncora[$delegaId])) {
                $scritti += $this->chiudiDalProgramma($tieni);
            } else {
                $orfani->push($tieni);
            }
        }
        $orfani = $orfani->sortBy(fn (Evento $e) => $e->start_time?->format('Y-m-d H:i:s').'|'.str_pad((string) $e->id, 12, '0', STR_PAD_LEFT))->values();
        $senza = $aperte->filter(fn (DelegaF24 $d) => ! isset($assegnati[(int) $d->id]))->values();
        // Prima lo stesso giorno e lo stesso regime, poi lo stesso regime nell'ordine delle date.
        foreach ([true, false] as $stessoGiorno) {
            foreach ($senza as $delega) {
                if (isset($assegnati[(int) $delega->id])) {
                    continue;
                }
                $chiave = $orfani->search(fn (Evento $e) => ($e->meta['plafond'] ?? null) === $delega->plafond?->value
                    && (! $stessoGiorno || $e->start_time?->toDateString() === $delega->data_scadenza?->toDateString()));
                if ($chiave !== false) {
                    $assegnati[(int) $delega->id] = $orfani->pull($chiave);
                }
            }
        }
        foreach ($orfani as $orfano) {
            $orfano->delete();
            $scritti++;
        }

        $numeri = $this->ritenuteDelleDeleghe($idAperte);
        foreach ($aperte as $delega) {
            $scritti += $this->scrivi($assegnati[(int) $delega->id] ?? null, $condominio, $categoria, $this->datiDellaDelega($delega, $condominio, (int) ($numeri[(int) $delega->id] ?? 0)));
        }

        // 2. Il «Prepara le deleghe F24» del condominio, uno solo.
        $tieni = array_shift($prepara);
        foreach ($prepara as $doppio) {
            $doppio->delete();
            $scritti++;
        }
        $scoperte = app(GeneraDelegheF24Action::class)->ritenuteDaVersare($condominio)->filter(fn ($r) => $r['data_pagamento'] !== null)->values();
        if ($scoperte->isEmpty()) {
            if ($tieni !== null) {
                $scritti += $this->chiudiDalProgramma($tieni);
            }
        } else {
            $scritti += $this->scrivi($tieni, $condominio, $categoria, $this->datiDelPrepara($scoperte, $condominio));
        }

        // 3. Gli altri promemoria aperti delle ritenute del condominio: quelli per pagamento delle versioni di prima.
        foreach ($altri as $evento) {
            $scritti += $this->chiudiDalProgramma($evento);
        }

        return $scritti > 0;
    }

    /**
     * I promemoria delle ritenute del condominio che contano: quelli aperti, e quelli delle deleghe e del «Prepara» anche chiusi (per
     * non riaprire ciò che l'amministratore ha chiuso e per far seguire alla delega rifatta il suo compito). I promemoria per pagamento
     * già chiusi delle versioni di prima restano come sono. Si aggiungono i promemoria per pagamento aperti senza legame, del condominio
     * del loro pagamento.
     *
     * @return Collection<int, Evento>
     */
    private function promemoriaDelCondominio(int $condominioId): Collection
    {
        $legati = Evento::query()
            ->where('meta->type', self::TIPO)
            ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))
            ->where(fn ($q) => $q->where('is_completed', false)
                ->orWhereNotNull('meta->context->delega_f24_id')
                ->orWhereNotNull('meta->context->prepara_deleghe'))
            ->get();

        // I promemoria per pagamento aperti senza il legame con il condominio (la migrazione fallita li lascia così): il condominio è
        // quello del loro pagamento, e il primo allineamento riuscito li chiude con gli altri.
        $senzaLegame = Evento::query()
            ->where('meta->type', self::TIPO)
            ->where('is_completed', false)
            ->whereNotNull('meta->context->pagamento_id')
            ->whereNull('meta->context->delega_f24_id')
            ->whereNull('meta->context->prepara_deleghe')
            ->whereDoesntHave('condomini')
            ->get()
            ->filter(fn (Evento $e) => DB::table('pagamenti_fornitori')
                ->where('id', (int) ($e->meta['context']['pagamento_id'] ?? 0))
                ->where('condominio_id', $condominioId)->exists());

        return $legati->concat($senzaLegame)->sortBy('id')->values();
    }

    /**
     * Quante ritenute (pagamenti) porta ogni delega.
     *
     * @param  list<int>  $deleghe
     * @return array<int, int>
     */
    private function ritenuteDelleDeleghe(array $deleghe): array
    {
        if ($deleghe === []) {
            return [];
        }

        return DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->whereIn('righe_f24.delega_f24_id', $deleghe)
            ->groupBy('righe_f24.delega_f24_id')
            ->selectRaw('righe_f24.delega_f24_id as delega, count(distinct riga_f24_pagamento.pagamento_fornitore_id) as quante')
            ->pluck('quante', 'delega')
            ->mapWithKeys(fn ($quante, $delega) => [(int) $delega => (int) $quante])
            ->all();
    }

    /** @return array{giorno: string, titolo: string, descrizione: string, meta: array<string, mixed>} */
    private function datiDellaDelega(DelegaF24 $delega, Condominio $condominio, int $ritenute): array
    {
        $giorno = $delega->data_scadenza->toDateString();
        $importo = (int) $delega->totale_debito;

        return [
            'giorno' => $giorno,
            'titolo' => self::TITOLO_DELEGA.' · '.$condominio->nome,
            'descrizione' => 'La delega F24 del '.self::data($giorno).' da versare: '.self::ritenute($ritenute).', '.MoneyHelper::format($importo).".\n"
                .'Il versamento si registra dalla scheda della delega, in «Ritenute e F24».',
            'meta' => [
                'type' => 'versamento_ritenuta',
                'requires_action' => true,
                'condominio_nome' => $condominio->nome,
                'importo' => $importo,
                'numero_ritenute' => $ritenute,
                'scadenza' => $giorno,
                'plafond' => $delega->plafond?->value,
                'titolo_azione' => self::AZIONE_DELEGA,
                'action_url' => IndirizzoDelCompito::percorso(route('admin.gestionale.f24.show', ['condominio' => $condominio->id, 'delega' => $delega->id], false)),
                'context' => ['delega_f24_id' => (int) $delega->id, 'is_f24' => true],
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $scoperte
     * @return array{giorno: string, titolo: string, descrizione: string, meta: array<string, mixed>}
     */
    private function datiDelPrepara(Collection $scoperte, Condominio $condominio): array
    {
        $gruppi = $this->plafond->raggruppaPerScadenza($scoperte->all());
        $giorno = $gruppi[0]['scadenza']->toDateString();
        $importo = (int) $scoperte->sum('importo');
        $quante = $scoperte->count();

        return [
            'giorno' => $giorno,
            'titolo' => self::TITOLO_PREPARA.' · '.$condominio->nome,
            'descrizione' => ucfirst(self::ritenute($quante)).' senza delega, '.MoneyHelper::format($importo).': '.self::quando($quante, count($gruppi), $giorno).".\n"
                .'«Aggiorna scadenze» in «Ritenute e F24» prepara le deleghe.',
            'meta' => [
                'type' => 'versamento_ritenuta',
                'requires_action' => true,
                'condominio_nome' => $condominio->nome,
                'importo' => $importo,
                'numero_ritenute' => $quante,
                'scadenza' => $giorno,
                'scadenze' => count($gruppi),
                'titolo_azione' => self::AZIONE_PREPARA,
                'action_url' => IndirizzoDelCompito::percorso(route('admin.gestionale.f24.index', ['condominio' => $condominio->id], false)),
                'context' => ['prepara_deleghe' => true, 'is_f24' => true],
            ],
        ];
    }

    /**
     * Scrive il promemoria con i dati dati: lo crea, o aggiorna quello che c'è salvando solo ciò che cambia. Un promemoria chiuso dal
     * programma si riapre; uno chiuso dall'amministratore si riapre solo se il giorno cambia.
     *
     * @param  array{giorno: string, titolo: string, descrizione: string, meta: array<string, mixed>}  $dati
     * @return int 1 se ha scritto, 0 altrimenti
     */
    private function scrivi(?Evento $evento, Condominio $condominio, CategoriaEvento $categoria, array $dati): int
    {
        $inizio = CarbonImmutable::parse($dati['giorno'].' '.self::ORA);

        if ($evento === null) {
            $nuovo = Evento::create([
                'title' => $dati['titolo'],
                'description' => $dati['descrizione'],
                'start_time' => $inizio,
                'end_time' => $inizio->addHour(),
                // Il programma: un promemoria di più pagamenti non ha un autore.
                'created_by' => null,
                'category_id' => $categoria->id,
                'visibility' => VisibilityStatus::HIDDEN->value,
                'is_approved' => true,
                'is_completed' => false,
                'meta' => $dati['meta'],
            ]);
            $nuovo->condomini()->syncWithoutDetaching([$condominio->id]);

            return 1;
        }

        $dalProgramma = (bool) ($evento->meta['chiuso_dal_programma'] ?? false);
        $riapri = $evento->is_completed && (
            $dalProgramma || $evento->start_time?->toDateString() !== $dati['giorno']
        );
        // Le chiavi che altri scrivono nel compito restano; le sue si riscrivono. Un promemoria chiuso dal programma qui si riapre sempre,
        // quindi il segno del programma se ne va.
        $meta = array_merge(array_diff_key(is_array($evento->meta) ? $evento->meta : [], $dati['meta'], ['chiuso_dal_programma' => true]), $dati['meta']);

        $evento->fill([
            'title' => $dati['titolo'],
            'description' => $dati['descrizione'],
            'start_time' => $inizio,
            'end_time' => $inizio->addHour(),
            'category_id' => $evento->category_id ?? $categoria->id,
        ]);
        // Su MySQL la colonna JSON riordina le chiavi: il confronto con ciò che c'è non guarda l'ordine, o ogni allineamento riscriverebbe.
        if (self::ordinato($meta) !== self::ordinato(is_array($evento->meta) ? $evento->meta : [])) {
            $evento->meta = $meta;
        }
        if ($riapri) {
            $evento->is_completed = false;
            $evento->completed_at = null;
        }
        if (! $evento->isDirty()) {
            return 0;
        }
        $evento->save();

        return 1;
    }

    /**
     * Chiude il promemoria come lo chiude il programma (`is_completed`, `completed_at`, `meta.chiuso_dal_programma`). Su un promemoria
     * già chiuso dall'amministratore scrive solo che ora non c'è più niente da fare.
     *
     * @return int 1 se ha scritto, 0 altrimenti
     */
    private function chiudiDalProgramma(Evento $evento): int
    {
        $meta = is_array($evento->meta) ? $evento->meta : [];
        if ($evento->is_completed && ($meta['chiuso_dal_programma'] ?? false) === true) {
            return 0;
        }
        $meta['chiuso_dal_programma'] = true;
        $evento->meta = $meta;
        if (! $evento->is_completed) {
            $evento->is_completed = true;
            $evento->completed_at = now();
        }
        $evento->save();

        return 1;
    }

    /** Le chiavi in ordine, a ogni livello: per confrontare due `meta` senza guardare l'ordine in cui il database le restituisce. */
    private static function ordinato(array $valori): array
    {
        ksort($valori);
        foreach ($valori as $chiave => $valore) {
            if (is_array($valore)) {
                $valori[$chiave] = self::ordinato($valore);
            }
        }

        return $valori;
    }

    private static function data(string $giorno): string
    {
        return CarbonImmutable::parse($giorno)->format('d/m/Y');
    }

    private static function ritenute(int $quante): string
    {
        return $quante === 1 ? '1 ritenuta' : $quante.' ritenute';
    }

    /** Quando si versano le ritenute senza delega, con il cumulo: la data, o la prima di più date. */
    public static function quando(int $quante, int $scadenze, string $giorno): string
    {
        if ($scadenze > 1) {
            return 'con il cumulo la prima si versa entro il '.self::data($giorno);
        }

        return 'con il cumulo si '.($quante === 1 ? 'versa' : 'versano').' entro il '.self::data($giorno);
    }

    /** La riga sotto il titolo nell'Inbox (`TipiDeiCompiti::sottoDaiDati()`), dai dati del promemoria; null per i promemoria di prima. */
    public static function sotto(array $meta): ?string
    {
        $contesto = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        $importo = is_numeric($meta['importo'] ?? null) ? MoneyHelper::format((int) $meta['importo']) : null;
        $quante = (int) ($meta['numero_ritenute'] ?? 0);
        $giorno = is_string($meta['scadenza'] ?? null) ? $meta['scadenza'] : null;

        if (isset($contesto['delega_f24_id'])) {
            return implode(' · ', array_filter([$giorno !== null ? 'Delega del '.self::data($giorno) : 'Delega F24', $quante > 0 ? self::ritenute($quante) : null, $importo]));
        }
        if (($contesto['prepara_deleghe'] ?? false) === true) {
            return implode(' · ', array_filter([self::ritenute($quante).' senza delega', $importo,
                $giorno !== null ? self::quando($quante, (int) ($meta['scadenze'] ?? 1), $giorno) : null]));
        }

        return null;
    }
}
