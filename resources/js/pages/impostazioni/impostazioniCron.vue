<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Copy, RefreshCw, AlertTriangle, CheckCircle, Settings, Info, Globe, Terminal, Server } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Card, CardContent } from '@/components/ui/card';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { trans } from 'laravel-vue-i18n';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import CronSetupGuide from '@/components/guides/CronSetupGuide.vue';

const props = defineProps({
    enabled: Boolean,
    webhookUrl: String,
    allowedIps: Array,
    lastHeartbeat: Number,
    lastHeartbeatSource: String,
});

const form = useForm({
    enabled: props.enabled,
});

const breadcrumbs = computed(() => [
  {
    title: trans('impostazioni.label.settings'),
    href: '/impostazioni',
  },
  {
    title: trans('impostazioni.header.cron_settings_title'),
    href: '/impostazioni/cron',
  },
]);

const pageGuides = computed(() => [
  {
    title: trans('impostazioni.dialogs.cron_guide_1_title'),
    description: trans('impostazioni.dialogs.cron_guide_1_desc'),
    icon: Info,
    colorVariant: 'blue'
  },
  {
    title: trans('impostazioni.dialogs.cron_guide_2_title'),
    description: trans('impostazioni.dialogs.cron_guide_2_desc'),
    icon: Globe,
    colorVariant: 'amber'
  },
  {
    title: trans('impostazioni.dialogs.cron_guide_3_title'),
    description: trans('impostazioni.dialogs.cron_guide_3_desc'),
    icon: Terminal,
    colorVariant: 'emerald'
  }
]);

const copied = ref(false);
const isRegenerateDialogOpen = ref(false);
const showPleskGuide = ref(false);

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

const currentTime = ref(Math.floor(Date.now() / 1000));
let timeInterval = null;
let pollInterval = null;

onMounted(() => {
    timeInterval = setInterval(() => {
        currentTime.value = Math.floor(Date.now() / 1000);
    }, 1000);

    pollInterval = setInterval(() => {
        router.reload({
            only: ['lastHeartbeat', 'lastHeartbeatSource'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 15000);
});

onUnmounted(() => {
    if (timeInterval) clearInterval(timeInterval);
    if (pollInterval) clearInterval(pollInterval);
});

const isCronActive = computed(() => {
    if (!props.lastHeartbeat) return false;
    // Consider active if heartbeat is within the last 3 minutes (180 seconds)
    return (currentTime.value - props.lastHeartbeat) <= 180;
});

const lastHeartbeatRelative = computed(() => {
    if (!props.lastHeartbeat) return trans('impostazioni.dialogs.cron_status_never_run');
    const seconds = currentTime.value - props.lastHeartbeat;
    if (seconds < 0) return trans('impostazioni.dialogs.cron_status_now');
    if (seconds < 60) return trans('impostazioni.dialogs.cron_status_seconds_ago', { seconds });
    const mins = Math.floor(seconds / 60);
    return trans('impostazioni.dialogs.cron_status_minutes_ago', { minutes: mins });
});
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head :title="trans('impostazioni.header.cron_settings_title')" />

        <div class="px-4 py-6 space-y-6">
            <PageHeaderGuide
                :page-title="trans('impostazioni.header.cron_settings_title')"
                :page-subtitle="trans('impostazioni.header.cron_settings_description')"
                :icon="Settings"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                back-url="/impostazioni"
                :back-text="trans('impostazioni.label.settings')"
            >
                <template #actions>
                    <Button variant="outline" size="sm" @click="showPleskGuide = true" class="bg-white gap-2 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 border-indigo-200">
                        <Server class="w-4 h-4" />
                        Guida configurazione
                    </Button>
                </template>
            </PageHeaderGuide>

            <Card class="border shadow-none p-4">
                <div class="flex flex-col w-full sm:flex-row sm:justify-between mb-4">
                    <!-- HEARTBEAT STATUS -->
                    <div class="flex items-center gap-3 bg-muted/30 px-3 py-2 rounded-md border mb-4 sm:mb-0">
                        <div class="relative flex h-3 w-3">
                            <span v-if="isCronActive" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3" :class="isCronActive ? 'bg-green-500' : 'bg-red-500'"></span>
                        </div>
                        <div class="text-sm flex items-center">
                            <span class="font-medium text-foreground mr-1">{{ trans('impostazioni.dialogs.cron_label_daemon') }}</span>
                            <span :class="isCronActive ? 'text-green-600' : 'text-red-600 font-semibold'">
                                {{ isCronActive ? trans('impostazioni.dialogs.cron_status_active') : trans('impostazioni.dialogs.cron_status_error') }}
                            </span>
                            
                            <!-- SOURCE INDICATOR -->
                            <div v-if="isCronActive && lastHeartbeatSource" class="ml-3 flex items-center gap-1.5 px-2 py-0.5 rounded-full border bg-background/50 text-xs">
                                <span class="w-2 h-2 rounded-full" :class="lastHeartbeatSource === 'webhook' ? 'bg-orange-500' : 'bg-gray-400'"></span>
                                <span class="text-muted-foreground capitalize">{{ lastHeartbeatSource }}</span>
                            </div>

                            <span class="text-xs text-muted-foreground ml-2">
                                {{ trans('impostazioni.dialogs.cron_label_last_check') }} {{ lastHeartbeatRelative }})
                            </span>
                        </div>
                    </div>

                </div>
                
                <CardContent class="space-y-4 p-0">
                    
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

        <ConfirmDialog
            v-model:modelValue="isRegenerateDialogOpen"
            :title="trans('impostazioni.actions.regenerate_token')"
            :description="trans('impostazioni.confirmations.regenerate_token')"
            @confirm="regenerateToken"
        />
    </AppLayout>

    <CronSetupGuide v-model:open="showPleskGuide" />
</template>