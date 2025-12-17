<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { LoaderCircle, Info } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { Separator } from '@/components/ui/separator';
import { Item, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  roles: Role[];
  permissions: Permission[];
  anagrafiche: Anagrafica[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'crea utente', href: '#' },
];

const form = useForm({
    name: '',
    email: '',
    roles: null as number | null,
    permissions: [] as Permission[],
    anagrafica: null as number | null,
});

// Computed per ottenere il ruolo selezionato
const selectedRole = computed(() => {
    if (!form.roles) return null;
    return props.roles.find(r => r.id === form.roles) ?? null;
});

// Computed per i permessi ereditati dal ruolo selezionato
const inheritedPermissions = computed(() => {
    const role = selectedRole.value;
    if (!role) return [];
    
    const perms = role.permissions || [];
    return Array.isArray(perms) ? perms : [];
});

// Computed per i permessi selezionabili nel dropdown
const selectablePermissions = computed(() => {
    const inheritedIds = inheritedPermissions.value.map(p => p.id);
    const selectedIds = form.permissions.map(p => p.id);

    return props.permissions.filter(
        permission =>
            !inheritedIds.includes(permission.id) &&
            !selectedIds.includes(permission.id)
    );
});

// Watch per rimuovere permessi che diventano ereditati quando cambia il ruolo
watch(
    () => form.roles,
    (newRoleId) => {
        const newRole = props.roles.find(r => r.id === newRoleId);
        if (newRole && newRole.permissions) {
            const newRolePermissionIds = newRole.permissions.map(p => p.id);
            // Rimuovi i permessi aggiuntivi che sono ora ereditati dal nuovo ruolo
            form.permissions = form.permissions.filter(
                perm => !newRolePermissionIds.includes(perm.id)
            );
        }
    }
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        permissions: data.permissions.map(p => p.id)
    })).post(route("utenti.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        }
    });
};
</script>

<template>
  <Head title="Crea nuovo utente" />
  
  <AppLayout :breadcrumbs="breadcrumbs">
    <UtentiLayout>
      <form @submit.prevent="submit">
        <div>
          <h3 class="text-lg font-medium leading-6 text-gray-900">Nuovo utente</h3>
          <p class="mt-1 text-sm text-gray-500">
            Di seguito è possibile creare un nuovo utente, puoi assegnare un ruolo, un'anagrafica e dei permessi specifici per questo utente
          </p>
        </div>

        <Separator class="my-4" />

        <div class="py-4">
          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="name">Nome e cognome</Label>
              <Input 
                id="name" 
                class="mt-1 block w-full"
                v-model="form.name" 
                @focus="form.clearErrors('name')"
                autocomplete="name" 
                placeholder="Nome e cognome" 
              />
              <InputError :message="form.errors.name" />
            </div>

            <div class="sm:col-span-3">
              <Label for="email">Indirizzo email</Label>
              <Input 
                id="email" 
                class="mt-1 block w-full"
                v-model="form.email" 
                @focus="form.clearErrors('email')"
                autocomplete="email" 
                placeholder="Indirizzo email" 
              />
              <InputError class="mt-2" :message="form.errors.email" />
            </div>
          </div>

          <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">

              <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                <Label for="stato">Ruolo utente</Label>

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
                            Ruolo utente
                        </h4>
                        <p class="text-sm">
                            Seleziona il ruolo da assegnare all'utente, scegli tra i ruoli di default oppure uno di quelli da te creati.
                        </p>
                        <p class="text-sm">
                            I permessi associati al ruolo verranno ereditati automaticamente.
                        </p>
                        </div>
                    </div>
                    </HoverCardContent>
                </HoverCard>
              </div>

              <v-select 
                :options="roles" 
                label="name" 
                v-model="form.roles"
                :reduce="(option: Role) => option.id"
                @update:modelValue="form.clearErrors('roles')"
                placeholder="Seleziona ruolo utente"
              />

              <InputError class="mt-2" :message="form.errors.roles" />
            </div>

            <div class="sm:col-span-3">
              <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                <Label for="stato">Associa anagrafica</Label>

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
                            Associa anagrafica
                        </h4>
                        <p class="text-sm">
                            Seleziona l'anagrafica da associare all'utente. L'anagrafica associata potra accedere al sistema con le credenziali dell'utente creato e consultare i propri dati e quelli a lui collegati.
                        </p>
                        </div>
                    </div>
                    </HoverCardContent>
                </HoverCard>
              </div>
              <v-select 
                :options="anagrafiche" 
                label="nome" 
                v-model="form.anagrafica"
                :reduce="(option: Anagrafica) => option.id"
                @update:modelValue="form.clearErrors('anagrafica')" 
                placeholder="Seleziona anagrafica"
              >
                <template #option="{ nome, indirizzo }">
                  <div class="flex flex-col">
                    <span class="font-medium">{{ nome }}</span>
                    <span class="text-sm text-gray-500">{{ indirizzo }}</span>
                  </div>
                </template>
                <template #selected-option="{ nome, indirizzo }">
                  <div class="flex items-center gap-2">
                    <span class="font-medium">{{ nome }}</span>
                    <span class="text-gray-500 text-sm">– {{ indirizzo }}</span>
                  </div>
                </template>
              </v-select>
              <InputError class="mt-2" :message="form.errors.anagrafica" />
            </div>
          </div>

          <div class="mt-6">
            <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
              <Label for="stato">Permessi aggiuntivi</Label>

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
                        Permessi aggiuntivi
                    </h4>
                    <p class="text-sm">
                        Seleziona permessi specifici da assegnare all'utente oltre a quelli ereditati dal ruolo selezionato.
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
              placeholder="Seleziona permessi aggiuntivi"
            >
              <template #option="{ name, description }">
                <div class="flex flex-col">
                  <span class="font-medium">{{ name }}</span>
                  <span class="text-sm text-gray-500">{{ description }}</span>
                </div>
              </template>
            </v-select>
            <p class="mt-1 text-sm text-gray-500">
              I permessi del ruolo selezionato sono ereditati automaticamente
            </p>
          </div>
        </div>

        <div class="pt-5">
          <Button :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Crea utente
          </Button>
        </div>
      </form>

      <!-- Mostra i permessi ereditati solo se è stato selezionato un ruolo -->
      <template v-if="form.roles">
        <Separator class="my-8" />

        <div>
          <div class="flex items-center gap-2 mb-4">
            <div>
              <h2 class="text-xl font-semibold">Permessi ereditati dal ruolo</h2>
              <p class="text-sm text-muted-foreground">
                Questi permessi sono assegnati tramite il ruolo 
                <span v-if="selectedRole" class="font-semibold">{{ selectedRole.name }}</span>
                e verranno assegnati automaticamente all'utente
              </p>
            </div>
          </div>

          <div v-if="!inheritedPermissions.length" class="text-center py-12 border rounded-lg bg-muted/20">
            Nessun permesso ereditato dal ruolo
          </div>

          <div v-else class="flex flex-col gap-3">
            <Item 
              v-for="permission in inheritedPermissions" 
              :key="permission.id"
              variant="muted"
            >
              <ItemContent>
                <div class="flex items-center gap-2">
                  <ItemTitle>{{ permission.name }}</ItemTitle>
                </div>
                <ItemDescription>
                  {{ permission.description }}
                </ItemDescription>
              </ItemContent>
            </Item>
          </div>
        </div>
      </template>
    </UtentiLayout>
  </AppLayout> 
</template>

<style src="vue-select/dist/vue-select.css"></style>