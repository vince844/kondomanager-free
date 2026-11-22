<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Models\Gestionale\RegistroInvio;
use App\Models\MailLog;
use App\Settings\MailSettings;
use App\Settings\PecSettings;
use App\Settings\PrintSettings;
use App\Support\FunzioniInstallazione;
use Illuminate\Support\Carbon;

/**
 * Le caselle dello studio come le legge la pagina «Configurazione email» (decisione 572, bozza approvata): per ognuna lo stato in una parola, la riga
 * «Adesso», la riga dell'indirizzo, la card in alto e l'ultima prova; in più la card degli invii del mese, contati dallo storico delle email e dal registro
 * degli invii. Le frasi sono quelle della bozza (scene 1, 2, 3 e 5).
 *
 * Gli stati (proposta 6): «Da impostare» arancio, «Da provare» azzurro, «Provata» verde, «Prova non andata» rosso, «Spenta» grigio (solo la posta). Dove la
 * posta la dà l'installazione ({@see FunzioniInstallazione}, `posta_dell_installazione`) la posta è «Già pronta» e la pagina non la cambia.
 */
final class CaselleDelloStudio
{
    public const DA_IMPOSTARE = 'da_impostare';
    public const DA_PROVARE = 'da_provare';
    public const PROVATA = 'provata';
    public const NON_ANDATA = 'non_andata';
    public const SPENTA = 'spenta';
    public const GIA_PRONTA = 'gia_pronta';

    public const PAROLE = [
        self::DA_IMPOSTARE => 'Da impostare',
        self::DA_PROVARE => 'Da provare',
        self::PROVATA => 'Provata',
        self::NON_ANDATA => 'Prova non andata',
        self::SPENTA => 'Spenta',
        self::GIA_PRONTA => 'Già pronta',
    ];

    public const CONSIGLIO = 'Fai una prova quando puoi: gli invii continuano.';

    private const MESI = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    public function __construct(private readonly CasellaPec $casella) {}

    /** La casella PEC: lo stato, le frasi e i valori del modulo (mai la password). @return array<string, mixed> */
    public function pec(): array
    {
        $s = app(PecSettings::class);
        $configurazione = $this->casella->configurazione();
        $prova = $this->casella->ultimaProva();
        $stato = match (true) {
            $configurazione === null => self::DA_IMPOSTARE,
            $prova === null => self::DA_PROVARE,
            $prova['andata'] => self::PROVATA,
            default => self::NON_ANDATA,
        };
        $quando = ProveDelleCaselle::quando($prova['il'] ?? null);
        $motivo = $stato === self::NON_ANDATA ? MotivoDellaProva::per((string) ($prova['motivo'] ?? MotivoDellaProva::ALTRO), 'pec') : null;
        $impostata = ProveDelleCaselle::quando($s->impostata_il);

        $adesso = match ($stato) {
            self::DA_IMPOSTARE => 'Adesso: da impostare. Le convocazioni e le lettere per PEC non possono ancora partire dal programma.',
            self::DA_PROVARE => 'Adesso: impostata' . ($impostata !== null ? ' ' . $impostata : '') . ', non ancora provata.',
            self::PROVATA => 'Adesso: impostata, provata ' . $quando . '.',
            default => 'Adesso: impostata, ma l\'ultima prova, ' . $quando . ', non è andata: ' . $motivo['breve'] . '.',
        };
        $sotto = match ($stato) {
            self::DA_IMPOSTARE => 'le convocazioni e le lettere per PEC partono da qui',
            self::DA_PROVARE => $configurazione->indirizzo . ' · non ancora provata',
            self::PROVATA => $configurazione->indirizzo . ' · ' . $quando,
            default => $configurazione->indirizzo . ' · l\'ultima prova ' . $quando,
        };
        $carta = $this->pecDellaCarta();

        return [
            'stato' => $stato,
            'parola' => self::PAROLE[$stato],
            'adesso' => $adesso,
            'riga' => $configurazione === null ? null
                : $configurazione->indirizzo . ' · ' . $configurazione->server . ', porta ' . $configurazione->porta . ', ' . strtoupper($configurazione->cifratura),
            'card' => ['valore' => self::PAROLE[$stato], 'sotto' => $sotto],
            'ultima_prova' => $this->provaPerLaPagina($prova, 'pec'),
            'valori' => [
                'indirizzo' => $s->indirizzo,
                'server' => $s->server,
                'porta' => $s->porta,
                'cifratura' => in_array($s->cifratura, ['ssl', 'tls'], true) ? $s->cifratura : 'ssl',
                'utente' => $s->utente,
            ],
            'password_impostata' => CasellaPec::decifra($s->password) !== null,
            'dalla_carta' => $carta,
        ];
    }

    /** La posta dello studio: lo stato e le frasi; i valori del modulo restano nella prop `settings` di sempre. @return array<string, mixed> */
    public function posta(): array
    {
        if (FunzioniInstallazione::postaDellInstallazione()) {
            return [
                'stato' => self::GIA_PRONTA,
                'parola' => self::PAROLE[self::GIA_PRONTA],
                'adesso' => 'Adesso: la posta la prepara chi gestisce questa installazione. Le email partono da lì: qui non c\'è niente da impostare.',
                'riga' => null,
                'card' => ['valore' => self::PAROLE[self::GIA_PRONTA], 'sotto' => 'la prepara chi gestisce l\'installazione'],
                'ultima_prova' => null,
                'sola_lettura' => true,
            ];
        }

        $s = app(MailSettings::class);
        $sendmail = ($s->mail_driver ?? 'smtp') === 'sendmail';
        $impronta = ProveDelleCaselle::improntaDellaPosta($s);
        $memoria = is_array($s->mail_ultima_prova) ? $s->mail_ultima_prova : null;
        $prova = $impronta !== null && $memoria !== null && ($memoria['impronta'] ?? null) === $impronta ? $memoria : null;
        $stato = match (true) {
            ! $s->mail_enabled => self::SPENTA,
            $impronta === null => self::DA_IMPOSTARE,
            $prova === null => self::DA_PROVARE,
            (bool) ($prova['andata'] ?? false) => self::PROVATA,
            default => self::NON_ANDATA,
        };
        $quando = ProveDelleCaselle::quando($prova['il'] ?? null);
        $motivo = $stato === self::NON_ANDATA ? MotivoDellaProva::per((string) ($prova['motivo'] ?? MotivoDellaProva::ALTRO), 'posta') : null;
        $impostata = ProveDelleCaselle::quando($s->mail_impostata_il);
        $aNomeDi = filled($s->mail_from_name) ? ' Le email partono a nome di «' . $s->mail_from_name . '».' : '';
        $indirizzo = filled($s->mail_from_address) ? (string) $s->mail_from_address : null;

        $adesso = match ($stato) {
            self::SPENTA => 'Adesso: spenta. Le email partono con la posta preparata sul server, se c\'è; se non c\'è, restano scritte nel registro del programma.',
            self::DA_IMPOSTARE => 'Adesso: da impostare. Finché manca il server, le email partono con la posta preparata sul server, se c\'è.',
            self::DA_PROVARE => 'Adesso: impostata' . ($impostata !== null ? ' ' . $impostata : '') . ', non ancora provata.' . $aNomeDi,
            self::PROVATA => 'Adesso: impostata, provata ' . $quando . '.' . $aNomeDi,
            default => 'Adesso: impostata, ma l\'ultima prova, ' . $quando . ', non è andata: ' . $motivo['breve'] . '.',
        };
        $chi = $indirizzo ?? 'la posta dello studio';
        $sotto = match ($stato) {
            self::SPENTA => 'le email partono con la posta del server, se c\'è',
            self::DA_IMPOSTARE => 'manca il server di posta in uscita',
            self::DA_PROVARE => $chi . ' · non ancora provata',
            self::PROVATA => $chi . ' · ' . $quando,
            default => $chi . ' · l\'ultima prova ' . $quando,
        };
        $riga = match (true) {
            $sendmail => ($indirizzo !== null ? $indirizzo . ' · ' : '') . 'la posta del server (Sendmail)',
            filled($s->mail_host) => ($indirizzo !== null ? $indirizzo . ' · ' : '') . $s->mail_host . ', porta ' . $s->mail_port . ', '
                . (ImprontaDellaCasella::cifratura($s->mail_encryption) === '' ? 'senza cifratura' : strtoupper(ImprontaDellaCasella::cifratura($s->mail_encryption))),
            default => null,
        };

        return [
            'stato' => $stato,
            'parola' => self::PAROLE[$stato],
            'adesso' => $adesso,
            'riga' => $riga,
            'card' => ['valore' => self::PAROLE[$stato], 'sotto' => $sotto],
            'ultima_prova' => $this->provaPerLaPagina($prova, 'posta'),
            'sola_lettura' => false,
            // Dopo l'aggiornamento la posta che va da mesi risulta mai provata: un consiglio, non un allarme, e niente si ferma (decisione 577).
            'consiglio' => $stato === self::DA_PROVARE ? self::CONSIGLIO : null,
        ];
    }

    /**
     * La card degli invii del mese (proposta 12): le email partite e quelle non partite dallo storico delle email, le PEC partite dal registro degli invii e
     * dallo storico (le prove). «Non partite» sono quelle rifiutate e quelle rimaste nel registro del programma senza un server.
     *
     * @return array{titolo: string, valore: string, sotto: string, url: string}
     */
    public function invii(): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $inizio = Carbon::now($fuso)->startOfMonth()->utc();
        $fine = Carbon::now($fuso)->endOfMonth()->utc();
        $delMese = fn () => MailLog::query()->whereBetween('sent_at', [$inizio, $fine]);

        $email = $delMese()->where('mailer', '!=', ProvaDellaPec::NELLO_STORICO)->where('status', 'sent')->count();
        $nonPartite = $delMese()->whereIn('status', ['failed', 'logged'])->count()
            + RegistroInvio::query()->where('canale', RegistroInvio::CANALE_PEC)->where('stato', RegistroInvio::STATO_NON_PARTITA)
                ->whereBetween('emesso_il', [$inizio, $fine])->count();
        $pec = RegistroInvio::query()->where('canale', RegistroInvio::CANALE_PEC)->where('stato', RegistroInvio::STATO_INVIATA)
            ->whereBetween('emesso_il', [$inizio, $fine])->count()
            + $delMese()->where('mailer', ProvaDellaPec::NELLO_STORICO)->where('status', 'sent')->count();

        return [
            'titolo' => 'Gli invii di ' . self::MESI[Carbon::now($fuso)->month],
            'valore' => self::numero($email) . ' email',
            'sotto' => match ($nonPartite) { 0 => 'nessuna non partita', 1 => '1 non partita', default => self::numero($nonPartite) . ' non partite' }
                . ' · ' . ($pec === 0 ? 'nessuna PEC' : self::numero($pec) . ' PEC'),
            'url' => route('logs.index'),
        ];
    }

    /**
     * Per «Manda le PEC» della convocazione e delle lettere (proposta 11): con l'ultima prova non andata il pulsante resta, e la conferma lo dice; `null`
     * altrimenti.
     */
    public function avvisoPerLInvio(): ?string
    {
        $prova = $this->casella->ultimaProva();
        if ($prova === null || $prova['andata']) {
            return null;
        }
        $motivo = MotivoDellaProva::per((string) ($prova['motivo'] ?? MotivoDellaProva::ALTRO), 'pec');

        return 'L\'ultima prova della casella PEC dello studio, ' . ProveDelleCaselle::quando($prova['il']) . ', non è andata: ' . $motivo['breve']
            . '. Se mandi lo stesso, le PEC che il gestore non accetta restano da mandare, con il motivo.';
    }

    /** @param array<string, mixed>|null $prova @return array<string, mixed>|null */
    private function provaPerLaPagina(?array $prova, string $casella): ?array
    {
        if ($prova === null) {
            return null;
        }
        $andata = (bool) ($prova['andata'] ?? false);

        return [
            'quando' => ProveDelleCaselle::quando($prova['il'] ?? null),
            'andata' => $andata,
            'destinatario' => $prova['destinatario'] ?? null,
            'motivo' => $andata ? null : MotivoDellaProva::per((string) ($prova['motivo'] ?? MotivoDellaProva::ALTRO), $casella),
            'testo' => $andata ? null : ($prova['testo'] ?? null),
        ];
    }

    /** L'indirizzo PEC della carta dello studio (Impostazioni › Stampe), da proporre. */
    private function pecDellaCarta(): ?string
    {
        try {
            $pec = app(PrintSettings::class)->studio_pec;
        } catch (\Throwable) {
            return null;
        }

        return filled($pec) ? trim((string) $pec) : null;
    }

    private static function numero(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
