<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import { watch, onMounted, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { LoaderCircle, Info, Trash2, ShieldCheck, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from 'vue-select';
import { Checkbox } from '@/components/ui/checkbox';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Separator } from '@/components/ui/separator';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { Badge } from '@/components/ui/badge';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  role: Role;
  permissions: Permission[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'ruoli', href: '/ruoli' },
  { title: 'modifica ruolo', href: '#' },
];

const form = useForm({
  name: props.role.name,
  description: props.role.description,
  permissions: [] as Permission[],
  accessAdmin:
    props.role.permissions.some(
      permission => permission.name === 'Accesso pannello amministratore'
    ) || false,
});

// Solo i permessi assegnati NON admin (per il form iniziale)
const assignedPermissionsWithoutAdmin = computed(() => 
  props.role.permissions.filter(
    permission => permission.name !== 'Accesso pannello amministratore'
  )
);

// Trova il permesso admin nei permessi del ruolo (se esiste)
const adminPermission = computed(() => 
  props.role.permissions.find(p => p.name === 'Accesso pannello amministratore')
);

// Lista COMPLETA dei permessi da visualizzare (già salvati + selezionati nel form)
const displayedPermissions = computed(() => {
  const permissions: Permission[] = [];
  
  // 1. Aggiungi tutti i permessi già salvati (escluso admin)
  const savedPermissions = props.role.permissions.filter(
    p => p.name !== 'Accesso pannello amministratore'
  );
  permissions.push(...savedPermissions);
  
  // 2. Aggiungi i permessi selezionati nel form che non sono già salvati
  const savedIds = savedPermissions.map(p => p.id);
  const newPermissions = form.permissions.filter(p => !savedIds.includes(p.id));
  permissions.push(...newPermissions);
  
  // 3. Se la checkbox è attiva E il permesso admin esiste, aggiungilo
  if (form.accessAdmin && adminPermission.value) {
    permissions.push(adminPermission.value);
  }
  
  return permissions;
});

const selectablePermissions = computed(() => {
  const assignedIds = assignedPermissionsWithoutAdmin.value.map(p => p.id);
  const selectedIds = form.permissions.map(p => p.id);

  return props.permissions.filter(
    permission =>
      !assignedIds.includes(permission.id) &&
      !selectedIds.includes(permission.id)
  );
});

// Controlla se un permesso è già salvato nel database
const isPersistedPermission = (permission: Permission) => {
  return props.role.permissions.some(p => p.id === permission.id);
};

// Controlla se un permesso è quello di accesso admin
const isAdminPermission = (permission: Permission) => {
  return permission.name === 'Accesso pannello amministratore';
};

// Rimuovi un permesso dalla selezione (solo per permessi non ancora salvati)
const removePermissionFromForm = (permission: Permission) => {
  if (isAdminPermission(permission)) {
    form.accessAdmin = false;
  } else {
    form.permissions = form.permissions.filter(p => p.id !== permission.id);
  }
};

onMounted(() => {
  form.permissions = [...assignedPermissionsWithoutAdmin.value];
});

watch(
  () => props.role,
  () => {
    form.permissions = props.role.permissions.filter(
      p => p.name !== 'Accesso pannello amministratore'
    );
    form.accessAdmin = props.role.permissions.some(
      permission => permission.name === 'Accesso pannello amministratore'
    );
  }
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        permissions: data.permissions.map(p => p.id)
    })).put(route("ruoli.update", {id: props.role.id}), {
        preserveScroll: true
    });
};

// Gestisci la revoca del permesso admin
const handleRevokeAdminPermission = () => {
  form.accessAdmin = false;
};
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="Modifica ruolo" />

    <UtentiLayout>
      <form @submit.prevent="submit">
        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <Label for="name">Nome</Label>
            <Input
              id="name"
              class="mt-1 block w-full"
              v-model="form.name"
              @focus="form.clearErrors('name')"
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
              placeholder="Inserisci una descrizione per il ruolo"
            />
            <InputError :message="form.errors.description" />
          </div>
        </div>

        <div class="mt-6">
          <Label>Permessi</Label>
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

        <div class="mb-4">
          <label class="flex items-center space-x-2 mt-6">
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
            Modifica ruolo
          </Button>
        </div>
      </form>

      <Separator class="my-8" />

      <div>
        <div class="flex items-center gap-2 mb-4">
          <div>
            <h2 class="text-xl font-semibold">Anteprima permessi del ruolo</h2>
            <p class="text-sm text-muted-foreground">
              Permessi salvati e nuovi permessi selezionati (non ancora salvati)
            </p>
          </div>
        </div>

        <div v-if="!displayedPermissions.length" class="text-center py-12 border rounded-lg bg-muted/20">
          Nessun permesso associato a questo ruolo
        </div>

        <div v-else class="flex flex-col gap-3">
          <Item 
            v-for="permission in displayedPermissions" 
            :key="permission.id"
            :variant="isAdminPermission(permission) ? 'muted' : 'outline'"
          >
            <ItemContent>
              <div class="flex items-center gap-2">
                <ItemTitle>{{ permission.name }}</ItemTitle>
                
                <!-- Badge per permessi NON ancora salvati -->
                <Badge v-if="!isPersistedPermission(permission)" variant="outline" class="text-xs">
                  Da salvare
                </Badge>
              </div>
              <ItemDescription>
                {{ permission.description }}
              </ItemDescription>
            </ItemContent>
            <ItemActions>
              <!-- Permessi già salvati: revoca tramite route -->
              <Link
                v-if="isPersistedPermission(permission)"
                :href="route('ruoli.permissions.destroy', [role.id, permission.id])"
                method="delete"
                as="button"
                preserve-scroll
                @success="isAdminPermission(permission) ? handleRevokeAdminPermission() : null"
              >
                <Button  v-if="!isAdminPermission(permission)" variant="ghost" size="sm" class="text-destructive hover:text-destructive hover:bg-destructive/10">
                  <Trash2 class="h-4 w-4" />
                </Button>
              </Link>
              
              <!-- Permessi NON salvati: rimuovi dal form -->
              <Button
                v-else
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
    </UtentiLayout>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>