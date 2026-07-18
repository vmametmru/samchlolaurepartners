-- Splits the combined "children" count into the two age brackets already
-- collected on the booking form (children_under5, children_5to12), so
-- status emails (confirmation/cancellation) can use the {{bebes}} /
-- {{enfants}} variables the same way the initial request email does,
-- instead of only ever having the combined total.
ALTER TABLE reservation_requests
  ADD COLUMN children_under5 TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER children,
  ADD COLUMN children_5to12 TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER children_under5;

-- Best-effort backfill for existing rows: without the original split, treat
-- previously recorded "children" as 5-12 year olds (the more common case)
-- rather than leaving both new columns at 0 while "children" is non-zero.
UPDATE reservation_requests SET children_5to12 = children WHERE children > 0;
