<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\NotaSintetica;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\ModelloTesto;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\TestiDellaNota;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La scheda «Nota sintetica» del rendiconto: i fatti che il programma propone e il testo dell'amministratore, nelle cinque parti; la bozza salvata; la
 * copia dall'anno prima o da un altro condominio (i testi e gli elenchi, mai i fatti); «Genera la nota» con il suo registro; i testi dello studio (salvati,
 * rinominati, eliminati); la stampa della nota da sola. La nota entra nel fascicolo come parte sua, subito dopo il frontespizio ({@see \App\Services\Gestionale\FascicoloDelRendiconto}).
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello, mai fra quelle del portale. L'esercizio di un altro condominio fa 404.
 */
class RendicontoNotaController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    public function __construct(private readonly NotaSinteticaDelRendiconto $nota) {}

    public function show(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $letture = $this->nota->letture($condominio, $esercizio);
        $prima = NotaSinteticaDelRendiconto::eserciziPrima($esercizio);

        return Inertia::render('gestionale/movimenti/rendiconto/NotaSintetica', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'stato_rendiconto' => VersioniDelRendiconto::statoInTesta($esercizio),
            'nota' => $this->nota->perLaScheda($condominio, $esercizio, $letture),
            'anno_prima' => $prima === null ? null : ['id' => (int) $prima->id, 'nome' => (string) $prima->nome],
            // Gli esercizi di altri condomini con una nota da copiare: la bozza o una versione fermata.
            'altri' => $this->altriConUnaNota($condominio),
            'testi_studio' => TestiDellaNota::delCondominio($condominio),
            'puo_salvare_testi' => $request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true,
            'tipi_contratto' => NotaSinteticaDelRendiconto::TIPI_CONTRATTO,
            'dichiarazione' => NotaSinteticaDelRendiconto::DICHIARAZIONE,
            'lunghezza' => NotaSinteticaDelRendiconto::LUNGHEZZA,
            // Una frase (un fatto riscritto, una causa, una spiegazione, una risposta) e una riga (una cosa da decidere, i dipendenti) sono più corte di una parte.
            'lunghezza_frase' => NotaSinteticaDelRendiconto::LUNGHEZZA_FRASE,
            'lunghezza_riga' => NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA,
            'righe_elenco' => NotaSinteticaDelRendiconto::RIGHE_ELENCO,
        ]);
    }

    /**
     * La bozza come la manda la pagina: tutto il contenuto, normalizzato; le cifre degli elenchi arrivano in euro e diventano centesimi qui. Il registro della
     * nota non viene dalla pagina: resta quello salvato.
     */
    public function update(Request $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $lunghezza = 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA;
        $frase = 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA_FRASE;
        $riga = 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA;
        $request->validate([
            'contenuto' => ['required', 'array'],
            'contenuto.parti.*.testo' => ['nullable', 'string', $lunghezza],
            'contenuto.fatti.*.riscritto' => ['nullable', 'string', $frase],
            'contenuto.cause.*' => ['nullable', 'string', $frase],
            'contenuto.spiegazioni.*' => ['nullable', 'string', $frase],
            'contenuto.contratti' => ['nullable', 'array', 'max:'.NotaSinteticaDelRendiconto::RIGHE_ELENCO],
            'contenuto.contratti.*.importo' => ['nullable', 'numeric', 'min:0'],
            'contenuto.contratti.*.scadenza' => ['nullable', 'date'],
            'contenuto.debiti_certi' => ['nullable', 'array', 'max:'.NotaSinteticaDelRendiconto::RIGHE_ELENCO],
            'contenuto.debiti_certi.*.importo' => ['nullable', 'numeric', 'min:0'],
            'contenuto.debiti_certi.*.scadenza' => ['nullable', 'date'],
            'contenuto.da_decidere' => ['nullable', 'array', 'max:'.NotaSinteticaDelRendiconto::RIGHE_ELENCO],
            'contenuto.da_decidere.*' => ['nullable', 'string', $riga],
            'contenuto.dichiarazione.*.testo' => ['nullable', 'string', $frase],
            'contenuto.dichiarazione.*.da_confermare' => ['nullable', 'boolean'],
            'contenuto.dipendenti.testo' => ['nullable', 'string', $riga],
            'contenuto.dipendenti.da_confermare' => ['nullable', 'boolean'],
            'contenuto.banca' => ['nullable', 'array'],
            'contenuto.banca.*.data' => ['nullable', 'date'],
            'contenuto.banca.*.saldo' => ['nullable', 'numeric'],
            'contenuto.banca.*.spiegazione' => ['nullable', 'string', $frase],
        ], [
            'max' => 'Il testo è troppo lungo: al massimo :max caratteri.',
            'contenuto.contratti.max' => 'L\'elenco ha al massimo '.NotaSinteticaDelRendiconto::RIGHE_ELENCO.' righe.',
            'contenuto.debiti_certi.max' => 'L\'elenco ha al massimo '.NotaSinteticaDelRendiconto::RIGHE_ELENCO.' righe.',
            'contenuto.da_decidere.max' => 'L\'elenco ha al massimo '.NotaSinteticaDelRendiconto::RIGHE_ELENCO.' righe.',
            'numeric' => 'Scrivi la cifra in euro, per esempio 1.250,00.',
            'date' => 'Scrivi una data.',
            'boolean' => 'La conferma è sì o no.',
        ]);
        $contenuto = NotaSinteticaDelRendiconto::normalizza((array) $request->input('contenuto'), true);
        $contenuto['registro'] = NotaSinteticaDelRendiconto::contenuto($esercizio)['registro'];
        NotaSinteticaDelRendiconto::salva($esercizio, $contenuto, $request->user());

        return back()->with($this->flashSuccess('Nota salvata.'));
    }

    /**
     * «Genera la nota»: tiene i fatti proposti e copia dall'anno prima i testi e gli elenchi che mancano, con la nota dell'amministratore nel registro. La
     * risposta dice che cosa manca ancora.
     */
    public function genera(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $dati = $request->validate(['nota' => ['nullable', 'string', 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA]],
            ['nota.max' => 'La nota è troppo lunga: al massimo '.NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA.' caratteri.']);
        $esito = $this->nota->genera($condominio, $esercizio, (string) ($dati['nota'] ?? ''), $request->user());

        return response()->json($esito);
    }

    /**
     * Che cosa si copierebbe da un esercizio (`da`): di questo condominio, o di un altro. La pagina lo mostra prima, e lo mette nella bozza solo se
     * l'amministratore conferma; i fatti non si copiano mai.
     */
    public function copia(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $da = Esercizio::find((int) $request->query('da'));
        abort_if($da === null || (int) $da->id === (int) $esercizio->id, 404);
        $copia = NotaSinteticaDelRendiconto::copiabile($da);
        if ($copia === null) {
            return response()->json(['message' => 'L\'esercizio «'.$da->nome.'» non ha una nota da copiare.'], 404);
        }
        $copia['altro_condominio'] = (int) $da->condominio_id !== (int) $condominio->id;
        if ($copia['altro_condominio']) {
            $copia['da'] .= ', condominio «'.(string) Condominio::whereKey($da->condominio_id)->value('nome').'»';
            // Da un altro condominio i contratti arrivano senza l'importo e la scadenza: sono di quel contratto, non di questo.
            $copia['contenuto']['contratti'] = array_map(fn ($r) => ['importo' => null, 'scadenza' => null] + $r, $copia['contenuto']['contratti']);
        }

        return response()->json($copia);
    }

    /** «Salva come testo dello studio»: un testo della nota con un nome, per tutti i condomini o solo per questo. Lo salva chi gestisce le stampe. */
    public function salvaTesto(Request $request, Condominio $condominio): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true, 403);
        $dati = $request->validate(TestiDellaNota::regole(), TestiDellaNota::messaggi());
        $testo = TestiDellaNota::salva($dati['tipo'], $dati['nome'], $dati['testo'], filter_var($dati['per_tutti'], FILTER_VALIDATE_BOOLEAN) ? null : (int) $condominio->id,
            $request->user());

        return response()->json(['testo' => TestiDellaNota::perLaPagina($testo), 'testi' => TestiDellaNota::delCondominio($condominio)], 201);
    }

    /** Cambia il nome di un testo dello studio; il testo e «vale per» no. Lo fa chi gestisce le stampe. */
    public function rinominaTesto(Request $request, Condominio $condominio, string|int $testo): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true, 403);
        $modello = $this->assicuraTesto($testo, $condominio);
        $dati = $request->validate(['nome' => TestiDellaNota::regole()['nome']], TestiDellaNota::messaggi());
        TestiDellaNota::rinomina($modello, $dati['nome'], $request->user());

        return response()->json(['testi' => TestiDellaNota::delCondominio($condominio)]);
    }

    /** Elimina un testo dello studio: le note che l'hanno usato hanno già le loro parole e non cambiano. Lo fa chi gestisce le stampe. */
    public function eliminaTesto(Request $request, Condominio $condominio, string|int $testo): JsonResponse
    {
        abort_unless($request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true, 403);
        $this->assicuraTesto($testo, $condominio)->delete();

        return response()->json(['testi' => TestiDellaNota::delCondominio($condominio)]);
    }

    /** «Stampa la nota»: la nota da sola, con la carta del rendiconto. */
    public function stampa(Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $stampa = $this->nota->perLaStampa($condominio, $esercizio, $this->nota->letture($condominio, $esercizio));
        $nome = PdfService::nomeFile('nota-sintetica', (string) $condominio->nome, $esercizio->data_inizio->format('Y'));
        $mpdf = $pdf->generate('pdf.gestionale.rendiconto_nota', ['condominio' => $condominio, 'esercizio' => $esercizio, 'nota' => $stampa,
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], \App\Http\Controllers\Gestionale\Movimenti\StatoPatrimonialeController::OPZIONI_STAMPA);

        return response($mpdf->Output('', 'S'))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    /** Il testo dello studio della nota dall'indirizzo: della nota, e per tutti i condomini o per questo; altrimenti 404. */
    private function assicuraTesto(string|int $testo, Condominio $condominio): ModelloTesto
    {
        $modello = TestiDellaNota::oppure404($testo, $condominio);
        abort_unless($modello->condominio_id === null || (int) $modello->condominio_id === (int) $condominio->id, 404);

        return $modello;
    }

    /** Gli esercizi di altri condomini con una nota da copiare, per «Copia da un altro condominio»: per condominio, il più recente prima. */
    private function altriConUnaNota(Condominio $condominio): array
    {
        $ids = NotaSintetica::where('condominio_id', '!=', $condominio->id)->pluck('esercizio_id')
            ->merge(RendicontoVersione::where('condominio_id', '!=', $condominio->id)->whereNotNull('nota')->pluck('esercizio_id'))->unique()->all();
        if ($ids === []) {
            return [];
        }

        return Esercizio::whereIn('esercizi.id', $ids)->join('condomini', 'condomini.id', '=', 'esercizi.condominio_id')
            ->orderBy('condomini.nome')->orderByDesc('esercizi.data_inizio')
            ->get(['esercizi.id', 'esercizi.nome', 'condomini.id as condominio_id', 'condomini.nome as condominio'])
            ->map(fn ($e) => ['esercizio_id' => (int) $e->id, 'esercizio' => (string) $e->nome, 'condominio_id' => (int) $e->condominio_id, 'condominio' => (string) $e->condominio])
            ->all();
    }
}
