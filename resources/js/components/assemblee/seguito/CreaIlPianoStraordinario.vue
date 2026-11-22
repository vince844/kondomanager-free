<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Crea il piano straordinario» (decisione 622 (1), la scena 2 della bozza approvata con la decisione 623): **prepara tutto, salvi tu**. Si apre dalla cosa da
 * fare di una delibera di lavori, nel passo 6 e nel riepilogo (`?scheda=delibere&cosa={id}`).
 *
 * - Tre gruppi: **la gestione** (il nome, l'esercizio in una barra, il periodo), **la voce di spesa** (il nome, l'importo dalla delibera, la tabella, il
 *   fornitore facoltativo), **dopo** (che cosa si apre). Il nome, la voce e l'importo vengono dalla delibera; si cambiano qui.
 * - Confermata, nascono la gestione straordinaria, il suo piano dei conti e la voce, per le strade di oggi; la gestione si lega alla delibera. Poi si apre il
 *   nuovo piano già compilato, con la delibera scelta: **il piano nasce solo con «Salva il piano»**, e allora la cosa si chiude da sola.
 * - Una gestione già legata alla delibera non si crea di nuovo: la finestra porta al suo piano.
 * - Approvata con modifiche (decisione 637 (1)): in testa «Che cosa ha cambiato l'assemblea: …», e l'importo da scrivere; la cifra di prima non è
 *   deliberata, e il riepilogo non dice la differenza.
 * - La forma delle finestre (decisione 608): ancorata in alto, il contenuto che scorre, i titoli piccoli e grigi dei gruppi, il riepilogo fisso nel piede
 *   accanto ai pulsanti, con il segno del lavoro in corso. Gli errori rossi sotto il campo (decisione 612).
 *
 * L'importo si scrive in euro: lo converte il server, una volta sola.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { importoNelCampo, messaggioDellErrore } from '@/lib/assemblee/assemblee';
import { ARANCIO } from '@/components/assemblee/verbale/finestra';
import { nomeConLAnno, riepilogoDelPianoStraordinario, type PropostaDelPianoStraordinario } from '@/lib/assemblee/pianoStraordinario';

const props = defineProps<{ open: boolean; condominioId: number; assembleaId: number; cosaId: number | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const { generateRoute } = usePermission();
const proposta = ref<PropostaDelPianoStraordinario | null>(null);
const errore = ref<string | null>(null);
const errori = ref<Record<string, string>>({});
const caricamento = ref(false);
const inCorso = ref(false);

const gestioneNome = ref('');
const esercizioId = ref<number | null>(null);
const dataInizio = ref('');
const dataFine = ref('');
const voceNome = ref('');
const importo = ref('');
const tabellaId = ref<number | null>(null);
const fornitoreId = ref<number | null>(null);

const parametri = () => ({ condominio: props.condominioId, assemblea: props.assembleaId, cosa: props.cosaId });

async function leggi() {
    if (props.cosaId === null) return;
    caricamento.value = true;
    errore.value = null;
    errori.value = {};
    try {
        const r = await axios.get(route(generateRoute('gestionale.assemblee.seguito.piano-straordinario.proposta'), parametri()));
        const p = r.data as PropostaDelPianoStraordinario;
        proposta.value = p;
        gestioneNome.value = p.gestione.nome;
        esercizioId.value = p.gestione.esercizio_id;
        dataInizio.value = p.gestione.data_inizio ?? '';
        dataFine.value = p.gestione.data_fine ?? '';
        voceNome.value = p.voce.nome;
        importo.value = importoNelCampo(p.voce.importo);
        tabellaId.value = p.voce.tabella_id;
        fornitoreId.value = p.voce.fornitore_id;
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'La proposta non si è caricata. Riprova.');
    } finally {
        caricamento.value = false;
    }
}
watch(() => [props.open, props.cosaId], ([aperta]) => { if (aperta) leggi(); }, { immediate: true });

/** L'esercizio scelto cambia il periodo e l'anno nel nome della gestione (se il nome lo porta ancora). */
function scegliEsercizio(id: number) {
    const prima = proposta.value?.esercizi.find((e) => e.id === esercizioId.value);
    const dopo = proposta.value?.esercizi.find((e) => e.id === id);
    esercizioId.value = id;
    if (!dopo) return;
    gestioneNome.value = nomeConLAnno(gestioneNome.value, prima?.data_fine?.slice(0, 4) ?? null, dopo.data_fine?.slice(0, 4) ?? null);
    dataInizio.value = dopo.data_inizio ?? dataInizio.value;
    dataFine.value = dopo.data_fine ?? dataFine.value;
}

const esistente = computed(() => proposta.value?.gestione_esistente ?? null);
const riepilogo = computed(() => riepilogoDelPianoStraordinario(importo.value, proposta.value?.delibera.importo ?? 0, esistente.value?.nome ?? null));
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perId = (o: { id: number }) => o.id;
const puoCreare = computed(() => !!proposta.value && !inCorso.value && !caricamento.value && (esistente.value !== null
    || (gestioneNome.value.trim() !== '' && voceNome.value.trim() !== '' && esercizioId.value !== null && tabellaId.value !== null && importo.value.trim() !== '')));

async function crea() {
    if (!puoCreare.value || !proposta.value) return;
    if (esistente.value) {
        router.visit(esistente.value.url);
        return;
    }
    inCorso.value = true;
    errore.value = null;
    errori.value = {};
    try {
        const r = await axios.post(route(generateRoute('gestionale.assemblee.seguito.piano-straordinario'), parametri()), {
            gestione_nome: gestioneNome.value, esercizio_id: esercizioId.value, data_inizio: dataInizio.value, data_fine: dataFine.value,
            voce_nome: voceNome.value, importo: importo.value, tabella_id: tabellaId.value, fornitore_id: fornitoreId.value,
        });
        emit('update:open', false);
        router.visit((r.data as { url: string }).url);
    } catch (e) {
        const dati = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } }).response;
        if (dati?.status === 422 && dati.data?.errors) {
            errori.value = Object.fromEntries(Object.entries(dati.data.errors).map(([k, v]) => [k, v[0]]));
            if (errori.value.cosa) errore.value = errori.value.cosa;
        } else {
            errore.value = messaggioDellErrore(e, 'La gestione non è nata. Riprova.');
        }
    } finally {
        inCorso.value = false;
    }
}

// La forma delle finestre: i gruppi con il titolo piccolo e grigio, separati da una riga.
const gruppo = 'space-y-3 border-b border-slate-100 py-4 last:border-b-0 dark:border-slate-800';
const titoloGruppo = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
const etichetta = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400';
const campo = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950';
const erroreSotto = 'mt-1 block text-xs text-rose-700 dark:text-rose-400';
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[640px]" data-testid="crea-il-piano-straordinario">
            <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
                <DialogTitle>Crea il piano straordinario</DialogTitle>
                <DialogDescription>{{ proposta?.delibera.frase ?? 'Dalla delibera dell\'assemblea.' }}</DialogDescription>
            </DialogHeader>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 text-sm" data-testid="corpo-finestra">
                <div v-if="proposta?.modifiche && !esistente" :class="[ARANCIO, 'mt-4']" data-testid="modifiche-dell-assemblea">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <div class="min-w-0 space-y-0.5 break-words">
                        <p><b class="font-semibold">Che cosa ha cambiato l'assemblea:</b> {{ proposta.modifiche }}</p>
                        <p>L'importo lo scrivi tu: la cifra di prima non è deliberata.</p>
                    </div>
                </div>
                <section v-if="esistente" :class="gruppo">
                    <p class="text-slate-700 dark:text-slate-200" data-testid="gestione-esistente">
                        La gestione «{{ esistente.nome }}» è già legata a questa delibera: il nuovo piano si apre con quella, con la delibera già scelta.
                    </p>
                </section>

                <template v-else-if="proposta">
                    <section :class="gruppo">
                        <h3 :class="titoloGruppo">La gestione straordinaria</h3>
                        <label class="block"><span :class="etichetta">Nome</span>
                            <input v-model="gestioneNome" :class="campo" data-testid="nome-gestione" />
                            <span v-if="errori.gestione_nome" :class="erroreSotto">{{ errori.gestione_nome }}</span>
                        </label>
                        <div>
                            <span :class="etichetta">Esercizio</span>
                            <div v-if="proposta.esercizi.length <= 3" class="inline-flex flex-wrap rounded-md border border-slate-200 p-0.5 dark:border-slate-700" role="group"
                                data-testid="esercizio">
                                <button v-for="e in proposta.esercizi" :key="e.id" type="button" class="h-8 rounded px-3 text-sm"
                                    :class="esercizioId === e.id ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-700 dark:text-slate-200'"
                                    :aria-pressed="esercizioId === e.id" @click="scegliEsercizio(e.id)">{{ e.nome }}</button>
                            </div>
                            <div v-else class="menu-campo">
                                <v-select :model-value="esercizioId" :options="proposta.esercizi" label="nome" :reduce="perId" :clearable="false"
                                    :searchable="false" @update:model-value="(v: number) => scegliEsercizio(v)" />
                            </div>
                            <span v-if="errori.esercizio_id" :class="erroreSotto">{{ errori.esercizio_id }}</span>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="block"><span :class="etichetta">Dal</span><CampoGiorno v-model="dataInizio" class="h-9 text-sm" data-testid="dal" /></label>
                            <label class="block"><span :class="etichetta">Al</span><CampoGiorno v-model="dataFine" :min="dataInizio || undefined" class="h-9 text-sm" data-testid="al" />
                                <span v-if="errori.data_fine" :class="erroreSotto">{{ errori.data_fine }}</span>
                            </label>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Nasce come una gestione nuova di oggi, con il suo piano dei conti, legata a questa delibera.</p>
                    </section>

                    <section :class="gruppo">
                        <h3 :class="titoloGruppo">La voce di spesa</h3>
                        <label class="block"><span :class="etichetta">Nome</span>
                            <input v-model="voceNome" :class="campo" data-testid="nome-voce" />
                            <span v-if="errori.voce_nome" :class="erroreSotto">{{ errori.voce_nome }}</span>
                        </label>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="block"><span :class="etichetta">{{ proposta.modifiche ? 'Importo' : 'Importo dalla delibera' }}</span>
                                <input v-model="importo" inputmode="decimal" :class="campo" data-testid="importo" />
                                <span v-if="errori.importo" :class="erroreSotto">{{ errori.importo }}</span>
                            </label>
                            <div class="menu-campo"><span :class="etichetta">Tabella</span>
                                <v-select v-model="tabellaId" :options="proposta.tabelle" label="nome" :reduce="perId" :clearable="false"
                                    :searchable="proposta.tabelle.length > 8" placeholder="Scegli la tabella" />
                                <span v-if="tabellaId === null" class="mt-1 block text-xs text-amber-800 dark:text-amber-300" data-testid="scegli-la-tabella">Scegli la tabella: nessuna è segnata come di proprietà generale.</span>
                                <span v-if="errori.tabella_id" :class="erroreSotto">{{ errori.tabella_id }}</span>
                            </div>
                        </div>
                        <div class="menu-campo"><span :class="etichetta">Fornitore (facoltativo)</span>
                            <v-select v-model="fornitoreId" :options="proposta.fornitori" label="nome" :reduce="perId" :clearable="true"
                                placeholder="Nessuno" />
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ proposta.a_carico }}</p>
                    </section>
                </template>

                <section v-if="proposta" :class="gruppo">
                    <h3 :class="titoloGruppo">Dopo</h3>
                    <ul class="list-disc space-y-1 pl-5 text-slate-700 dark:text-slate-200">
                        <li>Si apre il nuovo piano con la gestione, la voce e la delibera già scelte.</li>
                        <li>Le rate le guardi tu: il piano nasce quando lo salvi, e allora «Crea il piano straordinario» si chiude da sola.</li>
                    </ul>
                </section>
                <p v-if="errore" class="my-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
                    data-testid="errore">{{ errore }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60"
                data-testid="piede-finestra">
                <p class="flex min-w-[200px] flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300" role="status" data-testid="riepilogo">
                    <span v-if="caricamento || inCorso" class="inline-flex shrink-0" data-testid="aggiorno"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /></span>
                    <span class="min-w-0">{{ proposta ? riepilogo : (caricamento ? 'Caricamento…' : '') }}</span>
                </p>
                <div class="ml-auto flex flex-wrap justify-end gap-2">
                    <Button variant="outline" :disabled="inCorso" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!puoCreare" data-testid="crea-e-apri" @click="crea">{{ esistente ? 'Apri il piano' : 'Crea e apri il piano' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
