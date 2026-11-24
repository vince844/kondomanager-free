<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Debiti;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\AccordoRientroRata;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'accordo di rientro letto per l'elenco «Rate scadute», le lettere, l'estratto conto, la finestra dell'accordo, l'incasso e la tesoreria. Una lettura
 * sola: chi mostra l'accordo legge i segni di qui.
 *
 * - **In corso a una data** è un accordo con la data non dopo quella e non chiuso prima o quel giorno. Solo gli accordi in corso contano.
 * - **Quello che è andato all'accordo** lo dicono i versamenti, con la regola dell'incasso ({@see allocati()}): gli incassi e le loro compensazioni
 *   dal giorno dell'accordo (di quel giorno, solo quelli registrati dopo l'accordo: gli altri sono già nel residuo da cui parte), non stornati. Su una rata coperta in parte un versamento senza la scelta va prima alla parte fuori dall'accordo, uno con
 *   «Versamento dell'accordo» prima alla parte nell'accordo. Un passaggio, un'emissione annullata, gli interessi passati agli eredi abbassano il
 *   residuo ma non sono un versamento all'accordo.
 * - **Il segno** di un accordo dice due cifre: i riconosciuti (la somma degli importi riconosciuti delle rate coperte) e quanto resta oggi, cioè per
 *   ogni rata coperta il riconosciuto meno quello che è andato all'accordo, mai più del suo residuo. Il segno dice «residuo invariato» quando il
 *   residuo di ogni rata coperta è quello del giorno dell'accordo (un versamento stornato lo lascia uguale).
 * - **Le lettere** lasciano fuori la parte in accordo di ogni rata finché l'accordo è in corso e «fino al» non è passato; dopo quella data le
 *   rate tornano nelle lettere proposte, e il segno lo dice. Il programma non scrive mai che un accordo è decaduto.
 * - **Versati oltre il capitale riconosciuto**: la parte in più rimasta a credito, come la conta la ricevuta ({@see RicevutaIncasso}), degli
 *   incassi della persona dal giorno dell'accordo che hanno coperto rate dell'accordo (gli interessi e le spese versati con le rate prima
 *   che il programma li registrasse come addebiti). Il programma non la sposta: usarla, restituirla o lasciarla è dell'amministratore.
 * - **Il calendario** ({@see CalendarioAccordo}): quello che è andato all'accordo copre le rate del calendario in ordine, la più vecchia prima; una
 *   rata con un centesimo scoperto non è versata. Un accordo chiuso si legge al giorno prima della chiusura.
 * - **La regola di decadenza**: il programma cerca il primo giorno in cui ogni forma si verifica, con i versamenti giorno per giorno, e lo **conserva**
 *   nell'accordo con le rate e i versati di quel giorno: un versamento registrato dopo con una data di prima non lo cancella, e il segnale dice che il
 *   giornale di oggi lo smentisce. Non scrive mai che l'accordo è decaduto: la decadenza la registra l'amministratore, e chiude l'accordo.
 */
final class AccordiRientro
{
    public function __construct(private readonly RicevutaIncasso $ricevuta) {}

    /**
     * Gli accordi in corso alla data, per persona, con le loro rate coperte.
     *
     * @param list<int>|null $persone
     * @return array<int, Collection<int, AccordoRientro>>
     */
    public function inCorso(int $condominioId, string $alla, ?array $persone = null): array
    {
        return AccordoRientro::with(['rate', 'scadenze'])
            ->where('condominio_id', $condominioId)
            ->when($persone !== null, fn ($q) => $q->whereIn('anagrafica_id', $persone === [] ? [0] : $persone))
            ->whereDate('data_accordo', '<=', $alla)
            ->where(fn ($q) => $q->whereNull('chiuso_il')->orWhereDate('chiuso_il', '>', $alla))
            ->orderBy('data_accordo')->orderBy('id')
            ->get()
            ->groupBy('anagrafica_id')
            ->all();
    }

    /**
     * Il segno di ogni accordo di una persona e la parte in accordo di ogni sua rata, dalla lettura alla data.
     *
     * @param Collection<int, AccordoRientro> $accordi
     * @param list<array<string,mixed>> $rateLettura le righe `rate` di {@see LetturaDebiti} alla data
     * @return array{accordi: list<array<string,mixed>>, parti: array<string, array<string,mixed>>} `parti`: «rata|unità» => la parte in accordo
     */
    public function segni(Collection $accordi, array $rateLettura, string $alla, bool $conVersatiOltre = true): array
    {
        $residui = self::residui($rateLettura);
        $segni = [];
        $versamentiDi = [];
        foreach ($accordi as $a) {
            $versamenti = $versamentiDi[$a->id] = $this->versamenti($a);
            $allocati = self::allocati($a, $versamenti, $alla);
            $riconosciuti = 0;
            $oggi = 0;
            $invariato = true;
            foreach ($a->rate as $coperta) {
                $k = self::chiave($coperta->rata_id, $coperta->immobile_id);
                $residuo = $residui[$k] ?? 0;
                $riconosciuti += (int) $coperta->importo_riconosciuto;
                $oggi += self::inAccordo($coperta, $residuo, $allocati[$k] ?? 0);
                // Invariato: il residuo della rata è quello del giorno dell'accordo. Senza quel residuo (una riga scritta prima che si tenesse),
                // la parte in accordo uguale al riconosciuto.
                $invariato = $invariato && ($coperta->residuo_iniziale !== null ? $residuo === (int) $coperta->residuo_iniziale
                    : min($residuo, (int) $coperta->importo_riconosciuto) === (int) $coperta->importo_riconosciuto);
            }
            // Un accordo chiuso si legge al giorno prima della chiusura: quello che è arrivato dopo non è suo.
            $giorno = self::giornoDiLettura($a, $alla);
            $segni[] = self::descrizione($a) + [
                'riconosciuti'    => $riconosciuti,
                'oggi'            => $oggi,
                'invariato'       => $invariato,
                'fino_al_passato' => $a->fino_al->format('Y-m-d') < $alla,
                'versati_oltre'   => $conVersatiOltre ? $this->versatiOltre($a, $alla) : 0,
            ] + $this->calendario($a, (int) array_sum(self::allocati($a, $versamenti, $giorno)), $giorno, $alla, $versamenti);
        }

        return ['accordi' => $segni, 'parti' => $this->parti($accordi, $rateLettura, $alla, $versamentiDi)];
    }

    /**
     * La parte in accordo di ogni rata di una persona alla data: «rata|unità» => la parte, con l'accordo che la copre. La parte è il riconosciuto meno
     * quello che i versamenti hanno dato all'accordo ({@see allocati()}), mai più del residuo della rata.
     *
     * @param Collection<int, AccordoRientro> $accordi
     * @param list<array<string,mixed>> $rateLettura le righe `rate` di {@see LetturaDebiti} alla data
     * @param array<int, list<array<string,mixed>>> $versamentiDi i versamenti già letti, per accordo
     * @return array<string, array<string,mixed>>
     */
    public function parti(Collection $accordi, array $rateLettura, string $alla, array $versamentiDi = []): array
    {
        $residui = self::residui($rateLettura);
        $parti = [];
        foreach ($accordi as $a) {
            $finoAl = $a->fino_al->format('Y-m-d');
            $allocati = self::allocati($a, $versamentiDi[$a->id] ?? $this->versamenti($a), $alla);
            foreach ($a->rate as $coperta) {
                $k = self::chiave($coperta->rata_id, $coperta->immobile_id);
                $inAccordo = self::inAccordo($coperta, $residui[$k] ?? 0, $allocati[$k] ?? 0);
                // Una rata in due accordi in corso (non dovrebbe succedere): conta la parte più grande, mai la somma.
                if (! isset($parti[$k]) || $parti[$k]['in_accordo'] < $inAccordo) {
                    $parti[$k] = [
                        'accordo_id'    => (int) $a->id,
                        'data_accordo'  => $a->data_accordo->format('Y-m-d'),
                        'fino_al'       => $finoAl,
                        'riconosciuto'  => (int) $coperta->importo_riconosciuto,
                        'in_accordo'    => $inAccordo,
                        // Coperta solo in parte: il riconosciuto è meno del residuo del giorno dell'accordo.
                        'in_parte'      => $coperta->residuo_iniziale !== null && (int) $coperta->importo_riconosciuto < (int) $coperta->residuo_iniziale,
                        // Le lettere lasciano fuori la parte in accordo fino a «fino al» compreso; l'incasso la tiene fuori dall'ordine negli stessi giorni.
                        'fuori_dalle_lettere' => $alla <= $finoAl,
                    ];
                }
            }
        }

        return $parti;
    }

    /**
     * Le parti in accordo delle persone date, per gli accordi in corso alla data e con «fino al» non passato: persona => «rata|unità» => centesimi. La
     * regola è quella delle lettere: dopo l'ultima rata del calendario le rate coperte tornano nelle lettere e nell'ordine dell'incasso. La usano l'incasso
     * (l'ordine proposto lascia fuori la parte coperta), la situazione debitoria e la proposta degli interessi. Una persona senza parti non c'è.
     *
     * @param list<int> $persone
     * @return array<int, array<string, int>>
     */
    public function partiInCorso(int $condominioId, array $persone, string $alla): array
    {
        $esito = [];
        foreach ($this->inCorso($condominioId, $alla, $persone) as $persona => $suoi) {
            $lettura = app(LetturaDebiti::class)->perPersona($condominioId, (int) $persona, $alla)['rate'];
            $parti = array_filter($this->parti($suoi, $lettura, $alla), fn ($p) => $p['fuori_dalle_lettere'] && $p['in_accordo'] > 0);
            if ($parti !== []) {
                $esito[(int) $persona] = array_map(fn ($p) => (int) $p['in_accordo'], $parti);
            }
        }

        return $esito;
    }

    /**
     * La stampa dell'accordo con il calendario, per la persona (PDF): le rate coperte, il calendario, la regola di decadenza, l'autorizzazione, e in testa la
     * decadenza registrata o la chiusura. La scarica la finestra dell'accordo, e va nel fascicolo per il legale.
     */
    public function stampa(\App\Models\Condominio $condominio, AccordoRientro $a): string
    {
        $a->loadMissing(['rate', 'scadenze']);
        $persona = \App\Models\Anagrafica::findOrFail($a->anagrafica_id);
        $nomi = self::nomiDelleRate($a->rate->map(fn ($r) => ['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id])->all());
        $descrizione = self::descrizione($a);
        $dati = [
            'condominio' => $condominio,
            'persona' => ['nome' => (string) $persona->nome, 'indirizzo' => $persona->indirizzo],
            'accordo' => $descrizione + [
                'riconosciuti' => (int) $a->rate->sum('importo_riconosciuto'),
                'rate' => $a->rate->map(fn ($r) => ['riconosciuto' => (int) $r->importo_riconosciuto] + $nomi(['rata_id' => $r->rata_id, 'immobile_id' => $r->immobile_id]))->values()->all(),
                'calendario' => $a->scadenze->map(fn ($r) => ['numero' => (int) $r->numero, 'data' => $r->data->format('Y-m-d'), 'importo' => (int) $r->importo])->values()->all(),
            ],
            'regola' => self::fraseRegola($descrizione['regola']),
            'autorizzazione' => self::fraseAutorizzazione($descrizione),
            'data_emissione_stampe' => DateHelper::adessoUtente(),
            'gruppo_carta' => \App\Services\PDF\GruppoCarta::PER_LA_PERSONA,
        ];

        return app(\App\Services\PDF\PdfService::class)->generate('pdf.gestionale.accordo_rientro', $dati, ['margin_top' => 14])->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Il nome di ogni rata coperta da un accordo: l'unità, il piano, il numero, la scadenza, e per un addebito a una persona sola (gli interessi, le spese,
     * un contributo) la sua causale.
     *
     * @param list<array{rata_id: int|string, immobile_id: int|string|null}> $rate
     */
    public static function nomiDelleRate(array $rate): callable
    {
        $rataIds = array_values(array_unique(array_map(fn ($r) => (int) $r['rata_id'], $rate)));
        $unitaIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['immobile_id'] === null ? null : (int) $r['immobile_id'], $rate))));
        $info = DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->whereIn('rate.id', $rataIds ?: [0])
            ->get(['rate.id', 'rate.numero_rata', 'rate.data_scadenza', 'rate.descrizione', 'piani_rate.nome', 'piani_rate.natura'])->keyBy('id');
        $unita = \App\Models\Immobile::whereIn('id', $unitaIds ?: [0])->get()->keyBy('id');

        return fn (array $r) => [
            'unita' => $r['immobile_id'] !== null && isset($unita[(int) $r['immobile_id']]) ? $unita[(int) $r['immobile_id']]->etichetta : null,
            'piano' => (string) ($info[(int) $r['rata_id']]->nome ?? ''),
            'numero_rata' => (int) ($info[(int) $r['rata_id']]->numero_rata ?? 0),
            'scadenza' => isset($info[(int) $r['rata_id']]) ? substr((string) $info[(int) $r['rata_id']]->data_scadenza, 0, 10) : null,
            'causale' => ($info[(int) $r['rata_id']]->natura ?? null) === PianoRate::NATURA_ADDEBITI ? (string) $info[(int) $r['rata_id']]->descrizione : null,
        ];
    }

    /**
     * Il debito che la proposta degli interessi lascia fuori (decisioni 389 (2), 433, 438 e 450): persona => «rata|unità» => la parte in accordo (`parte`),
     * il primo giorno che resta fuori (`dal`, il giorno dell'accordo) e, per una rata coperta in parte con gli interessi che continuano sulla parte fuori,
     * la parte nell'accordo giorno per giorno (`protetto`); null quando dal giorno dell'accordo resta fuori tutta la rata. Sono le rate coperte di ogni
     * accordo che {@see tieneFuoriGliInteressi()} tiene fuori; la parte è quella di {@see partiInCorso()}, a zero per la rata coperta già versata tutta e
     * per le rate di un accordo finito e rispettato: la parte a zero non cambia la frase «€ … sono in un accordo». Una rata in due accordi che la tengono
     * fuori (non dovrebbe succedere) resta fuori dal giorno del primo. Per un accordo chiuso da una successione in regola, `fine` è il giorno prima del
     * decesso: l'ultimo in cui la parte fuori, se c'è, prende ancora interessi (decisione 454). `sospesi` dice se per quella rata valgono i «sospesi» della
     * decisione 450 ({@see sospesiSullaParteFuori()}): è la sola ragione per cui la proposta scrive che resta fuori tutta la rata per una scelta
     * dell'amministratore (Coda 678); una rata coperta per intero, o di un accordo chiuso, esce tutta per un'altra ragione (Coda 712).
     *
     * @param list<int> $persone
     * @return array<int, array<string, array{parte: int, dal: string, protetto: ?list<array{dal: string, importo: int}>, sospesi: bool, fine?: string}>>
     */
    public function senzaInteressi(int $condominioId, array $persone, string $alla): array
    {
        $parti = $this->partiInCorso($condominioId, $persone, $alla);
        $esito = [];
        $accordi = AccordoRientro::with(['rate', 'scadenze'])->where('condominio_id', $condominioId)
            ->whereIn('anagrafica_id', $persone === [] ? [0] : $persone)->whereDate('data_accordo', '<=', $alla)->orderBy('data_accordo')->orderBy('id')->get();
        foreach ($accordi as $a) {
            if (! $this->tieneFuoriGliInteressi($a, $alla)) {
                continue;
            }
            $persona = (int) $a->anagrafica_id;
            $versamenti = null;
            // Chiuso dalla successione in regola: dal decesso il defunto non matura interessi neanche sulla parte fuori (decisione 454).
            $fine = $this->chiusoInRegolaPerLaSuccessione($a, $alla) ? ['fine' => CarbonImmutable::parse($a->chiuso_il->format('Y-m-d'), 'UTC')->subDay()->format('Y-m-d')] : [];
            foreach ($a->rate as $coperta) {
                $k = self::chiave($coperta->rata_id, $coperta->immobile_id);
                if (isset($esito[$persona][$k])) {
                    continue;
                }
                $protetto = null;
                if (self::interessiSullaParteFuori($a, $coperta)) {
                    $versamenti ??= $this->versamenti($a);
                    $protetto = self::protetto($a, $coperta, $versamenti, $alla);
                }
                $esito[$persona][$k] = ['parte' => (int) ($parti[$persona][$k] ?? 0), 'dal' => self::primoGiornoFuori($a), 'protetto' => $protetto,
                    'sospesi' => self::sospesiSullaParteFuori($a, $coperta, $alla)] + $fine;
            }
        }

        return $esito;
    }

    /**
     * La regola della decisione 450, in un punto solo: di una rata coperta da un accordo solo in parte (il riconosciuto meno del residuo del giorno
     * dell'accordo), sulla parte fuori dall'accordo gli interessi continuano, salvo che l'amministratore abbia scelto nell'accordo che restino sospesi finché
     * l'accordo regge. Una rata coperta per intero, o registrata prima che si tenesse il residuo del giorno dell'accordo, esce tutta.
     */
    public static function interessiSullaParteFuori(AccordoRientro $a, AccordoRientroRata $coperta): bool
    {
        return ($a->interessi_parte_fuori ?? AccordoRientro::INTERESSI_CONTINUANO) === AccordoRientro::INTERESSI_CONTINUANO
            && $coperta->residuo_iniziale !== null && (int) $coperta->importo_riconosciuto < (int) $coperta->residuo_iniziale;
    }

    /**
     * I «sospesi» della decisione 450 per una rata coperta, alla data: l'amministratore li ha scelti nell'accordo, l'accordo copre la rata solo in parte (il
     * riconosciuto meno del residuo del giorno dell'accordo: il caso opposto di {@see interessiSullaParteFuori()}), ed è in corso, non chiuso. Una rata
     * coperta per intero esce tutta perché l'accordo la copre tutta; un accordo chiuso, anche in regola da una successione, non «regge» più per la scelta.
     */
    public static function sospesiSullaParteFuori(AccordoRientro $a, AccordoRientroRata $coperta, string $alla): bool
    {
        return ($a->interessi_parte_fuori ?? AccordoRientro::INTERESSI_CONTINUANO) === AccordoRientro::INTERESSI_SOSPESI
            && $coperta->residuo_iniziale !== null && (int) $coperta->importo_riconosciuto < (int) $coperta->residuo_iniziale
            && $a->inCorsoIl($alla);
    }

    /**
     * La parte di una rata coperta che resta nell'accordo, giorno per giorno dal giorno dell'accordo alla data: il riconosciuto meno quello che i versamenti
     * le hanno dato con la regola dell'incasso ({@see allocati()}), come la parte in accordo delle lettere ({@see inAccordo()}). Un versamento conta dal
     * giorno dopo, come negli interessi; uno stornato smette dal giorno dopo la sua rettifica. Un passo per ogni giorno in cui cambia.
     *
     * @return list<array{dal: string, importo: int}>
     */
    private static function protetto(AccordoRientro $a, AccordoRientroRata $coperta, array $versamenti, string $alla): array
    {
        $k = self::chiave($coperta->rata_id, $coperta->immobile_id);
        $riconosciuto = (int) $coperta->importo_riconosciuto;
        $passi = [['dal' => self::primoGiornoFuori($a), 'importo' => $riconosciuto]];
        $giorni = [];
        foreach ($versamenti as $v) {
            if (isset($v['importi'][$k])) {
                $giorni[] = $v['dal'];
                if ($v['al'] !== null) {
                    $giorni[] = $v['al'];
                }
            }
        }
        $giorni = array_values(array_unique($giorni));
        sort($giorni);
        foreach ($giorni as $g) {
            if ($g < self::primoGiornoFuori($a) || $g >= $alla) {
                continue;
            }
            $importo = max(0, $riconosciuto - (int) (self::allocati($a, $versamenti, $g)[$k] ?? 0));
            if ($importo !== $passi[count($passi) - 1]['importo']) {
                $passi[] = ['dal' => CarbonImmutable::parse($g, 'UTC')->addDay()->format('Y-m-d'), 'importo' => $importo];
            }
        }

        return $passi;
    }

    /**
     * La regola, in un punto solo (decisioni 433 e 438): le rate coperte da un accordo restano fuori dalla proposta degli interessi, dal giorno dell'accordo
     * in poi ({@see primoGiornoFuori()}), se l'accordo non è decaduto alla data e il suo calendario non è finito (in corso, «fino al» non passato), oppure è
     * tutto versato. I giorni prima dell'accordo restano nella proposta. Dopo «fino al», o chiuso senza decadenza, con qualcosa ancora da versare le rate
     * tornano con tutti i giorni, come nelle lettere e nell'incasso; con la decadenza registrata tornano sempre.
     *
     * Chiuso da una successione (decisione 454): di serie l'accordo si legge al giorno prima della chiusura, il giorno prima del decesso, e se quel giorno
     * reggeva resta fuori anche dopo ({@see chiusoInRegolaPerLaSuccessione()}); con la scelta «vale come una decadenza» tornano i giorni, come con la
     * decadenza registrata.
     */
    public function tieneFuoriGliInteressi(AccordoRientro $a, string $alla): bool
    {
        if ($a->decadenza_registrata_il !== null && $a->decadenza_registrata_il->format('Y-m-d') <= $alla) {
            return false;
        }
        if ($a->inCorsoIl($alla) && $alla <= $a->fino_al->format('Y-m-d')) {
            return true;
        }
        if (self::chiusoPerLaSuccessione($a, $alla)) {
            return $a->chiusura_per_successione === AccordoRientro::SUCCESSIONE_IN_REGOLA && $this->reggevaIl($a, self::giornoDiLettura($a, $alla));
        }

        return $this->tuttoVersato($a, $alla);
    }

    /**
     * L'accordo che una successione ha chiuso alla data, con la scelta di serie, «resta in regola fino al decesso», e che il giorno prima della chiusura
     * reggeva: da quel giorno il defunto non matura più interessi sulle sue rate coperte (decisione 454).
     */
    public function chiusoInRegolaPerLaSuccessione(AccordoRientro $a, string $alla): bool
    {
        return self::chiusoPerLaSuccessione($a, $alla) && $a->chiusura_per_successione === AccordoRientro::SUCCESSIONE_IN_REGOLA
            && $this->reggevaIl($a, self::giornoDiLettura($a, $alla));
    }

    /**
     * L'accordo reggeva quel giorno, con la regola di {@see tieneFuoriGliInteressi()} per un accordo in corso: il calendario non era finito, oppure era tutto
     * versato. La lettura della successione: un accordo il cui calendario era già finito con rate non versate non era in regola al decesso.
     */
    public function reggevaIl(AccordoRientro $a, string $giorno): bool
    {
        if ($a->decadenza_registrata_il !== null && $a->decadenza_registrata_il->format('Y-m-d') <= $giorno) {
            return false;
        }

        return ($a->data_accordo->format('Y-m-d') <= $giorno && $giorno <= $a->fino_al->format('Y-m-d')) || $this->tuttoVersato($a, $giorno);
    }

    /** Chiuso da una successione il giorno della data o prima. */
    private static function chiusoPerLaSuccessione(AccordoRientro $a, string $alla): bool
    {
        return $a->chiusura_per_successione !== null && $a->chiuso_il !== null && $a->chiuso_il->format('Y-m-d') <= $alla;
    }

    /**
     * Il primo giorno che un accordo tiene fuori dalla proposta degli interessi quando la regola lo tiene fuori ({@see tieneFuoriGliInteressi()}): il giorno
     * dell'accordo. Il giorno prima è l'ultimo che resta nella proposta (decisione 438).
     */
    public static function primoGiornoFuori(AccordoRientro $a): string
    {
        return $a->data_accordo->format('Y-m-d');
    }

    /** Il calendario dell'accordo tutto versato alla data (un accordo chiuso si legge al giorno prima della chiusura); senza calendario, tutto il riconosciuto. */
    private function tuttoVersato(AccordoRientro $a, string $alla): bool
    {
        $giorno = self::giornoDiLettura($a, $alla);
        $versato = (int) array_sum(self::allocati($a, $this->versamenti($a), $giorno));
        $scadenze = self::scadenzeDi($a);
        if ($scadenze === []) {
            return $versato >= (int) $a->rate->sum(fn ($r) => (int) $r->importo_riconosciuto);
        }

        return collect(CalendarioAccordo::rate($scadenze, $versato, $giorno))->every(fn ($r) => $r['versata']);
    }

    /**
     * Il calendario di un accordo letto a un giorno, dato quello che i versamenti hanno dato all'accordo fino a quel giorno: le rate con il versato di
     * ognuna, quante delle scadute non sono versate, la prima non versata, e i segnali della regola di decadenza fino alla data. Un accordo senza
     * calendario ha `calendario` nullo.
     *
     * @return array{calendario: ?list<array<string,mixed>>, rate_non_versate: ?int, prossima: ?array<string,mixed>, segnali: array{rate: ?array, giorni: ?array}}
     */
    public function calendario(AccordoRientro $a, int $versato, string $giorno, ?string $alla = null, ?array $versamenti = null): array
    {
        $scadenze = self::scadenzeDi($a);
        if ($scadenze === []) {
            return ['calendario' => null, 'rate_non_versate' => null, 'prossima' => null, 'segnali' => ['rate' => null, 'giorni' => null]];
        }
        $rate = CalendarioAccordo::rate($scadenze, $versato, $giorno);
        $prossima = collect($rate)->first(fn ($r) => ! $r['versata']);

        return [
            'calendario' => $rate,
            'rate_non_versate' => count(array_filter($rate, fn ($r) => $r['scaduta'] && ! $r['versata'])),
            'prossima' => $prossima === null ? null : ['numero' => $prossima['numero'], 'data' => $prossima['data'], 'importo' => $prossima['importo'], 'mancano' => $prossima['mancano']],
            'segnali' => $this->segnali($a, $alla ?? $giorno, $versamenti),
        ];
    }

    /**
     * I segnali della regola di decadenza fino a una data: per ogni forma il primo giorno in cui si è verificata e le rate di quel giorno con i loro
     * versati, conservati nell'accordo la prima volta che si trovano e poi letti com'erano. Ogni segnale dice anche se il giornale di oggi lo smentisce
     * (`smentito`): un versamento registrato dopo con una data di prima, per cui quel giorno la condizione non c'era. Si cerca dal giorno dell'accordo
     * alla data (mai oltre oggi, e per un accordo chiuso fino al giorno prima della chiusura). Un segnale con il giorno dopo la data non si mostra.
     *
     * @return array{rate: ?array<string,mixed>, giorni: ?array<string,mixed>}
     */
    public function segnali(AccordoRientro $a, string $alla, ?array $versamenti = null): array
    {
        $vuoti = ['rate' => null, 'giorni' => null];
        $scadenze = self::scadenzeDi($a);
        if ($scadenze === [] || ($a->decadenza_rate === null && $a->decadenza_giorni === null)) {
            return $vuoti;
        }
        $versamenti ??= $this->versamenti($a);
        $versatoAl = fn (string $giorno) => (int) array_sum(self::allocati($a, $versamenti, $giorno));
        $conservati = is_array($a->segnali) ? $a->segnali : [];
        $fino = min($alla, DateHelper::oggiUtente());
        if ($a->chiuso_il !== null) {
            $fino = min($fino, CarbonImmutable::parse($a->chiuso_il->format('Y-m-d'), 'UTC')->subDay()->format('Y-m-d'));
        }
        $nRate = $a->decadenza_rate === null ? null : (int) $a->decadenza_rate;
        $giorni = $a->decadenza_giorni === null ? null : (int) $a->decadenza_giorni;
        $cercaRate = $nRate !== null && ! isset($conservati['rate']);
        $cercaGiorni = $giorni !== null && ! isset($conservati['giorni']);
        if ($cercaRate || $cercaGiorni) {
            $trovati = array_filter(CalendarioAccordo::segnali($scadenze, $cercaRate ? $nRate : null, $cercaGiorni ? $giorni : null, $versatoAl,
                self::giorniDeiVersamenti($versamenti), $a->data_accordo->format('Y-m-d'), $fino));
            if ($trovati !== []) {
                $conservati = $conservati + $trovati;
                DB::table('accordi_rientro')->where('id', $a->id)->update(['segnali' => json_encode($conservati)]);
                $a->setRawAttributes(['segnali' => json_encode($conservati)] + $a->getAttributes(), true);
            }
        }

        $esito = $vuoti;
        foreach (['rate', 'giorni'] as $forma) {
            $s = $conservati[$forma] ?? null;
            if ($s === null || $s['il'] > $alla) {
                continue;
            }
            // Quel giorno, con il giornale di oggi: la condizione c'era ancora?
            $oggi = CalendarioAccordo::condizioni($scadenze, $forma === 'rate' ? $nRate : null, $forma === 'giorni' ? $giorni : null, $versatoAl($s['il']), $s['il']);
            $esito[$forma] = $s + ['smentito' => $oggi[$forma] === null];
        }

        return $esito;
    }

    /**
     * Quello che i versamenti hanno dato all'accordo alla fine di un giorno ({@see allocati()}). Su rate coperte per intero e con versamenti senza la
     * scelta, è anche i riconosciuti meno la parte in accordo della lettura comune a quel giorno: lo prova il test della seconda fonte.
     */
    public function versatoAlGiorno(AccordoRientro $a, string $giorno): int
    {
        return (int) array_sum(self::allocati($a, $this->versamenti($a), Scadenza::giorno($giorno)));
    }

    /**
     * La regola di decadenza dell'ultimo accordo con il calendario registrato nel condominio: la finestra la propone per il prossimo. Null se il
     * condominio non ne ha.
     *
     * @return array{rate: ?int, giorni: ?int}|null
     */
    public static function regolaDiSerie(int $condominioId): ?array
    {
        $ultimo = AccordoRientro::where('condominio_id', $condominioId)->whereHas('scadenze')->orderByDesc('id')->first(['id', 'decadenza_rate', 'decadenza_giorni']);

        return $ultimo === null ? null : ['rate' => $ultimo->decadenza_rate, 'giorni' => $ultimo->decadenza_giorni];
    }

    /**
     * Quanto di ogni rata di una persona le lettere lasciano fuori alla data, per gli accordi in corso fino a «fino al»: «rata|unità» => centesimi.
     * È la stessa parte che {@see \App\Services\Gestionale\Lettere\EmissioneLettere} toglie dalla lettera; la rilegge chi deve sapere se una
     * lettera di prima chiede ancora quello che chiederebbe oggi.
     *
     * @param list<array<string,mixed>> $rateLettura le righe `rate` di {@see LetturaDebiti} alla data, per la persona
     * @return array<string, int>
     */
    public function fuoriDalleLettere(int $condominioId, int $personaId, array $rateLettura, string $alla): array
    {
        $accordi = $this->inCorso($condominioId, $alla, [$personaId])[$personaId] ?? null;
        if ($accordi === null) {
            return [];
        }
        $parti = [];
        foreach ($this->parti($accordi, $rateLettura, $alla) as $k => $p) {
            if ($p['fuori_dalle_lettere'] && $p['in_accordo'] > 0) {
                $parti[$k] = (int) $p['in_accordo'];
            }
        }

        return $parti;
    }

    /** I dati di un accordo come li mostrano l'elenco e la finestra, senza le cifre di oggi. */
    public static function descrizione(AccordoRientro $a): array
    {
        return [
            'id'                     => (int) $a->id,
            'data_accordo'           => $a->data_accordo->format('Y-m-d'),
            'fino_al'                => $a->fino_al->format('Y-m-d'),
            'autorizzato_assemblea'  => (bool) $a->autorizzato_assemblea,
            'data_autorizzazione'    => $a->data_autorizzazione?->format('Y-m-d'),
            'verbale_autorizzazione' => $a->verbale_autorizzazione,
            'nota'                   => $a->nota,
            'chiuso_il'              => $a->chiuso_il?->format('Y-m-d'),
            'nota_chiusura'          => $a->nota_chiusura,
            'regola'                 => ['rate' => $a->decadenza_rate, 'giorni' => $a->decadenza_giorni],
            'decadenza_registrata_il' => $a->decadenza_registrata_il?->format('Y-m-d'),
            // Sulla parte della rata fuori dall'accordo gli interessi continuano o restano sospesi (decisione 450).
            'interessi_parte_fuori'  => (string) ($a->interessi_parte_fuori ?? AccordoRientro::INTERESSI_CONTINUANO),
            // Il registro dell'accordo: le scelte cambiate dopo la registrazione, una frase per cambio.
            'registro'               => array_map(fn (array $r) => self::fraseDelRegistro($r), is_array($a->registro) ? $a->registro : []),
            'chiusura_per_successione' => $a->chiusura_per_successione,
        ];
    }

    /** I nomi delle due scelte sulla parte fuori, come li dice il menu (`SCELTE_PARTE_FUORI` in `accordiRientro.ts`). */
    public const NOMI_PARTE_FUORI = [
        AccordoRientro::INTERESSI_CONTINUANO => 'gli interessi continuano',
        AccordoRientro::INTERESSI_SOSPESI => 'restano sospesi finché l\'accordo regge',
    ];

    /** Una riga del registro dell'accordo: «Il 20/07/2026 alle 12:00 Mario Rossi ha cambiato la scelta sulla parte della rata fuori dall'accordo: da «…» a «…».» */
    public static function fraseDelRegistro(array $r): string
    {
        $il = DateHelper::comeSiLegge(CarbonImmutable::parse((string) ($r['il'] ?? 'now')));
        $chi = trim((string) ($r['utente'] ?? '')) !== '' ? ' ' . $r['utente'] : '';
        if (($r['cosa'] ?? null) === 'interessi_parte_fuori') {
            return sprintf('Il %s alle %s%s ha cambiato la scelta sulla parte della rata fuori dall\'accordo: da «%s» a «%s».', $il->format('d/m/Y'), $il->format('H:i'), $chi,
                self::NOMI_PARTE_FUORI[$r['da'] ?? ''] ?? (string) ($r['da'] ?? ''), self::NOMI_PARTE_FUORI[$r['a'] ?? ''] ?? (string) ($r['a'] ?? ''));
        }

        return sprintf('Il %s alle %s%s ha cambiato l\'accordo.', $il->format('d/m/Y'), $il->format('H:i'), $chi);
    }

    /**
     * La parte in più rimasta a credito degli incassi della persona dal giorno dell'accordo ({@see dalGiornoDellAccordo()}) fino alla data (per un accordo chiuso, fino
     * al giorno prima della chiusura), che hanno coperto rate dell'accordo: la somma, per ogni incasso, di quello che la ricevuta chiama «parte in
     * più» lasciata a credito.
     */
    public function versatiOltre(AccordoRientro $a, string $alla): int
    {
        $rate = $a->rate->pluck('rata_id')->unique()->values()->all();
        if ($rate === []) {
            return 0;
        }
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        // Un accordo chiuso non è in corso dal giorno della chiusura: i versamenti di quel giorno e dopo non sono suoi (un accordo nuovo sulla
        // stessa rata li conta, una volta).
        if ($a->chiuso_il !== null && $a->chiuso_il->format('Y-m-d') < $dopo) {
            $dopo = $a->chiuso_il->format('Y-m-d');
        }
        $incassi = DB::table('scritture_contabili as sc')
            ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
            ->where('sc.condominio_id', $a->condominio_id)
            ->where('sc.tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->where('sc.stato', '!=', 'annullata')
            ->whereNull('sc.deleted_at')
            ->where(fn ($q) => self::dalGiornoDellAccordo($q, $a))
            ->where('sc.data_competenza', '<', $dopo)
            ->where('rs.anagrafica_id', $a->anagrafica_id)
            ->whereIn('rs.rata_id', $rate)
            ->where('rs.tipo_riga', 'avere')
            ->distinct()
            ->pluck('sc.id');

        $oltre = 0;
        foreach (ScritturaContabile::whereIn('id', $incassi->all() ?: [0])->get() as $incasso) {
            $r = $this->ricevuta->per($incasso);
            if ((int) ($r['posizione']['id'] ?? 0) !== (int) $a->anagrafica_id) {
                continue;
            }
            foreach ($r['eccedenze'] as $e) {
                if (! $e['di_chi_ha_versato']) {
                    $oltre += (int) $e['a_credito'];
                }
            }
        }

        return $oltre;
    }

    /**
     * Il segno di un accordo nella stampa dell'elenco, in una riga: la data, e le due cifre o «residuo invariato». La pagina lo scrive con le
     * stesse parole (`accordiRientro.ts`), dalle date `Y-m-d` del server.
     */
    public static function fraseBreve(array $segno): string
    {
        $giorno = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');
        $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
        $cifre = $segno['invariato']
            ? 'riconosciuti ' . $euro($segno['riconosciuti']) . ', residuo invariato dal ' . $giorno($segno['data_accordo'])
            : 'riconosciuti ' . $euro($segno['riconosciuti']) . ', oggi ' . $euro($segno['oggi']);

        $calendario = self::statoDelCalendario($segno);
        $segnali = array_values(array_filter([$segno['segnali']['giorni'] ?? null, $segno['segnali']['rate'] ?? null]));
        usort($segnali, fn ($x, $y) => strcmp($x['il'], $y['il']));
        $segnale = $segnali === [] ? ''
            : '; la condizione di decadenza scritta nell\'accordo si è verificata il ' . $giorno($segnali[0]['il']) . (($segnali[0]['smentito'] ?? false) ? ', e nel giornale di oggi quel giorno non c\'era' : '');

        return 'accordo di rientro del ' . $giorno($segno['data_accordo']) . ', fino al ' . $giorno($segno['fino_al']) . ($segno['fino_al_passato'] ? ' (data passata)' : '') . ': ' . $cifre
            . ($calendario !== null ? '; ' . $calendario : '') . $segnale;
    }

    /** La regola di decadenza scritta come nell'accordo; le stesse parole della pagina (`testoRegola` in `accordiRientro.ts`). */
    public static function fraseRegola(array $regola): string
    {
        $parti = array_values(array_filter([
            ($regola['rate'] ?? null) !== null ? $regola['rate'] . ' ' . ((int) $regola['rate'] === 1 ? 'rata del calendario non versata' : 'rate del calendario non versate, anche non consecutive') : null,
            ($regola['giorni'] ?? null) !== null ? $regola['giorni'] . ' ' . ((int) $regola['giorni'] === 1 ? 'giorno' : 'giorni') . ' dopo una rata non versata' : null,
        ]));

        return $parti === [] ? 'L\'accordo non ha una regola di decadenza.' : 'Regola di decadenza: ' . implode('; oppure ', $parti) . '.';
    }

    /** L'autorizzazione dell'assemblea, con le parole della pagina (`testoAutorizzazione` in `accordiRientro.ts`). */
    public static function fraseAutorizzazione(array $segno): string
    {
        if (! ($segno['autorizzato_assemblea'] ?? false)) {
            return 'Non autorizzato dall\'assemblea.';
        }
        $verbale = filled($segno['verbale_autorizzazione'] ?? null) ? ' (verbale n. ' . $segno['verbale_autorizzazione'] . ')' : '';

        return 'Autorizzato dall\'assemblea del ' . CarbonImmutable::parse($segno['data_autorizzazione'])->format('d/m/Y') . $verbale . '.';
    }

    /**
     * A che punto è il calendario, in una riga, per la tesoreria e la stampa dell'elenco: quante rate scadute non sono versate, o che quelle scadute
     * sono versate, o che nessuna è ancora scaduta. Null senza calendario.
     */
    public static function fraseCalendario(array $segno): ?string
    {
        $stato = self::statoDelCalendario($segno);

        return $stato === null ? null : 'accordo di rientro: ' . $stato;
    }

    /** Lo stato del calendario senza il nome dell'accordo: «una rata non versata», «le rate scadute sono versate»…; null senza calendario. */
    public static function statoDelCalendario(array $segno): ?string
    {
        if (($segno['calendario'] ?? null) === null) {
            return null;
        }
        $scadute = count(array_filter($segno['calendario'], fn ($r) => $r['scaduta']));
        $n = (int) $segno['rate_non_versate'];

        return match (true) {
            $scadute === 0 => 'nessuna rata ancora scaduta',
            $n === 0 => 'le rate scadute sono versate',
            $n === 1 => 'una rata non versata',
            default => $n . ' rate non versate',
        };
    }

    /** La chiave di una rata di una persona: rata e unità, come la lettura. */
    public static function chiave(mixed $rataId, mixed $immobileId): string
    {
        return ((int) $rataId) . '|' . ($immobileId === null ? '' : (int) $immobileId);
    }

    /**
     * Le rate che un accordo può coprire a una data: le rate scadute della persona con un residuo, con il residuo di quel giorno come importo
     * proposto. La lettura è quella comune, a quella data. Le rate del piano degli addebiti (gli interessi e le spese registrati, i contributi) anche
     * quando scadono quel giorno: gli interessi proposti e registrati la mattina dell'accordo scadono il giorno della registrazione.
     *
     * @param list<int> $pianiDegliAddebiti i piani degli addebiti del condominio
     * @return list<array<string,mixed>>
     */
    public static function coperturePossibili(array $lettura, array $pianiDegliAddebiti = []): array
    {
        $alla = (string) ($lettura['alla'] ?? '');

        return array_values(array_map(fn ($r) => [
            'rata_id' => (int) $r['rata_id'], 'immobile_id' => $r['immobile_id'] === null ? null : (int) $r['immobile_id'], 'numero_rata' => (int) $r['numero_rata'],
            'piano_rate_id' => (int) $r['piano_rate_id'], 'scadenza' => $r['scadenza'], 'residuo' => (int) $r['residuo'],
        ], array_filter($lettura['rate'], fn ($r) => $r['residuo'] > 0
            && ($r['scaduta'] || (in_array((int) $r['piano_rate_id'], $pianiDegliAddebiti, true) && $alla !== '' && $r['scadenza'] <= $alla)))));
    }

    /** I piani degli addebiti del condominio. @return list<int> */
    public static function pianiDegliAddebiti(int $condominioId): array
    {
        return PianoRate::degliAddebiti()->where('condominio_id', $condominioId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Le rate del calendario di un accordo, come le legge {@see CalendarioAccordo}. @return list<array{numero:int, data:string, importo:int}> */
    private static function scadenzeDi(AccordoRientro $a): array
    {
        return $a->scadenze->map(fn ($r) => ['numero' => (int) $r->numero, 'data' => $r->data->format('Y-m-d'), 'importo' => (int) $r->importo])->values()->all();
    }

    /** I residui della lettura, per «rata|unità». @return array<string,int> */
    private static function residui(array $rateLettura): array
    {
        $residui = [];
        foreach ($rateLettura as $r) {
            $residui[self::chiave($r['rata_id'], $r['immobile_id'])] = (int) $r['residuo'];
        }

        return $residui;
    }

    /**
     * I versamenti sulle rate coperte, dal giorno dell'accordo ({@see dalGiornoDellAccordo()}): gli incassi e le loro compensazioni con il credito (le
     * scritture che l'ordine dell'incasso imputa), ognuno con la sua dichiarazione (`accordo_rientro` o no), il giorno da cui vale e, se è stornato, il
     * giorno della sua rettifica, da cui non vale più. Per ogni rata coperta (rata e unità) l'avere meno il dare della persona. Non sono versamenti un passaggio di
     * proprietà, un'emissione annullata, gli interessi passati agli eredi: abbassano il residuo, ma non sono denaro dato all'accordo.
     *
     * @return list<array{id:int, dal:string, al:?string, accordo:bool, importi:array<string,int>}> in ordine di giorno e di registrazione
     */
    private function versamenti(AccordoRientro $a): array
    {
        $coperte = [];
        foreach ($a->rate as $r) {
            $coperte[self::chiave($r->rata_id, $r->immobile_id)] = true;
        }
        if ($coperte === []) {
            return [];
        }
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')
            ->where('sc.condominio_id', $a->condominio_id)
            ->whereIn('sc.tipo_movimento', [TipoMovimentoContabile::INCASSO_RATA->value, TipoMovimentoContabile::STORNO_CREDITO->value])
            ->where(fn ($q) => self::dalGiornoDellAccordo($q, $a))
            ->where('cc.ruolo', 'crediti_condomini')
            ->where('rs.anagrafica_id', $a->anagrafica_id)
            ->whereIn('rs.rata_id', $a->rate->pluck('rata_id')->unique()->values()->all())
            ->get(['sc.id', 'sc.data_competenza', 'sc.stato', 'sc.imputazione', 'sc.scrittura_padre_id', 'sc.tipo_movimento', 'rs.rata_id', 'rs.immobile_id', 'rs.tipo_riga', 'rs.importo']);
        if ($righe->isEmpty()) {
            return [];
        }
        $ids = $righe->pluck('id')->unique()->values()->all();
        // La dichiarazione di una compensazione è quella del suo incasso.
        $padri = DB::table('scritture_contabili')->whereIn('id', $righe->pluck('scrittura_padre_id')->filter()->unique()->values()->all() ?: [0])->pluck('imputazione', 'id');
        // Il giorno da cui una scrittura stornata non vale più: quello della sua rettifica.
        $rettifiche = DB::table('scritture_contabili')->whereNull('deleted_at')->whereIn('scrittura_padre_id', $ids)
            ->where('tipo_movimento', TipoMovimentoContabile::RETTIFICA->value)->get(['scrittura_padre_id', 'data_competenza'])
            ->groupBy('scrittura_padre_id')->map(fn ($g) => Scadenza::giorno((string) $g->min('data_competenza')));

        $versamenti = [];
        foreach ($righe as $r) {
            $k = self::chiave($r->rata_id, $r->immobile_id);
            if (! isset($coperte[$k])) {
                continue;
            }
            $tipo = $r->tipo_movimento instanceof \BackedEnum ? $r->tipo_movimento->value : $r->tipo_movimento;
            $imputazione = $tipo === TipoMovimentoContabile::STORNO_CREDITO->value ? ($padri[$r->scrittura_padre_id] ?? null) : $r->imputazione;
            $dal = Scadenza::giorno((string) $r->data_competenza);
            $al = $rettifiche[$r->id] ?? ($r->stato === 'annullata' ? $dal : null);
            $versamenti[$r->id] ??= ['id' => (int) $r->id, 'dal' => $dal, 'al' => $al,
                'accordo' => $imputazione === \App\Services\Gestionale\Incassi\OrdineImputazione::ACCORDO_RIENTRO, 'importi' => []];
            $versamenti[$r->id]['importi'][$k] = ($versamenti[$r->id]['importi'][$k] ?? 0) + ($r->tipo_riga === 'avere' ? (int) $r->importo : -(int) $r->importo);
        }
        usort($versamenti, fn ($x, $y) => [$x['dal'], $x['id']] <=> [$y['dal'], $y['id']]);

        return $versamenti;
    }

    /**
     * I movimenti che sono dell'accordo per il giorno: quelli dei giorni dopo l'accordo e, del giorno dell'accordo, solo quelli registrati dopo
     * l'accordo. Un versamento dello stesso giorno registrato prima è già nel residuo del giorno da cui l'accordo è partito (la lettura comune legge fino
     * alla fine del giorno, e il residuo del giorno dell'accordo lo conserva): contarlo anche fra quelli dati all'accordo lo conterebbe due volte. Il
     * confronto è sul giorno (`whereDate`), non sulla stringa: una data scritta con l'ora è maggiore, come stringa, del solo giorno.
     */
    private static function dalGiornoDellAccordo(\Illuminate\Database\Query\Builder $q, AccordoRientro $a): void
    {
        $giorno = $a->data_accordo->format('Y-m-d');
        if ($a->created_at === null) {
            // Un accordo senza l'ora della registrazione (scritto a mano): tutto il giorno, come prima.
            $q->whereDate('sc.data_competenza', '>=', $giorno);

            return;
        }
        $q->whereDate('sc.data_competenza', '>', $giorno)
            ->orWhere(fn ($w) => $w->whereDate('sc.data_competenza', $giorno)->where('sc.created_at', '>=', $a->created_at));
    }

    /**
     * Quello che i versamenti che valgono alla fine di un giorno hanno dato all'accordo, per «rata|unità»: la stessa regola dell'incasso. Su una rata
     * coperta in parte, un versamento senza la scelta va prima alla parte fuori dall'accordo (art. 1193: il debitore non ha detto altro), uno con
     * «Versamento dell'accordo» prima alla parte nell'accordo (lo ha dichiarato); una rata coperta per intero non ha la parte fuori.
     *
     * @param list<array{id:int, dal:string, al:?string, accordo:bool, importi:array<string,int>}> $versamenti
     * @return array<string, int>
     */
    private static function allocati(AccordoRientro $a, array $versamenti, string $giorno): array
    {
        $dentro = [];
        $fuori = [];
        foreach ($a->rate as $c) {
            $k = self::chiave($c->rata_id, $c->immobile_id);
            $dentro[$k] = (int) $c->importo_riconosciuto;
            $fuori[$k] = $c->residuo_iniziale === null ? 0 : max(0, (int) $c->residuo_iniziale - (int) $c->importo_riconosciuto);
        }
        $allocati = [];
        foreach ($versamenti as $v) {
            if ($v['dal'] > $giorno || ($v['al'] !== null && $v['al'] <= $giorno)) {
                continue;
            }
            foreach ($v['importi'] as $k => $importo) {
                if ($importo <= 0 || ! isset($dentro[$k])) {
                    continue;
                }
                if ($v['accordo']) {
                    $all = min($importo, $dentro[$k]);
                    $fuori[$k] -= min($importo - $all, $fuori[$k]);
                } else {
                    $f = min($importo, $fuori[$k]);
                    $fuori[$k] -= $f;
                    $all = min($importo - $f, $dentro[$k]);
                }
                $dentro[$k] -= $all;
                $allocati[$k] = ($allocati[$k] ?? 0) + $all;
            }
        }

        return $allocati;
    }

    /** La parte di una rata coperta ancora nell'accordo: il riconosciuto meno quello che i versamenti gli hanno dato, mai più del residuo. */
    private static function inAccordo(AccordoRientroRata $coperta, int $residuo, int $allocato): int
    {
        return max(0, min($residuo, (int) $coperta->importo_riconosciuto - $allocato));
    }

    /** Il giorno a cui si legge il calendario: la data, o il giorno prima della chiusura di un accordo chiuso. */
    private static function giornoDiLettura(AccordoRientro $a, string $alla): string
    {
        if ($a->chiuso_il === null || $a->chiuso_il->format('Y-m-d') > $alla) {
            return $alla;
        }

        return max($a->data_accordo->format('Y-m-d'), CarbonImmutable::parse($a->chiuso_il->format('Y-m-d'), 'UTC')->subDay()->format('Y-m-d'));
    }

    /** I giorni in cui quello che va all'accordo cambia: quando un versamento comincia a valere e quando smette. @return list<string> */
    private static function giorniDeiVersamenti(array $versamenti): array
    {
        return array_values(array_unique(array_filter(array_merge(array_column($versamenti, 'dal'), array_column($versamenti, 'al')))));
    }
}
