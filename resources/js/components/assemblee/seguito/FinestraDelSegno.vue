<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * I tre segni sulla delibera, dal menu della riga del passo 6 (scena 8 della bozza della tappa), nello standard delle finestre. Ognuno chiede il suo perché, e
 * nessuno ferma niente da solo:
 *
 * - **«Segna impugnata»**: notificata il, da chi (il menu propone chi può impugnare: i contrari, gli astenuti, gli assenti; si scrive anche un altro nome),
 *   l'atto, il documento facoltativo. L'impugnazione non sospende l'esecuzione, salvo che la sospensione la ordini il giudice (art. 1137 c.c.).
 * - **«Segna annullata dal giudice»**: il giorno e il provvedimento; le schede che la usano dicono «Annullata», e le rivede l'amministratore.
 * - **«Segna revocata»**: la delibera che la revoca, fra quelle successive dello stesso condominio (proposte quelle il cui punto parla di questa).
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { messaggioDellErrore } from '@/lib/assemblee/assemblee';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from '@/components/assemblee/verbale/finestra';
import type { ModoDelSegno } from '@/lib/assemblee/seguito';
import type { RigaDelPassoSei } from '@/types/assemblee';


const props = defineProps<{
    open: boolean;
    modo: ModoDelSegno;
    condominioId: number;
    condominioNome: string;
    assembleaId: number;
    delibera: RigaDelPassoSei | null;
}>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();
const giorno = ref(oggi);
const chi = ref<string | null>(null);
const atto = ref('');
const documento = ref<File | null>(null);
const provvedimento = ref('');
const revoche = ref<{ id: number; frase: string; testo: string }[] | null>(null);
const revocata = ref<number | null>(null);
const errori = ref<Record<string, string>>({});
const errore = ref<string | null>(null);
const inCorso = ref(false);

const titolo = computed(() => ({ impugnata: 'Segna impugnata', annullata: 'Segna annullata dal giudice', revocata: 'Segna revocata' })[props.modo]);
const schede = computed(() => (props.delibera?.posti ?? []).filter((p) => p.esiste));
const nomiDelleSchede = computed(() => schede.value.map((p) => p.nome).join(', '));
/** Il menu «Da chi»: chi può impugnare, con il perché. */
const persone = computed(() => (props.delibera?.chi_puo_impugnare ?? []).map((p) => `${p.nome}`));
const nomeDellaRevoca = (r: { frase: string; testo: string }) => `${r.frase} · ${r.testo}`;
const riepilogo = computed(() => {
    const n = schede.value.length;
    if (props.modo === 'impugnata') return `Il segno va sulla delibera${n ? ` e su ${n === 1 ? '1 scheda' : `${n} schede`}` : ''}. Niente si ferma.`;
    if (props.modo === 'annullata') return n ? `${n === 1 ? 'La scheda che la usa dice' : `Le ${n} schede che la usano dicono`} «Annullata»: le rivedi tu.` : 'Nessuna scheda la usa.';
    const r = revoche.value?.find((x) => x.id === revocata.value);

    const giorno = r?.frase.match(/(\d{2}\/\d{2}\/\d{4})/u)?.[1] ?? null;

    return r ? `La delibera non vale più${giorno ? ` dal ${giorno}` : ''}.` : 'Scegli la delibera che la revoca.';
});
const puoMandare = computed(() => !inCorso.value && ({
    impugnata: giorno.value !== '' && giorno.value <= oggi && (chi.value ?? '').trim() !== '' && atto.value.trim() !== '',
    annullata: giorno.value !== '' && giorno.value <= oggi && provvedimento.value.trim() !== '',
    revocata: revocata.value !== null,
})[props.modo]);

watch(() => props.open, async (a) => {
    if (!a) return;
    giorno.value = oggi;
    chi.value = null;
    atto.value = '';
    documento.value = null;
    provvedimento.value = '';
    revocata.value = null;
    errori.value = {};
    errore.value = null;
    if (props.modo === 'revocata' && props.delibera) {
        revoche.value = null;
        try {
            const r = await axios.get(route(generateRoute('gestionale.assemblee.delibere.revoche'), { condominio: props.condominioId, assemblea: props.assembleaId,
                delibera: props.delibera.delibera_id }));
            revoche.value = (r.data?.delibere ?? []) as { id: number; frase: string; testo: string }[];
            revocata.value = revoche.value[0]?.id ?? null;
        } catch (e) {
            revoche.value = [];
            errore.value = messaggioDellErrore(e, 'Le delibere non si sono caricate. Riprova.');
        }
    }
}, { immediate: true });

function manda() {
    if (!puoMandare.value || !props.delibera) return;
    const parametri = { condominio: props.condominioId, assemblea: props.assembleaId, delibera: props.delibera.delibera_id };
    const [rotta, dati] = props.modo === 'impugnata'
        ? ['gestionale.assemblee.delibere.impugnata', { giorno: giorno.value, nota: `${(chi.value ?? '').trim()} · ${atto.value.trim()}`, ...(documento.value ? { atto: documento.value } : {}) }]
        : props.modo === 'annullata'
            ? ['gestionale.assemblee.delibere.annullata', { giorno: giorno.value, provvedimento: provvedimento.value.trim() }]
            : ['gestionale.assemblee.delibere.revocata', { revocata_da_delibera_id: revocata.value }];
    inCorso.value = true;
    router.post(route(generateRoute(rotta), parametri), dati as never, {
        forceFormData: props.modo === 'impugnata', preserveScroll: true, only: ['seguito', 'passi', 'flash'],
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perId = (o: { id: number }) => o.id;
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-del-segno" :data-modo="modo">
            <DialogHeader :class="TESTA">
                <DialogTitle>{{ titolo }}<template v-if="delibera">: punto {{ delibera.punto }}</template></DialogTitle>
                <DialogDescription v-if="delibera" class="break-words">{{ delibera.testo }}</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <template v-if="modo === 'impugnata'">
                    <section :class="GRUPPO">
                        <p :class="TITOLO_GRUPPO">L'impugnazione</p>
                        <p :class="TITOLO_GRUPPO">Notificata il</p>
                        <CampoGiorno v-model="giorno" :max="oggi" class="h-9 max-w-[220px] text-sm" aria-label="Notificata il" />
                        <InputError :message="errori.giorno" />
                        <p :class="TITOLO_GRUPPO">Da chi</p>
                        <div class="menu-campo">
                            <v-select v-model="chi" :options="persone" taggable :clearable="false" placeholder="Scegli o scrivi il nome" aria-label="Da chi" />
                        </div>
                        <p :class="AIUTO">Il menu propone chi può impugnare: i contrari, gli astenuti, gli assenti.</p>
                        <p :class="TITOLO_GRUPPO">L'atto</p>
                        <input v-model="atto" type="text" maxlength="500" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950"
                            placeholder="Per esempio: atto di citazione davanti al Tribunale di Milano" aria-label="L'atto" data-testid="atto" />
                        <InputError :message="errori.nota" />
                        <p :class="TITOLO_GRUPPO">Documento (facoltativo)</p>
                        <CampoFile v-model="documento" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="documento-impugnata" />
                        <InputError :message="errori.atto" />
                    </section>
                    <section :class="GRUPPO">
                        <p :class="TITOLO_GRUPPO">Che cosa succede</p>
                        <p class="text-sm text-slate-700 dark:text-slate-300">La delibera resta in vigore: l'impugnazione non sospende l'esecuzione, salvo che la
                            sospensione la ordini il giudice (art. 1137 c.c.).</p>
                        <p v-if="schede.length" class="text-sm text-slate-700 dark:text-slate-300">Le schede che la usano lo dicono: {{ nomiDelleSchede }}.</p>
                    </section>
                </template>
                <section v-else-if="modo === 'annullata'" :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Il giorno</p>
                    <CampoGiorno v-model="giorno" :max="oggi" class="h-9 max-w-[220px] text-sm" aria-label="Il giorno del provvedimento" />
                    <InputError :message="errori.giorno" />
                    <p :class="TITOLO_GRUPPO">Il provvedimento</p>
                    <input v-model="provvedimento" type="text" maxlength="1000" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950"
                        placeholder="Per esempio: sentenza n. 1187/2028 del Tribunale di Milano" aria-label="Il provvedimento" data-testid="provvedimento" />
                    <InputError :message="errori.provvedimento" />
                    <p :class="AIUTO">Nessun conto cambia da solo: le schede che la usano lo dicono, e decidi tu.</p>
                </section>
                <section v-else :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">La delibera che la revoca</p>
                    <p v-if="revoche === null" :class="AIUTO">Un momento…</p>
                    <p v-else-if="revoche.length === 0" :class="AIUTO" data-testid="nessuna-revoca">Nessuna delibera di {{ condominioNome }} successiva a questa: la revoca
                        nasce da una delibera, e si segna dopo.</p>
                    <div v-else class="menu-campo">
                        <v-select v-model="revocata" :options="revoche" :get-option-label="nomeDellaRevoca" :reduce="perId" :clearable="false"
                            :searchable="false" aria-label="La delibera che la revoca" />
                    </div>
                    <p :class="AIUTO">Proposte prima le delibere il cui punto parla di questa. Solo delibere di {{ condominioNome }} successive.</p>
                    <InputError :message="errori.revocata_da_delibera_id ?? errore ?? undefined" />
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>{{ riepilogo }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!puoMandare" data-testid="conferma-del-segno" @click="manda">{{ titolo }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
