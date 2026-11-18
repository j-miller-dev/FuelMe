import { Head, useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Guest } from '@/layouts/AppLayout'

export default function ConfirmPassword() {
  const form = useForm({ password: '' })
  return (
    <Guest title="Confirm your password">
      <Head title="Confirm password" />
      <p className="mb-6 text-center text-sm text-muted">This area changes how you sign in, so please confirm your password first.</p>
      <form onSubmit={(e) => { e.preventDefault(); form.post('/user/confirm-password', { onSuccess: () => form.reset() }) }} className="space-y-4">
        <Field label="Password" type="password" autoComplete="current-password" autoFocus value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} required />
        <Button className="w-full" disabled={form.processing}>Confirm</Button>
      </form>
    </Guest>
  )
}
