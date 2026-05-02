<script setup>

import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue'
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { Send, Save, ShieldCheck, AlertCircle, Eye, EyeOff, Settings, Loader2, Info, Server, Terminal } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import Alert from '@/components/Alert.vue'
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { trans } from 'laravel-vue-i18n';
import axios from 'axios';

const page = usePage()
const flashMessage = computed(() => page.props.flash?.message)

const props = defineProps({
    settings:       Object,
    mail_host_env:  String,
    password_set:   Boolean,
});

const form = useForm({
    mail_enabled:     props.settings.mail_enabled,
    mail_driver:      props.settings.mail_driver ?? 'smtp',
    mail_host:        props.settings.mail_host,
    mail_port:        props.settings.mail_port,
    mail_username:    props.settings.mail_username,
    mail_password:    '',
    mail_encryption:  props.settings.mail_encryption,
    mail_from_address: props.settings.mail_from_address,
    mail_from_name:   props.settings.mail_from_name,
});

const showPassword   = ref(false);
const isTesting      = ref(false);
const testEmail      = ref('');
const testStatus     = ref(null);
const errorMessage   = ref('');

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
        const colors = {
            smtp:     'text-green-600 bg-green-100 border-green-200 dark:bg-green-900/30',
            sendmail: 'text-orange-600 bg-orange-100 border-orange-200 dark:bg-orange-900/30',
        };
        return {
            label: form.mail_driver.charAt(0).toUpperCase() + form.mail_driver.slice(1),
            color: colors[form.mail_driver] ?? colors.smtp,
        };
    }
    if (props.mail_host_env && !['127.0.0.1', 'localhost'].includes(props.mail_host_env)) {
        return { label: trans('impostazioni.mail_status.env'), color: 'text-blue-600 bg-blue-100 border-blue-200 dark:bg-blue-900/30' };
    }
    return { label: trans('impostazioni.mail_status.log'), color: 'text-gray-600 bg-gray-100 border-gray-200 dark:bg-gray-800/50' };
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
    } catch (error) {
        testStatus.value   = 'error';
        errorMessage.value = error.response?.data?.message || 'Errore di connessione';
    } finally {
        isTesting.value = false;
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="trans('impostazioni.header.mail_settings_title')" />

        <div class="px-4 py-6">

            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ trans('impostazioni.header.mail_settings_title') }}
                    </h1>
                    <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border rounded-full', mailStatus.color]">
                        {{ mailStatus.label }}
                    </span>
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ trans('impostazioni.header.mail_settings_description') }}
                </p>
            </div>

            <Card class="border shadow-none p-4">

                <!-- Toolbar -->
                <div class="flex flex-col w-full sm:flex-row sm:justify-end gap-2 mb-6">
                    <Button @click="submit" :disabled="form.processing" class="w-full sm:w-auto gap-2">
                        <Save class="w-4 h-4" />
                        {{ trans('impostazioni.label.save_settings') }}
                    </Button>
                    <Link as="button" href="/impostazioni"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90">
                        <Settings class="w-4 h-4" />
                        {{ trans('impostazioni.label.back_to_settings') }}
                    </Link>
                </div>

                <CardContent class="space-y-6 p-0">

                    <div v-if="flashMessage">
                        <Alert :message="flashMessage.message" :type="flashMessage.type" />
                    </div>

                    <!-- Info box -->
                    <div class="rounded-lg border bg-muted/40 p-4">
                        <div class="flex items-start gap-4">
                            <Info class="w-5 h-5 text-primary mt-0.5 shrink-0" />
                            <div class="grid gap-6 md:grid-cols-2 w-full">
                                <div>
                                    <h4 class="font-semibold text-sm mb-1 text-foreground">
                                        {{ trans('impostazioni.dialogs.mail_info_title') }}
                                    </h4>
                                    <p class="text-xs text-muted-foreground leading-relaxed"
                                        v-html="trans('impostazioni.dialogs.mail_info_description')"></p>
                                </div>
                                <div class="text-xs space-y-2 border-l pl-4 md:border-l-0 md:pl-0">
                                    <h4 class="font-semibold text-sm mb-1 text-foreground">
                                        {{ trans('impostazioni.dialogs.mail_legend_title') }}
                                    </h4>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-green-600"></span>
                                        <span class="font-medium">SMTP:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.driver.smtp_description') }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-orange-500"></span>
                                        <span class="font-medium">Sendmail:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.driver.sendmail_description') }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                        <span class="font-medium">Env:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.dialogs.mail_legend_env') }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                        <span class="font-medium">Log:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.dialogs.mail_legend_log') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="submit" class="space-y-6">

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
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
                                <div class="flex items-start gap-3 p-4 bg-orange-50 dark:bg-orange-900/20 rounded-lg text-orange-800 dark:text-orange-200 text-sm border border-orange-200 dark:border-orange-800">
                                    <AlertCircle class="h-5 w-5 shrink-0 mt-0.5" />
                                    <div>
                                        <p class="font-semibold mb-1">{{ trans('impostazioni.dialogs.mail_guide_title') }}</p>
                                        <ul class="list-disc list-inside text-orange-700 dark:text-orange-300 space-y-1">
                                            <li>{{ trans('impostazioni.dialogs.mail_guide_gmail') }}</li>
                                            <li>{{ trans('impostazioni.dialogs.mail_guide_smtp2go') }}</li>
                                            <li>{{ trans('impostazioni.dialogs.mail_guide_domain') }}</li>
                                        </ul>
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
                                            class="text-[11px] text-green-600 dark:text-green-400 mt-1 flex items-center gap-1">
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
                                <div class="flex items-start gap-3 p-4 bg-orange-50 dark:bg-orange-900/20 rounded-lg text-orange-800 dark:text-orange-200 text-sm border border-orange-200 dark:border-orange-800">
                                    <Terminal class="h-5 w-5 shrink-0 mt-0.5" />
                                    <div>
                                        <p class="font-semibold mb-1">{{ trans('impostazioni.dialogs.sendmail_guide_title') }}</p>
                                        <p class="text-orange-700 dark:text-orange-300 text-xs">
                                            {{ trans('impostazioni.dialogs.sendmail_guide_description') }}
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
                    </form>

                    <!-- Sezione test -->
                    <div v-if="form.mail_enabled" class="border-t pt-6 mt-6 animate-in fade-in duration-500">
                        <h3 class="text-sm font-semibold mb-4 flex items-center gap-2">
                            <Send class="w-4 h-4 text-primary" />
                            {{ trans('impostazioni.dialogs.test_header') }}
                        </h3>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <Input v-model="testEmail" type="email"
                                :placeholder="trans('impostazioni.placeholder.test_recipient')"
                                class="flex-1 text-sm" />
                            <Button variant="outline" @click="runTest"
                                :disabled="isTesting || !testEmail" class="gap-2 shrink-0">
                                <Loader2 v-if="isTesting" class="w-4 h-4 animate-spin" />
                                <ShieldCheck v-else class="w-4 h-4" />
                                {{ trans('impostazioni.label.send_test') }}
                            </Button>
                        </div>

                        <div v-if="testStatus === 'success'"
                            class="mt-4 p-3 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 rounded-md text-sm flex items-center gap-2 border border-green-200">
                            <ShieldCheck class="w-4 h-4" />
                            {{ trans('impostazioni.dialogs.test_success_message') }}
                        </div>
                        <div v-if="testStatus === 'error'"
                            class="mt-4 p-3 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 rounded-md text-sm border border-red-200">
                            <p class="font-bold flex items-center gap-2 mb-1">
                                <AlertCircle class="w-4 h-4" />
                                {{ trans('impostazioni.dialogs.test_error_title') }}
                            </p>
                            <p class="text-xs opacity-80">{{ errorMessage }}</p>
                        </div>
                    </div>

                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>