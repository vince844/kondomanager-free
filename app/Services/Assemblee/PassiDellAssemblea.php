<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Assemblea;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Posta\PecDelloStudio;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use Illuminate\Validation\ValidationException;

/**
 * I sei passi della pagina dell'assemblea, la strada della variante A della bozza dei passi guidati: «Date e ordine del giorno», «Documenti»,
 * «Convocazione», «In sala», «Verbale e trasmissione», «Delibere eseguite». Per ognuno lo stato (`fatto`, `adesso`, `dopo` e `chiuso` con il perché), la riga sotto il nome, per
 * il passo di adesso «che cosa manca» (al più tre voci; quelle da fare con il loro collegamento; una voce che `informa` non conta e non blocca) e la frase
 * del piede.
 *
 * - **Date e ordine del giorno** è fatto quando le date valgono ({@see ControlloDelleDate}) e c'è almeno un punto. La tabella del valore che non copre tutte
 *   le unità e il luogo (o la piattaforma) che manca sono voci che informano: non bloccano il passo (il luogo lo chiede la spedizione). In preparazione le
 *   tre voci restano anche a passo fatto. Da convocata le date e i punti non si cambiano più: il passo è fatto.
 * - **Documenti** non blocca: è fatto quando lo è il passo delle date (l'avviso con la delega c'è sempre, gli allegati sono facoltativi).
 * - **Convocazione** è fatto quando ogni convocato di oggi ha il suo avviso spedito ({@see ElencoDeiConvocati::spedito()}, la regola anche del compito
 *   dell'Action inbox e dello stato dell'assemblea): la PEC partita, la raccomandata spedita, la consegna a mano registrata. Una lettera stampata e non
 *   ancora spedita non lo è, e chi non ha un mezzo valido tiene il passo aperto.
 * - **In sala** si apre **dalla prima spedizione registrata** (l'assemblea «convocata»), anche con la convocazione non ancora completa: prima del giorno con
 *   «Deleghe ricevute» e il foglio della sala, e «Apri la sala» spento; dal giorno della prima convocazione «Apri la sala» si accende, e chi non risulta fra
 *   i convocati lo vede il presidente all'apertura (art. 1136 c. 6). Durante «in corso», con i presenti e il punto; poi «chiusa» o «deserta», fatto. Dalla
 *   sala in poi la convocazione è fatta. **Verbale e trasmissione** si apre con la sala (la discussione dei punti) e dice a che punto è il verbale di
 *   ogni riunione: da scrivere, in bozza, da firmare, registrato e da trasmettere, fatto con i trenta giorni ({@see verbale()}).
 * - **Delibere eseguite** (il seguito delle delibere) ha il lucchetto fino al verbale registrato; poi si apre anche con la trasmissione da finire: «da
 *   confermare» finché «Che cosa cambia nel gestionale» non è confermato, poi «8 su 9 · 1 da eseguire» con «Da eseguire» e «Trenta giorni» in testa, fatto
 *   quando ogni delibera è eseguita. Il verbale annullato dopo la conferma lo chiude con la frase, e il passo si apre lo stesso: le schede che usano le
 *   delibere e le cose aperte restano da vedere ({@see delibere()}).
 * - Il passo di adesso è il primo non fatto e non chiuso; quelli dopo vengono dopo, e dicono il perché. Un'assemblea annullata ha i primi tre fatti (gli
 *   invii e le prove restano) e la sala e il verbale chiusi; una riunione registrata a mano, o tenuta fuori dal programma, non ha passi.
 */
final class PassiDellAssemblea
{
    /** I passi, nell'ordine. */
    public const PASSI = ['date' => 'Date e ordine del giorno', 'documenti' => 'Documenti', 'convocazione' => 'Convocazione', 'sala' => 'In sala',
        'verbale' => 'Verbale e trasmissione', 'delibere' => 'Delibere eseguite'];

    public const PIEDE_DATE = 'Il passo è fatto quando le date valgono e c\'è almeno un punto; la tabella che non copre tutte le unità avvisa e non blocca.';

    public const PIEDE_DOCUMENTI = 'Guarda l\'avviso con la delega e gli allegati, poi premi «Avanti: Convocazione»: il passo diventa fatto.';

    public const PIEDE_CONVOCAZIONE = 'Il passo è fatto quando tutti i convocati hanno il loro avviso spedito.';

    public const PIEDE_SALA = 'Il passo si apre dalla prima spedizione, con le deleghe ricevute e il foglio della sala; '
        . '«Apri la sala» si accende il giorno della prima convocazione.';

    public const PIEDE_SALA_PRIMA = 'Prima del giorno: le deleghe ricevute e il foglio della sala. «Apri la sala» si accende il giorno della prima convocazione.';

    public const PIEDE_SALA_IN_CORSO = 'La sala è aperta: ogni gesto si segna nella pagina della sala, anche senza rete.';

    public const PIEDE_VERBALE = 'Il verbale si scrive dopo la sala.';

    public const PIEDE_VERBALE_DOPO = 'Il passo è fatto quando il verbale è registrato e ogni destinatario ha il verbale spedito.';

    public const PIEDE_VERBALE_FATTO = 'Il verbale è registrato e trasmesso: i trenta giorni di ognuno sono nella pagina del verbale.';

    /** La riga del passo «Delibere eseguite» quando il verbale è annullato dopo la conferma. */
    public const RIGA_VERBALE_ANNULLATO = 'il verbale è annullato';

    public const PIEDE_PREPARAZIONE = 'L\'assemblea resta in preparazione: niente parte ai condòmini.';

    /** La riga dei passi chiusi in «Prepara l'assemblea». */
    public const DOPO_LA_PREPARAZIONE = 'dopo «Prepara l\'assemblea»';

    /** Il perché dei passi chiusi in «Prepara l'assemblea»: si aprono quando il primo passo è fatto, non solo dopo il salvataggio. */
    public const PERCHE_DOPO_LA_PREPARAZIONE = 'si apre dopo «Prepara l\'assemblea», quando il primo passo è fatto: le date valgono e c\'è almeno un punto';

    public function __construct(
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly PecDelloStudio $pec,
        private readonly FascicoloDellAssemblea $fascicolo,
    ) {}

    /**
     * @param (\Closure(): array<string, mixed>)|null $seguito la lettura del seguito delle delibere della stessa richiesta, se la pagina la fa già
     * @return list<array<string, mixed>>
     */
    public function per(Assemblea $a, ?\Closure $seguito = null): array
    {
        if ($a->stato === StatoAssemblea::REGISTRATA_A_MANO) {
            return [];
        }
        $a->loadMissing(['sedute', 'tabella', 'palazzina', 'scala']);
        $date = $this->date($a);
        // Il riepilogo del fascicolo si legge una volta: lo usano la riga di «Documenti» e la voce del preventivo da fermare della convocazione.
        $riepilogo = $this->fascicolo->riepilogo($a);
        $passi = [
            'date' => $date,
            'documenti' => $this->documenti($a, $riepilogo, $date['fatto']),
            'convocazione' => $this->convocazione($a, $riepilogo['da_fermare']),
            'sala' => $this->sala($a),
            'verbale' => $this->verbale($a),
            'delibere' => $this->delibere($a, $seguito),
        ];

        $adesso = null;
        $elenco = [];
        $numero = 0;
        foreach ($passi as $chiave => $p) {
            $stato = match (true) {
                $p['chiuso'] !== null => 'chiuso',
                // La sala si apre dalla prima spedizione anche con la convocazione da finire: tutti e due i passi sono «di adesso».
                $adesso !== null && ! ($p['aperto'] ?? false) => 'dopo',
                $p['fatto'] => 'fatto',
                default => 'adesso',
            };
            if ($stato === 'adesso') {
                $adesso ??= $chiave;
            }
            // Un passo che viene dopo dice il perché: si apre quando è fatto il passo di adesso.
            $perche = $p['chiuso'] ?? ($stato === 'dopo' ? 'si apre quando «' . self::PASSI[$adesso] . '» è fatto' : null);
            // «Che cosa manca» per il passo di adesso, per il passo 1 fatto in preparazione (le voci che informano restano), per la sala fatta (le presenze e i
            // voti) e per il verbale fatto (la pagina da incollare nel libro). Si calcola solo quando si mostra: quella della convocazione legge l'elenco e i
            // controlli di prima di spedire.
            $mostra = $stato === 'adesso' || ($chiave === 'date' && $stato === 'fatto' && $a->eInPreparazione())
                || (in_array($chiave, ['sala', 'verbale'], true) && $stato === 'fatto');
            $manca = $mostra ? (is_callable($p['manca']) ? ($p['manca'])() : $p['manca']) : [];
            $elenco[] = self::passo($chiave, ++$numero, $stato, $p['chiuso'] !== null ? ($p['riga_se_chiuso'] ?? $p['chiuso']) : $p['riga'], $perche, $manca,
                $stato === 'adesso' ? $p['scopo'] : null, $p['piede'], array_intersect_key($p, ['badge' => true, 'apribile' => true]));
        }

        return $elenco;
    }

    /** La strada di «Prepara l'assemblea»: il primo passo aperto, gli altri chiusi finché l'assemblea non c'è. @return list<array<string, mixed>> */
    public static function dellaPreparazione(): array
    {
        $elenco = [];
        $numero = 0;
        foreach (array_keys(self::PASSI) as $chiave) {
            $primo = $chiave === 'date';
            $elenco[] = self::passo($chiave, ++$numero, $primo ? 'adesso' : 'chiuso', $primo ? null : self::DOPO_LA_PREPARAZIONE,
                $primo ? null : self::PERCHE_DOPO_LA_PREPARAZIONE, [], null, $primo ? self::PIEDE_PREPARAZIONE : '');
        }

        return $elenco;
    }

    /**
     * `$piu`: il badge del passo aperto quando non è il conto di «che cosa manca» («1 delibera da eseguire»), e `apribile` per un passo chiuso che si apre lo
     * stesso (le delibere con il verbale annullato).
     *
     * @param list<array<string, mixed>> $manca @param array<string, mixed> $piu @return array<string, mixed>
     */
    private static function passo(string $chiave, int $numero, string $stato, ?string $riga, ?string $perche, array $manca, ?string $scopo, string $piede,
        array $piu = []): array
    {
        return ['chiave' => $chiave, 'numero' => $numero, 'nome' => self::PASSI[$chiave], 'stato' => $stato, 'riga' => $riga, 'perche' => $perche,
            'manca' => $manca, 'scopo' => $scopo, 'piede' => $piede] + $piu;
    }

    /**
     * Una voce di «che cosa manca»: la chiave, fatta o no, se `informa` (non conta fra le cose da fare e non blocca), il collegamento (una pagina, un punto
     * della pagina come `#convocati`, o un altro passo). La strada lo mostra come un pulsante in fondo alla card, con il verbo intero. Il collegamento può
     * anche avere `stampa` (un PDF in una scheda nuova, {@see self::stampa()}), `apre` (una finestra della pagina, {@see self::apre()}) o `spento` (il
     * pulsante c'è ma non si preme, con il perché, {@see self::spento()}); la voce può avere `rifare` (una cosa fatta che va rifatta: la card in arancio).
     *
     * @return array{chiave: string, fatto: bool, informa: bool, titolo: string, dettaglio: string, collegamento: ?array{testo: string, url: ?string, passo: ?string}}
     */
    /**
     * A che punto è la trasmissione e il prossimo gesto, con il nome del pulsante della pagina (21/11/2026: «Trasmetti il verbale» non era un gesto della
     * pagina, e dopo la stampa la card diceva ancora «2 da spedire»): «2 da stampare», «1 PEC da mandare», «2 stampate, da spedire».
     *
     * @param list<array<string, mixed>> $persone
     * @return array{dettaglio: string, gesto: string}
     */
    private static function aCheePuntoLaTrasmissione(array $persone): array
    {
        $daStampare = $pec = $stampate = 0;
        foreach ($persone as $p) {
            if (! ($p['riceve'] ?? false) || ($p['invio']['spedito'] ?? false)) {
                continue;
            }
            if (($p['invio']['stato'] ?? null) === \App\Models\Gestionale\RegistroInvio::STATO_STAMPATA) {
                $stampate++;
            } elseif (($p['mezzo']['mezzo'] ?? null) === 'pec') {
                $pec++;
            } else {
                $daStampare++;
            }
        }
        $parti = array_values(array_filter([
            $daStampare > 0 ? $daStampare . ' da stampare' : null,
            $pec > 0 ? ($pec === 1 ? '1 PEC da mandare' : $pec . ' PEC da mandare') : null,
            $stampate > 0 ? ($stampate === 1 ? '1 stampata, da spedire' : $stampate . ' stampate, da spedire') : null,
        ]));
        $gesto = $daStampare > 0 ? 'Stampa le lettere' : ($pec > 0 ? 'Manda le PEC' : 'Registra la spedizione');

        return ['dettaglio' => $parti === [] ? 'da spedire' : implode(' · ', $parti), 'gesto' => $gesto];
    }

    private static function voce(string $chiave, bool $fatto, string $titolo, string $dettaglio, ?string $testo = null, ?string $url = null, ?string $passo = null,
        bool $informa = false): array
    {
        return ['chiave' => $chiave, 'fatto' => $fatto, 'informa' => $informa, 'titolo' => $titolo, 'dettaglio' => $dettaglio,
            'collegamento' => $testo === null ? null : ['testo' => $testo, 'url' => $url, 'passo' => $passo]];
    }

    /** Il controllo delle date dell'assemblea, con la frase. @return array{valide: bool, frase: string} */
    private static function controlloDelleDate(Assemblea $a): array
    {
        $sedute = $a->sedute->map(fn ($s) => ['convocazione' => $s->convocazione?->value, 'numero' => (int) $s->numero, 'fissata_il' => $s->fissata_il])->all();

        return $sedute === [] ? ['valide' => false, 'frase' => ControlloDelleDate::FRASE_MANCA_LA_PRIMA] : ControlloDelleDate::controlla($sedute);
    }

    /**
     * Il passo 1 «Date e ordine del giorno» di un'assemblea in preparazione è fatto: le date valgono e c'è almeno un punto. È la regola del passo, e quella
     * con cui «Prepara l'assemblea» sceglie dove aprire la pagina (decisione 661): con il passo fatto si apre «Documenti».
     */
    public static function primoPassoFatto(Assemblea $a): bool
    {
        $a->loadMissing('sedute');

        return self::controlloDelleDate($a)['valide'] && $a->punti()->count() > 0;
    }

    /** @return array<string, mixed> */
    private function date(Assemblea $a): array
    {
        $controllo = self::controlloDelleDate($a);
        $giorni = $a->sedute->filter(fn ($s) => $s->fissata_il !== null)->sortBy(fn ($s) => $s->fissata_il->format('Y-m-d H:i'))
            ->map(fn ($s) => $s->fissata_il->format('d/m'))->unique()->values()->all();
        $quanti = $a->punti()->count();
        $punti = $quanti === 0 ? 'nessun punto' : ($quanti === 1 ? '1 punto' : $quanti . ' punti');
        [$copre, $frase, $scelta] = $this->tabella($a);
        $date = $giorni === [] ? 'senza le date' : ConvocatiDellAssemblea::elenco($giorni);
        // Il luogo (o la piattaforma) lo chiede l'avviso: la voce informa, e la spedizione non parte finché manca.
        $manca = EmissioneDelleConvocazioni::cheCosaMancaAllAvviso($a);
        $piattaforma = $manca !== null && str_contains($manca, 'piattaforma');
        $voceDate = match (true) {
            ! $controllo['valide'] => self::voce('date', false, 'Le date e il luogo', $controllo['frase'], 'Scrivile', '#date-dell-assemblea'),
            $manca !== null => self::voce('date', true, 'Le date e il luogo', $date . ' · manca ' . ($piattaforma ? 'la piattaforma' : 'il luogo')
                . ': l\'avviso lo deve dire prima di spedire', $piattaforma ? 'Scrivila' : 'Scrivilo', '#date-dell-assemblea', null, true),
            default => self::voce('date', true, 'Le date e il luogo', $date),
        };

        return [
            'fatto' => ! $a->eInPreparazione() || ($controllo['valide'] && $quanti > 0),
            'riga' => $date . ' · ' . $punti,
            'chiuso' => null,
            'manca' => [
                $voceDate,
                $quanti > 0 ? self::voce('ordine', true, 'L\'ordine del giorno', $punti)
                    : self::voce('ordine', false, 'L\'ordine del giorno', 'nessun punto: aggiungilo qui sotto', 'Aggiungi', '#ordine-del-giorno'),
                // La tabella che non copre tutte le unità avvisa e non blocca: il controllo dice che cosa manca.
                $copre ? self::voce('tabella', true, 'La tabella del valore', $frase)
                    : self::voce('tabella', false, 'La tabella del valore', $frase, $scelta ? 'Controllala' : 'Sceglila', '#tabella-del-valore', null, true),
            ],
            'scopo' => 'per andare avanti',
            'piede' => self::PIEDE_DATE,
        ];
    }

    /** La tabella del valore copre tutte le unità del perimetro, ognuna con la sua quota; la frase; se una tabella è scelta. @return array{0: bool, 1: string, 2: bool} */
    private function tabella(Assemblea $a): array
    {
        $perimetro = match ($a->ambito) {
            AmbitoAssemblea::SCALA => $a->scala,
            AmbitoAssemblea::PALAZZINA => $a->palazzina,
            default => $a->condominio,
        };
        if ($a->tabella === null || $perimetro === null) {
            return [false, 'da scegliere qui sotto', false];
        }
        $c = TabellaDiProprieta::controllo($a->tabella, $perimetro);
        $copre = $c['unita'] > 0 && $c['con_quota'] === $c['unita'];
        $frase = '«' . $a->tabella->nome . '» · ' . $c['con_quota'] . ' unità su ' . $c['unita'];

        return [$copre, $copre ? $frase : $frase . ($c['avvisi'] !== [] ? ' · ' . $c['avvisi'][0] : ''), true];
    }

    /**
     * «Documenti» è fatto quando l'amministratore li ha guardati e ha premuto «Avanti: Convocazione» (`documenti_visti_il`), non da solo con le date.
     * Un'assemblea già uscita dalla preparazione senza quel giorno (le assemblee di prima) l'ha fatto.
     *
     * @param array{documenti: int, da_fermare: list<string>} $r il riepilogo del fascicolo @return array<string, mixed>
     */
    private function documenti(Assemblea $a, array $r, bool $dateFatte): array
    {
        $n = $r['documenti'];

        return [
            'fatto' => $dateFatte && ($a->documenti_visti_il !== null || ! $a->eInPreparazione()),
            'riga' => 'l\'avviso con la delega · ' . ($n === 0 ? 'nessun allegato' : ($n === 1 ? '1 allegato' : $n . ' allegati'))
                . ($r['da_fermare'] === [] ? '' : ' · ' . ConvocatiDellAssemblea::elenco($r['da_fermare']) . ' da fermare'),
            'chiuso' => null,
            'manca' => [],
            'scopo' => null,
            'piede' => self::PIEDE_DOCUMENTI,
        ];
    }

    /** @param list<string> $daFermare il rendiconto e il preventivo da fermare @return array<string, mixed> */
    private function convocazione(Assemblea $a, array $daFermare): array
    {
        $annullata = $a->stato === StatoAssemblea::ANNULLATA;
        $dopo = in_array($a->stato, [StatoAssemblea::IN_CORSO, StatoAssemblea::DESERTA, StatoAssemblea::CHIUSA], true);
        $ultimo = $this->termini->ultimoGiorno($a);
        if ($ultimo === null) {
            return ['fatto' => $annullata, 'riga' => 'dopo le date', 'chiuso' => null, 'manca' => [], 'scopo' => 'prima di spedire',
                'piede' => $annullata ? 'L\'assemblea è annullata: gli invii e le prove restano.' : self::PIEDE_CONVOCAZIONE];
        }
        $voci = $this->elenco->per($a);
        // Spedito: la regola sola del passo, del compito dell'Action inbox e dello stato dell'assemblea (la lettera stampata non è spedita).
        $oggi = array_values(array_filter($voci, fn ($v) => $v['oggi']));
        $daSpedire = array_values(array_filter($oggi, fn ($v) => ! ElencoDeiConvocati::spedito($v)));
        $uscite = array_filter($voci, fn ($v) => $v['invio'] !== null);
        $raggiunte = count(array_filter($voci, fn ($v) => $v['invio']?->ricevuta_il !== null));
        $raccomandate = array_filter($daSpedire, fn ($v) => $v['mezzo']['valido'] && $v['mezzo']['mezzo'] === 'raccomandata');
        // Il giorno scelto, mai uno passato: con la prima convocazione troppo vicina «entro oggi 10/12» (decisione 665).
        $entro = $this->termini->entroPer($a, $raccomandate !== [] ? 'raccomandata' : null);
        $quanti = count($daSpedire);
        $senza = count(array_filter($daSpedire, fn ($v) => ! $v['mezzo']['valido']));
        $ristampa = array_filter($voci, fn ($v) => $v['da_ristampare']) !== [];

        $riga = ($uscite === []
            ? $quanti . ' da spedire · ' . \App\Services\Assemblee\Convocazione\TerminiDellaConvocazione::entroDaOggi($entro ?? $ultimo)
            : $raggiunte . ' su ' . count($voci) . ($raggiunte === 1 ? ' raggiunta' : ' raggiunte') . ($quanti > 0 ? ' · ' . $quanti . ' da spedire' : ''))
            . ($senza > 0 && ! $annullata ? ' · ' . $senza . ' senza un mezzo valido' : '')
            . ($ristampa && ! $annullata ? ' · le lettere da ristampare' : '');

        return [
            'fatto' => $annullata || $dopo || ($oggi !== [] && $daSpedire === []),
            'riga' => $riga,
            'chiuso' => null,
            'manca' => $annullata || $dopo ? [] : fn () => $this->mancaAllaConvocazione($a, $voci, $daFermare),
            'scopo' => 'prima di spedire',
            'piede' => $annullata ? 'L\'assemblea è annullata: gli invii e le prove restano.' : self::PIEDE_CONVOCAZIONE,
        ];
    }

    /**
     * «Che cosa manca» prima di spedire, in tre voci:
     *
     * - **Date, luogo e ordine del giorno**: ciò che l'avviso deve dire (le date, il luogo, l'ordine del giorno, i controlli della destinazione d'uso: quello
     *   che {@see EmissioneDelleConvocazioni::primaDiSpedire()} già sa); poi le lettere stampate prima che cambiassero date o punti, da ristampare; a posto, il
     *   rendiconto o il preventivo da fermare nel passo «Documenti» (informa, non blocca).
     * - **La casella PEC dello studio**.
     * - **I convocati**: chi non ha un mezzo valido (tiene il passo aperto) e le PEC non comunicate dalla persona (da controllare, non bloccano la spedizione).
     *
     * @param list<array<string, mixed>> $voci
     * @param list<string> $daFermare
     * @return list<array<string, mixed>>
     */
    private function mancaAllaConvocazione(Assemblea $a, array $voci, array $daFermare): array
    {
        // Il luogo e la piattaforma si scrivono nel passo delle date: la voce porta lì. Il resto lo dice il controllo di prima di spedire.
        $avviso = EmissioneDelleConvocazioni::cheCosaMancaAllAvviso($a);
        $sullAvviso = $avviso !== null;
        if ($avviso === null) {
            try {
                app(EmissioneDelleConvocazioni::class)->primaDiSpedire($a, $voci);
            } catch (ValidationException $e) {
                $avviso = (string) collect($e->errors())->flatten()->first();
            }
        }
        $titolo = 'Date, luogo e ordine del giorno';
        $ristampa = ElencoDeiConvocati::fraseDellaRistampa($voci);
        $prima = match (true) {
            $avviso !== null => self::voce('avviso', false, $titolo, ucfirst(preg_replace('/^La spedizione non parte: /u', '', $avviso) ?? $avviso),
                $sullAvviso ? (str_contains($avviso, 'piattaforma') ? 'Scrivila' : 'Scrivilo') : null, null, $sullAvviso ? 'date' : null),
            $ristampa !== null => self::voce('avviso', false, $titolo, $ristampa, 'Vedi chi', '#convocati') + ['rifare' => true],
            $daFermare !== [] => self::voce('avviso', true, $titolo, 'a posto · ' . ConvocatiDellAssemblea::elenco($daFermare) . ' da fermare prima di spedire',
                'Apri «Documenti»', null, 'documenti', true),
            default => self::voce('avviso', true, $titolo, 'a posto'),
        };

        $pec = count(array_filter($voci, fn ($v) => ! $v['vale'] && $v['oggi'] && $v['mezzo']['valido'] && $v['mezzo']['mezzo'] === 'pec'));
        $casella = match (true) {
            $this->pec->impostata() => self::voce('casella', true, 'La casella PEC dello studio', $this->pec->avviso() === null ? 'impostata'
                : 'impostata · l\'ultima prova non è andata'),
            $pec === 0 => self::voce('casella', true, 'La casella PEC dello studio', 'non serve: nessuno da convocare per PEC'),
            default => self::voce('casella', false, 'La casella PEC dello studio', 'da impostare: ' . ($pec === 1 ? 'la PEC non parte' : 'le ' . $pec . ' PEC non partono'),
                $this->pec->dove() !== null ? 'Imposta' : null, $this->pec->dove()),
        };

        return [$prima, $casella, self::voceDeiConvocati($voci)];
    }

    /**
     * «I convocati»: chi non ha un mezzo valido («1 senza un mezzo valido: Bianchi Anna (ha solo l'email)») e le PEC non comunicate dalla persona, con «Vedi
     * chi» alla tabella dei convocati.
     *
     * @param list<array<string, mixed>> $voci
     * @return array<string, mixed>
     */
    private static function voceDeiConvocati(array $voci): array
    {
        $senza = array_values(array_filter($voci, fn ($v) => $v['oggi'] && ! ElencoDeiConvocati::spedito($v) && ! $v['mezzo']['valido']));
        $nonComunicate = count(array_filter($voci, fn ($v) => ! $v['vale'] && ($v['mezzo']['pec_non_comunicata'] ?? false)));
        if ($senza === [] && $nonComunicate === 0) {
            return self::voce('convocati', true, 'I convocati', 'ognuno con un mezzo valido');
        }
        $pezzi = [];
        if ($senza !== []) {
            $nomi = array_map(fn ($v) => (string) $v['nome'], $senza);
            $pezzi[] = count($senza) . ' senza un mezzo valido: ' . match (true) {
                count($senza) === 1 => $nomi[0] . ' (' . explode(':', (string) $senza[0]['mezzo']['perche'])[0] . ')',
                count($senza) <= 3 => ConvocatiDellAssemblea::elenco($nomi),
                default => implode(', ', array_slice($nomi, 0, 3)) . ' e altri ' . (count($senza) - 3),
            };
        }
        if ($nonComunicate > 0) {
            $pezzi[] = $nonComunicate . ($nonComunicate === 1 ? ' PEC non comunicata' : ' PEC non comunicate') . ' dalla persona';
        }

        return self::voce('convocati', false, 'I convocati', implode(' · ', $pezzi), 'Vedi chi', '#convocati');
    }

    /**
     * Il passo «In sala»: chiuso in preparazione e per l'assemblea annullata; dalla prima spedizione si apre (`aperto`); fatto quando l'assemblea è chiusa o
     * deserta.
     *
     * @return array<string, mixed>
     */
    private function sala(Assemblea $a): array
    {
        $prima = $a->sedute->first(fn ($s) => $s->convocazione?->value === 'prima' && (int) $s->numero === 1);
        $giorno = $prima?->fissata_il?->format('d/m');
        $chiuso = match ($a->stato) {
            StatoAssemblea::ANNULLATA => 'l\'assemblea è annullata',
            StatoAssemblea::IN_PREPARAZIONE => 'si apre dalla prima spedizione',
            default => null,
        };
        if ($chiuso !== null) {
            return ['fatto' => false, 'riga' => $chiuso, 'chiuso' => $chiuso, 'manca' => [], 'scopo' => null, 'piede' => self::PIEDE_SALA];
        }
        if ($a->stato === StatoAssemblea::CHIUSA || $a->stato === StatoAssemblea::DESERTA) {
            // Tre card, come gli altri passi (decisione 654): la sala com'è finita, le deleghe, il prospetto con i nomi e i millesimi che il verbale richiama.
            $tutte = \App\Models\Assemblee\DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->orderBy('id')->get();
            $valide = $tutte->filter(fn ($d) => $d->stato?->value === 'valida')->count();
            $com = \App\Services\Assemblee\Sala\LetturaDellaSala::comEFinita($a) ?? $this->rigaDopoLaSala($a);
            $prospetto = [
                self::voce('sala', true, 'La sala', $com, 'Apri la sala', route('admin.gestionale.assemblee.sala', [$a->condominio_id, $a->id])),
                self::apre(self::voce('deleghe', true, 'Le deleghe', self::valideDellaCard($valide) . self::piuRevocate($tutte), 'Vedi le deleghe', null, null, true), 'deleghe'),
                self::stampa(self::voce('prospetto', true, 'Le presenze e i voti', 'il prospetto con nomi e millesimi che il verbale richiama', 'Stampa il prospetto',
                    null, null, true), route('admin.gestionale.assemblee.sala.prospetto', [$a->condominio_id, $a->id])),
            ];

            return ['fatto' => true, 'riga' => $this->rigaDopoLaSala($a), 'chiuso' => null, 'manca' => $prospetto, 'scopo' => null, 'piede' => self::PIEDE_SALA_IN_CORSO];
        }
        if ($a->stato === StatoAssemblea::IN_CORSO) {
            return ['fatto' => false, 'aperto' => true, 'riga' => $this->rigaInCorso($a), 'chiuso' => null, 'manca' => [], 'scopo' => null,
                'piede' => self::PIEDE_SALA_IN_CORSO];
        }
        $apri = PerLePagine::apriLaSala($a);
        $tutte = \App\Models\Assemblee\DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->orderBy('id')->get();
        $deleghe = $tutte->filter(fn ($d) => $d->stato?->value === 'valida');
        $rifiutate = $tutte->filter(fn ($d) => $d->stato?->value === 'rifiutata')->count();
        // La riga sotto il passo, corta perché la strada ha cinque colonne: «4 deleghe · 1 rifiutata · dal 14/12».
        $piuRifiutate = $rifiutate === 0 ? '' : ($rifiutate === 1 ? ' · 1 rifiutata' : ' · ' . $rifiutate . ' rifiutate');
        $quante = ($deleghe->count() === 0 ? 'nessuna delega' : ($deleghe->count() === 1 ? '1 delega' : $deleghe->count() . ' deleghe')) . $piuRifiutate;
        // «Vedi le deleghe» apre il cassetto delle deleghe della pagina; senza deleghe il pulsante apre subito «Segna una delega ricevuta».
        $manca = [
            self::apre(self::voce('deleghe', true, 'Deleghe ricevute', $tutte->isEmpty() ? 'nessuna, per ora'
                : self::valideDellaCard($deleghe->count()) . $piuRifiutate . self::piuRevocate($tutte),
                $tutte->isEmpty() ? 'Segna una delega ricevuta' : 'Vedi le deleghe', null, null, true), $tutte->isEmpty() ? 'segna_delega' : 'deleghe'),
            self::stampa(self::voceDelFoglio($a), route('admin.gestionale.assemblee.sala.foglio', [$a->condominio_id, $a->id])),
        ];
        if (! $apri['attiva']) {
            // Prima del giorno il pulsante c'è, spento, con il perché.
            $manca[] = self::spento(self::voce('apri', true, 'Apri la sala', 'si accende il ' . $giorno . ', il giorno della prima convocazione', 'Apri la sala',
                null, null, true), $apri['perche']);
        } else {
            $manca[] = self::voce('apri', false, 'Apri la sala', 'oggi è il giorno della prima convocazione', 'Apri la sala', $apri['url'] ?? null);
            $daSpedire = count(array_filter($this->elenco->per($a), fn ($v) => $v['oggi'] && ! ElencoDeiConvocati::spedito($v)));
            if ($daSpedire > 0) {
                $manca[] = self::voce('convocazione', true, 'La convocazione', $daSpedire . ' da spedire: all\'apertura il presidente vede chi non risulta fra '
                    . 'i convocati (art. 1136 c. 6 c.c.) e decide', 'Vedi chi', '#convocati', null, true);
            }
        }

        $deserta = $a->sedute->first(fn ($s) => $s->convocazione?->value === 'prima' && (int) $s->numero === 1 && $s->esito?->value === 'deserta');
        $seconda = $a->sedute->first(fn ($s) => $s->convocazione?->value === 'seconda' && (int) $s->numero === 1);
        $riga = match (true) {
            $deserta !== null && $seconda !== null => 'prima deserta · la seconda ' . self::giornoDellaSeduta($seconda->fissata_il),
            $apri['attiva'] => $quante . ' · si apre oggi',
            default => $quante . ' · dal ' . $giorno,
        };

        return ['fatto' => false, 'aperto' => true, 'riga' => $riga, 'chiuso' => null,
            'manca' => $manca, 'scopo' => $apri['attiva'] ? 'per aprire la sala' : 'prima dell\'assemblea', 'piede' => self::PIEDE_SALA_PRIMA];
    }

    /**
     * La voce del foglio della sala: «non ancora stampato» (informa, «Stampa il foglio»), «stampato il 13/12 alle 17:00» (fatta, verde, «Stampa di nuovo»),
     * o, quando una delega è cambiata dopo la stampa, da rifare in arancio con «Ristampa il foglio» (la voce non è più fatta). L'ora della stampa è un
     * istante: si legge a Roma.
     *
     * @return array<string, mixed>
     */
    private static function voceDelFoglio(Assemblea $a): array
    {
        $foglio = \App\Services\Assemblee\Sala\LetturaDellaSala::foglio($a);
        if ($foglio['stampato_il'] === null) {
            return self::voce('foglio', true, 'Il foglio della sala', 'non ancora stampato: da stampare il giorno prima, con le deleghe ricevute; serve se la rete manca',
                'Stampa il foglio', null, null, true);
        }
        $quando = $a->foglio_stampato_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));
        $alle = $quando->format('d/m') . ' alle ' . $quando->format('H:i');

        return $foglio['da_ristampare']
            ? self::voce('foglio', false, 'Il foglio della sala', 'una delega è cambiata dopo la stampa del ' . $alle . ': ristampalo', 'Ristampa il foglio')
                + ['rifare' => true]
            : self::voce('foglio', true, 'Il foglio della sala', 'stampato il ' . $alle . ', con le deleghe ricevute fino ad allora', 'Stampa di nuovo');
    }

    /** Una voce che porta a una stampa: l'indirizzo, e la strada la apre in una scheda nuova (un PDF non è una pagina del programma). @param array<string, mixed> $voce */
    private static function stampa(array $voce, string $url): array
    {
        $voce['collegamento'] = ['testo' => $voce['collegamento']['testo'], 'url' => $url, 'passo' => null, 'stampa' => true];

        return $voce;
    }

    /**
     * Una voce il cui pulsante apre una finestra della pagina dell'assemblea, senza lasciarla: `deleghe` il cassetto delle deleghe ricevute, `segna_delega`
     * la finestra «Segna una delega ricevuta». @param array<string, mixed> $voce
     */
    private static function apre(array $voce, string $cosa): array
    {
        $voce['collegamento']['apre'] = $cosa;

        return $voce;
    }

    /** Una voce con il pulsante spento: c'è, non si preme, e dice perché (sul passaggio del mouse e a chi legge lo schermo). @param array<string, mixed> $voce */
    private static function spento(array $voce, string $perche): array
    {
        $voce['collegamento']['spento'] = $perche;

        return $voce;
    }

    /** «martedì 15/12 alle 18:30», «domenica 22/11 alle 08:00»: il giorno della settimana, il giorno e l'ora di una seduta come l'ha scritta l'amministratore. */
    public static function giornoDellaSeduta(\DateTimeInterface $quando): string
    {
        $giorni = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];

        return $giorni[(int) $quando->format('w')] . ' ' . $quando->format('d/m') . ' alle ' . $quando->format('H:i');
    }

    /** «in corso · 11 su 26 presenti · punto 2 di 6». */
    private function rigaInCorso(Assemblea $a): string
    {
        $aperta = \App\Services\Assemblee\Sala\StatiDellaSala::sedutaAperta($a);
        $punti = $a->punti()->count();
        $senza = \App\Services\Assemblee\Sala\StatiDellaSala::puntiSenzaEsito($a);
        $punto = $senza->isEmpty() ? 'tutti i punti hanno un esito' : 'punto ' . $senza->first()->numero . ' di ' . $punti;
        if ($aperta === null) {
            // Sospesa (art. 66 c. 5 disp. att.): la prossima seduta della stessa convocazione, ancora da aprire, è la data di prosecuzione dell'avviso.
            $sospesa = $a->sedute()->get()->filter(fn ($s) => $s->esito?->value === 'sospesa')->sortByDesc('chiusa_il')->first();
            $prosegue = $sospesa === null ? null : $a->sedute()->get()->first(fn ($s) => $s->convocazione === $sospesa->convocazione
                && (int) $s->numero > (int) $sospesa->numero && $s->aperta_il === null && $s->esito === null);

            return $prosegue !== null ? 'sospesa · prosegue ' . self::giornoDellaSeduta($prosegue->fissata_il) : 'in corso · sospesa · ' . $punto;
        }
        $c = \App\Services\Assemblee\Sala\PresenzeDellaSala::conteggio((array) $aperta->fotografia['costituzione']['teste'],
            \App\Services\Assemblee\Sala\PresenzeDellaSala::presenti($aperta));

        return 'in corso · ' . $c['teste'] . ' su ' . (int) $aperta->fotografia['costituzione']['totale_teste'] . ' presenti · ' . $punto;
    }

    /** «chiusa alle 20:40 · 6 delibere», «deserta in prima e in seconda convocazione». */
    private function rigaDopoLaSala(Assemblea $a): string
    {
        if ($a->stato === StatoAssemblea::DESERTA) {
            return 'deserta in prima e in seconda convocazione';
        }
        $ultima = $a->sedute()->get()->filter(fn ($s) => $s->chiusa_il !== null)->sortByDesc('chiusa_il')->first();
        $delibere = \App\Models\Assemblee\Delibera::query()->where('assemblea_id', $a->id)->get();
        $rinviate = $delibere->filter(fn ($d) => $d->esito === \App\Enums\Assemblee\EsitoDelibera::RINVIATA)->count();
        $altre = $delibere->count() - $rinviate;
        $pezzi = [];
        if ($altre > 0) {
            $pezzi[] = $altre === 1 ? '1 delibera' : $altre . ' delibere';
        }
        if ($rinviate > 0) {
            $pezzi[] = $rinviate === 1 ? '1 punto rinviato' : $rinviate . ' punti rinviati';
        }

        return 'chiusa' . ($ultima !== null ? ' alle ' . $ultima->chiusa_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i') : '')
            . ($pezzi !== [] ? ' · ' . implode(', ', $pezzi) : '');
    }

    /**
     * Il passo «Verbale e trasmissione» (V14 del progetto del verbale; scena 10 della bozza approvata): dopo la sala; durante «la discussione: 3 punti scritti»;
     * poi, per l'ultima riunione, «da scrivere» → «bozza alle 21:02 · versione 3» → «da firmare» → «n. 42 · trasmesso a 9 di 15» → fatto «n. 42 · i trenta
     * giorni finiscono il 20/01/2027». L'assemblea deserta ha il passo fatto: «annotata nel registro: mancata costituzione». Un'assemblea aperta e poi rinviata
     * dalla sala ha il suo verbale: la riunione c'è stata (§0.4 (6)). «Che cosa manca» con al più tre voci.
     *
     * @return array<string, mixed>
     */
    private function verbale(Assemblea $a): array
    {
        $riunioni = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::riunioni($a);
        $chiuso = match (true) {
            $a->stato === StatoAssemblea::ANNULLATA && $riunioni->isEmpty() => 'l\'assemblea è annullata',
            in_array($a->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true) && $riunioni->isEmpty() => 'dopo la sala',
            default => null,
        };
        if ($chiuso !== null) {
            return ['fatto' => false, 'riga' => $chiuso, 'chiuso' => $chiuso, 'manca' => [], 'scopo' => null, 'piede' => self::PIEDE_VERBALE];
        }
        if ($a->stato === StatoAssemblea::DESERTA) {
            $deserte = $a->sedute->filter(fn ($s) => $s->esito?->value === 'deserta');
            $daIncollare = $deserte->filter(fn ($s) => $s->nel_libro_il === null)->count();
            $registro = route('admin.gestionale.assemblee.registro', [$a->condominio_id, 'registro' => \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registroDi($a), 'da_assemblea' => $a->id]);

            return ['fatto' => true, 'riga' => 'annotata nel registro: ' . ($deserte->count() === 1 ? '1 mancata costituzione' : $deserte->count() . ' mancate costituzioni'),
                'chiuso' => null, 'scopo' => null, 'piede' => self::PIEDE_VERBALE_FATTO, 'manca' => [
                    self::voce('libro', $daIncollare === 0, 'Le pagine per il libro dei verbali di carta', $daIncollare === 0 ? 'incollate' : $daIncollare . ' da incollare', 'Stampa le pagine',
                        $registro, null, true),
                    self::voce('verbale', true, 'Il verbale', 'nessuno: la riunione non c\'è stata', null, null, null, true),
                ]];
        }
        $pagina = route('admin.gestionale.assemblee.verbale.pagina', [$a->condominio_id, $a->id]);
        $aperta = \App\Services\Assemblee\Sala\StatiDellaSala::sedutaAperta($a);
        if ($aperta !== null && $a->stato === StatoAssemblea::IN_CORSO && $riunioni->count() === 1) {
            $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::dellaSeduta($aperta);
            $scritti = $verbale === null ? 0 : \App\Models\Assemblee\ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'like', 'punto:%')
                ->get()->filter(fn ($p) => \App\Services\Assemblee\Verbale\StrutturaDelVerbale::caratteri((array) $p->documento) > 0)->count();

            return ['fatto' => false, 'aperto' => true, 'riga' => 'la discussione: ' . ($scritti === 1 ? '1 punto scritto' : $scritti . ' punti scritti'), 'chiuso' => null,
                'scopo' => null, 'piede' => self::PIEDE_VERBALE, 'manca' => [self::voce('verbale', false, 'Il verbale', 'la discussione si scrive sotto il voto di ogni punto, '
                    . 'nella sala o nella pagina del verbale', 'Apri il verbale', $pagina)]];
        }
        // L'ultima riunione chiusa (la sospesa ha il suo verbale; la prosecuzione ancora da aprire è «dopo la sala»).
        $ultima = $riunioni->filter(fn ($s) => $s->esito !== null)->sortBy(fn ($s) => $s->aperta_il)->last();
        if ($ultima === null) {
            return ['fatto' => false, 'aperto' => true, 'riga' => 'dopo la sala', 'chiuso' => null, 'scopo' => null, 'piede' => self::PIEDE_VERBALE, 'manca' => []];
        }
        $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::dellaSeduta($ultima);
        $sospesa = $ultima->esito?->value === 'sospesa' ? ' di ' . \App\Services\Assemblee\Verbale\ParoleDelVerbale::giornoConLaSettimana($ultima->fissata_il->format('Y-m-d')) : '';
        $entro = \Carbon\CarbonImmutable::parse(\App\Services\Assemblee\Verbale\ParoleDelVerbale::giornoDi($ultima->chiusa_il) ?? $ultima->fissata_il->format('Y-m-d'))
            ->addDays(\App\Services\Assemblee\Verbale\CompitiDelVerbale::GIORNI)->format('d/m');
        if ($verbale === null || $verbale->stato === \App\Enums\Assemblee\StatoVerbale::IN_SALA) {
            return ['fatto' => false, 'aperto' => true, 'riga' => 'da scrivere · ' . \App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione::entro($entro), 'chiuso' => null, 'scopo' => 'per registrare il verbale',
                'piede' => self::PIEDE_VERBALE_DOPO, 'manca' => [
                    self::voce('verbale', false, 'Il verbale', 'da generare dal modello, con i dati della sala', 'Genera il verbale', $pagina),
                    self::voce('copia', false, 'La copia firmata', 'dopo la bozza, firmata da presidente e segretario', null, null, null, true),
                    self::voce('trasmissione', false, 'La trasmissione', 'dopo la registrazione', null, null, null, true),
                ]];
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        if ($verbale->stato === \App\Enums\Assemblee\StatoVerbale::BOZZA) {
            $ultimaVersione = (int) \App\Models\Assemblee\VersioneDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->where('in_attesa', false)->max('numero');
            if ($verbale->stampata === null) {
                $cifre = app(\App\Services\Assemblee\Verbale\CifreDellaSala::class)->controlla($verbale)['avvisi'];

                return ['fatto' => false, 'aperto' => true, 'riga' => 'bozza alle ' . $verbale->generato_il?->copy()->setTimezone($fuso)->format('H:i') . ' · versione ' . $ultimaVersione,
                    'chiuso' => null, 'scopo' => 'per registrare il verbale', 'piede' => self::PIEDE_VERBALE_DOPO, 'manca' => array_values(array_filter([
                        self::voce('bozza', false, 'La bozza da firmare', 'da stampare', 'Stampa la bozza da firmare', $pagina),
                        $cifre === [] ? null : self::voce('cifre', false, 'Le cifre', count($cifre) === 1 ? 'una non torna' . ($cifre[0]['punto'] !== null ? ' nel punto '
                            . $cifre[0]['punto'] : '') : count($cifre) . ' non tornano', 'Vedi le cifre', $pagina),
                    ]))];
            }

            return ['fatto' => false, 'aperto' => true, 'riga' => 'da firmare · bozza stampata alle ' . \Carbon\Carbon::parse($verbale->stampata['il'])->setTimezone($fuso)->format('H:i'),
                'chiuso' => null, 'scopo' => 'per registrare il verbale', 'piede' => self::PIEDE_VERBALE_DOPO, 'manca' => [
                    self::voce('copia', false, 'La copia firmata', 'da caricare: il programma non vede la firma', 'Registra il verbale sottoscritto', $pagina)]];
        }
        // «Il verbale» in testa, come le altre card dei passi (decisione 654): registrato, con il numero e il giorno della firma.
        $sottoscritto = $verbale->sottoscritto_il?->format('d/m/Y');
        $registrato = self::voce('verbale', true, 'Il verbale', 'Registrato · n. ' . $verbale->numero . ($sottoscritto !== null ? ', sottoscritto il ' . $sottoscritto : ''),
            'Apri il verbale', $pagina);
        $d = app(\App\Services\Assemblee\Verbale\DestinatariDelVerbale::class)->per($verbale);
        $daSpedire = $d['quanti'] - $d['spediti'];
        // Le tre card restano tre anche con la pagina incollata (decisione 654): la card del libro diventa fatta, con il giorno e il registro.
        $libro = $verbale->nel_libro_il === null ? self::voce('libro', false, 'La pagina per il libro dei verbali di carta', 'da stampare e incollare', 'Stampa la pagina',
            route('admin.gestionale.assemblee.registro', [$a->condominio_id, 'registro' => $verbale->registro, 'da_assemblea' => $a->id]), null, true)
            : self::voce('libro', true, 'La pagina per il libro dei verbali di carta', 'Incollata nel libro il ' . \Carbon\Carbon::parse($verbale->nel_libro_il)->format('d/m/Y'),
                'Il registro dei verbali', route('admin.gestionale.assemblee.registro', [$a->condominio_id, 'registro' => $verbale->registro, 'da_assemblea' => $a->id]));
        $prossima = $ultima->esito?->value === 'sospesa' ? ' · ' . \App\Services\Assemblee\Verbale\ParoleDelVerbale::giornoConLaSettimana(
            $a->sedute->first(fn ($s) => $s->convocazione === $ultima->convocazione && (int) $s->numero > (int) $ultima->numero)?->fissata_il?->format('Y-m-d') ?? '') . ' il prossimo' : '';
        if ($daSpedire > 0) {
            $aChePunto = self::aCheePuntoLaTrasmissione($d['persone']);

            return ['fatto' => false, 'aperto' => true, 'riga' => 'n. ' . $verbale->numero . $sospesa . ' · trasmesso a ' . $d['spediti'] . ' di ' . $d['quanti'] . $prossima,
                'chiuso' => null, 'scopo' => 'per trasmettere il verbale', 'piede' => self::PIEDE_VERBALE_DOPO, 'manca' => array_values(array_filter([
                    $registrato,
                    self::voce('trasmissione', false, 'La trasmissione', $aChePunto['dettaglio'], $aChePunto['gesto'], $pagina . '#trasmissione'),
                    $libro,
                ]))];
        }
        $perTutti = app(\App\Services\Assemblee\Verbale\TerminiDellImpugnazione::class)->perTuttiIlVerbale($verbale);
        $riga = 'n. ' . $verbale->numero . $sospesa . ' · ' . match (true) {
            $perTutti === null => 'trasmesso a tutti: i trenta giorni aspettano le consegne',
            $perTutti < \App\Helpers\DateHelper::oggiUtente() => 'trenta giorni passati per tutti',
            default => 'i trenta giorni finiscono il ' . \Carbon\Carbon::parse($perTutti)->format('d/m/Y'),
        };

        // A trasmissione finita la card «da spedire» lascia il posto a quella fatta: a chi è andato e l'ultimo giorno, con «Vedi chi» alla sezione della pagina.
        $ultimo = collect($d['persone'])->filter(fn ($p) => $p['riceve'] && ($p['invio']['spedito'] ?? false))->map(fn ($p) => $p['invio']['emesso_il'] ?? null)->filter()
            ->map(fn ($i) => \App\Services\Assemblee\Verbale\ParoleDelVerbale::giornoDi($i))->filter()->max();
        $trasmessa = self::voce('trasmissione', true, 'La trasmissione', 'Trasmesso a ' . $d['spediti'] . ' di ' . $d['quanti']
            . ($ultimo !== null ? ' · l\'ultimo il ' . \Carbon\Carbon::parse($ultimo)->format('d/m/Y') : ''), 'Vedi chi', $pagina . '#trasmissione');

        return ['fatto' => true, 'riga' => $riga, 'chiuso' => null, 'scopo' => null, 'piede' => self::PIEDE_VERBALE_FATTO, 'manca' => array_values(array_filter([$registrato, $trasmessa, $libro]))];
    }

    /**
     * Il passo «Delibere eseguite»: la lettura del seguito ({@see \App\Services\Assemblee\Seguito\SeguitoDelleDelibere::perLaPagina()}), una volta: quella
     * della pagina, se la richiesta chiede anche il seguito (`$seguito`). Chiuso fino al verbale registrato; aperto anche mentre la trasmissione finisce. In
     * testa, a riepilogo confermato, «Da eseguire» (la prima cosa aperta, con la data e la persona, e la sua azione) e «Trenta giorni».
     *
     * @param (\Closure(): array<string, mixed>)|null $seguito
     * @return array<string, mixed>
     */
    private function delibere(Assemblea $a, ?\Closure $seguito = null): array
    {
        $pagina = $seguito !== null ? $seguito() : \App\Services\Assemblee\Seguito\SeguitoDelleDelibere::perLaPagina($a, null);
        $passo = $pagina['passo'];
        if ($passo['stato'] === 'chiuso') {
            $annullato = $passo['perche'] === \App\Services\Assemblee\Seguito\SeguitoDelleDelibere::VERBALE_ANNULLATO;

            return ['fatto' => false, 'riga' => $passo['riga'], 'riga_se_chiuso' => $annullato ? self::RIGA_VERBALE_ANNULLATO : $passo['riga'],
                'chiuso' => $passo['perche'] ?? $passo['riga'], 'manca' => [], 'scopo' => null, 'piede' => $passo['piede'], 'apribile' => $annullato];
        }
        // Le tre card («Da fare adesso», «Le delibere», «Trenta giorni») sono del passo, che le legge dal seguito (decisione 645): niente voci di «che cosa manca»
        // e niente badge giallo in alto a destra.
        return ['fatto' => $passo['stato'] === 'fatto', 'aperto' => true, 'riga' => $passo['riga'], 'chiuso' => null, 'manca' => [], 'scopo' => null,
            'piede' => $passo['piede'], 'badge' => null];
    }

    /** La card delle deleghe: «Nessuna valida», «1 valida», «3 valide». */
    private static function valideDellaCard(int $valide): string
    {
        return $valide === 0 ? 'Nessuna valida' : ($valide === 1 ? '1 valida' : $valide . ' valide');
    }

    /** Le revocate nella card: « · 1 revocata», « · 2 revocate», o niente. @param \Illuminate\Support\Collection<int, \App\Models\Assemblee\DelegaDellAssemblea> $tutte */
    private static function piuRevocate(\Illuminate\Support\Collection $tutte): string
    {
        $revocate = $tutte->filter(fn ($d) => $d->stato?->value === 'revocata')->count();

        return $revocate === 0 ? '' : ($revocate === 1 ? ' · 1 revocata' : ' · ' . $revocate . ' revocate');
    }
}
