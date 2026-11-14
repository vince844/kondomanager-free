<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Gestionale\FogliNonInviatiException;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\FogliDelConguaglio;
use App\Services\Gestionale\InvioDeiFogli;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I fogli del conguaglio per email, dalla scheda «Conguaglio» ({@see InvioDeiFogli}): la finestra (le opzioni, i modelli dello studio), l'anteprima,
 * l'invio, il rinvio dei non partiti, il PDF partito di una riga del registro e lo stato degli invii che la scheda rilegge dopo un invio. Le rotte
 * stanno nel gruppo del gestionale, dietro il pannello; l'esercizio di un altro condominio fa 404 per tutta la richiesta (`scopeBindings()`), con la
 * guardia in ogni metodo; una persona o una riga del registro che non è di questo condominio e di questo esercizio fa 404.
 */
class RendicontoConguaglioInvioController extends Controller
{
    /** Le persone di cui la scheda chiede lo stato, al massimo: una pagina della scheda ne ha meno. */
    public const PERSONE_PER_STATO = 1000;

    public function __construct(private readonly InvioDeiFogli $invio) {}

    /** La finestra: l'oggetto, il testo del programma e i modelli, gli ultimi indirizzi, i campi, il server di posta, i tetti. */
    public function opzioni(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        return response()->json($this->invio->opzioni($condominio, $request->user()));
    }

    /** L'anteprima di chi riceve il foglio, a quali indirizzi, con quale messaggio. */
    public function anteprima(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        return $this->json(fn () => $this->invio->anteprima($condominio, $esercizio, $request->all()));
    }

    /** L'invio dei fogli delle persone scelte e viste nell'anteprima. La scelta delle persone si ricorda, come per l'archivio. */
    public function invia(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        if (in_array($request->input('chi'), FogliDelConguaglio::SCELTE, true)) {
            $request->session()->put(FogliDelConguaglio::chiaveScelta($condominio), (string) $request->input('chi'));
        }

        return $this->json(fn () => $this->invio->invia($condominio, $esercizio, $request->all(), $request->user()));
    }

    /**
     * Il rinvio dei fogli non partiti, di una persona o di tutte. Una persona senza fogli di questo esercizio e non legata al condominio fa 404
     * ({@see InvioDeiFogli::riprova()}): chi ha un foglio qui passa anche staccato dal condominio.
     */
    public function riprova(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        // Senza la chiave, tutte le persone; con la chiave, un intero positivo, altrimenti 404 (non «tutte»).
        $persona = null;
        if ($request->has('anagrafica_id')) {
            $valore = $request->input('anagrafica_id');
            abort_unless(is_int($valore) || (is_string($valore) && ctype_digit($valore)), 404);
            $persona = (int) $valore;
            abort_if($persona < 1, 404);
        }

        return $this->json(fn () => $this->invio->riprova($condominio, $esercizio, $persona, $request->user()));
    }

    /**
     * Lo stato degli invii, come la scheda lo dice: l'ultimo invio delle persone chieste (quelle della pagina), quante sono da rimandare, quanti
     * fogli sono in coda da più di un'ora. La scheda lo rilegge dopo un invio o un rinvio. Un GET: non scrive niente.
     */
    public function stato(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $persone = array_slice(array_values(array_unique(array_map('intval', array_filter((array) $request->query('persone', []), 'is_numeric')))), 0, self::PERSONE_PER_STATO);

        return response()->json($this->invio->perLaScheda($condominio, $esercizio, $persone));
    }

    /** Il foglio partito: il PDF conservato di una riga del registro di questo esercizio, altrimenti 404. */
    public function pdf(Condominio $condominio, Esercizio $esercizio, string $invio, ArchivioDocumenti $archivio)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $riga = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', InvioDeiFogli::TIPO)
            ->where('documentabile_type', Esercizio::class)->where('documentabile_id', $esercizio->id)->find((int) $invio);
        $documento = isset($riga?->dati['documento_id']) ? Documento::find((int) $riga->dati['documento_id']) : null;
        abort_if($documento === null || $documento->documentable_type !== RegistroInvio::class || ! $archivio->esiste($documento->path), 404);

        return $archivio->scarica($documento->path, $documento->nomeDiScaricamento());
    }

    private function json(callable $azione): JsonResponse
    {
        try {
            return response()->json($azione());
        } catch (FogliNonInviatiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
