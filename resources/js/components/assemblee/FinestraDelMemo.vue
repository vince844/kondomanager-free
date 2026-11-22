<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra veloce del memo «Da portare in assemblea» (§3 P8; scena 2 della bozza approvata con la decisione 501): due righe da riscrivere se serve, e il
 * resto è già pronto. Il punto proposto dal contesto, da dove nasce con «Apri», per quale assemblea, la nota dello studio, «Ricordamelo» con la riga
 * «Adesso: …», il giorno e «A chi» (a me, a tutto lo studio, a un collaboratore). **«Ricordamelo dal» non accetta un giorno già passato**: la finestra lo
 * dice e «Salva il memo» resta spento; il server lo rifiuta comunque.
 *
 * Si apre da quindici posti: la proposta e il salvataggio passano da un JSON (`axios`). L'origine arriva come tipo e id, e il server la cerca nel condominio
 * (R18). Salvare un memo non collega niente (R3).
 *
 * **In modifica** (con `memo`, dal menu ⋯ del punto nella card «Per la prossima assemblea», decisione 574) si apre con i campi del memo e «Salva il memo» lo
 * cambia (`memo.update`); le righe per un memo nuovo («Già nella memoria…», «Il programma propone già…») non servono e non ci sono.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { usePermission } from '@/composables/permissions';
import { piuGiorni } from '@/lib/giorno';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { adessoDelMemo, messaggioDellErrore } from '@/lib/assemblee/assemblee';
import { CONTENUTO, CORPO, menuFuoriDallaFinestra, PIEDE, PULSANTI, restaSeNelMenu, RIEPILOGO, TESTA } from '@/components/assemblee/verbale/finestra';
import type { MemoDellaFinestra } from '@/lib/assemblee/pagine';

const props = withDefaults(defineProps<{
    open: boolean;
    condominioId: number;
    origineTipo?: string | null;
    origineId?: number | null;
    /** Un punto scritto da zero (la testata del condominio) o già pronto (il compito): sostituisce la proposta. */
    testo?: string | null;
    /** Un memo che c'è già: la finestra lo cambia invece di salvarne uno nuovo. */
    memo?: MemoDellaFinestra | null;
}>(), { origineTipo: null, origineId: null, testo: null, memo: null });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'salvato', frase: string): void }>();

interface Proposta {
    testo: string;
    origine: { tipo: string; id: number; nome: string; apri: string | null } | null;
    assemblee: { id: number; nome: string }[];
    collaboratori: { id: number; nome: string }[];
    gia_proposto: string | null;
    esistente: { id: number; frase: string } | null;
    condominio: string;
}
interface Scelta { valore: string | number; nome: string }

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();

const proposta = ref<Proposta | null>(null);
const testoDelPunto = ref('');
const nota = ref('');
const assembleaId = ref<number | null>(null);
const ricordami = ref(false);
const giorno = ref(piuGiorni(oggi, 7));
const aChi = ref('me');
const collaboratore = ref<number | null>(null);
const errore = ref<string | null>(null);
const inCorso = ref(false);

const condominio = computed(() => proposta.value?.condominio ?? '');
const assemblee = computed<Scelta[]>(() => [
    { valore: 0, nome: `La prossima di ${condominio.value} (tutto il condominio)` },
    ...(proposta.value?.assemblee ?? []).map((a) => ({ valore: a.id, nome: a.nome })),
]);
const chi: Scelta[] = [{ valore: 'me', nome: 'A me' }, { valore: 'studio', nome: 'A tutto lo studio' }, { valore: 'collaboratore', nome: 'A un collaboratore' }];
const collaboratori = computed<Scelta[]>(() => (proposta.value?.collaboratori ?? []).map((c) => ({ valore: c.id, nome: c.nome })));
const nomeDelCollaboratore = computed(() => proposta.value?.collaboratori.find((c) => c.id === collaboratore.value)?.nome ?? null);
const giornoPassato = computed(() => ricordami.value && giorno.value !== '' && giorno.value < oggi);
const adesso = computed(() => adessoDelMemo(ricordami.value, giorno.value, aChi.value, nomeDelCollaboratore.value, condominio.value));
const puoSalvare = computed(() => testoDelPunto.value.trim() !== '' && !giornoPassato.value && !(ricordami.value && giorno.value === '')
    && !(ricordami.value && aChi.value === 'collaboratore' && collaboratore.value === null) && !inCorso.value && proposta.value !== null);

watch(() => props.open, async (aperta) => {
    if (!aperta) return;
    errore.value = null;
    proposta.value = null;
    nota.value = '';
    assembleaId.value = null;
    ricordami.value = false;
    giorno.value = piuGiorni(oggi, 7);
    aChi.value = 'me';
    collaboratore.value = null;
    const m = props.memo;
    try {
        const r = await axios.get(route(generateRoute('gestionale.memo.proposta'), { condominio: props.condominioId }), {
            params: { origine_tipo: (m ? m.origine_tipo : props.origineTipo) ?? undefined, origine_id: (m ? m.origine_id : props.origineId) ?? undefined },
        });
        proposta.value = r.data as Proposta;
        testoDelPunto.value = m ? m.testo : (props.testo ?? proposta.value.testo ?? '');
        if (m) {
            nota.value = m.nota ?? '';
            assembleaId.value = m.assemblea_id;
            ricordami.value = !!m.ricordami_dal;
            giorno.value = m.ricordami_dal ?? piuGiorni(oggi, 7);
            aChi.value = m.a_chi ?? 'me';
            collaboratore.value = m.assegnato_a;
        }
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'La proposta non si è caricata. Riprova.');
    }
}, { immediate: true });

async function salva() {
    if (!puoSalvare.value) return;
    inCorso.value = true;
    errore.value = null;
    const campi = {
        testo: testoDelPunto.value,
        nota: nota.value || null,
        assemblea_id: assembleaId.value || null,
        ricordami: ricordami.value,
        ricordami_dal: ricordami.value ? giorno.value : null,
        a_chi: aChi.value,
        assegnato_a: aChi.value === 'collaboratore' ? collaboratore.value : null,
    };
    try {
        if (props.memo) {
            const r = await axios.patch(route(generateRoute('gestionale.memo.update'), { condominio: props.condominioId, memo: props.memo.id }), campi);
            emit('salvato', `Memo cambiato. ${r.data.frase as string}`);
            emit('update:open', false);
            return;
        }
        const r = await axios.post(route(generateRoute('gestionale.memo.store'), { condominio: props.condominioId }), {
            ...campi,
            origine_tipo: proposta.value?.origine?.tipo ?? null,
            origine_id: proposta.value?.origine?.id ?? null,
        });
        emit('salvato', `Memo salvato. ${r.data.frase as string}`);
        emit('update:open', false);
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'Il memo non è stato salvato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const etichetta = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400';
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <!-- Lo standard delle finestre (decisione 608): ancorata in alto, il contenuto scorre dentro (`min-w-0`: nulla esce a destra), il riepilogo e i pulsanti fissi in fondo. -->
        <DialogContent :class="[CONTENUTO, 'sm:max-w-[560px]']" data-testid="finestra-memo" @open-auto-focus.prevent @interact-outside="restaSeNelMenu">
            <DialogHeader :class="TESTA">
                <DialogTitle>{{ memo ? 'Modifica il memo' : 'Da portare in assemblea' }}</DialogTitle>
                <DialogDescription>Lo trovi proposto quando prepari la prossima assemblea{{ condominio ? ` di ${condominio}` : '' }}.</DialogDescription>
            </DialogHeader>

            <div :class="[CORPO, 'min-w-0 space-y-3.5 py-4']" data-testid="corpo-memo">
            <p v-if="!proposta && !errore" class="text-sm text-slate-500">Un momento…</p>
            <div v-if="proposta" class="min-w-0 space-y-3.5">
                <p v-if="proposta.esistente && !memo" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="memo-esistente">{{ proposta.esistente.frase }}. Un memo nuovo sulla stessa scheda diventa lo stesso punto.</p>
                <label class="block"><span :class="etichetta">Il punto</span>
                    <textarea v-model="testoDelPunto" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" data-testid="testo-memo" />
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">È la frase che ti proponiamo per l'ordine del giorno. Nell'avviso e in bacheca la ricontrolli quando prepari l'assemblea.</span>
                </label>
                <div v-if="proposta.origine">
                    <span :class="etichetta">Da dove nasce</span>
                    <p class="flex flex-wrap items-center gap-2 text-sm text-slate-800 dark:text-slate-200" data-testid="origine">
                        <span class="break-words">{{ proposta.origine.nome }}</span>
                        <a v-if="proposta.origine.apri" :href="proposta.origine.apri" class="text-xs font-medium underline">Apri</a>
                    </p>
                </div>
                <div class="menu-campo w-full min-w-0" data-testid="menu-assemblea">
                    <span :class="etichetta" class="flex items-center gap-1">Per quale assemblea
                        <SuggerimentoDellaI testid="i-per-quale-assemblea"
                            testo="«La prossima» vuol dire la prima che prepari: il memo ti aspetta lì. Se un'assemblea è già in preparazione, puoi sceglierla." />
                    </span>
                    <v-select append-to-body :calculate-position="menuFuoriDallaFinestra" :model-value="assembleaId ?? 0" :options="assemblee" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false"
                        class="w-full min-w-0 [&_.vs__selected]:truncate" @update:model-value="(v: number) => (assembleaId = v || null)" />
                </div>
                <label class="block"><span :class="etichetta">Una nota per te (resta nello studio)</span>
                    <textarea v-model="nota" rows="2" placeholder="Per esempio: portare il preventivo dell'impresa per sostituire tutta la colonna"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-slate-400 dark:bg-slate-950" />
                </label>

                <div class="space-y-2.5 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Ricordamelo</span>
                        <Switch v-model="ricordami" data-testid="ricordamelo" />
                    </div>
                    <div v-if="ricordami" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="block"><span :class="etichetta">Dal giorno</span>
                            <CampoGiorno v-model="giorno" :min="oggi" data-testid="giorno-memo" />
                            <span v-if="giornoPassato" class="mt-1 block text-xs text-rose-700 dark:text-rose-400" data-testid="giorno-passato">
                                Il giorno è già passato: scegli oggi o un giorno dopo.
                            </span>
                        </label>
                        <div class="menu-campo"><span :class="etichetta">A chi</span>
                            <v-select append-to-body :calculate-position="menuFuoriDallaFinestra" v-model="aChi" :options="chi" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" />
                        </div>
                        <div v-if="aChi === 'collaboratore'" class="menu-campo sm:col-span-2"><span :class="etichetta">Il collaboratore</span>
                            <v-select append-to-body :calculate-position="menuFuoriDallaFinestra" v-model="collaboratore" :options="collaboratori" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false"
                                :searchable="collaboratori.length > 8" placeholder="Scegli il collaboratore" />
                        </div>
                    </div>
                </div>

                <p v-if="proposta.gia_proposto && !memo" class="text-xs text-slate-600 dark:text-slate-300" data-testid="gia-proposto">{{ proposta.gia_proposto }}</p>
            </div>
            <p v-if="errore" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore-memo">{{ errore }}</p>
            </div>

            <div :class="PIEDE" data-testid="piede-memo">
                <p :class="RIEPILOGO" data-testid="adesso-memo">{{ adesso }}</p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                    <Button :disabled="!puoSalvare" data-testid="salva-memo" @click="salva">{{ inCorso ? 'Un momento…' : 'Salva il memo' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
