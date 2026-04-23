import { useState } from 'react';
import Navbar from '../components/Navbar';
import NationalityInput from '../components/NationalityInput';
import { GuestNationality } from '@samchlolaurepartners/shared';
import api from '../api';

export default function ContactPage() {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [checkin, setCheckin] = useState('');
  const [checkout, setCheckout] = useState('');
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);
  const [guests, setGuests] = useState<GuestNationality[]>([]);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      await api.post('/api/contact', {
        name, email, phone: phone || undefined,
        checkin_date: checkin || undefined, checkout_date: checkout || undefined,
        adults, children, guests, message,
      });
      setSuccess(true);
    } catch {
      setError('Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <div className="max-w-2xl mx-auto px-4 py-10">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Nous contacter</h1>
        <p className="text-gray-500 mb-8">
          Une question ? Un projet de séjour ? Écrivez-nous, nous vous répondrons dans les plus brefs délais.
        </p>

        {success ? (
          <div className="text-center py-12">
            <div className="text-5xl mb-4">✅</div>
            <h2 className="text-xl font-semibold text-gray-900">Message envoyé !</h2>
            <p className="text-gray-500 mt-2">Nous vous contacterons très prochainement.</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="card p-6 space-y-5">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Nom *</label>
                <input type="text" className="input" value={name}
                  onChange={(e) => setName(e.target.value)} required />
              </div>
              <div>
                <label className="label">Email *</label>
                <input type="email" className="input" value={email}
                  onChange={(e) => setEmail(e.target.value)} required />
              </div>
            </div>

            <div>
              <label className="label">Téléphone</label>
              <input type="tel" className="input" value={phone}
                onChange={(e) => setPhone(e.target.value)} />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Arrivée souhaitée</label>
                <input type="date" className="input" value={checkin}
                  onChange={(e) => setCheckin(e.target.value)} />
              </div>
              <div>
                <label className="label">Départ souhaité</label>
                <input type="date" className="input" value={checkout} min={checkin}
                  onChange={(e) => setCheckout(e.target.value)} />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Adultes</label>
                <input type="number" className="input" min={1} max={20} value={adults}
                  onChange={(e) => setAdults(parseInt(e.target.value, 10))} />
              </div>
              <div>
                <label className="label">Enfants (&lt;12)</label>
                <input type="number" className="input" min={0} max={20} value={children}
                  onChange={(e) => setChildren(parseInt(e.target.value, 10))} />
              </div>
            </div>

            <div>
              <label className="label">Nationalités</label>
              <NationalityInput adults={adults} children={children} guests={guests} onChange={setGuests} />
            </div>

            <div>
              <label className="label">Message *</label>
              <textarea className="input" rows={4} value={message}
                onChange={(e) => setMessage(e.target.value)} required />
            </div>

            {error && <p className="text-red-600 text-sm">{error}</p>}

            <button type="submit" className="btn-primary w-full" disabled={loading}>
              {loading ? 'Envoi...' : 'Envoyer le message'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
