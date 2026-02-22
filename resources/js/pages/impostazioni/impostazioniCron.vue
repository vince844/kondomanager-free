<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Copy, RefreshCw, AlertTriangle, CheckCircle, Settings, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Card, CardContent } from '@/components/ui/card';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { trans } from 'laravel-vue-i18n';

const props = defineProps({
    enabled: Boolean,
    webhookUrl: String,
    allowedIps: Array,
});

const form = useForm({
    enabled: props.enabled,
});

const copied = ref(false);
const isRegenerateDialogOpen = ref(false);

const toggleEnabled = () => {
    form.post(route('impostazioni.cron.update'), {
        preserveScroll: true,
    });
};

const openRegenerateDialog = () => {
    isRegenerateDialogOpen.value = true;
};

const regenerateToken = () => {
    useForm({}).post(route('impostazioni.cron.regenerate'));
    isRegenerateDialogOpen.value = false;
};

const copyToClipboard = () => {
    navigator.clipboard.writeText(props.webhookUrl);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
};
</script>

<template>
    <AppLayout>
        <Head :title="trans('impostazioni.header.cron_settings_title')" />

        <div class="px-4 py-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ trans('impostazioni.header.cron_settings_title') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ trans('impostazioni.header.cron_settings_description') }}
                </p>
            </div>

            <Card class="border shadow-none p-4">
                <div class="flex flex-col w-full sm:flex-row sm:justify-end mb-4">
                    <Link
                        as="button"
                        href="/impostazioni"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        <Settings class="w-4 h-4" />
                        <span>{{ trans('impostazioni.label.settings') }}</span>
                    </Link>
                </div>
                
                <CardContent class="space-y-4 p-0">

                    <div class="rounded-lg border bg-muted/40 p-4 mb-6">
                        <div class="flex items-start gap-4">
                            <Info class="w-5 h-5 text-primary mt-0.5 shrink-0" />
                            <div class="grid gap-6 md:grid-cols-2 w-full">
                                <div>
                                    <h4 class="font-semibold text-sm mb-1 text-foreground">
                                        {{ trans('impostazioni.dialogs.cron_info_title') }}
                                    </h4>
                                    <p 
                                        class="text-xs text-muted-foreground leading-relaxed" 
                                        v-html="trans('impostazioni.dialogs.cron_info_description')"
                                    ></p>
                                </div>
                                <div class="text-xs space-y-2 border-l pl-4 md:border-l-0 md:pl-0 md:border-l-0">
                                    <h4 class="font-semibold text-sm mb-1 text-foreground">
                                        {{ trans('impostazioni.dialogs.cron_legend_title') }}
                                    </h4>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-orange-500"></span>
                                        <span class="font-medium">Webhook:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.dialogs.cron_legend_external') }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                        <span class="font-medium">System Cron:</span>
                                        <span class="text-muted-foreground">{{ trans('impostazioni.dialogs.cron_legend_internal') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TOGGLE SCHEDULER -->
                    <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium leading-none mb-1">
                                {{ trans('impostazioni.dialogs.enable_external_scheduler_title') }}
                            </label>
                            <p class="text-sm text-muted-foreground">
                                {{ trans('impostazioni.dialogs.enable_external_scheduler_description') }}
                            </p>
                        </div>
                        
                        <Switch 
                            v-model="form.enabled" 
                            @update:model-value="toggleEnabled"
                        />
                    </div>

                    <!-- WEBHOOK URL -->
                    <div v-if="props.enabled" class="flex flex-col gap-4 border rounded-lg p-4">
                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium leading-none">
                                    {{ trans('impostazioni.dialogs.webhook_url_title') }}
                                </label>
                                <span class="text-xs text-orange-600 bg-orange-100 dark:bg-orange-900/30 px-2 py-0.5 rounded-full">
                                    {{ trans('impostazioni.dialogs.webhook_url_badge') }}
                                </span>
                            </div>
                            <p class="text-sm text-muted-foreground mb-3">
                                {{ trans('impostazioni.dialogs.webhook_url_description') }}
                            </p>
                        </div>
                        
                        <div class="flex gap-2">
                            <input 
                                readonly 
                                :value="props.webhookUrl" 
                                class="flex-1 text-sm bg-background border border-input rounded-md px-3 py-2 text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <Button 
                                variant="outline" 
                                @click="copyToClipboard"
                                :title="trans('impostazioni.actions.copy_url')"
                            >
                                <CheckCircle v-if="copied" class="h-4 w-4 text-green-500" />
                                <Copy v-else class="h-4 w-4" />
                            </Button>
                            <Button 
                                variant="destructive" 
                                size="icon" 
                                @click="openRegenerateDialog" 
                                :title="trans('impostazioni.actions.regenerate_token')"
                            >
                                <RefreshCw class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- SECURITY WARNING -->
                    <div class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-blue-800 dark:text-blue-200 text-sm border border-blue-200 dark:border-blue-800">
                        <AlertTriangle class="h-5 w-5 shrink-0 mt-0.5" />
                        <div>
                            <p class="font-semibold mb-1">
                                {{ trans('impostazioni.dialogs.security_warning_title') }}
                            </p>
                            <p class="text-blue-700 dark:text-blue-300">
                                {{ trans('impostazioni.dialogs.security_warning_description') }}
                            </p>
                        </div>
                    </div>

                </CardContent>
            </Card>
        </div>

        <!-- CONFIRM DIALOG -->
        <ConfirmDialog
            v-model:modelValue="isRegenerateDialogOpen"
            :title="trans('impostazioni.actions.regenerate_token')"
            :description="trans('impostazioni.confirmations.regenerate_token')"
            @confirm="regenerateToken"
        />
    </AppLayout>
</template>