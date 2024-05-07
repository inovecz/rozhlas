<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'
import VueMultiselect from 'vue-multiselect'
import "vue-multiselect/dist/vue-multiselect.css";
import {contactGroupStore} from "../../store/contactGroupStore.js";

const isOpen = ref(true)
const props = defineProps(['contactGroups', 'contact']);
const emit = defineEmits(['confirm', 'cancel']);
const contactGroupStoreInfo = contactGroupStore();
const contactGroups = ref(contactGroupStoreInfo.contactGroups);

const cantSave = computed(() => {
  let retVal = false;
  if (props.contact.name.length < 3) {
    retVal = true;
  }
  if (props.contact.surname.length < 3) {
    retVal = true;
  }
  return retVal;
});

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', props.contact);
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
                {{ props.contact.id ? 'Úprava kontaktu' : 'Nový kontakt' }}
              </DialogTitle>

              <div class="flex flex-col gap-3">

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Jméno
                  </div>
                  <div>
                    <input v-model="props.contact.name" type="text" placeholder="Zadejte jméno (min. 3 znaky)" class="input input-sm w-full"/>
                  </div>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Příjmení
                  </div>
                  <div>
                    <input v-model="props.contact.surname" type="text" placeholder="Zadejte příjmení (min. 3 znaky)" class="input input-sm w-full"/>
                  </div>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    E-mail
                  </div>
                  <div>
                    <input v-model="props.contact.email" type="email" placeholder="Zadejte e-mail" class="input input-sm w-full"/>
                  </div>
                </div>

                <div class="form-control">
                  <label class="label cursor-pointer">
                    <span class="label-text">Použít e-mail pro zasílání informačních zpráv</span>
                    <input v-model="props.contact.has_info_email_allowed" type="checkbox" checked="checked" class="checkbox checkbox-primary"/>
                  </label>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Telefon
                  </div>
                  <div>
                    <input v-model="props.contact.phone" type="text" placeholder="Zadejte telefon" class="input input-sm w-full"/>
                  </div>
                </div>

                <div class="form-control">
                  <label class="label cursor-pointer">
                    <span class="label-text">Použít telefon pro zasílání informačních zpráv</span>
                    <input v-model="props.contact.has_info_sms_allowed" type="checkbox" checked="checked" class="checkbox checkbox-primary"/>
                  </label>
                </div>

                <div class="flex flex-col gap-2">
                  <div class="text-sm text-base-content">
                    Skupiny
                  </div>
                  <div>
                    <VueMultiselect v-model="props.contact.contact_groups" :options="contactGroups" label="name" trackBy="id" :multiple="true"
                                    :close-on-select="false"
                                    placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                                    selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
                      <template #noResult>
                        <span>Zadaným parametrům neodpovídá žádná skupina</span>
                      </template>
                    </VueMultiselect>
                  </div>
                </div>
              </div>

              <div class="flex items-center justify-end space-x-5">
                <button class="underline" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-sm btn-primary" @click="closeModalWith('confirm')" :disabled="cantSave">Potvrdit</button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>