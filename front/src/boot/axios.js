import { defineBoot } from '#q-app/wrappers'
import axios from 'axios'
import { Alert } from '../addons/Alert'
import { cacheCompanyLogo, companyData, saveCompany } from '../addons/empresa'
import { cacheAvatar, clearSession, saveSession, sessionPermissions, sessionToken, sessionUser, shouldRefreshMe } from '../addons/sesion'
import { useCounterStore } from '../stores/example-store'

// Be careful when using SSR for cross-request state pollution
// due to creating a Singleton instance here;
// If any client changes this (global) instance, it might be a
// good idea to move this instance creation inside of the
// "export default () => {}" function below (which runs individually
// for each client)
const api = axios.create({ baseURL: 'https://api.example.com' })

export default defineBoot(({ app, router }) => {
  const store = useCounterStore()

  app.config.globalProperties.$axios = axios.create({ baseURL: import.meta.env.VITE_API_BACK })
  app.config.globalProperties.$alert = Alert
  app.config.globalProperties.$store = store
  app.config.globalProperties.$url = import.meta.env.VITE_API_BACK
  app.config.globalProperties.$imgBase = (import.meta.env.VITE_API_BACK || '').replace(/\/api\/?$/, '')
  app.config.globalProperties.$version = import.meta.env.VITE_VERSION
  // Datos de la empresa: primero lo guardado (así el encabezado y los tickets
  // funcionan sin conexión) y luego se refresca si el servidor responde.
  app.config.globalProperties.$empresa = companyData()
  app.config.globalProperties.$axios.get('/configuracion').then(({ data }) => {
    app.config.globalProperties.$empresa = saveCompany(data, app.config.globalProperties.$imgBase)
    cacheCompanyLogo(app.config.globalProperties.$axios, app.config.globalProperties.$empresa)
  }).catch(() => { /* sin conexión: se usa la configuración guardada */ })

  function cerrarSesion () {
    clearSession()
    delete app.config.globalProperties.$axios.defaults.headers.common['Authorization']
    store.isLogged = false
    store.permissions = []
    store.user = {}
    if (router.currentRoute.value.path !== '/login') router.push('/login')
  }

  // Sólo el servidor cierra la sesión: un 401 con respuesta significa token inválido.
  // Un error de red (sin internet) no toca nada, para poder seguir navegando.
  app.config.globalProperties.$axios.interceptors.response.use(
    response => {
      store.offline = false

      return response
    },
    error => {
      // Sin respuesta = no se pudo llegar al servidor (sin internet o caído).
      store.offline = !error.response
      if (error.response?.status === 401) cerrarSesion()

      return Promise.reject(error)
    }
  )

  const token = sessionToken()
  if (token) {
    app.config.globalProperties.$axios.defaults.headers.common['Authorization'] = `Bearer ${token}`

    // La sesión sale de localStorage: nombre del usuario y permisos (que arman el
    // menú) quedan listos sin pedir nada al servidor, así se entra sin internet.
    const cachedUser = sessionUser()
    const cachedPerms = sessionPermissions()
    if (cachedUser.id || cachedPerms.length) {
      store.user = cachedUser
      store.permissions = cachedPerms
      store.isLogged = true
    }

    // /me se consulta sólo si no hay sesión guardada o si ya está vieja (12 h).
    if (shouldRefreshMe()) {
      app.config.globalProperties.$axios.get('me').then(({ data }) => {
        store.isLogged = true
        store.user = data
        store.permissions = saveSession(data)
        cacheAvatar(app.config.globalProperties.$axios, data)
      }).catch(() => { /* sin conexión: se sigue con la sesión guardada */ })
    }
  }

  app.config.globalProperties.$api = api
  // ^ ^ ^ this will allow you to use this.$api (for Vue Options API form)
  //       so you can easily perform requests against your app's API
})

export { api }
