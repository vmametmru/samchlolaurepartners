import { useState } from 'react';
import { LodgifyPropertyImage } from '@samchlolaurepartners/shared';

interface Props {
  images: LodgifyPropertyImage[];
}

export default function PhotoGallery({ images }: Props) {
  const [selected, setSelected] = useState(0);
  const [lightbox, setLightbox] = useState<number | null>(null);

  if (images.length === 0) {
    return (
      <div className="bg-gray-100 rounded-xl aspect-video flex items-center justify-center text-gray-400">
        Aucune photo disponible
      </div>
    );
  }

  return (
    <>
      {/* Main image */}
      <div
        className="relative rounded-xl overflow-hidden aspect-video cursor-zoom-in"
        onClick={() => setLightbox(selected)}
      >
        <img
          src={images[selected].url}
          alt={images[selected].text ?? ''}
          className="w-full h-full object-cover"
        />
        <div className="absolute bottom-2 right-2 bg-black/50 text-white text-xs px-2 py-1 rounded">
          {selected + 1} / {images.length}
        </div>
      </div>

      {/* Thumbnails */}
      {images.length > 1 && (
        <div className="mt-2 flex gap-2 overflow-x-auto pb-1">
          {images.map((img, i) => (
            <button
              key={i}
              onClick={() => setSelected(i)}
              className={`flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden border-2 transition-colors ${
                i === selected ? 'border-brand-500' : 'border-transparent'
              }`}
            >
              <img src={img.url} alt={img.text ?? ''} className="w-full h-full object-cover" />
            </button>
          ))}
        </div>
      )}

      {/* Lightbox */}
      {lightbox !== null && (
        <div
          className="fixed inset-0 bg-black/90 z-50 flex items-center justify-center"
          onClick={() => setLightbox(null)}
        >
          <button
            className="absolute top-4 right-4 text-white text-2xl"
            onClick={() => setLightbox(null)}
          >
            ✕
          </button>
          <button
            className="absolute left-4 text-white text-3xl"
            onClick={(e) => { e.stopPropagation(); setLightbox((lightbox - 1 + images.length) % images.length); }}
          >
            ‹
          </button>
          <img
            src={images[lightbox].url}
            alt={images[lightbox].text ?? ''}
            className="max-w-screen-lg max-h-screen object-contain"
            onClick={(e) => e.stopPropagation()}
          />
          <button
            className="absolute right-4 text-white text-3xl"
            onClick={(e) => { e.stopPropagation(); setLightbox((lightbox + 1) % images.length); }}
          >
            ›
          </button>
        </div>
      )}
    </>
  );
}
