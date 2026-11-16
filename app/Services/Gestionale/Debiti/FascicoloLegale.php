<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Debiti;

use App\Exceptions\Gestionale\FascicoloNonPreparabileException;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\EstrattoContoDellaPersona;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\Riparto\CompetenzaDelPiano;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Il fascicolo per il legale di una persona: un archivio .zip con l'indice in PDF, il prospetto delle rate non versate, gli estratti conto
 * degli esercizi dei piani, le lettere con il PDF conservato e le loro prove, il rendiconto approvato con il verbale, l'accordo di rientro (la stampa con il
 * calendario, la scansione firmata, la decadenza o la chiusura). L'indice dice che cosa c'è e che cosa manca; mai che basta per agire. Va al legale: le
 * istruzioni all'amministratore (dove si aggiunge ciò che manca) stanno nella finestra, non nel PDF. Lo prepara chi vede l'estratto conto e il registro delle lettere:
 * nessun permesso nuovo, nessuna numerazione nuova delle lettere (si nominano con il giorno, l'ora e il numero della riga del registro).
 *
 * **Che cosa ci metti**: di serie tutte le rate scadute della persona e tutte le parti; l'amministratore toglie un esercizio, una rata o una
 * parte. L'anteprima ({@see anteprima()}) segna ogni pezzo, ogni fatto e ogni mancanza con la parte (`parte`, null: sempre) e le rate (`rate`, null: sempre;
 * altrimenti c'è se almeno una è scelta) a cui appartiene: la pagina filtra con questi segni, senza un'altra lettura, e il server prepara con la stessa regola
 * ({@see dentro()}). L'indice e il prospetto dicono solo ciò che è dentro; il registro della persona tiene anche ciò che è stato tolto.
 *
 * **Le impronte.** Il fascicolo legge ogni file (gli serve per metterlo nell'archivio) e ne decide lo stato fra quattro: è quello conservato (l'impronta è la
 * stessa), non è quello conservato, non c'è più sul disco, non ha un'impronta conservata con cui confrontarlo. Il file che non c'è più non entra nell'archivio;
 * l'indice lo dice, e la mancanza anche.
 *
 * **L'archivio** si scrive a pezzi ({@see ArchivioAPezzi}): ogni file va sul disco appena è letto o fatto, e la memoria tiene un documento alla volta. Ogni nome
 * passa da {@see InviiPerPersona::nomeLibero()}. Nessun file resta nella cartella temporanea: i pezzi si tolgono quando lo .zip è scritto, lo .zip dopo l'invio,
 * e tutto dopo un problema. L'indice e il prospetto di quel giorno si conservano fra i documenti della riga della persona ({@see RispostePromemoria::fascicolo()}),
 * fuori dall'archivio dei documenti e dal portale: sono i due file che cambiano a ogni incasso.
 *
 * Il peso (misurato il 15/11/2026, Apple M1 Pro, SQLite in memoria): una persona con 30 lettere con le prove e il rendiconto di 22 pagine (2 MB) sta nei
 * numeri scritti in `tests/Feature/Gestionale/FascicoloMisuraTest.php`.
 */
final class FascicoloLegale
{
    public const LETTERE = 'lettere';
    public const ESTRATTI = 'estratti';
    public const RENDICONTO = 'rendiconto';
    public const ACCORDO = 'accordo';

    /** Le parti che l'amministratore può togliere, nell'ordine della finestra. */
    public const PARTI = [self::LETTERE, self::ESTRATTI, self::RENDICONTO, self::ACCORDO];

    /** Gli stati dell'impronta di un file conservato. */
    public const UGUALE = 'uguale';
    public const DIVERSA = 'diversa';
    public const SPARITO = 'sparito';
    public const SENZA = 'senza';

    public const FILE_INDICE = '00 Indice.pdf';
    public const FILE_PROSPETTO = '01 Rate non versate.pdf';

    public const TITOLO = 'Fascicolo per il legale';
    public const FRASE_IN_FONDO = 'Questo indice dice che cosa il programma conserva per questa persona e che cosa non trova. Se basta per agire lo valuta il legale.';
    /** Le due frasi nel PDF dell'indice, che va al legale: dicono i documenti dello studio, non il programma. */
    public const FRASE_IN_FONDO_DELL_INDICE = 'Questo indice dice quali documenti lo studio conserva per questa persona e quali mancano. Se bastano per agire lo valuta il legale.';
    public const FRASE_NESSUNA_MANCANZA_DELL_INDICE = 'Fra i documenti dello studio per queste rate non risultano mancanze.';
    public const FRASE_DATI_PERSONALI = 'Contiene dati personali di una persona: si consegna a chi la segue per il condominio.';
    public const FRASE_SALDO_ESTRATTO = 'Il saldo dell\'estratto conto comprende anche le rate non ancora scadute e il credito: non è lo scaduto.';
    public const FRASE_NESSUNA_LETTERA = 'Nessuna lettera a questa persona nel registro.';
    public const FRASE_NESSUNA_MANCANZA = 'Il programma non trova mancanze fra ciò che conserva per queste rate.';
    public const FRASE_SCEGLI_UNA_RATA = 'Scegli almeno una rata.';
    public const FRASE_RATA_NON_SCADUTA = 'Questa rata non è fra le rate scadute della persona.';
    public const FRASE_PARTE = 'Questa parte non è fra quelle del fascicolo.';
    public const FRASE_SENZA_ZIP = 'Su questo server PHP non ha l\'estensione zip: il fascicolo non si può fare. Chiedi a chi gestisce l\'installazione di attivarla.';
    public const SENZA_DATA = 'Senza la data dei sei mesi';

    /** Le frasi dei quattro stati dell'impronta: la frase per l'indice e la parola breve per la finestra. */
    public const FRASE_UGUALE = 'Il file è quello conservato il %s: l\'impronta è la stessa.';
    public const FRASE_DIVERSA = 'Il file non è quello conservato il %s: la sua impronta non è quella scritta %s.';
    public const FRASE_SPARITO = 'Il file non c\'è più sul disco dei documenti.';
    public const FRASE_SPARITO_DEL_REGISTRO = 'Il file non c\'è più sul disco dei documenti: resta la riga del registro.';
    public const FRASE_SENZA = 'Nessuna impronta conservata: il file non si può confrontare.';
    public const BREVI = [self::UGUALE => 'il file è quello conservato', self::DIVERSA => 'il file non è quello conservato', self::SPARITO => 'il file non c\'è più',
        self::SENZA => 'nessuna impronta conservata'];

    /** Il nome di ogni tipo di lettera, il suo articolo e la sua desinenza (la diffida è stampata, il sollecito è stampato). */
    private const TIPI = [
        'promemoria' => ['Promemoria', 'il', 'o'], 'sollecito' => ['Sollecito', 'il', 'o'], 'diffida' => ['Diffida', 'la', 'a'], 'messa_in_mora' => ['Messa in mora', 'la', 'a'],
    ];

    public function __construct(private readonly ElencoRateScadute $elenco, private readonly ArchivioDocumenti $archivio,
        private readonly EstrattoContoDellaPersona $estratti, private readonly RispostePromemoria $risposte) {}

    /** Un pezzo, un fatto o una mancanza è dentro: la sua parte è scelta (o non ne ha), e almeno una delle sue rate è scelta (o non ne ha). */
    public static function dentro(array $x, array $scelte, array $parti): bool
    {
        if (($x['parte'] ?? null) !== null && ! in_array($x['parte'], $parti, true)) {
            return false;
        }
        $rate = $x['rate'] ?? null;

        return $rate === null || array_intersect($rate, $scelte) !== [];
    }

    /**
     * L'anteprima per la finestra, in sola lettura: le rate scadute della persona per esercizio dei sei mesi, le parti che ci sono, i pezzi dell'archivio con lo
     * stato delle impronte, i fatti, le mancanze, l'ultimo fascicolo preparato. Ogni pezzo, fatto e mancanza porta i suoi segni (`parte`, `rate`).
     *
     * @return array<string, mixed>
     */
    public function anteprima(Condominio $condominio, Anagrafica $persona, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $rate = $this->rateDellaPersona($condominio, $persona, $oggi);
        $l = $this->leggi($condominio, $persona, $rate, $oggi);
        $ultimo = RispostaPromemoria::where('chiave', RispostePromemoria::chiave(RispostePromemoria::PERSONA, (int) $condominio->id, null, (int) $persona->id))->first();
        $fascicolo = $ultimo?->dati['fascicolo'] ?? null;

        return [
            'oggi' => $oggi,
            'persona' => ['id' => (int) $persona->id, 'nome' => (string) $persona->nome, 'unita' => array_values(array_unique(array_filter(array_column($rate, 'unita'))))],
            'condominio' => (string) $condominio->nome,
            'esercizi' => self::esercizi($rate),
            'parti' => $l['parti'],
            'pezzi' => $l['pezzi'],
            'fatti' => $l['fatti'],
            'mancanze' => $l['mancanze'],
            'ultimo' => $fascicolo === null ? null : ['giorno' => (string) $fascicolo['giorno'], 'utente' => (string) ($fascicolo['utente'] ?? '')],
        ];
    }

    /**
     * «Scarica il fascicolo»: le rate scelte (le chiavi «rata|unità», tutte fra le rate scadute della persona) e le parti scelte; l'archivio .zip in un file
     * temporaneo, che chi lo manda cancella dopo l'invio. Scrive la riga `fascicolo` nel registro della persona e conserva l'indice e il prospetto.
     *
     * @param list<string> $scelte
     * @param list<string> $parti
     * @return array{zip: string, nome: string}
     *
     * @throws ValidationException una rata che non è fra le scadute della persona, nessuna rata, una parte che non c'è
     */
    public function prepara(Condominio $condominio, Anagrafica $persona, array $scelte, array $parti, ?User $utente, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $scelte = array_values(array_unique(array_map('strval', $scelte)));
        $parti = array_values(array_unique(array_map('strval', $parti)));
        if ($scelte === []) {
            throw ValidationException::withMessages(['rate' => self::FRASE_SCEGLI_UNA_RATA]);
        }
        if (array_diff($parti, self::PARTI) !== []) {
            throw ValidationException::withMessages(['parti' => self::FRASE_PARTE]);
        }
        $rate = $this->rateDellaPersona($condominio, $persona, $oggi);
        if (array_diff($scelte, array_column($rate, 'chiave')) !== []) {
            throw ValidationException::withMessages(['rate' => self::FRASE_RATA_NON_SCADUTA]);
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new FascicoloNonPreparabileException(self::FRASE_SENZA_ZIP);
        }
        // L'ordine delle rate scelte è quello del prospetto (per scadenza), non quello della richiesta.
        $scelte = array_values(array_intersect(array_column($rate, 'chiave'), $scelte));

        $archivio = new ArchivioAPezzi(FascicoloNonPreparabileException::class);
        $salvati = [];
        try {
            // I file conservati vanno nell'archivio mentre si leggono: ognuno è letto una volta, e la memoria ne tiene uno.
            $l = $this->leggi($condominio, $persona, $rate, $oggi, function (array $pezzo, string $contenuto) use ($archivio, $scelte, $parti) {
                if (self::dentro($pezzo, $scelte, $parti)) {
                    $archivio->aggiungi($pezzo['file'], $contenuto);
                }
            });
            $pezzi = array_values(array_filter($l['pezzi'], fn ($p) => self::dentro($p, $scelte, $parti)));
            $fatti = array_values(array_filter($l['fatti'], fn ($f) => self::dentro($f, $scelte, $parti)));
            $mancanze = array_values(array_filter($l['mancanze'], fn ($m) => self::dentro($m, $scelte, $parti)));
            $dentro = array_values(array_filter($rate, fn ($r) => in_array($r['chiave'], $scelte, true)));
            $cifre = self::cifre($dentro);

            $prospetto = $this->prospetto($condominio, $persona, $dentro, $cifre, in_array(self::ACCORDO, $parti, true), $oggi);
            $archivio->aggiungi(self::FILE_PROSPETTO, $prospetto);
            foreach ($pezzi as $p) {
                if ($p['tipo'] === 'estratto') {
                    $archivio->aggiungi($p['file'], $this->estratti->pdf($condominio, Esercizio::findOrFail($p['esercizio_id']), $persona));
                    gc_collect_cycles();
                }
                if ($p['tipo'] === 'stampa_accordo') {
                    $archivio->aggiungi($p['file'], app(AccordiRientro::class)->stampa($condominio, AccordoRientro::findOrFail($p['accordo_id'])));
                }
            }
            $indice = $this->indice($condominio, $persona, array_values(array_unique(array_filter(array_column($rate, 'unita')))), $pezzi, $fatti, $mancanze, $cifre, $utente, $oggi);
            $archivio->aggiungi(self::FILE_INDICE, $indice);
            $zip = $archivio->scrivi();

            // Il registro della persona: che cosa ha ricevuto il legale quel giorno, e che cosa è stato tolto. L'indice e il prospetto fra i suoi documenti.
            $cartella = ArchivioDocumenti::CARTELLA . '/' . $condominio->id . '/fascicoli';
            $salvati[] = $this->archivio->salvaContenuto($indice, 'pdf', $cartella);
            $salvati[] = $this->archivio->salvaContenuto($prospetto, 'pdf', $cartella);
            $nome = Str::slug((string) $persona->nome);
            $dati = [
                'giorno' => $oggi, 'cifra' => $cifre['totale'], 'esercizi' => $cifre['esercizi'], 'rate' => $scelte,
                'file' => array_values(array_map(fn ($p) => ['nome' => $p['file'], 'tipo' => $p['tipo'], 'impronta' => $p['impronta']['stato'] ?? null,
                    'cifre' => $p['impronta']['cifre'] ?? null], array_filter($pezzi, fn ($p) => $p['file'] !== null && ($p['impronta']['stato'] ?? null) !== self::SPARITO))),
                'mancanze' => array_column($mancanze, 'frase'),
                'tolti' => ['rate' => array_values(array_diff(array_column($rate, 'chiave'), $scelte)), 'parti' => array_values(array_diff($l['parti'], $parti))],
            ];
            $this->risposte->fascicolo($condominio, (int) $persona->id, $oggi, $utente, $dati, function (RispostaPromemoria $r) use ($salvati, $indice, $prospetto, $utente, $oggi, $nome) {
                $giorno = Carbon::parse($oggi)->format('d/m/Y');

                return [
                    (int) $r->documenti()->create(['name' => 'fascicolo-legale-indice-' . $nome . '-' . $oggi . '.pdf', 'description' => 'Indice del fascicolo per il legale del ' . $giorno,
                        'path' => $salvati[0], 'mime_type' => 'application/pdf', 'file_size' => strlen($indice), 'created_by' => $utente?->id, 'is_published' => false,
                        'is_approved' => true])->id,
                    (int) $r->documenti()->create(['name' => 'fascicolo-legale-rate-non-versate-' . $nome . '-' . $oggi . '.pdf',
                        'description' => 'Rate non versate del fascicolo per il legale del ' . $giorno, 'path' => $salvati[1], 'mime_type' => 'application/pdf',
                        'file_size' => strlen($prospetto), 'created_by' => $utente?->id, 'is_published' => false, 'is_approved' => true])->id,
                ];
            });
        } catch (\Throwable $e) {
            $archivio->togli();
            foreach ($salvati as $percorso) {
                $this->archivio->elimina($percorso);
            }

            throw $e;
        }

        return ['zip' => $zip, 'nome' => Str::replaceLast('.pdf', '.zip', PdfService::nomeFile('fascicolo-legale', (string) $condominio->nome, $oggi, $nome))];
    }

    /**
     * I fascicoli preparati per la persona, il più recente prima: il giorno e l'ora, chi, la cifra, e l'indice e il prospetto conservati di quel giorno.
     *
     * @return list<array<string, mixed>>
     */
    public static function preparati(Condominio $condominio, Anagrafica $persona): array
    {
        $r = RispostaPromemoria::where('chiave', RispostePromemoria::chiave(RispostePromemoria::PERSONA, (int) $condominio->id, null, (int) $persona->id))->first();
        if ($r === null) {
            return [];
        }
        $documenti = $r->documenti()->get(['id', 'name', 'path'])->keyBy('id');
        $righe = array_values(array_filter(\App\Services\Gestionale\RegistroDelGesto::perLaPagina((array) ($r->registro ?? [])), fn ($x) => $x['tipo'] === 'fascicolo'));

        return array_map(fn ($x) => [
            'quando' => $x['quando'], 'utente' => $x['utente'], 'cifra' => (int) ($x['dati']['cifra'] ?? 0), 'mancanze' => count((array) ($x['dati']['mancanze'] ?? [])),
            // Nell'ordine in cui il fascicolo li conserva: l'indice, poi il prospetto delle rate non versate.
            'documenti' => array_values(array_filter(array_map(fn ($id, $n) => isset($documenti[$id])
                ? ['id' => (int) $id, 'nome' => (string) $documenti[$id]->name, 'etichetta' => $n === 0 ? 'Indice' : 'Rate non versate'] : null,
                (array) ($x['dati']['documenti'] ?? []), array_keys((array) ($x['dati']['documenti'] ?? []))))),
        ], $righe);
    }

    /**
     * Le rate scadute e non versate della persona oggi, dalla riga dell'elenco «Rate scadute» (la lettura comune dei debiti), nell'ordine delle scadenze: la
     * chiave, il nome, l'unità, la scadenza, il residuo, il piano, la data dei sei mesi con il suo esercizio, la parte in un accordo di rientro.
     *
     * @return list<array<string, mixed>>
     */
    private function rateDellaPersona(Condominio $condominio, Anagrafica $persona, string $oggi): array
    {
        $riga = $this->elenco->righe($condominio, $this->elenco->filtriNormalizzati(['alla' => $oggi, 'anagrafica_id' => $persona->id]), [(int) $persona->id], false)->first();

        return array_map(function (array $d) {
            $sm = $d['sei_mesi'] ?? null;
            $es = ($sm['piu_vicina'] ?? null) !== null ? ($sm['piu_vicina_esercizio'] ?? null) : null;

            return [
                'chiave' => AccordiRientro::chiave($d['rata_id'], $d['immobile_id']),
                'nome' => $d['addebito'] !== null ? (string) $d['addebito']
                    : trim($d['piano'] . ', ' . ((int) $d['numero_rata'] === 0 ? 'rata 0 (pregresso)' : 'rata ' . $d['numero_rata']), ', '),
                'piano' => (string) $d['piano'], 'piano_rate_id' => $d['piano_rate_id'], 'numero_rata' => (int) $d['numero_rata'], 'addebito' => $d['addebito'],
                'unita' => $d['unita'], 'scadenza' => substr((string) $d['scadenza'], 0, 10), 'residuo' => (int) $d['residuo'],
                'sei_mesi' => $es === null ? null : ['esercizio_id' => (int) $es['id'], 'esercizio' => (string) $es['nome'], 'data' => (string) $sm['piu_vicina']],
                'accordo' => $d['accordo'] === null || (int) ($d['accordo']['in_accordo'] ?? 0) <= 0 ? null
                    : ['data' => (string) $d['accordo']['data_accordo'], 'in_accordo' => (int) $d['accordo']['in_accordo']],
            ];
        }, $riga === null ? [] : $riga['rate']);
    }

    /**
     * Le rate per esercizio dei sei mesi, nell'ordine delle date; quelle senza la data in fondo.
     *
     * @param list<array<string, mixed>> $rate
     * @return list<array<string, mixed>>
     */
    private static function esercizi(array $rate): array
    {
        $gruppi = [];
        foreach ($rate as $r) {
            $chiave = $r['sei_mesi'] === null ? 'senza' : 'esercizio-' . $r['sei_mesi']['esercizio_id'];
            $gruppi[$chiave] ??= ['chiave' => $chiave, 'id' => $r['sei_mesi']['esercizio_id'] ?? null, 'nome' => $r['sei_mesi']['esercizio'] ?? self::SENZA_DATA,
                'data' => $r['sei_mesi']['data'] ?? null, 'rate' => []];
            $gruppi[$chiave]['rate'][] = ['chiave' => $r['chiave'], 'nome' => $r['nome'], 'unita' => $r['unita'], 'scadenza' => $r['scadenza'], 'residuo' => $r['residuo']];
        }
        uasort($gruppi, fn ($a, $b) => [$a['data'] === null ? 1 : 0, $a['data'] ?? '', $a['id'] ?? 0] <=> [$b['data'] === null ? 1 : 0, $b['data'] ?? '', $b['id'] ?? 0]);

        return array_values($gruppi);
    }

    /**
     * Le cifre delle rate: il totale e, per esercizio dei sei mesi, quelle rimaste.
     *
     * @param list<array<string, mixed>> $rate
     * @return array{totale: int, rate: int, esercizi: list<array{nome: string, cifra: int, data: ?string}>}
     */
    private static function cifre(array $rate): array
    {
        return [
            'totale' => (int) array_sum(array_column($rate, 'residuo')),
            'rate' => count($rate),
            'esercizi' => array_map(fn ($g) => ['nome' => $g['nome'], 'cifra' => (int) array_sum(array_column($g['rate'], 'residuo')), 'data' => $g['data']], self::esercizi($rate)),
        ];
    }

    /**
     * Che cosa c'è e che cosa manca, per tutte le rate della persona e tutte le parti, con i segni: i pezzi dell'archivio con i nomi (dati sull'insieme intero,
     * così non cambiano con le scelte) e lo stato delle impronte, i fatti, le mancanze, le parti che ci sono. `$conIlFile` riceve ogni file conservato letto.
     *
     * @param list<array<string, mixed>> $rate
     * @return array{parti: list<string>, pezzi: list<array<string, mixed>>, fatti: list<array<string, mixed>>, mancanze: list<array<string, mixed>>}
     */
    private function leggi(Condominio $condominio, Anagrafica $persona, array $rate, string $oggi, ?\Closure $conIlFile = null): array
    {
        $usati = [];
        $nome = function (string $base, string $estensione = 'pdf') use (&$usati) {
            return InviiPerPersona::nomeLibero(EmissioneRicevute::nomeFileDellaPersona($base), $usati) . '.' . $estensione;
        };
        $tutte = array_column($rate, 'chiave');
        $esito = ['pezzi' => [], 'fatti' => [], 'mancanze' => []];
        $pezzo = fn (string $tipo, ?string $file, string $cosa, ?string $data, ?array $impronta, ?string $parte, ?array $diRate, array $altro = []) => $altro
            + ['tipo' => $tipo, 'file' => $file, 'cosa' => $cosa, 'data' => $data, 'impronta' => $impronta, 'parte' => $parte, 'rate' => $diRate];
        $fatto = fn (string $frase, ?string $parte, ?array $diRate) => ['frase' => $frase, 'parte' => $parte, 'rate' => $diRate];
        // Una mancanza ha la frase della finestra, con che cosa fare, e quella dell'indice, che va al legale: il fatto, senza le istruzioni all'amministratore.
        $mancanza = fn (string $frase, ?string $parte, ?array $diRate, ?array $azione = null, ?string $perIlLegale = null) => ['frase' => $frase, 'azione' => $azione,
            'parte' => $parte, 'rate' => $diRate, 'per_il_legale' => $perIlLegale ?? $frase];
        // Un file conservato: letto una volta, lo stato dell'impronta, e il contenuto a chi lo mette nell'archivio.
        $leggiIl = function (array $p, ?Documento $d, ?string $registrata, string $dove) use ($conIlFile) {
            $contenuto = $d === null ? null : $this->archivio->leggi($d->path);
            $p['impronta'] = self::impronta($contenuto, $registrata, $d?->created_at, $dove);
            if ($contenuto !== null && $conIlFile !== null) {
                $conIlFile($p, $contenuto);
            }

            return $p;
        };
        $estratto = route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $condominio->id, 'anagrafica' => $persona->id, 'scheda' => 'corrispondenza']);

        $esito['pezzi'][] = $pezzo('indice', $nome('00 Indice'), 'L\'indice: che cosa c\'è e che cosa manca', $oggi, null, null, null);
        $esito['pezzi'][] = $pezzo('prospetto', $nome('01 Rate non versate'), 'Rate non versate', $oggi, null, null, null);

        // Gli estratti conto: uno per esercizio dei piani delle rate, non per esercizio dei sei mesi.
        $piani = PianoRate::whereIn('id', array_values(array_unique(array_filter(array_column($rate, 'piano_rate_id')))) ?: [0])->get()->keyBy('id');
        $competenza = app(CompetenzaDelPiano::class);
        $eserciziDeiPiani = $piani->map(fn (PianoRate $p) => $competenza->esercizioDelPiano($p));
        $perEsercizio = [];
        foreach ($rate as $r) {
            $e = $eserciziDeiPiani[$r['piano_rate_id']] ?? null;
            if ($e !== null) {
                $perEsercizio[(int) $e->id] ??= ['esercizio' => $e, 'rate' => []];
                $perEsercizio[(int) $e->id]['rate'][] = $r['chiave'];
            }
        }
        uasort($perEsercizio, fn ($a, $b) => [(string) $a['esercizio']->data_inizio, $a['esercizio']->id] <=> [(string) $b['esercizio']->data_inizio, $b['esercizio']->id]);
        foreach ($perEsercizio as $id => $x) {
            $anno = self::anno($x['esercizio']);
            $esito['pezzi'][] = $pezzo('estratto', $nome('02 Estratto conto ' . $anno), 'Estratto conto ' . $anno, $oggi, null, self::ESTRATTI, $x['rate'], ['esercizio_id' => (int) $id]);
        }
        if ($perEsercizio !== []) {
            $esito['fatti'][] = $fatto(self::FRASE_SALDO_ESTRATTO, self::ESTRATTI, array_values(array_merge(...array_column($perEsercizio, 'rate'))));
        }

        // Le delibere dei piani: un fatto, o la mancanza con il piano rate. Un addebito a una persona sola non ha una delibera.
        foreach ($piani as $p) {
            if ($p->natura === PianoRate::NATURA_ADDEBITI) {
                continue;
            }
            $diRate = array_values(array_column(array_filter($rate, fn ($r) => (int) $r['piano_rate_id'] === (int) $p->id), 'chiave'));
            if ($p->data_delibera_assemblea !== null) {
                $esito['fatti'][] = $fatto('Piano «' . $p->nome . '»: delibera del ' . $p->data_delibera_assemblea->format('d/m/Y')
                    . (filled($p->numero_verbale) ? ' (verbale n. ' . $p->numero_verbale . ')' : '') . '.', null, $diRate);
            } else {
                $esercizio = $eserciziDeiPiani[$p->id] ?? null;
                $esito['mancanze'][] = $mancanza('Il piano «' . $p->nome . '» non ha la data della delibera: scrivila nel piano rate.', null, $diRate, $esercizio === null ? null
                    : ['etichetta' => 'Apri il piano rate', 'url' => route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $condominio->id, 'esercizio' => $esercizio->id,
                        'pianoRate' => $p->id])], 'Il piano «' . $p->nome . '» non ha la data della delibera.');
            }
        }

        // Le lettere uscite dal programma (stampate, partite per email, spedite), con il PDF conservato e le prove.
        $righe = RegistroInvio::where('condominio_id', $condominio->id)->where('anagrafica_id', $persona->id)->whereIn('tipo', RegistroInvio::TIPI_LETTERE)
            ->orderBy('emesso_il')->orderBy('id')->get();
        $documenti = $righe->isEmpty() ? collect() : Documento::where('documentable_type', RegistroInvio::class)->whereIn('documentable_id', $righe->pluck('id'))->orderBy('id')->get();
        $lettere = 0;
        foreach ($righe->groupBy(fn (RegistroInvio $r) => (string) ($r->dati['lettera'] ?? 'riga-' . $r->id)) as $sue) {
            $stato = RegistroLettere::statoDiLettera($sue->pluck('stato')->all());
            if (! in_array($stato, RegistroInvio::STATI_LETTERA_USCITA, true)) {
                continue;
            }
            $lettere++;
            /** @var RegistroInvio $prima */
            $prima = $sue->sortBy('id')->first();
            [$tipoNome, $articolo, $finale] = self::TIPI[$prima->tipo] ?? [ucfirst((string) $prima->tipo), 'la', 'a'];
            $emessa = $prima->emesso_il->copy()->setTimezone(self::fuso());
            $giorno = $emessa->format('d/m/Y');
            $della = ($articolo === 'il' ? 'del ' : 'della ') . mb_strtolower($tipoNome) . ' del ' . $giorno;
            $laLettera = ($articolo === 'il' ? 'Il ' : 'La ') . mb_strtolower($tipoNome) . ' del ' . $giorno;
            $spedita = $sue->first(fn ($r) => ($r->dati['spedizione'] ?? null) !== null);
            $destinatari = $sue->pluck('destinatario')->filter()->unique()->values()->all();
            $come = match ($stato) {
                RegistroInvio::STATO_SPEDITA => 'spedit' . $finale . self::perCanale($spedita?->dati['spedizione'] ?? []),
                RegistroInvio::STATO_INVIATA => 'partit' . $finale . ' per email' . ($destinatari !== [] ? ' a ' . implode(', ', $destinatari) : ''),
                default => 'stampat' . $finale . ': la spedizione non è registrata',
            };
            $base = '03 ' . $tipoNome . ' ' . $emessa->format('Y-m-d Hi') . ' r' . $prima->id;
            $conservatoId = (int) ($prima->dati['documento_id'] ?? 0);
            $conservato = $documenti->firstWhere('id', $conservatoId);
            $prove = $documenti->filter(fn (Documento $d) => $sue->pluck('id')->contains($d->documentable_id) && (int) $d->id !== $conservatoId)->values();
            if ($conservato !== null) {
                $p = $leggiIl($pezzo('lettera', $nome($base), $tipoNome . ' del ' . $giorno . ', ' . $come, $emessa->format('Y-m-d'), null, self::LETTERE, null,
                    ['invio_id' => (int) $prima->id]), $conservato, $prima->impronta, 'nel registro');
                if ($p['impronta']['stato'] === self::SPARITO) {
                    $p['file'] = null;
                    $esito['mancanze'][] = $mancanza('Il file del PDF ' . $della . ' non c\'è più sul disco dei documenti: resta la riga del registro.', self::LETTERE, null);
                } elseif ($p['impronta']['stato'] === self::DIVERSA) {
                    $esito['mancanze'][] = $mancanza('Il file del PDF ' . $della . ' non è quello conservato: la sua impronta non è quella scritta nel registro.', self::LETTERE, null);
                }
                $esito['pezzi'][] = $p;
            } else {
                $esito['fatti'][] = $fatto($tipoNome . ' del ' . $giorno . ', ' . $come . ': il PDF non è conservato, resta la riga del registro.', self::LETTERE, null);
            }
            foreach ($prove as $n => $d) {
                $p = $leggiIl($pezzo('prova', $nome($base . ' - prova ' . ($n + 1), self::estensione($d)), 'Prova di spedizione ' . $della . ': ' . $d->name,
                    $d->created_at?->copy()->setTimezone(self::fuso())->format('Y-m-d'), null, self::LETTERE, null, ['invio_id' => (int) $prima->id]), $d, null, 'nel registro');
                if ($p['impronta']['stato'] === self::SPARITO) {
                    $p['file'] = null;
                    $esito['mancanze'][] = $mancanza('Il file della prova «' . $d->name . '» ' . $della . ' non c\'è più sul disco dei documenti.', self::LETTERE, null);
                }
                $esito['pezzi'][] = $p;
            }
            if ($stato === RegistroInvio::STATO_STAMPATA) {
                $esito['mancanze'][] = $mancanza($laLettera . ' è stampat' . $finale . ', ma la spedizione non è registrata.', self::LETTERE, null,
                    ['etichetta' => 'Registra la spedizione', 'url' => $estratto]);
            } elseif ($stato === RegistroInvio::STATO_SPEDITA && $prove->isEmpty()) {
                $esito['mancanze'][] = $mancanza($laLettera . ' non ha una prova di spedizione: allegala dal registro delle lettere, nell\'estratto conto della persona.',
                    self::LETTERE, null, ['etichetta' => 'Allega la prova', 'url' => $estratto]);
            }
        }
        if ($lettere === 0) {
            $esito['fatti'][] = $fatto(self::FRASE_NESSUNA_LETTERA, self::LETTERE, null);
        }

        // Il rendiconto approvato di ogni esercizio dei sei mesi delle rate, con il verbale; per un esercizio non finito non si cerca.
        $gruppi = array_values(array_filter(self::esercizi($rate), fn ($g) => $g['id'] !== null));
        $esercizi = Esercizio::whereIn('id', array_column($gruppi, 'id') ?: [0])->get()->keyBy('id');
        $approvate = RendicontoVersione::whereIn('esercizio_id', array_column($gruppi, 'id') ?: [0])->where('stato', RendicontoVersione::APPROVATA)
            ->orderByDesc('numero')->get(RendicontoVersione::COLONNE_DELLA_LISTA)->groupBy('esercizio_id')->map(fn ($v) => $v->first());
        $stati = VersioniDelRendiconto::statiInTesta(array_map('intval', array_column($gruppi, 'id')));
        foreach ($gruppi as $g) {
            $e = $esercizi[$g['id']] ?? null;
            if ($e === null) {
                continue;
            }
            $anno = self::anno($e);
            $diRate = array_column($g['rate'], 'chiave');
            if ($e->data_fine === null || $e->data_fine->format('Y-m-d') >= $oggi) {
                $esito['fatti'][] = $fatto('Esercizio ' . $anno . ': non ancora finito, non ha un rendiconto.', self::RENDICONTO, $diRate);

                continue;
            }
            $sintesi = ['etichetta' => 'Apri il rendiconto', 'url' => route('admin.gestionale.esercizi.rendiconto.sintesi', ['condominio' => $condominio->id, 'esercizio' => $e->id])];
            $v = $approvate[$e->id] ?? null;
            if ($v === null) {
                $fermata = ($stati[$e->id]['stato'] ?? null) === RendicontoVersione::PRESENTATA
                    ? RendicontoVersione::find($stati[$e->id]['versione_id'], ['id', 'calcolato_il']) : null;
                $stato = $fermata !== null ? 'c\'è la versione fermata per l\'assemblea il ' . VersioniDelRendiconto::emessaIl($fermata)->format('d/m/Y') . '.' : 'è ancora una bozza.';
                $esito['mancanze'][] = $mancanza('Il rendiconto dell\'esercizio ' . $anno . ' non è approvato nel programma: ' . $stato, self::RENDICONTO, $diRate, $sintesi,
                    'Il rendiconto dell\'esercizio ' . $anno . ' non risulta approvato: ' . $stato);

                continue;
            }
            $approvato = 'approvato il ' . $v->data_delibera->format('d/m/Y');
            $righePdf = (array) ($v->pdf['documenti'] ?? []);
            $docVersione = Documento::whereIn('id', array_merge(array_column($righePdf, 'documento_id'), [(int) $v->verbale_documento_id]))->get()->keyBy('id');
            // Il PDF che i condòmini hanno ricevuto: quello caricato se c'è, altrimenti il fascicolo conservato, altrimenti le parti conservate una per una.
            $interi = array_values(array_filter($righePdf, fn ($r) => $r['parte'] === VersioniDelRendiconto::CARICATO));
            $interi = $interi !== [] ? [end($interi)] : array_values(array_filter($righePdf, fn ($r) => $r['parte'] === VersioniDelRendiconto::FASCICOLO));
            $daMettere = $interi !== [] ? [end($interi)] : array_values(array_filter($righePdf, fn ($r) => ! in_array($r['parte'], [VersioniDelRendiconto::FASCICOLO, VersioniDelRendiconto::CARICATO], true)));
            foreach ($daMettere as $r) {
                $parte = in_array($r['parte'], [VersioniDelRendiconto::FASCICOLO, VersioniDelRendiconto::CARICATO], true) ? null : (FascicoloDelRendiconto::NOMI[$r['parte']] ?? $r['parte']);
                $d = $docVersione[$r['documento_id']] ?? null;
                $p = $leggiIl($pezzo('rendiconto', $nome('04 Rendiconto ' . $anno . ($parte === null ? ' approvato' : ' - ' . $parte), $d === null ? 'pdf' : self::estensione($d)),
                    'Rendiconto ' . $anno . ', ' . $approvato . (filled($v->numero_verbale) ? ' (verbale n. ' . $v->numero_verbale . ')' : '') . ($parte === null ? '' : ': ' . $parte),
                    $v->data_delibera->format('Y-m-d'), null, self::RENDICONTO, $diRate), $d, (string) $r['impronta'], 'sulla versione');
                if ($p['impronta']['stato'] === self::SPARITO) {
                    $p['file'] = null;
                }
                $esito['pezzi'][] = $p;
            }
            $fraseDelFile = VersioniDelRendiconto::fraseDelFile($daMettere, $docVersione);
            if ($daMettere === []) {
                $esito['mancanze'][] = $mancanza('Il PDF del rendiconto ' . $anno . ' approvato non è conservato: caricalo dalla Sintesi del rendiconto.', self::RENDICONTO, $diRate, $sintesi,
                    'Il PDF del rendiconto ' . $anno . ' approvato non è conservato.');
            } elseif ($fraseDelFile !== null) {
                $esito['mancanze'][] = $mancanza('Rendiconto ' . $anno . ' approvato. ' . $fraseDelFile, self::RENDICONTO, $diRate, $sintesi);
            } elseif ($interi === [] && ($senza = VersioniDelRendiconto::fraseSenzaPdf((array) $v->pdf)) !== null) {
                $esito['mancanze'][] = $mancanza('Rendiconto ' . $anno . ' approvato. ' . $senza, self::RENDICONTO, $diRate, $sintesi);
            }
            $verbale = $v->verbale_documento_id === null ? null : ($docVersione[$v->verbale_documento_id] ?? null);
            if ($verbale === null) {
                $esito['mancanze'][] = $mancanza('Il rendiconto dell\'esercizio ' . $anno . ' è ' . $approvato . ', ma il verbale non è caricato.', self::RENDICONTO, $diRate);
            } else {
                $p = $leggiIl($pezzo('verbale', $nome('04 Rendiconto ' . $anno . ' - verbale', self::estensione($verbale)),
                    'Verbale dell\'assemblea del ' . $v->data_delibera->format('d/m/Y') . ' che ha approvato il rendiconto ' . $anno . ': ' . $verbale->name,
                    $v->data_delibera->format('Y-m-d'), null, self::RENDICONTO, $diRate), $verbale, null, 'sulla versione');
                if ($p['impronta']['stato'] === self::SPARITO) {
                    $p['file'] = null;
                    $esito['mancanze'][] = $mancanza('Il file del verbale del rendiconto ' . $anno . ' non c\'è più sul disco dei documenti.', self::RENDICONTO, $diRate);
                }
                $esito['pezzi'][] = $p;
            }
        }

        // Gli accordi di rientro sulle rate scadute della persona, con la scansione firmata.
        $accordi = AccordoRientro::with(['rate', 'documenti'])->where('condominio_id', $condominio->id)->where('anagrafica_id', $persona->id)
            ->orderBy('data_accordo')->orderBy('id')->get();
        $conAccordo = false;
        foreach ($accordi as $a) {
            $diRate = array_values(array_intersect($tutte, $a->rate->map(fn ($r) => AccordiRientro::chiave($r->rata_id, $r->immobile_id))->all()));
            if ($diRate === []) {
                continue;
            }
            $conAccordo = true;
            $giorno = $a->data_accordo->format('d/m/Y');
            // Com'è finito l'accordo: la decadenza registrata, o la chiusura, con la nota dell'amministratore.
            $nota = filled($a->nota_chiusura) ? ' (' . rtrim(trim((string) $a->nota_chiusura), '.') . ')' : '';
            if ($a->decadenza_registrata_il !== null) {
                $esito['fatti'][] = $fatto('Accordo di rientro del ' . $giorno . ': decadenza registrata il ' . $a->decadenza_registrata_il->format('d/m/Y') . $nota . '.', self::ACCORDO, $diRate);
            } elseif ($a->chiuso_il !== null && $a->chiuso_il->format('Y-m-d') <= $oggi) {
                $esito['fatti'][] = $fatto('Accordo di rientro del ' . $giorno . ': chiuso il ' . $a->chiuso_il->format('d/m/Y') . $nota . '.', self::ACCORDO, $diRate);
            }
            // La stampa dell'accordo con il calendario, come la scarica la finestra: c'è anche senza la scansione firmata.
            if ($a->scadenze()->exists()) {
                $esito['pezzi'][] = $pezzo('stampa_accordo', $nome('05 Accordo di rientro ' . $a->data_accordo->format('Y-m-d') . ' - stampa'),
                    'Accordo di rientro del ' . $giorno . ', con il calendario: la stampa', $oggi, null, self::ACCORDO, $diRate, ['accordo_id' => (int) $a->id]);
            }
            if ($a->documenti->isEmpty()) {
                $esito['fatti'][] = $fatto('Accordo di rientro del ' . $giorno . ': la scansione firmata non è fra i documenti dello studio.', self::ACCORDO, $diRate);
            }
            foreach ($a->documenti->sortBy('id')->values() as $d) {
                $p = $leggiIl($pezzo('accordo', $nome('05 Accordo di rientro ' . $a->data_accordo->format('Y-m-d'), self::estensione($d)),
                    'Accordo di rientro del ' . $giorno . ', firmato: ' . $d->name, $a->data_accordo->format('Y-m-d'), null, self::ACCORDO, $diRate), $d, null, 'sull\'accordo');
                if ($p['impronta']['stato'] === self::SPARITO) {
                    $p['file'] = null;
                    $esito['mancanze'][] = $mancanza('Il file della scansione dell\'accordo di rientro del ' . $giorno . ' non c\'è più sul disco dei documenti.', self::ACCORDO, $diRate);
                }
                $esito['pezzi'][] = $p;
            }
        }

        return ['parti' => $conAccordo ? self::PARTI : [self::LETTERE, self::ESTRATTI, self::RENDICONTO]] + $esito;
    }

    /**
     * Lo stato dell'impronta di un file conservato, fra quattro: il file non c'è (null), non c'è un'impronta con cui confrontarlo, è quello conservato, non lo è.
     * Le prime 12 cifre dell'impronta del file com'è. `$dove` dice dove è scritta l'impronta conservata: nel registro (le lettere) o sulla versione (il rendiconto).
     *
     * @return array{stato: string, cifre: ?string, frase: string, breve: string}
     */
    public static function impronta(?string $contenuto, ?string $registrata, ?CarbonInterface $conservatoIl, string $dove = 'nel registro'): array
    {
        if ($contenuto === null) {
            return ['stato' => self::SPARITO, 'cifre' => null, 'frase' => $dove === 'nel registro' ? self::FRASE_SPARITO_DEL_REGISTRO : self::FRASE_SPARITO,
                'breve' => self::BREVI[self::SPARITO]];
        }
        $impronta = hash('sha256', $contenuto);
        $il = $conservatoIl?->copy()->setTimezone(self::fuso())->format('d/m/Y') ?? '';
        $stato = $registrata === null || $registrata === '' ? self::SENZA : (hash_equals($registrata, $impronta) ? self::UGUALE : self::DIVERSA);

        return ['stato' => $stato, 'cifre' => substr($impronta, 0, 12), 'breve' => self::BREVI[$stato], 'frase' => match ($stato) {
            self::SENZA => self::FRASE_SENZA,
            self::UGUALE => sprintf(self::FRASE_UGUALE, $il),
            default => sprintf(self::FRASE_DIVERSA, $il, $dove),
        }];
    }

    /**
     * Il PDF delle rate non versate: ogni rata scelta con il piano e la sua delibera, il numero, l'unità, la scadenza, la cifra non versata, l'esercizio e la
     * data dei sei mesi, l'accordo se la parte è dentro; il totale.
     *
     * @param list<array<string, mixed>> $rate
     */
    private function prospetto(Condominio $condominio, Anagrafica $persona, array $rate, array $cifre, bool $conAccordo, string $oggi): string
    {
        $piani = PianoRate::whereIn('id', array_values(array_unique(array_filter(array_column($rate, 'piano_rate_id')))) ?: [0])->get()->keyBy('id');
        $righe = array_map(function (array $r) use ($piani, $conAccordo) {
            $p = $piani[$r['piano_rate_id']] ?? null;
            $delibera = $p === null || $p->natura === PianoRate::NATURA_ADDEBITI ? 'addebito alla persona'
                : ($p->data_delibera_assemblea !== null ? 'delibera del ' . $p->data_delibera_assemblea->format('d/m/Y') . (filled($p->numero_verbale) ? ' (verbale n. ' . $p->numero_verbale . ')' : '')
                    : 'delibera: data non scritta nel piano');

            return $r + ['delibera' => $delibera, 'accordo_testo' => $conAccordo && $r['accordo'] !== null
                ? 'in un accordo di rientro del ' . Carbon::parse($r['accordo']['data'])->format('d/m/Y') . ': ' . str_replace(' ', "\u{00A0}", self::euro($r['accordo']['in_accordo'])) : null];
        }, $rate);

        return app(PdfService::class)->generate('pdf.gestionale.fascicolo_legale_prospetto', [
            'condominio' => $condominio, 'persona' => ['nome' => (string) $persona->nome, 'unita' => array_values(array_unique(array_filter(array_column($rate, 'unita'))))],
            'oggi' => $oggi, 'righe' => $righe, 'cifre' => $cifre, 'gruppo_carta' => GruppoCarta::PER_LA_PERSONA, 'data_emissione_stampe' => DateHelper::adessoUtente(),
        ], ['margin_top' => 32])->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Il PDF dell'indice: in testa il condominio, la persona, il giorno e chi l'ha preparato; le cifre con le loro etichette; l'elenco dei file con il numero,
     * il nome nell'archivio, che cosa è, la data e l'impronta con la sua frase; i fatti; «Che cosa manca»; la frase in fondo.
     *
     * @param list<array<string, mixed>> $pezzi
     * @param list<array<string, mixed>> $fatti
     * @param list<array<string, mixed>> $mancanze
     */
    private function indice(Condominio $condominio, Anagrafica $persona, array $unita, array $pezzi, array $fatti, array $mancanze, array $cifre, ?User $utente, string $oggi): string
    {
        $giorno = Carbon::parse($oggi)->format('d/m/Y');
        $n = 0;
        $file = array_map(function (array $p) use (&$n) {
            return ['numero' => $p['file'] === null ? null : ++$n, 'nome' => $p['file'], 'cosa' => $p['cosa'], 'data' => $p['data'] === null ? null : Carbon::parse($p['data'])->format('d/m/Y'),
                'cifre' => $p['impronta']['cifre'] ?? null, 'frase' => $p['impronta']['frase'] ?? null, 'stato' => $p['impronta']['stato'] ?? null];
        }, $pezzi);

        return app(PdfService::class)->generate('pdf.gestionale.fascicolo_legale_indice', [
            'condominio' => $condominio, 'persona' => ['nome' => (string) $persona->nome, 'codice_fiscale' => $persona->codice_fiscale],
            'unita' => $unita,
            'preparato' => 'Preparato il ' . $giorno . ($utente !== null && filled($utente->name) ? ' da ' . $utente->name : ''),
            'cifra' => 'Le rate scadute in questo fascicolo, al ' . $giorno . ': ' . self::euro($cifre['totale']),
            'di_cui' => array_map(fn ($e) => $e['data'] === null ? 'di cui senza la data dei sei mesi: ' . self::euro($e['cifra'])
                : 'di cui dell\'esercizio ' . self::annoDelNome($e['nome']) . ': ' . self::euro($e['cifra']) . ' (data dei sei mesi: ' . Carbon::parse($e['data'])->format('d/m/Y') . ')', $cifre['esercizi']),
            'file' => $file, 'fatti' => array_column($fatti, 'frase'), 'mancanze' => array_map(fn ($m) => $m['per_il_legale'] ?? $m['frase'], $mancanze),
            'frase_nessuna_mancanza' => self::FRASE_NESSUNA_MANCANZA_DELL_INDICE, 'frase_in_fondo' => self::FRASE_IN_FONDO_DELL_INDICE, 'frase_dati_personali' => self::FRASE_DATI_PERSONALI,
            'gruppo_carta' => GruppoCarta::PER_LA_PERSONA, 'data_emissione_stampe' => DateHelper::adessoUtente(),
        ], ['margin_top' => 32])->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Come è stata spedita una lettera, con le parole del registro (`RegistroLettere::CANALI_SPEDIZIONE`): «per raccomandata A/R», «a mano». */
    private const SPEDITA = ['raccomandata' => ' per raccomandata A/R', 'pec' => ' per PEC, fuori dal programma', 'a_mano' => ' a mano', 'posta' => ' per posta ordinaria'];

    /** « per raccomandata A/R il 03/03/2026», dalla spedizione registrata. */
    private static function perCanale(array $spedizione): string
    {
        $data = filled($spedizione['data'] ?? null) ? ' il ' . Carbon::parse($spedizione['data'])->format('d/m/Y') : '';

        return (self::SPEDITA[$spedizione['canale'] ?? ''] ?? '') . $data;
    }

    /** «2025» da «Esercizio 2025». */
    private static function anno(Esercizio $esercizio): string
    {
        return self::annoDelNome((string) $esercizio->nome);
    }

    private static function annoDelNome(string $nome): string
    {
        return trim((string) preg_replace('/^esercizio\s+/iu', '', $nome));
    }

    /** L'estensione del file sul disco, in minuscolo; un PDF se non ce l'ha. */
    private static function estensione(Documento $d): string
    {
        $e = mb_strtolower(pathinfo((string) $d->path, PATHINFO_EXTENSION));

        return $e === '' ? 'pdf' : $e;
    }

    private static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }

    private static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
