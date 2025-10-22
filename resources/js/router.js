import {createRouter, createWebHistory} from "vue-router";
import AuthLayout from "./components/layouts/AuthLayout.vue";
import DefaultLayout from "./components/layouts/DefaultLayout.vue";
import Page404 from "./views/PageNotFound.vue";
import About from "./views/about/About.vue";
import Login from "./views/auth/Login.vue";
import LiveBroadcast from "./views/live-broadcast/LiveBroadcast.vue";
import Recordings from "./views/records/Recordings.vue";
import Scheduler from "./views/schedule/Scheduler.vue";
import ScheduleTask from "./views/schedule/ScheduleTask.vue";
import Map from "./views/map/Map.vue";
import Log from "./views/log/Log.vue";
import Users from "./views/users/Users.vue";
import GeneralSettings from "./views/settings/GeneralSettings.vue";
import Contacts from "./views/settings/Contacts.vue";
import SmtpSettings from "./views/settings/SmtpSettings.vue";
import FMSettings from "./views/settings/FMSettings.vue";
import TwoWayCommSettings from "./views/settings/TwoWayCommSettings.vue";
import Messages from "./views/messages/Messages.vue";
import CreateMessage from "./views/messages/CreateMessage.vue";
import GSMSettings from "./views/settings/GSMSettings.vue";
import LocationGroupsSettings from "./views/settings/LocationGroupsSettings.vue";
import LocationGroupEdit from "./views/settings/LocationGroupEdit.vue";
import Jsvv from "./views/jsvv/Jsvv.vue";
import JsvvEdit from "./views/jsvv/JsvvEdit.vue";
import JSVVSettings from "./views/settings/JSVVSettings.vue";
import VolumeSettings from "./views/settings/VolumeSettings.vue";

const routes = [
    {
        path: "/",
        redirect: "/live-broadcast",
        component: DefaultLayout,
        children: [
            {path: "/live-broadcast", name: "LiveBroadcast", component: LiveBroadcast, meta: {title: "Živé vysílání"}},
            {path: "/recordings", name: "Recordings", component: Recordings, meta: {title: "Záznamy"}},
            {path: "/schedule", name: "Scheduler", component: Scheduler, meta: {title: "Plán vysílání"}},
            {path: '/schedule/task', name: "CreateSchedule", component: ScheduleTask, meta: {title: "Nový úkol"}},
            {path: '/schedule/task/:id', name: "EditSchedule", component: ScheduleTask, meta: {title: "Úprava úkolu"}},
            {path: "/jsvv-list", name: "JSVV", component: Jsvv, meta: {title: "Jednotný systém varování a vyrozumění"}},
            {path: "/jsvv-list/alarm", name: "CreateJSVV", component: JsvvEdit, meta: {title: "JSVV - Nový alarm"}},
            {path: "/jsvv-list/alarm/:id", name: "EditJSVV", component: JsvvEdit, meta: {title: "JSVV - Úprava alarmu"}},
            {path: "/messages", name: "Messages", component: Messages, meta: {title: "Zprávy"}},
            {path: "/messages/create", name: "CreateMessage", component: CreateMessage, meta: {title: "Nová zpráva"}},
            {path: "/map", name: "Map", component: Map, meta: {title: "Mapa"}},
            {path: "/log", name: "Log", component: Log, meta: {title: "Protokoly"}},
            {path: "/users", name: "Users", component: Users, meta: {title: "Uživatelé"}},
            {path: "/settings/general", name: "GeneralSettings", component: GeneralSettings, meta: {title: "Obecné nastavení"}},
            {path: "/settings/contacts", name: "Contacts", component: Contacts, meta: {title: "Kontakty"}},
            {path: "/settings/smtp", name: "SmtpSettings", component: SmtpSettings, meta: {title: "Nastavení SMTP"}},
            {path: "/settings/gsm", name: "GSMSettings", component: GSMSettings, meta: {title: "Nastavení GSM"}},
            {path: "/settings/fm", name: "FMSettings", component: FMSettings, meta: {title: "Nastavení FM rádia"}},
            {path: "/settings/jsvv", name: "JSVVSettings", component: JSVVSettings, meta: {title: "Nastavení JSVV"}},
            {path: "/settings/volume", name: "VolumeSettings", component: VolumeSettings, meta: {title: "Nastavení hlasitosti"}},
            {path: "/settings/two-way-comm", name: "TwoWayCommSettings", component: TwoWayCommSettings, meta: {title: "Nastavení obousměrné komunikace"}},
            {path: "/settings/location-groups", name: "LocationGroupsSettings", component: LocationGroupsSettings, meta: {title: "Nastavení lokalit"}},
            {path: "/settings/location-group", name: "CreateLocationGroup", component: LocationGroupEdit, meta: {title: "Nová lokalita"}},
            {path: "/settings/location-group/:id", name: "EditLocationGroup", component: LocationGroupEdit, meta: {title: "Úprava lokality"}},
            {path: '/about', name: "About", component: About},
        ],
    },
    {
        path: "/auth",
        redirect: "/login",
        name: "Auth",
        component: AuthLayout,
        children: [
            {path: "/login", name: "Login", component: Login},
        ],
    },
    {
        path: "/:pathMatch(.*)*",
        name: "NotFound",
        component: Page404,
    },
];


const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Update the document title on each route change
router.beforeEach((to, from, next) => {
    if (to.name !== 'Login' && to.name !== 'Register' && !localStorage.getItem('token')) {
        next({name: 'Login'});
    }
    document.title = to.meta.title || 'Sarrah V';
    next();
});

export default router;
