<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\PrintSettings;
use App\Http\Requests\Settings\UpdatePrintSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use App\Services\Documenti\ArchivioDocumenti;
use App\Models\CartaStampa;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\CarteDelloStudio;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\ImmagineDiStampa;
use App\Traits\HandleFlashMessages;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ImpostazioniStampeController extends Controller
{
    use HandleFlashMessages;

    /** Le immagini delle stampe: il campo del caricamento, la proprietà delle impostazioni, la cartella sul disco dei file pubblici. */
    private const IMMAGINI = [
        'firma_stampe' => ['firma_stampe_path', 'settings/signatures'],
        'logo_stampe' => ['logo_stampe_path', 'settings/logo'],
    ];

    /**
     * Show the print settings form.
     */
    public function index(PrintSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        $archivio = app(ArchivioDocumenti::class);
        CarteDelloStudio::laPredefinitaSeManca();

        return Inertia::render('impostazioni/impostazioniStampe', [
            'nota_legale_stampe' => (string) $settings->nota_legale_stampe,
            'firma_stampe_url'   => $settings->firma_stampe_path ? $archivio->urlPubblico($settings->firma_stampe_path) : null,
            // Il limite lo decide il server, non noi: il testo d'aiuto scriveva «max 2MB» a mano in
            // quattro lingue, e su uno spazio web che ne accetta uno prometteva il doppio.
            'limiteFirma'        => \App\Support\LimiteCaricamento::etichetta(2.0),
            // La firma caricata prima che il programma riscrivesse le immagini resta com'era: se pesa sulle stampe, la pagina chiede di ricaricarla.
            'firma_da_ricaricare' => self::firmaDaRicaricare($settings, $archivio),
            'logo_stampe_url'    => $settings->logo_stampe_path ? $archivio->urlPubblico($settings->logo_stampe_path) : null,
            // Lo studio in testa alle stampe; l'email è quella a cui si risponde alle lettere.
            'studio'             => [
                'nome'           => (string) $settings->studio_nome,
                'indirizzo'      => (string) $settings->studio_indirizzo,
                'email'          => (string) $settings->studio_email,
                'pec'            => (string) $settings->studio_pec,
                'telefono'       => (string) $settings->studio_telefono,
                'comune'         => (string) $settings->studio_comune,
                'partita_iva'    => (string) $settings->studio_partita_iva,
                'codice_fiscale' => (string) $settings->studio_codice_fiscale,
                'sito'           => (string) $settings->studio_sito,
            ],
            // Le carte dello studio, chi le usa, la carta di ogni gruppo di documenti (nullo: la predefinita, anche per una carta che non c'è più). Una
            // carta con il file della carta intestata (anche tornata a campi) dice il tipo del file, le pagine, i margini e, se è con la carta intestata e
            // il file non si legge, perché le stampe escono a campi.
            'carte'              => CartaStampa::query()->orderByDesc('predefinita')->orderBy('nome')->get()->map(fn (CartaStampa $c) => [
                'id'          => (int) $c->id,
                'nome'        => (string) $c->nome,
                'aspetto'     => (string) $c->aspetto,
                'mostra_logo' => (bool) $c->mostra_logo,
                'colore'      => $c->colore,
                'predefinita' => (bool) $c->predefinita,
                'usata_da'    => array_map(fn (GruppoCarta $g) => $g->nome(), CarteDelloStudio::gruppiDi($c)),
            ] + self::cartaIntestataPerLaPagina($c))->all(),
            'carta_per_gruppo'   => CarteDelloStudio::sceltePerLaPagina(),
            // Il file più grande della carta intestata, come il limite della firma: lo decide il server.
            'limiteCarta'        => \App\Support\LimiteCaricamento::etichetta(CarteDelloStudio::MB_CARTA),
            'gruppi_carta'       => array_map(fn (GruppoCarta $g) => ['valore' => $g->value, 'nome' => $g->nome(), 'esempi' => $g->esempi()], CarteDelloStudio::gruppi()),
        ]);
    }

    /**
     * La carta intestata di una carta, per la pagina: il tipo, le pagine, i margini e, per una carta intestata che non si legge, la frase che lo dice.
     * Nulli per una carta senza il file; una carta tornata a campi che tiene il file li ha, per tornare alla carta intestata senza caricarla di nuovo (e
     * la frase no: le sue stampe sono a campi). A campi lo studio lo scrive il programma solo se ha il nome: senza, la frase lo dice e chiede il nome.
     *
     * @return array{carta_tipo: ?string, carta_pagine: ?int, margini: ?array{alto: float, basso: float, sinistro: float, destro: float}, carta_illeggibile: ?string}
     */
    private static function cartaIntestataPerLaPagina(CartaStampa $c): array
    {
        if (! filled($c->carta_path)) {
            return ['carta_tipo' => null, 'carta_pagine' => null, 'margini' => null, 'carta_illeggibile' => null];
        }

        return [
            'carta_tipo' => $c->carta_tipo,
            'carta_pagine' => $c->carta_pagine,
            'margini' => ['alto' => (float) $c->margine_alto, 'basso' => (float) $c->margine_basso, 'sinistro' => (float) $c->margine_sinistro, 'destro' => (float) $c->margine_destro],
            'carta_illeggibile' => $c->aspetto === CartaStampa::ASPETTO_CARTA && app(CartaDiStampa::class)->intestataDi($c) === null ? self::cartaIlleggibile() : null,
        ];
    }

    /** La frase di una carta intestata che non si legge: le stampe escono a campi, con lo studio se ha il nome ({@see \App\Services\PDF\Carta::haStudio()}). */
    private static function cartaIlleggibile(): string
    {
        return 'Il file della carta intestata non si legge (o il disco non risponde): le stampe escono a campi, '.(CartaDiStampa::studio()['nome'] !== null
            ? 'con lo studio scritto dal programma. Se resta così, caricalo di nuovo.'
            : 'senza lo studio: il suo nome, qui sopra, è vuoto. Scrivilo, o carica di nuovo il file.');
    }

    /**
     * Perché la firma salvata pesa su ogni stampa, in una frase per la pagina; null se non pesa, se non c'è o se il disco non la dà. Pesa una firma
     * caricata prima che il programma riscrivesse le immagini, più grande di 1.000 pixel sul lato lungo; una firma caricata da questa versione in poi
     * è già riscritta (al massimo 1.000 pixel, con la sua trasparenza): non pesa. Una di più di 4 milioni di pixel il caricamento non la prende: la
     * frase dice di ridurla prima.
     */
    private static function firmaDaRicaricare(PrintSettings $settings, ArchivioDocumenti $archivio): ?string
    {
        $locale = $settings->firma_stampe_path ? $archivio->percorsoLocalePubblico($settings->firma_stampe_path) : null;
        $pesante = $locale === null ? null : ImmagineDiStampa::pesante($locale);
        if ($pesante === null) {
            return null;
        }
        $misura = number_format($pesante['misura'][0], 0, ',', '.').' × '.number_format($pesante['misura'][1], 0, ',', '.').' pixel';
        $perche = $pesante['trasparente']
            ? "La firma ha lo sfondo trasparente ed è di {$misura}: a ogni stampa il programma la legge tutta in memoria, e i PDF con la firma sono più lenti e pesanti."
            : "La firma è di {$misura}: ogni PDF con la firma la porta tutta, e pesa più del necessario.";
        $come = match (true) {
            $pesante['oltre_il_tetto'] => ' Riducila (bastano 1.000 pixel sul lato lungo) e caricala di nuovo: il programma non prende immagini di più di 4 milioni di pixel.',
            $pesante['trasparente'] => ' Caricala di nuovo: il programma la riduce a 1.000 pixel sul lato lungo, e lo sfondo resta trasparente.',
            default => ' Caricala di nuovo: il programma la riduce a 1.000 pixel sul lato lungo.',
        };

        return $perche.$come;
    }

    /**
     * Update the print settings.
     */
    public function store(UpdatePrintSettingsRequest $request, PrintSettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        // Le immagini si riscrivono prima di toccare niente: una che non si apre è un errore del suo campo, e le impostazioni restano com'erano.
        $riscritte = [];
        foreach (array_keys(self::IMMAGINI) as $campo) {
            if ($request->hasFile($campo)) {
                try {
                    $riscritte[$campo] = ImmagineDiStampa::riscritta((string) $request->file($campo)->getRealPath());
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages([$campo => 'L\'immagine non si apre: caricala di nuovo come PNG o JPG.']);
                }
            }
        }

        $archivio = app(ArchivioDocumenti::class);
        $salvati = [];
        $daCancellare = [];

        try {
            $validated = $request->validated();

            $settings->nota_legale_stampe = $validated['nota_legale_stampe'] ?? null;
            // Un campo che la richiesta non porta resta com'era: la pagina li manda tutti, ma una richiesta di prima di questi campi no.
            foreach (['studio_nome', 'studio_indirizzo', 'studio_email', 'studio_pec', 'studio_telefono', 'studio_comune',
                'studio_partita_iva', 'studio_codice_fiscale', 'studio_sito'] as $campo) {
                if ($request->has($campo)) {
                    $valore = trim((string) ($validated[$campo] ?? ''));
                    $settings->{$campo} = $valore === '' ? null : $valore;
                }
            }

            // Il file nuovo si salva prima di cancellare il vecchio, e il vecchio si cancella solo dopo che le impostazioni sono salvate: se
            // qualcosa non riesce, l'impostazione punta ancora a un file che c'è.
            foreach (self::IMMAGINI as $campo => [$proprieta, $cartella]) {
                if (isset($riscritte[$campo])) {
                    $salvati[] = $nuovo = $archivio->salvaPubblicoContenuto($riscritte[$campo], $cartella, 'png');
                    $daCancellare[] = $settings->{$proprieta};
                    $settings->{$proprieta} = $nuovo;
                } elseif (! empty($validated['delete_'.$campo]) && $settings->{$proprieta}) {
                    $daCancellare[] = $settings->{$proprieta};
                    $settings->{$proprieta} = null;
                }
            }

            $settings->save();
        } catch (\Exception $e) {
            // Il file nuovo già scritto non serve più: le impostazioni puntano ancora a quello di prima.
            foreach ($salvati as $percorso) {
                try {
                    $archivio->eliminaPubblico($percorso);
                } catch (\Throwable) {
                }
            }

            return redirect()->back()->with(
                $this->flashError(__('impostazioni.error_save_general_settings'))
            );
        }

        foreach (array_filter($daCancellare) as $percorso) {
            try {
                $archivio->eliminaPubblico($percorso);
            } catch (\Throwable $e) {
                // Un file vecchio che non si cancella resta sul disco: le impostazioni sono già salvate e puntano al nuovo.
                Log::warning('Immagine delle stampe di prima non cancellata.', ['percorso' => $percorso, 'errore' => $e->getMessage()]);
            }
        }

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_save_print_settings'))
        );
    }
}
