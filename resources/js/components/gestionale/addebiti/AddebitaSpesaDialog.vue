<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Spese del ritardo»: una spesa per il ritardo (sollecito, spese legali, altro) pagata dal condominio senza una riga sull'unità, a una persona o a
 * tante insieme (dall'elenco «Rate scadute»: la cifra uguale per tutti, la base scritta una volta, una rata per persona). Il server propone per ognuno
 * l'unità su cui si scrive, le gestioni di oggi con le loro voci, i tipi, le basi e gli avvisi di ogni coppia; l'amministratore sceglie il tipo, la
 * base, la voce che riceve il denaro quando è versato. Niente è già scelto. Gli importi si scrivono in euro: il server li porta in centesimi.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import vSelect from 'vue-select';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { FRASE_BASE, FRASE_SPESA, avvisiDi, causaleDiSerie, type Scelta } from '@/lib/gestionale/addebiti/accessori';

interface PersonaDellaSpesa { anagrafica_id: number; nome: string; immobile_id: number | null; unita: string | null; perche: string | null }
interface Voce { id: number; nome: string; entrata: boolean }
interface Gestione { gestione_id: number; esercizio_id: number; nome: string; voci: Voce[] }
interface Proposta {
    persone: PersonaDellaSpesa[]; dove: { proposta: number | null; scelte: Gestione[] }; tipi: Scelta[]; basi: Scelta[];
    avvisi: Record<string, Record<string, string[]>>; oggi: string;
}

const props = defineProps<{
    open: boolean; condominioId: number;
    /** Le persone scelte; null, tutte quelle dei filtri dell'elenco, che il server rilegge. */
    persone: number[] | null; filtri?: Record<string, unknown>;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const proposta = ref<Proposta | null>(null);
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

const vuoto = () => ({ tipo: '', base: '', riferimento: '', importo: '', causale: '', gestione: null as number | null, voce: null as number | null, scadenza: '' });
const modulo = ref(vuoto());

async function leggi() {
    const numero = ++numeroLettura;
    errore.value = null;
    try {
        const params = props.persone !== null ? { persone: props.persone } : { tutte: 1, filtri: props.filtri ?? {} };
        const r = (await axios.get(route(generateRoute('gestionale.addebiti.spese.proposta'), { condominio: props.condominioId }), { params })).data as Proposta;
        if (numero !== numeroLettura) {
            return;
        }
        proposta.value = r;
        modulo.value = { ...vuoto(), gestione: r.dove.proposta, scadenza: r.oggi };
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'La proposta non si è caricata. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (aperta) {
        proposta.value = null;
        esito.value = null;
        leggi();
    }
}, { immediate: true });

// La causale proposta segue il tipo, finché l'amministratore non la scrive lui.
watch(() => modulo.value.tipo, (tipo, prima) => {
    const vecchia = causaleDiSerie(prima, proposta.value?.tipi ?? []);
    if (modulo.value.causale === '' || modulo.value.causale === vecchia) {
        modulo.value.causale = causaleDiSerie(tipo, proposta.value?.tipi ?? []);
    }
});

const gestione = computed(() => proposta.value?.dove.scelte.find((g) => g.gestione_id === modulo.value.gestione) ?? null);
watch(() => modulo.value.gestione, () => { modulo.value.voce = null; });
// Le voci del menu della voce: il nome, e «(entrata)» per una voce di entrata.
const vociDelMenu = computed(() => (gestione.value?.voci ?? []).map((v) => ({ id: v.id, etichetta: `${v.nome}${v.entrata ? ' (entrata)' : ''}` })));
const conUnita = computed(() => (proposta.value?.persone ?? []).filter((p) => p.immobile_id !== null));
const senzaUnita = computed(() => (proposta.value?.persone ?? []).filter((p) => p.immobile_id === null));
const avvisi = computed(() => avvisiDi(proposta.value?.avvisi, modulo.value.tipo, modulo.value.base));
const titolo = computed(() => ((proposta.value?.persone ?? []).length === 1 ? `Spese del ritardo per ${proposta.value!.persone[0].nome}` : 'Spese del ritardo'));
const pronto = computed(() => {
    const m = modulo.value;

    return conUnita.value.length > 0 && m.tipo !== '' && m.base !== '' && m.importo.trim() !== '' && m.causale.trim() !== '' && gestione.value !== null && m.voce !== null && m.scadenza !== '';
});

async function registra() {
    const g = gestione.value;
    if (!g || !pronto.value) {
        return;
    }
    errore.value = null;
    esito.value = null;
    inCorso.value = true;
    const m = modulo.value;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.addebiti.store'), { condominio: props.condominioId }), {
            gestione_id: g.gestione_id, esercizio_id: g.esercizio_id,
            voci: conUnita.value.map((p) => ({ anagrafica_id: p.anagrafica_id, immobile_id: p.immobile_id, importo: m.importo, causale: m.causale, scadenza: m.scadenza,
                natura: 'accessorio', tipo_accessorio: m.tipo, base: m.base, riferimento: m.riferimento || null, conto_id: m.voce })),
        })).data;
        esito.value = r.messaggio;
        emit('fatto');
    } catch (e) {
        errore.value = frasePerErrore(e, 'La spesa non è stata addebitata. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- All'apertura il cursore non va in un menu, che prendendolo si aprirebbe da sé. -->
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ titolo }}</DialogTitle>
                <DialogDescription>{{ FRASE_SPESA }}</DialogDescription>
            </DialogHeader>

            <div v-if="proposta" class="space-y-4 text-sm" data-testid="modulo-spesa">
                <div class="space-y-1 text-xs" data-testid="persone-spesa">
                    <p class="text-slate-500">{{ conUnita.length === 1 ? 'A chi va' : `A chi va: ${conUnita.length} persone, una rata ciascuna, la stessa cifra` }}</p>
                    <p v-for="p in conUnita" :key="p.anagrafica_id"><strong>{{ p.nome }}</strong> · {{ p.unita }}</p>
                    <p v-for="p in senzaUnita" :key="p.anagrafica_id" class="text-amber-700 dark:text-amber-400" data-testid="senza-unita"><strong>{{ p.nome }}</strong>: {{ p.perche }}</p>
                </div>

                <fieldset class="space-y-1 text-xs" data-testid="tipo">
                    <legend class="text-slate-500">Che cos'è</legend>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="t in proposta.tipi" :key="t.valore" class="flex items-center gap-1"><input v-model="modulo.tipo" type="radio" :value="t.valore" /> {{ t.nome }}</label>
                    </div>
                </fieldset>

                <fieldset class="space-y-1 text-xs" data-testid="base">
                    <legend class="text-slate-500">Su che cosa si fonda</legend>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="b in proposta.basi" :key="b.valore" class="flex items-center gap-1"><input v-model="modulo.base" type="radio" :value="b.valore" /> {{ b.nome }}</label>
                    </div>
                    <label class="block space-y-1"><span class="block text-slate-500">Il riferimento (l'articolo, la data e il verbale, il numero del provvedimento)</span>
                        <Input v-model="modulo.riferimento" class="h-8 text-xs" data-testid="riferimento" /></label>
                    <p class="text-slate-500">{{ FRASE_BASE }}</p>
                    <p v-for="a in avvisi" :key="a" class="text-amber-800 dark:text-amber-300" data-testid="avviso">{{ a }}</p>
                </fieldset>

                <div class="flex flex-wrap gap-3 text-xs">
                    <label class="space-y-1"><span class="block text-slate-500">Importo per persona (€)</span>
                        <Input v-model="modulo.importo" inputmode="decimal" class="h-8 w-[120px] text-xs" data-testid="importo" /></label>
                    <label class="flex-1 space-y-1 min-w-[220px]"><span class="block text-slate-500">Causale</span>
                        <Input v-model="modulo.causale" class="h-8 text-xs" data-testid="causale" /></label>
                    <label class="space-y-1"><span class="block text-slate-500">Scadenza</span>
                        <Input v-model="modulo.scadenza" type="date" class="h-8 w-[150px] text-xs" data-testid="scadenza" /></label>
                </div>

                <div class="flex flex-wrap gap-3 text-xs">
                    <div class="basis-full space-y-1 menu-campo" data-testid="gestione"><span class="block text-slate-500">Gestione</span>
                        <v-select :model-value="modulo.gestione" :options="proposta.dove.scelte" label="nome" :reduce="(g: Gestione) => g.gestione_id" :searchable="false"
                            :clearable="false" class="w-full" @update:model-value="(v: number) => (modulo.gestione = v)" />
                    </div>
                    <div class="basis-full space-y-1 menu-campo" data-testid="voce"><span class="block text-slate-500">La voce che riceve il denaro quando è versato</span>
                        <v-select :model-value="modulo.voce" :options="vociDelMenu" label="etichetta" :reduce="(v: { id: number }) => v.id" :searchable="false"
                            :clearable="false" placeholder="Scegli la voce" class="w-full" @update:model-value="(v: number) => (modulo.voce = v)">
                            <template #no-options>La gestione non ha voci.</template>
                        </v-select>
                    </div>
                </div>
                <p v-if="!proposta.dove.scelte.length" class="text-xs text-amber-700 dark:text-amber-400" data-testid="senza-esercizio">Non c'è un esercizio aperto che contiene oggi: la spesa si addebita in un esercizio aperto.</p>
                <p v-if="modulo.importo && conUnita.length > 1" class="text-xs text-slate-500">In tutto {{ conUnita.length }} addebiti.</p>

                <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-spesa" @click="registra">{{ conUnita.length > 1 ? `Addebita a ${conUnita.length} persone` : 'Addebita la spesa' }}</Button>
            </div>
            <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
            <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
        </DialogContent>
    </Dialog>
</template>
