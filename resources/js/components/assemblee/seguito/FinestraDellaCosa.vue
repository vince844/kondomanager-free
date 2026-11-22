<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le finestre delle cose da fare di una delibera, nel passo 6 (la bozza approvata del seguito, scena 4), nello standard delle finestre: ancorata in alto, i
 * gruppi con il titolo piccolo, il riepilogo fisso nel piede accanto ai pulsanti, l'errore solo rosso sotto il campo.
 *
 * - **«Segna eseguita»**, per ciò che si fa fuori dal programma: quando (non dopo oggi), la nota, il documento facoltativo (PDF e immagini, 10 MB). Resta nel
 *   registro delle modifiche con il nome di chi la segna; il documento va fra i documenti dell'assemblea, fuori dal portale.
 * - **«Non serve più»**, con il motivo; per una delibera respinta **«Chiudi con il motivo»**, che chiude anche i compiti dell'origine.
 * - **«Un'altra cosa da fare»** dopo la conferma: che cosa, entro quando (con la data è un compito dell'Action inbox), a chi.
 *
 * Con più cose aperte la finestra chiede quale, con quella scelta dalla riga già proposta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from '@/components/assemblee/verbale/finestra';
import type { ModoDellaCosa } from '@/lib/assemblee/seguito';
import type { AChiMemo, RigaDelPassoSei } from '@/types/assemblee';


const props = withDefaults(defineProps<{
    open: boolean;
    modo: ModoDellaCosa;
    condominioId: number;
    assembleaId: number;
    delibera: RigaDelPassoSei | null;
    /** La cosa scelta dalla riga; senza, la prima aperta. */
    cosaId?: number | null;
}>(), { cosaId: null });
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();
const scelta = ref<number | null>(null);
const giorno = ref(oggi);
const nota = ref('');
const motivo = ref('');
const documento = ref<File | null>(null);
const testo = ref('');
const entroIl = ref('');
const persona = ref('me');
const collaboratori = ref<{ id: number; nome: string }[]>([]);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

const aperte = computed(() => (props.delibera?.cose ?? []).filter((c) => c.stato === 'da_fare'
    && (props.modo === 'chiudi' ? c.tipo === 'dopo_il_rifiuto' : c.tipo !== 'dopo_il_rifiuto')));
const cosa = computed(() => aperte.value.find((c) => c.id === scelta.value) ?? null);
const altreAperte = computed(() => (props.delibera?.cose ?? []).filter((c) => c.stato === 'da_fare' && c.id !== scelta.value).length);
const titolo = computed(() => ({
    eseguita: `Segna eseguita${cosa.value ? `: ${cosa.value.testo.charAt(0).toLowerCase()}${cosa.value.testo.slice(1)}` : ''}`,
    non_serve: 'Non serve più',
    chiudi: 'Chiudi con il motivo',
    nuova: 'Un\'altra cosa da fare',
})[props.modo]);
const persone = computed(() => [{ valore: 'me', nome: 'A me' }, { valore: 'studio', nome: 'A tutto lo studio' },
    ...collaboratori.value.map((c) => ({ valore: `c:${c.id}`, nome: c.nome }))]);
const riepilogo = computed(() => {
    if (props.modo === 'nuova') return entroIl.value ? 'Con la data diventa un compito dell\'Action inbox.' : 'Senza data resta nel passo 6 e nella dashboard.';
    if (props.modo === 'eseguita') {
        return `Resta nel registro delle modifiche con il tuo nome.${altreAperte.value > 0 ? ' La delibera resta «Da eseguire» finché le altre cose non sono fatte.' : ''}`;
    }
    if (props.modo === 'chiudi') return 'Chiude anche i compiti dell\'origine, con la stessa frase. Il motivo resta nel registro.';

    return 'Il motivo resta nel registro delle modifiche.';
});
const puoMandare = computed(() => !inCorso.value && (props.modo === 'nuova' ? testo.value.trim() !== '' && !(persona.value === '') : cosa.value !== null
    && (props.modo === 'eseguita' ? giorno.value !== '' && giorno.value <= oggi : motivo.value.trim() !== '')));

watch(() => props.open, async (a) => {
    if (!a) return;
    scelta.value = props.cosaId && aperte.value.some((c) => c.id === props.cosaId) ? props.cosaId : (aperte.value[0]?.id ?? null);
    giorno.value = oggi;
    nota.value = '';
    motivo.value = '';
    documento.value = null;
    testo.value = '';
    entroIl.value = '';
    persona.value = 'me';
    errori.value = {};
    if (props.modo === 'nuova' && collaboratori.value.length === 0) {
        try {
            const r = await axios.get(route(generateRoute('gestionale.memo.proposta'), { condominio: props.condominioId }));
            collaboratori.value = (r.data?.collaboratori ?? []) as { id: number; nome: string }[];
        } catch {
            collaboratori.value = [];
        }
    }
}, { immediate: true });

function manda() {
    if (!puoMandare.value || !props.delibera) return;
    const base = { condominio: props.condominioId, assemblea: props.assembleaId };
    const [rotta, parametri, dati] = props.modo === 'nuova'
        ? ['gestionale.assemblee.seguito.cose.store', { ...base, delibera: props.delibera.delibera_id }, {
            testo: testo.value.trim(), entro_il: entroIl.value || null,
            a_chi: (persona.value.startsWith('c:') ? 'collaboratore' : persona.value) as AChiMemo,
            assegnato_a: persona.value.startsWith('c:') ? Number(persona.value.slice(2)) : null,
        }]
        : props.modo === 'eseguita'
            ? ['gestionale.assemblee.seguito.cose.eseguita', { ...base, cosa: scelta.value }, { giorno: giorno.value, nota: nota.value.trim() || null,
                ...(documento.value ? { documento: documento.value } : {}) }]
            : [props.modo === 'chiudi' ? 'gestionale.assemblee.seguito.cose.chiudi' : 'gestionale.assemblee.seguito.cose.non-serve', { ...base, cosa: scelta.value },
                { motivo: motivo.value.trim() }];
    inCorso.value = true;
    router.post(route(generateRoute(rotta), parametri), dati as never, {
        forceFormData: props.modo === 'eseguita', preserveScroll: true, only: ['seguito', 'passi', 'flash'],
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perId = (o: { id: number }) => o.id;
const perValore = (o: { valore: string }) => o.valore;
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-della-cosa" :data-modo="modo">
            <DialogHeader :class="TESTA">
                <DialogTitle class="break-words">{{ titolo }}</DialogTitle>
                <DialogDescription v-if="delibera">Assemblea, punto {{ delibera.punto }}<template v-if="cosa && cosa.chi"> · affidata a {{ cosa.chi }}</template>.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section v-if="modo !== 'nuova' && aperte.length > 1" :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Quale cosa</p>
                    <div class="menu-campo">
                        <v-select v-model="scelta" :options="aperte" label="testo" :reduce="perId" :clearable="false" :searchable="false"
                            aria-label="Quale cosa" />
                    </div>
                </section>
                <section v-if="modo === 'eseguita'" :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Quando</p>
                    <CampoGiorno v-model="giorno" :max="oggi" class="h-9 max-w-[220px] text-sm" aria-label="Il giorno in cui l'hai fatta" />
                    <InputError :message="errori.giorno" />
                    <p :class="TITOLO_GRUPPO">Nota</p>
                    <Textarea v-model="nota" rows="2" class="min-h-[60px] resize-none text-sm" aria-label="La nota"
                        placeholder="Per esempio: contratto firmato; consegna del portone entro il 31/03/2027." />
                    <InputError :message="errori.nota" />
                    <p :class="TITOLO_GRUPPO">Documento (facoltativo)</p>
                    <CampoFile v-model="documento" accept=".pdf,.jpg,.jpeg,.png" testid="documento-eseguita" />
                    <p :class="AIUTO">PDF o immagine, fino a 10 MB: resta fra i documenti dell'assemblea, fuori dal portale.</p>
                    <InputError :message="errori.documento" />
                </section>
                <section v-else-if="modo === 'non_serve' || modo === 'chiudi'" :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Il motivo</p>
                    <Textarea v-model="motivo" rows="3" class="min-h-[72px] resize-none text-sm" aria-label="Il motivo" data-testid="motivo"
                        :placeholder="modo === 'chiudi' ? 'Per esempio: la spesa la paga l\'impresa, come da accordo.' : 'Per esempio: il fornitore ha rinunciato.'" />
                    <InputError :message="errori.motivo ?? errori.cosa" />
                </section>
                <section v-else :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Che cosa resta da fare</p>
                    <input v-model="testo" type="text" maxlength="255" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950"
                        placeholder="Per esempio: firma con Serramenti Marino" aria-label="Che cosa resta da fare" data-testid="testo" />
                    <InputError :message="errori.testo" />
                    <p :class="TITOLO_GRUPPO">Entro il</p>
                    <CampoGiorno v-model="entroIl" :min="oggi" svuotabile class="h-9 max-w-[220px] text-sm" aria-label="Entro il" />
                    <InputError :message="errori.entro_il" />
                    <p :class="TITOLO_GRUPPO">A chi</p>
                    <div class="menu-campo">
                        <v-select v-model="persona" :options="persone" label="nome" :reduce="perValore" :clearable="false" :searchable="false"
                            aria-label="A chi" />
                    </div>
                    <InputError :message="errori.assegnato_a" />
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>{{ riepilogo }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!puoMandare" data-testid="conferma-della-cosa" @click="manda">{{ modo === 'nuova' ? 'Aggiungi' : titolo.split(':')[0] }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
