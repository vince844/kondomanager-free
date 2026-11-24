<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Assemblee\Convocazione\UnioneDiPdf;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Posta\CasellaPecNonImpostata;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\ErroreDellaPec;
use App\Services\Posta\InvioPerPec;
use App\Services\Posta\PecDelloStudio;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La trasmissione del verbale registrato (V10, scena 3 del verbale e scena 6 della bozza delle scene nuove): **a chi lo riceve** ({@see DestinatariDelVerbale},
 * la lista proposta e corretta dall'amministratore), **con il mezzo e la prova della convocazione** (in busta o per PEC, mai in bacheca: il Garante).
 *
 * - **«Manda le PEC»** dalla casella dello studio: la lettera della trasmissione, e la copia sottoscritta com'è (il `.p7m` compreso). Una riga del registro degli
 *   invii per persona, tipo `verbale`, con l'assemblea come documento e `dati.verbale_id`.
 * - **«Stampa le lettere»** per chi la riceve su carta: per persona la lettera e la copia unita (dal `.p7m` il PDF dentro, dall'immagine una pagina con la
 *   scansione), una riga «stampata» per persona; poi «Registra la spedizione», «Registra la consegna» e la ricevuta PEC passano dalla consegna della
 *   convocazione, aperta al tipo del verbale, e il suo «dopo» chiama i compiti del verbale.
 * - **«Togli»** e **«Aggiungi una persona»** con il perché, nel registro delle modifiche.
 * - Un invio per volta per verbale, come la convocazione.
 */
final class TrasmissioneDelVerbale
{
    public const NIENTE_DA_MANDARE = 'Nessuna PEC da mandare: chi riceve il verbale per PEC lo ha già.';

    public const NIENTE_DA_STAMPARE = 'Nessuna lettera da stampare: chi riceve il verbale su carta ha già la sua.';

    public const GIA_IN_CORSO = 'La trasmissione di questo verbale sta già partendo: aspetta che finisca, poi ricarica la pagina.';

    public function __construct(
        private readonly DestinatariDelVerbale $destinatari,
        private readonly StampeDelVerbale $stampe,
        private readonly PdfDellaCopia $copia,
        private readonly ArchivioDocumenti $archivio,
        private readonly InvioPerPec $pec,
    ) {}

    /** «Togli» una persona dalla lista: non riceve il verbale, e per i punti in cui era assente conta come chi c'era e non ha votato. */
    public function togli(VerbaleDellAssemblea $verbale, int $anagraficaId, string $perche, User $utente): VerbaleDellAssemblea
    {
        $this->registrato($verbale);
        if (trim($perche) === '') {
            throw ValidationException::withMessages(['perche' => 'Scrivi perché la togli: resta nel registro delle modifiche.']);
        }
        $persona = collect($this->destinatari->per($verbale)['persone'])->firstWhere('anagrafica_id', $anagraficaId);
        if ($persona === null || ! $persona['riceve']) {
            throw ValidationException::withMessages(['anagrafica_id' => 'Questa persona non è fra chi riceve il verbale.']);
        }
        if ($persona['invio']['spedito'] ?? false) {
            throw ValidationException::withMessages(['anagrafica_id' => 'A questa persona il verbale è già partito: non si toglie.']);
        }
        $scelte = (array) ($verbale->trasmissione ?? []);
        $punti = $persona['punti_assente'];
        if (isset($scelte['aggiunti'][$anagraficaId])) {
            unset($scelte['aggiunti'][$anagraficaId]);
        } else {
            $scelte['tolti'][$anagraficaId] = ['perche' => trim($perche), 'il' => now()->utc()->toIso8601ZuluString(), 'da' => $utente->name];
        }
        $giorno = $verbale->seduta?->fissata_il?->format('d/m');
        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'chi lo riceve: tolta dalla lista: ' . $persona['nome'] . ($punti !== []
            ? ' · ' . ParoleDelVerbale::punti($punti) . ' contati dal ' . $giorno : ''), null, trim($perche), [$anagraficaId]),
            fn () => $verbale->update(['trasmissione' => $scelte]));

        return $verbale->refresh();
    }

    /**
     * «Aggiungi una persona» fra chi c'era: riceve il verbale come assente per i punti scelti (tutti se nessuno), e i suoi trenta giorni partono dalla
     * consegna. Una persona tolta prima torna nella lista.
     *
     * @param ?list<int> $punti i numeri dei punti
     */
    public function aggiungi(VerbaleDellAssemblea $verbale, int $anagraficaId, ?array $punti, string $perche, User $utente): VerbaleDellAssemblea
    {
        $this->registrato($verbale);
        if (trim($perche) === '') {
            throw ValidationException::withMessages(['perche' => 'Scrivi perché la aggiungi: resta nel registro delle modifiche.']);
        }
        $d = $this->destinatari->per($verbale);
        $persona = collect($d['persone'])->firstWhere('anagrafica_id', $anagraficaId);
        if ($persona === null) {
            throw ValidationException::withMessages(['anagrafica_id' => 'Questa persona non è fra i condòmini di questa riunione.']);
        }
        if ($persona['riceve']) {
            throw ValidationException::withMessages(['anagrafica_id' => 'Questa persona riceve già il verbale.']);
        }
        $deliberati = array_values(array_unique(array_map(fn ($p) => (int) $p['numero'], $d['punti'])));
        $punti = $punti === null || $punti === [] ? $deliberati : array_values(array_unique(array_intersect(array_map('intval', $punti), $deliberati)));
        if ($punti === []) {
            throw ValidationException::withMessages(['punti' => 'Scegli i punti per cui la persona riceve il verbale come assente.']);
        }
        $scelte = (array) ($verbale->trasmissione ?? []);
        if (isset($scelte['tolti'][$anagraficaId])) {
            unset($scelte['tolti'][$anagraficaId]);
        } else {
            $scelte['aggiunti'][$anagraficaId] = ['punti' => $punti, 'perche' => trim($perche), 'il' => now()->utc()->toIso8601ZuluString(), 'da' => $utente->name];
        }
        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'chi lo riceve: aggiunta a mano: ' . $persona['nome'] . ', come assente per '
            . ParoleDelVerbale::punti($punti) . ' · i trenta giorni dalla consegna', null, trim($perche), [$anagraficaId]),
            fn () => $verbale->update(['trasmissione' => $scelte]));

        return $verbale->refresh();
    }

    /**
     * «Manda le PEC»: a chi riceve il verbale per PEC e non l'ha ancora (o le persone date), la lettera e la copia sottoscritta com'è.
     *
     * @param ?list<int> $anagrafiche
     * @return array{partite: int, frase: string, errore: ?string}
     */
    public function mandaLePec(VerbaleDellAssemblea $verbale, ?array $anagrafiche, User $utente, bool $diNuovo = false): array
    {
        return $this->conIlBlocco($verbale, function () use ($verbale, $anagrafiche, $utente, $diNuovo) {
            $this->registrato($verbale);
            if (! $this->pec->puoInviare()) {
                throw ValidationException::withMessages(['trasmissione' => PecDelloStudio::NON_IMPOSTATA]);
            }
            $voci = $this->daMandare($verbale, ['pec'], $anagrafiche, $diNuovo);
            if ($voci === []) {
                throw ValidationException::withMessages(['trasmissione' => self::NIENTE_DA_MANDARE]);
            }
            [$copia, $nomeCopia, $mime] = $this->laCopia($verbale);
            $partite = [];
            $errore = null;
            $gesto = GestiDelRegistro::delVerbale($verbale, function () use (&$partite) {
                return 'trasmesso: ' . count($partite) . ' PEC';
            })->scriviSe(function () use (&$partite) {
                return $partite !== [];
            });
            RegistroModifiche::durantePiuTransazioni($gesto, function ($gesto) use ($verbale, $voci, $utente, $copia, $nomeCopia, $mime, &$partite, &$errore) {
                foreach ($voci as $v) {
                    $lettera = $this->stampe->lettera($verbale, $this->perLaLettera($v));
                    $documento = new DocumentoPerPec((int) $verbale->condominio_id, (int) $v['anagrafica_id'], (string) $v['mezzo']['indirizzo'],
                        RegistroInvio::TIPO_VERBALE, $verbale->assemblea, $lettera, $this->nomeDelFile($verbale, (string) $v['nome']), $this->oggetto($verbale),
                        $this->testo($verbale, (string) $v['nome']), $this->datiDellInvio($verbale, $v, 'pec'),
                        [['file' => $nomeCopia, 'contenuto' => $copia, 'mime' => $mime]]);
                    try {
                        $riga = $this->pec->invia($documento, $utente->id);
                    } catch (CasellaPecNonImpostata) {
                        $errore = PecDelloStudio::NON_IMPOSTATA;
                        break;
                    }
                    if ($riga->stato !== RegistroInvio::STATO_INVIATA) {
                        $errore = ErroreDellaPec::frase($riga->errore, $this->pec->casella());
                        if (ErroreDellaPec::diConnessione($riga->errore)) {
                            break;
                        }
                        continue;
                    }
                    $partite[] = (int) $v['anagrafica_id'];
                }
                $gesto->soggettiDi('anagrafica', $partite)->lotto(['pec' => count($partite)]);

                return ['partite' => count($partite)];
            });
            app(CompitiDelVerbale::class)->sincronizza($verbale->assemblea);
            $frase = $partite === [] ? 'Nessuna PEC è partita.' : (count($partite) === 1 ? '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.'
                : count($partite) . ' PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.');

            return ['partite' => count($partite), 'frase' => $errore !== null ? $frase . ' ' . $errore : $frase, 'errore' => $errore];
        });
    }

    /**
     * «Stampa le lettere»: per chi riceve il verbale su carta (raccomandata, a mano, fax) e non ha ancora una lettera che vale, la lettera e la copia unita, un
     * PDF unico; una riga «stampata» per persona. Poi «Registra la spedizione» o «Registra la consegna».
     *
     * @param ?list<int> $anagrafiche
     * @return array{contenuto: string, nome: string, quante: int}
     */
    public function stampaLeLettere(VerbaleDellAssemblea $verbale, ?array $anagrafiche, User $utente, bool $diNuovo = false): array
    {
        return $this->conIlBlocco($verbale, function () use ($verbale, $anagrafiche, $utente, $diNuovo) {
            $this->registrato($verbale);
            $voci = array_values(array_filter($this->daMandare($verbale, ['raccomandata', 'a_mano', 'fax'], $anagrafiche, $diNuovo),
                fn ($v) => ($v['invio']['stato'] ?? null) !== RegistroInvio::STATO_STAMPATA || $anagrafiche !== null));
            if ($voci === []) {
                throw ValidationException::withMessages(['trasmissione' => self::NIENTE_DA_STAMPARE]);
            }
            $documento = Documento::query()->findOrFail($verbale->documento_id);
            $copiaPdf = $this->copia->daDocumento($documento);
            if ($copiaPdf === null) {
                throw ValidationException::withMessages(['trasmissione' => 'La copia sottoscritta non si legge come PDF: sostituiscila con un PDF, un .p7m o un\'immagine.']);
            }
            $scritti = [];
            $pdf = [];
            try {
                RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, (count($voci) === 1 ? 'lettera della trasmissione stampata: 1'
                    : 'lettere della trasmissione stampate: ' . count($voci)) . ', con la copia sottoscritta unita', null, null,
                    array_map(fn ($v) => (int) $v['anagrafica_id'], $voci)), function () use ($verbale, $voci, $utente, $copiaPdf, &$scritti, &$pdf) {
                    foreach ($voci as $v) {
                        try {
                            $una = UnioneDiPdf::unisci([$this->stampe->lettera($verbale, $this->perLaLettera($v)), $copiaPdf]);
                        } catch (\Throwable $e) {
                            // La copia che non si legge, o che si legge ma non si unisce (un errore della libreria dei PDF): la frase, mai un errore del
                            // server. Un altro errore (la lettera stessa) resta un errore.
                            $delPdf = $e instanceof \setasign\Fpdi\PdfParser\PdfParserException || $e instanceof \setasign\Fpdi\PdfReader\PdfReaderException
                                || $e instanceof \Mpdf\MpdfException;
                            if ($delPdf || ! PdfDellaCopia::siLegge($copiaPdf)) {
                                throw ValidationException::withMessages(['trasmissione' => PdfDellaCopia::NON_SI_UNISCE]);
                            }
                            throw $e;
                        }
                        $percorso = $this->archivio->salvaContenuto($una, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $verbale->condominio_id . '/verbali');
                        $scritti[] = $percorso;
                        $mezzo = (string) $v['mezzo']['mezzo'];
                        $riga = RegistroInvio::create(['condominio_id' => $verbale->condominio_id, 'anagrafica_id' => (int) $v['anagrafica_id'],
                            'tipo' => RegistroInvio::TIPO_VERBALE, 'canale' => RegistroInvio::CANALE_STAMPA, 'documentabile_type' => $verbale->assemblea->getMorphClass(),
                            'documentabile_id' => $verbale->assemblea_id, 'destinatario' => $mezzo === 'raccomandata' ? (string) $v['mezzo']['indirizzo'] : null,
                            'dati' => $this->datiDellInvio($verbale, $v, $mezzo), 'impronta' => hash('sha256', $una), 'stato' => RegistroInvio::STATO_STAMPATA,
                            'emesso_da' => $utente->id, 'emesso_il' => now()]);
                        $doc = $riga->documenti()->create(['name' => $this->nomeDelFile($verbale, (string) $v['nome']), 'description' => 'Trasmissione del verbale n. '
                            . $verbale->numero . ' a ' . $v['nome'], 'path' => $percorso, 'mime_type' => 'application/pdf', 'file_size' => strlen($una),
                            'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true]);
                        $riga->update(['dati' => (array) $riga->dati + ['documento_id' => $doc->id]]);
                        $pdf[] = $una;
                    }
                });
            } catch (\Throwable $e) {
                foreach ($scritti as $p) {
                    $this->archivio->elimina($p);
                }
                throw $e;
            }
            app(CompitiDelVerbale::class)->sincronizza($verbale->assemblea);

            return ['contenuto' => UnioneDiPdf::unisci($pdf), 'nome' => PdfService::nomeFile('verbale-' . $verbale->numero, (string) $verbale->assemblea->condominio->nome,
                $verbale->seduta?->fissata_il?->format('Y-m-d'), 'lettere'), 'quante' => count($voci)];
        });
    }

    /** La prova della trasmissione: i destinatari con il mezzo, la consegna e i trenta giorni. */
    public function prova(VerbaleDellAssemblea $verbale): string
    {
        $d = $this->destinatari->per($verbale);
        $destinatari = array_map(function ($p) {
            $assente = collect($p['termini'])->firstWhere('perche', 'assente');

            return $p + ['fino_al' => $assente['fino_al'] ?? null];
        }, array_values(array_filter($d['persone'], fn ($p) => $p['riceve'])));

        return $this->stampe->prova($verbale, ['destinatari' => $destinatari]);
    }

    /**
     * Chi riceve il verbale con uno dei mezzi dati e non ha ancora un invio che vale (o una lettera solo stampata), o le persone date. Con `$diNuovo`
     * («rimandala», dopo «Sostituisci la copia») anche chi ha l'invio partito con la copia di prima: riceve quella nuova.
     *
     * @param list<string> $mezzi
     * @param ?list<int> $anagrafiche
     * @return list<array<string, mixed>>
     */
    public function daMandare(VerbaleDellAssemblea $verbale, array $mezzi, ?array $anagrafiche, bool $diNuovo = false): array
    {
        $sostituita = $diNuovo ? self::copiaSostituitaIl($verbale) : null;

        return array_values(array_filter($this->destinatari->per($verbale)['persone'], fn ($p) => $p['riceve'] && in_array($p['mezzo']['mezzo'], $mezzi, true)
            && ($sostituita !== null && ($p['invio']['spedito'] ?? false) && ($p['invio']['creato_il'] ?? null) !== null
                && \Carbon\Carbon::parse($p['invio']['creato_il'])->lt($sostituita)
            || ! ($p['invio']['spedito'] ?? false) && ($p['invio'] === null || $p['invio']['stato'] === RegistroInvio::STATO_STAMPATA || in_array($p['invio']['stato'],
                [RegistroInvio::STATO_NON_PARTITA], true) || $p['invio']['esito'] === RegistroInvio::ESITO_NON_CONSEGNATA))
            && ($anagrafiche === null || in_array((int) $p['anagrafica_id'], array_map('intval', $anagrafiche), true))));
    }

    /**
     * Prima di «Registra la spedizione»: una lettera stampata con la copia di prima (la copia è stata sostituita dopo la stampa) non parte. Undici raccomandate
     * da rifare si evitano qui: si ristampano prima di spedirle.
     *
     * @param list<int> $invii
     */
    public function controllaLeLettereStampate(VerbaleDellAssemblea $verbale, array $invii): void
    {
        $sostituita = self::copiaSostituitaIl($verbale);
        if ($sostituita === null) {
            return;
        }
        $vecchie = RegistroInvio::query()->whereIn('id', $invii)->where('tipo', RegistroInvio::TIPO_VERBALE)->where('stato', RegistroInvio::STATO_STAMPATA)
            ->where('created_at', '<', $sostituita)->get();
        if ($vecchie->isEmpty()) {
            return;
        }
        $nomi = $vecchie->map(fn (RegistroInvio $r) => (string) ($r->dati['persona']['nome'] ?? ''))->filter()->values()->all();
        throw ValidationException::withMessages(['invii' => ($vecchie->count() === 1 ? '1 lettera stampata' : $vecchie->count() . ' lettere stampate')
            . ' con la copia di prima' . ($nomi !== [] ? ' (' . implode(', ', $nomi) . ')' : '') . ': ristampale prima di spedirle.']);
    }

    /** L'ultima volta che la copia sottoscritta è stata sostituita (la versione di prima tenuta fra le versioni del documento), o null. */
    public static function copiaSostituitaIl(VerbaleDellAssemblea $verbale): ?\Carbon\CarbonInterface
    {
        if ($verbale->documento_id === null) {
            return null;
        }
        $il = \App\Models\DocumentoVersione::query()->where('documento_id', $verbale->documento_id)->max('sostituita_il');

        return $il === null ? null : \Carbon\Carbon::parse($il);
    }

    /** Il verbale è registrato, con la copia: si trasmette solo quello. */
    private function registrato(VerbaleDellAssemblea $verbale): void
    {
        if ($verbale->stato !== StatoVerbale::REGISTRATO || $verbale->documento_id === null) {
            throw ValidationException::withMessages(['trasmissione' => 'Si trasmette il verbale registrato, con la copia sottoscritta.']);
        }
    }

    /** La copia sottoscritta com'è, il suo nome e il tipo, per la PEC. @return array{0: string, 1: string, 2: string} */
    private function laCopia(VerbaleDellAssemblea $verbale): array
    {
        $documento = Documento::query()->findOrFail($verbale->documento_id);
        $contenuto = $this->archivio->leggi((string) $documento->path);
        if ($contenuto === null) {
            throw ValidationException::withMessages(['trasmissione' => 'La copia sottoscritta non si trova sul disco dei documenti.']);
        }
        $estensione = mb_strtolower(pathinfo((string) $documento->path, PATHINFO_EXTENSION)) ?: 'pdf';
        $nome = 'verbale-' . $verbale->numero . '-sottoscritto.' . $estensione;

        return [$contenuto, $nome, (string) ($documento->mime_type ?: 'application/octet-stream')];
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function perLaLettera(array $v): array
    {
        // Le delibere per cui è assente (non i soli numeri dei punti): chi era fuori al solo riparto del punto 1 non ha un termine per il rendiconto che ha votato.
        $voci = [];
        foreach ((array) ($v['termini'] ?? []) as $t) {
            if (($t['perche'] ?? null) === 'assente') {
                array_push($voci, ...array_values((array) ($t['voci'] ?? [])));
            }
        }
        $assente = $v['punti_assente'];
        // Ogni voce è una delibera: «al riparto del punto 1» è una sola, «ai punti 1 e 2» sono più delibere.
        $dove = $voci !== [] ? DestinatariDelVerbale::aiPunti($voci) : ($assente !== [] ? ParoleDelVerbale::punti($assente) : '');
        $piu = count($voci) > 1 || ($voci === [] && count($assente) > 1);

        return ['nome' => $v['nome'], 'unita' => $v['unita'], 'mezzo' => (string) ($v['mezzo']['mezzo'] ?? ''), 'mezzo_parola' => $v['mezzo']['parola'] ?? null,
            'indirizzo' => $v['mezzo']['indirizzo'] ?? null, 'righe' => array_values(array_filter([
            $dove !== '' ? 'Lo riceve come assente ' . ($voci !== [] ? '' : 'per ') . $dove . ': i trenta giorni per impugnare ' . ($piu ? 'quelle delibere' : 'quella delibera')
                . ' decorrono da quando lo riceve (art. 1137 c. 2 c.c.).' : null,
        ]))];
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function datiDellInvio(VerbaleDellAssemblea $verbale, array $v, string $mezzo): array
    {
        return ['mezzo' => $mezzo, 'verbale_id' => (int) $verbale->id, 'numero_verbale' => (int) $verbale->numero,
            'persona' => ['nome' => (string) $v['nome'], 'indirizzo' => (string) ($v['mezzo']['indirizzo'] ?? ''), 'unita' => (string) $v['unita']],
            'punti_assente' => $v['punti_assente']];
    }

    private function nomeDelFile(VerbaleDellAssemblea $verbale, string $persona): string
    {
        return 'verbale-' . $verbale->numero . '-' . (Str::slug($persona) ?: 'persona') . '.pdf';
    }

    private function oggetto(VerbaleDellAssemblea $verbale): string
    {
        return 'Verbale n. ' . $verbale->numero . ' dell\'assemblea ' . mb_strtolower((string) $verbale->assemblea->tipo?->parola()) . ' — '
            . TestiLettere::condominioConNome((string) $verbale->assemblea->condominio->nome) . ', ' . $verbale->seduta?->fissata_il?->format('d/m/Y');
    }

    private function testo(VerbaleDellAssemblea $verbale, string $persona): string
    {
        return 'Gentile ' . trim($persona) . ",\n\nin allegato il verbale n. " . $verbale->numero . ' dell\'assemblea del '
            . $verbale->seduta?->fissata_il?->format('d/m/Y') . ", nella copia sottoscritta, con la lettera della trasmissione.\n\nL'amministratore";
    }

    /** @template T @param \Closure(): T $lavoro @return T */
    private function conIlBlocco(VerbaleDellAssemblea $verbale, \Closure $lavoro): mixed
    {
        $blocco = Cache::lock('assemblee:verbale:trasmissione:' . $verbale->id, 900);
        if (! $blocco->get()) {
            throw ValidationException::withMessages(['trasmissione' => self::GIA_IN_CORSO]);
        }
        try {
            return $lavoro();
        } finally {
            $blocco->release();
        }
    }
}
