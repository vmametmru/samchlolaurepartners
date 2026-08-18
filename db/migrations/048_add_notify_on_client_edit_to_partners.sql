-- When a client edits and resends their own pending reservation request via
-- the public "Partager le lien" page (ReservationsController::
-- updatePublicRequest()), the agency (partner) and the client themselves
-- are both emailed a change-details diff (sendClientEditNotificationEmail()/
-- sendClientSelfEditConfirmationEmail()). notify_on_client_edit lets the
-- partner opt out of receiving their own notification for this via a
-- "Ne pas envoyer de email" checkbox on /partner/settings, without
-- affecting the client's own confirmation email. Defaults to 1 (sent) so
-- existing partners keep today's behaviour.
ALTER TABLE partners
  ADD COLUMN notify_on_client_edit TINYINT(1) NOT NULL DEFAULT 1;
