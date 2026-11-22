<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Documento;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Assemblee\Seguito\StampaDelloStatoDiEsecuzione;
use Mpdf\Output\Destination;

/**
 * Il fascicolo dell'assemblea (§3 P14 del progetto della tappa 2; scena 3 della bozza approvata della pagina): **un PDF solo** con la copertina, l'avviso e i
 * documenti dei punti, e per la posta lo stesso **per persona**, con il suo avviso e la delega. I documenti dei punti:
 *
 * - il punto del rendiconto: il fascicolo della versione fermata per l'assemblea (quella collegata al punto, o l'ultima dell'esercizio del punto);
 * - il punto del preventivo: le stampe della versione fermata ({@see VersioniDelPreventivo}); finché non c'è, «Ferma il preventivo» e la frase;
 * - gli altri punti: i documenti PDF collegati al punto («Parla di», ruolo `documento`), nell'ordine in cui sono stati aggiunti; senza, «Nessun documento.».
 * - il punto «Stato di esecuzione delle delibere precedenti» (R10): senza un documento aggiunto, la stampa «Lo stato di esecuzione delle delibere» con
 *   l'elenco fermato nel punto ({@see \App\Services\Assemblee\Seguito\StampaDelloStatoDiEsecuzione}), fatta quando si compone il fascicolo.
 *   Si aggiungono con «Aggiungi un documento» ({@see DocumentiDeiPunti}): i PDF delle schede di cui parla il punto sono proposti nella sua riga, mai
 *   aggiunti da soli.
 *
 * Il passo «Documenti» (prop `fascicolo`) ha la forma `SchedaDelFascicolo` di `components/assemblee/convocazione/tipi.ts`, con il riepilogo del piede: i
 * documenti, le loro pagine e il peso, che vicino al tetto lo dice. Comporre il fascicolo è una
 * riga del registro: «Fascicolo composto: 5 documenti, impronta …». Il PDF non si rifà a pezzi: si copiano le pagine dei documenti già conservati, che
 * tengono la loro impronta; il tetto è quello degli avvisi.
 */
class FascicoloDellAssemblea
{
    public const FRASE_PREVENTIVO = 'Il preventivo non è ancora fermato: lo fermi prima di spedire, così resta che cosa hanno ricevuto i condòmini.';

    public const NESSUN_DOCUMENTO = 'Nessun documento.';

    /** Il tetto della memoria dei documenti uniti, in MB: oltre, il fascicolo non si compone e lo dice. */
    public const MB_DEI_DOCUMENTI = 40;

    /** Dalla metà del tetto il riepilogo dice il peso; dall'ottanta per cento è arancio, con «manda i documenti più pesanti a parte». */
    public const PESO_DA_DIRE = 0.5;

    public const PESO_VICINO = 0.8;

    public function __construct(
        private readonly ArchivioDocumenti $archivio,
        private readonly PdfService $pdf,
        private readonly EmissioneDelleConvocazioni $emissione,
        private readonly ElencoDeiConvocati $elenco,
        private readonly DocumentiDeiPunti $deiPunti,
    ) {}

    /**
     * Il fascicolo del passo «Documenti» della pagina dell'assemblea; vuoto per una riunione a mano. Ogni riga ha il numero, il punto e la riga grigia sotto
     * (la scheda da cui nasce, o la versione fermata); per gli altri punti i documenti aggiunti (nome e pagine), le proposte del programma, e se si
     * aggiungono e si tolgono (chi gestisce, in preparazione). @return array<string, mixed>
     */
    public function perLaPagina(Assemblea $assemblea): array
    {
        if ($assemblea->eAMano()) {
            return [];
        }
        $gestisce = AccessoAlleAssemblee::gestisce(auth()->user());
        $aperta = $assemblea->stato === StatoAssemblea::IN_PREPARAZIONE || $assemblea->stato === StatoAssemblea::CONVOCATA;
        $modificabile = $gestisce && $assemblea->stato === StatoAssemblea::IN_PREPARAZIONE;
        $righe = [['numero' => '—', 'punto_id' => null, 'punto' => 'Copertina e avviso', 'documento' => 'sulla carta dello studio, con l\'ordine del giorno e la delega',
            'documento_muto' => true, 'sotto' => null, 'stato' => ['parola' => 'Pronti', 'colore' => 'em'], 'azione' => null, 'prende' => false, 'documenti' => [],
            'proposte' => [], 'aggiungi' => false, 'togli' => false]];
        $preventivoDaFermare = false;
        foreach ($assemblea->punti()->get() as $punto) {
            [$documento, $muto, $stato, $azione] = match ($punto->materia) {
                MateriaPunto::RENDICONTO => $this->rigaDelRendiconto($punto),
                MateriaPunto::PREVENTIVO => $this->rigaDelPreventivo($punto),
                default => $this->rigaDegliAllegati($punto),
            };
            $preventivoDaFermare = $preventivoDaFermare || $azione === 'ferma_preventivo';
            $prende = DocumentiDeiPunti::accetta($punto);
            $righe[] = ['numero' => (string) $punto->numero, 'punto_id' => (int) $punto->id, 'punto' => (string) $punto->testo, 'documento' => $documento,
                'documento_muto' => $muto, 'sotto' => $prende ? DocumentiDeiPunti::sotto($punto) : null, 'stato' => $stato,
                // «Ferma il preventivo» c'è solo in preparazione: dopo la convocazione l'assemblea approva il preventivo spedito (decisione 622 (8)).
                'azione' => $gestisce && $aperta && ($azione !== 'ferma_preventivo' || $modificabile) ? $azione : null, 'prende' => $prende,
                'documenti' => $prende ? array_map(fn (Documento $d) => $this->deiPunti->voce($d), DocumentiDeiPunti::delPunto($punto)) : [],
                'proposte' => $prende && $modificabile ? $this->deiPunti->proposti($punto) : [],
                'aggiungi' => $prende && $modificabile, 'togli' => $prende && $modificabile];
        }
        $p = ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id];
        $r = fn (string $nome, array $piu = []) => Route::has($nome) ? route($nome, $p + $piu) : '';
        $convocati = count($this->elenco->per($assemblea));

        return [
            'righe' => $righe,
            'frase' => $preventivoDaFermare ? self::FRASE_PREVENTIVO : null,
            'riepilogo' => $this->pesoEPagine($assemblea),
            'condominio' => (string) $assemblea->condominio?->nome,
            'modificabile' => $modificabile,
            'componi' => ['quante' => count($righe), 'attivo' => $gestisce, 'perche' => $gestisce ? null : 'Serve «Gestisce le assemblee».'],
            'per_persona' => ['quante' => $convocati, 'attivo' => $gestisce && $convocati > 0, 'perche' => $convocati === 0 ? 'Nessun convocato.' : ($gestisce ? null : 'Serve «Gestisce le assemblee».')],
            'ferma_preventivo' => ['quante' => $preventivoDaFermare ? 1 : 0, 'attivo' => $modificabile && $preventivoDaFermare,
                'perche' => $preventivoDaFermare && $assemblea->stato !== StatoAssemblea::IN_PREPARAZIONE ? VersioniDelPreventivo::DOPO_LA_CONVOCAZIONE : null],
            'gestisce' => $gestisce,
            'rotte' => ['componi' => $r('admin.gestionale.assemblee.fascicolo.componi'), 'per_persona' => $r('admin.gestionale.assemblee.fascicolo.per-persona'),
                'ferma_preventivo' => $r('admin.gestionale.assemblee.fascicolo.preventivo'),
                // I documenti di un punto: `__PUNTO__` e `__DOCUMENTO__` li scrive la pagina.
                'documenti' => $r('admin.gestionale.assemblee.fascicolo.documenti', ['punto' => '__PUNTO__']),
                'aggiungi' => $r('admin.gestionale.assemblee.fascicolo.documenti.store', ['punto' => '__PUNTO__']),
                'togli' => $r('admin.gestionale.assemblee.fascicolo.documenti.destroy', ['punto' => '__PUNTO__', 'documento' => '__DOCUMENTO__'])],
        ];
    }

    /**
     * Il riepilogo del piede del passo: quanti documenti partono con l'avviso, le loro pagine (la copertina e l'avviso a parte) e il peso in MB sul tetto;
     * `vicino` dall'ottanta per cento del tetto. Il peso è quello scritto nel documento, senza leggere i file.
     *
     * @return array{documenti: int, pagine: int, mb: int, tetto: int, dire: bool, vicino: bool}
     */
    public function pesoEPagine(Assemblea $assemblea): array
    {
        $documenti = 0;
        $pagine = 0;
        $byte = 0;
        foreach ($assemblea->punti()->get() as $punto) {
            foreach ($this->documentiDelPunto($punto) as $d) {
                $documenti++;
                $pagine += $this->deiPunti->pagine($d) ?? 0;
                $byte += (int) $d->file_size;
            }
            // La stampa dello stato di esecuzione si fa quando si compone il fascicolo: qui conta come un documento di una pagina.
            if (self::eLoStatoDiEsecuzione($punto) && $this->documentiDelPunto($punto) === []) {
                $documenti++;
                $pagine++;
            }
        }
        $tetto = self::MB_DEI_DOCUMENTI * 1024 * 1024;

        return ['documenti' => $documenti, 'pagine' => $pagine, 'mb' => (int) round($byte / 1048576), 'tetto' => self::MB_DEI_DOCUMENTI,
            'dire' => $byte >= $tetto * self::PESO_DA_DIRE, 'vicino' => $byte >= $tetto * self::PESO_VICINO];
    }

    /**
     * Per la riga del passo «Documenti»: quanti documenti dei punti partono con l'avviso, e quali punti hanno ancora la versione da fermare (il rendiconto,
     * il preventivo). Si contano i documenti senza leggerne i file.
     *
     * @return array{documenti: int, da_fermare: list<string>}
     */
    public function riepilogo(Assemblea $assemblea): array
    {
        $documenti = 0;
        $daFermare = [];
        foreach ($assemblea->punti()->get() as $punto) {
            $suoi = count($this->documentiDelPunto($punto)) ?: (self::eLoStatoDiEsecuzione($punto) ? 1 : 0);
            $documenti += $suoi;
            if ($punto->materia === MateriaPunto::RENDICONTO && $this->versioneDelRendiconto($punto) === null) {
                $daFermare['rendiconto'] = 'il rendiconto';
            }
            if ($punto->materia === MateriaPunto::PREVENTIVO && VersioniDelPreventivo::delPunto($punto) === null) {
                $daFermare['preventivo'] = 'il preventivo';
            }
        }

        return ['documenti' => $documenti, 'da_fermare' => array_values($daFermare)];
    }

    /** «Componi il fascicolo»: la copertina, l'avviso (senza un nome, con la delega in bianco) e i documenti dei punti, in un PDF. @return array{pdf: string, nome: string, documenti: int} */
    public function componi(Assemblea $assemblea, User $utente): array
    {
        $documenti = $this->documenti($assemblea);
        $avviso = $this->emissione->rendi($assemblea, [$this->emissione->avviso($assemblea, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null])]);
        $copertina = $this->copertina($assemblea, $documenti, UnioneDiPdf::pagine($avviso));
        $pdf = UnioneDiPdf::unisci([$copertina, $avviso, ...array_column($documenti, 'contenuto')]);
        $impronta = hash('sha256', $pdf);
        RegistroModifiche::gesto(GestiDelRegistro::fascicoloComposto($assemblea, count($documenti) + 2, $impronta));

        return ['pdf' => $pdf, 'nome' => PdfService::nomeFile('fascicolo-assemblea', (string) $assemblea->condominio->nome, $assemblea->data?->format('Y-m-d')),
            'documenti' => count($documenti) + 2];
    }

    /** «Uno per persona (.zip)»: per ogni convocato il suo avviso con la delega e i documenti dei punti che riceve. Rende il percorso del file temporaneo. */
    public function perPersona(Assemblea $assemblea): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['fascicolo' => 'Su questo server PHP non ha l\'estensione zip: componi il fascicolo in un PDF unico.']);
        }
        $voci = array_values(array_filter($this->elenco->per($assemblea), fn ($v) => $v['oggi'] || $v['fissato'] !== null));
        if (count($voci) > EmissioneDelleConvocazioni::TETTO) {
            throw ValidationException::withMessages(['fascicolo' => 'Al più ' . EmissioneDelleConvocazioni::TETTO . ' persone per volta.']);
        }
        $documenti = $this->documenti($assemblea);
        $archivio = new ArchivioAPezzi(\RuntimeException::class);
        foreach ($voci as $v) {
            $suoi = $v['punti'] === null ? $documenti : array_values(array_filter($documenti, fn ($d) => in_array($d['numero'], (array) $v['punti'], true)));
            $avviso = $this->emissione->rendi($assemblea, [$this->emissione->avviso($assemblea, $v, $v['mezzo']['mezzo'])]);
            $archivio->aggiungi(EmissioneDelleConvocazioni::nomeDelFile($assemblea, (string) $v['nome']), UnioneDiPdf::unisci([$avviso, ...array_column($suoi, 'contenuto')]));
        }

        return ['file' => $archivio->scrivi(), 'nome' => Str::replaceLast('.pdf', '.zip', PdfService::nomeFile('fascicolo-per-persona',
            (string) $assemblea->condominio->nome, $assemblea->data?->format('Y-m-d')))];
    }

    /**
     * I documenti dei punti, nell'ordine dei punti: il PDF conservato, il nome, le pagine e l'impronta. Oltre il tetto della memoria, un errore che dice quale
     * documento togliere; un documento che manca sul disco o non si legge come PDF ferma, con il suo nome.
     *
     * @return list<array{numero: int, punto: string, nome: string, file: string, contenuto: string, pagine: int, impronta: string}>
     */
    public function documenti(Assemblea $assemblea): array
    {
        $documenti = [];
        $byte = 0;
        foreach ($assemblea->punti()->get() as $punto) {
            foreach ($this->documentiDelPunto($punto) as $d) {
                $contenuto = $this->archivio->leggi($d->path);
                // Un documento che manca o non si legge non si salta in silenzio: il riepilogo lo conta, e i convocati riceverebbero un allegato in meno.
                if ($contenuto === null || ! str_starts_with($contenuto, '%PDF')) {
                    throw ValidationException::withMessages(['fascicolo' => '«' . $d->name . '» del punto ' . $punto->numero . ($contenuto === null
                        ? ' non c\'è più nell\'archivio' : ' non si legge come PDF') . ': toglilo o caricalo di nuovo.']);
                }
                $byte += strlen($contenuto);
                if ($byte > self::MB_DEI_DOCUMENTI * 1024 * 1024) {
                    throw ValidationException::withMessages(['fascicolo' => 'Il fascicolo supera ' . self::MB_DEI_DOCUMENTI . ' MB con «' . $d->name . '» del punto '
                        . $punto->numero . ': togli un documento, o manda quel documento a parte.']);
                }
                $documenti[] = ['numero' => (int) $punto->numero, 'punto' => (string) $punto->testo, 'nome' => (string) ($d->description ?: $d->name),
                    'file' => (string) $d->name, 'contenuto' => $contenuto, 'pagine' => UnioneDiPdf::pagine($contenuto), 'impronta' => hash('sha256', $contenuto)];
            }
            if (self::eLoStatoDiEsecuzione($punto) && $this->documentiDelPunto($punto) === []) {
                $contenuto = app(StampaDelloStatoDiEsecuzione::class)->pdf($punto->assemblea()->firstOrFail());
                $documenti[] = ['numero' => (int) $punto->numero, 'punto' => (string) $punto->testo, 'nome' => StampaDelloStatoDiEsecuzione::TITOLO,
                    'file' => StampaDelloStatoDiEsecuzione::nomeDelFile($punto->assemblea()->firstOrFail()), 'contenuto' => $contenuto,
                    'pagine' => UnioneDiPdf::pagine($contenuto), 'impronta' => hash('sha256', $contenuto)];
            }
        }

        return $documenti;
    }

    /** @return list<Documento> */
    private function documentiDelPunto(PuntoDellOrdineDelGiorno $punto): array
    {
        if ($punto->materia === MateriaPunto::RENDICONTO) {
            $v = $this->versioneDelRendiconto($punto);
            if ($v === null) {
                return [];
            }
            $docs = $v->documenti()->get();
            $fascicolo = collect((array) ($v->pdf['documenti'] ?? []))->first(fn ($p) => in_array($p['parte'] ?? null, ['fascicolo', 'caricato'], true));

            return $fascicolo === null ? $docs->sortBy('id')->values()->all() : array_values(array_filter([$docs->firstWhere('id', (int) $fascicolo['documento_id'])]));
        }
        if ($punto->materia === MateriaPunto::PREVENTIVO) {
            $v = VersioniDelPreventivo::delPunto($punto);

            return $v === null ? [] : $v->documenti()->orderBy('id')->get()->all();
        }
        return DocumentiDeiPunti::delPunto($punto);
    }

    /** La versione del rendiconto del punto: quella collegata al punto, o l'ultima non sostituita dell'esercizio del punto. */
    public function versioneDelRendiconto(PuntoDellOrdineDelGiorno $punto): ?RendicontoVersione
    {
        $collegata = $punto->oggetti()->where('oggetto_tipo', 'rendiconto_versione')->orderByDesc('id')->value('oggetto_id');
        if ($collegata !== null) {
            return RendicontoVersione::query()->find((int) $collegata);
        }
        if ($punto->esercizio_id === null) {
            return null;
        }

        return RendicontoVersione::query()->where('esercizio_id', $punto->esercizio_id)->where('stato', '!=', 'sostituita')->orderByDesc('numero')->first();
    }

    /** @return array{0: string, 1: bool, 2: ?array{parola: string, colore: string}, 3: ?string} */
    private function rigaDelRendiconto(PuntoDellOrdineDelGiorno $punto): array
    {
        $v = $this->versioneDelRendiconto($punto);
        if ($v === null) {
            return ['Il rendiconto non è ancora fermato: lo fermi dalla pagina del rendiconto, con «Ferma per l\'assemblea».', true, ['parola' => 'Da fermare', 'colore' => 'am'], null];
        }
        $fascicolo = collect((array) ($v->pdf['documenti'] ?? []))->first(fn ($p) => in_array($p['parte'] ?? null, ['fascicolo', 'caricato'], true));

        return ['Versione ' . $v->numero . ' fermata il ' . $v->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
            . ($fascicolo !== null ? ' · impronta ' . mb_substr((string) $fascicolo['impronta'], 0, 4) . '…' : ' · il PDF del fascicolo non è conservato'), false,
            ['parola' => 'Fermato', 'colore' => 'em'], null];
    }

    /** @return array{0: string, 1: bool, 2: ?array{parola: string, colore: string}, 3: ?string} */
    private function rigaDelPreventivo(PuntoDellOrdineDelGiorno $punto): array
    {
        $v = VersioniDelPreventivo::delPunto($punto);
        if ($v === null) {
            return ['la distinta, il riparto per tabella, le rate per condòmino', true, null, 'ferma_preventivo'];
        }

        return ['Versione ' . $v->numero . ' fermata il ' . $v->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m')
            . ' · impronta ' . mb_substr((string) $v->impronta, 0, 4) . '…', false, ['parola' => 'Fermato', 'colore' => 'em'], null];
    }

    /** Il punto «Stato di esecuzione delle delibere precedenti» (R10): la sua stampa va nel fascicolo, se non c'è un documento aggiunto. */
    public static function eLoStatoDiEsecuzione(PuntoDellOrdineDelGiorno $punto): bool
    {
        return $punto->chiave_fonte === \App\Services\Assemblee\Seguito\StatoDiEsecuzione::CHIAVE;
    }

    /** @return array{0: string, 1: bool, 2: ?array{parola: string, colore: string}, 3: ?string} */
    private function rigaDegliAllegati(PuntoDellOrdineDelGiorno $punto): array
    {
        $docs = $this->documentiDelPunto($punto);
        if ($docs === [] && self::eLoStatoDiEsecuzione($punto)) {
            return [StampaDelloStatoDiEsecuzione::TITOLO . ', stampato dal programma con l\'elenco del punto', false, ['parola' => 'Pronto', 'colore' => 'em'], null];
        }
        if ($docs === []) {
            return [self::NESSUN_DOCUMENTO, true, null, null];
        }

        return [implode(' · ', array_map(fn (Documento $d) => (string) ($d->description ?: $d->name), $docs)), false, ['parola' => 'Allegati', 'colore' => 'em'], null];
    }

    /** @param list<array<string, mixed>> $documenti */
    private function copertina(Assemblea $assemblea, array $documenti, int $pagineDellAvviso): string
    {
        $avviso = $this->emissione->avviso($assemblea, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null]);
        $indice = [['punto' => '—', 'nome' => 'L\'avviso di convocazione, con la delega', 'pagine' => $pagineDellAvviso, 'impronta' => '—']];
        foreach ($documenti as $d) {
            $indice[] = ['punto' => (string) $d['numero'], 'nome' => $d['nome'], 'pagine' => $d['pagine'], 'impronta' => mb_substr($d['impronta'], 0, 16) . '…'];
        }

        return $this->pdf->generate('pdf.gestionale.assemblea_fascicolo_copertina', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'titolo' => $avviso['titolo'],
            'sedute' => $avviso['sedute'],
            'punti' => $avviso['punti'],
            'indice' => $indice,
            'nota' => 'I documenti dei punti sono le versioni fermate per l\'assemblea, con la loro impronta. Chi è convocato per PEC li riceve con il suo '
                . 'avviso; per la posta e la consegna a mano lo stesso fascicolo si fa per persona, con il suo avviso e la sua delega.',
        ])->Output('', Destination::STRING_RETURN);
    }
}
