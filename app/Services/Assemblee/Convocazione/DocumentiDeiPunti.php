<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Support\LimiteCaricamento;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I documenti dei punti, nel passo «Documenti» della pagina dell'assemblea (la bozza approvata dei documenti dei punti, scene 1, 1b e 2): che cosa riceve
 * ogni condòmino insieme all'avviso. Ogni punto che non è il rendiconto o il preventivo (quei due portano le loro versioni fermate) li prende da tre posti:
 *
 * - **proposti dal programma**: i PDF delle schede di cui parla il punto, per esempio la fattura della ratifica. Il programma li propone e non li aggiunge
 *   mai da solo: la riga del punto dice «Proposto dalla fattura n. 45» e aspetta il clic;
 * - **dai documenti del condominio**: i PDF dell'archivio del condominio, con la ricerca per nome;
 * - **un PDF nuovo**: va nei documenti del condominio, nella categoria «Assemblee» (creata se manca), non pubblicato, e si collega al punto.
 *
 * Un documento di un altro condominio, o il PDF di una scheda di cui il punto non parla, è un 404 (R18). Solo PDF: il fascicolo è un PDF solo. Ogni
 * aggiunta e ogni «Togli» passa dal campo «Parla di» del punto ({@see OrdineDelGiorno::documentiDelPunto()}), con la sua riga del registro.
 */
final class DocumentiDeiPunti
{
    /** I documenti del condominio che la finestra mostra per volta: gli altri si trovano con la ricerca per nome. */
    public const QUANTI_NELLA_FINESTRA = 20;

    /**
     * Le schede di cui il programma propone i PDF. I documenti dei punti arrivano a tutti i condòmini: i PDF di una persona (un accordo di rientro, la
     * dispensa dei sei mesi, l'anagrafica, l'unità) non si propongono mai; l'amministratore li aggiunge a mano se lo decide.
     */
    public const TIPI_PROPOSTI = ['fattura_passiva', 'segnalazione', 'rendiconto_versione', 'preventivo_versione'];

    public const FRASE_NIENTE = 'Scegli un documento, o un PDF nuovo.';

    public const FRASE_RENDICONTO = 'Il rendiconto e il preventivo portano le loro versioni fermate per l\'assemblea: i documenti si aggiungono agli altri punti.';

    public const FRASE_SOLO_PDF = 'Solo PDF: il fascicolo è un PDF solo.';

    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    /** Il punto prende documenti: tutti tranne il rendiconto e il preventivo. */
    public static function accetta(PuntoDellOrdineDelGiorno $punto): bool
    {
        return ! in_array($punto->materia, [MateriaPunto::RENDICONTO, MateriaPunto::PREVENTIVO], true);
    }

    /** I documenti aggiunti al punto, nell'ordine in cui sono stati aggiunti. @return list<Documento> */
    public static function delPunto(PuntoDellOrdineDelGiorno $punto): array
    {
        $ids = OggettoDelPunto::query()->where('punto_id', $punto->id)->where('oggetto_tipo', 'documento')->orderBy('id')->pluck('oggetto_id')
            ->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $documenti = Documento::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => $documenti[$id] ?? null, $ids)));
    }

    /** Un documento è un PDF dal suo tipo, o dall'estensione del file. */
    public static function eUnPdf(Documento $d): bool
    {
        return $d->mime_type === 'application/pdf' || str_ends_with(mb_strtolower((string) $d->path), '.pdf');
    }

    /**
     * Le pagine di un PDF conservato, lette una volta e tenute per il percorso del file (il file non cambia: una versione nuova ha un altro percorso).
     * `null` se il file non si legge come PDF.
     */
    public function pagine(Documento $d): ?int
    {
        if (! self::eUnPdf($d) || trim((string) $d->path) === '') {
            return null;
        }
        $chiave = 'assemblee.pagine.' . sha1((string) $d->path);
        $tenute = Cache::get($chiave);
        if (is_int($tenute) && $tenute > 0) {
            return $tenute;
        }
        $contenuto = $this->archivio->leggi($d->path);
        if ($contenuto === null || ! str_starts_with($contenuto, '%PDF')) {
            return null;
        }
        try {
            $pagine = UnioneDiPdf::pagine($contenuto);
        } catch (\Throwable) {
            return null;
        }
        // Solo un numero vero si tiene: una lettura fallita (il disco che non risponde un momento) si riprova la volta dopo, non resta «niente» per sempre.
        if ($pagine > 0) {
            Cache::forever($chiave, $pagine);
        }

        return $pagine > 0 ? $pagine : null;
    }

    /**
     * I documenti del condominio che la finestra propone e «Aggiungi» accetta: quelli dell'archivio (non legati a un'unità o ad altro, come
     * `DocumentoService::getAdminBaseQuery()`), approvati. Il rogito caricato dalla pagina di un'unità, o il documento di un condòmino non ancora approvato,
     * non partono con la convocazione a tutti.
     */
    private static function dellArchivio(Condominio $condominio): \Illuminate\Database\Eloquent\Builder
    {
        return Documento::query()->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->whereNull('documentable_type')
            ->where('is_approved', true);
    }

    /** Un documento come lo mostra la pagina. @return array{id: int, nome: string, pagine: ?int} */
    public function voce(Documento $d): array
    {
        return ['id' => (int) $d->id, 'nome' => (string) $d->name, 'pagine' => $this->pagine($d)];
    }

    /**
     * I PDF proposti dal programma, una voce per scheda: «Proposto dalla fattura n. 45», «Dalla fattura» al telefono, i documenti e le loro pagine. Senza
     * quelli già nel punto; un PDF proposto da due schede si propone una volta.
     *
     * @return list<array{da: string, breve: string, documenti: list<array{id: int, nome: string, pagine: ?int}>, pagine: ?int}>
     */
    public function proposti(PuntoDellOrdineDelGiorno $punto): array
    {
        $gia = array_map(fn (Documento $d) => (int) $d->id, self::delPunto($punto));
        $visti = [];
        $voci = [];
        foreach ($this->schede($punto) as [$tipo, $scheda]) {
            $documenti = [];
            foreach ($scheda->documenti()->orderBy('id')->get() as $d) {
                if (! self::eUnPdf($d) || in_array((int) $d->id, $gia, true) || isset($visti[(int) $d->id])) {
                    continue;
                }
                $visti[(int) $d->id] = true;
                $documenti[] = $this->voce($d);
            }
            if ($documenti === []) {
                continue;
            }
            [$da, $breve] = self::daDove($tipo, $scheda);
            $pagine = array_column($documenti, 'pagine');
            $voci[] = ['da' => (count($documenti) === 1 ? 'Proposto ' : 'Proposti ') . $da, 'breve' => $breve, 'documenti' => $documenti,
                'pagine' => in_array(null, $pagine, true) ? null : array_sum($pagine)];
        }

        return $voci;
    }

    /**
     * Le schede del punto da cui il programma propone i PDF: quelle dei tipi proposti, che hanno i documenti.
     *
     * @return list<array{0: string, 1: \Illuminate\Database\Eloquent\Model}>
     */
    private function schede(PuntoDellOrdineDelGiorno $punto): array
    {
        $schede = [];
        $oggetti = $punto->oggetti()->where('ruolo', '!=', RuoloOggettoPunto::DOCUMENTO->value)->whereIn('oggetto_tipo', self::TIPI_PROPOSTI)->orderBy('id')->get();
        foreach ($oggetti as $o) {
            [$classe] = SchedaDelCondominio::TIPI[$o->oggetto_tipo];
            $scheda = $classe::query()->find($o->oggetto_id);
            if ($scheda === null || ! method_exists($scheda, 'documenti') || ! $scheda->documenti() instanceof MorphMany) {
                continue;
            }
            $schede[] = [(string) $o->oggetto_tipo, $scheda];
        }

        return $schede;
    }

    /** «dalla fattura n. 45» e «Dalla fattura». @return array{0: string, 1: string} */
    private static function daDove(string $tipo, \Illuminate\Database\Eloquent\Model $scheda): array
    {
        return match ($tipo) {
            'fattura_passiva' => ['dalla fattura n. ' . $scheda->getAttribute('numero_documento'), 'Dalla fattura'],
            'segnalazione' => ['dalla segnalazione', 'Dalla segnalazione'],
            'rendiconto_versione' => ['dal rendiconto, versione ' . $scheda->getAttribute('numero'), 'Dal rendiconto'],
            'preventivo_versione' => ['dal preventivo, versione ' . $scheda->getAttribute('numero'), 'Dal preventivo'],
            default => ['dalla scheda del punto', 'Dalla scheda'],
        };
    }

    /** La riga grigia sotto il testo del punto: la scheda da cui nasce («Fattura n. 45 · Idraulica Bianchi · € 1.800,00»), se c'è. */
    public static function sotto(PuntoDellOrdineDelGiorno $punto): ?string
    {
        $origine = $punto->oggetti()->where('ruolo', RuoloOggettoPunto::ORIGINE->value)->orderBy('id')->first();
        if ($origine === null || ! isset(SchedaDelCondominio::TIPI[$origine->oggetto_tipo])) {
            return null;
        }
        [$classe] = SchedaDelCondominio::TIPI[$origine->oggetto_tipo];
        $scheda = $classe::query()->find($origine->oggetto_id);

        return $scheda === null ? null : SchedaDelCondominio::nomeDi($scheda);
    }

    /**
     * La finestra «Aggiungi un documento»: il punto, i PDF proposti, i documenti del condominio (i più recenti, o quelli che la ricerca trova per nome),
     * il tetto del file nuovo.
     *
     * @return array<string, mixed>
     */
    public function perLaFinestra(Condominio $condominio, PuntoDellOrdineDelGiorno $punto, ?string $cerca): array
    {
        self::soloPerIPuntiCheLiPrendono($punto);
        $gia = array_map(fn (Documento $d) => (int) $d->id, self::delPunto($punto));
        $proposti = [];
        foreach ($this->proposti($punto) as $voce) {
            foreach ($voce['documenti'] as $d) {
                $proposti[] = $d + ['da' => mb_strtolower(mb_substr($voce['breve'], 0, 1)) . mb_substr($voce['breve'], 1)];
            }
        }
        $cerca = trim((string) $cerca);
        $query = self::dellArchivio($condominio)
            ->when($cerca !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes(mb_substr($cerca, 0, 100), '\\%_') . '%'));
        $quanti = (clone $query)->count();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        // Un documento dell'archivio legato a una persona resta sceglibile, ma la finestra lo dice: arriva a tutti i convocati.
        $documenti = $query->with('categorie:id,name')->withCount('anagrafiche')->orderByDesc('created_at')->orderByDesc('id')->limit(self::QUANTI_NELLA_FINESTRA)
            ->get()->map(fn (Documento $d) => ['id' => (int) $d->id, 'nome' => (string) $d->name, 'pdf' => self::eUnPdf($d), 'pagine' => $this->pagine($d),
                'categorie' => $d->categorie->pluck('name')->implode(', '), 'caricato_il' => $d->created_at?->copy()->setTimezone($fuso)->format('d/m/Y'),
                'gia' => in_array((int) $d->id, $gia, true), 'di_una_persona' => (int) $d->anagrafiche_count > 0])->all();

        return [
            'punto' => ['numero' => (int) $punto->numero, 'testo' => (string) $punto->testo, 'sotto' => self::sotto($punto)],
            'proposti' => $proposti,
            'condominio' => ['nome' => (string) $condominio->nome, 'documenti' => $documenti, 'altri' => max(0, $quanti - count($documenti))],
            'tetto' => LimiteCaricamento::etichetta(),
        ];
    }

    /**
     * «Aggiungi»: i documenti scelti (proposti, o del condominio) e il PDF nuovo, nel punto, in una riga del registro. Rende quanti documenti sono entrati.
     *
     * @param list<int> $ids
     */
    public function aggiungi(Condominio $condominio, PuntoDellOrdineDelGiorno $punto, array $ids, ?UploadedFile $file, User $utente): int
    {
        OrdineDelGiorno::siPuoCambiare($punto->assemblea);
        self::soloPerIPuntiCheLiPrendono($punto);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] && $file === null) {
            throw ValidationException::withMessages(['documenti' => self::FRASE_NIENTE]);
        }
        $this->scelti($condominio, $punto, $ids);
        $gia = array_map(fn (Documento $d) => (int) $d->id, self::delPunto($punto));
        $nuovi = array_values(array_diff($ids, $gia));
        $percorso = $file === null ? null : $this->archivio->salva($file, ArchivioDocumenti::CARTELLA . '/' . $condominio->id);
        try {
            DB::transaction(function () use ($condominio, $punto, $file, $percorso, $utente, &$nuovi) {
                if ($file !== null) {
                    $nuovi[] = (int) $this->nuovo($condominio, $file, (string) $percorso, $utente)->id;
                }
                OrdineDelGiorno::documentiDelPunto($punto, $nuovi);
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }

        return count($nuovi);
    }

    /** «Togli»: il documento esce dal punto e resta nei documenti del condominio. Un documento che il punto non ha: 404. */
    public function togli(PuntoDellOrdineDelGiorno $punto, int $documento, User $utente): Documento
    {
        OrdineDelGiorno::siPuoCambiare($punto->assemblea);
        $d = collect(self::delPunto($punto))->first(fn (Documento $x) => (int) $x->id === $documento);
        abort_if($d === null, 404);
        OrdineDelGiorno::documentiDelPunto($punto, [], [$documento]);

        return $d;
    }

    /**
     * I documenti scelti, riletti dal server (R18): un PDF proposto dal punto o un documento del condominio; un altro è 404. Uno che non è un PDF lo dice
     * con il suo nome.
     *
     * @param list<int> $ids
     * @return list<Documento>
     */
    private function scelti(Condominio $condominio, PuntoDellOrdineDelGiorno $punto, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $proposti = [];
        foreach ($this->schede($punto) as [, $scheda]) {
            foreach ($scheda->documenti()->pluck('id') as $id) {
                $proposti[] = (int) $id;
            }
        }
        $delCondominio = self::dellArchivio($condominio)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $gia = array_map(fn (Documento $d) => (int) $d->id, self::delPunto($punto));
        foreach ($ids as $id) {
            abort_unless(in_array($id, $proposti, true) || in_array($id, $delCondominio, true) || in_array($id, $gia, true), 404);
        }
        $documenti = Documento::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! self::eUnPdf($documenti[$id])) {
                throw ValidationException::withMessages(['documenti' => '«' . $documenti[$id]->name . '» non è un PDF: il fascicolo è un PDF solo.']);
            }
            // Il tipo salvato lo dice il browser che l'ha caricato: entra solo un file che si legge come PDF, così il riepilogo conta ciò che parte.
            if (! in_array($id, $gia, true) && $this->pagine($documenti[$id]) === null) {
                throw ValidationException::withMessages(['documenti' => '«' . $documenti[$id]->name . '» non si legge come PDF: non va nel fascicolo.']);
            }
        }

        return array_values(array_map(fn (int $id) => $documenti[$id], $ids));
    }

    /**
     * Il PDF nuovo nei documenti del condominio, nella categoria «Assemblee», non pubblicato: si pubblica dall'archivio, se serve. Nasce e si lega nella
     * stessa transazione, così la categoria e il condominio stanno nella sua «Creata».
     */
    private function nuovo(Condominio $condominio, UploadedFile $file, string $percorso, User $utente): Documento
    {
        $categoria = CategoriaDocumento::perLeAssemblee();

        return DB::transaction(function () use ($condominio, $file, $percorso, $utente, $categoria) {
            $nome = (string) $file->getClientOriginalName();
            $documento = Documento::create([
                'name' => $nome,
                'description' => (string) preg_replace('/\.pdf$/iu', '', $nome),
                'path' => $percorso,
                'mime_type' => 'application/pdf',
                'file_size' => $file->getSize(),
                'created_by' => $utente->id,
                'is_published' => false,
                'is_approved' => true,
            ]);
            RegistroModifiche::legame($documento, 'categorie', 'Categorie', fn () => $documento->categorie()->attach($categoria->id), 'name');
            RegistroModifiche::legame($documento, 'condomini', 'Condomini', fn () => $documento->condomini()->attach($condominio->id));

            return $documento;
        });
    }

    private static function soloPerIPuntiCheLiPrendono(PuntoDellOrdineDelGiorno $punto): void
    {
        if (! self::accetta($punto)) {
            throw ValidationException::withMessages(['documenti' => self::FRASE_RENDICONTO]);
        }
    }
}
