<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'

const isOpen = ref(true)
const props = defineProps(['locationGroups', 'location']);
const emit = defineEmits(['confirm', 'cancel']);

const canSave = computed(() => props.location.name.length < 3);

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', props.location);
    } else {
      emit('cancel');
    }
  }, 300);
}
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="closeModalWith('cancel')" class="relative z-10">
      <TransitionChild
          as="template"
          enter="duration-300 ease-out"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="duration-200 ease-in"
          leave-from="opacity-100"
          leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/25 backdrop-blur-sm"/>
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto z-[999]">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95">
            <DialogPanel class="w-full max-w-md flex flex-col gap-6 transform overflow-hidden rounded-2xl glass p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="text-lg font-medium leading-6 text-primary">
                {{ props.location.id ? 'Úprava místa' : 'Nové místo' }}
              </DialogTitle>

              <div class="flex flex-col gap-3">

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Zadejte název pod kterým bude místo uloženo
                  </div>
                  <div>
                    <input v-model="props.location.name" type="text" placeholder="Zadejte název místa" class="input input-sm w-full"/>
                  </div>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Zvolte typ místa
                  </div>
                  <div>
                    <select v-model="props.location.type" class="select select-sm w-full">
                      <option value="CENTRAL" :selected="props.location.type === 'CENTRAL'">Centrála</option>
                      <option value="NEST" :selected="props.location.type === 'NEST'">Hnízdo</option>
                    </select>
                  </div>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Zvolte lokaci
                  </div>
                  <div>
                    <select v-model="props.location.location_group" class="select select-sm w-full">
                      <option :value="null" :selected="props.location.locationGroup === null">Nepřiřazeno</option>
                      <option v-if="props.locationGroups" v-for="locationGroup of props.locationGroups" :value="locationGroup" :selected="props.location.location_group?.id === locationGroup.id">{{ locationGroup.name }}</option>
                    </select>
                  </div>
                </div>

              </div>

              <div class="flex items-center justify-end space-x-5">
                <button class="underline" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-sm btn-primary" @click="closeModalWith('confirm')" :disabled="canSave">Potvrdit</button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>