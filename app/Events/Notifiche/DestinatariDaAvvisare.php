<?php

namespace App\Events\Notifiche;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un gruppo di destinatari va avvisato, e il motivo dice quale avviso.
 *
 * ## Perché un evento solo e non sei
 *
 * Gli eventi di creazione sono uno per entità e per lato (`NotifyUserOfCreatedComunicazione`,
 * `NotifyAdminOfCreatedSegnalazione`, …): sei classi che differiscono per il tipo del campo. È una
 * forma legittima, ed è quella che c'era.
 *
 * Qui però il caso è un altro: la modifica riguarda **le stesse tre entità con la stessa logica**,
 * e la misura fatta aprendo la beta.64 dice perché conta — `store()` lanciava un evento e
 * `update()` no in **sei controller su sei**, e il changelog registra già due difetti della stessa
 * identica forma («il riparto manuale arrivava dal form solo alla creazione», «il controllo di
 * capienza era collegato solo in creazione»). Sei classi nuove sarebbero sei posti in cui
 * dimenticarsene il prossimo giro.
 *
 * Il motivo per cui un evento solo qui non è un accentramento cieco: il listener che lo riceve
 * **solleva** davanti a un modello che non conosce, quindi un'entità nuova non può cadere nel
 * silenzio — che è esattamente come sono nati i difetti che questa beta corregge.
 */
class DestinatariDaAvvisare
{
    use Dispatchable, SerializesModels;

    /** L'oggetto di cui si avvisa: una comunicazione, una segnalazione o un documento. */
    public Model $oggetto;

    /**
     * Gli id delle anagrafiche da avvisare.
     *
     * ⚠️ Si passano gli **id** e non le anagrafiche già caricate perché l'evento finisce in coda e
     * viene serializzato: una collezione di modelli attraverserebbe la serializzazione portandosi
     * dietro uno stato vecchio di minuti. Gli id si rileggono, e chi nel frattempo è stato
     * cancellato semplicemente non si trova.
     *
     * @var array<int, int>
     */
    public array $anagraficaIds;

    /**
     * `nuovo` — questa gente l'oggetto non l'ha mai ricevuto: le arriva l'avviso di creazione,
     * perché per loro è nuovo davvero.
     *
     * `aggiornato` — l'aveva già ricevuto e l'amministratore ha chiesto di segnalare la modifica:
     * le arriva un avviso diverso. Dire «nuova comunicazione» a chi l'ha già letta sarebbe falso.
     */
    public string $motivo;

    public function __construct(Model $oggetto, array $anagraficaIds, string $motivo)
    {
        $this->oggetto = $oggetto;
        $this->anagraficaIds = array_values(array_unique($anagraficaIds));
        $this->motivo = $motivo;
    }
}
