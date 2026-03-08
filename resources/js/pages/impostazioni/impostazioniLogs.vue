<script setup lang="ts">

import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { debounce } from 'lodash'; 
import { Mail, Activity, Search, Server, CheckCircle2, FileText, AlertCircle, LayoutList } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { trans } from 'laravel-vue-i18n';

// Props
const props = defineProps<{
    mailLogs: any;
    activityLogs: any;
    filters: { search: string };
}>();

// Stato Tab e Ricerca
const activeTab = ref('mail');
const search = ref(props.filters.search || '');

// Gestione Ricerca
const handleSearch = debounce((val: string) => {
    router.get(route('logs.index'), { search: val }, { 
        preserveState: true, 
        preserveScroll: true, 
        replace: true 
    });
}, 300);

watch(search, (val) => {
    handleSearch(val);
});

// Helper Formattazione Data
const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};

const getInitials = (name: string) => {
    if (!name) return 'SY';
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
};
</script>

<template>
    <AppLayout>
        <Head :title="trans('impostazioni.dialogs.logs_settings_title')" />

        <div class="px-4 py-6">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white flex items-center gap-2">
                        {{ trans('impostazioni.dialogs.logs_settings_title') }}
                    </h1>
                    <p class="text-muted-foreground text-sm mt-1">
                        {{ trans('impostazioni.dialogs.logs_settings_description') }}
                    </p>
                </div>
                
                <div class="relative w-full md:w-64">
                    <Search class="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input 
                        v-model="search" 
                        :placeholder="trans('impostazioni.dialogs.logs_search_placeholder')" 
                        class="pl-8 h-9 bg-white dark:bg-gray-950" 
                    />
                </div>
            </div>

            <nav class="inline-flex items-center space-x-2 shadow ring-1 ring-black/5 md:rounded-lg p-2 mb-4 bg-white dark:bg-card w-full sm:w-auto overflow-x-auto">
                
                <Button
                    variant="ghost"
                    class="justify-start gap-2"
                    :class="{ 'bg-muted': activeTab === 'mail' }"
                    @click="activeTab = 'mail'"
                >
                    <Mail class="h-4 w-4" />
                    {{ trans('impostazioni.dialogs.logs_mail_tab') }}
                    <Badge variant="secondary" class="ml-1 h-5 px-1.5 text-[10px] pointer-events-none">
                        {{ props.mailLogs.total }}
                    </Badge>
                </Button>

                <Button
                    variant="ghost"
                    class="justify-start gap-2"
                    :class="{ 'bg-muted': activeTab === 'activity' }"
                    @click="activeTab = 'activity'"
                >
                    <Activity class="h-4 w-4" />
                    {{ trans('impostazioni.dialogs.logs_activity_tab') }}
                    <Badge variant="secondary" class="ml-1 h-5 px-1.5 text-[10px] pointer-events-none">
                        {{ props.activityLogs.total }}
                    </Badge>
                </Button>

            </nav>

            <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white dark:bg-card">
                
                <section v-if="activeTab === 'mail'" class="w-full">
                    <div class="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow class="bg-muted/50">
                                    <TableHead class="w-[50px] text-center">Stato</TableHead>
                                    <TableHead>Destinatario</TableHead>
                                    <TableHead class="hidden md:table-cell">Oggetto</TableHead>
                                    <TableHead class="hidden lg:table-cell">Driver</TableHead>
                                    <TableHead class="text-right">Data Invio</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="log in mailLogs.data" :key="log.id" class="hover:bg-muted/50">
                                    <TableCell class="text-center p-2">
                                        <div v-if="log.status === 'sent'" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-green-600">
                                            <CheckCircle2 class="w-4 h-4" />
                                        </div>
                                        <div v-else-if="log.status === 'logged'" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-blue-600">
                                            <FileText class="w-4 h-4" />
                                        </div>
                                        <div v-else class="inline-flex items-center justify-center w-8 h-8 rounded-full text-red-600">
                                            <AlertCircle class="w-4 h-4" />
                                        </div>
                                    </TableCell>
                                    <TableCell class="font-medium text-sm">
                                        {{ log.recipient }}
                                    </TableCell>
                                    <TableCell class="hidden md:table-cell text-sm max-w-[250px]">
                                        <div class="truncate" :title="log.subject">{{ log.subject }}</div>
                                    </TableCell>
                                    <TableCell class="hidden lg:table-cell">
                                        <Badge variant="outline" class="font-mono text-[10px] uppercase">{{ log.mailer }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-right text-xs text-muted-foreground whitespace-nowrap">
                                        {{ formatDate(log.sent_at) }}
                                    </TableCell>
                                </TableRow>
                                
                                <TableRow v-if="mailLogs.data.length === 0">
                                    <TableCell colspan="5" class="h-32 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center gap-2">
                                            <Mail class="w-8 h-8 opacity-20" />
                                            <span>Nessun log email trovato</span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <div v-if="mailLogs.links.length > 3" class="flex items-center justify-between pt-4">
                        <div class="text-xs text-muted-foreground">
                            Pag. {{ mailLogs.current_page }} di {{ mailLogs.last_page }}
                        </div>
                        <div class="flex gap-1">
                            <Button 
                                v-for="(link, i) in mailLogs.links" :key="i"
                                :as="Link" 
                                :href="link.url || '#'" 
                                :variant="link.active ? 'default' : 'outline'"
                                size="icon"
                                class="h-8 w-8"
                                :disabled="!link.url"
                                v-html="link.label.includes('Previous') ? '<' : link.label.includes('Next') ? '>' : link.label"
                            />
                        </div>
                    </div>
                </section>

                <section v-if="activeTab === 'activity'" class="w-full">
                    <div class="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow class="bg-muted/50">
                                    <TableHead class="w-[60px]"></TableHead>
                                    <TableHead>Utente</TableHead>
                                    <TableHead>Azione</TableHead>
                                    <TableHead class="hidden md:table-cell">Dettagli</TableHead>
                                    <TableHead class="text-right">Data</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="log in activityLogs.data" :key="log.id" class="hover:bg-muted/50">
                                    <TableCell class="p-2">
                                        <Avatar class="h-8 w-8 border">
                                            <AvatarImage v-if="log.causer?.profile_photo_url" :src="log.causer.profile_photo_url" />
                                            <AvatarFallback class="text-xs">{{ log.causer ? getInitials(log.causer.name) : 'SYS' }}</AvatarFallback>
                                        </Avatar>
                                    </TableCell>
                                    <TableCell>
                                        <div v-if="log.causer">
                                            <div class="font-medium text-sm">{{ log.causer.name }}</div>
                                            <div class="text-[10px] text-muted-foreground">{{ log.causer.email }}</div>
                                        </div>
                                        <div v-else class="flex items-center gap-2 text-muted-foreground text-sm">
                                            <Server class="w-3 h-3" /> Sistema
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary" class="font-medium capitalize text-xs">
                                            {{ log.description || log.event }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="hidden md:table-cell">
                                        <span v-if="log.subject_type" class="text-xs font-mono text-muted-foreground bg-muted px-2 py-1 rounded">
                                            {{ log.subject_type.split('\\').pop() }} <span class="text-primary">#{{ log.subject_id }}</span>
                                        </span>
                                        <span v-else class="text-muted-foreground">-</span>
                                    </TableCell>
                                    <TableCell class="text-right text-xs text-muted-foreground whitespace-nowrap">
                                        {{ formatDate(log.created_at) }}
                                    </TableCell>
                                </TableRow>
                                
                                <TableRow v-if="activityLogs.data.length === 0">
                                    <TableCell colspan="5" class="h-32 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center gap-2">
                                            <LayoutList class="w-8 h-8 opacity-20" />
                                            <span>Nessuna attività registrata</span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <div v-if="activityLogs.links.length > 3" class="flex items-center justify-between pt-4">
                        <div class="text-xs text-muted-foreground">
                            Pag. {{ activityLogs.current_page }} di {{ activityLogs.last_page }}
                        </div>
                        <div class="flex gap-1">
                            <Button 
                                v-for="(link, i) in activityLogs.links" :key="i"
                                :as="Link" 
                                :href="link.url || '#'" 
                                :variant="link.active ? 'default' : 'outline'"
                                size="icon"
                                class="h-8 w-8"
                                :disabled="!link.url"
                                v-html="link.label.includes('Previous') ? '<' : link.label.includes('Next') ? '>' : link.label"
                            />
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </AppLayout>
</template>
