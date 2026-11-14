<script setup lang="ts">
/**
 * L'ultimo invio per email del foglio di una persona, accanto al suo nome nella scheda «Conguaglio»: lo stato del suo ultimo foglio («Foglio inviato
 * il … alle …, consegna non provata», «in coda», «non partito: …» con l'errore in chiaro, o «in parte» con lo stato di ogni indirizzo), il PDF del
 * foglio (quello conservato) e, per un invio non partito, «Invia di nuovo, con le cifre di oggi». Il dato arriva con la pagina
 * (`invio_fogli.ultimi`); dopo un invio o un rinvio la scheda lo rilegge dal server ({@link rileggiStato}), e una pagina nuova torna ai suoi dati.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import {
    CON_LE_CIFRE_DI_OGGI, chiaveDegliInvii, fraseDellErroreDiInvio, rileggiStato, testoDelRinvio, testoUltimoInvio, ultimoDi, type UltimoInvio,
} from '@/lib/gestionale/rendiconto/invioDeiFogli';

const props = defineProps<{ condominioId: number; esercizioId: number; persona: number }>();

const { generateRoute } = usePermission();
const page = usePage<{
    invio_fogli?: { ultimi?: Record<number, UltimoInvio> | UltimoInvio[] };
    conguaglio?: { righe?: Array<{ anagrafica_id: number; subtotale: boolean }> };
}>();
const chiave = computed(() => chiaveDegliInvii(props.condominioId, props.esercizioId));
const ultimo = computed<UltimoInvio | null>(() => ultimoDi(chiave.value, page.props.invio_fogli, props.persona));
const base = () => ({ condominio: props.condominioId, esercizio: props.esercizioId });
const hrefPdf = computed(() => (ultimo.value ? route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.invio.pdf'), { ...base(), invio: ultimo.value.pdf }) : ''));

const inCorso = ref(false);
const esito = ref<string[]>([]);
/** Le persone della pagina, e questa: quelle di cui si rilegge lo stato. */
const personeDellaPagina = () => [...new Set([...(page.props.conguaglio?.righe ?? []).filter((x) => !x.subtotale).map((x) => x.anagrafica_id), props.persona])];

async function rimanda() {
    inCorso.value = true;
    esito.value = [];
    try {
        const r = (await axios.post(route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.invio.riprova'), base()), { anagrafica_id: props.persona })).data;
        esito.value = testoDelRinvio(r);
        await rileggiStato(route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.invio.stato'), base()), personeDellaPagina(), chiave.value, page.props.invio_fogli)
            .catch(() => null);
    } catch (e) {
        // Senza una risposta il foglio può essere partito: lo stato si rilegge, e la frase dice di guardarlo.
        esito.value = [fraseDellErroreDiInvio(e)];
        await rileggiStato(route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.invio.stato'), base()), personeDellaPagina(), chiave.value, page.props.invio_fogli)
            .catch(() => null);
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <span v-if="ultimo" class="block text-[11px]" :class="ultimo.da_rimandare ? 'text-amber-800 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400'" data-test="ultimo-invio">
        {{ testoUltimoInvio(ultimo) }}
        <a :href="hrefPdf" target="_blank" class="underline decoration-dotted underline-offset-4" data-test="foglio-inviato">il PDF del foglio</a>
        <button v-if="ultimo.da_rimandare" type="button" class="ml-2 font-semibold underline" :disabled="inCorso" data-test="invia-di-nuovo" @click="rimanda">Invia di nuovo, {{ CON_LE_CIFRE_DI_OGGI }}</button>
        <span v-for="(riga, i) in esito" :key="i" class="block text-slate-600 dark:text-slate-300" data-test="esito-rinvio">{{ riga }}</span>
    </span>
</template>
