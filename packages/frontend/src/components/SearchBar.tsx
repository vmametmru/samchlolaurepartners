import { useState } from 'react';

interface Props {
  onSearch: (params: SearchParams) => void;
}

export interface SearchParams {
  checkin: string;
  checkout: string;
  adults: number;
  children: number;
  nationality: string;
}

export default function SearchBar({ onSearch }: Props) {
  const today = new Date().toISOString().split('T')[0];
  const [checkin, setCheckin] = useState('');
  const [checkout, setCheckout] = useState('');
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);
  const [nationality, setNationality] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSearch({ checkin, checkout, adults, children, nationality });
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="bg-white rounded-2xl shadow-lg p-4 flex flex-col md:flex-row gap-3 items-end"
    >
      {/* Checkin */}
      <div className="flex-1 min-w-0">
        <label className="label">Arrivée</label>
        <input
          type="date"
          className="input"
          min={today}
          value={checkin}
          onChange={(e) => setCheckin(e.target.value)}
          required
        />
      </div>

      {/* Checkout */}
      <div className="flex-1 min-w-0">
        <label className="label">Départ</label>
        <input
          type="date"
          className="input"
          min={checkin || today}
          value={checkout}
          onChange={(e) => setCheckout(e.target.value)}
          required
        />
      </div>

      {/* Adults */}
      <div className="w-24">
        <label className="label">Adultes</label>
        <input
          type="number"
          className="input"
          min={1}
          max={20}
          value={adults}
          onChange={(e) => setAdults(parseInt(e.target.value, 10))}
        />
      </div>

      {/* Children */}
      <div className="w-28">
        <label className="label">Enfants (&lt;12)</label>
        <input
          type="number"
          className="input"
          min={0}
          max={20}
          value={children}
          onChange={(e) => setChildren(parseInt(e.target.value, 10))}
        />
      </div>

      {/* Nationality */}
      <div className="flex-1 min-w-0">
        <label className="label">Nationalité</label>
        <input
          type="text"
          className="input"
          placeholder="Française, Mauricienne..."
          value={nationality}
          onChange={(e) => setNationality(e.target.value)}
        />
      </div>

      <button type="submit" className="btn-primary whitespace-nowrap">
        Rechercher
      </button>
    </form>
  );
}
