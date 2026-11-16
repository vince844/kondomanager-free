<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Gestionale\FascicoloNonPreparabileException;
use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\FascicoloLegale;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il fascicolo per il legale di una persona, dal riquadro «Sei mesi per agire» e dalla scheda «Corrispondenza» del suo estratto conto: l'anteprima per la
 * finestra (che cosa c'è e che cosa manca, in sola lettura), l'archivio .zip con la riga del registro e i due documenti conservati, e quei documenti riaperti
 * dalla pagina della persona. La persona deve essere di questo condominio, altrimenti 404; i documenti devono essere della sua riga. Nessun permesso nuovo:
 * lo prepara chi vede l'estratto conto e il registro delle lettere. Nessun collegamento dal portale.
 */
class FascicoloLegaleController extends Controller
{
    public function __construct(private readonly FascicoloLegale $fascicolo) {}

    public function anteprima(Condominio $condominio, Anagrafica $anagrafica): JsonResponse
    {
        $this->assicuraPersonaDelCondominio($condominio, $anagrafica);

        return response()->json($this->fascicolo->anteprima($condominio, $anagrafica));
    }

    /** «Scarica il fascicolo»: le rate scelte (`rate[]`, le chiavi «rata|unità») e le parti scelte (`parti[]`). Lo .zip va al browser e si cancella dopo. */
    public function prepara(Request $request, Condominio $condominio, Anagrafica $anagrafica): Response
    {
        $this->assicuraPersonaDelCondominio($condominio, $anagrafica);
        $dati = $request->validate([
            'rate' => ['nullable', 'array'], 'rate.*' => ['string', 'max:40'],
            'parti' => ['nullable', 'array'], 'parti.*' => ['string', Rule::in(FascicoloLegale::PARTI)],
        ], ['rate.array' => FascicoloLegale::FRASE_SCEGLI_UNA_RATA, 'rate.*' => FascicoloLegale::FRASE_RATA_NON_SCADUTA, 'parti.*' => FascicoloLegale::FRASE_PARTE,
            'parti.array' => FascicoloLegale::FRASE_PARTE]);

        try {
            $esito = $this->fascicolo->prepara($condominio, $anagrafica, $dati['rate'] ?? [], $dati['parti'] ?? [], $request->user());
        } catch (FascicoloNonPreparabileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->download($esito['zip'], $esito['nome'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** L'indice o il prospetto di un fascicolo preparato, dalla riga della persona: si apre nel browser. */
    public function documento(Condominio $condominio, Anagrafica $anagrafica, string|int $documento, ArchivioDocumenti $archivio): Response
    {
        $this->assicuraPersonaDelCondominio($condominio, $anagrafica);
        $riga = RispostaPromemoria::where('chiave', RispostePromemoria::chiave(RispostePromemoria::PERSONA, (int) $condominio->id, null, (int) $anagrafica->id))->value('id');
        $d = $riga === null || ! is_numeric($documento) ? null
            : Documento::where('documentable_type', RispostaPromemoria::class)->where('documentable_id', $riga)->whereKey((int) $documento)->first();
        abort_if($d === null || ! $archivio->esiste($d->path), 404);

        return $archivio->apri($d->path, $d->nomeDiScaricamento(), (string) $d->mime_type);
    }

    /** La persona è di questo condominio, altrimenti 404. */
    private function assicuraPersonaDelCondominio(Condominio $condominio, Anagrafica $anagrafica): void
    {
        abort_unless($anagrafica->condomini()->where('condomini.id', $condominio->id)->exists(), 404);
    }
}
