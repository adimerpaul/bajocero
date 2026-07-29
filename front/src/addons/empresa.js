export function companyData () {
  try {
    return JSON.parse(localStorage.getItem('empresaBajoCero') || '{}')
  } catch {
    return {}
  }
}
