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
 * ## Cosa NON fa
 *
 * Non pulisce l'errore di un campo diverso da quello toccato: se il rifiuto era su due campi e ne
 * correggi uno, l'altro resta, che è quello che deve succedere. E non rivalida niente lato client —
 * l'ultima parola resta del server.
 *
 * @param form L'oggetto restituito da `useForm()` di Inertia.
 */
export function usePuliziaErrori(form: {
  data: () => Record<string, unknown>;
  errors: Record<string, string>;
  clearErrors: (...campi: string[]) => void;
}): void {
  let precedente: Record<string, unknown> = { ...form.data() };

  watch(
    // La copia superficiale è voluta: restituendo un oggetto nuovo a ogni giro, Vue rileva il
    // cambiamento di qualunque campo senza bisogno di `deep`, e leggere tutte le chiavi qui dentro
    // è ciò che le rende tracciate.
    () => ({ ...form.data() }),
    (adesso) => {
      const daPulire = Object.keys(form.errors).filter(
        (campo) => adesso[campo] !== precedente[campo],
      );

      precedente = adesso;

      if (daPulire.length) {
        form.clearErrors(...daPulire);
      }
    },
  );
}
