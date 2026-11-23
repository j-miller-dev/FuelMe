import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'

// Attribution is a licence condition for both food databases, so it lives here permanently (BLUEPRINT 5.1).
export default function About({ counts }: { counts: { afcd: number; off: number; user: number } }) {
  return (
    <AppLayout>
      <Head title="About · FUEL" />
      <h1 className="mb-1 text-2xl font-bold">About</h1>
      <p className="mb-6 text-sm text-muted">Where the food data comes from. FUEL gives estimates, not medical advice.</p>

      <section className="mb-6 rounded-xl bg-surface p-4" aria-labelledby="afcd">
        <h2 id="afcd" className="font-semibold">Australian Food Composition Database</h2>
        <p className="mt-1 text-sm text-muted">{counts.afcd.toLocaleString('en-AU')} generic and whole foods.</p>
        <p className="mt-2 text-sm">© Food Standards Australia New Zealand. Released under CC BY 4.0 Australia. FSANZ does not endorse this app or its use of the data.</p>
        <p className="mt-2 text-xs text-muted">Energy is the AFCD “with dietary fibre, equated” value converted from kJ at 4.184 kJ per kcal. Carbohydrate is available carbohydrate including sugar alcohols.</p>
      </section>

      <section className="mb-6 rounded-xl bg-surface p-4" aria-labelledby="off">
        <h2 id="off" className="font-semibold">Open Food Facts</h2>
        <p className="mt-1 text-sm text-muted">{counts.off.toLocaleString('en-AU')} packaged products sold in Australia.</p>
        <p className="mt-2 text-sm">Contains information from Open Food Facts, made available under the Open Database Licence (ODbL). Individual contents are under the Database Contents Licence. Product data is contributed by volunteers, so rows marked “check” may be wrong; compare with the pack.</p>
      </section>

      <section className="mb-8 rounded-xl bg-surface p-4" aria-labelledby="own">
        <h2 id="own" className="font-semibold">Your own foods</h2>
        <p className="mt-1 text-sm text-muted">{counts.user.toLocaleString('en-AU')} foods and meals you created. They rank above everything else in search.</p>
      </section>

      <Link href="/more" className="text-sm text-accent">Back to More</Link>
    </AppLayout>
  )
}
