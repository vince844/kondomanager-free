<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Riparto\CompetenzaDelPiano;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * I fatti che il programma propone per la nota sintetica esplicativa del rendiconto: frasi vere e controllabili, con le cifre delle
 * schede da cui vengono. Il programma propone; la nota è dell'amministratore, che tiene, toglie o riscrive ogni fatto ({@see NotaSinteticaDelRendiconto}).
 *
 * UNA FONTE PER CIFRA. Ogni cifra viene dalla lettura che la scheda di provenienza fa già: il prospetto e i riquadri della Sintesi, il riepilogo per voce,
 * la situazione patrimoniale (i fondi, le fatture aperte, l'Erario), i controlli, la lettura del conguaglio, i crediti per anzianità. Chi chiama passa le
 * letture che ha (la Sintesi, i controlli, il conguaglio): qui non si rifanno. Le poche cose che nessuna scheda conta (i passaggi, i piani approvati, le
 * lettere, gli accordi, gli incassi imputati in un altro modo, le spese da ratificare) si leggono qui, con le regole dei servizi che le conoscono.
 *
 * LE FRASI. Sono per i condòmini: in terza persona, senza pulsanti e senza promesse, senza i nomi delle persone (il nome di un'unità sì), con le parole
 * di tutti («quanto l'assemblea ha approvato con le rate», non «deliberato»), con «in più» e «in meno» al posto del segno. Il simbolo dell'euro è legato
 * alla cifra da uno spazio che non va a capo. Il programma non giudica e non dichiara niente al posto dell'amministratore: niente «regolare», «dovuto»,
 * «rilevante», «moroso», mai «nessuna lite». Ogni fatto si propone solo quando i dati lo rendono vero; dove non può dirlo, tace, e la pagina dice perché.
 */
final class FattiDellaNota
{
    /** Le cinque parti della nota, sempre in quest'ordine, con il loro titolo. */
    public const PARTI = [
        'criteri' => '1. I criteri del rendiconto',
        'gestione' => '2. La gestione dell\'anno',
        'rapporti' => '3. I rapporti in corso',
        'questioni' => '4. Le questioni pendenti',
        'altre' => '5. Altre informazioni',
    ];

    /** Lo spazio che lega il simbolo dell'euro alla cifra: non va a capo, né a video né nella stampa. */
    public const SPAZIO = "\u{00A0}";

    public const K1 = 'Il registro di contabilità è per cassa: il denaro entrato e uscito, con la sua data. Il riepilogo finanziario, la situazione patrimoniale '
        .'e il conguaglio sono per competenza: le spese e le quote dell\'esercizio, pagate o no.';

    public const K4 = 'Un versamento che non dice a che cosa va è imputato alla rata con la scadenza più vecchia, di qualunque gestione: il criterio dell\'art. 1193 c.c. '
        .'Chi versa può indicare a che cosa va.';

    /** K4 quando l'esercizio ha interessi o spese addebitati a singole persone: quelli già scaduti vengono prima del capitale (art. 1194 c.c.). */
    public const K4_ACCESSORI = 'Un versamento che non dice a che cosa va è imputato prima agli interessi e alle spese addebitati già scaduti (art. 1194 c.c.), '
        .'poi alla rata con la scadenza più vecchia, di qualunque gestione. Chi versa può indicare a che cosa va.';

    public const K5 = 'Una spesa con i giorni di competenza scritti sulla fattura è divisa fra chi era titolare in quei giorni.';

    public const N3 = 'Nella contabilità del condominio non ci sono fondi.';

    public const N4 = 'Ogni condòmino, e chi ha un diritto reale o di godimento su un\'unità, può vedere in ogni momento le fatture e gli altri documenti delle '
        .'spese, e averne copia a sue spese (art. 1130-bis c.c.).';

    /** N5, quando il fascicolo ha la parte con le posizioni di ciascuno; senza, la frase non dice dove sono. */
    public const N5_NEL_FASCICOLO = 'Le posizioni di ciascuno sono nella parte «Posizioni dei condòmini» del fascicolo: sono per i condòmini e non si affiggono.';

    public const N5 = 'Le posizioni di ciascuno sono per i condòmini e non si affiggono.';

    /** La frase della stampa che non vale senza fondi con un vincolo dichiarato. */
    private const FRASE_VINCOLI = ' I vincoli dei fondi sono invece di oggi.';

    /** Le unità che una frase nomina una per una: oltre, la frase dice quante sono (un condominio grande ne ha centinaia, e la nota resta sintetica). */
    public const ELENCO_MASSIMO = 10;

    /** Le voci con lo scostamento più grande che G2 nomina: le altre sono nel riepilogo finanziario. */
    public const VOCI_G2 = 5;

    /** I tipi di lettera che Q2 conta, con il nome al singolare e al plurale: il promemoria non riguarda le rate già scadute. */
    private const LETTERE = ['sollecito' => ['lettera di sollecito', 'lettere di sollecito'], 'diffida' => ['diffida', 'diffide'],
        'messa_in_mora' => ['messa in mora', 'messe in mora']];

    public function __construct(
        private readonly PosizioniDeiCondomini $posizioni,
        private readonly AnnoACavallo $annoACavallo,
        private readonly SeiMesi $seiMesi,
        private readonly RegistroLettere $lettere,
        private readonly CompetenzaDelPiano $competenza,
        private readonly SaldiDelleBanche $banche,
    ) {}

    /**
     * I fatti proposti, nell'ordine delle parti e del catalogo. Ogni fatto: la chiave, la parte, la frase (per G2 l'intestazione, con le righe a parte),
     * le righe, la fonte (dove l'amministratore la ritrova), la scheda che la mostra, e se chiede un voto all'assemblea (la riga del «Da decidere»).
     * Accanto, i fatti che oggi non si propongono con il perché, per la pagina.
     *
     * @param  array{controlli: array<string,mixed>, sintesi: array<string,mixed>, letto: ?array<string,mixed>}  $letture
     * @return array{fatti: list<array<string,mixed>>, non_proposti: list<array{chiave:string, perche:string}>, fornitori: list<array{categoria:string, fornitore:string}>}
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio, array $letture, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $c = $letture['controlli'];
        $sintesi = $letture['sintesi'];
        $letto = $letture['letto'] ?? null;
        $r = $c['riepilogo'];
        $p = $c['pagina'];
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $alla = (string) $r['alla'];
        $finito = (bool) $r['finito'];

        $fatti = [];
        $non = [];
        $aggiungi = function (?array $fatto, string $chiave, ?string $perche = null) use (&$fatti, &$non) {
            if ($fatto !== null) {
                $fatti[] = $fatto + ['chiave' => $chiave, 'righe' => [], 'voto' => null, 'spiegazione' => false, 'cause' => false];
            } elseif ($perche !== null) {
                $non[] = ['chiave' => $chiave, 'perche' => $perche];
            }
        };

        // 1. I criteri.
        $aggiungi(self::fatto('criteri', self::K1, 'I nomi delle schede del rendiconto', 'sintesi'), 'K1');
        $vincoli = collect($p['liquidita']['fondi'] ?? [])->contains(fn ($f) => (int) ($f['vincolo_registrato'] ?? 0) > 0);
        $stato = (string) $sintesi['stato']['frase_stampa'];
        $aggiungi(self::fatto('criteri', $vincoli ? $stato : str_replace(self::FRASE_VINCOLI, '', $stato), 'La frase di stato del frontespizio', 'sintesi'), 'K2');
        $cg = collect($c['controlli'])->firstWhere('id', 'CG');
        // I centesimi si dicono divisi solo se il controllo torna e una spesa è stata divisa davvero fra le persone.
        $diviso = $letto !== null && (int) ConguaglioDelRendiconto::totaliDelCondominio($letto)['a'] !== 0;
        $aggiungi(($cg['esito'] ?? null) === 'quadra' && $diviso ? self::fatto('criteri', ConguaglioDelRendiconto::FRASE_CENTESIMI, 'Il controllo «'.$cg['nome'].'»', 'controlli') : null,
            'K3', match (true) {
                $cg === null || $cg['esito'] === 'non_calcolato' => 'Il conguaglio non è calcolato.',
                $cg['esito'] !== 'quadra' => 'Il controllo «'.$cg['nome'].'» non torna: i centesimi non si possono dire divisi.',
                default => 'Nell\'esercizio nessuna spesa è divisa fra le persone.',
            });
        $aggiungi(self::k4($esercizio), 'K4', 'Nell\'esercizio non è registrato nessun versamento delle rate.');
        $conCompetenza = DB::table('fatture_passive')->where('esercizio_id', $esercizio->id)->whereNotNull('competenza_dal')->exists();
        $aggiungi($conCompetenza ? self::fatto('criteri', self::K5, 'Le fatture dell\'esercizio', 'riepilogo') : null, 'K5', 'Nessuna fattura dell\'esercizio ha i giorni di competenza scritti.');

        // 2. La gestione dell'anno.
        $aggiungi(self::g1($sintesi['prospetto']), 'G1');
        [$g2, $perche] = self::g2($r);
        $aggiungi($g2, 'G2', $perche);
        [$g3, $perche] = self::g3($r);
        $aggiungi($g3, 'G3', $perche);
        $aggiungi(self::g4($r), 'G4', 'Nell\'esercizio non sono entrati soldi da altri che non sono quote.');
        $fondi = $p['movimento_fondi']['righe'] ?? [];
        if (($p['liquidita']['fondi'] ?? []) === []) {
            $aggiungi(self::fatto('gestione', self::N3, 'La situazione patrimoniale', 'situazione'), 'N3');
        } else {
            $aggiungi(self::g5($fondi), 'G5', 'I fondi non hanno movimenti da dire.');
        }
        $aggiungi(self::g6($sintesi), 'G6');
        // Accanto alla liquidità, il saldo dell'estratto conto di ogni conto della banca, se l'amministratore l'ha scritto, contro la contabilità.
        // Una lettura sola: quella dei controlli, che la fanno con la stessa nota; chi passa un'altra banca la fa rifare.
        $banche = ! isset($letture['banca']) && isset($letture['controlli']['banche']) ? $letture['controlli']['banche']
            : $this->banche->confronto($condominio, $letture['banca'] ?? NotaSinteticaDelRendiconto::contenuto($esercizio)['banca'], $p);
        foreach ($banche as $b) {
            $aggiungi($b['estratto'] === null ? null : self::fatto('gestione', SaldiDelleBanche::frase($b), 'La situazione patrimoniale, il conto «'.$b['cassa'].'»', 'situazione')
                + ['banca' => (string) $b['cassa_id'], 'differenza' => $b['differenza']], 'G6B:'.$b['cassa_id'],
                'Il saldo che dice la banca per «'.$b['cassa'].'» non è scritto: scrivi qui sotto quello che leggi sull\'estratto conto che ti manda la banca, e il '
                .'programma lo confronta con il saldo registrato in KondoManager.');
        }
        $aggiungi($letto === null ? null : self::g7($letto, $sintesi), 'G7', 'Il conguaglio non è calcolato.');
        $aggiungi(self::g8($sintesi['fasce']), 'G8', 'Non ci sono spese fuori dai capitoli.');
        foreach ($c['esito']['righe'] as $riga) {
            // Il controllo della banca non ha un fatto suo: lo dicono i conti qui sopra (G6B), con le due cifre e la spiegazione accanto. Un campo solo per
            // la stessa spiegazione, non due.
            if ($riga['id'] !== 'BC' && in_array($riga['esito'], ['non_quadra', 'segnalazione'], true)) {
                $aggiungi(self::fatto('gestione', 'Il controllo «'.$riga['nome'].'» '.($riga['esito'] === 'non_quadra' ? 'non torna' : 'è da leggere').'.',
                    'Il controllo «'.$riga['nome'].'»', 'controlli') + ['spiegazione' => true, 'controllo' => $riga['id']], 'G9:'.$riga['id']);
            }
        }
        $aggiungi($this->g10($condominio, $esercizio, $c['anno_a_cavallo']), 'G10', 'Il risultato non comprende registrazioni scritte qui con la data fuori dall\'esercizio.');
        $aggiungi(self::g11($esercizio, $dal, (string) $esercizio->data_fine->format('Y-m-d')), 'G11', 'Nell\'esercizio non è registrato nessun passaggio di proprietà.');
        $piani = $this->pianiDellEsercizio($condominio, $esercizio);
        $aggiungi(self::g12($piani), 'G12', 'Nessun piano delle rate dell\'esercizio ha la data della delibera dell\'assemblea.');

        // 3. I rapporti in corso.
        $aggiungi($letto === null ? null : self::r1($letto), 'R1', $letto === null ? 'Il conguaglio non è calcolato.' : 'Nessuna gestione straordinaria è in corso.');
        $aggiungi(self::r2($piani), 'R2', 'Le rate dei piani dell\'esercizio sono tutte emesse.');
        $aggiungi(self::r3((int) $condominio->id, $p['debiti'], $finito, $alla, $oggi), 'R3', 'Non ci sono fatture da pagare.');
        $aggiungi(self::r4($p['debiti']), 'R4', 'Non ci sono ritenute da versare all\'Erario.');
        $aggiungi(self::r5($condominio, $alla, $finito), 'R5', 'Non ci sono accordi di rientro in corso.');
        $aggiungi(self::r6($p['liquidita']['fondi'] ?? []), 'R6', 'Il condominio non ha un fondo per il trattamento di fine rapporto.');

        // 4. Le questioni pendenti.
        $crediti = $this->posizioni->crediti($condominio, $esercizio, 'scaduto', $oggi);
        $aggiungi(self::q1($crediti, $sintesi['prospetto'], $finito, $oggi), 'Q1', 'Alla data non ci sono rate scadute e non versate.');
        $aggiungi($this->q2($condominio, $dal, $alla, $finito, $oggi), 'Q2', 'Non risultano lettere di sollecito, diffide o messe in mora partite o spedite.');
        $aggiungi($this->q3($condominio, $crediti, $dal, $alla), 'Q3', 'Non ci sono rate scadute nell\'esercizio e non versate.');
        $aggiungi(self::q4($sintesi['fasce']), 'Q4', 'Non ci sono fatture contestate.');
        $aggiungi(self::q5($esercizio), 'Q5', 'Nessuna spesa dell\'esercizio ha superato il preventivo con la ratifica da chiedere.');
        $aggiungi($letto === null ? null : self::q6($letto), 'Q6', $letto === null ? 'Il conguaglio non è calcolato.' : 'Non ci sono somme versate in passato per un\'unità e non usate.');
        $aggiungi($letto === null ? null : self::q8($letto), 'Q8', $letto === null ? 'Il conguaglio non è calcolato.' : 'Ogni cambio di titolare dell\'esercizio ha il suo passaggio registrato.');

        // 5. Altre informazioni.
        $aggiungi(self::fatto('altre', self::N4, 'Il codice civile', 'sintesi'), 'N4');
        $aggiungi(self::fatto('altre', self::N5, 'La parte «Condòmini» del fascicolo', 'condomini'), 'N5');

        return ['fatti' => $fatti, 'non_proposti' => $non, 'fornitori' => self::fornitori($esercizio), 'banche' => $banche];
    }

    /** La cifra in euro, con il simbolo legato: «€ 1.234,56». Le frasi dicono il verso a parole, quindi la cifra è senza segno. */
    public static function euro(int $centesimi): string
    {
        return '€'.self::SPAZIO.number_format(abs($centesimi) / 100, 2, ',', '.');
    }

    private static function data(string $iso): string
    {
        return CarbonImmutable::parse(substr($iso, 0, 10))->format('d/m/Y');
    }

    /** «1 fattura», «3 fatture». */
    private static function quanti(int $n, string $uno, string $tanti): string
    {
        return $n.' '.($n === 1 ? $uno : $tanti);
    }

    /** «A», «A e B», «A, B e C». */
    private static function elenco(array $pezzi): string
    {
        $pezzi = array_values($pezzi);

        return count($pezzi) <= 1 ? (string) ($pezzi[0] ?? '') : implode(', ', array_slice($pezzi, 0, -1)).' e '.end($pezzi);
    }

    /** Un fatto. Le frasi prese da un'altra scheda («€ 71,34») hanno lo spazio normale: qui il simbolo si lega alla cifra. */
    public static function fatto(string $parte, string $frase, string $fonte, string $scheda): array
    {
        return ['parte' => $parte, 'frase' => str_replace('€ ', '€'.self::SPAZIO, $frase), 'fonte' => $fonte, 'scheda' => $scheda];
    }

    /** K4: l'ordine dei versamenti, con quanti versamenti dell'esercizio sono stati imputati in un altro modo. */
    public static function k4(Esercizio $esercizio): ?array
    {
        $incassi = DB::table('scritture_contabili')->where('esercizio_id', $esercizio->id)->where('tipo_movimento', 'incasso_rata')->whereNull('deleted_at')
            ->where('stato', '!=', 'annullata')->pluck('imputazione');
        if ($incassi->isEmpty()) {
            return null;
        }
        // Imputati in un altro modo: come ha indicato chi ha versato, per scelta dichiarata dell'amministratore, o fuori ordine senza una ragione scritta.
        $modi = array_filter([
            'come ha indicato chi ha versato' => $incassi->filter(fn ($i) => $i === OrdineImputazione::INDICATA_DAL_VERSANTE)->count(),
            'per scelta dell\'amministratore' => $incassi->filter(fn ($i) => $i === OrdineImputazione::SCELTA_AMMINISTRATORE)->count(),
            'senza una ragione scritta' => $incassi->filter(fn ($i) => $i === OrdineImputazione::FUORI_ORDINE)->count(),
        ]);
        $altri = (int) array_sum($modi);
        $conAccessori = \App\Models\Gestionale\RataQuote::where('tipo', \App\Models\Gestionale\RataQuote::TIPO_ACCESSORIO)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', \App\Models\Gestionale\PianoRate::degliAddebiti()->where('condominio_id', $esercizio->condominio_id)
                ->where('esercizio_id', $esercizio->id)->select('id'))->select('id'))
            ->exists();
        $frase = $conAccessori ? self::K4_ACCESSORI : self::K4;
        if ($altri > 0) {
            $come = count($modi) === 1 ? ', '.array_key_first($modi).'.'
                : ': '.self::elenco(array_map(fn ($modo, $n) => $n.' '.$modo, array_keys($modi), array_values($modi))).'.';
            $frase .= ' Nell\'esercizio '.($altri === 1 ? '1 versamento è stato imputato' : $altri.' versamenti sono stati imputati').' in un altro modo'.$come;
        }

        return self::fatto('criteri', $frase, 'Gli incassi delle rate dell\'esercizio', 'cassa');
    }

    /**
     * G1: il risultato, con i suoi termini. La rettifica di esercizi precedenti non è una quota chiesta con le rate ma sta nel risultato: quando
     * c'è, la frase la nomina, e il conto delle cifre torna.
     */
    public static function g1(array $pr): array
    {
        $risultato = (int) $pr['risultato'];
        $rettifica = (int) ($pr['rettifica_esercizi_precedenti'] ?? 0);
        // La parola del risultato (disavanzo, avanzo, in pareggio) è quella del riepilogo e del frontespizio: una regola sola.
        $parola = RiepilogoPerCompetenza::comeRisultato($risultato);
        $spese = 'Le spese dell\'esercizio sono '.self::euro((int) $pr['costi']);
        $quote = 'le quote chieste ai condòmini con le rate sono '.self::euro((int) $pr['quote_giornale']);
        if ($rettifica === 0) {
            $verso = match (true) {
                $risultato < 0 => 'le spese superano le quote di '.self::euro($risultato),
                $risultato > 0 => 'le quote superano le spese di '.self::euro($risultato),
                default => 'spese e quote si equivalgono',
            };
            $frase = $spese.' e '.$quote.': '.$verso.' ('.$parola.').';
        } else {
            // Con un terzo termine il confronto fra spese e quote non basta: si dice il risultato.
            $frase = $spese.', '.$quote.' e la rettifica di esercizi precedenti è di '.self::euro($rettifica).($rettifica > 0 ? ' in più' : ' in meno')
                .': il risultato è '.($risultato === 0 ? $parola : 'un '.$parola.' di '.self::euro($risultato)).'.';
        }

        return self::fatto('gestione', $frase, 'Il prospetto della gestione, nella Sintesi', 'sintesi')
            + ['cifre' => ['costi' => (int) $pr['costi'], 'quote' => (int) $pr['quote_giornale'], 'rettifica' => $rettifica, 'risultato' => $risultato]];
    }

    /** Le voci di spesa delle gestioni ordinarie, una per riga del riepilogo: le voci dei capitoli, o il capitolo senza voci. */
    public static function vociDiSpesa(array $riepilogo): array
    {
        $voci = [];
        foreach ($riepilogo['blocchi'] as $b) {
            if ($b['gestione']['tipo'] === 'straordinaria') {
                continue;
            }
            foreach ($b['spese'] as $capitolo) {
                foreach (isset($capitolo['voci']) ? $capitolo['voci'] : [$capitolo] as $v) {
                    if (! ($v['proprio'] ?? false)) {
                        $voci[] = $v;
                    }
                }
            }
        }

        return $voci;
    }

    /**
     * G2: le voci con lo scostamento più grande, in valore, da quanto l'assemblea ha approvato con le rate; se nessuna voce ha il deliberato, dal preventivo.
     * Una riga per voce, con il posto per la causa accanto.
     *
     * @return array{0: ?array, 1: ?string}
     */
    public static function g2(array $riepilogo): array
    {
        $voci = self::vociDiSpesa($riepilogo);
        $colonna = collect($voci)->contains(fn ($v) => ($v['scostamento'] ?? null) !== null) ? 'scostamento'
            : (collect($voci)->contains(fn ($v) => ($v['scostamento_preventivo'] ?? null) !== null) ? 'scostamento_preventivo' : null);
        if ($colonna === null) {
            return [null, 'Né il deliberato con le rate né il preventivo delle voci sono disponibili: lo dice il riepilogo finanziario.'];
        }
        $base = $colonna === 'scostamento' ? 'deliberato' : 'preventivo';
        $confronto = array_values(array_filter($voci, fn ($v) => ($v[$colonna] ?? null) !== null));
        $diverse = array_values(array_filter($confronto, fn ($v) => (int) $v[$colonna] !== 0));
        $da = $base === 'deliberato' ? 'da quanto l\'assemblea ha approvato con le rate' : 'da quanto era previsto nel preventivo';
        if ($diverse === []) {
            return [self::fatto('gestione', 'Le spese di ogni voce sono uguali a quanto '.($base === 'deliberato' ? 'l\'assemblea ha approvato con le rate' : 'era previsto nel preventivo').'.',
                'Il riepilogo finanziario', 'riepilogo'), null];
        }
        usort($diverse, fn ($a, $b) => [abs((int) $b[$colonna]), $a['nome']] <=> [abs((int) $a[$colonna]), $b['nome']]);
        $mostrate = array_slice($diverse, 0, self::VOCI_G2);
        $altre = count(array_filter($voci, fn ($v) => (int) ($v['consuntivo'] ?? 0) !== 0 || (int) ($v[$base] ?? 0) !== 0)) > count($mostrate);
        $righe = array_map(fn ($v) => ['voce_id' => (int) $v['voce_id'], 'testo' => $v['nome'].': '.self::euro((int) $v['consuntivo']).' contro '
            .self::euro((int) $v[$base]).' ('.self::euro((int) $v[$colonna]).((int) $v[$colonna] > 0 ? ' in più' : ' in meno').').',
            'scostamento' => (int) $v[$colonna]], $mostrate);

        // «Di più» quando le voci scostate sono più di quelle nominate; «le altre voci» quando il riepilogo ne ha altre, scostate o no.
        return [self::fatto('gestione', 'Le voci che si sono scostate'.(count($diverse) > count($mostrate) ? ' di più ' : ' ').$da.':', 'Il riepilogo finanziario, colonna «Scostamento»', 'riepilogo')
            + ['righe' => $righe, 'coda' => $altre ? 'Le altre voci sono nel riepilogo finanziario.' : null, 'cause' => true, 'base' => $base], null];
    }

    /** G3: le spese contro l'esercizio prima, sulle voci che l'esercizio prima aveva. */
    public static function g3(array $riepilogo): array
    {
        if ($riepilogo['anno_prima'] === null) {
            return [null, 'È il primo esercizio nel programma.'];
        }
        $voci = array_values(array_filter(self::vociDiSpesa($riepilogo), fn ($v) => (int) ($v['anno_prima'] ?? 0) !== 0));
        if ($voci === []) {
            return [null, 'L\'esercizio prima non ha spese sulle voci di quest\'anno (una gestione nuova ha voci nuove).'];
        }
        $prima = (int) array_sum(array_column($voci, 'anno_prima'));
        $ora = (int) array_sum(array_column($voci, 'consuntivo'));
        $tutte = count($voci) === count(array_filter(self::vociDiSpesa($riepilogo), fn ($v) => (int) ($v['consuntivo'] ?? 0) !== 0 || (int) ($v['anno_prima'] ?? 0) !== 0));
        // Le cifre sono quelle delle voci del riepilogo finanziario, non i costi dell'esercizio di G1: la frase dice quali, e delle sole gestioni ordinarie
        // quando l'esercizio ne ha una straordinaria (che non ha l'anno prima).
        $soloOrdinarie = collect($riepilogo['blocchi'])->contains(fn ($b) => $b['gestione']['tipo'] === 'straordinaria') ? ' delle gestioni ordinarie' : '';
        $verso = $ora === $prima ? 'uguali a quelle' : self::euro($ora - $prima).($ora > $prima ? ' in più' : ' in meno').' rispetto a quelle';

        return [self::fatto('gestione', ($tutte ? 'Le spese delle voci del riepilogo finanziario'.$soloOrdinarie.' sono '
            : 'Sulle voci che c\'erano anche nell\'esercizio prima, le spese del riepilogo finanziario'.$soloOrdinarie.' sono ')
            .self::euro($ora).', '.$verso.' dell\'esercizio prima ('.self::euro($prima).').', 'Il riepilogo finanziario, colonna dell\'anno prima', 'riepilogo')
            + ['cifre' => ['ora' => $ora, 'prima' => $prima]], null];
    }

    /** G4: le entrate che non sono quote, voce per voce. */
    public static function g4(array $riepilogo): ?array
    {
        $entrate = [];
        foreach ($riepilogo['blocchi'] as $b) {
            foreach ($b['entrate'] as $capitolo) {
                foreach (isset($capitolo['voci']) ? $capitolo['voci'] : [$capitolo] as $v) {
                    if (! ($v['proprio'] ?? false) && (int) ($v['incassato'] ?? 0) > 0) {
                        $entrate[] = $v;
                    }
                }
            }
        }
        if ($entrate === []) {
            return null;
        }
        $totale = (int) array_sum(array_column($entrate, 'incassato'));
        $quali = count($entrate) === 1 ? $entrate[0]['nome'] : implode(', ', array_map(fn ($v) => $v['nome'].' '.self::euro((int) $v['incassato']), $entrate));

        return self::fatto('gestione', 'Nell\'anno sono entrati '.self::euro($totale).' che non sono quote dei condòmini ('.$quali.'): riducono le spese da dividere.',
            'Il riepilogo finanziario, le entrate', 'riepilogo') + ['cifre' => ['entrate' => $totale]];
    }

    /** G5: ogni fondo con il suo movimento dell'esercizio. */
    public static function g5(array $righe): ?array
    {
        if ($righe === []) {
            return null;
        }
        $frasi = array_map(fn ($f) => $f['cassa'].': all\'inizio '.self::euro((int) $f['iniziale']).', accantonati '.self::euro((int) $f['accantonato'])
            .', usati '.self::euro((int) $f['utilizzato']).', alla fine '.self::euro((int) $f['finale']).'.', $righe);

        return self::fatto('gestione', implode(' ', $frasi), 'La situazione patrimoniale, il movimento dei fondi', 'situazione');
    }

    /** G6: la liquidità secondo la contabilità, con la parte nei fondi. */
    public static function g6(array $sintesi): array
    {
        $liquidita = (int) $sintesi['riquadri']['liquidita']['liquidita'];
        $inFondi = (int) $sintesi['prospetto']['in_fondi'];

        $cifra = 'La liquidità secondo la contabilità è '.($liquidita < 0 ? 'sotto zero di ' : '').self::euro($liquidita)
            .($inFondi !== 0 ? ', di cui '.self::euro($inFondi).' nei fondi' : '');

        // Con il confronto di un conto della banca nella nota, la seconda frase non serve: la stampa usa quella breve ({@see NotaSinteticaDelRendiconto}).
        return self::fatto('gestione', $cifra.'. Non è il saldo dell\'estratto conto della banca: il confronto lo fa l\'amministratore.', 'La Sintesi, il riquadro della liquidità', 'sintesi')
            + ['frase_breve' => str_replace('€ ', '€'.self::SPAZIO, $cifra).'.', 'cifre' => ['liquidita' => $liquidita, 'in_fondi' => $inFondi]];
    }

    /**
     * G7: la proposta di conguaglio, dalle cifre del condominio della scheda «Conguaglio», e quante persone hanno da versare e quante sono a credito.
     */
    public static function g7(array $letto, array $sintesi): array
    {
        $t = ConguaglioDelRendiconto::totaliDelCondominio($letto);
        $persone = [];
        foreach ($letto['persone'] ?? [] as $g => $perPersona) {
            if (isset($letto['gestioni'][$g]['in_corso'])) {
                continue;
            }
            foreach ($perPersona as $aid => $perUnita) {
                foreach ($perUnita as $riga) {
                    if (($riga['conguaglio'] ?? null) !== null) {
                        $persone[$aid] = ($persone[$aid] ?? 0) + (int) $riga['conguaglio'];
                    }
                }
            }
        }
        foreach ($letto['da_altre_gestioni']['persone'] ?? [] as $aid => $perUnita) {
            foreach ($perUnita as $riga) {
                $persone[$aid] = ($persone[$aid] ?? 0) + (int) $riga['conguaglio'];
            }
        }
        $daVersare = array_filter($persone, fn ($v) => $v > 0);
        $aCredito = array_filter($persone, fn ($v) => $v < 0);
        $totale = (int) $t['conguaglio'];
        $personali = (int) $t['personali'];
        $comuni = $totale - $personali;
        $pezzi = [];
        if ($comuni !== 0) {
            $pezzi[] = self::euro($comuni).($comuni > 0 ? ' di spese comuni' : ' di quote chieste oltre le spese comuni');
        }
        if ($personali !== 0) {
            $pezzi[] = self::euro($personali).($personali > 0 ? ' di spese personali non ancora chieste' : ' di spese personali da restituire');
        }
        $frase = 'La proposta di conguaglio, da approvare dall\'assemblea, è di '.self::euro($totale).($totale < 0 ? ' a credito' : '').' in tutto'
            .($pezzi === [] ? '' : ': '.implode(' e ', $pezzi)).'.';
        $nDa = count($daVersare);
        $nCr = count($aCredito);
        $frase .= ' '.($nDa === 0 ? 'Nessuna persona ha un conguaglio da versare' : self::quanti($nDa, 'persona ha', 'persone hanno').' un conguaglio da versare'
            .($nCr === 0 ? '' : ', per '.self::euro((int) array_sum($daVersare))))
            .($nCr === 0 ? ', nessuna a credito.' : ($nDa === 0 ? '; ' : ', e ').self::quanti($nCr, 'persona è', 'persone sono').' a credito, per '.self::euro((int) array_sum($aCredito)).'.');
        foreach ($letto['gestioni'] ?? [] as $g) {
            if (isset($g['in_corso'])) {
                $frase .= ' Per i lavori «'.$g['nome'].'», in corso, il conguaglio si propone quando l\'opera è finita.';
            }
        }
        $nonDisponibili = ConguaglioDelRendiconto::fraseNonDisponibili($t, ConguaglioDelRendiconto::nonSiCalcolano($letto));
        if ($nonDisponibili !== null) {
            $frase .= ' '.$nonDisponibili;
        }

        return self::fatto('gestione', $frase, 'La scheda «Conguaglio», la riga del condominio', 'conguaglio')
            // Da votare solo se qualcuno ha un conguaglio: una proposta di € 0,00 per nessuno non è una decisione.
            + ['voto' => $nDa + $nCr === 0 ? null : 'La proposta di conguaglio: '.self::euro($totale).($totale < 0 ? ' a credito' : '').' in tutto.',
                'cifre' => ['conguaglio' => $totale, 'personali' => $personali, 'da_versare' => $nDa, 'a_credito' => $nCr]];
    }

    /** G8: le spese che non sono divise fra i condòmini, dalle fasce della Sintesi. */
    public static function g8(array $fasce): ?array
    {
        $nomi = ['commissioni' => 'di commissioni senza voce', 'storni' => 'di storni senza voce di registrazioni di un esercizio chiuso',
            'contestate' => 'di fatture contestate'];
        $pezzi = [];
        foreach ($fasce as $f) {
            if (isset($nomi[$f['tipo']]) && (int) $f['importo'] !== 0) {
                $pezzi[] = self::euro((int) $f['importo']).' '.$nomi[$f['tipo']];
            }
        }
        if ($pezzi === []) {
            return null;
        }

        return self::fatto('gestione', ucfirst(self::elenco($pezzi)).' non sono divisi fra i condòmini: sono nel risultato, non nel conguaglio.',
            'La Sintesi, le spese fuori dai capitoli', 'sintesi');
    }

    /** G10: le registrazioni scritte qui con la data fuori dall'esercizio che cambiano il risultato (le rosse del verso A). */
    private function g10(Condominio $condominio, Esercizio $esercizio, array $anno): ?array
    {
        $rosse = (int) ($anno['versi']['A']['rosse'] ?? 0);
        if ($rosse === 0) {
            return null;
        }
        $righe = $this->annoACavallo->perEsercizio($condominio, $esercizio, ['verso' => 'A', 'colore' => 'rosso'], 1, $rosse)['righe'];
        $importo = (int) array_sum(array_map(fn ($r) => abs((int) $r['importo']), $righe));

        return self::fatto('gestione', 'Il risultato comprende '.self::quanti($rosse, 'registrazione scritta', 'registrazioni scritte')
            .' in questo esercizio con la data fuori dal suo periodo, per '.self::euro($importo).' in tutto.', 'Il controllo delle registrazioni oltre il confine', 'controlli');
    }

    /** G11: i passaggi di proprietà con la decorrenza nell'esercizio. */
    public static function g11(Esercizio $esercizio, string $dal, string $fine): ?array
    {
        $passaggi = Subentro::where('condominio_id', $esercizio->condominio_id)->whereIn('tipologia', ['proprietario', 'nuda_proprietario'])
            ->whereDate('decorrenza', '>=', $dal)->whereDate('decorrenza', '<=', $fine)->orderBy('decorrenza')->orderBy('id')->get(['immobile_id', 'decorrenza']);
        if ($passaggi->isEmpty()) {
            return null;
        }
        if ($passaggi->count() > self::ELENCO_MASSIMO) {
            return self::fatto('gestione', 'Nell\'esercizio sono stati registrati '.$passaggi->count().' passaggi di proprietà.', 'I passaggi delle unità', 'conguaglio');
        }
        $unita = Immobile::whereIn('id', $passaggi->pluck('immobile_id')->unique()->all())->get()->keyBy('id');
        $quali = $passaggi->map(fn ($p) => 'unità '.($unita[$p->immobile_id]->etichetta ?? '').', dal '.self::data((string) $p->decorrenza))->all();

        return self::fatto('gestione', $passaggi->count() === 1
            ? 'Nell\'esercizio è stato registrato un passaggio di proprietà: '.$quali[0].'.'
            : 'Nell\'esercizio sono stati registrati '.$passaggi->count().' passaggi di proprietà: '.implode('; ', $quali).'.', 'I passaggi delle unità', 'conguaglio');
    }

    /** I piani delle rate dell'esercizio, con la regola della competenza del piano (quella del riepilogo). */
    private function pianiDellEsercizio(Condominio $condominio, Esercizio $esercizio)
    {
        return PianoRate::where('condominio_id', $condominio->id)->orderBy('id')->get()
            ->filter(fn (PianoRate $p) => (int) ($this->competenza->esercizioDelPiano($p)?->id ?? 0) === (int) $esercizio->id)->values();
    }

    /** G12: i piani dell'esercizio approvati dall'assemblea, con la data e il verbale. */
    public static function g12($piani): ?array
    {
        $approvati = $piani->filter(fn (PianoRate $p) => $p->data_delibera_assemblea !== null)->values();
        if ($approvati->isEmpty()) {
            return null;
        }
        $verbale = fn (PianoRate $p) => $p->numero_verbale ? ' (verbale n. '.$p->numero_verbale.')' : '';
        $data = fn (PianoRate $p) => self::data((string) $p->data_delibera_assemblea);
        $frase = $approvati->count() === 1
            ? 'Il piano delle rate «'.$approvati[0]->nome.'» è stato approvato dall\'assemblea del '.$data($approvati[0]).$verbale($approvati[0]).'.'
            : 'I piani delle rate sono stati approvati dall\'assemblea: '.$approvati->map(fn ($p) => '«'.$p->nome.'» il '.$data($p).$verbale($p))->implode('; ').'.';

        return self::fatto('gestione', $frase, 'I piani delle rate', 'riepilogo');
    }

    /** R1: le opere straordinarie in corso, con lo speso finora e quanto prevedono le rate. */
    public static function r1(array $letto): ?array
    {
        $frasi = [];
        foreach ($letto['gestioni'] ?? [] as $g) {
            $i = $g['in_corso'] ?? null;
            if ($i === null) {
                continue;
            }
            $frasi[] = 'Lavori «'.$g['nome'].'» (gestione straordinaria): in corso'.($i['data_fine'] === null ? ' (la gestione non ha la data di fine)' : '')
                .'; spesi finora '.self::euro((int) $i['spesi']).', previsti con le rate '.self::euro((int) $i['chiesti']).'.';
        }

        return $frasi === [] ? null : self::fatto('rapporti', implode(' ', $frasi), 'La scheda «Conguaglio», l\'opera in corso', 'conguaglio');
    }

    /** R2: i piani dell'esercizio con rate non ancora emesse. */
    public static function r2($piani): ?array
    {
        $frasi = [];
        foreach ($piani as $p) {
            if ($p->stato instanceof \BackedEnum ? $p->stato->value !== 'approvato' : (string) $p->stato !== 'approvato') {
                continue;
            }
            $rate = DB::table('rate')->where('piano_rate_id', $p->id)->where('numero_rata', '>', 0);
            $tutte = (clone $rate)->count();
            $emesse = (clone $rate)->whereExists(\App\Models\Gestionale\Rata::aGiornale())->count();
            if ($tutte > 0 && $emesse < $tutte) {
                $frasi[] = 'Del piano «'.$p->nome.'» '.($emesse === 1 ? 'è stata emessa 1 rata' : 'sono state emesse '.$emesse.' rate').' su '.$tutte.'.';
            }
        }

        return $frasi === [] ? null : self::fatto('rapporti', implode(' ', $frasi), 'I piani delle rate', 'riepilogo');
    }

    /** R3, con N2: le fatture da pagare alla data, le contestate, quelle con la scadenza passata, e quelle pagate dopo la fine. */
    public static function r3(int $condominioId, array $debiti, bool $finito, string $alla, string $oggi): ?array
    {
        $aperte = array_values(array_filter($debiti['fatture'], fn ($f) => ! $f['nota_credito'] && (int) $f['residuo'] > 0));
        if ($aperte === []) {
            return null;
        }
        $totale = (int) array_sum(array_column($aperte, 'residuo'));
        $contestate = count(array_filter($aperte, fn ($f) => $f['contestata']));
        $scadute = (int) array_sum(array_map(fn ($f) => $f['scadenza'] !== null && substr((string) $f['scadenza'], 0, 10) < $alla ? (int) $f['residuo'] : 0, $aperte));
        $n = count($aperte);
        $frase = ($finito ? 'Alla fine dell\'esercizio ' : 'A oggi ').($n === 1 ? ($finito ? 'restava' : 'resta').' da pagare 1 fattura' : ($finito ? 'restavano' : 'restano').' da pagare '.$n.' fatture')
            .' per '.self::euro($totale).($n > 1 && $scadute === $totale ? ', tutte con la scadenza passata' : '');
        $dettagli = [];
        if ($contestate > 0) {
            $dettagli[] = $n === 1 ? 'è contestata' : ($contestate === 1 ? '1 è contestata' : $contestate.' sono contestate');
        }
        if ($scadute > 0 && ($n === 1 || $scadute !== $totale)) {
            $dettagli[] = $n === 1 ? 'la scadenza è passata' : self::euro($scadute).' hanno la scadenza passata';
        }
        $frase .= ($dettagli === [] ? '' : ($n === 1 ? ': ' : '; di queste ').implode(' e ', $dettagli)).'.';
        if ($finito) {
            $pagate = self::pagateDopo($condominioId, array_column($aperte, 'residuo', 'id'), $oggi);
            $frase .= ' Dopo la fine dell\'esercizio, fino al '.self::data($oggi).', '.($pagate['numero'] === 0 ? 'non ne è stata pagata nessuna'
                : ($pagate['numero'] === 1 ? 'ne è stata pagata 1' : 'ne sono state pagate '.$pagate['numero']).' per '.self::euro($pagate['importo'])).'.';
        }

        return self::fatto('rapporti', $frase, 'La situazione patrimoniale, le fatture aperte', 'situazione') + ['cifre' => ['numero' => $n, 'totale' => $totale]];
    }

    /**
     * Le fatture aperte alla fine dell'esercizio pagate dopo, fino a oggi, con la lettura del pagato alla data della situazione patrimoniale
     * ({@see PagatoAllaData}): quante sono pagate del tutto, e quanto è stato pagato su tutte.
     *
     * @param  array<int,int>  $restoAllaFine  id della fattura => residuo alla fine dell'esercizio
     */
    public static function pagateDopo(int $condominioId, array $restoAllaFine, string $oggi): array
    {
        $aperteOggi = app(PagatoAllaData::class)->perFatture($condominioId, $oggi, null, true);
        $numero = 0;
        $importo = 0;
        foreach ($restoAllaFine as $id => $restoPrima) {
            $restoOggi = max(0, (int) ($aperteOggi[$id]['residuo'] ?? 0));
            $importo += max(0, $restoPrima - $restoOggi);
            $numero += $restoOggi === 0 ? 1 : 0;
        }

        return ['numero' => $numero, 'importo' => $importo];
    }

    /** R4: le ritenute da versare all'Erario, con la parte su fatture non ancora pagate. */
    public static function r4(array $debiti): ?array
    {
        $erario = (int) $debiti['saldo_erario'];
        if ($erario <= 0) {
            return null;
        }
        $suNonPagate = (int) $debiti['erario_su_fatture_non_pagate'];

        return self::fatto('rapporti', 'Le ritenute da versare all\'Erario sono '.self::euro($erario).($suNonPagate > 0 ? ', di cui '.self::euro($suNonPagate).' su fatture non ancora pagate' : '').'.',
            'La situazione patrimoniale, i debiti verso l\'Erario', 'situazione');
    }

    /** R5: gli accordi di rientro in corso alla data, e quanti l'assemblea ha autorizzato. Mai i nomi, mai «in regola» o «decaduto». */
    public static function r5(Condominio $condominio, string $alla, bool $finito): ?array
    {
        $accordi = AccordoRientro::where('condominio_id', $condominio->id)->whereDate('data_accordo', '<=', $alla)
            ->where(fn ($q) => $q->whereNull('chiuso_il')->orWhereDate('chiuso_il', '>', $alla))->get(['id', 'autorizzato_assemblea']);
        $n = $accordi->count();
        if ($n === 0) {
            return null;
        }
        $autorizzati = $accordi->where('autorizzato_assemblea', true)->count();
        $quando = $finito ? 'Alla fine dell\'esercizio ' : 'A oggi ';
        $frase = $n === 1
            ? $quando.'è in corso un accordo di rientro con un condòmino, '.($autorizzati === 1 ? 'autorizzato' : 'non autorizzato').' dall\'assemblea.'
            : $quando.'sono in corso '.$n.' accordi di rientro con condòmini'.($autorizzati === 0 ? ', nessuno autorizzato dall\'assemblea.' : ', di cui '.$autorizzati.' '
                .($autorizzati === 1 ? 'autorizzato' : 'autorizzati').' dall\'assemblea.');

        return self::fatto('rapporti', $frase, 'Gli accordi di rientro', 'condomini');
    }

    /** R6: il fondo per il trattamento di fine rapporto. */
    public static function r6(array $fondi): ?array
    {
        $tfr = array_values(array_filter($fondi, fn ($f) => ($f['sottotipo'] ?? null) === 'tfr'));
        if ($tfr === []) {
            return null;
        }
        $saldo = (int) array_sum(array_column($tfr, 'saldo'));

        return self::fatto('rapporti', 'Il fondo per il trattamento di fine rapporto è di '.self::euro($saldo).'.', 'La situazione patrimoniale, i fondi', 'situazione');
    }

    /**
     * Q1, con N1: le rate scadute e non versate alla data, per anzianità (le fasce vuote non si dicono), le persone (mai i nomi), la parte delle quote dei
     * piani non versata, e quanto è arrivato dopo la fine dell'esercizio. Gli addebiti a singole persone scaduti e non versati in una frase a parte: non sono
     * rate, e chi ha solo un addebito non è contato fra le persone.
     */
    public static function q1(array $crediti, array $prospetto, bool $finito, string $oggi): ?array
    {
        // Gli addebiti a singole persone (interessi, spese, contributi) non sono rate: si dicono a parte, e chi ha solo un addebito non è fra le persone.
        $tutte = array_values(array_filter($crediti['righe'], fn ($r) => $r['scaduta']));
        $scadute = array_values(array_filter($tutte, fn ($r) => ($r['addebito'] ?? null) === null));
        $addebiti = (int) array_sum(array_map(fn ($r) => ($r['addebito'] ?? null) === null ? 0 : (int) $r['importo'], $tutte));
        $importo = (int) array_sum(array_column($scadute, 'importo'));
        $fraseAddebiti = $addebiti > 0 ? ' Gli addebiti a singole persone (interessi, spese o contributi) scaduti e non versati sono '.self::euro($addebiti).'.' : '';
        if ($importo <= 0) {
            return $addebiti <= 0 ? null : self::fatto('questioni', ($finito ? 'Alla fine dell\'esercizio non c\'erano rate scadute e non versate.' : 'A oggi non ci sono '
                .'rate scadute e non versate.').$fraseAddebiti, 'La scheda «Condòmini», i crediti per anzianità', 'condomini')
                + ['cifre' => ['importo' => 0, 'persone' => 0, 'fasce' => [], 'non_pagate' => 0, 'emesse' => 0, 'addebiti' => $addebiti]];
        }
        $persone = count(array_unique(array_column($scadute, 'anagrafica_id')));
        $fasce = ['1_30' => 'da non più di 30 giorni', '31_90' => 'da 31 a 90 giorni', 'oltre_90' => 'da oltre 90 giorni'];
        $perFascia = [];
        foreach ($scadute as $r) {
            $perFascia[$r['fascia']] = ($perFascia[$r['fascia']] ?? 0) + (int) $r['importo'];
        }
        $piene = array_filter(array_intersect_key($perFascia, $fasce), fn ($v) => $v !== 0);
        $anzianita = count($piene) === 1 ? ', tutte '.$fasce[array_key_first($piene)]
            : ': '.implode(', ', array_map(fn ($k) => self::euro($piene[$k]).' '.$fasce[$k], array_keys(array_intersect_key($fasce, $piene))));
        $nonNota = (int) array_sum(array_map(fn ($r) => $r['origine'] !== null && $r['giorni'] === null ? (int) $r['importo'] : 0, $scadute));
        $frase = ($finito ? 'Alla fine dell\'esercizio ' : 'A oggi ').self::quanti($persone, 'persona aveva', 'persone avevano')
            .' rate scadute e non versate per '.self::euro($importo).$anzianita.($nonNota > 0 ? ' (di cui '.self::euro($nonNota).' di anzianità non nota)' : '').'.';
        if (! $finito) {
            $frase = str_replace(['persona aveva', 'persone avevano'], ['persona ha', 'persone hanno'], $frase);
        }
        // La percentuale sulle sole rate già scadute (la base della frase della morosità accanto): una rata non ancora scaduta non è «non versata».
        $emesse = (int) ($prospetto['quote_scadute'] ?? 0);
        $nonPagate = (int) ($prospetto['quote_scadute_non_pagate'] ?? 0);
        if ($emesse > 0 && $nonPagate > 0) {
            $permille = intdiv(2 * $nonPagate * 1000 + $emesse, 2 * $emesse);
            $frase .= ' Delle quote dei piani dell\'esercizio già scadute, '.self::euro($emesse).', non sono state versate '.self::euro($nonPagate)
                .': il '.number_format($permille / 10, $permille % 10 === 0 ? 0 : 1, ',', '.')."\u{00A0}%.";
        }
        if ($finito) {
            $dopo = (int) array_sum(array_map(fn ($r) => (int) ($r['versato_dopo'] ?? 0), $scadute));
            $frase .= ' Dopo la fine dell\'esercizio, fino al '.self::data($oggi).', '.($dopo === 0 ? 'non ne è stato versato niente' : 'ne sono stati versati '.self::euro($dopo)).'.';
        }
        $frase .= $fraseAddebiti;

        return self::fatto('questioni', $frase, 'La scheda «Condòmini», i crediti per anzianità', 'condomini')
            + ['cifre' => ['importo' => $importo, 'persone' => $persone, 'fasce' => $perFascia, 'non_pagate' => $nonPagate, 'emesse' => $emesse, 'addebiti' => $addebiti]];
    }

    /** Q2, con N6: le lettere partite o spedite nell'esercizio, e dopo la sua fine fino a oggi. Una lettera solo stampata non conta. */
    private function q2(Condominio $condominio, string $dal, string $alla, bool $finito, string $oggi): ?array
    {
        $nel = $this->lettere->contaArrivate((int) $condominio->id, $dal, $alla);
        $dopo = $finito ? $this->lettere->contaArrivate((int) $condominio->id, CarbonImmutable::parse($alla)->addDay()->format('Y-m-d'), $oggi) : [];
        $dire = fn (array $conti) => self::elenco(array_values(array_filter(array_map(fn ($tipo) => ($conti[$tipo] ?? 0) === 0 ? null
            : $conti[$tipo].' '.self::LETTERE[$tipo][$conti[$tipo] === 1 ? 0 : 1], array_keys(self::LETTERE)))));
        $mandate = fn (array $conti) => array_sum(array_intersect_key($conti, self::LETTERE)) === 1 ? 'è stata mandata ' : 'sono state mandate ';
        $frasi = [];
        if (array_sum(array_intersect_key($nel, self::LETTERE)) > 0) {
            $frasi[] = 'Nell\'esercizio '.$mandate($nel).$dire($nel).'.';
        }
        if (array_sum(array_intersect_key($dopo, self::LETTERE)) > 0) {
            $frasi[] = 'Dopo la fine dell\'esercizio, fino al '.self::data($oggi).', '.$mandate($dopo).$dire($dopo).'.';
        }
        if ($frasi === []) {
            return null;
        }

        return self::fatto('questioni', implode(' ', $frasi), 'Il registro delle lettere', 'condomini');
    }

    /**
     * Q3: i sei mesi entro cui l'amministratore deve agire per le rate scadute nell'esercizio e non versate, nelle due letture della norma quando danno
     * date diverse ({@see SeiMesi}); la dispensa solo quando è registrata. Mai «termine scaduto».
     */
    private function q3(Condominio $condominio, array $crediti, string $dal, string $alla): ?array
    {
        $righe = array_values(array_filter($crediti['righe'], fn ($r) => $r['scaduta'] && $r['origine'] === null
            && substr((string) $r['scadenza'], 0, 10) >= $dal && substr((string) $r['scadenza'], 0, 10) <= $alla));
        if ($righe === []) {
            return null;
        }
        $calcolo = $this->seiMesi->perCondominio((int) $condominio->id, $alla);
        $pianoDi = DB::table('rate')->whereIn('id', array_unique(array_column($righe, 'rata_id')))->pluck('piano_rate_id', 'id');
        $date = [];
        $dispense = [];
        $coperte = 0;
        foreach ($righe as $r) {
            $sei = $calcolo->perRiga(['scadenza' => $r['scadenza'], 'pregresso' => null, 'piano_rate_id' => (int) ($pianoDi[$r['rata_id']] ?? 0)], (int) $r['anagrafica_id']);
            $coperta = false;
            foreach (['dalla_scadenza', 'dalla_competenza'] as $lettura) {
                $v = $sei[$lettura];
                if ($v['data'] !== null && ! $v['prima_della_scadenza']) {
                    $date[] = $v['data'];
                }
                if (($v['dispensa'] ?? null) !== null && $v['dispensa']['revocata_il'] === null) {
                    $dispense[$v['dispensa']['id']] = $v['dispensa'];
                    $coperta = true;
                }
            }
            $coperte += $coperta ? 1 : 0;
        }
        $date = array_values(array_unique($date));
        sort($date);
        if ($date === []) {
            return null;
        }
        $frase = 'Per le rate scadute nell\'esercizio e non versate, il termine di sei mesi entro cui l\'amministratore deve agire (art. 1129, comma 9, c.c.) cade il '
            .self::data($date[0]).(count($date) > 1 ? ', o il '.self::data(end($date)).' secondo l\'altra lettura della norma' : '').'.';
        foreach ($dispense as $d) {
            $frase .= ' L\'assemblea del '.self::data((string) $d['data_delibera']).($d['numero_verbale'] ? ' (verbale n. '.$d['numero_verbale'].')' : '')
                .' ha dispensato l\'amministratore'.($coperte === count($righe) ? '' : ' per una parte di queste rate').'.';
        }

        return self::fatto('questioni', $frase, 'La scheda «Condòmini», i sei mesi', 'condomini');
    }

    /** Q4: le fatture contestate, dalla fascia della Sintesi. */
    public static function q4(array $fasce): ?array
    {
        $f = collect($fasce)->firstWhere('tipo', 'contestate');
        if ($f === null || (int) $f['importo'] === 0) {
            return null;
        }
        $n = (int) $f['numero'];

        return self::fatto('questioni', ($n === 1 ? 'Una fattura di '.self::euro((int) $f['importo']).' è contestata' : $n.' fatture, per '.self::euro((int) $f['importo']).', sono contestate')
            .': non '.($n === 1 ? 'è divisa' : 'sono divise').' fra i condòmini, '.($n === 1 ? 'è' : 'sono').' fra i debiti e nel risultato.', 'La Sintesi, le fatture contestate', 'sintesi');
    }

    /** Q5: le spese registrate oltre il preventivo della voce, con la ratifica dell'assemblea da chiedere e la motivazione scritta alla registrazione. */
    public static function q5(Esercizio $esercizio): ?array
    {
        $fatture = \App\Models\Gestionale\FatturaPassiva::where('esercizio_id', $esercizio->id)->where('stato_approvazione', 'sforo_motivato')->orderBy('id')->get();
        if ($fatture->isEmpty()) {
            return null;
        }
        $frasi = [];
        $voti = [];
        foreach ($fatture as $f) {
            $override = (array) ($f->dati_extra['override_budget'] ?? []);
            // La spesa è il totale della fattura; lo sforo (`importo_sforo`) è la sola parte oltre il preventivo. La registrazione non scrive quali voci
            // hanno sforato: la frase nomina le voci della fattura come tali.
            $totale = abs((int) $f->totale_documento);
            $sforo = abs((int) ($override['importo_sforo'] ?? 0));
            $voci = DB::table('righe_fattura')->join('conti', 'conti.id', '=', 'righe_fattura.conto_id')->where('righe_fattura.fattura_passiva_id', $f->id)
                ->distinct()->pluck('conti.nome')->all();
            $della = $voci === [] ? 'il preventivo' : 'il preventivo '.(count($voci) === 1 ? 'della voce ' : 'delle voci della fattura, ').self::elenco($voci);
            $motivo = trim((string) ($override['motivazione'] ?? ''));
            $frasi[] = 'Una spesa di '.self::euro($totale).' ha superato '.($sforo > 0 ? 'di '.self::euro($sforo).' ' : '').$della.'; la ratifica dell\'assemblea è da chiedere.'
                .($motivo === '' ? '' : ' La motivazione scritta alla registrazione: «'.$motivo.'».');
            $voti[] = 'La ratifica della spesa di '.self::euro($totale).($sforo > 0 ? ', '.self::euro($sforo).' oltre ' : ' oltre ').$della.'.';
        }

        return self::fatto('questioni', implode(' ', $frasi), 'Le fatture con la spesa oltre il preventivo', 'riepilogo') + ['voto' => implode(' ', $voti)];
    }

    /** Q6: il già versato di un'unità non usato da nessun piano, dopo un cambio: chi lo riceve lo decide l'amministratore, e la frase non lo dice. */
    public static function q6(array $letto): ?array
    {
        $perUnita = [];
        foreach ($letto['gestioni'] ?? [] as $g) {
            foreach ($g['gia_versato_da_decidere'] ?? [] as $iid => $importo) {
                $perUnita[(int) $iid] = ($perUnita[(int) $iid] ?? 0) + (int) $importo;
            }
        }
        $perUnita = array_filter($perUnita, fn ($v) => $v !== 0);
        if ($perUnita === []) {
            return null;
        }
        if (count($perUnita) > self::ELENCO_MASSIMO) {
            return self::fatto('questioni', 'Restano '.self::euro((int) array_sum($perUnita)).' versati in passato per '.count($perUnita).' unità e non usati da nessun piano: '
                .'non sono nel conguaglio di nessuno.', 'La scheda «Conguaglio», il già versato da decidere', 'conguaglio');
        }
        $unita = Immobile::whereIn('id', array_keys($perUnita))->get()->keyBy('id');
        $frasi = array_map(fn ($iid, $importo) => 'Restano '.self::euro($importo).' versati in passato per l\'unità '.($unita[$iid]->etichetta ?? '').' e non usati da nessun piano: '
            .'non sono nel conguaglio di nessuno.', array_keys($perUnita), array_values($perUnita));

        return self::fatto('questioni', implode(' ', $frasi), 'La scheda «Conguaglio», il già versato da decidere', 'conguaglio');
    }

    /** Q8: le unità con un cambio di titolare nell'esercizio senza un passaggio registrato. Il nome dell'unità sì, quello della persona mai. */
    public static function q8(array $letto): ?array
    {
        $iids = array_keys(array_filter($letto['passaggi_non_registrati'] ?? [], fn ($frasi) => $frasi !== []));
        if ($iids === []) {
            return null;
        }
        if (count($iids) > self::ELENCO_MASSIMO) {
            return self::fatto('questioni', 'Per '.count($iids).' unità la contabilità ha nell\'esercizio un cambio di titolare senza un passaggio registrato.',
                'La scheda «Conguaglio», gli avvisi delle unità', 'conguaglio');
        }
        $nomi = Immobile::whereIn('id', $iids)->get()->map(fn ($u) => $u->etichetta)->sort()->values()->all();

        return self::fatto('questioni', (count($nomi) === 1 ? 'Per l\'unità '.$nomi[0] : 'Per le unità '.self::elenco($nomi))
            .' la contabilità ha nell\'esercizio un cambio di titolare senza un passaggio registrato.', 'La scheda «Conguaglio», gli avvisi delle unità', 'conguaglio');
    }

    /**
     * I fornitori che hanno fatturato nell'esercizio, con la loro categoria: un aiuto per scrivere l'elenco dei contratti, non un fatto (una fattura non dice
     * che il contratto è in corso). Non si stampano.
     *
     * @return list<array{categoria:string, fornitore:string}>
     */
    public static function fornitori(Esercizio $esercizio): array
    {
        return DB::table('fatture_passive as fp')->join('fornitori as f', 'f.id', '=', 'fp.fornitore_id')
            ->leftJoin('categorie_fornitore as cf', 'cf.id', '=', 'f.categoria_id')
            ->where('fp.esercizio_id', $esercizio->id)
            ->distinct()->orderBy('cf.name')->orderBy('f.ragione_sociale')
            ->get(['cf.name as categoria', 'f.ragione_sociale as fornitore'])
            ->map(fn ($r) => ['categoria' => (string) ($r->categoria ?? 'Senza categoria'), 'fornitore' => (string) $r->fornitore])->all();
    }
}
