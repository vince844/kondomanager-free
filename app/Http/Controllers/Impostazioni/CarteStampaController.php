<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Models\CartaStampa;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\CartaIntestata;
use App\Services\PDF\CarteDelloStudio;
use App\Services\PDF\ProvaDellaCarta;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Le carte dello studio, da «Impostazioni → Stampe»: creare, cambiare ed eliminare una carta, scegliere la carta per ogni gruppo di documenti,
 * provarla. Le rotte sono di chi gestisce le impostazioni (il permesso nel gruppo di `routes/settings.php`). `{carta}` arriva grezzo: che sia
 * una carta lo controlla il controller (404). Le regole stanno in {@see CarteDelloStudio}.
 *
 * La carta intestata caricata si controlla prima di toccare niente ({@see CartaIntestata}: il PDF per la strada della stampa, e si salva rifatto con
 * le sole pagine; l'immagine, un JPG, si salva senza i dati della foto, senza riscriverla): un file che non si usa è un errore del campo, e la carta resta com'era. Il file nuovo si salva prima di cancellare il vecchio,
 * e il vecchio si cancella dopo il salvataggio della carta; se il salvataggio non riesce, il file nuovo si toglie. Il nome del file è casuale, sul
 * disco dei file pubblici, come il logo e la firma.
 */
class CarteStampaController extends Controller
{
    use HandleFlashMessages;

    private const CARTELLA = 'settings/carte';

    public function __construct(private readonly CarteDelloStudio $carte, private readonly ArchivioDocumenti $archivio) {}

    public function store(Request $request): RedirectResponse
    {
        $dati = $this->dati($request, null);
        [$carta, $caricata] = $this->salvaConLaCarta(null, $dati, $request);

        return back()->with($this->flashSuccess($caricata
            ? "Carta «{$carta->nome}» salvata con la carta intestata. ".self::daProvare($carta)
            : "Carta «{$carta->nome}» salvata. Con «Prova la carta» la vedi su una lettera e su una pagina orizzontale."));
    }

    public function update(Request $request, string $carta): RedirectResponse
    {
        $esistente = self::oppure404($carta);
        $conIlFile = filled($esistente->carta_path);
        $dati = $this->dati($request, $esistente);
        [, $caricata] = $this->salvaConLaCarta($esistente, $dati, $request);
        $usata = self::usata($esistente);

        return back()->with($this->flashSuccess(match (true) {
            $caricata => "Carta «{$esistente->nome}» salvata con la carta intestata nuova. ".self::daProvare($esistente),
            $conIlFile && ! filled($esistente->carta_path) => "Carta «{$esistente->nome}» salvata a campi: il file della carta intestata è tolto."
                .($usata ? ' Le stampe che la usano escono così.' : ''),
            $usata => "Carta «{$esistente->nome}» salvata: le stampe che la usano escono così.",
            default => "Carta «{$esistente->nome}» salvata. Nessun documento la usa ancora: la scegli in «Quale carta per quale documento».",
        }));
    }

    /** Le stampe usano la carta: è la predefinita, o è scelta per un gruppo. */
    private static function usata(CartaStampa $carta): bool
    {
        return $carta->predefinita || CarteDelloStudio::gruppiDi($carta) !== [];
    }

    /**
     * Dopo il caricamento, la prova: una carta che le stampe usano già (la predefinita, o scelta per un gruppo) esce così da subito; una che nessuno
     * usa ancora si prova prima di sceglierla.
     */
    private static function daProvare(CartaStampa $carta): string
    {
        return self::usata($carta)
            ? 'Le stampe che la usano escono già così: aprila con «Prova la carta» e guarda dove cadono il condominio, il testo e il piede.'
            : 'Prima di usarla, aprila con «Prova la carta»: guarda dove cadono il condominio, il testo e il piede.';
    }

    public function destroy(string $carta): RedirectResponse
    {
        $esistente = self::oppure404($carta);
        if (($rifiuto = CarteDelloStudio::rifiutoEliminazione($esistente)) !== null) {
            return back()->with($this->flashError($rifiuto));
        }
        $nome = $esistente->nome;
        $file = $esistente->carta_path;
        $esistente->delete();
        $this->cancella($file);

        return back()->with($this->flashSuccess("Carta «{$nome}» eliminata."));
    }

    /**
     * Salva la carta e, se è arrivato, il file della carta intestata: [la carta, se il file è nuovo]. Il file di prima si cancella dopo il salvataggio,
     * quando ce n'è uno nuovo o quando la carta va a campi con «Togli la carta intestata».
     *
     * @throws ValidationException se il file non si usa, o se una carta intestata nuova arriva senza il file
     */
    private function salvaConLaCarta(?CartaStampa $carta, array $dati, Request $request): array
    {
        $file = ($dati['aspetto'] ?? null) === CartaStampa::ASPETTO_CARTA ? $this->fileDellaCarta($request, $carta) : null;
        $percorso = $file === null ? null : $this->archivio->salvaPubblicoContenuto($file['contenuto'], self::CARTELLA, $file['estensione']);
        $vecchio = $carta?->carta_path;
        try {
            $salvata = $this->carte->salva($carta, $dati, $percorso === null ? null : ['percorso' => $percorso, 'tipo' => $file['tipo'], 'pagine' => $file['pagine']]);
        } catch (\Throwable $e) {
            $this->cancella($percorso);

            throw $e;
        }
        if ($vecchio !== null && $vecchio !== $percorso && $salvata->carta_path !== $vecchio) {
            $this->cancella($vecchio);
        }

        return [$salvata, $percorso !== null];
    }

    /**
     * Il file della carta intestata arrivato con la richiesta, controllato e pronto per il disco: il contenuto, l'estensione, il tipo, le pagine. Null
     * se non è arrivato e la carta ne ha già uno.
     *
     * @return array{contenuto: string, estensione: string, tipo: string, pagine: int}|null
     *
     * @throws ValidationException
     */
    private function fileDellaCarta(Request $request, ?CartaStampa $carta): ?array
    {
        if (! $request->hasFile('carta')) {
            if ($carta !== null && filled($carta->carta_path)) {
                return null;
            }

            throw ValidationException::withMessages(['carta' => 'Carica la carta intestata: un PDF di una o due pagine, o un\'immagine A4 in JPG.']);
        }
        $caricato = $request->file('carta');
        $percorso = (string) $caricato->getRealPath();
        if ($caricato->getMimeType() === 'application/pdf') {
            $controllo = CartaIntestata::controllaPdf($percorso, CartaDiStampa::cartellaTemporanea());
            if ($controllo['rifiuto'] !== null) {
                throw ValidationException::withMessages(['carta' => $controllo['rifiuto']]);
            }

            // Si salva il PDF rifatto dal controllo, con le sole pagine: i loro caratteri e le loro immagini come sono nel file arrivato (anche i dati che
            // un'immagine porta con sé); il JavaScript, gli allegati, le azioni e i collegamenti del file arrivato non restano sul disco.
            return ['contenuto' => (string) $controllo['pdf'], 'estensione' => 'pdf', 'tipo' => CartaIntestata::PDF, 'pagine' => $controllo['pagine']];
        }
        if (($rifiuto = CartaIntestata::rifiutoDellImmagine($percorso)) !== null) {
            throw ValidationException::withMessages(['carta' => $rifiuto]);
        }

        // Il JPG senza i dati della foto, senza aprirlo né riscriverlo: mPDF lo mette nel PDF senza decodificarlo. Il nome del file sul disco è casuale.
        return ['contenuto' => (string) CartaIntestata::senzaDatiDellaFoto((string) file_get_contents($percorso)), 'estensione' => 'jpg', 'tipo' => CartaIntestata::IMMAGINE, 'pagine' => 1];
    }

    /** Cancella un file della carta intestata; uno che il disco non cancella resta, con una riga nel registro. */
    private function cancella(?string $percorso): void
    {
        if ($percorso === null || $percorso === '') {
            return;
        }
        try {
            $this->archivio->eliminaPubblico($percorso);
        } catch (\Throwable $e) {
            Log::warning('Il file di una carta intestata non si cancella dal disco: resta sul disco.', ['percorso' => $percorso, 'errore' => $e->getMessage()]);
        }
    }

    /** La carta per ognuno dei cinque gruppi di documenti: un id, o nullo per la predefinita. */
    public function perGruppo(Request $request): RedirectResponse
    {
        $dati = $request->validate(CarteDelloStudio::regolePerGruppo(), CarteDelloStudio::messaggiPerGruppo());
        $this->carte->assegna($dati['carta_per_gruppo']);

        return back()->with($this->flashSuccess('Scelta delle carte salvata: ogni gruppo di documenti esce con la sua carta.'));
    }

    /** «Prova la carta»: il PDF con i dati d'esempio, da aprire nel browser. */
    public function prova(string $carta, ProvaDellaCarta $prova): Response
    {
        $esistente = self::oppure404($carta);

        return response($prova->pdf($esistente), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="prova-della-carta.pdf"',
        ]);
    }

    /** @return array{nome: string, mostra_logo: bool, colore: ?string, predefinita: bool, aspetto?: string, carta?: mixed, margine_alto?: float, margine_basso?: float, margine_sinistro?: float, margine_destro?: float} */
    private function dati(Request $request, ?CartaStampa $carta): array
    {
        $request->merge(['nome' => is_string($request->input('nome')) ? CarteDelloStudio::nomePulito($request->input('nome')) : $request->input('nome')]);

        return $request->validate(CarteDelloStudio::regole($carta), CarteDelloStudio::messaggi());
    }

    private static function oppure404(string $id): CartaStampa
    {
        abort_unless(ctype_digit($id), 404);

        return CartaStampa::findOrFail((int) $id);
    }
}
