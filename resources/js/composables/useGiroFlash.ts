import { onUnmounted, ref, type Ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Un numero che cresce a ogni visita Inertia riuscita: da usare come `:key` dell'`Alert` del messaggio flash (1.11.0-beta.37,
 * giro di verifica della Fase 1-bis, L-R4).
 *
 * Una pagina che resta montata fra due azioni — la scheda dello storico annulla il conguaglio e poi il passaggio — non
 * rimonta il suo `Alert`, e l'`Alert` accende il messaggio e il timer solo quando si monta: il secondo messaggio non
 * compariva, oppure ereditava il timer del primo (un errore che si chiudeva da solo). Il testo non basta come chiave: due
 * annullamenti a cascata danno due messaggi identici, e Inertia 3 riusa l'oggetto flash uguale al precedente.
 *
 * Le altre pagine con lo stesso schema restano com'erano: il cambio va fatto a parte, pagina per pagina.
 */
export function useGiroFlash(): Ref<number> {
    const giro = ref(0);
    onUnmounted(router.on('success', () => {
        giro.value++;
    }));

    return giro;
}
