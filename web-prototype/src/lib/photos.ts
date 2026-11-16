import { db, type Photo } from './outbox'

/** Progress photos live ONLY on this device in the prototype (IndexedDB). The real app stores them privately on your own server. */
export type Pose = Photo['pose']
export const POSES: [Pose, string][] = [['front', 'Front'], ['side', 'Side'], ['back', 'Back']]

export const addPhoto = (blob: Blob, pose: Pose, date = new Date().toISOString().slice(0, 10)) => db.photos.add({ blob, pose, date, createdAt: Date.now() })
export const deletePhoto = (id: number) => db.photos.delete(id)
export const listPhotos = async (pose?: Pose) => {
  const all = await db.photos.orderBy('date').reverse().toArray()
  return pose ? all.filter(p => p.pose === pose) : all
}
