<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Concerns;

use App\Enums\Permission;
use App\Helpers\RouteHelper;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Gli avvisi all'autore di un commento che un altro ha nascosto o eliminato (decisioni 478 (2) e 483 (3)).
 *
 * Quando la notifica gira, in coda, il commento può essere già eliminato (soft delete) e la segnalazione o la comunicazione
 * possono non esserci più: per questo la notifica non tiene il model del commento (`SerializesModels` lo rileggerebbe e
 * fallirebbe) ma porta con sé come testo ciò che serve, scritto al momento dell'azione. Il collegamento alla scheda si
 * compone solo se l'elemento c'è ancora e, come per gli altri avvisi all'autore, solo se l'autore lo apre
 * (`PulsanteSoloSeLaApre`).
 */
trait AvvisoSulCommentoDiUnAltro
{
    use PulsanteSoloSeLaApre;
    use TestoScrittoDaAltri;

    /** Quanti caratteri, al massimo, del commento l'avviso riporta: poi si taglia a una parola intera. */
    public const LUNGHEZZA_INIZIO = 120;

    public int $commentoId;

    public string $commentableType;

    public int $commentableId;

    /** 'ticket' o 'communication': sceglie le chiavi di lingua. */
    public string $tipo;

    /** Il titolo dell'elemento, già ripulito dal testo scritto da altri; vuoto se non c'è. */
    public string $titolo;

    /** La data del commento (`d/m/Y`), nel fuso dell'installazione. */
    public string $data;

    /** Le prime parole del commento, già ripulite e tagliate. */
    public string $inizio;

    /** Il commento era ancora in attesa di approvazione quando è stato eliminato. */
    public bool $eraInAttesa;

    protected function ricordaIlCommento(Commento $commento): void
    {
        $elemento = $commento->commentable;

        $this->commentoId = (int) $commento->id;
        $this->commentableType = (string) $commento->commentable_type;
        $this->commentableId = (int) $commento->commentable_id;
        $this->tipo = $elemento instanceof Comunicazione ? 'communication' : 'ticket';
        $titolo = $elemento?->subject ?? null;
        $this->titolo = $titolo === null ? '' : self::testoComeTesto($titolo);
        $this->data = $commento->created_at
            ? $commento->created_at->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y')
            : '';
        $this->inizio = self::inizioDelTesto((string) $commento->corpo);
        $this->eraInAttesa = $commento->stato === 'in_attesa';
    }

    /**
     * Le prime parole di un commento: il testo come testo (`TestoScrittoDaAltri`), su una riga, al massimo
     * `LUNGHEZZA_INIZIO` caratteri tagliati a una parola intera, con «…» se si è tagliato.
     */
    public static function inizioDelTesto(string $corpo): string
    {
        $testo = self::testoComeTesto($corpo);
        if (mb_strlen($testo) <= self::LUNGHEZZA_INIZIO) {
            return $testo;
        }

        $taglio = mb_substr($testo, 0, self::LUNGHEZZA_INIZIO);
        // Se il taglio cade dentro una parola (il carattere dopo non è uno spazio) si torna all'ultimo spazio.
        if (mb_substr($testo, self::LUNGHEZZA_INIZIO, 1) !== ' ') {
            $ultimoSpazio = mb_strrpos($taglio, ' ');
            if ($ultimoSpazio !== false && $ultimoSpazio > 0) {
                $taglio = mb_substr($taglio, 0, $ultimoSpazio);
            }
        }

        return rtrim($taglio, " ,;:.-") . '…';
    }

    /** `segnalazione "Perdita d'acqua nel box"`, o solo `segnalazione` se il titolo non c'è. */
    protected function riferimento(): string
    {
        $parola = __("notifications.comment_entity.{$this->tipo}");

        return $this->titolo !== '' ? "{$parola} \"{$this->titolo}\"" : $parola;
    }

    /** L'elemento del commento, se esiste ancora (segnalazione o comunicazione); altrimenti null. */
    protected function elemento(): Segnalazione|Comunicazione|null
    {
        $classe = Relation::getMorphedModel($this->commentableType) ?? $this->commentableType;
        if (! is_string($classe) || ! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
            return null;
        }
        $elemento = $classe::query()->find($this->commentableId);

        return $elemento instanceof Segnalazione || $elemento instanceof Comunicazione ? $elemento : null;
    }

    /** Il pulsante verso la scheda (con il motivo, se l'autore non la apre); niente se l'elemento non c'è più. */
    protected function conIlPulsante(MailMessage $mail, object $notifiable): MailMessage
    {
        $elemento = $this->elemento();
        if ($elemento === null) {
            return $mail;
        }

        $prefisso = RouteHelper::getRoutePrefixForUser($notifiable);
        $comunicazione = $elemento instanceof Comunicazione;
        $indirizzo = ($comunicazione
            ? route("{$prefisso}.comunicazioni.show", $elemento)
            : route("{$prefisso}.segnalazioni.show", $elemento)) . '#commenti';

        return $this->pulsanteOMotivo(
            $mail,
            $notifiable,
            $elemento,
            __($this->chiaveAvviso() . '.action'),
            $indirizzo,
            $comunicazione ? Permission::VIEW_COMUNICAZIONI->value : Permission::VIEW_SEGNALAZIONI->value,
            $comunicazione
                ? $elemento->condomini()->orderBy('nome')->pluck('nome')->implode(', ')
                : (string) $elemento->condominio?->nome,
        );
    }

    /** La chiave di lingua dell'avviso, per esempio `notifications.deleted_ticket_comment`. */
    abstract protected function chiaveAvviso(): string;

    public function toArray(object $notifiable): array
    {
        return [
            'commento_id'      => $this->commentoId,
            'commentable_type' => $this->commentableType,
            'commentable_id'   => $this->commentableId,
        ];
    }
}
