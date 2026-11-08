<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MoneyInput from '@/components/MoneyInput.vue';
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { AlertTriangle, ArrowDownLeft, Info, Landmark, Users } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';
import { oggiLocale } from '@/lib/giorno';

/**
 * L'incasso da terzi: l'affitto dell'antenna, gli interessi del conto, un rimborso. DARE la cassa reale, AVERE la voce di entrata, con chi ha
 * versato (`RegistraIncassoDaTerziAction`). Le voci sono quelle di entrata del condominio, delle gestioni dell'esercizio aperto; le casse solo
 * banca e contanti (in un fondo il registro di contabilità non vedrebbe l'entrata). L'importo si digita in euro e il server lo converte una volta
 * sola. Dopo la registrazione si torna da dove si è partiti (`da`).
 */

interface Voce { id: number; nome: string; label: string; preventivo: number }
interface Cassa { id: number; nome: string; tipo: string }
interface Anagrafica { id: number; nome: string }

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizio: { id: number; nome: string } | null;
    voci: Voce[];
    casse: Cassa[];
    anagrafiche: Anagrafica[];
    prossimo_protocollo: string;
    voce_scelta: number | null;
    da: 'incassi' | 'fatture' | null;
    link_piano_dei_conti: string | null;
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter({ fromCents: false });
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);

const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Incassi', href: generatePath('gestionale/:condominio/movimenti-rate', { condominio: props.condominio.id }) },
    { title: 'Incasso da terzi' },
]);

const pageGuides = [
    { title: 'Quando usarlo', description: 'Per il denaro che entra da chi non è un condòmino: l\'affitto dell\'antenna, gli interessi del conto, un rimborso. Le rate dei condòmini si registrano con «Registra incasso».', icon: Users, colorVariant: 'emerald' as const },
    { title: 'Effetto contabile', description: 'Una scrittura: DARE la banca o la cassa, AVERE la voce di entrata. L\'incasso riduce i costi dell\'esercizio, come il piano riduce già le quote.', icon: Landmark, colorVariant: 'blue' as const },
    { title: 'Il registro', description: 'L\'incasso compare nel registro di contabilità come entrata, con chi ha versato come controparte.', icon: ArrowDownLeft, colorVariant: 'amber' as const },
];

const oggi = oggiLocale();

// NB: il campo NON può chiamarsi `data`: `useForm` ha già un metodo `form.data()`.
const form = useForm({
    esercizio_id: props.esercizio?.id ?? null,
    conto_id: props.voce_scelta ?? (props.voci.length === 1 ? props.voci[0].id : null) as number | null,
    cassa_id: (props.casse.length === 1 ? props.casse[0].id : null) as number | null,
    anagrafica_id: null as number | null,
    data_operazione: oggi,
    causale: '',
    importo: 0,
    da: props.da,
});

const voceScelta = computed(() => props.voci.find((v) => v.id === form.conto_id) ?? null);
const cassaScelta = computed(() => props.casse.find((c) => c.id === form.cassa_id) ?? null);
const importoValido = computed(() => Number(form.importo) > 0);
const puoRegistrare = computed(() =>
    !!form.esercizio_id && !!form.conto_id && !!form.cassa_id && importoValido.value && form.causale.trim().length >= 3 && !!form.data_operazione,
);

const submit = (creaAltro = false) => {
    if (!puoRegistrare.value || form.processing) return;
    form.transform((dati) => ({ ...dati, crea_altro: creaAltro }))
        .post(route(generateRoute('gestionale.incassi-da-terzi.store'), { condominio: props.condominio.id }), {
            preserveScroll: !creaAltro,
            onSuccess: () => { if (creaAltro) form.reset(); },
        });
};

const urlDiPartenza = computed(() => props.da === 'fatture'
    ? generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id })
    : generatePath('gestionale/:condominio/movimenti-rate', { condominio: props.condominio.id }));

const annulla = () => {
    if (form.isDirty && !confirm('Uscire senza registrare? I dati inseriti andranno persi.')) return;
    router.visit(urlDiPartenza.value);
};

// Il rifiuto dell'azione (una regola di dominio), che non è di un campo.
const erroreDiDominio = computed(() => (form.errors as Record<string, string | undefined>).incasso_non_ammesso ?? null);
</script>

<template>
    <Head title="Incasso da terzi" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Incasso da terzi"
                page-subtitle="Denaro che entra da chi non è un condòmino, sulla voce di entrata del piano dei conti."
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :condominio="props.condominio"
                :condomini="props.condomini"
                :back-url="urlDiPartenza"
                back-text="Indietro"
            />

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div v-if="erroreDiDominio" data-testo="non-ammesso"
                 class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <p class="text-sm">{{ erroreDiDominio }}</p>
            </div>

            <div v-if="!props.esercizio" data-testo="senza-esercizio" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                Il condominio non ha un esercizio aperto: l'incasso da terzi si registra nell'esercizio aperto.
            </div>

            <div v-else-if="props.voci.length === 0" data-testo="senza-voci" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 space-y-1">
                <p>Il piano dei conti non ha una voce di entrata: l'incasso va su una voce di entrata.</p>
                <p>
                    Crea la voce di entrata con preventivo zero (per esempio «Affitto antenna» o «Interessi attivi»), poi torna qui.
                    <a v-if="props.link_piano_dei_conti" :href="props.link_piano_dei_conti" class="font-semibold underline underline-offset-2">Apri il piano dei conti</a>
                </p>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <form class="space-y-5" @submit.prevent="submit(false)">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="protocollo_previsto">Protocollo previsto</Label>
                                <div id="protocollo_previsto" class="flex h-9 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600"
                                     title="Numero previsto: diventa definitivo al salvataggio">{{ props.prossimo_protocollo }}</div>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Esercizio</Label>
                                <div class="flex h-9 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600">{{ props.esercizio?.nome ?? '—' }}</div>
                                <InputError :message="form.errors.esercizio_id" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="data_operazione">Data dell'incasso *</Label>
                                <Input id="data_operazione" v-model="form.data_operazione" type="date" :max="oggi" />
                                <InputError :message="form.errors.data_operazione" />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="importo">Importo *</Label>
                                <MoneyInput id="importo" v-model="form.importo" class="h-9 py-1" />
                                <InputError :message="form.errors.importo" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="voce">Voce di entrata *</Label>
                            <v-select id="voce" v-model="form.conto_id" :options="props.voci" :reduce="(v: Voce) => v.id" label="label"
                                      placeholder="Su quale voce di entrata va l'incasso?" />
                            <p v-if="voceScelta" class="text-xs text-slate-500">
                                Preventivo della voce: {{ euro(voceScelta.preventivo / 100) }}. L'incasso riduce i costi dell'esercizio, come il piano riduce già le quote.
                            </p>
                            <InputError :message="form.errors.conto_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="cassa">Banca o cassa *</Label>
                            <v-select id="cassa" v-model="form.cassa_id" :options="props.casse" :reduce="(c: Cassa) => c.id" label="nome"
                                      placeholder="Dove è entrato il denaro?" />
                            <p class="text-xs text-slate-500">Un fondo non si offre: per accantonare, registra l'incasso sulla banca e poi fai un giroconto.</p>
                            <InputError :message="form.errors.cassa_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="anagrafica">Chi ha versato <span class="font-normal text-slate-500">(facoltativo)</span></Label>
                            <v-select id="anagrafica" v-model="form.anagrafica_id" :options="props.anagrafiche" :reduce="(a: Anagrafica) => a.id" label="nome"
                                      placeholder="Una persona o una società della rubrica" />
                            <p class="text-xs text-slate-500">Il registro di contabilità lo mostra come controparte. Se non lo sai, lascia vuoto.</p>
                            <InputError :message="form.errors.anagrafica_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="causale">Causale *</Label>
                            <Input id="causale" v-model="form.causale" placeholder="Es. Affitto antenna, primo semestre" maxlength="255" />
                            <InputError :message="form.errors.causale" />
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <Button type="submit" :disabled="!puoRegistrare || form.processing">
                                {{ form.processing ? 'Registrazione…' : 'Registra incasso' }}
                            </Button>
                            <Button type="button" variant="outline" :disabled="!puoRegistrare || form.processing" @click="submit(true)">Registra e nuovo</Button>
                            <Button type="button" variant="outline" @click="annulla">Annulla</Button>
                        </div>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-950 p-6 text-slate-100 shadow-lg">
                    <span class="text-xs font-semibold uppercase tracking-wide">Anteprima scrittura</span>
                    <div class="mt-4 space-y-2.5">
                        <div class="rounded-lg border-l-2 border-emerald-400/80 bg-white/[0.04] px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-300">Dare</span>
                                <span class="text-base font-semibold tabular-nums">{{ importoValido ? euro(form.importo) : '—' }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm text-slate-300">{{ cassaScelta?.nome ?? 'Banca o cassa da scegliere' }}</p>
                        </div>
                        <div class="rounded-lg border-l-2 border-rose-400/80 bg-white/[0.04] px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[10px] font-black uppercase tracking-widest text-rose-300">Avere</span>
                                <span class="text-base font-semibold tabular-nums">{{ importoValido ? euro(form.importo) : '—' }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm text-slate-300">{{ voceScelta?.label ?? 'Voce di entrata da scegliere' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-start gap-2 rounded-lg bg-slate-800/60 p-3 text-xs text-slate-300">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>Un movimento a protocollo <span class="font-semibold">ENT</span>. Si annulla con lo storno, dalla pagina della scrittura.</span>
                    </div>
                </div>
            </div>
        </div>
    </GestionaleLayout>
</template>

<style scoped>
/* Le tendine alla misura dei campi nativi di questa pagina, come nella regolazione immediata: l'intervento resta su questa schermata. */
:deep(.vs__dropdown-toggle) {
    min-height: 2.25rem;
    border-radius: 0.375rem;
}
</style>
