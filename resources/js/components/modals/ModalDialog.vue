<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="closeModal" class="relative z-10">
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
        <div
            class="flex min-h-full items-center justify-center p-4 text-center"
        >
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900">
                {{ title }}
              </DialogTitle>
              <div class="mt-2">
                <p class="text-sm text-gray-500">
                  {{ message }}
                </p>
              </div>

              <div class="mt-4 flex items-center justify-end space-x-5">
                <button class="underline" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-primary" @click="closeModalWith('confirm')">Potvrdit</button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup>
import {ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'

const isOpen = ref(true)
const props = defineProps(['title', 'message']);
const emit = defineEmits(['confirm', 'cancel']);

function closeModal() {
  isOpen.value = false
}

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    emit(value);
  }, 300);
}

function openModal() {
  isOpen.value = true
}
</script>


<!--
<script setup>
import {ref} from "vue";
import {DialogTitle, TransitionChild, TransitionRoot} from "@headlessui/vue";

const props = defineProps(['title', 'message']);
const emit = defineEmits(['confirm', 'cancel']);

const isOpen = ref(true);

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    emit(value);
  }, 300);
}
</script>

<template>
  <TransitionRoot :show="isOpen" as="template">
    <dialog class="modal modal-open" @close="closeModalWith('cancel')">
      <TransitionChild
          as="template"
          enter="ease-out duration-300"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="ease-in duration-200"
          leave-from="opacity-100"
          leave-to="opacity-0">
        <div class="fixed inset-0 bg-base-100/50 backdrop-blur-sm transition-opacity"/>
      </TransitionChild>
      <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
          <TransitionChild
              as="template"
              enter="ease-out duration-300"
              enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enter-to="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leave-from="opacity-100 translate-y-0 sm:scale-100"
              leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
          >
            <div class="modal-box">
              <form method="dialog">
                <button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" @click="closeModalWith('cancel')">✕</button>
              </form>
              <DialogTitle>{{ title }}</DialogTitle>
              <h3 class="font-bold text-lg">{{ title }}</h3>
              <div class="my-3">
                {{ message }}
              </div>
              <div class="modal-action flex space-x-4">
                <button class="" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-primary" @click="closeModalWith('confirm')">Potvrdit</button>
              </div>
            </div>
          </TransitionChild>
        </div>
      </div>
    </dialog>
  </TransitionRoot>
</template>-->
