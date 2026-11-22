<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Registra una delibera a mano» (§3 P5; scena 4 della bozza approvata con la decisione 502): una riunione di carta, prima di usare il programma o tenuta
 * senza il modulo. Il giorno della riunione (non dopo oggi), ordinaria o straordinaria (di serie «Ordinaria»), per chi, che cosa ha deciso, l'esito, il
 * numero del verbale di carta con la riga «Entra nella riunione del …» e, **solo con «Registra il verbale sottoscritto»**, il verbale (decisioni 562 e 563:
 * senza, la delibera nasce con «Verbale da caricare» e il campo del file non compare).
 *
 * Aperta da una pagina porta con sé l'origine e ciò di cui parla (R8): la proposta la trova al livello 1, e la materia è quella della pagina; aperta
 * dall'archivio si sceglie «Di che cosa parla», perché le pagine la propongano per la stessa materia. Dopo il clic su «Registra la delibera» la
 * delibera torna a chi ha aperto la finestra, che la sceglie (R3). Al telefono la finestra va a tutta altezza, i campi uno sotto l'altro.
 */
import { computed, ref, useId, watch } from 'vue';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { messaggioDellErrore } from '@/lib/assemblee/assemblee';
import type { DeliberaDelMenu, RuoloCollegamento } from '@/types/assemblee';

const props = withDefaults(defineProps<{
    open: boolean;
    condominioId: number;
    ruolo?: RuoloCollegamento;
    oggettoTipo?: string | null;
    oggettoId?: number | null;
    parlaDi?: [string, number][];
    testo?: string | null;
}>(), { ruolo: 'delibera', oggettoTipo: null, oggettoId: null, parlaDi: () => [], testo: null });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'registrata', v: DeliberaDelMenu): void }>();

interface Scelta { valore: string; nome: string }
interface Destinazione {
    palazzine: { id: number; nome: string }[];
    scale: { id: number; nome: string }[];
    frase: string | null;
    riunione: { id: number; nome: string } | null;
    riunioni: { id: number; nome: string }[];
    materie?: { valore: string; nome: string }[];
}

const { generateRoute, hasPermission } = usePermission();
const caricaIlVerbale = computed(() => hasPermission([Permission.REGISTER_VERBALE_SOTTOSCRITTO]));
const oggi = giornoDiRoma();

const giorno = ref('');
const tipo = ref('ordinaria');
const perChi = ref('condominio');
const testoDeciso = ref('');
/** Di che cosa parla: si sceglie solo dall'archivio; aperta da una pagina, è quella della pagina (la mette il server). */
const materia = ref('altro');
const materie = computed<Scelta[]>(() => destinazione.value?.materie ?? [{ valore: 'altro', nome: 'Altro' }]);
const esito = ref('approvata');
const numero = ref('');
const file = ref<File | null>(null);
const riunioneScelta = ref<number | null>(null);
const cambia = ref(false);
const destinazione = ref<Destinazione | null>(null);
const errore = ref<string | null>(null);
const inCorso = ref(false);

const tipi: Scelta[] = [{ valore: 'ordinaria', nome: 'Ordinaria' }, { valore: 'straordinaria', nome: 'Straordinaria' }];
const esiti: Scelta[] = [
    { valore: 'approvata', nome: 'Approvata' }, { valore: 'approvata_con_modifiche', nome: 'Approvata con modifiche' },
    { valore: 'respinta', nome: 'Respinta' }, { valore: 'rinviata', nome: 'Rinviata' }, { valore: 'senza_voto', nome: 'Senza voto' },
];
const perimetri = computed<Scelta[]>(() => [
    { valore: 'condominio', nome: 'Tutto il condominio' },
    ...(destinazione.value?.palazzine ?? []).map((p) => ({ valore: `palazzina:${p.id}`, nome: `La palazzina ${p.nome}` })),
    ...(destinazione.value?.scale ?? []).map((s) => ({ valore: `scala:${s.id}`, nome: `La scala ${s.nome}` })),
]);
const giornoDopoOggi = computed(() => giorno.value !== '' && giorno.value > oggi);
const puoRegistrare = computed(() => giorno.value !== '' && !giornoDopoOggi.value && testoDeciso.value.trim() !== '' && !inCorso.value);

function perimetroDati() {
    const [ambito, id] = perChi.value.split(':');
    return { ambito, palazzina_id: ambito === 'palazzina' ? Number(id) : undefined, scala_id: ambito === 'scala' ? Number(id) : undefined };
}

let lettura = 0;
async function leggiLaDestinazione() {
    const numeroLettura = ++lettura;
    try {
        const r = await axios.get(route(generateRoute('gestionale.delibere.a-mano.destinazione'), { condominio: props.condominioId }), {
            params: { giorno: giorno.value || undefined, numero_verbale: numero.value || undefined, ...perimetroDati() },
        });
        if (numeroLettura === lettura) {
            destinazione.value = r.data as Destinazione;
        }
    } catch {
        // La riga sotto il numero è un aiuto: se non arriva, la registrazione dice comunque dove entra.
    }
}

let attesa: ReturnType<typeof setTimeout> | null = null;
watch(() => [giorno.value, numero.value, perChi.value], () => {
    riunioneScelta.value = null;
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(leggiLaDestinazione, 250);
});

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    giorno.value = '';
    tipo.value = 'ordinaria';
    perChi.value = 'condominio';
    testoDeciso.value = props.testo ?? '';
    materia.value = 'altro';
    esito.value = 'approvata';
    numero.value = '';
    file.value = null;
    riunioneScelta.value = null;
    cambia.value = false;
    errore.value = null;
    leggiLaDestinazione();
}, { immediate: true });

async function registra() {
    if (!puoRegistrare.value) return;
    inCorso.value = true;
    errore.value = null;
    const dati = new FormData();
    const per = perimetroDati();
    const campi: Record<string, string | number | undefined> = {
        giorno: giorno.value, tipo: tipo.value, ambito: per.ambito, palazzina_id: per.palazzina_id, scala_id: per.scala_id, testo: testoDeciso.value,
        esito: esito.value, numero_verbale: numero.value || undefined, riunione_id: riunioneScelta.value ?? undefined, ruolo: props.ruolo,
        oggetto_tipo: props.oggettoTipo ?? undefined, oggetto_id: props.oggettoId ?? undefined,
        materia: props.oggettoTipo ? undefined : materia.value,
    };
    Object.entries(campi).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') dati.append(k, String(v)); });
    props.parlaDi.forEach(([t, id], i) => { dati.append(`parla_di[${i}][tipo]`, t); dati.append(`parla_di[${i}][id]`, String(id)); });
    if (file.value && caricaIlVerbale.value) dati.append('verbale', file.value);
    try {
        const r = await axios.post(route(generateRoute('gestionale.delibere.a-mano'), { condominio: props.condominioId }), dati);
        emit('registrata', r.data.delibera as DeliberaDelMenu);
        emit('update:open', false);
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'La delibera non è stata registrata. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const etichetta = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400';
/** Il campo del numero del verbale: la sua etichetta lo dice con `for`, perché la «i» accanto è un pulsante e non può stare dentro l'etichetta. */
const idDelNumero = useId();
const input = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs placeholder:text-slate-400 dark:bg-slate-950';
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[560px]" data-testid="finestra-delibera-a-mano" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Registra una delibera a mano</DialogTitle>
                <DialogDescription>Resta nell'archivio delle assemblee, segnata «a mano».</DialogDescription>
            </DialogHeader>

            <div class="space-y-3.5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block"><span :class="etichetta">Il giorno della riunione</span>
                        <CampoGiorno v-model="giorno" :max="oggi" data-testid="giorno" />
                        <span v-if="giornoDopoOggi" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">Il giorno della riunione non può essere dopo oggi.</span>
                    </label>
                    <div class="menu-campo"><span :class="etichetta">Ordinaria o straordinaria</span>
                        <v-select v-model="tipo" :options="tipi" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" data-testid="tipo" />
                    </div>
                </div>
                <div class="menu-campo"><span :class="etichetta">Per chi</span>
                    <v-select v-model="perChi" :options="perimetri" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="perimetri.length > 8" />
                </div>
                <label class="block"><span :class="etichetta">Che cosa ha deciso</span>
                    <textarea v-model="testoDeciso" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" data-testid="testo" />
                </label>
                <div v-if="!oggettoTipo" class="menu-campo" data-testid="materia"><span :class="etichetta">Di che cosa parla</span>
                    <v-select v-model="materia" :options="materie" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="materie.length > 8" />
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Le pagine propongono la delibera dove parla della stessa cosa: la dispensa, un accordo, i lavori…</span>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="menu-campo"><span :class="etichetta">L'esito</span>
                        <v-select v-model="esito" :options="esiti" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" />
                    </div>
                    <div>
                        <span :class="etichetta" class="flex items-center gap-1"><label :for="idDelNumero">Numero del verbale</label>
                            <SuggerimentoDellaI testid="i-numero-verbale"
                                testo="Il numero scritto sul verbale di carta, per esempio 3/2024. Due delibere della stessa sera con lo stesso numero stanno nella stessa riunione." />
                        </span>
                        <input :id="idDelNumero" v-model="numero" type="text" placeholder="3/2024" :class="input" data-testid="numero" />
                    </div>
                </div>
                <p v-if="destinazione?.frase" class="text-xs text-slate-600 dark:text-slate-300" data-testid="destinazione">
                    {{ riunioneScelta ? `Entra in ${destinazione.riunioni.find((r) => r.id === riunioneScelta)?.nome ?? 'la riunione scelta'}` : destinazione.frase }}
                    <button v-if="destinazione.riunioni.length > 0" type="button" class="ml-1 font-medium underline" @click="cambia = !cambia">Cambia</button>
                </p>
                <div v-if="cambia && destinazione" class="space-y-1 rounded-md border border-slate-200 p-2 dark:border-slate-700">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Le riunioni registrate a mano dello stesso giorno:</p>
                    <label v-for="r in destinazione.riunioni" :key="r.id" class="flex items-center gap-2 text-sm">
                        <input v-model="riunioneScelta" type="radio" :value="r.id" /> {{ r.nome }}
                    </label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="riunioneScelta" type="radio" :value="null" /> Una riunione nuova</label>
                </div>

                <div v-if="caricaIlVerbale">
                    <span :class="etichetta">Il verbale sottoscritto (scansione o PDF firmato, .p7m)</span>
                    <CampoFile v-model="file" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="file" class="mt-1" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Facoltativo: senza, la delibera si registra con «Verbale da caricare», e il verbale lo aggiungi dopo.</p>
                </div>
                <p v-else class="text-xs text-slate-500 dark:text-slate-400" data-testid="verbale-da-caricare">
                    La delibera si registra con «Verbale da caricare»: il verbale sottoscritto lo carica chi ha il permesso «Registra il verbale sottoscritto».
                </p>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Dopo, la scegli come le altre: in questa pagina, nelle ratifiche, nelle dispense.
                </p>
                <p v-if="errore" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore">{{ errore }}</p>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                <Button :disabled="!puoRegistrare" data-testid="registra" @click="registra">{{ inCorso ? 'Un momento…' : 'Registra la delibera' }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
