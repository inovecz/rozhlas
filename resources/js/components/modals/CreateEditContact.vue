<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'
import VueMultiselect from 'vue-multiselect'
import "vue-multiselect/dist/vue-multiselect.css";
import {contactGroupStore} from "../../store/contactGroupStore.js";
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";
import Checkbox from "../forms/Checkbox.vue";
import CustomFormControl from "../forms/CustomFormControl.vue";

const errorBag = ref({});
const isOpen = ref(true)
const props = defineProps(['contactGroups', 'contact']);
const emit = defineEmits(['confirm', 'cancel']);
const contactGroupStoreInfo = contactGroupStore();
const contactGroups = ref(contactGroupStoreInfo.contactGroups);

const cantSave = computed(() => {
  errorBag.value = {};
  const phoneRegex = /^(\+(?:\d{1,3}))?(\s|-)?((?:\d{2,3})|\(\d{2,3}\))(?:\s|-)?(\d{3})(?:\s|-)?(\d{3})$/;
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  let retVal = false;
  if (props.contact.name.length < 3) {
    errorBag.value.name = 'Jméno musí mít alespoň 3 znaky';
    retVal = true;
  }
  if (props.contact.surname.length < 3) {
    errorBag.value.surname = 'Příjmení musí mít alespoň 3 znaky';
    retVal = true;
  }

  if (props.contact.email && !emailRegex.test(props.contact.email)) {
    errorBag.value.email = 'Zadejte platný e-mail';
    retVal = true;
  }

  if (props.contact.phone && !phoneRegex.test(props.contact.phone)) {
    errorBag.value.phone = 'Zadejte platný telefon';
    retVal = true;
  }

  if (!props.contact.phone && !props.contact.email) {
    errorBag.value.phone = 'Zadejte alespoň jeden kontakt (telefon nebo e-mail)';
    errorBag.value.email = 'Zadejte alespoň jeden kontakt (telefon nebo e-mail)';
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

              <div class="flex flex-col">

                <Input v-model="props.contact.name" size="sm" label="Jméno:" placeholder="Zadejte jméno (min. 3 znaky)" :error="errorBag?.name"/>
                <Input v-model="props.contact.surname" size="sm" label="Příjmení:" placeholder="Zadejte příjmení (min. 3 znaky)" :error="errorBag?.surname"/>

                <Input v-model="props.contact.email" type="email" size="sm" label="E-mail:" placeholder="Zadejte e-mail" :error="errorBag?.email"/>
                <Checkbox v-model="props.contact.has_info_email_allowed" label="Použít e-mail pro zasílání informačních zpráv"/>

                <Input v-model="props.contact.phone" size="sm" label="Telefon:" placeholder="Zadejte telefon" :error="errorBag?.phone"/>
                <Checkbox v-model="props.contact.has_info_sms_allowed" label="Použít telefon pro zasílání informačních zpráv"/>

                <CustomFormControl label="Skupiny">
                  <VueMultiselect v-model="props.contact.contact_groups" :options="contactGroups" label="name" trackBy="id" :multiple="true"
                                  :close-on-select="false"
                                  placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                                  selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
                    <template #noResult>
                      <span>Zadaným parametrům neodpovídá žádná skupina</span>
                    </template>
                  </VueMultiselect>
                </CustomFormControl>

                <div class="flex items-center justify-end space-x-2">
                  <Button data-class="btn-ghost" label="Zrušit" size="sm" @click="closeModalWith('cancel')"/>
                  <Button icon="mdi-content-save" label="Uložit" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
                </div>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>