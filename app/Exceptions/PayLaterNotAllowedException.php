<?php

namespace App\Exceptions;

/** MARKER-PAY-LATER: the shop doesn't allow paying later for this order. Message is shopper-facing. */
class PayLaterNotAllowedException extends \RuntimeException
{
}
