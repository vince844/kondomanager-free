<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Configurazione email»: l'elenco delle caselle dello studio (decisione 572, bozza approvata `design/impostazioni_posta_pec_bozza.html`). In alto le tre
 * card (la posta, la PEC, gli invii del mese con «Apri lo storico»); sotto una riga per casella, «La posta dello studio» e «La casella PEC dello studio»,
 * ognuna con il suo stato, «Serve per», la riga «Adesso», il suo «Salva» e il suo «Prova l'invio». Una sola «Guida», con il cassetto, e «Storia».
 *
 * Le caselle in più si aggiungeranno nello stesso elenco (proposta 3); oggi non c'è un pulsante «Aggiungi». La vede e la cambia solo l'amministratore.
 * Sotto le caselle, «I promemoria per email»: «Promemoria per email delle convocazioni da spedire», che si cambia da solo, al clic.
 */
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Mail, MailCheck, Send } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import Alert from '@/components/Alert.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import MailSetupGuide from '@/components/guides/MailSetupGuide.vue';
import CasellaDellaPosta from '@/components/impostazioni/caselle/CasellaDellaPosta.vue';
import CasellaPecDelloStudio from '@/components/impostazioni/caselle/CasellaPecDelloStudio.vue';
import PromemoriaDelleConvocazioni from '@/components/impostazioni/caselle/PromemoriaDelleConvocazioni.vue';
import { classiValore } from '@/components/impostazioni/caselle/aiuti';
import type { CasellaDellaPosta as Posta, CasellaPecDelloStudio as Pec, ImpostazioniDellaPosta, InviiDelMese,
    PromemoriaDelleConvocazioni as Promemoria } from '@/components/impostazioni/caselle/tipi';

const props = defineProps<{
    settings: ImpostazioniDellaPosta;
    mail_host_env?: string | null;
    password_set?: boolean;
    caselle: { posta: Posta; pec: Pec };
    invii: InviiDelMese;
    /** «Prima imposta la casella PEC dello studio» porta qui con la PEC aperta. */
    apri?: 'posta' | 'pec' | null;
    /** «Promemoria per email delle convocazioni da spedire», sotto le caselle. */
    promemoria?: Promemoria | null;
}>();

const page = usePage();
const flashMessage = computed(() => (page.props as { flash?: { message?: { message: string; type: string } } }).flash?.message);
const emailDiChiEntra = computed(() => (page.props as { auth?: { user?: { email?: string } } }).auth?.user?.email ?? '');

const guidaAperta = ref(false);
const postaAperta = ref(props.apri === 'posta');
const pecAperta = ref(props.apri === 'pec');

const breadcrumbs = computed(() => [
    { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
    { title: trans('impostazioni.header.mail_settings_title'), href: '/impostazioni/mail' },
]);

onMounted(async () => {
    if (props.apri === 'pec') {
        await nextTick();
        document.getElementById('casella-pec')?.scrollIntoView?.({ block: 'start' });
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('impostazioni.header.mail_settings_title')" />

        <div class="space-y-5 px-4 py-6">
            <PageHeaderGuide
                :storia="{ tipo: 'impostazioni_posta' }"
                :page-title="trans('impostazioni.header.mail_settings_title')"
                :page-subtitle="trans('impostazioni.header.mail_settings_description')"
                :guides="[]"
                :breadcrumbs="breadcrumbs"
                back-url="/impostazioni"
                :back-text="trans('impostazioni.label.settings')"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="guidaAperta = true"
            />

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <!-- Le tre card: la posta, la PEC, gli invii del mese (proposta 12). -->
            <div class="grid grid-cols-1 gap-3.5 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-posta">
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><Mail class="h-3.5 w-3.5" /> La posta dello studio</p>
                    <p class="mt-1 text-lg font-bold md:text-xl" :class="classiValore(caselle.posta.stato)">{{ caselle.posta.card.valore }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400">{{ caselle.posta.card.sotto }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-pec">
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><MailCheck class="h-3.5 w-3.5" /> La casella PEC</p>
                    <p class="mt-1 text-lg font-bold md:text-xl" :class="classiValore(caselle.pec.stato)">{{ caselle.pec.card.valore }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400">{{ caselle.pec.card.sotto }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-invii">
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><Send class="h-3.5 w-3.5" /> {{ invii.titolo }}</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-slate-900 md:text-xl dark:text-slate-100">{{ invii.valore }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ invii.sotto }} ·
                        <Link :href="invii.url" class="text-slate-700 underline underline-offset-2 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Apri lo storico</Link>
                    </p>
                </div>
            </div>

            <!-- L'elenco delle caselle (proposte 1, 2 e 3). -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="elenco-caselle">
                <div class="border-b border-slate-100 px-4 py-3.5 sm:px-5 dark:border-slate-800">
                    <h3 class="text-[15px] font-semibold text-slate-900 dark:text-slate-100">Le caselle dello studio</h3>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Il programma usa da solo quella giusta per ogni invio. Si impostano una volta; le password
                        restano cifrate e non si rileggono.</p>
                </div>
                <CasellaDellaPosta v-model:aperta="postaAperta" :settings="settings" :password-set="password_set" :casella="caselle.posta"
                    :destinatario-di-serie="emailDiChiEntra" />
                <div class="border-t border-slate-100 dark:border-slate-800">
                    <CasellaPecDelloStudio v-model:aperta="pecAperta" :casella="caselle.pec" />
                </div>
            </div>

            <PromemoriaDelleConvocazioni v-if="promemoria" :promemoria="promemoria" />
        </div>

        <MailSetupGuide v-model:open="guidaAperta" />
    </AppLayout>
</template>
