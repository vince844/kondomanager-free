<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\User;
use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La funzione sola che scrive le risposte dell'amministratore ai promemoria (la tabella `risposte_promemoria`): «Registra che hai agito», «Fatto», «Non serve»,
 * «Ricordamelo», «Riapri», «Mettilo in agenda». Le date non si scrivono mai (si calcolano a ogni lettura): qui c'è solo ciò che l'amministratore ha detto,
 * con il registro di ogni gesto nella forma di {@see RegistroDelGesto}. Lo scrive solo il server; un `registro` mandato dalla pagina non si legge.
 *
 * **Due amministratori insieme.** Ogni risposta si scrive in una transazione: prima la riga vuota con la sua chiave (`insertOrIgnore`: se c'è già non succede
 * niente), poi la lettura con il lucchetto, poi la scrittura. Senza la prima mossa, la prima volta la riga non c'è, il lucchetto non tiene niente e la seconda
 * richiesta finirebbe sull'indice unico. «Fatto» fa lo stesso per la riga dell'esercizio e per ogni persona, nella stessa transazione; il promemoria nell'agenda
 * si scrive nella stessa transazione della risposta, che tiene il suo id.
 *
 * **L'agenda.** «Ricordamelo» e «Mettilo in agenda» scrivono un evento nell'agenda del condominio ({@see InboxService::creaPromemoria()}), il giorno scelto alle
 * 09:00, scritte come le scrive il resto dell'agenda: l'ora così come si legge, «alle 09:00», d'inverno e d'estate. Una risposta ha un evento solo per tipo: una seconda volta si sposta lo stesso; se non c'è più, se ne
 * scrive uno nuovo. Il giorno vero lo dice l'evento (spostato nell'agenda, vale il giorno nuovo): `ricordami_il` è la copia del giorno scelto, per il registro.
 * Il promemoria del compito di una segnalazione non porta l'oggetto della segnalazione, che si legge solo aprendola: l'agenda dello studio la legge anche chi
 * non apre le segnalazioni ({@see TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE}).
 *
 * **I compiti dell'agenda** (le due Inbox): «Fatto» e «Non serve» chiudono l'evento come facevano «Completa» e «Rifiuta», e scrivono la risposta, riga
 * `compito` con il titolo e il tipo copiati al primo gesto (la risposta resta quando l'evento se ne va). «Ricordamelo» non chiude niente: sposta l'evento al
 * giorno scelto, o, per i tipi in cui il giorno è una scadenza, lo riscrive il programma o dice da quando il compito aspetta, scrive un promemoria a parte
 * ({@see COMPITI_CON_IL_PROMEMORIA_A_PARTE}). La regola che nasconde il compito
 * fino al giorno è {@see Evento::scopeCompitiAperti()}. Le chiusure da sole del programma non passano di qui, e restano come sono.
 */
final class RispostePromemoria
{
    public const COMPITO = 'compito';
    public const SEI_MESI = 'sei_mesi';
    public const RENDICONTO = 'rendiconto';
    public const PERSONA = 'persona';

    public const AGITO = 'agito';
    public const FATTO = 'fatto';
    public const NON_SERVE = 'non_serve';
    public const RICORDAMELO = 'ricordamelo';

    /** L'ora del promemoria nell'agenda, nel fuso dell'amministratore. */
    public const ORA_DEL_PROMEMORIA = '09:00';

    /** `argomento|condominio|esercizio|persona`, con `-` dove manca. */
    public static function chiave(string $argomento, ?int $condominio, ?int $esercizio, ?int $persona): string
    {
        return implode('|', [$argomento, $condominio ?? '-', $esercizio ?? '-', $persona ?? '-']);
    }

    public static function chiaveDelCompito(int $evento): string
    {
        return self::COMPITO . '|' . $evento;
    }

    /**
     * I tipi di compito in cui il giorno dell'evento è una scadenza (il pagamento di una fattura, l'F24) o lo riscrive il programma a ogni salvataggio della
     * fattura (la convocazione per lo sforo, la ratifica, la sopravvenienza, il deficit ereditato); i commenti e gli allegati, che tornano prima se ne arriva
     * uno nuovo e allora devono contare con il loro giorno (nel numero della barra, fra gli urgenti); la segnalazione di guasto e il bonifico segnalato, il cui
     * giorno dice da quando aspettano («aperta il», «segnalato il», i più vecchi prima): «Ricordamelo» lascia l'evento al suo giorno e scrive un promemoria a
     * parte. Per gli altri il giorno dell'evento è solo un promemoria, e si sposta.
     */
    public const COMPITI_CON_IL_PROMEMORIA_A_PARTE = ['pagamento_fornitore', 'versamento_ritenuta', 'convocazione_urgenza', 'ratifica_sforo',
        'emissione_rata_sopravvenienza', 'pianifica_ripianamento_deficit', 'commento', 'allegato', 'segnalazione_guasto', 'verifica_pagamento', 'da_moderare'];

    /** I tipi con la scadenza: se il programma la anticipa, il compito torna quel giorno. */
    public const COMPITI_CON_LA_SCADENZA = ['pagamento_fornitore', 'versamento_ritenuta', 'convocazione_urgenza', 'ratifica_sforo',
        'emissione_rata_sopravvenienza', 'pianifica_ripianamento_deficit'];

    public const FRASE_SCADENZA_CAMBIATA = 'La scadenza è cambiata: il compito è tornato.';

    /** «Fatto» sul bonifico segnalato, finché la segnalazione è in verifica: il condòmino resterebbe «in verifica» per sempre. */
    public const FRASE_PRIMA_L_INCASSO = 'Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».';

    /** I compiti che tornano prima del giorno quando arriva una cosa nuova, con le parole della riga. */
    public const COMPITI_CHE_TORNANO_PRIMA = ['commento' => 'un commento nuovo', 'allegato' => 'un allegato nuovo', 'da_moderare' => 'qualcosa di nuovo da moderare'];

    /** Il bonifico segnalato dal portale: «Non serve» è il rifiuto della segnalazione, che il condòmino legge. */
    public const BONIFICO_SEGNALATO = 'verifica_pagamento';

    public const FRASE_PROMEMORIA_TOLTO = 'Il promemoria nell\'agenda è stato tolto: il compito è tornato.';

    /**
     * Il titolo del promemoria di «Ricordamelo» sul compito di una segnalazione, con il nome del condominio al posto di `:condominio`. L'agenda dello studio
     * la legge anche chi non apre le segnalazioni: l'oggetto della segnalazione non ci va, si legge solo aprendola. Le parole stanno qui sole.
     */
    public const TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE = 'Riprendi una segnalazione · :condominio';

    /** La descrizione di serie dello stesso promemoria: anche qui niente oggetto. */
    public const DESCRIZIONE_DEL_PROMEMORIA_DELLA_SEGNALAZIONE = 'La segnalazione resta nell\'Inbox al suo giorno: si legge aprendola.';

    /** Il tipo del compito: la colonna, o `meta.type` per i compiti che i listener scrivono senza la colonna. */
    public static function tipoDelCompito(Evento $compito): string
    {
        return (string) ($compito->tipo?->value ?? $compito->meta['type'] ?? 'generic');
    }

    public static function promemoriaAParte(Evento $compito): bool
    {
        return in_array(self::tipoDelCompito($compito), self::COMPITI_CON_IL_PROMEMORIA_A_PARTE, true);
    }

    public static function eUnBonificoSegnalato(Evento $compito): bool
    {
        return self::tipoDelCompito($compito) === self::BONIFICO_SEGNALATO;
    }

    /**
     * «Fatto» su un compito dell'agenda: chiude l'evento come faceva «Completa» (`is_completed`, `completed_at`) e scrive la risposta con la nota, che qui è
     * facoltativa. Un promemoria a parte ancora nell'agenda si toglie.
     */
    public function fattoDelCompito(Evento $compito, ?string $nota, ?User $utente): RispostaPromemoria
    {
        $r = DB::transaction(function () use ($compito, $nota, $utente) {
            $r = $this->chiudiIlCompito($compito, self::FATTO, self::notaPulita($nota), $utente, []);
            // Sul bonifico segnalato la rata non resta «in verifica»: si riallinea alle quote, con l'incasso dopo la segnalazione se c'è.
            if (self::eUnBonificoSegnalato($compito)) {
                app(SegnalazioneDelBonifico::class)->chiusa($compito);
            }

            return $r;
        });
        InboxService::clearAdminCache();

        return $r;
    }

    /**
     * «Non serve» su un compito dell'agenda, con il motivo: chiude l'evento come «Fatto». Sul bonifico segnalato dal portale è il rifiuto della segnalazione,
     * come faceva «Rifiuta»: sull'evento del condòmino collegato (`related_event_id`, e nessun altro) lo stato `rejected`, il motivo e il giorno, che il
     * portale mostra.
     */
    public function nonServeDelCompito(Evento $compito, string $motivo, ?User $utente): RispostaPromemoria
    {
        $r = DB::transaction(function () use ($compito, $motivo, $utente) {
            $rifiutata = $this->rifiutaLaSegnalazione($compito, $motivo);

            return $this->chiudiIlCompito($compito, self::NON_SERVE, self::notaPulita($motivo), $utente, $rifiutata === null ? [] : ['segnalazione_rifiutata' => $rifiutata]);
        });
        InboxService::clearAdminCache();

        return $r;
    }

    /**
     * «Ricordamelo il…» su un compito dell'agenda: non chiude niente. Il giorno va nell'agenda, alle 09:00 come si leggono nell'agenda: si sposta l'evento
     * (la stessa durata; il giorno di prima resta nel registro), o, per i tipi di {@see COMPITI_CON_IL_PROMEMORIA_A_PARTE}, si scrive un promemoria a parte,
     * uno per risposta. Per i commenti e gli allegati si tiene il giorno dell'ultimo aggiornamento e il contatore: uno nuovo fa tornare il compito prima.
     */
    public function ricordameloDelCompito(Evento $compito, string $giorno, ?string $nota, User $utente): RispostaPromemoria
    {
        $nota = self::notaPulita($nota);
        $r = DB::transaction(fn () => $this->scriviDelCompito($compito, function (RispostaPromemoria $r) use ($compito, $giorno, $nota, $utente) {
            $dati = ['giorno' => $giorno];
            if (self::promemoriaAParte($compito)) {
                [$titolo, $diSerie] = self::titoloEDescrizioneDelPromemoria($compito, $r->condominio_id === null ? null : (int) $r->condominio_id);
                $evento = $this->eventoNelGiorno($r, 'promemoria_evento_id', $r->condominio_id, $giorno, $nota, $utente,
                    $titolo, route('admin.inbox', array_filter(['condominio_id' => $r->condominio_id])), $diSerie, ['compito_id' => $compito->id]);
            } else {
                $inizio = self::inizioDelGiorno($giorno);
                $durata = $compito->end_time === null ? 3600 : max(0, (int) $compito->start_time->diffInSeconds($compito->end_time, false));
                $dati['prima'] = $compito->start_time->copy()->utc()->format('Y-m-d H:i');
                $compito->update(['start_time' => $inizio, 'end_time' => $inizio->copy()->addSeconds($durata)]);
                $evento = $compito;
            }
            $dati['evento_id'] = $evento->id;
            // Ciò che fa tornare prima il compito: per i commenti e gli allegati un arrivo dopo adesso, per i compiti con la scadenza la scadenza di adesso.
            $tipo = self::tipoDelCompito($compito);
            $tornaPrima = ['aggiornato_il' => isset(self::COMPITI_CHE_TORNANO_PRIMA[$tipo]) ? now()->utc()->format('Y-m-d H:i:s') : null,
                'scadenza' => in_array($tipo, self::COMPITI_CON_LA_SCADENZA, true) ? $compito->start_time?->copy()->utc()->format('Y-m-d H:i:s') : null];
            $r->fill(['stato' => self::RICORDAMELO, 'ricordami_il' => $giorno, 'promemoria_evento_id' => $evento->id, 'nota' => $nota, 'aggiornata_da' => $utente->id]);
            $r->dati = array_merge(array_diff_key((array) ($r->dati ?? []), ['contatore' => true]), ['ricordamelo' => $dati], $tornaPrima);
            $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], self::RICORDAMELO, (string) $nota, $utente, $dati)['registro'];
        }));
        InboxService::clearAdminCache();

        return $r;
    }

    /**
     * Il titolo e la descrizione di serie del promemoria a parte: «Ricordamelo: » con il titolo del compito; per il compito di una segnalazione
     * {@see TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE}, con il nome del condominio, perché l'agenda la legge anche chi non apre le segnalazioni.
     *
     * @return array{0: string, 1: string}
     */
    private static function titoloEDescrizioneDelPromemoria(Evento $compito, ?int $condominioId): array
    {
        if (self::tipoDelCompito($compito) === CompitoDellaSegnalazione::TIPO) {
            $nome = $condominioId === null ? '' : (string) Condominio::whereKey($condominioId)->value('nome');
            // Senza il nome (un compito senza condominio) il titolo si ferma prima del separatore.
            $titolo = strtr(self::TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE, [':condominio' => $nome]);
            $titolo = $nome !== '' ? $titolo : (string) preg_replace('/[\s·]+$/u', '', $titolo);

            return [Str::limit($titolo, 250, '…'), self::DESCRIZIONE_DEL_PROMEMORIA_DELLA_SEGNALAZIONE];
        }

        return [Str::limit('Ricordamelo: ' . $compito->title, 250, '…'), 'Il compito «' . $compito->title . '» resta nell\'agenda al suo giorno.'];
    }

    /**
     * Per la riga di ogni compito delle due Inbox: la risposta (perché il compito è tornato prima del giorno, il registro, il più recente prima) e se
     * «Ricordamelo» scrive un promemoria a parte. Due letture per la pagina, non una per compito.
     *
     * @param iterable<Evento> $compiti
     * @return array<int, array{risposta: array{tornato: ?string, registro: list<array<string, mixed>>}|null, promemoria_a_parte: bool}>
     */
    public static function perLeRighe(iterable $compiti): array
    {
        $compiti = collect($compiti);
        $risposte = $compiti->isEmpty() ? collect() : RispostaPromemoria::where('argomento', self::COMPITO)
            ->whereIn('evento_id', $compiti->pluck('id')->all())->get()->keyBy('evento_id');
        $promemoria = $risposte->pluck('promemoria_evento_id')->filter()->values();
        $ci = $promemoria->isEmpty() ? [] : array_flip(Evento::whereIn('id', $promemoria->all())->pluck('id')->all());
        // Le segnalazioni dei bonifici: finché sono in verifica, «Fatto» è spento.
        $segnalazioni = $compiti->filter(fn ($c) => self::eUnBonificoSegnalato($c))->map(fn ($c) => $c->meta['context']['related_event_id'] ?? null)->filter()->values();
        $stati = $segnalazioni->isEmpty() ? collect() : Evento::whereIn('id', $segnalazioni->all())->get(['id', 'meta'])->mapWithKeys(fn ($e) => [$e->id => $e->meta['status'] ?? null]);
        $righe = [];
        foreach ($compiti as $compito) {
            $r = $risposte->get($compito->id);
            $segnalazione = $compito->meta['context']['related_event_id'] ?? null;
            $righe[$compito->id] = [
                'risposta' => $r === null ? null : [
                    'tornato' => self::perCheETornato($r, $compito, $r->promemoria_evento_id !== null && isset($ci[$r->promemoria_evento_id])),
                    'registro' => RegistroDelGesto::perLaPagina($r->registro ?? []),
                ],
                'promemoria_a_parte' => self::promemoriaAParte($compito),
                // La scadenza, nel giorno dell'amministratore, per la riga della finestra di «Ricordamelo» quando il giorno scelto viene dopo.
                'scadenza' => in_array(self::tipoDelCompito($compito), self::COMPITI_CON_LA_SCADENZA, true)
                    ? $compito->start_time?->format('Y-m-d') : null,
                'fatto_spento' => self::eUnBonificoSegnalato($compito) && $segnalazione !== null && ($stati[$segnalazione] ?? null) === 'reported'
                    && app(SegnalazioneDelBonifico::class)->fattoSpento($compito) ? self::FRASE_PRIMA_L_INCASSO : null,
            ];
        }

        return $righe;
    }

    /** La frase della riga quando il compito è tornato prima del giorno del «Ricordamelo»; null altrimenti. La stessa regola di {@see Evento::scopeCompitiAperti()}. */
    private static function perCheETornato(RispostaPromemoria $r, Evento $compito, bool $promemoriaCe): ?string
    {
        if ($r->stato !== self::RICORDAMELO) {
            return null;
        }
        if (! $promemoriaCe) {
            return self::FRASE_PROMEMORIA_TOLTO;
        }
        $cosa = self::COMPITI_CHE_TORNANO_PRIMA[self::tipoDelCompito($compito)] ?? null;
        $il = $r->dati['aggiornato_il'] ?? null;
        $arrivo = $compito->meta['ultimo_arrivo'] ?? null;
        if ($cosa !== null && $il !== null && $arrivo !== null && (string) $arrivo > $il) {
            return 'È arrivato ' . $cosa . ' dopo il tuo «Ricordamelo»: il compito è tornato.';
        }
        $scadenza = $r->dati['scadenza'] ?? null;
        $inizio = $compito->start_time?->copy()->utc();
        if ($scadenza !== null && $inizio !== null && $inizio->format('Y-m-d H:i:s') !== $scadenza
            && $inizio->lt(self::inizioDiDomani())) {
            return self::FRASE_SCADENZA_CAMBIATA;
        }

        return null;
    }

    /**
     * Le risposte di un condominio per gli argomenti dati, per chiave, con gli eventi dell'agenda legati (`promemoria`, `agenda`) già letti.
     *
     * @param list<string> $argomenti
     * @return Collection<string, RispostaPromemoria>
     */
    public function delCondominio(int $condominioId, array $argomenti = [self::SEI_MESI, self::RENDICONTO]): Collection
    {
        return RispostaPromemoria::with(['promemoria:id,start_time', 'agenda:id,start_time'])
            ->where('condominio_id', $condominioId)->whereIn('argomento', $argomenti)->get()->keyBy('chiave');
    }

    /** Il giorno di un evento nel calendario dell'amministratore; null se l'evento non c'è più. */
    public static function giornoDellEvento(?Evento $evento): ?string
    {
        // L'agenda tiene l'ora così come si legge: il giorno è quello scritto, senza cambiare fuso.
        return $evento?->start_time?->format('Y-m-d');
    }

    /**
     * «Registra che hai agito» per una persona nella riga di un esercizio: il giorno dichiarato, la nota, e nei dati la cifra e le rate di oggi con il giorno
     * della registrazione (`registrato_il`), per sapere dopo se la persona torna.
     *
     * @param array{cifra: int, chiavi: list<string>} $riga
     * @param array<string, mixed> $altro
     */
    public function agito(Condominio $condominio, int $esercizioId, int $personaId, string $giorno, string $nota, ?User $utente, array $riga, array $altro = []): RispostaPromemoria
    {
        return DB::transaction(fn () => $this->agitoDentro($condominio, $esercizioId, $personaId, $giorno, $nota, $utente, $riga, $altro));
    }

    /**
     * «Fatto» sulla riga di un esercizio: una riga `fatto` per la riga, con le persone, e «hai agito» per ognuna (`da = fatto`), in una transazione. Per il
     * rendiconto la riga sola, senza persone.
     *
     * @param array<int, array{cifra: int, chiavi: list<string>}> $persone
     */
    public function fatto(Condominio $condominio, string $argomento, int $esercizioId, array $persone, string $giorno, string $nota, ?User $utente): RispostaPromemoria
    {
        return DB::transaction(function () use ($condominio, $argomento, $esercizioId, $persone, $giorno, $nota, $utente) {
            $riga = $this->scrivi(self::chiave($argomento, (int) $condominio->id, $esercizioId, null),
                ['condominio_id' => $condominio->id, 'argomento' => $argomento, 'esercizio_id' => $esercizioId],
                function (RispostaPromemoria $r) use ($persone, $giorno, $nota, $utente) {
                    $dati = ['giorno' => $giorno, 'persone' => array_map('intval', array_keys($persone))];
                    $tolto = $this->togliPromemoria($r);
                    $r->fill(['stato' => self::FATTO, 'giorno' => $giorno, 'nota' => $nota, 'dati' => $dati, 'aggiornata_da' => $utente?->id]);
                    $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], self::FATTO, $nota, $utente,
                        $dati + ($tolto === null ? [] : ['promemoria_tolto' => $tolto]))['registro'];
                });
            foreach ($persone as $id => $datiPersona) {
                $this->agitoDentro($condominio, $esercizioId, (int) $id, $giorno, $nota, $utente, $datiPersona, ['da' => self::FATTO]);
            }

            return $riga;
        });
    }

    /**
     * «Non serve» sulla riga di un esercizio, con il motivo; nei dati le persone aperte di quel giorno: se ne compare una nuova, il compito torna.
     *
     * @param array<string, mixed> $dati
     */
    public function nonServe(Condominio $condominio, string $argomento, int $esercizioId, string $motivo, ?User $utente, array $dati): RispostaPromemoria
    {
        return DB::transaction(fn () => $this->scrivi(self::chiave($argomento, (int) $condominio->id, $esercizioId, null),
            ['condominio_id' => $condominio->id, 'argomento' => $argomento, 'esercizio_id' => $esercizioId],
            function (RispostaPromemoria $r) use ($motivo, $utente, $dati) {
                $tolto = $this->togliPromemoria($r);
                $r->fill(['stato' => self::NON_SERVE, 'nota' => $motivo, 'dati' => $dati, 'aggiornata_da' => $utente?->id]);
                $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], self::NON_SERVE, $motivo, $utente,
                    $dati + ($tolto === null ? [] : ['promemoria_tolto' => $tolto]))['registro'];
            }));
    }

    /**
     * «Ricordamelo il…» sulla riga di un esercizio: il giorno va nell'agenda del condominio, come un promemoria che riporta alla riga. Un secondo «Ricordamelo»
     * sposta lo stesso evento; se non c'è più, ne scrive uno nuovo.
     */
    public function ricordamelo(Condominio $condominio, string $argomento, int $esercizioId, string $giorno, ?string $nota, User $utente, string $titolo, string $ritorno): RispostaPromemoria
    {
        return DB::transaction(fn () => $this->scrivi(self::chiave($argomento, (int) $condominio->id, $esercizioId, null),
            ['condominio_id' => $condominio->id, 'argomento' => $argomento, 'esercizio_id' => $esercizioId],
            function (RispostaPromemoria $r) use ($condominio, $giorno, $nota, $utente, $titolo, $ritorno) {
                $evento = $this->eventoNelGiorno($r, 'promemoria_evento_id', (int) $condominio->id, $giorno, $nota, $utente, $titolo, $ritorno);
                $dati = ['giorno' => $giorno, 'evento_id' => $evento->id];
                $r->fill(['stato' => self::RICORDAMELO, 'ricordami_il' => $giorno, 'promemoria_evento_id' => $evento->id, 'nota' => $nota, 'aggiornata_da' => $utente->id]);
                $r->dati = array_merge((array) ($r->dati ?? []), ['ricordamelo' => $dati]);
                $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], self::RICORDAMELO, (string) $nota, $utente, $dati)['registro'];
            }));
    }

    /** «Mettilo in agenda» per il rendiconto: un evento nell'agenda del condominio, uno solo; non chiude il compito e non cambia lo stato. */
    public function inAgenda(Condominio $condominio, int $esercizioId, string $giorno, ?string $nota, User $utente, string $titolo, string $ritorno): RispostaPromemoria
    {
        return DB::transaction(fn () => $this->scrivi(self::chiave(self::RENDICONTO, (int) $condominio->id, $esercizioId, null),
            ['condominio_id' => $condominio->id, 'argomento' => self::RENDICONTO, 'esercizio_id' => $esercizioId],
            function (RispostaPromemoria $r) use ($condominio, $giorno, $nota, $utente, $titolo, $ritorno) {
                $evento = $this->eventoNelGiorno($r, 'agenda_evento_id', (int) $condominio->id, $giorno, $nota, $utente, $titolo, $ritorno);
                $r->fill(['agenda_evento_id' => $evento->id, 'aggiornata_da' => $utente->id]);
                $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], 'in_agenda', (string) $nota, $utente,
                    ['giorno' => $giorno, 'evento_id' => $evento->id])['registro'];
            }));
    }

    /**
     * Il fascicolo per il legale di una persona, preparato oggi: sulla riga della persona (argomento `persona`, senza esercizio) il giorno, l'ultimo
     * fascicolo nei dati, e una riga `fascicolo` nel registro con ciò che il legale ha ricevuto (i file e lo stato delle impronte, le mancanze, le cifre, ciò
     * che è stato tolto). `$documenti` lega alla riga l'indice e il prospetto di quel giorno e rende i loro id, che la riga del registro tiene. Lo stato non
     * cambia: il fascicolo non chiude niente. Dentro la transazione di chi chiama, o in una sua.
     *
     * @param array<string, mixed> $dati
     * @param \Closure(RispostaPromemoria): list<int> $documenti
     */
    public function fascicolo(Condominio $condominio, int $personaId, string $giorno, ?User $utente, array $dati, \Closure $documenti): RispostaPromemoria
    {
        return DB::transaction(fn () => $this->scrivi(self::chiave(self::PERSONA, (int) $condominio->id, null, $personaId),
            ['condominio_id' => $condominio->id, 'argomento' => self::PERSONA, 'anagrafica_id' => $personaId],
            function (RispostaPromemoria $r) use ($giorno, $utente, $dati, $documenti) {
                $ids = $documenti($r);
                $r->fill(['giorno' => $giorno, 'aggiornata_da' => $utente?->id]);
                $r->dati = array_merge((array) ($r->dati ?? []), ['fascicolo' => ['giorno' => $giorno, 'utente' => (string) ($utente?->name ?? ''), 'cifra' => $dati['cifra'] ?? null,
                    'tolti' => $dati['tolti'] ?? null, 'documenti' => $ids]]);
                $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], 'fascicolo', '', $utente, $dati + ['documenti' => $ids])['registro'];
            }));
    }

    /**
     * «Riapri»: toglie lo stato e lascia la riga nel registro. Sulla riga di un esercizio chiusa da «Fatto» riapre anche le persone di quel «Fatto» (le loro
     * righe «hai agito» con `da = fatto` dello stesso giorno): chi ha registrato a parte resta chiuso. Un promemoria ancora nell'agenda si toglie. Dove non c'è
     * una risposta non scrive niente, e rende null.
     *
     * @return array{risposta: RispostaPromemoria, persone: int}|null
     */
    public function riapri(Condominio $condominio, string $argomento, int $esercizioId, ?int $personaId, ?User $utente): ?array
    {
        $chiave = self::chiave($argomento, (int) $condominio->id, $esercizioId, $personaId);
        if (! RispostaPromemoria::where('chiave', $chiave)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($condominio, $argomento, $esercizioId, $personaId, $utente, $chiave) {
            $prima = null;
            $r = $this->scrivi($chiave, ['condominio_id' => $condominio->id, 'argomento' => $argomento, 'esercizio_id' => $esercizioId, 'anagrafica_id' => $personaId],
                function (RispostaPromemoria $r) use ($utente, &$prima) {
                    $prima = $r->stato;
                    $tolto = $this->togliPromemoria($r);
                    $r->fill(['stato' => null, 'aggiornata_da' => $utente?->id]);
                    $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], 'riapri', '', $utente,
                        ['prima' => $prima] + ($tolto === null ? [] : ['promemoria_tolto' => $tolto]))['registro'];
                });
            $riaperte = 0;
            if ($prima === self::FATTO && $personaId === null && $argomento === self::SEI_MESI) {
                $giorno = (string) ($r->dati['giorno'] ?? '');
                foreach ((array) ($r->dati['persone'] ?? []) as $id) {
                    $p = RispostaPromemoria::where('chiave', self::chiave(self::SEI_MESI, (int) $condominio->id, $esercizioId, (int) $id))->lockForUpdate()->first();
                    if ($p === null || $p->stato !== self::AGITO || ($p->dati['da'] ?? null) !== self::FATTO || $p->giorno?->format('Y-m-d') !== $giorno) {
                        continue;
                    }
                    $p->fill(['stato' => null, 'aggiornata_da' => $utente?->id]);
                    $p->registro = RegistroDelGesto::registra(['registro' => $p->registro ?? []], 'riapri', '', $utente, ['prima' => self::AGITO, 'da' => self::FATTO])['registro'];
                    $p->save();
                    $riaperte++;
                }
            }

            return ['risposta' => $r, 'persone' => $riaperte];
        });
    }

    /**
     * Il promemoria di «Ricordamelo» si toglie dall'agenda quando la risposta chiude o riapre la riga: il giorno non serve più, e nell'agenda resterebbe il
     * promemoria di un compito chiuso. Rende il giorno del promemoria tolto (per il registro), o null se non c'era.
     */
    private function togliPromemoria(RispostaPromemoria $r): ?string
    {
        if ($r->promemoria_evento_id === null) {
            return null;
        }
        $evento = Evento::find($r->promemoria_evento_id);
        $giorno = self::giornoDellEvento($evento);
        $r->promemoria_evento_id = null;
        // Il compito spostato è il suo promemoria: resta nell'agenda al giorno scelto, e non c'è niente da togliere.
        if ($evento !== null && (int) $evento->id === (int) $r->evento_id) {
            return null;
        }
        $evento?->delete();

        return $giorno;
    }

    /** Chiude il compito con la risposta, dentro una transazione già aperta. */
    private function chiudiIlCompito(Evento $compito, string $stato, ?string $nota, ?User $utente, array $dati): RispostaPromemoria
    {
        $r = $this->scriviDelCompito($compito, function (RispostaPromemoria $r) use ($stato, $nota, $utente, $dati) {
            $tolto = $this->togliPromemoria($r);
            $r->fill(['stato' => $stato, 'nota' => $nota, 'aggiornata_da' => $utente?->id]);
            $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], $stato, (string) $nota, $utente,
                $dati + ($tolto === null ? [] : ['promemoria_tolto' => $tolto]))['registro'];
        });
        $compito->update(['is_completed' => true, 'completed_at' => now()]);

        return $r;
    }

    /**
     * Il rifiuto del bonifico segnalato, come lo scriveva «Rifiuta»: sull'evento del condòmino collegato al compito. Rende il suo id, o null se il compito
     * non ne ha uno.
     */
    private function rifiutaLaSegnalazione(Evento $compito, string $motivo): ?int
    {
        $id = $compito->meta['context']['related_event_id'] ?? null;
        $evento = $id ? Evento::find($id) : null;
        if ($evento === null) {
            return null;
        }
        $meta = $evento->meta ?? [];
        $meta['status'] = 'rejected';
        $meta['rejection_reason'] = $motivo;
        $meta['rejected_at'] = now()->toIso8601String();
        $evento->update(['meta' => $meta]);

        return (int) $evento->id;
    }

    /** La riga della risposta di un compito: il condominio, il titolo e il tipo copiati al primo gesto. */
    private function scriviDelCompito(Evento $compito, \Closure $gesto): RispostaPromemoria
    {
        // Una risposta con questa chiave e senza l'evento è di un compito che non c'è più (la potatura, «Emettere rata» all'emissione): un compito nuovo che
        // riprende il suo id (su MySQL 5.7 il contatore riparte da MAX(id) + 1 dopo un riavvio) non la eredita. Le si cambia la chiave, e resta con il suo titolo.
        $vecchia = RispostaPromemoria::where('chiave', self::chiaveDelCompito((int) $compito->id))->whereNull('evento_id')->lockForUpdate()->first();
        if ($vecchia !== null) {
            $vecchia->update(['chiave' => self::chiaveDelCompito((int) $compito->id) . '|' . $vecchia->id]);
        }

        return $this->scrivi(self::chiaveDelCompito((int) $compito->id), [
            'condominio_id' => $compito->condomini()->value('condomini.id'),
            'argomento' => self::COMPITO,
            'evento_id' => $compito->id,
            'titolo' => Str::limit((string) $compito->title, 250, '…'),
            'tipo_compito' => Str::limit(self::tipoDelCompito($compito), 60, ''),
        ], $gesto);
    }

    /** Una nota vuota non è una nota. */
    private static function notaPulita(?string $nota): ?string
    {
        $nota = trim((string) $nota);

        return $nota === '' ? null : $nota;
    }

    /** «Hai agito» dentro una transazione già aperta. */
    private function agitoDentro(Condominio $condominio, int $esercizioId, int $personaId, string $giorno, string $nota, ?User $utente, array $riga, array $altro): RispostaPromemoria
    {
        return $this->scrivi(self::chiave(self::SEI_MESI, (int) $condominio->id, $esercizioId, $personaId),
            ['condominio_id' => $condominio->id, 'argomento' => self::SEI_MESI, 'esercizio_id' => $esercizioId, 'anagrafica_id' => $personaId],
            function (RispostaPromemoria $r) use ($giorno, $nota, $utente, $riga, $altro) {
                $dati = ['cifra' => (int) $riga['cifra'], 'chiavi' => array_values($riga['chiavi']), 'registrato_il' => DateHelper::oggiUtente()] + $altro;
                $r->fill(['stato' => self::AGITO, 'giorno' => $giorno, 'nota' => $nota, 'dati' => $dati, 'aggiornata_da' => $utente?->id]);
                $r->registro = RegistroDelGesto::registra(['registro' => $r->registro ?? []], self::AGITO, $nota, $utente, ['giorno' => $giorno] + $dati)['registro'];
            });
    }

    /** La riga con la sua chiave, scritta sotto il lucchetto: prima la riga vuota se non c'è, poi la lettura, poi il gesto. */
    private function scrivi(string $chiave, array $base, \Closure $gesto): RispostaPromemoria
    {
        DB::table('risposte_promemoria')->insertOrIgnore([$base + ['chiave' => $chiave, 'created_at' => now(), 'updated_at' => now()]]);
        $r = RispostaPromemoria::where('chiave', $chiave)->lockForUpdate()->firstOrFail();
        $gesto($r);
        $r->save();

        return $r;
    }

    /**
     * L'evento della risposta in quella colonna, spostato al giorno; se non c'è più, uno nuovo. La descrizione è la nota, o la frase di serie.
     *
     * @param array<string, mixed> $contesto
     */
    private function eventoNelGiorno(RispostaPromemoria $r, string $colonna, ?int $condominioId, string $giorno, ?string $nota, User $utente, string $titolo, string $ritorno,
        string $descrizioneDiSerie = 'Dal riquadro «Sei mesi per agire» della dashboard del condominio.', array $contesto = []): Evento
    {
        $inizio = self::inizioDelGiorno($giorno);
        $evento = $r->{$colonna} === null ? null : Evento::find($r->{$colonna});
        $descrizione = trim((string) $nota) !== '' ? trim((string) $nota) : $descrizioneDiSerie;
        if ($evento !== null) {
            $evento->update(['start_time' => $inizio, 'end_time' => $inizio->copy()->addHour(), 'title' => $titolo, 'description' => $descrizione]);

            return $evento;
        }

        return InboxService::creaPromemoria($titolo, $descrizione, $inizio, (int) $utente->id, $condominioId, $ritorno, ['risposta_id' => $r->id] + $contesto);
    }

    /**
     * Le 09:00 del giorno, scritte come le scrive il resto dell'agenda (`InboxService` per i compiti delle rate, gli eventi scritti a mano): l'ora così come si
     * legge, senza la conversione da Roma, che d'inverno la sposterebbe di un'ora e d'estate di due.
     */
    private static function inizioDelGiorno(string $giorno): Carbon
    {
        return Carbon::parse($giorno . ' ' . self::ORA_DEL_PROMEMORIA, (string) config('app.timezone'));
    }

    /**
     * La mezzanotte di domani nel giorno dell'amministratore, nella forma in cui l'agenda scrive le ore (così come si leggono): da lì in poi un giorno non è
     * ancora arrivato. La regola dei compiti in attesa la usa per il giorno del promemoria e per la scadenza.
     */
    public static function inizioDiDomani(): Carbon
    {
        return Carbon::parse(Carbon::now(self::fuso())->addDay()->toDateString() . ' 00:00:00', (string) config('app.timezone'));
    }

    private static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
