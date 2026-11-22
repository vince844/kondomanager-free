<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\StatoVerbale;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\RegistroDelPerimetro;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\Documento;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Documenti\VersioniDeiDocumenti;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La registrazione del verbale sottoscritto (V7, scena 1 del verbale e scene 3 e 4 della bozza delle scene nuove).
 *
 * - **«Stampa la bozza da firmare»** ferma in `stampata` la versione, l'impronta del testo, quella dei dati della sala e l'impronta del PDF; prima chiede, se
 *   una cifra non torna con i voti registrati o la sala è cambiata dopo la generazione ({@see CifreDellaSala}), e una scelta aperta fra due versioni la ferma.
 * - **«Registra il verbale sottoscritto»**, con il permesso suo: la copia (PDF, `.p7m` o immagine, al più 10 MB, che si apra come PDF) è un documento
 *   dell'assemblea, fuori dall'archivio e dal portale; il numero proposto (l'ultimo del registro più uno; la prima volta da dove parte il libro di carta, nella
 *   stessa transazione) si corregge ma non può essere già usato né più basso, anche con due registrazioni insieme, e un salto chiede la conferma; «Sottoscritto
 *   il» fra il giorno della riunione e oggi. **Si registra il testo firmato**: se il testo è cambiato dopo la bozza stampata, o i dati della sala sono cambiati
 *   dopo, la registrazione si ferma e dice di ristampare; senza una bozza stampata chiede prima ({@see cambiatoDopoLaStampa()}). Il testo registrato
 *   è una versione («registrato n. 42»), e non cambia più. Scrive `assemblee.numero_verbale` e `verbale_documento_id`, così chi li legge già dice «verbale n.
 *   42»; propone la riga di nomina e revoca della delibera di nomina; i compiti passano da «Registrare» a «Trasmettere».
 * - **«Sostituisci la copia»**: il file nuovo, la versione di prima resta (decisione 559); il numero e il giorno della firma restano; il perché è nella riga.
 * - **«Annulla la registrazione»** con il perché: la riga resta nel registro «Annullato», il numero non torna, il testo torna in bozza in un verbale nuovo che
 *   sostituisce quello annullato e prenderà il numero dopo (decisione 595 (5)).
 */
final class RegistrazioneDelVerbale
{
    public function __construct(
        private readonly RegistroDeiVerbali $registro,
        private readonly VersioniDelVerbale $versioni,
        private readonly CifreDellaSala $cifre,
        private readonly StampeDelVerbale $stampe,
    ) {}

    /**
     * «Stampa la bozza da firmare»: il PDF del testo di adesso, con la versione e l'impronta in piede, e `stampata`. Con le cifre che non tornano chiede prima
     * (`$ancheSeLeCifreNonTornano`).
     *
     * @return array{pdf: string, versione: int, impronta: string}
     */
    public function stampaLaBozza(VerbaleDellAssemblea $verbale, User $utente, bool $ancheSeLeCifreNonTornano = false): array
    {
        if ($verbale->stato !== StatoVerbale::BOZZA) {
            throw ValidationException::withMessages(['bozza' => $verbale->stato === StatoVerbale::IN_SALA ? 'Prima genera il verbale dal modello.'
                : 'Il verbale è registrato: si stampa la copia firmata, non la bozza.']);
        }
        if (($aperta = self::sceltaAperta($verbale)) !== null) {
            throw ValidationException::withMessages(['bozza' => $aperta . ', poi stampa la bozza.']);
        }
        $controllo = $this->cifre->controlla($verbale);
        $avvisi = $controllo['avvisi'];
        // La sala cambiata dopo la generazione chiede prima come una cifra che non torna: la bozza firmata direbbe i voti di prima.
        $frasi = [...array_column($avvisi, 'frase'), ...($controllo['sala_cambiata'] !== null ? [self::fraseDellaSalaCambiata($controllo['sala_cambiata'])] : [])];
        if ($frasi !== [] && ! $ancheSeLeCifreNonTornano) {
            throw ValidationException::withMessages(['cifre' => $frasi]);
        }
        $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();
        $improntaSala = app(ComposizioneDelVerbale::class)->improntaDiAdesso($verbale);

        return RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, fn ($lotto, $e) => 'bozza stampata per la firma, versione ' . $e['versione']
            . ', impronta ' . substr($e['impronta'], 0, 8) . '…' . ($avvisi !== [] ? ' · stampata con ' . (count($avvisi) === 1 ? '1 cifra' : count($avvisi) . ' cifre')
                . ' che non tornano' : '')),
            function () use ($verbale, $parte, $utente, $improntaSala) {
                $versione = $this->versioni->nuova($verbale, 'intero', (array) $parte->documento, 'bozza stampata per la firma', 'programma', null, now(), $utente);
                $pdf = $this->stampe->bozza($verbale, (int) $versione->numero, (string) $versione->impronta);
                $impronta = hash('sha256', $pdf);
                $verbale->update(['stampata' => ['versione' => (int) $versione->numero, 'impronta_testo' => $versione->impronta, 'impronta_sala' => $improntaSala,
                    'impronta' => $impronta, 'il' => now()->utc()->toIso8601ZuluString(), 'da' => $utente->id, 'da_nome' => $utente->name]]);

                return ['pdf' => $pdf, 'versione' => (int) $versione->numero, 'impronta' => $impronta];
            });
    }

    /**
     * «Registra il verbale sottoscritto».
     *
     * @param ?array{scelta?: string, ultimo?: mixed, del?: ?string} $partenza la prima volta, da dove parte il registro
     * @param bool $senzaLaBozza la conferma di chi registra senza aver stampato la bozza da firmare
     * @param bool $conIlSalto la conferma di un numero più alto del proposto: i numeri in mezzo non tornano
     */
    public function registra(VerbaleDellAssemblea $verbale, UploadedFile $copia, ?int $numero, string $sottoscrittoIl, ?array $partenza, User $utente,
        bool $senzaLaBozza = false, bool $conIlSalto = false): VerbaleDellAssemblea
    {
        if (! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per registrare il verbale serve il permesso «Registra il verbale sottoscritto».');
        }
        if ($verbale->stato !== StatoVerbale::BOZZA) {
            throw ValidationException::withMessages(['verbale' => $verbale->stato === StatoVerbale::IN_SALA ? 'Prima genera il verbale dal modello e stampalo per la firma.'
                : 'Questo verbale è ' . mb_strtolower((string) $verbale->stato?->parola()) . '.']);
        }
        self::controllaLaCopia($copia, 'copia');
        if (! PdfDellaCopia::siApreComePdf((string) file_get_contents((string) $copia->getRealPath()), $copia->getClientOriginalName(), (string) $copia->getMimeType())) {
            throw ValidationException::withMessages(['copia' => 'La copia firmata non contiene un PDF che si apre: carica la scansione, il PDF firmato o il suo .p7m.']);
        }
        self::controllaCheSiUnisce($copia);
        $this->controllaIlTestoFirmato($verbale, $senzaLaBozza);
        $seduta = $verbale->seduta()->firstOrFail();
        $giorno = $seduta->fissata_il->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sottoscrittoIl) !== 1 || $sottoscrittoIl < $giorno || $sottoscrittoIl > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['sottoscritto_il' => 'Il verbale si sottoscrive fra il giorno della riunione, il ' . $seduta->fissata_il->format('d/m/Y')
                . ', e oggi.']);
        }
        $assemblea = $verbale->assemblea()->firstOrFail();
        $condominio = $assemblea->condominio()->firstOrFail();
        $registro = (string) $verbale->registro;
        $percorso = null;

        try {
            return DB::transaction(function () use ($verbale, $copia, $numero, $sottoscrittoIl, $partenza, $utente, $assemblea, $condominio, $registro, $conIlSalto, &$percorso) {
                // La riga del registro bloccata: due registrazioni insieme prendono due numeri, uno dopo l'altro.
                $riga = RegistroDelPerimetro::query()->where('condominio_id', $condominio->id)->where('registro', $registro)->lockForUpdate()->first();
                if ($riga === null || (! $this->registro->haUnVerbale($condominio, $registro) && $partenza !== null && ($partenza['scelta'] ?? null) !== null)) {
                    if ($partenza === null || ($partenza['scelta'] ?? null) === null) {
                        throw ValidationException::withMessages(['partenza' => 'Prima dimmi da dove parte il registro dei verbali: continua il libro di carta, o comincia dal n. 1.']);
                    }
                    $this->registro->impostaPartenza($condominio, $registro, (string) $partenza['scelta'], $partenza['ultimo'] ?? null, $partenza['del'] ?? null, $utente);
                }
                $proposto = (int) $this->registro->prossimoNumero($condominio, $registro);
                $numero ??= $proposto;
                $errore = $this->registro->numeroUsato($condominio, $registro, $numero);
                if ($errore !== null) {
                    throw ValidationException::withMessages(['numero' => $errore]);
                }
                // Un numero più alto del proposto salta dei numeri, che non tornano: si conferma.
                if ($numero > $proposto && ! $conIlSalto) {
                    throw ValidationException::withMessages(['numero' => RegistroDeiVerbali::fraseDelSalto($proposto, $numero)]);
                }
                $sostituito = VerbaleDellAssemblea::query()->where('seduta_id', $verbale->seduta_id)->where('stato', StatoVerbale::ANNULLATO->value)
                    ->where('id', '<', $verbale->id)->orderByDesc('id')->first();
                $percorso = app(ArchivioDocumenti::class)->salva($copia);
                $nome = 'Verbale n. ' . $numero . ' del ' . $verbale->seduta->fissata_il->format('d/m/Y');
                $frase = 'registrato n. ' . $numero . ' nel ' . mb_lcfirst(RegistroDeiVerbali::perimetro($condominio, $registro)['nome']) . ', sottoscritto il '
                    . \Carbon\Carbon::parse($sottoscrittoIl)->format('d/m/Y') . ($numero !== $proposto ? ' (proposto il n. ' . $proposto . ')' : '')
                    . ($sostituito !== null ? ', sostituisce il n. ' . $sostituito->numero : '');
                $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->firstOrFail();

                RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, $frase)->lotto(['copia' => ['nome' => $copia->getClientOriginalName(),
                    'impronta' => hash_file('sha256', (string) $copia->getRealPath())]]), function () use ($verbale, $copia, $numero, $sottoscrittoIl, $utente,
                    $assemblea, $percorso, $nome, $sostituito, $parte) {
                    $documento = $assemblea->documenti()->create(['name' => $nome, 'description' => 'La copia sottoscritta del verbale', 'path' => $percorso,
                        'mime_type' => (string) $copia->getMimeType(), 'file_size' => (int) $copia->getSize(), 'created_by' => $utente->id,
                        'is_published' => false, 'is_approved' => true]);
                    $this->versioni->nuova($verbale, 'intero', (array) $parte->documento, 'registrato n. ' . $numero, 'programma', null, now(), $utente);
                    $verbale->update(['stato' => StatoVerbale::REGISTRATO->value, 'numero' => $numero, 'sottoscritto_il' => $sottoscrittoIl,
                        'documento_id' => $documento->id, 'registrato_il' => now(), 'registrato_da' => $utente->id, 'sostituisce_id' => $sostituito?->id]);
                    self::scriviSullAssemblea($assemblea, $documento->id);
                    app(RegistroDiNominaERevoca::class)->proponiDalVerbale($verbale->refresh(), $utente);
                });

                return $verbale->refresh();
            });
        } catch (QueryException $e) {
            if ($percorso !== null) {
                app(ArchivioDocumenti::class)->elimina($percorso);
            }
            // Lo stesso numero preso intanto da un'altra registrazione: l'indice unico lo ferma. La prima registrazione di un registro nuovo, due volte
            // insieme: la riga del registro nasce due volte, e l'indice del registro ferma la seconda.
            if (str_contains($e->getMessage(), 'assemblee_verbali_numero_unico') || str_contains($e->getMessage(), 'assemblee_verbali.numero')
                || str_contains($e->getMessage(), 'assemblee_registri_verbali_unico') || str_contains($e->getMessage(), 'assemblee_registri_verbali.condominio_id')) {
                throw ValidationException::withMessages(['numero' => 'Il numero è stato preso intanto da un\'altra registrazione: riprova, il programma propone il prossimo.']);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($percorso !== null) {
                app(ArchivioDocumenti::class)->elimina($percorso);
            }
            throw $e;
        } finally {
            app(CompitiDelVerbale::class)->sincronizza($verbale->assemblea()->firstOrFail());
        }
    }

    /** «Sostituisci la copia»: il file nuovo con il perché; il numero e il giorno della firma restano, la versione di prima resta fra le versioni della copia. */
    public function sostituisciLaCopia(VerbaleDellAssemblea $verbale, UploadedFile $copia, string $perche, User $utente): VerbaleDellAssemblea
    {
        if (! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per sostituire la copia serve il permesso «Registra il verbale sottoscritto».');
        }
        if ($verbale->stato !== StatoVerbale::REGISTRATO || $verbale->documento_id === null) {
            throw ValidationException::withMessages(['copia' => 'Si sostituisce la copia di un verbale registrato.']);
        }
        if (trim($perche) === '') {
            throw ValidationException::withMessages(['perche' => 'Scrivi perché sostituisci la copia: resta nel registro delle modifiche.']);
        }
        self::controllaLaCopia($copia, 'copia');
        self::controllaCheSiUnisce($copia);
        DB::transaction(function () use ($verbale, $copia, $perche, $utente) {
            $documento = Documento::query()->findOrFail($verbale->documento_id);
            RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'copia sottoscritta sostituita: ' . $copia->getClientOriginalName()
                . '; la copia di prima resta fra le versioni', null, trim($perche)), function () use ($documento, $copia, $utente) {
                    app(VersioniDeiDocumenti::class)->sostituisci($documento, $copia);
                    $documento->update(['updated_by' => $utente->id]);
                });
        });

        return $verbale->refresh();
    }

    /**
     * «Annulla la registrazione» con il perché: la riga resta nel registro, «Annullato», con il perché, il giorno e chi; il numero non torna. Il testo torna in
     * bozza in un verbale nuovo della stessa riunione, che registrato prenderà il numero dopo e dirà che sostituisce questo. Le righe di nomina proposte da
     * questo verbale e non confermate si annullano.
     */
    public function annulla(VerbaleDellAssemblea $verbale, string $motivo, User $utente): VerbaleDellAssemblea
    {
        if (! AccessoAlleAssemblee::registraIlVerbale($utente)) {
            throw new AuthorizationException('Per annullare la registrazione serve il permesso «Registra il verbale sottoscritto».');
        }
        if ($verbale->stato !== StatoVerbale::REGISTRATO) {
            throw ValidationException::withMessages(['motivo' => 'Si annulla la registrazione di un verbale registrato.']);
        }
        if (mb_strlen(trim($motivo)) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché annulli la registrazione: si legge nel registro e nella sua stampa.']);
        }
        $nuovo = DB::transaction(function () use ($verbale, $motivo, $utente) {
            $assemblea = $verbale->assemblea()->firstOrFail();
            $nuovo = RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, 'registrazione n. ' . $verbale->numero . ' annullata; il numero non torna, e il testo '
                . 'torna in bozza', null, trim($motivo)), function () use ($verbale, $motivo, $utente) {
                    $verbale->update(['stato' => StatoVerbale::ANNULLATO->value, 'annullato_il' => now(), 'annullato_motivo' => trim($motivo), 'annullato_da' => $utente->id]);
                    $nuovo = VerbaleDellAssemblea::create(['assemblea_id' => $verbale->assemblea_id, 'condominio_id' => $verbale->condominio_id, 'registro' => $verbale->registro,
                        'seduta_id' => $verbale->seduta_id, 'stato' => StatoVerbale::BOZZA->value, 'modello_id' => $verbale->modello_id, 'modello_nome' => $verbale->modello_nome,
                        'impronta_sala' => $verbale->impronta_sala, 'generato_il' => $verbale->generato_il, 'trasmissione' => $verbale->trasmissione,
                        'created_by' => $utente->id]);
                    foreach (ParteDelVerbale::query()->where('verbale_id', $verbale->id)->get() as $p) {
                        $this->versioni->scrivi($nuovo, $p->parte, $p->punto_id, (array) $p->documento, 'programma', now(), $utente);
                    }
                    $intero = ParteDelVerbale::query()->where('verbale_id', $nuovo->id)->where('parte', 'intero')->first();
                    if ($intero !== null) {
                        $this->versioni->nuova($nuovo, 'intero', (array) $intero->documento, 'dal n. ' . $verbale->numero . ' annullato', 'programma', null, now(), $utente);
                    }

                    self::scriviSullAssemblea($verbale->assemblea()->firstOrFail(), null);

                    return $nuovo;
                });
            app(RegistroDiNominaERevoca::class)->dopoLAnnullamento($verbale, $utente);

            return $nuovo;
        });
        app(CompitiDelVerbale::class)->sincronizza($verbale->assemblea()->firstOrFail());

        return $nuovo;
    }

    /**
     * Se il testo da registrare non è quello firmato, il perché; null se lo è. Il testo firmato è quello della bozza stampata: se il testo intero è cambiato
     * dopo (una frase aggiunta, il testo arrivato tardi dal tablet), o i dati della sala sono cambiati dopo (un voto corretto in sala), la copia firmata dice
     * un'altra cosa. Senza la bozza stampata non si sa: lo dice la registrazione, che chiede prima.
     */
    public function cambiatoDopoLaStampa(VerbaleDellAssemblea $verbale): ?string
    {
        $stampata = (array) ($verbale->stampata ?? []);
        if ($stampata === [] || $verbale->stato !== StatoVerbale::BOZZA) {
            return null;
        }
        $dove = 'dopo la bozza stampata alle ' . ParoleDelVerbale::ora((string) ($stampata['il'] ?? now())) . ' (versione ' . ($stampata['versione'] ?? '?') . ')';
        $documento = (array) (ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->value('documento') ?? []);
        if (StrutturaDelVerbale::impronta($documento) !== ($stampata['impronta_testo'] ?? null)) {
            return 'Il testo è cambiato ' . $dove . ': ristampala e falla firmare, o ripristina la versione ' . ($stampata['versione'] ?? '') . '.';
        }
        $sala = isset($stampata['impronta_sala']) ? app(ComposizioneDelVerbale::class)->improntaDiAdesso($verbale) !== $stampata['impronta_sala']
            : $this->cifre->controlla($verbale)['sala_cambiata'] !== null;
        if ($sala) {
            return 'I dati della sala sono cambiati ' . $dove . ': aggiorna le parti segnate, ristampa la bozza e falla firmare.';
        }

        return null;
    }

    /**
     * Prima di registrare: si registra il testo firmato. Una scelta aperta fra due versioni (o una discussione arrivata dopo la generazione) ferma; il testo o la
     * sala cambiati dopo la bozza stampata fermano; senza la bozza stampata serve la conferma, e le cifre che non tornano (o la sala cambiata) fermano.
     */
    private function controllaIlTestoFirmato(VerbaleDellAssemblea $verbale, bool $senzaLaBozza): void
    {
        if (($aperta = self::sceltaAperta($verbale)) !== null) {
            throw ValidationException::withMessages(['bozza' => $aperta . ', poi registra.']);
        }
        if ($verbale->stampata !== null) {
            if (($perche = $this->cambiatoDopoLaStampa($verbale)) !== null) {
                throw ValidationException::withMessages(['bozza' => $perche]);
            }

            return;
        }
        if (! $senzaLaBozza) {
            throw ValidationException::withMessages(['senza_la_bozza' => self::SENZA_LA_BOZZA]);
        }
        $controllo = $this->cifre->controlla($verbale);
        $frasi = [...array_column($controllo['avvisi'], 'frase'), ...($controllo['sala_cambiata'] !== null ? [self::fraseDellaSalaCambiata($controllo['sala_cambiata'])] : [])];
        if ($frasi !== []) {
            throw ValidationException::withMessages(['bozza' => $frasi[0] . ' Prima sistema il testo: si registra ciò che è firmato.']);
        }
    }

    /** La frase di chi registra senza aver stampato la bozza. */
    public const SENZA_LA_BOZZA = 'La bozza da firmare non è stata stampata: la copia firmata viene da questo testo? Conferma che registri il testo di adesso, o '
        . 'stampa prima la bozza e falla firmare.';

    /** La scelta aperta che ferma la stampa e la registrazione, o null. */
    private static function sceltaAperta(VerbaleDellAssemblea $verbale): ?string
    {
        if (VersioniDelVerbale::scelteAperte($verbale, VersioneDelVerbale::DUE_VERSIONI) > 0) {
            return 'C\'è una scelta aperta fra due versioni: scegli prima quale tenere';
        }
        if (VersioniDelVerbale::scelteAperte($verbale, VersioneDelVerbale::DOPO_LA_GENERAZIONE) > 0) {
            return 'C\'è una discussione arrivata dopo la generazione: mettila nel verbale, o lasciala fra le versioni';
        }

        return null;
    }

    /** @param array{alle: ?string} $cambiata */
    private static function fraseDellaSalaCambiata(array $cambiata): string
    {
        return 'I dati della sala sono cambiati' . (($cambiata['alle'] ?? null) !== null ? ' alle ' . $cambiata['alle'] : '') . ' dopo la generazione: «Aggiorna le parti '
            . 'segnate» rifà le parti con i voti.';
    }

    /**
     * Il numero e la copia sull'assemblea, dove li leggono già la frase della delibera, la ratifica e il piano: i numeri registrati («42», «42 e 43» per la
     * riunione sospesa e la prosecuzione), la copia dell'ultimo registrato.
     */
    public static function scriviSullAssemblea(Assemblea $assemblea, ?int $documento): void
    {
        $ultimo = VerbaleDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoVerbale::REGISTRATO->value)->orderByDesc('registrato_il')
            ->orderByDesc('id')->first();
        $numeri = VerbaliDellAssemblea::numeriRegistrati($assemblea);
        $assemblea->update(['numero_verbale' => $numeri !== '' ? mb_substr($numeri, 0, 50) : null, 'verbale_documento_id' => $documento ?? $ultimo?->documento_id]);
    }

    /** La copia si unisce alle lettere: se è un PDF che la libreria non legge, lo si dice subito. */
    private static function controllaCheSiUnisce(UploadedFile $copia): void
    {
        if (! PdfDellaCopia::siUnisce((string) file_get_contents((string) $copia->getRealPath()), $copia->getClientOriginalName(), (string) $copia->getMimeType())) {
            throw ValidationException::withMessages(['copia' => PdfDellaCopia::NON_SI_UNISCE]);
        }
    }

    /** La copia sottoscritta: PDF, `.p7m` o immagine, al più 10 MB, come il verbale della riunione registrata a mano. */
    public static function controllaLaCopia(UploadedFile $file, string $campo): void
    {
        $estensione = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($estensione, DeliberaAMano::ESTENSIONI_DEL_VERBALE, true) || $file->getSize() > DeliberaAMano::KB_MASSIMI_DEL_VERBALE * 1024) {
            throw ValidationException::withMessages([$campo => 'La copia sottoscritta è una scansione o un PDF firmato (pdf, p7m, jpg, png), al più 10 MB.']);
        }
    }
}
