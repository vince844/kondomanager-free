<script setup lang="ts">
/**
 * La dispensa dell'assemblea dall'agire entro sei mesi (art. 1129 c. 9): l'elenco delle delibere registrate, la registrazione di una nuova
 * (data, verbale, esercizi coperti, tutte le persone o alcune, nota) e la revoca. Una delibera alla volta. Il programma la mostra accanto
 * alle date dei sei mesi dell'elenco delle rate scadute; le date non cambiano.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/composables/permissions';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import type { Flash } from '@/types/flash';

interface Dispensa {
    id: number; data_delibera: string; numero_verbale: string | null; nota: string | null; tutte_le_persone: boolean;
    esercizi: Array<{ id: number; nome: string }>; persone: Array<{ id: number; nome: string }>; revocata_il: string | null; nota_revoca: string | null; verbale: string | null;
}
const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    esercizi: Array<{ id: number; nome: string; stato: string }>; persone: Array<{ id: number; nome: string }>;
    dispense: Dispensa[]; testo_norma: string; oggi: string;
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const nuova = ref({ data_delibera: '', numero_verbale: '', nota: '', esercizi: [] as number[], per_tutti: '' as '' | '1' | '0', persone: [] as number[] });
const verbale = ref<File | null>(null);
const alterna = (lista: number[], id: number) => (lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);
function registra() {
    router.post(route(generateRoute('gestionale.dispense.store'), { condominio: props.condominio.id }), {
        ...nuova.value, ...(verbale.value ? { verbale: verbale.value } : {}),
    }, { forceFormData: true, preserveScroll: true, onSuccess: () => { nuova.value = { data_delibera: '', numero_verbale: '', nota: '', esercizi: [], per_tutti: '', persone: [] }; verbale.value = null; } });
}

const revoca = ref<{ id: number | null; revocata_il: string; nota_revoca: string }>({ id: null, revocata_il: '', nota_revoca: '' });
function revoca_conferma() {
    if (!revoca.value.id) return;
    router.post(route(generateRoute('gestionale.dispense.revoca'), { condominio: props.condominio.id, dispensa: revoca.value.id }), {
        revocata_il: revoca.value.revocata_il, nota_revoca: revoca.value.nota_revoca,
    }, { preserveScroll: true, onSuccess: () => { revoca.value = { id: null, revocata_il: '', nota_revoca: '' }; } });
}
const indirizzoVerbale = (d: Dispensa) => route(generateRoute('gestionale.dispense.verbale'), { condominio: props.condominio.id, dispensa: d.id });
const indirizzoElenco = computed(() => route(generateRoute('gestionale.rate-scadute.index'), { condominio: props.condominio.id }));

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
    { title: 'Dispense dei sei mesi' },
]);
</script>

<template>
    <Head title="Dispense dei sei mesi" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Dispense dei sei mesi"
                page-subtitle="Le delibere con cui l'assemblea dispensa dall'agire entro sei mesi per la riscossione: si leggono accanto alle date dell'elenco delle rate scadute."
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />
            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <p class="text-xs text-slate-500 dark:text-slate-400 italic">{{ testo_norma }}</p>
            <p class="text-xs text-slate-600 dark:text-slate-300">
                La dispensa non sposta le date: il programma la scrive accanto. Non è l'accordo di rientro con una persona.
                <Link :href="indirizzoElenco" class="underline">Vai alle rate scadute</Link>
            </p>

            <!-- Le dispense registrate -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800">
                <div v-for="d in dispense" :key="d.id" class="p-3 text-sm space-y-1" data-testid="dispensa">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">Assemblea del {{ giorno(d.data_delibera) }}<template v-if="d.numero_verbale"> — verbale n. {{ d.numero_verbale }}</template></span>
                        <span v-if="d.revocata_il" class="text-xs text-rose-700 dark:text-rose-300">revocata il {{ giorno(d.revocata_il) }}</span>
                        <Button v-else size="sm" variant="outline" class="h-7 text-xs" @click="revoca = { id: d.id, revocata_il: oggi, nota_revoca: '' }">Registra la revoca</Button>
                    </div>
                    <p class="text-xs">Esercizi: {{ d.esercizi.map((e) => e.nome).join(', ') }} · {{ d.tutte_le_persone ? 'tutte le persone' : `solo ${d.persone.map((p) => p.nome).join(', ')}` }}</p>
                    <p v-if="d.nota" class="text-xs text-slate-500">{{ d.nota }}</p>
                    <p v-if="d.nota_revoca" class="text-xs text-slate-500">Revoca: {{ d.nota_revoca }}</p>
                    <a v-if="d.verbale" :href="indirizzoVerbale(d)" class="text-xs underline">Verbale: {{ d.verbale }}</a>
                    <div v-if="revoca.id === d.id" class="flex flex-wrap items-end gap-2 pt-2">
                        <label class="text-xs space-y-1"><span class="block">Data della revoca</span><Input v-model="revoca.revocata_il" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                        <label class="text-xs space-y-1 flex-1 min-w-[200px]"><span class="block">Nota</span><Input v-model="revoca.nota_revoca" class="h-8 text-xs" /></label>
                        <Button size="sm" class="h-8" @click="revoca_conferma">Revoca</Button>
                        <Button size="sm" variant="ghost" class="h-8" @click="revoca = { id: null, revocata_il: '', nota_revoca: '' }">Annulla</Button>
                    </div>
                </div>
                <p v-if="!dispense.length" class="p-4 text-sm text-slate-500">Nessuna dispensa registrata.</p>
            </div>

            <!-- Una dispensa nuova -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3 text-sm">
                <h3 class="font-semibold">Registra una dispensa</h3>
                <div class="flex flex-wrap gap-3">
                    <label class="text-xs space-y-1"><span class="block">Data della delibera</span><Input v-model="nuova.data_delibera" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                    <label class="text-xs space-y-1"><span class="block">Numero del verbale</span><Input v-model="nuova.numero_verbale" class="h-8 w-[140px] text-xs" /></label>
                    <label class="text-xs space-y-1"><span class="block">Il verbale (PDF o immagine, facoltativo)</span>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="text-xs" @change="(e: any) => (verbale = e.target.files?.[0] ?? null)" />
                    </label>
                </div>
                <div class="text-xs space-y-1">
                    <span class="block font-medium">Gli esercizi coperti</span>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="e in esercizi" :key="e.id" class="flex items-center gap-1.5">
                            <Checkbox :model-value="nuova.esercizi.includes(e.id)" @update:model-value="nuova.esercizi = alterna(nuova.esercizi, e.id)" /> {{ e.nome }}
                        </label>
                    </div>
                    <span class="block text-slate-500">Gli esercizi creati dopo non sono coperti finché non registri una dispensa che li comprende.</span>
                </div>
                <div class="text-xs space-y-1">
                    <span class="block font-medium">Per chi vale (lo dice la delibera)</span>
                    <label class="flex items-center gap-1.5"><input v-model="nuova.per_tutti" type="radio" value="1" /> Per tutte le persone</label>
                    <label class="flex items-center gap-1.5"><input v-model="nuova.per_tutti" type="radio" value="0" /> Solo per alcune persone</label>
                    <div v-if="nuova.per_tutti === '0'" class="flex flex-wrap gap-3 pt-1 max-h-[160px] overflow-y-auto">
                        <label v-for="p in persone" :key="p.id" class="flex items-center gap-1.5">
                            <Checkbox :model-value="nuova.persone.includes(p.id)" @update:model-value="nuova.persone = alterna(nuova.persone, p.id)" /> {{ p.nome }}
                        </label>
                    </div>
                </div>
                <label class="text-xs space-y-1 block"><span class="block">Nota</span><Textarea v-model="nuova.nota" rows="2" class="text-xs" /></label>
                <ul v-if="Object.keys(errori).length" class="text-xs text-rose-700 dark:text-rose-300 list-disc pl-4">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
                <Button size="sm" :disabled="!nuova.data_delibera || !nuova.esercizi.length || !nuova.per_tutti" @click="registra">Registra la dispensa</Button>
            </div>
        </div>
    </GestionaleLayout>
</template>
