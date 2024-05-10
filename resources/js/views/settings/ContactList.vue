<script setup>
import {onMounted, ref, watch} from "vue";
import {useDataTables} from "../../utils/datatablesTrait.js";
import {useToast} from "vue-toastification";
import ContactService from "../../services/ContactService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import CreateEditContact from "../../components/modals/CreateEditContact.vue";
import {contactGroupStore} from "../../store/contactGroupStore.js";
import ModalDialog from "../../components/modals/ModalDialog.vue";

const contactGroupStoreInfo = contactGroupStore();
const contactGroups = ref([]);
const contacts = ref([]);
const groupFilter = ref('');
const toast = useToast();

onMounted(() => {
  fetchRecords();
});

watch(contactGroupStoreInfo, () => {
  contactGroups.value = contactGroupStoreInfo.contactGroups;
});

emitter.on('filterContactListByGroupId', (contactGroupId) => {
  groupFilter.value = contactGroupId;
  fetchRecords();
});

const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchContacts, 'surname', 5);

function fetchContacts(paginatorUrl = null) {
  const filter = {
    contact_group: groupFilter.value,
  };
  ContactService.fetchContacts(paginatorUrl, search, pageLength, orderColumn.value, orderAsc.value, filter).then(response => {
    contacts.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function editContact(id = null) {
  const foundContact = contacts.value.data.find(contact => contact.id === id);
  const {reveal, onConfirm} = createConfirmDialog(CreateEditContact, {
    contact: foundContact ? {...foundContact} : {id: null, name: '', surname: '', position: '', email: '', phone: '', has_info_email_allowed: false, has_info_sms_allowed: false, contact_groups: []}
  });
  reveal();
  onConfirm(contact => {
    contact.contact_groups = contact.contact_groups.map(group => group.id);
    ContactService.saveContact(contact).then(() => {
      toast.success('Kontakt byl úspěšně uložen');
      fetchRecords();
      emitter.emit('refetchContactGroups');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se uložit uživatele');
    });
  });
}

function deleteContact(id) {
  const foundContact = contacts.value.data.find(contact => contact.id === id);
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu chcete smazat kontakt?',
    message: `Kontakt ${foundContact.name} ${foundContact.surname} bude trvale smazán. Tato akce je nevratná.`
  });
  reveal();
  onConfirm(() => {
    ContactService.deleteContact(id).then(() => {
      toast.success('Kontakt byl smazán');
      fetchRecords();
      emitter.emit('refetchContactGroups');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat kontakt');
    });
  });
}
</script>

<template>
  <div class="component-box">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        Seznam kontaktů
      </div>
      <div class="flex gap-4">
        <select v-if="contactGroups" v-model="groupFilter" @change="fetchContacts()" class="select select-bordered select-sm">
          <option value="">Všechny skupiny</option>
          <option v-for="contactGroup in contactGroups" :value="contactGroup.id">
            {{ contactGroup.name }}
          </option>
        </select>
        <label class="input input-bordered input-sm flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
            <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
          </svg>
          <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
          <svg @click="() => {search.value = null}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 opacity-70 cursor-pointer">
            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"></path>
          </svg>
        </label>
        <button @click="editContact" class="btn btn-primary btn-square btn-sm text-xl"><span class="mdi mdi-plus-box"></span></button>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('surname')">
              <div class="flex items-center cursor-pointer underline">
                Příjmení
                <span v-if="orderColumn.value === 'surname'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('name')">
              <div class="flex items-center cursor-pointer underline">
                Jméno
                <span v-if="orderColumn.value === 'name'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('email')">
              <div class="flex items-center cursor-pointer underline">
                E-mail
                <span v-if="orderColumn.value === 'email'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('phone')">
              <div class="flex items-center cursor-pointer underline">
                Telefon
                <span v-if="orderColumn.value === 'phone'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th>
              <div class="flex items-center">
                Skupiny
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="contact in contacts.data" :key="contact.id" class="hover">
            <td>
              {{ contact.surname }}
            </td>
            <td>
              {{ contact.name }}
            </td>
            <td>
              <span :class="{'text-success': contact.has_info_email_allowed}">{{ contact.email }}</span>
            </td>
            <td>
              <span :class="{'text-success': contact.has_info_sms_allowed}">{{ contact.phone }}</span>
            </td>
            <td>
              <div class="flex flex-wrap max-w-32 gap-1">
                <template v-for="contactGroup in contact.contact_groups">
                  <span v-if="contactGroups.find(group => group.id === contactGroup.id)" class="bg-primary text-primary-content rounded-full text-xs px-1.5">
                    {{ contactGroups.find(group => group.id === contactGroup.id)?.name }}
                  </span>
                </template>
              </div>
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="editContact(contact.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button @click="deleteContact(contact.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="contacts?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="contacts?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in contacts?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>

</style>