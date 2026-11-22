<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * L'invio per PEC di un documento a una persona, dalla casella PEC dello studio (decisioni 564 e 569): **non è della sola convocazione**, perché dopo la
 * convocazione ci passano anche le lettere ai condòmini in ritardo. Un invio è una riga del registro degli invii con il canale `pec`
 * ({@see RegistroInvio::CANALE_PEC}), il PDF conservato come documento della riga e la sua impronta; la riga nasce `in_coda` e diventa `inviata` quando il
 * gestore accetta il messaggio, che vuol dire solo «accettata»: la consegna è la ricevuta, che si trascina sulla riga e dà l'esito con il giorno e l'ora
 * (`esito`, `esito_il`, `ricevuta_il`). Se il gestore non accetta, la riga resta `non_partita` con l'errore intero; la pagina lo dice con
 * {@see ErroreDellaPec}.
 *
 * Il messaggio parte subito, non in una coda: le installazioni locali non ne hanno una affidabile, e chi preme «Manda le PEC» deve sapere se sono partite.
 * La casella si legge solo da {@see CasellaPec::configurazione()}; il trasporto è {@see TrasportoPec} (nei test, uno finto).
 */
class InvioPerPec
{
    /** La cartella dei PDF mandati per PEC, sotto quella del condominio. */
    public const CARTELLA = 'pec';

    private readonly TrasportoPec $trasporto;

    public function __construct(private readonly CasellaPec $casella, private readonly ArchivioDocumenti $archivio, ?TrasportoPec $trasporto = null)
    {
        $this->trasporto = $trasporto ?? new TrasportoPecSmtp();
    }

    /** Si può mandare per PEC: la casella dello studio è impostata. */
    public function puoInviare(): bool
    {
        return $this->casella->impostata();
    }

    /** La casella dello studio, per le frasi della pagina (mai la password). */
    public function casella(): ?ConfigurazionePec
    {
        return $this->casella->configurazione();
    }

    /**
     * Manda il documento e rende la sua riga del registro degli invii: `inviata` se il gestore l'ha accettato, `non_partita` con l'errore se no.
     *
     * @throws CasellaPecNonImpostata
     */
    public function invia(DocumentoPerPec $documento, ?int $emessoDa): RegistroInvio
    {
        $casella = $this->casella->configurazione();
        if ($casella === null) {
            throw new CasellaPecNonImpostata();
        }

        $impronta = hash('sha256', $documento->pdf);
        $percorso = $this->archivio->salvaContenuto($documento->pdf, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $documento->condominioId . '/' . self::CARTELLA);
        try {
            $riga = DB::transaction(function () use ($documento, $casella, $impronta, $percorso, $emessoDa) {
                $riga = RegistroInvio::create([
                    'condominio_id' => $documento->condominioId,
                    'anagrafica_id' => $documento->anagraficaId,
                    'tipo' => $documento->tipo,
                    'canale' => RegistroInvio::CANALE_PEC,
                    'documentabile_type' => $documento->documentabile->getMorphClass(),
                    'documentabile_id' => $documento->documentabile->getKey(),
                    'destinatario' => $documento->indirizzo,
                    // Il testo del messaggio sta in una chiave sua: la riga di una lettera ha già `testo` (il testo della lettera, che il PDF e la lettera
                    // rifatta leggono), e con `+` vince la chiave di sinistra. L'oggetto resta in `oggetto`: il controllo della ricevuta lo legge.
                    'dati' => ['oggetto' => $documento->oggetto, 'testo_del_messaggio' => $documento->testo, 'dalla_casella' => $casella->indirizzo] + $documento->dati,
                    'impronta' => $impronta,
                    'stato' => RegistroInvio::STATO_IN_CODA,
                    'emesso_da' => $emessoDa,
                    'emesso_il' => now(),
                ]);
                $conservato = $riga->documenti()->create([
                    'name' => $documento->nomeDelFile,
                    'description' => $documento->oggetto,
                    'path' => $percorso,
                    'mime_type' => 'application/pdf',
                    'file_size' => strlen($documento->pdf),
                    'created_by' => $emessoDa,
                    'is_published' => false,
                    'is_approved' => true,
                ]);
                $riga->update(['dati' => (array) $riga->dati + ['documento_id' => $conservato->id]]);

                return $riga;
            });
        } catch (\Throwable $e) {
            $this->archivio->elimina($percorso);

            throw $e;
        }

        // Il messaggio si compone dentro il `try`: un indirizzo scritto male (che `Address` rifiuta) è una PEC non partita con l'errore, non un errore della
        // pagina che lascerebbe la riga «in partenza».
        try {
            $messaggio = (new Email())
                ->from(new Address($casella->indirizzo))
                ->to(new Address($documento->indirizzo))
                ->subject($documento->oggetto)
                ->text($documento->testo)
                ->attach($documento->pdf, Str::finish($documento->nomeDelFile, '.pdf'), 'application/pdf');
            foreach ($documento->allegati as $allegato) {
                // Un allegato con il suo tipo parte com'è, con il suo nome (la copia sottoscritta del verbale, anche `.p7m`); gli altri sono PDF.
                isset($allegato['mime'])
                    ? $messaggio->attach($allegato['contenuto'], (string) $allegato['file'], (string) $allegato['mime'])
                    : $messaggio->attach($allegato['contenuto'], Str::finish($allegato['file'], '.pdf'), 'application/pdf');
            }
            $this->trasporto->manda($casella, $messaggio);
            $riga->update(['stato' => RegistroInvio::STATO_INVIATA, 'errore' => null]);
        } catch (\Throwable $e) {
            $riga->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => mb_substr($e->getMessage() !== '' ? $e->getMessage() : $e::class, 0, 1000)]);
        }

        return $riga->refresh();
    }

    /** Il PDF conservato di una riga mandata per PEC. */
    public function conservato(RegistroInvio $riga): ?Documento
    {
        $id = (int) ($riga->dati['documento_id'] ?? 0);

        return $id === 0 ? null : $riga->documenti()->whereKey($id)->first();
    }
}
