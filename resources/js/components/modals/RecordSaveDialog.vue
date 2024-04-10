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

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95">
            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl glass p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="text-lg font-medium leading-6 text-primary">
                Uložit nahrávku
              </DialogTitle>
              <div class="mt-2">
                <p class="text-sm text-base-content">
                  Zadejte název pod kterým bude nahrávka uložena
                </p>
                <div class="my-3">
                  <input ref="recordNameInput" v-model="recordName" type="text" placeholder="Zadejte název nahrávky" class="input input-sm w-full"/>
                </div>
              </div>

              <div class="mt-4 flex items-center justify-end space-x-5">
                <button class="underline" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-primary" @click="closeModalWith('confirm')" :disabled="canSave">Potvrdit</button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'

const isOpen = ref(true)
const props = defineProps(['title', 'message']);
const emit = defineEmits(['confirm', 'cancel']);

const recordName = ref('Nahrávka ' + new Date().toLocaleString('cs-CZ'));
const canSave = computed(() => recordName.value.length < 3);

function closeModal() {
  isOpen.value = false
}

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', recordName.value);
    } else {
      emit('cancel');
    }
  }, 300);
}

function openModal() {
  isOpen.value = true
}
</script>