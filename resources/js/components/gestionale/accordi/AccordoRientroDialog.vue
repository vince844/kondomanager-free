<script setup lang="ts">
/**
 * L'accordo di rientro di una persona, dalla sua riga dell'elenco «Rate scadute»: gli accordi che ha (con il segno delle due cifre,
 * l'autorizzazione, la nota, la scansione, la chiusura) e il modulo per registrarne uno. L'accordo è un registro: le rate coperte si scelgono fra
 * le scadute della persona il giorno dell'accordo, con l'importo riconosciuto proposto dal residuo di quel giorno; «autorizzato dall'assemblea»
 * non ha una risposta già scelta. Un accordo alla volta: è un atto con una persona. Non si cancella: si chiude.
 *
 * Le rate si rileggono quando cambia la data dell'accordo: una risposta arrivata dopo un altro cambio della data si scarta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import CampoFile from '@/components/CampoFile.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { FRASE_AUTORIZZAZIONE, FRASE_IMPORTO, FRASE_NON_SCADUTE, FRASE_REGISTRO, testoAutorizzazione, testoSegnoAccordo, type AccordoRientro } from '@/lib/gestionale/debiti/accordiRientro';

interface RataPossibile { rata_id: number; immobile_id: number | null; numero_rata: number; piano: string; unita: string | null; scadenza: string; residuo: number }
interface AccordoDellaPersona extends AccordoRientro {
    in_corso: boolean;
    documento: string | null;
    rate: Array<{ rata_id: number; immobile_id: number | null; riconosciuto: number; unita: string | null; piano: string; numero_rata: number; scadenza: string | null }>;
}

const props = defineProps<{ open: boolean; condominioId: number; persona: { id: number; nome: string } | null; oggi: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.accordi-rientro.${nome}`), { condominio: props.condominioId, ...altro });

const accordi = ref<AccordoDellaPersona[]>([]);
const possibili = ref<RataPossibile[]>([]);
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

const chiave = (r: { rata_id: number; immobile_id: number | null }) => `${r.rata_id}|${r.immobile_id ?? ''}`;
const centesimiInEuro = (c: number) => (c / 100).toFixed(2).replace('.', ',');

const modulo = ref({
    data_accordo: props.oggi,
    fino_al: '',
    scelte: {} as Record<string, boolean>,
    importi: {} as Record<string, string>,
    autorizzato: null as null | '0' | '1',
    data_autorizzazione: '',
    verbale_autorizzazione: '',
    nota: '',
});
const file = ref<File | null>(null);
const chiusura = ref<Record<number, { chiuso_il: string; nota_chiusura: string }>>({});
const autorizzazione = ref<Record<number, { data_autorizzazione: string; verbale_autorizzazione: string }>>({});

async function leggi(data?: string) {
    if (!props.persona) {
        return;
    }
    const numero = ++numeroLettura;
    errore.value = null;
    try {
        const r = (await axios.get(rotta('index'), { params: { anagrafica_id: props.persona.id, data: data ?? modulo.value.data_accordo } })).data;
        // Arrivata dopo un altro cambio della data, o di persona: non è la lettura di quello che c'è a video.
        if (numero !== numeroLettura || r.persona?.id !== props.persona?.id) {
            return;
        }
        accordi.value = r.accordi;
        possibili.value = r.rate;
        modulo.value.scelte = {};
        modulo.value.importi = Object.fromEntries(r.rate.map((p: RataPossibile) => [chiave(p), centesimiInEuro(p.residuo)]));
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'Gli accordi non si sono caricati. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (!aperta) {
        return;
    }
    modulo.value = { data_accordo: props.oggi, fino_al: '', scelte: {}, importi: {}, autorizzato: null, data_autorizzazione: '', verbale_autorizzazione: '', nota: '' };
    file.value = null;
    esito.value = null;
    accordi.value = [];
    possibili.value = [];
    leggi(props.oggi);
}, { immediate: true });

watch(() => modulo.value.data_accordo, (data, prima) => {
    if (props.open && data && data !== prima) {
        leggi(data);
    }
});

const scelte = computed(() => possibili.value.filter((p) => modulo.value.scelte[chiave(p)]));
const pronto = computed(() => scelte.value.length > 0 && !!modulo.value.fino_al && modulo.value.autorizzato !== null
    && (modulo.value.autorizzato === '0' || !!modulo.value.data_autorizzazione));

async function registra() {
    errore.value = null;
    esito.value = null;
    inCorso.value = true;
    const corpo = new FormData();
    corpo.append('anagrafica_id', String(props.persona?.id ?? ''));
    corpo.append('data_accordo', modulo.value.data_accordo);
    corpo.append('fino_al', modulo.value.fino_al);
    scelte.value.forEach((p, i) => {
        corpo.append(`rate[${i}][rata_id]`, String(p.rata_id));
        if (p.immobile_id !== null) {
            corpo.append(`rate[${i}][immobile_id]`, String(p.immobile_id));
        }
        corpo.append(`rate[${i}][importo]`, modulo.value.importi[chiave(p)] ?? '');
    });
    if (modulo.value.autorizzato !== null) {
        corpo.append('autorizzato_assemblea', modulo.value.autorizzato);
    }
    if (modulo.value.autorizzato === '1') {
        corpo.append('data_autorizzazione', modulo.value.data_autorizzazione);
        corpo.append('verbale_autorizzazione', modulo.value.verbale_autorizzazione);
    }
    if (modulo.value.nota.trim() !== '') {
        corpo.append('nota', modulo.value.nota.trim());
    }
    if (file.value) {
        corpo.append('documento', file.value);
    }
    try {
        const r = (await axios.post(rotta('store'), corpo)).data;
        esito.value = r.messaggio;
        modulo.value = { ...modulo.value, fino_al: '', scelte: {}, autorizzato: null, data_autorizzazione: '', verbale_autorizzazione: '', nota: '' };
        file.value = null;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'accordo non è stato registrato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

async function chiudi(a: AccordoDellaPersona) {
    errore.value = null;
    const c = chiusura.value[a.id];
    try {
        const r = (await axios.post(rotta('chiudi', { accordo: a.id }), { chiuso_il: c?.chiuso_il, nota_chiusura: c?.nota_chiusura || null })).data;
        esito.value = r.messaggio;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'accordo non è stato chiuso. Riprova.');
    }
}

async function autorizza(a: AccordoDellaPersona) {
    errore.value = null;
    const v = autorizzazione.value[a.id];
    try {
        const r = (await axios.post(rotta('autorizza', { accordo: a.id }), { data_autorizzazione: v?.data_autorizzazione, verbale_autorizzazione: v?.verbale_autorizzazione || null })).data;
        esito.value = r.messaggio;
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'autorizzazione non è stata registrata. Riprova.');
    }
}

const apriChiusura = (a: AccordoDellaPersona) => { chiusura.value = { ...chiusura.value, [a.id]: { chiuso_il: props.oggi, nota_chiusura: '' } }; };
const apriAutorizzazione = (a: AccordoDellaPersona) => { autorizzazione.value = { ...autorizzazione.value, [a.id]: { data_autorizzazione: '', verbale_autorizzazione: '' } }; };
const nomeRata = (r: { numero_rata: number; piano: string; unita: string | null }) =>
    `${r.unita ? `${r.unita}, ` : ''}${r.numero_rata === 0 ? 'rata 0 (pregresso)' : `rata ${r.numero_rata}`}${r.piano ? ` — ${r.piano}` : ''}`;
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Accordo di rientro{{ persona ? ` di ${persona.nome}` : '' }}</DialogTitle>
                <DialogDescription>{{ FRASE_REGISTRO }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm">
                <!-- Gli accordi che la persona ha -->
                <div v-if="accordi.length" class="space-y-2" data-testid="accordi">
                    <div v-for="a in accordi" :key="a.id" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="accordo">
                        <p class="font-medium">{{ testoSegnoAccordo(a) }}</p>
                        <p>{{ testoAutorizzazione(a) }}</p>
                        <p v-if="a.nota" class="text-slate-600 dark:text-slate-300">{{ a.nota }}</p>
                        <ul class="list-disc pl-4">
                            <li v-for="r in a.rate" :key="`${r.rata_id}-${r.immobile_id}`">{{ nomeRata(r) }}: riconosciuti {{ euroTesto(r.riconosciuto) }}</li>
                        </ul>
                        <p v-if="a.documento">
                            <a :href="rotta('documento', { accordo: a.id })" target="_blank" rel="noopener" class="underline">{{ a.documento }}</a>
                        </p>
                        <p v-if="a.chiuso_il" data-testid="chiuso">Chiuso il {{ giorno(a.chiuso_il) }}{{ a.nota_chiusura ? `: ${a.nota_chiusura}` : '.' }}</p>
                        <div v-else class="flex flex-wrap gap-2">
                            <Button v-if="!a.autorizzato_assemblea && !autorizzazione[a.id]" size="sm" variant="ghost" class="h-7 text-xs" @click="apriAutorizzazione(a)">Registra l'autorizzazione dell'assemblea</Button>
                            <Button v-if="!chiusura[a.id]" size="sm" variant="ghost" class="h-7 text-xs" data-testid="apri-chiusura" @click="apriChiusura(a)">Chiudi l'accordo</Button>
                        </div>
                        <div v-if="autorizzazione[a.id] && !a.autorizzato_assemblea" class="flex flex-wrap items-end gap-2">
                            <label class="space-y-1"><span class="block text-slate-500">Data della delibera</span>
                                <Input v-model="autorizzazione[a.id].data_autorizzazione" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Verbale n.</span>
                                <Input v-model="autorizzazione[a.id].verbale_autorizzazione" class="h-8 w-[100px] text-xs" /></label>
                            <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!autorizzazione[a.id].data_autorizzazione" @click="autorizza(a)">Registra</Button>
                        </div>
                        <div v-if="chiusura[a.id] && !a.chiuso_il" class="flex flex-wrap items-end gap-2" data-testid="modulo-chiusura">
                            <label class="space-y-1"><span class="block text-slate-500">Chiuso il</span>
                                <Input v-model="chiusura[a.id].chiuso_il" type="date" :min="a.data_accordo" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Nota</span>
                                <Input v-model="chiusura[a.id].nota_chiusura" class="h-8 w-[220px] text-xs" /></label>
                            <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!chiusura[a.id].chiuso_il" data-testid="chiudi" @click="chiudi(a)">Chiudi</Button>
                        </div>
                    </div>
                </div>

                <!-- Un accordo nuovo -->
                <fieldset class="space-y-3 rounded-lg border p-3" data-testid="modulo-accordo">
                    <legend class="px-1 text-xs font-medium">Registra un accordo</legend>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <label class="space-y-1"><span class="block text-slate-500">Data dell'accordo</span>
                            <Input v-model="modulo.data_accordo" type="date" :max="oggi" class="h-8 w-[150px] text-xs" data-testid="data-accordo" /></label>
                        <label class="space-y-1"><span class="block text-slate-500">Fino al (l'ultima rata dell'accordo)</span>
                            <Input v-model="modulo.fino_al" type="date" :min="modulo.data_accordo" class="h-8 w-[150px] text-xs" data-testid="fino-al" /></label>
                    </div>

                    <div class="space-y-1 text-xs">
                        <p class="text-slate-500">Le rate che può coprire: quelle già scadute al {{ giorno(modulo.data_accordo) }}, con il residuo di quel giorno. {{ FRASE_IMPORTO }}</p>
                        <p class="text-slate-500" data-testid="frase-non-scadute">{{ FRASE_NON_SCADUTE }}</p>
                        <p v-if="!possibili.length" data-testid="nessuna-rata">Il {{ giorno(modulo.data_accordo) }} {{ persona?.nome }} non ha rate scadute da coprire.</p>
                        <div v-for="p in possibili" :key="chiave(p)" class="flex flex-wrap items-center gap-2" data-testid="rata-possibile">
                            <Checkbox :model-value="!!modulo.scelte[chiave(p)]" @update:model-value="(v: any) => (modulo.scelte = { ...modulo.scelte, [chiave(p)]: !!v })" />
                            <span class="w-64">{{ nomeRata(p) }}, scaduta il {{ giorno(p.scadenza) }}: residuo {{ euroTesto(p.residuo) }}</span>
                            <Input v-model="modulo.importi[chiave(p)]" inputmode="decimal" :disabled="!modulo.scelte[chiave(p)]" class="h-7 w-[110px] text-xs" aria-label="Importo riconosciuto" />
                        </div>
                    </div>

                    <fieldset class="space-y-1 text-xs">
                        <legend class="text-slate-500">Autorizzato dall'assemblea</legend>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-1" data-testid="autorizzato-si"><input v-model="modulo.autorizzato" type="radio" name="autorizzato" value="1" /> Sì</label>
                            <label class="flex items-center gap-1" data-testid="autorizzato-no"><input v-model="modulo.autorizzato" type="radio" name="autorizzato" value="0" /> No</label>
                        </div>
                        <p class="text-slate-500">{{ FRASE_AUTORIZZAZIONE }}</p>
                        <div v-if="modulo.autorizzato === '1'" class="flex flex-wrap gap-3">
                            <label class="space-y-1"><span class="block text-slate-500">Data della delibera</span>
                                <Input v-model="modulo.data_autorizzazione" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Verbale n.</span>
                                <Input v-model="modulo.verbale_autorizzazione" class="h-8 w-[100px] text-xs" /></label>
                        </div>
                    </fieldset>

                    <label class="block space-y-1 text-xs"><span class="block text-slate-500">Nota</span>
                        <Textarea v-model="modulo.nota" rows="2" /></label>
                    <div class="block space-y-1 text-xs"><span class="block text-slate-500">L'accordo firmato (PDF o immagine, fino a 10 MB)</span>
                        <CampoFile v-model="file" accept=".pdf,.jpg,.jpeg,.png" testid="file-accordo" /></div>

                    <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-accordo" @click="registra">Registra l'accordo</Button>
                </fieldset>

                <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
