import { cacheImage } from './imagen'

const TOKEN = 'tokenBajoCero'
const USER = 'user'
const PERMS = 'permissionsBajoCero'
const AVATAR = 'avatarBajoCero'
const AVATAR_NAME = 'avatarNombreBajoCero'
const SYNC = 'meSyncBajoCero'
const HORAS_REFRESCO = 12

export function sessionToken () {
  return localStorage.getItem(TOKEN)
}

export function sessionUser () {
  try {
    return JSON.parse(localStorage.getItem(USER) || '{}')
  } catch {
    return {}
  }
}

export function sessionPermissions () {
  try {
    return JSON.parse(localStorage.getItem(PERMS) || '[]')
  } catch {
    return []
  }
}

/** Foto del usuario en base64; sirve cuando no hay conexión con el servidor. */
export function sessionAvatar () {
  return localStorage.getItem(AVATAR)
}

/**
 * Deja en localStorage todo lo necesario para entrar sin internet: token, usuario
 * (con su nombre) y permisos, que son los que arman el menú.
 */
export function saveSession (user, token = null) {
  const perms = (user?.permissions || []).map(p => p.name)
  if (token) localStorage.setItem(TOKEN, token)
  localStorage.setItem(USER, JSON.stringify(user || {}))
  localStorage.setItem(PERMS, JSON.stringify(perms))
  localStorage.setItem(SYNC, String(Date.now()))

  return perms
}

export function clearSession () {
  [TOKEN, USER, PERMS, AVATAR, AVATAR_NAME, SYNC].forEach(key => localStorage.removeItem(key))
}

/**
 * /me sólo se consulta cuando falta la sesión guardada o cuando ya pasaron 12 horas
 * desde la última vez; así la app abre al instante y funciona sin conexión.
 */
export function shouldRefreshMe () {
  if (!sessionUser().id) return true
  const last = Number(localStorage.getItem(SYNC) || 0)

  return !last || Date.now() - last > HORAS_REFRESCO * 60 * 60 * 1000
}

export function cacheAvatar (axiosInstance, user) {
  return cacheImage(axiosInstance, { name: user?.avatar, dataKey: AVATAR, nameKey: AVATAR_NAME })
}

/** Foto a mostrar: la copia local y, mientras no exista, la del servidor. */
export function avatarSrc (user, imgBase) {
  return sessionAvatar() || (user?.avatar ? `${imgBase}/images/${user.avatar}` : null)
}
