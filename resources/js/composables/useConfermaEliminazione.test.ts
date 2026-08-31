import { describe, expect, it, vi } from 'vitest';
import { useConfermaEliminazione } from './useConfermaEliminazione';

/**
 * 1.11.0-beta.9 — La conferma di eliminazione non deve perdere per strada cosa si sta eliminando.
 *
 * ## Il difetto vero da cui nasce questo file
 *
 * Due schermate scritte nella stessa beta — le categorie di fornitore e i documenti dell'anagrafica
 * — usavano una sola variabile per due cose: il **dato** da eliminare e l'**interruttore** della
 * finestra di conferma. La finestra era aperta quando il dato non era `null`, e chiudendosi lo
 * azzerava.
 *
 * `AlertDialogAction` chiude il dialogo al clic, e la chiusura arriva **prima** dell'evento di
 * conferma: quando il gestore leggeva il dato ci trovava `null`, la guardia in cima usciva, e **non
 * partiva nessuna richiesta**. A schermo: si conferma, la finestra sparisce, e la riga resta dov'è.
 * Nessun errore, nessun messaggio, nessuna traccia nei log.
 *
 * ⚠️ **Nessun test lato server poteva vederlo.** La rotta e il controller funzionavano
 * perfettamente, e i test Pest li chiamano direttamente: erano tutti verdi mentre la funzione, a
 * video, non faceva niente. L'ha trovato una prova a mano, cliccando.
 *
 * È esattamente la ragione per cui la suite JavaScript esiste dalla beta.35: **i difetti che vivono
 * nell'interfaccia si provano nell'interfaccia**. Qui la logica è stata tirata fuori dai due
 * template apposta per poterla provare senza montarli.
 *
 * ## Cosa questo file NON copre
 *
 * Non monta `ConfirmDialog` né le due pagine: verifica il **contratto** del composable, cioè le
 * regole che i template devono rispettare. Che i template lo usino davvero — e non riscrivano la
 * logica a mano — è cosa che si vede leggendoli, e i loro commenti lo dichiarano.
 */
describe('useConfermaEliminazione', () => {
    it('registra cosa si sta per eliminare e apre la finestra', () => {
        const c = useConfermaEliminazione<{ id: number }>();

        expect(c.daEliminare.value).toBeNull();
        expect(c.confermaAperta.value).toBe(false);

        c.chiedi({ id: 7 });

        expect(c.daEliminare.value).toEqual({ id: 7 });
        expect(c.confermaAperta.value).toBe(true);
    });

    it('⚠️ chiudere la finestra NON perde il dato: è il difetto della beta.9', () => {
        const c = useConfermaEliminazione<{ id: number }>();

        c.chiedi({ id: 7 });
        c.suCambioApertura(false);

        // La riga che conta di tutto il file. Se qualcuno rimettesse l'azzeramento nel gestore di
        // chiusura — che è la cosa che viene naturale scrivere — questa aspettativa diventa rossa
        // prima che il difetto arrivi a video.
        expect(c.daEliminare.value).toEqual({ id: 7 });
        expect(c.confermaAperta.value).toBe(false);
    });

    it('⚠️ conferma dopo la chiusura esegue comunque l\'azione, con il dato giusto', () => {
        const c = useConfermaEliminazione<{ id: number }>();
        const azione = vi.fn();

        c.chiedi({ id: 7 });

        // È **l'ordine vero degli eventi**: `AlertDialogAction` chiude e poi emette `confirm`.
        c.suCambioApertura(false);
        c.conferma(azione);

        expect(azione).toHaveBeenCalledTimes(1);
        expect(azione).toHaveBeenCalledWith({ id: 7 });
    });

    it('senza niente da eliminare non esegue niente', () => {
        const c = useConfermaEliminazione<{ id: number }>();
        const azione = vi.fn();

        c.conferma(azione);

        expect(azione).not.toHaveBeenCalled();
        expect(c.inCorso.value).toBe(false);
    });

    it('un secondo clic mentre la richiesta è in corso non ne manda un\'altra', () => {
        const c = useConfermaEliminazione<{ id: number }>();
        const azione = vi.fn();

        c.chiedi({ id: 7 });
        c.conferma(azione);
        c.conferma(azione);

        // Due DELETE sullo stesso id: la seconda risponderebbe 404 e mostrerebbe un errore su
        // un'operazione che invece era riuscita.
        expect(azione).toHaveBeenCalledTimes(1);
    });

    it('conclusa ripulisce tutto, e da lì si può ricominciare', () => {
        const c = useConfermaEliminazione<{ id: number }>();
        const azione = vi.fn();

        c.chiedi({ id: 7 });
        c.conferma(azione);
        c.conclusa();

        expect(c.daEliminare.value).toBeNull();
        expect(c.confermaAperta.value).toBe(false);
        expect(c.inCorso.value).toBe(false);

        c.chiedi({ id: 9 });
        c.conferma(azione);

        expect(azione).toHaveBeenLastCalledWith({ id: 9 });
    });

    it('si può registrare l\'elemento SENZA aprire la conferma', () => {
        // Serve alla pagina delle categorie: se qualche fornitore usa la categoria, al posto della
        // conferma si apre la finestra che spiega **chi** la usa — e quella finestra ha bisogno
        // dell'elemento lo stesso.
        const c = useConfermaEliminazione<{ id: number; usata: boolean }>();

        c.chiedi({ id: 7, usata: true }, false);

        expect(c.daEliminare.value).toEqual({ id: 7, usata: true });
        expect(c.confermaAperta.value).toBe(false);
    });
});
