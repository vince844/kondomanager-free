<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\NotaSintetica;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Gestionale\Lettere\TestiLettere;

/**
 * La nota sintetica esplicativa del rendiconto: cinque parti, sempre nello stesso ordine, ognuna con i fatti che il programma propone
 * ({@see FattiDellaNota}) e il testo dell'amministratore; l'elenco dei contratti, delle polizze e delle verifiche, i dipendenti, gli altri debiti certi
 * (che la situazione patrimoniale mostra sotto la riga), la dichiarazione sulle liti, le impugnazioni e i sinistri, le cose da decidere in assemblea.
 *
 * LA NOTA È DELL'AMMINISTRATORE (art. 1130 n. 10). Nessun fatto è tenuto di serie: un fatto non letto non si stampa. Per ogni fatto tre azioni: tieni (si
 * stampa la frase di oggi, con le cifre di oggi, finché il rendiconto è in bozza), togli, riscrivi (si stampa la sua frase; si conserva quella che il
 * programma proponeva quel giorno, e la pagina dice quando oggi direbbe altro). Un fatto tenuto che oggi il programma non propone più non si stampa, e la
 * pagina lo dice. La spiegazione di un controllo che non torna o è da leggere è il suo fatto: senza la spiegazione non si stampa. Il testo
 * dell'amministratore è testo: niente HTML, gli a capo restano a capo; non si corregge.
 *
 * IL CONTENUTO. La bozza è una riga di `note_sintetiche` per esercizio, con un JSON: per ogni parte il testo; per ogni fatto lo stato; le cause degli
 * scostamenti per voce; le spiegazioni dei controlli; gli elenchi; la dichiarazione. Gli elenchi hanno righe con i loro campi, non testo: quando un modulo
 * porterà i contratti, le polizze o i sinistri come dati, la nota li proporrà come fatti accanto alle righe scritte qui, che restano per ciò che il modulo
 * non sa (ogni riga dice da dove viene: `fonte`).
 *
 * LA STAMPA. Si stampano i fatti tenuti, i testi, gli elenchi, la dichiarazione, le cose da decidere; una parte vuota non si stampa; se tutto è vuoto la
 * nota non c'è, e il frontespizio del fascicolo lo dice ai condòmini. La nota non cambia nessuna cifra.
 */
final class NotaSinteticaDelRendiconto
{
    public const PARTI = FattiDellaNota::PARTI;

    public const TITOLO = 'Nota sintetica esplicativa';

    /** Il frontespizio di un fascicolo senza la nota: un fatto vero, detto a chi vota. */
    public const FRASE_SENZA_NOTA = 'Il fascicolo non contiene la nota sintetica esplicativa.';

    /** Il tetto del testo di ogni parte e di ogni campo: come i testi delle lettere. Il conteggio delle parole informa, non ferma. */
    public const LUNGHEZZA = TestiLettere::LUNGHEZZA_MASSIMA;

    /** Le righe di un elenco. */
    public const RIGHE_ELENCO = 30;

    /** Le parole di una pagina della nota stampata, per «circa N pagine»: la stampa di prova di 650 parole sta in due pagine. */
    public const PAROLE_PER_PAGINA = 350;

    public const TIPI_CONTRATTO = ['contratto' => 'Contratto', 'polizza' => 'Polizza', 'verifica' => 'Verifica periodica'];

    /** Le tre domande della dichiarazione, con la frase di chi non ne ha e il titolo di chi ne ha. */
    public const DICHIARAZIONE = [
        'liti' => ['domanda' => 'Cause in corso, del condominio o contro il condominio', 'nessuna' => 'non ci sono cause in corso, né del condominio né contro il condominio',
            'ci_sono' => 'Ci sono cause in corso'],
        'impugnazioni' => ['domanda' => 'Delibere dell\'assemblea impugnate', 'nessuna' => 'nessuna delibera dell\'assemblea è stata impugnata', 'ci_sono' => 'Ci sono delibere impugnate'],
        'sinistri' => ['domanda' => 'Sinistri aperti con l\'assicurazione', 'nessuna' => 'non ci sono sinistri aperti', 'ci_sono' => 'Ci sono sinistri aperti'],
    ];

    /** Il tetto di una riga scritta dall'amministratore (una cosa da decidere, i dipendenti) e di un testo legato a un fatto (riscritto, causa, spiegazione). */
    public const LUNGHEZZA_RIGA = 500;

    public const LUNGHEZZA_FRASE = 2000;

    /** I controlli del conguaglio per persona: la loro spiegazione nella nota chiede la lettura del conguaglio, come i fatti che la leggono. */
    public const CONTROLLI_DEL_CONGUAGLIO = ['CG', 'CU', 'CB', 'CA'];

    /**
     * Il controllo della banca che non torna, senza la spiegazione: si scrive sotto il saldo della banca, accanto alle cifre, e si stampa con loro. È
     * l'unico posto: il controllo non ha un secondo campo nella nota ({@see FattiDellaNota}, G6B).
     */
    public const FRASE_BANCA_SENZA_SPIEGAZIONE = 'La differenza fra il saldo della banca e la contabilità non ha la spiegazione: scrivila sotto «Saldo secondo la banca», '
        .'nella parte «La gestione dell\'anno» della nota.';

    /** La riga che sostituisce le cinque delle parti quando nessuna è stata toccata. */
    public const FRASE_INTATTA = 'Nessuna parte ha ancora un fatto tenuto o un testo: in cima alla scheda «Nota sintetica», «Tieni tutti i fatti proposti» o «Genera la nota».';

    /** I fatti che leggono il conguaglio: chi stampa la nota senza la sua lettura la fa, se ne è tenuto uno. */
    public const CON_IL_CONGUAGLIO = ['K3', 'G7', 'R1', 'Q6', 'Q8'];

    public function __construct(
        private readonly FattiDellaNota $fatti,
        private readonly ControlliDelRendiconto $controlli,
        private readonly SintesiDelRendiconto $sintesi,
        private readonly ConguaglioDelRendiconto $conguaglio,
    ) {}

    /** Il contenuto della bozza dell'esercizio, completo di tutte le chiavi (vuote se l'amministratore non ha scritto niente). */
    public static function contenuto(Esercizio $esercizio): array
    {
        return self::normalizza((array) (NotaSintetica::where('esercizio_id', $esercizio->id)->first()?->contenuto ?? []));
    }

    /**
     * Le letture che servono ai fatti, fatte una volta: i controlli con il conguaglio (sempre, come la scheda «Controlli»), la Sintesi con quei controlli.
     * Chi le ha già (il fascicolo) le passa.
     *
     * @return array{controlli: array<string,mixed>, sintesi: array<string,mixed>, letto: ?array<string,mixed>}
     */
    public function letture(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null, ?array $letto = null, bool $semprePerIlConguaglio = true): array
    {
        // La scheda legge sempre il conguaglio (i suoi fatti si propongono); la stampa, solo se la nota ne tiene uno.
        if ($letto === null && ($semprePerIlConguaglio || self::chiedeIlConguaglio($esercizio))) {
            $letto = $this->conguaglio->leggi($esercizio);
        }
        $c = $this->controlli->perEsercizio($condominio, $esercizio, $oggi, [], null, null, [], $letto);

        return ['controlli' => $c, 'sintesi' => $this->sintesi->perEsercizio($condominio, $esercizio, $oggi, $c), 'letto' => $letto];
    }

    /**
     * La nota per la scheda: le cinque parti, ognuna con i fatti proposti (con lo stato che l'amministratore ha dato), il suo testo, e accanto i fatti
     * che oggi non si propongono con il perché; gli elenchi, la dichiarazione, le cose da decidere; che cosa manca; le parole; i fornitori suggeriti.
     *
     * @param  array{controlli: array<string,mixed>, sintesi: array<string,mixed>, letto: ?array<string,mixed>}  $letture
     */
    public function perLaScheda(Condominio $condominio, Esercizio $esercizio, array $letture, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $proposti = $this->fatti->perEsercizio($condominio, $esercizio, $letture, $oggi);
        $contenuto = self::contenuto($esercizio);
        $fatti = array_map(fn (array $f) => $f + self::statoDi($f, $contenuto), $proposti['fatti']);
        $chiavi = array_column($fatti, 'chiave');
        // I fatti tenuti o riscritti che oggi il programma non propone più: non si stampano, e la pagina lo dice.
        $spariti = [];
        foreach ($contenuto['fatti'] as $chiave => $s) {
            if (! in_array($chiave, $chiavi, true) && ($s['stato'] ?? null) === 'tenuto') {
                $spariti[] = ['chiave' => $chiave, 'frase' => $s['riscritto'] ?? $s['proposto'] ?? null];
            }
        }
        $stampa = self::stampa($fatti, $contenuto, false);

        return [
            'parti' => array_map(fn ($parte, $titolo) => ['chiave' => $parte, 'titolo' => $titolo, 'testo' => $contenuto['parti'][$parte]['testo'],
                'fatti' => array_values(array_filter($fatti, fn ($f) => $f['parte'] === $parte))], array_keys(self::PARTI), self::PARTI),
            'non_proposti' => $proposti['non_proposti'],
            'spariti' => $spariti,
            'contenuto' => $contenuto,
            'fornitori' => $proposti['fornitori'],
            'mancanze' => [...self::mancanze($contenuto, $letture['sintesi']['esito']['righe'] ?? [], $proposti['banche']), ...self::mancanzeDelleBanche($proposti['banche'])],
            // I conti della banca, con il saldo della contabilità alla data e quello dell'estratto, se scritto.
            'banche' => $proposti['banche'],
            'parole' => self::parole($stampa),
            'vuota' => $stampa['vuota'],
            'oggi' => $oggi,
            // Nessuna parte toccata: la pagina mette «Tieni tutti» e «Genera la nota» accanto alla riga che lo dice.
            'intatta' => in_array(self::FRASE_INTATTA, self::mancanze($contenuto, []), true),
            'frase_intatta' => self::FRASE_INTATTA,
            'registro' => self::registroPerLaPagina($contenuto['registro']),
        ];
    }

    /**
     * La nota per la stampa (la parte del fascicolo, «Stampa la nota», la versione fermata): le parti con i fatti tenuti (la frase riscritta, o quella di
     * oggi), i testi, gli elenchi, la dichiarazione, le cose da decidere. Le parti vuote non ci sono; `vuota` se non c'è niente da stampare.
     *
     * @param  bool  $conLaParteCondomini  il fascicolo ha la parte con le posizioni di ciascuno: la frase della riservatezza dice dove sono
     */
    public function perLaStampa(Condominio $condominio, Esercizio $esercizio, array $letture, ?string $oggi = null, bool $conLaParteCondomini = false): array
    {
        $contenuto = self::contenuto($esercizio);
        $proposti = $this->fatti->perEsercizio($condominio, $esercizio, $letture, $oggi ?? DateHelper::oggiUtente());
        $fatti = array_map(fn (array $f) => $f + self::statoDi($f, $contenuto), $proposti['fatti']);

        return self::stampa($fatti, $contenuto, $conLaParteCondomini) + ['alla' => (string) $letture['controlli']['riepilogo']['alla']];
    }

    /** La nota di oggi chiede la lettura del conguaglio? Sì, se tiene un fatto che la legge. */
    public static function chiedeIlConguaglio(Esercizio $esercizio): bool
    {
        $c = self::contenuto($esercizio);
        $fatti = $c['fatti'];

        return collect(self::CON_IL_CONGUAGLIO)->contains(fn ($k) => ($fatti[$k]['stato'] ?? null) === 'tenuto')
            // La spiegazione di un controllo del conguaglio si stampa solo se il controllo è calcolato: senza la lettura sarebbe «non calcolato».
            || collect(self::CONTROLLI_DEL_CONGUAGLIO)->contains(fn ($id) => trim((string) ($c['spiegazioni'][$id] ?? '')) !== ''
                || ($fatti['G9:'.$id]['stato'] ?? null) === 'tenuto');
    }

    /** Lo stato di un fatto nella bozza: proposto (nessuno), tenuto, tolto; la frase riscritta e quella proposta quel giorno; se oggi direbbe altro. */
    private static function statoDi(array $fatto, array $contenuto): array
    {
        $s = $contenuto['fatti'][$fatto['chiave']] ?? [];
        $riscritto = $s['riscritto'] ?? null;
        $testoDiOggi = self::testoDelFatto($fatto, $contenuto, false);

        return [
            'stato' => $s['stato'] ?? 'proposto',
            'riscritto' => $riscritto,
            'riscritto_il' => $s['il'] ?? null,
            'proposto_allora' => $s['proposto'] ?? null,
            // «Hai riscritto questo fatto il …; oggi il programma direbbe: …»: solo se la frase di oggi non è quella di allora.
            'cambiato' => $riscritto !== null && ($s['proposto'] ?? null) !== null && $s['proposto'] !== $testoDiOggi,
            'testo_di_oggi' => $testoDiOggi,
        ];
    }

    /**
     * Il testo di un fatto com'è proposto oggi: la frase, le righe di G2 (con la causa accanto, se scritta) e la coda; per un controllo, la frase con la
     * spiegazione dell'amministratore.
     */
    public static function testoDelFatto(array $fatto, array $contenuto, bool $conLeCause = true): string
    {
        if (isset($fatto['banca'])) {
            $spiegazione = trim((string) ($contenuto['banca'][$fatto['banca']]['spiegazione'] ?? ''));

            return $spiegazione === '' || ! $conLeCause ? $fatto['frase'] : $fatto['frase'].' '.self::conIlPunto($spiegazione);
        }
        if ($fatto['spiegazione'] ?? false) {
            $spiegazione = trim((string) ($contenuto['spiegazioni'][$fatto['controllo']] ?? ''));

            return $spiegazione === '' ? $fatto['frase'] : $fatto['frase'].' '.self::conIlPunto($spiegazione);
        }
        $righe = array_map(function (array $r) use ($contenuto, $conLeCause) {
            $causa = trim((string) ($contenuto['cause'][(string) $r['voce_id']] ?? ''));

            return '– '.$r['testo'].($conLeCause && $causa !== '' ? ' '.self::conIlPunto($causa) : '');
        }, $fatto['righe'] ?? []);

        return implode("\n", array_filter([$fatto['frase'], ...$righe, $fatto['coda'] ?? null]));
    }

    private static function conIlPunto(string $testo): string
    {
        return preg_match('/[.!?…»)]$/u', $testo) ? $testo : $testo.'.';
    }

    /**
     * Le parti da stampare. Un fatto si stampa se è tenuto; un controllo, se ha la spiegazione; il fatto della riservatezza dice dove sono le posizioni
     * solo quando il fascicolo le ha.
     */
    private static function stampa(array $fatti, array $contenuto, bool $conLaParteCondomini): array
    {
        $parti = [];
        $voti = [];
        $spiegati = 0;
        // Con il confronto di un conto della banca stampato, la liquidità non ripete che il confronto lo fa l'amministratore: è lì sotto.
        $conLaBanca = collect($fatti)->contains(fn ($f) => isset($f['banca']) && $f['stato'] !== 'tolto');
        foreach (self::PARTI as $parte => $titolo) {
            $frasi = [];
            foreach ($fatti as $f) {
                // Il controllo che non torna o è da leggere si stampa con la spiegazione: scriverla è tenerlo, toglierlo lo toglie.
                // Il saldo della banca scritto dall'amministratore è suo come la spiegazione: il fatto del conto si stampa, salvo che sia tolto.
                $spiegato = (($f['spiegazione'] ?? false) && trim((string) ($contenuto['spiegazioni'][$f['controllo']] ?? '')) !== '') || isset($f['banca']);
                if ($f['parte'] !== $parte || $f['stato'] === 'tolto' || ($f['stato'] !== 'tenuto' && ! $spiegato)) {
                    continue;
                }
                if (($f['spiegazione'] ?? false) && $f['riscritto'] === null && ! $spiegato) {
                    continue;
                }
                if ($f['spiegazione'] ?? false) {
                    $spiegati++;
                }
                $testo = $f['riscritto'] ?? self::testoDelFatto($f, $contenuto);
                if ($f['chiave'] === 'N5' && $f['riscritto'] === null && $conLaParteCondomini) {
                    $testo = FattiDellaNota::N5_NEL_FASCICOLO;
                }
                if ($f['chiave'] === 'G6' && $f['riscritto'] === null && $conLaBanca && isset($f['frase_breve'])) {
                    $testo = $f['frase_breve'];
                }
                $frasi[] = ['chiave' => $f['chiave'], 'testo' => $testo];
                if (($f['voto'] ?? null) !== null) {
                    $voti[] = $f['voto'];
                }
            }
            $p = ['chiave' => $parte, 'titolo' => $titolo, 'fatti' => $frasi, 'testo' => trim($contenuto['parti'][$parte]['testo'])];
            if ($parte === 'rapporti') {
                $p['contratti'] = $contenuto['contratti'];
                // I dipendenti copiati da un'altra nota restano da confermare: non si stampano finché l'amministratore non li conferma.
                $p['dipendenti'] = $contenuto['dipendenti']['stato'] === null || $contenuto['dipendenti']['da_confermare'] ? null
                    : ($contenuto['dipendenti']['stato'] === 'nessuno' ? 'Il condominio non ha dipendenti.' : self::conIlPunto(trim('Dipendenti: '.$contenuto['dipendenti']['testo'])));
            }
            if ($parte === 'questioni') {
                $p['dichiarazione'] = self::dichiarazione($contenuto['dichiarazione']);
                $p['debiti_certi'] = $contenuto['debiti_certi'];
                $p['da_decidere'] = [...$voti, ...array_map(fn ($r) => self::conIlPunto($r), $contenuto['da_decidere'])];
            }
            $vuota = $p['fatti'] === [] && $p['testo'] === '' && ($p['contratti'] ?? []) === [] && ($p['dipendenti'] ?? null) === null
                && ($p['dichiarazione'] ?? []) === [] && ($p['debiti_certi'] ?? []) === [] && ($p['da_decidere'] ?? []) === [];
            if (! $vuota) {
                $parti[] = $p;
            }
        }

        // Il controllo della banca si spiega sotto il saldo della banca: è spiegato quando un conto con la differenza si stampa con la spiegazione (o con la
        // frase riscritta dall'amministratore). Una volta: è un controllo solo, anche con più conti.
        if (collect($fatti)->contains(fn ($f) => isset($f['banca']) && $f['stato'] !== 'tolto' && (int) ($f['differenza'] ?? 0) !== 0
            && (($f['riscritto'] ?? null) !== null || trim((string) ($contenuto['banca'][$f['banca']]['spiegazione'] ?? '')) !== ''))) {
            $spiegati++;
        }

        return ['parti' => $parti, 'vuota' => $parti === [], 'spiegati' => $spiegati];
    }

    /** La dichiarazione sulle liti, le impugnazioni e i sinistri, a frasi: «L'amministratore dichiara che …», e per quelle che ci sono il loro testo. */
    public static function dichiarazione(array $d): array
    {
        $nessune = [];
        $righe = [];
        foreach (self::DICHIARAZIONE as $chiave => $domanda) {
            $stato = $d[$chiave]['stato'] ?? null;
            // Una risposta copiata da un'altra nota non si stampa finché l'amministratore non la conferma: un «nessuna lite» copiato può essere falso.
            if ($d[$chiave]['da_confermare'] ?? false) {
                continue;
            }
            if ($stato === 'nessuna') {
                $nessune[] = $domanda['nessuna'];
            } elseif ($stato === 'ci_sono') {
                $quali = trim((string) ($d[$chiave]['testo'] ?? ''));
                $righe[] = $domanda['ci_sono'].'.'.($quali === '' ? '' : ' '.self::conIlPunto($quali));
            }
        }
        if ($nessune !== []) {
            // «… che A, che B e che C»: ogni parte con il suo «che», l'ultima con «e che». Il punto e virgola spezzava il parallelo.
            array_unshift($righe, 'L\'amministratore dichiara che '.(count($nessune) === 1 ? $nessune[0] : implode(', che ', array_slice($nessune, 0, -1)).' e che '.end($nessune)).'.');
        }

        return $righe;
    }

    /**
     * Che cosa manca alla nota, per le finestre di «Ferma per l'assemblea» e del fascicolo: le parti senza un fatto tenuto e senza testo, la dichiarazione
     * non fatta, i controlli da leggere senza spiegazione. Non ferma niente.
     *
     * Il controllo della banca si spiega in un posto solo, sotto il saldo della banca: con i conti della banca (`$banche`, il confronto con la differenza
     * di ognuno) manca la spiegazione di ogni conto che non coincide; senza, basta una spiegazione scritta sotto un conto.
     *
     * @param  list<array{id:string, nome:string, esito:string}>  $controlli  le righe dell'esito dei controlli
     * @param  list<array{cassa_id:int, estratto:?int, differenza:?int}>|null  $banche  i conti della banca della stessa lettura ({@see SaldiDelleBanche::confronto()})
     * @return list<string>
     */
    public static function mancanze(array $contenuto, array $controlli, ?array $banche = null): array
    {
        $mancano = [];
        $parti = [];
        foreach (self::PARTI as $parte => $titolo) {
            $tenuti = collect($contenuto['fatti'])->filter(fn ($s, $k) => ($s['stato'] ?? null) === 'tenuto' && self::parteDellaChiave((string) $k) === $parte)->isNotEmpty();
            $elenchi = match ($parte) {
                'gestione' => collect($contenuto['spiegazioni'])->contains(fn ($t) => trim($t) !== '') || $contenuto['banca'] !== [],
                'rapporti' => $contenuto['contratti'] !== [] || $contenuto['dipendenti']['stato'] !== null,
                'questioni' => $contenuto['debiti_certi'] !== [] || $contenuto['da_decidere'] !== [] || collect($contenuto['dichiarazione'])->contains(fn ($d) => $d['stato'] !== null),
                default => false,
            };
            if (! $tenuti && ! $elenchi && trim($contenuto['parti'][$parte]['testo']) === '') {
                $parti[] = 'La parte «'.mb_substr($titolo, 3).'» non ha né fatti tenuti né testo: tieni un suo fatto o scrivi il testo, in quella parte della nota.';
            }
        }
        // Nessuna parte toccata: una riga sola, che dice dove si comincia.
        array_push($mancano, ...(count($parti) === count(self::PARTI) ? [self::FRASE_INTATTA] : $parti));
        $questioni = 'nella parte «Le questioni pendenti» della nota';
        $senza = array_keys(array_filter(self::DICHIARAZIONE, fn ($d, $k) => ($contenuto['dichiarazione'][$k]['stato'] ?? null) === null, ARRAY_FILTER_USE_BOTH));
        if ($senza !== []) {
            $mancano[] = count($senza) === 3 ? 'La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta: la trovi '.$questioni.'.'
                : 'Nella dichiarazione manca la risposta su: '.implode(', ', array_map(fn ($k) => mb_strtolower(self::DICHIARAZIONE[$k]['domanda']), $senza)).' ('.$questioni.').';
        }
        if (collect($contenuto['dichiarazione'])->contains(fn ($d) => $d['stato'] !== null && $d['da_confermare'])) {
            $mancano[] = 'La dichiarazione copiata da un\'altra nota è da confermare: non si stampa finché non la confermi, '.$questioni.'.';
        }
        foreach (self::DICHIARAZIONE as $k => $d) {
            if (($contenuto['dichiarazione'][$k]['stato'] ?? null) === 'ci_sono' && trim((string) $contenuto['dichiarazione'][$k]['testo']) === '') {
                $mancano[] = 'Hai scritto «'.mb_strtolower($d['ci_sono']).'», ma non quali: scrivilo '.$questioni.'.';
            }
        }
        if ($contenuto['dipendenti']['stato'] !== null && $contenuto['dipendenti']['da_confermare']) {
            $mancano[] = 'La riga dei dipendenti copiata da un\'altra nota è da confermare: non si stampa finché non la confermi, nella parte «I rapporti in corso» della nota.';
        }
        $daSpiegare = count(array_filter($controlli, fn ($c) => $c['id'] !== 'BC' && in_array($c['esito'], ['non_quadra', 'segnalazione'], true)
            && trim((string) ($contenuto['spiegazioni'][$c['id']] ?? '')) === ''));
        if ($daSpiegare > 0) {
            $mancano[] = ($daSpiegare === 1 ? 'Un controllo che non torna o è da leggere non ha' : $daSpiegare.' controlli che non tornano o sono da leggere non hanno')
                .' la spiegazione: scrivila sotto il controllo, nella parte «La gestione dell\'anno» della nota.';
        }
        $banca = collect($controlli)->contains(fn ($c) => $c['id'] === 'BC' && in_array($c['esito'], ['non_quadra', 'segnalazione'], true));
        if ($banca && ! self::bancaSpiegata($contenuto, $banche)) {
            $mancano[] = self::FRASE_BANCA_SENZA_SPIEGAZIONE;
        }

        return $mancano;
    }

    /** La differenza della banca ha la spiegazione: sotto ogni conto che non coincide; senza i conti della lettura, sotto almeno un conto. */
    private static function bancaSpiegata(array $contenuto, ?array $banche): bool
    {
        $scritta = fn ($cassaId) => trim((string) ($contenuto['banca'][(string) $cassaId]['spiegazione'] ?? '')) !== '';
        if ($banche === null) {
            return collect(array_keys($contenuto['banca']))->contains($scritta);
        }

        return collect($banche)->filter(fn ($b) => ($b['estratto'] ?? null) !== null && (int) ($b['differenza'] ?? 0) !== 0)
            ->every(fn ($b) => $scritta($b['cassa_id']));
    }

    /**
     * La bozza non ha niente da stampare? Dalla sola bozza, senza leggere i fatti: nessun fatto tenuto, nessun testo, nessun elenco, nessuna dichiarazione.
     * Per le finestre della Sintesi; la stampa lo sa con i fatti di oggi ({@see perLaStampa()}).
     */
    public static function bozzaVuota(array $contenuto): bool
    {
        return collect($contenuto['fatti'])->every(fn ($s) => ($s['stato'] ?? null) !== 'tenuto')
            && collect($contenuto['parti'])->every(fn ($p) => trim($p['testo']) === '') && collect($contenuto['spiegazioni'])->every(fn ($t) => trim($t) === '') && $contenuto['banca'] === []
            && $contenuto['contratti'] === [] && $contenuto['debiti_certi'] === [] && $contenuto['da_decidere'] === []
            && ($contenuto['dipendenti']['stato'] === null || $contenuto['dipendenti']['da_confermare'])
            && collect($contenuto['dichiarazione'])->every(fn ($d) => $d['stato'] === null || $d['da_confermare']);
    }

    /** @return list<string> */
    public static function mancanzeDelleBanche(array $banche): array
    {
        $senza = array_values(array_filter($banche, fn ($b) => $b['estratto'] === null));

        return $senza === [] ? [] : ['Il saldo che dice la banca non è scritto per '.implode(', ', array_map(fn ($b) => '«'.$b['cassa'].'»', $senza))
            .': scrivilo accanto alla liquidità, nella parte «La gestione dell\'anno» della nota.'];
    }

    /** La parte di un fatto dalla sua chiave: K i criteri, G e N3 la gestione, R i rapporti, Q le questioni, N4 e N5 le altre informazioni. */
    public static function parteDellaChiave(string $chiave): string
    {
        return match (true) {
            str_starts_with($chiave, 'K') => 'criteri',
            str_starts_with($chiave, 'G'), $chiave === 'N3' => 'gestione',
            str_starts_with($chiave, 'R') => 'rapporti',
            str_starts_with($chiave, 'Q') => 'questioni',
            default => 'altre',
        };
    }

    /** Le parole della nota stampata e le pagine, circa. */
    public static function parole(array $stampa): array
    {
        $testi = [];
        foreach ($stampa['parti'] as $p) {
            $testi[] = $p['titolo'];
            array_push($testi, ...array_column($p['fatti'], 'testo'));
            $testi[] = $p['testo'];
            foreach ($p['contratti'] ?? [] as $c) {
                $testi[] = implode(' ', array_filter([$c['cosa'], $c['con_chi'], $c['rinnovo'], $c['note']]));
            }
            array_push($testi, ...($p['dichiarazione'] ?? []), ...($p['da_decidere'] ?? []));
            foreach ($p['debiti_certi'] ?? [] as $d) {
                $testi[] = implode(' ', [$d['creditore'], $d['fonte']]);
            }
        }
        $parole = count(preg_split('/\s+/u', trim(implode(' ', $testi)), -1, PREG_SPLIT_NO_EMPTY));

        return ['parole' => $parole, 'pagine' => $parole === 0 ? 0 : max(1, (int) ceil($parole / self::PAROLE_PER_PAGINA))];
    }

    /**
     * Il contenuto della bozza com'è salvato: ogni chiave c'è, ogni testo è testo (gli a capo «\n», senza gli spazi in testa e in coda), ogni elenco ha
     * al più {@see RIGHE_ELENCO} righe, le cifre sono in centesimi. Le cifre che arrivano dalla pagina sono in euro: si convertono qui, una volta.
     *
     * @param  bool  $dallaPagina  le cifre degli elenchi sono in euro (la pagina); altrimenti sono già centesimi (il database, una copia)
     */
    public static function normalizza(array $c, bool $dallaPagina = false): array
    {
        $testo = fn ($v) => mb_substr(str_replace("\r\n", "\n", trim((string) ($v ?? ''))), 0, self::LUNGHEZZA);
        $data = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($v, 0, 10)) ? substr($v, 0, 10) : null;
        $cifra = fn ($v) => $v === null || $v === '' ? null : ($dallaPagina ? MoneyHelper::toCents($v) : (int) $v);
        $parti = [];
        foreach (array_keys(self::PARTI) as $parte) {
            $parti[$parte] = ['testo' => $testo($c['parti'][$parte]['testo'] ?? $c[$parte]['testo'] ?? '')];
        }
        $fatti = [];
        foreach ((array) ($c['fatti'] ?? []) as $chiave => $s) {
            $stato = $s['stato'] ?? null;
            if (! in_array($stato, ['tenuto', 'tolto'], true) || ! is_string($chiave) || strlen($chiave) > 40) {
                continue;
            }
            $riscritto = isset($s['riscritto']) && trim((string) $s['riscritto']) !== '' ? mb_substr($testo($s['riscritto']), 0, self::LUNGHEZZA_FRASE) : null;
            $fatti[$chiave] = ['stato' => $stato, 'riscritto' => $stato === 'tenuto' ? $riscritto : null,
                'proposto' => $riscritto !== null && isset($s['proposto']) ? (string) $s['proposto'] : null, 'il' => $riscritto !== null ? $data($s['il'] ?? null) : null];
        }
        $mappa = fn ($v) => collect((array) ($v ?? []))->map(fn ($t) => mb_substr($testo($t), 0, self::LUNGHEZZA_FRASE))->filter(fn ($t) => $t !== '')->take(200)->all();
        $si = fn ($v) => filter_var($v ?? false, FILTER_VALIDATE_BOOLEAN);
        $contratti = collect((array) ($c['contratti'] ?? []))->map(fn ($r) => [
            'tipo' => array_key_exists($r['tipo'] ?? '', self::TIPI_CONTRATTO) ? $r['tipo'] : 'contratto',
            'cosa' => mb_substr($testo($r['cosa'] ?? ''), 0, 255), 'con_chi' => mb_substr($testo($r['con_chi'] ?? ''), 0, 255), 'importo' => $cifra($r['importo'] ?? null),
            'scadenza' => $data($r['scadenza'] ?? null), 'rinnovo' => mb_substr($testo($r['rinnovo'] ?? ''), 0, 255), 'note' => mb_substr($testo($r['note'] ?? ''), 0, 500),
            'fonte' => 'amministratore',
        ])->filter(fn ($r) => $r['cosa'] !== '' || $r['con_chi'] !== '')->take(self::RIGHE_ELENCO)->values()->all();
        $debiti = collect((array) ($c['debiti_certi'] ?? []))->map(fn ($r) => [
            'creditore' => mb_substr($testo($r['creditore'] ?? ''), 0, 255), 'importo' => $cifra($r['importo'] ?? null), 'fonte' => mb_substr($testo($r['fonte'] ?? ''), 0, 255),
            'scadenza' => $data($r['scadenza'] ?? null),
        ])->filter(fn ($r) => $r['creditore'] !== '' || $r['importo'] !== null)->take(self::RIGHE_ELENCO)->values()->all();
        $dichiarazione = [];
        foreach (array_keys(self::DICHIARAZIONE) as $k) {
            $stato = $c['dichiarazione'][$k]['stato'] ?? null;
            $stato = in_array($stato, ['nessuna', 'ci_sono'], true) ? $stato : null;
            $dichiarazione[$k] = ['stato' => $stato, 'testo' => $testo($c['dichiarazione'][$k]['testo'] ?? ''),
                'da_confermare' => $stato !== null && $si($c['dichiarazione'][$k]['da_confermare'] ?? false)];
        }
        $dipendenti = $c['dipendenti']['stato'] ?? null;

        return [
            'parti' => $parti,
            'fatti' => $fatti,
            'cause' => $mappa($c['cause'] ?? []),
            'spiegazioni' => $mappa($c['spiegazioni'] ?? []),
            'contratti' => $contratti,
            'dipendenti' => ['stato' => in_array($dipendenti, ['nessuno', 'ci_sono'], true) ? $dipendenti : null,
                'testo' => mb_substr($testo($c['dipendenti']['testo'] ?? ''), 0, self::LUNGHEZZA_RIGA),
                'da_confermare' => in_array($dipendenti, ['nessuno', 'ci_sono'], true) && $si($c['dipendenti']['da_confermare'] ?? false)],
            'debiti_certi' => $debiti,
            'dichiarazione' => $dichiarazione,
            'da_decidere' => collect((array) ($c['da_decidere'] ?? []))->map(fn ($t) => mb_substr($testo($t), 0, self::LUNGHEZZA_RIGA))->filter(fn ($t) => $t !== '')
                ->take(self::RIGHE_ELENCO)->values()->all(),
            // Il saldo dell'estratto conto di ogni conto della banca, per cassa: la data, la cifra (anche sotto zero), la spiegazione di una differenza.
            'banca' => collect((array) ($c['banca'] ?? []))->filter(fn ($b, $id) => is_numeric($id) && is_array($b))->map(fn ($b) => [
                'data' => $data($b['data'] ?? null), 'saldo' => ($b['saldo'] ?? null) === null || ($b['saldo'] ?? '') === '' ? null : ($dallaPagina ? MoneyHelper::toCents($b['saldo']) : (int) $b['saldo']),
                'spiegazione' => mb_substr($testo($b['spiegazione'] ?? ''), 0, self::LUNGHEZZA_FRASE),
            ])->filter(fn ($b) => $b['saldo'] !== null || $b['spiegazione'] !== '')->mapWithKeys(fn ($b, $id) => [(string) $id => $b])->take(self::RIGHE_ELENCO)->all(),
            // Il registro della nota: le righe che lasciano «Genera la nota» (chi, quando, che cosa mancava, la sua nota). Si scrive solo dal programma, non
            // dalla pagina, e resta anche quando la nota cambia ({@see registra()}).
            'registro' => collect((array) ($c['registro'] ?? []))->filter(fn ($r) => is_array($r) && isset($r['tipo'], $r['il']))->map(fn ($r) => [
                'tipo' => (string) $r['tipo'], 'il' => (string) $r['il'], 'utente_id' => isset($r['utente_id']) ? (int) $r['utente_id'] : null,
                'utente' => (string) ($r['utente'] ?? ''), 'nota' => (string) ($r['nota'] ?? ''), 'dati' => (array) ($r['dati'] ?? []),
            ])->values()->all(),
        ];
    }

    /** Salva la bozza dell'esercizio, con chi l'ha toccata. */
    public static function salva(Esercizio $esercizio, array $contenuto, ?User $utente): NotaSintetica
    {
        return NotaSintetica::updateOrCreate(['esercizio_id' => $esercizio->id],
            ['condominio_id' => $esercizio->condominio_id, 'contenuto' => $contenuto, 'aggiornata_da' => $utente?->id]);
    }

    /**
     * Che cosa si copia da un esercizio (l'anno prima, o un altro condominio): i testi delle parti, gli elenchi, i dipendenti, la dichiarazione, le cose da
     * decidere scritte a mano. Mai i fatti, le cause degli scostamenti e le spiegazioni dei controlli: sono dei conti di quell'anno e di quel condominio.
     * Si copia dalla nota dell'ultima versione approvata dell'esercizio, altrimenti dell'ultima presentata, altrimenti dalla bozza.
     *
     * @return array{da:string, contenuto:array<string,mixed>}|null
     */
    public static function copiabile(Esercizio $da): ?array
    {
        $versione = RendicontoVersione::where('esercizio_id', $da->id)->whereIn('stato', [RendicontoVersione::APPROVATA, RendicontoVersione::PRESENTATA])
            ->orderByRaw("CASE WHEN stato = 'approvata' THEN 0 ELSE 1 END")->orderByDesc('numero')->first(['id', 'numero', 'stato', 'nota']);
        $bozza = $versione !== null && is_array($versione->nota) ? ($versione->nota['bozza'] ?? $versione->nota) : NotaSintetica::where('esercizio_id', $da->id)->first()?->contenuto;
        if (! is_array($bozza) || $bozza === []) {
            return null;
        }
        $c = self::normalizza($bozza);
        $c['fatti'] = [];
        $c['cause'] = [];
        $c['spiegazioni'] = [];
        // Il saldo dell'estratto conto è una cifra dell'anno: non si copia. I conti della banca la pagina li prende dalle casse del condominio.
        $c['banca'] = [];
        $c['registro'] = [];
        // Una dichiarazione copiata non si stampa finché l'amministratore non la conferma: «nessuna lite» copiato senza guardare può essere falso. Lo stesso
        // per i dipendenti. Qui, sul server: nessuna pagina la può saltare (la copia dall'anno prima, da un altro condominio, «Genera la nota»).
        foreach ($c['dichiarazione'] as $k => $d) {
            $c['dichiarazione'][$k]['da_confermare'] = $d['stato'] !== null;
        }
        $c['dipendenti']['da_confermare'] = $c['dipendenti']['stato'] !== null;
        $dove = $versione === null ? 'la bozza della nota' : 'la nota della versione '.$versione->numero.' ('.($versione->stato === RendicontoVersione::APPROVATA ? 'approvata' : 'presentata').')';

        return ['da' => $dove.' di «'.$da->nome.'»', 'contenuto' => $c];
    }

    /**
     * «Genera la nota», con un clic dell'amministratore: tiene tutti i fatti che il programma propone oggi (non quelli che ha tolto), e copia dall'anno prima
     * i testi delle parti vuote, i contratti e i debiti certi se la nota non ne ha, i dipendenti e la dichiarazione se non sono scritti (da confermare: non si
     * stampano finché non li conferma). Non inventa niente. Lascia una riga nel registro della nota con chi, quando, che cosa mancava dopo e la sua nota.
     *
     * @return array{mancanze: list<string>, tenuti: int, copiato_da: ?string}
     */
    public function genera(Condominio $condominio, Esercizio $esercizio, string $nota, ?User $utente): array
    {
        $letture = $this->letture($condominio, $esercizio);
        $proposti = $this->fatti->perEsercizio($condominio, $esercizio, $letture);
        $c = self::contenuto($esercizio);
        $tenuti = 0;
        foreach ($proposti['fatti'] as $f) {
            if (! isset($c['fatti'][$f['chiave']])) {
                $c['fatti'][$f['chiave']] = ['stato' => 'tenuto', 'riscritto' => null, 'proposto' => null, 'il' => null];
                $tenuti++;
            }
        }
        $prima = self::eserciziPrima($esercizio);
        $copia = $prima === null ? null : self::copiabile($prima);
        if ($copia !== null) {
            $da = $copia['contenuto'];
            foreach (array_keys(self::PARTI) as $parte) {
                if (trim($c['parti'][$parte]['testo']) === '') {
                    $c['parti'][$parte]['testo'] = $da['parti'][$parte]['testo'];
                }
            }
            $contrattiCopiati = $c['contratti'] === [] && $da['contratti'] !== [];
            foreach (['contratti', 'debiti_certi'] as $elenco) {
                if ($c[$elenco] === []) {
                    $c[$elenco] = $da[$elenco];
                }
            }
            if ($c['dipendenti']['stato'] === null) {
                $c['dipendenti'] = $da['dipendenti'];
            }
            foreach ($c['dichiarazione'] as $k => $d) {
                if ($d['stato'] === null) {
                    $c['dichiarazione'][$k] = $da['dichiarazione'][$k];
                }
            }
        }
        $mancanze = [...self::mancanze($c, $letture['sintesi']['esito']['righe'] ?? [], $proposti['banche']), ...self::mancanzeDelleBanche($proposti['banche'])];
        if ($contrattiCopiati ?? false) {
            $mancanze[] = 'I contratti sono quelli dell\'anno prima: rileggi scadenze e importi, nella parte «I rapporti in corso» della nota.';
        }
        if ($copia === null) {
            array_unshift($mancanze, $prima === null ? 'Non c\'è un esercizio prima con una nota: i testi e gli elenchi non si copiano.'
                : 'L\'esercizio «'.$prima->nome.'» non ha una nota: i testi e gli elenchi non si copiano.');
        }
        $c = self::registra($c, 'genera', $nota, $utente, ['mancanze' => $mancanze, 'tenuti' => $tenuti, 'copiato_da' => $copia['da'] ?? null]);
        self::salva($esercizio, $c, $utente);

        return ['mancanze' => $mancanze, 'tenuti' => $tenuti, 'copiato_da' => $copia['da'] ?? null];
    }

    /**
     * Una riga del registro della nota: il tipo, il giorno e l'ora (UTC), chi (il numero e il nome di quel giorno), la sua nota e i dati del gesto. È la forma
     * delle righe di ogni registro di un gesto dell'amministratore che deve restare scritto (anche «Ignora» di una pista): una lista dentro il JSON di chi la tiene.
     * La riga la scrive {@see RegistroDelGesto}, che la usano anche le risposte ai promemoria.
     */
    public static function registra(array $contenuto, string $tipo, string $nota, ?User $utente, array $dati): array
    {
        return RegistroDelGesto::registra($contenuto, $tipo, $nota, $utente, $dati);
    }

    /** Le righe del registro per la pagina, la più recente prima, con il giorno e l'ora nel fuso dell'amministratore. */
    public static function registroPerLaPagina(array $registro): array
    {
        return RegistroDelGesto::perLaPagina($registro);
    }

    /** L'esercizio prima di questo nello stesso condominio, se c'è. */
    public static function eserciziPrima(Esercizio $esercizio): ?Esercizio
    {
        return Esercizio::where('condominio_id', $esercizio->condominio_id)->where('data_inizio', '<', $esercizio->data_inizio)->orderByDesc('data_inizio')->first();
    }
}
