/**
 * Convierte una imagen del servidor en base64 para poder mostrarla sin internet.
 * Se pide por /api/imagen/{archivo} y no por /images/{archivo}: los archivos
 * estáticos los sirve el servidor web sin CORS y el navegador no deja leerlos.
 */
async function toDataUri (axiosInstance, name) {
  const { data } = await axiosInstance.get(`/imagen/${encodeURIComponent(name)}`, { responseType: 'blob' })

  return await new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(data)
  })
}

/**
 * Guarda una imagen en localStorage (base64) y la reutiliza mientras no cambie el
 * nombre del archivo. Si no hay conexión se conserva la copia anterior.
 */
export async function cacheImage (axiosInstance, { name, dataKey, nameKey }) {
  if (!name) {
    localStorage.removeItem(dataKey)
    localStorage.removeItem(nameKey)

    return null
  }
  if (localStorage.getItem(nameKey) === name && localStorage.getItem(dataKey)) {
    return localStorage.getItem(dataKey)
  }
  try {
    const dataUri = await toDataUri(axiosInstance, name)
    localStorage.setItem(dataKey, dataUri)
    localStorage.setItem(nameKey, name)

    return dataUri
  } catch {
    // Sin conexión o almacenamiento lleno: se sigue usando lo que ya estaba guardado.
    return localStorage.getItem(dataKey)
  }
}
