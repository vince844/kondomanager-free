<?php

namespace App\Services\Gestionale;

use App\Exceptions\Gestionale\ConguaglioNonStampabileException;
use App\Http\Controllers\Gestionale\Movimenti\RendicontoConguaglioController;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Il foglio della persona: la sua parte del consuntivo e la proposta di conguaglio, unità per unità e voce per voce, con la tabella, i millesimi
 * e i giorni; A, le spese personali, B, il conguaglio, la posizione e il saldo; le righe che spiegano (l'anno prima, il versato in cassa, un
 * credito usato, il già versato prima delle rate, le rate non ancora emesse) e «Come leggere». A4 verticale, con la carta «per la persona».
 *
 * Uno per uno, in un archivio .zip a pezzi ({@see ArchivioAPezzi}) come le ricevute, o per email ({@see InvioDeiFogli}): al massimo {@see ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA}
 * per richiesta, le persone scelte fra tutte, quelle con il conguaglio a debito, quelle con un indirizzo email, di tutto l'elenco o di una sua
 * pagina. Una lettura sola per richiesta, con la spesa per tabella ({@see ConsuntivoPerPersona}, `per_tabella`): le righe di spiegazione del motore
 * non servono, e i fogli di duecento persone non le tengono in memoria. Nel foglio non c'è nessuna nota sui passaggi regolati fra le parti né
 * l'avviso dell'anno prima: sono per l'amministratore, e stanno nella scheda.
 */
final class FogliDelConguaglio
{
    /** Le persone che si possono scegliere per l'archivio. */
    public const SCELTE = ['tutte', 'a_debito', 'con_email'];

    public function __construct(private readonly ConguaglioDelRendiconto $conguaglio, private readonly PdfService $pdf) {}

    /** La chiave della sessione dove la scheda ricorda l'ultima scelta delle persone, una per condominio. */
    public static function chiaveScelta(Condominio $condominio): string
    {
        return 'rendiconto.conguaglio.fogli.'.$condominio->id;
    }

    /**
     * Quante persone per ogni scelta, in tutto l'elenco e nella pagina mostrata: l'anteprima del numero prima di scaricare.
     *
     * @param  list<array<string,mixed>>  $elenco  le righe della scheda, in ordine, con le righe dei subtotali
     * @return array{tutto: array<string,int>, pagina: array<string,int>}
     */
    public function conteggi(array $letto, array $elenco, int $pagina, int $perPagina): array
    {
        $tutte = self::persone($letto);
        $diPagina = array_values(array_unique(array_map(fn ($r) => (int) $r['anagrafica_id'], array_filter(array_slice($elenco, ($pagina - 1) * $perPagina, $perPagina),
            fn ($r) => ! $r['subtotale']))));
        $conEmail = $this->conEmail(array_keys($tutte));
        // Chi ha il conguaglio non disponibile su un'unità e su nessuna è a debito: «a debito» lo lascia fuori, e il riquadro lo dice.
        $nonDisponibili = self::nonDisponibili($letto);
        $conta = fn (array $ids) => [
            'tutte' => count($ids),
            'a_debito' => count(array_filter($ids, fn ($aid) => $tutte[$aid] ?? false)),
            'con_email' => count(array_filter($ids, fn ($aid) => isset($conEmail[$aid]))),
            'non_disponibili' => count(array_filter($ids, fn ($aid) => isset($nonDisponibili[$aid]) && ! ($tutte[$aid] ?? false))),
        ];

        return ['tutto' => $conta(array_keys($tutte)), 'pagina' => $conta($diPagina)];
    }

    /**
     * Le persone della lettura, e se sono a debito: chi ha il conguaglio a debito su almeno un'unità (le righe non disponibili non contano). Mai un
     * netto fra unità: chi è a debito su un'unità e a credito su un'altra è a debito.
     *
     * @return array<int,bool>
     */
    public static function persone(array $letto): array
    {
        $persone = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                foreach ($perUnita as $r) {
                    $persone[(int) $aid] = ($persone[(int) $aid] ?? false) || (int) ($r['conguaglio'] ?? 0) > 0;
                }
            }
        }
        foreach ($letto['da_altre_gestioni']['persone'] ?? [] as $aid => $perUnita) {
            foreach ($perUnita as $r) {
                $persone[(int) $aid] = ($persone[(int) $aid] ?? false) || (int) $r['conguaglio'] > 0;
            }
        }

        return $persone;
    }

    /**
     * Le persone con il conguaglio non disponibile su almeno un'unità.
     *
     * @return array<int,true>
     */
    public static function nonDisponibili(array $letto): array
    {
        $persone = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                foreach ($perUnita as $r) {
                    if (($r['conguaglio'] ?? null) === null) {
                        $persone[(int) $aid] = true;
                    }
                }
            }
        }

        return $persone;
    }

    /** @return array<int,true> */
    private function conEmail(array $ids): array
    {
        $con = [];
        foreach (array_chunk($ids, 1000) as $pezzo) {
            foreach (Anagrafica::whereIn('id', $pezzo)->get(['id', 'email', 'email_secondaria', 'pec']) as $a) {
                if (EmissioneRicevute::indirizzi($a) !== []) {
                    $con[(int) $a->id] = true;
                }
            }
        }

        return $con;
    }

    /**
     * I dati del foglio di una persona, dalla lettura (con `per_tabella`); null se la persona non ha righe nel consuntivo.
     *
     * @return array<string,mixed>|null
     */
    public function datiDelFoglio(Condominio $condominio, Esercizio $esercizio, array $letto, int $aid, ?array $contesto = null): ?array
    {
        $contesto ??= $this->contesto($esercizio, $letto, [$aid]);
        $persona = $contesto['anagrafiche'][$aid] ?? Anagrafica::find($aid);
        $gestioni = [];
        // `gia`: quello che è già da versare, le rate chieste e non pagate e le altre somme a carico della persona fuori dalle rate; conta anche dove
        // la proposta non è ancora calcolata.
        $totale = ['a' => 0, 'personali' => 0, 'b' => 0, 'conguaglio' => 0, 'posizione' => 0, 'fuori' => 0, 'saldo' => 0, 'non_emesse' => 0, 'gia' => 0];
        $nonDisponibile = false;
        $spiegano = ['gia_versato_prima' => 0, 'non_emesse' => 0, 'non_emesse_personali' => 0, 'gia_versato_non_usato' => 0, 'gia_versato_non_usato_tutti' => 0];
        foreach ($letto['gestioni'] as $gid => $g) {
            $unita = [];
            foreach ($letto['persone'][$gid][$aid] ?? [] as $iid => $r) {
                $voci = [];
                foreach ($r['voci'] as $cid => $importo) {
                    $tabelle = $contesto['tabelle_delle_voci'][$cid] ?? [];
                    $voci[] = [
                        'voce' => (string) ($contesto['voci'][$cid] ?? ''),
                        'tabella' => implode(', ', array_map(fn ($tid) => (string) ($letto['per_tabella'][$gid]['tabelle'][$tid]['nome'] ?? $contesto['nomi_tabelle'][$tid] ?? ''), $tabelle)),
                        'millesimi' => count($tabelle) === 1 ? self::millesimi($letto['per_tabella'][$gid] ?? null, (int) $tabelle[0], (int) $iid) : null,
                        'importo' => (int) $importo,
                    ];
                }
                $voci = array_values(array_filter($voci, fn ($v) => $v['importo'] !== 0));
                $nd = $r['non_disponibile'] !== null;
                $nonDisponibile = $nonDisponibile || $nd;
                $unita[] = [
                    'unita' => (string) ($contesto['unita'][$iid] ?? ''), 'giorni' => $contesto['giorni'][$aid][$iid] ?? null, 'voci' => $voci,
                    'a' => (int) $r['a'], 'gia_versato_unita' => (int) $r['gia_versato_unita'],
                    'personali' => (int) $r['personali']['non_chieste'], 'personali_righe' => array_map(fn ($p) => ['giorno' => $p['giorno'], 'importo' => (int) $p['importo']], $r['personali']['righe']),
                    'competenze_scritte' => array_map(fn ($c) => $c + ['voce' => (string) ($contesto['voci'][$c['conto_id']] ?? '')], $r['competenze_scritte'] ?? []),
                    'b' => $r['b'], 'conguaglio' => $r['conguaglio'], 'posizione' => (int) $r['posizione'], 'fuori' => (int) $r['fuori_dalle_rate'], 'saldo' => $r['saldo'],
                    'non_emesse' => (int) $r['b']['di_cui_non_emesse'] + (int) ($r['personali']['chieste_non_emesse'] ?? 0),
                    'gia' => (int) $r['posizione'] + (int) $r['fuori_dalle_rate'],
                    'non_disponibile' => $nd,
                ];
                $totale['gia'] += (int) $r['posizione'] + (int) $r['fuori_dalle_rate'];
                $spiegano['gia_versato_prima'] += (int) $r['b']['gia_versato_persona'] + (int) $r['gia_versato_unita'];
                // Le rate non ancora emesse con la cifra che sta accanto al saldo: le quote e le spese personali chieste con le rate.
                $spiegano['non_emesse'] += (int) $r['b']['di_cui_non_emesse'] + (int) ($r['personali']['chieste_non_emesse'] ?? 0);
                $spiegano['non_emesse_personali'] += (int) ($r['personali']['chieste_non_emesse'] ?? 0);
                $totale['non_emesse'] += (int) $r['b']['di_cui_non_emesse'] + (int) ($r['personali']['chieste_non_emesse'] ?? 0);
                $spiegano['gia_versato_non_usato'] += (int) ($r['gia_versato_non_usato']['questo_esercizio'] ?? 0);
                $spiegano['gia_versato_non_usato_tutti'] += (int) ($r['gia_versato_non_usato']['tutti_gli_esercizi'] ?? 0);
                if (! $nd) {
                    foreach (['a' => $r['a'], 'personali' => $r['personali']['non_chieste'], 'b' => $r['b']['totale'], 'conguaglio' => $r['conguaglio'], 'posizione' => $r['posizione'],
                        'fuori' => $r['fuori_dalle_rate'], 'saldo' => $r['saldo']] as $k => $v) {
                        $totale[$k] += (int) $v;
                    }
                }
            }
            if ($unita !== []) {
                // Una gestione che non si calcola non ha una spesa divisa: il foglio non dice «nessuna spesa».
                $gestioni[] = ['nome' => (string) $g['nome'], 'tipo' => (string) $g['tipo'], 'unita' => $unita, 'non_divisa' => ($g['non_calcolabile'] ?? null) !== null];
            }
        }
        $altre = [];
        foreach ($letto['da_altre_gestioni']['persone'][$aid] ?? [] as $iid => $r) {
            $altre[] = ['unita' => (string) ($contesto['unita'][$iid] ?? ''), 'posizione' => (int) $r['posizione'], 'personali' => (int) $r['personali']['non_chieste'],
                'conguaglio' => (int) $r['conguaglio'], 'saldo' => (int) $r['saldo']];
            $totale['gia'] += (int) $r['posizione'];
            $totale['personali'] += (int) $r['personali']['non_chieste'];
            $totale['conguaglio'] += (int) $r['conguaglio'];
            $totale['posizione'] += (int) $r['posizione'];
            $totale['saldo'] += (int) $r['saldo'];
        }
        if ($gestioni === [] && $altre === []) {
            return null;
        }

        return [
            'persona' => ['id' => $aid, 'nome' => (string) ($persona?->nome ?? ''), 'indirizzo' => (string) ($persona?->indirizzo ?? '')],
            'data' => ConguaglioDelRendiconto::data($esercizio, $letto),
            'gestioni' => $gestioni,
            'da_altre_gestioni' => $altre,
            'totale' => $totale,
            'non_disponibile' => $nonDisponibile,
            'non_divise' => $this->nonDivise($condominio, $esercizio, $letto),
            'spiegano' => $spiegano + $this->movimenti($esercizio, $aid) + ['anno_prima' => (int) ($contesto['anno_prima'][$aid] ?? 0)],
        ];
    }

    /** Le spese non ancora divise del condominio, per esercizio: si leggono una volta per i fogli di una richiesta. */
    private array $nonDiviseDi = [];

    /**
     * Le spese dell'esercizio divise fra i condòmini e quelle non ancora divise (le voci senza una divisione e le uscite fuori dai capitoli del
     * riepilogo: le fatture contestate, le commissioni bancarie senza voce, i costi non classificati): il foglio dice che il conguaglio può cambiare.
     *
     * @return array{divise:int, non_divise:int, cosa: list<string>}
     */
    public function nonDivise(Condominio $condominio, Esercizio $esercizio, array $letto): array
    {
        if (! isset($this->nonDiviseDi[$esercizio->id])) {
            $t = ConguaglioDelRendiconto::totaliDelCondominio($letto);
            $fuori = app(RiepilogoPerCompetenza::class)->perEsercizio($condominio, $esercizio)['fuori_capitoli'];
            $nd = ParoleDelConguaglio::nonDivise((int) $t['non_attribuite'], array_map(fn ($f) => (int) ($f['consuntivo'] ?? 0), array_intersect_key($fuori, ParoleDelConguaglio::NON_DIVISE)));
            $this->nonDiviseDi[$esercizio->id] = ['divise' => (int) $t['a'] + (int) $t['a_non_disponibili'], 'non_divise' => $nd['importo'], 'cosa' => $nd['cosa']];
        }

        return $this->nonDiviseDi[$esercizio->id];
    }

    /** «500,00 su 1.000,00»: il millesimo dell'unità e il totale della tabella. */
    private static function millesimi(?array $perTabella, int $tid, int $iid): ?string
    {
        $valore = $perTabella['millesimi'][$tid][$iid] ?? null;
        if ($valore === null) {
            return null;
        }
        $somma = $perTabella['tabelle'][$tid]['somma_valori'] ?? null;
        $f = fn ($v) => number_format((float) $v, 2, ',', '.');

        return $somma === null ? $f($valore) : $f($valore).' su '.$f($somma);
    }

    /**
     * Quello che i fogli di più persone leggono una volta: i nomi delle voci e delle unità, le tabelle di ogni voce, le persone, i giorni e i saldi
     * dell'anno prima dell'esercizio.
     */
    public function contesto(Esercizio $esercizio, array $letto, array $aids): array
    {
        $iids = [];
        $cids = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($aids as $aid) {
                foreach ($perPersona[$aid] ?? [] as $iid => $r) {
                    $iids[(int) $iid] = true;
                    foreach (array_keys($r['voci']) as $cid) {
                        $cids[(int) $cid] = true;
                    }
                    foreach ($r['competenze_scritte'] ?? [] as $c) {
                        $cids[(int) $c['conto_id']] = true;
                    }
                }
            }
        }
        foreach ($aids as $aid) {
            foreach (array_keys($letto['da_altre_gestioni']['persone'][$aid] ?? []) as $iid) {
                $iids[(int) $iid] = true;
            }
        }
        $tabelleDelleVoci = [];
        foreach (DB::table('conto_tabella_millesimale')->whereIn('conto_id', array_keys($cids) ?: [0])->orderBy('id')->get(['conto_id', 'tabella_id']) as $r) {
            $tabelleDelleVoci[(int) $r->conto_id][] = (int) $r->tabella_id;
        }
        $nomiTabelle = DB::table('tabelle')->whereIn('id', array_merge([0], ...array_values($tabelleDelleVoci)))->pluck('nome', 'id')->all();
        $unita = [];
        foreach (array_chunk(array_keys($iids), 1000) as $pezzo) {
            foreach (Immobile::whereIn('id', $pezzo)->get() as $i) {
                $unita[(int) $i->id] = (string) $i->etichetta;
            }
        }
        $annoPrima = [];
        foreach (DB::table('saldi')->where('esercizio_id', $esercizio->id)->whereIn('anagrafica_id', $aids ?: [0])->whereNull('subentro_id')
            ->get(['anagrafica_id', 'saldo_iniziale']) as $r) {
            $annoPrima[(int) $r->anagrafica_id] = ($annoPrima[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
        }

        return [
            'voci' => DB::table('conti')->whereIn('id', array_keys($cids) ?: [0])->pluck('nome', 'id')->all(),
            'tabelle_delle_voci' => $tabelleDelleVoci,
            'nomi_tabelle' => $nomiTabelle,
            'unita' => $unita,
            'anagrafiche' => Anagrafica::whereIn('id', $aids ?: [0])->get()->keyBy('id')->all(),
            'giorni' => $this->conguaglio->giorniDiTitolarita($esercizio, array_keys($iids)),
            'anno_prima' => $annoPrima,
        ];
    }

    /**
     * Il versato in cassa e il credito usato della persona nell'esercizio: le righe in avere sui crediti verso i condòmini con la persona, negli
     * incassi delle rate dell'esercizio (meno quelle in dare negli storni). Se la registrazione ha una riga in dare su una cassa è versato in
     * cassa, altrimenti è un credito usato. Righe che spiegano, non termini del conguaglio.
     *
     * @return array{versato_in_cassa:int, usato_un_credito:int}
     */
    private function movimenti(Esercizio $esercizio, int $aid): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->where('sc.esercizio_id', $esercizio->id)->whereNull('sc.deleted_at')
            ->where('sc.tipo_movimento', 'incasso_rata')->where('cc.ruolo', 'crediti_condomini')->where('rs.anagrafica_id', $aid)
            ->get(['rs.scrittura_id', 'rs.tipo_riga', 'rs.importo']);
        if ($righe->isEmpty()) {
            return ['versato_in_cassa' => 0, 'usato_un_credito' => 0];
        }
        $inCassa = array_flip(DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereIn('rs.scrittura_id', $righe->pluck('scrittura_id')->unique()->all())->where('cc.categoria', 'liquidita')
            ->distinct()->pluck('rs.scrittura_id')->map(fn ($id) => (int) $id)->all());
        $esito = ['versato_in_cassa' => 0, 'usato_un_credito' => 0];
        foreach ($righe as $r) {
            $importo = $r->tipo_riga === 'avere' ? (int) $r->importo : -(int) $r->importo;
            $esito[isset($inCassa[(int) $r->scrittura_id]) ? 'versato_in_cassa' : 'usato_un_credito'] += $importo;
        }

        return $esito;
    }

    /** Il PDF del foglio. */
    public function pdf(Condominio $condominio, Esercizio $esercizio, array $dati): string
    {
        $mpdf = $this->pdf->generate('pdf.gestionale.foglio_conguaglio', ['condominio' => $condominio, 'esercizio' => $esercizio, 'foglio' => $dati,
            'titolo' => 'Consuntivo e proposta di conguaglio', 'gruppo_carta' => GruppoCarta::PER_LA_PERSONA], RendicontoConguaglioController::OPZIONI_FOGLIO);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Le persone scelte: tutte quelle della lettura, quelle con il conguaglio a debito o quelle con un indirizzo email, di tutto l'elenco o della
     * pagina data (le righe della scheda, nel suo ordine). Le usano l'archivio e l'invio per email ({@see InvioDeiFogli}).
     *
     * @return list<int>
     */
    public function scelte(Condominio $condominio, Esercizio $esercizio, array $letto, string $chi, ?int $pagina): array
    {
        $persone = self::persone($letto);
        $ids = array_keys($persone);
        if ($pagina !== null) {
            $scheda = $this->conguaglio->righe($condominio, $esercizio, $letto);
            $elenco = [];
            foreach ($scheda['gestioni'] as $g) {
                array_push($elenco, ...$g['righe']);
            }
            array_push($elenco, ...$scheda['da_altre_gestioni']['righe']);
            $per = ConguaglioDelRendiconto::RIGHE_PER_PAGINA;
            $ids = array_values(array_unique(array_map(fn ($r) => (int) $r['anagrafica_id'],
                array_filter(array_slice($elenco, ($pagina - 1) * $per, $per), fn ($r) => ! $r['subtotale']))));
        }

        return match ($chi) {
            'a_debito' => array_values(array_filter($ids, fn ($aid) => $persone[$aid] ?? false)),
            'con_email' => array_values(array_intersect($ids, array_keys($this->conEmail($ids)))),
            default => array_values($ids),
        };
    }

    /**
     * L'archivio .zip con i fogli delle persone scelte (di tutto l'elenco, o di una pagina) e l'elenco degli indirizzi. Oltre il tetto non parte, e
     * dice di scegliere le persone di una pagina dell'elenco.
     *
     * @return array{zip: string, nome: string, persone: int}
     */
    public function archivio(Condominio $condominio, Esercizio $esercizio, string $chi, ?int $pagina): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new ConguaglioNonStampabileException('Su questo server PHP non ha l\'estensione zip: l\'archivio non si può fare. Scarica i fogli uno per uno, '
                .'o chiedi a chi gestisce l\'installazione di attivare l\'estensione.');
        }
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        $ids = $this->scelte($condominio, $esercizio, $letto, $chi, $pagina);
        if ($ids === []) {
            throw new ConguaglioNonStampabileException('Nessuna persona fra quelle scelte: l\'archivio sarebbe vuoto.');
        }
        if (count($ids) > ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA) {
            throw new ConguaglioNonStampabileException('Le persone scelte sono '.count($ids).': in un archivio ne stanno al massimo '.ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA
                .'. Scegli le persone di una pagina dell\'elenco.');
        }

        $contesto = $this->contesto($esercizio, $letto, $ids);
        $nomi = array_map(fn ($a) => (string) $a->nome, $contesto['anagrafiche']);
        uasort($nomi, fn ($a, $b) => mb_strtolower($a) <=> mb_strtolower($b));
        $archivio = new ArchivioAPezzi(ConguaglioNonStampabileException::class);
        try {
            $usati = [mb_strtolower(InviiPerPersona::FILE_DEGLI_INDIRIZZI)];
            $elencoIndirizzi = [];
            foreach (array_keys($nomi) as $aid) {
                $dati = $this->datiDelFoglio($condominio, $esercizio, $letto, (int) $aid, $contesto);
                if ($dati === null) {
                    continue;
                }
                $nome = InviiPerPersona::nomeLibero(EmissioneRicevute::nomeFileDellaPersona($dati['persona']['nome']), $usati).'.pdf';
                $archivio->aggiungi($nome, $this->pdf($condominio, $esercizio, $dati));
                $elencoIndirizzi[] = ['nome' => $dati['persona']['nome'], 'indirizzi' => EmissioneRicevute::indirizzi($contesto['anagrafiche'][$aid] ?? null),
                    'documenti' => 1, 'file' => $nome];
                gc_collect_cycles();
            }
            $archivio->aggiungi(InviiPerPersona::FILE_DEGLI_INDIRIZZI, InviiPerPersona::elencoDegliIndirizzi('Consuntivo e proposta di conguaglio, '.$esercizio->nome,
                (string) $condominio->nome, $elencoIndirizzi, now(), fn (int $n) => $n === 1 ? '1 foglio' : $n.' fogli', EmissioneRicevute::TIPI_INDIRIZZO,
                'Un PDF per persona, da mandare dal tuo programma di posta o da stampare.'));

            return [
                'zip' => $archivio->scrivi(),
                'nome' => Str::replaceLast('.pdf', '.zip', PdfService::nomeFile('conguaglio-per-persona', $condominio->nome, $esercizio->data_inizio->format('Y'), 'fogli')),
                'persone' => count($elencoIndirizzi),
            ];
        } catch (\Throwable $e) {
            $archivio->togli();

            throw $e;
        }
    }
}
