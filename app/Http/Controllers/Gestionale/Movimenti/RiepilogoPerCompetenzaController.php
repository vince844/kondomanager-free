<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\CommissioniSenzaVoce;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\VoceNelloStorno;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\PDF\GruppoCarta;

/**
 * Il riepilogo finanziario per competenza dell'esercizio: una pagina sua, con la sua richiesta, così le letture del riepilogo non si sommano a quelle
 * della situazione patrimoniale; è una delle schede del rendiconto. E la sua stampa, con il dettaglio per spesa a scelta.
 */
class RiepilogoPerCompetenzaController extends Controller
{
    use HandleFlashMessages, HasCondomini, PdfRigheStampabili;

    /**
     * Il tetto della stampa, misurato su questo modello e non preso dal Libro Giornale: la base (la lettura del riepilogo compresa) e il costo per riga
     * (le righe delle voci e, con il dettaglio, una per fattura o movimento), con il margine dell'85% delle altre stampe ({@see PdfRigheStampabili}).
     *
     * Misurato l'08/11/2026 con richieste vere alla rotta, su un condominio con una voce e N fatture nell'esercizio, la stampa con il dettaglio, il picco
     * del processo con 2G: 1.001 righe 62,5 MB (2,5 s); 3.001 74,5 (6,6 s); 6.001 94,5 (12,9 s); 8.001 108,5 (17,3 s); 12.001 139,0 (25,7 s); senza il
     * dettaglio 60,5 MB. Da cui circa 56 MB di base e 0,007 MB a riga: 7.542 righe con 128M, 23.085 con 256M.
     */
    public const BASE_MB_STAMPA = 56;
    public const MB_PER_RIGA = 0.007;

    /** Le opzioni di mPDF della stampa: la carta della situazione patrimoniale, in orizzontale per le otto colonne. La usa anche il fascicolo. */
    public const OPZIONI_STAMPA = ['default_font' => 'inter', 'orientation' => 'L', 'margin_top' => 22, 'margin_bottom' => 20, 'margin_header' => 8, 'margin_footer' => 8];

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    /** Quante righe la stampa regge con un `memory_limit` dato. */
    public static function righeStampabiliDelRiepilogo($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_STAMPA, self::MB_PER_RIGA, 0);
    }

    /** Le righe della stampa: una per voce e capitolo, e con il dettaglio una per fattura o movimento. */
    public static function righeDellaStampa(array $riepilogo, bool $dettaglio): int
    {
        $n = 0;
        foreach ($riepilogo['blocchi'] as $b) {
            foreach ([...$b['spese'], ...$b['entrate']] as $c) {
                $n += 1 + count($c['voci'] ?? []);
                if ($dettaglio) {
                    $n += count($c['dettaglio'] ?? []) + array_sum(array_map(fn ($v) => count($v['dettaglio'] ?? []), $c['voci'] ?? []));
                }
            }
        }

        return $n;
    }

    public function index(Condominio $condominio, Esercizio $esercizio, RiepilogoPerCompetenza $riepilogo, CommissioniSenzaVoce $commissioni): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $r = $riepilogo->perEsercizio($condominio, $esercizio);
        // L'elenco delle commissioni e degli storni senza voce, letto solo se il riepilogo ha commissioni senza voce o righe di storni senza voce (anche
        // con la somma a zero).
        // Le commissioni: la riga conta solo le commissioni (il DARE), lo storno da solo di una commissione ha solo la cifra.
        $commissioniSenzaVoce = $r['fuori_capitoli']['commissioni_senza_voce'];
        $conCommissioni = (int) $commissioniSenzaVoce['numero'] > 0 || (int) $commissioniSenzaVoce['consuntivo'] !== 0;
        $conStorni = VoceNelloStorno::righeSenzaVoce((int) $esercizio->id)->exists();
        $elenco = $conCommissioni || $conStorni ? $commissioni->perEsercizio($condominio, $esercizio) : null;

        return Inertia::render('gestionale/movimenti/rendiconto/RiepilogoFinanziario', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'riepilogo' => $r,
            // Quante delle commissioni senza voce, e quanti degli storni senza voce fra i costi non classificati, si assegnano, con la regola dell'elenco:
            // ogni riga porta all'elenco solo se ce n'è almeno una (in un esercizio chiuso nessuna).
            'commissioni_assegnabili' => $conCommissioni ? $elenco['assegnabili']['numero'] : 0,
            'storni_assegnabili' => $conStorni ? $elenco['storni_assegnabili']['numero'] : 0,
        ]);
    }

    /** La stampa del riepilogo; con `?dettaglio=1` anche il dettaglio per spesa. Oltre il tetto non parte e torna alla scheda con la frase. */
    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, RiepilogoPerCompetenza $servizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $dettaglio = $request->boolean('dettaglio');
        $riepilogo = $servizio->perEsercizio($condominio, $esercizio);
        $righe = self::righeDellaStampa($riepilogo, $dettaglio);
        if ($righe > self::righeStampabiliDelRiepilogo(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return redirect()->route('admin.gestionale.esercizi.rendiconto.riepilogo', [$condominio->id, $esercizio->id])->with($this->flashError(
                'Il riepilogo'.($dettaglio ? ' con il dettaglio per spesa' : '').' ha '.$righe.' righe: più di quante il server riesce a stampare con la memoria che ha. '
                .($dettaglio ? 'Stampalo senza il dettaglio, o chiedi' : 'Chiedi').' a chi gestisce il server di alzare il limite di memoria di PHP.'
            ));
        }

        $mpdf = $pdf->generate('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $condominio, 'esercizio' => $esercizio, 'riepilogo' => $riepilogo,
            'dettaglio' => $dettaglio, 'senza_firma' => true, 'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], self::OPZIONI_STAMPA);
        $nome = PdfService::nomeFile('riepilogo-finanziario', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$riepilogo['alla']);

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }
}
