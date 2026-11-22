<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La sezione «Il verbale dell'assemblea» della pagina «Modelli delle lettere» (scena 9 della bozza delle scene): il testo del programma (non si modifica, si
 * duplica) e i modelli dello studio con «vale per», chi li ha cambiati e quando; la finestra di un modello con lo stesso editor e la stessa barra del verbale,
 * due testi («Prima dei punti» e «Dopo i punti») e in mezzo la parte «Dati della sala», che il programma riempie; i nove campi; l'anteprima con un'assemblea
 * di esempio. Le regole dei modelli delle lettere: il nome è unico per «vale per». Cambiare un modello non cambia i verbali già generati.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import EditorDelVerbale from './EditorDelVerbale.vue';
import TestoDelVerbale from './TestoDelVerbale.vue';
import { AIUTO, CONTENUTO_LARGO, CORPO, GRUPPO, PIEDE, PULSANTE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import { ETICHETTA_DELLA_SALA, PARTE_DELLA_SALA } from './stili';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import { giorno } from '@/lib/assemblee/verbale/pagina';

export interface ModelloDelVerbaleDellaPagina {
    id: number;
    nome: string;
    prima: NodoDelVerbale;
    dopo: NodoDelVerbale;
    condominio: { id: number; nome: string } | null;
    cambiato_da: string | null;
    cambiato_il: string | null;
}
export interface SezioneDelVerbale {
    testo_del_programma: { prima: NodoDelVerbale; dopo: NodoDelVerbale };
    modelli: ModelloDelVerbaleDellaPagina[];
    campi: Record<string, string>;
}

const props = defineProps<{ verbale: SezioneDelVerbale; condomini: Array<{ id: number; nome: string }>; puoModificare: boolean; filtroCondominio: string | null }>();

/** I valori dell'anteprima: l'assemblea di esempio di Via Roma 12. */
const ESEMPIO: Record<string, string> = {
    '[condominio]': 'Condominio Via Roma 12', '[tipo]': 'ordinaria', '[giorno]': '15 dicembre 2026', '[ora]': '18:42', '[luogo]': 'la sala condominiale di Via Roma 12',
    '[convocazione]': 'seconda convocazione, essendo andata deserta la prima del 14 dicembre 2026', '[presidente]': 'la sig.ra Ida Romano', '[segretario]': 'Andrea Costa',
    '[ora_di_chiusura]': '20:55',
};
function conIlEsempio(n: NodoDelVerbale): NodoDelVerbale {
    if (n.type === 'text' && n.text) return { ...n, text: Object.entries(ESEMPIO).reduce((t, [k, v]) => t.split(k).join(v), n.text) };
    return n.content ? { ...n, content: n.content.map(conIlEsempio) } : n;
}

const scelteValePer = computed(() => [{ valore: 'tutti', nome: 'Tutti i condomini' }, ...props.condomini.map((c) => ({ valore: String(c.id), nome: c.nome }))]);
const modelli = computed(() => props.verbale.modelli.filter((m) => !props.filtroCondominio || (props.filtroCondominio === 'tutti' ? m.condominio === null
    : m.condominio === null || String(m.condominio.id) === props.filtroCondominio)));

interface Modulo { id: number | null; nome: string; valePer: string | null; prima: NodoDelVerbale; dopo: NodoDelVerbale }
const modulo = ref<Modulo | null>(null);
const aperto = computed({ get: () => modulo.value !== null, set: (a: boolean) => { if (!a) modulo.value = null; } });
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
const copia = (nome: string) => `${nome} (copia)`;
function apri(m: Modulo) { errori.value = {}; modulo.value = JSON.parse(JSON.stringify(m)); }
const nuovo = () => apri({ id: null, nome: '', valePer: 'tutti', ...props.verbale.testo_del_programma });
const duplicaIlProgramma = () => apri({ id: null, nome: 'Verbale dello studio', valePer: 'tutti', ...props.verbale.testo_del_programma });
const modifica = (m: ModelloDelVerbaleDellaPagina) => apri({ id: m.id, nome: m.nome, valePer: m.condominio ? String(m.condominio.id) : 'tutti', prima: m.prima, dopo: m.dopo });
const duplica = (m: ModelloDelVerbaleDellaPagina) => apri({ id: null, nome: copia(m.nome), valePer: m.condominio ? String(m.condominio.id) : 'tutti', prima: m.prima, dopo: m.dopo });
const anteprima = computed(() => (modulo.value ? { prima: conIlEsempio(modulo.value.prima), dopo: conIlEsempio(modulo.value.dopo) } : null));
const salvabile = computed(() => !!modulo.value && modulo.value.nome.trim() !== '' && !!modulo.value.valePer);
function salva() {
    const m = modulo.value;
    if (!m || !salvabile.value) return;
    inCorso.value = true;
    const corpo = { nome: m.nome.trim(), prima: m.prima, dopo: m.dopo, condominio_id: m.valePer === 'tutti' ? null : Number(m.valePer) };
    const opzioni = { preserveScroll: true, onSuccess: () => { modulo.value = null; }, onError: (e: Record<string, string>) => { errori.value = e; }, onFinish: () => { inCorso.value = false; } };
    if (m.id === null) router.post(route('impostazioni.stampe.modelli-verbale.store'), corpo as never, opzioni);
    else router.put(route('impostazioni.stampe.modelli-verbale.update', m.id), corpo as never, opzioni);
}
const daEliminare = ref<ModelloDelVerbaleDellaPagina | null>(null);
const conferma = computed({ get: () => daEliminare.value !== null, set: (a: boolean) => { if (!a) daEliminare.value = null; } });
function elimina() {
    if (daEliminare.value) router.delete(route('impostazioni.stampe.modelli-verbale.destroy', daEliminare.value.id), { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-2" data-testid="tipo-verbale">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">Il verbale dell'assemblea</h2>
            <Button v-if="puoModificare" size="sm" variant="outline" class="h-8 text-xs" data-testid="nuovo-modello-verbale" @click="nuovo">Nuovo modello</Button>
        </div>
        <div class="space-y-2 rounded-lg border border-dashed bg-slate-50/60 p-3 dark:bg-slate-900/30" data-testid="programma-verbale">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">Testo del programma</span>
                <span class="inline-flex items-center rounded-md border px-2 py-px text-xs font-medium text-slate-600 dark:text-slate-300">Non si modifica</span>
                <Button v-if="puoModificare" size="sm" variant="outline" class="ml-auto h-7 text-xs" data-testid="duplica-programma-verbale" @click="duplicaIlProgramma">Duplica</Button>
            </div>
            <p :class="AIUTO">Il testo di partenza. Per cambiarlo, duplicalo: diventa un modello dello studio.</p>
            <TestoDelVerbale :documento="verbale.testo_del_programma.prima" />
            <p :class="[PARTE_DELLA_SALA, 'text-xs']"><span :class="ETICHETTA_DELLA_SALA">Dati della sala</span><span class="block">… qui i dati della sala e i punti …</span></p>
            <TestoDelVerbale :documento="verbale.testo_del_programma.dopo" />
        </div>
        <div v-for="m in modelli" :key="m.id" class="space-y-1.5 rounded-lg border p-3" data-testid="modello-verbale">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ m.nome }}</span>
                <span class="inline-flex items-center rounded-md border px-2 py-px text-xs font-medium text-slate-600 dark:text-slate-300">{{ m.condominio ? m.condominio.nome : 'Tutti i condomini' }}</span>
                <span v-if="m.cambiato_da" class="text-xs text-slate-500 dark:text-slate-400">cambiato da {{ m.cambiato_da }} il {{ giorno(m.cambiato_il) }}</span>
                <span v-if="puoModificare" class="ml-auto flex gap-1.5">
                    <button type="button" :class="PULSANTE" @click="modifica(m)">Modifica</button>
                    <button type="button" :class="PULSANTE" @click="duplica(m)">Duplica</button>
                    <button type="button" :class="[PULSANTE, 'text-rose-700 dark:text-rose-400']" @click="daEliminare = m">Elimina</button>
                </span>
            </div>
            <TestoDelVerbale :documento="m.prima" class="line-clamp-3" />
        </div>

        <Dialog v-model:open="aperto">
            <DialogContent :class="CONTENUTO_LARGO" data-testid="finestra-modello-verbale">
                <DialogHeader :class="TESTA">
                    <DialogTitle>{{ modulo?.id ? 'Modifica il modello' : 'Nuovo modello del verbale' }}</DialogTitle>
                    <DialogDescription>Il testo si riempie con i dati dell'assemblea; il verbale generato resta da correggere.</DialogDescription>
                </DialogHeader>
                <div v-if="modulo" :class="CORPO">
                    <section :class="GRUPPO">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="nome-modello-verbale" class="text-xs">Nome del modello</Label>
                                <Input id="nome-modello-verbale" v-model="modulo.nome" class="h-9 text-sm" data-testid="nome-modello-verbale" />
                                <InputError :message="errori.nome" />
                            </div>
                            <div class="space-y-1.5">
                                <Label class="text-xs">Vale per</Label>
                                <v-select v-model="modulo.valePer" :options="scelteValePer" label="nome" :reduce="(s: { valore: string }) => s.valore" :clearable="false"
                                    :searchable="scelteValePer.length > 8" />
                                <InputError :message="errori.condominio_id" />
                            </div>
                        </div>
                    </section>
                    <section :class="GRUPPO">
                        <p :class="TITOLO_GRUPPO">Prima dei punti</p>
                        <EditorDelVerbale v-model="modulo.prima" etichetta="Il testo prima dei punti" />
                        <InputError :message="errori.prima" />
                        <p :class="[PARTE_DELLA_SALA, 'text-xs text-slate-700 dark:text-slate-300']" data-testid="dati-della-sala-del-modello"><span :class="ETICHETTA_DELLA_SALA">Dati della sala</span>
                            <span class="block">Li mette il programma: la costituzione, i punti con la discussione e i voti con nomi e millesimi, le dichiarazioni, le note del presidente. Nel modello
                                non si scrivono.</span></p>
                        <p :class="TITOLO_GRUPPO">Dopo i punti</p>
                        <EditorDelVerbale v-model="modulo.dopo" etichetta="Il testo dopo i punti" />
                        <InputError :message="errori.dopo" />
                    </section>
                    <section :class="GRUPPO" data-testid="campi-del-verbale">
                        <p :class="TITOLO_GRUPPO">I campi, che si riempiono dall'assemblea</p>
                        <ul class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300">
                            <li v-for="(cosa, campo) in verbale.campi" :key="campo"><b class="font-semibold">{{ campo }}</b> {{ cosa }}</li>
                        </ul>
                    </section>
                    <section v-if="anteprima" :class="GRUPPO" data-testid="anteprima-del-modello">
                        <p :class="TITOLO_GRUPPO">Anteprima, con l'assemblea di Via Roma 12 del 15/12/2026</p>
                        <TestoDelVerbale :documento="anteprima.prima" :vuoto="''" />
                        <p class="text-xs text-slate-500 dark:text-slate-400">… qui i dati della sala e i punti …</p>
                        <TestoDelVerbale :documento="anteprima.dopo" :vuoto="''" />
                    </section>
                </div>
                <div :class="PIEDE">
                    <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                        <span>Cambiare un modello non cambia i verbali già generati.</span></p>
                    <div :class="PULSANTI">
                        <Button variant="outline" @click="modulo = null">Chiudi</Button>
                        <Button :disabled="!salvabile || inCorso" data-testid="salva-modello-verbale" @click="salva">Salva il modello</Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
        <ConfirmDialog v-model="conferma" :title="`Eliminare «${daEliminare?.nome ?? ''}»?`" confirm-text="Elimina il modello"
            description="I verbali già generati non cambiano." @confirm="elimina" />
    </section>
</template>
