<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Services\RegistroModifiche\RegistroPerLaPagina;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response as RispostaHttp;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il registro delle modifiche di un condominio (`gestionale/{condominio}/registro-modifiche`, la voce in fondo al sottomenu della struttura) e dello studio
 * (`impostazioni/registro-modifiche`, la card in Impostazioni), con la stampa (§14 del progetto della tappa 1, scena 2 della bozza).
 *
 * Solo chi ha «Vede il registro delle modifiche» (di serie l'amministratore), mai i ruoli `utente` e `fornitore` (P18). I filtri stanno nell'indirizzo; la
 * pagina li cambia senza ricaricare (una visita parziale di `righe` e `riepilogo`). Le righe hanno le pagine delle altre tabelle: 20, 50 o 100 per pagina
 * (`per_page`), e una freccia chiede la pagina con una visita parziale delle sole righe.
 *
 * La stampa si sceglie nella finestra «Stampa il registro delle modifiche»: i filtri della pagina e il periodo dal … al … (`dal` e `al`, accanto al `mese`
 * che la pagina continua a usare). Prima di stampare la finestra chiede **il conto** (`contaDelCondominio`, `contaDelloStudio`): quante righe uscirebbero
 * con gli stessi filtri e lo stesso periodo, e il limite di una stampa sola. Il conto ha gli stessi permessi della stampa e non fa il PDF.
 */
class RegistroController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, PdfRigheStampabili;

    /**
     * Le righe che una stampa tiene al più, qualunque sia la memoria: il tempo di una stampa sola (misurato: 5.000 righe in circa 19 secondi). Oltre, si
     * restringe il periodo o i filtri, come per il registro di contabilità.
     */
    public const RIGHE_STAMPABILI = 5000;

    /**
     * Il tetto della memoria, misurato su questo modello con mPDF ({@see PdfRigheStampabili}, lo stesso margine dell'85%). Misurato il 19/11/2026 con la
     * strada della stampa (la lettura delle righe, le righe per la stampa, mPDF), righe del condominio con un dato cambiato o un gesto con il riepilogo e
     * il rimando, il picco della memoria del processo: 500 righe 88,5 MB; 2.000 104,5 MB; 3.000 118,5 MB; 4.000 130,6 MB; 5.000 146,1 MB; 6.000 157,7 MB;
     * 8.000 183,3 MB; con `memory_limit = 128M` 3.000 righe vanno, 4.000 fatal error dentro mPDF. Da cui circa 78 MB di base e 0,0131 MB a riga: 2.351 righe
     * con 128M (provato: 108,5 MB), 5.000 con 256M (il tetto del tempo).
     */
    private const BASE_MB_STAMPA = 78;
    private const MB_PER_RIGA = 0.0131;

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    /** Quante righe la stampa regge con un `memory_limit` dato: la misura di questo modello, e al più {@see RIGHE_STAMPABILI}. */
    public static function righeStampabiliDelRegistroCon(string|false $limite): int
    {
        return min(self::RIGHE_STAMPABILI, self::righeStampabiliCon($limite, self::BASE_MB_STAMPA, self::MB_PER_RIGA));
    }

    public function delCondominio(Request $request, Condominio $condominio): Response
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return Inertia::render('gestionale/registroModifiche/RegistroModifiche', $this->props($request, (int) $condominio->id, false) + [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            // Gli esercizi di questo condominio soltanto, dal più recente: il menu «L'esercizio» della finestra di stampa.
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->orderByDesc('id')->get()->map(fn ($e) => [
                'id' => $e->id, 'nome' => $e->nome, 'dal' => $e->data_inizio?->format('Y-m-d'), 'al' => $e->data_fine?->format('Y-m-d'), 'stato' => $e->stato,
            ])->all(),
        ]);
    }

    public function delloStudio(Request $request): Response
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return Inertia::render('impostazioni/registroModifiche', $this->props($request, null, true));
    }

    public function stampaDelCondominio(Request $request, Condominio $condominio, PdfService $pdf): RispostaHttp|RedirectResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return $this->stampa($request, $pdf, $condominio, false);
    }

    public function stampaDelloStudio(Request $request, PdfService $pdf): RispostaHttp|RedirectResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return $this->stampa($request, $pdf, null, true);
    }

    /** Il conto della stampa del condominio: quante righe uscirebbero e il limite di una stampa sola, niente PDF. */
    public function contaDelCondominio(Request $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return $this->conta($request, (int) $condominio->id, false);
    }

    /** Il conto della stampa dello studio, con il condominio fra i filtri. */
    public function contaDelloStudio(Request $request): JsonResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));

        return $this->conta($request, null, true);
    }

    /** Le righe della stampa con questi filtri e il loro limite: le stesse della stampa ({@see stampa()}), contate e non lette. */
    private function conta(Request $request, ?int $condominioId, bool $delloStudio): JsonResponse
    {
        $filtri = RegistroPerLaPagina::filtriDellaStampa($request, $delloStudio);

        return response()->json([
            'righe' => RegistroPerLaPagina::query($condominioId, $filtri)->count(),
            'limite' => self::righeStampabiliDelRegistroCon(self::$limiteMemoria ?? ini_get('memory_limit')),
        ]);
    }

    /** @return array<string, mixed> */
    private function props(Request $request, ?int $condominioId, bool $delloStudio): array
    {
        $filtri = RegistroPerLaPagina::filtri($request, $delloStudio);

        return [
            'filtri' => $filtri,
            'dello_studio' => $delloStudio,
            // Le pagine delle altre tabelle: il conto delle righe, la pagina, quante per pagina (20, 50, 100).
            'righe' => function () use ($request, $condominioId, $filtri) {
                $pagina = RegistroPerLaPagina::query($condominioId, $filtri)->paginate(RegistroPerLaPagina::righePerPagina($request))->withQueryString();

                return $pagina->setCollection(collect(RegistroPerLaPagina::righe($pagina->getCollection())));
            },
            'riepilogo' => fn () => RegistroPerLaPagina::riepilogo($condominioId, $filtri),
            'scelte' => fn () => RegistroPerLaPagina::scelte($condominioId, $delloStudio),
        ];
    }

    /**
     * Il PDF del periodo e dei filtri scelti, con la carta dello studio: le righe e, in fondo, l'impronta della stampa (sha256 delle righe stampate, in
     * una forma fissa) e l'impronta dell'ultima riga del periodo, per un'impugnazione o per il passaggio di consegne (la bozza). Il periodo è quello
     * dal … al … della finestra, o il mese della pagina; in testa i filtri scelti.
     */
    private function stampa(Request $request, PdfService $pdf, ?Condominio $condominio, bool $delloStudio): RispostaHttp|RedirectResponse
    {
        $filtri = RegistroPerLaPagina::filtriDellaStampa($request, $delloStudio);
        $conPeriodo = $filtri['dal'] !== null || $filtri['al'] !== null;
        $query = RegistroPerLaPagina::query($condominio?->id, $filtri);
        $quante = (clone $query)->count();
        if ($quante > self::righeStampabiliDelRegistroCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return back()->with($this->flashError('Il registro filtrato ha ' . number_format($quante, 0, ',', '.')
                . ' righe: troppe per una stampa sola. Scegli un periodo più breve o un filtro, e stampa in più parti.'));
        }
        if ($quante === 0) {
            return back()->with($this->flashError('Nessuna riga con questi filtri: non c\'è niente da stampare.'));
        }
        $modelli = $query->get();
        $righe = RegistroPerLaPagina::righe($modelli);
        $ultima = $modelli->filter(fn (RegistroModifica $r) => $r->impronta !== null)->sortByDesc('sequenza')->first();
        $improntaDellaStampa = hash('sha256', json_encode(array_map(fn (array $r) => [$r['id'], $r['avvenuta_il'], $r['autore']['nome'], $r['oggetto']['nome'],
            $r['cambiato'], $r['perche']], $righe), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $scelte = RegistroPerLaPagina::scelte($condominio?->id, $delloStudio);
        $nomeDi = fn (string $chiave, ?string $valore) => $valore === null || $valore === '' ? null
            : (collect($scelte[$chiave])->firstWhere('valore', (string) $valore)['nome'] ?? null);
        $titolo = 'Registro delle modifiche' . ($condominio !== null ? ' — ' . $condominio->nome : ' dello studio');

        $mpdf = $pdf->generate('pdf.registro_modifiche.stampa', [
            'gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI,
            'condominio' => $condominio,
            'titolo' => $titolo,
            'titolo_stampa' => $titolo,
            'senza_firma' => true,
            'righe' => $righe,
            'filtri' => array_filter([
                'Periodo' => $conPeriodo ? RegistroPerLaPagina::nomeDelPeriodo($filtri['dal'], $filtri['al']) : RegistroPerLaPagina::nomeDelMese($filtri['mese']),
                'Chi' => $nomeDi('chi', $filtri['chi']),
                'Su che cosa' => $nomeDi('aree', $filtri['area']),
                'Che cosa è successo' => $nomeDi('azioni', $filtri['azione']),
                'Condominio' => $delloStudio ? $nomeDi('condomini', $filtri['condominio'] !== null ? (string) $filtri['condominio'] : null) : null,
                'Cerca' => $filtri['cerca'] !== null ? '«' . $filtri['cerca'] . '»' : null,
            ]),
            'impronta_della_stampa' => $improntaDellaStampa,
            'ultima_riga' => $ultima === null ? null : ['id' => $ultima->id, 'quando' => $ultima->avvenuta_il->copy()->setTimezone($fuso)->format('d/m/Y H:i'),
                'impronta' => $ultima->impronta],
            'stampata_il' => now($fuso)->format('d/m/Y \a\l\l\e H:i'),
        ], ['orientation' => 'L']);
        $nome = PdfService::nomeFile('registro-modifiche', $condominio?->nome ?? 'studio', $conPeriodo
            ? implode('-', array_filter([$filtri['dal'] !== null ? 'dal' : null, $filtri['dal'], $filtri['al'] !== null ? 'al' : null, $filtri['al']]))
            : ($filtri['mese'] === RegistroPerLaPagina::TUTTI ? null : $filtri['mese']));

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $nome . '"')
            ->header('X-Impronta-Della-Stampa', $improntaDellaStampa);
    }
}
