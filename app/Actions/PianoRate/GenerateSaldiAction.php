<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\PianoRate;

use App\Enums\RuoloAnagraficaImmobile;
use App\Exceptions\Gestionale\SaldoSolidaleSenzaTitolareException;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Riparto\RisolutoreTitolari;
use App\Services\Riparto\UnitaMista;

class GenerateSaldiAction
{
    /**
     * @return array Formato: [ anagrafica_id => [ immobile_id => [ 'importo' => X, 'meta_storico' => [...] ] ] ]
     */
    public function execute(PianoRate $pianoRate, Gestione $gestione, array $saldiConfig = []): array
    {
        // Un saldo è disponibile per questo piano se è libero, OPPURE se è già
        // intestato proprio a questo piano: senza il secondo caso, ricalcolare
        // un piano che aveva assorbito i pregressi li faceva sparire dalle
        // quote (il piano ripartiva dal solo preventivo) e il lucchetto restava
        // chiuso senza più nessuno in grado di riaprirlo.
        $saldiDaApplicare = Saldo::where('gestione_id', $gestione->id)
            // I debiti verso fornitori vivono nella stessa tabella e non devono
            // MAI essere candidati a un piano rate. Oggi ne restano fuori perché
            // nascono già bloccati: è un'invariante non scritta nello schema, e
            // quindi non è una difesa.
            ->whereNull('fornitore_id')
            ->where(function ($q) use ($pianoRate) {
                $q->where('is_applicato', false)
                  ->orWhere('piano_rate_id', $pianoRate->id);
            })
            ->where('saldo_iniziale', '!=', 0)
            ->get();

        if ($saldiDaApplicare->isEmpty()) {
            return [];
        }

        $distribuzione = [];
        $saldiConfigMap = collect($saldiConfig)->keyBy('saldo_id');

        foreach ($saldiDaApplicare as $saldo) {
            
            // CASO A: Saldo Nominale (Ha già un'anagrafica assegnata)
            if ($saldo->anagrafica_id !== null) {
                $this->assegnaQuota(
                    $distribuzione,
                    $saldo->anagrafica_id,
                    $saldo->immobile_id,
                    $saldo->saldo_iniziale, // Importo in centesimi dal DB
                    $this->creaMeta($saldo, 'nominale')
                );
                continue;
            }

            // CASO B: Saldo Solidale (Art. 63) - anagrafica_id è NULL
            $configCustom = $saldiConfigMap->get($saldo->id);

            if ($configCustom && !empty($configCustom['ripartizioni'])) {
                // B1. Riparto Manuale (L'utente ha forzato le quote da Vue)

                // Il verso lo detta la FONTE, non quello che è arrivato dal form. La casella
                // del riparto manuale ha `disableNegative: true` e mostra il totale da
                // distribuire in valore assoluto (`PianiRateNew.vue:127` e `:1292`): il meno
                // l'utente non può digitarlo, ed è giusto così. Ma nessuno lo rimetteva —
                // `CreatePianoRateRequest` valida l'importo come stringa senza vincoli di
                // segno, `MoneyHelper::toCents()` di una stringa positiva fa un intero
                // positivo, e qui il valore veniva usato com'era. Un credito dell'unità da
                // 600,00 € diviso fra due comproprietari usciva come **due debiti da
                // 300,00 €**: non un importo storto, il verso opposto.
                $segno = $saldo->saldo_iniziale < 0 ? -1 : 1;

                foreach ($configCustom['ripartizioni'] as $rip) {
                    $this->assegnaQuota(
                        $distribuzione,
                        $rip['anagrafica_id'],
                        $saldo->immobile_id,
                        // CENTESIMI, non euro. Il chiamante ha già convertito con
                        // MoneyHelper::toCents (PianoRateController::store), l'unico
                        // che sa leggere la stringa mascherata "1.200,50" del form.
                        // Il vecchio `* 100` qui la convertiva una seconda volta:
                        // un riparto manuale di 250,00 € addebitava 25.000,00 €.
                        $segno * abs((int) $rip['importo']),
                        $this->creaMeta($saldo, 'solidale_manuale', scelta: 'amministratore')
                    );
                }
            } else {
                // B2. Riparto Automatico sui titolari di un diritto reale, scelti per natura
                // della gestione — vedi `risolviTitolari()` per la regola e il perché. Il giorno dei titolari è
                // quello della regola (issue 58, decisione 674), o quello che l'amministratore ha scelto nella
                // finestra «Riparto saldi iniziali» (`alla_data`, conservato nel piano: un ricalcolo rifà la scelta).
                $allaData = is_array($configCustom) && ! empty($configCustom['alla_data']) ? (string) $configCustom['alla_data'] : null;
                // Una scelta che non vale più (il passaggio di quel giorno annullato, o quel giorno altre persone) non si rifà: si torna
                // alla regola, e nelle quote resta scritto quale scelta non vale più e perché (`percheLaSceltaNonVale()`).
                $sceltaDiPrima = null;
                if ($allaData !== null && ($perche = $this->percheLaSceltaNonVale($saldo, $gestione, (array) $configCustom)) !== null) {
                    $sceltaDiPrima = ['alla_data' => substr($allaData, 0, 10), 'perche' => $perche];
                    $allaData = null;
                }
                $giornoRegola = $this->giornoDellaRegola($saldo);
                $giorno = $allaData !== null ? CarbonImmutable::parse(substr($allaData, 0, 10)) : $giornoRegola;
                $scelta = $allaData === null || ($giornoRegola !== null && $giorno->equalTo($giornoRegola)) ? 'regola' : 'amministratore';
                [$ruoloRisolto, $titolari] = $this->risolviTitolari($saldo, $gestione, $giorno);
                $persone = self::perPersona($titolari, $ruoloRisolto);

                $quote = MoneyHelper::ripartisciPerQuote(
                    $saldo->saldo_iniziale,
                    array_map(fn (array $p): float => $p['quota'], $persone)
                );

                foreach ($persone as $anagraficaId => $persona) {
                    $importoProQuota = $quote[$anagraficaId] ?? 0;

                    // Uno zero può essere legittimo — un centesimo fra tre persone ne lascia
                    // due a mani vuote — e una quota da zero non va scritta.
                    if ($importoProQuota !== 0) {
                        $this->assegnaQuota(
                            $distribuzione,
                            $anagraficaId,
                            $saldo->immobile_id,
                            $importoProQuota,
                            $this->creaMeta($saldo, 'solidale_automatico', $persona['quota'], $persona['tipologia'], $giorno?->toDateString(), $scelta, $sceltaDiPrima)
                        );
                    }
                }
            }
        }

        Log::info("Saldi elaborati e distribuiti su " . count($distribuzione) . " anagrafiche.");
        return $distribuzione;
    }

    /**
     * Chi pagherebbe cosa, se il piano venisse generato adesso. **Sola lettura.**
     *
     * Serve all'anteprima nella creazione del piano: fino alla beta.43 il riparto automatico
     * di un saldo solidale era invisibile, e l'amministratore scopriva chi era stato addebitato
     * solo dopo aver generato. Non è un dettaglio di comodità — è il requisito di trasparenza
     * del §4 di `cascata_risoluzione_ruolo_coerenza_ruoli.md`: *quello che l'amministratore
     * legge è quello che viene addebitato*, e senza vederlo il riparto manuale non è una scelta
     * ma un ripiego di chi ha già sbagliato una volta.
     *
     * Passa **dalle stesse funzioni** del generatore, `risolviTitolari()` e
     * `MoneyHelper::ripartisciPerQuote()`. Non è una scorciatoia: un'anteprima che ricalcola a
     * modo suo diverge dal calcolo autoritativo al primo cambio, ed è il difetto che nella
     * beta.35 è costato un centesimo sul netto da pagare.
     *
     * A differenza della generazione **non solleva**: la catena esaurita qui non è un errore
     * ma la cosa più utile da mostrare, perché si è ancora in tempo per censire il proprietario
     * mancante invece di scoprirlo a piano emesso.
     *
     * **La issue 58 (Coda 174, decisione 674).** Le quote sono quelle della regola: i titolari del giorno a cui il saldo si
     * riferisce (`riferito_al`, il primo giorno del suo esercizio; `titolari_al` è il giorno che la regola guarda, il giorno
     * prima quando un passaggio comincia proprio il primo giorno, decisione 685 (2)). Dove un passaggio tocca il saldo
     * (`passaggi`, vedi {@see passaggiNellArco()}), `scelte` dice a chi andrebbe con ciascun giorno che conta, la regola per
     * prima: la finestra le mostra affiancate e l'amministratore sceglie (decisione 674 (2)). Senza passaggi nell'arco
     * `scelte` è vuota e l'anteprima è quella di prima.
     *
     * Per le frasi della finestra (giro di correzioni dopo lo scettico della tappa 1 della 1.12.0-beta.14): `natura` è la natura della
     * gestione (`ordinaria` o `straordinaria`: la frase dell'art. 1005 c.c. vale solo per la seconda); `successione` dice, quando nella
     * regola c'è una successione — gli eredi al posto del defunto con l'arretrato agli eredi (decisione 685 (1)), il defunto al posto
     * degli eredi con l'arretrato a suo nome e la successione prima del primo giorno (decisione 689 (2)) —, chi era titolare quel giorno,
     * chi è morto, la data della successione e dove è andato l'arretrato, perché la finestra non scriva un titolare sbagliato.
     *
     * @return array{risolvibile: bool, ruolo: ?string, ruolo_label: ?string, motivo: ?string, quote: list<array{anagrafica_id: int, nome: string, quota: float, importo: int}>,
     *   riferito_al: ?string, titolari_al: ?string, esercizio: ?string, natura: string,
     *   successione: ?array{defunti: list<string>, data: string, titolari: list<string>, arretrato: string},
     *   passaggi: list<array<string, mixed>>, scelte: list<array<string, mixed>>}
     */
    public function anteprimaSolidale(Saldo $saldo, Gestione $gestione): array
    {
        $primo = $this->primoGiornoDelSaldo($saldo);
        $giornoRegola = $this->giornoDellaRegola($saldo);
        $contesto = [
            'riferito_al' => $primo?->toDateString(),
            'titolari_al' => $giornoRegola?->toDateString(),
            'esercizio' => $saldo->esercizio_id !== null ? DB::table('esercizi')->where('id', $saldo->esercizio_id)->value('nome') : null,
            'natura' => $gestione->tipo === 'straordinaria' ? 'straordinaria' : 'ordinaria',
        ];

        try {
            [$ruolo, $titolari] = $this->risolviTitolari($saldo, $gestione, $giornoRegola);
        } catch (SaldoSolidaleSenzaTitolareException $e) {
            return [
                'risolvibile' => false,
                'ruolo' => null,
                'ruolo_label' => null,
                'motivo' => $e->getMessage(),
                'quote' => [],
            ] + $contesto + ['successione' => null, 'passaggi' => [], 'scelte' => []];
        }

        $passaggi = $primo !== null ? $this->passaggiNellArco($saldo, $primo) : [];

        return [
            'risolvibile' => true,
            'ruolo' => $ruolo,
            'ruolo_label' => self::etichettaDeiRuoli($titolari),
            'motivo' => null,
            'quote' => $this->quoteDellaScelta($saldo, $ruolo, $titolari),
        ] + $contesto + [
            'successione' => $this->successioneDelGiorno($titolari),
            'passaggi' => $passaggi,
            'scelte' => $passaggi === [] ? [] : $this->scelte($saldo, $gestione, $primo, $giornoRegola, $passaggi),
        ];
    }

    /**
     * Le persone della scelta che la finestra offre per quel giorno (gli `anagrafica_id` delle sue quote, in ordine): la richiesta
     * della creazione le conserva nel piano accanto al giorno (`CreatePianoRateRequest::validated()`), così un ricalcolo rifà la scelta
     * solo se quel giorno le persone sono ancora quelle ({@see percheLaSceltaNonVale()}). Vuoto se quel giorno non è offerto.
     *
     * @return list<int>
     */
    public function titolariDellaScelta(Saldo $saldo, string $allaData): array
    {
        $gestione = $saldo->anagrafica_id === null ? Gestione::find($saldo->gestione_id) : null;
        $scelta = $gestione !== null ? collect($this->anteprimaSolidale($saldo, $gestione)['scelte'])->firstWhere('alla_data', substr($allaData, 0, 10)) : null;

        return $scelta === null ? [] : self::idOrdinati(array_column($scelta['quote'], 'anagrafica_id'));
    }

    /**
     * Le scelte salvate nel piano che non valgono più (rilievo 2 dello scettico della tappa 1 della 1.12.0-beta.14): per ognuna il saldo,
     * il giorno scelto, il perché e la frase che lo dice all'amministratore. La generazione, per loro, torna alla regola. **Sola lettura**:
     * la scelta resta scritta nel piano (`saldi_config`), così la frase si può dire a ogni ricalcolo, e se lo stesso passaggio torna con
     * le stesse persone la scelta torna a valere.
     *
     * @return list<array{saldo_id: int, alla_data: string, perche: string, frase: string}>
     */
    public function scelteCheNonValgonoPiu(PianoRate $piano): array
    {
        $gestione = Gestione::find($piano->gestione_id);
        if ($gestione === null) {
            return [];
        }

        $esito = [];
        foreach ((array) ($piano->saldi_config ?? []) as $conf) {
            if (! is_array($conf) || empty($conf['alla_data']) || ! empty($conf['ripartizioni'])) {
                continue;
            }
            $saldo = Saldo::where('gestione_id', $gestione->id)->whereNull('anagrafica_id')->find((int) ($conf['saldo_id'] ?? 0));
            if ($saldo === null || ($perche = $this->percheLaSceltaNonVale($saldo, $gestione, $conf)) === null) {
                continue;
            }
            $giorno = CarbonImmutable::parse(substr((string) $conf['alla_data'], 0, 10));
            $scelti = DB::table('anagrafiche')->whereIn('id', self::idOrdinati((array) ($conf['titolari'] ?? [])))->orderBy('id')->pluck('nome')->all();
            $chi = $scelti === []
                ? 'il giorno ' . $giorno->locale('it')->translatedFormat('j F Y')
                : sprintf('%s, %s il %s', self::elenco($scelti), count($scelti) > 1 ? 'titolari' : 'titolare', $giorno->locale('it')->translatedFormat('j F Y'));
            $regola = $this->anteprimaSolidale($saldo, $gestione);
            $aChi = $regola['risolvibile'] ? ': va a ' . self::elenco(array_column($regola['quote'], 'nome')) : '';
            $esito[] = [
                'saldo_id' => (int) $saldo->id,
                'alla_data' => $giorno->toDateString(),
                'perche' => $perche,
                'frase' => sprintf('La scelta di prima per il pregresso di %s (%s) non vale più: %s. Il pregresso torna alla regola%s.',
                    DB::table('immobili')->where('id', $saldo->immobile_id)->value('nome') ?? 'un\'unità', $chi, $perche, $aChi),
            ];
        }

        return $esito;
    }

    /**
     * Le frasi di {@see scelteCheNonValgonoPiu()} in fila; nulla se tutte le scelte del piano valgono. L'esito delle rotte che rigenerano il piano le
     * dice solo per i saldi il cui pregresso è andato ad altre persone ({@see fraseDelPregressoCambiato()}).
     */
    public function fraseDelleScelteCheNonValgonoPiu(PianoRate $piano): ?string
    {
        $frasi = array_column($this->scelteCheNonValgonoPiu($piano), 'frase');

        return $frasi === [] ? null : implode(' ', $frasi);
    }

    /**
     * Il pregresso dell'unità nelle quote del piano, per saldo e per persona (`saldo_id => [anagrafica_id => centesimi]`, in ordine di id): la parte del
     * saldo di ogni quota (`regole_calcolo.importi.saldo_usato`) con il suo storico (`dettagli_saldo`), per i soli saldi dell'unità (il riparto solidale,
     * automatico o a mano). Una quota che porta più saldi insieme non dice quanto è di ciascuno: quei saldi non si leggono. Le rotte che rigenerano il
     * piano lo leggono prima di rigenerare, per {@see fraseDelPregressoCambiato()}. **Sola lettura.**
     *
     * @return array<int, array<int, int>>
     */
    public function pregressoDelleUnita(PianoRate $piano): array
    {
        $perSaldo = [];
        $misti = [];
        $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->whereNotNull('rate_quote.anagrafica_id')->whereNotNull('rate_quote.regole_calcolo')->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
        foreach ($quote as $q) {
            $regole = is_string($q->regole_calcolo) ? (json_decode($q->regole_calcolo, true) ?: []) : (array) $q->regole_calcolo;
            $usato = (int) ($regole['importi']['saldo_usato'] ?? 0);
            $dettagli = $regole['dettagli_saldo'] ?? [];
            $dettagli = isset($dettagli['saldo_origine_id']) ? [$dettagli] : array_values(array_filter((array) $dettagli, fn ($d) => is_array($d) && isset($d['saldo_origine_id'])));
            $solidali = array_values(array_filter($dettagli, fn (array $d) => str_starts_with((string) ($d['tipo_riparto'] ?? ''), 'solidale')));
            if ($usato === 0 || $solidali === []) {
                continue;
            }
            if (count(array_unique(array_map(fn (array $d) => (int) $d['saldo_origine_id'], $dettagli))) > 1) {
                foreach ($solidali as $d) {
                    $misti[(int) $d['saldo_origine_id']] = true;
                }

                continue;
            }
            $saldoId = (int) $solidali[0]['saldo_origine_id'];
            $perSaldo[$saldoId][(int) $q->anagrafica_id] = ($perSaldo[$saldoId][(int) $q->anagrafica_id] ?? 0) + $usato;
        }
        $perSaldo = array_diff_key($perSaldo, $misti);
        foreach ($perSaldo as &$persone) {
            ksort($persone);
        }
        unset($persone);
        ksort($perSaldo);

        return $perSaldo;
    }

    /**
     * Che cosa dice del pregresso dell'unità l'esito di una rotta che rigenera il piano («Ricalcola», una voce tolta), dato il pregresso di prima
     * ({@see pregressoDelleUnita()} letto prima di rigenerare). Per ogni saldo dell'unità che ora va ad altre persone, o alle stesse con altre cifre:
     * - con una scelta salvata che non vale più, la sua frase ({@see scelteCheNonValgonoPiu()}: la scelta, il perché, a chi va con la regola);
     * - altrimenti chi lo aveva prima, chi lo ha ora e con che cosa: la regola, con le parole della sua etichetta, o la scelta dell'amministratore. È il
     *   piano generato con la regola di prima della issue 58 (decisione 674: la vendita dell'1/4 divideva i € 180,00 fra Ugo ed Elsa), o prima di un
     *   passaggio registrato o annullato dopo: il ricalcolo applica la regola di oggi (rilievo 4 del secondo scettico della tappa 1 della 1.12.0-beta.14).
     * Con le stesse persone e le stesse cifre nessuna frase, anche se la scelta di prima non vale più (rilievo 7: la scelta «Ugo fino al 14/12/2025»,
     * annullata la vendita del 15/12, con la regola dà Ugo). Un saldo che prima non c'era, che ora non c'è più o che ha cambiato importo non è un cambio
     * di persona: non si dice. Nulla se niente è cambiato.
     *
     * @param array<int, array<int, int>> $prima
     */
    public function fraseDelPregressoCambiato(PianoRate $piano, array $prima): ?string
    {
        $dopo = $this->pregressoDelleUnita($piano);
        $cambiati = array_filter($dopo, fn (array $persone, int $saldoId) => isset($prima[$saldoId]) && $prima[$saldoId] !== $persone
            && array_sum($prima[$saldoId]) === array_sum($persone), ARRAY_FILTER_USE_BOTH);
        if ($cambiati === []) {
            return null;
        }
        $nonValgono = collect($this->scelteCheNonValgonoPiu($piano))->keyBy('saldo_id');
        $gestione = Gestione::find($piano->gestione_id);
        $config = collect((array) ($piano->saldi_config ?? []))->filter(fn ($c) => is_array($c))->keyBy(fn (array $c) => (int) ($c['saldo_id'] ?? 0));
        $nomi = DB::table('anagrafiche')->whereIn('id', array_merge(...array_map('array_keys', array_merge(array_values($cambiati), array_values(array_intersect_key($prima, $cambiati))))))
            ->pluck('nome', 'id');
        $conLeCifre = fn (array $persone) => self::elenco(array_map(fn (int $id, int $cents) => ($nomi[$id] ?? 'un condòmino') . ' ' . MoneyHelper::format(abs($cents)),
            array_keys($persone), array_values($persone)));

        $frasi = [];
        foreach ($cambiati as $saldoId => $persone) {
            if ($nonValgono->has($saldoId)) {
                $frasi[] = $nonValgono[$saldoId]['frase'];

                continue;
            }
            $saldo = Saldo::find($saldoId);
            if ($saldo === null || $gestione === null) {
                continue;
            }
            $frasi[] = sprintf('Il ricalcolo ha cambiato a chi va il pregresso di %s (%s%s): prima %s, ora %s%s.',
                DB::table('immobili')->where('id', $saldo->immobile_id)->value('nome') ?? 'un\'unità', MoneyHelper::format(abs((int) $saldo->saldo_iniziale)),
                (int) $saldo->saldo_iniziale < 0 ? ' a credito' : '', $conLeCifre($prima[$saldoId]), $conLeCifre($persone),
                $this->conCheCosa($saldo, $gestione, $config->get((int) $saldoId)));
        }

        return $frasi === [] ? null : implode(' ', $frasi);
    }

    /**
     * Con che cosa va il pregresso di un saldo dell'unità, per {@see fraseDelPregressoCambiato()}: «, con la regola (titolare al 1 gennaio 2026)»
     * con le parole dell'etichetta della regola, «, con la tua scelta (…)» con il giorno scelto che vale ancora, niente con il riparto a mano.
     */
    private function conCheCosa(Saldo $saldo, Gestione $gestione, ?array $conf): string
    {
        if (! empty($conf['ripartizioni'])) {
            return '';
        }
        $anteprima = $this->anteprimaSolidale($saldo, $gestione);
        if (! $anteprima['risolvibile']) {
            return '';
        }
        $scelte = collect($anteprima['scelte']);
        $giorno = ! empty($conf['alla_data']) ? substr((string) $conf['alla_data'], 0, 10) : null;
        $tua = $giorno !== null ? $scelte->first(fn (array $c) => $c['alla_data'] === $giorno && ! $c['regola']) : null;
        if ($tua !== null) {
            return ', con la tua scelta (' . $tua['etichetta'] . ')';
        }
        $regola = $scelte->firstWhere('regola', true);
        if ($regola !== null) {
            return ', con la regola (' . preg_replace('/ · la regola$/u', '', (string) $regola['etichetta']) . ')';
        }
        // Senza passaggi nell'arco la finestra non offre scelte: la regola con il suo giorno, come la direbbe l'etichetta.
        return empty($anteprima['titolari_al']) ? '' : sprintf(', con la regola (%s al %s)', count($anteprima['quote']) > 1 ? 'titolari' : 'titolare',
            CarbonImmutable::parse((string) $anteprima['titolari_al'])->locale('it')->translatedFormat('j F Y'));
    }

    /**
     * Perché una scelta salvata (`{saldo_id, alla_data, titolari}`) non vale più, o nullo se vale ancora. Vale se quel giorno è ancora
     * fra quelli che la finestra offre e, quando il piano conserva le persone scelte, se quel giorno le persone sono le stesse. Non vale se
     * il passaggio di quel giorno è stato annullato (o cambiato: annullato e registrato di nuovo con un'altra data o un'altra persona):
     * il ricalcolo non dà il pregresso, come «scelta dell'amministratore», a qualcuno che nessuno ha scelto.
     */
    private function percheLaSceltaNonVale(Saldo $saldo, Gestione $gestione, array $conf): ?string
    {
        $giorno = substr((string) ($conf['alla_data'] ?? ''), 0, 10);
        $scelta = collect($this->anteprimaSolidale($saldo, $gestione)['scelte'])->firstWhere('alla_data', $giorno);
        $scelti = self::idOrdinati((array) ($conf['titolari'] ?? []));
        $oggi = $scelta === null ? [] : self::idOrdinati(array_column($scelta['quote'], 'anagrafica_id'));
        if ($scelta !== null && ($scelti === [] || $scelti === $oggi)) {
            return null;
        }

        $ragioni = [];
        if (($annullato = $this->passaggioAnnullato($saldo, $giorno)) !== null) {
            $ragioni[] = 'il passaggio ' . self::delGiorno($annullato) . ' è stato annullato';
        }
        if ($scelta !== null) {
            $nomi = array_column($scelta['quote'], 'nome');
            $ragioni[] = sprintf('quel giorno ora %s %s', count($nomi) > 1 ? 'sono titolari' : 'è titolare', self::elenco($nomi));
        } elseif ($ragioni === []) {
            $ragioni[] = 'quel giorno non è più fra quelli che i passaggi dell\'unità offrono';
        }

        return implode(', e ', $ragioni);
    }

    /**
     * Il passaggio annullato dell'unità da cui veniva il giorno scelto: quello di quel giorno (chi è entrato) o del giorno dopo (chi c'era
     * fino al giorno prima), di vendita, usufrutto o successione come {@see passaggiNellArco()}. Nullo se non ce n'è uno annullato. Se quel
     * giorno è stato registrato di nuovo un altro passaggio, il perché lo dice insieme alle persone di adesso.
     */
    private function passaggioAnnullato(Saldo $saldo, string $giorno): ?CarbonImmutable
    {
        foreach ([$giorno, CarbonImmutable::parse($giorno)->addDay()->toDateString()] as $data) {
            $annullato = Subentro::conAnnullati()->where('immobile_id', $saldo->immobile_id)->whereNotNull('annullato_il')
                ->whereIn('tipo_passaggio', ['vendita', 'usufrutto', 'successione'])->whereDate('decorrenza', $data)->exists();
            if ($annullato) {
                return CarbonImmutable::parse($data);
            }
        }

        return null;
    }

    /** @return list<int> gli id, interi, senza doppi e in ordine: due elenchi delle stesse persone sono uguali. */
    private static function idOrdinati(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * La successione nella regola, per le frasi della finestra (rilievo 4 e decisione 689 (2)). Con l'arretrato agli eredi
     * ({@see agliEredi()}) fra i titolari del giorno ci sono eredi al posto di un defunto: quel giorno il titolare era il defunto. Con
     * l'arretrato a nome del defunto ({@see alDefunto()}) c'è il defunto al posto dei suoi eredi: quel giorno i titolari erano gli eredi.
     * Dice chi era titolare quel giorno, chi è morto, il giorno della successione e dove è andato l'arretrato. Nulla senza successioni.
     *
     * @return ?array{defunti: list<string>, data: string, titolari: list<string>, arretrato: string}
     */
    private function successioneDelGiorno(Collection $titolari): ?array
    {
        $eredi = $titolari->filter(fn ($t) => isset($t->erede_di));
        $alDefunto = $titolari->filter(fn ($t) => isset($t->eredi_al_posto));
        if ($eredi->isEmpty() && $alDefunto->isEmpty()) {
            return null;
        }
        $delGiorno = $titolari->flatMap(fn ($t) => isset($t->erede_di) ? [(int) $t->erede_di]
            : (isset($t->eredi_al_posto) ? array_map('intval', $t->eredi_al_posto) : [(int) $t->anagrafica_id]))->unique()->values()->all();
        $defunti = $eredi->pluck('erede_di')->concat($alDefunto->pluck('anagrafica_id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $nomi = DB::table('anagrafiche')->whereIn('id', array_merge($delGiorno, $defunti))->pluck('nome', 'id');

        return [
            'defunti' => array_map(fn (int $id) => (string) ($nomi[$id] ?? "#{$id}"), $defunti),
            'data' => (string) $eredi->concat($alDefunto)->pluck('successione_del')->filter()->sort()->first(),
            'titolari' => array_map(fn (int $id) => (string) ($nomi[$id] ?? "#{$id}"), $delGiorno),
            'arretrato' => $eredi->isNotEmpty() ? Subentro::ARRETRATO_AGLI_EREDI : Subentro::ARRETRATO_AL_DEFUNTO,
        ];
    }

    /**
     * I giorni che la finestra offre per un saldo dell'unità (`alla_data` di ogni scelta): la richiesta della creazione del
     * piano accetta solo uno di questi (`CreatePianoRateRequest`), non un giorno qualunque. Vuoto senza passaggi nell'arco o per
     * un saldo nominale.
     *
     * @return list<string>
     */
    public function giorniOfferti(Saldo $saldo): array
    {
        $gestione = $saldo->anagrafica_id === null ? Gestione::find($saldo->gestione_id) : null;
        if ($gestione === null) {
            return [];
        }

        return array_column($this->anteprimaSolidale($saldo, $gestione)['scelte'], 'alla_data');
    }

    /**
     * Il primo giorno dell'esercizio del saldo (`saldi.esercizio_id` → `esercizi.data_inizio`): il giorno a cui il saldo si
     * riferisce. Nullo solo se l'esercizio non si trova, e allora la regola torna quella senza periodo.
     */
    public function primoGiornoDelSaldo(Saldo $saldo): ?CarbonImmutable
    {
        $inizio = $saldo->esercizio_id !== null ? DB::table('esercizi')->where('id', $saldo->esercizio_id)->value('data_inizio') : null;

        return $inizio !== null ? CarbonImmutable::parse(substr((string) $inizio, 0, 10)) : null;
    }

    /**
     * Il giorno dei titolari della regola (decisione 674 (1)): il primo giorno dell'esercizio del saldo. Se quel giorno comincia
     * un passaggio — una riga di diritto reale dell'unità chiusa il giorno prima — il giorno prima: il saldo al primo giorno è
     * quello che si chiude il giorno prima, e va a chi era titolare allora (decisione 685 (2): Ugo vende a Elsa il 01/01/2026,
     * l'arretrato maturato nel 2025 resta a Ugo).
     */
    public function giornoDellaRegola(Saldo $saldo): ?CarbonImmutable
    {
        $primo = $this->primoGiornoDelSaldo($saldo);
        if ($primo === null) {
            return null;
        }

        $chiusaIlGiornoPrima = DB::table('anagrafica_immobile')
            ->where('immobile_id', $saldo->immobile_id)
            ->where('attivo', true)
            ->whereIn('tipologia', array_map(fn (RuoloAnagraficaImmobile $r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale()))
            ->whereDate('data_fine', $primo->subDay()->toDateString())
            ->exists();

        return $chiusaIlGiornoPrima ? $primo->subDay() : $primo;
    }

    /**
     * I passaggi che «toccano» il saldo (bozza della issue 58): registrati e non annullati sull'unità del saldo, di vendita, usufrutto
     * o successione (le locazioni no: l'inquilino non risponde dei saldi dell'unità), con la decorrenza fra l'inizio dell'esercizio
     * prima di quello del saldo e oggi. È l'arco della solidarietà dell'art. 63 co. 4 disp. att. c.c. (l'anno in corso e il
     * precedente); senza un esercizio prima, l'anno prima del primo giorno.
     *
     * Decisione 705 (1.12.0-beta.14): i passaggi registrati insieme (più persone che escono con lo stesso atto: stessa data, stesso tipo, chi entra) sono
     * **una riga sola**, con chi esce in tutti, nell'ordine in cui sono stati scritti; le scelte non cambiano (lo stesso giorno, le stesse persone).
     *
     * @return list<array{data: string, tipo: string, etichetta: string, riserva: bool, uscenti: list<string>, entranti: list<string>}>
     */
    private function passaggiNellArco(Saldo $saldo, CarbonImmutable $primo): array
    {
        $dal = $this->inizioDellArco($saldo, $primo);

        $subentri = Subentro::query()
            ->where('immobile_id', $saldo->immobile_id)
            ->whereIn('tipo_passaggio', ['vendita', 'usufrutto', 'successione'])
            ->whereDate('decorrenza', '>=', $dal->toDateString())
            ->whereDate('decorrenza', '<=', DateHelper::oggiUtente())
            ->orderBy('decorrenza')->orderBy('id')
            ->get();
        if ($subentri->isEmpty()) {
            return [];
        }

        $ids = $subentri->flatMap(fn (Subentro $s) => array_merge([(int) $s->anagrafica_uscente_id], $s->entranti()))->filter()->unique()->values()->all();
        $nomi = DB::table('anagrafiche')->whereIn('id', $ids)->pluck('nome', 'id');
        $nome = fn (?int $id, ?string $registrato) => $id !== null && isset($nomi[$id]) ? (string) $nomi[$id] : ($registrato ?? 'chi non è più in anagrafica');

        // L'insieme si legge dal padre: le righe delle pertinenze non hanno `insieme_id` (solo le righe padre), e sul box in comproprietà spuntato con
        // l'insieme le parti sarebbero due righe (rilievo 6 dello scettico della tappa 7).
        $insiemeDelPadre = DB::table('subentri')->whereIn('id', $subentri->pluck('subentro_padre_id')->filter()->unique()->values()->all())->pluck('insieme_id', 'id');
        $insieme = fn (Subentro $s) => $s->insieme_id ?? ($s->subentro_padre_id !== null ? ($insiemeDelPadre[$s->subentro_padre_id] ?? null) : null);

        return $subentri->groupBy(fn (Subentro $s) => $insieme($s) !== null ? 'insieme-' . $insieme($s) : 'passaggio-' . $s->id)
            ->map(fn (Collection $gruppo) => [
                'data' => $gruppo->first()->decorrenza->toDateString(),
                'tipo' => (string) $gruppo->first()->tipo_passaggio,
                'etichetta' => self::etichettaDelPassaggio($gruppo->first()),
                'riserva' => $gruppo->first()->riservaUsufrutto(),
                'uscenti' => $gruppo->map(fn (Subentro $s) => $nome($s->anagrafica_uscente_id !== null ? (int) $s->anagrafica_uscente_id : null, $s->registro['nomi']['uscente'] ?? null))
                    ->unique()->values()->all(),
                'entranti' => $gruppo->flatMap(fn (Subentro $s) => array_map(fn (int $id) => $nome($id, null), $s->entranti()))->unique()->values()->all(),
            ])->values()->all();
    }

    /** L'inizio dell'arco della solidarietà: il primo giorno dell'esercizio prima di quello del saldo, o l'anno prima del primo giorno. */
    private function inizioDellArco(Saldo $saldo, CarbonImmutable $primo): CarbonImmutable
    {
        $precedente = DB::table('esercizi')->where('condominio_id', $saldo->condominio_id)
            ->whereDate('data_inizio', '<', $primo->toDateString())->orderByDesc('data_inizio')->value('data_inizio');

        return $precedente !== null ? CarbonImmutable::parse(substr((string) $precedente, 0, 10)) : $primo->subYear();
    }

    private static function etichettaDelPassaggio(Subentro $s): string
    {
        return match (true) {
            $s->riservaUsufrutto() => 'Vendita con riserva d\'usufrutto',
            $s->tipo_passaggio === 'vendita' && $s->tipologia === 'nuda_proprietario' => 'Vendita della nuda proprietà',
            $s->tipo_passaggio === 'vendita' => 'Vendita',
            $s->legato() => 'Legato',
            $s->successione() => 'Successione',
            $s->estinzioneUsufrutto() => 'Estinzione dell\'usufrutto',
            default => 'Costituzione d\'usufrutto',
        };
    }

    /**
     * Le scelte della finestra (decisione 674 (2)): un gruppo di titolari per ogni giorno che conta — il giorno della regola,
     * la data di ogni passaggio nell'arco (chi è entrato quel giorno) e, per un passaggio che viene prima del primo giorno
     * dell'esercizio, il giorno prima del passaggio (chi c'era) — con le cifre calcolate come le calcola la generazione. I gruppi
     * uguali (stesse persone, stesse cifre) si fondono nel primo, così la regola resta una sola; un giorno in cui la catena non
     * trova nessuno non si offre.
     *
     * @param list<array<string, mixed>> $passaggi
     * @return list<array{alla_data: string, regola: bool, etichetta: string, ruolo: string, ruolo_label: string, quote: list<array<string, mixed>>}>
     */
    private function scelte(Saldo $saldo, Gestione $gestione, CarbonImmutable $primo, CarbonImmutable $giornoRegola, array $passaggi): array
    {
        $giorni = [[$giornoRegola, 'regola']];
        foreach ($passaggi as $p) {
            $giorno = CarbonImmutable::parse($p['data']);
            if ($giorno->lte($primo)) {
                $giorni[] = [$giorno->subDay(), 'fino'];
            }
            $giorni[] = [$giorno, 'dal'];
        }

        $scelte = [];
        foreach ($giorni as [$giorno, $forma]) {
            try {
                [$ruolo, $titolari] = $this->risolviTitolari($saldo, $gestione, $giorno);
            } catch (SaldoSolidaleSenzaTitolareException) {
                continue;
            }
            $quote = $this->quoteDellaScelta($saldo, $ruolo, $titolari);
            $chiave = json_encode(collect($quote)->map(fn ($q) => [$q['anagrafica_id'], $q['importo']])->sortBy(0)->values()->all());
            if (isset($scelte[$chiave])) {
                continue;
            }
            $scelte[$chiave] = ['giorno' => $giorno, 'forma' => $forma, 'ruolo' => $ruolo, 'ruolo_label' => self::etichettaDeiRuoli($titolari), 'quote' => $quote,
                'eredi_di' => self::erediDiUnoSolo($titolari), 'al_defunto' => $titolari->isNotEmpty() && $titolari->every(fn ($t) => isset($t->eredi_al_posto)),
                'con_la_successione' => self::conLaSuccessione($titolari)];
        }

        // «titolare» quando tutte le scelte hanno lo stesso ruolo (la vendita piena); il diritto quando cambia (la riserva: la piena
        // proprietà l'1/1, la nuda proprietà dal giorno dell'atto). Senza genere (rilievo 15, come «in comproprietà» della decisione 673 (5)):
        // il programma non sa se una persona è un uomo o una donna, e «con la nuda proprietà» vale per tutti e due.
        $unSoloRuolo = count(array_unique(array_column($scelte, 'ruolo'))) === 1;

        return array_values(array_map(function (array $c) use ($unSoloRuolo) {
            $chi = count($c['quote']) > 1 ? 'titolari' : ($unSoloRuolo ? 'titolare' : self::conIlDiritto($c['ruolo']));
            $giorno = $c['giorno']->locale('it')->translatedFormat('j F Y');

            return [
                'alla_data' => $c['giorno']->toDateString(),
                'regola' => $c['forma'] === 'regola',
                // Rilievo 4: la regola che dà il pregresso agli eredi (decisione 685 (1)) si chiama con il defunto, non con un giorno in cui gli
                // eredi non erano ancora titolari.
                'etichetta' => match (true) {
                    $c['eredi_di'] !== null => "eredi di {$c['eredi_di']}" . ($c['forma'] === 'regola' ? ' · la regola' : ''),
                    // Decisione 689 (2): il defunto, con l'arretrato lasciato a suo nome, quel giorno non era più titolare.
                    $c['al_defunto'] => 'a nome del defunto' . ($c['forma'] === 'regola' ? ' · la regola' : ''),
                    // Rilievo 3 del secondo scettico: accanto a chi era titolare quel giorno ci sono gli eredi (l'erede già comproprietaria) o il
                    // defunto al posto di una parte. «titolari al …» non è vero per tutti: l'etichetta dice la successione.
                    $c['con_la_successione'] !== null => $c['con_la_successione'] . ($c['forma'] === 'regola' ? ' · la regola' : ''),
                    $c['forma'] === 'regola' => "{$chi} al {$giorno} · la regola",
                    $c['forma'] === 'fino' => "{$chi} fino al {$giorno}",
                    default => "{$chi} dal {$giorno}",
                },
                'ruolo' => $c['ruolo'],
                'ruolo_label' => $c['ruolo_label'],
                'quote' => $c['quote'],
            ];
        }, $scelte));
    }

    /**
     * Le quote di un saldo fra i titolari dati, per persona, con il nome: le stesse funzioni della generazione.
     *
     * @return list<array{anagrafica_id: int, nome: string, quota: float, importo: int}>
     */
    private function quoteDellaScelta(Saldo $saldo, string $ruolo, Collection $titolari): array
    {
        $persone = self::perPersona($titolari, $ruolo);
        $importi = MoneyHelper::ripartisciPerQuote($saldo->saldo_iniziale, array_map(fn (array $p): float => $p['quota'], $persone));

        $nomi = DB::table('anagrafiche')
            ->whereIn('id', $titolari->pluck('anagrafica_id'))
            ->pluck('nome', 'id');

        return collect($persone)->map(fn (array $persona, int $anagraficaId): array => [
            'anagrafica_id' => $anagraficaId,
            'nome' => $nomi[$anagraficaId] ?? "#{$anagraficaId}",
            'quota' => $persona['quota'],
            'importo' => $importi[$anagraficaId] ?? 0,
        ])->values()->all();
    }

    /** Il diritto di chi è titolare, senza genere (rilievo 15): «con la piena proprietà», «con la nuda proprietà», «con l'usufrutto». */
    private static function conIlDiritto(string $ruolo): string
    {
        return match ($ruolo) {
            RuoloAnagraficaImmobile::PROPRIETARIO->value => 'con la piena proprietà',
            RuoloAnagraficaImmobile::NUDA_PROPRIETA->value => 'con la nuda proprietà',
            RuoloAnagraficaImmobile::USUFRUTTUARIO->value => 'con l\'usufrutto',
            default => 'titolare',
        };
    }

    /**
     * Il nome del defunto quando tutti i titolari del giorno sono i suoi eredi, messi al suo posto da {@see agliEredi()}: la scelta si
     * chiama «eredi di …». Nullo se fra i titolari c'è anche qualcun altro (l'erede che era già comproprietario, un altro defunto).
     */
    private static function erediDiUnoSolo(Collection $titolari): ?string
    {
        $defunti = $titolari->pluck('erede_di')->unique()->values();
        if ($titolari->isEmpty() || $defunti->count() !== 1 || $defunti->first() === null) {
            return null;
        }

        return (string) (DB::table('anagrafiche')->where('id', (int) $defunti->first())->value('nome') ?? '');
    }

    /**
     * L'etichetta di una scelta in cui la successione ha messo qualcuno al posto di un titolare di quel giorno, accanto ad altri (rilievo 3 del secondo
     * scettico della tappa 1 della 1.12.0-beta.14): gli eredi al posto del defunto con l'arretrato agli eredi, quando fra i titolari c'è anche chi lo era
     * già (l'erede già comproprietaria: «con l'arretrato di Ugo Rossi agli eredi»), o il defunto al posto della parte che gli eredi hanno ricevuto, con
     * l'arretrato a suo nome («con l'arretrato a nome di Ugo Rossi»). Nulla senza successioni: i casi in cui ci sono solo gli eredi o solo il defunto
     * hanno la loro etichetta («eredi di …», «a nome del defunto»), che viene prima.
     */
    private static function conLaSuccessione(Collection $titolari): ?string
    {
        $nomi = fn (Collection $ids) => self::elenco(DB::table('anagrafiche')->whereIn('id', $ids->all())->orderBy('id')->pluck('nome')->all());
        $alPosto = $titolari->filter(fn ($t) => isset($t->eredi_al_posto))->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values();
        $diChi = $titolari->filter(fn ($t) => isset($t->erede_di))->pluck('erede_di')->map(fn ($id) => (int) $id)->unique()->values();
        $parti = array_filter([
            $alPosto->isNotEmpty() ? 'con l\'arretrato a nome di ' . $nomi($alPosto) : null,
            $diChi->isNotEmpty() ? 'con l\'arretrato di ' . $nomi($diChi) . ' agli eredi' : null,
        ]);

        return $parti === [] ? null : implode(' e ', $parti);
    }

    /** Sull'unità mista i ruoli sono due (Coda 170): «Usufruttuario e proprietario», non solo il primo. */
    private static function etichettaDeiRuoli(Collection $titolari): string
    {
        return $titolari->pluck('tipologia')->unique()->values()
            ->map(fn ($t, $i) => $i === 0 ? RuoloAnagraficaImmobile::from($t)->label() : mb_strtolower(RuoloAnagraficaImmobile::from($t)->label()))
            ->implode(' e ');
    }

    /**
     * I titolari raccolti per persona, con le quote sommate (rilievo D2 della Fase 1-bis della beta.41). La stessa persona
     * può stare due volte fra i titolari: sull'unità mista sul ruolo e sul gemello (proprietaria piena di una metà e nuda
     * proprietaria dell'altra), e già prima della beta su due righe dello stesso ruolo (vende e ricompra, Coda 174). Con
     * `pluck('quota', 'anagrafica_id')` le due righe si fondevano nell'ultima quota, la persona prendeva il 100 % e il
     * ciclo lo scriveva una volta per riga: un pregresso da € 1.000,00 ne addebitava € 2.000,00. Una voce di storico per
     * persona, con il ruolo della prima riga (quello della catena), così `VerificaSaldiSolidaliCommand` non conta doppio.
     *
     * @return array<int, array{quota: float, tipologia: string}>
     */
    private static function perPersona(Collection $titolari, string $ruoloRisolto): array
    {
        $persone = [];
        foreach ($titolari as $t) {
            $id = (int) $t->anagrafica_id;
            $persone[$id] ??= ['quota' => 0.0, 'tipologia' => (string) ($t->tipologia ?? $ruoloRisolto)];
            $persone[$id]['quota'] += (float) $t->quota;
        }

        return $persone;
    }

    private function assegnaQuota(array &$distribuzione, int $anagraficaId, ?int $immobileId, int $importo, array $meta): void
    {
        $immobileKey = $immobileId ?? 0; // Usiamo 0 come fallback se il saldo non è legato a un immobile

        if (!isset($distribuzione[$anagraficaId])) {
            $distribuzione[$anagraficaId] = [];
        }
        
        if (!isset($distribuzione[$anagraficaId][$immobileKey])) {
            // FIX: Inizializziamo esplicitamente 'importo' a 0 prima di sommarlo
            $distribuzione[$anagraficaId][$immobileKey] = [
                'importo' => 0,
                'meta_storico' => []
            ];
        }

        // FIX: Sommiamo l'importo calcolato alla chiave corretta
        $distribuzione[$anagraficaId][$immobileKey]['importo'] += $importo;
        $distribuzione[$anagraficaId][$immobileKey]['meta_storico'][] = $meta;
    }

    /**
     * Chi si fa carico del pregresso intestato all'unità, e con che ruolo.
     *
     * Un saldo solidale (art. 63 disp. att. c.c.) è un debito **dell'unità**: il condominio lo
     * riscuote da chi su quell'unità ha un diritto reale. L'inquilino non è mai fra questi —
     * il suo rapporto è con il locatore (gli oneri accessori dell'art. 9 L. 392/1978), e il
     * condominio non ha titolo per escuterlo. Fino alla beta.43 questa query non guardava
     * affatto il ruolo: prendeva ogni occupante attivo, addebitava l'inquilino e **diluiva la
     * quota del proprietario**, perché il denominatore sommava anche i millesimi di chi non
     * doveva pagare. Misurato: su un pregresso di 1.000,00 € con proprietario e inquilino
     * entrambi censiti, il proprietario ne pagava 500,00 e l'inquilino gli altri 500,00.
     *
     * Fra i titolari decide la **natura della gestione** da cui il pregresso proviene: le
     * spese di ordinaria amministrazione sono dell'usufruttuario (art. 1004 c.c.), le
     * riparazioni straordinarie del proprietario (art. 1005 c.c.). La catena sta in
     * `RuoloAnagraficaImmobile::catenaSaldoSolidale()`, che è anche l'unico posto in cui
     * questa regola è scritta.
     *
     * **È un default, non un obbligo.** Verso il condominio usufruttuario e nudo proprietario
     * rispondono in solido (art. 67 co. 8 disp. att. c.c.): questa è la ripartizione interna
     * fra loro, che un patto può avere deciso altrimenti. Per quel caso c'è il riparto
     * manuale — il ramo B1 — e per questo la catena non va irrigidita in una guardia.
     *
     * **Il giorno (issue 58, Coda 174, decisione 674).** I titolari sono quelli di un giorno: quello della regola
     * ({@see giornoDellaRegola()}, il primo giorno dell'esercizio del saldo) o quello scelto dall'amministratore. Fino alla
     * 1.12.0-beta.13 la query era senza periodo e prendeva anche la riga chiusa da un passaggio: dopo una vendita piena il
     * pregresso si divideva a metà fra chi vende e chi compra, dopo una riserva d'usufrutto la straordinaria andava tutta a chi
     * compra la nuda proprietà. Con un giorno solo `vincolaQuery` applica la regola D7 (la fine filtra sempre, l'inizio solo con
     * un predecessore), e l'unità mista si guarda quel giorno ({@see UnitaMista::righeGemelle()} con tutte le righe dell'unità,
     * che servono a D7): un'unità diventata mista dopo quel giorno, quel giorno non lo era. Senza un giorno (l'esercizio del
     * saldo non si trova) la regola è quella di prima, senza periodo.
     *
     * **La successione (decisione 685 (1)).** Se il giorno dà un defunto e nella successione l'arretrato è andato agli eredi,
     * il pregresso va agli eredi per le loro quote ({@see agliEredi()}); con l'arretrato a nome del defunto resta a lui. E se la
     * successione è prima del primo giorno, dentro l'arco della solidarietà, con l'arretrato a nome del defunto, il giorno della regola
     * dà gli eredi e il pregresso va al defunto lo stesso ({@see alDefunto()}, decisione 689 (2)).
     *
     * @return array{0: string, 1: \Illuminate\Support\Collection}
     */
    private function risolviTitolari(Saldo $saldo, Gestione $gestione, ?CarbonImmutable $giorno = null): array
    {
        // Blocco B1 del progetto sul subentro (1.11.0-beta.30): chi è titolare lo decide
        // `RisolutoreTitolari`, in un posto solo.
        $periodo = $giorno !== null ? PeriodoCompetenza::puntuale($giorno) : null;
        $righe = fn () => DB::table('anagrafica_immobile')->where('immobile_id', $saldo->immobile_id);
        $occupanti = app(RisolutoreTitolari::class)->vincolaQuery($righe(), $periodo)->get();
        // Tutte le righe attive dell'unità, anche fuori dal giorno: `UnitaMista` le vuole per D7 (il predecessore).
        $righeUnita = $periodo === null ? $occupanti : $righe()->where('attivo', true)->get();

        foreach (RuoloAnagraficaImmobile::catenaSaldoSolidale($gestione->tipo) as $ruolo) {
            $titolari = $occupanti->where('tipologia', $ruolo->value)->values();

            if ($titolari->isNotEmpty()) {
                // Coda 170 (decisione 31.2, 1.11.0-beta.41): sull'unità mista il pregresso si divide anche con il ruolo
                // gemello — l'ordinaria fra usufruttuario e proprietario pieno, la straordinaria fra nudo proprietario e
                // proprietario pieno, per le loro quote. Con il giorno (Coda 174) la riga chiusa da un passaggio non conta più,
                // e un'unità diventata mista con una riserva si legge com'era quel giorno.
                $gemelle = app(UnitaMista::class)->righeGemelle($righeUnita, $titolari, $ruolo->value, $periodo);
                $tutte = $titolari->concat($gemelle)->values();

                if ($giorno === null) {
                    return [$ruolo->value, $tutte];
                }
                $tutte = $this->agliEredi($tutte);
                // Decisione 689 (2): sul giorno della regola, gli eredi di una successione dell'arco con l'arretrato a nome del defunto
                // lasciano il posto al defunto. Solo sul giorno della regola: negli altri giorni la finestra offre gli eredi come scelta.
                $regola = $this->giornoDellaRegola($saldo);

                return [$ruolo->value, $regola !== null && $giorno->equalTo($regola) ? $this->alDefunto($saldo, $giorno, $tutte) : $tutte];
            }
        }

        // Catena esaurita. Prima di adesso qui non succedeva niente: la collection era vuota,
        // il `foreach` non girava, e il saldo spariva dalla distribuzione **senza eccezione e
        // senza log** — mentre il lucchetto si chiudeva lo stesso e la gestione registrava un
        // importo «processato» che non esisteva in nessuna quota. L'ADR-001 lo prevedeva
        // bloccante fin dalla prima stesura; non era mai stato scritto.
        $nomeImmobile = DB::table('immobili')->where('id', $saldo->immobile_id)->value('nome')
            ?? "#{$saldo->immobile_id}";

        Log::warning('GenerateSaldiAction: saldo solidale senza titolari di diritto reale.', [
            'saldo_id' => $saldo->id,
            'immobile_id' => $saldo->immobile_id,
            'gestione_tipo' => $gestione->tipo,
            'importo_cents' => $saldo->saldo_iniziale,
            'ruoli_attivi' => $occupanti->pluck('tipologia')->unique()->values()->all(),
        ]);

        throw new SaldoSolidaleSenzaTitolareException(
            $saldo->id,
            $saldo->immobile_id,
            $nomeImmobile,
            $saldo->saldo_iniziale
        );
    }

    /**
     * La successione nella regola della issue 58 (decisione 685 (1)). Una riga che il giorno dei titolari è di un defunto, e che una
     * successione registrata ha chiuso con «l'arretrato agli eredi», lascia il posto agli eredi per le loro quote: a Ugo, morto il
     * 01/04/2026, non si chiede niente, e la scelta sull'arretrato l'amministratore l'ha già fatta registrando la successione. Con
     * «a nome del defunto», e nel legato (chi riceve l'unità per testamento non eredita il patrimonio), la riga resta.
     *
     * La quota di ogni erede è la sua quota ereditata (`Subentro::eredi()`), riportata alla quota del defunto su quest'unità: sulla
     * pertinenza con una quota diversa gli eredi si dividono quella. L'erede che era già titolare compare due volte, e
     * `perPersona()` somma le sue quote.
     *
     * @param Collection<int, object> $titolari
     * @return Collection<int, object>
     */
    private function agliEredi(Collection $titolari): Collection
    {
        $ids = $titolari->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return $titolari;
        }
        $successioni = Subentro::query()->where('tipo_passaggio', 'successione')->whereIn('riga_uscente_id', $ids)->get()->keyBy('riga_uscente_id');
        if ($successioni->isEmpty()) {
            return $titolari;
        }

        return $titolari->flatMap(function ($riga) use ($successioni) {
            $successione = $successioni->get((int) ($riga->id ?? 0));
            $capo = $successione?->capo();
            if ($capo === null || $capo->legato() || ($capo->registro['arretrato']['scelta'] ?? null) !== Subentro::ARRETRATO_AGLI_EREDI) {
                return [$riga];
            }
            $eredi = $successione->eredi() !== [] ? $successione->eredi() : $capo->eredi();
            $totale = array_sum(array_column($eredi, 'quota'));
            if ($eredi === [] || $totale <= 0) {
                return [$riga];
            }

            // `erede_di` e `successione_del` servono solo alle frasi della finestra (rilievo 4, {@see successioneDelGiorno()}): chi era
            // titolare quel giorno è il defunto, non i suoi eredi.
            return array_map(fn (array $e) => (object) [
                'id' => $e['riga_id'],
                'anagrafica_id' => $e['anagrafica_id'],
                'immobile_id' => $riga->immobile_id ?? null,
                'tipologia' => $riga->tipologia,
                'quota' => round((float) $riga->quota * $e['quota'] / $totale, 4),
                'attivo' => true,
                'erede_di' => (int) $riga->anagrafica_id,
                'successione_del' => $capo->decorrenza?->toDateString(),
            ], $eredi);
        })->values();
    }

    /**
     * La successione prima del primo giorno con l'arretrato a nome del defunto (decisione 689 (2), completa la 685 (1)): Ugo muore il
     * 15/12/2025, i € 180,00 all'01/01/2026 sono il debito che si chiude il 31/12, maturato quasi tutto quando il titolare era lui, e
     * registrando la successione l'amministratore l'ha lasciato a suo nome. Sul giorno della regola le righe che quella successione ha aperto
     * agli eredi lasciano il posto alla riga del defunto, con la quota che gli eredi hanno ricevuto; l'erede che era già titolare tiene la
     * sua parte. Vale anche per il legato (chi riceve l'unità per testamento non eredita il patrimonio). Solo le successioni dell'arco della
     * solidarietà ({@see inizioDellArco()}): una successione di anni prima non tocca il saldo, gli eredi sono i titolari da allora. Con
     * l'arretrato agli eredi, o senza una scelta scritta nel registro, le righe restano degli eredi.
     *
     * @param Collection<int, object> $titolari
     * @return Collection<int, object>
     */
    private function alDefunto(Saldo $saldo, CarbonImmutable $giorno, Collection $titolari): Collection
    {
        $primo = $this->primoGiornoDelSaldo($saldo);
        $ids = $titolari->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($primo === null || $ids === []) {
            return $titolari;
        }
        $successioni = Subentro::query()->where('immobile_id', $saldo->immobile_id)->where('tipo_passaggio', 'successione')
            ->whereDate('decorrenza', '>=', $this->inizioDellArco($saldo, $primo)->toDateString())->whereDate('decorrenza', '<=', $giorno->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get()
            ->filter(function (Subentro $s) {
                $capo = $s->capo();

                return $capo->legato() || ($capo->registro['arretrato']['scelta'] ?? null) === Subentro::ARRETRATO_AL_DEFUNTO;
            });

        foreach ($successioni as $successione) {
            $eredi = $successione->eredi() !== [] ? $successione->eredi() : $successione->capo()->eredi();
            $totale = array_sum(array_column($eredi, 'quota'));
            $rigaDefunto = $successione->riga_uscente_id !== null ? DB::table('anagrafica_immobile')->where('id', $successione->riga_uscente_id)->first() : null;
            $diChi = collect($eredi)->filter(fn (array $e) => $e['riga_id'] !== null && in_array((int) $e['riga_id'], $ids, true));
            if ($rigaDefunto === null || $totale <= 0 || $diChi->isEmpty()) {
                continue;
            }
            $ricevuta = fn (array $e): float => round((float) $rigaDefunto->quota * $e['quota'] / $totale, 4);
            $perRiga = $diChi->keyBy(fn (array $e) => (int) $e['riga_id']);
            // Gli eredi entrano nel ruolo del defunto: la tipologia è quella delle loro righe, che la catena ha già scelto.
            $tipologia = (string) ($titolari->first(fn ($t) => $perRiga->has((int) ($t->id ?? 0)))?->tipologia ?? $rigaDefunto->tipologia);
            $quotaDelDefunto = 0.0;
            $titolari = $titolari->flatMap(function ($riga) use ($perRiga, $ricevuta, &$quotaDelDefunto) {
                $erede = $perRiga->get((int) ($riga->id ?? 0));
                if ($erede === null) {
                    return [$riga];
                }
                $parte = min((float) $riga->quota, $ricevuta($erede));
                $quotaDelDefunto += $parte;
                $resto = round((float) $riga->quota - $parte, 4);

                // L'erede che era già titolare ha una riga sola con la sua parte e quella ricevuta: tiene la sua.
                return $resto > 0 ? [(object) array_merge((array) $riga, ['quota' => $resto])] : [];
            })->values();
            $titolari->push((object) [
                'id' => (int) $rigaDefunto->id,
                'anagrafica_id' => (int) $rigaDefunto->anagrafica_id,
                'immobile_id' => $saldo->immobile_id,
                'tipologia' => $tipologia,
                'quota' => round($quotaDelDefunto, 4),
                'attivo' => true,
                // Per le frasi della finestra ({@see successioneDelGiorno()}): quel giorno i titolari erano gli eredi.
                'eredi_al_posto' => $diChi->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->values()->all(),
                'successione_del' => $successione->decorrenza?->toDateString(),
            ]);
        }

        return $titolari;
    }

    /**
     * Lo storico del saldo in ogni quota (`rate_quote.regole_calcolo.dettagli_saldo`). Sui saldi dell'unità anche il giorno dei
     * titolari usato (`riferito_al`) e chi l'ha deciso (`scelta`: `regola`, o `amministratore` con un giorno scelto nella
     * finestra o con il riparto a mano). `tipo_riparto` resta `solidale_automatico` anche con il giorno scelto, così
     * `VerificaSaldiSolidaliCommand` e `LetturaDebiti` leggono come prima. Con una scelta salvata che non vale più, `scelta` è
     * `regola` e `scelta_di_prima` dice il giorno che era stato scelto e perché non vale più.
     */
    private function creaMeta(
        Saldo $saldo,
        string $tipoRiparto,
        ?float $quotaUsata = null,
        ?string $ruoloRisolto = null,
        ?string $riferitoAl = null,
        ?string $scelta = null,
        ?array $sceltaDiPrima = null
    ): array {
        return [
            'saldo_origine_id' => $saldo->id,
            'gestione_origine_id' => $saldo->gestione_id,
            'tipo_riparto' => $tipoRiparto,
            'importo_originale' => $saldo->saldo_iniziale,
            'quota_applicata' => $quotaUsata,
            // Congelato nel riparto, non ricalcolato a runtime: è il requisito di trasparenza
            // del §4 di `cascata_risoluzione_ruolo_coerenza_ruoli.md`. Quello che
            // l'amministratore ha letto nell'anteprima è quello che è stato addebitato.
            'ruolo_risolto' => $ruoloRisolto,
        ] + ($scelta !== null ? ['riferito_al' => $riferitoAl, 'scelta' => $scelta] : [])
          + ($sceltaDiPrima !== null ? ['scelta_di_prima' => $sceltaDiPrima] : []);
    }

    /** «del 15 dicembre 2025», «dell'1 aprile 2026», «dell'8 marzo 2026»: come `delGiorno()` della finestra. */
    private static function delGiorno(CarbonImmutable $giorno): string
    {
        return (in_array($giorno->day, [1, 8, 11], true) ? 'dell\'' : 'del ') . $giorno->locale('it')->translatedFormat('j F Y');
    }

    /**
     * A; A e B; A, B e C. Davanti a un nome che comincia per «e» l'italiano scrive «ed» («Acquirente Elsa ed Erede Bruno», rilievo 7 del secondo
     * scettico), come `PianoRate::cioCheLaStradaPerde`.
     */
    private static function elenco(array $nomi): string
    {
        $nomi = array_values($nomi);
        if (count($nomi) <= 1) {
            return $nomi[0] ?? '';
        }
        $ultimo = (string) $nomi[count($nomi) - 1];

        return implode(', ', array_slice($nomi, 0, -1)) . (preg_match('/^e/iu', $ultimo) === 1 ? ' ed ' : ' e ') . $ultimo;
    }
}
