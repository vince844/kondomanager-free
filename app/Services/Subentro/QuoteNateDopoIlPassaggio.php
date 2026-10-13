<?php

namespace App\Services\Subentro;

use App\Models\Gestionale\Subentro;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le quote nate dopo un passaggio che l'annullamento di quel passaggio cambierebbe (1.11.0-beta.51, §6.3).
 *
 * La regola è nata dentro `AnnullaPassaggioAction` (beta.37), che la usa per gli avvisi («ricalcola il piano di nuovo») e per le
 * guardie dell'annullamento («una quota nata dopo è stata emessa»). Dalla .51 la legge anche `PianoRate::passaggiAnnullatiDaSeguire`,
 * per fermare l'emissione di un piano ricalcolato fra la registrazione di un passaggio e il suo annullamento: le sue quote hanno la
 * titolarità di un passaggio che non c'è più, e emesse così andrebbero a giornale a chi non è più titolare. Una regola sola per
 * tutte e due: spostata qui senza cambiarla, l'azione la chiama (una regola che esiste già si chiama, non si rifà).
 *
 * Con un passaggio **già annullato** si guardano le sole quote nate prima dell'annullamento: quelle di un ricalcolo fatto dopo hanno
 * già la titolarità di prima e non sono stantie. «Prima» lo dice il fatto scritto nel registro all'annullamento
 * (`registro.annullamento.quota_max_id`, il più alto id di quota di quel momento: il dato si scrive) in
 * congiunzione con l'ora, perché su MySQL 5.7 un riavvio fa riusare gli id (`created_at <= annullato_il`); per gli annullamenti di
 * prima della .51, senza la chiave, vale la sola ora e nel dubbio la guardia scatta (ricalcolo e annullamento nello stesso secondo).
 */
final class QuoteNateDopoIlPassaggio
{
    /**
     * Le quote nate dopo il passaggio sulle sue unità che l'annullamento cambierebbe: un piano generato o ricalcolato dopo,
     * con la titolarità di dopo. «Nate dopo»: oltre l'ultima quota che esisteva al passaggio (`registro.quota_max_id`,
     * per le quote generate nello stesso secondo) o create dopo di lui (per MySQL 5.7, dove un riavvio fa riusare gli id).
     * «Che l'annullamento cambierebbe»: quelle il cui riparto su quell'unità (`righe_riparto`) ha un ruolo toccato dal
     * passaggio, con una competenza che arriva alla decorrenza o oltre, chiunque ne sia il titolare, anche chi è entrato
     * (caso B6 della beta.38: chi vende e resta usufruttuario è fra gli entrati, ma il consuntivo dell'anno prima,
     * tutto suo, l'annullamento non lo cambia). L'annullamento cambia la
     * titolarità solo da lì in poi e solo su quei ruoli: il consuntivo dell'anno prima, o una spesa del solo proprietario
     * dopo una fine locazione, non cambiano e non contano; la parte dell'inquilino che dopo una fine locazione senza
     * nuovo inquilino va al proprietario (il ripiego della decisione 22) sì. Una quota senza
     * dettaglio del riparto, non nata dal motore, conta solo se è di chi è entrato. Le gemelle del pregresso, che un
     * passaggio scrive con `divisa_da` (questo, o un altro della stessa unità registrato dopo con un rogito anteriore),
     * non sono un piano nuovo e non contano.
     *
     * Con `$pianoId` solo le quote di quel piano.
     *
     * @param Collection<int, Subentro> $famiglia il passaggio e le sue pertinenze
     * @return Collection<int, object> le quote, con `created_at`
     */
    public static function per(Subentro $padre, Collection $famiglia, ?int $pianoId = null): Collection
    {
        $unita = self::unita($famiglia);
        // Le bozze del passaggio non sono nate dopo; un id del registro ripreso da una quota nuova (MySQL 5.7) sì.
        $voci = self::quoteDelRegistro($padre)['voci']->keys()->map(fn ($id) => (int) $id)->all();
        $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->whereIn('rate_quote.immobile_id', $unita)
            ->when($pianoId !== null, fn ($q) => $q->where('rate.piano_rate_id', $pianoId))
            ->where(fn ($q) => $q->where('rate_quote.id', '>', (int) ($padre->registro['quota_max_id'] ?? PHP_INT_MAX))
                ->when($padre->created_at !== null, fn ($q) => $q->orWhere('rate_quote.created_at', '>', $padre->created_at)))
            ->when($padre->annullato(), function ($q) use ($padre) {
                // Un passaggio annullato: solo le quote nate prima dell'annullamento. L'ora sempre; la chiave del registro quando c'è.
                $q->where('rate_quote.created_at', '<=', $padre->annullato_il->toDateTimeString());
                $chiave = $padre->registro['annullamento']['quota_max_id'] ?? null;
                if ($chiave !== null) {
                    $q->where('rate_quote.id', '<=', (int) $chiave);
                }
            })
            ->whereNotIn('rate_quote.id', $voci)
            ->get(['rate_quote.id', 'rate_quote.rata_id', 'rate_quote.anagrafica_id', 'rate_quote.immobile_id', 'rate_quote.importo', 'rate_quote.scrittura_contabile_id', 'rate_quote.importo_pagato', 'rate_quote.regole_calcolo', 'rate_quote.created_at', 'rate.numero_rata', 'rate.piano_rate_id', 'piani_rate.nome as piano_nome'])
            ->reject(fn ($q) => isset(self::regole($q)['divisa_da']))
            ->values();
        if ($quote->isEmpty()) {
            return $quote;
        }

        // Per unità: da quando e su quali ruoli il passaggio ha cambiato la titolarità.
        $toccati = [];
        foreach ($famiglia as $s) {
            $ruoli = [];
            foreach ($s->registro['righe'] ?? [] as $op) {
                foreach (['prima', 'dopo'] as $lato) {
                    if (isset($op[$lato]['tipologia'])) {
                        $ruoli[] = (string) $op[$lato]['tipologia'];
                    }
                }
                if ($op['operazione'] !== 'aperta') {
                    $ruoli[] = (string) DB::table('anagrafica_immobile')->where('id', $op['id'])->value('tipologia');
                }
            }
            $toccati[(int) $s->immobile_id] = ['dal' => $s->decorrenza?->toDateString() ?? '0000-00-00', 'ruoli' => array_values(array_unique(array_filter($ruoli)))];
        }

        $entrati = self::entrati($famiglia);
        $riparto = DB::table('righe_riparto')->whereIn('piano_rate_id', $quote->pluck('piano_rate_id')->unique()->all())->whereIn('immobile_id', $unita)
            ->get(['piano_rate_id', 'anagrafica_id', 'immobile_id', 'ruolo_richiesto', 'ruolo_risolto', 'competenza_al'])
            ->groupBy(fn ($r) => $r->piano_rate_id . ':' . $r->anagrafica_id . ':' . $r->immobile_id);

        // Con il dettaglio del riparto decide il riparto, per chiunque; «è fra gli entrati» è il ripiego delle quote senza.
        // Chi vende e resta usufruttuario, chi resta nudo, il comproprietario che somma e il nudo che torna pieno sono fra
        // gli entrati ma erano titolari anche prima (caso B6 della beta.38); chi entra davvero ha righe col ruolo
        // toccato e la competenza dalla decorrenza in poi, e resta.
        return $quote->filter(function ($q) use ($entrati, $toccati, $riparto) {
            $righe = $riparto->get($q->piano_rate_id . ':' . $q->anagrafica_id . ':' . $q->immobile_id);
            if ($righe === null || $righe->isEmpty()) {
                return in_array((int) $q->anagrafica_id, $entrati, true);
            }
            $t = $toccati[(int) $q->immobile_id] ?? ['dal' => '0000-00-00', 'ruoli' => []];

            return $righe->contains(fn ($r) => (in_array((string) $r->ruolo_richiesto, $t['ruoli'], true) || in_array((string) $r->ruolo_risolto, $t['ruoli'], true))
                && ($r->competenza_al === null || substr((string) $r->competenza_al, 0, 10) >= $t['dal']));
        })->values();
    }

    /** Le unità della famiglia. @return list<int> */
    public static function unita(Collection $famiglia): array
    {
        return $famiglia->pluck('immobile_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Chi il passaggio ha fatto entrare, su tutta la famiglia: l'entrante, le persone delle righe aperte e quelle delle
     * righe modificate (il comproprietario che somma, i nudi che all'estinzione tornano pieni). @return list<int>
     */
    public static function entrati(Collection $famiglia): array
    {
        $ids = [];
        $modificate = [];
        foreach ($famiglia as $s) {
            $ids[] = (int) $s->anagrafica_entrante_id;
            foreach ($s->registro['righe'] ?? [] as $op) {
                if ($op['operazione'] === 'aperta') {
                    $ids[] = (int) ($op['dopo']['anagrafica_id'] ?? 0);
                } elseif ($op['operazione'] === 'modificata') {
                    $modificate[] = (int) $op['id'];
                }
            }
        }
        if ($modificate !== []) {
            $ids = array_merge($ids, DB::table('anagrafica_immobile')->whereIn('id', $modificate)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Le quote del registro che esistono ancora e sono ancora quelle del passaggio, riconosciute dalla traccia che il
     * passaggio ha scritto dentro ciascuna — `regole_calcolo.riassegnazione.subentro_id` sulla bozza passata,
     * `regole_calcolo.divisa_da` sulla gemella del pregresso — e non solo dall'id. KondoManager dichiara MySQL 5.7+
     * (Vincenzo, 29/09/2026: la compatibilità resta), e MySQL 5.7 dopo un riavvio fa ripartire il contatore da MAX(id)+1:
     * una quota nuova può riprendere l'id di una sparita col suo piano. Con l'id giusto e la traccia sbagliata la quota
     * conta come sparita.
     *
     * @return array{voci: Collection<int, object>, gemelle: Collection<int, object>} per id
     */
    public static function quoteDelRegistro(Subentro $padre): array
    {
        $voci = collect($padre->registro['quote'] ?? []);
        $righe = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->whereIn('rate_quote.id', $voci->pluck('id')->merge($voci->pluck('gemella_id'))->filter()->all())
            ->get(['rate_quote.id', 'rate_quote.rata_id', 'rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.scrittura_contabile_id', 'rate_quote.importo_pagato', 'rate_quote.regole_calcolo', 'rate.numero_rata', 'piani_rate.nome as piano_nome'])
            ->keyBy('id');

        $trovate = ['voci' => collect(), 'gemelle' => collect()];
        foreach ($voci as $voce) {
            $q = $righe->get((int) $voce['id']);
            if ($q !== null && (int) $q->rata_id === (int) $voce['rata_id'] && (int) (self::regole($q)['riassegnazione']['subentro_id'] ?? 0) === (int) $padre->id) {
                $trovate['voci']->put((int) $voce['id'], $q);
            }
            $g = empty($voce['gemella_id']) ? null : $righe->get((int) $voce['gemella_id']);
            $divisa = $g !== null ? (self::regole($g)['divisa_da'] ?? []) : [];
            if ($g !== null && (int) ($divisa['subentro_id'] ?? 0) === (int) $padre->id && (int) ($divisa['rata_quote_id'] ?? 0) === (int) $voce['id']) {
                $trovate['gemelle']->put((int) $voce['gemella_id'], $g);
            }
        }

        return $trovate;
    }

    /** `regole_calcolo` di una quota letta con il query builder, come array. */
    public static function regole(object $q): array
    {
        return is_string($q->regole_calcolo ?? null) ? (json_decode($q->regole_calcolo, true) ?: []) : (array) ($q->regole_calcolo ?? []);
    }
}
