<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Per quanto tempo si tengono le righe del registro delle modifiche (1.12.0-beta.13, §11 del progetto, decisione 518 (1), bozza scena 4). Carica da sé il suo
 * stato (`GET registro-modifiche/conservazione`) e ha due forme:
 *  - `scelta` (di serie), per il registro dello studio in Impostazioni: la carta della bozza con «Per quanto tempo si tengono le righe», la «i» con
 *    l'esempio, il menu 10 / 15 / 20 anni, la riga «Adesso: 10 anni» e «Salva la durata» (solo l'amministratore; agli altri il menu è spento e lo dice).
 *    Con `con-carta` a falso, solo il campo, per una carta che c'è già.
 *  - `riquadro`, per la card «Conservazione» della pagina del condominio (scena 2): «10 anni» e «le righe del 2026 si tolgono da sole nel 2036».
 * In tutte e due, se un condominio ha la cancellazione sospesa, la frase della decisione 558: «La cancellazione è ferma per tutto lo studio finché è
 * sospesa Via Roma 12 (dal 03/03/2035: causa Rossi).» (il prefisso della conservazione non salta le righe). Nella forma «scelta», all'amministratore,
 * anche i condomini eliminati con la cancellazione sospesa, con «Togli la sospensione» e il motivo (la pagina del loro registro non c'è più).
 */
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import vSelect from 'vue-select';
import { Info, LoaderCircle, RotateCw } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from '@/composables/permissions';
import {
    DESCRIZIONE_CARTA_DURATA, ETICHETTA_DURATA, TITOLO_CARTA_DURATA, annoDellaFrase, esempioDellaDurata, fraseDelleSospensioni, messaggioDiErrore,
    rigaAdesso, testoDegliAnni, type SospensioneAttiva, type StatoConservazione,
} from '@/lib/registroModifiche/casiSpeciali';

const props = withDefaults(defineProps<{ forma?: 'scelta' | 'riquadro'; condominioId?: number | null; conCarta?: boolean }>(), {
    forma: 'scelta', condominioId: null, conCarta: true,
});
const emit = defineEmits<{ (e: 'cambiata', stato: StatoConservazione): void }>();

const { generateRoute } = usePermission();

const stato = ref<StatoConservazione | null>(null);
const errore = ref('');
const scelta = ref<number | null>(null);
const salvando = ref(false);
const salvato = ref('');
const erroreSalvataggio = ref('');

const opzioni = computed(() => (stato.value?.scelte ?? []).map((anni) => ({ valore: anni, nome: testoDegliAnni(anni) })));
const cambiata = computed(() => stato.value !== null && scelta.value !== null && scelta.value !== stato.value.anni);
const esempio = computed(() => stato.value ? esempioDellaDurata(stato.value.anni, annoDellaFrase(stato.value.frase)) : '');
const fraseSospensioni = computed(() => fraseDelleSospensioni(stato.value?.sospensioni ?? []));

// I condomini eliminati con la cancellazione sospesa (solo nella forma «scelta», solo all'amministratore): la loro sospensione si toglie da qui.
const eliminati = ref<SospensioneAttiva[]>([]);
const daTogliere = ref<SospensioneAttiva | null>(null);
const motivo = ref('');
const erroreMotivo = ref('');
const togliendo = ref(false);

async function caricaEliminati() {
    try {
        const { data } = await axios.get<{ sospensioni?: SospensioneAttiva[] }>(route(generateRoute('registro-modifiche.sospensioni.eliminati')));
        eliminati.value = data.sospensioni ?? [];
    } catch {
        eliminati.value = [];
    }
}

function apriTogli(s: SospensioneAttiva) {
    daTogliere.value = s;
    motivo.value = '';
    erroreMotivo.value = '';
}

async function togli() {
    if (!daTogliere.value || togliendo.value) return;
    if (motivo.value.trim() === '') {
        erroreMotivo.value = 'Scrivi perché togli la sospensione.';
        return;
    }
    togliendo.value = true;
    erroreMotivo.value = '';
    try {
        const { data } = await axios.delete<{ sospensioni?: SospensioneAttiva[] }>(
            route(generateRoute('registro-modifiche.sospensioni.togli'), { condominio_id: daTogliere.value.condominio_id }), { data: { motivo: motivo.value } });
        eliminati.value = data.sospensioni ?? [];
        daTogliere.value = null;
        await carica();
    } catch (e) {
        erroreMotivo.value = messaggioDiErrore(e, 'motivo');
    } finally {
        togliendo.value = false;
    }
}

async function carica() {
    errore.value = '';
    try {
        const parametri = props.condominioId ? { condominio: props.condominioId } : {};
        const { data } = await axios.get<StatoConservazione>(route(generateRoute('registro-modifiche.conservazione.mostra'), parametri));
        stato.value = data;
        scelta.value = data.anni;
        if (props.forma === 'scelta' && data.puo_cambiare && !caricati) {
            caricati = true;
            await caricaEliminati();
        }
    } catch (e) {
        errore.value = messaggioDiErrore(e);
    }
}
let caricati = false;

async function salva() {
    if (!cambiata.value || salvando.value) return;
    salvando.value = true;
    salvato.value = '';
    erroreSalvataggio.value = '';
    try {
        const { data } = await axios.put<StatoConservazione>(route(generateRoute('registro-modifiche.conservazione.cambia')), { anni: scelta.value });
        stato.value = { ...data, sospensione: stato.value?.sospensione, sospensioni: data.sospensioni ?? stato.value?.sospensioni };
        scelta.value = data.anni;
        salvato.value = `Salvato: le righe si tengono ${data.adesso}.`;
        emit('cambiata', data);
    } catch (e) {
        erroreSalvataggio.value = messaggioDiErrore(e, 'anni');
    } finally {
        salvando.value = false;
    }
}

onMounted(carica);
</script>

<template>
    <!-- La card «Conservazione» della pagina del condominio: la durata e la frase, e la sospensione se c'è. -->
    <div v-if="forma === 'riquadro'" data-testid="conservazione-riquadro">
        <template v-if="stato">
            <div class="text-xl font-bold tabular-nums">{{ stato.adesso }}</div>
            <div class="mt-0.5 text-xs text-muted-foreground">{{ stato.frase }}</div>
            <div v-if="fraseSospensioni" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400" data-testid="conservazione-sospesa">
                {{ fraseSospensioni }}
            </div>
        </template>
        <div v-else-if="errore" class="text-xs text-muted-foreground">{{ errore }}</div>
        <div v-else class="space-y-2"><Skeleton class="h-6 w-20" /><Skeleton class="h-3 w-48" /></div>
    </div>

    <!-- Il registro dello studio: la carta della bozza (scena 4) con la scelta della durata. -->
    <component :is="conCarta ? Card : 'div'" v-else data-testid="conservazione-scelta">
        <CardHeader v-if="conCarta" class="pb-3 border-b">
            <CardTitle class="text-base font-semibold">{{ TITOLO_CARTA_DURATA }}</CardTitle>
            <CardDescription>{{ DESCRIZIONE_CARTA_DURATA }}</CardDescription>
        </CardHeader>
        <component :is="conCarta ? CardContent : 'div'" :class="conCarta ? 'pt-4' : ''">
            <div v-if="errore" class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground" data-testid="conservazione-errore">
                <span>{{ errore }}</span>
                <Button variant="outline" size="sm" class="h-8 gap-2" @click="carica"><RotateCw class="h-4 w-4" />Riprova</Button>
            </div>
            <div v-else-if="!stato" class="space-y-2"><Skeleton class="h-4 w-56" /><Skeleton class="h-9 w-full max-w-xs" /></div>
            <div v-else class="space-y-2">
                <div class="flex items-center gap-1.5 text-sm font-medium">
                    <label for="durata-del-registro">{{ ETICHETTA_DURATA }}</label>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button type="button" class="inline-flex text-muted-foreground" aria-label="Un esempio"><Info class="h-4 w-4" /></button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs text-xs">{{ esempio }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <vSelect
                        v-model="scelta"
                        input-id="durata-del-registro"
                        :options="opzioni"
                        label="nome"
                        :reduce="(o: { valore: number }) => o.valore"
                        :clearable="false"
                        :searchable="false"
                        :disabled="!stato.puo_cambiare || salvando"
                        class="w-full max-w-xs"
                        data-testid="durata"
                    />
                    <Button v-if="stato.puo_cambiare" size="sm" class="h-8 gap-2" :disabled="!cambiata || salvando" data-testid="salva-durata" @click="salva">
                        <LoaderCircle v-if="salvando" class="h-4 w-4 animate-spin" />
                        Salva la durata
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground" data-testid="adesso">{{ rigaAdesso(stato) }}</p>
                <p v-if="!stato.puo_cambiare" class="text-xs text-muted-foreground">La durata la sceglie l'amministratore.</p>
                <p v-if="salvato" class="text-xs font-medium text-emerald-700 dark:text-emerald-400" aria-live="polite" data-testid="salvato">{{ salvato }}</p>
                <p v-if="erroreSalvataggio" class="text-xs text-red-600 dark:text-red-400" aria-live="polite" data-testid="errore-salvataggio">{{ erroreSalvataggio }}</p>

                <!-- I condomini eliminati con la cancellazione sospesa: la pagina del loro registro non c'è più, la sospensione si toglie da qui. -->
                <div v-if="eliminati.length" class="space-y-2 pt-3" data-testid="eliminati">
                    <div class="text-sm font-medium">Condomini eliminati con la cancellazione sospesa</div>
                    <p class="text-xs text-muted-foreground">Il condominio non c'è più, ma le sue righe restano finché non togli la sospensione.</p>
                    <ul class="divide-y rounded-lg border">
                        <li v-for="e in eliminati" :key="e.condominio_id" class="flex flex-wrap items-center gap-3 px-3 py-2" :data-testid="`eliminato-${e.condominio_id}`">
                            <div class="min-w-0 flex-1 text-xs">
                                <div class="text-sm font-medium">{{ e.nome }}</div>
                                <div class="text-muted-foreground">sospesa dal {{ e.dal }} da {{ e.da }}<template v-if="e.motivo">: {{ e.motivo }}</template></div>
                            </div>
                            <Button variant="outline" size="sm" class="h-8" @click="apriTogli(e)">Togli la sospensione</Button>
                        </li>
                    </ul>
                </div>
            </div>
        </component>
    </component>

    <Dialog :open="daTogliere !== null" @update:open="(v: boolean) => { if (!v) daTogliere = null }">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="togli">
                <DialogHeader class="space-y-2">
                    <DialogTitle>Togli la sospensione</DialogTitle>
                    <DialogDescription>
                        {{ daTogliere?.nome }}, condominio eliminato: cancellazione sospesa dal {{ daTogliere?.dal }}<template v-if="daTogliere?.motivo">: {{ daTogliere?.motivo }}</template>.
                        Tolta la sospensione, le sue righe più vecchie della durata si tolgono la notte dopo; la fine della sospensione è una riga del registro.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="motivo-eliminato">Perché la togli</Label>
                    <Textarea id="motivo-eliminato" v-model="motivo" rows="3" maxlength="1000" placeholder="causa chiusa con la sentenza del …" data-testid="motivo-eliminato" />
                    <InputError :message="erroreMotivo" />
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="daTogliere = null">Annulla</Button>
                    <Button type="submit" :disabled="togliendo">
                        <LoaderCircle v-if="togliendo" class="h-4 w-4 animate-spin" />
                        Togli la sospensione
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
