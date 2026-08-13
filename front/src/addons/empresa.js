import { cacheImage } from './imagen'

const KEY = 'empresaBajoCero'
const LOGO_KEY = 'logoBajoCero'
const LOGO_NAME_KEY = 'logoNombreBajoCero'
const FALLBACK = '/bajo-cero-logo.svg'

export function companyData () {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '{}')
  } catch {
    return {}
  }
}

/**
 * Guarda la configuración de la empresa (nombre, NIT, dirección, teléfono y el
 * nombre del archivo del logo) para poder usarla sin conexión. La imagen en sí la
 * guarda cacheCompanyLogo, que es quien controla LOGO_NAME_KEY.
 */
export function saveCompany (data, imgBase) {
  const company = { ...data, logo_url: data.logo ? `${imgBase}/images/${data.logo}` : null }
  try {
    localStorage.setItem(KEY, JSON.stringify(company))
  } catch { /* almacenamiento lleno: se sigue usando lo que ya estaba */ }

  return company
}

/**
 * Logo listo para usar en pantalla y en los tickets: primero la copia en base64
 * guardada en localStorage (funciona sin internet), después la URL del servidor y
 * al final el logo que viene con la app.
 */
export function companyLogo () {
  return localStorage.getItem(LOGO_KEY) || companyData().logo_url || FALLBACK
}

/**
 * Descarga el logo una sola vez por nombre de archivo y lo deja guardado en base64.
 * Si no hay conexión se conserva la copia anterior.
 */
export function cacheCompanyLogo (axiosInstance, company = companyData()) {
  return cacheImage(axiosInstance, { name: company.logo, dataKey: LOGO_KEY, nameKey: LOGO_NAME_KEY })
}
