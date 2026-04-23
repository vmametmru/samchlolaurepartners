import { useState } from 'react';
import api from '../api';
import NationalityInput from './NationalityInput';
import { GuestNationality } from '@samchlolaurepartners/shared';

interface Props {
  propertyId?: string;
  propertyName?: string;
  defaultCheckin?: string;
  defaultCheckout?: string;
  onSuccess?: () => void;
}

export default function BookingRequestForm({
  propertyId,
  propertyName,
  defaultCheckin = '',
  defaultCheckout = '',
  onSuccess,
}: Props) {
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  // Step 1 fields
  const [checkin, setCheckin] = useState(defaultCheckin);
  const [checkout, setCheckout] = useState(defaultCheckout);
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);

  // Step 2 fields
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [guests, setGuests] = useState<GuestNationality[]>([]);

  const handleStep1 = (e: React.FormEvent) => {
    e.preventDefault();
    // Initialize guests array
    const initialGuests: GuestNationality[] = [
      ...Array(adults).fill(null).map(() => ({ type: 'adult' as const, nationality: '' })),
      ...Array(children).fill(null).map(() => ({ type: 'child' as const, nationality: '' })),
    ];
    setGuests(initialGuests);
    setStep(2);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      await api.post('/api/reservations/request', {
        property_id: propertyId,
        property_name: propertyName,
        client_name: name,
        client_email: email,
        client_phone: phone || undefined,
        checkin_date: checkin,
        checkout_date: checkout,
        adults,
        children,
        guests,
        message: message || undefined,
      });

      setSuccess(true);
      onSuccess?.();
    } catch {
      setError('Une erreur est survenue. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="text-center py-8">
        <div className="text-4xl mb-3">✅</div>
        <h3 className="text-lg font-semibold text-gray-900">Demande envoyée !</h3>
        <p className="text-sm text-gray-500 mt-2">
          Vous recevrez un email de confirmation. Notre équipe vous contactera rapidement.
        </p>
      </div>
    );
  }

  return (
    <div>
      {step === 1 && (
        <form onSubmit={handleStep1} className="space-y-4">
          <h3 className="font-semibold text-lg text-gray-900">Votre séjour</h3>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Date d'arrivée</label>
              <input type="date" className="input" value={checkin}
                onChange={(e) => setCheckin(e.target.value)} required />
            </div>
            <div>
              <label className="label">Date de départ</label>
              <input type="date" className="input" value={checkout} min={checkin}
                onChange={(e) => setCheckout(e.target.value)} required />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Adultes</label>
              <input type="number" className="input" min={1} max={20} value={adults}
                onChange={(e) => setAdults(parseInt(e.target.value, 10))} />
            </div>
            <div>
              <label className="label">Enfants (&lt;12 ans)</label>
              <input type="number" className="input" min={0} max={20} value={children}
                onChange={(e) => setChildren(parseInt(e.target.value, 10))} />
            </div>
          </div>

          <button type="submit" className="btn-primary w-full">
            Continuer
          </button>
        </form>
      )}

      {step === 2 && (
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="flex items-center gap-2">
            <button type="button" onClick={() => setStep(1)} className="text-brand-500 text-sm">
              ← Retour
            </button>
            <h3 className="font-semibold text-lg text-gray-900">Vos coordonnées</h3>
          </div>

          <div>
            <label className="label">Nom complet *</label>
            <input type="text" className="input" value={name}
              onChange={(e) => setName(e.target.value)} required />
          </div>

          <div>
            <label className="label">Email *</label>
            <input type="email" className="input" value={email}
              onChange={(e) => setEmail(e.target.value)} required />
          </div>

          <div>
            <label className="label">Téléphone</label>
            <input type="tel" className="input" value={phone}
              onChange={(e) => setPhone(e.target.value)} />
          </div>

          <div>
            <label className="label">Nationalités des voyageurs</label>
            <NationalityInput
              adults={adults}
              children={children}
              guests={guests}
              onChange={setGuests}
            />
          </div>

          <div>
            <label className="label">Message (optionnel)</label>
            <textarea className="input" rows={3} value={message}
              onChange={(e) => setMessage(e.target.value)} />
          </div>

          {error && <p className="text-red-600 text-sm">{error}</p>}

          <button type="submit" className="btn-primary w-full" disabled={loading}>
            {loading ? 'Envoi en cours...' : 'Envoyer ma demande'}
          </button>
        </form>
      )}
    </div>
  );
}
