<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Treasury;

use App\Support\Treasury\TreasuryStatus;
use App\Support\Treasury\AzioneSuggerita;
use App\Enums\TipoAllocazioneFattura;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Immobile;
use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Services\Gestionale\Debiti\PassoProposto;
use App\Services\Gestionale\Debiti\Scadenza;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/**
 * La «Tesoreria a 30 giorni» della dashboard del condominio: il disponibile di oggi e da dove viene ({@see DisponibilitaDiOggi}), le uscite previste (le
 * fatture da pagare), le entrate attese (le rate dei condòmini, «se arrivano»), il saldo previsto giorno per giorno ({@see TreasuryTimelineBuilder}), il
 * semaforo, e le due liste che ne dicono il perché: le fatture in scadenza e le persone con rate scadute, ognuna con la sua prossima azione.
 *
 * **La regola delle 8**: fino a 8 righe tutte; oltre, le prime 5 e quante mancano con la loro cifra (niente scorrimento dentro la dashboard). Le persone
 * mostrate hanno la prossima azione della regola dei sei mesi ({@see PassoProposto}, vista tesoreria), letta per loro sole.
 *
 * **Il peso**: le rate dei condòmini si leggono a blocchi di persone ({@see LetturaDebiti::perCondominioABlocchi()}): di ogni persona resta il riassunto, e
 * la timeline tiene gli eventi già sommati per giorno.
 *
 * **I tre scenari e le azioni consigliate**: il saldo previsto porta, giorno per giorno, le rate che scadono quel giorno e le fatture,
 * e a parte le rate già scadute. Il recupero ({@see self::recupero()}): le persone con le rate scadute, le più alte prima, quante bastano a coprire la cifra che
 * tiene a zero o sopra lo scenario «Con le rate in scadenza»; per ogni persona di ognuna resta solo la cifra, i nomi e le lettere si leggono per le mostrate.
 * I fatti delle altre azioni (le rate da emettere, i bonifici segnalati, le ritenute, i deficit ereditati, i conti e i fondi) li legge {@see DatiDelleAzioni}.
 */
final class TreasuryGuardianService
{
    /** Fino a 8 righe si mostrano tutte; oltre, le prime 5 e la riga di fondo. */
    public const TUTTE_FINO_A = 8;
    public const MOSTRATE_OLTRE = 5;

    /** Le persone lette insieme per le rate scadute: la memoria resta quella di un blocco. */
    public const BLOCCO = 250;

    public function __construct(
        private readonly TreasuryTimelineBuilder $timeline,
        private readonly LetturaDebiti $lettura,
        private readonly \App\Services\Gestionale\CreditoService $crediti,
        // Facoltativo: chi costruisce il servizio a mano con le tre letture di prima lo riceve dal contenitore.
        private ?\App\Services\Gestionale\Debiti\AccordiRientro $accordi = null,
        private ?DisponibilitaDiOggi $disponibilita = null,
    ) {
        $this->accordi ??= app(\App\Services\Gestionale\Debiti\AccordiRientro::class);
        $this->disponibilita ??= app(DisponibilitaDiOggi::class);
    }

    public function perCondominio(int $condominioId, ?int $gestioneId = null, int $giorni = 30): TreasuryStatus
    {
        // «Oggi» è il giorno dell'amministratore, non quello UTC del server.
        $oggi = CarbonImmutable::parse(DateHelper::oggiUtente());
        $limite = $oggi->addDays($giorni);
        $condominio = Condominio::findOrFail($condominioId);

        // 1. Il disponibile di oggi: la liquidità della fotografia meno i fondi che non si possono usare, con le sue righe.
        // Non troncare il disponibile con max(0, ...): se è negativo, lo scoperto è già in atto e la timeline deve partire dal saldo reale.
        $disponibile = $this->disponibilita->perCondominio($condominio, $oggi->toDateString(), $giorni);
        $liquiditaTotaleCents = $disponibile['totale'];
        $liquiditaDisponibileCents = $disponibile['cifra'];
        $liquiditaVincolataCents = $liquiditaTotaleCents - $liquiditaDisponibileCents;

        // §6.4: Verifica se esiste un fondo riserva per mostrare avviso non bloccante nel frontend
        $hasFondoRiserva = $this->condominioHaFondoRiserva($condominioId);

        // 2. Inizializza variabili
        $uscitePredittiveCents = 0;
        $debitiPregressiScadutiCents = 0;
        $fatture = [];
        $fattureSenzaScadenza = [];

        $this->timeline->reset()
                       ->setLiquiditaIniziale($liquiditaDisponibileCents)
                       ->setFinestra($giorni);

        // I movimenti già registrati con una data dopo oggi, entro la finestra: al loro giorno, in tutti e due gli scenari.
        foreach ($disponibile['futuri']['nel_flusso'] as $giorno => $movimenti) {
            foreach ($movimenti as $m) {
                $this->timeline->addRegistrato($m['voce'] !== '' ? $m['voce'] : 'Movimento registrato', $m['importo'], $giorno);
            }
        }

        // 3. Uscite Predittive (Fatture Passive)
        foreach ($this->queryFattureAperte($condominioId, $gestioneId) as $f) {
            $residuo = $f->netto_a_pagare - ($f->allocazioni_pagamento_sum_importo_allocato ?? 0);
            if ($residuo <= 0) continue;

            // §6.2 Anomalia: fattura aperta con data_scadenza NULL
            // Non scartarla in silenzio — segregarla come anomalia.
            // NON entra nelle uscite predittive né nella timeline.
            if (empty($f->data_scadenza)) {
                $fattureSenzaScadenza[] = [
                    'fornitore'    => $f->fornitore->ragione_sociale ?? '(sconosciuto)',
                    'numero'       => $f->numero_documento,
                    'importoCents' => $residuo,
                ];
                continue;
            }

            $scadenza = CarbonImmutable::parse($f->data_scadenza);
            $giornoScadenza = $scadenza->toDateString();

            // Segregazione Pregressi Scaduti (§6.7)
            if ($f->is_pregresso && $giornoScadenza < $oggi->toDateString()) {
                $debitiPregressiScadutiCents += $residuo;
                continue; // Non entra nella timeline a 30gg!
            }

            // Filtro finestra (esclude le fatture future oltre i 30gg)
            if ($giornoScadenza > $limite->toDateString()) {
                continue;
            }

            $fornitoreNome = $f->fornitore->ragione_sociale ?? '(sconosciuto)';

            // Entra nella timeline (le scadute non pregresse vanno a day 0 grazie al clamp del TimelineBuilder), con la fattura per l'elenco del giorno.
            $this->timeline->addUscita($fornitoreNome . ' — n. ' . $f->numero_documento, $residuo, $giornoScadenza,
                ['id' => (int) $f->id, 'fornitore' => $fornitoreNome, 'numero' => (string) $f->numero_documento]);
            $uscitePredittiveCents += $residuo;

            $fatture[] = [
                'id'           => (int) $f->id,
                'fornitore'    => $fornitoreNome,
                'numero'       => $f->numero_documento,
                'dataScadenza' => $giornoScadenza,
                'scaduta'      => $giornoScadenza < $oggi->toDateString(),
                'importoCents' => $residuo,
            ];
        }
        // In ordine di scadenza, la più vicina prima; a pari giorno la cifra più alta.
        usort($fatture, fn ($a, $b) => [$a['dataScadenza'], -$a['importoCents'], $a['id']] <=> [$b['dataScadenza'], -$b['importoCents'], $b['id']]);
        $fattureMostrate = count($fatture) <= self::TUTTE_FINO_A ? $fatture : array_slice($fatture, 0, self::MOSTRATE_OLTRE);
        $fattureFuori = array_slice($fatture, count($fattureMostrate));

        // 4. Incassi attesi e rate scadute, dalla lettura comune dei debiti
        $attesi = $this->incassiAttesi($condominioId, $gestioneId, $oggi, $limite);
        $incassiAttesiCents = $attesi['scaduti'] + $attesi['in_scadenza'];

        // 5. Build Timeline
        $risultato = $this->timeline->build();

        // 6. Calcolo Livello Semaforo: sul giorno più basso dei 30, non solo sull'ultimo. Un saldo che va sotto zero il 18/11 e torna sopra il 25/11 non copre la
        // fattura del 18/11: il grafico lo mostra rosso, e il semaforo non può dire che va tutto bene. Rosso se va sotto zero anche con le entrate attese;
        // giallo se va sotto zero senza.
        $livello = 'verde';
        if (min($risultato['scenarioOttimisticoCents'], $risultato['scopertoMaxConEntrateCents']) < 0) {
            $livello = 'rosso';
        } elseif (min($risultato['scenarioPessimisticoCents'], $risultato['scopertoMaxCents']) < 0) {
            $livello = 'giallo';
        }

        // 7. Generazione Azioni Suggerite
        $azioni = $this->generaAzioniSuggerite($condominioId, $livello, $risultato['scenarioPessimisticoCents'], $incassiAttesiCents, $debitiPregressiScadutiCents);

        // 8. Le persone del recupero: quante bastano perché il saldo con le rate in scadenza non vada sotto zero.
        $recupero = $this->recupero($condominioId, $attesi['scaduti_per_persona'], $attesi['unita_per_persona'], -min(0, $risultato['scopertoMaxConRateCents']),
            $oggi->toDateString());
        unset($attesi['scaduti_per_persona'], $attesi['unita_per_persona']);

        // 9. La prossima azione delle persone mostrate, con la regola dei sei mesi (vista tesoreria) e la scelta del condominio.
        $suggerisce = (bool) ImpostazioniCondominio::delCondominio($condominioId)->recupero_suggerisce_azione;
        $passi = app(SeiMesiPerAgire::class)->passi($condominio, PassoProposto::TESORERIA, $oggi->toDateString(), array_column($attesi['morosita'], 'anagraficaId'));
        $morosita = array_map(fn ($m) => $m + ['passo' => self::passoPerLaRiga($passi[$m['anagraficaId']] ?? null, $suggerisce)], $attesi['morosita']);

        // 10. I fatti delle altre azioni consigliate: le rate da emettere, i bonifici segnalati, le ritenute, i deficit ereditati, i conti e i fondi.
        $datiDelleAzioni = app(DatiDelleAzioni::class)->perCondominio($condominio, $gestioneId, $oggi, $limite, $disponibile);

        return new TreasuryStatus(
            condominioId: $condominioId,
            gestioneId: $gestioneId,
            liquiditaTotaleCents: $liquiditaTotaleCents,
            liquiditaVincolataCents: $liquiditaVincolataCents,
            liquiditaDisponibileCents: $liquiditaDisponibileCents,
            uscitePredittiveCents: $uscitePredittiveCents,
            incassiAttesiCents: $incassiAttesiCents,
            debitiPregressiScadutiCents: $debitiPregressiScadutiCents,
            scenarioPessimisticoCents: $risultato['scenarioPessimisticoCents'],
            scenarioOttimisticoCents: $risultato['scenarioOttimisticoCents'],
            giornoScopertoPrevisto: $risultato['giornoScopertoPrevisto'],
            scopertoMaxCents: $risultato['scopertoMaxCents'],
            livello: $livello,
            fattureInScadenza: $fattureMostrate,
            morositaImpattanti: $morosita,
            azioniSuggerite: $azioni,
            fattureSenzaScadenza: $fattureSenzaScadenza,
            hasFondoRiserva: $hasFondoRiserva,
            incassiScadutiCents: $attesi['scaduti'],
            incassiInScadenzaCents: $attesi['in_scadenza'],
            elencoRateScadute: ['route' => 'admin.gestionale.rate-scadute.index', 'routeParams' => ['condominio' => $condominioId]],
            morositaAltri: $attesi['altri'],
            scadutoPerFasce: $attesi['fasce'],
            morositaAltriCents: $attesi['altri_cents'],
            morositaPersone: $attesi['persone'],
            fattureQuante: count($fatture),
            fattureMancano: $fattureFuori === [] ? null : ['quante' => count($fattureFuori), 'cifra' => (int) array_sum(array_column($fattureFuori, 'importoCents'))],
            disponibile: $disponibile,
            saldoPrevisto: [
                'giorni' => $risultato['giorni'],
                'primoSottoZero' => $risultato['giornoScopertoPrevisto'],
                'minimoCents' => $risultato['scopertoMaxCents'],
                'finaleCents' => $risultato['scenarioPessimisticoCents'],
                'finaleConEntrateCents' => $risultato['scenarioOttimisticoCents'],
                // Le rate già scadute, al netto del credito: entrano oggi nello scenario «Se pagano tutti».
                'rateScaduteCents' => $attesi['scaduti'],
            ],
            suggerisceAzione: $suggerisce,
            oggi: $oggi->toDateString(),
            recupero: $recupero,
            datiDelleAzioni: $datiDelleAzioni,
        );
    }

    /**
     * Le persone del recupero delle rate scadute: le rate scadute da versare di ognuna (al netto del suo credito nella stessa gestione,
     * come le entrate attese), le più alte prima e a pari cifra la persona prima; si prendono finché la somma copre la cifra che basta (`$basta`: il giorno più
     * basso dello scenario «Con le rate in scadenza», cambiato di segno), e quando il recupero non basta tutte. Della lista si mandano le persone con il nome,
     * l'unità, la cifra e l'ultima lettera (partita, stampata o spedita) con la regola delle 8: fino a 8 tutte, oltre le prime 5; con quante servono e la loro
     * cifra, quante persone hanno rate scadute e la cifra di tutte (il fondo del cursore della simulazione).
     *
     * @param  array<int, int>  $perPersona  le rate scadute da versare per persona, in centesimi
     * @param  array<int, list<int>>  $unitaPerPersona  le unità delle rate scadute di ogni persona
     * @return array{persone: list<array{anagraficaId:int, nome:string, interno:string, cifra:int, lettera: array{tipo:string, data:string}|null}>, servono:int, servonoCents:int, quante:int, cifra:int}
     */
    private function recupero(int $condominioId, array $perPersona, array $unitaPerPersona, int $basta, string $oggi): array
    {
        $perPersona = array_filter($perPersona, fn ($c) => $c > 0);
        uksort($perPersona, fn ($a, $b) => [$perPersona[$b], $a] <=> [$perPersona[$a], $b]);
        $cifra = (int) array_sum($perPersona);
        $daCoprire = min($basta, $cifra);
        $servono = [];
        $coperto = 0;
        foreach ($perPersona as $persona => $c) {
            if ($coperto >= $daCoprire) {
                break;
            }
            $servono[$persona] = $c;
            $coperto += $c;
        }
        $mostrate = count($servono) <= self::TUTTE_FINO_A ? $servono : array_slice($servono, 0, self::MOSTRATE_OLTRE, true);
        $ids = array_keys($mostrate);
        $nomi = $ids === [] ? collect() : Anagrafica::whereIn('id', $ids)->pluck('nome', 'id');
        $unita = array_values(array_unique(array_merge([], ...array_map(fn ($id) => $unitaPerPersona[$id] ?? [], $ids))));
        $immobili = $unita === [] ? collect() : Immobile::whereIn('id', $unita)->get()->keyBy('id');
        $lettere = $ids === [] ? [] : app(RegistroLettere::class)->ultimePerPersone($condominioId, $ids, $oggi);

        return [
            'persone' => array_map(function ($id) use ($mostrate, $nomi, $unitaPerPersona, $immobili, $lettere) {
                $ultima = $lettere[$id]['ultima'] ?? null;

                return [
                    'anagraficaId' => (int) $id,
                    'nome' => (string) ($nomi[$id] ?? ''),
                    'interno' => implode(', ', array_filter(array_map(fn ($u) => $immobili[$u]->etichetta ?? null, $unitaPerPersona[$id] ?? []))),
                    'cifra' => (int) $mostrate[$id],
                    'lettera' => $ultima === null ? null : ['tipo' => (string) $ultima['tipo'], 'data' => (string) $ultima['data']],
                ];
            }, $ids),
            'servono' => count($servono),
            'servonoCents' => (int) array_sum($servono),
            'quante' => count($perPersona),
            'cifra' => $cifra,
        ];
    }

    /**
     * La prossima azione di una persona come la legge la riga della tesoreria: il caso, l'azione suggerita (null quando la regola non ne suggerisce), la frase
     * della regola e quella dei fatti, le note, la rata che il credito copre per «Compensa». Con «Scelgo io la prossima azione» (la scelta del condominio)
     * nessuna azione è segnata e la frase è quella dei fatti, come nel riquadro dei sei mesi.
     *
     * @return array{caso:string, passo:?string, frase:string, frase_dei_fatti:string, note:list<string>, rata_compensa:?int}|null
     */
    private static function passoPerLaRiga(?array $p, bool $suggerisce): ?array
    {
        if ($p === null) {
            return null;
        }
        $fatti = (string) ($p['frase_dei_fatti'] ?? $p['frase']);

        return ['caso' => (string) $p['caso'], 'passo' => $suggerisce ? $p['passo'] : null, 'frase' => $suggerisce ? (string) $p['frase'] : $fatti, 'frase_dei_fatti' => $fatti,
            'note' => array_values($p['note'] ?? []), 'rata_compensa' => $p['rata_compensa'] ?? null];
    }

    /**
     * Gli incassi attesi dalle rate emesse, per persona, dalla lettura comune dei debiti (decisione 217 D4): le rate con un residuo che
     * scadono entro la finestra, le già scadute senza limite all'indietro (entrano nella timeline al giorno 0). Il denaro atteso da una
     * persona è il suo residuo meno il suo credito **nella stessa gestione** (il perimetro del pianificatore del credito), tolto dalla
     * rata più vecchia; mai il credito di un altro. Il credito è quello del giornale (il credito delle sue rate emesse, nella lettura) più
     * quello delle sue quote fuori dal giornale (la rata 0 in bozza a credito): il credito di una quota a giornale è già nel giornale, e
     * quando sta sulla stessa (persona, unità, rata) di una quota a debito (vendita e ricompra, decisione 25) è già nettato nel residuo.
     * Contato anche dal credito disponibile delle quote, si toglieva due volte. Fino alla 1.12.0-beta.2 il residuo era per rata, netto dentro la rata:
     * il credito di una persona toglieva denaro atteso dal debito di un'altra (tipicamente sulla rata 0), e il colore del semaforo di un
     * condominio con la rata 0 mista può cambiare.
     *
     * Le persone con rate scadute, per scaduto (lordo: il credito si mostra, non si netta), con la regola delle 8: fino a 8 tutte, oltre le prime 5;
     * ognuna con l'unità, i giorni di ritardo della rata più vecchia, il suo credito (lo stesso che la riga «Rate scadute» toglie, in tutto il perimetro) e il
     * collegamento al suo estratto conto; quante persone sono in tutto, quante mancano e la loro cifra; lo scaduto di tutte per fasce. A che punto è l'accordo
     * di rientro lo dice la frase della prossima azione, una riga sola. Le persone si leggono a blocchi: di ognuna resta il riassunto.
     *
     * **L'accordo di rientro con il calendario**, in corso oggi: la parte delle rate che copre non è attesa alla scadenza della rata (per la legge sono
     * scadute, e la lettura e lo scaduto lo dicono), ma alle date del calendario, per quello che di ogni rata del calendario manca. Una rata del
     * calendario passata e non versata è attesa a oggi. Con il filtro di una gestione l'accordo non si guarda (il calendario non è di una gestione), e
     * le rate coperte sono attese alla loro scadenza; senza calendario anche.
     *
     * Per il recupero delle azioni consigliate, di ogni persona le rate scadute da versare (`scaduti_per_persona`, la parte scaduta del suo atteso) e le unità
     * delle sue rate scadute (`unita_per_persona`).
     *
     * @return array{scaduti:int, in_scadenza:int, morosita:list<array<string,mixed>>, altri:int, altri_cents:int, persone:int, fasce:array<string,int>, scaduti_per_persona:array<int,int>, unita_per_persona:array<int,list<int>>}
     */
    private function incassiAttesi(int $condominioId, ?int $gestioneId, CarbonImmutable $oggi, CarbonImmutable $limite): array
    {
        $fino = $limite->toDateString();
        $scaduti = 0;
        $inScadenza = 0;
        $fasce = Scadenza::FASCE;
        $morosi = [];
        // Le rate scadute da versare di ogni persona (una cifra per persona): il recupero delle azioni consigliate.
        $scadutiPerPersona = [];
        $fuoriGiornale = $this->crediti->disponibilePerGestione($condominioId, null, soloFuoriGiornale: true);

        $accordiInCorso = $gestioneId === null ? $this->accordi->inCorso($condominioId, $oggi->toDateString()) : [];

        foreach ($this->lettura->perCondominioABlocchi($condominioId, $oggi->toDateString(), self::BLOCCO, $gestioneId) as $blocco) {
            foreach ($blocco as $persona => $l) {
                $perGestione = [];
                $creditoPerGestione = [];
                // Gli accordi con il calendario e «fino al» non passato: dopo l'ultima rata del calendario le rate coperte tornano attese come le altre (la regola
                // delle lettere e dell'incasso).
                $conCalendario = isset($accordiInCorso[$persona])
                    ? $accordiInCorso[$persona]->filter(fn ($a) => $a->scadenze->isNotEmpty() && $a->fino_al->format('Y-m-d') >= $oggi->toDateString())->values() : collect();
                $segni = $conCalendario->isNotEmpty() ? $this->accordi->segni($conCalendario, $l['rate'], $oggi->toDateString(), false) : ['accordi' => [], 'parti' => []];
                foreach ($l['rate'] as $r) {
                    // La parte coperta da un accordo con il calendario è attesa alle date del calendario, qui sotto.
                    $r['residuo'] -= min($r['residuo'], (int) ($segni['parti'][\App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id'])]['in_accordo'] ?? 0));
                    if ($r['residuo'] > 0 && $r['scadenza'] <= $fino) {
                        $perGestione[$r['gestione_id']][] = $r;
                    }
                    $creditoPerGestione[$r['gestione_id']] = ($creditoPerGestione[$r['gestione_id']] ?? 0) + (int) $r['credito'];
                }
                foreach ($fuoriGiornale[$persona] ?? [] as $gestione => $importo) {
                    if ($gestioneId === null || $gestione === $gestioneId) {
                        $creditoPerGestione[$gestione] = ($creditoPerGestione[$gestione] ?? 0) + $importo;
                    }
                }
                foreach ($perGestione as $gestione => $righe) {
                    $credito = (int) ($creditoPerGestione[$gestione] ?? 0);
                    foreach ($righe as $r) {
                        $coperto = min($credito, $r['residuo']);
                        $credito -= $coperto;
                        $atteso = $r['residuo'] - $coperto;
                        $this->timeline->addEntrataAttesa('Rata ' . $r['numero_rata'], $atteso, $r['scadenza'], (bool) $r['scaduta']);
                        $r['scaduta'] ? $scaduti += $atteso : $inScadenza += $atteso;
                        if ($r['scaduta'] && $atteso > 0) {
                            $scadutiPerPersona[(int) $persona] = ($scadutiPerPersona[(int) $persona] ?? 0) + $atteso;
                        }
                    }
                }
                // Le rate del calendario, per quello che manca, mai più di quello che è stato tolto dalle rate coperte.
                $daAttendere = (int) array_sum(array_map(fn ($p) => (int) $p['in_accordo'], $segni['parti']));
                foreach ($segni['accordi'] as $segno) {
                    foreach ($segno['calendario'] ?? [] as $rc) {
                        $atteso = min($rc['mancano'], $daAttendere);
                        if ($rc['versata'] || $atteso <= 0) {
                            continue;
                        }
                        $daAttendere -= $atteso;
                        if ($rc['data'] <= $fino) {
                            $this->timeline->addEntrataAttesa('Accordo di rientro, rata ' . $rc['numero'], $atteso, $rc['data'], (bool) $rc['scaduta']);
                            $rc['scaduta'] ? $scaduti += $atteso : $inScadenza += $atteso;
                            if ($rc['scaduta']) {
                                $scadutiPerPersona[(int) $persona] = ($scadutiPerPersona[(int) $persona] ?? 0) + $atteso;
                            }
                        }
                    }
                }

                if ($l['totali']['scaduto'] > 0) {
                    foreach ($l['totali']['fasce'] as $fascia => $importo) {
                        $fasce[$fascia] += $importo;
                    }
                    // Le unità delle rate scadute; una riga con l'unità cancellata non ne ha (`nullOnDelete`).
                    $unita = array_values(array_unique(array_filter(array_column(array_filter($l['rate'], fn ($r) => $r['scaduta'] && $r['residuo'] > 0), 'immobile_id'), fn ($id) => $id !== null)));
                    // Il ritardo più vecchio che la lettura sa contare: un pregresso senza un numero di giorni non lo sposta, e la riga lo dice.
                    $morosi[] = ['anagraficaId' => (int) $persona, 'unita' => $unita, 'importoCents' => $l['totali']['scaduto'], 'giorniRitardo' => $l['totali']['ritardo_massimo'],
                        'anzianitaNonNota' => (bool) $l['totali']['anzianita_non_nota'], 'creditoCents' => (int) array_sum($creditoPerGestione)];
                }
            }
            unset($blocco);
        }

        usort($morosi, fn ($a, $b) => [$b['importoCents'], $b['giorniRitardo'], $a['anagraficaId']] <=> [$a['importoCents'], $a['giorniRitardo'], $b['anagraficaId']]);
        // La regola delle 8: fino a 8 persone tutte, mai «e altri 1»; oltre, le prime 5 per scaduto.
        $primi = count($morosi) <= self::TUTTE_FINO_A ? $morosi : array_slice($morosi, 0, self::MOSTRATE_OLTRE);
        $fuori = array_slice($morosi, count($primi));
        $unitaPerPersona = array_column($morosi, 'unita', 'anagraficaId');
        $nomi = Anagrafica::whereIn('id', array_column($primi, 'anagraficaId'))->pluck('nome', 'id');
        $immobili = Immobile::whereIn('id', array_merge([], ...array_column($primi, 'unita')))->get()->keyBy('id');

        return [
            'scaduti' => $scaduti,
            'in_scadenza' => $inScadenza,
            'morosita' => array_map(fn ($m) => [
                'anagraficaId' => $m['anagraficaId'],
                'condomino' => (string) ($nomi[$m['anagraficaId']] ?? ''),
                'immobile' => implode(', ', array_map(fn ($id) => $immobili[$id]->etichetta, $m['unita'])),
                'importoCents' => $m['importoCents'],
                // Nullo quando la persona ha solo un pregresso di cui il programma non sa l'anzianità: mai «in ritardo di N giorni» per quello.
                'giorniRitardo' => $m['giorniRitardo'] > 0 || ! $m['anzianitaNonNota'] ? $m['giorniRitardo'] : null,
                'anzianitaNonNota' => $m['anzianitaNonNota'],
                'ritardoTesto' => OriginePregresso::fraseRitardo($m['giorniRitardo'], $m['anzianitaNonNota']),
                'creditoCents' => $m['creditoCents'],
                'route' => 'admin.gestionale.anagrafiche.estratto-conto',
                'routeParams' => ['condominio' => $condominioId, 'anagrafica' => $m['anagraficaId']],
            ], $primi),
            'altri' => count($fuori),
            'altri_cents' => (int) array_sum(array_column($fuori, 'importoCents')),
            'persone' => count($morosi),
            'fasce' => $fasce,
            'scaduti_per_persona' => $scadutiPerPersona,
            'unita_per_persona' => $unitaPerPersona,
        ];
    }

    /**
     * §6.4: verifica se il condominio ha almeno una cassa fondo attiva.
     * Prima della beta.19 il controllo era su conti_contabili.categoria='fondi',
     * che è sempre vera (la radice PASSIVO 2000 ha quella categoria): il flag
     * risultava true anche senza alcun fondo reale.
     */
    private function condominioHaFondoRiserva(int $condominioId): bool
    {
        return DB::table('casse')
            ->where('condominio_id', $condominioId)
            ->where('tipo', 'fondo')
            ->where('attiva', true)
            ->exists();
    }

    /**
     * Fatture aperte/parziali con totale pagato calcolato via subquery (withSum).
     *
     * Usa Eloquent + withSum('allocazioniPagamento', 'importo_allocato') che genera
     * una subquery correlata, eliminando completamente il GROUP BY e il rischio
     * ONLY_FULL_GROUP_BY di MySQL.
     *
     * @return \Illuminate\Database\Eloquent\Collection<FatturaPassiva>
     */
    private function queryFattureAperte(int $condominioId, ?int $gestioneId): \Illuminate\Database\Eloquent\Collection
    {
        return FatturaPassiva::query()
            ->withSum('allocazioniPagamento', 'importo_allocato')
            ->with('fornitore:id,ragione_sociale')
            ->where('condominio_id', $condominioId)
            ->whereIn('stato_pagamento', ['aperta', 'parziale'])
            ->where('stato_approvazione', '!=', 'contestata')
            ->get();
    }

    /**
     * Le righe d'azione del widget aprono una pagina: `route` è il nome vero della rotta, con il prefisso
     * `admin.` (il gestionale esiste solo lì) e con i parametri che la rotta pretende, a partire dal
     * condominio. Un nome senza prefisso o un parametro che manca fa sollevare Ziggy, e la riga non si apre.
     */
    private function generaAzioniSuggerite(int $condominioId, string $livello, int $scenarioPessimisticoCents, int $incassiAttesiCents, int $debitiPregressiScadutiCents): array
    {
        $azioni = [];
        $delCondominio = ['condominio' => $condominioId];

        if ($livello !== 'verde') {
            if ($incassiAttesiCents > 0) {
                // Un consiglio sulla liquidità: la riga porta all'elenco delle rate scadute, una riga per persona, da cui si vede chi deve che
                // cosa. Il nome dice quello che la pagina fa; l'elenco si apre anche dal blocco delle persone, con «Vedi tutte», sempre.
                $azioni[] = new AzioneSuggerita(
                    tipo: 'sollecito',
                    label: 'Vedi le rate scadute',
                    descrizione: 'Chi deve ancora pagare, persona per persona: prima di scrivere, registra i versamenti già arrivati in banca',
                    impattoCents: $incassiAttesiCents,
                    route: 'admin.gestionale.rate-scadute.index',
                    routeParams: $delCondominio,
                );
            } else {
                // Se non ci sono rate emesse, l'unica soluzione per coprire le uscite è emetterle.
                // I piani rate stanno sotto un esercizio: si apre quello aperto, o l'elenco degli esercizi se non ce n'è.
                $esercizioId = DB::table('esercizi')
                    ->where('condominio_id', $condominioId)
                    ->where('stato', 'aperto')
                    ->orderBy('id')
                    ->value('id');

                $azioni[] = new AzioneSuggerita(
                    tipo: 'emissione_rate',
                    label: 'Emetti Nuove Rate',
                    descrizione: 'Non ci sono incassi attesi sufficienti per coprire le uscite',
                    impattoCents: 0,
                    route: $esercizioId ? 'admin.gestionale.esercizi.piani-rate.index' : 'admin.gestionale.esercizi.index',
                    routeParams: $esercizioId ? $delCondominio + ['esercizio' => $esercizioId] : $delCondominio,
                );
            }
        }

        if ($debitiPregressiScadutiCents > 0) {
            // I debiti del condominio verso i fornitori rimasti dalle gestioni passate: l'azione porta alle loro fatture. Riguarda i fornitori,
            // non i condòmini: l'accordo di rientro di un condòmino è un'altra cosa e sta nell'elenco delle rate scadute.
             $azioni[] = new AzioneSuggerita(
                tipo: 'gestione_pregressi',
                label: 'Gestisci Debiti Pregressi',
                descrizione: 'Hai esposizioni storiche che non rientrano nel budget corrente',
                impattoCents: 0, // Nessun impatto a 30gg
                route: 'admin.gestionale.fatture.index',
                routeParams: $delCondominio + ['pregresso' => 1]
            );
        }

        return $azioni;
    }
}
