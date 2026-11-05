<?php

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gli incassi di un condominio visti dalla parte della ricevuta: per ognuno il protocollo, la data del versamento, l'importo, la persona della
 * posizione e chi ha versato, se la persona ha un indirizzo email, l'ultima emissione della ricevuta e l'ultima email (`ultima_email`: quella che «Invia di
 * nuovo» rimanda, con il suo bollo e l'indirizzo a cui va adesso). Serve la pagina delle ricevute, la
 * scelta «tutte le N dei filtri», l'anteprima e l'invio in blocco; legge con poche query anche migliaia di incassi, senza costruire le ricevute.
 *
 * Gli incassi annullati non ci sono: una ricevuta annullata si stampa dal suo incasso, non si cerca qui. Nemmeno gli incassi in cui non è
 * entrato denaro (la rata coperta solo dal credito: una compensazione, non un versamento di cui dare ricevuta), né quelli delle versioni di
 * prima che coprono le rate di più persone senza dire chi ha versato (decisione 204): la ricevuta non saprebbe per conto di chi.
 *
 * ## I filtri
 *
 * `data_da`, `data_a` (la data del versamento), `gestione_id`, `cassa_id`, `persone` (la posizione o chi ha versato: una, alcune, tutte quando è
 * vuoto), `email` (`con`, `senza`: della posizione), `soglia` (`sopra` o `fino` a € 77,47, l'importo versato), `ricevuta` (`mai`, `stampata`, `inviata`, `tutte`). «Mai emessa» vuol dire nessuna stampa,
 * nessun invio partito o in coda: una ricevuta che non è partita torna fra quelle da fare. «Già inviata» vuol dire partita o in coda: una
 * ricevuta in coda è già stata mandata, e un secondo invio prima che la coda giri la manderebbe due volte. Senza `ricevuta` vale «mai»: è il lavoro che
 * l'amministratore fa dopo aver registrato gli incassi.
 */
class ElencoRicevute
{
    public const RICEVUTA_PREDEFINITA = 'mai';

    /** Perché un incasso senza denaro non ha una ricevuta: lo dicono la finestra, la stampa e l'invio. */
    public const MOTIVO_SENZA_VERSAMENTO = 'In questo incasso non è entrato denaro: le rate sono state coperte con il credito. Non c\'è un versamento '
        . 'di cui dare ricevuta; la compensazione si legge nell\'estratto conto della persona.';

    /**
     * Il periodo con cui la pagina delle ricevute si apre, e con cui conta il numero accanto al pulsante «Ricevute»: i versamenti dal primo
     * del mese scorso a oggi, nel calendario dell'amministratore. Dopo l'aggiornamento tutti gli incassi già registrati sono «mai emessa»:
     * senza il periodo si spingerebbe a mandare le ricevute di un anno.
     *
     * @return array{data_da: string, data_a: string}
     */
    public static function periodoDiApertura(): array
    {
        $oggi = now()->setTimezone(config('app.user_timezone', 'Europe/Rome'));

        return ['data_da' => $oggi->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), 'data_a' => $oggi->format('Y-m-d')];
    }

    /** Perché un incasso con le rate di più persone e nessun «Versato da» non ha una ricevuta: lo dicono la finestra, la stampa e l'invio. */
    public static function motivoPiuPersone(array $nomi): string
    {
        return 'Questo incasso copre le rate di più persone (' . implode(', ', $nomi) . ') e non dice chi ha versato: non si può fare una ricevuta sola. '
            . 'Per ciascuna persona c\'è l\'estratto conto.';
    }

    public function __construct(private PersoneDegliIncassi $persone) {}

    /**
     * Le righe dell'elenco, filtrate e in ordine (data del versamento, poi protocollo, dal più recente).
     *
     * @param array<string, mixed> $filtri
     * @param array<int, int>|null $ids solo questi incassi (una selezione esplicita)
     * @return Collection<int, array<string, mixed>>
     */
    public function righe(Condominio $condominio, array $filtri = [], ?array $ids = null): Collection
    {
        $filtri = $this->filtriNormalizzati($filtri);
        $righe = $this->tutte($condominio, $ids, $filtri['data_da'], $filtri['data_a']);

        return $righe->filter(function (array $r) use ($filtri) {
            if ($filtri['data_da'] && $r['data'] < $filtri['data_da']) return false;
            if ($filtri['data_a'] && $r['data'] > $filtri['data_a']) return false;
            if ($filtri['gestione_id'] && $r['gestione_id'] !== $filtri['gestione_id']) return false;
            if ($filtri['cassa_id'] && $r['cassa_id'] !== $filtri['cassa_id']) return false;
            if ($filtri['persone'] !== [] && ! in_array($r['posizione']['id'] ?? null, $filtri['persone'], true)
                && ! in_array($r['versato_da']['id'] ?? null, $filtri['persone'], true)) return false;
            if ($filtri['email'] === 'con' && ! $r['ha_email']) return false;
            if ($filtri['email'] === 'senza' && $r['ha_email']) return false;
            if ($filtri['soglia'] === 'sopra' && ! $r['sopra_soglia']) return false;
            if ($filtri['soglia'] === 'fino' && $r['sopra_soglia']) return false;

            return match ($filtri['ricevuta']) {
                'mai'      => ! $r['emessa'],
                'stampata' => $r['stampata'],
                'inviata'  => $r['inviata'],
                default    => true,
            };
        })->values();
    }

    /**
     * I filtri come li legge l'elenco: tipi giusti, vuoti a null, `ricevuta` a «mai» se manca.
     *
     * @param array<string, mixed> $filtri
     * @return array{data_da: ?string, data_a: ?string, gestione_id: ?int, cassa_id: ?int, persone: array<int, int>, email: ?string, soglia: ?string, ricevuta: string}
     */
    public function filtriNormalizzati(array $filtri): array
    {
        $data = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $intero = fn ($v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

        return [
            'data_da'     => $data($filtri['data_da'] ?? null),
            'data_a'      => $data($filtri['data_a'] ?? null),
            'gestione_id' => $intero($filtri['gestione_id'] ?? null),
            'cassa_id'    => $intero($filtri['cassa_id'] ?? null),
            'persone'     => array_values(array_unique(array_filter(array_map($intero, (array) ($filtri['persone'] ?? []))))),
            'email'       => in_array($filtri['email'] ?? null, ['con', 'senza'], true) ? $filtri['email'] : null,
            'soglia'      => in_array($filtri['soglia'] ?? null, ['sopra', 'fino'], true) ? $filtri['soglia'] : null,
            'ricevuta'    => in_array($filtri['ricevuta'] ?? null, ['mai', 'stampata', 'inviata', 'tutte'], true) ? $filtri['ricevuta'] : self::RICEVUTA_PREDEFINITA,
        ];
    }

    /**
     * Le persone che si possono scegliere nel filtro: chi è la posizione, o ha versato, di almeno un incasso non annullato.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    public function personeDelFiltro(Condominio $condominio): array
    {
        return $this->tutte($condominio)
            ->flatMap(fn (array $r) => array_filter([$r['posizione'], $r['versato_da']]))
            ->unique('id')
            ->sortBy(fn (array $p) => [mb_strtolower($p['nome']), $p['id']])
            ->values()
            ->all();
    }

    /**
     * Quante ricevute mancano, contate come la pagina che si apre: mai emesse (né stampate, né inviate, né in coda), dei versamenti del
     * periodo di apertura, senza gli incassi annullati e quelli senza denaro. Con il giorno da cui conta, perché il numero lo dica.
     *
     * @return array{numero: int, dal: string}
     */
    public function senzaRicevuta(Condominio $condominio): array
    {
        $periodo = self::periodoDiApertura();

        return ['numero' => $this->righe($condominio, ['ricevuta' => 'mai'] + $periodo)->count(), 'dal' => $periodo['data_da']];
    }

    /**
     * Le ricevute mai emesse di incassi registrati dentro il periodo scelto ma con un versamento più vecchio: la pagina si apre sui versamenti
     * del mese scorso, e un incasso registrato oggi per un versamento di due mesi fa non ci sarebbe. Il numero e il giorno del versamento più
     * vecchio, perché l'amministratore possa includerle. Solo con il filtro «mai emessa» e un periodo.
     *
     * @param array<string, mixed> $filtri
     * @return array{numero: int, dal: string}|null
     */
    public function registratiDiRecente(Condominio $condominio, array $filtri): ?array
    {
        $f = $this->filtriNormalizzati($filtri);
        if ($f['ricevuta'] !== 'mai' || $f['data_da'] === null) {
            return null;
        }

        $righe = $this->righe($condominio, array_merge($f, ['data_da' => null, 'data_a' => null]))
            ->filter(fn (array $r) => $r['data'] !== null && $r['data'] < $f['data_da'] && $r['registrato_il'] !== null && $r['registrato_il'] >= $f['data_da']);

        return $righe->isEmpty() ? null : ['numero' => $righe->count(), 'dal' => (string) $righe->min('data')];
    }

    /**
     * Degli incassi chiesti, quelli che l'elenco non ha e perché: annullati, senza denaro o con le rate di più persone. Gli identificativi sono
     * già controllati (`EmissioneRicevute::incassi()`): tutti incassi di rate di questo condominio.
     *
     * @param array<int, int> $ids
     * @return array{annullati: int, senza_versamento: int, piu_persone: int}
     */
    public function esclusi(Condominio $condominio, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $inElenco = $this->tutte($condominio, $ids)->pluck('id')->all();
        $fuori = array_values(array_diff($ids, $inElenco));
        if ($fuori === []) {
            return ['annullati' => 0, 'senza_versamento' => 0, 'piu_persone' => 0];
        }

        $annullati = ScritturaContabile::whereIn('id', $fuori)->where('condominio_id', $condominio->id)->where('stato', 'annullata')->pluck('id')->all();
        $resto = array_values(array_diff($fuori, $annullati));
        $piuPersone = 0;
        if ($resto !== []) {
            $denaro = DB::table('righe_scritture')->whereIn('scrittura_id', $resto)->where('tipo_riga', 'dare')->whereNotNull('cassa_id')
                ->groupBy('scrittura_id')->selectRaw('scrittura_id, SUM(importo) as importo')->pluck('importo', 'scrittura_id');
            $persone = $this->persone->di($resto);
            $piuPersone = count(array_filter($resto, fn (int $id) => (int) ($denaro[$id] ?? 0) > 0 && $persone[$id]['piu_persone'] !== []));
        }

        return ['annullati' => count($annullati), 'senza_versamento' => count($resto) - $piuPersone, 'piu_persone' => $piuPersone];
    }

    /**
     * @param array<int, int>|null $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function tutte(Condominio $condominio, ?array $ids = null, ?string $dal = null, ?string $al = null): Collection
    {
        $incassi = ScritturaContabile::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->where('stato', '!=', 'annullata')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids === [] ? [0] : $ids))
            ->when($dal !== null, fn ($q) => $q->whereDate('data_competenza', '>=', $dal))
            ->when($al !== null, fn ($q) => $q->whereDate('data_competenza', '<=', $al))
            ->orderByDesc('data_competenza')
            ->orderByDesc('numero_protocollo')
            ->orderByDesc('id')
            ->get(['id', 'numero_protocollo', 'data_competenza', 'gestione_id', 'created_at']);

        if ($incassi->isEmpty()) {
            return collect();
        }

        $tuttiIds = $incassi->pluck('id')->all();
        $cassa = [];
        $emissioni = collect();
        foreach (array_chunk($tuttiIds, 500) as $blocco) {
            foreach (DB::table('righe_scritture')->whereIn('scrittura_id', $blocco)->where('tipo_riga', 'dare')->whereNotNull('cassa_id')
                ->groupBy('scrittura_id')->selectRaw('scrittura_id, SUM(importo) as importo, MIN(cassa_id) as cassa_id')->get() as $r) {
                $cassa[(int) $r->scrittura_id] = ['importo' => (int) $r->importo, 'cassa_id' => (int) $r->cassa_id];
            }
            $emissioni = $emissioni->merge(RegistroInvio::query()
                ->where('documentabile_type', ScritturaContabile::class)
                ->whereIn('documentabile_id', $blocco)
                ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
                ->orderBy('id')
                ->get(['id', 'documentabile_id', 'anagrafica_id', 'canale', 'stato', 'destinatario', 'errore', 'emesso_il', 'dati->bollo as bollo', 'dati->modo_bollo as modo_bollo',
                    'dati->tipo_indirizzo as tipo_indirizzo']));
        }
        $emissioni = $emissioni->groupBy('documentabile_id');

        $persone = $this->persone->di($tuttiIds);
        // Le persone delle righe, e quelle a cui è andata l'ultima email di ogni incasso: «Invia di nuovo» manda al loro indirizzo di oggi.
        $ultimeEmail = $emissioni->map(fn (Collection $sue) => $sue->last(fn ($e) => $e->canale === RegistroInvio::CANALE_EMAIL))->filter();
        $anagrafiche = Anagrafica::whereIn('id', collect($persone)->flatMap(fn ($p) => array_filter([$p['posizione'], $p['versato_da']]))->merge($ultimeEmail->pluck('anagrafica_id'))->filter()->unique()->values())
            ->get(['id', 'nome', 'email', 'email_secondaria', 'pec'])
            ->keyBy('id');
        $persona = fn (?int $id) => $id && isset($anagrafiche[$id]) ? ['id' => $id, 'nome' => $anagrafiche[$id]->nome] : null;

        // Un incasso in cui non è entrato denaro non ha una ricevuta di pagamento, e nemmeno uno che copre le rate di più persone senza dire
        // chi ha versato.
        $incassi = $incassi->filter(fn (ScritturaContabile $i) => ($cassa[$i->id]['importo'] ?? 0) > 0 && $persone[$i->id]['piu_persone'] === [])->values();

        return $incassi->map(function (ScritturaContabile $i) use ($cassa, $emissioni, $ultimeEmail, $persone, $anagrafiche, $persona) {
            $p = $persone[$i->id];
            $posizione = $p['posizione'] ? ($anagrafiche[$p['posizione']] ?? null) : null;
            /** @var Collection $sue */
            $sue = $emissioni->get($i->id, collect());
            $ultima = $sue->last();
            $ultimaEmail = $ultimeEmail->get($i->id);
            $importo = $cassa[$i->id]['importo'] ?? 0;

            return [
                'id'              => $i->id,
                'numero'          => $i->numero_protocollo,
                'data'            => $i->data_competenza?->format('Y-m-d'),
                'registrato_il'   => $i->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
                'gestione_id'     => $i->gestione_id ? (int) $i->gestione_id : null,
                'cassa_id'        => $cassa[$i->id]['cassa_id'] ?? null,
                'importo'         => $importo,
                'sopra_soglia'    => RicevutaIncasso::sopraSoglia($importo),
                'posizione'       => $persona($p['posizione']),
                'versato_da'      => $persona($p['versato_da']),
                'ha_email'        => $posizione !== null && (filled($posizione->email) || filled($posizione->email_secondaria)),
                'emessa'          => $sue->contains(fn ($e) => in_array($e->stato, RegistroInvio::STATI_EMESSI, true)),
                'stampata'        => $sue->contains(fn ($e) => $e->canale === RegistroInvio::CANALE_STAMPA),
                'inviata'         => $sue->contains(fn ($e) => $e->canale === RegistroInvio::CANALE_EMAIL
                    && in_array($e->stato, [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_IN_CODA], true)),
                'ultima'          => $ultima ? self::emissione($ultima) : null,
                'ultima_email_id' => $ultimaEmail?->id,
                // L'email che «Invia di nuovo» rimanda: con il suo bollo e l'indirizzo a cui va adesso, perché la conferma dica quello che succede.
                'ultima_email'    => $ultimaEmail ? self::emissione($ultimaEmail) + ['manda_a' => EmissioneRicevute::indirizzoDiNuovo(
                    (string) ($ultimaEmail->tipo_indirizzo ?? 'altro'), $ultimaEmail->destinatario, $anagrafiche->get($ultimaEmail->anagrafica_id), false)] : null,
            ];
        })->values();
    }

    /**
     * Un'emissione come la mostrano l'elenco e la pagina dell'incasso: il canale, lo stato, il giorno e l'ora nel calendario
     * dell'amministratore, a chi, e perché non è partita: la frase che leggono tutti, e l'errore intero del server solo per chi gestisce le
     * impostazioni generali ({@see ErroreDiPosta}).
     *
     * @return array{id: int, canale: string, stato: string, quando: ?string, destinatario: ?string, errore: ?string, errore_completo: ?string, bollo: ?string, modo_bollo: ?string}
     */
    public static function emissione(RegistroInvio $e): array
    {
        return [
            'id'           => $e->id,
            'canale'       => $e->canale,
            'stato'        => $e->stato,
            'quando'       => $e->emesso_il?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d H:i'),
            'destinatario' => $e->destinatario,
            'errore'       => ErroreDiPosta::frase($e->errore),
            'errore_completo' => ErroreDiPosta::completoPerChiGuarda($e->errore),
            // Lo stato del bollo scelto in quell'emissione, e come è stato assolto: la traccia che il registro tiene, anche a video.
            'bollo'        => self::scelta($e, 'bollo'),
            'modo_bollo'   => self::scelta($e, 'modo_bollo'),
        ];
    }

    /** Una scelta dell'emissione: dalla colonna estratta dall'elenco (`dati->…`) o, se la riga è intera, da `dati`. */
    private static function scelta(RegistroInvio $e, string $chiave): ?string
    {
        $attributi = $e->getAttributes();
        $valore = array_key_exists($chiave, $attributi) ? $attributi[$chiave] : (array_key_exists('dati', $attributi) ? ($e->dati[$chiave] ?? null) : null);

        return is_string($valore) && $valore !== '' ? $valore : null;
    }
}
