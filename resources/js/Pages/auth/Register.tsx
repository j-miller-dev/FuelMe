import { Head, useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Field } from '@/components/ui/field'
import { Guest } from '@/layouts/AppLayout'

export default function Register() {
  const form = useForm({ name: '', email: '', password: '', password_confirmation: '' })
  const set = (k: keyof typeof form.data) => (e: React.ChangeEvent<HTMLInputElement>) => form.setData(k, e.target.value)
  return (
    <Guest title="Create your account">
      <Head title="Create account" />
      <p className="mb-6 text-center text-sm text-muted">This is a single-user app. Registration closes as soon as your account exists. You can add a passkey afterwards in Security.</p>
      <form onSubmit={(e) => { e.preventDefault(); form.post('/register') }} className="space-y-4">
        <Field label="Name" autoComplete="name" value={form.data.name} onChange={set('name')} error={form.errors.name} required />
        <Field label="Email" type="email" autoComplete="username" value={form.data.email} onChange={set('email')} error={form.errors.email} required />
        <Field label="Password" type="password" autoComplete="new-password" value={form.data.password} onChange={set('password')} error={form.errors.password} required />
        <Field label="Confirm password" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={set('password_confirmation')} required />
        <Button className="w-full" disabled={form.processing}>Create account</Button>
      </form>
    </Guest>
  )
}
