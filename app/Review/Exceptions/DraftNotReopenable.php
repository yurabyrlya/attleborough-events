<?php

namespace App\Review\Exceptions;

use DomainException;

/**
 * ReopenDraft refused on the freshly locked rows: only new-event drafts can
 * be reopened (an ignored update or cancellation stays ignored, C14), and a
 * feed item never gets a second pending draft (PIPE-06). The message is
 * shown to the admin as is.
 */
final class DraftNotReopenable extends DomainException {}
