<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { LoaderCircle, Info, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { Badge } from '@/components/ui/badge';
import type { BreadcrumbItem } from '@/types';
import type { Permission } from '@/types/permissions';

const props = defineProps<{ 
  permissions: Permission[] 
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'ruoli', href: '/ruoli' },
  { title: 'crea ruolo', href: '#' },
];

const form = useForm({
    name: '',
    description: '',
    permissions: [] as Permission[],
    accessAdmin: false,
});

// Permesso admin "placeholder" per la visualizzazione
const adminPermissionPlaceholder: Permission = {
    id: 0, // ID fittizio
    name: 'Accesso pannello amministratore',
    description: 'Permette l\'accesso al pannello di amministrazione'
};

// Computed per i permessi selezionabili nel dropdown
const selectablePermissions = computed(() => {
    const selectedIds = form.permissions.map(p => p.id);
    return props.permissions.filter(
        permission => !selectedIds.includes(permission.id)
    );
});

// Computed per i permessi da visualizzare (selezionati + admin se checkbox attiva)
const displayedPermissions = computed(() => {
    const permissions: Permission[] = [...form.permissions];
    
    // Aggiungi il permesso admin se la checkbox è attiva
    if (form.accessAdmin) {
        permissions.push(adminPermissionPlaceholder);
    }
    
    return permissions;
});

// Controlla se un permesso è quello di accesso admin
const isAdminPermission = (permission: Permission) => {
    return permission.name === 'Accesso pannello amministratore';
};

// Rimuovi un permesso dalla selezione
const removePermissionFromForm = (permission: Permission) => {
    if (isAdminPermission(permission)) {
        form.accessAdmin = false;
    } else {
        form.permissions = form.permissions.filter(p => p.id !== permission.id);
    }
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        permissions: data.permissions.map(p => p.id)
    })).post(route("ruoli.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        }
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Crea nuovo ruolo" />

        <UtentiLayout>
            <form @submit.prevent="submit">
                <div>
                    <h3 class="text-lg font-medium leading-6 text-gray-900">Crea nuovo ruolo</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Di seguito è possibile creare un nuovo ruolo e selezionare i permessi ad esso associati
                    </p>
                </div>

                <Separator class="my-4" />

                <div class="py-4">
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <Label for="name">Nome</Label>
                            <Input 
                                id="name" 
                                class="mt-1 block w-full"
                                v-model="form.name" 
                                @focus="form.clearErrors('name')"
                                autocomplete="name" 
                                placeholder="Inserisci un nome per il ruolo" 
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="sm:col-span-3">
                            <Label for="description">Descrizione</Label>
                            <Input 
                                id="description" 
                                class="mt-1 block w-full"
                                v-model="form.description" 
                                @focus="form.clearErrors('description')"
                                placeholder="Inserisci la descrizione per il ruolo" 
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                    </div>

                    <div class="mt-6">
                        <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                            <Label for="permissions">Permessi</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="cursor-pointer">
                                        <Info class="w-4 h-4 text-muted-foreground" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                Permessi del ruolo
                                            </h4>
                                            <p class="text-sm">
                                                Seleziona permessi specifici da assegnare al ruolo creato. Questi permessi definiranno le azioni che gli utenti con questo ruolo potranno eseguire all'interno del sistema.
                                            </p>
                                        </div>
                                    </div>
                                </HoverCardContent>
                            </HoverCard>
                        </div>

                        <v-select 
                            multiple
                            :options="selectablePermissions" 
                            label="name"
                            :close-on-select="false"
                            v-model="form.permissions"
                            placeholder="Seleziona permessi ruolo"
                        >
                            <template #option="{ name, description }">
                                <div class="flex flex-col">
                                    <span class="font-medium">{{ name }}</span>
                                    <span class="text-sm text-gray-500">{{ description }}</span>
                                </div>
                            </template>
                        </v-select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="flex items-center space-x-2">
                        <Checkbox v-model="form.accessAdmin" />
                        <span class="font-medium">Dai accesso al layout amministratore</span>
                        <HoverCard>
                            <HoverCardTrigger as-child>
                                <Info class="w-4 h-4 text-muted-foreground cursor-pointer" />
                            </HoverCardTrigger>
                            <HoverCardContent class="w-80">
                                <div class="flex justify-between space-x-4">
                                    <div class="space-y-1">
                                        <h4 class="text-sm font-semibold">
                                            Accesso layout amministratore
                                        </h4>
                                        <p class="text-sm">
                                            Se selezionata questa opzione permette di mostrare il layout amministratore con funzioni avanzate per il nuovo ruolo creato.
                                        </p>
                                    </div>
                                </div>
                            </HoverCardContent>
                        </HoverCard>
                    </label>
                </div>

                <div class="pt-5">
                    <Button :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Crea ruolo
                    </Button>
                </div>
            </form>

            <!-- Anteprima permessi selezionati -->
            <template v-if="displayedPermissions.length">
                <Separator class="my-8" />

                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <div>
                            <h2 class="text-xl font-semibold">Anteprima permessi del ruolo</h2>
                            <p class="text-sm text-muted-foreground">
                                Permessi selezionati che verranno assegnati al ruolo
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <Item 
                            v-for="permission in displayedPermissions" 
                            :key="permission.id"
                            :variant="isAdminPermission(permission) ? 'muted' : 'default'"
                        >
                            <ItemContent>
                                <div class="flex items-center gap-2">
                                    <ItemTitle>{{ permission.name }}</ItemTitle>
                                    <Badge variant="outline" class="text-xs">
                                        Da salvare
                                    </Badge>
                                </div>
                                <ItemDescription>
                                    {{ permission.description }}
                                </ItemDescription>
                            </ItemContent>
                            <ItemActions>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive hover:text-destructive hover:bg-destructive/10"
                                    @click="removePermissionFromForm(permission)"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </ItemActions>
                        </Item>
                    </div>
                </div>
            </template>
        </UtentiLayout>
    </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>