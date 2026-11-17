-- Keyword matching is now scoped to Call Intelligence topics.
--
-- Rows still carrying the old catch-all default searched the summary and the
-- whole transcript as well, which produced matches on incidental words. Those
-- three columns are exactly the old default, so they are normalised to topics;
-- anything a user picked deliberately is left untouched. The summary can be
-- switched back on in Settings (match_in_summary) or per keyword.

UPDATE keywords SET fields = '["topics"]' WHERE fields IN (
    '["summary","topics","transcript"]',
    '["summary", "topics", "transcript"]',
    '["topics","summary","transcript"]'
);
