<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\EventoTipo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Assemblee\ConvocazioneDaSpedireNotification;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Settings\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Il promemoria per email del compito «Spedire la convocazione entro il …» ({@see CompitiDellaConvocazione}).
 *
 * - **A chi**: all'utente del compito, chi ha preparato l'assemblea. Se non c'è più, è sospeso o non può più gestire le assemblee, a nessuno, e il log lo
 *   dice.
 * - **Quando**: due giorni lavorativi prima del giorno limite del compito, con lo stesso calendario del programma (niente sabato, domenica e feste), e la
 *   mattina del giorno limite; dalle 8 di Roma. Solo se restano avvisi da spedire ({@see ElencoDeiConvocati::spedito()}); mai per un'assemblea annullata,
 *   registrata a mano o passata (il compito si chiude con lei). Il comando del mattino manda ciò che è dovuto e non è ancora partito: un giorno che il
 *   pianificatore non gira, parte il giorno dopo; passato il giorno limite scelto dall'amministratore parte solo quello del giorno, e dice che il giorno
 *   limite era. Passato il giorno di legge senza una data scelta (la prima convocazione troppo vicina, decisione 665) il giorno limite è oggi, come la
 *   proposta del campo, e il promemoria dice che è fuori dai cinque giorni dell'art. 66: ogni mattina, finché restano avvisi da spedire.
 * - **Una volta sola per tipo** e per giorno limite: il segno è la riga del registro degli invii (`dati.promemoria`, `dati.limite`), anche con il comando
 *   lanciato due volte. Se il giorno limite si sposta, il promemoria segue il giorno nuovo; un giorno limite già promemorato non si promemora di nuovo.
 * - **Che cosa dice**: l'oggetto «Convocazione da spedire entro il …: assemblea …», il condominio, quanti avvisi restano e per quale mezzo, il giorno limite
 *   in parole e il pulsante alla pagina della convocazione. La riga del registro degli invii porta il gettone: diventa «inviata» quando il server di posta
 *   accetta il messaggio, o «non partita» con l'errore.
 * - **L'interruttore** «Promemoria per email delle convocazioni da spedire» delle impostazioni della posta, acceso di serie. Spento, o senza un server di
 *   posta, niente email e nessun errore.
 *
 * Il giorno è sempre quello di Roma, come si legge.
 */
final class PromemoriaDellaConvocazione
{
    public const DUE_GIORNI_PRIMA = 'due_giorni_prima';

    public const IL_GIORNO = 'il_giorno';

    /** Dalle 8 di Roma: prima, per il promemoria vale ancora il giorno prima. */
    public const DALLE = '08:00';

    /** L'ora in cui il pianificatore manda i promemoria (`routes/console.php`): dopo, quello di oggi parte domattina. */
    public const ORA_DEL_PIANIFICATORE = '08:15';

    private const GIORNI = [1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica'];

    /** Le parole dei mezzi, nell'ordine in cui il promemoria li dice: prima le raccomandate, che decidono il giorno limite. */
    private const MEZZI = ['raccomandata' => 'per raccomandata', 'pec' => 'per PEC', 'a_mano' => 'da consegnare a mano', 'fax' => 'per fax',
        'senza' => 'senza un mezzo valido'];

    public function __construct(
        private readonly CalendarioFiscaleService $calendario,
        private readonly CompitiDellaConvocazione $compiti,
    ) {}

    /** L'interruttore delle impostazioni della posta. */
    public static function acceso(): bool
    {
        return (bool) app(MailSettings::class)->promemoria_convocazioni;
    }

    /** Il giorno limite del compito: il giorno del compito, scritto come si legge. */
    public static function limite(Evento $compito): CarbonImmutable
    {
        return CarbonImmutable::parse($compito->start_time->format('Y-m-d'));
    }

    /**
     * I due giorni del promemoria per un giorno limite: due giorni lavorativi prima, e il giorno stesso.
     *
     * @return array{due_giorni_prima: CarbonImmutable, il_giorno: CarbonImmutable}
     */
    public function giorni(CarbonImmutable $limite): array
    {
        $giorno = $limite->startOfDay();
        $contati = 0;
        while ($contati < 2) {
            $giorno = $giorno->subDay();
            if ($this->calendario->isGiornoLavorativo($giorno)) {
                $contati++;
            }
        }

        return [self::DUE_GIORNI_PRIMA => $giorno, self::IL_GIORNO => $limite->startOfDay()];
    }

    /**
     * Il promemoria dovuto adesso per un giorno limite, o null: dal giorno limite «il giorno», da due giorni lavorativi prima «due giorni prima». Il giorno
     * è quello di Roma dalle 8; prima delle 8 vale il giorno prima, così un promemoria che il pianificatore di ieri non ha mandato parte lo stesso.
     */
    public function dovuto(CarbonImmutable $limite, ?\DateTimeInterface $adesso = null): ?string
    {
        $giorni = $this->giorni($limite);
        $oggi = self::giornoDelPromemoria($adesso);

        return match (true) {
            $oggi->gte($giorni[self::IL_GIORNO]) => self::IL_GIORNO,
            $oggi->gte($giorni[self::DUE_GIORNI_PRIMA]) => self::DUE_GIORNI_PRIMA,
            default => null,
        };
    }

    /** Il giorno di Roma per il promemoria: oggi dalle 8, ieri prima delle 8. */
    private static function giornoDelPromemoria(?\DateTimeInterface $adesso): CarbonImmutable
    {
        $roma = CarbonImmutable::instance($adesso ?? now())->setTimezone((string) config('app.user_timezone', 'Europe/Rome'));
        $giorno = CarbonImmutable::parse($roma->format('Y-m-d'));

        return $roma->format('H:i') < self::DALLE ? $giorno->subDay() : $giorno;
    }

    /**
     * I giorni del promemoria per il passo «Convocazione», come si leggono: «martedì 01/12 e giovedì 03/12», senza quelli già passati (a Roma) o già
     * partiti; null se non ne restano. Spento: «spento»; senza un server di posta: «senza_server».
     */
    public function giorniDaDire(Assemblea $assemblea, CarbonImmutable $limite): ?string
    {
        if (! self::acceso()) {
            return 'spento';
        }
        if (EmissioneRicevute::senzaServerPosta()) {
            return 'senza_server';
        }
        $oggi = DateHelper::oggiUtenteImmutable();
        // Dopo l'ora del pianificatore il promemoria di oggi non parte più oggi: parte domattina, e la riga lo dice.
        $passataLOra = CarbonImmutable::now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i') >= self::ORA_DEL_PIANIFICATORE;
        $restano = [];
        foreach ($this->giorni($limite) as $tipo => $giorno) {
            if ($giorno->gte($oggi) && ! $this->giaPartito($assemblea, $tipo, $limite)) {
                if ($passataLOra && $giorno->equalTo($oggi)) {
                    $domani = $oggi->addDay();
                    $restano[] = 'domattina, ' . self::GIORNI[$domani->dayOfWeekIso] . ' ' . $domani->format('d/m');
                    continue;
                }
                $restano[] = self::GIORNI[$giorno->dayOfWeekIso] . ' ' . $giorno->format('d/m');
            }
        }
        $restano = array_values(array_unique($restano));

        return $restano === [] ? null : ConvocatiDellAssemblea::elenco($restano);
    }

    /**
     * Manda i promemoria dovuti e non ancora partiti, per ogni assemblea in preparazione o convocata. Rende una riga per promemoria partito o per destinatario
     * che manca, per il comando; le stesse righe vanno nel log.
     *
     * @return list<string>
     */
    public function manda(?\DateTimeInterface $adesso = null): array
    {
        if (! self::acceso()) {
            return ['Il promemoria è spento nelle impostazioni della posta: nessuna email.'];
        }
        if (EmissioneRicevute::senzaServerPosta()) {
            Log::info('Promemoria della convocazione: il programma non ha un server di posta, nessuna email.');

            return ['Il programma non ha un server di posta: nessuna email.'];
        }
        $righe = [];
        $assemblee = Assemblea::query()->whereIn('stato', [StatoAssemblea::IN_PREPARAZIONE->value, StatoAssemblea::CONVOCATA->value])->orderBy('id')->get();
        foreach ($assemblee as $assemblea) {
            try {
                $riga = $this->perLAssemblea($assemblea, $adesso);
            } catch (\Throwable $e) {
                Log::warning('Promemoria della convocazione non mandato: ' . $e->getMessage(), ['assemblea' => $assemblea->id]);
                $riga = 'Assemblea ' . $assemblea->id . ': promemoria non mandato, ' . $e->getMessage();
            }
            if ($riga !== null) {
                $righe[] = $riga;
            }
        }

        return $righe;
    }

    private function perLAssemblea(Assemblea $assemblea, ?\DateTimeInterface $adesso): ?string
    {
        // Il compito segue le date, e si chiude per un'assemblea passata: il promemoria legge quello di adesso.
        $this->compiti->sincronizza($assemblea);
        $compito = $this->compiti->aperti($assemblea, EventoTipo::SPEDIRE_CONVOCAZIONE)->first();
        if ($compito === null) {
            return null;
        }
        $limite = self::limite($compito);
        $tipo = $this->dovuto($limite, $adesso);
        if ($tipo === null) {
            return null;
        }
        // Due comandi insieme: il segno si guarda e si scrive uno alla volta.
        $esito = Cache::lock('promemoria-convocazione:' . $assemblea->id, 120)->get(fn () => $this->mandaUno($assemblea, $compito, $tipo, $limite, $adesso));

        return $esito === false ? null : $esito;
    }

    private function mandaUno(Assemblea $assemblea, Evento $compito, string $tipo, CarbonImmutable $limite, ?\DateTimeInterface $adesso): ?string
    {
        if ($this->giaPartito($assemblea, $tipo, $limite)) {
            return null;
        }
        $daSpedire = $this->compiti->daSpedire($assemblea);
        if ($daSpedire === []) {
            return null;
        }
        // A chi ha preparato l'assemblea, come dicono l'email e le impostazioni: il compito rinato dopo (un convocato nuovo) può essere di chi l'ha toccata per
        // ultimo, ma il promemoria resta di chi l'ha preparata.
        $chi = $assemblea->created_by ?? $compito->created_by;
        $utente = $chi === null ? null : User::query()->find($chi);
        $perche = self::percheANessuno($utente);
        if ($perche !== null) {
            $frase = 'Promemoria della convocazione dell\'assemblea ' . $assemblea->id . ' a nessuno: ' . $perche . '.';
            Log::info($frase, ['assemblea' => $assemblea->id, 'compito' => $compito->id, 'utente' => $compito->created_by]);

            return $frase;
        }

        $testi = $this->testi($assemblea, $tipo, $limite, $daSpedire, $adesso);
        $gettone = (string) Str::uuid();
        $riga = RegistroInvio::create(['condominio_id' => $assemblea->condominio_id, 'anagrafica_id' => null, 'tipo' => RegistroInvio::TIPO_PROMEMORIA_CONVOCAZIONE,
            'canale' => RegistroInvio::CANALE_EMAIL, 'documentabile_type' => $assemblea->getMorphClass(), 'documentabile_id' => $assemblea->id,
            'destinatario' => (string) $utente->email, 'stato' => RegistroInvio::STATO_IN_CODA, 'emesso_da' => null, 'emesso_il' => now(),
            'dati' => ['promemoria' => $tipo, 'limite' => $limite->format('Y-m-d'), 'oggetto' => $testi['oggetto'], 'righe' => $testi['righe'],
                'chiusura' => $testi['chiusura'], 'url' => CompitiDellaConvocazione::indirizzo($assemblea), 'avvisi' => count($daSpedire),
                'utente' => ['id' => (int) $utente->id, 'nome' => (string) $utente->name], 'compito_id' => (int) $compito->id, 'gettone' => $gettone]]);
        try {
            $notifica = new ConvocazioneDaSpedireNotification((int) $riga->id);
            $notifica->id = $gettone;
            Notification::route('mail', (string) $utente->email)->notify($notifica);
        } catch (\Throwable $e) {
            Log::warning('Promemoria della convocazione non partito: ' . $e->getMessage(), ['invio' => $riga->id]);
            $riga->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return 'Assemblea ' . $assemblea->id . ': promemoria «' . ($tipo === self::IL_GIORNO ? 'il giorno' : 'due giorni prima') . '» a ' . $utente->email . '.';
    }

    /** Il segno: la riga di questo promemoria per questo giorno limite c'è già (anche non partita: non si rimanda da sola). */
    private function giaPartito(Assemblea $assemblea, string $tipo, CarbonImmutable $limite): bool
    {
        return RegistroInvio::query()->where('tipo', RegistroInvio::TIPO_PROMEMORIA_CONVOCAZIONE)->where('documentabile_type', $assemblea->getMorphClass())
            ->where('documentabile_id', $assemblea->id)->where('dati->promemoria', $tipo)->where('dati->limite', $limite->format('Y-m-d'))->exists();
    }

    /** Perché il promemoria non va a nessuno, o null se c'è a chi mandarlo. */
    private static function percheANessuno(?User $utente): ?string
    {
        return match (true) {
            $utente === null => 'chi ha preparato l\'assemblea non c\'è più',
            $utente->suspended() => 'chi ha preparato l\'assemblea è sospeso',
            ! AccessoAlleAssemblee::gestisce($utente) => 'chi ha preparato l\'assemblea non può più gestire le assemblee',
            filter_var((string) $utente->email, FILTER_VALIDATE_EMAIL) === false => 'chi ha preparato l\'assemblea non ha un indirizzo email valido',
            default => null,
        };
    }

    /**
     * L'oggetto e le righe dell'email.
     *
     * @param list<array<string, mixed>> $daSpedire
     * @return array{oggetto: string, righe: list<string>, chiusura: string}
     */
    private function testi(Assemblea $assemblea, string $tipo, CarbonImmutable $limite, array $daSpedire, ?\DateTimeInterface $adesso): array
    {
        $assemblea->loadMissing('condominio');
        $nome = mb_strtolower($assemblea->nome());
        $condominio = TestiLettere::condominioConNome((string) $assemblea->condominio?->nome);
        $perMezzo = [];
        foreach ($daSpedire as $v) {
            $mezzo = ($v['mezzo']['valido'] ?? false) ? (string) ($v['mezzo']['mezzo'] ?? 'senza') : 'senza';
            $perMezzo[$mezzo] = ($perMezzo[$mezzo] ?? 0) + 1;
        }
        $parti = [];
        foreach (self::MEZZI as $mezzo => $parola) {
            if (($perMezzo[$mezzo] ?? 0) > 0) {
                $parti[] = $perMezzo[$mezzo] . ' ' . $parola;
            }
        }
        $quanti = count($daSpedire);
        $giorno = self::GIORNI[$limite->dayOfWeekIso] . ' ' . $limite->format('d/m');
        $oggi = self::giornoDelPromemoria($adesso);
        // La prima convocazione troppo vicina (decisione 665): il giorno limite è quello scelto, oggi, e il promemoria dice che è fuori dai cinque giorni.
        $fuori = app(TerminiDellaConvocazione::class)->fuoriDaiCinqueGiorni($assemblea, ($perMezzo['raccomandata'] ?? 0) > 0 ? 'raccomandata' : null);
        $quando = match (true) {
            $tipo === self::IL_GIORNO && $oggi->gt($limite) => 'Il giorno limite era ' . $giorno . ': spedisci appena puoi.',
            $tipo === self::IL_GIORNO && $fuori => 'Il giorno limite è oggi, ' . $giorno . ', fuori dai cinque giorni dell\'art. 66: spedisci appena puoi.',
            $tipo === self::IL_GIORNO => 'Il giorno limite è oggi, ' . $giorno . '.',
            default => 'Il giorno limite è ' . $giorno . '.',
        };

        return [
            'oggetto' => 'Convocazione da spedire ' . ConsegneDellaConvocazione::entro($limite) . ': assemblea ' . $nome,
            'righe' => [
                'Per l\'assemblea ' . $nome . ' del ' . $condominio . ' ' . ($quanti === 1 ? 'resta 1 avviso' : 'restano ' . $quanti . ' avvisi') . ' da spedire: '
                    . ConvocatiDellAssemblea::elenco($parti) . '.',
                $quando,
            ],
            'chiusura' => 'Il compito «Spedire la convocazione ' . ConsegneDellaConvocazione::entro($limite) . '» è nell\'Action inbox. Questo promemoria si spegne nelle '
                . 'impostazioni della posta.',
        ];
    }
}
