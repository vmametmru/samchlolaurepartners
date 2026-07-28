-- Persists the VAT rate applied to the room/extra-person totals at
-- submission time (quote_room_total/quote_extra_person_total already have
-- it baked in, same as the partner's markup), so confirmation/cancellation
-- emails can re-derive the commission (partner margin only, never the VAT
-- collected on behalf of the property owner) from the persisted breakdown
-- exactly as it was computed when the guest booked.
ALTER TABLE reservation_requests
  ADD COLUMN quote_vat_rate DECIMAL(5,2) DEFAULT NULL AFTER quote_partner_rate;
