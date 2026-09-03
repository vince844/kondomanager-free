import { watch } from 'vue';

/**
 * Toglie l'errore di un campo appena quel campo cambia valore.
 *
 * ## Il difetto che chiude
 *
 * Inertia tiene `form.errors` finché non arriva la risposta del salvataggio successivo. Quindi,
 * dopo un rifiuto, l'amministratore corregge il campo e **la riga rossa resta lì** — sotto il campo
 * e nel riquadro di riepilogo in testa al modulo. Ha corretto e il programma continua a dirgli che
 * ha sbagliato: è il difetto gemello di quello da cui nasce questa beta, in verso opposto — prima
 * il programma taceva quando avrebbe dovuto parlare, adesso parlerebbe quando non ha più niente da
 * dire.
 *
 * ## Perché sul cambiamento e non sul focus
 *
 * La convenzione già in casa è `@focus="form.clearErrors('campo')"` (`BuildingsEdit.vue`), e su una
 * schermata con quattro campi funziona. Qui i campi validati sono trentanove: appendere l'attributo
 * a mano a ognuno significa che il prossimo campo aggiunto non ce l'avrà, e nessuno se ne accorgerà
 * — cioè la stessa forma di difetto degli `<InputError>` mancanti. Legandola al **valore**, vale per
 * tutti i campi, compresi quelli che non esistono ancora.
 *
 * Il focus ha anche un difetto suo: cancella l'errore a chi entra nel campo e ne esce **senza aver
 * corretto niente**, e a quel punto lo schermo dice che va tutto bene mentre il salvataggio verrà
 * rifiutato di nuovo.
 *
 * ## I campi annidati (`righe.0.conto_id`)
 *
 * ⚠️ **La prima stesura puliva solo le chiavi di primo livello, e su un modulo con righe ripetute
 * non puliva niente.** Confrontava `adesso[campo]` con `precedente[campo]` prendendo `campo` alla
 * lettera: per una chiave come `righe.0.conto_id` quella lettura dà `undefined` da entrambe le
 * parti — non esiste nessuna proprietà che si chiami così — quindi il confronto era sempre «uguale»
 * e l'errore restava a schermo per sempre. Trovato il 03/09/2026 da Vincenzo sulla registrazione
 * fattura: sceglieva il capitolo mancante e la riga rossa non spariva, né sotto il campo né nel
 * riepilogo in testa.
 *
 * Le chiavi di Laravel per gli array sono percorsi puntati, quindi vanno **risolti** dentro i dati.
 * E il confronto non può basarsi su una copia superficiale: `{ ...form.data() }` copia il
 * riferimento all'array `righe`, quindi «prima» e «dopo» puntano allo stesso oggetto e risultano
 * identici anche dopo la modifica. Si fotografa il valore **in fondo a ogni percorso in errore**,
 * che è anche ciò che rende tracciate quelle proprietà annidate per Vue — senza bisogno di `deep`,
 * che rileggerebbe l'intero modulo a ogni battuta di tasti.
 *
 * ## Cosa NON fa
 *
 * Non pulisce l'errore di un campo diverso da quello toccato: se il rifiuto era su due campi e ne
 * correggi uno, l'altro resta, che è quello che deve succedere. E non rivalida niente lato client —
 * l'ultima parola resta del server.
 *
 * @param form L'oggetto restituito da `useForm()` di Inertia.
 */

/**
 * Il valore in fondo a un percorso puntato (`righe.0.conto_id`), o `undefined` se il percorso non
 * esiste. Gli indici degli array funzionano perché in JavaScript `array['0']` è `array[0]`.
 */
function valoreAlPercorso(dati: unknown, percorso: string): unknown {
  return percorso.split('.').reduce<unknown>(
    (nodo, pezzo) => (nodo == null ? undefined : (nodo as Record<string, unknown>)[pezzo]),
    dati,
  );
}

export function usePuliziaErrori(form: {
  data: () => Record<string, unknown>;
  errors: Record<string, string>;
  clearErrors: (...campi: string[]) => void;
}): void {
  let precedente: Record<string, unknown> | null = null;

  watch(
    // Si leggono **solo i percorsi che hanno un errore aperto**: è ciò che li rende tracciati da
    // Vue (comprese le proprietà annidate) e, quando non c'è nessun errore, questo getter non legge
    // praticamente niente — nessun costo per il caso normale, che è la stragrande maggioranza.
    () => {
      const dati = form.data();
      const istantanea: Record<string, unknown> = {};
      for (const campo of Object.keys(form.errors)) {
        istantanea[campo] = valoreAlPercorso(dati, campo);
      }
      return istantanea;
    },
    (adesso) => {
      // Al primo giro non c'è un «prima» con cui confrontare: si fotografa e basta, altrimenti
      // ogni errore appena arrivato dal server verrebbe pulito subito dopo essere comparso.
      if (precedente === null) {
        precedente = adesso;
        return;
      }

      // ⚠️ **Se sono cambiate le CHIAVI, l'istantanea non è confrontabile: si rifotografa
      // e basta.** Un percorso come `righe.1.conto_id` non nomina una voce, nomina la voce
      // che in quel momento sta in seconda posizione: quando le voci scalano — perché
      // l'utente ne ha cancellata una — le chiavi vengono rinumerate da chi le possiede, e
      // il valore vecchio di una chiave non ha più niente a che vedere con quello nuovo.
      // Confrontarli comunque faceva sparire un errore ancora valido nello stesso istante
      // in cui era stato spostato al posto giusto (Fase 1-bis, reperto 16).
      //
      // Vale anche nel verso ovvio: quando il server manda errori nuovi le chiavi cambiano,
      // e lì rifotografare è già ciò che si vuole — è la stessa ragione della guardia sul
      // primo giro qui sopra.
      const chiaviOra = Object.keys(form.errors);
      const chiaviPrima = Object.keys(precedente);
      const stesseChiavi =
        chiaviOra.length === chiaviPrima.length && chiaviOra.every((campo) => campo in precedente!);

      if (!stesseChiavi) {
        precedente = adesso;
        return;
      }

      const daPulire = chiaviOra.filter(
        (campo) => campo in precedente! && adesso[campo] !== precedente![campo],
      );

      precedente = adesso;

      if (daPulire.length) {
        form.clearErrors(...daPulire);
      }
    },
    // `flush: 'post'` non serve e `immediate` nemmeno: il primo scatto utile è quello che segue
    // l'arrivo degli errori, e lo si usa per fotografare (vedi sopra).
  );
}
