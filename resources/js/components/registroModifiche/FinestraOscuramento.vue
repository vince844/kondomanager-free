<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra «Oscura i dati di … nel registro» (1.12.0-beta.13, §10 del progetto, P6, P9, decisione 518 (2)), costruita come la bozza (scena 4). La apre il
 * pulsante «Oscura i suoi dati nel registro» della «Storia» di una persona o di un fornitore, solo per l'amministratore (l'evento `oscura` della «Storia»
 * porta `{ soggetto_tipo, soggetto_id, nome }`, che sono le props di questa finestra).
 *
 * Carica da sé l'anteprima (`GET registro-modifiche/oscuramento/{tipo}/{id}`): quante righe con i suoi dati, dal … al …, «Vedi le righe»; la frase su che
 * cosa resta e che cosa diventa «oscurato il …»; la nota sulle eccezioni della legge (art. 17 par. 3) e sulla lite aperta; il file di un ripristino rimasto
 * con le righe di prima, e il giorno in cui si toglie da solo (l'oscuramento non lo raggiunge); le righe di altre schede che
 * contengono un suo dato, che l'amministratore sceglie una per una; il perché obbligatorio; «Oscura N righe». Dopo, l'esito, e «Chiudi».
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { CircleCheck, Info, LoaderCircle, RotateCw } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/composables/permissions';
import { messaggioDiErrore, testoPulsanteOscura, testoRigheDellaPersona } from '@/lib/registroModifiche/casiSpeciali';

interface RigaInElenco { id: number; quando: string; oggetto: string; azione: string; chi: string; cosa: string[]; contiene?: string }
interface Anteprima {
    /** `descrizione`: la riga sotto il titolo, composta dal server («Condòmina di Via Roma 12, Int. 7», «Ex condòmino: ha venduto l'Int. 11 il …»). */
    soggetto: { tipo: string; id: number; nome: string; parola: string; condomini: string; descrizione?: string };
    righe: { quante: number; dal: string | null; al: string | null; elenco: RigaInElenco[] };
    altre: { quante: number; elenco: RigaInElenco[] };
    oggi: string;
    sostituzione: string;
    /** Un file di un ripristino rimasto con le righe di prima: «C'è un file del ripristino del 03/03/2027 con le righe di prima: si toglie da solo il …». */
    file_rimasti?: string[];
}

const props = withDefaults(defineProps<{ soggetto_tipo: 'anagrafica' | 'fornitore'; soggetto_id: number; nome: string; open?: boolean }>(), { open: true });
const emit = defineEmits<{ (e: 'update:open', aperta: boolean): void; (e: 'oscurato', esito: { righe: number; oscurato_il: string; riga_id: number }): void }>();

const { generateRoute } = usePermission();

const anteprima = ref<Anteprima | null>(null);
const erroreAnteprima = ref('');
const vediLeRighe = ref(false);
const scelte = ref<number[]>([]);
const perche = ref('');
const errore = ref('');
const inCorso = ref(false);
const esito = ref('');

const quante = computed(() => (anteprima.value?.righe.quante ?? 0) + scelte.value.length);
const indirizzo = (nome: string) => route(generateRoute(`registro-modifiche.oscuramento.${nome}`), { soggetto_tipo: props.soggetto_tipo, soggetto_id: props.soggetto_id });
const descrizione = computed(() => {
    const s = anteprima.value?.soggetto;
    if (!s) return '';
    if (s.descrizione) return s.descrizione;

    return s.condomini ? `${s.parola} · ${s.condomini}` : s.parola;
});

async function carica() {
    anteprima.value = null;
    erroreAnteprima.value = '';
    esito.value = '';
    scelte.value = [];
    perche.value = '';
    errore.value = '';
    vediLeRighe.value = false;
    try {
        const { data } = await axios.get<Anteprima>(indirizzo('anteprima'));
        anteprima.value = data;
    } catch (e) {
        erroreAnteprima.value = messaggioDiErrore(e);
    }
}

function alterna(id: number, scelta: boolean) {
    scelte.value = scelta ? [...new Set([...scelte.value, id])] : scelte.value.filter((x) => x !== id);
}

async function oscura() {
    if (inCorso.value || quante.value === 0) return;
    if (perche.value.trim() === '') {
        errore.value = 'Scrivi perché oscuri i suoi dati: per esempio «Richiesta scritta del 10/03/2027, protocollo 41».';
        return;
    }
    inCorso.value = true;
    errore.value = '';
    try {
        const { data } = await axios.post(indirizzo('oscura'), { perche: perche.value, altre: scelte.value });
        esito.value = data.esito;
        emit('oscurato', { righe: data.righe, oscurato_il: data.oscurato_il, riga_id: data.riga_id });
    } catch (e) {
        errore.value = messaggioDiErrore(e, 'perche');
    } finally {
        inCorso.value = false;
    }
}

function chiudi() {
    emit('update:open', false);
}

watch(() => [props.open, props.soggetto_tipo, props.soggetto_id], ([aperta]) => {
    if (aperta) carica();
}, { immediate: true });
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogScrollContent class="sm:max-w-xl">
            <DialogHeader class="space-y-1">
                <DialogTitle>Oscura i dati di {{ nome }} nel registro</DialogTitle>
                <DialogDescription>{{ descrizione || 'I suoi dati nelle righe del registro delle modifiche.' }}</DialogDescription>
            </DialogHeader>

            <!-- Dopo: l'esito. -->
            <div v-if="esito" class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300" data-testid="esito">
                <CircleCheck class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <div class="font-medium">{{ esito }}</div>
                    <div class="text-xs">L'oscuramento è una riga del registro, con il tuo nome e il perché.</div>
                </div>
            </div>

            <div v-else-if="erroreAnteprima" class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground" data-testid="errore-anteprima">
                <span>{{ erroreAnteprima }}</span>
                <Button variant="outline" size="sm" class="h-8 gap-2" @click="carica"><RotateCw class="h-4 w-4" />Riprova</Button>
            </div>

            <div v-else-if="!anteprima" class="space-y-3" data-testid="caricamento">
                <Skeleton class="h-10 w-full" /><Skeleton class="h-4 w-3/4" /><Skeleton class="h-16 w-full" />
            </div>

            <form v-else class="flex flex-col gap-4" @submit.prevent="oscura">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-slate-50 px-3 py-2 text-sm dark:bg-slate-900/40" data-testid="quante">
                    <Info class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1">{{ testoRigheDellaPersona(anteprima.righe.quante, anteprima.righe.dal, anteprima.righe.al) }}</span>
                    <button
                        v-if="anteprima.righe.quante > 0"
                        type="button"
                        class="text-xs font-medium underline underline-offset-2"
                        data-testid="vedi-le-righe"
                        @click="vediLeRighe = !vediLeRighe"
                    >{{ vediLeRighe ? 'Nascondi le righe' : 'Vedi le righe' }}</button>
                </div>

                <ul v-if="vediLeRighe" class="divide-y rounded-lg border text-xs" data-testid="righe">
                    <li v-for="r in anteprima.righe.elenco" :key="r.id" class="px-3 py-2">
                        <div class="flex flex-wrap gap-x-2"><span class="tabular-nums text-muted-foreground">{{ r.quando }}</span><span class="font-medium">{{ r.oggetto }}</span></div>
                        <div class="text-muted-foreground">Si oscura: {{ r.cosa.join(', ') }}</div>
                    </li>
                    <li v-if="anteprima.righe.quante > anteprima.righe.elenco.length" class="px-3 py-2 text-muted-foreground">
                        e altre {{ anteprima.righe.quante - anteprima.righe.elenco.length }} righe
                    </li>
                </ul>

                <p class="text-xs text-muted-foreground">
                    Nel registro restano chi, quando e su che cosa. I suoi dati, nei valori di prima e di dopo, diventano «{{ anteprima.sostituzione }}».
                </p>

                <div class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        La legge ammette eccezioni alla cancellazione, per esempio un obbligo di conservazione o la difesa in giudizio (art. 17 par. 3 del
                        regolamento europeo). Se c'è una lite aperta con questa persona, valuta prima di oscurare: la scelta è tua.
                    </div>
                </div>

                <div v-if="anteprima.file_rimasti?.length" class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <div class="space-y-1">
                        <p v-for="(f, i) in anteprima.file_rimasti" :key="i" data-testid="file-rimasti">{{ f }}</p>
                    </div>
                </div>

                <div v-if="anteprima.altre.quante > 0" class="space-y-2" data-testid="altre">
                    <div class="text-sm font-medium">Anche in altre righe</div>
                    <p class="text-xs text-muted-foreground">
                        Queste righe di altre schede contengono un suo dato. Si oscura solo il suo dato, e solo nelle righe che scegli.
                    </p>
                    <ul class="divide-y rounded-lg border">
                        <li v-for="r in anteprima.altre.elenco" :key="r.id" class="flex items-start gap-3 px-3 py-2">
                            <Checkbox
                                :id="`altra-${r.id}`"
                                class="mt-0.5"
                                :model-value="scelte.includes(r.id)"
                                :data-testid="`altra-${r.id}`"
                                @update:model-value="(v: boolean | 'indeterminate') => alterna(r.id, v === true)"
                            />
                            <label :for="`altra-${r.id}`" class="min-w-0 flex-1 text-xs">
                                <span class="tabular-nums text-muted-foreground">{{ r.quando }}</span>
                                <span class="font-medium"> · {{ r.oggetto }}</span>
                                <span class="block text-muted-foreground">contiene «{{ r.contiene }}»</span>
                            </label>
                        </li>
                    </ul>
                </div>

                <div class="grid gap-2">
                    <Label for="perche-oscuramento">Perché</Label>
                    <Textarea id="perche-oscuramento" v-model="perche" rows="2" maxlength="2000" placeholder="Richiesta scritta del 10/03/2027, protocollo 41" data-testid="perche" />
                    <InputError :message="errore" />
                </div>
                <p class="text-xs text-muted-foreground">L'oscuramento è a sua volta una riga del registro, e non si annulla.</p>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="chiudi">Annulla</Button>
                    <Button type="submit" :disabled="inCorso || quante === 0" data-testid="oscura">
                        <LoaderCircle v-if="inCorso" class="h-4 w-4 animate-spin" />
                        {{ testoPulsanteOscura(quante) }}
                    </Button>
                </DialogFooter>
            </form>

            <DialogFooter v-if="esito" class="gap-2">
                <Button type="button" @click="chiudi">Chiudi</Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
