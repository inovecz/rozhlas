<script setup>
import {onMounted, ref} from "vue";
import {useDataTables} from "../../utils/datatablesTrait.js";
import ContactService from "../../services/ContactService.js";
import {contactGroupStore} from "../../store/contactGroupStore.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import CreateEditContactGroup from "../../components/modals/CreateEditContactGroup.vue";
import {useToast} from "vue-toastification";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";

const contactGroupStoreInfo = contactGroupStore();
const contactGroups = ref(contactGroupStoreInfo.contactGroups);
const toast = useToast();

onMounted(() => {
  fetchRecords();
});

emitter.on('refetchContactGroups', () => {
  fetchRecords();
});

const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchContactGroups, 'name', 5);

function fetchContactGroups(paginatorUrl = null) {
  ContactService.fetchContactGroups(paginatorUrl, search, pageLength, orderColumn.value, orderAsc.value).then(response => {
    contactGroups.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function filterContactsByGroup(groupId) {
  emitter.emit('filterContactListByGroupId', groupId);
}

function editContactGroup(id = null) {
  const foundContactGroup = contactGroups.value.data.find(contactGroup => contactGroup.id === id);
  const {reveal, onConfirm} = createConfirmDialog(CreateEditContactGroup, {
    contactGroup: foundContactGroup ? {...foundContactGroup} : {id: null, name: ''}
  });
  reveal();
  onConfirm(contactGroup => {
    ContactService.saveContactGroup(contactGroup).then(() => {
      toast.success('Skupina kontaktů byla úspěšně uložena');
      fetchRecords();
      emitter.emit('refetchContactGroupsSelect');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se uložit skupinu kontaktů');
    });
  });
}

function deleteContactGroup(id) {
  const foundContactGroup = contactGroups.value.data.find(contact => contact.id === id);
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu chcete smazat skupinu?',
    message: `Skupina ${foundContactGroup.name} bude trvale smazána. Spolu s tím dojde k odebrání této skupiny u všech kontaktů. Tato akce je nevratná.`
  });
  reveal();
  onConfirm(() => {
    ContactService.deleteContactGroup(id).then(() => {
      toast.success('Skupina byla smazána');
      fetchRecords();
      emitter.emit('refetchContactGroupsSelect');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat skupinu');
    });
  });
}

</script>

<template>
  <Box label="Skupiny kontaktů">
    <template #header>
      <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
      <button @click="editContactGroup" class="btn btn-primary btn-square btn-sm text-xl"><span class="mdi mdi-plus-box"></span></button>
    </template>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('name')">
              <div class="flex items-center cursor-pointer underline">
                Název skupiny
                <span v-if="orderColumn.value === 'name'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th>
              <div class="flex items-center">
                Počet kontaktů
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="contactGroup in contactGroups.data" :key="contactGroup.id" class="hover">
            <td>
              {{ contactGroup.name }}
            </td>
            <td>
              <span>{{ contactGroup.contacts_count }}</span>
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="filterContactsByGroup(contactGroup.id)"><span class="mdi mdi-filter text-primary text-xl"></span></button>
              <button @click="editContactGroup(contactGroup.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button @click="deleteContactGroup(contactGroup.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="contactGroups?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="contactGroups?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in contactGroups?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </Box>
</template>

<style scoped>
</style>