import { LodgifyProperty } from '@samchlolaurepartners/shared';
import { Link } from 'react-router-dom';

interface Props {
  property: LodgifyProperty;
  pricePerNight?: number;
  currency?: string;
}

export default function PropertyCard({ property, pricePerNight, currency = 'EUR' }: Props) {
  const mainImage = property.images[0]?.url ?? 'https://via.placeholder.com/400x240?text=No+Photo';

  return (
    <Link to={`/properties/${property.id}`} className="card group block hover:shadow-md transition-shadow">
      {/* Image */}
      <div className="relative overflow-hidden aspect-[4/3]">
        <img
          src={mainImage}
          alt={property.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
        />
      </div>

      {/* Content */}
      <div className="p-4">
        <h3 className="font-semibold text-gray-900 truncate">{property.name}</h3>
        <p className="text-sm text-gray-500 mt-1 line-clamp-2">{property.description}</p>

        <div className="mt-3 flex items-center justify-between">
          <div className="flex items-center gap-2 text-xs text-gray-400">
            <span>{property.bedrooms} ch.</span>
            <span>·</span>
            <span>{property.max_guests} pers. max</span>
          </div>

          {pricePerNight !== undefined && (
            <div className="text-right">
              <span className="text-sm font-bold text-gray-900">
                {pricePerNight.toLocaleString('fr-FR', { style: 'currency', currency })}
              </span>
              <span className="text-xs text-gray-400">/nuit</span>
            </div>
          )}
        </div>
      </div>
    </Link>
  );
}
