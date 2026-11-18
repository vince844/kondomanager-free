<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Proponi gli interessi alla data …», dall'elenco «Rate scadute», per le persone scelte (una lista, o «tutte le N dei filtri», che il server rilegge).
 * Il server propone riga per riga (la rata, la scadenza, i tratti con il capitale, i giorni, il tasso e il divisore, gli interessi della rata arrotondati
 * al centesimo); l'amministratore toglie righe o persone, sceglie la voce di entrata che riceve gli interessi quando sono versati, e registra. Il server
 * rifà la proposta e scrive solo se le cifre sono quelle viste. Niente si registra senza il suo clic.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import vSelect from 'vue-select';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import {
    FRASE_CALCOLO, FRASE_PROPOSTA_INTERESSI, euroEsatti, testoAccanto, testoCausa, testoFinoAllAccordo, testoGiaRegistrati, testoTratto, totaleTenuto, type PersonaInteressi,
} from '@/lib/gestionale/addebiti/accessori';

interface GestioneDellaProposta {
    gestione_id: number; gestione: string; scrive: { gestione_id: number; esercizio_id: number; nome: string } | null; perche: string | null;
    voci: Array<{ id: number; nome: string }>; voce_proposta: number | null;
}
interface Proposta {
    alla: string; persone: PersonaInteressi[]; totale: number; gestioni: GestioneDellaProposta[];
    regole: { righe: unknown[]; di_oggi: string | null; avviso: string | null }; frase_domanda_giudiziale: string;
    /** I tassi legali scritti a mano dallo studio che la proposta usa, con chi, quando e la fonte. */
    tassi_a_mano?: string[];
}

const props = defineProps<{
    open: boolean; condominioId: number; oggi: string;
    /** Le persone scelte nell'elenco; o tutte quelle dei filtri, con i filtri come li legge il server. */
    persone: number[] | null; filtri?: Record<string, unknown>;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const alla = ref(props.oggi);
const proposta = ref<Proposta | null>(null);
const tenute = ref<Set<string>>(new Set());
const voci = ref<Record<number, number | null>>({});
const aperte = ref<Set<string>>(new Set());
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

async function leggi() {
    const numero = ++numeroLettura;
    errore.value = null;
    esito.value = null;
    const params: Record<string, unknown> = props.persone !== null ? { persone: props.persone, alla: alla.value } : { tutte: 1, filtri: props.filtri ?? {}, alla: alla.value };
    try {
        const r = (await axios.get(route(generateRoute('gestionale.addebiti.interessi.proposta'), { condominio: props.condominioId }), { params })).data as Proposta;
        if (numero !== numeroLettura) {
            return;
        }
        proposta.value = r;
        tenute.value = new Set(r.persone.flatMap((p) => p.righe.filter((x) => x.fermo === null && x.interessi > 0).map((x) => x.chiave)));
        voci.value = Object.fromEntries(r.gestioni.map((g) => [g.gestione_id, g.voce_proposta]));
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'La proposta non si è caricata. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (aperta) {
        alla.value = props.oggi;
        proposta.value = null;
        leggi();
    }
}, { immediate: true });

const alterna = (chiave: string) => {
    const s = new Set(tenute.value);
    s.has(chiave) ? s.delete(chiave) : s.add(chiave);
    tenute.value = s;
};
const togliPersona = (p: PersonaInteressi) => {
    const s = new Set(tenute.value);
    p.righe.forEach((r) => s.delete(r.chiave));
    tenute.value = s;
};
const apriChiudi = (chiave: string) => {
    const s = new Set(aperte.value);
    s.has(chiave) ? s.delete(chiave) : s.add(chiave);
    aperte.value = s;
};

const totale = computed(() => totaleTenuto(proposta.value?.persone ?? [], tenute.value));
/** Le gestioni delle righe tenute: per ognuna serve la voce. */
const gestioniTenute = computed(() => {
    const ids = new Set((proposta.value?.persone ?? []).flatMap((p) => p.righe.filter((r) => tenute.value.has(r.chiave)).map((r) => r.gestione_id)));

    return (proposta.value?.gestioni ?? []).filter((g) => ids.has(g.gestione_id));
});
const pronto = computed(() => totale.value > 0 && gestioniTenute.value.every((g) => g.scrive !== null && voci.value[g.gestione_id]));
const indirizzoRegole = computed(() => route(generateRoute('gestionale.recupero-crediti.index'), { condominio: props.condominioId }));

async function registra() {
    if (!pronto.value || !proposta.value) {
        return;
    }
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.addebiti.interessi.store'), { condominio: props.condominioId }), {
            alla: proposta.value.alla, righe: [...tenute.value], atteso: totale.value,
            voci: Object.fromEntries(gestioniTenute.value.map((g) => [g.gestione_id, voci.value[g.gestione_id]])),
        })).data;
        esito.value = r.messaggio;
        emit('fatto');
        await leggi();
        esito.value = r.messaggio;
    } catch (e) {
        errore.value = frasePerErrore(e, 'Gli interessi non sono stati registrati. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- All'apertura il cursore non va in un menu, che prendendolo si aprirebbe da sé. -->
        <DialogContent class="sm:max-w-4xl max-h-[90vh] overflow-y-auto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Proponi gli interessi di mora</DialogTitle>
                <DialogDescription>{{ FRASE_PROPOSTA_INTERESSI }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <div class="flex flex-wrap items-end gap-3 text-xs">
                    <label class="space-y-1"><span class="block text-slate-500">Alla data</span>
                        <Input v-model="alla" type="date" :max="oggi" class="h-8 w-[150px] text-xs" data-testid="alla" /></label>
                    <Button size="sm" variant="outline" class="h-8" data-testid="rileggi" @click="leggi">Rifai la proposta</Button>
                    <Link :href="indirizzoRegole" class="underline text-slate-600 dark:text-slate-300" data-testid="regole">Le regole di questo condominio</Link>
                </div>

                <template v-if="proposta">
                    <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="domanda-giudiziale">{{ proposta.frase_domanda_giudiziale }}</p>
                    <p v-if="proposta.regole.di_oggi" class="text-xs text-slate-500">{{ proposta.regole.di_oggi }}</p>
                    <p v-if="proposta.regole.avviso" class="text-xs text-amber-800 dark:text-amber-300" data-testid="avviso-fonte">{{ proposta.regole.avviso }}</p>
                    <p v-for="f in proposta.tassi_a_mano ?? []" :key="f" class="text-xs text-slate-600 dark:text-slate-300" data-testid="tasso-a-mano">{{ f }}</p>
                    <p v-if="!proposta.persone.length" class="text-xs text-slate-500" data-testid="nessuna-proposta">Alla data non ci sono interessi da proporre per queste persone.</p>

                    <div v-for="p in proposta.persone" :key="p.anagrafica_id" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="persona-interessi">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>{{ p.nome }}</strong>
                            <span class="flex items-center gap-2">
                                <span class="tabular-nums">{{ euroTesto(p.totale) }}</span>
                                <Button v-if="p.righe.some((r) => tenute.has(r.chiave))" size="sm" variant="ghost" class="h-6 text-xs" data-testid="togli-persona" @click="togliPersona(p)">Togli la persona</Button>
                            </span>
                        </div>
                        <p v-for="f in testoAccanto(p)" :key="f" class="text-slate-500" data-testid="accanto">{{ f }}</p>
                        <div v-for="r in p.righe" :key="r.chiave" class="pl-2 border-l space-y-0.5" data-testid="riga-interessi">
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- Una riga a zero porta solo la frase del riallineo: si legge, non si registra. -->
                                <Checkbox v-if="r.interessi > 0 || r.fermo !== null" :model-value="tenute.has(r.chiave)" :disabled="r.fermo !== null" @update:model-value="alterna(r.chiave)" />
                                <span v-else class="w-4 shrink-0" aria-hidden="true" />
                                <span class="flex-1">{{ r.rata }}<template v-if="r.unita"> · {{ r.unita }}</template> · scaduta il {{ giorno(r.scadenza) }}</span>
                                <span class="tabular-nums font-medium">{{ r.fermo ? '—' : euroTesto(r.interessi) }}</span>
                                <button v-if="r.tratti.length" type="button" class="underline text-slate-500" data-testid="apri-tratti" @click="apriChiudi(r.chiave)">{{ aperte.has(r.chiave) ? 'Nascondi i tratti' : 'I tratti' }}</button>
                            </div>
                            <p v-if="r.fermo" class="text-amber-800 dark:text-amber-300" data-testid="fermo">{{ r.fermo }}</p>
                            <p v-if="testoGiaRegistrati(r.gia_registrati_al)" class="text-slate-500">{{ testoGiaRegistrati(r.gia_registrati_al) }}</p>
                            <p v-if="testoFinoAllAccordo(r)" class="text-slate-500" data-testid="fino-all-accordo">{{ testoFinoAllAccordo(r) }}</p>
                            <p v-if="r.riallineo" class="text-amber-800 dark:text-amber-300" data-testid="riallineo">{{ r.riallineo }}</p>
                            <ul v-if="aperte.has(r.chiave)" class="pl-6 list-disc text-slate-600 dark:text-slate-300" data-testid="tratti">
                                <li v-for="(t, i) in r.tratti" :key="i">{{ testoTratto(t) }}: {{ euroEsatti(t.esatti) }} <span class="text-slate-500">({{ testoCausa(t.causa) }})</span></li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="gestioniTenute.length" class="space-y-1 text-xs" data-testid="voci">
                        <div v-for="g in gestioniTenute" :key="g.gestione_id" class="flex flex-wrap items-center gap-2">
                            <span class="text-slate-500">{{ g.scrive ? `Si scrivono in ${g.scrive.nome}, sulla voce` : g.perche }}</span>
                            <div v-if="g.scrive" class="menu-campo w-[240px]" :data-testid="`voce-${g.gestione_id}`">
                                <v-select :model-value="voci[g.gestione_id] ?? null" :options="g.voci" label="nome" :reduce="(v: { id: number }) => v.id" :searchable="false"
                                    :clearable="false" placeholder="Scegli la voce di entrata" class="w-full" @update:model-value="(v: number) => (voci[g.gestione_id] = v)">
                                    <template #no-options>Nessuna voce di entrata.</template>
                                </v-select>
                            </div>
                            <span v-if="g.scrive && !g.voci.length" class="text-amber-800 dark:text-amber-300">La gestione non ha una voce di entrata: creala nel piano dei conti con il preventivo a zero.</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500">{{ FRASE_CALCOLO }}</p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-interessi" @click="registra">Registra {{ euroTesto(totale) }}</Button>
                        <span class="text-xs text-slate-500">Una scadenza per persona e unità, «Interessi di mora al {{ giorno(proposta.alla) }}»; con un tasso scritto, anche il tasso e il suo riferimento.</span>
                    </div>
                </template>
                <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
