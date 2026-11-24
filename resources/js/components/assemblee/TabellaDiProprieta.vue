<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La tabella del valore dell'assemblea e la tabella di proprietà generale (§3 P12, R14): la riga «Tabella «Proprietà generale» · 26 unità su 26 · 1.000,00
 * millesimi» con «Cambia», e gli avvisi del controllo, che non bloccano («l'Int. 14 non ha una quota», «la somma è 998,40»). Senza una tabella di proprietà
 * generale, la riga dice «Scegli la tabella di proprietà generale di Via Roma 12» con il pulsante.
 *
 * «Cambia» sceglie la tabella del valore dell'assemblea e, con la casella, la fa diventare la tabella di proprietà generale del perimetro (una riga del
 * registro sulla scheda del condominio, della palazzina o della scala). Le visite rileggono solo `assemblea` e `passi` (la tabella che copre tutte le
 * unità fa il primo passo).
 *
 * L'assemblea dei condòmini di un gruppo (Coda 734): la tabella del valore è il gruppo, ferma come in «Prepara l'assemblea» (il gruppo e la tabella decidono
 * chi è convocato, e il server non li cambia): la riga dice «la tabella del gruppo», e «Cambia» non c'è.
 */
import { computed, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { chiaveDelPerimetro, type AssembleaDellaPagina, type ScelteDelleAssemblee } from '@/lib/assemblee/pagine';

const props = defineProps<{
    condominioId: number;
    condominioNome: string;
    assemblea: AssembleaDellaPagina;
    scelte: ScelteDelleAssemblee;
    gestisce: boolean;
}>();

interface Opzione { valore: number; nome: string; sotto: string }

const { generateRoute } = usePermission();
const aperta = ref(false);
const scelta = ref<number | null>(null);
const diProprieta = ref(true);
const inCorso = ref(false);

const delGruppo = computed(() => props.assemblea.perimetro_tipo === 'gruppo');
const siCambia = computed(() => props.gestisce && props.assemblea.in_preparazione && !delGruppo.value);
const chiave = computed(() => chiaveDelPerimetro(props.assemblea.ambito, props.assemblea.palazzina_id, props.assemblea.scala_id));
const nomeDelPerimetro = computed(() => props.assemblea.perimetro_tipo === 'condominio' ? props.condominioNome : props.assemblea.perimetro.replace('Solo ', ''));
const opzioni = computed<Opzione[]>(() => props.scelte.tabelle.map((t) => ({ valore: t.id, nome: t.nome, sotto: t.controlli[chiave.value]?.frase ?? `${t.unita} unità` })));
const controlloScelto = computed(() => props.scelte.tabelle.find((t) => t.id === scelta.value)?.controlli[chiave.value] ?? null);

watch(aperta, (a) => {
    if (!a) return;
    scelta.value = props.assemblea.tabella?.id ?? props.assemblea.tabella_di_proprieta?.id ?? null;
    diProprieta.value = props.assemblea.tabella_di_proprieta === null;
});

function salva() {
    if (scelta.value === null) return;
    inCorso.value = true;
    const tabella = scelta.value;
    const poi = () => {
        if (diProprieta.value && props.assemblea.tabella_di_proprieta?.id !== tabella) {
            router.post(route(generateRoute('gestionale.tabella-di-proprieta'), { condominio: props.condominioId }), {
                perimetro_tipo: props.assemblea.perimetro_tipo, perimetro_id: props.assemblea.perimetro_tipo === 'condominio' ? null : props.assemblea.perimetro_id,
                tabella_id: tabella,
            }, { only: ['assemblea', 'passi', 'flash'], preserveScroll: true, preserveState: true, onFinish: () => { inCorso.value = false; aperta.value = false; } });
        } else {
            inCorso.value = false;
            aperta.value = false;
        }
    };
    router.patch(route(generateRoute('gestionale.assemblee.update'), { condominio: props.condominioId, assemblea: props.assemblea.id }), { tabella_id: tabella },
        { only: ['assemblea', 'passi', 'flash'], preserveScroll: true, preserveState: true, onSuccess: poi, onError: () => { inCorso.value = false; } });
}
</script>

<template>
    <div class="rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-700" data-testid="tabella-dell-assemblea">
        <div v-if="assemblea.tabella" class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-800 dark:text-slate-200" data-testid="controllo-tabella">
                Tabella «{{ assemblea.tabella.nome }}»{{ delGruppo ? ' · la tabella del gruppo' : '' }} · {{ assemblea.tabella.controllo.frase }}
            </p>
            <button v-if="siCambia" type="button" class="text-xs font-medium underline" data-testid="cambia-tabella" @click="aperta = true">Cambia</button>
        </div>
        <div v-else class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-amber-800 dark:text-amber-300" data-testid="manca-la-tabella">
                {{ assemblea.tabella_di_proprieta ? 'Scegli la tabella del valore dell\'assemblea' : `Scegli la tabella di proprietà generale di ${nomeDelPerimetro}` }}
            </p>
            <button v-if="siCambia" type="button" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium dark:border-slate-700 dark:bg-slate-900"
                data-testid="scegli-tabella" @click="aperta = true">Scegli la tabella</button>
        </div>
        <ul v-if="assemblea.tabella && assemblea.tabella.controllo.avvisi.length" class="mt-1.5 space-y-0.5" data-testid="avvisi-tabella">
            <li v-for="a in assemblea.tabella.controllo.avvisi.slice(0, 5)" :key="a" class="flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                <AlertTriangle class="mt-px h-3.5 w-3.5 shrink-0" /> {{ a.charAt(0).toUpperCase() + a.slice(1) }}
            </li>
            <li v-if="assemblea.tabella.controllo.avvisi.length > 5" class="text-xs text-amber-800 dark:text-amber-300">
                e altri {{ assemblea.tabella.controllo.avvisi.length - 5 }}: non bloccano, controlla prima di convocare.
            </li>
        </ul>

        <Dialog v-model:open="aperta">
            <DialogContent class="sm:max-w-[480px]" data-testid="finestra-tabella" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>La tabella del valore</DialogTitle>
                    <DialogDescription>Con questa tabella si contano i millesimi dell'assemblea. Il controllo avvisa e non blocca.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="menu-campo">
                        <v-select v-model="scelta" :options="opzioni" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="opzioni.length > 8"
                            placeholder="Scegli la tabella">
                            <template #option="o"><span class="block">{{ o.nome }}</span><span class="block text-xs opacity-70">{{ o.sotto }}</span></template>
                        </v-select>
                    </div>
                    <p v-if="controlloScelto" class="text-xs text-slate-600 dark:text-slate-300">{{ controlloScelto.frase }}{{ controlloScelto.copre ? '' : ' · non copre tutte le unità' }}</p>
                    <label class="flex items-start gap-2 text-sm text-slate-800 dark:text-slate-200">
                        <Checkbox v-model="diProprieta" class="mt-0.5 rounded" data-testid="di-proprieta" />
                        <span>È la tabella di proprietà generale di {{ nomeDelPerimetro }}<span class="block text-xs text-slate-500 dark:text-slate-400">La proposta per le prossime assemblee; la scelta resta nel registro delle modifiche.</span></span>
                    </label>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                    <Button variant="outline" @click="aperta = false">Annulla</Button>
                    <Button :disabled="scelta === null || inCorso" data-testid="salva-tabella" @click="salva">{{ inCorso ? 'Un momento…' : 'Salva' }}</Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
