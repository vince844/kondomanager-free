<script setup lang="ts">
/**
 * La sezione «Compensazioni senza avviso» della pagina delle ricevute: gli incassi del periodo in cui il credito ha coperto le rate di una
 * persona che non riceve la ricevuta di quell'incasso. Si scelgono una, alcune o tutte, e si stampa l'avviso (un PDF) o lo si manda per email a
 * chi ha un indirizzo; chi non ce l'ha resta da stampare. La riga del bollo come la ricevuta: lo stato lo sceglie l'amministratore, o nessuno, e
 * la sezione propone la sua ultima scelta in questo condominio. Prima dell'invio si vedono gli indirizzi; oltre il tetto per volta gli avvisi
 * che restano restano scelti. Di serie si vedono quelle senza avviso; quelle già avvisate si mostrano a richiesta.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { apriPdfInUnaScheda, fraseDaErroreConFile, frasePerErrore, testoDelPdf } from '@/lib/gestionale/incassi/ricevute';
import { FRASE_BOLLO_RICORDATO, FRASE_SEZIONE, FRASE_TUTTE_AVVISATE, testoAvvisiRestanti, testoCompensazione, testoIndirizziAvvisi, testoUltimoAvviso,
    type Compensazione } from '@/lib/gestionale/incassi/compensazioni';

const props = defineProps<{ condominioId: number; compensazioni: { righe: Compensazione[]; senza_avviso: number; frase_bollo: string; bollo_ricordato?: string | null } }>();
const emit = defineEmits<{ (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (azione: string) => route(generateRoute(`gestionale.ricevute.avvisi-compensazione.${azione}`), { condominio: props.condominioId });

const ancheAvvisate = ref(false);
const scelte = ref<string[]>([]);
const bollo = ref(props.compensazioni.bollo_ricordato ?? '');
const bolloRicordato = computed(() => !!props.compensazioni.bollo_ricordato && bollo.value === props.compensazioni.bollo_ricordato);
const esito = ref<string | null>(null);
const errore = ref<string | null>(null);
const inCorso = ref(false);

const righe = computed(() => props.compensazioni.righe.filter((c) => ancheAvvisate.value || !c.avvisata));
const scelteVisibili = computed(() => scelte.value.filter((k) => righe.value.some((c) => c.chiave === k)));
const alterna = (k: string) => { scelte.value = scelte.value.includes(k) ? scelte.value.filter((x) => x !== k) : [...scelte.value, k]; };
const tutte = computed(() => righe.value.length > 0 && righe.value.every((c) => scelte.value.includes(c.chiave)));
const alternaTutte = () => { scelte.value = tutte.value ? [] : righe.value.map((c) => c.chiave); };
const conEmail = computed(() => righe.value.filter((c) => scelteVisibili.value.includes(c.chiave) && c.ha_email).length);
const indirizziScelti = computed(() => testoIndirizziAvvisi(righe.value.filter((c) => scelteVisibili.value.includes(c.chiave))));
const corpo = () => ({ avvisi: scelteVisibili.value, ...(bollo.value ? { bollo: bollo.value } : {}) });

function stampa() {
    errore.value = null;
    esito.value = null;
    // La scheda si apre nel clic, prima della richiesta: niente `await` prima di questa chiamata.
    apriPdfInUnaScheda(() => axios.post(rotta('stampa'), corpo(), { responseType: 'blob' }), 'avvisi-di-compensazione.pdf').then((arrivato) => {
        esito.value = testoDelPdf(arrivato, { stampa: true, una: scelteVisibili.value.length === 1 });
        scelte.value = [];
        emit('fatto');
    }).catch(async (e) => {
        errore.value = await fraseDaErroreConFile(e, 'Gli avvisi non sono stati stampati. Riprova.');
    });
}

async function invia() {
    errore.value = null;
    esito.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta('invia'), corpo())).data;
        const senza = r.senza_indirizzo?.length ? ` Senza un indirizzo email: ${r.senza_indirizzo.join(', ')}. Stampali per la posta.` : '';
        const resto = testoAvvisiRestanti(r.restanti?.length ?? 0);
        esito.value = `${r.avvisi === 1 ? '1 avviso' : `${r.avvisi} avvisi`} per email (${r.messaggi === 1 ? '1 messaggio' : `${r.messaggi} messaggi`}).${senza}${resto ? ` ${resto}` : ''}`;
        // Gli avvisi oltre il tetto restano scelti: un altro clic li manda.
        scelte.value = r.restanti ?? [];
        emit('fatto');
    } catch (e) {
        errore.value = frasePerErrore(e, 'Gli avvisi non sono partiti. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <section v-if="compensazioni.righe.length" class="rounded-lg border border-slate-200 dark:border-slate-700 p-3 text-sm space-y-2" data-testid="compensazioni">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium">
                Compensazioni senza avviso: {{ compensazioni.senza_avviso }}
            </h3>
            <label class="flex items-center gap-2 text-xs" data-testid="anche-avvisate">
                <Checkbox :model-value="ancheAvvisate" @update:model-value="(v: any) => (ancheAvvisate = !!v)" /> Mostra anche quelle già avvisate
            </label>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-300">{{ FRASE_SEZIONE }}</p>
        <table v-if="righe.length" class="w-full text-xs">
            <thead class="text-slate-500 dark:text-slate-400">
                <tr>
                    <th class="p-1 w-8"><Checkbox :model-value="tutte" aria-label="Scegli tutte" @update:model-value="alternaTutte" /></th>
                    <th class="p-1 text-left">Che cosa</th>
                    <th class="p-1 text-left">Avviso</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in righe" :key="c.chiave" class="border-t align-top" data-testid="compensazione">
                    <td class="p-1"><Checkbox :model-value="scelte.includes(c.chiave)" :aria-label="`Scegli ${c.persona.nome}`" @update:model-value="alterna(c.chiave)" /></td>
                    <td class="p-1">
                        {{ testoCompensazione(c) }}
                        <span class="block text-[11px] text-slate-500">{{ c.quote.map((q) => `${q.unita ? `${q.unita}, ` : ''}${q.rata}`).join('; ') }}</span>
                        <span v-if="!c.ha_email" class="block text-[11px] text-amber-800 dark:text-amber-300">Senza email: stampalo per la posta.</span>
                    </td>
                    <td class="p-1">{{ testoUltimoAvviso(c.ultimo) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-else class="text-xs text-slate-500">{{ FRASE_TUTTE_AVVISATE }}</p>

        <div v-if="righe.length" class="flex flex-wrap items-end gap-2">
            <label class="space-y-1 text-xs">
                <span class="block text-slate-500">Imposta di bollo</span>
                <select v-model="bollo" class="h-8 rounded-md border px-2 bg-transparent" data-testid="bollo-avvisi">
                    <option value="">Nessuna scelta (la riga dice «—»)</option>
                    <option value="assolta">Assolta</option>
                    <option value="non_dovuta">Non dovuta</option>
                    <option value="da_assolvere">Da assolvere</option>
                </select>
            </label>
            <Button size="sm" variant="outline" :disabled="scelteVisibili.length === 0" data-testid="stampa-avvisi" @click="stampa">
                {{ scelteVisibili.length === 1 ? 'Stampa l\'avviso' : `Stampa ${scelteVisibili.length} avvisi` }}
            </Button>
            <Button size="sm" :disabled="inCorso || conEmail === 0" data-testid="invia-avvisi" @click="invia">
                {{ conEmail === 1 ? 'Invia 1 avviso per email' : `Invia ${conEmail} avvisi per email` }}
            </Button>
        </div>
        <p v-if="indirizziScelti" class="text-[11px] text-slate-600 dark:text-slate-300" data-testid="indirizzi-avvisi">{{ indirizziScelti }}</p>
        <p v-if="righe.length && bolloRicordato" class="text-[11px] text-slate-500" data-testid="bollo-ricordato">{{ FRASE_BOLLO_RICORDATO }}</p>
        <p v-if="righe.length" class="text-[11px] text-slate-500">{{ compensazioni.frase_bollo }}</p>
        <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito-avvisi">{{ esito }}</p>
        <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore-avvisi">{{ errore }}</p>
    </section>
</template>
