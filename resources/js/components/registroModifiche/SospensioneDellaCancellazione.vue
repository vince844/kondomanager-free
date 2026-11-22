<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Sospendi la cancellazione» del registro delle modifiche per un condominio (1.12.0-beta.13, decisione 550), nell'intestazione della pagina del registro del
 * condominio, accanto a «Stampa». Carica da sé lo stato (`GET gestionale/{condominio}/registro-modifiche/sospensione`).
 *  - Non sospesa: il pulsante «Sospendi la cancellazione», che apre la finestra con il motivo obbligatorio.
 *  - Sospesa: il pulsante ambra «Cancellazione sospesa», che apre la finestra con lo stato detto a parole («Cancellazione sospesa dal 12/03/2035: causa
 *    Rossi, tribunale di Napoli», e chi l'ha sospesa) e «Togli la sospensione», anche lei con il motivo.
 * Solo l'amministratore sospende e toglie (lo dice il server con `puo_cambiare`); a chi vede il registro senza esserlo, lo stato senza i pulsanti. I pulsanti
 * sono alti come «Guida» (`h-8`).
 *
 * Con `senza-pulsante` (dalla decisione 570, il menu «Registro» di `PulsantiDelRegistro.vue`) monta solo la finestra: il menu legge `stato` e chiama
 * `apri()`, esposti qui.
 */
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { LoaderCircle, PauseCircle, ShieldAlert } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/composables/permissions';
import { messaggioDiErrore, testoSospesa, type StatoSospensione } from '@/lib/registroModifiche/casiSpeciali';

const props = defineProps<{ condominioId: number; condominioNome?: string; senzaPulsante?: boolean }>();
const emit = defineEmits<{ (e: 'cambiata', stato: StatoSospensione): void }>();

const { generateRoute } = usePermission();

const stato = ref<StatoSospensione | null>(null);
const aperta = ref(false);
const motivo = ref('');
const errore = ref('');
const inCorso = ref(false);

const indirizzo = (nome: string) => route(generateRoute(`gestionale.registro-modifiche.sospensione.${nome}`), { condominio: props.condominioId });
const nomeDelCondominio = computed(() => props.condominioNome ? `di ${props.condominioNome}` : 'di questo condominio');

async function carica() {
    try {
        const { data } = await axios.get<StatoSospensione>(indirizzo('mostra'));
        stato.value = data;
    } catch {
        stato.value = null;
    }
}

function apri() {
    motivo.value = '';
    errore.value = '';
    aperta.value = true;
}

async function conferma() {
    if (inCorso.value || !stato.value) return;
    if (motivo.value.trim() === '') {
        errore.value = stato.value.sospesa ? 'Scrivi perché togli la sospensione.' : 'Scrivi perché sospendi la cancellazione.';
        return;
    }
    inCorso.value = true;
    errore.value = '';
    try {
        const { data } = stato.value.sospesa
            ? await axios.delete<StatoSospensione>(indirizzo('togli'), { data: { motivo: motivo.value } })
            : await axios.post<StatoSospensione>(indirizzo('sospendi'), { motivo: motivo.value });
        stato.value = data;
        aperta.value = false;
        emit('cambiata', data);
    } catch (e) {
        errore.value = messaggioDiErrore(e, 'motivo');
    } finally {
        inCorso.value = false;
    }
}

onMounted(carica);

defineExpose({ apri, stato });
</script>

<template>
    <template v-if="stato && (stato.sospesa || stato.puo_cambiare)">
        <!-- Alto e scritto come gli altri pulsanti dell'intestazione del registro («Verifica l'integrità», «Stampa»), perché la fila stia insieme. -->
        <button
            v-if="!senzaPulsante && stato.sospesa"
            type="button"
            class="inline-flex h-8 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-amber-300 bg-amber-50 px-3 text-xs font-bold text-amber-800 shadow-sm transition-colors hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/70"
            :title="testoSospesa(stato)"
            data-testid="sospesa"
            @click="apri"
        >
            <ShieldAlert class="h-3.5 w-3.5" />
            Cancellazione sospesa
        </button>
        <button
            v-else-if="!senzaPulsante"
            type="button"
            class="inline-flex h-8 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
            data-testid="sospendi"
            @click="apri"
        >
            <PauseCircle class="h-3.5 w-3.5" />
            Sospendi la cancellazione
        </button>

        <Dialog v-model:open="aperta">
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-4" @submit.prevent="conferma">
                    <DialogHeader class="space-y-2">
                        <DialogTitle>{{ stato.sospesa ? 'Cancellazione sospesa' : 'Sospendi la cancellazione automatica' }}</DialogTitle>
                        <DialogDescription v-if="stato.sospesa" data-testid="stato">
                            {{ testoSospesa(stato) }}<template v-if="stato.da">. Sospesa da {{ stato.da }}.</template>
                        </DialogDescription>
                        <DialogDescription v-else>
                            Le righe del registro {{ nomeDelCondominio }} restano oltre la durata finché non togli la sospensione: per esempio con una causa in corso.
                        </DialogDescription>
                    </DialogHeader>

                    <div v-if="!stato.sospesa" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300">
                        È l'eccezione della difesa in giudizio (art. 17 par. 3, lettera e, del regolamento europeo). La sospensione e la sua fine sono righe del
                        registro, con il tuo nome e il motivo.
                    </div>
                    <div v-else-if="stato.puo_cambiare" class="text-xs text-muted-foreground">
                        Tolta la sospensione, le righe più vecchie della durata si tolgono la notte dopo. La fine della sospensione è una riga del registro.
                    </div>

                    <div v-if="stato.puo_cambiare" class="grid gap-2">
                        <Label for="motivo-sospensione">{{ stato.sospesa ? 'Perché la togli' : 'Perché' }}</Label>
                        <Textarea
                            id="motivo-sospensione"
                            v-model="motivo"
                            rows="3"
                            maxlength="1000"
                            :placeholder="stato.sospesa ? 'causa chiusa con la sentenza del …' : 'causa Rossi, tribunale di Napoli'"
                            data-testid="motivo"
                        />
                        <InputError :message="errore" />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="secondary" @click="aperta = false">{{ stato.puo_cambiare ? 'Annulla' : 'Chiudi' }}</Button>
                        <Button v-if="stato.puo_cambiare" type="submit" :disabled="inCorso" data-testid="conferma">
                            <LoaderCircle v-if="inCorso" class="h-4 w-4 animate-spin" />
                            {{ stato.sospesa ? 'Togli la sospensione' : 'Sospendi la cancellazione' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </template>
</template>
