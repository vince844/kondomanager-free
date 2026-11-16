<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\NotaSintetica;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\PdfService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Le versioni del rendiconto per l'assemblea: fermarlo, conservarne il PDF, registrarne l'approvazione, dire in testa a ogni scheda a che punto è.
 *
 * FERMARE. «Ferma per l'assemblea» legge una volta il conguaglio (sempre, anche sopra la soglia della Sintesi), ne copia le cifre nella forma di oggi
 * ({@see CifreDelRendiconto}) e la nota sintetica com'è (la bozza e le frasi stampate quel giorno), e fa il fascicolo con le parti scelte e la data di emissione della versione: lo conserva
 * sul disco dei documenti come `Documento` della versione, con l'impronta SHA-256 (la strada delle lettere conservate). Il PDF è il documento di
 * riferimento: è ciò che i condòmini hanno ricevuto. Se il fascicolo sta oltre il tetto di tempo o di memoria la versione nasce lo stesso, con le cifre,
 * e dice che il PDF non c'è: si conservano le parti una per una (ognuna un suo documento, con la sua impronta e la stessa data), o si carica il PDF
 * mandato. Niente si fa in una coda: le installazioni locali non ne hanno una affidabile.
 *
 * APPROVARE. Registrare l'approvazione è scrivere un fatto dell'assemblea, non decidere: la data della delibera, il numero del verbale, il verbale (un
 * file), una nota, e se l'assemblea ha approvato anche lo stato di riparto, sì o no, senza una risposta già scelta. Una versione senza PDF si può
 * approvare. Una seconda approvazione dello stesso esercizio fa la prima «sostituita» e la tiene. Lo stato dell'esercizio non cambia, e niente si blocca.
 */
final class VersioniDelRendiconto
{
    /** La cartella dei documenti delle versioni, sotto quella del condominio. */
    public const CARTELLA = 'rendiconto';

    /** La parte di un documento della versione: il fascicolo intero, o quello caricato. Le altre sono le parti del fascicolo. */
    public const FASCICOLO = 'fascicolo';

    public const CARICATO = 'caricato';

    /** La nota stampata di una versione fermata senza nota. */
    public const NOTA_VUOTA = ['parti' => [], 'vuota' => true, 'spiegati' => 0];

    public const FRASE_BOZZA = 'Bozza: le cifre cambiano a ogni registrazione.';

    public const FRASE_SENZA_PDF = 'Il PDF del fascicolo non è conservato: è troppo grande per farlo in una volta. Conserva le parti una per una, o carica il PDF che hai mandato.';

    public function __construct(
        private readonly CifreDelRendiconto $cifre,
        private readonly FascicoloDelRendiconto $fascicolo,
        private readonly ConguaglioDelRendiconto $conguaglio,
        private readonly ArchivioDocumenti $archivio,
        private readonly NotaSinteticaDelRendiconto $nota,
    ) {}

    /**
     * «Ferma per l'assemblea»: una versione presentata, con il suo numero, le cifre, la nota e il PDF del fascicolo (o la frase che dice perché non c'è).
     *
     * @param  list<string>  $parti  le parti del fascicolo scelte, in qualunque ordine; nessuna, tutte
     */
    public function ferma(Condominio $condominio, Esercizio $esercizio, array $parti, bool $dettaglio, bool $lettere, ?User $utente): RendicontoVersione
    {
        // La lettura del conguaglio viene prima della prima stampa, che è dove il limite di tempo si alza: qui si alza subito.
        PdfService::alzaIlLimiteDiTempo();
        $adesso = DateHelper::adessoUtente();
        $parti = array_values(array_intersect(FascicoloDelRendiconto::PARTI, $parti)) ?: FascicoloDelRendiconto::PARTI;
        // Una lettura del conguaglio, per le cifre e per il fascicolo. Il fascicolo la riceve e chi chiama non la tiene: così esce dalla memoria prima
        // che le parti si stampino, come nel fascicolo scaricato dalla Sintesi, e il tetto del fascicolo conta giusto.
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        $letto['calcolato_il'] = self::calcolatoIl($adesso);
        // Le cifre in JSON prima del fascicolo: l'array (circa dieci volte il JSON) non resta in memoria mentre le parti si stampano, fuori dal tetto.
        $cifre = json_encode($this->cifre->perEsercizio($condominio, $esercizio, $letto, $adesso), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $esito = $this->fascicolo->genera($condominio, $esercizio, $parti, $dettaglio, null, $lettere, $adesso, self::cedi($letto));
        // La nota com'è quel giorno: la bozza e le frasi stampate, con le cifre di quel giorno. Cambiare la nota dopo non cambia la versione. Senza la
        // nota nel fascicolo (non scelta, o il fascicolo oltre il tetto) le frasi si leggono qui.
        $nota = null;
        if (NotaSintetica::where('esercizio_id', $esercizio->id)->exists()) {
            $giorno = $adesso->format('Y-m-d');
            $nota = ['bozza' => NotaSinteticaDelRendiconto::contenuto($esercizio), 'stampa' => $esito['nota']
                ?? $this->nota->perLaStampa($condominio, $esercizio, $this->nota->letture($condominio, $esercizio, $giorno, null, false), $giorno, in_array('condomini', $parti, true))];
        }

        $pdf = ['parti' => $parti, 'dettaglio' => $dettaglio, 'lettere' => $lettere, 'documenti' => [], 'troppo' => $esito['troppo'] ?? null];
        $percorso = isset($esito['pdf']) ? $this->archivio->salvaContenuto($esito['pdf'], 'pdf', $this->cartella($condominio)) : null;

        try {
            return DB::transaction(function () use ($condominio, $esercizio, $cifre, $nota, $adesso, $utente, $esito, $percorso, $pdf) {
                // Il numero dopo l'ultimo dell'esercizio, letto sotto il blocco della riga: due «Ferma» insieme non prendono lo stesso.
                $numero = (int) RendicontoVersione::where('esercizio_id', $esercizio->id)->lockForUpdate()->max('numero') + 1;
                $versione = new RendicontoVersione([
                    'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'numero' => $numero, 'stato' => RendicontoVersione::PRESENTATA,
                    'forma' => RendicontoVersione::FORMA, 'nota' => $nota, 'calcolato_il' => $adesso->copy()->utc(), 'pdf' => $pdf, 'creata_da' => $utente?->id,
                ]);
                // Le cifre sono già JSON: si scrivono come sono, senza il cast che le codificherebbe una seconda volta.
                $versione->setRawAttributes(['cifre' => $cifre] + $versione->getAttributes());
                $versione->save();
                if ($percorso !== null) {
                    $pdf['documenti'][] = $this->documento($versione, $percorso, $esito['pdf'], self::FASCICOLO, $esito['nome'], $utente,
                        array_sum(array_column($esito['indice'], 'pagine')) + 1);
                    $versione->update(['pdf' => $pdf]);
                }

                return $versione;
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * «Conserva le parti una per una»: la parte scelta, con il frontespizio, in un PDF suo con la data della versione. Prima di stamparla le cifre di oggi
     * si confrontano con quelle fermate: una parte conservata porta la data della versione, e deve portarne anche le cifre. Se sono cambiate, niente PDF e
     * la frase dice che cosa è cambiato; oltre il tetto anche da sola, la frase del fascicolo; conservata, null. La nota (la sua parte e il frontespizio)
     * è quella fermata con la versione, con le frasi di quel giorno: la bozza cambiata dopo non entra.
     */
    public function conservaParte(RendicontoVersione $versione, string $parte, ?User $utente): ?string
    {
        abort_unless(in_array($parte, FascicoloDelRendiconto::PARTI, true), 404);
        PdfService::alzaIlLimiteDiTempo();
        $condominio = $versione->condominio;
        $esercizio = $versione->esercizio;
        $emessa = self::emessaIl($versione);
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        // Con le cifre uguali il calcolo è quello della versione: le stampe del conguaglio dicono il suo giorno e la sua ora.
        $letto['calcolato_il'] = self::calcolatoIl($emessa);
        // Il saldo dell'estratto conto della banca scritto il giorno della versione: il controllo della banca della parte è quello di allora.
        $banca = (array) ($versione->nota['bozza']['banca'] ?? []);
        $cambiato = self::differenze((array) $versione->cifre, $this->cifre->perEsercizio($condominio, $esercizio, $letto, DateHelper::adessoUtente(), $banca));
        if ($cambiato !== []) {
            return self::fraseCambiate($versione, $cambiato);
        }
        $pdf = (array) $versione->pdf;
        // La nota di ogni parte (la parte «nota» e il frontespizio) è quella fermata con la versione, con le frasi di quel giorno: la bozza cambiata dopo non
        // entra in un PDF che porta la data della versione. Una versione fermata senza nota ha la nota vuota.
        $esito = $this->fascicolo->genera($condominio, $esercizio, [$parte], (bool) ($pdf['dettaglio'] ?? false), null,
            (bool) ($pdf['lettere'] ?? false), $emessa, self::cedi($letto), (array) ($versione->nota['stampa'] ?? []) + self::NOTA_VUOTA, $banca);
        if (isset($esito['troppo'])) {
            return $esito['troppo'];
        }
        $percorso = $this->archivio->salvaContenuto($esito['pdf'], 'pdf', $this->cartella($condominio));
        try {
            DB::transaction(function () use ($versione, $percorso, $esito, $parte, $utente) {
                $bloccata = RendicontoVersione::whereKey($versione->id)->lockForUpdate()->firstOrFail();
                $pdf = (array) $bloccata->pdf;
                // Una parte conservata due volte: vale l'ultima, e la prima resta fra i documenti della versione.
                $pdf['documenti'] = array_values(array_filter($pdf['documenti'] ?? [], fn ($d) => $d['parte'] !== $parte));
                $pdf['documenti'][] = $this->documento($bloccata, $percorso, $esito['pdf'], $parte, $esito['nome'], $utente,
                    array_sum(array_column($esito['indice'], 'pagine')) + 1);
                $bloccata->update(['pdf' => $pdf]);
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }

        return null;
    }

    /** Le cifre di una riga del conguaglio che si confrontano: non il nome della persona e dell'unità, che le cifre portano per chi legge l'elenco. */
    public const CIFRE_DELLA_RIGA = ['a', 'personali', 'b', 'conguaglio', 'posizione', 'fuori_dalle_rate', 'saldo', 'non_disponibile', 'gia_versato_non_usato',
        'gia_versato_da_decidere', 'giorni', 'giorni_per_ruolo'];

    /**
     * Che cosa è cambiato fra le cifre fermate e quelle di oggi, in parole per l'amministratore: le registrazioni del giornale, le spese, le voci, le
     * quote, la liquidità, i debiti e i crediti della situazione, il conguaglio per persona, l'esito dei controlli (il registro di contabilità per una
     * versione senza l'impronta del giornale di oggi). **Si confrontano le cifre** ({@see daConfrontare()}): non la forma, l'intestazione, i nomi delle
     * persone, delle unità, delle voci e delle gestioni, il giorno e l'ora del calcolo, il controllo della banca (il saldo dell'estratto scritto nella nota
     * non è una registrazione); e solo ciò che la forma più vecchia delle due ha. Vuoto: le cifre sono le stesse.
     *
     * @return list<string>
     */
    public static function differenze(array $prima, array $oggi): array
    {
        $p = self::daConfrontare($prima);
        $o = self::daConfrontare($oggi);
        // Una forma prima della 3 non ha l'impronta del giornale di oggi: il confronto è con le altre cifre, e il registro di contabilità dice le registrazioni.
        if ((int) ($prima['forma'] ?? 1) < 3 || (int) ($oggi['forma'] ?? 1) < 3) {
            unset($p['giornale'], $o['giornale']);
        } else {
            unset($p['registro'], $o['registro']);
        }
        // Solo ciò che tutte e due hanno: la forma più vecchia decide che cosa si confronta.
        $p = array_intersect_key($p, $o);
        $o = array_intersect_key($o, $p);
        if ($p == $o) {
            return [];
        }
        $euro = fn (?int $c) => $c === null ? '—' : '€'."\u{00A0}".number_format($c / 100, 2, ',', '.');
        $data = fn (?string $d) => $d === null ? '—' : Carbon::parse($d)->format('d/m/Y');
        $frasi = [];
        if (isset($p['giornale']) && $p['giornale'] !== $o['giornale']) {
            $cosa = ConfrontoDelRendiconto::differenzeDelGiornale(ConfrontoDelRendiconto::mappa((array) ($prima['giornale']['per_scrittura'] ?? [])),
                ConfrontoDelRendiconto::mappa((array) ($oggi['giornale']['per_scrittura'] ?? [])));
            if ($cosa !== []) {
                $frasi[] = self::fraseDelGiornale($cosa);
            }
            if ($p['giornale']['prima'] !== $o['giornale']['prima']) {
                $frasi[] = 'sono cambiate registrazioni con la data prima dell\'esercizio, che la situazione patrimoniale conta';
            }
        }
        if (($p['alla'] ?? null) !== ($o['alla'] ?? null)) {
            $frasi[] = 'il giorno delle cifre era il '.$data($p['alla'] ?? null).', oggi è il '.$data($o['alla'] ?? null);
        }
        foreach (['costi' => 'le spese dell\'esercizio', 'quote_giornale' => 'le quote registrate', 'quote_pagate' => 'le quote pagate'] as $k => $nome) {
            if (($p['prospetto'][$k] ?? null) !== ($o['prospetto'][$k] ?? null)) {
                $frasi[] = $nome.' erano '.$euro($p['prospetto'][$k] ?? null).', oggi sono '.$euro($o['prospetto'][$k] ?? null);
            }
        }
        // Le voci per id; il nome è quello di oggi (o di allora, per una voce che non c'è più).
        $nomi = collect([...($prima['riepilogo']['gestioni'] ?? []), ...($oggi['riepilogo']['gestioni'] ?? [])])->flatMap(fn ($g) => $g['voci'])->pluck('nome', 'voce_id');
        $voci = function (array $c) {
            $tutte = [];
            foreach ($c['riepilogo']['gestioni'] ?? [] as $g) {
                // `+`, non `flatMap`: le chiavi sono gli id delle voci.
                $tutte += $g['voci'];
            }

            return collect($tutte);
        };
        $vociPrima = $voci($p);
        $vociOggi = $voci($o);
        foreach ($vociOggi->filter(fn ($v, $id) => ($vociPrima[$id]['consuntivo'] ?? null) !== $v['consuntivo'])->take(3) as $id => $v) {
            $frasi[] = ($nomi[$id] ?? 'una voce').' era '.$euro($vociPrima[$id]['consuntivo'] ?? null).', oggi è '.$euro($v['consuntivo']);
        }
        foreach (['liquidita' => 'la liquidità', 'debiti_fornitori' => 'i debiti verso i fornitori', 'crediti_condomini' => 'i crediti verso i condòmini',
            'debiti_erario' => 'i debiti verso l\'erario'] as $k => $nome) {
            if (($p['situazione'][$k] ?? null) !== ($o['situazione'][$k] ?? null)) {
                $plurale = $k !== 'liquidita';
                $frasi[] = $nome.' '.($plurale ? 'erano' : 'era').' '.$euro($p['situazione'][$k] ?? null).', oggi '.($plurale ? 'sono' : 'è').' '.$euro($o['situazione'][$k] ?? null);
            }
        }
        $righePrima = $p['conguaglio']['righe'] ?? [];
        $righeOggi = $o['conguaglio']['righe'] ?? [];
        $tutte = collect(array_keys($righeOggi + $righePrima));
        $persone = $tutte->filter(fn ($k) => ($righePrima[$k] ?? null) != ($righeOggi[$k] ?? null))->map(fn ($k) => explode('|', $k)[1])->unique()->count();
        if ($persone > 0) {
            $quante = $tutte->map(fn ($k) => explode('|', $k)[1])->unique()->count();
            $frasi[] = 'il conguaglio è cambiato per '.($persone === 1 ? '1 persona' : $persone.' persone').' su '.$quante;
        }
        if (isset($p['registro'])) {
            $scritture = collect(array_keys($o['registro'] + $p['registro']))->filter(fn ($k) => ($p['registro'][$k] ?? null) !== ($o['registro'][$k] ?? null))->count();
            if ($scritture > 0) {
                $frasi[] = ($scritture === 1 ? '1 riga' : $scritture.' righe').' del registro di contabilità '.($scritture === 1 ? 'è nuova o cambiata' : 'sono nuove o cambiate');
            }
        }
        if (($p['esito'] ?? null) != ($o['esito'] ?? null)) {
            $frasi[] = 'l\'esito dei controlli è cambiato';
        }

        return $frasi === [] ? ['altre cifre del rendiconto'] : $frasi;
    }

    /**
     * Le cifre di una versione come si confrontano: il giorno, il prospetto, l'esito e i controlli senza quello della banca, il riepilogo per voce e per
     * gestione (per id, senza i nomi), la situazione (i fondi per cassa, senza il nome), il conguaglio (le gestioni per id, le righe per persona, unità e
     * gestione con le sole cifre, la riga del condominio), il registro di contabilità (per riga) e l'impronta del giornale. Fuori: la forma,
     * l'intestazione, il giorno e l'ora del calcolo, i nomi.
     */
    private static function daConfrontare(array $c): array
    {
        $c = json_decode(json_encode(self::senzaLaBanca($c), JSON_UNESCAPED_UNICODE), true);
        $senza = fn (array $x, array $chiavi) => array_diff_key($x, array_flip($chiavi));
        $per = function (array $elenco, callable $chiave, callable $valore): array {
            $mappa = [];
            foreach ($elenco as $x) {
                $mappa[$chiave($x)] = $valore($x);
            }
            ksort($mappa);

            return $mappa;
        };
        $out = array_intersect_key($c, array_flip(['alla', 'finito', 'prospetto', 'esito']));
        if (isset($c['controlli'])) {
            $out['controlli'] = $per($c['controlli'], fn ($x) => $x['id'], fn ($x) => [$x['esito'], $x['sinistra'] ?? null, $x['destra'] ?? null]);
        }
        if (isset($c['riepilogo'])) {
            $out['riepilogo'] = ['fuori_capitoli' => $c['riepilogo']['fuori_capitoli'] ?? [],
                'gestioni' => $per($c['riepilogo']['gestioni'] ?? [], fn ($g) => $g['gestione_id'], fn ($g) => $senza($g, ['nome', 'voci'])
                    + ['voci' => $per($g['voci'] ?? [], fn ($v) => $v['voce_id'], fn ($v) => $senza($v, ['nome']))])];
        }
        if (isset($c['situazione'])) {
            $out['situazione'] = ['fondi' => $per($c['situazione']['fondi'] ?? [], fn ($f) => $f['cassa_id'], fn ($f) => $senza($f, ['cassa']))] + $senza($c['situazione'], ['fondi']);
        }
        if (isset($c['conguaglio'])) {
            $out['conguaglio'] = [
                'gestioni' => $per($c['conguaglio']['gestioni'] ?? [], fn ($g) => $g['gestione_id'], fn ($g) => $senza($g, ['nome'])),
                'righe' => $per($c['conguaglio']['righe'] ?? [], fn ($r) => $r['gestione_id'].'|'.$r['anagrafica_id'].'|'.$r['immobile_id'],
                    fn ($r) => array_intersect_key($r, array_flip(self::CIFRE_DELLA_RIGA))),
                'da_altre_gestioni' => $per($c['conguaglio']['da_altre_gestioni'] ?? [], fn ($r) => $r['anagrafica_id'].'|'.$r['immobile_id'], fn ($r) => $senza($r, ['nome', 'unita'])),
                'condominio' => $c['conguaglio']['condominio'] ?? [],
            ];
        }
        if (isset($c['registro'])) {
            $out['registro'] = $per($c['registro'], fn ($r) => $r['riga_id'], fn ($r) => $r['impronta']);
        }
        if (isset($c['giornale']['impronta'])) {
            $out['giornale'] = ['impronta' => $c['giornale']['impronta'], 'prima' => $c['giornale']['prima'] ?? null];
        }

        return $out;
    }

    /** Le cifre senza il controllo della banca: tolto dall'elenco dei controlli e dal conto del suo esito. */
    private static function senzaLaBanca(array $c): array
    {
        $banca = collect($c['controlli'] ?? [])->firstWhere('id', 'BC');
        if ($banca === null) {
            return $c;
        }
        $c['controlli'] = array_values(array_filter($c['controlli'], fn ($x) => $x['id'] !== 'BC'));
        $campo = ['quadra' => 'tornano', 'non_quadra' => 'non_tornano', 'segnalazione' => 'da_leggere', 'non_calcolato' => 'non_calcolati'][$banca['esito']] ?? null;
        if ($campo !== null && isset($c['esito'][$campo])) {
            $c['esito'][$campo]--;
        }

        return $c;
    }

    /**
     * «nel giornale 1 registrazione nuova», «nel giornale 2 registrazioni nuove, 1 cambiata e 1 tolta».
     *
     * @param  array<int,string>  $cosa  {@see ConfrontoDelRendiconto::differenzeDelGiornale()}
     */
    private static function fraseDelGiornale(array $cosa): string
    {
        $conta = array_count_values($cosa);
        $nuove = $conta['nuova'] ?? 0;
        $cambiate = $conta['cambiata'] ?? 0;
        $tolte = $conta['tolta'] ?? 0;
        $pezzi = array_values(array_filter([
            $nuove === 0 ? null : ($nuove === 1 ? '1 registrazione nuova' : $nuove.' registrazioni nuove'),
            $cambiate === 0 ? null : $cambiate.' '.($cambiate === 1 ? 'cambiata' : 'cambiate'),
            $tolte === 0 ? null : $tolte.' '.($tolte === 1 ? 'tolta' : 'tolte'),
        ]));
        // Una registrazione cambiata o tolta dice solo «cambiata» o «tolta»: con il sostantivo davanti, se è la prima.
        if ($pezzi !== [] && $nuove === 0) {
            $pezzi[0] = preg_replace('/^(\d+) /', '$1 '.((int) $pezzi[0] === 1 ? 'registrazione ' : 'registrazioni '), $pezzi[0]);
        }
        $elenco = count($pezzi) > 1 ? implode(', ', array_slice($pezzi, 0, -1)).' e '.end($pezzi) : ($pezzi[0] ?? 'registrazioni cambiate');

        return 'nel giornale '.$elenco;
    }

    /** La frase del rifiuto di «Conserva»: che cosa è cambiato dal giorno della versione, e le due strade. */
    private static function fraseCambiate(RendicontoVersione $versione, array $cambiato): string
    {
        $giorno = self::emessaIl($versione)->format('d/m/Y');

        return 'Dal '.$giorno.', il giorno della versione '.$versione->numero.', sono cambiati i conti: '.implode('; ', $cambiato).'. Una parte conservata oggi avrebbe '
            .'la data della versione e le cifre di oggi, e non si conserva: ferma una versione nuova, o carica il PDF che hai mandato.';
    }

    /** Il giorno e l'ora di un calcolo come li scrivono le stampe del conguaglio («01/03/2027 alle 10:00»). */
    private static function calcolatoIl(CarbonInterface $quando): string
    {
        return $quando->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y \a\l\l\e H:i');
    }

    /** «Carica il PDF che hai mandato»: il file com'è, con la sua impronta, fra i documenti della versione. */
    public function caricaPdf(RendicontoVersione $versione, UploadedFile $file, ?User $utente): void
    {
        $percorso = $this->archivio->salvaConLEstensioneDelNome($file, $this->cartella($versione->condominio));
        try {
            DB::transaction(function () use ($versione, $percorso, $file, $utente) {
                $bloccata = RendicontoVersione::whereKey($versione->id)->lockForUpdate()->firstOrFail();
                $pdf = (array) $bloccata->pdf;
                $pdf['documenti'][] = $this->documento($bloccata, $percorso, (string) file_get_contents($file->getRealPath()), self::CARICATO,
                    $file->getClientOriginalName(), $utente, null);
                $bloccata->update(['pdf' => $pdf]);
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * «Registra l'approvazione» di una versione presentata.
     *
     * @param  array{data_delibera:string, numero_verbale:?string, riparto_approvato:bool, nota_approvazione:?string}  $dati
     */
    public function approva(RendicontoVersione $versione, array $dati, ?UploadedFile $verbale, ?User $utente): void
    {
        $percorso = $verbale === null ? null : $this->archivio->salvaConLEstensioneDelNome($verbale, $this->cartella($versione->condominio));
        try {
            DB::transaction(function () use ($versione, $dati, $verbale, $percorso, $utente) {
                $bloccata = RendicontoVersione::whereKey($versione->id)->lockForUpdate()->firstOrFail();
                abort_unless($bloccata->stato === RendicontoVersione::PRESENTATA, 422, 'Si approva una versione presentata.');
                // Una seconda approvazione dello stesso esercizio: l'assemblea che corregge fa una delibera nuova, e quella di prima resta, sostituita.
                RendicontoVersione::where('esercizio_id', $bloccata->esercizio_id)->where('stato', RendicontoVersione::APPROVATA)
                    ->update(['stato' => RendicontoVersione::SOSTITUITA]);
                $verbaleId = null;
                if ($verbale !== null) {
                    $verbaleId = $bloccata->documenti()->create([
                        'name' => $verbale->getClientOriginalName(),
                        'description' => 'Verbale dell\'assemblea del '.Carbon::parse($dati['data_delibera'])->format('d/m/Y').' che ha approvato il rendiconto '
                            .$bloccata->esercizio->nome,
                        'path' => $percorso, 'mime_type' => $verbale->getMimeType(), 'file_size' => $verbale->getSize(),
                        'created_by' => $utente?->id, 'is_published' => false, 'is_approved' => true,
                    ])->id;
                }
                $bloccata->update([
                    'stato' => RendicontoVersione::APPROVATA, 'data_delibera' => $dati['data_delibera'], 'numero_verbale' => $dati['numero_verbale'] ?? null,
                    'riparto_approvato' => (bool) $dati['riparto_approvato'], 'nota_approvazione' => $dati['nota_approvazione'] ?? null,
                    'verbale_documento_id' => $verbaleId, 'approvata_da' => $utente?->id, 'approvata_il' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }
    }

    /**
     * Lo stato del rendiconto in testa a ogni scheda: la versione approvata se c'è, altrimenti l'ultima fermata, altrimenti la bozza. `breve` per una
     * colonna (l'elenco degli esercizi). Dopo l'approvazione, `dopo` dice se il giornale è cambiato dal giorno della versione approvata
     * ({@see ConfrontoDelRendiconto::avvisoInTesta()}): un avviso, niente si blocca.
     *
     * @return array{stato:string, frase:string, breve:string, versione_id:?int, dopo:?string}
     */
    public static function statoInTesta(Esercizio $esercizio): array
    {
        $stato = self::statiInTesta([(int) $esercizio->id])[(int) $esercizio->id];

        return $stato + ['dopo' => $stato['stato'] === RendicontoVersione::APPROVATA ? ConfrontoDelRendiconto::avvisoInTesta($esercizio) : null];
    }

    /**
     * Lo stato del rendiconto di più esercizi, con una lettura sola.
     *
     * @param  list<int>  $ids
     * @return array<int, array{stato:string, frase:string, breve:string, versione_id:?int}>
     */
    public static function statiInTesta(array $ids): array
    {
        $tutte = $ids === [] ? collect() : RendicontoVersione::whereIn('esercizio_id', $ids)
            ->whereIn('stato', [RendicontoVersione::APPROVATA, RendicontoVersione::PRESENTATA])
            ->orderByDesc('numero')->get(['id', 'esercizio_id', 'numero', 'stato', 'calcolato_il', 'data_delibera', 'numero_verbale'])->groupBy('esercizio_id');
        $stati = [];
        foreach ($ids as $id) {
            $versioni = $tutte[$id] ?? collect();
            $approvata = $versioni->firstWhere('stato', RendicontoVersione::APPROVATA);
            $presentata = $versioni->first();
            $stati[$id] = match (true) {
                $approvata !== null => ['stato' => RendicontoVersione::APPROVATA, 'versione_id' => (int) $approvata->id,
                    'frase' => 'Approvato il '.$approvata->data_delibera->format('d/m/Y').($approvata->numero_verbale ? ' (verbale n. '.$approvata->numero_verbale.')' : '').'.',
                    'breve' => 'Approvato il '.$approvata->data_delibera->format('d/m/Y')],
                // Il programma sa quando il rendiconto è stato fermato, non quando è stato presentato all'assemblea (una convocazione si rinvia).
                $presentata !== null => ['stato' => RendicontoVersione::PRESENTATA, 'versione_id' => (int) $presentata->id,
                    'frase' => 'Fermato per l\'assemblea il '.self::emessaIl($presentata)->format('d/m/Y').'.',
                    'breve' => 'Fermato il '.self::emessaIl($presentata)->format('d/m/Y')],
                default => ['stato' => 'bozza', 'versione_id' => null, 'frase' => self::FRASE_BOZZA, 'breve' => 'Bozza'],
            };
        }

        return $stati;
    }

    /**
     * Le versioni dell'esercizio per la Sintesi, la più recente prima, senza le cifre: il numero, lo stato, il giorno, l'approvazione, i documenti.
     *
     * @return list<array<string,mixed>>
     */
    public static function elenco(Esercizio $esercizio): array
    {
        $versioni = RendicontoVersione::where('esercizio_id', $esercizio->id)->orderByDesc('numero')->get([...RendicontoVersione::COLONNE_DELLA_LISTA, 'nota']);
        $utenti = User::whereIn('id', $versioni->pluck('creata_da')->merge($versioni->pluck('approvata_da'))->filter()->unique())->pluck('name', 'id');
        $documenti = Documento::whereIn('id', $versioni->flatMap(fn ($v) => array_column((array) ($v->pdf['documenti'] ?? []), 'documento_id'))
            ->merge($versioni->pluck('verbale_documento_id'))->filter()->unique())->get(['id', 'name', 'path'])->keyBy('id');
        $nomi = $documenti->map(fn ($d) => $d->name);
        $nonTornano = self::cifreCheNonTornano($versioni->pluck('id')->all());

        return $versioni->map(fn (RendicontoVersione $v) => [
            'id' => (int) $v->id, 'numero' => $v->numero, 'stato' => $v->stato, 'emessa_il' => self::emessaIl($v)->format('Y-m-d H:i'),
            'creata_da' => $utenti[$v->creata_da] ?? null,
            'data_delibera' => $v->data_delibera?->format('Y-m-d'), 'numero_verbale' => $v->numero_verbale, 'riparto_approvato' => $v->riparto_approvato,
            'nota_approvazione' => $v->nota_approvazione, 'approvata_da' => $utenti[$v->approvata_da] ?? null,
            'parti' => $v->pdf['parti'] ?? [], 'troppo' => self::fraseSenzaPdf((array) $v->pdf),
            'parti_conservate' => self::partiConservate((array) $v->pdf),
            'cifre_non_tornano' => $nonTornano[$v->id] ?? null,
            // Il file del PDF conservato che non c'è più, o non è quello del giorno in cui è stato conservato: la frase, e «Carica il PDF che hai mandato».
            'pdf_frase' => self::fraseDelFile((array) ($v->pdf['documenti'] ?? []), $documenti),
            'documenti' => array_map(fn (array $d) => ['id' => (int) $d['documento_id'], 'parte' => $d['parte'], 'nome' => $nomi[$d['documento_id']] ?? null,
                'impronta' => $d['impronta']], (array) ($v->pdf['documenti'] ?? [])),
            'verbale' => $v->verbale_documento_id === null ? null : ['id' => (int) $v->verbale_documento_id, 'nome' => $nomi[$v->verbale_documento_id] ?? null],
            // Che cosa mancava alla nota della versione, per la finestra dell'approvazione: la nota fermata, non la bozza di oggi.
            'nota_mancanze' => self::mancanzeDellaNota($v->nota, (array) ($v->pdf['parti'] ?? [])),
        ])->values()->all();
    }

    /**
     * Il file di un PDF della versione che non c'è più sul disco dei documenti, o che non è quello conservato (l'impronta del file non è quella scritta
     * sulla versione): la frase, con i nomi. Null: i file ci sono e sono quelli. La usano la Sintesi del rendiconto e il fascicolo per il legale.
     *
     * @param  list<array<string,mixed>>  $righe  i documenti della versione (`pdf.documenti`)
     * @param  \Illuminate\Support\Collection<int,Documento>  $documenti
     */
    public static function fraseDelFile(array $righe, \Illuminate\Support\Collection $documenti): ?string
    {
        $archivio = app(ArchivioDocumenti::class);
        $mancano = [];
        $diversi = [];
        foreach ($righe as $r) {
            $d = $documenti[$r['documento_id']] ?? null;
            $contenuto = $d === null ? null : $archivio->leggi($d->path);
            $nome = '«'.($d->name ?? (FascicoloDelRendiconto::NOMI[$r['parte']] ?? $r['parte'])).'»';
            if ($contenuto === null) {
                $mancano[] = $nome;
            } elseif (hash('sha256', $contenuto) !== $r['impronta']) {
                $diversi[] = $nome;
            }
        }
        $frasi = array_filter([
            $mancano === [] ? null : (count($mancano) === 1 ? 'Il file del PDF '.$mancano[0].' non c\'è più' : 'I file dei PDF '.implode(', ', $mancano).' non ci sono più')
                .' sul disco dei documenti.',
            $diversi === [] ? null : (count($diversi) === 1 ? 'Il file del PDF '.$diversi[0].' non è quello conservato' : 'I file dei PDF '.implode(', ', $diversi).' non sono quelli conservati')
                .': la sua impronta non è quella scritta sulla versione.',
        ]);

        return $frasi === [] ? null : implode(' ', $frasi).' Carica il PDF che hai mandato ai condòmini.';
    }

    /**
     * Il contratto con la chiusura dell'esercizio: dalla versione approvata, il conguaglio per persona, unità e gestione com'era il giorno della versione,
     * la data della delibera, il verbale, e se l'assemblea ha approvato anche lo stato di riparto. Con «riparto: no» il conguaglio resta una proposta: la
     * chiusura non lo scrive. La chiusura legge da qui, non dalla lettura di oggi; il confronto con oggi lo dice {@see ConfrontoDelRendiconto}. Null: nessuna
     * versione approvata.
     *
     * @return array{versione_id:int, numero:int, forma:int, data_delibera:string, numero_verbale:?string, riparto_approvato:bool, conguaglio:array{gestioni:list<array<string,mixed>>, righe:list<array<string,mixed>>, da_altre_gestioni:list<array<string,mixed>>, condominio:array<string,int>}}|null
     */
    public static function perLaChiusura(Esercizio $esercizio): ?array
    {
        $v = RendicontoVersione::where('esercizio_id', $esercizio->id)->where('stato', RendicontoVersione::APPROVATA)->orderByDesc('numero')->first();
        if ($v === null) {
            return null;
        }
        $g = (array) ($v->cifre['conguaglio'] ?? []);
        $scegli = fn (array $righe, array $chiavi) => array_values(array_map(fn ($r) => array_intersect_key((array) $r, array_flip($chiavi)), $righe));

        return [
            'versione_id' => (int) $v->id, 'numero' => (int) $v->numero, 'forma' => (int) $v->forma, 'data_delibera' => $v->data_delibera->format('Y-m-d'),
            'numero_verbale' => $v->numero_verbale, 'riparto_approvato' => (bool) $v->riparto_approvato,
            'conguaglio' => [
                'gestioni' => $scegli((array) ($g['gestioni'] ?? []), ['gestione_id', 'nome', 'tipo', 'in_corso', 'opera_finita', 'non_calcolabile', 'totali']),
                'righe' => $scegli((array) ($g['righe'] ?? []), ['gestione_id', 'anagrafica_id', 'immobile_id', 'nome', 'unita', 'a', 'personali', 'conguaglio', 'posizione', 'saldo',
                    'non_disponibile']),
                'da_altre_gestioni' => array_values((array) ($g['da_altre_gestioni'] ?? [])),
                'condominio' => (array) ($g['condominio'] ?? []),
            ],
        ];
    }

    /** Le parti conservate una per una (non il fascicolo intero né il PDF caricato). */
    private static function partiConservate(array $pdf): array
    {
        return array_values(array_column(array_filter((array) ($pdf['documenti'] ?? []), fn ($d) => ! in_array($d['parte'], [self::FASCICOLO, self::CARICATO], true)), 'parte'));
    }

    /**
     * Perché il PDF della versione non c'è intero: il fascicolo era oltre il tetto, e né il fascicolo né il PDF caricato ci sono; finché restano parti da
     * conservare. Con qualche parte già conservata la frase dice quante. Null: il PDF c'è.
     */
    public static function fraseSenzaPdf(array $pdf): ?string
    {
        $documenti = (array) ($pdf['documenti'] ?? []);
        $intero = collect($documenti)->contains(fn ($d) => in_array($d['parte'], [self::FASCICOLO, self::CARICATO], true));
        $parti = (array) ($pdf['parti'] ?? []);
        $conservate = array_values(array_intersect($parti, self::partiConservate($pdf)));
        if ($intero || ($pdf['troppo'] ?? null) === null || array_diff($parti, $conservate) === []) {
            return null;
        }
        if ($conservate === []) {
            return self::FRASE_SENZA_PDF;
        }
        $n = count($conservate);

        return 'Il PDF del fascicolo non è conservato intero: '.($n === 1 ? 'conservata 1 parte' : 'conservate '.$n.' parti').' su '.count($parti)
            .'. Conserva le altre una per una, o carica il PDF che hai mandato.';
    }

    /**
     * Le versioni le cui cifre fermate non rispettano l'uguaglianza del conguaglio («uscite + voci ferme = persone + non attribuite»), con la frase per
     * l'amministratore: è un errore del programma, e il PDF è conservato com'è. Legge dalle cifre la sola parte delle gestioni (non le righe delle persone).
     *
     * @param  list<int>  $ids
     * @return array<int,string> versione => frase
     */
    public static function cifreCheNonTornano(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $frasi = [];
        $righe = DB::table('rendiconto_versioni')->whereIn('id', $ids)->selectRaw("id, json_extract(cifre, '$.conguaglio.gestioni') as gestioni")->get();
        foreach ($righe as $r) {
            $voci = [];
            foreach ((array) json_decode((string) $r->gestioni, true) as $g) {
                foreach ((array) ($g['invariante'] ?? []) as $conto) {
                    $voci[] = $conto === '*' ? ['gestione', (string) $g['nome']] : ['voce', (int) $conto];
                }
            }
            if ($voci === []) {
                continue;
            }
            $nomi = DB::table('conti')->whereIn('id', array_column(array_filter($voci, fn ($v) => $v[0] === 'voce'), 1))->pluck('nome', 'id');
            $dove = array_map(fn ($v) => $v[0] === 'voce' ? '«'.($nomi[$v[1]] ?? 'voce '.$v[1]).'»' : 'la gestione «'.$v[1].'»', $voci);
            $frasi[(int) $r->id] = 'Le cifre del conguaglio fermate non tornano per '.implode(', ', $dove).': uscite e voci ferme non sono uguali alle spese delle persone '
                .'più quelle non attribuite. È un errore del programma, non dei tuoi dati: il PDF è conservato com\'è. Segnalalo prima dell\'assemblea.';
        }

        return $frasi;
    }

    /**
     * «Elimina la versione»: solo una versione presentata e mai approvata, con i suoi documenti e i loro file. Le approvate e le sostituite restano: sono
     * delibere dell'assemblea. Restituisce la frase del rifiuto, o null.
     */
    public function elimina(RendicontoVersione $versione): ?string
    {
        if ($versione->stato !== RendicontoVersione::PRESENTATA) {
            return 'La versione '.$versione->numero.' è '.($versione->stato === RendicontoVersione::APPROVATA ? 'approvata' : 'sostituita da un\'approvazione successiva')
                .': resta, con il suo PDF, ed è la delibera dell\'assemblea. Si elimina solo una versione fermata e mai approvata.';
        }
        $percorsi = [];
        DB::transaction(function () use ($versione, &$percorsi) {
            $bloccata = RendicontoVersione::whereKey($versione->id)->lockForUpdate()->firstOrFail();
            abort_unless($bloccata->stato === RendicontoVersione::PRESENTATA, 422);
            $percorsi = $bloccata->documenti()->pluck('path')->all();
            $bloccata->documenti()->delete();
            $bloccata->delete();
        });
        // I file dopo le righe: se la cancellazione si ferma a metà, le righe tornano e i file ci sono ancora.
        foreach ($percorsi as $percorso) {
            $this->archivio->elimina($percorso);
        }

        return null;
    }

    /**
     * Le versioni che trattengono un esercizio o un condominio, dette una per una per il rifiuto dell'eliminazione: quale versione, di quale esercizio,
     * approvata o fermata, e dove si guarda. Una versione fermata e mai approvata si elimina dalla Sintesi; un'approvata o una sostituita resta.
     *
     * @return list<string>
     */
    public static function perIlRifiuto(\Illuminate\Database\Eloquent\Builder $versioni, bool $conEsercizio): array
    {
        return $versioni->with('esercizio:id,nome')->orderBy('esercizio_id')->orderBy('numero')
            ->get(['id', 'esercizio_id', 'numero', 'stato', 'calcolato_il', 'data_delibera'])
            ->map(fn (RendicontoVersione $v) => 'la versione '.$v->numero.' del rendiconto'.($conEsercizio ? ' «'.$v->esercizio?->nome.'»' : '').', '.match ($v->stato) {
                RendicontoVersione::PRESENTATA => 'fermata per l\'assemblea il '.self::emessaIl($v)->format('d/m/Y').' e mai approvata: si elimina dalla Sintesi del rendiconto',
                RendicontoVersione::APPROVATA => 'approvata il '.$v->data_delibera?->format('d/m/Y').': resta, ed è nella Sintesi del rendiconto',
                default => 'approvata il '.$v->data_delibera?->format('d/m/Y').' e poi sostituita: resta, ed è nella Sintesi del rendiconto',
            })->values()->all();
    }

    /** @return list<string> */
    private static function mancanzeDellaNota(?array $nota, array $parti): array
    {
        if ($nota === null || ($nota['stampa']['vuota'] ?? true) || ! in_array('nota', $parti, true)) {
            return ['Il fascicolo di questa versione non contiene la nota sintetica esplicativa.'];
        }

        return NotaSinteticaDelRendiconto::mancanze(NotaSinteticaDelRendiconto::normalizza((array) ($nota['bozza'] ?? [])), []);
    }

    /** Il valore di una variabile, che la variabile non tiene più: chi lo riceve è il solo ad averlo, e liberandolo lo toglie dalla memoria. */
    private static function cedi(?array &$valore): ?array
    {
        $ceduto = $valore;
        $valore = null;

        return $ceduto;
    }

    /** Il giorno e l'ora della versione nel fuso dell'amministratore: la data di emissione di ogni sua parte. */
    public static function emessaIl(RendicontoVersione $versione): CarbonInterface
    {
        return $versione->calcolato_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'));
    }

    private function cartella(Condominio $condominio): string
    {
        return ArchivioDocumenti::CARTELLA.'/'.$condominio->id.'/'.self::CARTELLA;
    }

    /**
     * Il `Documento` della versione per un PDF già scritto sul disco: legato alla versione, mai nell'archivio né nel portale. Restituisce la sua riga
     * per il JSON della versione, con l'impronta del contenuto.
     *
     * @return array{documento_id:int, parte:string, impronta:string, byte:int, pagine:?int}
     */
    private function documento(RendicontoVersione $versione, string $percorso, string $contenuto, string $parte, string $nome, ?User $utente, ?int $pagine): array
    {
        $esercizio = $versione->esercizio;
        $cosa = match ($parte) {
            self::FASCICOLO => 'Fascicolo del rendiconto',
            self::CARICATO => 'PDF caricato del rendiconto',
            default => (FascicoloDelRendiconto::NOMI[$parte] ?? $parte).', parte del rendiconto',
        };
        $documento = $versione->documenti()->create([
            'name' => $parte === self::CARICATO ? $nome : PdfService::nomeFile('rendiconto', (string) $versione->condominio->nome, $esercizio->data_inizio->format('Y'),
                'versione-'.$versione->numero.($parte === self::FASCICOLO ? '' : '-'.$parte)),
            'description' => $cosa.' '.$esercizio->nome.', versione '.$versione->numero.' del '.self::emessaIl($versione)->format('d/m/Y'),
            'path' => $percorso, 'mime_type' => 'application/pdf', 'file_size' => strlen($contenuto),
            'created_by' => $utente?->id, 'is_published' => false, 'is_approved' => true,
        ]);

        return ['documento_id' => (int) $documento->id, 'parte' => $parte, 'impronta' => hash('sha256', $contenuto), 'byte' => strlen($contenuto), 'pagine' => $pagine];
    }
}
