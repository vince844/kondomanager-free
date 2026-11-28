<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\EventoTipo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Evento;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\RispostePromemoria;
use App\Support\LimiteCaricamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Ciò che resta da fare dopo una delibera (decisioni 522 (1) e 523): una cosa per riga, con la data e la persona. **Con la data è un compito**
 * dell'Action inbox (una riga sola, la cosa come `eventable`), e le risposte al compito passano dalla cosa, come quelle del memo passano dal memo; senza, resta
 * nel passo 6 e nella dashboard.
 *
 * - **R5, la cosa si chiude da sola** quando la scheda nasce con la delibera: l'aggancio è nel solo scrittore dei collegamenti
 *   ({@see \App\Services\Assemblee\CollegamentiDelleDelibere::collega()}), che chiama {@see dopoIlCollegamento()}: il piano con la delibera fa «Crea il piano
 *   straordinario» e «Rimetti il piano come il preventivo approvato», la dispensa «Registra la dispensa», la regola «Scrivi la regola», la versione corretta
 *   approvata «Correggi e ferma una versione nuova». Il compito si chiude con «Chiuso: piano «…» creato il 12/01/2027». **Nei due sensi**: tolta la delibera
 *   dalla scheda (o cambiata), la cosa fatta da quella scheda torna da fare, con il compito di nuovo ({@see dopoLoScollegamento()}).
 * - **Una cosa sola**: la stessa cosa scritta di nuovo sulla stessa delibera (una «Conferma» rifatta, un doppio clic) si rifiuta con la frase.
 * - **«Segna eseguita»** (scena 4): il giorno, la nota, il documento facoltativo (PDF e immagini), fra i documenti dell'assemblea, fuori dal portale.
 * - **«Non serve più»** con il motivo; **«Ricordamelo»** sposta il giorno (e il compito).
 * - «Fatto» e «Non serve» restano a chi apre il compito (decisione 395 (1)(a)); il compito che il programma crea e chiude da solo non è una riga (decisione 547).
 *   «Non serve» dal compito «Delibere respinte» fa ciò che fa «Chiudi con il motivo» ({@see NON_SERVE_DALLA_RESPINTA_CHIUDE}).
 * - **Leggere non scrive**: le letture delle pagine (il passo 6, la card, il riepilogo) non cambiano nessuna cosa; «Avvisa i condòmini» si chiude dove la
 *   comunicazione diventa pubblicata ({@see AvvisoAiCondomini::dopoLaPubblicazione()}).
 */
final class CoseDaFare
{
    /**
     * «Non serve» dal menu del compito «Delibere respinte» chiude come «Chiudi con il motivo» ({@see DopoIlRifiuto::chiudi()}): anche i compiti dello sforo,
     * con la riga nella «Storia». È la risposta proposta, in attesa di Vincenzo: con `false` chiude solo la cosa, com'era.
     */
    public const NON_SERVE_DALLA_RESPINTA_CHIUDE = true;

    /** Il compito si apre alle 9:00 del giorno scelto, come il memo. */
    public const ORA_DEL_COMPITO = '09:00';

    /** I formati del documento di «Segna eseguita», come le prove: PDF e immagini. */
    public const FORMATI = ['pdf', 'jpg', 'jpeg', 'png'];

    /** `$oggetto`: la scheda a cui la cosa si riferisce già (il piano da rimettere come il preventivo approvato). */
    public static function crea(Delibera $delibera, TipoCosaDaFare $tipo, string $testo, ?string $entroIl, ?string $aChi, ?int $assegnatoA, User $utente,
        ?Model $oggetto = null): CosaDaFareDellaDelibera
    {
        $testo = trim(preg_replace('/\s+/u', ' ', $testo) ?? '');
        if ($testo === '') {
            throw ValidationException::withMessages(['testo' => 'Scrivi che cosa resta da fare.']);
        }
        if (mb_strlen($testo) > 255) {
            $testo = rtrim(mb_substr($testo, 0, 254)) . '…';
        }
        $gia = CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->where('tipo', $tipo->value)
            ->when($tipo === TipoCosaDaFare::FUORI_DAL_PROGRAMMA, fn ($q) => $q->where('testo', $testo)->where('stato', StatoCosaDaFare::DA_FARE->value))->first();
        if ($gia !== null) {
            throw ValidationException::withMessages(['testo' => 'C\'è già: «' . $gia->testo . '», ' . mb_strtolower((string) $gia->stato?->parola()) . '.']);
        }
        if ($entroIl !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entroIl) !== 1) {
            throw ValidationException::withMessages(['entro_il' => 'Il giorno non è valido.']);
        }
        // La respinta chiede una risposta adesso: il compito c'è sempre (R7).
        if ($tipo === TipoCosaDaFare::DOPO_IL_RIFIUTO && $entroIl === null) {
            $entroIl = DateHelper::oggiUtente();
        }
        $chi = AChiMemo::tryFrom((string) ($aChi ?? '')) ?? AChiMemo::ME;
        if ($chi === AChiMemo::COLLABORATORE) {
            $collaboratore = $assegnatoA === null ? null : User::query()->find($assegnatoA);
            if ($collaboratore === null || ! AccessoAlleAssemblee::vede($collaboratore)) {
                throw ValidationException::withMessages(['assegnato_a' => 'Scegli un collaboratore dello studio che vede le assemblee.']);
            }
        } else {
            $assegnatoA = null;
        }

        return DB::transaction(function () use ($delibera, $tipo, $testo, $entroIl, $chi, $assegnatoA, $utente, $oggetto) {
            $cosa = CosaDaFareDellaDelibera::create(['delibera_id' => $delibera->id, 'condominio_id' => $delibera->condominio_id, 'tipo' => $tipo->value,
                'testo' => $testo, 'entro_il' => $entroIl, 'a_chi' => $chi->value, 'assegnato_a' => $assegnatoA, 'stato' => StatoCosaDaFare::DA_FARE->value,
                'oggetto_tipo' => $oggetto === null ? null : SchedaDelCondominio::tipoDi($oggetto), 'oggetto_id' => $oggetto === null ? null : (int) $oggetto->getKey(),
                'created_by' => $utente->id]);
            if ($entroIl !== null) {
                self::creaIlCompito($cosa);
            }

            return $cosa->refresh();
        });
    }

    /**
     * R5: la scheda è nata (o si è legata) con la delibera, con il ruolo che esegue una cosa da fare. La cosa è fatta oggi, con la scheda; il compito si chiude
     * con la frase; il compito del deficit del punto, se c'è, anche. Lo chiama solo `CollegamentiDelleDelibere::collega()`.
     */
    public static function dopoIlCollegamento(Model $scheda, string $ruolo, Delibera $delibera, ?User $utente = null): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')) {
            return;
        }
        $tipoScheda = SchedaDelCondominio::tipoDi($scheda);
        $tipi = self::tipiEseguitiDa($tipoScheda, $ruolo);
        if ($tipi === []) {
            return;
        }
        $cose = CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->whereIn('tipo', array_map(fn ($t) => $t->value, $tipi))
            ->where('stato', StatoCosaDaFare::DA_FARE->value)->orderBy('id')->get();
        foreach ($cose as $cosa) {
            $frase = 'Chiuso: ' . lcfirst(SchedaDelCondominio::nomeDi($scheda)) . ' ' . self::nata($tipoScheda) . ' il ' . Carbon::parse(DateHelper::oggiUtente())->format('d/m/Y') . '.';
            $cosa->update(['stato' => StatoCosaDaFare::FATTA->value, 'fatta_il' => DateHelper::oggiUtente(), 'fatta_da' => $utente?->id,
                'oggetto_tipo' => $tipoScheda, 'oggetto_id' => (int) $scheda->getKey()]);
            ChiusuraDeiCompiti::chiudi(self::compito($cosa), $frase);
            if ($cosa->tipo === TipoCosaDaFare::PREPARA_RIPIANAMENTO) {
                $c = ContestoDellaDelibera::per($delibera);
                $deficit = $c->scheda('compito');
                if ($deficit instanceof Evento) {
                    ChiusuraDeiCompiti::chiudi($deficit, $frase);
                }
            }
        }
    }

    /**
     * R5 nell'altro senso: la delibera tolta da una scheda (o cambiata con un'altra) che aveva fatto una cosa da fare. La cosa torna da fare, con il compito
     * di nuovo se aveva una data: la scheda che la faceva non è più della delibera. Lo chiama solo `CollegamentiDelleDelibere` (scollega e «Cambia»).
     */
    public static function dopoLoScollegamento(Model $scheda, string $ruolo, Delibera $delibera): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')) {
            return;
        }
        $tipoScheda = SchedaDelCondominio::tipoDi($scheda);
        $tipi = self::tipiEseguitiDa($tipoScheda, $ruolo);
        if ($tipi === []) {
            return;
        }
        $cose = CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->whereIn('tipo', array_map(fn ($t) => $t->value, $tipi))
            ->where('stato', StatoCosaDaFare::FATTA->value)->where('oggetto_tipo', $tipoScheda)->where('oggetto_id', (int) $scheda->getKey())->orderBy('id')->get();
        foreach ($cose as $cosa) {
            DB::transaction(function () use ($cosa) {
                $tiene = $cosa->tipo === TipoCosaDaFare::RIMETTI_PIANO;
                $cosa->update(['stato' => StatoCosaDaFare::DA_FARE->value, 'fatta_il' => null, 'fatta_da' => null,
                    'oggetto_tipo' => $tiene ? $cosa->oggetto_tipo : null, 'oggetto_id' => $tiene ? $cosa->oggetto_id : null]);
                if ($cosa->entro_il !== null) {
                    self::creaIlCompito($cosa->refresh());
                }
            });
        }
    }

    /** I tipi di cosa che una scheda con quel ruolo esegue (R5). @return list<TipoCosaDaFare> */
    private static function tipiEseguitiDa(string $tipoScheda, string $ruolo): array
    {
        return array_values(array_filter(TipoCosaDaFare::cases(), fn (TipoCosaDaFare $t) => in_array([$tipoScheda, $ruolo], $t->schedeCheLaEseguono(), true)));
    }

    /** «Segna eseguita»: il giorno, la nota, il documento facoltativo (fra i documenti dell'assemblea, fuori dal portale). */
    public static function segnaEseguita(CosaDaFareDellaDelibera $cosa, string $giorno, ?string $nota, ?UploadedFile $documento, User $utente): CosaDaFareDellaDelibera
    {
        if (! $cosa->eDaFare()) {
            throw ValidationException::withMessages(['cosa' => 'Questa cosa non è più da fare.']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || $giorno > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno in cui l\'hai fatta non può essere dopo oggi.']);
        }
        $nota = $nota === null || trim($nota) === '' ? null : trim($nota);
        $percorso = null;
        $archivio = app(ArchivioDocumenti::class);
        try {
            return DB::transaction(function () use ($cosa, $giorno, $nota, $documento, $utente, $archivio, &$percorso) {
                $documentoId = null;
                if ($documento !== null) {
                    $assemblea = $cosa->delibera->assemblea;
                    $percorso = $archivio->salvaConLEstensioneDelNome($documento, ArchivioDocumenti::CARTELLA . '/' . $cosa->condominio_id . '/delibere');
                    $documentoId = $assemblea->documenti()->create(['name' => $documento->getClientOriginalName(),
                        'description' => 'Eseguita: ' . $cosa->testo, 'path' => $percorso, 'mime_type' => $documento->getMimeType(),
                        'file_size' => $documento->getSize(), 'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true])->id;
                }
                $cosa->update(['stato' => StatoCosaDaFare::FATTA->value, 'fatta_il' => $giorno, 'fatta_da' => $utente->id, 'nota' => $nota,
                    'documento_id' => $documentoId]);
                ChiusuraDeiCompiti::chiudi(self::compito($cosa), 'Chiuso: eseguita il ' . Carbon::parse($giorno)->format('d/m/Y') . '.');

                return $cosa->refresh();
            });
        } catch (\Throwable $e) {
            $archivio->elimina($percorso);

            throw $e;
        }
    }

    /** «Non serve più», con il motivo. */
    public static function nonServe(CosaDaFareDellaDelibera $cosa, string $motivo, User $utente): CosaDaFareDellaDelibera
    {
        if (! $cosa->eDaFare()) {
            throw ValidationException::withMessages(['cosa' => 'Questa cosa non è più da fare.']);
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché non serve più.']);
        }

        return DB::transaction(function () use ($cosa, $motivo, $utente) {
            $cosa->update(['stato' => StatoCosaDaFare::NON_SERVE->value, 'fatta_il' => DateHelper::oggiUtente(), 'fatta_da' => $utente->id,
                'chiusa_motivo' => $motivo]);
            ChiusuraDeiCompiti::chiudi(self::compito($cosa), 'Chiuso: non serve più.');

            return $cosa->refresh();
        });
    }

    /** «Ricordamelo»: sposta il giorno; il compito si sposta (o nasce, se la cosa non aveva una data). */
    public static function ricordamelo(CosaDaFareDellaDelibera $cosa, string $giorno, User $utente): CosaDaFareDellaDelibera
    {
        if (! $cosa->eDaFare()) {
            throw ValidationException::withMessages(['cosa' => 'Questa cosa non è più da fare.']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || $giorno < DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['giorno' => 'Scegli un giorno da oggi in poi.']);
        }

        return DB::transaction(function () use ($cosa, $giorno) {
            $cosa->update(['entro_il' => $giorno]);
            $compito = self::compito($cosa);
            if ($compito === null || $compito->is_completed) {
                self::creaIlCompito($cosa->refresh());
            } else {
                InboxService::spostaIlCompito($compito, self::inizioDelGiorno($giorno));
            }

            return $cosa->refresh();
        });
    }

    /**
     * Dopo la risposta di una persona al compito di una cosa, nella stessa transazione: «Fatto» fa la cosa con la nota; «Non serve più» la chiude con il
     * motivo; «Ricordamelo» sposta il suo giorno. Per gli altri compiti non fa niente.
     *
     * @param array<string, mixed> $dati
     */
    public static function dopoLaRispostaDelCompito(Evento $compito, string $stato, array $dati, ?User $utente): void
    {
        if (! in_array((($compito->meta ?? [])['type'] ?? null), [EventoTipo::DELIBERA_DA_ESEGUIRE->value, EventoTipo::DELIBERA_RESPINTA->value], true)) {
            return;
        }
        $cosa = self::delCompito($compito);
        if ($cosa === null || ! $cosa->eDaFare()) {
            return;
        }
        if ($stato === RispostePromemoria::NON_SERVE && $cosa->tipo === TipoCosaDaFare::DOPO_IL_RIFIUTO && self::NON_SERVE_DALLA_RESPINTA_CHIUDE
            && $utente !== null && trim((string) ($dati['motivo'] ?? '')) !== '') {
            DopoIlRifiuto::chiudi($cosa, (string) $dati['motivo'], $utente);

            return;
        }
        match ($stato) {
            RispostePromemoria::NON_SERVE => $cosa->update(['stato' => StatoCosaDaFare::NON_SERVE->value, 'fatta_il' => DateHelper::oggiUtente(),
                'fatta_da' => $utente?->id, 'chiusa_motivo' => $dati['motivo'] ?? null]),
            RispostePromemoria::RICORDAMELO => $cosa->update(['entro_il' => (string) $dati['giorno']]),
            default => $cosa->update(['stato' => StatoCosaDaFare::FATTA->value, 'fatta_il' => DateHelper::oggiUtente(), 'fatta_da' => $utente?->id,
                'nota' => $dati['nota'] ?? null]),
        };
    }

    /** La cosa di un compito: l'`eventable`, o `meta.cosa_id`. */
    public static function delCompito(Evento $compito): ?CosaDaFareDellaDelibera
    {
        $id = $compito->eventable_type === (new CosaDaFareDellaDelibera())->getMorphClass() ? $compito->eventable_id : (($compito->meta ?? [])['cosa_id'] ?? null);

        return $id === null ? null : CosaDaFareDellaDelibera::query()->find((int) $id);
    }

    public static function compito(CosaDaFareDellaDelibera $cosa): ?Evento
    {
        return $cosa->evento_id === null ? null : Evento::query()->find($cosa->evento_id);
    }

    /**
     * La prossima azione di una cosa, per il compito, il passo 6 e la card: «Crea il piano straordinario», «Registra la dispensa», «Scrivi la regola»,
     * «Avvisa i condòmini», «Apri la delibera». Senza una pagina, «Segna eseguita».
     *
     * @return array{testo: string, url: ?string}
     */
    public static function azione(CosaDaFareDellaDelibera $cosa): array
    {
        $cosa->loadMissing('delibera');
        $d = $cosa->delibera;
        $condominio = (int) $cosa->condominio_id;
        $assemblea = fn (array $piu = []) => Route::has('admin.gestionale.assemblee.show')
            ? route('admin.gestionale.assemblee.show', ['condominio' => $condominio, 'assemblea' => $d->assemblea_id, 'scheda' => 'delibere'] + $piu, false) : null;
        $punto = $d->punto_id === null ? null : \App\Models\Assemblee\PuntoDellOrdineDelGiorno::query()->find($d->punto_id);

        return match ($cosa->tipo) {
            TipoCosaDaFare::CREA_PIANO_STRAORDINARIO => ['testo' => 'Crea il piano straordinario', 'url' => $assemblea(['cosa' => $cosa->id])],
            TipoCosaDaFare::PREPARA_RIPIANAMENTO => ['testo' => 'Prepara il piano di ripianamento', 'url' => self::pianoDelDeficit($cosa) ?? $assemblea(['cosa' => $cosa->id])],
            TipoCosaDaFare::REGISTRA_DISPENSA => ['testo' => 'Registra la dispensa', 'url' => route('admin.gestionale.dispense.index', ['condominio' => $condominio,
                'delibera_id' => $d->id, 'esercizio_id' => $punto?->esercizio_id, 'nuova' => 1], false)],
            TipoCosaDaFare::SCRIVI_REGOLA => ['testo' => 'Scrivi la regola', 'url' => route('admin.gestionale.recupero-crediti.index', ['condominio' => $condominio,
                'delibera_id' => $d->id, 'nuova' => 1], false)],
            TipoCosaDaFare::AVVISA_CONDOMINI => ['testo' => 'Avvisa i condòmini', 'url' => route('admin.comunicazioni.create', ['cosa_da_fare' => $cosa->id], false)],
            TipoCosaDaFare::RIMETTI_PIANO => ['testo' => 'Apri il piano', 'url' => self::paginaDelPiano($cosa) ?? $assemblea(['delibera' => $d->id])],
            TipoCosaDaFare::DOPO_IL_RIFIUTO => ['testo' => 'Apri la delibera', 'url' => $assemblea(['delibera' => $d->id])],
            default => ['testo' => 'Segna eseguita', 'url' => $assemblea(['delibera' => $d->id])],
        };
    }

    /**
     * Le cose di una delibera, nella forma delle pagine (il passo 6, la card, il riepilogo dopo la conferma).
     *
     * @return list<array<string, mixed>>
     */
    public static function perLaDelibera(Delibera $delibera): array
    {
        return CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->with(['documento', 'fattaDa', 'assegnatoA'])->orderBy('id')->get()
            ->map(fn (CosaDaFareDellaDelibera $c) => self::riga($c))->values()->all();
    }

    /** Una cosa nella forma delle pagine. @return array<string, mixed> */
    public static function riga(CosaDaFareDellaDelibera $c): array
    {
        $oggetto = $c->oggetto_tipo === null ? null : SchedaDelCondominio::cerca(\App\Models\Condominio::query()->findOrFail($c->condominio_id), $c->oggetto_tipo,
            (int) $c->oggetto_id);
        $chi = match ($c->a_chi) {
            AChiMemo::COLLABORATORE => $c->assegnatoA?->name,
            AChiMemo::STUDIO => 'tutto lo studio',
            default => User::query()->whereKey($c->created_by)->value('name'),
        };

        return [
            'id' => (int) $c->id,
            'delibera_id' => (int) $c->delibera_id,
            'tipo' => $c->tipo?->value,
            'tipo_parola' => $c->tipo?->parola(),
            'testo' => $c->testo,
            'entro_il' => $c->entro_il?->format('Y-m-d'),
            'a_chi' => $c->a_chi?->value,
            'assegnato_a' => $c->assegnato_a,
            'chi' => $chi,
            'stato' => $c->stato?->value,
            'fatta_il' => $c->fatta_il?->format('Y-m-d'),
            'fatta_da' => $c->fattaDa?->name,
            'nota' => $c->nota,
            'chiusa_motivo' => $c->chiusa_motivo,
            'documento' => $c->documento === null ? null : ['id' => (int) $c->documento->id, 'nome' => $c->documento->name],
            'oggetto' => $oggetto === null ? null : ['tipo' => $c->oggetto_tipo, 'id' => (int) $c->oggetto_id, 'nome' => SchedaDelCondominio::nomeDi($oggetto)],
            'compito_id' => $c->evento_id,
            'azione' => $c->eDaFare() ? self::azione($c) : null,
        ];
    }

    /** Il compito della cosa, dal giorno scelto alle 9:00. */
    private static function creaIlCompito(CosaDaFareDellaDelibera $cosa): Evento
    {
        $d = $cosa->delibera()->with(['assemblea.condominio', 'punto'])->firstOrFail();
        $respinta = $cosa->tipo === TipoCosaDaFare::DOPO_IL_RIFIUTO;
        $riga = ($d->assemblea?->condominio?->nome ?? '') . ' · assemblea del ' . $d->deliberata_il?->format('d/m/Y')
            . ($d->punto !== null ? ', punto ' . $d->punto->numero : '');
        $azione = self::azione($cosa);
        $compito = InboxService::createTask(
            $respinta ? EventoTipo::DELIBERA_RESPINTA : EventoTipo::DELIBERA_DA_ESEGUIRE,
            ($respinta ? 'Delibera respinta: ' : 'Delibera da eseguire: ') . $cosa->testo,
            DeliberaInParole::breve($d, scritta: true) . ($cosa->entro_il !== null && ! $respinta ? ', entro il ' . $cosa->entro_il->format('d/m/Y') : '') . '.',
            self::inizioDelGiorno((string) $cosa->entro_il?->format('Y-m-d')),
            $cosa->created_by === null ? null : (int) $cosa->created_by,
            (int) $cosa->condominio_id,
            ['delibera_id' => (int) $d->id, 'assemblea_id' => (int) $d->assemblea_id, 'cosa_id' => (int) $cosa->id],
            $azione['url'],
            ['cosa_id' => (int) $cosa->id, 'assegnato_a' => self::assegnatoA($cosa), 'creato_da' => $cosa->created_by, 'riga' => $riga,
                'azione' => $azione['testo'], 'scadenza' => $cosa->entro_il?->format('Y-m-d')],
            $cosa->getMorphClass(),
            (int) $cosa->id,
        );
        $cosa->forceFill(['evento_id' => $compito->id])->saveQuietly();

        return $compito;
    }

    /** Per «Le mie»: chi l'ha scritta con «A me», il collaboratore con «A un collaboratore», nessuno con «A tutto lo studio». */
    private static function assegnatoA(CosaDaFareDellaDelibera $cosa): ?int
    {
        return match ($cosa->a_chi) {
            AChiMemo::ME => $cosa->created_by === null ? null : (int) $cosa->created_by,
            AChiMemo::COLLABORATORE => $cosa->assegnato_a === null ? null : (int) $cosa->assegnato_a,
            default => null,
        };
    }

    private static function inizioDelGiorno(string $giorno): Carbon
    {
        return Carbon::parse(($giorno !== '' ? $giorno : DateHelper::oggiUtente()) . ' ' . self::ORA_DEL_COMPITO . ':00', (string) config('app.timezone'));
    }

    /** «creato», «registrata», «scritta»: la parola della scheda nata. */
    private static function nata(string $tipoScheda): string
    {
        return match ($tipoScheda) {
            'piano_rate' => 'creato',
            'dispensa_sei_mesi' => 'registrata',
            'regola_recupero' => 'scritta',
            default => 'fatto',
        };
    }

    /**
     * Il nuovo piano straordinario del deficit, con la delibera scelta, nell'esercizio aperto di oggi; null senza un esercizio aperto. Coda 743: con il debito
     * ereditato del punto ancora da ripianare, anche il compito e la gestione della pregressa (la pagina mette il debito nel piano), nell'esercizio della
     * pregressa se è aperto; chiuso, la pagina dell'esercizio di oggi dice perché lì il ripianamento non si prepara.
     */
    private static function pianoDelDeficit(CosaDaFareDellaDelibera $cosa): ?string
    {
        $cosa->loadMissing('delibera');
        $deficit = $cosa->delibera === null ? null : ContestoDellaDelibera::per($cosa->delibera)->scheda('compito');
        $parametri = \App\Services\Gestionale\RipianamentoDelDeficit::parametriDelCompito($deficit instanceof Evento ? $deficit : null);
        $esercizio = $parametri['esercizio'] ?? \App\Services\Treasury\DisponibilitaDiOggi::esercizioDiOggi((int) $cosa->condominio_id, DateHelper::oggiUtente());
        unset($parametri['esercizio']);
        if ($esercizio === null) {
            return null;
        }

        return route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $cosa->condominio_id, 'esercizio' => $esercizio,
            'tipo' => 'straordinario', 'delibera_id' => $cosa->delibera_id] + $parametri, false);
    }

    /** La pagina del piano da rimettere come il preventivo approvato, se la cosa lo ricorda. */
    private static function paginaDelPiano(CosaDaFareDellaDelibera $cosa): ?string
    {
        if ($cosa->oggetto_tipo !== 'piano_rate' || $cosa->oggetto_id === null) {
            return null;
        }
        $piano = \App\Models\Gestionale\PianoRate::query()->whereKey((int) $cosa->oggetto_id)->where('condominio_id', $cosa->condominio_id)->first();

        return $piano === null || $piano->esercizio_id === null ? null : route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $cosa->condominio_id,
            'esercizio' => $piano->esercizio_id, 'pianoRate' => $piano->id], false);
    }

    /** Il limite del documento di «Segna eseguita», come le prove: 10 MB, e il contenuto che dice il nome (un file HTML chiamato .pdf si rifiuta). */
    public static function regolaDelDocumento(): array
    {
        return ['nullable', 'file', 'extensions:' . implode(',', self::FORMATI), 'max:' . LimiteCaricamento::regolaMax(10.0), \App\Support\FileComeIlNome::regola()];
    }
}
