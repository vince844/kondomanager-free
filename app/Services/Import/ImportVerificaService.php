<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Models\ImportFile;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Parser\AnagraficaMillesimiParser;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\Parser\ElencoUnitaParser;
use App\Services\Import\Parser\RipartoConsuntivoParser;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Legge i file di un lotto e ne ricava i dati canonici e i rilievi — la schermata S3 (§14.1).
 *
 * ## È il posto in cui i frammenti si fondono
 *
 * Ogni file produce un pezzo (§6): il riparto porta condominio, esercizio e saldi; l'elenco
 * unità porta persone, unità e titolarità; il compatto porta unità e tabelle. Nessuno da solo
 * basta, e le unità dei due file si sovrappongono in parte. La fusione avviene **per chiave**,
 * e il report più ricco vince sui doppioni — l'elenco unità porta piano, tipo e dati catastali
 * che il compatto non ha.
 *
 * ## Non scrive niente
 *
 * Questo servizio legge e basta. È il «dry-run first» del §7: la schermata di verifica esiste
 * proprio perché fra il leggere e lo scrivere ci sia un momento in cui l'amministratore può
 * dire di no.
 */
final class ImportVerificaService
{
    public function __construct(
        private readonly SpreadsheetReader $reader = new SpreadsheetReader,
        private readonly ReportRecognizer $riconoscitore = new ReportRecognizer,
    ) {}

    /**
     * @return array{
     *     canonici: array<string, mixed>,
     *     esiti: array<string, EsitoVerifica>,
     *     letture: list<array{file: string, tipo: string, righe: int}>
     * }
     */
    public function verifica(ImportBatch $batch): array
    {
        $canonici = [];
        $esiti = [];
        $letture = [];

        // Le unità arrivano da due report diversi e si fondono: l'ordine conta, ed è per questo
        // che i frammenti si accumulano qui invece di essere assegnati direttamente.
        $immobiliCompatti = [];
        $immobiliRicchi = [];

        foreach ($batch->files as $file) {
            $tipo = $file->report_type === null ? null : ReportType::tryFrom($file->report_type);

            if ($tipo === null || ! $tipo->importabile()) {
                continue;
            }

            $foglio = $this->foglio($file, $tipo);

            if ($foglio === null) {
                $esiti['file:'.$file->id] = new EsitoVerifica(0, [Rilievo::errore(
                    'file.illeggibile',
                    sprintf('Non riesco a rileggere «%s».', $file->nome_originale),
                    'Ricaricalo: potrebbe essere stato spostato o danneggiato dopo il caricamento.',
                )]);

                continue;
            }

            [$foglioLetto, $rigaIntestazione] = $foglio;

            match ($tipo) {
                ReportType::RipartoConsuntivo => $this->leggiRiparto($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file),
                ReportType::ElencoUnita => $this->leggiElencoUnita($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file, $immobiliRicchi),
                ReportType::AnagraficaMillesimi => $this->leggiMillesimi($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file, $immobiliCompatti),
                // Gli altri report hanno una testata da cui ricavare condominio ed esercizio,
                // anche se il loro contenuto va nell'archivio storico che la 1.10 non ha.
                default => $this->leggiSoloBanner($foglioLetto, $canonici, $esiti),
            };
        }

        // La fusione: il compatto fa da base, il report ricco sovrascrive.
        $immobili = [...$immobiliCompatti, ...$immobiliRicchi];

        if ($immobili !== []) {
            $canonici[LivelloUnita::CHIAVE] = $immobili;
        }

        $canonici = $this->applicaDecisioni($batch, $canonici);
        $esiti = $this->togliDecisioniPrese($batch, $esiti);

        return compact('canonici', 'esiti', 'letture');
    }

    /**
     * Un rilievo «da decidere» a cui l'utente ha già risposto **smette di bloccare**.
     *
     * Sembra ovvio e non lo era: le decisioni si applicavano ai dati e non ai rilievi, e il
     * pulsante di conferma restava disabilitato anche dopo che l'amministratore aveva deciso
     * tutto — senza dire perché. È il difetto peggiore di una schermata di conferma, perché
     * l'unica via d'uscita apparente è ricominciare da capo.
     *
     * @param  array<string, EsitoVerifica>  $esiti
     * @return array<string, EsitoVerifica>
     */
    private function togliDecisioniPrese(ImportBatch $batch, array $esiti): array
    {
        $decisioni = $batch->decisioni ?? [];

        if ($decisioni === []) {
            return $esiti;
        }

        $ripuliti = [];

        foreach ($esiti as $chiave => $esito) {
            $restano = array_values(array_filter(
                $esito->rilievi,
                fn (Rilievo $r) => $r->chiaveDecisione === null || ! array_key_exists($r->chiaveDecisione, $decisioni),
            ));

            $ripuliti[$chiave] = new EsitoVerifica($esito->righeTotali, $restano);
        }

        return $ripuliti;
    }

    /**
     * Applica le decisioni che l'utente ha già preso, prima che i dati arrivino ai livelli.
     *
     * Oggi ce n'è una sola e vale la pena spiegarla: **dividere in due un nome che sta in una
     * cella sola**. «ROSSI G. / BIANCHI N.» può essere due comproprietari o una società, e il
     * parser non lo decide — si limita a chiedere (§17.4, trappola 8).
     *
     * Quando l'utente risponde «dividi», qui nascono **due soggetti** e la titolarità che era
     * una diventa **due sulla stessa unità**: è la rappresentazione corretta della comproprietà
     * che il tracciato di Danea non sa esprimere.
     *
     * Il posto è questo e non il livello perché la divisione riguarda i **dati canonici**, non
     * la scrittura: se stesse nel committer, l'anteprima mostrerebbe una persona e l'archivio ne
     * conterrebbe due — cioè la schermata di conferma direbbe una cosa diversa da quella che
     * succede, che è il difetto peggiore che una conferma possa avere.
     *
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    private function applicaDecisioni(ImportBatch $batch, array $canonici): array
    {
        $decisioni = $batch->decisioni ?? [];

        if ($decisioni === []) {
            return $canonici;
        }

        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];
        /** @var list<CanonicalTitolarita> $titolarita */
        $titolarita = $canonici[LivelloTitolarita::CHIAVE] ?? [];

        foreach ($decisioni as $chiave => $scelta) {
            if ($scelta !== 'dividi' || ! str_starts_with($chiave, 'soggetto:')) {
                continue;
            }

            $chiaveSoggetto = substr($chiave, strlen('soggetto:'));
            $originale = $soggetti[$chiaveSoggetto] ?? null;

            if ($originale === null) {
                continue;
            }

            $pezzi = array_values(array_filter(array_map('trim', preg_split('#\s*/\s*#', $originale->nome) ?: [])));

            if (count($pezzi) < 2) {
                continue;
            }

            unset($soggetti[$chiaveSoggetto]);
            $nuove = [];

            foreach ($pezzi as $i => $nome) {
                // Il codice fiscale resta al **primo**: è l'unico a cui appartiene con certezza,
                // e duplicarlo violerebbe l'indice unico su `anagrafiche.codice_fiscale`.
                // Al secondo si dà una chiave costruita sul nome, che è il ripiego di sempre.
                $nuovo = new CanonicalSoggetto(
                    nome: $nome,
                    codiceFiscale: $i === 0 ? $originale->codiceFiscale : null,
                    indirizzo: $originale->indirizzo,
                    cap: $originale->cap,
                    comune: $originale->comune,
                    provincia: $originale->provincia,
                    // Anche i recapiti restano al primo: sono unici a schema, e attribuirli a
                    // entrambi farebbe fallire l'inserimento del secondo.
                    email: $i === 0 ? $originale->email : null,
                    pec: $i === 0 ? $originale->pec : null,
                    telefoni: $i === 0 ? $originale->telefoni : [],
                    note: $originale->note,
                );

                $soggetti[$nuovo->chiave()] = $nuovo;
                $nuove[] = $nuovo->chiave();
            }

            // La titolarità che era una diventa due sulla stessa unità: è la comproprietà.
            $aggiornate = [];

            foreach ($titolarita as $t) {
                if ($t->soggettoRef !== $chiaveSoggetto) {
                    $aggiornate[] = $t;

                    continue;
                }

                foreach ($nuove as $ref) {
                    $aggiornate[] = new CanonicalTitolarita(
                        immobileRef: $t->immobileRef,
                        soggettoRef: $ref,
                        ruolo: $t->ruolo,
                        // Il saldo **non** si spezza: resta sul primo. Dividerlo a metà sarebbe
                        // inventare una ripartizione che il file non dichiara, su denaro altrui.
                        saldoPrecedenteCents: $ref === $nuove[0] ? $t->saldoPrecedenteCents : null,
                        quota: round(100 / count($nuove), 2),
                        rigaSorgente: $t->rigaSorgente,
                    );
                }
            }

            $titolarita = $aggiornate;

            // ── E i saldi, che altrimenti restano orfani ──────────────────────────────────
            //
            // Il riparto continua a intestare la posizione al **nome unito**: dividendo le
            // persone, quel nome sparisce dall'archivio e il saldo non trova più nessuno. Sul
            // primo condominio vero l'effetto era un blocco secco al livello «Saldi di
            // apertura», provocato dalla decisione che l'interfaccia consiglia per prima.
            //
            // Non si spezza in due: il file non dice in che proporzione, e inventarla
            // significherebbe decidere su denaro altrui. Diventa **solidale sull'unità**, che è
            // esattamente ciò che quella posizione è — un debito di due persone insieme.
            $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

            if ($saldi instanceof CanonicalSaldiApertura) {
                $cercato = $this->chiaveNome($originale->nome);
                $righe = [];

                foreach ($saldi->righe as $r) {
                    if ($r->soggettoNome === null || $this->chiaveNome($r->soggettoNome) !== $cercato) {
                        $righe[] = $r;

                        continue;
                    }

                    $righe[] = new CanonicalSaldo(
                        immobileRef: $r->immobileRef,
                        soggettoNome: null,
                        importoCents: $r->importoCents,
                        daTitolareCessato: $r->daTitolareCessato,
                        rigaSorgente: $r->rigaSorgente,
                        daNomeDiviso: true,
                    );
                }

                $canonici[LivelloSaldi::CHIAVE] = new CanonicalSaldiApertura(
                    righe: $righe,
                    totaleRiferimentoCents: $saldi->totaleRiferimentoCents,
                    arrotondamentiCents: $saldi->arrotondamentiCents,
                );
            }
        }

        $canonici[LivelloSoggetti::CHIAVE] = $soggetti;
        $canonici[LivelloTitolarita::CHIAVE] = $titolarita;

        return $canonici;
    }

    /**
     * La forma su cui due nomi si dicono uguali: minuscolo, spazi collassati.
     *
     * Danea scrive «ROSSI M. /  BIANCHI L.» con due spazi dopo la barra, e il confronto crudo
     * fallisce su quello.
     */
    private function chiaveNome(string $nome): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nome)));
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     */
    private function leggiRiparto(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file): void
    {
        $banner = (new BannerParser)->estrai($foglio);
        $this->registraBanner($banner, $canonici, $esiti);

        $saldi = (new RipartoConsuntivoParser)->estrai($foglio, $riga);

        $canonici[LivelloSaldi::CHIAVE] = new CanonicalSaldiApertura(
            righe: $saldi['saldi'],
            totaleRiferimentoCents: $saldi['totale_riferimento_cents'],
            arrotondamentiCents: $saldi['arrotondamenti_cents'],
        );

        $esiti[LivelloSaldi::CHIAVE] = $saldi['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Saldi di apertura', 'righe' => $saldi['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiElencoUnita(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new ElencoUnitaParser)->estrai($foglio, $riga);

        $canonici[LivelloSoggetti::CHIAVE] = $letto['soggetti'];
        $canonici[LivelloTitolarita::CHIAVE] = $letto['titolarita'];
        $immobili = [...$immobili, ...$letto['immobili']];

        $esiti[LivelloSoggetti::CHIAVE] = $letto['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Persone, unità e titolarità', 'righe' => $letto['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiMillesimi(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new AnagraficaMillesimiParser)->estrai($foglio, $riga);

        $canonici[LivelloTabelle::CHIAVE] = $letto['tabelle'];
        $immobili = [...$immobili, ...$letto['immobili']];

        $esiti[LivelloTabelle::CHIAVE] = $letto['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Tabelle millesimali', 'righe' => $letto['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     */
    private function leggiSoloBanner(Foglio $foglio, array &$canonici, array &$esiti): void
    {
        $this->registraBanner((new BannerParser)->estrai($foglio), $canonici, $esiti);
    }

    /**
     * Il condominio e l'esercizio arrivano dalla testata di **più** stampe, e devono coincidere.
     *
     * Il primo che si legge vince; i successivi non sovrascrivono. Se dichiarassero condomìni
     * diversi sarebbe un errore grave — due export di due condomìni caricati insieme — e va
     * detto invece che risolto in silenzio prendendo l'ultimo.
     *
     * @param  array{condominio: mixed, esercizio: mixed, esito: EsitoVerifica}  $banner
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     */
    private function registraBanner(array $banner, array &$canonici, array &$esiti): void
    {
        if ($banner['condominio'] === null) {
            return;
        }

        $gia = $canonici[LivelloCondominio::CHIAVE] ?? null;

        if ($gia !== null) {
            if ($gia->chiave() !== $banner['condominio']->chiave()) {
                $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(1))
                    ->con(Rilievo::errore(
                        'condominio.file_di_condomini_diversi',
                        sprintf(
                            'I file caricati parlano di due condomìni diversi: «%s» e «%s».',
                            $gia->nome,
                            $banner['condominio']->nome,
                        ),
                        'Importa un condominio alla volta: mescolarli farebbe finire i saldi '
                        .'dell\'uno sulle unità dell\'altro. Togli i file di troppo dal caricamento.',
                    ));
            }

            return;
        }

        $canonici[LivelloCondominio::CHIAVE] = $banner['condominio'];
        $esiti[LivelloCondominio::CHIAVE] = $banner['esito'];

        if ($banner['esercizio'] !== null) {
            $canonici[LivelloEsercizi::CHIAVE] = $banner['esercizio'];
        }
    }

    /**
     * @return array{0: Foglio, 1: int}|null
     */
    private function foglio(ImportFile $file, ReportType $tipo): ?array
    {
        $percorso = Storage::disk(ImportUploadService::DISCO)->path($file->percorso);

        try {
            $fogli = $this->reader->leggi($percorso);
        } catch (RuntimeException) {
            return null;
        }

        foreach ($fogli as $foglio) {
            $esito = $this->riconoscitore->riconosci($foglio);

            if ($esito->tipo === $tipo && $esito->rigaIntestazione !== null) {
                return [$foglio, $esito->rigaIntestazione];
            }
        }

        // Il tipo è stato **forzato dall'utente**: il riconoscitore non lo confermerebbe, ma
        // l'ultima parola è sua. Si cerca comunque l'intestazione con le etichette di quel tipo.
        foreach ($fogli as $foglio) {
            $trovato = (new HeaderDetector)->trova($foglio, $tipo->etichette(), $tipo->quorum());

            if ($trovato !== null) {
                return [$foglio, $trovato['riga']];
            }
        }

        return null;
    }
}
