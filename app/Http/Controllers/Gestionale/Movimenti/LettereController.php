<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Gestionale\LetteraNonEmettibileException;
use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Models\ModelloTesto;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\ModelliLettere;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le lettere ai condòmini (promemoria, sollecito, diffida, messa in mora) dall'elenco delle rate scadute e dalla pagina dell'estratto conto:
 * le opzioni della finestra, l'anteprima, la stampa, il .zip per persona, l'invio per email, la prova a sé, «riprova» e «invia di nuovo»;
 * «Salva come modello» e «Aggiorna» del modello di partenza; la spedizione registrata a mano, una o in blocco; il PDF conservato e le prove.
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello; le persone, le righe del registro, i modelli e i documenti della richiesta
 * devono essere di questo condominio (un modello anche per tutti), altrimenti 404 per tutta la richiesta.
 */
class LettereController extends Controller
{
    use HandleFlashMessages;

    /** Che cosa dice la pagina delle rate scadute a chi arriva con un GET su un indirizzo di un PDF (la scheda ricaricata o riaperta). */
    public const FRASE_RIAPRI_PDF = 'Il PDF delle lettere non si riapre dall\'indirizzo della scheda, e aprire quell\'indirizzo non ha stampato niente: '
        . 'le lettere già emesse si leggono nella pagina dell\'estratto conto di ogni persona; di sollecito, diffida e messa in mora anche il PDF.';

    public function __construct(private readonly EmissioneLettere $lettere, private readonly RegistroLettere $registro, private readonly ModelliLettere $modelli) {}

    public function opzioni(Request $request, Condominio $condominio): JsonResponse
    {
        return response()->json($this->lettere->opzioni($condominio, $request->user()) + [
            'puo_salvare_il_testo' => self::puoSalvareIlTesto($request),
        ]);
    }

    public function anteprima(Request $request, Condominio $condominio): JsonResponse
    {
        return $this->json(fn () => $this->lettere->anteprima($condominio, $request->all()));
    }

    /** La stampa: le lettere in un PDF. Gli errori tornano come testo, perché la risposta si apre in una scheda del browser. */
    public function stampa(Request $request, Condominio $condominio): Response
    {
        return $this->pdf($request, $condominio, false);
    }

    /** Lo stesso PDF con «ANTEPRIMA» in testa: niente nel registro. */
    public function anteprimaPdf(Request $request, Condominio $condominio): Response
    {
        return $this->pdf($request, $condominio, true);
    }

    public function pdfPerPersona(Request $request, Condominio $condominio): Response
    {
        try {
            $esito = $this->lettere->archivioPerPersona($condominio, $request->all(), $request->user());
        } catch (LetteraNonEmettibileException $e) {
            return response('Archivio non fatto. ' . $e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        // Lo .zip è un file temporaneo: va al browser letto a pezzi, e si cancella dopo.
        return response()->download($esito['zip'], $esito['nome'], ['Content-Type' => 'application/zip', 'X-Persone-Aggiunte' => (string) $esito['aggiunte'],
            'X-Lettere-Invii' => self::invii($esito['invii'])])->deleteFileAfterSend();
    }

    /** L'invio per email. Un indirizzo scritto a mano conta nel limite di dieci al minuto. */
    public function invia(Request $request, Condominio $condominio): JsonResponse
    {
        if ($request->filled('altro_indirizzo') && ! LimiteAltroIndirizzo::consuma($request->user())) {
            return self::troppiInvii($request);
        }

        return $this->json(fn () => $this->lettere->invia($condominio, $request->all(), $request->user()));
    }

    public function prova(Request $request, Condominio $condominio): JsonResponse
    {
        return $this->json(fn () => $this->lettere->provaAMe($condominio, $request->all(), $request->user()));
    }

    /** «Riprova le non partite»: del condominio, o di una persona del condominio (un'altra è un 404 per tutta la richiesta). */
    public function riprova(Request $request, Condominio $condominio): JsonResponse
    {
        $persona = is_numeric($request->input('anagrafica_id')) ? (int) $request->input('anagrafica_id') : null;
        abort_if($persona !== null && ! \App\Models\Anagrafica::whereKey($persona)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->exists(), 404);

        return $this->json(fn () => $this->lettere->riprova($condominio, $persona, $request->user()));
    }

    public function inviaDiNuovo(Request $request, Condominio $condominio, string|int $invio): JsonResponse
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        if ($riga->canale === RegistroInvio::CANALE_EMAIL && LimiteAltroIndirizzo::aMano($riga) && ! LimiteAltroIndirizzo::consuma($request->user())) {
            return self::troppiInvii($request);
        }

        return $this->json(fn () => $this->lettere->inviaDiNuovo($condominio, $riga, $request->user()));
    }

    /**
     * «Salva come modello…»: il testo della finestra diventa un modello con un nome, per tutti i condomini o solo per questo. I modelli sono dello
     * studio: li salva chi gestisce le impostazioni di stampa. La risposta porta il modello e i modelli del condominio di nuovo.
     */
    public function salvaModello(Request $request, Condominio $condominio): JsonResponse
    {
        abort_unless(self::puoSalvareIlTesto($request), 403);
        $dati = $request->validate(ModelliLettere::regole(true) + ['per_tutti' => ['required', 'boolean']],
            ModelliLettere::messaggi() + ['per_tutti.required' => 'Scegli per quali condomini vale il modello: tutti, o solo questo.']);
        $modello = $this->modelli->salva(null, ['tipo' => $dati['tipo'], 'nome' => $dati['nome'], 'testo' => $dati['testo'],
            'condominio_id' => filter_var($dati['per_tutti'], FILTER_VALIDATE_BOOLEAN) ? null : (int) $condominio->id], $request->user());

        return response()->json(['modello' => ModelliLettere::perLaFinestra($modello), 'modelli' => $this->modelli->delCondominio($condominio)], 201);
    }

    /** «Aggiorna «nome»»: il testo della finestra nel modello da cui è partito; il nome e il «vale per» restano. Un modello di un altro condominio: 404. */
    public function aggiornaModello(Request $request, Condominio $condominio, string|int $modello): JsonResponse
    {
        $esistente = $this->assicuraModello($condominio, $modello);
        abort_unless(self::puoSalvareIlTesto($request), 403);
        $dati = $request->validate(['testo' => ModelliLettere::regole(false)['testo']], ModelliLettere::messaggi());
        $esistente = $this->modelli->aggiornaIlTesto($esistente, $dati['testo'], $request->user());

        return response()->json(['modello' => ModelliLettere::perLaFinestra($esistente), 'modelli' => $this->modelli->delCondominio($condominio)]);
    }

    /** «Registra la spedizione», una o in blocco. */
    public function spedizione(Request $request, Condominio $condominio): JsonResponse
    {
        $dati = $request->validate([
            'invii'   => ['required', 'array', 'min:1'],
            'invii.*' => ['integer'],
            'canale'  => ['required', Rule::in(array_keys(RegistroLettere::CANALI_SPEDIZIONE))],
            'data'    => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'numeri'  => ['nullable', 'array'],
            'numeri.*' => ['nullable', 'string', 'max:100'],
            'nota'    => ['nullable', 'string', 'max:1000'],
        ], [
            'data.before_or_equal' => 'La data della spedizione non può essere dopo oggi.',
            'canale.required' => 'Scegli come è stata spedita.',
        ]);

        return $this->json(fn () => ['spedite' => $this->registro->registraSpedizione($condominio, $dati['invii'], $dati['canale'], $dati['data'],
            (array) ($dati['numeri'] ?? []), $dati['nota'] ?? null, $request->user())]);
    }

    /** «Correggi la spedizione» di una lettera spedita: i dati nuovi, con quelli di prima nel registro. */
    public function correggiSpedizione(Request $request, Condominio $condominio, string|int $invio): JsonResponse
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        $dati = $request->validate([
            'canale' => ['required', Rule::in(array_keys(RegistroLettere::CANALI_SPEDIZIONE))],
            'data'   => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'numero' => ['nullable', 'string', 'max:100'],
            'nota'   => ['nullable', 'string', 'max:1000'],
        ], [
            'data.before_or_equal' => 'La data della spedizione non può essere dopo oggi.',
            'canale.required' => 'Scegli come è stata spedita.',
        ]);

        return $this->json(function () use ($riga, $dati, $request) {
            $this->registro->correggiSpedizione($riga, $dati['canale'], $dati['data'], $dati['numero'] ?? null, $dati['nota'] ?? null, $request->user());

            return ['corretta' => true];
        });
    }

    /** Il PDF conservato di una lettera. */
    public function pdfConservato(Condominio $condominio, string|int $invio)
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        $documento = isset($riga->dati['documento_id']) ? Documento::find((int) $riga->dati['documento_id']) : null;
        abort_if($documento === null || $documento->documentable_type !== RegistroInvio::class || ! app(ArchivioDocumenti::class)->esiste($documento->path), 404);

        return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());
    }

    /** «Allega la prova»: PDF, immagine, `.eml`, `.p7m`, fino a 10 MB. */
    public function allegaProva(Request $request, Condominio $condominio, string|int $invio): RedirectResponse
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        $request->validate([
            // `extensions:` e non `mimes:`: un `.p7m` e un `.eml` non hanno un contenuto che `finfo` riconosca. Un PDF, un JPG o un PNG invece
            // devono esserlo davvero (`FileComeIlNome`). Il file va su un disco privato con un nome casuale e l'estensione del nome, e si scarica soltanto.
            'file' => ['required', 'file', 'extensions:' . implode(',', RegistroLettere::ESTENSIONI_PROVA), 'max:' . LimiteCaricamento::regolaMax(RegistroLettere::MB_PROVA),
                \App\Support\FileComeIlNome::regola()],
        ], [
            'file.extensions' => 'La prova è un PDF, un\'immagine (JPG o PNG), un messaggio .eml o un file firmato .p7m.',
        ]);
        try {
            $this->registro->allegaProva($riga, $request->file('file'), $request->user());
        } catch (LetteraNonEmettibileException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with($this->flashSuccess('Prova allegata.'));
    }

    public function scaricaProva(Condominio $condominio, string|int $invio, string|int $documento)
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        $doc = $this->provaDellaRiga($riga, $documento);

        return app(ArchivioDocumenti::class)->scarica($doc->path, $doc->nomeDiScaricamento());
    }

    public function togliProva(Request $request, Condominio $condominio, string|int $invio, string|int $documento): RedirectResponse
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        try {
            $this->registro->togliProva($riga, $this->provaDellaRiga($riga, $documento), $request->user());
        } catch (LetteraNonEmettibileException $e) {
            return back()->withErrors(['prova' => $e->getMessage()]);
        }

        return back()->with($this->flashSuccess('Prova tolta: nel registro resta che c\'era.'));
    }

    /** Un GET su un indirizzo dei PDF delle lettere: non rifà niente, riporta alle rate scadute con la frase. */
    public function riapriPdf(Condominio $condominio): RedirectResponse
    {
        return to_route('admin.gestionale.rate-scadute.index', ['condominio' => $condominio->id])->with($this->flashWarning(self::FRASE_RIAPRI_PDF));
    }

    /** Il modello della rotta: un modello delle lettere di questo condominio o per tutti, altrimenti 404. */
    private function assicuraModello(Condominio $condominio, string|int $modello): ModelloTesto
    {
        return ModelliLettere::delCondominioOppure404($condominio, $modello);
    }

    /** La riga del registro della rotta: una lettera di questo condominio, altrimenti 404. */
    private function assicuraInvio(Condominio $condominio, string|int $invio): RegistroInvio
    {
        $riga = $this->registro->righeDelCondominio($condominio, [$invio])->first();
        abort_unless((int) $riga->condominio_id === (int) $condominio->id, 404);

        return $riga;
    }

    private function provaDellaRiga(RegistroInvio $riga, string|int $documento): Documento
    {
        abort_unless(is_numeric($documento), 404);
        $doc = Documento::find((int) $documento);
        abort_unless($doc !== null && $doc->documentable_type === RegistroInvio::class && (int) $doc->documentable_id === (int) $riga->id
            && (int) ($riga->dati['documento_id'] ?? 0) !== (int) $doc->id, 404);

        return $doc;
    }

    private function pdf(Request $request, Condominio $condominio, bool $anteprima): Response
    {
        try {
            $esito = $this->lettere->stampa($condominio, $request->all(), $request->user(), $anteprima);
        } catch (LetteraNonEmettibileException $e) {
            return response(($anteprima ? 'Anteprima non fatta. ' : 'Lettere non stampate. ') . $e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($esito['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $esito['nome'] . '"')
            ->header('X-Persone-Aggiunte', (string) $esito['aggiunte'])
            ->header('X-Lettere-Invii', self::invii($esito['invii']));
    }

    /**
     * Le righe scritte, per «Registra la spedizione» subito dopo la stampa: «riga:persona», separate da virgole.
     *
     * @param array<int, int> $invii
     */
    private static function invii(array $invii): string
    {
        return implode(',', array_map(fn ($riga, $persona) => $riga . ':' . $persona, array_keys($invii), $invii));
    }

    /**
     * I modelli delle lettere sono dello studio, accanto alle impostazioni di stampa: li salva chi ha il loro permesso, quello che chiede
     * `PrintSettingsPolicy::manage`. Si chiede con `can()` e non con la policy, che solleva un'eccezione quando il permesso non è nel database (un'installazione a metà).
     */
    private static function puoSalvareIlTesto(Request $request): bool
    {
        return $request->user()?->can(\App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value) === true;
    }

    private static function troppiInvii(Request $request): JsonResponse
    {
        return response()->json(['message' => LimiteAltroIndirizzo::FRASE], 429, ['Retry-After' => LimiteAltroIndirizzo::secondiAllaProssima($request->user())]);
    }

    private function json(callable $azione): JsonResponse
    {
        try {
            return response()->json($azione());
        } catch (LetteraNonEmettibileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
