<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Debiti;

use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\Accessori\RegoleDelRecupero;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Models\ImpostazioniCondominio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il riquadro «Sei mesi per agire» della dashboard del condominio (art. 1129 c. 9 c.c.) e i 180 giorni del rendiconto (art. 1130 n. 10 c.c.), calcolati a ogni
 * apertura e mai scritti. Una riga per ogni esercizio da cui viene la data dei sei mesi (la più vicina, {@see SeiMesi}) di almeno una rata scaduta e non
 * versata: la data, i giorni, le persone aperte e la cifra «di questo esercizio», i cartellini, il passo proposto di ognuna ({@see PassoProposto}, uno per
 * persona, uguale in ogni riga), i pulsanti in blocco, la risposta dell'amministratore ({@see RispostePromemoria}) e il compito dell'Inbox.
 *
 * **Chi è aperto in una riga**: chi ha almeno una rata nella riga, tranne chi ha la dispensa in vigore su tutte le sue rate della riga (una rata è dispensata
 * solo con tutte le sue date coperte: `SeiMesi` sceglie prima le date non coperte) e chi ha «hai agito» ancora valido per la riga. «Hai agito» smette di valere
 * quando nella riga compare una rata che non c'era, o quando la cifra sale: la persona torna, e la frase lo dice con il giorno della registrazione.
 *
 * **Le cifre** vengono dalla lettura dell'elenco «Rate scadute» ({@see ElencoRateScadute::righeABlocchi()}), a blocchi di persone (la memoria di un blocco), senza
 * «versati oltre»; per persona la somma delle cifre delle righe più le rate senza data è lo scaduto della tesoreria. Il credito per gestione viene da
 * `CreditoService` (il `compensabile`), le lettere da `per_tipo` del registro, gli accordi dai segni dell'elenco, la tolleranza dalle regole del recupero.
 *
 * **Il compito** dell'Inbox: per una riga con almeno una persona aperta e la data entro 60 giorni o passata, senza una risposta che lo tenga chiuso («Non
 * serve», tranne quando compare una persona nuova; «Ricordamelo» con il giorno del promemoria nell'agenda non ancora arrivato). Per il rendiconto: solo per
 * l'ultimo esercizio finito, da 60 giorni prima della data, finché il rendiconto è una bozza.
 *
 * **Il peso** (misurato il 15/11/2026, Apple M1 Pro, SQLite in memoria): a blocchi di 250 persone il riquadro sta in 0,29 s e 13,9 MB in più a 300 × 12 rate
 * scadute, e fra 19 e 21 MB fra 600 e 2.000 × 12; la memoria resta quella di un blocco. Sopra la soglia delle quote scadute non calcola, e lo dice: la frase
 * nasce dal controllo, non dall'errore.
 *
 * **La lista dello studio** ({@see perLoStudio()}) legge il riassunto leggero ({@see riassunto()}): le rate, le date, le dispense e le risposte, con le stesse
 * funzioni del riquadro per chi è aperto, la cifra e il compito (`nellaRiga`, `chiusure`, `decisioni`), senza lettere, accordi, crediti e passo. Un condominio
 * per richiesta: a 50 condomini × 40 persone × 3 rate la lista intera sta in 0,35 s e 2 MB, a 200 × 15 × 2 in 1,1 s e 0,2 MB (misurato il 15/11/2026).
 */
final class SeiMesiPerAgire
{
    /** Le quote scadute del condominio oltre le quali il riquadro non calcola (misurato: il tempo arriva prima della memoria). */
    public static int $sogliaQuote = 100_000;

    public const BLOCCO = 250;

    /** I giorni prima della data da cui c'è il compito, e da cui la data è «vicina». */
    public const GIORNI_COMPITO = 60;

    /** I giorni dopo la fine dell'esercizio entro cui convocare l'assemblea per il rendiconto. */
    public const GIORNI_RENDICONTO = 180;

    /** Fino a 8 persone si mostrano tutte; oltre, le prime 5 e la riga di fondo. */
    public const TUTTE_FINO_A = 8;
    public const MOSTRATE_OLTRE = 5;

    public const FRASE_TROPPE = 'Questo condominio ha troppe rate scadute per calcolare qui i sei mesi: le date sono nell\'elenco «Rate scadute».';
    public const FRASE_TORNATO_NON_SERVE = 'Dopo il tuo «Non serve» c\'è una persona nuova nella riga: il compito è tornato.';
    public const FRASE_PROMEMORIA_TOLTO = 'Il promemoria nell\'agenda è stato tolto: il compito è tornato.';

    public function __construct(private readonly ElencoRateScadute $elenco, private readonly CreditoService $crediti, private readonly RispostePromemoria $risposte,
        private readonly LetturaDebiti $lettura, private readonly SeiMesi $seiMesi) {}

    /** La data dei 180 giorni: la fine dell'esercizio più 180 giorni (il 31/12/2025 dà il 29/06/2026), la data più presto. */
    public static function dataDelRendiconto(string $fine): string
    {
        return CarbonImmutable::parse(substr($fine, 0, 10), 'UTC')->addDays(self::GIORNI_RENDICONTO)->format('Y-m-d');
    }

    /** I giorni dalla data di oggi alla data: positivi prima, zero il giorno stesso, negativi dopo. */
    public static function giorni(string $data, string $oggi): int
    {
        return (int) CarbonImmutable::parse($oggi, 'UTC')->diffInDays(CarbonImmutable::parse($data, 'UTC'), false);
    }

    /** Il colore di una data: passata, entro 60 giorni, oltre. */
    public static function colore(int $giorni): string
    {
        return $giorni < 0 ? 'passata' : ($giorni <= self::GIORNI_COMPITO ? 'vicina' : 'lontana');
    }

    /** Le quote scadute e non pagate del condominio: una lettura piccola, solo un numero, per il controllo prima del calcolo. */
    public static function quoteScadute(int $condominioId, string $oggi): int
    {
        return (int) DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
            ->where('p.condominio_id', $condominioId)->where('r.data_scadenza', '<', $oggi)->where('q.importo', '>', 0)->whereColumn('q.importo', '>', 'q.importo_pagato')
            ->count();
    }

    /**
     * Il riquadro del condominio, per la pagina.
     *
     * @return array<string, mixed>
     */
    public function perCondominio(Condominio $condominio, ?string $oggi = null, int $blocco = self::BLOCCO): array
    {
        $oggi ??= DateHelper::oggiUtente();
        // Le scelte del condominio (la scheda «Impostazioni»): se il programma suggerisce la prossima azione, e che cosa succede dopo una lettera.
        $impostazioni = ImpostazioniCondominio::delCondominio((int) $condominio->id);
        $proposto = (bool) $impostazioni->recupero_suggerisce_azione;
        $risposte = $this->risposte->delCondominio((int) $condominio->id);
        $rendiconti = $this->rendiconti($condominio, $oggi, $risposte);
        $compitiRendiconto = array_values(array_map(fn ($r) => self::compitoDelRendiconto($r), array_filter($rendiconti, fn ($r) => $r['compito'])));
        $esito = ['oggi' => $oggi, 'passo_proposto' => $proposto, 'testo_norma' => SeiMesi::TESTO_NORMA, 'troppe' => null, 'righe' => [], 'senza_data' => null,
            'rendiconti' => $rendiconti, 'compiti' => []];

        $quote = self::quoteScadute((int) $condominio->id, $oggi);
        if ($quote > self::$sogliaQuote) {
            return ['troppe' => ['quote' => $quote, 'frase' => self::FRASE_TROPPE], 'compiti' => $compitiRendiconto] + $esito;
        }

        $c = $this->calcola($condominio, $oggi, $blocco, $risposte, PassoProposto::RIQUADRO, $impostazioni);
        $righe = [];
        $primaAperta = true;
        foreach ($c['righe'] as $eid => $g) {
            $riga = $this->riga($condominio, (int) $eid, $g, $c['persone'], $risposte, $oggi, $proposto);
            // La riga aperta di serie è la prima con la data passata o vicina e con qualcuno da seguire.
            $riga['aperta'] = $primaAperta && $riga['colore'] !== 'lontana' && $riga['quante'] > 0;
            $primaAperta = $primaAperta && ! $riga['aperta'];
            $righe[] = $riga;
        }
        $compiti = array_values(array_map(fn ($r) => self::compitoDellaRiga($r), array_filter($righe, fn ($r) => $r['compito'])));

        return ['righe' => $righe, 'senza_data' => $c['senza_data'], 'compiti' => [...$compiti, ...$compitiRendiconto]] + $esito;
    }

    /**
     * Il riassunto leggero del riquadro di un condominio: per ogni riga la data, i giorni, le persone aperte con la cifra di quell'esercizio, chi è chiuso,
     * la risposta e il compito; i rendiconti da portare in assemblea. Le stesse decisioni del riquadro, con le stesse funzioni (chi è aperto, la cifra, il
     * compito), dalla sola parte che serve: le rate, le date, le dispense e le risposte. Le lettere, gli accordi, il credito e il passo cambiano che cosa si
     * propone a una persona, non chi è aperto, e qui non si leggono. A blocchi di persone, dalla lettura comune dei debiti; di ogni blocco resta il riassunto.
     *
     * @return array{condominio: array{id: int, nome: string}, oggi: string, troppe: ?array, righe: list<array<string, mixed>>, rendiconti: list<array<string, mixed>>}
     */
    public function riassunto(Condominio $condominio, ?string $oggi = null, int $blocco = self::BLOCCO): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $cid = (int) $condominio->id;
        $risposte = $this->risposte->delCondominio($cid);
        $esito = ['condominio' => ['id' => $cid, 'nome' => (string) $condominio->nome], 'oggi' => $oggi, 'troppe' => null, 'righe' => [],
            'rendiconti' => $this->rendiconti($condominio, $oggi, $risposte)];

        $quote = self::quoteScadute($cid, $oggi);
        if ($quote > self::$sogliaQuote) {
            return ['troppe' => ['quote' => $quote, 'frase' => self::FRASE_TROPPE]] + $esito;
        }

        // La data e le regole dell'elenco «Rate scadute» con i filtri di serie, come il riquadro: le stesse persone, le stesse rate.
        $alla = $this->elenco->filtriNormalizzati(['alla' => $oggi])['alla'];
        $calcolo = $this->seiMesi->perCondominio($cid, $alla);
        $righe = [];
        // 493 (12) del registro: come «Rate scadute», il debito ereditato non ancora in una rata sugli eredi e non più sul defunto.
        // Una lettura del debito ereditato con l'elenco della stessa richiesta (P1 della lente P della beta.12).
        foreach ($this->elenco->debitoEreditato()->aBlocchi($this->lettura->perCondominioABlocchi($cid, $alla, $blocco), $cid, $alla, null) as $letture) {
            foreach ($letture as $pid => $l) {
                $perRiga = [];
                foreach ($l['rate'] as $r) {
                    if ($r['residuo'] <= 0 || ! $r['scaduta']) {
                        continue;
                    }
                    $sm = $calcolo->perRiga($r, (int) $pid);
                    $es = $sm['piu_vicina_esercizio'] ?? null;
                    if ($sm['piu_vicina'] !== null && $es !== null) {
                        self::nellaRiga($righe, $perRiga, (int) $es['id'], (string) $es['nome'], (string) $sm['piu_vicina'],
                            AccordiRientro::chiave($r['rata_id'], $r['immobile_id']), (int) $r['residuo'], $sm['piu_vicina_dispensa']);
                    }
                }
                self::chiusure($perRiga, $risposte, $cid, (int) $pid);
                foreach ($perRiga as $eid => $x) {
                    $righe[$eid]['persone'][(int) $pid] = ['cifra' => $x['cifra'], 'chiusa' => $x['chiusa'] === null ? null : ['motivo' => $x['chiusa']['motivo']]];
                }
            }
            unset($letture);
        }
        uasort($righe, fn ($a, $b) => [$a['data'], $a['esercizio']['id']] <=> [$b['data'], $b['esercizio']['id']]);

        // I nomi delle persone aperte, in una lettura.
        $ids = collect($righe)->flatMap(fn ($g) => array_keys(array_filter($g['persone'], fn ($x) => $x['chiusa'] === null)))->unique()->values()->all();
        $nomi = $ids === [] ? collect() : Anagrafica::whereIn('id', $ids)->pluck('nome', 'id');
        foreach ($righe as $eid => $g) {
            $d = self::decisioni($cid, (int) $eid, $g, $risposte, $oggi);
            $persone = [];
            foreach ($d['aperte'] as $pid => $x) {
                $persone[] = ['anagrafica_id' => (int) $pid, 'nome' => (string) ($nomi[$pid] ?? ''), 'cifra' => $x['cifra']];
            }
            usort($persone, fn ($a, $b) => [-$a['cifra'], $a['nome'], $a['anagrafica_id']] <=> [-$b['cifra'], $b['nome'], $b['anagrafica_id']]);
            $chiuse = array_count_values(array_map(fn ($x) => $x['chiusa']['motivo'], array_filter($g['persone'], fn ($x) => $x['chiusa'] !== null)));
            $risposta = self::risposta($d['risposta'], $oggi);
            $esito['righe'][] = [
                'chiave' => 'sei-mesi-' . $eid,
                'esercizio' => $g['esercizio'],
                'data' => $g['data'],
                'giorni' => $d['giorni'],
                'colore' => $d['colore'],
                'quante' => $d['quante'],
                'cifra' => $d['cifra'],
                'persone' => $persone,
                'chiuse' => ['agito' => $chiuse['agito'] ?? 0, 'dispensa' => $chiuse['dispensa'] ?? 0],
                'compito' => $d['compito'],
                'compito_tornato' => $d['tornato'],
                'risposta' => $risposta === null ? null : ['stato' => $risposta['stato'], 'ricordami_il' => $risposta['ricordami_il'], 'frase' => $risposta['frase']],
            ];
        }

        return $esito;
    }

    /**
     * Il condominio nella lista dello studio: le righe con qualcuno da seguire, e i rendiconti con la data entro 60 giorni o passata e non approvati. È il
     * riassunto leggero del riquadro, e dice le stesse cose; le risposte e le azioni sulle persone restano nel riquadro, dove la lista porta.
     *
     * @return array{condominio: array{id: int, nome: string}, oggi: string, troppe: ?array{frase: string}, righe: list<array<string, mixed>>, rendiconti: list<array<string, mixed>>}
     */
    public function perLoStudio(Condominio $condominio, ?string $oggi = null): array
    {
        $r = $this->riassunto($condominio, $oggi);
        $rendiconti = array_filter($r['rendiconti'], fn ($x) => $x['stato'] !== 'approvata' && $x['giorni'] <= self::GIORNI_COMPITO);

        return [
            'condominio' => $r['condominio'],
            'oggi' => $r['oggi'],
            'troppe' => $r['troppe'] === null ? null : ['frase' => $r['troppe']['frase']],
            'righe' => array_values(array_filter($r['righe'], fn ($x) => $x['quante'] > 0)),
            'rendiconti' => array_values(array_map(fn ($x) => [
                'chiave' => 'rendiconto-' . $x['esercizio']['id'],
                'esercizio' => $x['esercizio'],
                'data' => $x['data'],
                'giorni' => $x['giorni'],
                'colore' => $x['colore'],
                'stato' => $x['stato'],
                'breve' => $x['breve'],
                'frase' => $x['frase'],
                'passo' => $x['passo'],
                'url' => $x['url'],
                'compito' => $x['compito'],
                'risposta' => $x['risposta'] === null ? null : ['stato' => $x['risposta']['stato'], 'ricordami_il' => $x['risposta']['ricordami_il'], 'frase' => $x['risposta']['frase']],
            ], $rendiconti)),
        ];
    }

    /**
     * Il passo di ogni persona con rate scadute, nella vista data (la tesoreria lo legge da qui): la stessa regola e gli stessi dati del riquadro. Con
     * `$persone` solo quelle, e la lettura legge solo loro: la tesoreria chiede le persone che mostra, al massimo otto.
     *
     * @param  list<int>|null  $persone
     * @return array<int, array<string, mixed>>
     */
    public function passi(Condominio $condominio, string $vista = PassoProposto::TESORERIA, ?string $oggi = null, ?array $persone = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        if ($persone === []) {
            return [];
        }
        $c = $this->calcola($condominio, $oggi, self::BLOCCO, $this->risposte->delCondominio((int) $condominio->id), $vista, null, $persone);

        return array_map(fn ($p) => $p['passo'], $c['persone']);
    }

    /**
     * Le persone aperte della riga di un esercizio, con la cifra e le rate di oggi: quelle a cui una risposta si può dare.
     *
     * @return array<int, array{nome: string, cifra: int, chiavi: list<string>}>
     */
    public function aperteDellaRiga(Condominio $condominio, int $esercizioId, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $risposte = $this->risposte->delCondominio((int) $condominio->id);
        $c = $this->calcola($condominio, $oggi, self::BLOCCO, $risposte, PassoProposto::RIQUADRO);
        $esito = [];
        foreach ($c['righe'][$esercizioId]['persone'] ?? [] as $pid => $x) {
            if ($x['chiusa'] === null) {
                $esito[(int) $pid] = ['nome' => $c['persone'][$pid]['nome'], 'cifra' => $x['cifra'], 'chiavi' => $x['chiavi']];
            }
        }

        return $esito;
    }

    /**
     * La lettura a blocchi e il riassunto: per ogni riga (esercizio) le persone con la cifra, le rate, la chiusura; per ogni persona il nome, le unità, il
     * credito, la situazione e il passo; le rate senza data.
     *
     * @param Collection<string, RispostaPromemoria> $risposte
     * @return array{righe: array<int, array<string, mixed>>, persone: array<int, array<string, mixed>>, senza_data: ?array<string, mixed>}
     */
    private function calcola(Condominio $condominio, string $oggi, int $blocco, Collection $risposte, string $vista, ?ImpostazioniCondominio $impostazioni = null, ?array $solo = null): array
    {
        $cid = (int) $condominio->id;
        $filtri = $this->elenco->filtriNormalizzati(['alla' => $oggi]);
        $tolleranza = RegoleDelRecupero::tolleranza((new RegoleDelRecupero())->delCondominio($cid), $oggi);
        $salita = (string) ($impostazioni ?? ImpostazioniCondominio::delCondominio($cid))->recupero_salita;
        $righe = [];
        $persone = [];
        $senzaData = ['rate' => 0, 'persone' => [], 'cifra' => 0, 'perche' => [], 'per_persona' => [], 'righe' => []];
        // Il giorno dell'ultimo fascicolo per il legale di ogni persona (la riga della persona nelle risposte): la regola lo confronta con l'ultima lettera (R6).
        $fascicoli = DB::table('risposte_promemoria')->where('condominio_id', $cid)->where('argomento', RispostePromemoria::PERSONA)->whereNotNull('giorno')
            ->pluck('giorno', 'anagrafica_id')->map(fn ($g) => substr((string) $g, 0, 10))->all();

        foreach ($this->elenco->righeABlocchi($condominio, $filtri, $solo, false, $blocco, true) as $bloccoRighe) {
            $crediti = $this->crediti->perCondominio($cid, $bloccoRighe->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->keyBy('anagrafica_id');
            foreach ($bloccoRighe as $riga) {
                $pid = (int) $riga['anagrafica_id'];
                $perRiga = [];
                $rateRegola = [];
                foreach ($riga['rate'] as $d) {
                    $sm = $d['sei_mesi'];
                    $chiave = AccordiRientro::chiave($d['rata_id'], $d['immobile_id']);
                    $es = $sm['piu_vicina_esercizio'] ?? null;
                    $eid = $sm['piu_vicina'] !== null && $es !== null ? (int) $es['id'] : null;
                    $perche = $eid === null ? SeiMesi::percheSenzaData($sm) : null;
                    if ($eid === null) {
                        $senzaData['rate']++;
                        $senzaData['cifra'] += (int) $d['residuo'];
                        $senzaData['persone'][$pid] = true;
                        $senzaData['per_persona'][$pid] = ($senzaData['per_persona'][$pid] ?? 0) + (int) $d['residuo'];
                        $senzaData['perche'][$perche] = true;
                        // La riga della persona: il perché di ogni rata e la prossima azione che chiede, una volta per perché.
                        $motivo = SeiMesi::senzaData($sm);
                        $senzaData['righe'][$pid] ??= ['anagrafica_id' => $pid, 'nome' => (string) $riga['nome'], 'unita' => array_values($riga['unita']), 'cifra' => 0, 'motivi' => []];
                        $senzaData['righe'][$pid]['cifra'] += (int) $d['residuo'];
                        if ($motivo !== null) {
                            $chiaveMotivo = $motivo['caso'] . '|' . ($motivo['azione']['tipo'] === 'piano' ? (int) ($d['piano_rate_id'] ?? 0) : '');
                            $senzaData['righe'][$pid]['motivi'][$chiaveMotivo] ??= $motivo + ($motivo['azione']['tipo'] === 'piano' ? ['piano_rate_id' => (int) ($d['piano_rate_id'] ?? 0)] : []);
                        }
                    } else {
                        self::nellaRiga($righe, $perRiga, $eid, (string) $es['nome'], (string) $sm['piu_vicina'], $chiave, (int) $d['residuo'], $sm['piu_vicina_dispensa']);
                    }
                    $rateRegola[] = ['chiave' => $chiave, 'residuo' => (int) $d['residuo'], 'scadenza' => $d['scadenza'], 'giorni_ritardo' => $d['giorni_ritardo'],
                        'data' => $sm['piu_vicina'], 'dispensa' => $sm['piu_vicina_dispensa'], 'senza_data' => $perche,
                        'in_accordo' => (int) ($d['accordo']['in_accordo'] ?? 0), 'fuori_dalle_lettere' => (bool) ($d['accordo']['fuori_dalle_lettere'] ?? false), 'eid' => $eid];
                }

                [$agiti, $chiuseDaAgito] = self::chiusure($perRiga, $risposte, $cid, $pid);
                $aperte = array_values(array_filter($rateRegola, fn ($r) => $r['eid'] === null || ! isset($chiuseDaAgito[$r['eid']])));
                $perTipo = $riga['lettere_per_tipo'] ?? ['diffida' => null, 'sollecito' => null, 'promemoria' => null];
                $accordi = array_values($riga['accordi'] ?? []);
                $passo = PassoProposto::per(['rate' => $aperte, 'agito' => $agiti, 'tornati' => [], 'credito' => $crediti[$pid]['compensabile'] ?? null, 'accordi' => $accordi,
                    'lettere' => $perTipo, 'fascicolo' => $fascicoli[$pid] ?? null, 'tolleranza' => $tolleranza, 'salita' => $salita], $oggi, $vista);
                $persone[$pid] = [
                    'nome' => (string) $riga['nome'],
                    'unita' => array_values($riga['unita']),
                    'credito' => (int) ($crediti[$pid]['totale_cents'] ?? 0),
                    'situazione' => self::situazione($perTipo, $accordi),
                    'diffida' => $perTipo['diffida']['stato'] ?? null,
                    'passo' => $passo,
                    'lettere_da_spedire' => collect($perTipo)->filter(fn ($l) => ($l['stato'] ?? null) === 'stampata')->flatMap(fn ($l) => $l['invii'] ?? [])->values()->all(),
                ];
                foreach ($perRiga as $eid => $x) {
                    $righe[$eid]['persone'][$pid] = ['cifra' => $x['cifra'], 'chiavi' => $x['chiavi'], 'residui' => $x['residui'], 'chiusa' => $x['chiusa'], 'torna' => $x['torna']];
                }
            }
        }

        uasort($righe, fn ($a, $b) => [$a['data'], $a['esercizio']['id']] <=> [$b['data'], $b['esercizio']['id']]);

        return ['righe' => $righe, 'persone' => $persone, 'senza_data' => $senzaData['rate'] === 0 ? null : [
            'rate' => $senzaData['rate'], 'persone' => count($senzaData['persone']), 'cifra' => $senzaData['cifra'], 'perche' => array_keys($senzaData['perche']),
            'per_persona' => $senzaData['per_persona'], 'righe' => self::righeSenzaData($senzaData['righe']),
        ]];
    }

    /**
     * Le persone con rate senza la data dei sei mesi, la cifra più alta prima: ognuna con i suoi perché e la prossima azione di ognuno. L'azione sul piano
     * porta il piano e il suo esercizio, che la pagina apre.
     *
     * @param array<int, array<string, mixed>> $righe
     * @return list<array<string, mixed>>
     */
    private static function righeSenzaData(array $righe): array
    {
        $piani = collect($righe)->flatMap(fn ($r) => array_column($r['motivi'], 'piano_rate_id'))->filter()->unique()->values()->all();
        $esercizi = $piani === [] ? collect() : DB::table('piani_rate')->whereIn('id', $piani)->pluck('esercizio_id', 'id');
        $esito = array_map(function (array $r) use ($esercizi) {
            $r['motivi'] = array_values(array_map(function (array $m) use ($esercizi) {
                if (isset($m['piano_rate_id'])) {
                    $esercizio = $esercizi->get($m['piano_rate_id']);
                    $m['azione']['piano'] = ['id' => $m['piano_rate_id'], 'esercizio_id' => $esercizio === null ? null : (int) $esercizio];
                    unset($m['piano_rate_id']);
                }

                return $m;
            }, $r['motivi']));

            return $r;
        }, array_values($righe));
        usort($esito, fn ($a, $b) => [-$a['cifra'], $a['nome'], $a['anagrafica_id']] <=> [-$b['cifra'], $b['nome'], $b['anagrafica_id']]);

        return $esito;
    }

    /**
     * Una riga per la pagina: la data, i giorni, il colore, le persone aperte (fino a 8 tutte, oltre le 5 con la cifra più alta), le chiuse, i cartellini, i
     * pulsanti in blocco, la risposta e il compito.
     *
     * @param Collection<string, RispostaPromemoria> $risposte
     */
    private function riga(Condominio $condominio, int $eid, array $g, array $persone, Collection $risposte, string $oggi, bool $proposto): array
    {
        $decisioni = self::decisioni((int) $condominio->id, $eid, $g, $risposte, $oggi);
        $giorni = $decisioni['giorni'];
        $aperte = [];
        $chiuse = [];
        foreach ($g['persone'] as $pid => $x) {
            $p = $persone[$pid];
            if ($x['chiusa'] !== null) {
                $chiuse[] = ['anagrafica_id' => $pid, 'nome' => $p['nome'], 'cifra' => $x['cifra']] + $x['chiusa'];

                continue;
            }
            $passo = $p['passo'];
            $aperte[] = ['anagrafica_id' => $pid, 'nome' => $p['nome'], 'unita' => $p['unita'], 'cifra' => $x['cifra'], 'credito' => $p['credito'], 'situazione' => $p['situazione'],
                // Con «Scelgo io il passo» nessun passo segnato, e la frase dei fatti (nella tolleranza non dice da quando si propone il sollecito).
                'passo' => $proposto ? $passo : ['passo' => null, 'frase' => $passo['frase_dei_fatti']] + $passo, 'passo_della_regola' => $passo['passo'], 'torna' => $x['torna'],
                'lettere_da_spedire' => $p['lettere_da_spedire'], 'diffida' => $p['diffida']];
        }
        usort($aperte, fn ($a, $b) => [-$a['cifra'], $a['nome'], $a['anagrafica_id']] <=> [-$b['cifra'], $b['nome'], $b['anagrafica_id']]);
        usort($chiuse, fn ($a, $b) => [$a['nome'], $a['anagrafica_id']] <=> [$b['nome'], $b['anagrafica_id']]);

        $mostrate = count($aperte) <= self::TUTTE_FINO_A ? $aperte : array_slice($aperte, 0, self::MOSTRATE_OLTRE);
        $nascoste = array_slice($aperte, count($mostrate));
        $cartellini = ['senza_lettera' => 0, 'promemoria' => 0, 'sollecito' => 0, 'diffida_spedita' => 0, 'diffida_da_spedire' => 0, 'accordo' => 0,
            'dispensata' => count(array_filter($chiuse, fn ($c) => $c['motivo'] === 'dispensa'))];
        foreach ($aperte as $p) {
            match ($p['situazione']) {
                'diffida' => $p['diffida'] === 'stampata' ? $cartellini['diffida_da_spedire']++ : $cartellini['diffida_spedita']++,
                default => $cartellini[$p['situazione']]++,
            };
        }
        $inBlocco = [];
        foreach ([PassoProposto::DIFFIDA, PassoProposto::SOLLECITO, PassoProposto::SPEDIZIONE] as $passo) {
            if ($passo !== PassoProposto::SPEDIZIONE && ! $proposto) {
                continue;
            }
            $chi = array_values(array_filter($aperte, fn ($p) => $p['passo_della_regola'] === $passo));
            if (count($chi) >= 2) {
                $inBlocco[] = ['passo' => $passo, 'persone' => array_column($chi, 'anagrafica_id'),
                    'lettere' => $passo === PassoProposto::SPEDIZIONE ? array_values(array_merge(...array_column($chi, 'lettere_da_spedire'))) : []];
            }
        }

        return [
            'chiave' => 'sei-mesi-' . $eid,
            'esercizio' => $g['esercizio'],
            'data' => $g['data'],
            'giorni' => $giorni,
            'colore' => $decisioni['colore'],
            'aperta' => false,
            'piu_avanti' => $giorni > self::GIORNI_COMPITO,
            'quante' => $decisioni['quante'],
            'cifra' => $decisioni['cifra'],
            'cartellini' => $cartellini,
            'persone' => array_map(fn ($p) => array_diff_key($p, ['passo_della_regola' => true, 'diffida' => true]), $mostrate),
            'oltre' => $nascoste === [] ? null : ['mancano' => count($nascoste), 'cifra' => (int) array_sum(array_column($nascoste, 'cifra'))],
            'tutte' => array_map(fn ($p) => ['anagrafica_id' => $p['anagrafica_id'], 'nome' => $p['nome'], 'cifra' => $p['cifra'], 'situazione' => $p['situazione']], $aperte),
            'chiuse' => $chiuse,
            'in_blocco' => $inBlocco,
            'risposta' => self::risposta($decisioni['risposta'], $oggi),
            'compito' => $decisioni['compito'],
            'compito_tornato' => $decisioni['tornato'],
        ];
    }

    /**
     * I rendiconti da portare in assemblea: gli esercizi finiti, non chiusi, con il rendiconto non approvato; e l'ultimo finito anche approvato, fino alla data.
     *
     * @param Collection<string, RispostaPromemoria> $risposte
     * @return list<array<string, mixed>>
     */
    private function rendiconti(Condominio $condominio, string $oggi, Collection $risposte): array
    {
        $finiti = Esercizio::where('condominio_id', $condominio->id)->whereNotNull('data_fine')->where('data_fine', '<', $oggi)
            ->orderBy('data_fine')->orderBy('id')->get(['id', 'nome', 'data_fine', 'stato']);
        if ($finiti->isEmpty()) {
            return [];
        }
        $ultimo = (int) $finiti->last()->id;
        $stati = VersioniDelRendiconto::statiInTesta($finiti->pluck('id')->map(fn ($id) => (int) $id)->all());
        $esito = [];
        foreach ($finiti as $e) {
            if ($e->stato === 'chiuso') {
                continue;
            }
            $st = $stati[(int) $e->id];
            $data = self::dataDelRendiconto((string) $e->data_fine);
            $giorni = self::giorni($data, $oggi);
            if ($st['stato'] === 'approvata' && ((int) $e->id !== $ultimo || $giorni < 0)) {
                continue;
            }
            $risposta = $risposte[RispostePromemoria::chiave(RispostePromemoria::RENDICONTO, (int) $condominio->id, (int) $e->id, null)] ?? null;
            [$chiuso, $tornato] = self::chiusoDallaRisposta($risposta, [], $oggi);
            $giornoAgenda = RispostePromemoria::giornoDellEvento($risposta?->agenda);
            $esito[] = [
                'esercizio' => ['id' => (int) $e->id, 'nome' => (string) $e->nome],
                'data' => $data,
                'giorni' => $giorni,
                'colore' => match ($st['stato']) { 'approvata' => 'approvato', 'presentata' => 'fermato', default => self::colore($giorni) },
                'stato' => $st['stato'],
                'frase' => $st['frase'],
                'breve' => $st['breve'],
                'passo' => match ($st['stato']) { 'bozza' => 'apri', 'presentata' => 'approvazione', default => null },
                'url' => route('admin.gestionale.esercizi.rendiconto.sintesi', ['condominio' => $condominio->id, 'esercizio' => $e->id]),
                'agenda' => $giornoAgenda === null ? null : ['giorno' => $giornoAgenda],
                'risposta' => self::risposta($risposta, $oggi),
                'compito' => (int) $e->id === $ultimo && $st['stato'] === 'bozza' && $giorni <= self::GIORNI_COMPITO && ! $chiuso,
                'compito_tornato' => $tornato,
                'ultimo' => (int) $e->id === $ultimo,
            ];
        }
        usort($esito, fn ($a, $b) => [$a['data'], $a['esercizio']['id']] <=> [$b['data'], $b['esercizio']['id']]);

        return $esito;
    }

    /**
     * Una rata di una persona nella riga del suo esercizio: la cifra, la chiave, il residuo e la dispensa della data. La stessa somma per il riquadro e per il
     * riassunto della lista dello studio.
     *
     * @param array<int, array<string, mixed>> $righe
     * @param array<int, array<string, mixed>> $perRiga le righe della persona
     */
    private static function nellaRiga(array &$righe, array &$perRiga, int $eid, string $esercizio, string $data, string $chiave, int $residuo, ?array $dispensa): void
    {
        $righe[$eid] ??= ['esercizio' => ['id' => $eid, 'nome' => $esercizio], 'data' => $data, 'persone' => []];
        $perRiga[$eid] ??= ['cifra' => 0, 'chiavi' => [], 'residui' => [], 'dispensate' => 0, 'rate' => 0, 'dispensa' => null];
        $perRiga[$eid]['cifra'] += $residuo;
        $perRiga[$eid]['chiavi'][] = $chiave;
        $perRiga[$eid]['residui'][$chiave] = $residuo;
        $perRiga[$eid]['rate']++;
        if ($dispensa !== null) {
            $perRiga[$eid]['dispensate']++;
            $perRiga[$eid]['dispensa'] ??= $dispensa;
        }
    }

    /**
     * Chi è chiuso nelle righe di una persona: la dispensa su tutte le sue rate della riga, o «hai agito» ancora valido; e chi torna, con la frase. Scrive
     * `chiusa` e `torna` in ogni riga della persona; rende gli «hai agito» validi (la regola del passo li legge) e le righe che chiudono.
     *
     * @param array<int, array<string, mixed>> $perRiga
     * @param Collection<string, RispostaPromemoria> $risposte
     * @return array{0: list<array<string, mixed>>, 1: array<int, true>}
     */
    private static function chiusure(array &$perRiga, Collection $risposte, int $cid, int $pid): array
    {
        $agiti = [];
        $chiuseDaAgito = [];
        foreach ($perRiga as $eid => $x) {
            $r = $risposte[RispostePromemoria::chiave(RispostePromemoria::SEI_MESI, $cid, $eid, $pid)] ?? null;
            $perRiga[$eid]['torna'] = null;
            $perRiga[$eid]['chiusa'] = null;
            if ($x['dispensate'] === $x['rate']) {
                $perRiga[$eid]['chiusa'] = ['motivo' => 'dispensa', 'frase' => self::fraseDispensa($x['dispensa'])];
            }
            if ($r !== null && $r->stato === RispostePromemoria::AGITO) {
                $torna = self::torna($r, $x);
                if ($torna === null) {
                    $agito = ['giorno' => $r->giorno->format('Y-m-d'), 'nota' => (string) $r->nota, 'utente' => self::chiHaAgito($r)];
                    $agiti[] = $agito;
                    $chiuseDaAgito[$eid] = true;
                    $perRiga[$eid]['chiusa'] ??= ['motivo' => 'agito', 'frase' => PassoProposto::fraseAgito($agito)];
                } else {
                    $perRiga[$eid]['torna'] = $torna;
                }
            }
        }

        return [$agiti, $chiuseDaAgito];
    }

    /**
     * Ciò che una riga decide, uguale nel riquadro e nella lista dello studio: i giorni, il colore, le persone aperte con la cifra della riga, la risposta,
     * se c'è il compito e perché è tornato.
     *
     * @param array<string, mixed> $g la riga, con `data` e le `persone` (per id: `cifra`, `chiusa`)
     * @param Collection<string, RispostaPromemoria> $risposte
     * @return array{giorni: int, colore: string, aperte: array<int, array<string, mixed>>, quante: int, cifra: int, risposta: ?RispostaPromemoria, compito: bool, tornato: ?string}
     */
    private static function decisioni(int $cid, int $eid, array $g, Collection $risposte, string $oggi): array
    {
        $giorni = self::giorni($g['data'], $oggi);
        $aperte = array_filter($g['persone'], fn ($x) => $x['chiusa'] === null);
        $risposta = $risposte[RispostePromemoria::chiave(RispostePromemoria::SEI_MESI, $cid, $eid, null)] ?? null;
        [$chiuso, $tornato] = self::chiusoDallaRisposta($risposta, array_map('intval', array_keys($aperte)), $oggi);

        return [
            'giorni' => $giorni,
            'colore' => self::colore($giorni),
            'aperte' => $aperte,
            'quante' => count($aperte),
            'cifra' => (int) array_sum(array_column($aperte, 'cifra')),
            'risposta' => $risposta,
            'compito' => $aperte !== [] && $giorni <= self::GIORNI_COMPITO && ! $chiuso,
            'tornato' => $tornato,
        ];
    }

    /**
     * Se la risposta tiene chiuso il compito, e perché è tornato: «Non serve» lo chiude finché non compare una persona nuova; «Ricordamelo» lo nasconde fino
     * al giorno del promemoria nell'agenda, e se il promemoria non c'è più il compito torna; «Fatto» del rendiconto lo chiude.
     *
     * @param list<int> $aperte
     * @return array{0: bool, 1: ?string}
     */
    private static function chiusoDallaRisposta(?RispostaPromemoria $r, array $aperte, string $oggi): array
    {
        if ($r === null) {
            return [false, null];
        }
        if ($r->stato === RispostePromemoria::NON_SERVE) {
            $allora = array_map('intval', (array) ($r->dati['persone'] ?? []));
            $nuove = $r->argomento === RispostePromemoria::SEI_MESI ? array_diff($aperte, $allora) : [];

            return $nuove === [] ? [true, null] : [false, self::FRASE_TORNATO_NON_SERVE];
        }
        if ($r->stato === RispostePromemoria::RICORDAMELO) {
            $giorno = RispostePromemoria::giornoDellEvento($r->promemoria);
            if ($giorno === null) {
                return [false, self::FRASE_PROMEMORIA_TOLTO];
            }

            return [$oggi < $giorno, null];
        }
        if ($r->stato === RispostePromemoria::FATTO && $r->argomento === RispostePromemoria::RENDICONTO) {
            return [true, null];
        }

        return [false, null];
    }

    /** La risposta di una riga, per la pagina: lo stato, la frase, la nota, il registro. */
    private static function risposta(?RispostaPromemoria $r, string $oggi): ?array
    {
        if ($r === null) {
            return null;
        }
        // La riga del registro dello stato di oggi: il giorno e chi l'ha scritto sono i suoi, non quelli di un gesto venuto dopo (un «Mettilo in agenda»).
        $ultima = collect($r->registro ?? [])->last(fn ($x) => ($x['tipo'] ?? null) === $r->stato) ?? collect($r->registro ?? [])->last();
        $quando = $ultima === null ? null : CarbonImmutable::parse($ultima['il'])->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
        $giornoPromemoria = RispostePromemoria::giornoDellEvento($r->promemoria);
        $frase = match ($r->stato) {
            RispostePromemoria::NON_SERVE => 'Non serve: «' . trim((string) $r->nota) . '» (' . $quando . (filled($ultima['utente'] ?? null) ? ', ' . $ultima['utente'] : '') . ').',
            // Dal giorno del promemoria il compito è tornato: la riga dice che te l'ha ricordato, non che te lo ricorderà.
            RispostePromemoria::RICORDAMELO => $giornoPromemoria === null ? self::FRASE_PROMEMORIA_TOLTO
                : ($oggi < $giornoPromemoria ? 'Ti ricordo il ' : 'Te l\'ho ricordato il ') . CarbonImmutable::parse($giornoPromemoria)->format('d/m/Y') . '.',
            RispostePromemoria::FATTO => 'Fatto il ' . ($r->giorno?->format('d/m/Y') ?? $quando)
                . (($n = count((array) ($r->dati['persone'] ?? []))) > 0 ? ' per ' . ($n === 1 ? '1 persona' : $n . ' persone') : '') . '.',
            default => null,
        };

        return ['stato' => $r->stato, 'frase' => $frase, 'nota' => $r->nota, 'ricordami_il' => $giornoPromemoria,
            'registro' => \App\Services\Gestionale\RegistroDelGesto::perLaPagina((array) ($r->registro ?? []))];
    }

    /** Il compito della riga dei sei mesi, per l'Inbox. */
    private static function compitoDellaRiga(array $r): array
    {
        return ['chiave' => $r['chiave'], 'argomento' => RispostePromemoria::SEI_MESI, 'esercizio' => $r['esercizio'], 'data' => $r['data'], 'giorni' => $r['giorni'],
            'colore' => $r['colore'], 'quante' => $r['quante'], 'cifra' => $r['cifra'], 'cartellini' => $r['cartellini'], 'tornato' => $r['compito_tornato'],
            'ancora' => $r['chiave'], 'tutte' => $r['tutte']];
    }

    /** Il compito del rendiconto, per l'Inbox. */
    private static function compitoDelRendiconto(array $r): array
    {
        return ['chiave' => 'rendiconto-' . $r['esercizio']['id'], 'argomento' => RispostePromemoria::RENDICONTO, 'esercizio' => $r['esercizio'], 'data' => $r['data'],
            'giorni' => $r['giorni'], 'colore' => $r['colore'], 'frase' => $r['frase'], 'url' => $r['url'], 'agenda' => $r['agenda'], 'tornato' => $r['compito_tornato'],
            'ancora' => 'rendiconto-' . $r['esercizio']['id']];
    }

    /**
     * Se «hai agito» non vale più: una rata della riga che non c'era (la cifra delle nuove), o la cifra della riga salita. Il giorno è quello in cui
     * l'amministratore ha registrato, non quello che ha dichiarato.
     */
    private static function torna(RispostaPromemoria $r, array $x): ?string
    {
        $dati = (array) ($r->dati ?? []);
        $quando = 'Dopo che hai registrato (il ' . CarbonImmutable::parse((string) ($dati['registrato_il'] ?? $r->updated_at?->format('Y-m-d')))->format('d/m/Y') . ')';
        $prima = array_flip(array_map('strval', (array) ($dati['chiavi'] ?? [])));
        $nuove = array_values(array_filter($x['chiavi'], fn ($k) => ! isset($prima[$k])));
        if ($nuove !== []) {
            $cifra = (int) array_sum(array_map(fn ($k) => $x['residui'][$k] ?? 0, $nuove));

            return $quando . ', ' . (count($nuove) === 1 ? 'c\'è una rata nuova' : 'ci sono ' . count($nuove) . ' rate nuove') . ' di questo esercizio: ' . self::euro($cifra) . '.';
        }
        if ($x['cifra'] > (int) ($dati['cifra'] ?? 0)) {
            return $quando . ', le rate di questo esercizio sono salite da ' . self::euro((int) ($dati['cifra'] ?? 0)) . ' a ' . self::euro($x['cifra']) . '.';
        }

        return null;
    }

    /** Chi ha registrato «hai agito» l'ultima volta. */
    private static function chiHaAgito(RispostaPromemoria $r): ?string
    {
        $riga = collect($r->registro ?? [])->last(fn ($x) => ($x['tipo'] ?? null) === RispostePromemoria::AGITO);

        return filled($riga['utente'] ?? null) ? (string) $riga['utente'] : null;
    }

    /**
     * A che punto è la persona, per i cartellini e il filtro della finestra «Fatto»: l'accordo in corso, la diffida, il sollecito, il promemoria; «senza lettera»
     * solo senza nessuna lettera uscita.
     */
    private static function situazione(array $perTipo, array $accordi): string
    {
        $uscita = ['stampata', 'inviata', 'spedita'];

        return match (true) {
            collect($accordi)->contains(fn ($s) => ! ($s['fino_al_passato'] ?? false)) => 'accordo',
            in_array($perTipo['diffida']['stato'] ?? null, $uscita, true) => 'diffida',
            in_array($perTipo['sollecito']['stato'] ?? null, $uscita, true) => 'sollecito',
            in_array($perTipo['promemoria']['stato'] ?? null, $uscita, true) => 'promemoria',
            default => 'senza_lettera',
        };
    }

    private static function fraseDispensa(?array $d): string
    {
        if ($d === null) {
            return '';
        }

        return 'Dispensa dell\'assemblea del ' . CarbonImmutable::parse($d['data_delibera'])->format('d/m/Y')
            . (filled($d['numero_verbale'] ?? null) ? ' (verbale n. ' . $d['numero_verbale'] . ')' : '') . ': per queste rate il programma non suggerisce azioni.';
    }

    private static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}
