<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Mail, Send, Save, ShieldCheck, AlertCircle, Eye, EyeOff, Loader2, Server, Terminal, CheckCircle2, Globe, AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import Alert from '@/components/Alert.vue';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import MailSetupGuide from '@/components/guides/MailSetupGuide.vue';
import { trans } from 'laravel-vue-i18n';
import axios from 'axios';

const page = usePage();
const flashMessage = computed(() => (page.props as any).flash?.message);
const showGuide = ref(false);

interface MailSettings {
    mail_enabled?: boolean;
    mail_driver?: string;
    mail_host?: string;
    mail_port?: string | number;
    mail_username?: string;
    mail_encryption?: string;
    mail_from_address?: string;
    mail_from_name?: string;
}

const props = defineProps<{
    settings: MailSettings;
    mail_host_env?: string;
    password_set?: boolean;
}>();

const form = useForm({
    mail_enabled:     props.settings?.mail_enabled ?? false,
    mail_driver:      props.settings?.mail_driver ?? 'smtp',
    mail_host:        props.settings?.mail_host ?? '',
    mail_port:        props.settings?.mail_port ?? '',
    mail_username:    props.settings?.mail_username ?? '',
    mail_password:    '',
    mail_encryption:  props.settings?.mail_encryption ?? 'tls',
    mail_from_address: props.settings?.mail_from_address ?? '',
    mail_from_name:   props.settings?.mail_from_name ?? '',
});

const showPassword   = ref(false);
const isTesting      = ref(false);
const testEmail      = ref('');
const testStatus     = ref<'success' | 'error' | null>(null);
const errorMessage   = ref('');

const breadcrumbs = computed(() => [
    {
        title: trans('impostazioni.label.settings') || 'Impostazioni',
        href: '/impostazioni',
    },
    {
        title: trans('impostazioni.header.mail_settings_title') || 'Configurazione Email',
        href: '/impostazioni/mail',
    },
]);

const pageGuides = computed(() => [
    {
        title: 'Posta in Uscita',
        description: 'Configura il server per l\'invio di avvisi, solleciti di pagamento e ricevute ai condòmini.',
        icon: Mail,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Driver SMTP',
        description: 'Consigliato per la massima affidabilità. Usa il tuo gestore mail (Gmail, Outlook, Aruba, SMTP2GO).',
        icon: Server,
        colorVariant: 'emerald' as const,
    },
    {
        title: 'Driver Sendmail',
        description: 'Utilizza il servizio di posta nativo del server di hosting. Più semplice ma soggetto a filtri antispam.',
        icon: Terminal,
        colorVariant: 'amber' as const,
    },
]);

// ─── Driver options ───────────────────────────────────────────────────────────
const drivers = [
    {
        value: 'smtp',
        label: 'SMTP',
        description: trans('impostazioni.driver.smtp_description'),
        icon: Server,
    },
    {
        value: 'sendmail',
        label: 'Sendmail',
        description: trans('impostazioni.driver.sendmail_description'),
        icon: Terminal,
    },
];

// ─── Computed ────────────────────────────────────────────────────────────────
const isSmtp     = computed(() => form.mail_driver === 'smtp');
const isSendmail = computed(() => form.mail_driver === 'sendmail');

const passwordPlaceholder = computed(() =>
    props.password_set
        ? trans('impostazioni.placeholder.mail_password_keep')
        : trans('impostazioni.placeholder.mail_password_enter')
);

const mailStatus = computed(() => {
    if (form.mail_enabled) {
        const colors: Record<string, string> = {
            smtp:     'text-emerald-700 bg-emerald-100 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300',
            sendmail: 'text-amber-700 bg-amber-100 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300',
        };
        return {
            label: form.mail_driver.charAt(0).toUpperCase() + form.mail_driver.slice(1),
            color: colors[form.mail_driver] ?? colors.smtp,
        };
    }
    if (props.mail_host_env && !['127.0.0.1', 'localhost'].includes(props.mail_host_env)) {
        return { label: trans('impostazioni.mail_status.env') || 'ENV', color: 'text-blue-700 bg-blue-100 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300' };
    }
    return { label: trans('impostazioni.mail_status.log') || 'Log (Disabilitato)', color: 'text-slate-600 bg-slate-100 border-slate-200 dark:bg-slate-800/50 dark:text-slate-400' };
});

// ─── Actions ─────────────────────────────────────────────────────────────────
const submit = () => {
    form.post(route('admin.settings.mail.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('mail_password'),
    });
};

const runTest = async () => {
    if (!testEmail.value) return;
    isTesting.value  = true;
    testStatus.value = null;
    errorMessage.value = '';

    try {
        const response = await axios.post(route('admin.settings.mail.test'), {
            ...form.data(),
            test_email: testEmail.value,
        });
        if (response.data.success) testStatus.value = 'success';
    } catch (error: any) {
        testStatus.value   = 'error';
        errorMessage.value = error.response?.data?.message || 'Errore di connessione';
    } finally {
        isTesting.value = false;
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('impostazioni.header.mail_settings_title')" />

        <div class="px-4 py-6 space-y-6">

            <!-- Header con guida e breadcrumbs integrati -->
            <PageHeaderGuide
                :page-title="trans('impostazioni.header.mail_settings_title') || 'Configurazione Mail'"
                :page-subtitle="trans('impostazioni.header.mail_settings_description') || 'Configura il server SMTP o Sendmail per l\'invio di avvisi, solleciti e comunicazioni alle famiglie e ai fornitori.'"
                :icon="Mail"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                back-url="/impostazioni"
                :back-text="trans('impostazioni.label.settings') || 'Impostazioni'"
            >
                <template #actions>
                    <Button variant="outline" size="sm" @click="showGuide = true" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200 shadow-sm">
                        <Mail class="w-4 h-4" />
                        Guida configurazione
                    </Button>
                </template>
            </PageHeaderGuide>

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <form @submit.prevent="submit">
                <Card class="border shadow-none">
                    <!-- Status Bar -->
                    <div class="px-6 pt-6 pb-2">
                        <div class="flex items-center gap-3 bg-muted/30 px-3 py-2 rounded-md border w-fit">
                            <div class="relative flex h-3 w-3">
                                <span v-if="form.mail_enabled" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3" :class="form.mail_enabled ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                            </div>
                            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Stato Servizio:</span>
                            <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border rounded-md', mailStatus.color]">
                                {{ mailStatus.label }}
                            </span>
                        </div>
                    </div>

                    <CardContent class="space-y-6 pt-4">

                        <!-- Toggle abilitazione -->
                        <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4 bg-background shadow-sm">
                            <div class="flex-1">
                                <Label class="block text-sm font-medium leading-none mb-1">
                                    {{ trans('impostazioni.label.enable_db_settings') }}
                                </Label>
                                <p class="text-sm text-muted-foreground">
                                    {{ trans('impostazioni.label.enable_db_description') }}
                                </p>
                            </div>
                            <Switch v-model="form.mail_enabled" />
                        </div>

                        <!-- Sezione configurazione (visibile solo se abilitato) -->
                        <div v-if="form.mail_enabled" class="space-y-6 animate-in slide-in-from-top-2 duration-300">

                            <!-- Selezione driver -->
                            <div>
                                <Label class="block text-sm font-medium mb-3">
                                    {{ trans('impostazioni.label.mail_driver') }}
                                </Label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <button
                                        v-for="d in drivers"
                                        :key="d.value"
                                        type="button"
                                        @click="form.mail_driver = d.value"
                                        :class="[
                                            'flex items-start gap-3 rounded-lg border p-3 text-left transition-all',
                                            form.mail_driver === d.value
                                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                : 'border-input bg-background hover:bg-muted/50'
                                        ]"
                                    >
                                        <component :is="d.icon" class="w-4 h-4 mt-0.5 shrink-0"
                                            :class="form.mail_driver === d.value ? 'text-primary' : 'text-muted-foreground'" />
                                        <div>
                                            <p class="text-sm font-semibold leading-none mb-1"
                                                :class="form.mail_driver === d.value ? 'text-primary' : 'text-foreground'">
                                                {{ d.label }}
                                            </p>
                                            <p class="text-xs text-muted-foreground leading-snug">
                                                {{ d.description }}
                                            </p>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <!-- Campi SMTP -->
                            <div v-if="isSmtp" class="space-y-4 animate-in slide-in-from-top-1 duration-200">
                                <!-- Card Guida SMTP -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 my-2">
                                    <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 text-white shadow-sm flex items-start gap-3">
                                        <div class="p-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 shrink-0">
                                            <CheckCircle2 class="w-5 h-5" />
                                        </div>
                                        <div class="space-y-1">
                                            <h4 class="font-bold text-sm text-white flex items-center gap-2">
                                                Gmail <span class="text-[9px] uppercase font-black tracking-widest px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Consigliato per tutti</span>
                                            </h4>
                                            <p class="text-xs text-slate-300 leading-relaxed">
                                                Attiva la verifica in 2 passaggi, genera una <strong>"Password per le App"</strong> e usa <code class="text-emerald-300 bg-slate-800 px-1 py-0.5 rounded">smtp.gmail.com</code>, porta <code class="text-emerald-300 bg-slate-800 px-1 py-0.5 rounded">587</code>, TLS. Funziona su qualsiasi hosting senza configurazioni DNS.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 text-white shadow-sm flex items-start gap-3">
                                        <div class="p-2 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 shrink-0">
                                            <Globe class="w-5 h-5" />
                                        </div>
                                        <div class="space-y-1">
                                            <h4 class="font-bold text-sm text-white">
                                                Altri provider SMTP (Brevo, SMTP2Go, ecc.)
                                            </h4>
                                            <p class="text-xs text-slate-300 leading-relaxed">
                                                Richiedono la verifica del dominio mittente tramite record DNS (SPF/DKIM). Usa questa opzione solo se hai un dominio proprio con accesso al pannello DNS.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="sm:col-span-2">
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_host') }}</Label>
                                        <Input v-model="form.mail_host" type="text"
                                            :placeholder="trans('impostazioni.placeholder.mail_host')"
                                            class="w-full text-sm" />
                                        <InputError :message="form.errors.mail_host" />
                                    </div>
                                    <div>
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_port') }}</Label>
                                        <Input v-model="form.mail_port" placeholder="587" class="w-full text-sm" />
                                        <InputError :message="form.errors.mail_port" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_username') }}</Label>
                                        <Input v-model="form.mail_username" type="text" class="w-full text-sm" />
                                    </div>
                                    <div>
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_password') }}</Label>
                                        <div class="relative">
                                            <Input v-model="form.mail_password"
                                                :type="showPassword ? 'text' : 'password'"
                                                class="w-full text-sm pr-10"
                                                :placeholder="passwordPlaceholder" />
                                            <button type="button" @click="showPassword = !showPassword"
                                                class="absolute right-3 top-2.5 text-muted-foreground hover:text-foreground">
                                                <Eye v-if="!showPassword" class="h-4 w-4" />
                                                <EyeOff v-else class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <InputError :message="form.errors.mail_password" />
                                        <p v-if="password_set && !form.mail_password"
                                            class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1 flex items-center gap-1">
                                            <ShieldCheck class="w-3 h-3" />
                                            {{ trans('impostazioni.label.password_is_set') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 border-t pt-4">
                                    <div>
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_encryption') }}</Label>
                                        <select v-model="form.mail_encryption"
                                            class="w-full text-sm bg-background border border-input rounded-md px-3 py-2 h-[38px] focus:ring-2 focus:ring-ring focus:outline-none">
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="null">{{ trans('impostazioni.label.encryption_none') }}</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_from_address') }}</Label>
                                        <Input v-model="form.mail_from_address" type="email"
                                            :placeholder="trans('impostazioni.placeholder.mail_from_address')"
                                            class="w-full text-sm" />
                                        <InputError :message="form.errors.mail_from_address" />
                                    </div>
                                </div>
                            </div>

                            <!-- Campi Sendmail -->
                            <div v-if="isSendmail" class="space-y-4 animate-in slide-in-from-top-1 duration-200">
                                <!-- Card Guida Sendmail -->
                                <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 text-white shadow-sm flex items-start gap-3 my-2">
                                    <div class="p-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 shrink-0">
                                        <AlertTriangle class="w-5 h-5" />
                                    </div>
                                    <div class="space-y-1">
                                        <h4 class="font-bold text-sm text-white flex items-center gap-2">
                                            Sendmail <span class="text-[9px] uppercase font-black tracking-widest px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">Solo server dedicati</span>
                                        </h4>
                                        <p class="text-xs text-slate-300 leading-relaxed">
                                            Usa questa opzione <strong>SOLO</strong> se il tuo server è un VPS o server dedicato con Postfix/Exim installato e i record SPF e DKIM configurati nel DNS del dominio mittente. Su hosting condivisi (Altervista, Aruba, ecc.) le email verranno rifiutate dai destinatari perché i provider moderni (Gmail, Outlook) richiedono l'autenticazione DNS. Per hosting condivisi usa Gmail SMTP.
                                        </p>
                                    </div>
                                </div>

                                <div class="border-t pt-4">
                                    <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_from_address') }}</Label>
                                    <Input v-model="form.mail_from_address" type="email"
                                        :placeholder="trans('impostazioni.placeholder.mail_from_address')"
                                        class="w-full text-sm" />
                                    <InputError :message="form.errors.mail_from_address" />
                                </div>
                            </div>

                            <!-- from_name: comune a tutti i driver -->
                            <div>
                                <Label class="block text-sm font-medium mb-1.5">{{ trans('impostazioni.label.mail_from_name') }}</Label>
                                <Input v-model="form.mail_from_name" type="text"
                                    placeholder="Kondomanager" class="w-full text-sm" />
                            </div>

                        </div>

                        <!-- Sezione test -->
                        <div v-if="form.mail_enabled" class="border-t pt-5 mt-6 animate-in fade-in duration-500">

                            <!-- Avviso modifiche non salvate -->
                            <div v-if="form.isDirty"
                                class="mb-5 p-3 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200 rounded-lg text-sm flex items-center gap-2.5 border border-amber-200 dark:border-amber-800 shadow-sm">
                                <AlertCircle class="w-4 h-4 shrink-0 text-amber-600" />
                                <span>{{ trans('impostazioni.dialogs.test_unsaved_warning') }}</span>
                            </div>

                            <h3 class="text-sm font-semibold mb-4 flex items-center gap-2">
                                <Send class="w-4 h-4 text-primary" />
                                {{ trans('impostazioni.dialogs.test_header') }}
                            </h3>
                            <div class="flex flex-col sm:flex-row gap-3">
                                <Input v-model="testEmail" type="email"
                                    :placeholder="trans('impostazioni.placeholder.test_recipient')"
                                    class="flex-1 text-sm" />
                                <Button type="button" variant="outline" @click="runTest"
                                    :disabled="isTesting || !testEmail" class="gap-2 shrink-0">
                                    <Loader2 v-if="isTesting" class="w-4 h-4 animate-spin" />
                                    <ShieldCheck v-else class="w-4 h-4" />
                                    {{ trans('impostazioni.label.send_test') }}
                                </Button>
                            </div>

                            <div v-if="testStatus === 'success'"
                                class="mt-4 p-3 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-800 dark:text-emerald-300 rounded-md text-sm flex items-center gap-2 border border-emerald-200">
                                <ShieldCheck class="w-4 h-4 text-emerald-600" />
                                {{ trans('impostazioni.dialogs.test_success_message') }}
                            </div>
                            <div v-if="testStatus === 'error'"
                                class="mt-4 p-3 bg-rose-50 dark:bg-rose-900/20 text-rose-800 dark:text-rose-300 rounded-md text-sm border border-rose-200">
                                <p class="font-bold flex items-center gap-2 mb-1">
                                    <AlertCircle class="w-4 h-4 text-rose-600" />
                                    {{ trans('impostazioni.dialogs.test_error_title') }}
                                </p>
                                <p class="text-xs opacity-80">{{ errorMessage }}</p>
                            </div>
                        </div>

                    </CardContent>

                    <CardFooter class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t px-6 py-4 bg-slate-50/50 dark:bg-slate-900/50 mt-6">
                        <span class="text-xs text-muted-foreground">
                            Verifica la connessione inviando un'email di prova prima di salvare.
                        </span>
                        <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto gap-2">
                            <Save class="w-4 h-4" />
                            {{ trans('impostazioni.label.save_settings') || 'Salva Impostazioni' }}
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </div>

        <!-- Sheet Guida Dettagliata -->
        <MailSetupGuide v-model:open="showGuide" />
    </AppLayout>
</template>
