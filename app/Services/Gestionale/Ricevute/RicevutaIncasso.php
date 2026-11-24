<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use Illuminate\Support\Facades\DB;

/**
 * Il contenuto della ricevuta di un incasso (decisioni 197 e 200), in sola lettura e in centesimi: che cosa la ricevuta dice, letto da ciò
 * che l'incasso ha scritto. Non scrive niente.
 *
 * ## Da dove viene ogni riga
 *
 * - **Le quote coperte**: per ogni quota, quanto ci hanno messo l'incasso (il denaro, compresa la parte in più che il programma vi ha
 *   attaccato) e le sue compensazioni (`storno_credito`, le figlie che usano il credito), dalla pivot `quota_scrittura`. L'**imputato** è
 *   il minore fra quella somma e il residuo che la quota aveva prima dell'incasso; quello che avanza è la parte in più rimasta a credito
 *   su quella quota. Così una parte in più che riduce una rata ancora da pagare è imputata alla rata, come la legge l'estratto conto.
 * - **Il già versato prima** è la somma della pivot di quella quota per le scritture registrate prima di questo incasso: l'ordine di
 *   registrazione, quello con cui il programma ha imputato il denaro (decisione 202). Un incasso annullato non conta, né la sua rettifica,
 *   né le compensazioni di un incasso annullato. Se la somma è negativa (il credito della quota usato e poi annullato il versamento che lo
 *   aveva prodotto) la ricevuta mostra zero e dice quanto era tornato a debito. **Il residuo dopo** non scende sotto zero.
 * - **Il credito usato** dalle quote che le compensazioni dell'incasso hanno svuotato (la pivot in negativo); per ogni quota coperta, la
 *   parte che viene dal credito.
 * - **La parte in più**: quello che il denaro ha messo oltre i debiti della posizione. Sulla rata di chi ha versato per un altro la ricevuta
 *   dice quanto la riduce e quanto resta a credito, senza il residuo di quella rata, che è un dato di chi ha versato.
 * - **Chi ha versato e per conto di chi** (decisione 30): la regola di `PersoneDegliIncassi`. Una compensazione che copre solo le rate di
 *   un'altra persona (il credito della posizione «si usa adesso» e il denaro va tutto sulla rata di chi l'ha versato) non è un versamento di
 *   questa ricevuta: non entra nelle quote né nel credito usato, e la ricevuta la nomina solo in `compensazioni_escluse`.
 * - **Il versamento di un erede sul debito ereditato** (decisione 539): di serie la ricevuta è intestata all'erede che ha pagato (la posizione di
 *   `PersoneDegliIncassi`), con `debito_ereditato` (di chi era il debito, il giorno della successione, la sua quota) al posto di «Per conto di». Con la
 *   scelta «Eredi di …» la posizione resta il defunto, con il suo indirizzo e il suo codice fiscale, e il nome «Eredi di Massimo Bagnara»; con «il defunto»
 *   come prima. Una ricevuta già emessa non cambia: la copia legge il contenuto dell'originale ({@see daIstantanea()}).
 * - **La riga della rata del defunto nella ricevuta all'erede** (Coda 706, la forma A della domanda D1): la ricevuta è dell'erede, e la riga dice la sua
 *   parte ({@see partiDellErede()}). «Quota» la parte dell'erede della rata (la cifra che «Rate scadute» le mostrava), «Già versato» i suoi versamenti su
 *   quella parte registrati prima di questo, «Imputato» questo, «Resta» quello che resta a lei; la `nota` «la parte di Cecilia Angeli (50 %) della rata di
 *   € 65,58 di Massimo Bagnara». La riga porta `parte_erede`, con le cifre della rata intera: la regola di prima, con cui si confronta una copia
 *   ({@see cifre()}). Con «Eredi di …» e con «il defunto» la riga resta la rata intera.
 *
 * Non c'è il metodo di pagamento (bonifico, contanti, assegno): l'incasso non lo registra.
 */
class RicevutaIncasso
{
    /** Sopra questa somma la ricevuta è «sopra € 77,47» per la finestra e per i filtri: il programma mostra il dato, non dice se il bollo è dovuto. */
    public const SOGLIA_BOLLO_CENTS = 7747;

    private const ETICHETTE_CASSA = [
        'banca'    => 'Conto corrente',
        'contanti' => 'Cassa contanti',
        'postale'  => 'Conto postale',
        'virtuale' => 'Cassa virtuale',
    ];

    /**
     * @return array<string, mixed>
     */
    public function per(ScritturaContabile $incasso): array
    {
        $incasso->loadMissing([
            'condominio',
            'gestione',
            'righe.cassa',
            'righe.anagrafica',
            'quotePagate.rata.pianoRate.gestione',
            'quotePagate.immobile',
            'quotePagate.anagrafica',
            'figlie.quotePagate.rata.pianoRate.gestione',
            'figlie.quotePagate.immobile',
            'figlie.quotePagate.anagrafica',
        ]);

        [$posizione, $versatoDa, $piuPersone, $eredita] = $this->persone($incasso);
        // Decisione 539: la ricevuta del versamento di un erede, intestata a lui (di serie) o agli eredi del defunto.
        $allErede = $eredita !== null && $eredita['intestatario'] === \App\Services\Gestionale\Accessori\VersamentoDegliEredi::RICEVUTA_ALL_EREDE && $posizione !== null;
        $agliEredi = $eredita !== null && $eredita['intestatario'] === \App\Services\Gestionale\Accessori\VersamentoDegliEredi::RICEVUTA_AGLI_EREDI;
        $notaDellErede = $allErede ? sprintf('la parte di %s del debito ereditato da %s', $eredita['nome_erede'], $eredita['di']) : null;
        // Coda 706: le parti dell'erede sulle rate del defunto, lette una volta sola e solo per la ricevuta all'erede.
        $partiDellErede = null;
        $compensazioni = $incasso->figlie->filter(fn (ScritturaContabile $f) => $f->tipo_movimento === TipoMovimentoContabile::STORNO_CREDITO)->values();

        // Quanto l'incasso ha messo su ogni quota con il denaro, e quanto le sue compensazioni con il credito.
        $quote = [];
        $denaro = [];
        foreach ($incasso->quotePagate as $quota) {
            $denaro[$quota->id] = ($denaro[$quota->id] ?? 0) + (int) $quota->pivot->importo_pagato;
            $quote[$quota->id] = $quota;
        }
        $credito = [];
        $escluse = [];
        foreach ($compensazioni as $compensazione) {
            $netto = [];
            foreach ($compensazione->quotePagate as $quota) {
                $netto[$quota->id] = ($netto[$quota->id] ?? 0) + (int) $quota->pivot->importo_pagato;
                $quote[$quota->id] ??= $quota;
            }
            $coperte = collect($netto)->filter(fn (int $v, int $id) => $v > 0 && (int) $quote[$id]->importo > 0);
            $persone = $coperte->keys()->map(fn (int $id) => (int) $quote[$id]->anagrafica_id)->unique()->values();
            if ($posizione !== null && $persone->isNotEmpty() && ! $persone->contains((int) $posizione->id)) {
                $escluse[] = [
                    'persona' => $coperte->keys()->map(fn (int $id) => $quote[$id]->anagrafica?->nome)->filter()->unique()->implode(', '),
                    'importo' => (int) $coperte->sum(),
                ];

                continue;
            }
            foreach ($netto as $id => $v) {
                $credito[$id] = ($credito[$id] ?? 0) + $v;
            }
        }

        $ids = array_keys($denaro + $credito);
        $prima = $this->giaVersatoPrima($incasso, $ids);
        $daChiHaVersato = fn (RataQuote $q) => $versatoDa !== null && (int) $q->anagrafica_id === (int) $versatoDa->id;

        $righeQuote = [];
        $crediti = [];
        $parteInPiu = [];
        foreach ($ids as $id) {
            $q = $quote[$id];
            $messo = ($denaro[$id] ?? 0) + ($credito[$id] ?? 0);
            if ($messo < 0) {
                // Il credito di un'altra persona (un comproprietario) usato per le rate della posizione: la ricevuta dice di chi è.
                $crediti[] = [
                    'importo' => -$messo,
                    'rata'    => $this->nomeRataEPiano($q),
                    'persona' => $posizione !== null && (int) $q->anagrafica_id !== (int) $posizione->id ? $q->anagrafica?->nome : null,
                ];

                continue;
            }
            if ($messo === 0 || (int) $q->importo <= 0) {
                continue;
            }

            $versatoPrima = $prima[$id] ?? 0;
            $residuoPrima = (int) $q->importo - $versatoPrima;
            $imputato = min($messo, max(0, $residuoPrima));
            $avanza = $messo - $imputato;

            if ($daChiHaVersato($q)) {
                $parteInPiu[] = $this->parteInPiu($messo, $q, $imputato, $avanza, true);

                continue;
            }
            if ($imputato > 0) {
                $riga = [
                    'quota_id'    => $q->id,
                    'rata'        => $this->nomeRata($q),
                    'piano'       => $q->rata?->pianoRate?->nome,
                    'gestione'    => $q->rata?->pianoRate?->gestione?->nome,
                    'unita'       => $q->immobile?->etichetta,
                    'scadenza'    => $q->rata?->data_scadenza?->format('Y-m-d'),
                    'importo'     => (int) $q->importo,
                    'prima'       => min(max(0, $versatoPrima), (int) $q->importo),
                    'riaddebito'  => max(0, -$versatoPrima),
                    'imputato'    => $imputato,
                    'dal_credito' => min(max(0, $credito[$id] ?? 0), $imputato),
                    'dopo'        => max(0, $residuoPrima - $imputato),
                ];
                if ($notaDellErede !== null && (int) $q->anagrafica_id !== (int) $posizione->id) {
                    $partiDellErede ??= $this->partiDellErede($incasso, (int) $posizione->id);
                    $riga = $this->rigaDellErede($riga, $q, $partiDellErede, $eredita, $notaDellErede);
                }
                $righeQuote[] = $riga;
            }
            if ($avanza > 0) {
                $parteInPiu[] = $this->parteInPiu($avanza, $q, 0, $avanza, false);
            }
        }
        // Gli interessi e le spese addebitati, scaduti o del giorno del versamento, prima, come li mette l'ordine proposto (art. 1194 c.c.); poi per
        // scadenza, con quelli che scadevano più avanti.
        $giornoDelVersamento = $incasso->data_competenza?->format('Y-m-d');
        $primaGliAccessori = fn (int $id) => OrdineImputazione::inTesta($quote[$id]->tipo === RataQuote::TIPO_ACCESSORIO,
            (string) ($quote[$id]->rata?->data_scadenza?->format('Y-m-d') ?? ''), $giornoDelVersamento) ? 0 : 1;
        usort($righeQuote, fn (array $a, array $b) => [$primaGliAccessori($a['quota_id']), $a['scadenza'] ?? '', $quote[$a['quota_id']]->rata?->numero_rata, $a['quota_id']]
            <=> [$primaGliAccessori($b['quota_id']), $b['scadenza'] ?? '', $quote[$b['quota_id']]->rata?->numero_rata, $b['quota_id']]);

        // La parte in più senza una quota su cui stare (incassi di versioni vecchie): un anticipo, tutto a credito.
        foreach ($incasso->righe as $riga) {
            if ($riga->tipo_riga === 'avere' && ! $riga->rata_id && str_starts_with((string) $riga->note, 'Anticipo / Eccedenza')) {
                $parteInPiu[] = ['importo' => (int) $riga->importo, 'persona' => $riga->anagrafica?->nome, 'rata' => null, 'riduce' => 0,
                    'a_credito' => (int) $riga->importo, 'di_chi_ha_versato' => false];
            }
        }

        $rigaCassa = $incasso->righe->first(fn (RigaScrittura $r) => $r->tipo_riga === 'dare' && $r->cassa_id);
        $importoVersato = (int) ($rigaCassa?->importo ?? 0);
        $condominio = $incasso->condominio;

        return [
            'incasso_id'      => $incasso->id,
            'numero'          => $incasso->numero_protocollo,
            'data_versamento' => $incasso->data_competenza?->format('Y-m-d'),
            'causale'         => $incasso->causale,
            'annullato'       => $incasso->stato === 'annullata',
            'storno'          => $incasso->stato === 'annullata' ? $this->storno($incasso) : null,
            'condominio'      => [
                'nome'           => $condominio?->nome,
                'indirizzo'      => trim(implode(' ', array_filter([
                    $condominio?->indirizzo,
                    trim(implode(' ', array_filter([$condominio?->cap, $condominio?->comune, $condominio?->provincia ? "({$condominio->provincia})" : null]))),
                ])), ' '),
                'codice_fiscale' => $condominio?->codice_fiscale,
            ],
            'posizione'       => $posizione ? [
                'id'             => $posizione->id,
                'nome'           => $agliEredi ? 'Eredi di ' . $eredita['defunto'] : $posizione->nome,
                'indirizzo'      => $posizione->indirizzo,
                'codice_fiscale' => $posizione->codice_fiscale,
            ] : null,
            'versato_da'      => $versatoDa ? ['id' => $versatoDa->id, 'nome' => $versatoDa->nome] : null,
            // Decisione 539: «Per il debito ereditato da» sotto «Ricevuto da», al posto di «Per conto di», con la ricevuta all'erede.
            'debito_ereditato' => $allErede ? ['di' => $eredita['di'], 'successione_del' => $eredita['successione_del'], 'quota' => $eredita['quota']] : null,
            'importo_versato' => $importoVersato,
            'cassa'           => $rigaCassa?->cassa ? [
                'nome' => $rigaCassa->cassa->nome,
                'tipo' => self::ETICHETTE_CASSA[$rigaCassa->cassa->tipo] ?? ucfirst((string) $rigaCassa->cassa->tipo),
            ] : null,
            'gestione'        => $incasso->gestione?->nome,
            // A che cosa va il versamento, con quale criterio (decisione 218, punto 4): una riga, o niente.
            'imputazione'     => self::fraseImputazione($incasso->imputazione, $incasso->imputazione_nota),
            'quote'           => $righeQuote,
            'totali'          => [
                'importo'  => array_sum(array_column($righeQuote, 'importo')),
                'prima'    => array_sum(array_column($righeQuote, 'prima')),
                'imputato' => array_sum(array_column($righeQuote, 'imputato')),
                'dopo'     => array_sum(array_column($righeQuote, 'dopo')),
            ],
            'credito_usato'   => array_sum(array_column($crediti, 'importo')),
            'crediti'         => $crediti,
            'eccedenza'       => array_sum(array_column($parteInPiu, 'importo')),
            'eccedenze'       => $parteInPiu,
            'compensazioni_escluse' => $escluse,
            // Le persone dell'incasso quando sono più d'una e nessuno ha versato per conto di altri: non c'è una ricevuta sola da fare.
            'piu_persone'     => $piuPersone,
            'sopra_soglia'    => self::sopraSoglia($importoVersato),
        ];
    }

    /**
     * La ricevuta com'è stata emessa la prima volta (il contenuto salvato nella riga del registro dell'originale), con quello che è di oggi:
     * se l'incasso è stato annullato dopo (la copia lo dice in testa, sopra le cifre dell'originale) e l'indirizzo postale della persona,
     * che serve alla busta di oggi. Le cifre restano quelle dell'originale: una copia non è una ricevuta nuova.
     *
     * @param array<string, mixed> $istantanea
     * @return array<string, mixed>
     */
    public function daIstantanea(array $istantanea, ScritturaContabile $incasso): array
    {
        $istantanea['annullato'] = $incasso->stato === 'annullata';
        $istantanea['storno'] = $istantanea['annullato'] ? $this->storno($incasso) : null;
        if (! empty($istantanea['posizione']['id']) && ($persona = Anagrafica::find($istantanea['posizione']['id'])) !== null) {
            $istantanea['posizione']['indirizzo'] = $persona->indirizzo;
        }

        return $istantanea;
    }

    /**
     * Le cifre della ricevuta, senza ciò che cambia con lo stato dell'incasso o con l'anagrafica: per sapere se la situazione delle quote è
     * cambiata dopo l'originale. La riga della rata del defunto nella ricevuta all'erede (Coda 706) si confronta con le cifre della rata intera, che porta
     * in `parte_erede`: sono quelle che scriveva la regola di prima, e un originale emesso con quella regola non si dice «cambiato» per la sola regola nuova.
     *
     * @param array<string, mixed> $contenuto
     * @return array<string, mixed>
     */
    public static function cifre(array $contenuto): array
    {
        return [
            'quote'      => array_map(fn (array $q) => isset($q['parte_erede']['rata'])
                ? [$q['quota_id'] ?? null, $q['parte_erede']['rata'], $q['parte_erede']['rata_prima'] ?? 0, $q['imputato'] ?? 0, $q['parte_erede']['rata_dopo'] ?? 0]
                : [$q['quota_id'] ?? null, $q['importo'] ?? 0, $q['prima'] ?? 0, $q['imputato'] ?? 0, $q['dopo'] ?? 0], $contenuto['quote'] ?? []),
            'credito'    => $contenuto['credito_usato'] ?? 0,
            'eccedenze'  => array_map(fn (array $e) => [$e['importo'] ?? 0, $e['riduce'] ?? 0, $e['a_credito'] ?? 0], $contenuto['eccedenze'] ?? []),
            'posizione'  => $contenuto['posizione']['id'] ?? null,
            'versato_da' => $contenuto['versato_da']['id'] ?? null,
        ];
    }

    /**
     * Coda 706: la riga della rata del defunto nella ricevuta all'erede, con la sua parte. Le cifre della rata intera restano in `parte_erede` per il
     * confronto della copia ({@see cifre()}). Senza la parte (una lettura che non la trova) la riga resta la rata intera, con la nota di prima.
     *
     * @param array<string, mixed> $riga
     * @param array<string, array{quota: int, prima: int, defunto: ?int}> $parti {@see partiDellErede()}
     * @param array<string, mixed> $eredita la lettura di {@see \App\Services\Gestionale\Accessori\VersamentoDegliEredi::deiVersamenti()}
     * @return array<string, mixed>
     */
    private function rigaDellErede(array $riga, RataQuote $q, array $parti, array $eredita, string $notaDiPrima): array
    {
        $parte = $parti[$q->rata_id . '|' . $q->immobile_id . '|' . $q->anagrafica_id] ?? null;
        if ($parte === null) {
            return ['nota' => $notaDiPrima] + $riga;
        }
        // Con la catena (decisione 530) la rata è del primo defunto, e la percentuale della successione dell'erede non è la sua parte di quella rata: la
        // nota la tace.
        $diretta = $parte['defunto'] === (int) $q->anagrafica_id;
        $nota = $diretta
            ? sprintf('la parte di %s (%s %%) della rata di %s di %s', $eredita['nome_erede'], $eredita['quota'], \App\Helpers\MoneyHelper::format((int) $q->importo), $eredita['defunto'])
            : sprintf('la parte di %s della rata di %s di %s', $eredita['nome_erede'], \App\Helpers\MoneyHelper::format((int) $q->importo), (string) $q->anagrafica?->nome);

        return ['nota' => $nota] + array_merge($riga, [
            'importo'     => $parte['quota'],
            'prima'       => min($parte['prima'], $parte['quota']),
            'riaddebito'  => 0,
            'dopo'        => max(0, $parte['quota'] - $parte['prima'] - (int) $riga['imputato']),
            'parte_erede' => ['percentuale' => $diretta ? $eredita['quota'] : null, 'rata' => $riga['importo'], 'rata_prima' => $riga['prima'], 'rata_dopo' => $riga['dopo']],
        ]);
    }

    /**
     * Coda 706: la parte dell'erede di ogni rata del defunto su cui l'incasso ha versato, «rata|unità|defunto della rata» => la parte (`quota`), i suoi
     * versamenti su quella parte registrati prima di questo incasso (`prima`) e il defunto della successione (`defunto`). La parte è quella che «Rate
     * scadute» le mostrava prima dei suoi versamenti: il residuo della sua riga «ereditato» al giorno del versamento
     * ({@see \App\Services\Gestionale\Accessori\DebitoEreditato::perLaLettura()}) più i suoi versamenti fino a quel giorno, che la lettura ha già tolto.
     * I versamenti sono quelli che il registro della successione tiene
     * (`versamenti_degli_eredi`, con la coppia, non stornati) con le righe sulla rata del defunto legate a lui, in un incasso non annullato; «prima» segue
     * l'ordine di registrazione, come il già versato delle altre righe (decisione 202).
     *
     * @return array<string, array{quota: int, prima: int, defunto: ?int}>
     */
    private function partiDellErede(ScritturaContabile $incasso, int $erede): array
    {
        $giorno = $incasso->data_competenza?->format('Y-m-d');
        if ($giorno === null) {
            return [];
        }
        // Le successioni in cui questo incasso ha la coppia dell'erede: la sua, e con la catena quella dell'erede morto dopo.
        $successioni = \App\Models\Gestionale\Subentro::query()->where('condominio_id', $incasso->condominio_id)->where('tipo_passaggio', 'successione')
            ->whereNull('subentro_padre_id')->orderBy('id')->get()
            ->filter(fn ($s) => collect((array) ($s->registro['versamenti_degli_eredi'] ?? []))->contains(fn ($v) => (int) ($v['scrittura_id'] ?? 0) === (int) $incasso->id
                && collect((array) ($v['coppie'] ?? []))->contains(fn ($c) => (int) ($c['erede'] ?? 0) === $erede)))->values();
        if ($successioni->isEmpty()) {
            return [];
        }
        $ids = $successioni->pluck('id')->map(fn ($id) => (int) $id)->all();
        $defunto = (int) $successioni->first()->anagrafica_uscente_id;

        // Il residuo delle sue righe «ereditato» al giorno del versamento.
        $esito = [];
        foreach ((new \App\Services\Gestionale\Accessori\DebitoEreditato())->perLaLettura((int) $incasso->condominio_id, $giorno)['eredi'][$erede] ?? [] as $l) {
            if (! in_array((int) ($l['ereditato']['subentro_id'] ?? 0), $ids, true)) {
                continue;
            }
            $k = (int) $l['rata_id'] . '|' . (int) $l['immobile_id'] . '|' . (int) ($l['ereditato']['defunto_della_rata'] ?? $defunto);
            $esito[$k] ??= ['quota' => 0, 'prima' => 0, 'defunto' => $defunto];
            $esito[$k]['quota'] += max(0, (int) $l['residuo']);
        }

        // I suoi versamenti sulle rate dei defunti: gli incassi con la coppia, non stornati, dal registro delle successioni.
        $conLaCoppia = $successioni->flatMap(fn ($s) => array_map(fn ($v) => (int) ($v['scrittura_id'] ?? 0), array_filter((array) ($s->registro['versamenti_degli_eredi'] ?? []),
            fn ($v) => ($v['stornato_il'] ?? null) === null && ($v['coppie'] ?? []) !== [])))->unique()->values()->all();
        $versati = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereIn('sc.id', $conLaCoppia ?: [0])->whereNull('sc.deleted_at')->where('sc.stato', '!=', 'annullata')->where('cc.ruolo', 'crediti_condomini')
            ->whereNotNull('rs.rata_id')->where('rs.riferimento_type', Anagrafica::class)->where('rs.riferimento_id', $erede)->where('rs.anagrafica_id', '!=', $erede)
            ->groupBy('sc.id', 'sc.data_competenza', 'rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id')
            ->select(['sc.id as scrittura_id', 'sc.data_competenza', 'rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id'])
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END) as y")->get();
        foreach ($versati as $v) {
            $k = (int) $v->rata_id . '|' . (int) $v->immobile_id . '|' . (int) $v->anagrafica_id;
            $esito[$k] ??= ['quota' => 0, 'prima' => 0, 'defunto' => $defunto];
            // La lettura al giorno ha già tolto i versamenti fino a quel giorno: tornano nella parte.
            if (substr((string) $v->data_competenza, 0, 10) <= $giorno) {
                $esito[$k]['quota'] += (int) $v->y;
            }
            if ((int) $v->scrittura_id < (int) $incasso->id) {
                $esito[$k]['prima'] += (int) $v->y;
            }
        }

        return array_filter($esito, fn (array $p) => $p['quota'] > 0);
    }

    /** @return array{importo: int, persona: ?string, rata: string, riduce: int, a_credito: int, di_chi_ha_versato: bool} */
    private function parteInPiu(int $importo, RataQuote $q, int $riduce, int $aCredito, bool $diChiHaVersato): array
    {
        return [
            'importo'           => $importo,
            'persona'           => $q->anagrafica?->nome,
            'rata'              => $this->nomeRataEPiano($q),
            'riduce'            => $riduce,
            'a_credito'         => $aCredito,
            'di_chi_ha_versato' => $diChiHaVersato,
        ];
    }

    /**
     * La riga del criterio dell'imputazione sotto «A che cosa va il versamento» (decisione 218, punto 4): l'ordine delle scadenze, la
     * dichiarazione di chi ha versato con la sua nota (è sua), la scelta dell'amministratore **senza** la sua nota (può essere interna, come
     * la causale: decisione 202.1), la compensazione. Niente per «fuori ordine» e per gli incassi di prima. Nessuna frase di effetto legale.
     */
    public static function fraseImputazione(?string $imputazione, ?string $nota): ?string
    {
        $nota = trim((string) $nota);

        return match ($imputazione) {
            \App\Services\Gestionale\Incassi\OrdineImputazione::ORDINE_SCADENZE => 'Imputazione: secondo l\'ordine delle scadenze.',
            \App\Services\Gestionale\Incassi\OrdineImputazione::INDICATA_DAL_VERSANTE => 'Imputazione: indicata da chi ha versato' . ($nota !== '' ? ": «{$nota}»." : '.'),
            \App\Services\Gestionale\Incassi\OrdineImputazione::SCELTA_AMMINISTRATORE => 'Imputazione: scelta dall\'amministratore.',
            \App\Services\Gestionale\Incassi\OrdineImputazione::COMPENSAZIONE => 'Imputazione: compensazione con il credito.',
            \App\Services\Gestionale\Incassi\OrdineImputazione::ACCORDO_RIENTRO => 'Imputazione: versamento dell\'accordo di rientro.',
            default => null,
        };
    }

    public static function sopraSoglia(int $importoCents): bool
    {
        return $importoCents > self::SOGLIA_BOLLO_CENTS;
    }

    /**
     * Il già versato prima di questo incasso su ogni quota: la pivot delle scritture registrate prima (identificativo minore: l'ordine con cui
     * il programma ha imputato il denaro, decisione 202). Le compensazioni di questo incasso sono registrate dopo di lui e non ci sono; una
     * scrittura annullata, una compensazione di un incasso annullato e la rettifica di uno storno non contano.
     *
     * @param array<int, int> $quoteIds
     * @return array<int, int> quota => centesimi
     */
    private function giaVersatoPrima(ScritturaContabile $incasso, array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }

        $righe = DB::table('quota_scrittura')
            ->join('scritture_contabili as s', 's.id', '=', 'quota_scrittura.scrittura_contabile_id')
            ->leftJoin('scritture_contabili as padre', 'padre.id', '=', 's.scrittura_padre_id')
            ->whereIn('quota_scrittura.rate_quota_id', $quoteIds)
            ->where('s.id', '<', $incasso->id)
            ->whereNull('s.deleted_at')
            ->where('s.stato', '!=', 'annullata')
            ->where('s.tipo_movimento', '!=', TipoMovimentoContabile::RETTIFICA->value)
            ->get([
                'quota_scrittura.rate_quota_id as quota_id',
                'quota_scrittura.importo_pagato as importo',
                's.tipo_movimento as tipo',
                'padre.stato as padre_stato',
            ]);

        $prima = [];
        foreach ($righe as $r) {
            if ($r->tipo === TipoMovimentoContabile::STORNO_CREDITO->value && $r->padre_stato === 'annullata') {
                continue;
            }
            $prima[$r->quota_id] = ($prima[$r->quota_id] ?? 0) + (int) $r->importo;
        }

        return $prima;
    }

    /**
     * La posizione e chi ha versato, con la regola di `PersoneDegliIncassi`: la stessa dell'elenco delle ricevute e dell'invio; con il versamento di un
     * erede anche la sua lettura (decisione 539).
     *
     * @return array{0: ?Anagrafica, 1: ?Anagrafica, 2: array<int, array{id: int, nome: string}>, 3: ?array<string, mixed>}
     */
    private function persone(ScritturaContabile $incasso): array
    {
        $persone = app(PersoneDegliIncassi::class)->di([$incasso->id])[$incasso->id];
        $trovate = Anagrafica::whereIn('id', array_merge(array_filter([$persone['posizione'], $persone['versato_da']]), $persone['piu_persone']))->get()->keyBy('id');

        return [
            $persone['posizione'] ? $trovate->get($persone['posizione']) : null,
            $persone['versato_da'] ? $trovate->get($persone['versato_da']) : null,
            collect($persone['piu_persone'])->map(fn (int $id) => $trovate->get($id))->filter()->map(fn (Anagrafica $a) => ['id' => $a->id, 'nome' => $a->nome])->values()->all(),
            $persone['eredita'] ?? null,
        ];
    }

    /** La rettifica che ha annullato l'incasso: il suo protocollo e il giorno in cui è stata scritta, nel calendario dell'amministratore. */
    private function storno(ScritturaContabile $incasso): ?array
    {
        $rettifica = ScritturaContabile::where('scrittura_padre_id', $incasso->id)
            ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)
            ->orderBy('id')
            ->first();

        if ($rettifica === null) {
            return null;
        }

        return [
            'protocollo' => $rettifica->numero_protocollo,
            'data'       => ($rettifica->created_at ?? $rettifica->data_registrazione)?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
        ];
    }

    private function nomeRata(RataQuote $q): string
    {
        $rata = $q->rata;
        $numero = $rata?->numero_rata;
        $descrizione = trim((string) ($rata?->descrizione ?? ''));
        // Un addebito a una persona sola si chiama con la sua causale («Interessi di mora al 29/06/2026», «Spese di sollecito»): il numero della rata nel
        // piano degli addebiti non dice niente a chi legge.
        if ($rata?->pianoRate?->eDegliAddebiti() && $descrizione !== '') {
            return $descrizione;
        }

        return self::nomeBreve(trim('Rata ' . ($numero ?? '?') . ($descrizione !== '' ? ' — ' . $descrizione : '')), $rata?->pianoRate?->nome);
    }

    /**
     * Il nome della rata senza il nome del piano ripetuto (decisione 206.1): il programma scrive la descrizione di una rata come «Rata n.1 -
     * <piano>», e la ricevuta, che dice il piano in testa al gruppo, leggeva «Rata 1 — Rata n.1 - <piano>». Toglie il nome del piano in fondo
     * alla descrizione e il «Rata n.N» che ripete il numero; lascia quello che l'amministratore ha scritto. Vale anche per il nome salvato
     * nel contenuto di un originale emesso prima, perché la copia si legga allo stesso modo.
     */
    public static function nomeBreve(string $rata, ?string $piano): string
    {
        if (! preg_match('/^(Rata\s+(\S+))(?:\s+—\s+(.*))?$/su', trim($rata), $m)) {
            return $rata;
        }
        [$testa, $numero, $resto] = [$m[1], $m[2], trim($m[3] ?? '')];
        $piano = trim((string) $piano);
        if ($piano !== '') {
            $resto = trim((string) preg_replace('/(?:^|\s*[-–—,]\s*)' . preg_quote($piano, '/') . '$/iu', '', $resto));
        }
        if ($resto === '' || preg_match('/^Rata\s*n\.?\s*' . preg_quote($numero, '/') . '$/iu', $resto)) {
            return $testa;
        }

        return $testa . ' — ' . $resto;
    }

    private function nomeRataEPiano(RataQuote $q): string
    {
        $piano = $q->rata?->pianoRate?->nome;

        return $this->nomeRata($q) . ($piano ? ', ' . $piano : '');
    }
}
