<?php

namespace App\Services\Gestionale;

use App\Exceptions\Pagamenti\VoceCommissioneNonAmmessaException;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Builder;

/**
 * La voce della commissione del pagamento al fornitore: quali voci si possono scegliere, quale si propone, e il controllo della scelta.
 *
 * La commissione va sul mastro della voce, con la voce, come la regolazione immediata (`RegistraRegolazioneImmediataAction`): una regola sola
 * per la stessa spesa, che così entra nel consuntivo della voce. La voce la sceglie l'amministratore, perché la ripartizione delle spese
 * bancarie è del condominio (millesimi generali, o parti uguali secondo il regolamento); il programma la propone e non la impone.
 *
 * - **Le voci ammesse** sono le voci di spesa del condominio senza sottovoci e con il mastro; per un pagamento, quelle della sua gestione (le
 *   fatture di un pagamento hanno una gestione sola).
 * - **La proposta** è la voce dell'ultima commissione registrata nel condominio, in quella gestione, da chiunque, in un pagamento non stornato (un
 *   pagamento stornato per rifarlo con la voce giusta non deve riproporre quella sbagliata): come il bollo della ricevuta
 *   (`AvvisiCompensazione::bolloRicordato`), ma per condominio e gestione e non per utente, perché la voce della spesa è del condominio.
 *   Se quella voce non c'è più, o non è più ammessa, la proposta è vuota: il campo resta vuoto e obbligatorio, mai una voce sbagliata.
 * - **Nessun controllo di budget**: la commissione si registra anche oltre il preventivo della voce, come la regolazione.
 * - **Un pagamento scritto prima senza voce** (la commissione sulle «Spese bancarie» di sistema, senza voce) si può modificare senza dargliela, finché
 *   la commissione non cambia: la riga si riscrive com'era. Se la commissione cambia, o se il pagamento aveva una voce, la voce si sceglie.
 */
class VoceDellaCommissione
{
    public const SENZA_VOCE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';

    /** La via d'uscita per una voce di un'altra gestione: la pagina la dice sotto il campo, il rifiuto in fondo alla sua frase. */
    public const ALTRA_GESTIONE = 'Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e '
        .'registrala con una regolazione immediata.';

    /** Le voci di spesa del condominio su cui una commissione si può scrivere. */
    public function vociAmmesse(int $condominioId): Builder
    {
        return Conto::query()
            ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $condominioId)->select('id'))
            ->whereIn('tipo', Conto::TIPI_DI_SPESA)
            ->whereDoesntHave('sottoconti')
            ->whereNotNull('conto_contabile_id')
            ->visibili();
    }

    /**
     * Le voci per il modulo, con la gestione del loro piano dei conti: la pagina mostra solo quelle della gestione del pagamento.
     *
     * @return list<array{id: int, nome: string, label: string, gestione_id: int}>
     */
    public function elenco(int $condominioId): array
    {
        return $this->vociAmmesse($condominioId)
            ->with(['parent:id,nome', 'pianoConto:id,gestione_id'])
            ->get(['id', 'nome', 'parent_id', 'piano_conto_id'])
            ->map(fn (Conto $v) => [
                'id' => (int) $v->id,
                'nome' => (string) $v->nome,
                'label' => $v->parent ? $v->parent->nome.' — '.$v->nome : (string) $v->nome,
                'gestione_id' => (int) $v->pianoConto->gestione_id,
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * La voce proposta per ogni gestione del condominio.
     *
     * @return list<array{gestione_id: int, voce_id: int|null}>
     */
    public function proposte(int $condominioId): array
    {
        return Gestione::query()->where('condominio_id', $condominioId)->orderBy('id')->pluck('id')
            ->map(fn ($id) => ['gestione_id' => (int) $id, 'voce_id' => $this->proposta($condominioId, (int) $id)])
            ->values()
            ->all();
    }

    /** La voce dell'ultima commissione registrata nel condominio in quella gestione, se è ancora una voce ammessa di quella gestione. */
    public function proposta(int $condominioId, int $gestioneId): ?int
    {
        $scritturaId = PagamentoFornitore::query()
            ->where('condominio_id', $condominioId)
            ->whereNull('pagamento_padre_id')
            ->where('stato', '!=', \App\Enums\StatoPagamentoFornitore::STORNATO->value)
            ->where('importo_commissione', '>', 0)
            ->whereHas('scrittura', fn ($q) => $q->where('gestione_id', $gestioneId))
            ->orderByDesc('id')
            ->value('scrittura_contabile_id');

        $voceId = $scritturaId ? $this->voceDellaScrittura((int) $scritturaId) : null;
        if ($voceId === null) {
            return null;
        }

        $ammessa = $this->vociAmmesse($condominioId)
            ->whereKey($voceId)
            ->whereIn('piano_conto_id', PianoConto::query()->where('gestione_id', $gestioneId)->select('id'))
            ->exists();

        return $ammessa ? $voceId : null;
    }

    /**
     * La voce della commissione di una scrittura di pagamento: la riga DARE con la voce (in un pagamento è solo la commissione). Nulla per una
     * commissione scritta senza voce, o la cui voce è stata cancellata.
     */
    public function voceDellaScrittura(int $scritturaId): ?int
    {
        $voce = RigaScrittura::query()
            ->where('scrittura_id', $scritturaId)
            ->where('tipo_riga', 'dare')
            ->whereNotNull('voce_spesa_id')
            ->orderBy('id')
            ->value('voce_spesa_id');

        return $voce === null ? null : (int) $voce;
    }

    /**
     * Il conto su cui sta la commissione di un pagamento scritto prima senza voce, se la modifica la può lasciare com'era: la commissione è più di zero e
     * non cambia (stessa cifra di quella registrata), e la sua riga sta sulle «Spese bancarie» di sistema, senza voce. Altrimenti nulla: la voce si sceglie.
     */
    public function contoSenzaVoce(PagamentoFornitore $pagamento, int $commissioni): ?int
    {
        if ($commissioni <= 0 || $commissioni !== (int) $pagamento->importo_commissione) {
            return null;
        }

        $conto = RigaScrittura::query()
            ->where('righe_scritture.scrittura_id', $pagamento->scrittura_contabile_id)
            ->where('righe_scritture.tipo_riga', 'dare')
            ->whereNull('righe_scritture.voce_spesa_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'righe_scritture.conto_contabile_id')
            ->where('cc.ruolo', 'spese_bancarie')
            ->orderBy('righe_scritture.id')
            ->value('righe_scritture.conto_contabile_id');

        return $conto === null ? null : (int) $conto;
    }

    /** La modifica può lasciare la commissione senza voce, com'era (vedi {@see contoSenzaVoce}). */
    public function puoRestareSenzaVoce(PagamentoFornitore $pagamento, int $commissioni): bool
    {
        return $this->contoSenzaVoce($pagamento, $commissioni) !== null;
    }

    /**
     * Il controllo della scelta: con una commissione più di zero la voce c'è, è del condominio, è di spesa, è della gestione del pagamento,
     * ha il mastro e non ha sottovoci. Senza commissione la voce non serve (e non si usa).
     *
     * @throws VoceCommissioneNonAmmessaException
     */
    public function verifica(int $condominioId, int $gestioneId, mixed $voceId, int $commissioni): ?Conto
    {
        if ($commissioni <= 0) {
            return null;
        }

        if ($voceId === null || $voceId === '' || filter_var($voceId, FILTER_VALIDATE_INT) === false) {
            throw new VoceCommissioneNonAmmessaException(self::SENZA_VOCE);
        }

        $voce = Conto::with('pianoConto.gestione')->find((int) $voceId);

        if (! $voce || ! $voce->pianoConto || (int) $voce->pianoConto->condominio_id !== $condominioId) {
            throw new VoceCommissioneNonAmmessaException('La voce scelta per la commissione non appartiene a questo condominio.');
        }

        if (Conto::segnoDelTipo((string) $voce->tipo) !== 1) {
            throw new VoceCommissioneNonAmmessaException("«{$voce->nome}» è una voce di entrata: la commissione è una spesa. Scegli una voce di spesa.");
        }

        if ((int) $voce->pianoConto->gestione_id !== $gestioneId) {
            $gestione = Gestione::find($gestioneId)?->nome ?? '—';

            throw new VoceCommissioneNonAmmessaException(
                "«{$voce->nome}» è di un'altra gestione: la commissione va su una voce della gestione del pagamento («{$gestione}»). ".self::ALTRA_GESTIONE
            );
        }

        if (! $this->vociAmmesse($condominioId)->whereKey($voce->id)->exists()) {
            throw new VoceCommissioneNonAmmessaException(
                "«{$voce->nome}» non può ricevere la commissione: deve essere una voce senza sottovoci, con un conto contabile."
            );
        }

        return $voce;
    }
}
