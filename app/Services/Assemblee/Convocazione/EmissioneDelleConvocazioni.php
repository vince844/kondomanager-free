<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\Assemblee\UnitaDelPunto;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\Posta\CasellaPecNonImpostata;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\ErroreDellaPec;
use App\Services\Posta\InvioPerPec;
use App\Services\Posta\PecDelloStudio;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Output\Destination;

/**
 * L'emissione degli avvisi di convocazione (§4.3 del progetto della tappa 2): **per ogni convocato un PDF solo** con il suo nome e le sue unità, il tipo, le
 * date e le ore, il luogo o la piattaforma, l'ordine del giorno con la frase specifica di ogni punto, e in fondo la delega staccabile già intestata. La carta è
 * quella del gruppo «Rendiconto e assemblea»; il PDF di ognuno si conserva come documento della sua riga del registro degli invii, con l'impronta SHA-256.
 *
 * - **«Stampa le lettere»**: i convocati con un mezzo di carta (raccomandata, a mano, fax) senza un invio che vale, o con una lettera stampata che non dice
 *   più l'avviso di oggi (si ristampa, con i dati di oggi); un PDF unico, o un PDF per persona in uno .zip; una riga «stampata» per persona. La stampa non
 *   spedisce e non blocca niente: l'assemblea resta com'è, e date e punti si correggono. Poi «Registra la spedizione» ({@see ConsegneDellaConvocazione}).
 * - **«Manda le PEC»**: dalla casella PEC dello studio con {@see InvioPerPec}, una riga per persona, con l'avviso e i documenti dei suoi punti; se il
 *   collegamento non parte, gli altri non si provano.
 * - **Un invio per volta per assemblea** ({@see conIlBlocco()}): un secondo «Manda le PEC» o «Stampa le lettere» mentre il primo lavora lo dice.
 * - **All'invio di ognuno** la sua riga di `assemblee_convocati` nasce (o si aggiorna, per un secondo invio o una ristampa) con i dati di quel giorno; il
 *   convocato è fissato quando il suo avviso è spedito ({@see ElencoDeiConvocati}): una lettera solo stampata si ristampa con i dati di oggi.
 * - **La prima spedizione registrata** (una PEC partita, una raccomandata registrata spedita, una consegna a mano registrata: {@see ElencoDeiConvocati::spedito()})
 *   fa l'assemblea «convocata» (da allora date e ordine del giorno non si cambiano più) e chiude il compito «Convocare l'assemblea per la spesa urgente» delle
 *   fatture che hanno un punto (decisione 561); dopo ogni gesto i compiti della convocazione si rifanno ({@see dopoLaSpedizione()}).
 * - Ogni gesto è una riga del registro delle modifiche: «Convocazione spedita: 11 PEC», «Lettere della convocazione stampate: 5», con i convocati fra i
 *   soggetti.
 *
 * Prima di spedire: le date ci sono, l'ordine del giorno non è vuoto, e con un punto sulla destinazione d'uso nessuno è senza raccomandata o PEC e il punto
 * dice le parti comuni e la nuova destinazione (art. 1117-ter). Al più {@see TETTO} avvisi per volta.
 */
final class EmissioneDelleConvocazioni
{
    public const TETTO = 200;

    public const ETICHETTA_ANTEPRIMA = 'ANTEPRIMA';

    /** I mezzi di carta: l'avviso si stampa. */
    public const MEZZI_DI_CARTA = ['raccomandata', 'a_mano', 'fax'];

    public const NIENTE_DA_STAMPARE = 'Nessuna lettera da stampare: tutti quelli che si convocano su carta hanno già il loro avviso.';

    public const NIENTE_DA_MANDARE = 'Nessuna PEC da mandare: tutti quelli che si convocano per PEC hanno già il loro avviso.';

    public const GIA_IN_CORSO = 'La convocazione di questa assemblea sta già partendo: aspetta che finisca, poi ricarica la pagina.';

    /** Quanto dura al più il blocco di un invio, in secondi: se il lavoro si ferma senza toglierlo, dopo questo tempo si riprova. */
    public const DURATA_DEL_BLOCCO = 900;

    /** I giorni della settimana, dal lunedì (ISO 1). */
    private const GIORNI = [1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica'];

    public function __construct(
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly PdfService $pdf,
        private readonly ArchivioDocumenti $archivio,
        private readonly InvioPerPec $pec,
    ) {}

    /**
     * L'istantanea dell'avviso di una persona, come la scrive la vista `pdf.gestionale.assemblea_convocazione` e come la riga del registro la conserva.
     *
     * @param array<string, mixed> $voce una voce di {@see ElencoDeiConvocati::per()} (o un convocato di {@see ConvocatiDellAssemblea::alGiorno()})
     * @return array<string, mixed>
     */
    public static function avviso(Assemblea $assemblea, array $voce, ?string $mezzo = null, ?string $etichetta = null, ?CarbonInterface $quando = null): array
    {
        $assemblea->loadMissing(['condominio', 'sedute', 'punti.scala', 'punti.palazzina', 'punti.tabella', 'scala', 'palazzina', 'tabella']);
        $condominio = $assemblea->condominio;
        $quando ??= DateHelper::adessoUtente();
        $tipo = mb_strtolower($assemblea->tipo?->parola() ?? 'ordinaria');
        $nomeCondominio = TestiLettere::condominioConNome((string) $condominio->nome);
        $puntiDellaPersona = isset($voce['punti']) && is_array($voce['punti']) && $voce['punti'] !== [] ? array_map('intval', $voce['punti']) : null;
        $punti = $assemblea->punti->filter(fn (PuntoDellOrdineDelGiorno $p) => $puntiDellaPersona === null || in_array((int) $p->numero, $puntiDellaPersona, true));
        // L'assemblea dei condòmini di un gruppo (Coda 734, scena 8 della bozza): il gruppo nel sottotitolo, nell'apertura, in una riga e nella delega.
        $gruppo = UnitaDelPunto::gruppoDellAssemblea($assemblea) !== null ? (string) ($assemblea->tabella?->nome ?? '') : null;
        $perimetro = match ($assemblea->ambito) {
            AmbitoAssemblea::SCALA => $assemblea->scala?->name !== null ? 'scala ' . $assemblea->scala->name : null,
            AmbitoAssemblea::PALAZZINA => $assemblea->palazzina?->name !== null ? 'palazzina ' . $assemblea->palazzina->name : null,
            AmbitoAssemblea::GRUPPO => $gruppo !== null ? 'i condòmini della tabella «' . $gruppo . '»' : null,
            AmbitoAssemblea::CONDOMINIO, null => null,
        };
        $dellAssemblea = $gruppo !== null ? ' dei condòmini delle unità della tabella «' . $gruppo . '» del ' . $nomeCondominio : null;

        $sedute = [];
        foreach ($assemblea->sedute as $s) {
            $giorno = CarbonImmutable::parse($s->fissata_il->format('Y-m-d H:i:s'));
            $nome = $s->numero > 1 ? 'Prosecuzione' : ($s->convocazione === ConvocazioneSeduta::PRIMA ? 'Prima convocazione' : 'Seconda convocazione');
            $sedute[] = ['nome' => $nome, 'quando' => self::GIORNI[$giorno->dayOfWeekIso] . ' ' . $giorno->format('d/m/Y') . ' alle ' . $giorno->format('H:i'),
                'dove' => self::dove($assemblea, $s->luogo)];
        }
        $righe = [];
        if ($gruppo !== null) {
            $righe[] = 'L\'assemblea riguarda ' . UnitaDelPunto::soliCondomini(null, $gruppo, true) . ': votano e si contano solo loro.';
        }
        if ($assemblea->modalita === ModalitaAssemblea::MISTA && filled($assemblea->piattaforma)) {
            $righe[] = 'Si può partecipare anche in videoconferenza, sulla piattaforma «' . $assemblea->piattaforma . '»: il collegamento arriva prima della riunione.';
        } elseif ($assemblea->modalita === ModalitaAssemblea::VIDEOCONFERENZA) {
            $righe[] = 'L\'assemblea si tiene in videoconferenza' . (filled($assemblea->piattaforma) ? ', sulla piattaforma «' . $assemblea->piattaforma . '»' : '')
                . ': il collegamento arriva prima della riunione.';
        }
        if (count($sedute) > 1) {
            $righe[] = 'In seconda convocazione l\'assemblea si tiene se la prima non raggiunge il numero legale.';
        }

        $conduttore = null;
        if ($puntiDellaPersona !== null) {
            $frasi = [];
            foreach ($punti as $p) {
                $frasi[$p->conduttore === ConduttorePunto::INTERVIENE ? 'interviene senza voto' : 'vota al posto del proprietario'][] = (string) $p->numero;
            }
            $pezzi = [];
            foreach ($frasi as $cosa => $numeri) {
                $pezzi[] = (count($numeri) === 1 ? 'il punto ' : 'i punti ') . ConvocatiDellAssemblea::elenco($numeri) . ', dove ' . $cosa;
            }
            $conduttore = 'La convocazione la riguarda per ' . implode('; per ', $pezzi) . '. Gli altri punti dell\'ordine del giorno non la riguardano.';
        }

        $puntiDellAvviso = [];
        foreach ($punti as $p) {
            $note = [];
            $delPunto = UnitaDelPunto::gruppoProprio($p, $assemblea); // la parte e il gruppo del punto (Coda 734): la riga dice chi vota
            $soli = UnitaDelPunto::soliCondomini(UnitaDelPunto::parteDi($p), $delPunto);
            if ($soli !== null) {
                $note[] = 'Riguarda ' . $soli . ($delPunto !== null ? ': votano solo loro.' : ($p->tabella !== null ? '; la spesa si divide con la tabella «' . $p->tabella->nome . '»' : '') . '.');
            }
            if ($p->materia === MateriaPunto::DESTINAZIONE_USO) {
                $dati = (array) ($p->dati ?? []);
                if (filled($dati['parti_comuni'] ?? null)) {
                    $note[] = 'Le parti comuni che cambiano: ' . trim((string) $dati['parti_comuni']) . '.';
                }
                if (filled($dati['nuova_destinazione'] ?? null)) {
                    $note[] = 'La nuova destinazione d\'uso: ' . trim((string) $dati['nuova_destinazione']) . '.';
                }
                $note[] = 'Serve il voto dei quattro quinti dei condòmini e del valore dell\'edificio (art. 1117-ter c.c.).';
            }
            // La spesa urgente o oltre il preventivo porta il suo motivo, perché i condòmini lo leggano prima dell'assemblea (art. 1135 c. 2).
            $nota = self::notaDellaMotivazione((array) ($p->dati ?? []));
            if ($nota !== null) {
                $note[] = $nota;
            }
            $puntiDellAvviso[] = ['numero' => (int) $p->numero, 'testo' => (string) $p->testo, 'note' => $note];
        }

        $unita = ConvocatiDellAssemblea::unitaInParole((array) ($voce['unita'] ?? []));
        $unitaNellaDelega = ConvocatiDellAssemblea::elenco(array_values(array_unique(array_map(fn ($u) => (string) $u['nome'], (array) ($voce['unita'] ?? [])))));
        $date = array_map(fn ($s) => $s['quando'] . ' in ' . mb_strtolower($s['nome']), array_filter($sedute, fn ($s) => $s['nome'] !== 'Prosecuzione'));
        $recapito = match ($mezzo) {
            'pec' => filled($voce['pec'] ?? null) ? 'Per PEC: ' . $voce['pec'] : null,
            'raccomandata' => 'Raccomandata A/R',
            'a_mano' => 'Consegna a mano',
            'fax' => 'Per fax',
            default => null,
        };

        return [
            'etichetta' => $etichetta,
            'anagrafica_id' => isset($voce['anagrafica_id']) ? (int) $voce['anagrafica_id'] : null,
            'persona' => ['nome' => (string) ($voce['nome'] ?? ''), 'indirizzo' => (string) ($voce['indirizzo'] ?? ''), 'unita' => $unita, 'recapito' => $recapito],
            'luogo_data' => trim((filled($condominio->comune) ? $condominio->comune . ', ' : '') . $quando->format('d/m/Y')),
            'titolo' => 'Convocazione dell\'assemblea ' . $tipo,
            'sottotitolo' => $nomeCondominio . ($perimetro !== null ? ' · ' . $perimetro : ''),
            'saluto' => trim((string) ($voce['nome'] ?? '')) === '' ? 'Gentili condòmini,' : 'Gentile ' . trim((string) $voce['nome']) . ',',
            'apertura' => $puntiDellaPersona === null
                ? 'la invito all\'assemblea ' . $tipo . ($dellAssemblea ?? ' dei condòmini del ' . $nomeCondominio . ($perimetro !== null ? ', ' . $perimetro : '')) . ', che si terrà:'
                : 'la invito all\'assemblea ' . $tipo . ($dellAssemblea ?? ' del ' . $nomeCondominio) . ' per i punti che la riguardano come conduttore, che si terrà:',
            'sedute' => $sedute,
            'righe_della_riunione' => $righe,
            'conduttore' => $conduttore,
            'titolo_ordine' => $puntiDellaPersona === null ? 'Ordine del giorno' : 'I punti che la riguardano',
            'punti' => $puntiDellAvviso,
            'chiusura' => array_values(array_filter([
                $unita !== '' ? 'L\'avviso è per le unità: ' . $unita . '.' : null,
                'Se non può partecipare, può farsi rappresentare con la delega qui sotto, compilata e firmata.',
            ])),
            'delega' => [
                'ritaglia' => 'Ritaglia lungo la linea e consegna la delega a chi la rappresenta',
                // Una forma che vale per tutti: l'anagrafica non ha il genere.
                'testo' => trim((string) ($voce['nome'] ?? '')) === '' ? 'Chi firma, ________________________________, unità ______________, delega'
                    : 'Chi firma, ' . trim((string) $voce['nome']) . ($unitaNellaDelega !== '' ? ', ' . $unitaNellaDelega : '') . ', delega',
                'delegato' => 'il sig. / la sig.ra ______________________________________________',
                'poteri' => 'a rappresentare chi firma nell\'assemblea ' . $tipo . ($gruppo !== null ? ' dei condòmini della tabella «' . $gruppo . '»' : '')
                    . ' del ' . $nomeCondominio . ($date !== [] ? ' di ' . ConvocatiDellAssemblea::elenco(array_values($date)) : '')
                    . ($puntiDellaPersona === null ? ', con ogni potere di intervento e di voto.' : ', per ' . (count($puntiDellaPersona) === 1 ? 'il punto ' : 'i punti ')
                        . ConvocatiDellAssemblea::elenco(array_map('strval', $puntiDellaPersona)) . '.'),
                'avvertenza' => 'Non si può delegare l\'amministratore (art. 67 c. 5 disp. att. c.c.). Se i condòmini sono più di venti, il delegato non può rappresentare più di un quinto dei condòmini e del valore.',
            ],
        ];
    }

    /** Il PDF di uno o più avvisi, uno per pagina nuova. @param list<array<string, mixed>> $avvisi */
    public function rendi(Assemblea $assemblea, array $avvisi): string
    {
        return $this->pdf->generate('pdf.gestionale.assemblea_convocazione', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'avvisi' => $avvisi,
        ])->Output('', Destination::STRING_RETURN);
    }

    /**
     * L'anteprima: gli avvisi di chi si convoca oggi (o delle persone date), con «ANTEPRIMA» in testa; non scrive niente.
     *
     * @param ?list<int> $anagrafiche
     */
    public function anteprima(Assemblea $assemblea, ?array $anagrafiche = null): string
    {
        $voci = array_values(array_filter($this->elenco->per($assemblea), fn ($v) => $v['oggi']
            && ($anagrafiche === null || in_array((int) $v['anagrafica_id'], $anagrafiche, true))));
        if ($voci === []) {
            throw ValidationException::withMessages(['convocazione' => 'Nessun convocato da mostrare.']);
        }
        $this->dentroIlTetto(count($voci));

        return $this->rendi($assemblea, array_map(fn ($v) => $this->avviso($assemblea, $v, $v['mezzo']['mezzo'], self::ETICHETTA_ANTEPRIMA), $voci));
    }

    /**
     * «Stampa le lettere»: un PDF unico (`pdf`) o uno .zip con un PDF per persona (`zip`), per chi si convoca su carta e non ha ancora un invio che vale.
     *
     * @param ?list<int> $anagrafiche
     * @return array{contenuto: ?string, file: ?string, nome: string, mime: string, quante: int}
     */
    public function stampaLeLettere(Assemblea $assemblea, ?array $anagrafiche, string $formato, User $utente): array
    {
        return self::conIlBlocco($assemblea, fn () => $this->stampa($assemblea, $anagrafiche, $formato, $utente));
    }

    /**
     * Un invio per volta per assemblea: un secondo clic, o una seconda richiesta dopo che il server web ha smesso di aspettare, trova il blocco e lo dice,
     * invece di calcolare di nuovo chi è da spedire mentre il primo lavora (le stesse persone riceverebbero due avvisi).
     *
     * @template T
     * @param \Closure(): T $lavoro
     * @return T
     */
    public static function conIlBlocco(Assemblea $assemblea, \Closure $lavoro): mixed
    {
        $blocco = Cache::lock(self::chiaveDelBlocco($assemblea), self::DURATA_DEL_BLOCCO);
        if (! $blocco->get()) {
            throw ValidationException::withMessages(['convocazione' => self::GIA_IN_CORSO]);
        }
        try {
            return $lavoro();
        } finally {
            $blocco->release();
        }
    }

    public static function chiaveDelBlocco(Assemblea $assemblea): string
    {
        return 'assemblee:convocazione:' . $assemblea->id;
    }

    /** @param ?list<int> $anagrafiche @return array{contenuto: ?string, file: ?string, nome: string, mime: string, quante: int} */
    private function stampa(Assemblea $assemblea, ?array $anagrafiche, string $formato, User $utente): array
    {
        $this->primaDiSpedire($assemblea);
        $voci = $this->elenco->daStampare($assemblea, $anagrafiche);
        if ($voci === []) {
            throw ValidationException::withMessages(['convocazione' => self::NIENTE_DA_STAMPARE]);
        }
        $this->dentroIlTetto(count($voci));
        $zip = $formato === 'zip';
        if ($zip && ! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['convocazione' => 'Su questo server PHP non ha l\'estensione zip: stampa un PDF unico.']);
        }
        $scritti = [];
        $pdfDiOgnuno = [];

        try {
            // La stampa non spedisce: l'assemblea resta com'è (la prima spedizione registrata la fa «convocata»), e la riga del registro dice le lettere stampate.
            DB::transaction(function () use ($assemblea, $voci, $utente, &$scritti, &$pdfDiOgnuno) {
                RegistroModifiche::durante(GestiDelRegistro::lettereStampate($assemblea, count($voci), array_map(fn ($v) => (int) $v['anagrafica_id'], $voci)),
                    function () use ($assemblea, $voci, $utente, &$scritti, &$pdfDiOgnuno) {
                    foreach ($voci as $v) {
                        $mezzo = (string) $v['mezzo']['mezzo'];
                        $avviso = $this->avviso($assemblea, $v, $mezzo);
                        $pdf = $this->rendi($assemblea, [$avviso]);
                        $nome = self::nomeDelFile($assemblea, (string) $v['nome']);
                        $percorso = $this->archivio->salvaContenuto($pdf, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $assemblea->condominio_id . '/convocazioni');
                        $scritti[] = $percorso;
                        $riga = RegistroInvio::create([
                            'condominio_id' => $assemblea->condominio_id, 'anagrafica_id' => (int) $v['anagrafica_id'], 'tipo' => RegistroInvio::TIPO_CONVOCAZIONE,
                            'canale' => RegistroInvio::CANALE_STAMPA, 'documentabile_type' => $assemblea->getMorphClass(), 'documentabile_id' => $assemblea->id,
                            'destinatario' => $mezzo === 'raccomandata' ? (string) $v['indirizzo'] : null, 'dati' => self::datiDellInvio($assemblea, $avviso, $mezzo),
                            'impronta' => hash('sha256', $pdf), 'stato' => RegistroInvio::STATO_STAMPATA, 'emesso_da' => $utente->id, 'emesso_il' => now(),
                        ]);
                        $documento = $riga->documenti()->create(['name' => $nome, 'description' => 'Avviso di convocazione a ' . $v['nome'], 'path' => $percorso,
                            'mime_type' => 'application/pdf', 'file_size' => strlen($pdf), 'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true]);
                        $riga->update(['dati' => (array) $riga->dati + ['documento_id' => $documento->id]]);
                        $this->fissa($assemblea, $v, $mezzo, $riga, $utente);
                        $pdfDiOgnuno[] = ['nome' => $nome, 'pdf' => $pdf];
                    }
                });
                $this->dopoLaSpedizione($assemblea, $utente, false);
            });
        } catch (\Throwable $e) {
            foreach ($scritti as $p) {
                $this->archivio->elimina($p);
            }

            throw $e;
        }

        if ($zip) {
            $archivio = new ArchivioAPezzi(\RuntimeException::class);
            foreach ($pdfDiOgnuno as $p) {
                $archivio->aggiungi($p['nome'], $p['pdf']);
            }

            return ['contenuto' => null, 'file' => $archivio->scrivi(), 'nome' => Str::replaceLast('.pdf', '.zip', PdfService::nomeFile('convocazioni',
                (string) $assemblea->condominio->nome, $assemblea->data?->format('Y-m-d'))), 'mime' => 'application/zip', 'quante' => count($voci)];
        }

        return ['contenuto' => UnioneDiPdf::unisci(array_column($pdfDiOgnuno, 'pdf')), 'file' => null,
            'nome' => PdfService::nomeFile('convocazioni', (string) $assemblea->condominio->nome, $assemblea->data?->format('Y-m-d')), 'mime' => 'application/pdf',
            'quante' => count($voci)];
    }

    /**
     * «Manda le PEC»: un avviso per persona dalla casella PEC dello studio. Le partite sono «Spedite»; le non partite restano da mandare, con l'errore. Se il
     * collegamento non parte, le altre non si provano (la porta 465 chiusa le farebbe aspettare tutte).
     *
     * @param ?list<int> $anagrafiche
     * @return array{partite: int, non_partite: int, frase: string, errore: ?string}
     */
    public function mandaLePec(Assemblea $assemblea, ?array $anagrafiche, User $utente): array
    {
        return self::conIlBlocco($assemblea, fn () => $this->manda($assemblea, $anagrafiche, $utente));
    }

    /** @param ?list<int> $anagrafiche @return array{partite: int, non_partite: int, frase: string, errore: ?string} */
    private function manda(Assemblea $assemblea, ?array $anagrafiche, User $utente): array
    {
        if (! $this->pec->puoInviare()) {
            throw ValidationException::withMessages(['convocazione' => PecDelloStudio::NON_IMPOSTATA]);
        }
        $this->primaDiSpedire($assemblea);
        $voci = $this->elenco->daSpedire($assemblea, ['pec'], $anagrafiche);
        if ($voci === []) {
            throw ValidationException::withMessages(['convocazione' => self::NIENTE_DA_MANDARE]);
        }
        $this->dentroIlTetto(count($voci));
        // I documenti dei punti (le versioni fermate del rendiconto e del preventivo, i documenti collegati) partono con l'avviso: a ognuno quelli dei suoi
        // punti, come «Uno per persona» del fascicolo. Si leggono una volta sola.
        $documentiDeiPunti = app(FascicoloDellAssemblea::class)->documenti($assemblea);

        $partite = [];
        $nonPartite = 0;
        $errore = null;
        $condominio = $assemblea->condominio;
        // Un gesto in più transazioni: una per PEC partita (la riga del convocato), e la riga del gesto alla fine con il numero vero delle partite; se il lavoro
        // si ferma a metà, la riga dice ciò che è partito prima. Nessuna partita, nessuna riga.
        $gesto = GestiDelRegistro::convocazioneSpedita($assemblea, ['pec' => 0], [])->scriviSe(function () use (&$partite) {
            return $partite !== [];
        });
        RegistroModifiche::durantePiuTransazioni($gesto,
            function ($gesto) use ($assemblea, $voci, $utente, $condominio, $documentiDeiPunti, &$partite, &$nonPartite, &$errore) {
                foreach ($voci as $v) {
                    $avviso = $this->avviso($assemblea, $v, 'pec');
                    $pdf = $this->rendi($assemblea, [$avviso]);
                    $allegati = self::allegatiDellaPersona($documentiDeiPunti, $v['punti'] ?? null);
                    $documento = new DocumentoPerPec((int) $assemblea->condominio_id, (int) $v['anagrafica_id'], (string) $v['pec'], RegistroInvio::TIPO_CONVOCAZIONE,
                        $assemblea, $pdf, self::nomeDelFile($assemblea, (string) $v['nome']), self::oggettoDellaPec($assemblea),
                        self::testoDellaPec($assemblea, (string) $v['nome'], $allegati !== []), self::datiDellInvio($assemblea, $avviso, 'pec', $allegati),
                        array_map(fn ($a) => ['file' => $a['file'], 'contenuto' => $a['contenuto']], $allegati));
                    try {
                        $riga = $this->pec->invia($documento, $utente->id);
                    } catch (CasellaPecNonImpostata) {
                        $errore = PecDelloStudio::NON_IMPOSTATA;
                        break;
                    }
                    if ($riga->stato !== RegistroInvio::STATO_INVIATA) {
                        $nonPartite++;
                        $errore = ErroreDellaPec::frase($riga->errore, $this->pec->casella());
                        if (ErroreDellaPec::diConnessione($riga->errore)) {
                            break;
                        }
                        continue;
                    }
                    DB::transaction(function () use ($assemblea, $v, $riga, $utente, &$partite) {
                        if ($partite === []) {
                            $this->convocataAllaPrima($assemblea, $utente);
                        }
                        $this->fissa($assemblea, $v, 'pec', $riga, $utente);
                        $partite[] = (int) $v['anagrafica_id'];
                    });
                }
                $gesto->soggettiDi('anagrafica', $partite)->lotto(['mezzi' => ['pec' => count($partite)]])
                    ->riepilogo('Convocazione spedita: ' . count($partite) . ' PEC');

                return ['partite' => count($partite)];
            });
        if ($partite !== []) {
            $this->dopoLaSpedizione($assemblea->refresh(), $utente, true);
        }
        // Le altre: quelle non accettate e quelle non provate dopo un collegamento caduto.
        $altre = count($voci) - count($partite);
        $frase = match (true) {
            $partite === [] => 'Nessuna PEC è partita.',
            count($partite) === 1 => '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.',
            default => count($partite) . ' PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.',
        };
        if ($errore !== null) {
            $frase = $partite === [] ? $errore . ' Nessuna PEC è partita.'
                : $frase . ' ' . ($altre === 1 ? 'L\'altra non è partita: ' : 'Le altre ' . $altre . ' non sono partite: ') . lcfirst($errore);
        }

        return ['partite' => count($partite), 'non_partite' => $nonPartite, 'frase' => $frase, 'errore' => $errore];
    }

    /**
     * I documenti dei punti che una persona riceve: tutti, o quelli dei suoi punti (il conduttore). Il nome del file dice il punto.
     *
     * @param list<array<string, mixed>> $documenti da {@see FascicoloDellAssemblea::documenti()}
     * @param ?list<int> $punti
     * @return list<array{punto: int, nome: string, file: string, impronta: string, contenuto: string}>
     */
    public static function allegatiDellaPersona(array $documenti, ?array $punti): array
    {
        $suoi = $punti === null || $punti === [] ? $documenti : array_filter($documenti, fn ($d) => in_array((int) $d['numero'], array_map('intval', $punti), true));
        $allegati = [];
        foreach (array_values($suoi) as $d) {
            $base = Str::slug(pathinfo((string) ($d['file'] ?? $d['nome']), PATHINFO_FILENAME)) ?: 'documento';
            $allegati[] = ['punto' => (int) $d['numero'], 'nome' => (string) $d['nome'], 'file' => 'punto-' . $d['numero'] . '-' . $base . '.pdf',
                'impronta' => (string) $d['impronta'], 'contenuto' => (string) $d['contenuto']];
        }

        return $allegati;
    }

    /**
     * I dati della riga di un invio della convocazione: il mezzo, i punti dell'avviso (con il testo), gli allegati partiti con lui (il punto, il nome, il file e
     * l'impronta: per PEC i documenti dei punti), il nome e le unità di allora.
     *
     * @param list<array<string, mixed>> $allegati
     */
    public static function datiDellInvio(Assemblea $assemblea, array $avviso, string $mezzo, array $allegati = []): array
    {
        return [
            'mezzo' => $mezzo,
            'punti' => array_map(fn ($p) => ['numero' => $p['numero'], 'testo' => $p['testo']], $avviso['punti']),
            'allegati' => array_map(fn ($a) => ['punto' => (int) $a['punto'], 'nome' => (string) $a['nome'], 'file' => (string) $a['file'],
                'impronta' => (string) $a['impronta']], $allegati),
            'persona' => $avviso['persona'],
            'sedute' => $avviso['sedute'],
            // L'impronta dell'avviso intero: una lettera stampata è da ristampare quando l'avviso di oggi non ha più questa impronta.
            'impronta_avviso' => self::improntaDellAvviso($avviso),
        ];
    }

    /**
     * L'impronta di un avviso, senza ciò che non cambia la lettera: il luogo e il giorno in testa (il giorno della stampa) e l'etichetta dell'anteprima. Tutto il
     * resto conta: le date, il luogo o la piattaforma, il tipo, il perimetro, i punti con il testo e le note (la motivazione, le parti comuni), la persona con
     * l'indirizzo, le unità e il mezzo, la delega.
     *
     * @param array<string, mixed> $avviso da {@see avviso()}
     */
    public static function improntaDellAvviso(array $avviso): string
    {
        unset($avviso['luogo_data'], $avviso['etichetta']);

        return hash('sha256', (string) json_encode($avviso, JSON_UNESCAPED_UNICODE));
    }

    public static function nomeDelFile(Assemblea $assemblea, string $persona): string
    {
        return 'convocazione-' . ($assemblea->data?->format('Y-m-d') ?? 'assemblea') . '-' . (Str::slug($persona) ?: 'persona') . '.pdf';
    }

    public static function oggettoDellaPec(Assemblea $assemblea): string
    {
        $assemblea->loadMissing('condominio');

        return 'Convocazione dell\'assemblea ' . mb_strtolower($assemblea->tipo?->parola() ?? 'ordinaria') . ' — '
            . TestiLettere::condominioConNome((string) $assemblea->condominio->nome) . ($assemblea->data !== null ? ', ' . $assemblea->data->format('d/m/Y') : '');
    }

    public static function testoDellaPec(Assemblea $assemblea, string $persona, bool $conIDocumenti = false): string
    {
        $assemblea->loadMissing('condominio');

        return 'Gentile ' . trim($persona) . ",\n\nin allegato l'avviso di convocazione dell'assemblea " . mb_strtolower($assemblea->tipo?->parola() ?? 'ordinaria')
            . ' del ' . TestiLettere::condominioConNome((string) $assemblea->condominio->nome) . ", con l'ordine del giorno e la delega"
            . ($conIDocumenti ? ", e i documenti dei punti: il nome di ogni file dice il suo punto" : '') . ".\n\nL'amministratore";
    }

    /**
     * Sotto il punto il motivo della spesa: «Perché era urgente: …» per quella pagata per l'urgenza, «Perché supera il preventivo: …» per quella oltre il
     * preventivo (`dati.motivazione` con `dati.motivazione_di`). Null senza un motivo.
     *
     * @param array<string, mixed> $dati
     */
    public static function notaDellaMotivazione(array $dati): ?string
    {
        $motivazione = trim((string) ($dati['motivazione'] ?? ''));
        if ($motivazione === '') {
            return null;
        }

        return (($dati['motivazione_di'] ?? null) === 'sforo' ? 'Perché supera il preventivo: ' : 'Perché era urgente: ') . $motivazione
            . (preg_match('/[.!?]$/u', $motivazione) ? '' : '.');
    }

    /**
     * Che cosa manca all'avviso perché dica dove si tiene l'assemblea (art. 66 c. 3: «luogo e ora»; per la videoconferenza «la piattaforma e l'ora»): il luogo
     * di ogni seduta in presenza (suo o dell'assemblea), la piattaforma in videoconferenza. Null se non manca niente.
     */
    public static function cheCosaMancaAllAvviso(Assemblea $assemblea): ?string
    {
        $manca = self::cosaMancaAllAvviso($assemblea);

        return $manca === null ? null : 'La spedizione non parte: ' . $manca['lungo'];
    }

    /**
     * La stessa cosa in una frase corta, per la riga dei perché sotto l'intestazione dei convocati («Lettere e PEC: …»): «Manca il luogo della prima e della
     * seconda convocazione (art. 66 c. 3). Scrivilo nel passo «Date e ordine del giorno».»
     */
    public static function cheCosaMancaInBreve(Assemblea $assemblea): ?string
    {
        return self::cosaMancaAllAvviso($assemblea)['breve'] ?? null;
    }

    /** @return ?array{lungo: string, breve: string} */
    private static function cosaMancaAllAvviso(Assemblea $assemblea): ?array
    {
        $modalita = $assemblea->modalita ?? ModalitaAssemblea::PRESENZA;
        if ($modalita !== ModalitaAssemblea::PRESENZA && ! filled($assemblea->piattaforma)) {
            return [
                'lungo' => 'in videoconferenza l\'avviso deve dire la piattaforma (art. 66 c. 3). Scrivila nel passo «Date e ordine del giorno».',
                'breve' => 'Manca la piattaforma della videoconferenza (art. 66 c. 3). Scrivila nel passo «Date e ordine del giorno».',
            ];
        }
        if ($modalita === ModalitaAssemblea::VIDEOCONFERENZA || filled($assemblea->luogo)) {
            return null;
        }
        $senza = [];
        foreach ($assemblea->sedute()->orderByRaw("CASE convocazione WHEN 'prima' THEN 0 ELSE 1 END")->orderBy('numero')->get() as $s) {
            if (! filled($s->luogo)) {
                $senza[] = $s->numero > 1 ? 'della prosecuzione del ' . $s->fissata_il->format('d/m') : ($s->convocazione === ConvocazioneSeduta::PRIMA
                    ? 'della prima convocazione' : 'della seconda convocazione');
            }
        }

        return $senza === [] ? null : [
            'lungo' => 'l\'avviso deve dire dove si tiene l\'assemblea (art. 66 c. 3). Manca il luogo ' . ConvocatiDellAssemblea::elenco($senza)
                . ': scrivilo nel passo «Date e ordine del giorno».',
            'breve' => 'Manca il luogo ' . ConvocatiDellAssemblea::elenco($senza) . ' (art. 66 c. 3). Scrivilo nel passo «Date e ordine del giorno».',
        ];
    }

    /**
     * Le date ci sono, l'ordine del giorno non è vuoto, l'assemblea è in preparazione o convocata, l'avviso dice dove si tiene; con la destinazione d'uso i
     * suoi controlli. `$voci` è l'elenco dei convocati già letto da chi chiama ({@see ElencoDeiConvocati::per()}), per non leggerlo due volte.
     *
     * @param ?list<array<string, mixed>> $voci
     */
    public function primaDiSpedire(Assemblea $assemblea, ?array $voci = null): void
    {
        if (! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true) || $assemblea->eAMano()) {
            throw ValidationException::withMessages(['convocazione' => 'Si spedisce la convocazione di un\'assemblea in preparazione o convocata.']);
        }
        if ($this->termini->primaConvocazione($assemblea) === null) {
            throw ValidationException::withMessages(['convocazione' => 'L\'assemblea non ha ancora la data della prima convocazione.']);
        }
        if (! $assemblea->punti()->exists()) {
            throw ValidationException::withMessages(['convocazione' => 'L\'ordine del giorno è vuoto: aggiungi i punti prima di spedire.']);
        }
        $manca = self::cheCosaMancaAllAvviso($assemblea);
        if ($manca !== null) {
            throw ValidationException::withMessages(['convocazione' => $manca]);
        }
        if (ConvocatiDellAssemblea::conDestinazioneDUso($assemblea)) {
            $senza = array_filter($voci ?? $this->elenco->per($assemblea), fn ($v) => $v['oggi'] && ! $v['vale'] && ! $v['mezzo']['valido']);
            if ($senza !== []) {
                throw ValidationException::withMessages(['convocazione' => 'La spedizione non parte: con un punto sulla destinazione d\'uso valgono solo la raccomandata e la PEC, e '
                    . (count($senza) === 1 ? '1 persona è' : count($senza) . ' persone sono') . ' senza (' . ConvocatiDellAssemblea::elenco(array_map(fn ($v) => (string) $v['nome'], array_values($senza))) . ').']);
            }
            $scheda = $this->termini->perLaScheda($assemblea, [], false);
            $mancano = (array) ($scheda['destinazione_uso']['mancano'] ?? []);
            if ($mancano !== []) {
                throw ValidationException::withMessages(['convocazione' => 'La spedizione non parte: per la destinazione d\'uso mancano ' . ConvocatiDellAssemblea::elenco($mancano) . ' (art. 1117-ter).']);
            }
        }
    }

    private function dentroIlTetto(int $quanti): void
    {
        if ($quanti > self::TETTO) {
            throw ValidationException::withMessages(['convocazione' => 'Al più ' . self::TETTO . ' avvisi per volta: scegli le persone a gruppi.']);
        }
    }

    /** Alla prima spedizione l'assemblea diventa «convocata»: da allora date e ordine del giorno non si cambiano più. */
    private function convocataAllaPrima(Assemblea $assemblea, User $utente): void
    {
        if ($assemblea->stato === StatoAssemblea::IN_PREPARAZIONE) {
            StatiDellAssemblea::convocata($assemblea, $utente);
        }
    }

    /**
     * Le date e i punti dell'avviso di una persona, come li conserva la riga dell'invio (le sedute con il giorno, l'ora e il luogo; i punti con il numero e il
     * testo): per sapere se una lettera stampata è ancora quella di oggi.
     *
     * @param array<string, mixed> $voce
     * @return array{sedute: list<list<string>>, punti: list<array{0: int, 1: string}>}
     */
    public static function dateEPunti(Assemblea $assemblea, array $voce, string $mezzo): array
    {
        return self::dateEPuntiDellInvio(self::datiDellInvio($assemblea, self::avviso($assemblea, $voce, $mezzo), $mezzo));
    }

    /**
     * Le date e i punti dai dati di una riga dell'invio, nella forma di {@see dateEPunti()}.
     *
     * @param array<string, mixed> $dati
     * @return array{sedute: list<list<string>>, punti: list<array{0: int, 1: string}>}
     */
    public static function dateEPuntiDellInvio(array $dati): array
    {
        return [
            'sedute' => array_map(fn ($s) => [(string) ($s['nome'] ?? ''), (string) ($s['quando'] ?? ''), (string) ($s['dove'] ?? '')], array_values((array) ($dati['sedute'] ?? []))),
            'punti' => array_map(fn ($p) => [(int) ($p['numero'] ?? 0), (string) ($p['testo'] ?? '')], array_values((array) ($dati['punti'] ?? []))),
        ];
    }

    /** Il convocato fissato all'invio: i dati copiati, il mezzo, l'invio che vale. Un secondo invio della stessa persona aggiorna la sua riga. */
    private function fissa(Assemblea $assemblea, array $voce, string $mezzo, RegistroInvio $invio, User $utente): ConvocatoDellAssemblea
    {
        return ConvocatoDellAssemblea::query()->updateOrCreate(
            ['assemblea_id' => $assemblea->id, 'anagrafica_id' => (int) $voce['anagrafica_id']],
            ['nome' => (string) $voce['nome'], 'indirizzo' => (string) ($voce['indirizzo'] ?? ''), 'pec' => $voce['pec'] ?? null,
                'unita' => (array) ($voce['unita'] ?? []), 'punti' => $voce['punti'] ?? null, 'mezzo' => $mezzo, 'fissato_il' => now(),
                'invio_id' => $invio->id, 'created_by' => $utente->id],
        );
    }

    /**
     * Dopo un gesto della convocazione (le lettere stampate, le PEC, una spedizione o una consegna registrata): con `$spedita` (il gesto ha spedito almeno un
     * avviso, {@see ElencoDeiConvocati::spedito()}) l'assemblea in preparazione diventa «convocata» e si chiude il compito «Convocare l'assemblea per la spesa
     * urgente» delle fatture che hanno un punto in questa assemblea (decisione 561); i compiti della convocazione si rifanno sempre. La stampa da sola non
     * spedisce: l'assemblea resta in preparazione.
     */
    public function dopoLaSpedizione(Assemblea $assemblea, ?User $utente, bool $spedita): void
    {
        if (! $spedita) {
            app(CompitiDellaConvocazione::class)->sincronizza($assemblea);

            return;
        }
        if ($utente !== null) {
            $this->convocataAllaPrima($assemblea, $utente);
        }
        // Il nuovo titolare che adesso ha il suo avviso spedito non aspetta più la convocazione.
        app(NuovoTitolareNonConvocato::class)->dopoLaSpedizione($assemblea, array_values(array_map(fn ($v) => (int) $v['anagrafica_id'],
            array_filter($this->elenco->per($assemblea), fn ($v) => $v['spedito']))));
        $assemblea->loadMissing('punti.oggetti');
        foreach ($assemblea->punti as $punto) {
            $fatture = $punto->oggetti->filter(fn ($o) => $o->oggetto_tipo === 'fattura_passiva')->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();
            if (preg_match('/^fattura:(\d+)$/', (string) $punto->chiave_fonte, $m) === 1) {
                $fatture[] = (int) $m[1];
            }
            foreach (array_unique($fatture) as $fattura) {
                $compiti = Evento::query()->where('meta->type', 'convocazione_urgenza')->where('meta->context->fattura_id', $fattura)
                    ->where('is_completed', false)->get();
                foreach ($compiti as $compito) {
                    ChiusuraDeiCompiti::chiudi($compito, 'Chiuso: è partita la convocazione dell\'assemblea del ' . ($assemblea->data?->format('d/m/Y') ?? '')
                        . ', con il punto ' . $punto->numero . '.');
                }
            }
        }
        app(CompitiDellaConvocazione::class)->sincronizza($assemblea);
    }

    private static function dove(Assemblea $assemblea, ?string $luogoDellaSeduta): ?string
    {
        $luogo = filled($luogoDellaSeduta) ? trim((string) $luogoDellaSeduta) : (filled($assemblea->luogo) ? trim((string) $assemblea->luogo) : null);
        if ($assemblea->modalita === ModalitaAssemblea::VIDEOCONFERENZA) {
            return 'in videoconferenza' . (filled($assemblea->piattaforma) ? ', sulla piattaforma «' . $assemblea->piattaforma . '»' : '');
        }

        return $luogo;
    }
}
