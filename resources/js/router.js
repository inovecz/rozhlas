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
            {path: "/map", name: "Map", component: Map, meta: {title: "Mapa"}},
            {path: "/log", name: "Log", component: Log, meta: {title: "Protokoly"}},
            {path: "/users", name: "Users", component: Users, meta: {title: "Uživatelé"}},
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
    document.title = to.meta.title || 'Sarrah IV';
    next();
});

export default router;